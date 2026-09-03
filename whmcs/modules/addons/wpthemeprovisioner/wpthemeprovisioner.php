<?php
/**
 * WHMCS WordPress Theme Provisioner — addon module
 *
 * Owns the theme/demo catalogue, the provisioning job queue, the agent API and
 * the admin interface. The companion server module `wpthemehost` handles the
 * WHMCS product lifecycle and enqueues jobs into this addon.
 *
 * @package  WTP
 * @version  1.0.0
 * @license  Proprietary
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Addon metadata and admin-visible settings.
 *
 * Note what is *not* here: no API keys, no master key, no server passwords.
 * Secrets live in the environment file (see docs/security.md) so they never
 * enter the WHMCS database, a database backup, or a screenshot in a ticket.
 *
 * @return array<string,mixed>
 */
function wpthemeprovisioner_config()
{
    return [
        'name' => 'WordPress Theme Provisioner',
        'description' => 'Lets customers choose a WordPress theme and demo at checkout, then '
            . 'provisions cPanel, installs WordPress and imports the demo automatically.',
        'author' => 'WTP',
        'language' => 'english',
        'version' => WTP_VERSION,
        'fields' => [
            'storage_path' => [
                'FriendlyName' => 'Package storage path',
                'Type' => 'text',
                'Size' => '60',
                'Default' => '/var/lib/whmcs-wtp/packages',
                'Description' => '<br>Absolute path <strong>outside the web root</strong> where theme and '
                    . 'demo packages are stored. Must be readable by the web server user.',
            ],
            'api_base_url' => [
                'FriendlyName' => 'Agent API base URL',
                'Type' => 'text',
                'Size' => '60',
                'Description' => '<br>Leave empty to use the WHMCS system URL. Must be reachable over '
                    . 'HTTPS from your hosting servers.',
            ],
            'wp_core_source' => [
                'FriendlyName' => 'WordPress core source',
                'Type' => 'dropdown',
                'Options' => 'mirror,wordpress.org',
                'Default' => 'mirror',
                'Description' => '<br><strong>mirror</strong> caches the archive on this server and serves '
                    . 'it over a signed link (recommended). <strong>wordpress.org</strong> makes each '
                    . 'hosting account download it directly.',
            ],
            'wp_version' => [
                'FriendlyName' => 'WordPress version',
                'Type' => 'text',
                'Size' => '20',
                'Default' => 'latest',
                'Description' => '<br>"latest" or an explicit version such as 6.5.2.',
            ],
            'log_level' => [
                'FriendlyName' => 'Log level',
                'Type' => 'dropdown',
                'Options' => 'debug,info,warning,error',
                'Default' => 'info',
                'Description' => '<br>"debug" is verbose; useful while setting a new theme up.',
            ],
            'log_retention_days' => [
                'FriendlyName' => 'Log retention (days)',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '60',
            ],
        ],
    ];
}

/**
 * Create the schema. Safe to run repeatedly: every migration is guarded and
 * recorded, and nothing is ever dropped.
 *
 * @return array<string,string>
 */
function wpthemeprovisioner_activate()
{
    try {
        $container = \Wtp\Addon\Container::boot();
        $installer = new \Wtp\Addon\Support\Installer(
            $container->db(),
            $container->clock(),
            WTP_ADDON_DIR . '/migrations'
        );

        $result = $installer->migrate();
        if ($result['errors'] !== []) {
            return [
                'status' => 'error',
                'description' => 'Schema installation failed: ' . implode(' | ', $result['errors']),
            ];
        }
        $installer->seedDefaults();

        $notes = [];
        if (!$container->config()->hasMasterKey()) {
            $notes[] = 'Set WTP_MASTER_KEY in ' . $container->config()->envPath()
                . ' before taking orders (see docs/security.md).';
        }
        $notes[] = 'Add the worker cron entry — see the Settings tab.';

        return [
            'status' => 'success',
            'description' => count($result['applied']) . ' migration(s) applied. ' . implode(' ', $notes),
        ];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

/**
 * Deactivation intentionally keeps every table.
 *
 * Live customer sites were provisioned from this data and their audit trail is
 * the only record of what was installed where. Dropping it because someone
 * clicked "Deactivate" would be unrecoverable; removing the tables is a
 * deliberate, documented manual step instead.
 *
 * @return array<string,string>
 */
function wpthemeprovisioner_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'Deactivated. The mod_wtp_* tables and stored packages were left intact — '
            . 'see docs/installation.md if you really want to remove them.',
    ];
}

/**
 * @param array<string,mixed> $vars
 * @return array<string,string>
 */
function wpthemeprovisioner_upgrade($vars)
{
    try {
        $container = \Wtp\Addon\Container::boot();
        $installer = new \Wtp\Addon\Support\Installer(
            $container->db(),
            $container->clock(),
            WTP_ADDON_DIR . '/migrations'
        );
        $result = $installer->migrate();
        $installer->seedDefaults();

        if ($result['errors'] !== []) {
            return ['status' => 'error', 'description' => implode(' | ', $result['errors'])];
        }
        return [
            'status' => 'success',
            'description' => 'Upgraded to ' . WTP_VERSION . '; '
                . count($result['applied']) . ' migration(s) applied.',
        ];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Upgrade failed: ' . $e->getMessage()];
    }
}

/**
 * Admin interface.
 *
 * @param array<string,mixed> $vars
 */
function wpthemeprovisioner_output($vars)
{
    try {
        $container = \Wtp\Addon\Container::boot();
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger errorbox"><strong>The provisioner cannot start.</strong> '
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
            . '<br>Check the environment file and the module log.</div>';
        return;
    }

    // Persist the operator-facing settings from the addon configuration screen
    // so the rest of the system reads one source of truth.
    wpthemeprovisioner_sync_settings($container, $vars);

    $request = array_merge($_GET, $_POST);
    $request['_method'] = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    $controller = new \Wtp\Addon\Admin\AdminController(
        $container,
        new \Wtp\Addon\Admin\View(WTP_ADDON_DIR . '/templates/admin'),
        (string) $vars['modulelink']
    );

    echo $controller->handle($request);
}

/**
 * Copy the addon-configuration values into mod_wtp_settings.
 *
 * The addon config screen is the natural place for an operator to set these,
 * but the worker and the API endpoint run without WHMCS's addon context — so
 * the values are mirrored into a table both can read.
 *
 * @param array<string,mixed> $vars
 */
function wpthemeprovisioner_sync_settings(\Wtp\Addon\Container $container, array $vars)
{
    $mirrored = ['storage_path', 'api_base_url', 'wp_core_source', 'wp_version', 'log_level', 'log_retention_days'];

    try {
        $db = $container->db();
        $now = $container->clock()->nowSql();

        foreach ($mirrored as $key) {
            if (!array_key_exists($key, $vars)) {
                continue;
            }
            $value = (string) $vars[$key];
            $affected = $db->update('mod_wtp_settings', ['v' => $value, 'updated_at' => $now], ['k' => $key]);
            if ($affected === 0) {
                $existing = $db->selectOne('SELECT k FROM mod_wtp_settings WHERE k = :k', ['k' => $key]);
                if ($existing === null) {
                    $db->insert('mod_wtp_settings', ['k' => $key, 'v' => $value, 'updated_at' => $now]);
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[WTP] could not mirror addon settings: ' . $e->getMessage());
    }
}

/**
 * Admin sidebar shortcuts.
 *
 * @param array<string,mixed> $vars
 * @return string
 */
function wpthemeprovisioner_sidebar($vars)
{
    $link = htmlspecialchars((string) $vars['modulelink'], ENT_QUOTES, 'UTF-8');

    $attention = 0;
    try {
        $container = \Wtp\Addon\Container::boot();
        $attention = (int) $container->db()->scalar(
            "SELECT COUNT(*) FROM mod_wtp_jobs WHERE status IN ('failed', 'needs_attention')"
        );
    } catch (\Throwable $e) {
        unset($e);
    }

    $badge = $attention > 0
        ? ' <span class="label label-danger">' . $attention . '</span>'
        : '';

    return '<span class="header"><i class="fa fa-wordpress"></i> Provisioner</span>
        <ul class="menu">
            <li><a href="' . $link . '&action=dashboard">Dashboard</a></li>
            <li><a href="' . $link . '&action=themes">Themes &amp; demos</a></li>
            <li><a href="' . $link . '&action=products">Products</a></li>
            <li><a href="' . $link . '&action=jobs">Jobs' . $badge . '</a></li>
            <li><a href="' . $link . '&action=settings">Settings</a></li>
        </ul>';
}
