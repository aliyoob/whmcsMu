<?php
namespace Wtp\Addon\Support;

/**
 * Everything an installer would do that PHP can do for itself.
 *
 * WHMCS has no "upload a zip and install" flow for addon modules the way
 * WordPress does for plugins: the files always arrive by FTP or File Manager,
 * and the only hook the admin area gives us is activation. So activation is
 * where the rest of the setup happens — the package storage, the encryption
 * key, the pointer that lets PHP find that key, and the agent package.
 *
 * That leaves exactly one thing a web request genuinely cannot do, and it is
 * named plainly on the Settings tab rather than left to be discovered: the
 * worker cron entry.
 *
 * Every operation here is idempotent and refuses to destroy anything. In
 * particular an existing master key is never regenerated — doing so would make
 * every stored credential undecryptable, and an accidental second click on
 * Activate must not be able to cause that.
 */
final class SelfSetup
{
    /** @var string */
    private $addonDir;
    /** @var string */
    private $whmcsRoot;
    /** @var string */
    private $home;
    /** @var bool */
    private $cpanel;

    public function __construct(string $addonDir, string $whmcsRoot, string $home, bool $cpanel)
    {
        $this->addonDir = rtrim($addonDir, '/');
        $this->whmcsRoot = rtrim($whmcsRoot, '/');
        $this->home = rtrim($home, '/');
        $this->cpanel = $cpanel;
    }

    /**
     * Work out the surroundings from the running process.
     */
    public static function discover(string $addonDir, string $whmcsRoot): self
    {
        return new self($addonDir, $whmcsRoot, self::detectHome($whmcsRoot), is_dir('/usr/local/cpanel'));
    }

    /**
     * The home directory of the user PHP is running as — falling back to the
     * one implied by the WHMCS path, because a php-fpm pool frequently has no
     * HOME in its environment at all.
     */
    private static function detectHome(string $whmcsRoot): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['dir']) && is_dir((string) $pw['dir'])) {
                return rtrim((string) $pw['dir'], '/');
            }
        }

        $fromEnv = (string) getenv('HOME');
        if ($fromEnv !== '' && is_dir($fromEnv)) {
            return rtrim($fromEnv, '/');
        }

        if (preg_match('#^(/home[0-9]*/[^/]+)/#', $whmcsRoot . '/', $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Where the environment file should live.
     *
     * An existing file always wins: if an operator, or install-module.sh, has
     * already put one somewhere, self-setup's job is to leave it alone. Only
     * when there is nothing do we choose, and then the home directory is the
     * safe answer on cPanel — CageFS and open_basedir hide everything else from
     * the account's PHP.
     */
    public function envPath(): string
    {
        $resolved = Config::currentEnvPath();
        if (is_file($resolved)) {
            return $resolved;
        }

        if (!$this->cpanel && $this->isWritableTarget(Config::DEFAULT_ENV_PATH)) {
            return Config::DEFAULT_ENV_PATH;
        }

        return $this->home !== '' ? $this->home . '/.whmcs-wtp/wtp.env' : $resolved;
    }

    public function storagePath(): string
    {
        $env = Config::loadEnv($this->envPath());
        if (isset($env['WTP_STORAGE_PATH']) && $env['WTP_STORAGE_PATH'] !== '') {
            return rtrim($env['WTP_STORAGE_PATH'], '/');
        }

        $base = dirname($this->envPath());
        return $base . '/packages';
    }

    public function pointerPath(): string
    {
        return $this->addonDir . '/' . Config::ENV_POINTER_FILE;
    }

    public function agentPackagePath(): string
    {
        return $this->storagePath() . '/agent/whmcs-theme-provisioner.zip';
    }

    /** The command the operator has to put in cron, with the PHP actually in use. */
    public function cronCommand(): string
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        return $php . ' -q ' . $this->addonDir . '/cron/worker.php >/dev/null 2>&1';
    }

    public function isCpanel(): bool
    {
        return $this->cpanel;
    }

    /**
     * What the world looks like right now, without changing any of it.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $envPath = $this->envPath();
        $env = Config::loadEnv($envPath);

        return [
            'cpanel' => $this->cpanel,
            'home' => $this->home,
            'env_path' => $envPath,
            'env_exists' => is_file($envPath),
            'has_master_key' => isset($env['WTP_MASTER_KEY']) && $env['WTP_MASTER_KEY'] !== '',
            'storage_path' => $this->storagePath(),
            'storage_exists' => is_dir($this->storagePath()),
            'pointer_path' => $this->pointerPath(),
            'pointer_needed' => $envPath !== Config::DEFAULT_ENV_PATH,
            'pointer_exists' => is_file($this->pointerPath()),
            'agent_package' => $this->agentPackagePath(),
            'agent_installed' => is_file($this->agentPackagePath()),
            'cron_command' => $this->cronCommand(),
        ];
    }

    /**
     * Create whatever is missing. Safe to call any number of times.
     *
     * @param string $apiBaseUrl written into a *new* environment file only
     * @return array{done:string[],notes:string[],errors:string[]}
     */
    public function run(string $apiBaseUrl = ''): array
    {
        $done = [];
        $notes = [];
        $errors = [];

        $envPath = $this->envPath();
        $storagePath = $this->storagePath();

        $storageExisted = is_dir($storagePath);
        foreach (['', '/agent', '/demos', '/themes', '/core'] as $sub) {
            $dir = $storagePath . $sub;
            if (is_dir($dir)) {
                continue;
            }
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                $errors[] = 'Could not create ' . $dir . ' — the web user cannot write there.';
                return ['done' => $done, 'notes' => $notes, 'errors' => $errors];
            }
        }
        if (!$storageExisted) {
            $done[] = 'Created the package storage at ' . $storagePath;
        }

        // The key. Never replaced, never logged, never returned to the caller.
        $existing = Config::loadEnv($envPath);
        if (isset($existing['WTP_MASTER_KEY']) && $existing['WTP_MASTER_KEY'] !== '') {
            $notes[] = 'An encryption key was already present and was left alone.';
        } else {
            $dir = dirname($envPath);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                $errors[] = 'Could not create ' . $dir;
            } else {
                $written = $this->writeEnvFile($envPath, $storagePath, $apiBaseUrl);
                if ($written === true) {
                    $done[] = 'Generated an encryption key in ' . $envPath;
                    Config::resetEnvCache();
                } else {
                    $errors[] = 'Could not write ' . $envPath . ' — create it by hand, 0600, '
                        . 'with a WTP_MASTER_KEY line (see docs/security.md).';
                }
            }
        }

        // The pointer, so the next request can find that key without an
        // environment variable being set for the web process.
        if ($envPath !== Config::DEFAULT_ENV_PATH) {
            if ($this->writePointer($envPath)) {
                $done[] = 'Recorded the key location in ' . Config::ENV_POINTER_FILE;
            } else {
                $errors[] = 'Could not write ' . $this->pointerPath()
                    . ' — the key will not be found on the next request.';
            }
        }

        // The agent package, if a copy shipped with the module.
        $shipped = $this->addonDir . '/resources/agent.zip';
        $target = $this->agentPackagePath();
        if (is_file($shipped)) {
            if (!is_file($target) || hash_file('sha256', $shipped) !== hash_file('sha256', $target)) {
                if (@copy($shipped, $target)) {
                    @chmod($target, 0640);
                    $done[] = 'Installed the agent package';
                } else {
                    $errors[] = 'Could not copy the agent package to ' . $target;
                }
            }
        } elseif (!is_file($target)) {
            $notes[] = 'The agent package is not at ' . $target
                . ' — upload dist/agent/whmcs-theme-provisioner.zip there.';
        }

        return ['done' => $done, 'notes' => $notes, 'errors' => $errors];
    }

    /**
     * Written with fopen('x') and chmod before the first byte: creating the
     * file and then tightening it leaves a window in which the key is
     * world-readable, and on shared hosting that window has neighbours.
     */
    private function writeEnvFile(string $path, string $storagePath, string $apiBaseUrl): bool
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return false;
        }
        @chmod($path, 0600);

        $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $body = "# WHMCS WordPress Theme Provisioner — secrets and overrides.\n"
            . "#\n"
            . "# Never commit this file, and never move it inside the web root.\n"
            . "# Losing WTP_MASTER_KEY makes every stored agent secret and every\n"
            . "# encrypted job payload unreadable. Back it up wherever you keep\n"
            . "# your other keys.\n\n"
            . 'WTP_MASTER_KEY=' . $key . "\n"
            . 'WTP_STORAGE_PATH=' . $storagePath . "\n"
            . 'WTP_API_BASE_URL=' . $apiBaseUrl . "\n"
            . "WTP_ENFORCE_HTTPS=1\n"
            . "WTP_LOG_LEVEL=info\n";

        $bytes = fwrite($handle, $body);
        fclose($handle);

        return $bytes !== false && $bytes === strlen($body);
    }

    private function writePointer(string $envPath): bool
    {
        $body = "<?php\n"
            . "// Written on activation. Holds the path of the environment file,\n"
            . "// nothing else. Delete it to fall back to the default location.\n"
            . 'return ' . var_export($envPath, true) . ";\n";

        if (@file_put_contents($this->pointerPath(), $body) === false) {
            return false;
        }
        @chmod($this->pointerPath(), 0644);
        return true;
    }

    /** Can we create a file at this path, without creating anything yet? */
    private function isWritableTarget(string $path): bool
    {
        $dir = dirname($path);
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        $parent = dirname($dir);
        return is_dir($parent) && is_writable($parent);
    }
}
