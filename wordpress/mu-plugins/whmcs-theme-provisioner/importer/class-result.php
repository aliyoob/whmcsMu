<?php
/**
 * The outcome of one importer invocation.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Four outcomes, modelled explicitly:
 *
 *   done      the step is finished
 *   progress  more work remains; the cursor says where to resume
 *   skipped   there was nothing to do (a demo without widgets, say)
 *   failed    it cannot proceed; the code drives WHMCS's retry decision
 */
final class Wtp_Import_Result
{
    public const DONE = 'done';
    public const PROGRESS = 'progress';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /** @var string */
    private $type;
    /** @var array<string,mixed> */
    private $cursor = [];
    /** @var array<string,mixed> */
    private $meta = [];
    /** @var int */
    private $percent = 0;
    /** @var string */
    private $code = '';
    /** @var string */
    private $message = '';

    private function __construct(string $type)
    {
        $this->type = $type;
    }

    /** @param array<string,mixed> $meta */
    public static function done(array $meta = []): self
    {
        $result = new self(self::DONE);
        $result->meta = $meta;
        $result->percent = 100;
        return $result;
    }

    /** @param array<string,mixed> $cursor */
    public static function progress(array $cursor, int $percent = 0, string $message = ''): self
    {
        $result = new self(self::PROGRESS);
        $result->cursor = $cursor;
        $result->percent = max(0, min(99, $percent));
        $result->message = $message;
        return $result;
    }

    public static function skipped(string $reason): self
    {
        $result = new self(self::SKIPPED);
        $result->message = $reason;
        $result->percent = 100;
        return $result;
    }

    public static function failed(string $code, string $message): self
    {
        $result = new self(self::FAILED);
        $result->code = $code;
        $result->message = $message;
        return $result;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isDone(): bool
    {
        return $this->type === self::DONE;
    }

    public function isProgress(): bool
    {
        return $this->type === self::PROGRESS;
    }

    public function isSkipped(): bool
    {
        return $this->type === self::SKIPPED;
    }

    public function isFailed(): bool
    {
        return $this->type === self::FAILED;
    }

    /** @return array<string,mixed> */
    public function cursor(): array
    {
        return $this->cursor;
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return $this->meta;
    }

    public function percent(): int
    {
        return $this->percent;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }
}
