<?php
/**
 * A security or integrity failure in the agent.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Wtp_Security_Exception extends RuntimeException
{
    /** @var string */
    private $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
