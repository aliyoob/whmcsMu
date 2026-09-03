<?php
namespace Wtp\Tests;

use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StatusPresenter;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Job\StepStatus;

final class StepCatalogTest extends TestCase
{
    public function testStepsAreUniqueAndSequential(): void
    {
        $steps = StepCatalog::all();
        $keys = array_column($steps, 'key');
        $this->assertSame(count($keys), count(array_unique($keys)), 'step keys must be unique');

        $expected = 1;
        foreach ($steps as $s) {
            $this->assertSame($expected, $s['seq']);
            $expected++;
        }
    }

    public function testEveryStepHasAnOwnerAndSaneTimeouts(): void
    {
        foreach (StepCatalog::all() as $s) {
            $this->assertTrue(in_array($s['owner'], ['whmcs', 'agent'], true), $s['key']);
            $this->assertGreaterThan(0, $s['timeout']);
            $this->assertGreaterThan(0, $s['max_attempts']);
            $this->assertGreaterThan(0, strlen($s['label']));
        }
    }

    public function testLabelsAreDecodedNotEscaped(): void
    {
        $this->assertSame('Fetch theme & demo plan', StepCatalog::get(StepCatalog::PLAN_FETCH)['label']);
    }

    public function testFromReturnsTheTail(): void
    {
        $tail = StepCatalog::from(StepCatalog::CONTENT_IMPORT);
        $this->assertSame(StepCatalog::CONTENT_IMPORT, $tail[0]);
        $this->assertSame(StepCatalog::JOB_VERIFY, $tail[count($tail) - 1]);
        $this->assertFalse(in_array(StepCatalog::THEME_INSTALL, $tail, true));
    }

    public function testUnknownStepSortsLast(): void
    {
        $this->assertSame(PHP_INT_MAX, StepCatalog::seq('nope'));
        $this->assertFalse(StepCatalog::exists('nope'));
        $this->assertNull(StepCatalog::get('nope'));
    }

    public function testStatusMapping(): void
    {
        $this->assertSame(JobStatus::RUNNING, StepCatalog::statusFor(StepCatalog::ACCOUNT_CREATE));
        $this->assertSame(JobStatus::AWAITING_AGENT, StepCatalog::statusFor(StepCatalog::AGENT_HANDSHAKE));
        $this->assertSame(JobStatus::IMPORTING, StepCatalog::statusFor(StepCatalog::CONTENT_IMPORT));
        $this->assertSame(JobStatus::VERIFYING, StepCatalog::statusFor(StepCatalog::JOB_VERIFY));
    }

    public function testAgentOwnsOnlyStepsWordPressCanRun(): void
    {
        $this->assertSame('whmcs', StepCatalog::ownerOf(StepCatalog::ACCOUNT_CREATE));
        $this->assertSame('whmcs', StepCatalog::ownerOf(StepCatalog::JOB_VERIFY));
        $this->assertSame('agent', StepCatalog::ownerOf(StepCatalog::CONTENT_IMPORT));
    }

    public function testChecklistCoversEveryStepEvenWhenUnrecorded(): void
    {
        $checklist = StatusPresenter::checklist([
            ['step_key' => StepCatalog::ACCOUNT_CREATE, 'status' => StepStatus::COMPLETED],
            ['step_key' => StepCatalog::CONTENT_IMPORT, 'status' => StepStatus::RUNNING, 'progress' => 40],
        ]);

        $this->assertCount(count(StepCatalog::all()), $checklist);
        $this->assertSame('✓', $checklist[0]['icon']);
        $byKey = array_column($checklist, null, 'key');
        $this->assertSame('⏳', $byKey[StepCatalog::CONTENT_IMPORT]['icon']);
        $this->assertSame(40, $byKey[StepCatalog::CONTENT_IMPORT]['progress']);
        $this->assertSame('○', $byKey[StepCatalog::JOB_VERIFY]['icon']);
    }

    public function testPercentageCountsSkippedStepsAsDone(): void
    {
        $steps = [];
        foreach (StepCatalog::keys() as $k) {
            $steps[] = ['step_key' => $k, 'status' => StepStatus::COMPLETED];
        }
        $this->assertSame(100, StatusPresenter::percentage($steps));

        $steps[3]['status'] = StepStatus::SKIPPED;
        $this->assertSame(100, StatusPresenter::percentage($steps));

        $steps[4]['status'] = StepStatus::FAILED;
        $this->assertLessThanOrEqual(99, StatusPresenter::percentage($steps));
    }

    public function testJobLabelsAreHumanReadable(): void
    {
        $this->assertSame('Queued', StatusPresenter::job(JobStatus::PENDING)['label']);
        $this->assertSame('danger', StatusPresenter::job(JobStatus::FAILED)['tone']);
        $this->assertSame(
            'Import demo content',
            StatusPresenter::job(JobStatus::IMPORTING, StepCatalog::CONTENT_IMPORT)['label']
        );
    }
}
