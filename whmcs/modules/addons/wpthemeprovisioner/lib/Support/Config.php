<?php
namespace Wtp\Addon\Support;

use RuntimeException;

/**
 * Configuration resolution, in priority order:
 *
 *   1. the environment file  (default /etc/whmcs-wtp/wtp.env, 0600, outside the web root)
 *   2. real environment variables (WTP_*)
 *   3. the addon's own settings row in mod_wtp_settings
 *   4. the hard defaults below
 *
 * Secrets are only ever read from (1) or (2). Nothing secret is stored in the
 * database in clear, in the addon configuration screen, or in the repository.
 */
final class Config
{
    public const ENV_PATH_CONSTANT = 'WTP_ENV_FILE';
    public const DEFAULT_ENV_PATH = '/etc/whmcs-wtp/wtp.env';

    /**
     * A file the installer may drop in the module root holding nothing but the
     * absolute path of the environment file:
     *
     *     <?php return '/home/billing/.whmcs-wtp/wtp.env';
     *
     * It exists for stacks where we cannot set an environment variable for the
     * web process without editing generated configuration that the panel will
     * later overwrite — cPanel being the reason it was written. It carries a
     * path, never a secret, and the .php extension means a direct HTTP request
     * for it executes and prints nothing.
     */
    public const ENV_POINTER_FILE = 'env-path.php';

    /** @var array<string,string> */
    private $env = [];
    /** @var array<string,string> */
    private $settings = [];
    /** @var array<string,mixed> */
    private $defaults = [
        'api_base_url' => '',                 // derived from WHMCS system URL when empty
        'api_path' => '/provisioning/v1',
        'storage_path' => '/var/lib/whmcs-wtp/packages',
        'agent_package_path' => '/var/lib/whmcs-wtp/agent',
        'wp_core_source' => 'wordpress.org',   // or "mirror"
        'wp_version' => 'latest',
        'bootstrap_ttl' => 1800,               // seconds
        'secret_ttl' => 86400,
        'download_ttl' => 600,
        'download_max_uses' => 3,
        'nonce_ttl' => 900,
        'idempotency_ttl' => 86400,
        'clock_skew' => 300,
        'worker_batch' => 10,
        'worker_lock_ttl' => 300,
        'max_job_attempts' => 5,
        'http_timeout' => 30,
        'http_connect_timeout' => 10,
        'rate_limit_api' => 300,               // requests per window per job
        'rate_limit_window' => 300,
        'rate_limit_auth_failures' => 10,
        'enforce_https' => 1,
        'log_level' => 'info',
        'log_retention_days' => 60,
    ];

    /** @var array<string,string>|null */
    private static $envCache;

    /** @param array<string,string> $settings rows from mod_wtp_settings */
    public function __construct(array $settings = [], ?string $envPath = null)
    {
        $this->settings = $settings;
        $this->env = self::loadEnv($envPath);
    }

    /** @param mixed $default @return mixed */
    public function get(string $key, $default = null)
    {
        $envKey = 'WTP_' . strtoupper($key);

        if (isset($this->env[$envKey]) && $this->env[$envKey] !== '') {
            return $this->env[$envKey];
        }
        $fromProcess = getenv($envKey);
        if ($fromProcess !== false && $fromProcess !== '') {
            return $fromProcess;
        }
        if (isset($this->settings[$key]) && $this->settings[$key] !== '') {
            return $this->settings[$key];
        }
        if (array_key_exists($key, $this->defaults)) {
            return $this->defaults[$key];
        }
        return $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $v = $this->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, $default ? '1' : '0');
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public function getString(string $key, string $default = ''): string
    {
        $v = $this->get($key, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    /**
     * The AES master key protecting agent secrets at rest.
     * Its absence is a hard, loud failure: silently falling back to an
     * unencrypted store would be worse than not provisioning at all.
     */
    public function masterKey(): string
    {
        $key = $this->getString('master_key');
        if ($key === '') {
            throw new RuntimeException(
                'WTP_MASTER_KEY is not set. Create it with `php bin/wtp-keygen.php` and store it in '
                . $this->envPath() . ' (chmod 0600). See docs/security.md.'
            );
        }
        return $key;
    }

    public function hasMasterKey(): bool
    {
        return $this->getString('master_key') !== '';
    }

    public function envPath(): string
    {
        return self::resolveEnvPath(null);
    }

    /** Absolute, scheme-qualified base URL of the agent API. */
    public function apiBaseUrl(string $systemUrlFallback = ''): string
    {
        $base = rtrim($this->getString('api_base_url'), '/');
        if ($base === '') {
            $base = rtrim($systemUrlFallback, '/');
        }
        if ($base === '') {
            throw new RuntimeException('Cannot determine the WHMCS system URL for the provisioning API');
        }
        if ($this->getBool('enforce_https', true) && stripos($base, 'https://') !== 0) {
            throw new RuntimeException('The provisioning API base URL must use https: ' . $base);
        }
        return $base . $this->getString('api_path', '/provisioning/v1');
    }

    // ------------------------------------------------------------------ env

    private static function resolveEnvPath(?string $override): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }
        if (defined(self::ENV_PATH_CONSTANT)) {
            return (string) constant(self::ENV_PATH_CONSTANT);
        }
        $fromEnv = getenv('WTP_ENV_FILE');
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }

        $pointer = self::envPointerPath();
        if (is_file($pointer) && is_readable($pointer)) {
            /** @psalm-suppress UnresolvableInclude */
            $value = @include $pointer;
            // Absolute paths only: a relative one would resolve against
            // whatever directory the caller happened to be in.
            if (is_string($value) && isset($value[0]) && $value[0] === '/') {
                return $value;
            }
        }

        return self::DEFAULT_ENV_PATH;
    }

    /** Absolute path of the pointer file described on ENV_POINTER_FILE. */
    public static function envPointerPath(): string
    {
        return dirname(__DIR__, 2) . '/' . self::ENV_POINTER_FILE;
    }

    /**
     * Parse a `KEY=value` file. Supports quoting and `#` comments; ignores
     * anything malformed rather than throwing, so a stray line can never take
     * the billing system down.
     *
     * @return array<string,string>
     */
    public static function loadEnv(?string $path = null): array
    {
        $resolved = self::resolveEnvPath($path);
        if (self::$envCache !== null && $path === null) {
            return self::$envCache;
        }

        $out = [];
        if (is_file($resolved) && is_readable($resolved)) {
            $lines = file($resolved, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ((array) $lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key = strtoupper(trim(substr($line, 0, $pos)));
                $value = trim(substr($line, $pos + 1));
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }
                if (preg_match('/^[A-Z0-9_]+$/', $key)) {
                    $out[$key] = $value;
                }
            }
        }

        if ($path === null) {
            self::$envCache = $out;
        }
        return $out;
    }

    /** Test seam. */
    public static function resetEnvCache(): void
    {
        self::$envCache = null;
    }

    /** @return array<string,mixed> */
    public function defaults(): array
    {
        return $this->defaults;
    }
}
