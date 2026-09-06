<?php
namespace Wtp\Tests;

use Wtp\Addon\Support\HttpClient;

/**
 * An outbound HTTP client whose destinations are all in this process.
 *
 * Handlers are matched by substring against the URL, most recently registered
 * first, so a test can override a default for one scenario without unpicking
 * the rest of the harness.
 */
final class RecordingHttpClient extends HttpClient
{
    /** @var array<int,array{needle:string,handler:callable}> */
    private $handlers = [];
    /** @var array<int,array{method:string,url:string,options:array<string,mixed>}> */
    public $calls = [];
    /** @var array{status:int,body:string} */
    private $default = ['status' => 404, 'body' => '{"error":"no handler"}'];

    public function __construct()
    {
        parent::__construct(5, 2);
    }

    /**
     * @param callable(string,string,array<string,mixed>):array{status:int,body:string,headers?:array<string,string>} $handler
     */
    public function on(string $urlContains, callable $handler): void
    {
        array_unshift($this->handlers, ['needle' => $urlContains, 'handler' => $handler]);
    }

    /** Every matching request fails at the transport layer. */
    public function failTransport(string $urlContains, string $error = 'Connection refused', string $code = 'connection_refused'): void
    {
        $this->on($urlContains, static function () use ($error, $code): array {
            return ['status' => 0, 'body' => '', 'error' => $error, 'error_code' => $code];
        });
    }

    public function setDefault(int $status, string $body): void
    {
        $this->default = ['status' => $status, 'body' => $body];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{status:int,body:string,headers:array<string,string>,error:string,error_code:string}
     */
    public function request(string $method, string $url, array $options = []): array
    {
        $this->calls[] = ['method' => strtoupper($method), 'url' => $url, 'options' => $options];

        foreach ($this->handlers as $entry) {
            if (strpos($url, $entry['needle']) === false) {
                continue;
            }
            $response = ($entry['handler'])($method, $url, $options);

            return [
                'status' => (int) ($response['status'] ?? 200),
                'body' => (string) ($response['body'] ?? ''),
                'headers' => (array) ($response['headers'] ?? []),
                'error' => (string) ($response['error'] ?? ''),
                'error_code' => (string) ($response['error_code'] ?? ''),
            ];
        }

        return [
            'status' => $this->default['status'],
            'body' => $this->default['body'],
            'headers' => [],
            'error' => '',
            'error_code' => '',
        ];
    }

    public function countCallsTo(string $urlContains): int
    {
        $count = 0;
        foreach ($this->calls as $call) {
            if (strpos($call['url'], $urlContains) !== false) {
                $count++;
            }
        }
        return $count;
    }

    /** @return array<int,array{method:string,url:string,options:array<string,mixed>}> */
    public function callsTo(string $urlContains): array
    {
        return array_values(array_filter($this->calls, static function (array $call) use ($urlContains): bool {
            return strpos($call['url'], $urlContains) !== false;
        }));
    }

    public function reset(): void
    {
        $this->calls = [];
    }
}
