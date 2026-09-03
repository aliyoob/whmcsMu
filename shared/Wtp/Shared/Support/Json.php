<?php
namespace Wtp\Shared\Support;

use InvalidArgumentException;

/**
 * Strict JSON helpers. Encoding never emits invalid UTF-8 or escaped slashes,
 * decoding never returns objects (arrays only) and never silently returns null.
 */
final class Json
{
    public const MAX_DEPTH = 64;

    /** @param mixed $value */
    public static function encode($value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($value, $flags, self::MAX_DEPTH);
        if ($json === false) {
            throw new InvalidArgumentException('JSON encode failed: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * @return array<string,mixed>|array<int,mixed>
     * @throws InvalidArgumentException when the payload is not a JSON array/object.
     */
    public static function decode(string $json, int $maxBytes = 1048576): array
    {
        if ($json === '') {
            return [];
        }
        if (strlen($json) > $maxBytes) {
            throw new InvalidArgumentException('JSON payload exceeds ' . $maxBytes . ' bytes');
        }
        $data = json_decode($json, true, self::MAX_DEPTH);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('JSON decode failed: ' . json_last_error_msg());
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException('JSON payload must be an object or array');
        }
        return $data;
    }

    /** Decode, but return $default instead of throwing. */
    public static function tryDecode(?string $json, array $default = []): array
    {
        if ($json === null || $json === '') {
            return $default;
        }
        try {
            return self::decode($json);
        } catch (InvalidArgumentException $e) {
            return $default;
        }
    }
}
