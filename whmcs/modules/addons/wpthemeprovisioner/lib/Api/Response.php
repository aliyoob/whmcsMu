<?php
namespace Wtp\Addon\Api;

use Wtp\Shared\Support\Json;

/** A JSON (or streamed file) response, emitted once by the router. */
final class Response
{
    /** @var int */
    private $status;
    /** @var array<string,string> */
    private $headers;
    /** @var string */
    private $body;
    /** @var callable|null streams the body instead of buffering it */
    private $streamer;

    /** @param array<string,string> $headers */
    public function __construct(int $status, string $body = '', array $headers = [], ?callable $streamer = null)
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
        $this->streamer = $streamer;
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            Json::encode($data),
            array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers)
        );
    }

    /** @param array<string,mixed> $meta */
    public static function error(int $status, string $error, string $message, array $meta = []): self
    {
        $body = ['success' => false, 'error' => $error, 'message' => $message];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }
        $headers = ['Content-Type' => 'application/json; charset=utf-8'];
        if (isset($meta['retry_after'])) {
            $headers['Retry-After'] = (string) (int) $meta['retry_after'];
        }
        return new self($status, Json::encode($body), $headers);
    }

    /** @param array<string,string> $headers */
    public static function stream(callable $streamer, array $headers, int $status = 200): self
    {
        return new self($status, '', $headers, $streamer);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isStream(): bool
    {
        return $this->streamer !== null;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    /** @return array<string,mixed> */
    public function decoded(): array
    {
        return Json::tryDecode($this->body, []);
    }

    /** Write the response to the client. */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            // These apply to every response this endpoint ever produces.
            header('X-Content-Type-Options: nosniff', true);
            header('Cache-Control: no-store, private', true);
            header('Referrer-Policy: no-referrer', true);
        }

        if ($this->streamer !== null) {
            ($this->streamer)();
            return;
        }
        echo $this->body;
    }
}
