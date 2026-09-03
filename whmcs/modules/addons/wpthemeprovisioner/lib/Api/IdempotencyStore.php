<?php
namespace Wtp\Addon\Api;

use PDOException;
use Wtp\Addon\Support\Db;
use Wtp\Shared\Support\Clock;
use Wtp\Shared\Support\Json;

/**
 * Request-level idempotency.
 *
 * A mutating call may carry `Idempotency-Key`. The first execution's response
 * is stored and replayed verbatim for every retry, so a network timeout on the
 * agent side can never produce a second import.
 *
 * Reusing a key with a *different* body is a client bug and is rejected with
 * 409 rather than silently returning the wrong answer.
 */
final class IdempotencyStore
{
    /** @var Db */
    private $db;
    /** @var Clock */
    private $clock;
    /** @var int */
    private $ttl;

    public function __construct(Db $db, Clock $clock, int $ttlSeconds = 86400)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->ttl = max(300, $ttlSeconds);
    }

    /**
     * @return array{status:int,body:array<string,mixed>}|null the stored response, if any
     * @throws ApiException 409 when the key was used with a different payload
     */
    public function lookup(int $jobId, string $scope, string $key, string $body): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM mod_wtp_idempotency WHERE job_id = :j AND scope = :s AND idem_key = :k',
            ['j' => $jobId, 's' => $scope, 'k' => $key]
        );
        if ($row === null) {
            return null;
        }
        if (strtotime((string) $row['expires_at'] . ' UTC') < $this->clock->now()) {
            $this->db->delete('mod_wtp_idempotency', ['id' => (int) $row['id']]);
            return null;
        }
        if (!hash_equals((string) $row['request_hash'], hash('sha256', $body))) {
            throw ApiException::conflict(
                'idempotency_key_reuse',
                'This Idempotency-Key was already used with a different request body'
            );
        }
        return [
            'status' => (int) $row['status_code'],
            'body' => Json::tryDecode((string) $row['response'], []),
        ];
    }

    /** @param array<string,mixed> $response */
    public function remember(int $jobId, string $scope, string $key, string $body, int $status, array $response): void
    {
        try {
            $this->db->insert('mod_wtp_idempotency', [
                'job_id' => $jobId,
                'scope' => substr($scope, 0, 96),
                'idem_key' => substr($key, 0, 128),
                'request_hash' => hash('sha256', $body),
                'status_code' => $status,
                'response' => Json::encode($response),
                'expires_at' => gmdate('Y-m-d H:i:s', $this->clock->now() + $this->ttl),
                'created_at' => $this->clock->nowSql(),
            ]);
        } catch (PDOException $e) {
            $sqlState = (string) $e->getCode();
            if ($sqlState !== '23000' && $sqlState !== '23505') {
                throw $e;
            }
            // Another concurrent request won the race; its stored response is
            // equivalent, so there is nothing to do.
        }
    }

    public function purgeExpired(): int
    {
        return $this->db->execute(
            'DELETE FROM mod_wtp_idempotency WHERE expires_at < :now',
            ['now' => $this->clock->nowSql()]
        );
    }
}
