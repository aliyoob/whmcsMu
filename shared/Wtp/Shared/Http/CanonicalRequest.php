<?php
namespace Wtp\Shared\Http;

/**
 * The exact bytes both sides agree to sign. Any change here is a breaking
 * protocol change and must move the scheme prefix (WTP1 -> WTP2).
 *
 *   WTP1 \n METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256hex(body)
 *
 * PATH is the request path only (no scheme, host, or query string); query
 * parameters are not signed because the agent never uses them on HMAC routes.
 */
final class CanonicalRequest
{
    public const SCHEME = 'WTP1';

    public static function build(string $method, string $path, int $timestamp, string $nonce, string $body): string
    {
        $method = strtoupper($method);
        $path = '/' . ltrim(self::stripQuery($path), '/');
        return implode("\n", [
            self::SCHEME,
            $method,
            $path,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function stripQuery(string $path): string
    {
        $pos = strpos($path, '?');
        return $pos === false ? $path : substr($path, 0, $pos);
    }
}
