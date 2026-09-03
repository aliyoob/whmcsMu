<?php
namespace Wtp\Addon\Api;

use Wtp\Addon\Container;
use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Support\Str;

/**
 * POST /complete and POST /fail — the agent's terminal reports.
 *
 * `complete` does not finish the job outright: it hands control back to WHMCS,
 * which verifies the site itself before telling the customer it is ready. An
 * agent claiming success is evidence, not proof.
 */
final class CompletionController
{
    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function complete(Request $request): Response
    {
        $auth = $this->container->authenticator()->authenticateAgent($request);
        $job = $auth['job'];
        $jobId = (int) $job['id'];

        $body = $request->json(65536);
        $summary = isset($body['summary']) && is_array($body['summary']) ? $body['summary'] : [];

        $result = $this->container->jobs()->payload($jobId);
        $this->container->jobs()->setResult($jobId, [
            'site_url' => (string) ($body['site_url'] ?? ($result['site_url'] ?? '')),
            'admin_url' => (string) ($body['admin_url'] ?? ''),
            'theme' => (string) ($body['theme'] ?? ''),
            'demo' => (string) ($body['demo'] ?? ''),
            'summary' => $summary,
            'reported_at' => gmdate('c', $this->container->clock()->now()),
        ]);

        $this->container->eventLog()->info($jobId, 'Agent reported the import as complete', $summary);

        // Anything the agent left unfinished that is optional is skipped, so
        // verification sees a clean board; a required gap keeps the job open.
        $steps = $this->container->steps();
        foreach ($steps->forJob($jobId) as $row) {
            if ((string) $row['owner'] !== 'agent') {
                continue;
            }
            if (\Wtp\Shared\Job\StepStatus::isTerminal((string) $row['status'])) {
                continue;
            }
            $definition = StepCatalog::get((string) $row['step_key']);
            if ($definition !== null && $definition['optional']) {
                $steps->skip($jobId, (string) $row['step_key'], 'agent reported completion without it');
            }
        }

        $current = (string) $this->container->jobs()->require($jobId)['status'];
        if (!JobStatus::isTerminal($current) && $current !== JobStatus::VERIFYING) {
            $this->container->jobs()->transition($jobId, JobStatus::VERIFYING, StepCatalog::JOB_VERIFY);
        }
        $this->container->jobs()->scheduleAt($jobId, $this->container->clock()->now());

        return Response::json([
            'success' => true,
            'job' => ['id' => (string) $job['uuid'], 'status' => JobStatus::VERIFYING],
            'message' => 'Recorded. WHMCS will now verify the site.',
        ], 200, ['X-WTP-Api' => Router::VERSION]);
    }

    public function fail(Request $request): Response
    {
        $auth = $this->container->authenticator()->authenticateAgent($request);
        $job = $auth['job'];
        $jobId = (int) $job['id'];

        $body = $request->json(65536);
        $code = substr((string) ($body['error_code'] ?? 'agent_failed'), 0, 64);
        $message = Str::limit((string) ($body['error_message'] ?? 'The agent gave up'), 2000);
        $stepKey = isset($body['step']) && StepCatalog::exists((string) $body['step'])
            ? (string) $body['step'] : null;

        $this->container->jobs()->recordError($jobId, $code, $message);
        $this->container->eventLog()->error($jobId, 'Agent reported a terminal failure', [
            'error_code' => $code,
            'error_message' => $message,
            'diagnostics' => isset($body['diagnostics']) && is_array($body['diagnostics'])
                ? $body['diagnostics'] : [],
        ], $stepKey, $code);

        $classification = $this->container->errorClassifier()->classify($code, $message);
        $target = $classification === \Wtp\Shared\Job\ErrorClassifier::MANUAL
            ? JobStatus::NEEDS_ATTENTION
            : JobStatus::FAILED;

        try {
            $this->container->jobs()->transition($jobId, $target, $stepKey);
        } catch (\DomainException $e) {
            unset($e);
        }

        return Response::json([
            'success' => true,
            'job' => ['id' => (string) $job['uuid'], 'status' => $target],
            'message' => 'Failure recorded. An operator can retry this job from WHMCS.',
        ], 200, ['X-WTP-Api' => Router::VERSION]);
    }
}
