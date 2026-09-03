<?php
/**
 * WHMCS WordPress Theme Provisioner — installer dropper
 *
 * A single-purpose, self-deleting file that WHMCS uploads into a freshly
 * created cPanel account. It installs WordPress and the provisioning agent,
 * then removes itself and its configuration.
 *
 * Design constraints that shaped this file:
 *
 *  - **One file, no dependencies.** It runs before WordPress exists, as the
 *    hosting account's own user, on whatever PHP that account has (7.2+).
 *  - **One bounded step per request.** WHMCS's worker calls it repeatedly.
 *    Nothing here depends on a single long-lived HTTP request surviving.
 *  - **Idempotent.** Every step checks whether its work is already done, so a
 *    retried call after a timeout is harmless.
 *  - **Short-lived.** It refuses to run after its expiry, after `max_runs`
 *    invocations, or once the install is finished — and then deletes itself.
 *
 * It is authenticated by a run token that only WHMCS knows. That token grants
 * nothing but "advance this installation", which is what we want to happen
 * anyway; the credentials that matter travel to the account over the cPanel
 * API and are never sent through this endpoint.
 *
 * @version 1.0.0
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Hard guards
// ---------------------------------------------------------------------------

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "This installer is driven over HTTP by WHMCS.\n");
    exit(1);
}

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);
@set_time_limit(0);
@ignore_user_abort(true);

define('WTP_BOOTSTRAP_VERSION', '1.0.0');
define('WTP_SELF', __FILE__);
define('WTP_BASENAME', basename(__FILE__, '.php'));
define('WTP_DIR', __DIR__);
define('WTP_CONFIG_FILE', WTP_DIR . '/' . WTP_BASENAME . '.json');
define('WTP_STATE_FILE', WTP_DIR . '/' . WTP_BASENAME . '.state.json');
define('WTP_TMP_DIR', WTP_DIR . '/.' . WTP_BASENAME . '-tmp');

// Extraction limits. A WordPress tarball is ~2 000 files / ~60 MB unpacked;
// a theme or the agent is far smaller. Anything wildly outside that is refused.
define('WTP_MAX_ENTRIES', 12000);
define('WTP_MAX_UNCOMPRESSED', 314572800); // 300 MiB
define('WTP_MAX_ENTRY_BYTES', 67108864);   // 64 MiB
define('WTP_MAX_RATIO', 200);

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

/**
 * WordPress must be loaded at *global* scope: wp-settings.php assigns dozens of
 * globals at file scope, and including it from inside a function silently makes
 * them local, breaking the installer in ways that only show up later. So the
 * dispatcher returns a "finish the install out here" instruction rather than
 * doing it itself.
 */
$wtpDeferredInstall = wtp_main();

if (is_array($wtpDeferredInstall)) {
    $wtpConfig = $wtpDeferredInstall['config'];
    $wtpDocroot = rtrim((string) $wtpConfig['paths']['docroot'], '/');
    $wtpSite = $wtpConfig['site'];

    try {
        if (!defined('WP_INSTALLING')) {
            define('WP_INSTALLING', true);
        }
        $_SERVER['HTTP_HOST'] = (string) parse_url((string) $wtpSite['url'], PHP_URL_HOST);
        $_SERVER['REQUEST_URI'] = '/';

        require_once $wtpDocroot . '/wp-load.php';          // global scope — required
        if (!function_exists('wp_install')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $wtpDetail = wtp_finish_wordpress_install($wtpSite);
    } catch (WtpError $e) {
        wtp_record_step_error('install', $e->code(), $e->getMessage());
    } catch (Throwable $e) {
        wtp_record_step_error('install', 'wp_install_failed', $e->getMessage());
    }

    $wtpState = wtp_load_state();
    $wtpState['completed']['install'] = true;
    $wtpState['detail']['install'] = $wtpDetail;
    unset($wtpState['last_error']);
    wtp_save_state($wtpState);

    wtp_respond(200, ['success' => true, 'step' => 'install', 'done' => true, 'detail' => $wtpDetail]);
}

/**
 * @return array<string,mixed>|null non-null when the caller must finish the
 *         WordPress installation at global scope
 */
function wtp_main(): ?array
{
    $config = wtp_load_config();
    if ($config === null) {
        wtp_respond(410, ['error' => 'gone', 'message' => 'This installer is no longer configured']);
    }

    if (!wtp_authenticate($config)) {
        // Deliberately slow and uninformative.
        usleep(250000);
        wtp_respond(401, ['error' => 'unauthorized']);
    }

    if (time() > (int) $config['expires_at']) {
        wtp_self_destruct();
        wtp_respond(410, ['error' => 'expired', 'message' => 'This installer has expired and removed itself']);
    }

    $state = wtp_load_state();
    $state['runs'] = (int) ($state['runs'] ?? 0) + 1;

    if ($state['runs'] > (int) ($config['max_runs'] ?? 40)) {
        wtp_save_state($state);
        wtp_self_destruct();
        wtp_respond(429, ['error' => 'run_limit', 'message' => 'Installer run limit reached']);
    }
    wtp_save_state($state);

    $action = wtp_param('action', 'status');

    switch ($action) {
        case 'status':
            wtp_respond(200, wtp_status($config, $state));

        case 'step':
            $step = wtp_param('step', '');
            return wtp_run_step($config, $state, $step);

        case 'cleanup':
            wtp_cleanup();
            wtp_self_destruct();
            wtp_respond(200, ['success' => true, 'step' => 'cleanup', 'done' => true]);

        default:
            wtp_respond(400, ['error' => 'unknown_action']);
    }

    return null; // unreachable: every branch above terminates the request
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $state
 * @return array<string,mixed>|null
 */
function wtp_run_step(array $config, array $state, string $step): ?array
{
    $steps = ['download', 'install', 'agent'];
    if (!in_array($step, $steps, true)) {
        wtp_respond(400, ['error' => 'unknown_step', 'message' => 'Unknown step: ' . $step]);
    }

    if (!empty($state['completed'][$step])) {
        wtp_respond(200, [
            'success' => true,
            'step' => $step,
            'done' => true,
            'repeated' => true,
            'detail' => $state['detail'][$step] ?? [],
        ]);
    }

    try {
        switch ($step) {
            case 'download':
                $detail = wtp_step_download($config);
                break;
            case 'install':
                // Prepare the filesystem here, then hand back to global scope
                // so WordPress can be bootstrapped correctly.
                wtp_prepare_wordpress($config);
                return ['config' => $config, 'state' => $state];
            default:
                $detail = wtp_step_agent($config);
                break;
        }
    } catch (WtpError $e) {
        $state['last_error'] = ['step' => $step, 'code' => $e->code(), 'message' => $e->getMessage()];
        wtp_save_state($state);
        wtp_respond(200, [
            'success' => false,
            'step' => $step,
            'done' => false,
            'error_code' => $e->code(),
            'error_message' => $e->getMessage(),
        ]);
    } catch (Throwable $e) {
        $state['last_error'] = ['step' => $step, 'code' => 'bootstrap_error', 'message' => $e->getMessage()];
        wtp_save_state($state);
        wtp_respond(200, [
            'success' => false,
            'step' => $step,
            'done' => false,
            'error_code' => 'bootstrap_error',
            'error_message' => $e->getMessage(),
        ]);
    }

    $state = wtp_load_state();
    $state['completed'][$step] = true;
    $state['detail'][$step] = $detail;
    unset($state['last_error']);
    wtp_save_state($state);

    wtp_respond(200, ['success' => true, 'step' => $step, 'done' => true, 'detail' => $detail]);

    return null; // unreachable
}

// ---------------------------------------------------------------------------
// Steps
// ---------------------------------------------------------------------------

/**
 * Fetch the WordPress core archive and verify it.
 *
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function wtp_step_download(array $config): array
{
    $wp = $config['wp'];
    $target = WTP_TMP_DIR . '/wordpress.zip';
    wtp_mkdir(WTP_TMP_DIR);

    // Mirrored archives carry a sha256 we computed ourselves; a direct
    // wordpress.org download is verified against the .sha1 that project
    // publishes next to it. Either way the bytes are checked before use.
    $sha256 = isset($wp['sha256']) ? strtolower((string) $wp['sha256']) : '';
    $sha1 = isset($wp['sha1']) ? strtolower((string) $wp['sha1']) : '';

    if (is_file($target) && wtp_archive_matches($target, $sha256, $sha1)) {
        return ['cached' => true, 'bytes' => (int) filesize($target)];
    }
    @unlink($target);

    $bytes = wtp_download((string) $wp['url'], $target, WTP_MAX_UNCOMPRESSED);

    if (!wtp_archive_matches($target, $sha256, $sha1)) {
        @unlink($target);
        throw new WtpError('checksum_mismatch', 'WordPress core failed its checksum verification');
    }

    return ['cached' => false, 'bytes' => $bytes, 'sha256' => $sha256, 'sha1' => $sha1];
}

/**
 * True when the file satisfies every checksum we were given. An archive with
 * no checksum at all is refused: unverified code must never be extracted into
 * a customer's account.
 */
function wtp_archive_matches(string $path, string $sha256, string $sha1): bool
{
    if (!is_file($path)) {
        return false;
    }
    if ($sha256 === '' && $sha1 === '') {
        return false;
    }
    if ($sha256 !== '' && !hash_equals($sha256, (string) hash_file('sha256', $path))) {
        return false;
    }
    if ($sha1 !== '' && !hash_equals($sha1, (string) hash_file('sha1', $path))) {
        return false;
    }
    return true;
}

/**
 * Extract WordPress and write wp-config.php. Does NOT bootstrap WordPress —
 * see the note at the top of this file about global scope.
 *
 * @param array<string,mixed> $config
 */
function wtp_prepare_wordpress(array $config): void
{
    $docroot = rtrim((string) $config['paths']['docroot'], '/');
    if (!is_dir($docroot)) {
        throw new WtpError('docroot_missing', 'Document root does not exist: ' . $docroot);
    }

    // Refuse to touch an installation that this installer did not create.
    if (is_file($docroot . '/wp-config.php') && !wtp_config_is_ours($docroot)) {
        throw new WtpError(
            'wordpress_exists',
            'A WordPress installation that this installer did not create is already present'
        );
    }

    if (!is_file($docroot . '/wp-settings.php')) {
        $archive = WTP_TMP_DIR . '/wordpress.zip';
        if (!is_file($archive)) {
            throw new WtpError('archive_missing', 'The WordPress archive is not present; run the download step first');
        }
        // The official archive nests everything under "wordpress/".
        wtp_extract_zip($archive, $docroot, 'wordpress/');
    }

    if (!is_file($docroot . '/wp-config.php')) {
        wtp_write_wp_config($config, $docroot);
    }
}

/**
 * Runs after WordPress has been loaded at global scope.
 *
 * @param array<string,mixed> $site
 * @return array<string,mixed>
 */
function wtp_finish_wordpress_install(array $site): array
{
    $version = isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : '';

    if (is_blog_installed()) {
        wtp_pin_site_urls($site);
        return ['installed' => false, 'wp_version' => $version, 'site_url' => (string) $site['url']];
    }

    // WHMCS sends its own welcome email; WordPress's would confuse the customer.
    add_filter('pre_wp_mail', '__return_false', 999);

    $result = wp_install(
        (string) $site['title'],
        (string) $site['admin_user'],
        (string) $site['admin_email'],
        empty($site['discourage_search_engines']),
        '',
        (string) $site['admin_password'],
        (string) ($site['locale'] ?? '')
    );

    if (!is_array($result) || empty($result['user_id'])) {
        throw new WtpError('wp_install_failed', 'wp_install() did not create an administrator');
    }

    wtp_pin_site_urls($site);

    return ['installed' => true, 'wp_version' => $version, 'site_url' => (string) $site['url']];
}

/** Record a step failure and answer the caller without marking it complete. */
function wtp_record_step_error(string $step, string $code, string $message): void
{
    $state = wtp_load_state();
    $state['last_error'] = ['step' => $step, 'code' => $code, 'message' => $message];
    wtp_save_state($state);

    wtp_respond(200, [
        'success' => false,
        'step' => $step,
        'done' => false,
        'error_code' => $code,
        'error_message' => $message,
    ]);
}

/**
 * Install the provisioning agent as a must-use plugin and hand it its
 * credentials.
 *
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function wtp_step_agent(array $config): array
{
    $docroot = rtrim((string) $config['paths']['docroot'], '/');
    $muDir = $docroot . '/wp-content/mu-plugins';
    wtp_mkdir($muDir);

    $archive = WTP_TMP_DIR . '/agent.zip';
    $expected = strtolower((string) $config['agent']['sha256']);

    if (!is_file($archive) || hash_file('sha256', $archive) !== $expected) {
        @unlink($archive);
        wtp_download((string) $config['agent']['url'], $archive, 16777216);
        if (!hash_equals($expected, (string) hash_file('sha256', $archive))) {
            @unlink($archive);
            throw new WtpError('checksum_mismatch', 'The agent package failed its checksum verification');
        }
    }

    // Replace any previous copy so a retry always lands on a clean agent.
    $agentDir = $muDir . '/whmcs-theme-provisioner';
    if (is_dir($agentDir)) {
        wtp_rrmdir($agentDir);
    }
    wtp_extract_zip($archive, $muDir, '');

    if (!is_file($agentDir . '/whmcs-theme-provisioner.php')) {
        throw new WtpError('agent_malformed', 'The agent package did not contain the expected entry point');
    }

    // wp-content/mu-plugins only auto-loads *.php at its top level, so the
    // package directory needs this two-line loader beside it.
    $loader = "<?php\n"
        . "/**\n"
        . " * Plugin Name: WHMCS Theme Provisioner (loader)\n"
        . " * Description: Loads the WHMCS provisioning agent. Managed automatically; do not edit.\n"
        . " * Version: " . WTP_BOOTSTRAP_VERSION . "\n"
        . " */\n"
        . "\$wtp = __DIR__ . '/whmcs-theme-provisioner/whmcs-theme-provisioner.php';\n"
        . "if (is_readable(\$wtp)) {\n"
        . "    require_once \$wtp;\n"
        . "}\n";
    wtp_put($muDir . '/whmcs-theme-provisioner.php', $loader, 0644);

    // Credentials live outside the plugin directory, in a denied-by-default
    // folder, so replacing the agent never destroys them and a directory
    // listing never exposes them.
    $credentialsDir = $docroot . '/wp-content/uploads/.wtp';
    wtp_mkdir($credentialsDir, 0750);
    wtp_put($credentialsDir . '/.htaccess', "Require all denied\nDeny from all\n", 0644);
    wtp_put($credentialsDir . '/index.php', "<?php\n// Silence is golden.\n", 0644);

    $credentials = "<?php\n"
        . "// Generated by the WHMCS provisioning installer. Do not edit or share.\n"
        . "return " . var_export([
            'version' => 1,
            'job' => (string) $config['job'],
            'api_base' => (string) $config['api_base'],
            'bootstrap_token' => (string) $config['bootstrap_token'],
            'issued_at' => time(),
        ], true) . ";\n";
    wtp_put($credentialsDir . '/credentials.php', $credentials, 0600);

    return [
        'agent_installed' => true,
        'mu_plugin' => 'whmcs-theme-provisioner',
    ];
}

// ---------------------------------------------------------------------------
// WordPress specifics
// ---------------------------------------------------------------------------

/** @param array<string,mixed> $config */
function wtp_write_wp_config(array $config, string $docroot): void
{
    $sample = $docroot . '/wp-config-sample.php';
    if (!is_file($sample)) {
        throw new WtpError('archive_incomplete', 'wp-config-sample.php is missing from the extracted archive');
    }

    $contents = (string) file_get_contents($sample);
    $db = $config['db'];

    $replacements = [
        'database_name_here' => (string) $db['name'],
        'username_here' => (string) $db['user'],
        'password_here' => (string) $db['password'],
        'localhost' => (string) ($db['host'] ?? 'localhost'),
    ];
    foreach ($replacements as $needle => $value) {
        $contents = str_replace("'" . $needle . "'", "'" . addcslashes($value, "'\\") . "'", $contents);
    }

    // Locally generated salts: no outbound call to api.wordpress.org, which
    // may be blocked and would be a needless dependency at install time.
    $keys = [
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
    ];
    foreach ($keys as $key) {
        $salt = wtp_random_salt();
        $contents = preg_replace(
            "/define\(\s*'" . $key . "',\s*'[^']*'\s*\);/",
            "define('" . $key . "', '" . addcslashes($salt, "'\\") . "');",
            $contents,
            1
        ) ?? $contents;
    }

    if (!empty($db['prefix'])) {
        $prefix = preg_replace('/[^a-z0-9_]/i', '', (string) $db['prefix']);
        $contents = preg_replace('/\$table_prefix\s*=\s*\'[^\']*\';/', "\$table_prefix = '" . $prefix . "';", $contents, 1)
            ?? $contents;
    }

    $siteUrl = rtrim((string) $config['site']['url'], '/');
    $hardening = "\n"
        . "/* WHMCS provisioning: pinned URLs and baseline hardening. */\n"
        . "define('WP_HOME', '" . addcslashes($siteUrl, "'\\") . "');\n"
        . "define('WP_SITEURL', '" . addcslashes($siteUrl, "'\\") . "');\n"
        . "define('DISALLOW_FILE_EDIT', true);\n"
        . "define('WP_AUTO_UPDATE_CORE', 'minor');\n"
        . "define('WTP_PROVISIONED', '" . addcslashes((string) $config['job'], "'\\") . "');\n";

    // Insert before the "stop editing" marker so WordPress still bootstraps.
    $marker = "/* That's all, stop editing!";
    $pos = strpos($contents, $marker);
    if ($pos !== false) {
        $contents = substr($contents, 0, $pos) . $hardening . "\n" . substr($contents, $pos);
    } else {
        $contents .= $hardening;
    }

    wtp_put($docroot . '/wp-config.php', $contents, 0640);
}

function wtp_config_is_ours(string $docroot): bool
{
    $contents = @file_get_contents($docroot . '/wp-config.php', false, null, 0, 65536);
    return is_string($contents) && strpos($contents, 'WTP_PROVISIONED') !== false;
}

/** @param array<string,mixed> $site */
function wtp_pin_site_urls(array $site): void
{
    $url = rtrim((string) $site['url'], '/');
    if ($url === '' || !function_exists('update_option')) {
        return;
    }
    update_option('siteurl', $url);
    update_option('home', $url);
    if (!empty($site['tagline'])) {
        update_option('blogdescription', (string) $site['tagline']);
    }
}

// ---------------------------------------------------------------------------
// Safe archive extraction
// ---------------------------------------------------------------------------

/**
 * Extract $archive into $destination, optionally stripping a leading path.
 *
 * Refuses absolute paths, traversal, symlinks, oversized entries and
 * suspicious compression ratios. Everything lands under $destination or
 * nothing does.
 */
function wtp_extract_zip(string $archive, string $destination, string $stripPrefix = ''): void
{
    if (!class_exists('ZipArchive')) {
        throw new WtpError('zip_unavailable', 'The PHP zip extension is not available on this account');
    }

    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CHECKCONS) !== true) {
        throw new WtpError('unsupported_archive', 'The archive could not be opened');
    }

    $realDestination = realpath($destination);
    if ($realDestination === false) {
        $zip->close();
        throw new WtpError('destination_missing', 'Extraction destination does not exist');
    }
    $realDestination = rtrim($realDestination, '/');

    if ($zip->numFiles > WTP_MAX_ENTRIES) {
        $zip->close();
        throw new WtpError('archive_too_large', 'The archive contains too many entries');
    }

    $total = 0;
    $written = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            continue;
        }
        $name = (string) $stat['name'];

        if ($name === '' || strpos($name, "\0") !== false) {
            $zip->close();
            throw new WtpError('zip_slip', 'Malformed entry name in archive');
        }
        if ($name[0] === '/' || $name[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $name)) {
            $zip->close();
            throw new WtpError('zip_slip', 'Absolute path in archive: ' . $name);
        }
        foreach (preg_split('#[\\\\/]+#', $name) ?: [] as $segment) {
            if ($segment === '..') {
                $zip->close();
                throw new WtpError('zip_slip', 'Path traversal in archive: ' . $name);
            }
        }

        $opsys = 0;
        $attr = 0;
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr)
            && $opsys === ZipArchive::OPSYS_UNIX
            && ((($attr >> 16) & 0xF000) === 0xA000)) {
            $zip->close();
            throw new WtpError('zip_slip', 'Symlinks are not allowed in packages');
        }

        $isDir = substr($name, -1) === '/';
        if (!$isDir) {
            $size = (int) $stat['size'];
            $comp = (int) $stat['comp_size'];
            if ($size > WTP_MAX_ENTRY_BYTES) {
                $zip->close();
                throw new WtpError('archive_too_large', 'Entry exceeds the size limit: ' . $name);
            }
            if ($comp > 0 && $size / max(1, $comp) > WTP_MAX_RATIO) {
                $zip->close();
                throw new WtpError('archive_too_large', 'Suspicious compression ratio: ' . $name);
            }
            $total += $size;
            if ($total > WTP_MAX_UNCOMPRESSED) {
                $zip->close();
                throw new WtpError('archive_too_large', 'The archive exceeds the uncompressed size limit');
            }
        }

        $relative = $name;
        if ($stripPrefix !== '') {
            if (strpos($relative, $stripPrefix) !== 0) {
                continue; // outside the tree we want
            }
            $relative = substr($relative, strlen($stripPrefix));
        }
        if ($relative === '' || $relative === '/') {
            continue;
        }

        $target = $realDestination . '/' . ltrim($relative, '/');
        $resolvedParent = wtp_resolve_parent($target);
        if ($resolvedParent === null || strpos($resolvedParent, $realDestination) !== 0) {
            $zip->close();
            throw new WtpError('zip_slip', 'Entry resolves outside the destination: ' . $name);
        }

        if ($isDir) {
            wtp_mkdir($target);
            continue;
        }

        wtp_mkdir(dirname($target));

        $in = $zip->getStream($name);
        if (!is_resource($in)) {
            $zip->close();
            throw new WtpError('unsupported_archive', 'Cannot read entry: ' . $name);
        }
        $out = @fopen($target, 'wb');
        if (!is_resource($out)) {
            fclose($in);
            $zip->close();
            throw new WtpError('write_failed', 'Cannot write: ' . $relative);
        }
        while (!feof($in)) {
            $chunk = fread($in, 262144);
            if ($chunk === false) {
                break;
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
        @chmod($target, 0644);
        $written++;
    }

    $zip->close();

    if ($written === 0) {
        throw new WtpError('unsupported_archive', 'The archive produced no files');
    }
}

/** Resolve the deepest existing ancestor of $path. */
function wtp_resolve_parent(string $path): ?string
{
    $candidate = dirname($path);
    $guard = 0;
    while ($candidate !== '' && $candidate !== '/' && !file_exists($candidate) && $guard++ < 64) {
        $candidate = dirname($candidate);
    }
    $real = realpath($candidate);
    return $real === false ? null : rtrim($real, '/');
}

// ---------------------------------------------------------------------------
// HTTP download
// ---------------------------------------------------------------------------

function wtp_download(string $url, string $target, int $maxBytes): int
{
    if (stripos($url, 'https://') !== 0) {
        throw new WtpError('insecure_url', 'Downloads must use https');
    }

    $handle = @fopen($target, 'wb');
    if (!is_resource($handle)) {
        throw new WtpError('write_failed', 'Cannot open the download target for writing');
    }

    $received = 0;
    $tooLarge = false;

    if (function_exists('curl_init')) {
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
            CURLOPT_TIMEOUT => 600,
            CURLOPT_USERAGENT => 'WTP-Bootstrap/' . WTP_BOOTSTRAP_VERSION,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($handle, &$received, &$tooLarge, $maxBytes): int {
                $received += strlen($chunk);
                if ($received > $maxBytes) {
                    $tooLarge = true;
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

        if ($tooLarge) {
            @unlink($target);
            throw new WtpError('download_too_large', 'The download exceeded its size limit');
        }
        if ($errno !== 0) {
            @unlink($target);
            throw new WtpError('network_error', 'Download failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            @unlink($target);
            throw new WtpError('http_' . $status, 'Download failed with HTTP ' . $status);
        }
        return $received;
    }

    // Fallback for accounts without curl.
    $stream = @fopen($url, 'rb', false, stream_context_create([
        'http' => ['timeout' => 600, 'follow_location' => 1, 'max_redirects' => 3],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]));
    if (!is_resource($stream)) {
        fclose($handle);
        @unlink($target);
        throw new WtpError('network_error', 'Cannot open the download stream');
    }
    while (!feof($stream)) {
        $chunk = fread($stream, 262144);
        if ($chunk === false) {
            break;
        }
        $received += strlen($chunk);
        if ($received > $maxBytes) {
            fclose($stream);
            fclose($handle);
            @unlink($target);
            throw new WtpError('download_too_large', 'The download exceeded its size limit');
        }
        fwrite($handle, $chunk);
    }
    fclose($stream);
    fclose($handle);

    return $received;
}

// ---------------------------------------------------------------------------
// Config, state, auth, output
// ---------------------------------------------------------------------------

/** @return array<string,mixed>|null */
function wtp_load_config(): ?array
{
    if (!is_file(WTP_CONFIG_FILE)) {
        return null;
    }
    $raw = @file_get_contents(WTP_CONFIG_FILE);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    foreach (['job', 'run_token', 'expires_at', 'api_base', 'bootstrap_token', 'wp', 'agent', 'db', 'site', 'paths'] as $key) {
        if (!isset($data[$key])) {
            return null;
        }
    }
    return $data;
}

/** @return array<string,mixed> */
function wtp_load_state(): array
{
    if (!is_file(WTP_STATE_FILE)) {
        return ['runs' => 0, 'completed' => [], 'detail' => []];
    }
    $data = json_decode((string) @file_get_contents(WTP_STATE_FILE), true);
    if (!is_array($data)) {
        return ['runs' => 0, 'completed' => [], 'detail' => []];
    }
    $data['completed'] = isset($data['completed']) && is_array($data['completed']) ? $data['completed'] : [];
    $data['detail'] = isset($data['detail']) && is_array($data['detail']) ? $data['detail'] : [];
    return $data;
}

/** @param array<string,mixed> $state */
function wtp_save_state(array $state): void
{
    wtp_put(WTP_STATE_FILE, (string) json_encode($state, JSON_UNESCAPED_SLASHES), 0600);
}

/** @param array<string,mixed> $config */
function wtp_authenticate(array $config): bool
{
    $provided = wtp_param('token', '');
    if ($provided === '' || strlen($provided) > 256) {
        return false;
    }
    return hash_equals((string) $config['run_token'], $provided);
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function wtp_status(array $config, array $state): array
{
    $docroot = rtrim((string) $config['paths']['docroot'], '/');
    return [
        'success' => true,
        'version' => WTP_BOOTSTRAP_VERSION,
        'job' => (string) $config['job'],
        'runs' => (int) $state['runs'],
        'completed' => array_keys(array_filter($state['completed'])),
        'last_error' => $state['last_error'] ?? null,
        'observed' => [
            'wp_core' => is_file($docroot . '/wp-settings.php'),
            'wp_config' => is_file($docroot . '/wp-config.php'),
            'agent' => is_file($docroot . '/wp-content/mu-plugins/whmcs-theme-provisioner.php'),
            'php' => PHP_VERSION,
            'zip' => class_exists('ZipArchive'),
            'curl' => function_exists('curl_init'),
        ],
    ];
}

function wtp_param(string $name, string $default = ''): string
{
    if (isset($_POST[$name]) && is_scalar($_POST[$name])) {
        return (string) $_POST[$name];
    }
    if (isset($_GET[$name]) && is_scalar($_GET[$name])) {
        return (string) $_GET[$name];
    }
    return $default;
}

/** @param array<string,mixed> $payload */
function wtp_respond(int $status, array $payload): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------------
// Filesystem helpers and teardown
// ---------------------------------------------------------------------------

function wtp_mkdir(string $dir, int $mode = 0755): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
        throw new WtpError('write_failed', 'Cannot create directory: ' . $dir);
    }
}

function wtp_put(string $path, string $contents, int $mode = 0644): void
{
    if (@file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new WtpError('write_failed', 'Cannot write: ' . basename($path));
    }
    @chmod($path, $mode);
}

function wtp_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        @unlink($dir);
        return;
    }
    $items = @scandir($dir);
    foreach (is_array($items) ? $items : [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_link($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            wtp_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function wtp_cleanup(): void
{
    wtp_rrmdir(WTP_TMP_DIR);
}

/** Remove the installer, its configuration and its state. */
function wtp_self_destruct(): void
{
    @unlink(WTP_CONFIG_FILE);
    @unlink(WTP_STATE_FILE);
    @unlink(WTP_SELF);
}

function wtp_random_salt(int $length = 64): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_[]{}<>~`+=,.;:/?|';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** An installer failure with a code WHMCS's classifier understands. */
class WtpError extends RuntimeException
{
    /** @var string */
    private $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function code(): string
    {
        return $this->errorCode;
    }
}
