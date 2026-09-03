<?php
namespace Wtp\Shared\Http;

use Wtp\Shared\Support\Bytes;
use Wtp\Shared\Support\Clock;

/**
 * Signs and verifies agent -> WHMCS requests.
 *
 * Verification failures are deliberately coarse: the caller learns *that* the
 * request was rejected, never *why*, to avoid an oracle. The precise reason is
 * available to the server via getLastFailureCode() for its own logs.
 */
final class HmacSigner
{
    public const HEADER_JOB = 'X-WTP-Job';
    public const HEADER_TIMESTAMP = 'X-WTP-Timestamp';
    public const HEADER_NONCE = 'X-WTP-Nonce';
    public const HEADER_SIGNATURE = 'X-WTP-Signature';

    public const DEFAULT_SKEW = 300;

    /** @var Clock */
    private $clock;
    /** @var int */
    private $skew;
    /** @var string|null */
    private $lastFailureCode;

    public function __construct(Clock $clock, int $skewSeconds = self::DEFAULT_SKEW)
    {
        $this->clock = $clock;
        $this->skew = $skewSeconds;
    }

    /**
     * @return array<string,string> headers to add to the outgoing request
     */
    public function sign(string $secret, string $jobUuid, string $method, string $path, string $body): array
    {
        $ts = $this->clock->now();
        $nonce = Bytes::hex(16);
        $canonical = CanonicalRequest::build($method, $path, $ts, $nonce, $body);
        $sig = hash_hmac('sha256', $canonical, $secret);

        return [
            self::HEADER_JOB => $jobUuid,
            self::HEADER_TIMESTAMP => (string) $ts,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => 'v1=' . $sig,
        ];
    }

    /**
     * @param array<string,string> $headers case-insensitive header map
     */
    public function verify(string $secret, string $method, string $path, string $body, array $headers): bool
    {
        $this->lastFailureCode = null;
        $h = self::normalizeHeaders($headers);

        $ts = isset($h['x-wtp-timestamp']) ? $h['x-wtp-timestamp'] : '';
        $nonce = isset($h['x-wtp-nonce']) ? $h['x-wtp-nonce'] : '';
        $sig = isset($h['x-wtp-signature']) ? $h['x-wtp-signature'] : '';

        if ($ts === '' || $nonce === '' || $sig === '') {
            $this->lastFailureCode = 'missing_headers';
            return false;
        }
        if (!preg_match('/^-?\d{1,12}$/', $ts)) {
            $this->lastFailureCode = 'bad_timestamp';
            return false;
        }
        if (!preg_match('/^[a-f0-9]{16,64}$/i', $nonce)) {
            $this->lastFailureCode = 'bad_nonce';
            return false;
        }
        if (strpos($sig, 'v1=') !== 0) {
            $this->lastFailureCode = 'bad_scheme';
            return false;
        }
        $provided = substr($sig, 3);
        if (!preg_match('/^[a-f0-9]{64}$/i', $provided)) {
            $this->lastFailureCode = 'bad_signature_format';
            return false;
        }

        $delta = abs($this->clock->now() - (int) $ts);
        if ($delta > $this->skew) {
            $this->lastFailureCode = 'timestamp_out_of_window';
            return false;
        }

        $canonical = CanonicalRequest::build($method, $path, (int) $ts, $nonce, $body);
        $expected = hash_hmac('sha256', $canonical, $secret);

        if (!Bytes::equals($expected, strtolower($provided))) {
            $this->lastFailureCode = 'signature_mismatch';
            return false;
        }
        return true;
    }

    public function getLastFailureCode(): ?string
    {
        return $this->lastFailureCode;
    }

    /** @param array<string,string> $headers @return array<string,string> */
    public static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[strtolower(str_replace('_', '-', (string) $k))] = is_array($v) ? (string) reset($v) : (string) $v;
        }
        return $out;
    }
}
