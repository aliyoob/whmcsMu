<?php
/**
 * Signed HTTP client for the WHMCS provisioning API.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Wtp\Shared\Http\HmacSigner;
use Wtp\Shared\Support\Json;
use Wtp\Shared\Support\SystemClock;

/**
 * Every call except the handshake is signed with the per-job secret.
 *
 * Uses WordPress's own HTTP API so the request honours the site's proxy
 * configuration, its CA bundle and any `http_request_args` filters a host has
 * in place — reimplementing that with raw curl is how agents end up unable to
 * reach anything on a locked-down host.
 */
final class Wtp_Api_Client
{
    /** Signing failures and 5xx are retried; a 4xx is a decision, not a hiccup. */
    private const RETRYABLE_STATUS = [408, 425, 429, 500, 502, 503, 504];

    /** @var Wtp_Config */
    private $config;
    /** @var Wtp_Logger */
    private $logger;
    /** @var HmacSigner */
    private $signer;
    /** @var int */
    private $timeout;

    public function __construct(Wtp_Config $config, Wtp_Logger $logger, int $timeout = 30)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->signer = new HmacSigner(new SystemClock());
        $this->timeout = max(5, $timeout);
    }

    /**
     * Exchange the installer's single-use token for a signing secret.
     *
     * @return array<string,mixed>
     * @throws Wtp_Api_Exception
     */
    public function handshake(): array
    {
        $token = $this->config->bootstrapToken();
        if ($token === '') {
            throw new Wtp_Api_Exception('missing_bootstrap_token', 'No bootstrap token is available');
        }

        $body = Json::encode([
            'site_url' => $this->config->siteUrl(),
            'wp_version' => (string) get_bloginfo('version'),
            'agent_version' => WTP_AGENT_VERSION,
            'php_version' => PHP_VERSION,
        ]);

        $response = $this->send('POST', '/handshake', $body, [
            'Authorization' => 'WTP-Bootstrap ' . $this->config->jobId() . '.' . $token,
        ]);

        if (empty($response['credentials']['secret'])) {
            throw new Wtp_Api_Exception('handshake_incomplete', 'The handshake returned no signing secret');
        }

        $this->config->storeSecret((string) $response['credentials']['secret']);
        return $response;
    }

    /**
     * @return array<string,mixed>
     * @throws Wtp_Api_Exception
     */
    public function fetchJob(): array
    {
        return $this->signed('GET', '/job', null);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws Wtp_Api_Exception
     */
    public function reportStep(string $stepKey, array $payload, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        return $this->signed('POST', '/steps/' . rawurlencode($stepKey), $payload, $headers);
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array<string,mixed>
     */
    public function sendEvents(array $events): array
    {
        return $this->signed('POST', '/events', ['events' => array_values($events)]);
    }

    /**
     * @return array<string,mixed>
     * @throws Wtp_Api_Exception
     */
    public function requestGrant(string $assetHandle): array
    {
        return $this->signed('POST', '/assets/' . rawurlencode($assetHandle) . '/grant', []);
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    public function complete(array $summary): array
    {
        return $this->signed('POST', '/complete', $summary);
    }

    /**
     * @param array<string,mixed> $diagnostics
     * @return array<string,mixed>
     */
    public function fail(string $code, string $message, ?string $stepKey = null, array $diagnostics = []): array
    {
        return $this->signed('POST', '/fail', [
            'error_code' => $code,
            'error_message' => $message,
            'step' => $stepKey,
            'diagnostics' => $diagnostics,
        ]);
    }

    // ----------------------------------------------------------- internals

    /**
     * @param array<string,mixed>|null $payload
     * @param array<string,string> $headers
     * @return array<string,mixed>
     * @throws Wtp_Api_Exception
     */
    private function signed(string $method, string $path, ?array $payload, array $headers = []): array
    {
        $secret = $this->config->secret();
        if ($secret === '') {
            throw new Wtp_Api_Exception('no_secret', 'The agent has not completed its handshake yet');
        }

        $body = $payload === null ? '' : Json::encode($payload);
        $signaturePath = $this->signaturePath($path);

        $headers = array_merge(
            $headers,
            $this->signer->sign($secret, $this->config->jobId(), $method, $signaturePath, $body)
        );

        return $this->send($method, $path, $body, $headers);
    }

    /**
     * The server signs the *full* request path, so the agent must too.
     */
    private function signaturePath(string $path): string
    {
        $base = (string) wp_parse_url($this->config->apiBase(), PHP_URL_PATH);
        return rtrim($base, '/') . $path;
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     * @throws Wtp_Api_Exception
     */
    private function send(string $method, string $path, string $body, array $headers): array
    {
        $url = $this->config->apiBase() . $path;

        $headers = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-WTP-Agent' => 'whmcs-theme-provisioner/' . WTP_AGENT_VERSION,
        ], $headers);

        $args = [
            'method' => $method,
            'timeout' => $this->timeout,
            'redirection' => 0,
            'headers' => $headers,
            'sslverify' => true,
            'user-agent' => 'WHMCS-Theme-Provisioner-Agent/' . WTP_AGENT_VERSION,
        ];
        if ($body !== '' && $method !== 'GET') {
            $args['body'] = $body;
        }

        $attempts = 0;
        $lastError = null;

        while ($attempts < 3) {
            $attempts++;
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $lastError = new Wtp_Api_Exception(
                    'network_error',
                    $response->get_error_message(),
                    0
                );
                $this->backoff($attempts);
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $raw = (string) wp_remote_retrieve_body($response);
            $decoded = Json::tryDecode($raw, []);

            if ($status >= 200 && $status < 300) {
                return $decoded;
            }

            $code = isset($decoded['error']) ? (string) $decoded['error'] : ('http_' . $status);
            $message = isset($decoded['message']) ? (string) $decoded['message'] : ('HTTP ' . $status);

            if (in_array($status, self::RETRYABLE_STATUS, true) && $attempts < 3) {
                $retryAfter = (int) wp_remote_retrieve_header($response, 'retry-after');
                $this->backoff($attempts, $retryAfter);
                $lastError = new Wtp_Api_Exception($code, $message, $status);
                continue;
            }

            throw new Wtp_Api_Exception($code, $message, $status);
        }

        throw $lastError ?? new Wtp_Api_Exception('network_error', 'The provisioning API is unreachable');
    }

    private function backoff(int $attempt, int $retryAfter = 0): void
    {
        $seconds = $retryAfter > 0 ? min(30, $retryAfter) : min(8, 2 ** $attempt);
        $this->logger->debug('Retrying the provisioning API in ' . $seconds . 's', ['attempt' => $attempt]);
        sleep($seconds);
    }
}
