<?php
namespace Wtp\Shared\Support;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }

    public function nowSql(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
