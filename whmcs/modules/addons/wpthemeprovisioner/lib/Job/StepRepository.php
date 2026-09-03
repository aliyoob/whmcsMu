<?php
namespace Wtp\Addon\Job;

use Wtp\Addon\Support\Db;
use Wtp\Shared\Job\JobStateMachine;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Job\StepStatus;
use Wtp\Shared\Support\Clock;
use Wtp\Shared\Support\Json;
use Wtp\Shared\Support\Str;

/** Per-step state: status, attempts, timing, resume cursor and last error. */
final class StepRepository
{
    /** @var Db */
    private $db;
    /** @var Clock */
    private $clock;
    /** @var JobStateMachine */
    private $sm;

    public function __construct(Db $db, Clock $clock, JobStateMachine $sm)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->sm = $sm;
    }

    /** Create the full step list for a new job. Safe to call twice. */
    public function initialise(int $jobId): void
    {
        $existing = $this->keysFor($jobId);
        foreach (StepCatalog::all() as $def) {
            if (in_array($def['key'], $existing, true)) {
                continue;
            }
            $this->db->insert('mod_wtp_job_steps', [
                'job_id' => $jobId,
                'step_key' => $def['key'],
                'seq' => $def['seq'],
                'owner' => $def['owner'],
                'status' => StepStatus::PENDING,
                'attempt' => 0,
                'max_attempts' => $def['max_attempts'],
                'timeout_seconds' => $def['timeout'],
                'progress' => 0,
                'created_at' => $this->clock->nowSql(),
                'updated_at' => $this->clock->nowSql(),
            ]);
        }
    }

    /** @return string[] */
    public function keysFor(int $jobId): array
    {
        $rows = $this->db->select('SELECT step_key FROM mod_wtp_job_steps WHERE job_id = :j', ['j' => $jobId]);
        return array_map(static function (array $r): string {
            return (string) $r['step_key'];
        }, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function forJob(int $jobId): array
    {
        return $this->db->select(
            'SELECT * FROM mod_wtp_job_steps WHERE job_id = :j ORDER BY seq ASC',
            ['j' => $jobId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $jobId, string $stepKey): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM mod_wtp_job_steps WHERE job_id = :j AND step_key = :k',
            ['j' => $jobId, 'k' => $stepKey]
        );
    }

    /**
     * The next step that still needs work, in sequence order. Returns null when
     * every step is terminal — which is what tells the job it is done.
     *
     * @return array<string,mixed>|null
     */
    public function nextActionable(int $jobId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM mod_wtp_job_steps
             WHERE job_id = :j AND status IN ('pending', 'running', 'failed')
             ORDER BY seq ASC LIMIT 1",
            ['j' => $jobId]
        );
    }

    public function allTerminal(int $jobId): bool
    {
        $pending = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM mod_wtp_job_steps
             WHERE job_id = :j AND status NOT IN ('completed', 'skipped')",
            ['j' => $jobId]
        );
        return $pending === 0;
    }

    /** @return array<string,mixed>|null the first failed step, if any */
    public function firstFailed(int $jobId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM mod_wtp_job_steps WHERE job_id = :j AND status = 'failed' ORDER BY seq ASC LIMIT 1",
            ['j' => $jobId]
        );
    }

    /** Mark a step as started, incrementing its attempt counter. */
    public function start(int $jobId, string $stepKey): array
    {
        $step = $this->requireStep($jobId, $stepKey);
        $this->sm->assertStepTransition($stepKey, (string) $step['status'], StepStatus::RUNNING);

        $this->db->update('mod_wtp_job_steps', [
            'status' => StepStatus::RUNNING,
            'attempt' => (int) $step['attempt'] + 1,
            'started_at' => $step['started_at'] ?? $this->clock->nowSql(),
            'heartbeat_at' => $this->clock->nowSql(),
            'error_code' => null,
            'error_message' => null,
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => (int) $step['id']]);

        return $this->requireStep($jobId, $stepKey);
    }

    /**
     * Report progress without changing the step's state. Also refreshes the
     * heartbeat, which is what stops the worker from declaring the step stalled.
     *
     * @param array<string,mixed>|null $cursor opaque resume position
     */
    public function progress(int $jobId, string $stepKey, int $percent, ?array $cursor = null): void
    {
        $step = $this->requireStep($jobId, $stepKey);
        $data = [
            'progress' => max(0, min(100, $percent)),
            'heartbeat_at' => $this->clock->nowSql(),
            'updated_at' => $this->clock->nowSql(),
        ];
        if ($cursor !== null) {
            $data['cursor'] = Str::limit(Json::encode($cursor), 60000);
        }
        $this->db->update('mod_wtp_job_steps', $data, ['id' => (int) $step['id']]);
    }

    /**
     * @param array<string,mixed> $meta
     * @return bool false when this was a repeat of an already-completed step
     */
    public function complete(int $jobId, string $stepKey, array $meta = []): bool
    {
        $step = $this->requireStep($jobId, $stepKey);
        $from = (string) $step['status'];

        if ($this->sm->isRepeatedTerminal($from, StepStatus::COMPLETED)) {
            return false;
        }
        $this->sm->assertStepTransition($stepKey, $from, StepStatus::COMPLETED);

        $this->db->update('mod_wtp_job_steps', [
            'status' => StepStatus::COMPLETED,
            'progress' => 100,
            'completed_at' => $this->clock->nowSql(),
            'heartbeat_at' => $this->clock->nowSql(),
            'error_code' => null,
            'error_message' => null,
            'meta' => $meta === [] ? $step['meta'] : Str::limit(Json::encode($meta), 16000),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => (int) $step['id']]);

        return true;
    }

    public function skip(int $jobId, string $stepKey, string $reason = ''): void
    {
        $step = $this->requireStep($jobId, $stepKey);
        if (StepStatus::isTerminal((string) $step['status'])) {
            return;
        }
        $this->sm->assertStepTransition($stepKey, (string) $step['status'], StepStatus::SKIPPED);

        $this->db->update('mod_wtp_job_steps', [
            'status' => StepStatus::SKIPPED,
            'progress' => 100,
            'completed_at' => $this->clock->nowSql(),
            'meta' => $reason === '' ? $step['meta'] : Json::encode(['skipped_because' => $reason]),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => (int) $step['id']]);
    }

    public function fail(int $jobId, string $stepKey, string $code, string $message): void
    {
        $step = $this->requireStep($jobId, $stepKey);
        $this->sm->assertStepTransition($stepKey, (string) $step['status'], StepStatus::FAILED);

        $this->db->update('mod_wtp_job_steps', [
            'status' => StepStatus::FAILED,
            'error_code' => substr($code, 0, 64),
            'error_message' => Str::limit($message, 2000),
            'heartbeat_at' => $this->clock->nowSql(),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => (int) $step['id']]);
    }

    /** Put a failed or stalled step back in the queue. */
    public function requeue(int $jobId, string $stepKey, bool $resetAttempts = false): void
    {
        $step = $this->requireStep($jobId, $stepKey);
        $this->sm->assertStepTransition($stepKey, (string) $step['status'], StepStatus::PENDING);

        $data = [
            'status' => StepStatus::PENDING,
            'heartbeat_at' => null,
            'updated_at' => $this->clock->nowSql(),
        ];
        if ($resetAttempts) {
            $data['attempt'] = 0;
            $data['progress'] = 0;
            $data['cursor'] = null;
            $data['error_code'] = null;
            $data['error_message'] = null;
            $data['started_at'] = null;
            $data['completed_at'] = null;
        }
        $this->db->update('mod_wtp_job_steps', $data, ['id' => (int) $step['id']]);
    }

    /**
     * Reset $stepKey and everything after it. This is what the admin "Retry
     * from here" button does — earlier work (the cPanel account, the WordPress
     * install) is expensive and correct, so it is deliberately left alone.
     */
    public function requeueFrom(int $jobId, string $stepKey): int
    {
        $count = 0;
        foreach (StepCatalog::from($stepKey) as $key) {
            $step = $this->find($jobId, $key);
            if ($step === null) {
                continue;
            }
            if ((string) $step['status'] === StepStatus::PENDING && (int) $step['attempt'] === 0) {
                continue;
            }
            $this->requeue($jobId, $key, true);
            $count++;
        }
        return $count;
    }

    /**
     * Steps that claim to be running but stopped sending heartbeats.
     *
     * @return array<int,array<string,mixed>>
     */
    public function stalled(int $limit = 50): array
    {
        $now = $this->clock->now();
        $rows = $this->db->select(
            "SELECT * FROM mod_wtp_job_steps WHERE status = 'running' ORDER BY id ASC LIMIT " . max(1, min(500, $limit))
        );

        $out = [];
        foreach ($rows as $row) {
            $beat = $row['heartbeat_at'] ?? $row['started_at'];
            if ($beat === null) {
                $out[] = $row;
                continue;
            }
            $age = $now - (int) strtotime((string) $beat . ' UTC');
            if ($age > (int) $row['timeout_seconds']) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function requireStep(int $jobId, string $stepKey): array
    {
        $step = $this->find($jobId, $stepKey);
        if ($step === null) {
            throw new JobException('Unknown step "' . $stepKey . '" for job ' . $jobId, 'step_not_found');
        }
        return $step;
    }
}
