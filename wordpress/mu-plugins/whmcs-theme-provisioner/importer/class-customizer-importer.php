<?php
/**
 * Customizer settings import.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies a customizer export (`.dat`, as produced by the widely used
 * "Customizer Export/Import" plugin).
 *
 * The file is a serialized array:
 *
 *   ['template' => 'astra', 'mods' => [...], 'options' => [...]]
 *
 * Deserialization is done with `allowed_classes => false`. A demo package is
 * operator-supplied, but "operator uploaded a file" is not a reason to hand a
 * customer's site an object-injection primitive.
 */
final class Wtp_Import_Customizer_Importer implements Wtp_Importer
{
    public function key(): string
    {
        return 'customizer';
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     */
    public function supports(array $plan, array $step): bool
    {
        return isset($step['asset']) && is_array($step['asset']);
    }

    public function run(Wtp_Import_Context $context): Wtp_Import_Result
    {
        $asset = $context->asset();
        if ($asset === null) {
            return Wtp_Import_Result::skipped('This demo ships no customizer export');
        }
        if ($context->alreadyDone()) {
            return Wtp_Import_Result::done(['repeated' => true]);
        }

        try {
            $file = $context->downloader()->fetch($asset);
        } catch (Wtp_Security_Exception $e) {
            return Wtp_Import_Result::failed($e->errorCode(), $e->getMessage());
        }

        $raw = (string) @file_get_contents($file);
        if ($raw === '') {
            return Wtp_Import_Result::failed('customizer_unreadable', 'The customizer export is empty');
        }

        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data)) {
            // Newer exporters emit JSON; accept either.
            $data = json_decode($raw, true);
        }
        if (!is_array($data)) {
            return Wtp_Import_Result::failed(
                'customizer_invalid',
                'The customizer export is neither a serialized array nor JSON'
            );
        }

        $template = isset($data['template']) ? (string) $data['template'] : '';
        $current = get_template();
        if ($template !== '' && $template !== $current) {
            $context->logger()->warning('The customizer export was made for a different theme', [
                'export_for' => $template,
                'active' => $current,
            ], 'customizer_theme_mismatch');
        }

        $mods = isset($data['mods']) && is_array($data['mods']) ? $data['mods'] : [];
        $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];

        $appliedMods = 0;
        foreach ($mods as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            // nav_menu_locations are remapped by the finaliser once menus exist.
            set_theme_mod($key, $value);
            $appliedMods++;
        }

        $appliedOptions = 0;
        foreach ($options as $key => $value) {
            if (!is_string($key) || $key === '' || !$this->optionIsSafe($key)) {
                continue;
            }
            update_option($key, $value);
            $appliedOptions++;
        }

        $context->markDone('', ['mods' => $appliedMods, 'options' => $appliedOptions]);
        $context->logger()->info('Applied customizer settings', [
            'mods' => $appliedMods,
            'options' => $appliedOptions,
        ]);

        return Wtp_Import_Result::done(['mods' => $appliedMods, 'options' => $appliedOptions]);
    }

    /**
     * A customizer export must not be able to rewrite the site's identity or
     * its security-relevant options. Theme and plugin settings are fine;
     * `siteurl`, `home`, user roles and upload paths are not.
     */
    private function optionIsSafe(string $key): bool
    {
        $denied = [
            'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role',
            'upload_path', 'upload_url_path', 'active_plugins', 'template', 'stylesheet',
            'db_version', 'initial_db_version', 'cron', 'rewrite_rules',
            'wtp_agent_secret', 'wtp_agent_state',
        ];
        if (in_array($key, $denied, true)) {
            return false;
        }
        if (strpos($key, 'user_role') !== false || strpos($key, '_transient') === 0) {
            return false;
        }
        return true;
    }
}
