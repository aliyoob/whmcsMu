<?php
namespace Wtp\Addon\Api;

use Wtp\Addon\Container;

/**
 * Unauthenticated liveness probe.
 *
 * It reports only what an anonymous caller may know: that the endpoint is
 * alive and which contract version it speaks. No counts, no versions of
 * WHMCS, no configuration.
 */
final class HealthController
{
    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request): Response
    {
        $healthy = true;
        $checks = [];

        try {
            $this->container->db()->scalar('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'error';
            $healthy = false;
        }

        $checks['secrets'] = $this->container->config()->hasMasterKey() ? 'ok' : 'missing';
        if ($checks['secrets'] !== 'ok') {
            $healthy = false;
        }

        $checks['transport'] = $request->isSecure() ? 'https' : 'http';

        return Response::json([
            'success' => $healthy,
            'api' => Router::VERSION,
            'checks' => $checks,
        ], $healthy ? 200 : 503, ['X-WTP-Api' => Router::VERSION]);
    }
}
