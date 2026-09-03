<?php
namespace Wtp\Addon\Api;

use Wtp\Addon\Container;
use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Job\StepStatus;

/**
 * POST /handshake — the agent's first and only unsigned call.
 *
 * Exchanges the single-use bootstrap token the installer left behind for the
 * long-lived per-job HMAC secret. The bootstrap token is burned inside the same
 * transaction that validates it, so this endpoint can be called exactly once
 * per job; a retried handshake (network dropped after we responded) is answered
 * from the idempotency store rather than by minting a second secret.
 */
final class HandshakeController
{
    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request): Response
    {
        $jobs = $this->container->jobs();
        $steps = $this->container->steps();
        $log = $this->container->eventLog();
        $config = $this->container->config();

        $job = $this->container->authenticator()->authenticateBootstrap($request);
        $jobId = (int) $job['id'];

        $payload = $request->json(16384);
        $siteUrl = isset($payload['site_url']) ? (string) $payload['site_url'] : '';
        $wpVersion = isset($payload['wp_version']) ? (string) $payload['wp_version'] : '';
        $agentVersion = isset($payload['agent_version']) ? (string) $payload['agent_version'] : '';

        $secret = $jobs->issueAgentSecret($jobId, $config->getInt('secret_ttl', 86400));

        $jobs->mergePayload($jobId, [
            'site_url' => substr($siteUrl, 0, 512),
            'wp_version' => substr($wpVersion, 0, 32),
            'agent_version' => substr($agentVersion, 0, 32),
            'agent_ip' => $request->ip(),
        ]);

        // The handshake step is the agent's, but it is only ever completed here.
        $step = $steps->find($jobId, StepCatalog::AGENT_HANDSHAKE);
        if ($step !== null && !StepStatus::isTerminal((string) $step['status'])) {
            if ((string) $step['status'] !== StepStatus::RUNNING) {
                $steps->start($jobId, StepCatalog::AGENT_HANDSHAKE);
            }
            $steps->complete($jobId, StepCatalog::AGENT_HANDSHAKE, [
                'agent_version' => $agentVersion,
                'wp_version' => $wpVersion,
            ]);
        }

        if (!JobStatus::isTerminal((string) $job['status'])
            && (string) $job['status'] !== JobStatus::AWAITING_AGENT) {
            $jobs->transition($jobId, JobStatus::AWAITING_AGENT, StepCatalog::PLAN_FETCH);
        } else {
            $jobs->setPhase($jobId, StepCatalog::PLAN_FETCH);
        }

        $log->info($jobId, 'Agent completed the handshake', [
            'agent_version' => $agentVersion,
            'wp_version' => $wpVersion,
            'site_url' => $siteUrl,
            'secret_fingerprint' => $this->container->authenticator()->fingerprint($secret),
        ], StepCatalog::AGENT_HANDSHAKE);

        $base = $this->container->apiBaseUrl();

        return Response::json([
            'success' => true,
            'job' => [
                'id' => (string) $job['uuid'],
                'domain' => (string) $job['domain'],
                'status' => JobStatus::AWAITING_AGENT,
            ],
            // Returned exactly once. Losing it means the agent must be
            // re-bootstrapped by an operator, which is the intended failure mode.
            'credentials' => [
                'secret' => $secret,
                'algorithm' => 'hmac-sha256',
                'scheme' => 'WTP1',
                'expires_at' => gmdate('c', $this->container->clock()->now() + $config->getInt('secret_ttl', 86400)),
            ],
            'endpoints' => [
                'base' => $base,
                'job' => $base . '/job',
                'steps' => $base . '/steps/',
                'events' => $base . '/events',
                'grant' => $base . '/assets/{asset_id}/grant',
                'complete' => $base . '/complete',
                'fail' => $base . '/fail',
            ],
            'policy' => [
                'clock_skew' => $config->getInt('clock_skew', 300),
                'poll_interval' => 15,
            ],
        ], 200, ['X-WTP-Api' => Router::VERSION]);
    }
}
