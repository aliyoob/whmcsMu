<?php
namespace Wtp\Tests;

use Wtp\Shared\Http\HmacSigner;
use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StepStatus;
use Wtp\Shared\Support\Json;

/**
 * The agent API, exercised through the real router with real signatures.
 */
final class AgentApiTest extends TestCase
{
    /** @var Harness */
    private $h;
    /** @var FakeAgent */
    private $agent;
    /** @var array<string,mixed> */
    private $job;
    /** @var string */
    private $bootstrapToken;

    public function setUp(): void
    {
        $this->h = new Harness();
        $this->h->seedCatalogue();
        $this->h->seedSelection();
        $this->job = $this->h->createJob();

        $this->bootstrapToken = $this->h->container->jobs()->issueBootstrapToken(
            (int) $this->job['id'],
            1800,
            'wtp-bootstrap-test.php'
        );
        $this->job = $this->h->container->jobs()->require((int) $this->job['id']);

        $this->agent = new FakeAgent($this->h->container, (string) $this->job['uuid'], $this->bootstrapToken);
    }

    public function tearDown(): void
    {
        $this->h->cleanup();
    }

    // ------------------------------------------------------------- handshake

    public function testHandshakeReturnsASigningSecret(): void
    {
        $response = $this->agent->handshake();
        $body = $response->decoded();

        $this->assertSame(200, $response->status());
        $this->assertTrue(!empty($body['credentials']['secret']));
        $this->assertSame('hmac-sha256', $body['credentials']['algorithm']);
        $this->assertContains('/provisioning/v1/job', (string) $body['endpoints']['job']);
    }

    public function testBootstrapTokenIsSingleUse(): void
    {
        $this->assertSame(200, $this->agent->handshake()->status());

        $second = new FakeAgent($this->h->container, (string) $this->job['uuid'], $this->bootstrapToken);
        $this->assertSame(401, $second->handshake()->status());
    }

    public function testAnExpiredBootstrapTokenIsRejected(): void
    {
        $this->h->clock->advance(1801);
        $this->assertSame(401, $this->agent->handshake()->status());
    }

    public function testAWrongBootstrapTokenIsRejected(): void
    {
        $impostor = new FakeAgent($this->h->container, (string) $this->job['uuid'], 'not-the-real-token-at-all');
        $this->assertSame(401, $impostor->handshake()->status());
    }

    public function testHandshakeCompletesTheAgentStep(): void
    {
        $this->agent->handshake();

        $step = $this->h->container->steps()->find((int) $this->job['id'], 'agent.handshake');
        $this->assertSame(StepStatus::COMPLETED, (string) $step['status']);

        $job = $this->h->container->jobs()->require((int) $this->job['id']);
        $this->assertSame(JobStatus::AWAITING_AGENT, (string) $job['status']);
    }

    // ------------------------------------------------------------------ auth

    public function testAnUnsignedRequestIsRejected(): void
    {
        $this->agent->handshake();
        $response = $this->agent->send('GET', '/job', '', []);

        $this->assertSame(401, $response->status());
        $this->assertSame('unauthorized', $response->decoded()['error']);
    }

    public function testATamperedBodyIsRejected(): void
    {
        $this->agent->handshake();

        $signer = new HmacSigner($this->h->clock);
        $headers = $signer->sign(
            $this->agent->secret(),
            (string) $this->job['uuid'],
            'POST',
            '/provisioning/v1/steps/theme.install',
            Json::encode(['status' => 'running'])
        );

        $response = $this->agent->send(
            'POST',
            '/steps/theme.install',
            Json::encode(['status' => 'completed']),
            $headers
        );

        $this->assertSame(401, $response->status());
    }

    public function testAnotherJobsSecretCannotBeUsed(): void
    {
        $this->agent->handshake();
        $this->agent->setSecret('a-completely-different-secret-value');

        $this->assertSame(401, $this->agent->signed('GET', '/job', null)->status());
    }

    public function testAReplayedRequestIsRejected(): void
    {
        $this->agent->handshake();

        $nonce = FakeAgent::nonce();
        $timestamp = $this->h->clock->now();

        $first = $this->agent->replay('GET', '/job', null, $nonce, $timestamp);
        $second = $this->agent->replay('GET', '/job', null, $nonce, $timestamp);

        $this->assertSame(200, $first->status());
        $this->assertSame(401, $second->status());
        $this->assertSame('replay_detected', $second->decoded()['error']);
    }

    public function testASignatureFromOutsideTheClockWindowIsRejected(): void
    {
        $this->agent->handshake();

        $stale = $this->agent->replay('GET', '/job', null, FakeAgent::nonce(), $this->h->clock->now() - 3600);
        $this->assertSame(401, $stale->status());
    }

    public function testTheSecretStopsWorkingOnceTheJobFinishes(): void
    {
        $this->agent->handshake();
        $this->assertSame(200, $this->agent->signed('GET', '/job', null)->status());

        $this->h->container->jobs()->transition((int) $this->job['id'], JobStatus::CANCELLED);

        $response = $this->agent->signed('GET', '/job', null);
        $this->assertSame(410, $response->status());
        $this->assertSame('job_finished', $response->decoded()['error']);
    }

    public function testHealthNeedsNoCredentials(): void
    {
        $response = $this->agent->send('GET', '/health', '', []);
        $this->assertSame(200, $response->status());
        $this->assertSame('1.0', $response->decoded()['api']);
    }

    public function testUnknownRoutesAre404(): void
    {
        $this->agent->handshake();
        $this->assertSame(404, $this->agent->signed('GET', '/nonsense', null)->status());
    }

    public function testWrongMethodIs405(): void
    {
        $this->agent->handshake();
        $this->assertSame(405, $this->agent->signed('POST', '/job', [])->status());
    }

    // ------------------------------------------------------------------ plan

    public function testJobEndpointReturnsAValidPlan(): void
    {
        $this->agent->handshake();
        $response = $this->agent->fetchJob();
        $body = $response->decoded();

        $this->assertSame(200, $response->status());
        $this->assertSame('astra', $body['plan']['theme']['slug']);
        $this->assertSame('agency', $body['plan']['demo']['slug']);
        $this->assertSame(Harness::DOMAIN, $body['job']['domain']);

        // The agent is told what is still outstanding so a resumed agent does
        // not redo completed work.
        $outstanding = array_column($body['outstanding_steps'], 'key');
        $this->assertTrue(in_array('theme.install', $outstanding, true));
        $this->assertFalse(in_array('agent.handshake', $outstanding, true));
    }

    public function testThePlanCarriesNoSecrets(): void
    {
        $this->agent->handshake();
        $body = $this->agent->fetchJob()->body();

        foreach (['secret', 'password', 'run_token', 'bootstrap'] as $forbidden) {
            $this->assertNotContains($forbidden, $body);
        }
    }

    public function testPlanBecomesUnavailableWhenTheDemoIsDeactivated(): void
    {
        $this->agent->handshake();
        $this->h->container->catalog()->setDemoStatus($this->h->ids['demo'], 'inactive');

        $response = $this->agent->fetchJob();
        $this->assertSame(422, $response->status());
        $this->assertSame('demo_inactive', $response->decoded()['error']);
    }

    // ----------------------------------------------------------------- steps

    public function testStepTransitionsAreRecorded(): void
    {
        $this->agent->handshake();
        $this->agent->fetchJob();

        $this->agent->reportStep('theme.install', ['status' => 'running']);
        $step = $this->h->container->steps()->find((int) $this->job['id'], 'theme.install');
        $this->assertSame(StepStatus::RUNNING, (string) $step['status']);

        $this->agent->reportStep('theme.install', ['status' => 'progress', 'progress' => 40]);
        $step = $this->h->container->steps()->find((int) $this->job['id'], 'theme.install');
        $this->assertSame(40, (int) $step['progress']);

        $this->agent->reportStep('theme.install', ['status' => 'completed', 'meta' => ['theme' => 'astra']]);
        $step = $this->h->container->steps()->find((int) $this->job['id'], 'theme.install');
        $this->assertSame(StepStatus::COMPLETED, (string) $step['status']);
        $this->assertSame(100, (int) $step['progress']);
    }

    public function testRepeatedCompletionIsAcknowledgedNotReapplied(): void
    {
        $this->agent->handshake();
        $this->agent->fetchJob();
        $this->agent->reportStep('theme.install', ['status' => 'completed']);

        $second = $this->agent->reportStep('theme.install', ['status' => 'completed']);
        $body = $second->decoded();

        $this->assertSame(200, $second->status());
        $this->assertTrue($body['repeated']);
    }

    public function testACompletedStepCannotBeRetroactivelyFailed(): void
    {
        $this->agent->handshake();
        $this->agent->fetchJob();
        $this->agent->reportStep('theme.install', ['status' => 'completed']);

        $response = $this->agent->reportStep('theme.install', [
            'status' => 'failed',
            'error_code' => 'oops',
            'error_message' => 'changed my mind',
        ]);

        $this->assertSame(409, $response->status());
        $this->assertSame('step_already_terminal', $response->decoded()['error']);
    }

    public function testIdempotencyKeyReplaysTheStoredResponse(): void
    {
        $this->agent->handshake();
        $this->agent->fetchJob();

        $key = 'idem-' . str_repeat('a', 12);
        $first = $this->agent->reportStep('theme.install', ['status' => 'completed'], $key);
        $second = $this->agent->reportStep('theme.install', ['status' => 'completed'], $key);

        $this->assertSame($first->body(), $second->body());
        $this->assertSame('true', $second->headers()['Idempotent-Replay']);
    }

    public function testReusingAnIdempotencyKeyWithADifferentBodyIsRefused(): void
    {
        $this->agent->handshake();
        $this->agent->fetchJob();

        $key = 'idem-' . str_repeat('b', 12);
        $this->agent->reportStep('theme.install', ['status' => 'running'], $key);
        $response = $this->agent->reportStep('theme.install', ['status' => 'completed'], $key);

        $this->assertSame(409, $response->status());
        $this->assertSame('idempotency_key_reuse', $response->decoded()['error']);
    }

    public function testTheAgentCannotReportAWhmcsOwnedStep(): void
    {
        $this->agent->handshake();
        $this->agent->fetchJob();

        $response = $this->agent->reportStep('account.create', ['status' => 'completed']);
        $this->assertSame(403, $response->status());
    }

    public function testAnUnknownStepIs404(): void
    {
        $this->agent->handshake();
        $this->agent->fetchJob();

        $this->assertSame(404, $this->agent->reportStep('rm.minus.rf', ['status' => 'completed'])->status());
    }

    public function testAnInvalidStatusIsRefused(): void
    {
        $this->agent->handshake();
        $this->agent->fetchJob();

        $response = $this->agent->reportStep('theme.install', ['status' => 'probably-fine']);
        $this->assertSame(422, $response->status());
    }

    // ---------------------------------------------------------------- events

    public function testEventsAreStoredAndRedacted(): void
    {
        $this->agent->handshake();
        $this->agent->fetchJob();

        $this->agent->signed('POST', '/events', [
            'events' => [
                ['level' => 'info', 'message' => 'Installed theme', 'step' => 'theme.install'],
                ['level' => 'warning', 'message' => 'token=supersecretvalue12345678', 'code' => 'oops'],
            ],
        ]);

        $events = $this->h->container->eventLog()->forJob((int) $this->job['id'], 50);
        $messages = implode("\n", array_column($events, 'message'));

        $this->assertContains('Installed theme', $messages);
        $this->assertNotContains('supersecretvalue12345678', $messages);
    }
}
