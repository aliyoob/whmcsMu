<?php
namespace Wtp\Addon\Api;

use Wtp\Addon\Support\Db;
use Wtp\Shared\Support\Clock;

/**
 * Download grants: the authority behind every signed package URL.
 *
 * A grant is job-bound, use-counted and short-lived. The URL signature proves
 * *who* is asking; the grant row decides *whether they may still ask*.
 */
final class GrantRepository
{
    /** @var Db */
    private $db;
    /** @var Clock */
    private $clock;

    public function __construct(Db $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    /**
     * @param array{kind:string,ref:string,sha256:string,size:int,filename:string} $asset
     */
    public function issue(int $jobId, array $asset, int $ttlSeconds, int $maxUses): int
    {
        return $this->db->insert('mod_wtp_download_grants', [
            'job_id' => $jobId,
            'asset_kind' => $asset['kind'],
            'asset_ref' => substr($asset['ref'], 0, 191),
            'sha256' => strtolower($asset['sha256']),
            'size_bytes' => max(0, $asset['size']),
            'filename' => substr($asset['filename'], 0, 191),
            'max_uses' => max(1, $maxUses),
            'used_count' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', $this->clock->now() + max(30, $ttlSeconds)),
            'created_at' => $this->clock->nowSql(),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $grantId): ?array
    {
        return $this->db->selectOne('SELECT * FROM mod_wtp_download_grants WHERE id = :id', ['id' => $grantId]);
    }

    /**
     * Validate and consume one use, atomically.
     *
     * The `used_count < max_uses` predicate lives inside the UPDATE so two
     * concurrent downloads cannot both slip through on the last use.
     *
     * @return array<string,mixed> the grant row
     * @throws ApiException
     */
    public function consume(int $grantId, int $jobId): array
    {
        return $this->db->transaction(function () use ($grantId, $jobId): array {
            $grant = $this->db->selectOne(
                'SELECT * FROM mod_wtp_download_grants WHERE id = :id' . $this->db->lockingSuffix(),
                ['id' => $grantId]
            );
            if ($grant === null) {
                throw ApiException::notFound('No such download grant');
            }
            if ((int) $grant['job_id'] !== $jobId) {
                // Signature already proved the job; this is defence in depth.
                throw ApiException::forbidden('This grant belongs to another job');
            }
            if ($grant['revoked_at'] !== null) {
                throw ApiException::gone('grant_revoked', 'This download link has been revoked');
            }
            if (strtotime((string) $grant['expires_at'] . ' UTC') < $this->clock->now()) {
                throw ApiException::gone('grant_expired', 'This download link has expired');
            }

            $affected = $this->db->execute(
                'UPDATE mod_wtp_download_grants
                 SET used_count = used_count + 1
                 WHERE id = :id AND used_count < max_uses',
                ['id' => $grantId]
            );
            if ($affected === 0) {
                throw ApiException::gone('grant_exhausted', 'This download link has already been used');
            }

            return $this->db->selectOne(
                'SELECT * FROM mod_wtp_download_grants WHERE id = :id',
                ['id' => $grantId]
            );
        });
    }

    public function revokeForJob(int $jobId): int
    {
        return $this->db->execute(
            'UPDATE mod_wtp_download_grants SET revoked_at = :now WHERE job_id = :j AND revoked_at IS NULL',
            ['now' => $this->clock->nowSql(), 'j' => $jobId]
        );
    }

    public function purgeExpired(int $graceSeconds = 86400): int
    {
        return $this->db->execute(
            'DELETE FROM mod_wtp_download_grants WHERE expires_at < :cutoff',
            ['cutoff' => gmdate('Y-m-d H:i:s', $this->clock->now() - max(0, $graceSeconds))]
        );
    }
}
