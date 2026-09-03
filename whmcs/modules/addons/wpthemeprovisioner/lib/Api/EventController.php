<?php
namespace Wtp\Addon\Api;

use Wtp\Addon\Container;
use Wtp\Addon\Support\EventLog;
use Wtp\Shared\Job\StepCatalog;

/**
 * POST /events — batched structured log lines from the agent.
 *
 * The agent's own diagnostics land in the same timeline as WHMCS's, which is
 * what makes a support ticket answerable from one screen. Everything written
 * here is redacted and size-capped by EventLog on the way in.
 */
final class EventController
{
    private const MAX_EVENTS = 50;

    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request): Response
    {
        $auth = $this->container->authenticator()->authenticateAgent($request);
        $jobId = (int) $auth['job']['id'];

        $body = $request->json(131072);
        $events = isset($body['events']) && is_array($body['events']) ? $body['events'] : [];

        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, 0, self::MAX_EVENTS);
        }

        $log = $this->container->eventLog();
        $accepted = 0;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $message = trim((string) ($event['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $level = strtolower((string) ($event['level'] ?? EventLog::INFO));
            $stepKey = isset($event['step']) ? (string) $event['step'] : null;
            if ($stepKey !== null && !StepCatalog::exists($stepKey)) {
                $stepKey = null;
            }

            $log->log(
                $jobId,
                $level,
                $message,
                isset($event['context']) && is_array($event['context']) ? $event['context'] : [],
                $stepKey,
                isset($event['code']) ? (string) $event['code'] : null,
                'agent'
            );
            $accepted++;
        }

        $this->container->jobs()->heartbeat($jobId);

        return Response::json([
            'success' => true,
            'accepted' => $accepted,
            'dropped' => max(0, count($events) - $accepted),
        ], 202, ['X-WTP-Api' => Router::VERSION]);
    }
}
