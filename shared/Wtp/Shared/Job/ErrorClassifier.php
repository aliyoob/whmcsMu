<?php
namespace Wtp\Shared\Job;

/**
 * Decides whether a failure should be retried, failed immediately, or parked
 * for a human. Getting this wrong is expensive in both directions: retrying a
 * permanent error burns quota, and failing a transient one abandons a paid order.
 */
final class ErrorClassifier
{
    public const RETRY  = 'transient';
    public const FAIL   = 'permanent';
    public const MANUAL = 'manual';

    /** Error codes that are always worth another attempt. */
    private const TRANSIENT_CODES = [
        'network_timeout', 'network_error', 'dns_not_resolved', 'connection_refused',
        'http_429', 'http_500', 'http_502', 'http_503', 'http_504',
        'cpanel_busy', 'cpanel_timeout', 'agent_not_ready', 'lock_contended',
        'download_incomplete', 'db_deadlock', 'temporary_failure', 'ssl_not_ready',
        'wordpress_not_ready', 'disk_busy',
    ];

    /** Error codes that will never succeed on retry. */
    private const PERMANENT_CODES = [
        'invalid_token', 'signature_mismatch', 'theme_not_found', 'demo_not_found',
        'asset_not_found', 'checksum_mismatch', 'unsupported_archive', 'zip_slip',
        'plan_invalid', 'selection_missing', 'account_exists_conflict',
        'domain_invalid', 'package_not_found', 'php_version_unsupported',
        'wp_version_unsupported', 'grant_expired', 'grant_exhausted', 'job_cancelled',
    ];

    /** Error codes that need an operator: quota, licensing, disk, credentials. */
    private const MANUAL_CODES = [
        'disk_quota_exceeded', 'account_quota_exceeded', 'cpanel_auth_failed',
        'whm_permission_denied', 'license_invalid', 'storage_unreadable',
        'master_key_missing',
    ];

    public function classify(string $code, ?string $message = null): string
    {
        $code = strtolower(trim($code));

        if (in_array($code, self::MANUAL_CODES, true)) {
            return self::MANUAL;
        }
        if (in_array($code, self::PERMANENT_CODES, true)) {
            return self::FAIL;
        }
        if (in_array($code, self::TRANSIENT_CODES, true)) {
            return self::RETRY;
        }

        // HTTP status shorthand: 4xx is ours to fix, 5xx is worth retrying.
        if (preg_match('/^http_(\d{3})$/', $code, $m)) {
            $status = (int) $m[1];
            if ($status === 408 || $status === 429 || $status >= 500) {
                return self::RETRY;
            }
            return self::FAIL;
        }

        if ($message !== null && $message !== '') {
            $lower = strtolower($message);
            foreach (['timed out', 'timeout', 'temporarily unavailable', 'try again',
                      'connection reset', 'could not resolve', 'deadlock'] as $needle) {
                if (strpos($lower, $needle) !== false) {
                    return self::RETRY;
                }
            }
        }

        // Unknown failures are retried a bounded number of times; the attempt
        // counter, not this classifier, is what eventually stops them.
        return self::RETRY;
    }

    public function shouldRetry(string $code, int $attempt, int $maxAttempts, ?string $message = null): bool
    {
        if ($attempt >= $maxAttempts) {
            return false;
        }
        return $this->classify($code, $message) === self::RETRY;
    }
}
