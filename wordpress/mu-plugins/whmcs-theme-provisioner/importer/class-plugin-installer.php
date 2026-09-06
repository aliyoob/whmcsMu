<?php
/**
 * Required-plugin installation.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Wtp\Shared\Security\SafeZip;

/**
 * Installs and activates the plugins a demo needs.
 *
 * Works through the list one plugin per pass so a slow repository never blows
 * the tick budget, keeping its position in the cursor. A plugin that fails to
 * install is recorded and skipped rather than failing the whole import: a demo
 * missing one optional plugin is still a usable site, and the operator sees
 * exactly what was missed.
 */
final class Wtp_Import_Plugin_Installer implements Wtp_Importer
{
    public function key(): string
    {
        return 'plugins';
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     */
    public function supports(array $plan, array $step): bool
    {
        return isset($plan['plugins']) && is_array($plan['plugins']) && $plan['plugins'] !== [];
    }

    public function run(Wtp_Import_Context $context): Wtp_Import_Result
    {
        $plugins = $context->plan()['plugins'];
        $total = count($plugins);

        $index = (int) $context->cursorValue('index', 0);
        $installed = (array) $context->cursorValue('installed', []);
        $failed = (array) $context->cursorValue('failed', []);

        if ($total === 0) {
            return Wtp_Import_Result::skipped('This demo requires no plugins');
        }

        while ($index < $total) {
            if (!$context->hasTimeLeft(8.0)) {
                return Wtp_Import_Result::progress(
                    ['index' => $index, 'installed' => $installed, 'failed' => $failed],
                    (int) round(($index / $total) * 100),
                    'Installing plugins'
                );
            }

            $plugin = $plugins[$index];
            $label = $this->label($plugin);

            try {
                $file = $this->ensureInstalled($context, $plugin);

                if ($file !== '' && !empty($plugin['activate'])) {
                    $this->activate($context, $file, $label);
                }
                $installed[] = $label;
            } catch (Throwable $e) {
                $failed[] = ['plugin' => $label, 'reason' => $e->getMessage()];
                $context->logger()->warning('Could not install a required plugin', [
                    'plugin' => $label,
                    'reason' => $e->getMessage(),
                ], 'plugin_install_failed');
            }

            $index++;
        }

        $context->markDone('', ['installed' => $installed]);

        return Wtp_Import_Result::done([
            'installed' => $installed,
            'failed' => $failed,
            'total' => $total,
        ]);
    }

    /**
     * @param array<string,mixed> $plugin
     * @return string the plugin file relative to wp-content/plugins, or '' when unknown
     * @throws Wtp_Security_Exception
     */
    private function ensureInstalled(Wtp_Import_Context $context, array $plugin): string
    {
        $slug = (string) ($plugin['slug'] ?? '');
        $existing = $slug !== '' ? $this->findInstalled($slug) : '';

        if ($existing !== '') {
            $context->logger()->debug('Plugin already installed', ['plugin' => $slug]);
            return $existing;
        }

        $descriptor = $plugin;
        if (($plugin['kind'] ?? '') === 'wporg') {
            $descriptor['repository'] = 'plugin';
        }

        $archive = $context->downloader()->fetch($descriptor);
        $context->downloader()->inspectArchive($archive, SafeZip::CODE_EXTENSIONS);

        $pluginsDir = trailingslashit(WP_PLUGIN_DIR);
        $report = $context->downloader()->extract($archive, $pluginsDir, SafeZip::CODE_EXTENSIONS);

        $context->logger()->info('Installed plugin', [
            'plugin' => $this->label($plugin),
            'root' => $report['root'],
        ]);

        wp_cache_delete('plugins', 'plugins');

        $searchSlug = $slug !== '' ? $slug : (string) ($report['root'] ?? '');
        return $searchSlug === '' ? '' : $this->findInstalled($searchSlug);
    }

    private function activate(Wtp_Import_Context $context, string $file, string $label): void
    {
        if (is_plugin_active($file)) {
            return;
        }
        $result = activate_plugin($file, '', false, true);

        if (is_wp_error($result)) {
            // A plugin that refuses to activate is a warning, not a failure:
            // the site still works and an operator can look at it.
            $context->logger()->warning('Plugin could not be activated', [
                'plugin' => $label,
                'reason' => $result->get_error_message(),
            ], 'plugin_activate_failed');
            return;
        }
        $context->logger()->info('Activated plugin', ['plugin' => $label]);
    }

    /** @return string the plugin's entry file, or '' */
    private function findInstalled(string $slug): string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (array_keys(get_plugins()) as $file) {
            if (strpos((string) $file, $slug . '/') === 0) {
                return (string) $file;
            }
            if ((string) $file === $slug . '.php') {
                return (string) $file;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $plugin */
    private function label(array $plugin): string
    {
        if (!empty($plugin['slug'])) {
            return (string) $plugin['slug'];
        }
        if (!empty($plugin['filename'])) {
            return (string) $plugin['filename'];
        }
        return (string) ($plugin['asset_id'] ?? 'plugin');
    }
}
