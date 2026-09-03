<?php
namespace Wtp\Addon\Api;

use Wtp\Shared\Http\HmacSigner;
use Wtp\Shared\Support\Json;

/**
 * An immutable view of the incoming HTTP request.
 *
 * Built once from the superglobals (or, in tests, from an array) so no code
 * below the router ever reads $_SERVER/$_POST directly.
 */
final class Request
{
    /** @var string */
    private $method;
    /** @var string path relative to the API base, e.g. "/steps/theme.install" */
    private $path;
    /** @var string the full request path, used for signature verification */
    private $signedPath;
    /** @var array<string,string> lowercase header names */
    private $headers;
    /** @var array<string,string> */
    private $query;
    /** @var string */
    private $body;
    /** @var string */
    private $ip;
    /** @var bool */
    private $secure;

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     */
    public function __construct(
        string $method,
        string $path,
        string $signedPath,
        array $headers,
        array $query,
        string $body,
        string $ip,
        bool $secure
    ) {
        $this->method = strtoupper($method);
        $this->path = '/' . trim($path, '/');
        $this->signedPath = $signedPath;
        $this->headers = HmacSigner::normalizeHeaders($headers);
        $this->query = $query;
        $this->body = $body;
        $this->ip = $ip;
        $this->secure = $secure;
    }

    /**
     * Build from PHP superglobals.
     *
     * $basePath is stripped from the request URI so the router sees stable
     * routes no matter where the endpoint is deployed.
     */
    public static function fromGlobals(string $basePath = '/provisioning/v1'): self
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $fullPath = parse_url($uri, PHP_URL_PATH);
        $fullPath = is_string($fullPath) ? $fullPath : '/';

        $relative = $fullPath;
        $basePath = '/' . trim($basePath, '/');
        if ($basePath !== '/' && strpos($fullPath, $basePath) === 0) {
            $relative = substr($fullPath, strlen($basePath));
        }
        if ($relative === '' || $relative === false) {
            $relative = '/';
        }

        $body = (string) file_get_contents('php://input');

        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_scalar($v)) {
                $query[(string) $k] = (string) $v;
            }
        }

        return new self(
            isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET',
            $relative,
            $fullPath,
            self::headersFromServer(),
            $query,
            $body,
            self::clientIp(),
            self::detectSecure()
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    /** Path relative to the API base ("/job", "/steps/theme.install"). */
    public function path(): string
    {
        return $this->path;
    }

    /** The path the client signed — always the full request path. */
    public function signedPath(): string
    {
        return $this->signedPath;
    }

    /** @return string[] path segments with empties removed */
    public function segments(): array
    {
        return array_values(array_filter(explode('/', $this->path), static function (string $s): bool {
            return $s !== '';
        }));
    }

    public function header(string $name, string $default = ''): string
    {
        $key = strtolower(str_replace('_', '-', $name));
        return isset($this->headers[$key]) ? $this->headers[$key] : $default;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function query(string $name, string $default = ''): string
    {
        return isset($this->query[$name]) ? $this->query[$name] : $default;
    }

    /** @return array<string,string> */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string,mixed>
     * @throws ApiException on malformed or oversized JSON
     */
    public function json(int $maxBytes = 262144): array
    {
        if ($this->body === '') {
            return [];
        }
        try {
            return Json::decode($this->body, $maxBytes);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(400, 'invalid_json', 'Request body is not valid JSON');
        }
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('idempotency-key');
        if ($key === '' || !preg_match('/^[A-Za-z0-9._\-]{8,128}$/', $key)) {
            return null;
        }
        return $key;
    }

    public function agent(): string
    {
        return substr($this->header('x-wtp-agent', $this->header('user-agent', 'unknown')), 0, 191);
    }

    // ------------------------------------------------------------- globals

    /** @return array<string,string> */
    private static function headersFromServer(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (strpos($key, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $header) {
            if (isset($_SERVER[$server])) {
                $headers[$header] = (string) $_SERVER[$server];
            }
        }
        return $headers;
    }

    /**
     * The connecting address. Proxy headers are deliberately NOT trusted by
     * default — they are attacker-controlled, and this value feeds the rate
     * limiter. Set WTP_TRUSTED_PROXIES when a real reverse proxy is in front.
     */
    private static function clientIp(): string
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

        $trusted = getenv('WTP_TRUSTED_PROXIES');
        if ($trusted === false || $trusted === '') {
            return $remote;
        }
        $list = array_filter(array_map('trim', explode(',', $trusted)));
        if (!in_array($remote, $list, true)) {
            return $remote;
        }
        $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
        if ($forwarded === '') {
            return $remote;
        }
        $first = trim(explode(',', $forwarded)[0]);
        return filter_var($first, FILTER_VALIDATE_IP) ? $first : $remote;
    }

    private static function detectSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        // Behind a terminating proxy WHMCS itself relies on this header.
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return false;
    }
}
