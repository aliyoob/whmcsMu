<?php
/**
 * API failure type.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** An API failure carrying the server's machine-readable error code. */
final class Wtp_Api_Exception extends RuntimeException
{
    /** @var string */
    private $errorCode;
    /** @var int */
    private $status;

    public function __construct(string $errorCode, string $message, int $status = 0)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** Whether another attempt could plausibly succeed. */
    public function isRetryable(): bool
    {
        if ($this->errorCode === 'network_error') {
            return true;
        }
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
