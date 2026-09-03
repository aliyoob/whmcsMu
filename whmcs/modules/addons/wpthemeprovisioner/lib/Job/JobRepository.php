<?php
namespace Wtp\Addon\Job;

use Wtp\Addon\Support\Db;
use Wtp\Shared\Crypto\SecretBox;
use Wtp\Shared\Job\JobStateMachine;
use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Support\Bytes;
use Wtp\Shared\Support\Clock;
use Wtp\Shared\Support\Json;
use Wtp\Shared\Support\Str;

/**
 * Provisioning jobs: lifecycle, queueing and credential management.
 *
 * Two invariants this class exists to protect:
 *   1. a service has at most one active job (enforced with a locked read, so a
 *      duplicated CreateAccount call cannot install WordPress twice)
 *   2. an agent secret is never readable in the database, never logged, and
 *      stops working the moment the job reaches a terminal state
 */
final class JobRepository
{
    public const SECRET_CONTEXT = 'job-agent-secret';

    /** @var Db */
    private $db;
    /** @var Clock */
    private $clock;
    /** @var JobStateMachine */
    private $sm;
    /** @var StepRepository */
    private $steps;
    /** @var SecretBox */
    private $box;

    public function __construct(Db $db, Clock $clock, JobStateMachine $sm, StepRepository $steps, SecretBox $box)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->sm = $sm;
        $this->steps = $steps;
        $this->box = $box;
    }

    // --------------------------------------------------------------- lookup

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM mod_wtp_jobs WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        if (!Str::isUuid($uuid)) {
            return null;
        }
        return $this->db->selectOne('SELECT * FROM mod_wtp_jobs WHERE uuid = :u', ['u' => $uuid]);
    }

    /** @return array<string,mixed>|null the current non-terminal job for a service */
    public function activeForService(int $serviceId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM mod_wtp_jobs
             WHERE service_id = :s AND status NOT IN ('completed', 'cancelled')
             ORDER BY id DESC LIMIT 1",
            ['s' => $serviceId]
        );
    }

    /** @return array<string,mixed>|null the most recent job of any state */
    public function latestForService(int $serviceId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM mod_wtp_jobs WHERE service_id = :s ORDER BY id DESC LIMIT 1',
            ['s' => $serviceId]
        );
    }

    /**
     * @param array<string,mixed> $filters status, service_id, domain, q
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $bind = [];

        if (!empty($filters['status'])) {
            $statuses = (array) $filters['status'];
            $ph = [];
            foreach (array_values($statuses) as $i => $s) {
                if (!in_array($s, JobStatus::all(), true)) {
                    continue;
                }
                $ph[] = ':st' . $i;
                $bind['st' . $i] = $s;
            }
            if ($ph !== []) {
                $where[] = 'status IN (' . implode(', ', $ph) . ')';
            }
        }
        if (!empty($filters['service_id'])) {
            $where[] = 'service_id = :svc';
            $bind['svc'] = (int) $filters['service_id'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'client_id = :cli';
            $bind['cli'] = (int) $filters['client_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(domain LIKE :q OR uuid LIKE :q)';
            $bind['q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['q']) . '%';
        }

        $sql = 'SELECT * FROM mod_wtp_jobs'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);

        return $this->db->select($sql, $bind);
    }

    /** @param array<string,mixed> $filters */
    public function count(array $filters = []): int
    {
        $rows = $this->search($filters, 500, 0);
        return count($rows);
    }

    // --------------------------------------------------------------- create

    /**
     * Get the open job for a service, creating one only if there is none.
     *
     * This is the idempotency anchor for the whole system: WHMCS may call
     * CreateAccount more than once (retry after timeout, operator clicking
     * "Create" again, an API caller retrying) and every one of those must land
     * on the same job.
     *
     * @param array<string,mixed> $attributes service_id, order_id, client_id, product_id, server_id, domain, payload
     * @return array{job:array<string,mixed>,created:bool}
     */
    public function openFor(array $attributes): array
    {
        $serviceId = (int) ($attributes['service_id'] ?? 0);
        if ($serviceId <= 0) {
            throw new JobException('A provisioning job needs a service id', 'invalid_service');
        }

        return $this->db->transaction(function () use ($serviceId, $attributes): array {
            $existing = $this->db->selectOne(
                "SELECT * FROM mod_wtp_jobs
                 WHERE service_id = :s AND status NOT IN ('completed', 'cancelled')
                 ORDER BY id DESC LIMIT 1" . $this->db->lockingSuffix(),
                ['s' => $serviceId]
            );
            if ($existing !== null) {
                return ['job' => $existing, 'created' => false];
            }

            $now = $this->clock->nowSql();
            $id = $this->db->insert('mod_wtp_jobs', [
                'uuid' => Bytes::uuid4(),
                'service_id' => $serviceId,
                'order_id' => isset($attributes['order_id']) ? (int) $attributes['order_id'] : null,
                'client_id' => isset($attributes['client_id']) ? (int) $attributes['client_id'] : null,
                'product_id' => isset($attributes['product_id']) ? (int) $attributes['product_id'] : null,
                'server_id' => isset($attributes['server_id']) ? (int) $attributes['server_id'] : null,
                'domain' => substr((string) ($attributes['domain'] ?? ''), 0, 191),
                'status' => JobStatus::PENDING,
                'phase' => StepCatalog::ACCOUNT_CREATE,
                'attempt' => 0,
                'max_attempts' => (int) ($attributes['max_attempts'] ?? 5),
                'payload' => isset($attributes['payload'])
                    ? Str::limit(Json::encode($attributes['payload']), 200000) : null,
                'scheduled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->steps->initialise($id);

            return ['job' => $this->find($id), 'created' => true];
        });
    }

    // ----------------------------------------------------------- transitions

    /**
     * @param array<string,mixed> $extra additional columns to write atomically
     */
    public function transition(int $jobId, string $to, ?string $phase = null, array $extra = []): array
    {
        $job = $this->require($jobId);
        $from = (string) $job['status'];

        $this->sm->assertTransition($from, $to);

        $data = array_merge($extra, [
            'status' => $to,
            'updated_at' => $this->clock->nowSql(),
        ]);
        if ($phase !== null) {
            $data['phase'] = substr($phase, 0, 64);
        }
        if ($to === JobStatus::RUNNING && $job['started_at'] === null) {
            $data['started_at'] = $this->clock->nowSql();
        }
        if (JobStatus::isTerminal($to) || $to === JobStatus::FAILED) {
            $data['completed_at'] = $this->clock->nowSql();
        }

        $this->db->update('mod_wtp_jobs', $data, ['id' => $jobId]);

        // A finished job must not keep a usable credential lying around.
        if (JobStatus::isTerminal($to)) {
            $this->revokeSecret($jobId);
        }

        return $this->require($jobId);
    }

    public function setPhase(int $jobId, string $phase): void
    {
        $this->db->update('mod_wtp_jobs', [
            'phase' => substr($phase, 0, 64),
            'heartbeat_at' => $this->clock->nowSql(),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);
    }

    public function heartbeat(int $jobId): void
    {
        $this->db->update('mod_wtp_jobs', [
            'heartbeat_at' => $this->clock->nowSql(),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);
    }

    public function scheduleAt(int $jobId, int $timestamp): void
    {
        $this->db->update('mod_wtp_jobs', [
            'scheduled_at' => gmdate('Y-m-d H:i:s', $timestamp),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);
    }

    public function recordError(int $jobId, string $code, string $message): void
    {
        $this->db->update('mod_wtp_jobs', [
            'last_error_code' => substr($code, 0, 64),
            'last_error' => Str::limit($message, 2000),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);
    }

    public function clearError(int $jobId): void
    {
        $this->db->update('mod_wtp_jobs', [
            'last_error_code' => null,
            'last_error' => null,
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);
    }

    public function incrementAttempt(int $jobId): int
    {
        $job = $this->require($jobId);
        $attempt = (int) $job['attempt'] + 1;
        $this->db->update('mod_wtp_jobs', [
            'attempt' => $attempt,
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);
        return $attempt;
    }

    /** @param array<string,mixed> $result */
    public function setResult(int $jobId, array $result): void
    {
        $this->db->update('mod_wtp_jobs', [
            'result' => Str::limit(Json::encode($result), 60000),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);
    }

    /** @return array<string,mixed> */
    public function payload(int $jobId): array
    {
        $job = $this->require($jobId);
        return Json::tryDecode($job['payload'] ?? null, []);
    }

    /** @param array<string,mixed> $payload */
    public function mergePayload(int $jobId, array $payload): void
    {
        $current = $this->payload($jobId);
        $this->db->update('mod_wtp_jobs', [
            'payload' => Str::limit(Json::encode(array_replace($current, $payload)), 200000),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);
    }

    // ------------------------------------------------------------- queueing

    /**
     * Jobs whose scheduled time has arrived, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function due(int $limit = 10): array
    {
        return $this->db->select(
            "SELECT * FROM mod_wtp_jobs
             WHERE status IN ('pending', 'running', 'awaiting_agent', 'importing', 'verifying')
               AND (scheduled_at IS NULL OR scheduled_at <= :now)
             ORDER BY scheduled_at ASC, id ASC
             LIMIT " . max(1, min(200, $limit)),
            ['now' => $this->clock->nowSql()]
        );
    }

    // -------------------------------------------------------- bootstrap token

    /**
     * Mint the single-use token the dropper hands to the agent.
     *
     * @return string the clear token — returned once, never stored, never logged
     */
    public function issueBootstrapToken(int $jobId, int $ttlSeconds, string $bootstrapFile): string
    {
        $token = Bytes::token(32);
        $this->db->update('mod_wtp_jobs', [
            'bootstrap_token_hash' => hash('sha256', $token),
            'bootstrap_expires_at' => gmdate('Y-m-d H:i:s', $this->clock->now() + max(60, $ttlSeconds)),
            'bootstrap_used_at' => null,
            'bootstrap_file' => substr($bootstrapFile, 0, 191),
            'bootstrap_runs' => 0,
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);

        return $token;
    }

    /**
     * Verify and atomically consume a bootstrap token.
     *
     * @return array<string,mixed> the job row
     * @throws JobException with a code the API maps to 401/410
     */
    public function consumeBootstrapToken(string $uuid, string $token): array
    {
        return $this->db->transaction(function () use ($uuid, $token): array {
            $job = $this->db->selectOne(
                'SELECT * FROM mod_wtp_jobs WHERE uuid = :u LIMIT 1' . $this->db->lockingSuffix(),
                ['u' => $uuid]
            );
            if ($job === null) {
                throw new JobException('No such job', 'invalid_token');
            }
            if (empty($job['bootstrap_token_hash'])) {
                throw new JobException('No bootstrap token is outstanding', 'invalid_token');
            }
            if (!Bytes::equals((string) $job['bootstrap_token_hash'], hash('sha256', $token))) {
                throw new JobException('Bootstrap token mismatch', 'invalid_token');
            }
            if ($job['bootstrap_used_at'] !== null) {
                throw new JobException('Bootstrap token has already been used', 'invalid_token');
            }
            if ($job['bootstrap_expires_at'] !== null
                && strtotime((string) $job['bootstrap_expires_at'] . ' UTC') < $this->clock->now()) {
                throw new JobException('Bootstrap token has expired', 'invalid_token');
            }
            if (JobStatus::isTerminal((string) $job['status'])) {
                throw new JobException('Job is no longer active', 'job_cancelled');
            }

            $this->db->update('mod_wtp_jobs', [
                'bootstrap_used_at' => $this->clock->nowSql(),
                'updated_at' => $this->clock->nowSql(),
            ], ['id' => (int) $job['id']]);

            return $this->require((int) $job['id']);
        });
    }

    public function bootstrapRunConsumed(int $jobId, int $maxRuns = 40): bool
    {
        $job = $this->require($jobId);
        $runs = (int) $job['bootstrap_runs'] + 1;
        $this->db->update('mod_wtp_jobs', [
            'bootstrap_runs' => $runs,
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);
        return $runs <= $maxRuns;
    }

    // ---------------------------------------------------------- agent secret

    /**
     * Generate and store the per-job HMAC secret.
     *
     * @return string the clear secret — the only time it exists outside the ciphertext
     */
    public function issueAgentSecret(int $jobId, int $ttlSeconds): string
    {
        $secret = Bytes::token(32);

        $this->db->update('mod_wtp_jobs', [
            'secret_ciphertext' => $this->box->encrypt($secret, self::SECRET_CONTEXT),
            'secret_fingerprint' => Bytes::fingerprint($secret),
            'secret_issued_at' => $this->clock->nowSql(),
            'secret_expires_at' => gmdate('Y-m-d H:i:s', $this->clock->now() + max(300, $ttlSeconds)),
            'secret_revoked_at' => null,
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);

        return $secret;
    }

    /**
     * @param array<string,mixed> $job
     * @throws JobException when the credential is missing, expired or revoked
     */
    public function agentSecret(array $job): string
    {
        if (empty($job['secret_ciphertext'])) {
            throw new JobException('No agent secret has been issued', 'invalid_token');
        }
        if (!empty($job['secret_revoked_at'])) {
            throw new JobException('Agent secret has been revoked', 'invalid_token');
        }
        if (!empty($job['secret_expires_at'])
            && strtotime((string) $job['secret_expires_at'] . ' UTC') < $this->clock->now()) {
            throw new JobException('Agent secret has expired', 'invalid_token');
        }
        return $this->box->decrypt((string) $job['secret_ciphertext'], self::SECRET_CONTEXT);
    }

    /**
     * The key that signs download URLs.
     *
     * Deliberately *not* the agent secret: the installer needs a signed link
     * for the agent package before any agent exists, and a link minted for the
     * agent must keep working across a secret rotation. Derived from the master
     * key and the job uuid, so it needs no storage and cannot be leaked by a
     * database dump alone.
     */
    public function downloadKey(array $job): string
    {
        return bin2hex($this->box->derive('download:' . (string) $job['uuid']));
    }

    public function revokeSecret(int $jobId): void
    {
        $this->db->update('mod_wtp_jobs', [
            'secret_revoked_at' => $this->clock->nowSql(),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $jobId]);
    }

    /** Operator action: issue a fresh secret and invalidate the old one. */
    public function rotateSecret(int $jobId, int $ttlSeconds): string
    {
        $this->revokeSecret($jobId);
        return $this->issueAgentSecret($jobId, $ttlSeconds);
    }

    // ------------------------------------------------------------- utilities

    /** @return array<string,mixed> */
    public function require(int $jobId): array
    {
        $job = $this->find($jobId);
        if ($job === null) {
            throw new JobException('Job ' . $jobId . ' does not exist', 'job_not_found');
        }
        return $job;
    }

    public function delete(int $jobId): void
    {
        $this->db->delete('mod_wtp_jobs', ['id' => $jobId]);
    }
}
