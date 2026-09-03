<?php
namespace Wtp\Shared\Plan;

use Wtp\Shared\Support\Json;
use Wtp\Shared\Support\Str;

/**
 * The self-describing provisioning plan.
 *
 * This document is the ONLY thing the WordPress agent knows about WHMCS. It
 * contains no WHMCS identifiers the agent could act on, no secrets, and no
 * absolute server paths — only asset handles the agent must exchange for a
 * signed URL, plus declarative import instructions.
 *
 * Validation runs on both sides:
 *   - WHMCS validates before storing a demo's plan and before serving it
 *   - the agent validates on receipt, so a compromised or buggy WHMCS cannot
 *     make it write outside its own site
 */
final class ImportPlan
{
    public const VERSION = 1;

    /** Importer keys the agent knows how to dispatch. */
    public const IMPORTERS = [
        'theme', 'plugins', 'content', 'widgets', 'customizer', 'elementor', 'options', 'finalize',
    ];

    public const PACKAGE_KINDS = ['asset', 'wporg', 'url'];

    /** @var array<string,mixed> */
    private $data;

    /** @param array<string,mixed> $data */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(self::validate($data));
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(Json::decode($json, 4194304));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(bool $pretty = false): string
    {
        return Json::encode($this->data, $pretty);
    }

    /** @return array<string,mixed> */
    public function theme(): array
    {
        return $this->data['theme'];
    }

    /** @return array<string,mixed> */
    public function demo(): array
    {
        return $this->data['demo'];
    }

    /** @return array<int,array<string,mixed>> */
    public function plugins(): array
    {
        return $this->data['plugins'];
    }

    /** @return array<int,array<string,mixed>> */
    public function steps(): array
    {
        return $this->data['steps'];
    }

    /** @return array<string,mixed>|null */
    public function step(string $key): ?array
    {
        foreach ($this->data['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }
        return null;
    }

    public function hasStep(string $key): bool
    {
        return $this->step($key) !== null;
    }

    /** @return array<string,mixed> */
    public function site(): array
    {
        return $this->data['site'];
    }

    /** @return array<string,mixed> */
    public function options(): array
    {
        return $this->data['options'];
    }

    /** @return array<string,mixed> */
    public function policy(): array
    {
        return $this->data['policy'];
    }

    /** Stable identity for idempotency markers written into wp_options. */
    public function fingerprint(): string
    {
        $material = [
            $this->data['theme']['slug'] ?? '',
            $this->data['theme']['version'] ?? '',
            $this->data['demo']['slug'] ?? '',
            count($this->data['steps']),
        ];
        return substr(hash('sha256', implode('|', $material)), 0, 32);
    }

    // ---------------------------------------------------------------- validation

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> the normalised plan
     * @throws PlanException
     */
    public static function validate(array $data): array
    {
        $version = isset($data['version']) ? (int) $data['version'] : 0;
        if ($version !== self::VERSION) {
            throw new PlanException('Unsupported plan version: ' . $version);
        }

        $out = ['version' => self::VERSION];

        // --- theme -----------------------------------------------------------
        if (!isset($data['theme']) || !is_array($data['theme'])) {
            throw new PlanException('Plan is missing the theme block');
        }
        $theme = $data['theme'];
        $slug = isset($theme['slug']) ? (string) $theme['slug'] : '';
        if ($slug === '' || $slug !== Str::slug($slug)) {
            throw new PlanException('Theme slug is missing or not a valid slug');
        }
        $out['theme'] = [
            'id' => isset($theme['id']) ? (int) $theme['id'] : 0,
            'slug' => $slug,
            'name' => self::text($theme['name'] ?? $slug, 191),
            'version' => self::version($theme['version'] ?? ''),
            'package' => self::validatePackage($theme['package'] ?? null, 'theme.package'),
        ];

        // --- demo ------------------------------------------------------------
        if (!isset($data['demo']) || !is_array($data['demo'])) {
            throw new PlanException('Plan is missing the demo block');
        }
        $demo = $data['demo'];
        $demoSlug = isset($demo['slug']) ? (string) $demo['slug'] : '';
        if ($demoSlug === '' || $demoSlug !== Str::slug($demoSlug)) {
            throw new PlanException('Demo slug is missing or not a valid slug');
        }
        $out['demo'] = [
            'id' => isset($demo['id']) ? (int) $demo['id'] : 0,
            'slug' => $demoSlug,
            'name' => self::text($demo['name'] ?? $demoSlug, 191),
        ];

        // --- plugins ---------------------------------------------------------
        $out['plugins'] = [];
        $plugins = isset($data['plugins']) && is_array($data['plugins']) ? $data['plugins'] : [];
        if (count($plugins) > 50) {
            throw new PlanException('Too many plugins in plan (max 50)');
        }
        foreach ($plugins as $i => $plugin) {
            if (!is_array($plugin)) {
                throw new PlanException('plugins[' . $i . '] is not an object');
            }
            $pkg = self::validatePackage($plugin, 'plugins[' . $i . ']');
            $pkg['activate'] = !empty($plugin['activate']);
            $out['plugins'][] = $pkg;
        }

        // --- steps -----------------------------------------------------------
        $out['steps'] = [];
        $steps = isset($data['steps']) && is_array($data['steps']) ? $data['steps'] : [];
        if (count($steps) > 40) {
            throw new PlanException('Too many steps in plan (max 40)');
        }
        $seen = [];
        foreach ($steps as $i => $step) {
            if (!is_array($step)) {
                throw new PlanException('steps[' . $i . '] is not an object');
            }
            $key = isset($step['key']) ? (string) $step['key'] : '';
            if ($key === '' || !preg_match('/^[a-z][a-z0-9_.]{1,62}$/', $key)) {
                throw new PlanException('steps[' . $i . '].key is invalid');
            }
            if (isset($seen[$key])) {
                throw new PlanException('Duplicate step key: ' . $key);
            }
            $seen[$key] = true;

            $importer = isset($step['importer']) ? (string) $step['importer'] : '';
            if (!in_array($importer, self::IMPORTERS, true)) {
                throw new PlanException('steps[' . $i . '].importer is not a known importer: ' . $importer);
            }

            $entry = ['key' => $key, 'importer' => $importer];

            if (isset($step['asset']) && $step['asset'] !== null) {
                $entry['asset'] = self::validatePackage($step['asset'], 'steps[' . $i . '].asset');
            }
            $entry['options'] = self::scalarMap($step['options'] ?? [], 'steps[' . $i . '].options');
            $entry['optional'] = !empty($step['optional']);

            $out['steps'][] = $entry;
        }

        // --- site ------------------------------------------------------------
        $site = isset($data['site']) && is_array($data['site']) ? $data['site'] : [];
        $out['site'] = [
            'title' => self::text($site['title'] ?? '', 191),
            'tagline' => self::text($site['tagline'] ?? '', 191),
            'language' => self::locale($site['language'] ?? ''),
            'timezone' => self::text($site['timezone'] ?? 'UTC', 64),
            'permalink_structure' => self::permalink($site['permalink_structure'] ?? '/%postname%/'),
            'front_page' => self::text($site['front_page'] ?? '', 191),
            'blog_page' => self::text($site['blog_page'] ?? '', 191),
            'menus' => self::scalarMap($site['menus'] ?? [], 'site.menus'),
            'discourage_search_engines' => !empty($site['discourage_search_engines']),
        ];

        // --- options ---------------------------------------------------------
        $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
        $out['options'] = [
            'replace_urls' => !isset($options['replace_urls']) || (bool) $options['replace_urls'],
            'source_site_url' => self::optionalUrl($options['source_site_url'] ?? null, 'options.source_site_url'),
            'fetch_attachments' => !empty($options['fetch_attachments']),
            'delete_default_content' => !isset($options['delete_default_content'])
                || (bool) $options['delete_default_content'],
        ];

        // --- policy ----------------------------------------------------------
        $policy = isset($data['policy']) && is_array($data['policy']) ? $data['policy'] : [];
        $out['policy'] = [
            'max_download_bytes' => self::intRange(
                $policy['max_download_bytes'] ?? 268435456,
                1048576,
                2147483647,
                'policy.max_download_bytes'
            ),
            'step_time_budget' => self::intRange($policy['step_time_budget'] ?? 20, 5, 120, 'policy.step_time_budget'),
            'http_timeout' => self::intRange($policy['http_timeout'] ?? 30, 5, 300, 'policy.http_timeout'),
        ];

        return $out;
    }

    /**
     * A "package" is anything the agent may have to fetch: theme zip, plugin,
     * WXR file, widget JSON. It is either an opaque asset handle (resolved to a
     * signed URL via the API), a wordpress.org slug, or an absolute https URL.
     *
     * @param mixed $raw
     * @return array<string,mixed>
     */
    public static function validatePackage($raw, string $path): array
    {
        if (!is_array($raw)) {
            throw new PlanException($path . ' must be an object');
        }
        $kind = isset($raw['kind']) ? (string) $raw['kind'] : '';
        if (!in_array($kind, self::PACKAGE_KINDS, true)) {
            throw new PlanException($path . '.kind must be one of: ' . implode(', ', self::PACKAGE_KINDS));
        }

        if ($kind === 'asset') {
            $ref = isset($raw['asset_id']) ? (string) $raw['asset_id'] : '';
            if (!preg_match('/^[a-z]{2}:[0-9]{1,10}$/', $ref)) {
                throw new PlanException($path . '.asset_id must look like "da:123"');
            }
            $sha = isset($raw['sha256']) ? strtolower((string) $raw['sha256']) : '';
            if (!Str::isSha256($sha)) {
                throw new PlanException($path . '.sha256 is required for asset packages');
            }
            return [
                'kind' => 'asset',
                'asset_id' => $ref,
                'sha256' => $sha,
                'size_bytes' => isset($raw['size_bytes']) ? max(0, (int) $raw['size_bytes']) : 0,
                'filename' => self::filename($raw['filename'] ?? 'package.zip', $path . '.filename'),
            ];
        }

        if ($kind === 'wporg') {
            $slug = isset($raw['slug']) ? (string) $raw['slug'] : '';
            if ($slug === '' || $slug !== Str::slug($slug)) {
                throw new PlanException($path . '.slug is required for wporg packages');
            }
            return [
                'kind' => 'wporg',
                'slug' => $slug,
                'version' => self::version($raw['version'] ?? ''),
            ];
        }

        // kind === 'url'
        $url = isset($raw['url']) ? (string) $raw['url'] : '';
        if (stripos($url, 'https://') !== 0 || strlen($url) > 2048) {
            throw new PlanException($path . '.url must be an absolute https URL');
        }
        $sha = isset($raw['sha256']) ? strtolower((string) $raw['sha256']) : '';
        if (!Str::isSha256($sha)) {
            throw new PlanException($path . '.sha256 is required for url packages');
        }
        return [
            'kind' => 'url',
            'url' => $url,
            'sha256' => $sha,
            'size_bytes' => isset($raw['size_bytes']) ? max(0, (int) $raw['size_bytes']) : 0,
            'filename' => self::filename($raw['filename'] ?? 'package.zip', $path . '.filename'),
        ];
    }

    // ---------------------------------------------------------------- scalars

    /** @param mixed $v */
    private static function text($v, int $max): string
    {
        if (is_array($v) || is_object($v)) {
            throw new PlanException('Expected a scalar value');
        }
        $s = trim((string) $v);
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        return substr($s, 0, $max);
    }

    /** @param mixed $v */
    private static function version($v): string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return '';
        }
        if (!preg_match('/^[0-9][0-9a-zA-Z.\-+]{0,31}$/', $s)) {
            throw new PlanException('Invalid version string: ' . $s);
        }
        return $s;
    }

    /** @param mixed $v */
    private static function locale($v): string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return '';
        }
        if (!preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})?$/', $s)) {
            throw new PlanException('Invalid locale: ' . $s);
        }
        return $s;
    }

    /** @param mixed $v */
    private static function permalink($v): string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return '';
        }
        if (!preg_match('#^/[A-Za-z0-9%/_.\-]{0,127}$#', $s)) {
            throw new PlanException('Invalid permalink structure');
        }
        return $s;
    }

    /** @param mixed $v */
    private static function filename($v, string $path): string
    {
        $s = basename(trim((string) $v));
        if ($s === '' || $s === '.' || $s === '..' || strpos($s, "\0") !== false) {
            throw new PlanException($path . ' is not a valid filename');
        }
        if (!preg_match('/^[A-Za-z0-9._\- ]{1,191}$/', $s)) {
            throw new PlanException($path . ' contains unsupported characters');
        }
        if (preg_match('/\.(php\d?|phtml|phar|cgi|pl|py|sh|htaccess)$/i', $s)) {
            throw new PlanException($path . ' has a forbidden extension');
        }
        return $s;
    }

    /** @param mixed $v @return array<string,scalar> */
    private static function scalarMap($v, string $path): array
    {
        if ($v === null || $v === '') {
            return [];
        }
        if (!is_array($v)) {
            throw new PlanException($path . ' must be an object');
        }
        if (count($v) > 64) {
            throw new PlanException($path . ' has too many entries');
        }
        $out = [];
        foreach ($v as $k => $val) {
            $key = (string) $k;
            if (!preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $key)) {
                throw new PlanException($path . ' has an invalid key: ' . $key);
            }
            if (is_array($val) || is_object($val)) {
                throw new PlanException($path . '.' . $key . ' must be a scalar');
            }
            if (is_bool($val) || is_int($val) || is_float($val)) {
                $out[$key] = $val;
            } else {
                $out[$key] = self::text($val, 512);
            }
        }
        return $out;
    }

    /** @param mixed $v */
    private static function optionalUrl($v, string $path): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = (string) $v;
        if (!preg_match('#^https?://[^\s]{3,2048}$#i', $s)) {
            throw new PlanException($path . ' must be an absolute URL');
        }
        return $s;
    }

    /** @param mixed $v */
    private static function intRange($v, int $min, int $max, string $path): int
    {
        if (!is_numeric($v)) {
            throw new PlanException($path . ' must be numeric');
        }
        $i = (int) $v;
        if ($i < $min || $i > $max) {
            throw new PlanException($path . ' must be between ' . $min . ' and ' . $max);
        }
        return $i;
    }
}
