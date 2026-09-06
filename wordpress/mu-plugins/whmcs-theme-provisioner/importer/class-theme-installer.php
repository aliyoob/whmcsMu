<?php
/**
 * Theme installation and activation.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Wtp\Shared\Security\SafeZip;

/**
 * Installs the purchased theme and switches to it.
 *
 * Already-installed is success, not a conflict: the customer may have been
 * provisioned before, or a retry may be picking up after a crash between
 * extraction and activation.
 */
final class Wtp_Import_Theme_Installer implements Wtp_Importer
{
    public function key(): string
    {
        return 'theme';
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     */
    public function supports(array $plan, array $step): bool
    {
        return !empty($plan['theme']['slug']);
    }

    public function run(Wtp_Import_Context $context): Wtp_Import_Result
    {
        $plan = $context->plan();
        $slug = (string) $plan['theme']['slug'];
        $package = isset($plan['theme']['package']) && is_array($plan['theme']['package'])
            ? $plan['theme']['package']
            : null;

        if ($package === null) {
            return Wtp_Import_Result::failed('plan_invalid', 'The plan has no theme package');
        }

        $theme = wp_get_theme($slug);

        if (!$theme->exists()) {
            $installed = $this->install($context, $slug, $package);
            if ($installed instanceof Wtp_Import_Result) {
                return $installed;
            }
            $theme = wp_get_theme($slug);
        } else {
            $context->logger()->info('Theme is already installed', ['theme' => $slug]);
        }

        if (!$theme->exists()) {
            return Wtp_Import_Result::failed(
                'theme_not_found',
                'The package was extracted but no theme directory named "' . $slug . '" appeared. '
                . 'Check that the catalogue slug matches the theme folder inside the zip.'
            );
        }

        $errors = $theme->errors();
        if ($errors instanceof WP_Error) {
            return Wtp_Import_Result::failed('theme_broken', 'The theme reports: ' . $errors->get_error_message());
        }

        if (get_stylesheet() !== $slug) {
            switch_theme($slug);
            $context->logger()->info('Activated theme', ['theme' => $slug, 'version' => $theme->get('Version')]);
        }

        $context->markDone('', ['theme' => $slug]);

        return Wtp_Import_Result::done([
            'theme' => $slug,
            'version' => (string) $theme->get('Version'),
            'template' => (string) $theme->get_template(),
        ]);
    }

    /**
     * @param array<string,mixed> $package
     * @return Wtp_Import_Result|null a result on failure, null on success
     */
    private function install(Wtp_Import_Context $context, string $slug, array $package): ?Wtp_Import_Result
    {
        try {
            $archive = $context->downloader()->fetch($package);
            $report = $context->downloader()->inspectArchive($archive, SafeZip::CODE_EXTENSIONS);

            // A theme package must contain exactly one top-level directory, and
            // extracting into wp-content/themes is only safe because SafeZip has
            // already rejected traversal, symlinks and absolute paths.
            if ($report['root'] !== null && $report['root'] !== $slug) {
                $context->logger()->warning(
                    'The theme package root does not match the catalogue slug',
                    ['expected' => $slug, 'found' => $report['root']]
                );
            }

            $themesDir = trailingslashit(get_theme_root());
            $context->downloader()->extract($archive, $themesDir, SafeZip::CODE_EXTENSIONS);

            $context->logger()->info('Installed theme package', [
                'theme' => $slug,
                'entries' => $report['entries'],
                'bytes' => $report['uncompressed'],
            ]);

            // Themes installed outside the WP_Upgrader flow need the cache clearing.
            wp_clean_themes_cache();

            return null;
        } catch (Wtp_Security_Exception $e) {
            return Wtp_Import_Result::failed($e->errorCode(), $e->getMessage());
        } catch (Throwable $e) {
            return Wtp_Import_Result::failed('theme_install_failed', $e->getMessage());
        }
    }
}
