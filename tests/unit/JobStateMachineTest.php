<?php
namespace Wtp\Tests;

use Wtp\Shared\Job\InvalidTransitionException;
use Wtp\Shared\Job\JobStateMachine;
use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StepStatus;

final class JobStateMachineTest extends TestCase
{
    /** @var JobStateMachine */
    private $sm;

    public function setUp(): void
    {
        $this->sm = new JobStateMachine();
    }

    public function testHappyPathIsWalkable(): void
    {
        $path = [
            JobStatus::PENDING, JobStatus::RUNNING, JobStatus::AWAITING_AGENT,
            JobStatus::IMPORTING, JobStatus::VERIFYING, JobStatus::COMPLETED,
        ];
        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue(
                $this->sm->canTransition($path[$i], $path[$i + 1]),
                $path[$i] . ' -> ' . $path[$i + 1] . ' should be allowed'
            );
        }
    }

    public function testCompletedIsTerminal(): void
    {
        foreach (JobStatus::all() as $to) {
            $this->assertFalse(
                $this->sm->canTransition(JobStatus::COMPLETED, $to),
                'completed must not transition to ' . $to
            );
        }
    }

    public function testCancelledIsTerminal(): void
    {
        foreach (JobStatus::all() as $to) {
            $this->assertFalse($this->sm->canTransition(JobStatus::CANCELLED, $to));
        }
    }

    public function testFailedCanBeRequeuedButNotResumedDirectly(): void
    {
        $this->assertTrue($this->sm->canTransition(JobStatus::FAILED, JobStatus::PENDING));
        $this->assertTrue($this->sm->canTransition(JobStatus::FAILED, JobStatus::CANCELLED));
        $this->assertFalse($this->sm->canTransition(JobStatus::FAILED, JobStatus::COMPLETED));
        $this->assertFalse($this->sm->canTransition(JobStatus::FAILED, JobStatus::IMPORTING));
    }

    public function testCannotJumpFromPendingStraightToCompleted(): void
    {
        $this->assertThrows(InvalidTransitionException::class, function () {
            $this->sm->assertTransition(JobStatus::PENDING, JobStatus::COMPLETED);
        }, 'Illegal job transition');
    }

    public function testUnknownStatusesAreRejected(): void
    {
        $this->assertThrows(InvalidTransitionException::class, function () {
            $this->sm->assertTransition('nonsense', JobStatus::RUNNING);
        }, 'Unknown source status');

        $this->assertThrows(InvalidTransitionException::class, function () {
            $this->sm->assertTransition(JobStatus::RUNNING, 'nonsense');
        }, 'Unknown target status');
    }

    public function testNeedsAttentionCanBeRequeued(): void
    {
        $this->assertTrue($this->sm->canTransition(JobStatus::NEEDS_ATTENTION, JobStatus::PENDING));
        $this->assertTrue($this->sm->canTransition(JobStatus::NEEDS_ATTENTION, JobStatus::IMPORTING));
    }

    public function testEveryStatusIsReachable(): void
    {
        foreach (JobStatus::all() as $target) {
            if ($target === JobStatus::PENDING) {
                continue; // the initial state
            }
            $reachable = false;
            foreach (JobStatus::all() as $from) {
                if ($from !== $target && $this->sm->canTransition($from, $target)) {
                    $reachable = true;
                    break;
                }
            }
            $this->assertTrue($reachable, $target . ' is unreachable');
        }
    }

    public function testStepCompletionIsIdempotent(): void
    {
        $this->assertTrue($this->sm->canTransitionStep(StepStatus::COMPLETED, StepStatus::COMPLETED));
        $this->assertTrue($this->sm->isRepeatedTerminal(StepStatus::COMPLETED, StepStatus::COMPLETED));
        $this->assertFalse($this->sm->isRepeatedTerminal(StepStatus::RUNNING, StepStatus::RUNNING));
    }

    public function testCompletedStepCannotSilentlyFail(): void
    {
        $this->assertFalse($this->sm->canTransitionStep(StepStatus::COMPLETED, StepStatus::FAILED));
        $this->assertThrows(InvalidTransitionException::class, function () {
            $this->sm->assertStepTransition('theme.install', StepStatus::COMPLETED, StepStatus::FAILED);
        });
    }

    public function testFailedStepCanBeRetried(): void
    {
        $this->assertTrue($this->sm->canTransitionStep(StepStatus::FAILED, StepStatus::PENDING));
        $this->assertTrue($this->sm->canTransitionStep(StepStatus::FAILED, StepStatus::RUNNING));
    }

    public function testOperatorCanResetACompletedStep(): void
    {
        $this->assertTrue($this->sm->canTransitionStep(StepStatus::COMPLETED, StepStatus::PENDING));
    }

    public function testIsActiveAndTerminalHelpers(): void
    {
        $this->assertTrue(JobStatus::isTerminal(JobStatus::COMPLETED));
        $this->assertTrue(JobStatus::isTerminal(JobStatus::CANCELLED));
        $this->assertFalse(JobStatus::isTerminal(JobStatus::FAILED));
        $this->assertTrue(JobStatus::isActive(JobStatus::IMPORTING));
        $this->assertFalse(JobStatus::isActive(JobStatus::FAILED));
    }
}
