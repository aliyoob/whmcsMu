<?php
/**
 * Elementor kit import.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Wtp\Shared\Security\SafeZip;
use Wtp\Shared\Support\Json;

/**
 * Applies an Elementor kit and repairs Elementor's own data after an import.
 *
 * Two jobs, both of which the generic content importer cannot do:
 *
 *  1. apply the kit's site settings (`_elementor_page_settings` on the kit post)
 *  2. rewrite the demo's URLs *inside* Elementor's slash-escaped JSON, which a
 *     plain database replace corrupts, and flush Elementor's CSS cache so the
 *     new colours actually render
 *
 * If Elementor is not active the step skips cleanly — a demo that lists an
 * Elementor kit but whose plugin failed to install should still produce a site.
 */
final class Wtp_Import_Elementor_Importer implements Wtp_Importer
{
    public function key(): string
    {
        return 'elementor';
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     */
    public function supports(array $plan, array $step): bool
    {
        return true;
    }

    public function run(Wtp_Import_Context $context): Wtp_Import_Result
    {
        if (!did_action('elementor/loaded') && !class_exists('\Elementor\Plugin')) {
            return Wtp_Import_Result::skipped('Elementor is not active on this site');
        }
        if ($context->alreadyDone()) {
            return Wtp_Import_Result::done(['repeated' => true]);
        }

        $applied = [];

        $asset = $context->asset();
        if ($asset !== null) {
            try {
                $applied['kit'] = $this->applyKit($context, $asset);
            } catch (Wtp_Security_Exception $e) {
                return Wtp_Import_Result::failed($e->errorCode(), $e->getMessage());
            } catch (Throwable $e) {
                $context->logger()->warning('The Elementor kit could not be applied', [
                    'reason' => $e->getMessage(),
                ], 'elementor_kit_failed');
                $applied['kit'] = false;
            }
        }

        $sourceUrl = (string) ($context->planOption('source_site_url', '') ?? '');
        if ($sourceUrl !== '' && $context->planOption('replace_urls', true)) {
            $applied['urls_rewritten'] = $this->rewriteUrls($sourceUrl, home_url());
        }

        $this->flushCss();

        $context->markDone('', $applied);
        $context->logger()->info('Finished Elementor post-processing', $applied);

        return Wtp_Import_Result::done($applied);
    }

    /**
     * @param array<string,mixed> $asset
     * @throws Wtp_Security_Exception
     */
    private function applyKit(Wtp_Import_Context $context, array $asset): bool
    {
        $file = $context->downloader()->fetch($asset);
        $filename = strtolower((string) ($asset['filename'] ?? ''));

        $settings = [];

        if (substr($filename, -4) === '.zip') {
            $directory = get_temp_dir() . 'wtp-kit-' . wp_generate_password(8, false);
            $context->downloader()->extract($file, $directory, SafeZip::CONTENT_EXTENSIONS);

            $candidates = glob($directory . '/site-settings.json')
                ?: glob($directory . '/*/site-settings.json')
                ?: glob($directory . '/*.json');

            if ($candidates) {
                $settings = Json::tryDecode((string) @file_get_contents($candidates[0]), []);
            }
        } else {
            $settings = Json::tryDecode((string) @file_get_contents($file), []);
        }

        if ($settings === []) {
            return false;
        }
        // Some exports wrap the settings; accept either shape.
        if (isset($settings['settings']) && is_array($settings['settings'])) {
            $settings = $settings['settings'];
        }

        $kitId = (int) get_option('elementor_active_kit', 0);
        if ($kitId <= 0) {
            return false;
        }

        $existing = get_post_meta($kitId, '_elementor_page_settings', true);
        $merged = is_array($existing) ? array_replace($existing, $settings) : $settings;
        update_post_meta($kitId, '_elementor_page_settings', $merged);

        return true;
    }

    /**
     * Elementor stores its documents as JSON with escaped slashes, so the demo
     * URL appears as `https:\/\/demo.example.com`. Replacing only the plain
     * form leaves every image and link pointing at the demo site.
     *
     * @return int rows updated
     */
    private function rewriteUrls(string $from, string $to): int
    {
        global $wpdb;

        $from = rtrim($from, '/');
        $to = rtrim($to, '/');
        if ($from === '' || $from === $to) {
            return 0;
        }

        $escapedFrom = str_replace('/', '\\/', $from);
        $escapedTo = str_replace('/', '\\/', $to);

        $updated = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)
             WHERE meta_key IN ('_elementor_data', '_elementor_page_settings', '_elementor_css')",
            $escapedFrom,
            $escapedTo
        ));

        $updated += (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)
             WHERE meta_key IN ('_elementor_data', '_elementor_page_settings', '_elementor_css')",
            $from,
            $to
        ));

        return $updated;
    }

    /** Elementor caches generated CSS per post; stale files mean stale colours. */
    private function flushCss(): void
    {
        if (class_exists('\Elementor\Plugin')) {
            $plugin = \Elementor\Plugin::instance();
            if (isset($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
                $plugin->files_manager->clear_cache();
                return;
            }
        }
        delete_post_meta_by_key('_elementor_css');
        delete_option('_elementor_global_css');
    }
}
