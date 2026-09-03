<?php
namespace Wtp\Addon\Catalog;

use Wtp\Shared\Plan\ImportPlan;
use Wtp\Shared\Support\Json;
use Wtp\Shared\Support\Str;

/**
 * Turns catalogue rows into the self-describing plan the WordPress agent runs.
 *
 * The plan is built fresh on every request rather than cached, so deactivating
 * a demo or replacing an asset takes effect for jobs still in flight — and so
 * the agent can never act on a stale checksum.
 *
 * Asset handles are opaque to the agent:
 *   tv:<id>  a theme version package
 *   da:<id>  a demo asset
 */
final class PlanBuilder
{
    /** @var CatalogRepository */
    private $catalog;

    public function __construct(CatalogRepository $catalog)
    {
        $this->catalog = $catalog;
    }

    /**
     * @param array<string,mixed> $theme
     * @param array<string,mixed> $version
     * @param array<string,mixed> $demo
     * @param array<string,mixed> $site   title, tagline, language, timezone, admin_email
     * @param array<string,mixed> $policy overrides
     */
    public function build(array $theme, array $version, array $demo, array $site = [], array $policy = []): ImportPlan
    {
        $planOverrides = Json::tryDecode($demo['import_plan'] ?? null, []);

        $data = [
            'version' => ImportPlan::VERSION,
            'theme' => [
                'id' => (int) $theme['id'],
                'slug' => (string) $theme['slug'],
                'name' => (string) $theme['name'],
                'version' => (string) $version['version'],
                'package' => $this->themePackage($version),
            ],
            'demo' => [
                'id' => (int) $demo['id'],
                'slug' => (string) $demo['slug'],
                'name' => (string) $demo['name'],
            ],
            'plugins' => $this->plugins($demo),
            'steps' => $this->steps($demo, $planOverrides),
            'site' => $this->site($site, $planOverrides),
            'options' => $this->options($planOverrides),
            'policy' => $this->policy($policy, $planOverrides),
        ];

        // Validating here means a malformed catalogue row fails on the WHMCS
        // side, where an operator can see it, rather than inside the customer's
        // WordPress where it would surface as a mysterious import failure.
        return ImportPlan::fromArray($data);
    }

    /** @param array<string,mixed> $version @return array<string,mixed> */
    private function themePackage(array $version): array
    {
        $sourceType = (string) $version['source_type'];

        if ($sourceType === CatalogRepository::SOURCE_WPORG) {
            return [
                'kind' => 'wporg',
                'slug' => (string) $version['source_ref'],
                'version' => (string) $version['version'],
            ];
        }

        if ($sourceType === CatalogRepository::SOURCE_URL) {
            return [
                'kind' => 'url',
                'url' => (string) $version['source_ref'],
                'sha256' => (string) $version['checksum_sha256'],
                'size_bytes' => (int) ($version['size_bytes'] ?? 0),
                'filename' => $this->safeFilename(basename((string) $version['source_ref']), 'theme.zip'),
            ];
        }

        return [
            'kind' => 'asset',
            'asset_id' => 'tv:' . (int) $version['id'],
            'sha256' => (string) $version['checksum_sha256'],
            'size_bytes' => (int) ($version['size_bytes'] ?? 0),
            'filename' => $this->safeFilename(basename((string) $version['source_ref']), 'theme.zip'),
        ];
    }

    /** @param array<string,mixed> $demo @return array<int,array<string,mixed>> */
    private function plugins(array $demo): array
    {
        $declared = Json::tryDecode($demo['required_plugins'] ?? null, []);
        $out = [];

        foreach ($declared as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $source = (string) ($entry['source'] ?? $entry['kind'] ?? 'wporg');

            if ($source === 'wporg') {
                $slug = Str::slug((string) ($entry['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $out[] = [
                    'kind' => 'wporg',
                    'slug' => $slug,
                    'version' => (string) ($entry['version'] ?? ''),
                    'activate' => !isset($entry['activate']) || (bool) $entry['activate'],
                ];
                continue;
            }

            // A plugin shipped as a demo asset.
            $assetId = (int) ($entry['asset_id'] ?? 0);
            if ($assetId <= 0) {
                continue;
            }
            $asset = $this->catalog->findAsset($assetId);
            if ($asset === null || (int) $asset['demo_id'] !== (int) $demo['id']) {
                continue; // never let a demo reference another demo's asset
            }
            $out[] = [
                'kind' => 'asset',
                'asset_id' => 'da:' . $assetId,
                'sha256' => (string) $asset['checksum_sha256'],
                'size_bytes' => (int) $asset['size_bytes'],
                'filename' => $this->safeFilename((string) $asset['filename'], 'plugin.zip'),
                'activate' => !isset($entry['activate']) || (bool) $entry['activate'],
            ];
        }

        return $out;
    }

    /**
     * Steps are derived from the assets that actually exist, so a demo without
     * a widgets file simply has no widgets step — the agent is never asked to
     * import something that is not there.
     *
     * @param array<string,mixed> $demo
     * @param array<string,mixed> $overrides
     * @return array<int,array<string,mixed>>
     */
    private function steps(array $demo, array $overrides): array
    {
        $assets = $this->catalog->listAssets((int) $demo['id']);
        $byRole = [];
        foreach ($assets as $asset) {
            $byRole[(string) $asset['role']][] = $asset;
        }

        $map = [
            'content' => ['key' => 'content.import', 'importer' => 'content', 'optional' => false],
            'widgets' => ['key' => 'widgets.import', 'importer' => 'widgets', 'optional' => true],
            'customizer' => ['key' => 'customizer.import', 'importer' => 'customizer', 'optional' => true],
            'elementor_kit' => ['key' => 'extras.import', 'importer' => 'elementor', 'optional' => true],
        ];

        $steps = [];
        foreach ($map as $role => $def) {
            if (!isset($byRole[$role])) {
                continue;
            }
            $asset = $byRole[$role][0];
            $steps[] = [
                'key' => $def['key'],
                'importer' => $def['importer'],
                'asset' => [
                    'kind' => 'asset',
                    'asset_id' => 'da:' . (int) $asset['id'],
                    'sha256' => (string) $asset['checksum_sha256'],
                    'size_bytes' => (int) $asset['size_bytes'],
                    'filename' => $this->safeFilename((string) $asset['filename'], 'asset.bin'),
                ],
                'options' => $this->stepOptions($role, $overrides),
                'optional' => $def['optional'],
            ];
        }

        // A demo may declare extra option writes without shipping a file.
        $optionWrites = isset($overrides['options_to_set']) && is_array($overrides['options_to_set'])
            ? $overrides['options_to_set'] : [];
        if ($optionWrites !== []) {
            $steps[] = [
                'key' => 'options.apply',
                'importer' => 'options',
                'options' => $optionWrites,
                'optional' => true,
            ];
        }

        return $steps;
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function stepOptions(string $role, array $overrides): array
    {
        $all = isset($overrides['step_options']) && is_array($overrides['step_options'])
            ? $overrides['step_options'] : [];
        $forRole = isset($all[$role]) && is_array($all[$role]) ? $all[$role] : [];

        if ($role === 'content') {
            $forRole += [
                'fetch_attachments' => true,
                'chunk_size' => 40,
            ];
        }
        return $forRole;
    }

    /**
     * @param array<string,mixed> $site
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function site(array $site, array $overrides): array
    {
        $fromPlan = isset($overrides['site']) && is_array($overrides['site']) ? $overrides['site'] : [];

        return [
            // Order-specific values win over the demo's suggestions.
            'title' => (string) ($site['title'] ?? $fromPlan['title'] ?? ''),
            'tagline' => (string) ($site['tagline'] ?? $fromPlan['tagline'] ?? ''),
            'language' => (string) ($site['language'] ?? $fromPlan['language'] ?? ''),
            'timezone' => (string) ($site['timezone'] ?? $fromPlan['timezone'] ?? 'UTC'),
            'permalink_structure' => (string) ($fromPlan['permalink_structure'] ?? '/%postname%/'),
            'front_page' => (string) ($fromPlan['front_page'] ?? ''),
            'blog_page' => (string) ($fromPlan['blog_page'] ?? ''),
            'menus' => isset($fromPlan['menus']) && is_array($fromPlan['menus']) ? $fromPlan['menus'] : [],
            'discourage_search_engines' => !empty($fromPlan['discourage_search_engines']),
        ];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function options(array $overrides): array
    {
        $fromPlan = isset($overrides['options']) && is_array($overrides['options']) ? $overrides['options'] : [];
        return [
            'replace_urls' => !isset($fromPlan['replace_urls']) || (bool) $fromPlan['replace_urls'],
            'source_site_url' => isset($fromPlan['source_site_url']) ? (string) $fromPlan['source_site_url'] : null,
            'fetch_attachments' => !empty($fromPlan['fetch_attachments']),
            'delete_default_content' => !isset($fromPlan['delete_default_content'])
                || (bool) $fromPlan['delete_default_content'],
        ];
    }

    /**
     * @param array<string,mixed> $policy
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function policy(array $policy, array $overrides): array
    {
        $fromPlan = isset($overrides['policy']) && is_array($overrides['policy']) ? $overrides['policy'] : [];
        return [
            'max_download_bytes' => (int) ($policy['max_download_bytes']
                ?? $fromPlan['max_download_bytes'] ?? 268435456),
            'step_time_budget' => (int) ($policy['step_time_budget'] ?? $fromPlan['step_time_budget'] ?? 20),
            'http_timeout' => (int) ($policy['http_timeout'] ?? $fromPlan['http_timeout'] ?? 30),
        ];
    }

    private function safeFilename(string $candidate, string $fallback): string
    {
        $name = basename(trim($candidate));
        if ($name === '' || !preg_match('/^[A-Za-z0-9._\- ]{1,191}$/', $name)) {
            return $fallback;
        }
        if (preg_match('/\.(php\d?|phtml|phar|cgi|pl|py|sh|htaccess)$/i', $name)) {
            return $fallback;
        }
        return $name;
    }
}
