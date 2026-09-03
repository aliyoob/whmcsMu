<?php
namespace Wtp\Tests;

/** A valid plan, plus helpers to break exactly one thing at a time. */
final class PlanFixture
{
    /** @return array<string,mixed> */
    public static function valid(): array
    {
        return [
            'version' => 1,
            'theme' => [
                'id' => 10,
                'slug' => 'astra',
                'name' => 'Astra',
                'version' => '4.6.1',
                'package' => [
                    'kind' => 'asset',
                    'asset_id' => 'tv:44',
                    'sha256' => str_repeat('a', 64),
                    'size_bytes' => 2400000,
                    'filename' => 'astra-4.6.1.zip',
                ],
            ],
            'demo' => ['id' => 25, 'slug' => 'agency', 'name' => 'Agency'],
            'plugins' => [
                ['kind' => 'wporg', 'slug' => 'elementor', 'version' => '3.21.0', 'activate' => true],
                [
                    'kind' => 'asset',
                    'asset_id' => 'da:91',
                    'sha256' => str_repeat('b', 64),
                    'filename' => 'astra-pro.zip',
                    'activate' => true,
                ],
            ],
            'steps' => [
                [
                    'key' => 'content.import',
                    'importer' => 'content',
                    'asset' => [
                        'kind' => 'asset',
                        'asset_id' => 'da:88',
                        'sha256' => str_repeat('c', 64),
                        'filename' => 'content.xml',
                    ],
                    'options' => ['fetch_attachments' => true, 'chunk_size' => 50],
                ],
                [
                    'key' => 'widgets.import',
                    'importer' => 'widgets',
                    'asset' => [
                        'kind' => 'asset',
                        'asset_id' => 'da:89',
                        'sha256' => str_repeat('d', 64),
                        'filename' => 'widgets.json',
                    ],
                    'optional' => true,
                ],
            ],
            'site' => [
                'title' => 'Acme Ltd',
                'tagline' => 'We make things',
                'language' => 'en_US',
                'timezone' => 'UTC',
                'permalink_structure' => '/%postname%/',
                'front_page' => 'Home',
                'menus' => ['primary' => 'Main Menu'],
            ],
            'options' => ['replace_urls' => true, 'source_site_url' => 'https://demo.example.com'],
            'policy' => ['max_download_bytes' => 268435456, 'step_time_budget' => 20],
        ];
    }

    /**
     * @param string $dottedPath e.g. "theme.package.sha256"
     * @param mixed $value
     * @return array<string,mixed>
     */
    public static function with(string $dottedPath, $value): array
    {
        $plan = self::valid();
        $ref = &$plan;
        $parts = explode('.', $dottedPath);
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $ref[$part] = $value;
                break;
            }
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        return $plan;
    }

    /** @return array<string,mixed> */
    public static function without(string $key): array
    {
        $plan = self::valid();
        unset($plan[$key]);
        return $plan;
    }
}
