<?php
/**
 * Agent wiring.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Composition root for the must-use plugin.
 *
 * Deliberately cheap on a normal page view: a site whose provisioning finished
 * reads one option and returns. Nothing is constructed, no hooks that matter
 * are registered, and no HTTP calls are made — a provisioning agent that keeps
 * costing the customer performance after it is done would be a defect.
 */
final class Wtp_Plugin
{
    /** @var Wtp_Plugin|null */
    private static $instance;

    /** @var Wtp_Config */
    private $config;
    /** @var Wtp_State */
    private $state;
    /** @var Wtp_Logger */
    private $logger;
    /** @var Wtp_Runner|null */
    private $runner;
    /** @var Wtp_Scheduler|null */
    private $scheduler;

    private function __construct()
    {
        $this->config = new Wtp_Config();
        $this->state = new Wtp_State();
        $this->logger = new Wtp_Logger();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        // Nothing to do on a site that was never provisioned by us, or whose
        // provisioning is over.
        if (!$this->config->isProvisioned() || $this->state->isFinished()) {
            add_action('admin_notices', [$this, 'renderCompletionNotice']);
            return;
        }

        $this->runner = new Wtp_Runner($this->config, $this->state, $this->logger, new Wtp_Import_Registry());

        $this->scheduler = new Wtp_Scheduler($this->config, $this->state, function (): void {
            $this->runner->tick();
        });
        $this->runner->setScheduler($this->scheduler);
        $this->scheduler->register();

        add_action('admin_notices', [$this, 'renderProgressNotice']);

        // A visitor arriving on a site that is still importing gives us a free
        // opportunity to make progress — but only after the response has been
        // sent, so nobody waits on it.
        add_action('shutdown', [$this, 'maybeNudge'], 99);
    }

    public function config(): Wtp_Config
    {
        return $this->config;
    }

    public function state(): Wtp_State
    {
        return $this->state;
    }

    public function runner(): ?Wtp_Runner
    {
        return $this->runner;
    }

    /**
     * Kick the agent from a normal page view, without delaying it.
     *
     * WP-Cron is unreliable on a site with no traffic and WHMCS's poll is once
     * a minute; a visitor hitting the site is the cheapest possible trigger.
     */
    public function maybeNudge(): void
    {
        if ($this->scheduler === null || $this->state->isFinished()) {
            return;
        }
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return; // the trigger endpoint is already handling this
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }

        // At most once a minute, whatever the traffic.
        $last = (int) get_transient('wtp_last_nudge');
        if ($last > 0) {
            return;
        }
        set_transient('wtp_last_nudge', time(), 60);

        $this->scheduler->dispatchSelf();
    }

    /** Progress notice for an administrator who logs in mid-provisioning. */
    public function renderProgressNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $phase = $this->state->phase();
        $plan = $this->state->plan();
        $done = count($this->state->completedSteps());

        $message = 'Your site is still being set up automatically.';
        if ($plan !== null) {
            $message = sprintf(
                'Installing the %s theme with the "%s" demo — %d step%s completed so far.',
                (string) ($plan['theme']['name'] ?? $plan['theme']['slug'] ?? 'selected'),
                (string) ($plan['demo']['name'] ?? $plan['demo']['slug'] ?? 'selected'),
                $done,
                $done === 1 ? '' : 's'
            );
        }

        printf(
            '<div class="notice notice-info"><p><strong>Site setup in progress.</strong> %s</p>'
            . '<p>You can keep using the site; content will appear as it is imported. '
            . 'Please avoid installing themes or plugins until this finishes.</p></div>',
            esc_html($message)
        );
        unset($phase);
    }

    /** One-time notice after a failed provisioning, for the site owner. */
    public function renderCompletionNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if ($this->state->phase() !== Wtp_State::PHASE_FAILED) {
            return;
        }
        if (get_option('wtp_failure_notice_dismissed')) {
            return;
        }

        $error = $this->state->get('last_error', []);
        $detail = is_array($error) && !empty($error['message']) ? (string) $error['message'] : '';

        printf(
            '<div class="notice notice-warning"><p><strong>Automatic site setup did not finish.</strong> '
            . 'Your hosting account is working normally, but the demo content was not fully imported. '
            . 'Please contact support and quote reference <code>%s</code>.%s</p></div>',
            esc_html(substr($this->config->jobId(), 0, 8)),
            $detail === '' ? '' : ' <span style="color:#666;">(' . esc_html($detail) . ')</span>'
        );
    }
}
