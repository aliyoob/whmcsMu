<?php
/**
 * @package Wtp\Shared
 * PHP 7.4 compatible. No external dependencies.
 */
namespace Wtp\Shared\Support;

interface Clock
{
    /** Current unix timestamp in seconds. */
    public function now(): int;

    /** Current time formatted for MySQL DATETIME columns (UTC). */
    public function nowSql(): string;
}
