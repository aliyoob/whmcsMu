<?php
namespace Wtp\Addon\Api;

use Wtp\Addon\Job\JobException;
use Wtp\Addon\Job\JobRepository;
use Wtp\Addon\Support\Config;
use Wtp\Addon\Support\EventLog;
use Wtp\Shared\Http\HmacSigner;
use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Security\RateLimiter;
use Wtp\Shared\Support\Bytes;
use Wtp\Shared\Support\Str;

/**
 * Authenticates the two kinds of caller this API accepts.
 *
 * Design notes:
 *  - every failure returns the same opaque 401 to the client; the specific
 *    reason is written to the job log, where an operator can see it
 *  - failures are rate-limited per IP *and* per job, so neither a wrong guess
 *    at a job uuid nor a compromised site can be used to grind at the endpoint
 *  - a successful signature check is only half the job: the nonce must also be
 *    claimable, which is what makes replay impossible
 */
final class Authenticator
{
    /** @var JobRepository */
    private $jobs;
    /** @var HmacSigner */
    private $signer;
    /** @var NonceStore */
    private $nonces;
    /** @var RateLimiter */
    private $limiter;
    /** @var EventLog */
    private $log;
    /** @var Config */
    private $config;

    public function __construct(
        JobRepository $jobs,
        HmacSigner $signer,
        NonceStore $nonces,
        RateLimiter $limiter,
        EventLog $log,
        Config $config
    ) {
        $this->jobs = $jobs;
        $this->signer = $signer;
        $this->nonces = $nonces;
        $this->limiter = $limiter;
        $this->log = $log;
        $this->config = $config;
    }

    /**
     * `Authorization: WTP-Bootstrap <job-uuid>.<token>`
     *
     * @return array<string,mixed> the job row
     */
    public function authenticateBootstrap(Request $request): array
    {
        $this->assertTransport($request);
        $this->guardAuthAttempts($request);

        $header = $request->header('authorization');
        if (stripos($header, 'WTP-Bootstrap ') !== 0) {
            throw ApiException::unauthorized('Missing bootstrap credentials');
        }
        $credential = trim(substr($header, 14));
        $dot = strpos($credential, '.');
        if ($dot === false) {
            throw ApiException::unauthorized('Malformed bootstrap credentials');
        }
        $uuid = substr($credential, 0, $dot);
        $token = substr($credential, $dot + 1);

        if (!Str::isUuid($uuid) || strlen($token) < 20 || strlen($token) > 200) {
            throw ApiException::unauthorized('Malformed bootstrap credentials');
        }

        try {
            $job = $this->jobs->consumeBootstrapToken($uuid, $token);
        } catch (JobException $e) {
            $this->penalise($request, null, 'bootstrap:' . $e->errorCode());
            throw ApiException::unauthorized('Bootstrap authentication failed');
        }

        $this->log->info(
            (int) $job['id'],
            'Bootstrap token accepted',
            ['ip' => $request->ip(), 'agent' => $request->agent()],
            null
        );
        return $job;
    }

    /**
     * HMAC-signed agent request.
     *
     * @return array{job:array<string,mixed>,secret:string}
     */
    public function authenticateAgent(Request $request): array
    {
        $this->assertTransport($request);
        $this->guardAuthAttempts($request);

        $uuid = $request->header(HmacSigner::HEADER_JOB);
        if (!Str::isUuid($uuid)) {
            $this->penalise($request, null, 'agent:bad_job_header');
            throw ApiException::unauthorized('Authentication failed');
        }

        $job = $this->jobs->findByUuid($uuid);
        if ($job === null) {
            // Same failure shape as a bad signature: no job-existence oracle.
            $this->penalise($request, null, 'agent:unknown_job');
            throw ApiException::unauthorized('Authentication failed');
        }
        $jobId = (int) $job['id'];

        if (JobStatus::isTerminal((string) $job['status'])) {
            $this->penalise($request, $jobId, 'agent:job_terminal');
            throw ApiException::gone('job_finished', 'This provisioning job has already finished');
        }

        try {
            $secret = $this->jobs->agentSecret($job);
        } catch (JobException $e) {
            $this->penalise($request, $jobId, 'agent:' . $e->errorCode());
            throw ApiException::unauthorized('Authentication failed');
        }

        $ok = $this->signer->verify(
            $secret,
            $request->method(),
            $request->signedPath(),
            $request->body(),
            $request->headers()
        );
        if (!$ok) {
            $this->penalise($request, $jobId, 'agent:' . (string) $this->signer->getLastFailureCode());
            throw ApiException::unauthorized('Authentication failed');
        }

        $nonce = $request->header(HmacSigner::HEADER_NONCE);
        if (!$this->nonces->claim($jobId, $nonce)) {
            $this->log->warning(
                $jobId,
                'Rejected a replayed request',
                ['ip' => $request->ip(), 'path' => $request->path()],
                null,
                'replay_detected'
            );
            throw new ApiException(401, 'replay_detected', 'This request has already been processed');
        }

        // Successful calls are throttled too, so a runaway agent loop cannot
        // saturate the billing server.
        $quota = $this->limiter->consume(
            'job:' . $jobId . ':api',
            $this->config->getInt('rate_limit_api', 300),
            $this->config->getInt('rate_limit_window', 300)
        );
        if (!$quota['allowed']) {
            throw ApiException::rateLimited($quota['retry_after']);
        }

        return ['job' => $job, 'secret' => $secret];
    }

    /**
     * TLS is not optional. An agent secret sent over plain HTTP would be
     * readable by every hop between the hosting server and WHMCS.
     */
    private function assertTransport(Request $request): void
    {
        if (!$this->config->getBool('enforce_https', true)) {
            return;
        }
        if (!$request->isSecure()) {
            throw new ApiException(
                426,
                'https_required',
                'The provisioning API is only available over HTTPS'
            );
        }
    }

    /** Global brake on authentication attempts from one address. */
    private function guardAuthAttempts(Request $request): void
    {
        $quota = $this->limiter->consume(
            'ip:' . $request->ip() . ':auth',
            $this->config->getInt('rate_limit_auth_failures', 10) * 6,
            $this->config->getInt('rate_limit_window', 300),
            0.0 // peek only; the cost is charged on failure
        );
        if (!$quota['allowed']) {
            throw ApiException::rateLimited($quota['retry_after']);
        }
    }

    /** Charge a failed attempt against both buckets and record it. */
    private function penalise(Request $request, ?int $jobId, string $reason): void
    {
        $window = $this->config->getInt('rate_limit_window', 300);
        $budget = $this->config->getInt('rate_limit_auth_failures', 10);

        $this->limiter->consume('ip:' . $request->ip() . ':auth', $budget * 6, $window, 6.0);
        if ($jobId !== null) {
            $this->limiter->consume('job:' . $jobId . ':auth', $budget, $window, 1.0);
            $this->log->warning(
                $jobId,
                'Authentication failed',
                [
                    'reason' => $reason,
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                    'agent' => $request->agent(),
                ],
                null,
                'auth_failed'
            );
        } else {
            error_log('[WTP] unauthenticated request rejected (' . $reason . ') from ' . $request->ip());
        }
        unset($budget);
    }

    /** Exposed for the download route, which authenticates by URL signature. */
    public function jobs(): JobRepository
    {
        return $this->jobs;
    }

    public function fingerprint(string $secret): string
    {
        return Bytes::fingerprint($secret);
    }
}
