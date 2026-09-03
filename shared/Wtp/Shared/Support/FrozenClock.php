<?php
namespace Wtp\Shared\Support;

/** Test double: a clock that only moves when you move it. */
final class FrozenClock implements Clock
{
    /** @var int */
    private $t;

    public function __construct(int $t = 1700000000)
    {
        $this->t = $t;
    }

    public function now(): int
    {
        return $this->t;
    }

    public function nowSql(): string
    {
        return gmdate('Y-m-d H:i:s', $this->t);
    }

    public function advance(int $seconds): void
    {
        $this->t += $seconds;
    }

    public function set(int $t): void
    {
        $this->t = $t;
    }
}
