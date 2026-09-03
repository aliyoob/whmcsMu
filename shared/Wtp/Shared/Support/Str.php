<?php
namespace Wtp\Shared\Support;

final class Str
{
    /** Lowercase slug: [a-z0-9-], collapsed, trimmed, length-capped. */
    public static function slug(string $value, int $maxLength = 96): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim((string) $value, '-');
        return substr($value, 0, $maxLength);
    }

    /** True when the string is a plausible, non-wildcard hostname. */
    public static function isHostname(string $value): bool
    {
        if ($value === '' || strlen($value) > 253) {
            return false;
        }
        return (bool) preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/i', $value);
    }

    public static function isSha256(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/i', $value);
    }

    public static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }

    /** Truncate for storage in a TEXT column without splitting a UTF-8 sequence. */
    public static function limit(string $value, int $max = 2000): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }
        $cut = substr($value, 0, $max);
        // drop a dangling multi-byte sequence
        while ($cut !== '' && (ord($cut[strlen($cut) - 1]) & 0xC0) === 0x80) {
            $cut = substr($cut, 0, -1);
        }
        return rtrim($cut, "\x80..\xFF") . '…';
    }
}
