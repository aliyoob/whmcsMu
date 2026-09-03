<?php
namespace Wtp\Addon\Api;

/**
 * An error that maps directly to an HTTP response.
 *
 * The public `error` code is intentionally coarse for authentication failures
 * so the endpoint is not an oracle; the precise reason goes to the job log.
 */
class ApiException extends \RuntimeException
{
    /** @var int */
    private $status;
    /** @var string */
    private $error;
    /** @var array<string,mixed> */
    private $meta;

    /** @param array<string,mixed> $meta */
    public function __construct(int $status, string $error, string $message = '', array $meta = [])
    {
        parent::__construct($message !== '' ? $message : $error);
        $this->status = $status;
        $this->error = $error;
        $this->meta = $meta;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function error(): string
    {
        return $this->error;
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return $this->meta;
    }

    public static function unauthorized(string $message = 'Authentication failed'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbidden(string $message = 'Not permitted'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function conflict(string $error, string $message): self
    {
        return new self(409, $error, $message);
    }

    public static function gone(string $error, string $message): self
    {
        return new self(410, $error, $message);
    }

    public static function unprocessable(string $error, string $message): self
    {
        return new self(422, $error, $message);
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'Too many requests', ['retry_after' => $retryAfter]);
    }
}
