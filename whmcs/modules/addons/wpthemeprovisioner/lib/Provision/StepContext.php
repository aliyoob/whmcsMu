<?php
namespace Wtp\Addon\Provision;

use Wtp\Addon\Container;
use Wtp\Addon\Panel\HostingPanel;
use Wtp\Addon\Support\EventLog;
use Wtp\Shared\Support\Json;

/** Everything a step handler is allowed to touch, passed explicitly. */
final class StepContext
{
    /** @var Container */
    private $container;
    /** @var array<string,mixed> */
    private $job;
    /** @var array<string,mixed> */
    private $step;
    /** @var HostingPanel|null */
    private $panel;

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $step
     */
    public function __construct(Container $container, array $job, array $step, ?HostingPanel $panel = null)
    {
        $this->container = $container;
        $this->job = $job;
        $this->step = $step;
        $this->panel = $panel;
    }

    public function container(): Container
    {
        return $this->container;
    }

    /** @return array<string,mixed> */
    public function job(): array
    {
        return $this->job;
    }

    public function jobId(): int
    {
        return (int) $this->job['id'];
    }

    public function jobUuid(): string
    {
        return (string) $this->job['uuid'];
    }

    public function domain(): string
    {
        return (string) $this->job['domain'];
    }

    /** @return array<string,mixed> */
    public function step(): array
    {
        return $this->step;
    }

    public function stepKey(): string
    {
        return (string) $this->step['step_key'];
    }

    public function attempt(): int
    {
        return (int) $this->step['attempt'];
    }

    public function panel(): HostingPanel
    {
        if ($this->panel === null) {
            throw new \RuntimeException('This step needs a hosting panel but none was configured');
        }
        return $this->panel;
    }

    public function hasPanel(): bool
    {
        return $this->panel !== null;
    }

    public function log(): EventLog
    {
        return $this->container->eventLog();
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return Json::tryDecode($this->job['payload'] ?? null, []);
    }

    /** @param mixed $default @return mixed */
    public function payloadValue(string $dottedKey, $default = null)
    {
        $value = $this->payload();
        foreach (explode('.', $dottedKey) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    public function mergePayload(array $data): void
    {
        $this->container->jobs()->mergePayload($this->jobId(), $data);
        $this->job = $this->container->jobs()->require($this->jobId());
    }

    public function refresh(): void
    {
        $this->job = $this->container->jobs()->require($this->jobId());
        $refreshed = $this->container->steps()->find($this->jobId(), $this->stepKey());
        if ($refreshed !== null) {
            $this->step = $refreshed;
        }
    }
}
