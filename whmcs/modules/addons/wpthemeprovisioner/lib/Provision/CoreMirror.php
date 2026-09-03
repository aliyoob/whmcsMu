<?php
namespace Wtp\Addon\Provision;

use Wtp\Addon\Support\Db;
use Wtp\Addon\Support\HttpClient;
use Wtp\Addon\Support\Storage;
use Wtp\Shared\Security\SecurityException;
use Wtp\Shared\Security\UrlGuard;
use Wtp\Shared\Support\Clock;
use Wtp\Shared\Support\Json;

/**
 * Keeps a local, checksummed copy of the WordPress core archive.
 *
 * Mirroring rather than letting each new account download from wordpress.org:
 *
 *  - many hosting accounts have restricted outbound access
 *  - wordpress.org publishes a .sha1 next to each release, not a sha256, and
 *    we want one integrity mechanism for every package in the system
 *  - a hundred provisionings a day should not be a hundred downloads
 *
 * Direct downloads remain available (`wp_core_source = wordpress.org`) for
 * operators who prefer them; in that mode the .sha1 is fetched and verified.
 */
final class CoreMirror
{
    private const VERSION_CHECK_URL = 'https://api.wordpress.org/core/version-check/1.2/';
    private const DOWNLOAD_TEMPLATE = 'https://wordpress.org/wordpress-%s.zip';
    private const CACHE_KEY = 'wp_latest_version';
    private const CACHE_TTL = 21600; // 6 hours

    /** @var Storage */
    private $storage;
    /** @var HttpClient */
    private $http;
    /** @var Db */
    private $db;
    /** @var Clock */
    private $clock;

    public function __construct(Storage $storage, HttpClient $http, Db $db, Clock $clock)
    {
        $this->storage = $storage;
        $this->http = $http;
        $this->db = $db;
        $this->clock = $clock;
    }

    /**
     * Resolve a configured version ("latest" or an explicit "6.5.2") to a real
     * release number.
     */
    public function resolveVersion(string $configured): string
    {
        $configured = trim($configured);
        if ($configured !== '' && $configured !== 'latest') {
            if (!preg_match('/^\d+(\.\d+){1,2}$/', $configured)) {
                throw new \InvalidArgumentException('Invalid WordPress version: ' . $configured);
            }
            return $configured;
        }

        $cached = $this->readCache();
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->http->get(self::VERSION_CHECK_URL, ['timeout' => 15]);
        if ($response['error'] !== '' || (int) $response['status'] !== 200) {
            $stale = $this->readCache(true);
            if ($stale !== null) {
                return $stale; // an outdated answer beats no answer
            }
            throw new \RuntimeException('Cannot determine the current WordPress version');
        }

        $data = Json::tryDecode($response['body'], []);
        $version = '';
        if (isset($data['offers'][0]['current'])) {
            $version = (string) $data['offers'][0]['current'];
        }
        if (!preg_match('/^\d+(\.\d+){1,2}$/', $version)) {
            throw new \RuntimeException('wordpress.org returned an unrecognisable version');
        }

        $this->writeCache($version);
        return $version;
    }

    /**
     * Ensure the archive for $version is cached locally.
     *
     * @return array{version:string,path:string,sha256:string,size:int,filename:string}
     */
    public function ensureCached(string $version): array
    {
        $filename = 'wordpress-' . $version . '.zip';
        $relative = 'core/' . $filename;

        if ($this->storage->exists($relative)) {
            return [
                'version' => $version,
                'path' => $relative,
                'sha256' => $this->storage->checksum($relative),
                'size' => $this->storage->size($relative),
                'filename' => $filename,
            ];
        }

        $url = sprintf(self::DOWNLOAD_TEMPLATE, $version);
        $expectedSha1 = $this->fetchSha1($url);

        $temp = tempnam(sys_get_temp_dir(), 'wtp-core-');
        if ($temp === false) {
            throw new \RuntimeException('Cannot create a temporary file for the WordPress download');
        }

        try {
            $this->downloadTo($url, $temp);

            if ($expectedSha1 !== '' && !hash_equals($expectedSha1, (string) hash_file('sha1', $temp))) {
                throw new \RuntimeException('The WordPress archive failed wordpress.org\'s own sha1 check');
            }

            $stored = $this->storage->put($temp, $filename, 'core');
        } finally {
            @unlink($temp);
        }

        return [
            'version' => $version,
            'path' => $stored['path'],
            'sha256' => $stored['sha256'],
            'size' => $stored['size'],
            'filename' => $filename,
        ];
    }

    /** The direct wordpress.org URL plus its published sha1. */
    public function directSource(string $version): array
    {
        $url = sprintf(self::DOWNLOAD_TEMPLATE, $version);
        return [
            'version' => $version,
            'url' => $url,
            'sha1' => $this->fetchSha1($url),
            'filename' => 'wordpress-' . $version . '.zip',
        ];
    }

    private function fetchSha1(string $url): string
    {
        $response = $this->http->get($url . '.sha1', ['timeout' => 15]);
        if ($response['error'] !== '' || (int) $response['status'] !== 200) {
            return '';
        }
        $sha1 = strtolower(trim($response['body']));
        return preg_match('/^[a-f0-9]{40}$/', $sha1) === 1 ? $sha1 : '';
    }

    /** Streamed download with an SSRF check and a hard size cap. */
    private function downloadTo(string $url, string $target): void
    {
        $guard = new UrlGuard(['https'], ['wordpress.org', 'downloads.wordpress.org'], true);
        try {
            $guard->validate($url);
        } catch (SecurityException $e) {
            throw new \RuntimeException('Refusing to download WordPress from ' . $url . ': ' . $e->getMessage());
        }

        $handle = fopen($target, 'wb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot open the WordPress download target');
        }

        $received = 0;
        $limit = 209715200; // 200 MiB
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 900,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($handle, &$received, $limit): int {
                $received += strlen($chunk);
                if ($received > $limit) {
                    return 0;
                }
                fwrite($handle, $chunk);
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($handle);

        if ($errno !== 0) {
            throw new \RuntimeException('WordPress download failed: ' . $error);
        }
        if ($status !== 200) {
            throw new \RuntimeException('WordPress download failed with HTTP ' . $status);
        }
        if ($received < 1048576) {
            throw new \RuntimeException('The WordPress download was implausibly small');
        }
    }

    private function readCache(bool $allowStale = false): ?string
    {
        $row = $this->db->selectOne('SELECT v, updated_at FROM mod_wtp_settings WHERE k = :k', ['k' => self::CACHE_KEY]);
        if ($row === null || $row['v'] === '') {
            return null;
        }
        if (!$allowStale) {
            $age = $this->clock->now() - (int) strtotime((string) $row['updated_at'] . ' UTC');
            if ($age > self::CACHE_TTL) {
                return null;
            }
        }
        return (string) $row['v'];
    }

    private function writeCache(string $version): void
    {
        $affected = $this->db->update(
            'mod_wtp_settings',
            ['v' => $version, 'updated_at' => $this->clock->nowSql()],
            ['k' => self::CACHE_KEY]
        );
        if ($affected === 0) {
            $this->db->insert('mod_wtp_settings', [
                'k' => self::CACHE_KEY,
                'v' => $version,
                'updated_at' => $this->clock->nowSql(),
            ]);
        }
    }
}
