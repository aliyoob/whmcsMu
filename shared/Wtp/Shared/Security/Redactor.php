<?php
namespace Wtp\Shared\Security;

/**
 * Removes credentials from anything that is about to be persisted or displayed.
 *
 * Two passes:
 *   1. by key name  - anything whose array key looks like a credential
 *   2. by shape     - bearer/basic headers, long high-entropy blobs, our own
 *                     token formats, and URLs carrying a `sig`/`token` query arg
 *
 * Redaction keeps a short fingerprint so operators can still correlate values
 * across log lines without ever seeing the secret itself.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    /** @var string[] */
    private static $sensitiveKeys = [
        'password', 'passwd', 'pass', 'pwd', 'secret', 'token', 'apikey', 'api_key',
        'accesshash', 'access_hash', 'authorization', 'auth', 'signature', 'sig',
        'privatekey', 'private_key', 'clientsecret', 'client_secret', 'masterkey',
        'master_key', 'serverpassword', 'serveraccesshash', 'dbpassword', 'db_password',
        'adminpassword', 'admin_password', 'bootstrap_token', 'job_secret', 'cookie',
        'set-cookie', 'x-wtp-signature', 'credentials', 'salt', 'nonce_key',
    ];

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function scrub($value, int $depth = 0)
    {
        if ($depth > 12) {
            return '[depth-limit]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::isSensitiveKey((string) $k)
                    ? self::mask(is_scalar($v) ? (string) $v : '[object]')
                    : self::scrub($v, $depth + 1);
            }
            return $out;
        }
        if (is_object($value)) {
            return '[object:' . get_class($value) . ']';
        }
        if (is_string($value)) {
            return self::scrubString($value);
        }
        return $value;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $k = strtolower(str_replace(['-', ' '], '_', $key));
        foreach (self::$sensitiveKeys as $needle) {
            if ($k === $needle || strpos($k, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function scrubString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // Authorization headers: mask the entire value, scheme included, so a
        // scheme name can never be mistaken for the credential itself.
        $value = (string) preg_replace_callback(
            '/\b(Authorization\s*:\s*)([^\r\n]+)/i',
            static function (array $m): string {
                return $m[1] . self::mask(trim($m[2]));
            },
            $value
        );

        // Bare credential-bearing schemes appearing outside a header.
        $value = (string) preg_replace_callback(
            '/\b(Bearer\s+|Basic\s+|WTP-Bootstrap\s+)([A-Za-z0-9._\-\/+=]{8,})/i',
            static function (array $m): string {
                return $m[1] . self::mask($m[2]);
            },
            $value
        );

        // Our own header/parameter shapes.
        $value = (string) preg_replace_callback(
            '/\b(v1=|sig=|token=|secret=|password=|apikey=|api_key=)([A-Za-z0-9._\-\/+=]{8,})/i',
            static function (array $m): string {
                return $m[1] . self::mask($m[2]);
            },
            $value
        );

        // Bare high-entropy blobs (>= 32 chars of base64url/hex) that are not
        // obviously a checksum we intentionally log.
        $value = (string) preg_replace_callback(
            '/(?<![A-Za-z0-9._\-])([A-Za-z0-9_\-]{40,})(?![A-Za-z0-9._\-])/',
            static function (array $m): string {
                return self::looksLikeEntropy($m[1]) ? self::mask($m[1]) : $m[1];
            },
            $value
        );

        return $value;
    }

    public static function mask(string $secret): string
    {
        if ($secret === '') {
            return self::MASK;
        }
        return self::MASK . '(' . substr(hash('sha256', 'wtp-fp:' . $secret), 0, 8) . ')';
    }

    private static function looksLikeEntropy(string $candidate): bool
    {
        // sha256 hex digests are deliberately allowed through: they are public
        // integrity values, not secrets.
        if (preg_match('/^[a-f0-9]{64}$/i', $candidate)) {
            return false;
        }
        $unique = count(array_unique(str_split($candidate)));
        return $unique >= 16;
    }
}
