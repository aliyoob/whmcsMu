<?php
/**
 * Dependency-free PSR-4 autoloader for the Wtp\Shared namespace.
 *
 * Both distributions ship a copy of shared/ and require this file. Composer is
 * a development-time convenience only; nothing at runtime depends on it,
 * because a WHMCS module and a WordPress mu-plugin must be drop-in installable.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Wtp\\Shared\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/Wtp/Shared/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
