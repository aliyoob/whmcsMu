<?php
namespace Wtp\Addon\Api;

use Throwable;
use Wtp\Addon\Container;
use Wtp\Addon\Support\EventLog;

/**
 * The agent API's front controller.
 *
 * Routing is an explicit table rather than a regex sweep: every reachable
 * endpoint is visible in one place, and an unmatched path is a 404 rather than
 * an accidental match on a controller that was never meant to be public.
 *
 * Versioning: this router serves the frozen v1 contract. A future v2 gets its
 * own router and its own controller namespace; v1 keeps behaving exactly as the
 * shipped agents expect.
 */
final class Router
{
    public const VERSION = '1.0';

    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->dispatch($request);
        } catch (ApiException $e) {
            return Response::error($e->status(), $e->error(), $e->getMessage(), $e->meta())
                ->withHeader('X-WTP-Api', self::VERSION);
        } catch (Throwable $e) {
            // Never leak a stack trace or a file path to the agent.
            $reference = substr(hash('sha256', (string) $e->getMessage() . $e->getFile() . $e->getLine()), 0, 12);
            error_log('[WTP][' . $reference . '] ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());

            return Response::error(
                500,
                'internal_error',
                'The provisioning API failed to handle this request',
                ['reference' => $reference]
            )->withHeader('X-WTP-Api', self::VERSION);
        }
    }

    private function dispatch(Request $request): Response
    {
        $segments = $request->segments();
        $method = $request->method();
        $head = isset($segments[0]) ? $segments[0] : '';

        // CORS is never needed: the agent is a server-side HTTP client.
        if ($method === 'OPTIONS') {
            throw new ApiException(405, 'method_not_allowed', 'This API does not accept preflight requests');
        }

        switch ($head) {
            case '':
            case 'health':
                $this->allow($method, ['GET']);
                return (new HealthController($this->container))->handle($request);

            case 'handshake':
                $this->allow($method, ['POST']);
                return (new HandshakeController($this->container))->handle($request);

            case 'job':
                $this->allow($method, ['GET']);
                return (new JobController($this->container))->handle($request);

            case 'steps':
                $this->allow($method, ['POST']);
                if (!isset($segments[1]) || $segments[1] === '') {
                    throw ApiException::notFound('A step key is required');
                }
                return (new StepController($this->container))->handle($request, $segments[1]);

            case 'events':
                $this->allow($method, ['POST']);
                return (new EventController($this->container))->handle($request);

            case 'assets':
                $this->allow($method, ['POST']);
                if (!isset($segments[1]) || !isset($segments[2]) || $segments[2] !== 'grant') {
                    throw ApiException::notFound('Unknown asset route');
                }
                return (new AssetController($this->container))->grant($request, $segments[1]);

            case 'download':
                $this->allow($method, ['GET']);
                return (new DownloadController($this->container))->handle($request);

            case 'complete':
                $this->allow($method, ['POST']);
                return (new CompletionController($this->container))->complete($request);

            case 'fail':
                $this->allow($method, ['POST']);
                return (new CompletionController($this->container))->fail($request);

            default:
                throw ApiException::notFound('Unknown endpoint');
        }
    }

    /** @param string[] $allowed */
    private function allow(string $method, array $allowed): void
    {
        if (!in_array($method, $allowed, true)) {
            throw new ApiException(
                405,
                'method_not_allowed',
                'Allowed: ' . implode(', ', $allowed),
                ['allow' => $allowed]
            );
        }
    }

    /**
     * Shared post-processing: stamp the API version and record the call.
     */
    public static function decorate(Response $response, ?EventLog $log = null, ?int $jobId = null, string $path = ''): Response
    {
        $out = $response->withHeader('X-WTP-Api', self::VERSION);
        if ($log !== null && $jobId !== null && $response->status() >= 400) {
            $log->warning($jobId, 'API call failed', [
                'path' => $path,
                'status' => $response->status(),
            ], null, 'api_error');
        }
        return $out;
    }
}
