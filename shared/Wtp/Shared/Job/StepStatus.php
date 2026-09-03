<?php
namespace Wtp\Shared\Job;

final class StepStatus
{
    public const PENDING   = 'pending';
    public const RUNNING   = 'running';
    public const COMPLETED = 'completed';
    public const FAILED    = 'failed';
    public const SKIPPED   = 'skipped';

    /** @return string[] */
    public static function all(): array
    {
        return [self::PENDING, self::RUNNING, self::COMPLETED, self::FAILED, self::SKIPPED];
    }

    public static function isTerminal(string $status): bool
    {
        return $status === self::COMPLETED || $status === self::SKIPPED;
    }
}
