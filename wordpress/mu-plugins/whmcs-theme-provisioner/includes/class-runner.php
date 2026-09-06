<?php
/**
 * The agent's step machine.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Wtp\Shared\Plan\ImportPlan;
use Wtp\Shared\Plan\PlanException;

/**
 * Runs one bounded slice of provisioning per invocation.
 *
 * The contract with WHMCS is that the agent owns the steps between
 * `agent.handshake` and `site.finalize`, reports every transition, and never
 * ties a long operation to a single HTTP request. So a tick:
 *
 *   - takes a lock (nothing runs twice concurrently)
 *   - does as much as fits in its time budget
 *   - persists a resume cursor
 *   - asks itself to continue if there is more to do
 *
 * Every reported transition carries an idempotency key derived from the step
 * and its attempt, so a response lost to a timeout is replayed rather than
 * re-executed.
 */
final class Wtp_Runner
{
    /**
     * WHMCS step key => the importer keys that make it up, in order.
     *
     * Several plan steps can roll up into one reported step: "extras" covers
     * an Elementor kit and any declarative option writes, and neither deserves
     * its own row in the customer's progress list.
     */
    private const STEP_GROUPS = [
        'theme.install' => ['theme'],
        'plugins.install' => ['plugins'],
        'content.import' => ['content'],
        'widgets.import' => ['widgets'],
        'customizer.import' => ['customizer'],
        'extras.import' => ['elementor', 'options'],
        'site.finalize' => ['finalize'],
    ];

    private const MAX_STEP_ATTEMPTS = 5;

    /** @var Wtp_Config */
    private $config;
    /** @var Wtp_State */
    private $state;
    /** @var Wtp_Logger */
    private $logger;
    /** @var Wtp_Import_Registry */
    private $registry;
    /** @var Wtp_Api_Client|null */
    private $client;
    /** @var Wtp_Security_Downloader|null */
    private $downloader;
    /** @var Wtp_Scheduler|null */
    private $scheduler;
    /** @var bool */
    private $moreWork = false;

    public function __construct(
        Wtp_Config $config,
        Wtp_State $state,
        Wtp_Logger $logger,
        Wtp_Import_Registry $registry
    ) {
        $this->config = $config;
        $this->state = $state;
        $this->logger = $logger;
        $this->registry = $registry;
    }

    public function setScheduler(Wtp_Scheduler $scheduler): void
    {
        $this->scheduler = $scheduler;
    }

    /** One tick. Never throws: a crashed agent must still answer the trigger. */
    public function tick(): void
    {
        if ($this->state->isFinished() || !$this->config->isProvisioned()) {
            return;
        }

        $lock = new Wtp_Lock();
        if (!$lock->acquire(180)) {
            $this->logger->debug('Another tick holds the lock');
            return;
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $this->moreWork = false;

        try {
            $this->run();
        } catch (Wtp_Api_Exception $e) {
            $this->handleApiFailure($e);
        } catch (Throwable $e) {
            $this->logger->error('The agent hit an unexpected error', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ], 'agent_exception');
            $this->moreWork = true;
        } finally {
            if ($this->downloader !== null) {
                $this->downloader->cleanup();
            }
            $this->logger->flush();
            $lock->release();
        }

        if ($this->moreWork && $this->scheduler !== null) {
            $this->scheduler->dispatchSelf();
        }
    }

    // ---------------------------------------------------------------- phases

    private function run(): void
    {
        if ($this->state->phase() === Wtp_State::PHASE_HANDSHAKE) {
            $this->handshake();
        }
        if ($this->state->phase() === Wtp_State::PHASE_PLAN) {
            $this->fetchPlan();
        }
        if ($this->state->phase() === Wtp_State::PHASE_RUN) {
            $this->execute();
        }
    }

    private function handshake(): void
    {
        if ($this->config->hasSecret()) {
            // A previous tick got the secret but died before recording it.
            $this->state->setPhase(Wtp_State::PHASE_PLAN);
            return;
        }

        $client = $this->client();
        $response = $client->handshake();

        $this->logger->attach($client);
        $this->logger->info('Completed the WHMCS handshake', [
            'api' => (string) ($response['endpoints']['base'] ?? ''),
        ]);

        $this->state->merge([
            'phase' => Wtp_State::PHASE_PLAN,
            'handshaked_at' => time(),
        ]);
        $this->moreWork = true;
    }

    private function fetchPlan(): void
    {
        $client = $this->client();
        $this->logger->attach($client);

        $response = $client->fetchJob();
        $raw = isset($response['plan']) && is_array($response['plan']) ? $response['plan'] : [];

        try {
            // Validate on arrival. A compromised or simply buggy WHMCS must not
            // be able to make this agent write outside its own site.
            $plan = ImportPlan::fromArray($raw);
        } catch (PlanException $e) {
            $this->logger->error('WHMCS sent a plan this agent will not run', [
                'reason' => $e->getMessage(),
            ], 'plan_invalid');
            $this->giveUp('plan_invalid', $e->getMessage(), null);
            return;
        }

        $this->state->merge([
            'phase' => Wtp_State::PHASE_RUN,
            'plan' => $plan->toArray(),
            'plan_fingerprint' => $plan->fingerprint(),
            'plan_fetched_at' => time(),
        ]);

        $this->logger->info('Received the provisioning plan', [
            'theme' => $plan->theme()['slug'],
            'demo' => $plan->demo()['slug'],
            'steps' => count($plan->steps()),
            'plugins' => count($plan->plugins()),
        ]);

        $this->moreWork = true;
    }

    private function execute(): void
    {
        $plan = $this->state->plan();
        if ($plan === null) {
            $this->state->setPhase(Wtp_State::PHASE_PLAN);
            $this->moreWork = true;
            return;
        }

        $budget = (int) ($plan['policy']['step_time_budget'] ?? 20);
        $deadline = microtime(true) + max(5, $budget);

        foreach (self::STEP_GROUPS as $stepKey => $importerKeys) {
            if ($this->state->isStepDone($stepKey)) {
                continue;
            }

            $work = $this->workFor($plan, $importerKeys);
            if ($work === []) {
                $this->reportSkipped($stepKey, 'nothing to do for this demo');
                $this->state->markStepDone($stepKey);
                continue;
            }

            $finished = $this->runGroup($stepKey, $work, $plan, $deadline);
            if (!$finished) {
                $this->moreWork = true;
                return; // resume on the next tick, from the saved cursor
            }

            if (microtime(true) >= $deadline) {
                $this->moreWork = true;
                return;
            }
        }

        $this->finish($plan);
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<int,array<string,mixed>> $work
     * @return bool true when every plan step in the group finished
     */
    private function runGroup(string $stepKey, array $work, array $plan, float $deadline): bool
    {
        $this->logger->forStep($stepKey);
        $this->reportRunning($stepKey);

        foreach ($work as $planStep) {
            $planKey = (string) $planStep['key'];
            if ($this->state->isStepDone($planKey)) {
                continue;
            }

            $importer = $this->registry->find($plan, $planStep);
            if ($importer === null) {
                $this->logger->warning('No importer handles this step', [
                    'step' => $planKey,
                    'importer' => (string) ($planStep['importer'] ?? ''),
                ], 'importer_missing');
                $this->state->markStepDone($planKey);
                continue;
            }

            $attempt = $this->state->attempts($planKey) + 1;
            $context = new Wtp_Import_Context(
                $plan,
                $planStep,
                $this->state->cursor($planKey),
                $this->logger,
                $this->downloader(),
                $deadline,
                $attempt
            );

            $result = $this->safeRun($importer, $context);

            if ($result->isProgress()) {
                $this->state->setCursor($planKey, $result->cursor());
                $this->reportProgress($stepKey, $result->percent(), $result->cursor());
                return false;
            }

            if ($result->isFailed()) {
                $this->state->recordAttempt($planKey);
                return $this->handleStepFailure($stepKey, $planKey, $result, $attempt);
            }

            $this->state->markStepDone($planKey);

            if ($result->isSkipped()) {
                $this->logger->info('Skipped ' . $planKey, ['reason' => $result->message()]);
            } else {
                $this->logger->info('Finished ' . $planKey, $result->meta());
            }

            if (microtime(true) >= $deadline) {
                // The group is not finished, but its remaining members are
                // recorded as pending; the next tick continues here.
                return false;
            }
        }

        $this->reportCompleted($stepKey, ['plan_steps' => count($work)]);
        $this->state->markStepDone($stepKey);
        $this->logger->forStep(null);

        return true;
    }

    private function safeRun(Wtp_Importer $importer, Wtp_Import_Context $context): Wtp_Import_Result
    {
        try {
            return $importer->run($context);
        } catch (Wtp_Security_Exception $e) {
            return Wtp_Import_Result::failed($e->errorCode(), $e->getMessage());
        } catch (Wtp_Api_Exception $e) {
            return Wtp_Import_Result::failed($e->errorCode(), $e->getMessage());
        } catch (Throwable $e) {
            return Wtp_Import_Result::failed('importer_exception', $e->getMessage());
        }
    }

    /** @return bool always false — the group did not finish */
    private function handleStepFailure(
        string $stepKey,
        string $planKey,
        Wtp_Import_Result $result,
        int $attempt
    ): bool {
        $this->logger->error('Step failed: ' . $planKey, [
            'error_code' => $result->code(),
            'error_message' => $result->message(),
            'attempt' => $attempt,
        ], $result->code());

        $response = $this->reportFailed($stepKey, $result->code(), $result->message(), $attempt);

        // WHMCS owns the retry decision: it knows the attempt budget and the
        // error classification. The agent simply does what it is told.
        $retryScheduled = !empty($response['retry']['scheduled']);
        $stepStatus = (string) ($response['step']['status'] ?? '');

        if ($stepStatus === 'skipped') {
            $this->state->markStepDone($planKey);
            $this->state->markStepDone($stepKey);
            $this->moreWork = true;
            return false;
        }

        if ($retryScheduled || $attempt < self::MAX_STEP_ATTEMPTS) {
            $this->moreWork = true;
            return false;
        }

        $this->giveUp($result->code(), $result->message(), $stepKey);
        return false;
    }

    /** @param array<string,mixed> $plan */
    private function finish(array $plan): void
    {
        $summary = [
            'theme' => (string) ($plan['theme']['slug'] ?? ''),
            'demo' => (string) ($plan['demo']['slug'] ?? ''),
            'steps' => $this->state->completedSteps(),
        ];

        try {
            $this->client()->complete([
                'site_url' => $this->config->siteUrl(),
                'admin_url' => admin_url(),
                'theme' => $summary['theme'],
                'demo' => $summary['demo'],
                'summary' => $summary,
            ]);
        } catch (Wtp_Api_Exception $e) {
            // Reporting failed, but the work is genuinely done. Stay in the run
            // phase so the next tick reports again rather than redoing anything
            // — every step is already marked complete.
            $this->logger->warning('Could not report completion; will retry', [
                'reason' => $e->getMessage(),
            ], $e->errorCode());
            $this->moreWork = true;
            return;
        }

        $this->logger->info('Provisioning finished', $summary);
        $this->logger->flush();

        $this->state->merge([
            'phase' => Wtp_State::PHASE_DONE,
            'finished_at' => time(),
            'summary' => $summary,
        ]);

        // The agent's work is over: its credentials should stop existing.
        // WHMCS revokes its side at the same moment.
        $this->config->forget();
        if ($this->scheduler !== null) {
            $this->scheduler->unschedule();
        }
        $this->moreWork = false;
    }

    private function giveUp(string $code, string $message, ?string $stepKey): void
    {
        try {
            $this->client()->fail($code, $message, $stepKey, [
                'php' => PHP_VERSION,
                'wp' => get_bloginfo('version'),
                'theme' => get_stylesheet(),
                'completed' => $this->state->completedSteps(),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Could not report the failure to WHMCS', [
                'reason' => $e->getMessage(),
            ]);
        }

        $this->state->merge([
            'phase' => Wtp_State::PHASE_FAILED,
            'failed_at' => time(),
            'last_error' => ['code' => $code, 'message' => $message, 'step' => $stepKey],
        ]);
        $this->moreWork = false;
    }

    private function handleApiFailure(Wtp_Api_Exception $e): void
    {
        $this->logger->error('The provisioning API rejected a call', [
            'error_code' => $e->errorCode(),
            'status' => $e->status(),
            'message' => $e->getMessage(),
        ], $e->errorCode());

        // A revoked or finished job means there is nothing left to do here.
        if (in_array($e->errorCode(), ['job_finished', 'unauthorized', 'replay_detected'], true)) {
            $this->state->merge([
                'phase' => Wtp_State::PHASE_FAILED,
                'last_error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()],
            ]);
            $this->config->forget();
            if ($this->scheduler !== null) {
                $this->scheduler->unschedule();
            }
            return;
        }

        $this->moreWork = $e->isRetryable();
    }

    // -------------------------------------------------------------- reporting

    private function reportRunning(string $stepKey): void
    {
        $this->report($stepKey, ['status' => 'running']);
    }

    /** @param array<string,mixed> $cursor */
    private function reportProgress(string $stepKey, int $percent, array $cursor): void
    {
        $this->report($stepKey, [
            'status' => 'progress',
            'progress' => $percent,
            'cursor' => $cursor,
        ]);
    }

    /** @param array<string,mixed> $meta */
    private function reportCompleted(string $stepKey, array $meta): void
    {
        $this->report($stepKey, ['status' => 'completed', 'meta' => $meta], 'done');
    }

    private function reportSkipped(string $stepKey, string $reason): void
    {
        $this->report($stepKey, ['status' => 'skipped', 'reason' => $reason], 'skip');
    }

    /** @return array<string,mixed> */
    private function reportFailed(string $stepKey, string $code, string $message, int $attempt): array
    {
        return $this->report($stepKey, [
            'status' => 'failed',
            'error_code' => $code,
            'error_message' => $message,
        ], 'fail-' . $attempt);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function report(string $stepKey, array $payload, ?string $idempotencySuffix = null): array
    {
        try {
            $key = null;
            if ($idempotencySuffix !== null) {
                // Stable across retries of the same transition, different for a
                // genuinely new one.
                $key = substr(hash('sha256', $this->config->jobId() . '|' . $stepKey . '|' . $idempotencySuffix), 0, 40);
            }
            return $this->client()->reportStep($stepKey, $payload, $key);
        } catch (Wtp_Api_Exception $e) {
            $this->logger->warning('Could not report a step transition', [
                'step' => $stepKey,
                'status' => (string) $payload['status'],
                'reason' => $e->getMessage(),
            ], $e->errorCode());

            if (!$e->isRetryable()) {
                throw $e;
            }
            return [];
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * The plan steps belonging to a reported step, plus the synthetic steps for
     * theme, plugins and finalisation which are derived from the plan rather
     * than listed in it.
     *
     * @param array<string,mixed> $plan
     * @param string[] $importerKeys
     * @return array<int,array<string,mixed>>
     */
    private function workFor(array $plan, array $importerKeys): array
    {
        $work = [];
        $planSteps = isset($plan['steps']) && is_array($plan['steps']) ? $plan['steps'] : [];

        foreach ($importerKeys as $importerKey) {
            if ($importerKey === 'theme') {
                $work[] = ['key' => 'theme.install', 'importer' => 'theme', 'options' => []];
                continue;
            }
            if ($importerKey === 'plugins') {
                if (!empty($plan['plugins'])) {
                    $work[] = ['key' => 'plugins.install', 'importer' => 'plugins', 'options' => []];
                }
                continue;
            }
            if ($importerKey === 'finalize') {
                $work[] = ['key' => 'site.finalize', 'importer' => 'finalize', 'options' => []];
                continue;
            }

            foreach ($planSteps as $planStep) {
                if ((string) ($planStep['importer'] ?? '') === $importerKey) {
                    $work[] = $planStep;
                }
            }
        }

        return $work;
    }

    private function client(): Wtp_Api_Client
    {
        if ($this->client === null) {
            $plan = $this->state->plan();
            $timeout = (int) ($plan['policy']['http_timeout'] ?? 30);
            $this->client = new Wtp_Api_Client($this->config, $this->logger, $timeout);
            $this->logger->attach($this->client);
        }
        return $this->client;
    }

    private function downloader(): Wtp_Security_Downloader
    {
        if ($this->downloader === null) {
            $plan = $this->state->plan();
            $maxBytes = (int) ($plan['policy']['max_download_bytes'] ?? 268435456);
            $this->downloader = new Wtp_Security_Downloader($this->client(), $this->logger, $maxBytes);
        }
        return $this->downloader;
    }
}
