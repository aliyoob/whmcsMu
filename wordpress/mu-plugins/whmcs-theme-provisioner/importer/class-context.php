<?php
/**
 * What an importer is given to work with.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything an importer may touch, passed explicitly.
 *
 * The deadline is the important part: importers must check `hasTimeLeft()` in
 * their loops and return `progress()` when it runs out, so a 5 000-post WXR
 * never collides with the host's max_execution_time.
 */
final class Wtp_Import_Context
{
    /** @var array<string,mixed> */
    private $plan;
    /** @var array<string,mixed> */
    private $step;
    /** @var array<string,mixed> */
    private $cursor;
    /** @var Wtp_Logger */
    private $logger;
    /** @var Wtp_Security_Downloader */
    private $downloader;
    /** @var float unix timestamp with microseconds */
    private $deadline;
    /** @var int */
    private $attempt;

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     * @param array<string,mixed> $cursor
     */
    public function __construct(
        array $plan,
        array $step,
        array $cursor,
        Wtp_Logger $logger,
        Wtp_Security_Downloader $downloader,
        float $deadline,
        int $attempt = 1
    ) {
        $this->plan = $plan;
        $this->step = $step;
        $this->cursor = $cursor;
        $this->logger = $logger;
        $this->downloader = $downloader;
        $this->deadline = $deadline;
        $this->attempt = $attempt;
    }

    /** @return array<string,mixed> */
    public function plan(): array
    {
        return $this->plan;
    }

    /** @return array<string,mixed> */
    public function step(): array
    {
        return $this->step;
    }

    public function stepKey(): string
    {
        return (string) ($this->step['key'] ?? '');
    }

    /** @return array<string,mixed> */
    public function cursor(): array
    {
        return $this->cursor;
    }

    /** @param mixed $default @return mixed */
    public function cursorValue(string $key, $default = null)
    {
        return array_key_exists($key, $this->cursor) ? $this->cursor[$key] : $default;
    }

    /** @param mixed $default @return mixed */
    public function option(string $key, $default = null)
    {
        $options = isset($this->step['options']) && is_array($this->step['options'])
            ? $this->step['options'] : [];
        return array_key_exists($key, $options) ? $options[$key] : $default;
    }

    /** @param mixed $default @return mixed */
    public function planOption(string $key, $default = null)
    {
        $options = isset($this->plan['options']) && is_array($this->plan['options'])
            ? $this->plan['options'] : [];
        return array_key_exists($key, $options) ? $options[$key] : $default;
    }

    /** @return array<string,mixed> */
    public function site(): array
    {
        return isset($this->plan['site']) && is_array($this->plan['site']) ? $this->plan['site'] : [];
    }

    /** @return array<string,mixed>|null the step's package descriptor */
    public function asset(): ?array
    {
        return isset($this->step['asset']) && is_array($this->step['asset']) ? $this->step['asset'] : null;
    }

    public function logger(): Wtp_Logger
    {
        return $this->logger;
    }

    public function downloader(): Wtp_Security_Downloader
    {
        return $this->downloader;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    /** Seconds of budget left in this tick. */
    public function timeLeft(): float
    {
        return $this->deadline - microtime(true);
    }

    public function hasTimeLeft(float $reserve = 2.0): bool
    {
        return $this->timeLeft() > $reserve;
    }

    /**
     * A stable marker key for "this exact work was already done".
     *
     * The last line of idempotency defence: even if WHMCS's job state and the
     * agent's own state were both lost, a restored site will not re-import.
     */
    public function markerKey(string $suffix = ''): string
    {
        $theme = (string) ($this->plan['theme']['slug'] ?? 'theme');
        $demo = (string) ($this->plan['demo']['slug'] ?? 'demo');
        $key = 'wtp_done_' . substr(md5($theme . '|' . $demo . '|' . $this->stepKey() . '|' . $suffix), 0, 20);
        return $key;
    }

    public function alreadyDone(string $suffix = ''): bool
    {
        return (bool) get_option($this->markerKey($suffix), false);
    }

    /** @param array<string,mixed> $meta */
    public function markDone(string $suffix = '', array $meta = []): void
    {
        update_option($this->markerKey($suffix), [
            'at' => gmdate('c'),
            'step' => $this->stepKey(),
            'meta' => $meta,
        ], false);
    }
}
