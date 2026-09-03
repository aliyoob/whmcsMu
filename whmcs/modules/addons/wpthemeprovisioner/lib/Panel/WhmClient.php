<?php
namespace Wtp\Addon\Panel;

use Wtp\Addon\Support\HttpClient;
use Wtp\Shared\Support\Json;

/**
 * Transport for WHM API 1 and, through WHM's `cpanel` proxy, cPanel UAPI/API 2.
 *
 * Authentication prefers a WHM API token (`Authorization: whm user:TOKEN`),
 * which is what WHMCS stores in a server's "Access Hash / API Token" field.
 * Basic auth with the root password is supported as a fallback because plenty
 * of existing WHMCS installs are still configured that way.
 */
final class WhmClient
{
    public const DEFAULT_PORT = 2087;

    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $username;
    /** @var string */
    private $token;
    /** @var string */
    private $password;
    /** @var bool */
    private $verifyTls;
    /** @var HttpClient */
    private $http;

    public function __construct(
        HttpClient $http,
        string $host,
        string $username,
        string $token = '',
        string $password = '',
        int $port = self::DEFAULT_PORT,
        bool $verifyTls = true
    ) {
        $this->http = $http;
        $this->host = $host;
        $this->username = $username !== '' ? $username : 'root';
        $this->token = $token;
        $this->password = $password;
        $this->port = $port > 0 ? $port : self::DEFAULT_PORT;
        $this->verifyTls = $verifyTls;
    }

    /**
     * Call a WHM API 1 function.
     *
     * @param array<string,scalar> $params
     * @return array<string,mixed> the decoded `data` payload
     * @throws PanelException
     */
    public function whm(string $function, array $params = []): array
    {
        $params['api.version'] = 1;
        $url = $this->baseUrl() . '/json-api/' . rawurlencode($function) . '?' . http_build_query($params);

        $decoded = $this->send($url, 'WHM ' . $function);

        // WHM API 1 shape: { metadata: {result, reason, command}, data: {...} }
        $metadata = isset($decoded['metadata']) && is_array($decoded['metadata']) ? $decoded['metadata'] : [];
        $result = isset($metadata['result']) ? (int) $metadata['result'] : 0;

        if ($result !== 1) {
            $reason = isset($metadata['reason']) ? (string) $metadata['reason'] : 'unknown error';
            throw new PanelException(
                'WHM ' . $function . ' failed: ' . $reason,
                self::codeForReason($reason)
            );
        }

        return isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
    }

    /**
     * Call a cPanel UAPI function as $account, proxied through WHM.
     *
     * @param array<string,scalar> $params
     * @return array<string,mixed> the UAPI `data` payload
     * @throws PanelException
     */
    public function uapi(string $account, string $module, string $function, array $params = []): array
    {
        $query = array_merge($params, [
            'cpanel_jsonapi_user' => $account,
            'cpanel_jsonapi_apiversion' => 3,
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $function,
        ]);
        $url = $this->baseUrl() . '/json-api/cpanel?' . http_build_query($query);

        $decoded = $this->send($url, 'UAPI ' . $module . '::' . $function);

        // Through the proxy the payload arrives as { result: { status, errors, data } }
        // and occasionally wrapped in { cpanelresult: { result: ... } }.
        $result = null;
        if (isset($decoded['result']) && is_array($decoded['result'])) {
            $result = $decoded['result'];
        } elseif (isset($decoded['cpanelresult']['result']) && is_array($decoded['cpanelresult']['result'])) {
            $result = $decoded['cpanelresult']['result'];
        }

        if ($result === null) {
            throw new PanelException(
                'Unexpected UAPI response for ' . $module . '::' . $function,
                'cpanel_protocol_error'
            );
        }

        if (empty($result['status'])) {
            $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : [];
            $reason = $errors === [] ? 'unknown error' : implode('; ', array_map('strval', $errors));
            throw new PanelException(
                'UAPI ' . $module . '::' . $function . ' failed: ' . $reason,
                self::codeForReason($reason)
            );
        }

        $data = isset($result['data']) ? $result['data'] : [];
        return is_array($data) ? $data : ['value' => $data];
    }

    /**
     * Call a cPanel API 2 function. Still required for `Fileman::fileop`,
     * which has no UAPI equivalent for archive extraction.
     *
     * @param array<string,scalar> $params
     * @return array<int,mixed>
     */
    public function api2(string $account, string $module, string $function, array $params = []): array
    {
        $query = array_merge($params, [
            'cpanel_jsonapi_user' => $account,
            'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $function,
        ]);
        $url = $this->baseUrl() . '/json-api/cpanel?' . http_build_query($query);

        $decoded = $this->send($url, 'API2 ' . $module . '::' . $function);

        $result = isset($decoded['cpanelresult']) && is_array($decoded['cpanelresult'])
            ? $decoded['cpanelresult']
            : $decoded;

        if (isset($result['error']) && $result['error'] !== '') {
            throw new PanelException(
                'API2 ' . $module . '::' . $function . ' failed: ' . (string) $result['error'],
                self::codeForReason((string) $result['error'])
            );
        }
        if (isset($result['event']['result']) && (int) $result['event']['result'] !== 1) {
            $reason = isset($result['event']['reason']) ? (string) $result['event']['reason'] : 'unknown error';
            throw new PanelException(
                'API2 ' . $module . '::' . $function . ' failed: ' . $reason,
                self::codeForReason($reason)
            );
        }

        $data = isset($result['data']) ? $result['data'] : [];
        return is_array($data) ? $data : [];
    }

    /** @return array<string,mixed> */
    private function send(string $url, string $label): array
    {
        $response = $this->http->request('GET', $url, [
            'headers' => ['Authorization' => $this->authorizationHeader()],
            'insecure' => !$this->verifyTls,
        ]);

        if ($response['error'] !== '') {
            throw new PanelException(
                $label . ' transport error: ' . $response['error'],
                $response['error_code'] !== '' ? $response['error_code'] : 'network_error'
            );
        }

        $status = (int) $response['status'];
        if ($status === 401 || $status === 403) {
            throw new PanelException($label . ' was rejected by the server', 'cpanel_auth_failed');
        }
        if ($status === 429) {
            throw new PanelException($label . ' was rate limited by the server', 'cpanel_busy');
        }
        if ($status >= 500) {
            throw new PanelException($label . ' failed with HTTP ' . $status, 'http_' . $status);
        }
        if ($status !== 200) {
            throw new PanelException($label . ' returned HTTP ' . $status, 'http_' . $status);
        }

        try {
            return Json::decode($response['body'], 4194304);
        } catch (\InvalidArgumentException $e) {
            throw new PanelException($label . ' returned a non-JSON response', 'cpanel_protocol_error');
        }
    }

    private function authorizationHeader(): string
    {
        if ($this->token !== '') {
            return 'whm ' . $this->username . ':' . $this->token;
        }
        return 'Basic ' . base64_encode($this->username . ':' . $this->password);
    }

    private function baseUrl(): string
    {
        return 'https://' . $this->host . ':' . $this->port;
    }

    /** Map a panel's prose error onto a code the classifier understands. */
    public static function codeForReason(string $reason): string
    {
        $lower = strtolower($reason);

        if (strpos($lower, 'already exists') !== false || strpos($lower, 'in use') !== false) {
            return 'account_exists_conflict';
        }
        if (strpos($lower, 'quota') !== false || strpos($lower, 'disk') !== false) {
            return 'disk_quota_exceeded';
        }
        if (strpos($lower, 'permission') !== false || strpos($lower, 'not allowed') !== false
            || strpos($lower, 'access denied') !== false) {
            return 'whm_permission_denied';
        }
        if (strpos($lower, 'package') !== false && strpos($lower, 'not') !== false) {
            return 'package_not_found';
        }
        if (strpos($lower, 'license') !== false) {
            return 'license_invalid';
        }
        if (strpos($lower, 'busy') !== false || strpos($lower, 'try again') !== false
            || strpos($lower, 'lock') !== false) {
            return 'cpanel_busy';
        }
        if (strpos($lower, 'timed out') !== false || strpos($lower, 'timeout') !== false) {
            return 'cpanel_timeout';
        }
        return 'cpanel_error';
    }
}
