<?php
/**
 * WHMCS hooks for the WordPress Theme Provisioner.
 *
 * WHMCS loads this file automatically for any active addon module. Every hook
 * is wrapped so that a failure here can never take down the cart or the
 * checkout: the worst case is that the picker does not render and the order
 * falls back to the product's default theme.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Build the cart integration, or null when the addon is not usable (missing
 * schema, missing master key, mid-upgrade).
 */
function wtp_cart_integration()
{
    static $integration = null;
    static $attempted = false;

    if ($attempted) {
        return $integration;
    }
    $attempted = true;

    try {
        $container = \Wtp\Addon\Container::boot();
        if (!$container->db()->tableExists('mod_wtp_product_settings')) {
            return null;
        }
        $integration = new \Wtp\Addon\Client\CartIntegration($container);
    } catch (\Throwable $e) {
        error_log('[WTP] cart integration unavailable: ' . $e->getMessage());
        $integration = null;
    }

    return $integration;
}

/** Run $callback, swallowing and logging anything it throws. */
function wtp_guard(callable $callback, $fallback = null)
{
    try {
        return $callback();
    } catch (\Throwable $e) {
        error_log('[WTP][hook] ' . $e->getMessage());
        return $fallback;
    }
}

// ---------------------------------------------------------------------------
// Order form
// ---------------------------------------------------------------------------

add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    return wtp_guard(static function () use ($vars) {
        $integration = wtp_cart_integration();
        return $integration === null ? '' : $integration->headOutput($vars);
    }, '');
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    return wtp_guard(static function () use ($vars) {
        $integration = wtp_cart_integration();
        return $integration === null ? '' : $integration->footerOutput($vars);
    }, '');
});

/**
 * The customer submitted the product configuration form. This is where the two
 * posted integers are validated and staged; nothing is trusted beyond this
 * point without being resolved against the catalogue again.
 */
add_hook('ShoppingCartValidateProductUpdate', 1, function ($vars) {
    return wtp_guard(static function () use ($vars) {
        $integration = wtp_cart_integration();
        if ($integration === null) {
            return [];
        }
        $request = is_array($vars) ? array_merge($_REQUEST, $vars) : $_REQUEST;
        return $integration->validateProductUpdate($request);
    }, []);
});

/**
 * Final gate before an order is created. A hosting plan that requires a demo
 * and does not have one must not reach payment — provisioning it would produce
 * an empty site and a support ticket.
 */
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    return wtp_guard(static function () {
        $integration = wtp_cart_integration();
        return $integration === null ? [] : $integration->validateCheckout();
    }, []);
});

/**
 * The order exists and the services have been created: bind each staged
 * selection to its service.
 */
add_hook('AfterShoppingCartCheckout', 1, function ($vars) {
    wtp_guard(static function () use ($vars) {
        $integration = wtp_cart_integration();
        if ($integration !== null) {
            $integration->bindOrder(is_array($vars) ? $vars : []);
        }
        return null;
    });
});

// ---------------------------------------------------------------------------
// Service lifecycle
// ---------------------------------------------------------------------------

/**
 * A deleted service leaves its selection and jobs behind on purpose: the audit
 * trail of what was installed where outlives the billing record. Only the live
 * credentials are revoked.
 */
add_hook('ServiceDelete', 1, function ($vars) {
    wtp_guard(static function () use ($vars) {
        $serviceId = (int) ($vars['serviceid'] ?? 0);
        if ($serviceId <= 0) {
            return null;
        }
        $container = \Wtp\Addon\Container::boot();
        $job = $container->jobs()->activeForService($serviceId);
        if ($job === null) {
            return null;
        }
        $container->jobs()->revokeSecret((int) $job['id']);
        $container->grants()->revokeForJob((int) $job['id']);
        $container->eventLog()->warning(
            (int) $job['id'],
            'The WHMCS service was deleted; agent credentials revoked',
            ['service_id' => $serviceId],
            null,
            'service_deleted'
        );
        return null;
    });
});

/**
 * Surface provisioning progress on the client's service page without needing a
 * template override.
 */
add_hook('ClientAreaPageProductDetails', 1, function ($vars) {
    return wtp_guard(static function () use ($vars) {
        $serviceId = (int) ($vars['id'] ?? $vars['serviceid'] ?? 0);
        if ($serviceId <= 0) {
            return [];
        }

        $container = \Wtp\Addon\Container::boot();
        $job = $container->jobs()->latestForService($serviceId);
        if ($job === null) {
            return [];
        }

        $steps = $container->steps()->forJob((int) $job['id']);
        $presented = \Wtp\Shared\Job\StatusPresenter::job((string) $job['status'], (string) $job['phase']);

        return [
            'wtpProvisioning' => [
                'status' => (string) $job['status'],
                'label' => $presented['label'],
                'tone' => $presented['tone'],
                'percentage' => \Wtp\Shared\Job\StatusPresenter::percentage($steps),
                'checklist' => \Wtp\Shared\Job\StatusPresenter::checklist($steps),
                'result' => \Wtp\Shared\Support\Json::tryDecode($job['result'] ?? null, []),
            ],
        ];
    }, []);
});
