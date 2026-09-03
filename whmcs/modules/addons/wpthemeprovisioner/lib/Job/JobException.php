<?php
namespace Wtp\Addon\Job;

/**
 * Carries a machine-readable code alongside the human message, so the error
 * classifier can decide retry-vs-fail without parsing English.
 *
 * ($code is deliberately named $errorCode: Exception::$code already exists and
 * is an int.)
 */
class JobException extends \RuntimeException
{
    /** @var string */
    protected $errorCode;

    public function __construct(string $message, string $errorCode = 'job_error', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
