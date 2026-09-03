<?php
namespace Wtp\Shared\Job;

/** Job-level states. PHP 7.4 target, so constants rather than an enum. */
final class JobStatus
{
    public const PENDING         = 'pending';
    public const RUNNING         = 'running';
    public const AWAITING_AGENT  = 'awaiting_agent';
    public const IMPORTING       = 'importing';
    public const VERIFYING       = 'verifying';
    public const COMPLETED       = 'completed';
    public const FAILED          = 'failed';
    public const CANCELLED       = 'cancelled';
    public const NEEDS_ATTENTION = 'needs_attention';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::PENDING, self::RUNNING, self::AWAITING_AGENT, self::IMPORTING,
            self::VERIFYING, self::COMPLETED, self::FAILED, self::CANCELLED,
            self::NEEDS_ATTENTION,
        ];
    }

    /** @return string[] states from which no automatic progress happens */
    public static function terminal(): array
    {
        return [self::COMPLETED, self::CANCELLED];
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::terminal(), true);
    }

    /** States the cron worker will pick up. */
    public static function isActive(string $status): bool
    {
        return in_array($status, [
            self::PENDING, self::RUNNING, self::AWAITING_AGENT,
            self::IMPORTING, self::VERIFYING,
        ], true);
    }
}
