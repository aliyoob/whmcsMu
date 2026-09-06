<?php
namespace Wtp\Tests;

/**
 * The dropper, as WHMCS sees it over HTTP.
 *
 * Mirrors the real installer's contract: token-authenticated, one bounded step
 * per call, idempotent repeats, and the same JSON envelope — including the
 * `success: false` shape it uses to report a step failure without failing the
 * HTTP request itself.
 */
final class FakeInstaller
{
    /** @var string */
    private $runToken;
    /** @var array<string,bool> */
    public $completed = [];
    /** @var array<string,array{code:string,message:string,times:int}> */
    private $failures = [];
    /** @var int */
    public $runs = 0;
    /** @var bool */
    public $expired = false;
    /** @var bool */
    public $selfDeleted = false;

    public function __construct(string $runToken)
    {
        $this->runToken = $runToken;
    }

    public function failStep(string $step, string $code, string $message, int $times = 1): void
    {
        $this->failures[$step] = ['code' => $code, 'message' => $message, 'times' => $times];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{status:int,body:string}
     */
    public function handle(string $method, string $url, array $options): array
    {
        $this->runs++;

        $params = [];
        if (isset($options['body'])) {
            if (is_array($options['body'])) {
                $params = $options['body'];
            } else {
                parse_str((string) $options['body'], $params);
            }
        }

        if ($this->expired || $this->selfDeleted) {
            return $this->json(410, ['error' => 'expired', 'message' => 'This installer has expired']);
        }
        if (!hash_equals($this->runToken, (string) ($params['token'] ?? ''))) {
            return $this->json(401, ['error' => 'unauthorized']);
        }

        $action = (string) ($params['action'] ?? 'status');

        if ($action === 'cleanup') {
            $this->selfDeleted = true;
            return $this->json(200, ['success' => true, 'step' => 'cleanup', 'done' => true]);
        }
        if ($action === 'status') {
            return $this->json(200, [
                'success' => true,
                'completed' => array_keys(array_filter($this->completed)),
                'runs' => $this->runs,
            ]);
        }

        $step = (string) ($params['step'] ?? '');
        if (!in_array($step, ['download', 'install', 'agent'], true)) {
            return $this->json(400, ['error' => 'unknown_step']);
        }

        if (!empty($this->completed[$step])) {
            return $this->json(200, [
                'success' => true,
                'step' => $step,
                'done' => true,
                'repeated' => true,
                'detail' => [],
            ]);
        }

        if (isset($this->failures[$step]) && $this->failures[$step]['times'] > 0) {
            $this->failures[$step]['times']--;
            return $this->json(200, [
                'success' => false,
                'step' => $step,
                'done' => false,
                'error_code' => $this->failures[$step]['code'],
                'error_message' => $this->failures[$step]['message'],
            ]);
        }

        $this->completed[$step] = true;

        $detail = ['step' => $step];
        if ($step === 'install') {
            $detail['wp_version'] = '6.5.2';
            $detail['installed'] = true;
        }
        if ($step === 'agent') {
            $detail['agent_installed'] = true;
        }

        return $this->json(200, ['success' => true, 'step' => $step, 'done' => true, 'detail' => $detail]);
    }

    /** @param array<string,mixed> $payload @return array{status:int,body:string} */
    private function json(int $status, array $payload): array
    {
        return ['status' => $status, 'body' => (string) json_encode($payload)];
    }
}
