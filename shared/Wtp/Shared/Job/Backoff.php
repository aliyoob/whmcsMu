<?php
namespace Wtp\Shared\Job;

/**
 * Exponential backoff with full-width jitter.
 *
 * delay(attempt) = clamp(base * 2^(attempt-1), base, cap) adjusted by ±jitter%.
 * Jitter avoids a thundering herd when a downstream service (cPanel, wordpress.org)
 * recovers and every parked job retries in the same second.
 */
final class Backoff
{
    /** @var int */
    private $baseSeconds;
    /** @var int */
    private $capSeconds;
    /** @var float 0..1 */
    private $jitter;
    /** @var callable():float returns 0..1 — injectable for deterministic tests */
    private $randomizer;

    public function __construct(int $baseSeconds = 15, int $capSeconds = 600, float $jitter = 0.2, ?callable $randomizer = null)
    {
        $this->baseSeconds = max(1, $baseSeconds);
        $this->capSeconds = max($this->baseSeconds, $capSeconds);
        $this->jitter = max(0.0, min(1.0, $jitter));
        $this->randomizer = $randomizer !== null ? $randomizer : static function (): float {
            return mt_rand(0, PHP_INT_MAX) / PHP_INT_MAX;
        };
    }

    public function delayFor(int $attempt): int
    {
        $attempt = max(1, $attempt);
        // 2^(attempt-1) overflows fast; cap the exponent.
        $exponent = min(24, $attempt - 1);
        $raw = $this->baseSeconds * (2 ** $exponent);
        $raw = (int) min($this->capSeconds, $raw);

        if ($this->jitter <= 0.0) {
            return $raw;
        }
        $rand = ($this->randomizer)();          // 0..1
        $factor = 1.0 + (($rand * 2.0) - 1.0) * $this->jitter;   // 1±jitter
        return max(1, (int) round($raw * $factor));
    }

    public function nextAttemptAt(int $now, int $attempt): int
    {
        return $now + $this->delayFor($attempt);
    }
}
