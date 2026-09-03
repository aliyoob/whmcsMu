<?php
namespace Wtp\Shared\Security;

/** Storage backend for the token bucket. Implemented against MySQL in WHMCS. */
interface RateLimitStore
{
    /** @return array{tokens:float,updated_at:int}|null */
    public function read(string $bucket): ?array;

    public function write(string $bucket, float $tokens, int $updatedAt, int $expiresAt): void;
}
