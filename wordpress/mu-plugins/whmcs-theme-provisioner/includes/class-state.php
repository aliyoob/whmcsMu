<?php
/**
 * Durable agent state.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One autoloaded option holding everything the runner needs to resume.
 *
 * Kept in a single row rather than a dozen options so a tick reads and writes
 * once, and so `delete_option` is a complete reset.
 */
final class Wtp_State
{
    public const OPTION = 'wtp_agent_state';

    public const PHASE_HANDSHAKE = 'handshake';
    public const PHASE_PLAN = 'plan';
    public const PHASE_RUN = 'run';
    public const PHASE_DONE = 'done';
    public const PHASE_FAILED = 'failed';

    /** @var array<string,mixed>|null */
    private $data;

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->data === null) {
            $stored = get_option(self::OPTION, []);
            $this->data = is_array($stored) ? $stored : [];
        }
        return $this->data;
    }

    /** @param mixed $default @return mixed */
    public function get(string $key, $default = null)
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @param mixed $value */
    public function set(string $key, $value): void
    {
        $all = $this->all();
        $all[$key] = $value;
        $this->save($all);
    }

    /** @param array<string,mixed> $values */
    public function merge(array $values): void
    {
        $this->save(array_replace($this->all(), $values));
    }

    public function phase(): string
    {
        return (string) $this->get('phase', self::PHASE_HANDSHAKE);
    }

    public function setPhase(string $phase): void
    {
        $this->set('phase', $phase);
    }

    public function isFinished(): bool
    {
        $phase = $this->phase();
        return $phase === self::PHASE_DONE || $phase === self::PHASE_FAILED;
    }

    /** @return array<string,mixed>|null */
    public function plan(): ?array
    {
        $plan = $this->get('plan');
        return is_array($plan) ? $plan : null;
    }

    /** @param array<string,mixed> $plan */
    public function setPlan(array $plan): void
    {
        $this->merge([
            'plan' => $plan,
            'plan_fetched_at' => time(),
        ]);
    }

    /** @return string[] step keys already finished */
    public function completedSteps(): array
    {
        $done = $this->get('completed', []);
        return is_array($done) ? array_values(array_map('strval', $done)) : [];
    }

    public function markStepDone(string $stepKey): void
    {
        $done = $this->completedSteps();
        if (!in_array($stepKey, $done, true)) {
            $done[] = $stepKey;
        }
        $this->merge(['completed' => $done, 'cursor_' . $stepKey => null]);
    }

    public function isStepDone(string $stepKey): bool
    {
        return in_array($stepKey, $this->completedSteps(), true);
    }

    /** @return array<string,mixed> */
    public function cursor(string $stepKey): array
    {
        $cursor = $this->get('cursor_' . $stepKey);
        return is_array($cursor) ? $cursor : [];
    }

    /** @param array<string,mixed> $cursor */
    public function setCursor(string $stepKey, array $cursor): void
    {
        $this->set('cursor_' . $stepKey, $cursor);
    }

    public function attempts(string $stepKey): int
    {
        return (int) $this->get('attempts_' . $stepKey, 0);
    }

    public function recordAttempt(string $stepKey): int
    {
        $attempt = $this->attempts($stepKey) + 1;
        $this->set('attempts_' . $stepKey, $attempt);
        return $attempt;
    }

    public function reset(): void
    {
        $this->data = [];
        delete_option(self::OPTION);
    }

    /** @param array<string,mixed> $data */
    private function save(array $data): void
    {
        $this->data = $data;
        // autoload = false: this row is only read by the agent, never on a
        // normal page view once provisioning has finished.
        update_option(self::OPTION, $data, false);
    }
}
