<?php
/**
 * Deciding when the agent runs.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Wtp\Shared\Http\HmacSigner;
use Wtp\Shared\Support\SystemClock;

/**
 * Three independent triggers, because a freshly provisioned site has no
 * visitors and therefore no WP-Cron:
 *
 *  1. **WHMCS pokes us.** The billing server signs a request to admin-ajax and
 *     the agent runs a tick. This is the primary path and the only one that
 *     works on a site nobody has visited yet.
 *  2. **Self-dispatch.** After a tick that still has work left, the agent fires
 *     a non-blocking request to itself so a long import proceeds at its own
 *     pace instead of one step per WHMCS poll.
 *  3. **WP-Cron.** A one-minute schedule as a backstop for the case where the
 *     site is reachable from the outside world but WHMCS is not.
 */
final class Wtp_Scheduler
{
    public const AJAX_ACTION = 'wtp_tick';
    public const CRON_HOOK = 'wtp_agent_tick';
    public const SCHEDULE = 'wtp_every_minute';

    /** @var Wtp_Config */
    private $config;
    /** @var Wtp_State */
    private $state;
    /** @var callable():void */
    private $runner;

    public function __construct(Wtp_Config $config, Wtp_State $state, callable $runner)
    {
        $this->config = $config;
        $this->state = $state;
        $this->runner = $runner;
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedule']);

        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleTrigger']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'handleTrigger']);
        add_action(self::CRON_HOOK, [$this, 'handleCron']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 30, self::SCHEDULE, self::CRON_HOOK);
        }
    }

    /**
     * @param array<string,array<string,mixed>> $schedules
     * @return array<string,array<string,mixed>>
     */
    public function addSchedule(array $schedules): array
    {
        if (!isset($schedules[self::SCHEDULE])) {
            $schedules[self::SCHEDULE] = [
                'interval' => 60,
                'display' => 'Every minute (WHMCS provisioning)',
            ];
        }
        return $schedules;
    }

    /**
     * admin-ajax entry point.
     *
     * Authenticated by the same HMAC scheme the agent uses outbound, so only
     * WHMCS (or the agent itself) can start a tick. An unauthenticated poke
     * would let anyone force a site to hammer the billing server.
     */
    public function handleTrigger(): void
    {
        nocache_headers();

        if (!$this->verifyTrigger()) {
            status_header(401);
            wp_send_json(['success' => false, 'error' => 'unauthorized'], 401);
        }

        ($this->runner)();

        wp_send_json([
            'success' => true,
            'agent' => WTP_AGENT_VERSION,
            'phase' => $this->state->phase(),
            'completed' => $this->state->completedSteps(),
        ]);
    }

    public function handleCron(): void
    {
        if ($this->state->isFinished()) {
            $this->unschedule();
            return;
        }
        ($this->runner)();
    }

    /**
     * Ask ourselves to continue, without waiting for the answer.
     *
     * `blocking => false` means the current request returns immediately; the
     * new request picks up the lock and carries on. If the loopback is blocked
     * (some hosts forbid it) nothing breaks — WP-Cron and WHMCS still fire.
     */
    public function dispatchSelf(): void
    {
        $secret = $this->config->secret();
        if ($secret === '') {
            return;
        }

        $url = admin_url('admin-ajax.php');
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $body = 'action=' . self::AJAX_ACTION;

        $signer = new HmacSigner(new SystemClock());
        $headers = $signer->sign($secret, $this->config->jobId(), 'POST', $path, $body);
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        wp_remote_post($url, [
            'timeout' => 1,
            'blocking' => false,
            'sslverify' => false, // loopback to ourselves; the certificate may be self-signed
            'headers' => $headers,
            'body' => $body,
            'cookies' => [],
        ]);
    }

    public function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    private function verifyTrigger(): bool
    {
        $secret = $this->config->secret();
        if ($secret === '') {
            // Before the handshake there is no secret to verify against, so the
            // only acceptable trigger is one carrying the bootstrap token.
            return $this->verifyBootstrapTrigger();
        }

        $signer = new HmacSigner(new SystemClock());
        $path = (string) wp_parse_url(admin_url('admin-ajax.php'), PHP_URL_PATH);
        $body = file_get_contents('php://input');

        return $signer->verify(
            $secret,
            'POST',
            $path,
            is_string($body) ? $body : '',
            $this->requestHeaders()
        );
    }

    private function verifyBootstrapTrigger(): bool
    {
        $token = $this->config->bootstrapToken();
        if ($token === '') {
            return false;
        }
        $headers = $this->requestHeaders();
        $provided = isset($headers['authorization']) ? (string) $headers['authorization'] : '';

        $expected = 'WTP-Bootstrap ' . $this->config->jobId() . '.' . $token;
        return hash_equals($expected, $provided);
    }

    /** @return array<string,string> */
    private function requestHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        return $headers;
    }
}
