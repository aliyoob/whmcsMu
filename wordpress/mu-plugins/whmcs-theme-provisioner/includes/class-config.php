<?php
/**
 * Agent configuration and credentials.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads the credentials file the installer left behind and the signing secret
 * the handshake produced.
 *
 * The credentials file lives under wp-content/uploads/.wtp/ with a deny-all
 * .htaccess and mode 0600. It is outside the plugin directory on purpose:
 * replacing the agent must not destroy the credentials, and a directory
 * listing of mu-plugins must not reveal them.
 */
final class Wtp_Config
{
    public const SECRET_OPTION = 'wtp_agent_secret';
    public const CREDENTIALS_DIR = '.wtp';
    public const CREDENTIALS_FILE = 'credentials.php';

    /** @var array<string,mixed>|null */
    private $credentials;
    /** @var bool */
    private $loaded = false;

    public function credentialsPath(): string
    {
        $uploads = wp_upload_dir(null, false);
        $base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
        return $base . '/' . self::CREDENTIALS_DIR . '/' . self::CREDENTIALS_FILE;
    }

    /** @return array<string,mixed>|null */
    public function credentials(): ?array
    {
        if ($this->loaded) {
            return $this->credentials;
        }
        $this->loaded = true;
        $this->credentials = null;

        $path = $this->credentialsPath();
        if (!is_readable($path)) {
            return null;
        }

        $data = include $path;
        if (!is_array($data) || empty($data['job']) || empty($data['api_base'])) {
            return null;
        }

        $this->credentials = [
            'version' => (int) ($data['version'] ?? 1),
            'job' => (string) $data['job'],
            'api_base' => rtrim((string) $data['api_base'], '/'),
            'bootstrap_token' => (string) ($data['bootstrap_token'] ?? ''),
            'issued_at' => (int) ($data['issued_at'] ?? 0),
        ];

        return $this->credentials;
    }

    public function isProvisioned(): bool
    {
        return $this->credentials() !== null;
    }

    public function jobId(): string
    {
        $credentials = $this->credentials();
        return $credentials === null ? '' : (string) $credentials['job'];
    }

    public function apiBase(): string
    {
        $credentials = $this->credentials();
        return $credentials === null ? '' : (string) $credentials['api_base'];
    }

    public function bootstrapToken(): string
    {
        $credentials = $this->credentials();
        return $credentials === null ? '' : (string) $credentials['bootstrap_token'];
    }

    /**
     * The HMAC signing secret received at handshake.
     *
     * Stored in wp_options rather than on disk so it is covered by the site's
     * own backups and so a file-permission mistake cannot expose it separately
     * from the database that already holds every other site credential.
     */
    public function secret(): string
    {
        $secret = get_option(self::SECRET_OPTION, '');
        return is_string($secret) ? $secret : '';
    }

    public function storeSecret(string $secret): void
    {
        update_option(self::SECRET_OPTION, $secret, false);
    }

    public function hasSecret(): bool
    {
        return $this->secret() !== '';
    }

    /**
     * Remove everything that could still be used to talk to WHMCS.
     *
     * Called when the job finishes: the agent's job is done, so its credentials
     * should stop existing. WHMCS revokes its side at the same moment.
     */
    public function forget(): void
    {
        delete_option(self::SECRET_OPTION);

        $path = $this->credentialsPath();
        if (is_file($path)) {
            @unlink($path);
        }
        $this->credentials = null;
        $this->loaded = true;
    }

    /** Absolute URL of this site, as WordPress understands it. */
    public function siteUrl(): string
    {
        return rtrim((string) get_option('siteurl', home_url()), '/');
    }
}
