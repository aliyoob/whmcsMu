<?php
namespace Wtp\Addon\Api;

use Wtp\Addon\Container;
use Wtp\Addon\Job\JobException;
use Wtp\Shared\Job\ErrorClassifier;
use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Job\StepStatus;
use Wtp\Shared\Support\Str;

/**
 * POST /steps/{key} — the agent reports progress on one step.
 *
 * Accepted transitions are `running`, `progress`, `completed`, `failed` and
 * `skipped`. Every other value is rejected rather than coerced, because a typo
 * in the agent must not be able to mark an import successful.
 *
 * Idempotency has two layers here:
 *   - `Idempotency-Key` replays the exact stored response
 *   - a repeat `completed` on an already-completed step returns 200 with
 *     `repeated: true` and changes nothing
 */
final class StepController
{
    private const ACTIONS = ['running', 'progress', 'completed', 'failed', 'skipped'];

    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, string $stepKey): Response
    {
        $auth = $this->container->authenticator()->authenticateAgent($request);
        $job = $auth['job'];
        $jobId = (int) $job['id'];

        if (!StepCatalog::exists($stepKey)) {
            throw ApiException::notFound('Unknown step: ' . $stepKey);
        }
        if (StepCatalog::ownerOf($stepKey) !== 'agent') {
            throw ApiException::forbidden('This step is not the agent\'s to report');
        }

        $scope = 'POST /steps/' . $stepKey;
        $idemKey = $request->idempotencyKey();
        $store = $this->container->idempotency();

        if ($idemKey !== null) {
            $cached = $store->lookup($jobId, $scope, $idemKey, $request->body());
            if ($cached !== null) {
                return Response::json($cached['body'], $cached['status'], [
                    'X-WTP-Api' => Router::VERSION,
                    'Idempotent-Replay' => 'true',
                ]);
            }
        }

        $body = $request->json(65536);
        $action = isset($body['status']) ? strtolower((string) $body['status']) : '';
        if (!in_array($action, self::ACTIONS, true)) {
            throw ApiException::unprocessable(
                'invalid_status',
                'status must be one of: ' . implode(', ', self::ACTIONS)
            );
        }

        [$status, $response] = $this->apply($job, $stepKey, $action, $body);

        if ($idemKey !== null) {
            $store->remember($jobId, $scope, $idemKey, $request->body(), $status, $response);
        }

        return Response::json($response, $status, ['X-WTP-Api' => Router::VERSION]);
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $body
     * @return array{0:int,1:array<string,mixed>}
     */
    private function apply(array $job, string $stepKey, string $action, array $body): array
    {
        $jobId = (int) $job['id'];
        $steps = $this->container->steps();
        $jobs = $this->container->jobs();
        $log = $this->container->eventLog();

        $step = $steps->find($jobId, $stepKey);
        if ($step === null) {
            throw ApiException::notFound('This job has no such step');
        }
        $current = (string) $step['status'];

        switch ($action) {
            case 'running':
                if (StepStatus::isTerminal($current)) {
                    return [200, $this->ok($stepKey, $current, true, 'Step already finished')];
                }
                if ($current !== StepStatus::RUNNING) {
                    $steps->start($jobId, $stepKey);
                    $log->info($jobId, 'Agent started ' . $stepKey, [], $stepKey);
                }
                $jobs->transition($jobId, StepCatalog::statusFor($stepKey), $stepKey);
                $jobs->heartbeat($jobId);
                return [200, $this->ok($stepKey, StepStatus::RUNNING, false)];

            case 'progress':
                if (StepStatus::isTerminal($current)) {
                    return [200, $this->ok($stepKey, $current, true, 'Step already finished')];
                }
                if ($current !== StepStatus::RUNNING) {
                    $steps->start($jobId, $stepKey);
                }
                $cursor = isset($body['cursor']) && is_array($body['cursor']) ? $body['cursor'] : null;
                $steps->progress($jobId, $stepKey, (int) ($body['progress'] ?? 0), $cursor);
                $jobs->heartbeat($jobId);
                return [200, $this->ok($stepKey, StepStatus::RUNNING, false)];

            case 'completed':
                $meta = isset($body['meta']) && is_array($body['meta']) ? $body['meta'] : [];
                $changed = $steps->complete($jobId, $stepKey, $meta);
                if ($changed) {
                    $log->info($jobId, 'Agent completed ' . $stepKey, $meta, $stepKey);
                }
                $this->advance($jobId, $stepKey);
                return [200, $this->ok($stepKey, StepStatus::COMPLETED, !$changed)];

            case 'skipped':
                if (StepStatus::isTerminal($current)) {
                    return [200, $this->ok($stepKey, $current, true)];
                }
                $reason = Str::limit((string) ($body['reason'] ?? 'nothing to do'), 191);
                $steps->skip($jobId, $stepKey, $reason);
                $log->info($jobId, 'Agent skipped ' . $stepKey, ['reason' => $reason], $stepKey);
                $this->advance($jobId, $stepKey);
                return [200, $this->ok($stepKey, StepStatus::SKIPPED, false)];

            case 'failed':
            default:
                if (StepStatus::isTerminal($current)) {
                    // A step that already succeeded cannot be retro-failed.
                    return [409, [
                        'success' => false,
                        'error' => 'step_already_terminal',
                        'message' => 'This step has already finished successfully',
                        'step' => ['key' => $stepKey, 'status' => $current],
                    ]];
                }
                return [200, $this->recordFailure($job, $step, $body)];
        }
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $step
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function recordFailure(array $job, array $step, array $body): array
    {
        $jobId = (int) $job['id'];
        $stepKey = (string) $step['step_key'];

        $code = substr((string) ($body['error_code'] ?? 'agent_error'), 0, 64);
        $message = Str::limit((string) ($body['error_message'] ?? 'The agent reported a failure'), 2000);

        $steps = $this->container->steps();
        $jobs = $this->container->jobs();
        $log = $this->container->eventLog();
        $classifier = $this->container->errorClassifier();

        $steps->fail($jobId, $stepKey, $code, $message);
        $jobs->recordError($jobId, $code, $message);
        $log->error($jobId, 'Agent reported a failure in ' . $stepKey, [
            'error_code' => $code,
            'error_message' => $message,
            'attempt' => (int) $step['attempt'],
        ], $stepKey, $code);

        $definition = StepCatalog::get($stepKey);
        $optional = $definition !== null && $definition['optional'];
        $attempt = (int) $step['attempt'];
        $maxAttempts = (int) $step['max_attempts'];

        $retry = $classifier->shouldRetry($code, $attempt, $maxAttempts, $message);

        if ($retry) {
            $delay = $this->container->backoff()->delayFor($attempt + 1);
            $steps->requeue($jobId, $stepKey);
            $jobs->scheduleAt($jobId, $this->container->clock()->now() + $delay);
            $log->info($jobId, 'Scheduled a retry of ' . $stepKey, [
                'in_seconds' => $delay,
                'attempt' => $attempt + 1,
            ], $stepKey);

            return [
                'success' => true,
                'step' => ['key' => $stepKey, 'status' => StepStatus::PENDING],
                'retry' => ['scheduled' => true, 'after_seconds' => $delay, 'attempt' => $attempt + 1],
            ];
        }

        // No more retries. An optional step is skipped so the rest of the
        // import still delivers a usable site; a required one fails the job.
        if ($optional) {
            $steps->skip($jobId, $stepKey, 'failed after ' . $attempt . ' attempts: ' . $code);
            $log->warning($jobId, 'Skipping optional step ' . $stepKey . ' after repeated failures', [
                'error_code' => $code,
            ], $stepKey, $code);
            $this->advance($jobId, $stepKey);

            return [
                'success' => true,
                'step' => ['key' => $stepKey, 'status' => StepStatus::SKIPPED],
                'retry' => ['scheduled' => false],
            ];
        }

        $classification = $classifier->classify($code, $message);
        $target = $classification === ErrorClassifier::MANUAL ? JobStatus::NEEDS_ATTENTION : JobStatus::FAILED;

        try {
            $jobs->transition($jobId, $target, $stepKey);
        } catch (\Throwable $e) {
            // Already terminal; nothing further to record.
            unset($e);
        }

        return [
            'success' => true,
            'step' => ['key' => $stepKey, 'status' => StepStatus::FAILED],
            'job' => ['status' => $target],
            'retry' => ['scheduled' => false],
        ];
    }

    /**
     * Move the job forward once a step finishes. When the agent has nothing
     * left to do, hand control back to the WHMCS worker for verification.
     */
    private function advance(int $jobId, string $stepKey): void
    {
        $steps = $this->container->steps();
        $jobs = $this->container->jobs();

        $next = $steps->nextActionable($jobId);
        if ($next === null) {
            try {
                $jobs->transition($jobId, JobStatus::VERIFYING, StepCatalog::JOB_VERIFY);
            } catch (JobException $e) {
                unset($e);
            } catch (\DomainException $e) {
                unset($e);
            }
            $jobs->scheduleAt($jobId, $this->container->clock()->now());
            return;
        }

        $nextKey = (string) $next['step_key'];
        $jobs->setPhase($jobId, $nextKey);

        // Hand back to the worker as soon as the next step is ours.
        if ((string) $next['owner'] === 'whmcs') {
            $jobs->scheduleAt($jobId, $this->container->clock()->now());
            try {
                $jobs->transition($jobId, StepCatalog::statusFor($nextKey), $nextKey);
            } catch (\DomainException $e) {
                unset($e);
            }
        }
        unset($stepKey);
    }

    /** @return array<string,mixed> */
    private function ok(string $stepKey, string $status, bool $repeated, string $message = ''): array
    {
        $out = [
            'success' => true,
            'repeated' => $repeated,
            'step' => ['key' => $stepKey, 'status' => $status],
        ];
        if ($message !== '') {
            $out['message'] = $message;
        }
        return $out;
    }
}
