<?php
namespace Wtp\Shared\Job;

/**
 * The only place a job's status is allowed to change.
 *
 * Illegal transitions throw. That is deliberate: a silent, wrong transition
 * would leave a customer's site half-imported with a green tick next to it.
 */
final class JobStateMachine
{
    /** @var array<string,string[]> */
    private const TRANSITIONS = [
        // AWAITING_AGENT is reachable straight from PENDING on purpose: the
        // installer can finish and the agent can complete its handshake before
        // the worker's next tick has moved the job to RUNNING. That race is
        // normal, not an error.
        JobStatus::PENDING => [
            JobStatus::RUNNING, JobStatus::AWAITING_AGENT,
            JobStatus::CANCELLED, JobStatus::FAILED,
        ],
        JobStatus::RUNNING => [
            JobStatus::RUNNING, JobStatus::AWAITING_AGENT, JobStatus::IMPORTING,
            JobStatus::VERIFYING, JobStatus::COMPLETED, JobStatus::FAILED,
            JobStatus::NEEDS_ATTENTION, JobStatus::CANCELLED, JobStatus::PENDING,
        ],
        JobStatus::AWAITING_AGENT => [
            JobStatus::AWAITING_AGENT, JobStatus::RUNNING, JobStatus::IMPORTING,
            JobStatus::VERIFYING, JobStatus::COMPLETED, JobStatus::FAILED,
            JobStatus::NEEDS_ATTENTION, JobStatus::CANCELLED,
        ],
        JobStatus::IMPORTING => [
            JobStatus::IMPORTING, JobStatus::RUNNING, JobStatus::VERIFYING,
            JobStatus::COMPLETED, JobStatus::FAILED, JobStatus::NEEDS_ATTENTION,
            JobStatus::CANCELLED,
        ],
        JobStatus::VERIFYING => [
            JobStatus::VERIFYING, JobStatus::COMPLETED, JobStatus::FAILED,
            JobStatus::IMPORTING, JobStatus::NEEDS_ATTENTION, JobStatus::CANCELLED,
        ],
        JobStatus::NEEDS_ATTENTION => [
            JobStatus::PENDING, JobStatus::RUNNING, JobStatus::IMPORTING,
            JobStatus::CANCELLED, JobStatus::FAILED,
        ],
        // Retryable: an operator (or automatic recovery) can requeue a failed job.
        JobStatus::FAILED => [
            JobStatus::PENDING, JobStatus::CANCELLED,
        ],
        JobStatus::COMPLETED => [],
        JobStatus::CANCELLED => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        if (!isset(self::TRANSITIONS[$from])) {
            return false;
        }
        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /** @throws InvalidTransitionException */
    public function assertTransition(string $from, string $to): void
    {
        if (!in_array($from, JobStatus::all(), true)) {
            throw new InvalidTransitionException('Unknown source status: ' . $from);
        }
        if (!in_array($to, JobStatus::all(), true)) {
            throw new InvalidTransitionException('Unknown target status: ' . $to);
        }
        if (!$this->canTransition($from, $to)) {
            throw new InvalidTransitionException(
                'Illegal job transition ' . $from . ' -> ' . $to
            );
        }
    }

    /** @return string[] */
    public function allowedFrom(string $from): array
    {
        return isset(self::TRANSITIONS[$from]) ? self::TRANSITIONS[$from] : [];
    }

    // ---- step-level transitions -------------------------------------------

    /** @var array<string,string[]> */
    private const STEP_TRANSITIONS = [
        // PENDING -> COMPLETED is allowed: an owner may find the work already
        // done and report only the outcome, and a lost "running" report must
        // not make the subsequent success impossible to record.
        StepStatus::PENDING => [
            StepStatus::PENDING, StepStatus::RUNNING, StepStatus::COMPLETED,
            StepStatus::SKIPPED, StepStatus::FAILED,
        ],
        StepStatus::RUNNING => [
            StepStatus::RUNNING, StepStatus::COMPLETED, StepStatus::FAILED,
            StepStatus::SKIPPED, StepStatus::PENDING,
        ],
        // A completed step accepts a repeated "completed" report (idempotency)
        // and can be reset to pending by an explicit operator retry.
        StepStatus::COMPLETED => [StepStatus::COMPLETED, StepStatus::PENDING],
        StepStatus::FAILED => [StepStatus::PENDING, StepStatus::RUNNING, StepStatus::SKIPPED],
        StepStatus::SKIPPED => [StepStatus::SKIPPED, StepStatus::PENDING],
    ];

    public function canTransitionStep(string $from, string $to): bool
    {
        return isset(self::STEP_TRANSITIONS[$from])
            && in_array($to, self::STEP_TRANSITIONS[$from], true);
    }

    /** @throws InvalidTransitionException */
    public function assertStepTransition(string $stepKey, string $from, string $to): void
    {
        if (!$this->canTransitionStep($from, $to)) {
            throw new InvalidTransitionException(
                'Illegal step transition for ' . $stepKey . ': ' . $from . ' -> ' . $to
            );
        }
    }

    /**
     * A repeated terminal report is not an error — it is the expected outcome of
     * a retried request. The caller replays the stored result instead of acting.
     */
    public function isRepeatedTerminal(string $from, string $to): bool
    {
        return $from === $to && StepStatus::isTerminal($from);
    }
}
