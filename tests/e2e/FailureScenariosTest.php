<?php
namespace Wtp\Tests;

use Wtp\Addon\Catalog\CatalogException;
use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StepStatus;

/**
 * The ten failure scenarios the system is required to survive.
 *
 * The bar for each is not "it does not crash" but "the state is recoverable":
 * an operator can see what happened, the site is not left half-built in a way
 * that a retry would corrupt, and pressing Retry actually finishes the job.
 */
final class FailureScenariosTest extends TestCase
{
    /** @var Harness */
    private $h;

    public function setUp(): void
    {
        $this->h = new Harness();
        $this->h->seedCatalogue();
        $this->h->seedSelection();
        $this->h->createJob();
        $this->h->wireSite();
    }

    public function tearDown(): void
    {
        $this->h->cleanup();
    }

    // -- 1 ------------------------------------------------------------------

    public function testScenario1WhmcsApiUnreachableFromTheSite(): void
    {
        // The site cannot reach the billing server at all: WHMCS's poke fails
        // and the agent never checks in.
        $this->h->http()->failTransport('/wp-admin/admin-ajax.php', 'Connection timed out', 'network_timeout');

        $result = $this->h->runWorker(30);
        $job = $result['job'];

        // The job must not silently succeed, and must not be wedged in a state
        // an operator cannot act on.
        $this->assertFalse((string) $job['status'] === JobStatus::COMPLETED);

        $statuses = $this->h->stepStatuses();
        $this->assertSame(StepStatus::COMPLETED, $statuses['account.create']);
        $this->assertSame(StepStatus::COMPLETED, $statuses['agent.install']);

        // Everything up to the agent was done and stays done; only the agent
        // side is outstanding, which is exactly what a retry should resume.
        $this->assertTrue(
            in_array($statuses['agent.handshake'], [StepStatus::PENDING, StepStatus::FAILED, StepStatus::RUNNING], true),
            'handshake ended as ' . $statuses['agent.handshake']
        );

        // And once connectivity returns, the same job finishes.
        $this->h->http()->on('/wp-admin/admin-ajax.php', function (): array {
            $agent = $this->h->agent();
            $outcome = $agent === null ? ['ok' => false] : $agent->runToCompletion();
            return ['status' => 200, 'body' => (string) json_encode(['success' => $outcome['ok']])];
        });
        $this->retryAndRun();

        $this->assertSame(JobStatus::COMPLETED, (string) $this->h->job()['status']);
    }

    // -- 2 ------------------------------------------------------------------

    public function testScenario2InvalidToken(): void
    {
        // Stop while the job is still live: a finished job answers 410 to
        // everything, which would hide the authentication behaviour under test.
        $this->h->runWorkerUntilAgentInstalled();
        $this->h->agent();

        $job = $this->h->job();
        $agent = $this->h->agent();
        $this->assertNotNull($agent);

        // A forged bootstrap token gets nowhere.
        $forged = new FakeAgent($this->h->container, (string) $job['uuid'], 'forged-token-value-0123456789');
        $this->assertSame(401, $forged->handshake()->status());

        // A forged signing secret gets nowhere either, and the genuine agent is
        // unaffected by the attempt.
        $impostor = new FakeAgent($this->h->container, (string) $job['uuid'], 'irrelevant');
        $impostor->setSecret('not-the-real-secret-at-all-12345');
        $this->assertSame(401, $impostor->signed('GET', '/job', null)->status());

        // The failures were recorded against the job for an operator to see.
        $this->assertTrue(in_array('auth_failed', $this->h->eventCodes(), true));

        // The real job is unharmed and still completes.
        $this->h->runWorker(40);
        $this->assertSame(JobStatus::COMPLETED, (string) $this->h->job()['status']);
    }

    // -- 3 ------------------------------------------------------------------

    public function testScenario3ThemeNotFound(): void
    {
        // The theme is withdrawn between the order and the agent asking for it.
        $this->h->container->catalog()->setThemeStatus($this->h->ids['theme'], 'inactive');

        $result = $this->h->runWorker(30);
        $job = $result['job'];

        $this->assertFalse((string) $job['status'] === JobStatus::COMPLETED);

        // The failure is legible, not a 500.
        $codes = $this->h->eventCodes();
        $this->assertTrue(
            in_array('theme_inactive', $codes, true) || in_array('plan_invalid', $codes, true),
            'expected a catalogue failure code, got: ' . implode(', ', array_unique($codes))
        );

        // Reactivating the theme and retrying is all it takes.
        $this->h->container->catalog()->setThemeStatus($this->h->ids['theme'], 'active');
        $this->resetAgent(); // it re-fetches the plan with the secret it already has
        $this->retryAndRun();

        $this->assertSame(JobStatus::COMPLETED, (string) $this->h->job()['status']);
    }

    // -- 4 ------------------------------------------------------------------

    public function testScenario4DemoNotFound(): void
    {
        $this->h->container->catalog()->setDemoStatus($this->h->ids['demo'], 'inactive');

        $this->h->runWorker(30);
        $job = $this->h->job();

        $this->assertFalse((string) $job['status'] === JobStatus::COMPLETED);

        // The service's selection row survives, so an operator can see exactly
        // what the customer bought even though it is no longer offered.
        $selection = $this->h->container->selections()->forService(Harness::SERVICE_ID);
        $this->assertSame($this->h->ids['demo'], (int) $selection['demo_id']);

        // Switching the customer to a different demo is a supported recovery.
        $replacement = $this->h->container->catalog()->createDemo($this->h->ids['theme'], [
            'name' => 'Corporate',
            'slug' => 'corporate',
        ]);
        $this->h->container->selections()->unlock(Harness::SERVICE_ID);
        $this->h->container->selections()->record(
            ['service_id' => Harness::SERVICE_ID, 'domain' => Harness::DOMAIN],
            $this->h->ids['theme'],
            $replacement,
            'admin'
        );

        $this->resetAgent();
        $this->retryAndRun();

        $this->assertSame(JobStatus::COMPLETED, (string) $this->h->job()['status']);
    }

    public function testScenario4bADemoInUseCannotBeDeletedOnlyDeactivated(): void
    {
        $this->assertThrows(CatalogException::class, function () {
            $this->h->container->catalog()->deleteDemo($this->h->ids['demo']);
        }, 'Deactivate it instead');
    }

    // -- 5 ------------------------------------------------------------------

    public function testScenario5DownloadFailed(): void
    {
        // The package vanishes from storage after the order was placed.
        $version = $this->h->container->catalog()->findVersion($this->h->ids['version']);
        $packagePath = $this->h->storagePath . '/' . $version['source_ref'];
        $contents = (string) file_get_contents($packagePath);
        unlink($packagePath);

        $this->h->runWorkerUntilAgentInstalled();
        $agent = $this->h->agent();
        $this->assertNotNull($agent);
        $agent->prepare();

        // The grant request fails cleanly; the agent gets a 503, not a broken zip.
        $this->assertSame(503, $agent->downloads['tv:' . $this->h->ids['version']]);

        $codes = $this->h->eventCodes();
        $this->assertTrue(in_array('storage_unreadable', $codes, true));

        // Restoring the file makes the very same handle downloadable again,
        // with no change to the job, the plan or the customer's selection.
        file_put_contents($packagePath, $contents);

        $agent->reset();
        $agent->tick(); // re-fetches the plan and its packages

        $this->assertSame(200, $agent->downloads['tv:' . $this->h->ids['version']]);
    }

    // -- 6 ------------------------------------------------------------------

    public function testScenario6ChecksumInvalid(): void
    {
        // The bytes on disk no longer match the catalogue: a bad upload, a
        // partial rsync, or something worse.
        $version = $this->h->container->catalog()->findVersion($this->h->ids['version']);
        file_put_contents(
            $this->h->storagePath . '/' . $version['source_ref'],
            'these are not the bytes you catalogued'
        );

        $this->h->runWorker(30);

        $agent = $this->h->agent();
        $this->assertNotNull($agent);

        // The mismatch is caught before anything is handed to the site.
        $this->assertSame(503, $agent->downloads['tv:' . $this->h->ids['version']]);

        $codes = $this->h->eventCodes();
        $this->assertTrue(
            in_array('checksum_mismatch', $codes, true),
            'expected checksum_mismatch, got: ' . implode(', ', array_unique($codes))
        );

        // It is escalated, not retried forever: a checksum mismatch will never
        // fix itself.
        $classifier = $this->h->container->errorClassifier();
        $this->assertFalse($classifier->shouldRetry('checksum_mismatch', 1, 5));
    }

    // -- 7 ------------------------------------------------------------------

    public function testScenario7ImportFailedThenRecovered(): void
    {
        $this->h->runWorkerUntilAgentInstalled();

        $agent = $this->h->agent();
        $this->assertNotNull($agent);

        // The content import fails twice, then succeeds — the ordinary case of
        // a slow or briefly unavailable resource.
        $agent->failStep('content.import', 'network_timeout', 'The demo file timed out', 2);

        $this->h->runWorker(40);

        $this->assertSame(JobStatus::COMPLETED, (string) $this->h->job()['status']);

        $step = $this->h->container->steps()->find((int) $this->h->job()['id'], 'content.import');
        $this->assertSame(StepStatus::COMPLETED, (string) $step['status']);
        $this->assertTrue((int) $step['attempt'] >= 2, 'the retries should be visible in the attempt count');
    }

    public function testScenario7bAnOptionalStepFailingDoesNotFailTheOrder(): void
    {
        $this->h->runWorkerUntilAgentInstalled();
        $agent = $this->h->agent();
        $this->assertNotNull($agent);

        // Widgets are optional. Exhaust their attempts entirely.
        $agent->failStep('widgets.import', 'widgets_invalid', 'The widget file is corrupt', 99);

        $this->h->runWorker(60);

        $job = $this->h->job();
        $statuses = $this->h->stepStatuses();

        // The customer still gets a site with a theme and content.
        $this->assertSame(StepStatus::SKIPPED, $statuses['widgets.import']);
        $this->assertSame(StepStatus::COMPLETED, $statuses['content.import']);
        $this->assertSame(JobStatus::COMPLETED, (string) $job['status']);
    }

    public function testScenario7cARequiredStepFailingStopsTheJobRecoverably(): void
    {
        $this->h->runWorkerUntilAgentInstalled();
        $agent = $this->h->agent();
        $this->assertNotNull($agent);

        // The theme itself will not install, however many times we try.
        $agent->failStep('theme.install', 'theme_broken', 'The theme has a fatal error', 99);

        $this->h->runWorker(60);
        $job = $this->h->job();

        $this->assertTrue(
            in_array((string) $job['status'], [JobStatus::FAILED, JobStatus::NEEDS_ATTENTION], true),
            'ended as ' . $job['status']
        );
        $this->assertContains('fatal error', (string) $job['last_error']);

        // Nothing earlier was undone: the account and WordPress are intact.
        $statuses = $this->h->stepStatuses();
        $this->assertSame(StepStatus::COMPLETED, $statuses['account.create']);
        $this->assertSame(StepStatus::COMPLETED, $statuses['wordpress.install']);

        // And the failure is retryable from the admin area.
        $this->assertTrue(
            $this->h->container->stateMachine()->canTransition((string) $job['status'], JobStatus::PENDING)
        );
    }

    // -- 8 ------------------------------------------------------------------

    public function testScenario8DuplicateRequests(): void
    {
        $this->h->runWorkerUntilAgentInstalled();
        $agent = $this->h->agent();
        $this->assertNotNull($agent);
        $agent->prepare();

        $key = 'dup-' . str_repeat('7', 12);

        $first = $agent->reportStep('theme.install', ['status' => 'completed', 'meta' => ['n' => 1]], $key);
        $second = $agent->reportStep('theme.install', ['status' => 'completed', 'meta' => ['n' => 1]], $key);

        $this->assertSame(200, $first->status());
        $this->assertSame(200, $second->status());
        $this->assertSame($first->body(), $second->body());

        // Without a key, a repeat is still safe — it is acknowledged as a repeat.
        $third = $agent->reportStep('theme.install', ['status' => 'completed']);
        $this->assertTrue($third->decoded()['repeated']);

        // A replayed *signature* is a different matter and is refused outright.
        $nonce = FakeAgent::nonce();
        $timestamp = $this->h->clock->now();
        $agent->replay('GET', '/job', null, $nonce, $timestamp);
        $replayed = $agent->replay('GET', '/job', null, $nonce, $timestamp);
        $this->assertSame('replay_detected', $replayed->decoded()['error']);
    }

    public function testScenario8bDuplicateCreateAccountReusesOneJob(): void
    {
        // WHMCS retrying CreateAccount, or an operator clicking Create twice.
        for ($i = 0; $i < 3; $i++) {
            $this->h->container->jobs()->openFor([
                'service_id' => Harness::SERVICE_ID,
                'domain' => Harness::DOMAIN,
                'server_id' => Harness::SERVER_ID,
            ]);
        }

        $jobs = $this->h->container->jobs()->search(['service_id' => Harness::SERVICE_ID], 50);
        $this->assertCount(1, $jobs);

        $this->h->runWorker();
        $this->assertSame(1, $this->h->panel->callCount('createAccount'));
    }

    // -- 9 ------------------------------------------------------------------

    public function testScenario9WordPressReinstalledUnderneathTheAgent(): void
    {
        $this->h->runWorker();
        $this->assertSame(JobStatus::COMPLETED, (string) $this->h->job()['status']);

        // The customer wipes the site and reinstalls WordPress. The old agent's
        // credentials are gone with the database, but even if a copy survived,
        // WHMCS revoked the secret when the job finished.
        $agent = $this->h->agent();
        $this->assertNotNull($agent);

        $this->assertSame(410, $agent->signed('GET', '/job', null)->status());
        $this->assertSame(410, $agent->complete([])->status());

        // A grant minted before completion is dead too.
        $grant = $agent->requestGrant('tv:' . $this->h->ids['version']);
        $this->assertSame(410, $grant->status());
    }

    public function testScenario9bTheInstallerRefusesToOverwriteAForeignWordPress(): void
    {
        // Guarded inside the installer itself: a wp-config.php it did not write
        // means somebody else's site, and it stops rather than clobbering it.
        $dropper = (string) file_get_contents(dirname(__DIR__, 2) . '/bootstrap/wtp-bootstrap.php');

        $this->assertContains('wordpress_exists', $dropper);
        $this->assertContains('WTP_PROVISIONED', $dropper);
        $this->assertContains('wtp_config_is_ours', $dropper);
    }

    // -- 10 -----------------------------------------------------------------

    public function testScenario10AgentGoesSilentAndIsRecovered(): void
    {
        $this->h->runWorkerUntilAgentInstalled();

        // Let the agent check in first: a stall happens mid-import, not before
        // the handshake.
        $agent = $this->h->agent();
        $this->assertNotNull($agent);
        $agent->prepare();

        $job = $this->h->job();
        $jobId = (int) $job['id'];

        // The agent starts a step and then the PHP process is killed.
        $this->h->container->steps()->requeue($jobId, 'content.import', true);
        $this->h->container->steps()->start($jobId, 'content.import');

        $before = $this->h->container->steps()->find($jobId, 'content.import');
        $this->assertSame(StepStatus::RUNNING, (string) $before['status']);

        // Nothing is heard for longer than the step's timeout.
        $this->h->clock->advance((int) $before['timeout_seconds'] + 120);
        $this->h->container->worker()->tick(5);

        $after = $this->h->container->steps()->find($jobId, 'content.import');
        $this->assertSame(StepStatus::PENDING, (string) $after['status'], 'a stalled step must be requeued');
        $this->assertTrue(in_array('step_stalled', $this->h->eventCodes(), true));

        // And the job goes on to finish once the agent picks the step back up.
        $this->resetAgent();
        $this->h->runWorker(60);
        $this->assertSame(JobStatus::COMPLETED, (string) $this->h->job()['status']);
    }

    public function testScenario10bInstallerTimeoutIsRetriedThenSucceeds(): void
    {
        // Stop right after the installer has been deployed.
        $this->h->runWorkerUntil(function (array $job): bool {
            $step = $this->h->container->steps()->find((int) $job['id'], 'bootstrap.deploy');
            return $step !== null && (string) $step['status'] === 'completed';
        }, 20);

        $installer = $this->h->installer();
        $this->assertNotNull($installer);
        $installer->failStep('install', 'network_timeout', 'The request timed out', 2);

        $this->h->runWorker(40);

        $this->assertSame(JobStatus::COMPLETED, (string) $this->h->job()['status']);
        $this->assertTrue($installer->completed['install']);
    }

    // -------------------------------------------------------------- helpers

    /**
     * Put the agent back to the start of its plan while keeping its signing
     * secret. Building a fresh one would fail: the bootstrap token that
     * produced the secret is single-use, by design.
     */
    private function resetAgent(): void
    {
        $agent = $this->h->agent();
        if ($agent !== null) {
            $agent->reset();
        }
    }

    /** Do what an operator does: retry from the first failure, then let it run. */
    private function retryAndRun(int $ticks = 60): void
    {
        $job = $this->h->job();
        $jobId = (int) $job['id'];

        $failed = $this->h->container->steps()->firstFailed($jobId);
        $from = $failed !== null ? (string) $failed['step_key'] : 'agent.handshake';

        $this->h->container->steps()->requeueFrom($jobId, $from);
        if (in_array((string) $job['status'], [JobStatus::FAILED, JobStatus::NEEDS_ATTENTION], true)) {
            $this->h->container->jobs()->transition($jobId, JobStatus::PENDING, $from);
        }
        $this->h->container->jobs()->clearError($jobId);
        $this->h->container->jobs()->scheduleAt($jobId, $this->h->clock->now());

        $this->h->runWorker($ticks);
    }
}
