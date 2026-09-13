#!/usr/bin/env php
<?php
/**
 * Assemble the deployable artefacts.
 *
 * Everything shared between the WHMCS side and the WordPress agent has exactly
 * one source of truth in `shared/`. This script distributes it into both
 * packages so signing, safe extraction, redaction and the job state machine can
 * never drift apart between the two sides of the protocol.
 *
 *   dist/whmcs/                       rsync this over the WHMCS installation
 *   dist/agent/whmcs-theme-provisioner.zip   upload to package storage
 *   dist/checksums.txt
 *
 * Usage: php bin/build.php [--version=1.0.0] [--clean]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

$root = dirname(__DIR__);
$dist = $root . '/dist';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') !== 0) {
        continue;
    }
    $pair = explode('=', substr($arg, 2), 2);
    $options[$pair[0]] = $pair[1] ?? true;
}

$version = (string) ($options['version'] ?? detect_version($root));

echo "Building WHMCS WordPress Theme Provisioner {$version}\n";
echo str_repeat('─', 60) . "\n";

if (!empty($options['clean']) && is_dir($dist)) {
    rrmdir($dist);
    echo "  cleaned dist/\n";
}

// ---------------------------------------------------------------------------
// 1. Distribute the shared library into both packages
// ---------------------------------------------------------------------------

$sharedTargets = [
    $root . '/whmcs/modules/addons/wpthemeprovisioner/lib/shared',
    $root . '/wordpress/mu-plugins/whmcs-theme-provisioner/shared',
];

foreach ($sharedTargets as $target) {
    ensure_dir($target);
    copy_tree($root . '/shared/Wtp', $target . '/Wtp');
    copy_file($root . '/shared/autoload.php', $target . '/autoload.php');
    echo "  shared -> " . relative($root, $target) . "\n";
}

// ---------------------------------------------------------------------------
// 2. Copy the generated resources into the addon
// ---------------------------------------------------------------------------

$addon = $root . '/whmcs/modules/addons/wpthemeprovisioner';

ensure_dir($addon . '/resources');
copy_file($root . '/bootstrap/wtp-bootstrap.php', $addon . '/resources/wtp-bootstrap.php');
echo "  installer template -> resources/wtp-bootstrap.php\n";

ensure_dir($addon . '/migrations');
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $migration) {
    copy_file($migration, $addon . '/migrations/' . basename($migration));
}
echo "  migrations -> " . count(glob($addon . '/migrations/*.sql') ?: []) . " file(s)\n";

// ---------------------------------------------------------------------------
// 3. Stage the WHMCS tree
// ---------------------------------------------------------------------------

$whmcsDist = $dist . '/whmcs';
if (is_dir($whmcsDist)) {
    rrmdir($whmcsDist);
}
copy_tree($root . '/whmcs', $whmcsDist);
echo "  staged dist/whmcs (" . count_files($whmcsDist) . " files)\n";

// ---------------------------------------------------------------------------
// 4. Build the agent package
// ---------------------------------------------------------------------------

$agentDist = $dist . '/agent';
ensure_dir($agentDist);

$zipPath = $agentDist . '/whmcs-theme-provisioner.zip';
$agentSource = $root . '/wordpress/mu-plugins/whmcs-theme-provisioner';

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The zip extension is required to build the agent package.\n");
    exit(1);
}

@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create {$zipPath}\n");
    exit(1);
}

$added = 0;
foreach (walk($agentSource) as $file) {
    $relative = 'whmcs-theme-provisioner/' . ltrim(substr($file, strlen($agentSource)), '/');
    // The dropper extracts this straight into wp-content/mu-plugins, so the
    // archive root must be the plugin directory itself.
    $zip->addFile($file, $relative);
    $added++;
}
$zip->close();

$agentSha = hash_file('sha256', $zipPath);
echo "  agent package: {$added} files, " . human_bytes((int) filesize($zipPath)) . "\n";
echo "  sha256: {$agentSha}\n";

// A copy travels inside the module, so activating the addon can install it into
// package storage. Without it, someone with only File Manager has to find the
// zip and place it by hand — and discovers they did not only when the first
// order stops halfway. Staged into dist only: it is a build artifact and has no
// business in the repository.
$shippedAgent = $whmcsDist . '/modules/addons/wpthemeprovisioner/resources/agent.zip';
ensure_dir(dirname($shippedAgent));
copy_file($zipPath, $shippedAgent);
echo "  agent package -> modules/addons/wpthemeprovisioner/resources/agent.zip\n";

// ---------------------------------------------------------------------------
// 5. Checksums and deployment notes
// ---------------------------------------------------------------------------

$checksums = [
    'built_at' => gmdate('c'),
    'version' => $version,
    'agent_package' => [
        'file' => 'agent/whmcs-theme-provisioner.zip',
        'sha256' => $agentSha,
        'bytes' => (int) filesize($zipPath),
    ],
    'installer_template' => [
        'file' => 'whmcs/modules/addons/wpthemeprovisioner/resources/wtp-bootstrap.php',
        'sha256' => hash_file('sha256', $addon . '/resources/wtp-bootstrap.php'),
    ],
];

file_put_contents(
    $dist . '/checksums.json',
    json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

echo str_repeat('─', 60) . "\n";
echo "Done.\n\n";
echo "Next steps:\n";
echo "  1. rsync -a --delete dist/whmcs/ user@server:/path/to/whmcs/\n";
echo "  2. activate the addon in WHMCS — that creates the tables, the storage,\n";
echo "     the encryption key, and installs the agent package\n";
echo "  3. add the worker cron entry shown on the addon's Settings tab\n";
echo "  4. php modules/addons/wpthemeprovisioner/cron/worker.php --selftest\n";

exit(0);

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

function detect_version(string $root): string
{
    $bootstrap = @file_get_contents($root . '/whmcs/modules/addons/wpthemeprovisioner/bootstrap.php');
    if (is_string($bootstrap) && preg_match("/WTP_VERSION',\s*'([^']+)'/", $bootstrap, $m)) {
        return $m[1];
    }
    return '0.0.0';
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create {$dir}\n");
        exit(1);
    }
}

function copy_file(string $from, string $to): void
{
    ensure_dir(dirname($to));
    if (!copy($from, $to)) {
        fwrite(STDERR, "Cannot copy {$from}\n");
        exit(1);
    }
}

function copy_tree(string $from, string $to): void
{
    ensure_dir($to);
    foreach (walk($from) as $file) {
        $relative = ltrim(substr($file, strlen($from)), '/');
        copy_file($file, $to . '/' . $relative);
    }
}

/** @return string[] every file under $dir, excluding VCS and generated noise */
function walk(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $skipDirs = ['.git', 'node_modules', '.idea', '.vscode'];
    $skipFiles = ['.DS_Store', 'Thumbs.db'];

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static function ($current) use ($skipDirs): bool {
                return !($current->isDir() && in_array($current->getFilename(), $skipDirs, true));
            }
        )
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || in_array($file->getFilename(), $skipFiles, true)) {
            continue;
        }
        $files[] = $file->getPathname();
    }
    sort($files);
    return $files;
}

function count_files(string $dir): int
{
    return count(walk($dir));
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        @unlink($dir);
        return;
    }
    foreach ((array) scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) && !is_link($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function human_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB'];
    $index = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $index < 2) {
        $value /= 1024;
        $index++;
    }
    return round($value, 1) . ' ' . $units[$index];
}

function relative(string $root, string $path): string
{
    return ltrim(substr($path, strlen($root)), '/');
}
