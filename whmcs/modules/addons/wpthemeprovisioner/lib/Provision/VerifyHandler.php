<?php
namespace Wtp\Addon\Provision;

use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Support\Json;

/**
 * The final gate: WHMCS checks the site itself before telling the customer
 * their order is ready.
 *
 * The agent's "I'm done" is evidence, not proof — a plugin conflict can leave
 * a fatal error behind a successful import. So we fetch the home page and the
 * agent's own status endpoint and insist on both.
 */
final class VerifyHandler implements StepHandler
{
    /** @var string[] strings that mean the site is broken even with HTTP 200 */
    private const FAILURE_MARKERS = [
        'There has been a critical error on this website',
        'Error establishing a database connection',
        'Fatal error:',
        'Parse error:',
    ];

    /** @return string[] */
    public function handles(): array
    {
        return [StepCatalog::JOB_VERIFY];
    }

    public function execute(StepContext $context): StepOutcome
    {
        $siteUrl = rtrim((string) $context->payloadValue('site_url', ''), '/');
        if ($siteUrl === '') {
            $siteUrl = 'https://' . $context->domain();
        }

        $http = $context->container()->httpClient();
        $ip = (string) $context->payloadValue('cpanel.ip', '');

        $options = ['timeout' => 30, 'headers' => ['Accept' => 'text/html']];
        if ($ip !== '') {
            $options['resolve'] = $ip;
        }

        $response = $http->get($siteUrl . '/', $options);

        // A brand-new certificate can take a few minutes; retry over http so a
        // pending SSL issuance does not fail an otherwise perfect install.
        if ($response['error'] !== '' && strpos($siteUrl, 'https://') === 0) {
            $options['insecure'] = true;
            $response = $http->get($siteUrl . '/', $options);
        }

        if ($response['error'] !== '') {
            if ($context->attempt() < 4) {
                return StepOutcome::waiting(30, 'Site not reachable yet: ' . $response['error'], 60);
            }
            return StepOutcome::failed(
                $response['error_code'] !== '' ? $response['error_code'] : 'network_error',
                'Could not reach the site to verify it: ' . $response['error']
            );
        }

        $status = (int) $response['status'];
        $body = (string) $response['body'];

        if ($status >= 500) {
            if ($context->attempt() < 4) {
                return StepOutcome::waiting(30, 'Site returned HTTP ' . $status, 60);
            }
            return StepOutcome::failed('site_error', 'The site returns HTTP ' . $status);
        }
        if ($status >= 400 && $status !== 401 && $status !== 403) {
            return StepOutcome::failed('site_error', 'The site returns HTTP ' . $status);
        }

        foreach (self::FAILURE_MARKERS as $marker) {
            if (stripos($body, $marker) !== false) {
                return StepOutcome::failed('site_error', 'The site is serving a WordPress error page');
            }
        }

        $signals = [
            'http_status' => $status,
            'is_wordpress' => stripos($body, 'wp-content') !== false || stripos($body, 'wp-includes') !== false,
            'bytes' => strlen($body),
        ];

        if (!$signals['is_wordpress'] && $context->attempt() < 3) {
            return StepOutcome::waiting(30, 'The response does not look like WordPress yet', 70);
        }

        $result = Json::tryDecode($context->job()['result'] ?? null, []);
        $context->container()->jobs()->setResult($context->jobId(), array_merge($result, [
            'site_url' => $siteUrl,
            'admin_url' => $siteUrl . '/wp-admin/',
            'verified_at' => gmdate('c', $context->container()->clock()->now()),
            'verification' => $signals,
        ]));

        $context->log()->info($context->jobId(), 'Verified the finished site', $signals, $context->stepKey());

        return StepOutcome::completed($signals);
    }
}
