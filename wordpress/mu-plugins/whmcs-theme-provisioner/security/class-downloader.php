<?php
/**
 * Fetching and verifying packages.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Wtp\Shared\Security\SafeZip;
use Wtp\Shared\Security\SecurityException;
use Wtp\Shared\Security\UrlGuard;

/**
 * Turns a plan package descriptor into a verified local file.
 *
 * Three kinds of package, one contract: whatever comes back is checksum-verified
 * before any caller is allowed to touch it.
 *
 *   asset  exchanged for a signed, single-use WHMCS URL, then downloaded
 *   url    an absolute https URL pinned by sha256 in the plan
 *   wporg  a slug, resolved through WordPress's own repository API
 *
 * Downloaded files land in a private temporary directory that is cleaned up
 * when the tick ends, so a failed import never leaves a half-written zip in
 * the uploads folder.
 */
final class Wtp_Security_Downloader
{
    /** @var Wtp_Api_Client */
    private $client;
    /** @var Wtp_Logger */
    private $logger;
    /** @var int */
    private $maxBytes;
    /** @var string[] */
    private $temporaryFiles = [];

    public function __construct(Wtp_Api_Client $client, Wtp_Logger $logger, int $maxBytes = 268435456)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->maxBytes = max(1048576, $maxBytes);
    }

    /**
     * @param array<string,mixed> $package a validated plan package descriptor
     * @return string absolute path to the verified file
     * @throws Wtp_Security_Exception
     */
    public function fetch(array $package): string
    {
        $kind = (string) ($package['kind'] ?? '');

        switch ($kind) {
            case 'asset':
                return $this->fetchAsset($package);
            case 'url':
                return $this->fetchUrl(
                    (string) $package['url'],
                    (string) $package['sha256'],
                    (string) ($package['filename'] ?? 'package.zip')
                );
            case 'wporg':
                return $this->fetchFromRepository($package);
            default:
                throw new Wtp_Security_Exception('plan_invalid', 'Unknown package kind: ' . $kind);
        }
    }

    /**
     * @param array<string,mixed> $package
     * @throws Wtp_Security_Exception
     */
    private function fetchAsset(array $package): string
    {
        $handle = (string) $package['asset_id'];

        try {
            $grant = $this->client->requestGrant($handle);
        } catch (Wtp_Api_Exception $e) {
            throw new Wtp_Security_Exception($e->errorCode(), 'Could not obtain a download link: ' . $e->getMessage());
        }

        if (empty($grant['grant']['url'])) {
            throw new Wtp_Security_Exception('grant_missing', 'WHMCS returned no download link');
        }

        // The plan's checksum is authoritative. The grant repeats it, and a
        // disagreement between the two means something changed underneath us.
        $expected = strtolower((string) $package['sha256']);
        $fromGrant = strtolower((string) ($grant['asset']['sha256'] ?? $expected));
        if (!hash_equals($expected, $fromGrant)) {
            throw new Wtp_Security_Exception(
                'checksum_mismatch',
                'The download grant does not match the checksum in the plan'
            );
        }

        return $this->download(
            (string) $grant['grant']['url'],
            $expected,
            (string) ($package['filename'] ?? 'package.zip'),
            false
        );
    }

    private function fetchUrl(string $url, string $sha256, string $filename): string
    {
        return $this->download($url, strtolower($sha256), $filename, true);
    }

    /**
     * Resolve a wordpress.org slug to its current package.
     *
     * The repository does not publish sha256 digests, so this path is trusted
     * on the strength of TLS to api.wordpress.org and downloads.wordpress.org
     * only — which is exactly the trust WordPress core itself places in them
     * every time it installs a plugin.
     *
     * @param array<string,mixed> $package
     */
    private function fetchFromRepository(array $package): string
    {
        $slug = (string) $package['slug'];
        $type = (string) ($package['repository'] ?? 'plugin');

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/theme-install.php';

        if ($type === 'theme') {
            $info = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        } else {
            $info = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        }

        if (is_wp_error($info) || empty($info->download_link)) {
            throw new Wtp_Security_Exception(
                'wporg_lookup_failed',
                'wordpress.org has no package for "' . $slug . '"'
            );
        }

        return $this->download((string) $info->download_link, '', $slug . '.zip', true);
    }

    /**
     * @param string $sha256 empty to skip verification (wordpress.org only)
     * @throws Wtp_Security_Exception
     */
    private function download(string $url, string $sha256, string $filename, bool $guardHost): string
    {
        if ($guardHost) {
            try {
                (new UrlGuard(['https'], null, true))->validate($url);
            } catch (SecurityException $e) {
                throw new Wtp_Security_Exception('unsafe_url', $e->getMessage());
            }
        } elseif (stripos($url, 'https://') !== 0) {
            throw new Wtp_Security_Exception('unsafe_url', 'Download links must use https');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $this->logger->debug('Downloading package', ['filename' => $filename]);

        $temporary = download_url($url, 300);
        if (is_wp_error($temporary)) {
            throw new Wtp_Security_Exception(
                'download_failed',
                'Download failed: ' . $temporary->get_error_message()
            );
        }
        $this->temporaryFiles[] = $temporary;

        $size = (int) @filesize($temporary);
        if ($size <= 0) {
            throw new Wtp_Security_Exception('download_incomplete', 'The downloaded package is empty');
        }
        if ($size > $this->maxBytes) {
            throw new Wtp_Security_Exception(
                'download_too_large',
                'The package is ' . $size . ' bytes, over the ' . $this->maxBytes . ' byte limit'
            );
        }

        if ($sha256 !== '') {
            $actual = (string) hash_file('sha256', $temporary);
            if (!hash_equals($sha256, $actual)) {
                throw new Wtp_Security_Exception(
                    'checksum_mismatch',
                    'The downloaded package does not match its expected checksum'
                );
            }
        }

        $this->logger->debug('Package verified', [
            'filename' => $filename,
            'bytes' => $size,
            'sha256' => $sha256 !== '' ? $sha256 : '(not published)',
        ]);

        return $temporary;
    }

    /**
     * Inspect an archive before anything touches its contents.
     *
     * @param string[]|null $allowedExtensions
     * @return array{entries:int,uncompressed:int,root:?string}
     * @throws Wtp_Security_Exception
     */
    public function inspectArchive(string $path, ?array $allowedExtensions = null): array
    {
        try {
            $report = (new SafeZip($allowedExtensions))->inspect($path);
        } catch (SecurityException $e) {
            throw new Wtp_Security_Exception('unsupported_archive', $e->getMessage());
        }
        unset($report['names']);
        return $report;
    }

    /** @throws Wtp_Security_Exception */
    public function extract(string $archive, string $destination, ?array $allowedExtensions = null): array
    {
        if (!is_dir($destination) && !wp_mkdir_p($destination)) {
            throw new Wtp_Security_Exception('write_failed', 'Cannot create ' . $destination);
        }
        try {
            return (new SafeZip($allowedExtensions))->extractTo($archive, $destination);
        } catch (SecurityException $e) {
            throw new Wtp_Security_Exception('unsupported_archive', $e->getMessage());
        }
    }

    /** Delete everything this downloader created. Called at the end of a tick. */
    public function cleanup(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->temporaryFiles = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
