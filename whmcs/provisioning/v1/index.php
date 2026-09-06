<?php
/**
 * WHMCS WordPress Theme Provisioner — agent API entry point (v1)
 *
 * Deploy to:  <whmcs-root>/provisioning/v1/index.php
 *
 * Rewrite (nginx):
 *     location /provisioning/v1 { try_files $uri /provisioning/v1/index.php$is_args$args; }
 * Rewrite (Apache): see the .htaccess shipped alongside this file.
 *
 * This file is deliberately tiny. It boots WHMCS, hands the request to the
 * addon's router and prints whatever comes back. All behaviour lives in
 * modules/addons/wpthemeprovisioner/lib/Api, where it is unit-testable without
 * a web server.
 */

declare(strict_types=1);

// An API must never emit warnings into its JSON body.
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

const WTP_API_ENTRY = true;

/** Walk up from this file to find the WHMCS root (the directory holding init.php). */
$whmcsRoot = null;
$candidate = __DIR__;
for ($depth = 0; $depth < 6; $depth++) {
    $candidate = dirname($candidate);
    if (is_file($candidate . '/init.php') && is_dir($candidate . '/modules/addons')) {
        $whmcsRoot = $candidate;
        break;
    }
}

if ($whmcsRoot === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'misconfigured',
        'message' => 'Cannot locate the WHMCS installation from the provisioning endpoint',
    ]);
    exit;
}

require_once $whmcsRoot . '/init.php';

// The agent has no session and holding the lock would serialise every call.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$addon = $whmcsRoot . '/modules/addons/wpthemeprovisioner/bootstrap.php';
if (!is_file($addon)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'addon_missing',
        'message' => 'The WordPress Theme Provisioner addon is not installed',
    ]);
    exit;
}
require_once $addon;

// Buffering keeps any stray notice from a third-party WHMCS hook out of the
// response body; we discard it rather than corrupt the JSON.
ob_start();

try {
    $container = \Wtp\Addon\Container::boot();

    // The base path is configurable (WTP_API_PATH) so the endpoint can be
    // mounted elsewhere; the router must strip whatever it is actually mounted
    // at, or every route below it becomes a 404.
    $basePath = $container->config()->getString('api_path', '/provisioning/v1');

    $request = \Wtp\Addon\Api\Request::fromGlobals($basePath);
    $response = (new \Wtp\Addon\Api\Router($container))->handle($request);
} catch (\Throwable $e) {
    error_log('[WTP] provisioning endpoint bootstrap failure: ' . $e->getMessage());
    $response = \Wtp\Addon\Api\Response::error(
        503,
        'unavailable',
        'The provisioning API is not available right now'
    );
}

$stray = ob_get_clean();
if ($stray !== '' && $stray !== false) {
    error_log('[WTP] discarded ' . strlen((string) $stray) . ' bytes of unexpected output before the API response');
}

$response->send();
