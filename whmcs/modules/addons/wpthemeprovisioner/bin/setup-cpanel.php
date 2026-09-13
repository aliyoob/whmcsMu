#!/usr/bin/env php
<?php
/**
 * WHMCS WordPress Theme Provisioner — setup from inside a cPanel account.
 *
 * For the case where you have a cPanel account and no root: no WHM, no root
 * SSH, nothing outside your own home directory. Run it from cPanel's Terminal,
 * or over SSH as the account:
 *
 *   php modules/addons/wpthemeprovisioner/bin/setup-cpanel.php
 *   php modules/addons/wpthemeprovisioner/bin/setup-cpanel.php --check
 *
 * It does everything infrastructure/install-module.sh does that does not need
 * root, and prints the two things that do have to be done by hand: the cron
 * entry (cPanel -> Cron Jobs) and activating the addon in WHMCS.
 *
 * Options:
 *   --check         report and change nothing
 *   --url=URL       the URL WHMCS is served on, if it cannot be read from the
 *                   database (e.g. before WHMCS's System URL has been set)
 *   --home=PATH     override the home directory
 *
 * Nothing here needs the module to be activated first, so it is safe to run
 * immediately after uploading the files.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

// ---------------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------------

$tty = function_exists('posix_isatty') && @posix_isatty(STDOUT);
$colour = static function (string $code, string $text) use ($tty): string {
    return $tty ? "\033[{$code}m{$text}\033[0m" : $text;
};

$problems = 0;
$step = static function (string $text) use ($colour): void {
    echo "\n" . $colour('1', '▸ ' . $text) . "\n";
};
$note = static function (string $text): void {
    echo '  ' . $text . "\n";
};
$ok = static function (string $text) use ($colour): void {
    echo '  ' . $colour('32', '✓') . ' ' . $text . "\n";
};
$warn = static function (string $text) use ($colour, &$problems): void {
    $problems++;
    echo '  ' . $colour('33', '!') . ' ' . $text . "\n";
};
$fail = static function (string $text) use ($colour): void {
    echo '  ' . $colour('31', '✗') . ' ' . $text . "\n";
    exit(1);
};

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------

$checkOnly = false;
$urlOverride = '';
$homeOverride = '';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--check') {
        $checkOnly = true;
    } elseif (strpos($arg, '--url=') === 0) {
        $urlOverride = rtrim(substr($arg, 6), '/');
    } elseif (strpos($arg, '--home=') === 0) {
        $homeOverride = rtrim(substr($arg, 7), '/');
    } elseif ($arg === '--help' || $arg === '-h') {
        $doc = (string) file_get_contents(__FILE__);
        if (preg_match('#/\*\*(.*?)\*/#s', $doc, $m)) {
            echo preg_replace('/^\s*\*ial? ?/m', '', preg_replace('/^\s*\* ?/m', '', trim($m[1]))) . "\n";
        }
        exit(0);
    } else {
        echo "Unknown option: {$arg}\n";
        exit(2);
    }
}

echo "\n  WordPress Theme Provisioner — cPanel account setup\n";
if ($checkOnly) {
    echo "  (check only — nothing will be changed)\n";
}

// ---------------------------------------------------------------------------
// 1. Where are we?
// ---------------------------------------------------------------------------

$step('Locating WHMCS');

$addonDir = dirname(__DIR__);
$whmcsRoot = null;
$candidate = $addonDir;
for ($depth = 0; $depth < 7; $depth++) {
    $candidate = dirname($candidate);
    if (is_file($candidate . '/init.php') && is_dir($candidate . '/modules/addons')) {
        $whmcsRoot = $candidate;
        break;
    }
}
if ($whmcsRoot === null) {
    $fail('Cannot find WHMCS above ' . $addonDir . '. Upload the module into '
        . 'modules/addons/ inside your WHMCS directory and run this again.');
}
$ok('WHMCS at ' . $whmcsRoot);

// Running this as root would create root-owned files in a user's home — which
// then fail to open from the account's own PHP, in a way that looks like a
// permissions bug weeks later. The root path is install-module.sh.
$uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
if ($uid === 0) {
    $fail('This script is for an unprivileged cPanel account. As root, use '
        . 'infrastructure/install-module.sh instead — it does more.');
}

$owner = @fileowner($whmcsRoot . '/init.php');
if ($uid >= 0 && $owner !== false && $owner !== $uid) {
    $warn('WHMCS belongs to uid ' . $owner . ' but you are uid ' . $uid . '.');
    $warn('Run this as the account that owns WHMCS, or the files it writes');
    $warn('will not be readable by the web requests that need them.');
}

$home = $homeOverride;
if ($home === '') {
    $home = (string) getenv('HOME');
}
if ($home === '' && function_exists('posix_getpwuid') && $uid >= 0) {
    $pw = @posix_getpwuid($uid);
    $home = is_array($pw) && isset($pw['dir']) ? (string) $pw['dir'] : '';
}
if ($home === '' || !is_dir($home)) {
    $fail('Cannot determine your home directory. Pass --home=/home/youraccount');
}
$ok('Home directory ' . $home);

$user = (string) getenv('USER');
if ($user === '' && function_exists('posix_getpwuid') && $uid >= 0) {
    $pw = @posix_getpwuid($uid);
    $user = is_array($pw) && isset($pw['name']) ? (string) $pw['name'] : '';
}

// ---------------------------------------------------------------------------
// 2. PHP
// ---------------------------------------------------------------------------

$step('Checking PHP');
$note('CLI: PHP ' . PHP_VERSION . ' (' . (PHP_BINARY ?: 'php') . ')');

$missing = [];
foreach (['curl', 'zip', 'openssl', 'json', 'mbstring', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
if ($missing !== []) {
    $fail('Missing PHP extensions: ' . implode(', ', $missing) . '. '
        . 'Enable them in cPanel -> Select PHP Version, then run this again.');
}
$ok('curl, zip, openssl, json, mbstring, pdo_mysql all present');

if (!extension_loaded('ionCube Loader') && !extension_loaded('ioncube_loader')) {
    $warn('ionCube Loader is not loaded in the CLI. WHMCS needs it, so the '
        . 'worker cron will not be able to start. cPanel -> Select PHP Version.');
} else {
    $ok('ionCube Loader present');
}

// The cron entry must use this same binary: cPanel's default `php` on PATH is
// often a different version from the one the account's vhost runs.
$phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';

// ---------------------------------------------------------------------------
// 3. WHMCS database and URL
// ---------------------------------------------------------------------------

$step('Reading the WHMCS configuration');

$configFile = $whmcsRoot . '/configuration.php';
if (!is_file($configFile) || filesize($configFile) === 0) {
    $fail($configFile . ' is missing or empty — finish installing WHMCS first.');
}

// Parsed rather than included: including it would execute WHMCS's file in this
// process for the sake of four strings.
$configSource = (string) file_get_contents($configFile);
$conf = [];
foreach (['db_host', 'db_username', 'db_password', 'db_name', 'db_port'] as $name) {
    if (preg_match('/\$' . $name . '\s*=\s*([\'"])(.*?)\1\s*;/s', $configSource, $m)) {
        $conf[$name] = $m[2];
    }
}
if (!isset($conf['db_name'])) {
    $fail('Could not read the database name out of configuration.php.');
}
$ok('Database ' . $conf['db_name'] . ' on ' . ($conf['db_host'] ?: 'localhost'));

$systemUrl = $urlOverride;
try {
    $dsn = 'mysql:host=' . ($conf['db_host'] ?: 'localhost')
        . (isset($conf['db_port']) && $conf['db_port'] !== '' ? ';port=' . $conf['db_port'] : '')
        . ';dbname=' . $conf['db_name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $conf['db_username'] ?? '', $conf['db_password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $ok('Connected to the database');

    if ($systemUrl === '') {
        $row = $pdo->query("SELECT value FROM tblconfiguration WHERE setting = 'SystemURL'")->fetch(PDO::FETCH_ASSOC);
        $systemUrl = is_array($row) ? rtrim((string) $row['value'], '/') : '';
    }

    $tables = $pdo->query("SHOW TABLES LIKE 'mod\\_wtp\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    $note('mod_wtp_* tables: ' . count($tables) . ' of 14');
} catch (Throwable $e) {
    $warn('Could not query the database: ' . $e->getMessage());
}

if ($systemUrl === '') {
    $warn('WHMCS has no System URL set and you did not pass --url. The agent '
        . 'API base URL will be left empty; set it before taking orders.');
} elseif (strpos($systemUrl, 'https://') !== 0) {
    $warn('The System URL is ' . $systemUrl . ' — plain HTTP. The agent API '
        . 'refuses to run without TLS unless WTP_ENFORCE_HTTPS=0, which you '
        . 'should not do in production.');
} else {
    $ok('System URL ' . $systemUrl);
}

// ---------------------------------------------------------------------------
// 4. Storage and the encryption key
// ---------------------------------------------------------------------------

$step('Package storage and encryption key');

$base = $home . '/.whmcs-wtp';
$storagePath = $base . '/packages';
$envPath = $base . '/wtp.env';

$note('Storage     ' . $storagePath);
$note('Key         ' . $envPath);
$note('Both live inside your home directory, so they count against your disk');
$note('quota and are included in your cPanel backups.');

if ($checkOnly) {
    is_dir($storagePath) ? $ok('storage exists') : $warn('storage does not exist yet');
    is_file($envPath) ? $ok('key file exists') : $warn('key file does not exist yet');
} else {
    foreach (['', '/agent', '/demos', '/themes', '/core'] as $sub) {
        $dir = $storagePath . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            $fail('Could not create ' . $dir);
        }
    }
    @chmod($base, 0700);
    $ok('Storage ready');

    $existing = is_file($envPath) ? (string) file_get_contents($envPath) : '';
    if (strpos($existing, 'WTP_MASTER_KEY=') !== false) {
        $ok('Encryption key already present — keeping it');
        $note('Replacing it would make every stored credential undecryptable.');
    } else {
        $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $env = "# WHMCS WordPress Theme Provisioner — secrets and overrides.\n"
            . "#\n"
            . "# Never commit this file, and never move it inside the web root.\n"
            . "# Losing WTP_MASTER_KEY makes every stored agent secret and every\n"
            . "# encrypted job payload unreadable. Back it up wherever you keep\n"
            . "# your other keys.\n\n"
            . 'WTP_MASTER_KEY=' . $key . "\n"
            . 'WTP_STORAGE_PATH=' . $storagePath . "\n"
            . 'WTP_API_BASE_URL=' . $systemUrl . "\n"
            . "WTP_ENFORCE_HTTPS=1\n"
            . "WTP_LOG_LEVEL=info\n";

        // Written 0600 from the start rather than created and then tightened:
        // between the two there is a window where it is world-readable.
        $handle = @fopen($envPath, 'x');
        if ($handle === false) {
            $fail('Could not create ' . $envPath);
        }
        @chmod($envPath, 0600);
        fwrite($handle, $env);
        fclose($handle);
        $ok('Generated a new encryption key in ' . $envPath);
    }
}

// ---------------------------------------------------------------------------
// 5. Tell the module where the key is
// ---------------------------------------------------------------------------

$step('Pointing the module at the key');

$note('Without root we cannot set an environment variable for the web process,');
$note('and cPanel regenerates its php-fpm pools anyway — a line added there');
$note('would disappear at the next rebuild. So the path goes in a file.');

$pointer = $addonDir . '/env-path.php';
if ($checkOnly) {
    if (is_file($pointer)) {
        $value = @include $pointer;
        $ok('env-path.php -> ' . (is_string($value) ? $value : 'unreadable'));
    } else {
        $warn('env-path.php does not exist yet');
    }
} else {
    $body = "<?php\n"
        . "// Written by setup-cpanel.php. Holds the path of the environment\n"
        . "// file, nothing else. Delete it to fall back to the default location.\n"
        . 'return ' . var_export($envPath, true) . ";\n";
    if (@file_put_contents($pointer, $body) === false) {
        $fail('Could not write ' . $pointer);
    }
    @chmod($pointer, 0644);
    $ok('env-path.php points at ' . $envPath);

    $resolved = @include $pointer;
    if ($resolved !== $envPath || !is_readable($envPath)) {
        $warn('The pointer file did not resolve back to a readable key file.');
    } else {
        $ok('Verified: the key is readable through the pointer');
    }
}

// ---------------------------------------------------------------------------
// 5b. The agent package
//
// Every provisioned site downloads this zip. Without it the chain gets as far
// as creating the cPanel account and then stops, which is the most annoying
// possible place to discover a missing file.
// ---------------------------------------------------------------------------

$step('Agent package');

$agentZip = $storagePath . '/agent/whmcs-theme-provisioner.zip';
if (is_file($agentZip)) {
    $ok('agent package present (' . number_format((int) filesize($agentZip)) . ' bytes)');
} else {
    $warn('The agent package is not at ' . $agentZip);
    $warn('Build it on your own machine with `php bin/build.php`, then upload');
    $warn('dist/agent/whmcs-theme-provisioner.zip to that exact path — cPanel');
    $warn('File Manager can do it. Nothing will provision until it is there.');
}

// ---------------------------------------------------------------------------
// 6. The API route
// ---------------------------------------------------------------------------

$step('The /provisioning/v1 route');

$htaccess = $whmcsRoot . '/provisioning/v1/.htaccess';
if (!is_file($whmcsRoot . '/provisioning/v1/index.php')) {
    $warn('provisioning/v1/index.php is not in ' . $whmcsRoot . '.');
    $warn('The whole dist/whmcs tree has to be uploaded, not just modules/.');
} elseif (!is_file($htaccess)) {
    $warn('provisioning/v1/.htaccess is missing; the route will not work.');
} else {
    $ok('provisioning/v1 is in place, with its .htaccess');
}

if ($systemUrl !== '' && function_exists('curl_init')) {
    $healthUrl = $systemUrl . '/provisioning/v1/health';
    $ch = curl_init($healthUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOBODY => false,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 || $code === 503) {
        $ok('The endpoint answers: ' . $healthUrl . ' (HTTP ' . $code . ')');
    } else {
        $warn('No answer from ' . $healthUrl . ' (HTTP ' . $code . ').');
        $warn('On cPanel this is nearly always one of:');
        $warn('  - AllowOverride is None for this document root, so .htaccess');
        $warn('    is ignored. Only your host can change that.');
        $warn('  - mod_rewrite is not built (WHM -> EasyApache 4).');
        $warn('  - the domain does not resolve from this server yet.');
        $warn('Provisioning cannot work until a hosting server can reach it.');
    }
}

// ---------------------------------------------------------------------------
// 7. What is left, and cannot be done from here
// ---------------------------------------------------------------------------

$worker = $addonDir . '/cron/worker.php';
$cronLine = $phpBinary . ' -q ' . $worker . ' >/dev/null 2>&1';

$step('Two things left, both in a browser');

echo "\n  " . $colour('1', '1  Add the cron job') . "\n\n";
echo "     cPanel -> Advanced -> Cron Jobs -> Add New Cron Job\n\n";
echo "       Common Settings   Once Per Minute (* * * * *)\n";
echo "       Command           " . $cronLine . "\n\n";
echo "     Use that exact php path. The `php` on your PATH is often a\n";
echo "     different version from the one your site runs.\n";

echo "\n  " . $colour('1', '2  Activate the addon') . "\n\n";
echo "     " . ($systemUrl !== '' ? $systemUrl : 'https://your-whmcs') . "/admin/\n";
echo "     System Settings -> Addon Modules -> WordPress Theme Provisioner\n";
echo "       -> Activate -> tick the admin roles that should see it\n\n";
echo "     That creates the mod_wtp_* tables. There is no supported way to do\n";
echo "     it from outside the WHMCS admin area.\n";

$step('Summary');
$note('Account        ' . ($user !== '' ? $user : 'uid ' . $uid));
$note('WHMCS          ' . $whmcsRoot);
$note('PHP            ' . $phpBinary);
$note('Storage        ' . $storagePath);
$note('Encryption key ' . $envPath);

echo "\n";
if ($checkOnly) {
    echo '  ' . $colour('1', 'Nothing was changed.') . " Run without --check to set it up.\n";
} elseif ($problems === 0) {
    echo '  ' . $colour('32', 'Done — no problems found.') . "\n";
} else {
    echo '  ' . $colour('33', 'Done, with ' . $problems . ' thing(s) to look at above.') . "\n";
}
echo "\n  " . $colour('33', 'Back up ' . $envPath) . "\n";
echo "  Losing that key makes every stored credential unreadable, permanently.\n\n";

// Non-zero when something needs attention, so --check is usable from a script.
exit($problems === 0 ? 0 : 1);
