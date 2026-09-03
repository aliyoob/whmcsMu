<?php
/**
 * Addon bootstrap: autoloading + the service container.
 *
 * Deliberately dependency-free. WHMCS ships its own composer autoloader and we
 * must not fight it, so the module registers its own PSR-4 prefixes and nothing
 * else. Loading this file twice is safe.
 */

if (!defined('WTP_ADDON_DIR')) {
    define('WTP_ADDON_DIR', __DIR__);
    define('WTP_ADDON_NAME', 'wpthemeprovisioner');
    define('WTP_VERSION', '1.0.0');
    define('WTP_API_VERSION', '1.0');

    require_once __DIR__ . '/lib/shared/autoload.php';

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Wtp\\Addon\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = WTP_ADDON_DIR . '/lib/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}
