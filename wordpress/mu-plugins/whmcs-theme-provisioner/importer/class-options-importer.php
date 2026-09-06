<?php
/**
 * Declarative option writes.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies the `options_to_set` block of a demo's import plan.
 *
 * Exists so a demo can pin a theme option or a plugin setting without shipping
 * a whole customizer export, and without anyone needing to write PHP.
 *
 * The plan validator already guarantees these are flat scalars; this class adds
 * the second half of the rule — that a demo may not touch options which control
 * the site's identity, its users, or the agent itself.
 */
final class Wtp_Import_Options_Importer implements Wtp_Importer
{
    /** Options a demo may never write, whatever its plan says. */
    private const PROTECTED_OPTIONS = [
        'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role',
        'upload_path', 'upload_url_path', 'active_plugins', 'template', 'stylesheet',
        'db_version', 'initial_db_version', 'cron', 'rewrite_rules', 'site_url',
        'wtp_agent_secret', 'wtp_agent_state', 'auth_key', 'auth_salt',
    ];

    public function key(): string
    {
        return 'options';
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     */
    public function supports(array $plan, array $step): bool
    {
        return isset($step['options']) && is_array($step['options']) && $step['options'] !== [];
    }

    public function run(Wtp_Import_Context $context): Wtp_Import_Result
    {
        $step = $context->step();
        $options = isset($step['options']) && is_array($step['options']) ? $step['options'] : [];

        if ($options === []) {
            return Wtp_Import_Result::skipped('This demo sets no options');
        }

        $applied = 0;
        $refused = [];

        foreach ($options as $key => $value) {
            $key = (string) $key;

            if ($this->isProtected($key)) {
                $refused[] = $key;
                continue;
            }
            update_option($key, $value);
            $applied++;
        }

        if ($refused !== []) {
            $context->logger()->warning('Refused to let the demo write protected options', [
                'options' => $refused,
            ], 'option_refused');
        }

        $context->markDone('', ['applied' => $applied]);

        return Wtp_Import_Result::done(['applied' => $applied, 'refused' => count($refused)]);
    }

    private function isProtected(string $key): bool
    {
        if (in_array($key, self::PROTECTED_OPTIONS, true)) {
            return true;
        }
        if (strpos($key, 'wtp_') === 0) {
            return true;
        }
        if (strpos($key, 'user_role') !== false) {
            return true;
        }
        return false;
    }
}
