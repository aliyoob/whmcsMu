<?php
namespace Wtp\Shared\Support;

use RuntimeException;

/** Random material and encodings used across the whole system. */
final class Bytes
{
    public static function random(int $length): string
    {
        if ($length < 1) {
            throw new RuntimeException('Random length must be >= 1');
        }
        return random_bytes($length);
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $encoded): string
    {
        $pad = strlen($encoded) % 4;
        if ($pad !== 0) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($raw === false) {
            throw new RuntimeException('Invalid base64url input');
        }
        return $raw;
    }

    /** URL-safe token, e.g. bootstrap tokens and agent secrets. */
    public static function token(int $rawLength = 32): string
    {
        return self::base64UrlEncode(self::random($rawLength));
    }

    public static function hex(int $rawLength = 16): string
    {
        return bin2hex(self::random($rawLength));
    }

    /** RFC 4122 version 4 UUID. */
    public static function uuid4(): string
    {
        $b = self::random(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $hex = bin2hex($b);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /** Timing-safe comparison that also tolerates non-string input. */
    public static function equals(?string $a, ?string $b): bool
    {
        if (!is_string($a) || !is_string($b)) {
            return false;
        }
        return hash_equals($a, $b);
    }

    /** Short, non-reversible fingerprint safe to write into logs. */
    public static function fingerprint(string $secret): string
    {
        return substr(hash('sha256', 'wtp-fp:' . $secret), 0, 16);
    }
}
