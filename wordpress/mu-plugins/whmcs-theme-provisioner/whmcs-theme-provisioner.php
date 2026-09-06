<?php
/**
 * Plugin Name: WHMCS Theme Provisioner
 * Description: Installs the theme and demo content chosen at checkout, then reports progress back to WHMCS.
 * Version:     1.0.0
 * Author:      WTP
 * License:     Proprietary
 *
 * This is a must-use plugin. WordPress only auto-loads *.php files at the top
 * level of wp-content/mu-plugins, so a two-line loader sits beside this
 * directory and requires this file. It cannot be deactivated from wp-admin,
 * which is deliberate: provisioning must survive a curious customer.
 *
 * Lifecycle:
 *   1. the installer leaves credentials in wp-content/uploads/.wtp/credentials.php
 *   2. this agent exchanges the bootstrap token for a signing secret (handshake)
 *   3. it pulls the provisioning plan and runs it, one bounded step per tick
 *   4. when everything is imported it reports completion and goes dormant
 *
 * Once the job is finished the agent does nothing at all on subsequent requests
 * beyond one cheap option read.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WTP_AGENT_VERSION', '1.0.0');
define('WTP_AGENT_DIR', __DIR__);
define('WTP_AGENT_MIN_PHP', '7.4');

/**
 * A host below the minimum must fail loudly rather than half-provision a site.
 * There is no supported path here: the installer refuses to run on such a host
 * too, so reaching this branch means the account's PHP version was changed
 * afterwards.
 */
if (version_compare(PHP_VERSION, WTP_AGENT_MIN_PHP, '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>WHMCS Theme Provisioner:</strong> '
            . 'PHP ' . esc_html(WTP_AGENT_MIN_PHP) . ' or newer is required; this account runs PHP '
            . esc_html(PHP_VERSION) . '. Automatic site setup has been paused.</p></div>';
    });
    return;
}

require_once WTP_AGENT_DIR . '/shared/autoload.php';

spl_autoload_register(static function (string $class): void {
    // Shared library classes are handled by their own autoloader.
    if (strncmp($class, 'Wtp\\Shared\\', 11) === 0) {
        return;
    }
    if (strncmp($class, 'Wtp_', 4) !== 0) {
        return;
    }

    // Wtp_Import_Content_Importer -> importer/class-content-importer.php
    $map = [
        'Wtp_Api_' => 'api/class-',
        'Wtp_Security_' => 'security/class-',
        'Wtp_Import_' => 'importer/class-',
    ];

    foreach ($map as $prefix => $directory) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $slug = strtolower(str_replace('_', '-', substr($class, strlen($prefix))));
            $path = WTP_AGENT_DIR . '/' . $directory . $slug . '.php';
            if (is_readable($path)) {
                require_once $path;
            }
            return;
        }
    }

    $slug = strtolower(str_replace('_', '-', substr($class, 4)));
    foreach (['includes/class-', 'importer/class-'] as $directory) {
        $path = WTP_AGENT_DIR . '/' . $directory . $slug . '.php';
        if (is_readable($path)) {
            require_once $path;
            return;
        }
    }
});

require_once WTP_AGENT_DIR . '/importer/interface-importer.php';

Wtp_Plugin::instance()->boot();
