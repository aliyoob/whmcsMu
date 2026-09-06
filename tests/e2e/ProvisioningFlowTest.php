<?php
namespace Wtp\Tests;

use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StepStatus;
use Wtp\Shared\Support\Json;

/**
 * The whole pipeline, end to end.
 *
 *   order -> job -> cPanel account -> database -> installer -> WordPress ->
 *   agent -> handshake -> plan -> packages -> import -> verify -> completed
 *
 * Every component is the real one except the cPanel server and the customer's
 * WordPress, and both of those are driven through the same HTTP contract the
 * real ones use. The agent in particular signs genuine requests and gets
 * genuine answers from the router.
 */
final class ProvisioningFlowTest extends TestCase
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

    public function testTheHappyPathProvisionsASiteEndToEnd(): void
    {
        $result = $this->h->runWorker();
        $job = $result['job'];

        $this->assertSame(JobStatus::COMPLETED, (string) $job['status'], $this->diagnostics());

        // Every step reached a terminal state.
        foreach ($this->h->stepStatuses() as $key => $status) {
            $this->assertTrue(
                StepStatus::isTerminal($status),
                $key . ' ended as ' . $status
            );
        }

        // The cPanel side really happened.
        $this->assertSame(1, $this->h->panel->callCount('createAccount'));
        $this->assertSame(1, $this->h->panel->callCount('provisionDatabase'));
        $this->assertSame(2, $this->h->panel->callCount('writeFile'), 'installer plus its configuration');

        // The installer ran each of its steps exactly once and then removed itself.
        $installer = $this->h->installer();
        $this->assertNotNull($installer);
        $this->assertTrue($installer->completed['download']);
        $this->assertTrue($installer->completed['install']);
        $this->assertTrue($installer->completed['agent']);
        $this->assertTrue($installer->selfDeleted);

        // The agent fetched every package the plan named, and each download
        // came back 200.
        $agent = $this->h->agent();
        $this->assertNotNull($agent);
        $this->assertCount(3, $agent->downloads);
        foreach ($agent->downloads as $handle => $status) {
            $this->assertSame(200, $status, 'download of ' . $handle);
        }

        // The result the customer sees.
        $result = Json::tryDecode($job['result'] ?? null, []);
        $this->assertContains(Harness::DOMAIN, (string) $result['site_url']);
        $this->assertContains('/wp-admin/', (string) $result['admin_url']);
        $this->assertTrue((bool) $result['verification']['is_wordpress']);
    }

    public function testTheCredentialsAreRevokedOnceTheJobFinishes(): void
    {
        $this->h->runWorker();
        $job = $this->h->job();

        $this->assertSame(JobStatus::COMPLETED, (string) $job['status']);
        $this->assertNotNull($job['secret_revoked_at']);

        // A late call from the site is refused.
        $agent = $this->h->agent();
        $this->assertNotNull($agent);
        $response = $agent->signed('GET', '/job', null);
        $this->assertSame(410, $response->status());
    }

    public function testTheSelectionIsLockedOnceProvisioningCompletes(): void
    {
        $this->h->runWorker();

        $selection = $this->h->container->selections()->forService(Harness::SERVICE_ID);
        $this->assertSame(1, (int) $selection['locked']);
    }

    public function testNoSecretEverReachesTheEventLog(): void
    {
        $this->h->runWorker();

        $job = $this->h->job();
        $events = $this->h->container->eventLog()->forJob((int) $job['id'], 1000);

        $haystack = '';
        foreach ($events as $event) {
            $haystack .= (string) $event['message'] . ' ' . (string) ($event['context'] ?? '') . "\n";
        }

        $config = $this->h->panel->installerConfig('acmeexam');
        $this->assertNotNull($config);

        foreach ([
            (string) $config['run_token'],
            (string) $config['bootstrap_token'],
            (string) $config['db']['password'],
            (string) $config['site']['admin_password'],
            'cpanel-secret-pw',
        ] as $secret) {
            $this->assertNotContains($secret, $haystack, 'a secret leaked into the job log');
        }
    }

    public function testThePayloadStoresNoSecretInClear(): void
    {
        $this->h->runWorker();
        $job = $this->h->job();

        $payload = (string) $job['payload'];
        $config = $this->h->panel->installerConfig('acmeexam');

        $this->assertNotContains((string) $config['db']['password'], $payload);
        $this->assertNotContains((string) $config['site']['admin_password'], $payload);
        $this->assertNotContains((string) $config['run_token'], $payload);
        $this->assertNotContains('cpanel-secret-pw', $payload);
    }

    public function testRunningTheWorkerAgainAfterCompletionChangesNothing(): void
    {
        $this->h->runWorker();

        $before = $this->h->job();
        $accountCalls = $this->h->panel->callCount('createAccount');

        $this->h->container->worker()->tick(5);
        $this->h->container->worker()->tick(5);

        $after = $this->h->job();
        $this->assertSame((string) $before['updated_at'], (string) $after['updated_at']);
        $this->assertSame($accountCalls, $this->h->panel->callCount('createAccount'));
    }

    /** A compact dump used when an assertion fails, so failures are debuggable. */
    private function diagnostics(): string
    {
        $lines = [];
        foreach ($this->h->stepStatuses() as $key => $status) {
            $lines[] = '  ' . str_pad($key, 22) . $status;
        }
        $job = $this->h->job();
        $lines[] = '  last error: ' . (string) ($job['last_error'] ?? '(none)');

        return "\nSteps:\n" . implode("\n", $lines) . "\n";
    }
}
