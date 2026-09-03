<?php
namespace Wtp\Addon\Job;

use Throwable;
use Wtp\Addon\Container;
use Wtp\Addon\Panel\PanelException;
use Wtp\Addon\Panel\PanelFactory;
use Wtp\Addon\Provision\StepContext;
use Wtp\Addon\Provision\StepHandler;
use Wtp\Addon\Provision\StepOutcome;
use Wtp\Shared\Job\ErrorClassifier;
use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Job\StepStatus;

/**
 * Control loop A: the WHMCS-side queue worker.
 *
 * Invoked once a minute by cron. Each tick:
 *   1. recovers steps whose owner stopped sending heartbeats
 *   2. advances every due job by at most one step
 *   3. runs housekeeping on the security tables
 *
 * It never blocks on anything slow: a step that needs more time returns
 * `waiting` and is picked up on a later tick. That is what keeps a stuck
 * customer server from stalling every other order on the queue.
 */
final class Worker
{
    /** @var Container */
    private $container;
    /** @var PanelFactory */
    private $panels;
    /** @var array<string,StepHandler> step key => handler */
    private $handlers = [];
    /** @var callable|null receives progress lines for CLI output */
    private $reporter;

    public function __construct(Container $container, PanelFactory $panels, ?callable $reporter = null)
    {
        $this->container = $container;
        $this->panels = $panels;
        $this->reporter = $reporter;
    }

    public function register(StepHandler $handler): void
    {
        foreach ($handler->handles() as $key) {
            $this->handlers[$key] = $handler;
        }
    }

    /**
     * Run one tick.
     *
     * @return array{jobs:int,steps:int,failed:int,recovered:int,purged:int}
     */
    public function tick(?int $limit = null): array
    {
        $summary = ['jobs' => 0, 'steps' => 0, 'failed' => 0, 'recovered' => 0, 'purged' => 0];

        $summary['recovered'] = $this->recoverStalledSteps();

        $limit = $limit ?? $this->container->config()->getInt('worker_batch', 10);
        foreach ($this->container->jobs()->due($limit) as $job) {
            $summary['jobs']++;
            try {
                if ($this->advance($job)) {
                    $summary['steps']++;
                }
            } catch (Throwable $e) {
                $summary['failed']++;
                $this->handleUnexpected($job, $e);
            }
        }

        $summary['purged'] = $this->housekeeping();

        return $summary;
    }

    /**
     * Advance one job by at most one step.
     *
     * @param array<string,mixed> $job
     * @return bool whether a step was actually executed
     */
    public function advance(array $job): bool
    {
        $jobs = $this->container->jobs();
        $steps = $this->container->steps();
        $jobId = (int) $job['id'];

        $job = $jobs->require($jobId);
        if (!JobStatus::isActive((string) $job['status'])) {
            return false;
        }

        $step = $steps->nextActionable($jobId);
        if ($step === null) {
            $this->finish($job);
            return false;
        }

        $stepKey = (string) $step['step_key'];
        $jobs->setPhase($jobId, $stepKey);

        // Agent-owned steps are not ours to run; we only watch the clock.
        if ((string) $step['owner'] === 'agent') {
            $this->superviseAgentStep($job, $step);
            return false;
        }

        if (!isset($this->handlers[$stepKey])) {
            $this->report('no handler for ' . $stepKey . ' (job ' . $jobId . ')');
            $steps->skip($jobId, $stepKey, 'no handler registered');
            return false;
        }

        if ((string) $job['status'] === JobStatus::PENDING) {
            $jobs->transition($jobId, JobStatus::RUNNING, $stepKey);
            $job = $jobs->require($jobId);
        } elseif (StepCatalog::statusFor($stepKey) !== (string) $job['status']) {
            try {
                $jobs->transition($jobId, StepCatalog::statusFor($stepKey), $stepKey);
                $job = $jobs->require($jobId);
            } catch (\DomainException $e) {
                unset($e); // an unexpected but harmless status; the step still runs
            }
        }

        // A step that has exhausted its attempts must not be started again.
        if ((int) $step['attempt'] >= (int) $step['max_attempts']
            && (string) $step['status'] === StepStatus::FAILED) {
            $this->failStep($job, $step, (string) ($step['error_code'] ?? 'attempts_exhausted'),
                (string) ($step['error_message'] ?? 'No attempts remaining'), true);
            return false;
        }

        $step = $steps->start($jobId, $stepKey);

        $panel = null;
        if ($job['server_id'] !== null) {
            try {
                $panel = $this->panels->forServer((int) $job['server_id']);
            } catch (PanelException $e) {
                $this->failStep($job, $step, $e->errorCode(), $e->getMessage(), false);
                return true;
            }
        }

        $context = new StepContext($this->container, $job, $step, $panel);

        try {
            $outcome = $this->handlers[$stepKey]->execute($context);
        } catch (PanelException $e) {
            $outcome = StepOutcome::failed($e->errorCode(), $e->getMessage());
        } catch (JobException $e) {
            $outcome = StepOutcome::failed($e->errorCode(), $e->getMessage());
        } catch (Throwable $e) {
            $this->container->eventLog()->error($jobId, 'Unhandled error in ' . $stepKey, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ], $stepKey, 'handler_exception');
            $outcome = StepOutcome::failed('handler_exception', $e->getMessage());
        }

        $this->applyOutcome($job, $step, $outcome);
        return true;
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $step
     */
    private function applyOutcome(array $job, array $step, StepOutcome $outcome): void
    {
        $jobId = (int) $job['id'];
        $stepKey = (string) $step['step_key'];
        $jobs = $this->container->jobs();
        $steps = $this->container->steps();

        if ($outcome->isCompleted()) {
            $steps->complete($jobId, $stepKey, $outcome->meta());
            $jobs->clearError($jobId);
            $jobs->scheduleAt($jobId, $this->container->clock()->now());
            $this->report('job ' . $jobId . ': ' . $stepKey . ' completed');

            $next = $steps->nextActionable($jobId);
            if ($next === null) {
                $this->finish($jobs->require($jobId));
            }
            return;
        }

        if ($outcome->isSkipped()) {
            $steps->skip($jobId, $stepKey, $outcome->message());
            $jobs->scheduleAt($jobId, $this->container->clock()->now());
            $this->report('job ' . $jobId . ': ' . $stepKey . ' skipped');
            return;
        }

        if ($outcome->isWaiting()) {
            $steps->progress($jobId, $stepKey, $outcome->progress());
            // Put the step back in the queue so the attempt counter reflects
            // real attempts rather than counting a poll as a failure.
            $steps->requeue($jobId, $stepKey);
            $jobs->scheduleAt($jobId, $this->container->clock()->now() + $outcome->retryAfter());
            $this->report('job ' . $jobId . ': ' . $stepKey . ' waiting ' . $outcome->retryAfter() . 's');
            return;
        }

        $this->failStep($job, $step, $outcome->code(), $outcome->message(), false);
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $step
     */
    private function failStep(array $job, array $step, string $code, string $message, bool $exhausted): void
    {
        $jobId = (int) $job['id'];
        $stepKey = (string) $step['step_key'];
        $jobs = $this->container->jobs();
        $steps = $this->container->steps();
        $log = $this->container->eventLog();
        $classifier = $this->container->errorClassifier();

        $current = $steps->find($jobId, $stepKey);
        $attempt = $current === null ? (int) $step['attempt'] : (int) $current['attempt'];
        $maxAttempts = (int) $step['max_attempts'];

        if ($current !== null && (string) $current['status'] !== StepStatus::FAILED) {
            $steps->fail($jobId, $stepKey, $code, $message);
        }
        $jobs->recordError($jobId, $code, $message);
        $log->error($jobId, 'Step ' . $stepKey . ' failed', [
            'error_code' => $code,
            'error_message' => $message,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
        ], $stepKey, $code);

        $retry = !$exhausted && $classifier->shouldRetry($code, $attempt, $maxAttempts, $message);

        if ($retry) {
            $delay = $this->container->backoff()->delayFor($attempt + 1);
            $steps->requeue($jobId, $stepKey);
            $jobs->scheduleAt($jobId, $this->container->clock()->now() + $delay);
            $log->info($jobId, 'Retrying ' . $stepKey . ' in ' . $delay . 's', [
                'attempt' => $attempt + 1,
            ], $stepKey);
            $this->report('job ' . $jobId . ': ' . $stepKey . ' failed (' . $code . '), retry in ' . $delay . 's');
            return;
        }

        $definition = StepCatalog::get($stepKey);
        if ($definition !== null && $definition['optional']) {
            $steps->skip($jobId, $stepKey, 'failed after ' . $attempt . ' attempts: ' . $code);
            $jobs->scheduleAt($jobId, $this->container->clock()->now());
            $log->warning($jobId, 'Skipping optional step ' . $stepKey, ['error_code' => $code], $stepKey, $code);
            return;
        }

        $target = $classifier->classify($code, $message) === ErrorClassifier::MANUAL
            ? JobStatus::NEEDS_ATTENTION
            : JobStatus::FAILED;

        try {
            $jobs->transition($jobId, $target, $stepKey);
        } catch (\DomainException $e) {
            unset($e);
        }
        $this->report('job ' . $jobId . ': ' . $stepKey . ' ' . $target . ' (' . $code . ')');
    }

    /**
     * Agent-owned steps advance from the other side. Our job is to notice when
     * they stop advancing.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $step
     */
    private function superviseAgentStep(array $job, array $step): void
    {
        $jobs = $this->container->jobs();
        $jobId = (int) $job['id'];
        $stepKey = (string) $step['step_key'];
        $now = $this->container->clock()->now();

        $reference = $step['heartbeat_at'] ?? ($step['started_at'] ?? $job['updated_at']);
        $age = $reference === null ? 0 : $now - (int) strtotime((string) $reference . ' UTC');
        $timeout = (int) $step['timeout_seconds'];

        if ($age <= $timeout) {
            $jobs->scheduleAt($jobId, $now + min(60, max(15, (int) ceil(($timeout - $age) / 2))));
            return;
        }

        $attempt = (int) $step['attempt'];
        $maxAttempts = (int) $step['max_attempts'];

        if ($attempt >= $maxAttempts) {
            $this->failStep(
                $job,
                $step,
                'agent_not_ready',
                'The site stopped reporting progress on ' . $stepKey . ' after ' . $attempt . ' attempts',
                true
            );
            return;
        }

        $this->container->eventLog()->warning($jobId, 'Agent went quiet on ' . $stepKey, [
            'silent_for_seconds' => $age,
            'timeout' => $timeout,
            'attempt' => $attempt,
        ], $stepKey, 'agent_not_ready');

        // Reset to pending so the agent picks the step up again on its next tick.
        $this->container->steps()->requeue($jobId, $stepKey);
        $jobs->scheduleAt($jobId, $now + $this->container->backoff()->delayFor($attempt + 1));
    }

    /** @param array<string,mixed> $job */
    private function finish(array $job): void
    {
        $jobId = (int) $job['id'];
        $steps = $this->container->steps();

        $failed = $steps->firstFailed($jobId);
        if ($failed !== null) {
            return; // a failed step keeps the job open for retry
        }
        if (!$steps->allTerminal($jobId)) {
            return;
        }
        if (JobStatus::isTerminal((string) $job['status'])) {
            return;
        }

        $this->container->jobs()->transition($jobId, JobStatus::COMPLETED, StepCatalog::JOB_VERIFY);
        $this->container->selections()->lock((int) $job['service_id']);
        $this->container->grants()->revokeForJob($jobId);
        $this->container->eventLog()->info($jobId, 'Provisioning completed', [
            'domain' => (string) $job['domain'],
        ]);
        $this->report('job ' . $jobId . ': completed');
    }

    /**
     * Steps whose owner died mid-flight. Resetting them to pending is safe
     * because every handler and every importer is idempotent.
     */
    private function recoverStalledSteps(): int
    {
        $recovered = 0;
        foreach ($this->container->steps()->stalled(50) as $step) {
            $jobId = (int) $step['job_id'];
            $stepKey = (string) $step['step_key'];

            $this->container->eventLog()->warning($jobId, 'Recovering a stalled step', [
                'step' => $stepKey,
                'timeout' => (int) $step['timeout_seconds'],
                'attempt' => (int) $step['attempt'],
            ], $stepKey, 'step_stalled');

            $this->container->steps()->requeue($jobId, $stepKey);
            $this->container->jobs()->scheduleAt($jobId, $this->container->clock()->now());
            $recovered++;
        }
        return $recovered;
    }

    /** @param array<string,mixed> $job */
    private function handleUnexpected(array $job, Throwable $e): void
    {
        $jobId = (int) $job['id'];
        $this->container->eventLog()->critical($jobId, 'The worker hit an unexpected error', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ], null, 'worker_exception');

        $this->container->jobs()->recordError($jobId, 'worker_exception', $e->getMessage());
        // Back off rather than hot-looping on a job that breaks the worker.
        $this->container->jobs()->scheduleAt($jobId, $this->container->clock()->now() + 300);
        $this->report('job ' . $jobId . ': worker exception — ' . $e->getMessage());
    }

    /** Expire the short-lived security rows. Cheap, and keeps the tables small. */
    public function housekeeping(): int
    {
        $purged = 0;
        try {
            $purged += $this->container->nonces()->purgeExpired();
            $purged += $this->container->idempotency()->purgeExpired();
            $purged += $this->container->grants()->purgeExpired();

            $retention = $this->container->config()->getInt('log_retention_days', 60);
            if ($retention > 0 && random_int(1, 60) === 1) {
                // Once an hour on average; the event table is the big one.
                $purged += $this->container->eventLog()->purgeOlderThan($retention);
            }
        } catch (Throwable $e) {
            error_log('[WTP] housekeeping failed: ' . $e->getMessage());
        }
        return $purged;
    }

    private function report(string $line): void
    {
        if ($this->reporter !== null) {
            ($this->reporter)($line);
        }
    }
}
