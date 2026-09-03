<?php
namespace Wtp\Shared\Http;

use Wtp\Shared\Support\Bytes;
use Wtp\Shared\Support\Clock;

/**
 * Short-lived, job-bound download links.
 *
 *   sig = HMAC-SHA256(jobSecret, "WTPDL1\n{job}\n{grant}\n{exp}")
 *
 * The grant row carries the use counter and the expected checksum, so the URL
 * on its own conveys no authority beyond "this job may fetch this grant, once,
 * before this instant".
 */
final class SignedUrl
{
    public const SCHEME = 'WTPDL1';

    /** @var Clock */
    private $clock;

    public function __construct(Clock $clock)
    {
        $this->clock = $clock;
    }

    public function make(string $baseUrl, string $jobUuid, string $grantId, string $jobSecret, int $ttlSeconds): string
    {
        $exp = $this->clock->now() + max(30, $ttlSeconds);
        $sig = self::signature($jobUuid, $grantId, $exp, $jobSecret);

        $query = http_build_query([
            'job' => $jobUuid,
            'grant' => $grantId,
            'exp' => $exp,
            'sig' => $sig,
        ]);
        return rtrim($baseUrl, '?&') . (strpos($baseUrl, '?') === false ? '?' : '&') . $query;
    }

    /** @param array<string,string> $query */
    public function verify(array $query, string $jobSecret): bool
    {
        foreach (['job', 'grant', 'exp', 'sig'] as $k) {
            if (!isset($query[$k]) || $query[$k] === '') {
                return false;
            }
        }
        if (!preg_match('/^\d{1,12}$/', (string) $query['exp'])) {
            return false;
        }
        if ((int) $query['exp'] < $this->clock->now()) {
            return false;
        }
        $expected = self::signature(
            (string) $query['job'],
            (string) $query['grant'],
            (int) $query['exp'],
            $jobSecret
        );
        return Bytes::equals($expected, strtolower((string) $query['sig']));
    }

    public static function signature(string $jobUuid, string $grantId, int $exp, string $jobSecret): string
    {
        $canonical = implode("\n", [self::SCHEME, $jobUuid, $grantId, (string) $exp]);
        return hash_hmac('sha256', $canonical, $jobSecret);
    }
}
