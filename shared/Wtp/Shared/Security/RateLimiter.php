<?php
namespace Wtp\Shared\Security;

use Wtp\Shared\Support\Clock;

/**
 * Token bucket. Capacity = burst, refill = capacity/windowSeconds per second.
 *
 * Used for: agent API calls per job, authentication failures per IP, download
 * grants per job, and bootstrap trigger attempts.
 */
final class RateLimiter
{
    /** @var RateLimitStore */
    private $store;
    /** @var Clock */
    private $clock;

    public function __construct(RateLimitStore $store, Clock $clock)
    {
        $this->store = $store;
        $this->clock = $clock;
    }

    /**
     * @return array{allowed:bool,remaining:float,retry_after:int}
     */
    public function consume(string $bucket, int $capacity, int $windowSeconds, float $cost = 1.0): array
    {
        $now = $this->clock->now();
        $rate = $capacity / max(1, $windowSeconds);

        $state = $this->store->read($bucket);
        if ($state === null) {
            $tokens = (float) $capacity;
            $last = $now;
        } else {
            $elapsed = max(0, $now - $state['updated_at']);
            $tokens = min((float) $capacity, $state['tokens'] + ($elapsed * $rate));
            $last = $now;
        }

        if ($tokens < $cost) {
            $deficit = $cost - $tokens;
            $retryAfter = (int) ceil($deficit / $rate);
            $this->store->write($bucket, $tokens, $last, $now + $windowSeconds * 2);
            return ['allowed' => false, 'remaining' => $tokens, 'retry_after' => max(1, $retryAfter)];
        }

        $tokens -= $cost;
        $this->store->write($bucket, $tokens, $last, $now + $windowSeconds * 2);
        return ['allowed' => true, 'remaining' => $tokens, 'retry_after' => 0];
    }
}
