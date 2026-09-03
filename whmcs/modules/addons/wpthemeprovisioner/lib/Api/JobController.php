<?php
namespace Wtp\Addon\Api;

use Wtp\Addon\Catalog\CatalogException;
use Wtp\Addon\Container;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Job\StepStatus;
use Wtp\Shared\Plan\PlanException;

/**
 * GET /job — the provisioning plan.
 *
 * The plan is rebuilt from the live catalogue on every call rather than cached,
 * so a checksum corrected by an operator takes effect immediately, and an agent
 * that retries after a failed download never receives a stale digest.
 */
final class JobController
{
    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request): Response
    {
        $auth = $this->container->authenticator()->authenticateAgent($request);
        $job = $auth['job'];
        $jobId = (int) $job['id'];

        $steps = $this->container->steps();
        $log = $this->container->eventLog();

        try {
            $plan = $this->container->planResolver()->forJob($job);
        } catch (CatalogException $e) {
            $log->error($jobId, 'Cannot build the provisioning plan', [
                'reason' => $e->getMessage(),
            ], StepCatalog::PLAN_FETCH, $e->getMessage());
            throw ApiException::unprocessable($e->getMessage(), 'The selected theme or demo is no longer available');
        } catch (PlanException $e) {
            $log->error($jobId, 'The catalogue produced an invalid plan', [
                'reason' => $e->getMessage(),
            ], StepCatalog::PLAN_FETCH, 'plan_invalid');
            throw ApiException::unprocessable('plan_invalid', 'The demo configuration is not valid');
        }

        $step = $steps->find($jobId, StepCatalog::PLAN_FETCH);
        if ($step !== null && !StepStatus::isTerminal((string) $step['status'])) {
            if ((string) $step['status'] !== StepStatus::RUNNING) {
                $steps->start($jobId, StepCatalog::PLAN_FETCH);
            }
            $steps->complete($jobId, StepCatalog::PLAN_FETCH, [
                'theme' => $plan->theme()['slug'],
                'demo' => $plan->demo()['slug'],
            ]);
            $log->info($jobId, 'Delivered the provisioning plan to the agent', [
                'theme' => $plan->theme()['slug'],
                'demo' => $plan->demo()['slug'],
                'steps' => count($plan->steps()),
            ], StepCatalog::PLAN_FETCH);
        }

        // Tell the agent which steps are still outstanding so a resumed agent
        // does not redo work that already succeeded.
        $outstanding = [];
        foreach ($steps->forJob($jobId) as $row) {
            if ((string) $row['owner'] !== 'agent') {
                continue;
            }
            if (StepStatus::isTerminal((string) $row['status'])) {
                continue;
            }
            $outstanding[] = [
                'key' => (string) $row['step_key'],
                'status' => (string) $row['status'],
                'attempt' => (int) $row['attempt'],
                'max_attempts' => (int) $row['max_attempts'],
                'progress' => (int) $row['progress'],
                'cursor' => $row['cursor'] !== null
                    ? \Wtp\Shared\Support\Json::tryDecode((string) $row['cursor'], []) : null,
            ];
        }

        $payload = \Wtp\Shared\Support\Json::tryDecode($job['payload'] ?? null, []);

        return Response::json([
            'success' => true,
            'job' => [
                'id' => (string) $job['uuid'],
                'status' => (string) $job['status'],
                'phase' => (string) $job['phase'],
                'domain' => (string) $job['domain'],
                'site_url' => isset($payload['site_url']) ? (string) $payload['site_url'] : '',
            ],
            'plan' => $plan->toArray(),
            'plan_fingerprint' => $plan->fingerprint(),
            'outstanding_steps' => $outstanding,
        ], 200, ['X-WTP-Api' => Router::VERSION]);
    }
}
