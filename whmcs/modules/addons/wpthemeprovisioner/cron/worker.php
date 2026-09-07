#!/usr/bin/env php
<?php
/**
 * WHMCS WordPress Theme Provisioner — queue worker
 *
 * Install as a system cron entry, running as the WHMCS web user:
 *
 *   * * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/wpthemeprovisioner/cron/worker.php >/dev/null 2>&1
 *
 * Options:
 *   --once            run a single tick and exit (the default)
 *   --loop=SECONDS    keep ticking for up to SECONDS (useful for debugging)
 *   --limit=N         process at most N jobs per tick
 *   --job=UUID        advance one specific job and exit
 *   --verbose         print what happens
 *   --selftest        check configuration and exit non-zero on a problem
 *   --migrate         create or update this module's tables and exit
 *
 * The worker is safe to run concurrently: every mutation is guarded by row
 * locks and unique constraints, and every step is idempotent. A slow tick will
 * simply overlap with the next one rather than corrupting state.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This worker may only be run from the command line.\n");
}

$whmcsRoot = null;
$candidate = __DIR__;
for ($depth = 0; $depth < 7; $depth++) {
    $candidate = dirname($candidate);
    if (is_file($candidate . '/init.php') && is_dir($candidate . '/modules/addons')) {
        $whmcsRoot = $candidate;
        break;
    }
}
if ($whmcsRoot === null) {
    fwrite(STDERR, "Cannot locate the WHMCS installation from " . __DIR__ . "\n");
    exit(2);
}

require_once $whmcsRoot . '/init.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$options = wtp_worker_options($argv);
$verbose = !empty($options['verbose']);

$reporter = static function (string $line) use ($verbose): void {
    if ($verbose) {
        fwrite(STDOUT, '[' . gmdate('H:i:s') . '] ' . $line . "\n");
    }
};

try {
    $container = \Wtp\Addon\Container::boot();
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot start the provisioning worker: " . $e->getMessage() . "\n");
    exit(3);
}

if (!empty($options['selftest'])) {
    exit(wtp_worker_selftest($container));
}

if (!empty($options['migrate'])) {
    exit(wtp_worker_migrate($container));
}

// A single-instance guard: overlapping ticks are safe, but there is no point
// running twenty of them if a previous one is wedged on a slow panel.
$lockFile = sys_get_temp_dir() . '/wtp-worker-' . substr(sha1($whmcsRoot), 0, 12) . '.lock';
$lock = fopen($lockFile, 'c');
if (is_resource($lock) && !flock($lock, LOCK_EX | LOCK_NB)) {
    $reporter('another worker holds the lock; exiting');
    exit(0);
}

$worker = $container->worker($reporter);

try {
    if (!empty($options['job'])) {
        $job = $container->jobs()->findByUuid((string) $options['job']);
        if ($job === null) {
            fwrite(STDERR, "No such job: " . $options['job'] . "\n");
            exit(4);
        }
        $worker->advance($job);
        $reporter('advanced job ' . $options['job']);
        exit(0);
    }

    $deadline = isset($options['loop']) ? time() + (int) $options['loop'] : null;
    $limit = isset($options['limit']) ? (int) $options['limit'] : null;

    do {
        $summary = $worker->tick($limit);
        $reporter(sprintf(
            'tick: %d job(s), %d step(s), %d failure(s), %d recovered, %d row(s) purged',
            $summary['jobs'],
            $summary['steps'],
            $summary['failed'],
            $summary['recovered'],
            $summary['purged']
        ));

        if ($deadline === null) {
            break;
        }
        if (time() >= $deadline) {
            break;
        }
        sleep(5);
    } while (true);
} catch (Throwable $e) {
    fwrite(STDERR, 'Worker failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

exit(0);

/**
 * @param string[] $argv
 * @return array<string,mixed>
 */
function wtp_worker_options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--') !== 0) {
            continue;
        }
        $arg = substr($arg, 2);
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $options[$arg] = true;
            continue;
        }
        $options[substr($arg, 0, $eq)] = substr($arg, $eq + 1);
    }
    return $options;
}

/**
 * Create or update this module's own tables.
 *
 * Activating the addon does exactly this, so the two are interchangeable and
 * both are idempotent — migrations are recorded and never re-applied. Having
 * it on the command line lets an unattended install prepare the schema before
 * anyone opens the admin area.
 */
function wtp_worker_migrate(\Wtp\Addon\Container $container): int
{
    $installer = new \Wtp\Addon\Support\Installer(
        $container->db(),
        $container->clock(),
        WTP_ADDON_DIR . '/migrations'
    );

    $result = $installer->migrate();
    $installer->seedDefaults();

    foreach ($result['errors'] as $error) {
        fwrite(STDERR, '  migration failed: ' . $error . "\n");
    }
    if ($result['errors'] !== []) {
        return 1;
    }

    fwrite(STDOUT, sprintf(
        "%d migration(s) applied, %d already present.\n",
        count($result['applied']),
        count($result['skipped'])
    ));

    if (!$installer->isFullyInstalled()) {
        fwrite(STDERR, "Some tables are still missing after migrating.\n");
        return 1;
    }

    return 0;
}

/** Verify the pieces an operator most often gets wrong. */
function wtp_worker_selftest(\Wtp\Addon\Container $container): int
{
    $failures = 0;
    $check = static function (string $label, callable $probe) use (&$failures): void {
        try {
            $detail = $probe();
            fwrite(STDOUT, sprintf("  %-28s OK    %s\n", $label, $detail));
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDOUT, sprintf("  %-28s FAIL  %s\n", $label, $e->getMessage()));
        }
    };

    fwrite(STDOUT, "WHMCS WordPress Theme Provisioner — self test\n\n");

    $check('database tables', static function () use ($container): string {
        foreach (['mod_wtp_themes', 'mod_wtp_demos', 'mod_wtp_jobs', 'mod_wtp_job_steps'] as $table) {
            if (!$container->db()->tableExists($table)) {
                throw new RuntimeException('missing table ' . $table);
            }
        }
        return 'all present';
    });

    $check('master key', static function () use ($container): string {
        $container->secretBox()->decrypt($container->secretBox()->encrypt('probe', 'selftest'), 'selftest');
        return 'present and usable';
    });

    $check('package storage', static function () use ($container): string {
        $storage = $container->storage();
        if (!is_readable($storage->root())) {
            throw new RuntimeException($storage->root() . ' is not readable');
        }
        return $storage->root();
    });

    $check('agent package', static function () use ($container): string {
        $path = $container->config()->getString('agent_package', 'agent/whmcs-theme-provisioner.zip');
        if (!$container->storage()->exists($path)) {
            throw new RuntimeException('missing ' . $path . ' — run bin/build.php and deploy dist/');
        }
        return $path . ' (' . $container->storage()->size($path) . ' bytes)';
    });

    $check('installer template', static function () use ($container): string {
        $path = $container->bootstrapTemplatePath();
        if (!is_file($path)) {
            throw new RuntimeException('missing ' . $path);
        }
        return basename($path);
    });

    $check('api base url', static function () use ($container): string {
        return $container->apiBaseUrl();
    });

    $check('pending jobs', static function () use ($container): string {
        return count($container->jobs()->due(5)) . ' due now';
    });

    fwrite(STDOUT, "\n" . ($failures === 0 ? "All checks passed.\n" : $failures . " check(s) failed.\n"));
    return $failures === 0 ? 0 : 1;
}
