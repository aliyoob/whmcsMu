<?php
namespace Wtp\Addon\Provision;

use Wtp\Addon\Job\JobSecrets;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Support\Json;

/**
 * Drives the installer, one bounded step per worker tick.
 *
 * WHMCS reaches the new account by IP with an explicit Host header
 * (CURLOPT_RESOLVE): right after `createacct` the customer's DNS may still
 * point at their previous provider, and waiting for propagation before
 * installing WordPress would add hours to every order.
 *
 * The connection prefers HTTPS even without a valid certificate — the account
 * has no issued certificate yet, but encrypting the channel still keeps the
 * run token away from passive observers. It falls back to HTTP only if the
 * server is not listening on 443 at all.
 */
final class InstallerHandler implements StepHandler
{
    private const STEP_TO_ACTION = [
        StepCatalog::WORDPRESS_DOWNLOAD => 'download',
        StepCatalog::WORDPRESS_INSTALL => 'install',
        StepCatalog::AGENT_INSTALL => 'agent',
    ];

    /** @var JobSecrets */
    private $secrets;

    public function __construct(JobSecrets $secrets)
    {
        $this->secrets = $secrets;
    }

    /** @return string[] */
    public function handles(): array
    {
        return array_keys(self::STEP_TO_ACTION);
    }

    public function execute(StepContext $context): StepOutcome
    {
        $stepKey = $context->stepKey();
        $action = self::STEP_TO_ACTION[$stepKey];

        $payload = $context->payload();
        $file = (string) ($payload['bootstrap']['file'] ?? '');
        $runToken = $this->secrets->tryOpen($payload['bootstrap']['run_token_enc'] ?? null, 'run-token');

        if ($file === '' || $runToken === '') {
            return StepOutcome::failed('bootstrap_not_deployed', 'The installer has not been deployed for this job');
        }

        $response = $this->callInstaller($context, $file, [
            'action' => 'step',
            'step' => $action,
            'token' => $runToken,
        ]);

        if ($response['transport_error'] !== '') {
            // The new account's web server may still be starting up.
            if ($context->attempt() <= 3) {
                return StepOutcome::waiting(20, 'Waiting for the hosting account to answer: ' . $response['transport_error']);
            }
            return StepOutcome::failed($response['error_code'], $response['transport_error']);
        }

        $status = $response['status'];
        $body = $response['json'];

        if ($status === 410) {
            // The installer removed itself. If its work is visibly done that is
            // fine; otherwise this job needs a fresh deployment.
            return StepOutcome::failed(
                'bootstrap_expired',
                'The installer expired before finishing. Retry from "Deploy installer".'
            );
        }
        if ($status === 401) {
            return StepOutcome::failed('invalid_token', 'The installer rejected our run token');
        }
        if ($status === 429) {
            return StepOutcome::failed('bootstrap_run_limit', 'The installer reached its run limit');
        }
        if ($status < 200 || $status >= 300) {
            return StepOutcome::failed('http_' . $status, 'The installer answered HTTP ' . $status);
        }
        if ($body === []) {
            return StepOutcome::failed('bootstrap_protocol_error', 'The installer returned an unreadable response');
        }

        if (empty($body['success'])) {
            $code = (string) ($body['error_code'] ?? 'bootstrap_error');
            $message = (string) ($body['error_message'] ?? 'The installer reported a failure');
            return StepOutcome::failed($code, $message);
        }

        $detail = isset($body['detail']) && is_array($body['detail']) ? $body['detail'] : [];

        $context->log()->info($context->jobId(), 'Installer completed "' . $action . '"', $detail, $stepKey);

        if ($stepKey === StepCatalog::WORDPRESS_INSTALL && isset($detail['wp_version'])) {
            $context->mergePayload(['wp' => array_merge(
                is_array($payload['wp'] ?? null) ? $payload['wp'] : [],
                ['version' => (string) $detail['wp_version']]
            )]);
        }

        // The agent takes over from here; the installer is no longer needed.
        if ($stepKey === StepCatalog::AGENT_INSTALL) {
            $this->cleanUpInstaller($context, $file, $runToken);
        }

        return StepOutcome::completed($detail);
    }

    /**
     * @param array<string,string> $params
     * @return array{status:int,json:array<string,mixed>,transport_error:string,error_code:string}
     */
    private function callInstaller(StepContext $context, string $file, array $params): array
    {
        $http = $context->container()->httpClient();
        $domain = $context->domain();
        $ip = (string) $context->payloadValue('cpanel.ip', '');
        $timeout = 120;

        $attempts = [
            ['scheme' => 'https', 'insecure' => true],
            ['scheme' => 'http', 'insecure' => false],
        ];

        $lastError = '';
        $lastCode = 'network_error';

        foreach ($attempts as $attempt) {
            $url = $attempt['scheme'] . '://' . $domain . '/' . $file;
            $options = [
                'body' => $params,
                'timeout' => $timeout,
                'insecure' => $attempt['insecure'],
                'headers' => ['Accept' => 'application/json'],
            ];
            if ($ip !== '') {
                $options['resolve'] = $ip;
            }

            $response = $http->post($url, $options);

            if ($response['error'] === '') {
                return [
                    'status' => (int) $response['status'],
                    'json' => Json::tryDecode($response['body'], []),
                    'transport_error' => '',
                    'error_code' => '',
                ];
            }
            $lastError = $response['error'];
            $lastCode = $response['error_code'] !== '' ? $response['error_code'] : 'network_error';
        }

        return ['status' => 0, 'json' => [], 'transport_error' => $lastError, 'error_code' => $lastCode];
    }

    /**
     * Best-effort teardown. A failure here is logged but never fails the job:
     * the installer expires and deletes itself regardless, and the account is
     * already working.
     */
    private function cleanUpInstaller(StepContext $context, string $file, string $runToken): void
    {
        $response = $this->callInstaller($context, $file, ['action' => 'cleanup', 'token' => $runToken]);

        if ($response['transport_error'] !== '' || $response['status'] >= 400) {
            $context->log()->warning(
                $context->jobId(),
                'Could not confirm installer cleanup; it will expire and remove itself',
                ['status' => $response['status'], 'error' => $response['transport_error']],
                $context->stepKey()
            );
            // Fall back to deleting it through the panel.
            try {
                $docroot = (string) $context->payloadValue('cpanel.docroot', '');
                $account = (string) $context->payloadValue('cpanel.username', '');
                if ($docroot !== '' && $account !== '') {
                    $context->panel()->deleteFile($account, $docroot . '/' . $file);
                    $context->panel()->deleteFile(
                        $account,
                        $docroot . '/' . preg_replace('/\.php$/', '.json', $file)
                    );
                }
            } catch (\Throwable $e) {
                $context->log()->warning($context->jobId(), 'Panel-side installer cleanup also failed', [
                    'reason' => $e->getMessage(),
                ], $context->stepKey());
            }
            return;
        }

        $context->log()->info($context->jobId(), 'Installer removed itself', [], $context->stepKey());
    }
}
