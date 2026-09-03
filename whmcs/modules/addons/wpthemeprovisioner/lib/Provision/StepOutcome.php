<?php
namespace Wtp\Addon\Provision;

/**
 * What a step handler decided.
 *
 * Explicitly modelled rather than returning booleans, because "not finished
 * yet, come back in 20 seconds" is a first-class outcome in this system — most
 * steps poll something that takes longer than one worker tick.
 */
final class StepOutcome
{
    public const COMPLETED = 'completed';
    public const WAITING = 'waiting';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    /** @var string */
    private $type;
    /** @var array<string,mixed> */
    private $meta;
    /** @var int */
    private $retryAfter;
    /** @var string */
    private $code;
    /** @var string */
    private $message;
    /** @var int */
    private $progress;

    private function __construct(string $type)
    {
        $this->type = $type;
        $this->meta = [];
        $this->retryAfter = 0;
        $this->code = '';
        $this->message = '';
        $this->progress = 0;
    }

    /** @param array<string,mixed> $meta */
    public static function completed(array $meta = []): self
    {
        $outcome = new self(self::COMPLETED);
        $outcome->meta = $meta;
        $outcome->progress = 100;
        return $outcome;
    }

    public static function waiting(int $retryAfterSeconds, string $reason = '', int $progress = 0): self
    {
        $outcome = new self(self::WAITING);
        $outcome->retryAfter = max(1, $retryAfterSeconds);
        $outcome->message = $reason;
        $outcome->progress = max(0, min(99, $progress));
        return $outcome;
    }

    public static function failed(string $code, string $message): self
    {
        $outcome = new self(self::FAILED);
        $outcome->code = $code;
        $outcome->message = $message;
        return $outcome;
    }

    public static function skipped(string $reason): self
    {
        $outcome = new self(self::SKIPPED);
        $outcome->message = $reason;
        return $outcome;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return $this->meta;
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function progress(): int
    {
        return $this->progress;
    }

    public function isCompleted(): bool
    {
        return $this->type === self::COMPLETED;
    }

    public function isWaiting(): bool
    {
        return $this->type === self::WAITING;
    }

    public function isFailed(): bool
    {
        return $this->type === self::FAILED;
    }

    public function isSkipped(): bool
    {
        return $this->type === self::SKIPPED;
    }
}
