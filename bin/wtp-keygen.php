#!/usr/bin/env php
<?php
/**
 * Generate the master key that protects agent secrets and job payloads at rest.
 *
 *   php bin/wtp-keygen.php                 print a key
 *   php bin/wtp-keygen.php --write PATH    write it into an environment file
 *
 * The key never goes in the database, in the addon configuration screen, or in
 * this repository. It belongs in a 0640 file outside the web root, owned by
 * root and readable by the web user.
 *
 * Rotating it makes every existing encrypted payload unreadable, which for a
 * job in flight means the provisioning cannot continue. Rotate only when every
 * job has finished, or accept that the ones in flight must be retried.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/shared/autoload.php';

use Wtp\Shared\Crypto\SecretBox;

$target = null;
foreach (array_slice($argv, 1) as $index => $arg) {
    if ($arg === '--write') {
        $target = $argv[$index + 2] ?? null;
    } elseif (strpos($arg, '--write=') === 0) {
        $target = substr($arg, 8);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "usage: php bin/wtp-keygen.php [--write /etc/whmcs-wtp/wtp.env]\n";
        exit(0);
    }
}

$key = SecretBox::generateMasterKey();

// Prove the key round-trips before anyone relies on it.
$box = SecretBox::fromEncodedKey($key);
if ($box->decrypt($box->encrypt('self-test', 'keygen'), 'keygen') !== 'self-test') {
    fwrite(STDERR, "The generated key failed its own round-trip test. Aborting.\n");
    exit(1);
}

if ($target === null) {
    echo "WTP_MASTER_KEY=" . $key . "\n";
    exit(0);
}

if (is_file($target)) {
    $existing = (string) file_get_contents($target);
    if (strpos($existing, 'WTP_MASTER_KEY=') !== false) {
        fwrite(STDERR, "Refusing to overwrite: {$target} already contains a WTP_MASTER_KEY.\n");
        fwrite(STDERR, "Replacing it would make every stored agent secret undecryptable.\n");
        exit(1);
    }
    $contents = rtrim($existing, "\n") . "\nWTP_MASTER_KEY=" . $key . "\n";
} else {
    $directory = dirname($target);
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        fwrite(STDERR, "Cannot create {$directory}\n");
        exit(1);
    }
    $contents = "# WHMCS WordPress Theme Provisioner secrets. Keep out of version control.\n"
        . "WTP_MASTER_KEY=" . $key . "\n";
}

if (@file_put_contents($target, $contents) === false) {
    fwrite(STDERR, "Cannot write {$target}\n");
    exit(1);
}
@chmod($target, 0640);

echo "Wrote a new master key to {$target} (mode 0640).\n";
echo "Back it up alongside your other keys — losing it is unrecoverable.\n";
