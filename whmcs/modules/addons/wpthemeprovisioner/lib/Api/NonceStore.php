<?php
namespace Wtp\Addon\Api;

use PDOException;
use Wtp\Addon\Support\Db;
use Wtp\Shared\Support\Clock;

/**
 * Replay protection.
 *
 * The uniqueness constraint does the work: a second request carrying the same
 * (job, nonce) fails to insert and is rejected. Combined with the ±300 s
 * timestamp window in HmacSigner, an intercepted request can never be replayed
 * — inside the window the nonce is already burned, outside it the timestamp is.
 */
final class NonceStore
{
    /** @var Db */
    private $db;
    /** @var Clock */
    private $clock;
    /** @var int */
    private $ttl;

    public function __construct(Db $db, Clock $clock, int $ttlSeconds = 900)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->ttl = max(60, $ttlSeconds);
    }

    /** @return bool false when the nonce has already been used */
    public function claim(int $jobId, string $nonce): bool
    {
        try {
            $this->db->insert('mod_wtp_api_nonces', [
                'job_id' => $jobId,
                'nonce' => substr($nonce, 0, 32),
                'expires_at' => gmdate('Y-m-d H:i:s', $this->clock->now() + $this->ttl),
                'created_at' => $this->clock->nowSql(),
            ]);
            return true;
        } catch (PDOException $e) {
            // 23000/23505 = integrity constraint violation, i.e. a replay.
            $sqlState = (string) $e->getCode();
            if ($sqlState === '23000' || $sqlState === '23505') {
                return false;
            }
            throw $e;
        }
    }

    public function purgeExpired(): int
    {
        return $this->db->execute(
            'DELETE FROM mod_wtp_api_nonces WHERE expires_at < :now',
            ['now' => $this->clock->nowSql()]
        );
    }
}
