<?php
namespace Wtp\Tests;

use Wtp\Addon\Api\Request;
use Wtp\Addon\Api\Response;
use Wtp\Addon\Api\Router;
use Wtp\Addon\Container;
use Wtp\Shared\Http\HmacSigner;
use Wtp\Shared\Plan\ImportPlan;
use Wtp\Shared\Support\Bytes;
use Wtp\Shared\Support\Json;

/**
 * The WordPress agent, speaking the real protocol to the real router.
 *
 * This is the piece that makes the end-to-end test worth having. It does not
 * stub the API: it builds genuine signed requests, hands them to
 * `Wtp\Addon\Api\Router`, and gets back whatever a real agent would get —
 * including 401s, 409s, replay rejections and expired grants.
 *
 * What it does *not* do is run WordPress. The importers are exercised by their
 * own tests; here the concern is the protocol, the state machine and the
 * recovery behaviour.
 */
final class FakeAgent
{
    /** @var Container */
    private $container;
    /** @var Router */
    private $router;
    /** @var string */
    private $jobUuid;
    /** @var string */
    private $bootstrapToken;
    /** @var string */
    private $secret = '';
    /** @var string */
    private $basePath = '/provisioning/v1';

    /** @var array<string,mixed>|null */
    public $plan;
    /** @var string[] */
    public $completedSteps = [];
    /** @var array<int,array{path:string,status:int}> */
    public $exchanges = [];
    /** @var array<string,array{code:string,message:string,times:int}> */
    private $stepFailures = [];
    /** @var array<string,int> */
    public $downloads = [];
    /** @var string handshake|plan|steps|complete|done|failed */
    private $phase = 'handshake';
    /** @var int */
    private $stepIndex = 0;
    /** @var string|null */
    public $failedAt;
    /** @var string|null */
    public $lastError;

    /** Steps the agent reports, in the order the runner would. */
    private const STEP_ORDER = [
        'theme.install',
        'plugins.install',
        'content.import',
        'widgets.import',
        'customizer.import',
        'extras.import',
        'site.finalize',
    ];

    public function __construct(Container $container, string $jobUuid, string $bootstrapToken)
    {
        $this->container = $container;
        $this->router = new Router($container);
        $this->jobUuid = $jobUuid;
        $this->bootstrapToken = $bootstrapToken;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function setSecret(string $secret): void
    {
        $this->secret = $secret;
    }

    /** Make the agent report a failure for a step, $times times. */
    public function failStep(string $stepKey, string $code, string $message, int $times = 1): void
    {
        $this->stepFailures[$stepKey] = ['code' => $code, 'message' => $message, 'times' => $times];
    }

    // ------------------------------------------------------------- protocol

    public function handshake(): Response
    {
        $body = Json::encode([
            'site_url' => 'https://' . Harness::DOMAIN,
            'wp_version' => '6.5.2',
            'agent_version' => '1.0.0',
        ]);

        $response = $this->send('POST', '/handshake', $body, [
            'Authorization' => 'WTP-Bootstrap ' . $this->jobUuid . '.' . $this->bootstrapToken,
        ]);

        $decoded = $response->decoded();
        if (isset($decoded['credentials']['secret'])) {
            $this->secret = (string) $decoded['credentials']['secret'];
        }
        return $response;
    }

    public function fetchJob(): Response
    {
        $response = $this->signed('GET', '/job', null);
        $decoded = $response->decoded();
        if (isset($decoded['plan']) && is_array($decoded['plan'])) {
            $this->plan = $decoded['plan'];
        }
        return $response;
    }

    /** @param array<string,mixed> $payload */
    public function reportStep(string $stepKey, array $payload, ?string $idempotencyKey = null): Response
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        return $this->signed('POST', '/steps/' . $stepKey, $payload, $headers);
    }

    public function requestGrant(string $handle): Response
    {
        return $this->signed('POST', '/assets/' . rawurlencode($handle) . '/grant', []);
    }

    /**
     * Follow a signed download link through the router, exactly as the agent's
     * downloader would.
     */
    public function download(string $url): Response
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $request = new Request(
            'GET',
            '/download',
            $this->basePath . '/download',
            [],
            array_map('strval', $query),
            '',
            '203.0.113.9',
            true
        );

        $response = $this->router->handle($request);
        $this->exchanges[] = ['path' => '/download', 'status' => $response->status()];

        return $response;
    }

    /** @param array<string,mixed> $summary */
    public function complete(array $summary = []): Response
    {
        return $this->signed('POST', '/complete', $summary);
    }

    public function fail(string $code, string $message, ?string $stepKey = null): Response
    {
        return $this->signed('POST', '/fail', [
            'error_code' => $code,
            'error_message' => $message,
            'step' => $stepKey,
        ]);
    }

    // ------------------------------------------------------------ behaviours

    /**
     * One tick, exactly as the real runner does it: at most one meaningful
     * action per invocation, resuming from where the last tick stopped.
     *
     * Ticking rather than running straight through is what makes mid-flight
     * failures testable — the worker gets to observe, retry and give up
     * between the agent's steps, which is the behaviour under test.
     *
     * @return array{phase:string,ok:bool,step:?string,error:?string}
     */
    public function tick(): array
    {
        if ($this->phase === 'done' || $this->phase === 'failed') {
            return $this->outcome(null, null);
        }

        if ($this->phase === 'handshake') {
            $response = $this->handshake();
            if ($response->status() !== 200) {
                return $this->fatal('handshake', (string) ($response->decoded()['error'] ?? 'unknown'));
            }
            $this->phase = 'plan';
            return $this->outcome(null, null);
        }

        if ($this->phase === 'plan') {
            $response = $this->fetchJob();
            if ($response->status() !== 200) {
                return $this->fatal('plan.fetch', (string) ($response->decoded()['error'] ?? 'unknown'));
            }
            $this->fetchAllAssets();
            $this->phase = 'steps';
            $this->stepIndex = 0;
            return $this->outcome(null, null);
        }

        if ($this->phase === 'steps') {
            return $this->tickStep();
        }

        $response = $this->complete([
            'site_url' => 'https://' . Harness::DOMAIN,
            'admin_url' => 'https://' . Harness::DOMAIN . '/wp-admin/',
            'summary' => ['steps' => $this->completedSteps],
        ]);
        $this->phase = $response->status() === 200 ? 'done' : 'failed';

        return $this->outcome(null, $response->status() === 200 ? null : 'complete_failed');
    }

    /** @return array{phase:string,ok:bool,step:?string,error:?string} */
    private function tickStep(): array
    {
        while ($this->stepIndex < count(self::STEP_ORDER)) {
            $stepKey = self::STEP_ORDER[$this->stepIndex];

            if (!$this->stepApplies($stepKey)) {
                $this->reportStep($stepKey, ['status' => 'skipped', 'reason' => 'nothing to do']);
                $this->completedSteps[] = $stepKey;
                $this->stepIndex++;
                continue;
            }

            $this->reportStep($stepKey, ['status' => 'running']);

            if (isset($this->stepFailures[$stepKey]) && $this->stepFailures[$stepKey]['times'] > 0) {
                $failure = $this->stepFailures[$stepKey];
                $this->stepFailures[$stepKey]['times']--;

                $decoded = $this->reportStep($stepKey, [
                    'status' => 'failed',
                    'error_code' => $failure['code'],
                    'error_message' => $failure['message'],
                ])->decoded();

                if ((string) ($decoded['step']['status'] ?? '') === 'skipped') {
                    $this->completedSteps[] = $stepKey;
                    $this->stepIndex++;
                    return $this->outcome($stepKey, null);
                }
                if (empty($decoded['retry']['scheduled'])) {
                    return $this->fatal($stepKey, $failure['code']);
                }
                // WHMCS scheduled a retry: stop here and let it come back.
                return $this->outcome($stepKey, $failure['code']);
            }

            $this->reportStep(
                $stepKey,
                ['status' => 'completed', 'meta' => ['by' => 'fake-agent']],
                substr(hash('sha256', $this->jobUuid . $stepKey . 'done'), 0, 40)
            );
            $this->completedSteps[] = $stepKey;
            $this->stepIndex++;

            return $this->outcome($stepKey, null);
        }

        $this->phase = 'complete';
        return $this->outcome(null, null);
    }

    /**
     * Tick until the agent settles. Used by tests that only care about the
     * end state.
     *
     * @return array{ok:bool,failed_at:?string,error:?string}
     */
    public function runToCompletion(int $maxTicks = 60): array
    {
        $ticks = 0;
        while ($ticks++ < $maxTicks && $this->phase !== 'done' && $this->phase !== 'failed') {
            $before = $this->phase . '|' . $this->stepIndex;
            $result = $this->tick();

            // A scheduled retry means the same step will be attempted again;
            // that is the worker's business, not this loop's.
            if ($result['error'] !== null && $this->phase !== 'failed') {
                continue;
            }
            if ($before === $this->phase . '|' . $this->stepIndex && $result['error'] === null
                && $this->phase === 'steps') {
                break; // no progress possible
            }
        }

        return [
            'ok' => $this->phase === 'done',
            'failed_at' => $this->failedAt,
            'error' => $this->lastError,
        ];
    }

    /**
     * Tick until the agent is ready to report steps (handshaken, plan fetched).
     * Used by tests that then drive individual step reports by hand.
     */
    public function prepare(int $maxTicks = 5): void
    {
        $ticks = 0;
        while ($ticks++ < $maxTicks && $this->phase !== 'steps') {
            if ($this->phase === 'done' || $this->phase === 'failed') {
                break;
            }
            $this->tick();
        }
    }

    /** Forget the plan and step position, keeping the signing secret. */
    public function reset(): void
    {
        $this->phase = $this->secret === '' ? 'handshake' : 'plan';
        $this->stepIndex = 0;
        $this->completedSteps = [];
        $this->failedAt = null;
        $this->lastError = null;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    /** @return array{phase:string,ok:bool,step:?string,error:?string} */
    private function outcome(?string $step, ?string $error): array
    {
        return [
            'phase' => $this->phase,
            'ok' => $this->phase !== 'failed',
            'step' => $step,
            'error' => $error,
        ];
    }

    /** @return array{phase:string,ok:bool,step:?string,error:?string} */
    private function fatal(string $step, string $error): array
    {
        $this->phase = 'failed';
        $this->failedAt = $step;
        $this->lastError = $error;
        return $this->outcome($step, $error);
    }

    /** Exchange every asset handle in the plan for a grant and fetch it. */
    public function fetchAllAssets(): void
    {
        foreach ($this->assetHandles() as $handle) {
            $grant = $this->requestGrant($handle);
            if ($grant->status() !== 200) {
                $this->downloads[$handle] = $grant->status();
                continue;
            }
            $url = (string) ($grant->decoded()['grant']['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $this->downloads[$handle] = $this->download($url)->status();
        }
    }

    /** @return string[] */
    public function assetHandles(): array
    {
        if ($this->plan === null) {
            return [];
        }
        $plan = ImportPlan::fromArray($this->plan);

        $handles = [];
        if (($plan->theme()['package']['kind'] ?? '') === 'asset') {
            $handles[] = (string) $plan->theme()['package']['asset_id'];
        }
        foreach ($plan->plugins() as $plugin) {
            if (($plugin['kind'] ?? '') === 'asset') {
                $handles[] = (string) $plugin['asset_id'];
            }
        }
        foreach ($plan->steps() as $step) {
            if (isset($step['asset']['asset_id'])) {
                $handles[] = (string) $step['asset']['asset_id'];
            }
        }
        return array_values(array_unique($handles));
    }

    private function stepApplies(string $stepKey): bool
    {
        if ($this->plan === null) {
            return false;
        }
        if (in_array($stepKey, ['theme.install', 'site.finalize'], true)) {
            return true;
        }
        if ($stepKey === 'plugins.install') {
            return !empty($this->plan['plugins']);
        }

        $importers = [
            'content.import' => ['content'],
            'widgets.import' => ['widgets'],
            'customizer.import' => ['customizer'],
            'extras.import' => ['elementor', 'options'],
        ];
        $wanted = $importers[$stepKey] ?? [];

        foreach ($this->plan['steps'] ?? [] as $step) {
            if (in_array((string) ($step['importer'] ?? ''), $wanted, true)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------- plumbing

    /**
     * @param array<string,mixed>|null $payload
     * @param array<string,string> $headers
     */
    public function signed(string $method, string $path, ?array $payload, array $headers = []): Response
    {
        $body = $payload === null ? '' : Json::encode($payload);
        $signer = new HmacSigner($this->container->clock());

        $signed = $signer->sign($this->secret, $this->jobUuid, $method, $this->basePath . $path, $body);

        return $this->send($method, $path, $body, array_merge($headers, $signed));
    }

    /**
     * Send a request with headers supplied verbatim — used by the tests that
     * need to tamper with them.
     *
     * @param array<string,string> $headers
     */
    public function send(string $method, string $path, string $body, array $headers): Response
    {
        $request = new Request(
            $method,
            $path,
            $this->basePath . $path,
            $headers,
            [],
            $body,
            '203.0.113.9',
            true
        );

        $response = $this->router->handle($request);
        $this->exchanges[] = ['path' => $path, 'status' => $response->status()];

        return $response;
    }

    /** A signed request whose nonce is reused — for replay testing. */
    public function replay(string $method, string $path, ?array $payload, string $nonce, int $timestamp): Response
    {
        $body = $payload === null ? '' : Json::encode($payload);
        $canonical = \Wtp\Shared\Http\CanonicalRequest::build(
            $method,
            $this->basePath . $path,
            $timestamp,
            $nonce,
            $body
        );

        return $this->send($method, $path, $body, [
            HmacSigner::HEADER_JOB => $this->jobUuid,
            HmacSigner::HEADER_TIMESTAMP => (string) $timestamp,
            HmacSigner::HEADER_NONCE => $nonce,
            HmacSigner::HEADER_SIGNATURE => 'v1=' . hash_hmac('sha256', $canonical, $this->secret),
        ]);
    }

    public static function nonce(): string
    {
        return Bytes::hex(16);
    }
}
