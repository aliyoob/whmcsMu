<?php
namespace Wtp\Addon\Api;

use PDOException;
use Wtp\Addon\Support\Db;
use Wtp\Shared\Security\RateLimitStore;

/** MySQL-backed token bucket storage. */
final class DbRateLimitStore implements RateLimitStore
{
    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /** @return array{tokens:float,updated_at:int}|null */
    public function read(string $bucket): ?array
    {
        $row = $this->db->selectOne(
            'SELECT tokens, updated_at FROM mod_wtp_rate_limits WHERE bucket = :b',
            ['b' => $this->key($bucket)]
        );
        if ($row === null) {
            return null;
        }
        return [
            'tokens' => (float) $row['tokens'],
            'updated_at' => (int) strtotime((string) $row['updated_at'] . ' UTC'),
        ];
    }

    public function write(string $bucket, float $tokens, int $updatedAt, int $expiresAt): void
    {
        $key = $this->key($bucket);
        $data = [
            'tokens' => number_format($tokens, 3, '.', ''),
            'updated_at' => gmdate('Y-m-d H:i:s', $updatedAt),
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
        ];

        $affected = $this->db->update('mod_wtp_rate_limits', $data, ['bucket' => $key]);
        if ($affected > 0) {
            return;
        }
        try {
            $data['bucket'] = $key;
            $this->db->insert('mod_wtp_rate_limits', $data);
        } catch (PDOException $e) {
            $sqlState = (string) $e->getCode();
            if ($sqlState !== '23000' && $sqlState !== '23505') {
                throw $e;
            }
            $this->db->update('mod_wtp_rate_limits', $data, ['bucket' => $key]);
        }
    }

    public function purgeExpired(string $nowSql): int
    {
        return $this->db->execute(
            'DELETE FROM mod_wtp_rate_limits WHERE expires_at < :now',
            ['now' => $nowSql]
        );
    }

    private function key(string $bucket): string
    {
        return strlen($bucket) > 191 ? substr($bucket, 0, 150) . ':' . substr(sha1($bucket), 0, 20) : $bucket;
    }
}
