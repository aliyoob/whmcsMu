<?php
/**
 * WHMCS provisioning module: WordPress hosting with automatic theme/demo import.
 *
 * Creates the cPanel account and then hands off to the WordPress Theme
 * Provisioner addon, which drives the rest of the pipeline out of band.
 *
 * Why this module rather than WHMCS's bundled cPanel module: WHMCS allows only
 * one server module per product, and the account-creation result (username,
 * document root, shared IP) is the input the provisioning pipeline needs. The
 * five WHM calls involved are implemented once, behind a HostingPanel
 * interface, and covered by tests against a fake WHM.
 *
 * @package  WTP
 * @version  1.0.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$wtpAddonBootstrap = dirname(__DIR__, 2) . '/addons/wpthemeprovisioner/bootstrap.php';
if (is_file($wtpAddonBootstrap)) {
    require_once $wtpAddonBootstrap;
}
unset($wtpAddonBootstrap);

use Wtp\Addon\Container;
use Wtp\Addon\Job\SelectionRepository;
use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StatusPresenter;
use Wtp\Shared\Support\Json;

/**
 * @return array<string,mixed>
 */
function wpthemehost_MetaData()
{
    return [
        'DisplayName' => 'WordPress Hosting (Theme Provisioner)',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '2086',
        'DefaultSSLPort' => '2087',
        'ServiceSingleSignOnLabel' => 'Log in to cPanel',
        'AdminSingleSignOnLabel' => 'Log in to WHM',
    ];
}

/**
 * Per-product settings. Deliberately few: everything that is an operational
 * concern (storage, timeouts, keys) belongs to the addon, and everything that
 * is a catalogue concern belongs to the catalogue.
 *
 * @return array<string,mixed>
 */
function wpthemehost_ConfigOptions()
{
    return [
        'plan' => [
            'FriendlyName' => 'cPanel package',
            'Type' => 'text',
            'Size' => '32',
            'Description' => 'The WHM package name to create accounts with.',
        ],
        'install_wordpress' => [
            'FriendlyName' => 'Install WordPress',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Install WordPress and import the chosen demo after the account is created.',
        ],
        'site_scheme' => [
            'FriendlyName' => 'Site URL scheme',
            'Type' => 'dropdown',
            'Options' => 'https,http',
            'Default' => 'https',
            'Description' => 'Use http only if your platform does not issue certificates automatically.',
        ],
        'site_language' => [
            'FriendlyName' => 'WordPress locale',
            'Type' => 'text',
            'Size' => '12',
            'Description' => 'Optional, e.g. en_GB or fa_IR. Leave empty for the WordPress default.',
        ],
        'welcome_credentials' => [
            'FriendlyName' => 'Show WordPress login to client',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Display the generated WordPress administrator credentials in the client area.',
        ],
    ];
}

/**
 * Create the hosting account and enqueue provisioning.
 *
 * Deliberately thin. WHMCS aborts a module call that takes too long, and a
 * half-finished WordPress install is far worse than a queued one — so this
 * records the job and returns, and the addon's worker does the rest.
 *
 * @param array<string,mixed> $params
 * @return string
 */
function wpthemehost_CreateAccount(array $params)
{
    try {
        $container = wpthemehost_container();
        $serviceId = (int) $params['serviceid'];

        $selectionSource = wpthemehost_ensure_selection($container, $params);

        $result = $container->jobs()->openFor([
            'service_id' => $serviceId,
            'order_id' => wpthemehost_order_id($container, $serviceId),
            'client_id' => (int) $params['userid'],
            'product_id' => (int) $params['pid'],
            'server_id' => (int) $params['serverid'],
            'domain' => (string) $params['domain'],
            'max_attempts' => $container->config()->getInt('max_job_attempts', 5),
            'payload' => wpthemehost_payload($container, $params, $selectionSource),
        ]);

        $job = $result['job'];
        $jobId = (int) $job['id'];

        if (!$result['created']) {
            // A retried CreateAccount lands here. Re-queue the existing job
            // rather than provisioning the account a second time.
            $container->jobs()->scheduleAt($jobId, $container->clock()->now());
            $container->eventLog()->info($jobId, 'CreateAccount called again; reusing the open job', [
                'service_id' => $serviceId,
            ]);
        } else {
            // The credentials WHMCS generated are only available in this call,
            // so they are sealed into the job payload now.
            $container->jobs()->mergePayload($jobId, [
                'cpanel' => [
                    'username' => (string) $params['username'],
                    'password_enc' => $container->jobSecrets()->seal((string) $params['password'], 'cpanel'),
                    'plan' => (string) ($params['configoption1'] ?? ''),
                    'ip' => '',
                ],
            ]);
            $container->eventLog()->info($jobId, 'Provisioning queued', [
                'domain' => (string) $params['domain'],
                'service_id' => $serviceId,
                'selection_source' => $selectionSource,
            ]);
        }

        if (strtolower((string) ($params['configoption2'] ?? 'on')) === 'off') {
            // WordPress installation disabled for this product: create the
            // account synchronously and finish there.
            return wpthemehost_create_account_only($container, $params, $jobId);
        }

        logModuleCall('wpthemehost', 'CreateAccount', [
            'serviceid' => $serviceId,
            'domain' => (string) $params['domain'],
        ], ['queued' => true, 'job' => (string) $job['uuid']], '', ['password']);

        return 'success';
    } catch (\Throwable $e) {
        return wpthemehost_error('CreateAccount', $params, $e);
    }
}

/**
 * @param array<string,mixed> $params
 * @return string
 */
function wpthemehost_SuspendAccount(array $params)
{
    try {
        $container = wpthemehost_container();
        $panel = $container->panelFactory()->forServer((int) $params['serverid']);
        $panel->suspendAccount((string) $params['username'], (string) ($params['suspendreason'] ?? ''));

        $job = $container->jobs()->activeForService((int) $params['serviceid']);
        if ($job !== null) {
            $container->eventLog()->warning((int) $job['id'], 'Hosting account suspended', [], null, 'account_suspended');
        }
        return 'success';
    } catch (\Throwable $e) {
        return wpthemehost_error('SuspendAccount', $params, $e);
    }
}

/**
 * @param array<string,mixed> $params
 * @return string
 */
function wpthemehost_UnsuspendAccount(array $params)
{
    try {
        $container = wpthemehost_container();
        $container->panelFactory()->forServer((int) $params['serverid'])
            ->unsuspendAccount((string) $params['username']);

        // A job parked while the account was suspended can now continue.
        $job = $container->jobs()->activeForService((int) $params['serviceid']);
        if ($job !== null && JobStatus::isActive((string) $job['status'])) {
            $container->jobs()->scheduleAt((int) $job['id'], $container->clock()->now());
        }
        return 'success';
    } catch (\Throwable $e) {
        return wpthemehost_error('UnsuspendAccount', $params, $e);
    }
}

/**
 * @param array<string,mixed> $params
 * @return string
 */
function wpthemehost_TerminateAccount(array $params)
{
    try {
        $container = wpthemehost_container();

        // Stop the pipeline before the account disappears, so the worker does
        // not spend the next hour retrying against a deleted account.
        $job = $container->jobs()->activeForService((int) $params['serviceid']);
        if ($job !== null) {
            $jobId = (int) $job['id'];
            $container->grants()->revokeForJob($jobId);
            try {
                $container->jobs()->transition($jobId, JobStatus::CANCELLED);
            } catch (\Throwable $e) {
                $container->jobs()->revokeSecret($jobId);
            }
            $container->eventLog()->warning($jobId, 'Service terminated; provisioning cancelled', [], null, 'job_cancelled');
        }

        $container->panelFactory()->forServer((int) $params['serverid'])
            ->terminateAccount((string) $params['username']);

        return 'success';
    } catch (\Throwable $e) {
        return wpthemehost_error('TerminateAccount', $params, $e);
    }
}

/**
 * @param array<string,mixed> $params
 * @return string
 */
function wpthemehost_ChangePackage(array $params)
{
    try {
        $plan = (string) ($params['configoption1'] ?? '');
        if ($plan === '') {
            return 'No cPanel package is configured for this product';
        }
        wpthemehost_container()->panelFactory()->forServer((int) $params['serverid'])
            ->changePackage((string) $params['username'], $plan);
        return 'success';
    } catch (\Throwable $e) {
        return wpthemehost_error('ChangePackage', $params, $e);
    }
}

/**
 * @param array<string,mixed> $params
 * @return string
 */
function wpthemehost_ChangePassword(array $params)
{
    try {
        wpthemehost_container()->panelFactory()->forServer((int) $params['serverid'])
            ->changePassword((string) $params['username'], (string) $params['password']);
        return 'success';
    } catch (\Throwable $e) {
        return wpthemehost_error('ChangePassword', $params, $e);
    }
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function wpthemehost_TestConnection(array $params)
{
    try {
        $container = wpthemehost_container();

        $panelResult = $container->panelFactory()->forServer((int) $params['serverid'])->testConnection();
        if (!$panelResult['ok']) {
            return ['success' => false, 'error' => 'WHM: ' . $panelResult['message']];
        }

        // The module is useless without the addon behind it, so check that too
        // and say so plainly rather than failing later during an order.
        $problems = [];
        if (!$container->db()->tableExists('mod_wtp_jobs')) {
            $problems[] = 'the WordPress Theme Provisioner addon is not activated';
        }
        if (!$container->config()->hasMasterKey()) {
            $problems[] = 'WTP_MASTER_KEY is not set in ' . $container->config()->envPath();
        }
        try {
            $agent = $container->config()->getString('agent_package', 'agent/whmcs-theme-provisioner.zip');
            if (!$container->storage()->exists($agent)) {
                $problems[] = 'the agent package is missing from storage (' . $agent . ')';
            }
        } catch (\Throwable $e) {
            $problems[] = 'package storage is not usable: ' . $e->getMessage();
        }

        if ($problems !== []) {
            return [
                'success' => false,
                'error' => 'Connected to WHM ' . $panelResult['version'] . ', but ' . implode('; ', $problems) . '.',
            ];
        }

        return ['success' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * The provisioning panel shown on the admin service page.
 *
 * @param array<string,mixed> $params
 * @return array<string,string>
 */
function wpthemehost_AdminServicesTabFields(array $params)
{
    try {
        $container = wpthemehost_container();
        $job = $container->jobs()->latestForService((int) $params['serviceid']);

        if ($job === null) {
            return ['WordPress provisioning' => '<em>No provisioning job for this service yet.</em>'];
        }

        $jobId = (int) $job['id'];
        $steps = $container->steps()->forJob($jobId);
        $presented = StatusPresenter::job((string) $job['status'], (string) $job['phase']);
        $result = Json::tryDecode($job['result'] ?? null, []);

        $rows = '';
        foreach (StatusPresenter::checklist($steps) as $item) {
            $colour = $item['status'] === 'failed' ? '#a94442'
                : ($item['status'] === 'completed' ? '#3c763d' : '#777');
            $rows .= '<div style="padding:2px 0;color:' . $colour . ';">'
                . htmlspecialchars($item['icon'] . ' ' . $item['label'], ENT_QUOTES, 'UTF-8')
                . ($item['error'] !== null && $item['error'] !== ''
                    ? ' <span style="color:#a94442;">— ' . htmlspecialchars($item['error'], ENT_QUOTES, 'UTF-8') . '</span>'
                    : '')
                . '</div>';
        }

        $links = '';
        if (!empty($result['site_url'])) {
            $links .= ' · <a href="' . htmlspecialchars((string) $result['site_url'], ENT_QUOTES, 'UTF-8')
                . '" target="_blank" rel="noopener">Open site</a>';
        }

        $adminLink = 'addonmodules.php?module=wpthemeprovisioner&action=job&job=' . $jobId;

        $html = '<div style="line-height:1.6;">'
            . '<strong>' . htmlspecialchars($presented['label'], ENT_QUOTES, 'UTF-8') . '</strong>'
            . ' <span style="color:#777;">(' . StatusPresenter::percentage($steps) . '%)</span>'
            . $links
            . ' · <a href="' . $adminLink . '">Open job #' . $jobId . '</a>'
            . '<div style="margin-top:8px;">' . $rows . '</div>';

        if (!empty($job['last_error'])) {
            $html .= '<div style="margin-top:8px;color:#a94442;">'
                . htmlspecialchars((string) $job['last_error'], ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $html .= '</div>';

        return ['WordPress provisioning' => $html];
    } catch (\Throwable $e) {
        return ['WordPress provisioning' => '<em>Unavailable: '
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</em>'];
    }
}

/**
 * @return array<string,string>
 */
function wpthemehost_AdminCustomButtonArray()
{
    return [
        'Retry provisioning' => 'retryProvisioning',
        'Rotate agent secret' => 'rotateAgentSecret',
    ];
}

/**
 * @param array<string,mixed> $params
 * @return string
 */
function wpthemehost_retryProvisioning(array $params)
{
    try {
        $container = wpthemehost_container();
        $job = $container->jobs()->latestForService((int) $params['serviceid']);
        if ($job === null) {
            return 'There is no provisioning job for this service';
        }
        $jobId = (int) $job['id'];

        if (JobStatus::isTerminal((string) $job['status']) && (string) $job['status'] !== JobStatus::CANCELLED) {
            return 'This job has already completed';
        }

        $failed = $container->steps()->firstFailed($jobId);
        $from = $failed !== null
            ? (string) $failed['step_key']
            : \Wtp\Shared\Job\StepCatalog::ACCOUNT_CREATE;

        $container->steps()->requeueFrom($jobId, $from);
        if ((string) $job['status'] !== JobStatus::PENDING) {
            $container->jobs()->transition($jobId, JobStatus::PENDING, $from);
        }
        $container->jobs()->clearError($jobId);
        $container->jobs()->scheduleAt($jobId, $container->clock()->now());
        $container->eventLog()->info($jobId, 'Retry requested from the service page', ['from_step' => $from]);

        return 'success';
    } catch (\Throwable $e) {
        return wpthemehost_error('retryProvisioning', $params, $e);
    }
}

/**
 * @param array<string,mixed> $params
 * @return string
 */
function wpthemehost_rotateAgentSecret(array $params)
{
    try {
        $container = wpthemehost_container();
        $job = $container->jobs()->activeForService((int) $params['serviceid']);
        if ($job === null) {
            return 'There is no active provisioning job for this service';
        }
        $container->jobs()->rotateSecret(
            (int) $job['id'],
            $container->config()->getInt('secret_ttl', 86400)
        );
        $container->eventLog()->warning((int) $job['id'], 'Agent secret rotated from the service page', [], null, 'secret_rotated');

        return 'success';
    } catch (\Throwable $e) {
        return wpthemehost_error('rotateAgentSecret', $params, $e);
    }
}

/**
 * Customer-facing progress panel.
 *
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function wpthemehost_ClientArea(array $params)
{
    try {
        $container = wpthemehost_container();
        $job = $container->jobs()->latestForService((int) $params['serviceid']);
        if ($job === null) {
            return [];
        }

        $steps = $container->steps()->forJob((int) $job['id']);
        $presented = StatusPresenter::job((string) $job['status'], (string) $job['phase']);
        $result = Json::tryDecode($job['result'] ?? null, []);
        $payload = Json::tryDecode($job['payload'] ?? null, []);

        $credentials = [];
        if (strtolower((string) ($params['configoption5'] ?? 'on')) !== 'off'
            && (string) $job['status'] === JobStatus::COMPLETED) {
            $credentials = [
                'username' => (string) ($payload['wp']['admin_user'] ?? ''),
                'password' => $container->jobSecrets()->tryOpen(
                    $payload['wp']['admin_password_enc'] ?? null,
                    'wp-admin'
                ),
            ];
        }

        return [
            'templatefile' => 'clientarea',
            'vars' => [
                'status' => (string) $job['status'],
                'statusLabel' => $presented['label'],
                'statusTone' => $presented['tone'],
                'percentage' => StatusPresenter::percentage($steps),
                'checklist' => StatusPresenter::checklist($steps),
                'siteUrl' => (string) ($result['site_url'] ?? ''),
                'adminUrl' => (string) ($result['admin_url'] ?? ''),
                'credentials' => $credentials,
                'failed' => in_array((string) $job['status'], [JobStatus::FAILED, JobStatus::NEEDS_ATTENTION], true),
            ],
        ];
    } catch (\Throwable $e) {
        error_log('[WTP][clientarea] ' . $e->getMessage());
        return [];
    }
}

// ---------------------------------------------------------------------------
// Internals
// ---------------------------------------------------------------------------

function wpthemehost_container(): Container
{
    static $container = null;
    if ($container === null) {
        $container = Container::boot();
    }
    return $container;
}

/**
 * The order a service belongs to, if any. Admin-created services have none.
 */
function wpthemehost_order_id(Container $container, int $serviceId): ?int
{
    try {
        $row = $container->db()->selectOne(
            'SELECT orderid FROM tblhosting WHERE id = :id',
            ['id' => $serviceId]
        );
    } catch (\Throwable $e) {
        return null;
    }
    if ($row === null || empty($row['orderid'])) {
        return null;
    }
    return (int) $row['orderid'];
}

/**
 * Make sure the service has a theme/demo selection, falling back to the
 * product defaults for admin- and API-created orders.
 *
 * @param array<string,mixed> $params
 * @return string the source that ended up being used
 */
function wpthemehost_ensure_selection(Container $container, array $params): string
{
    $serviceId = (int) $params['serviceid'];
    $existing = $container->selections()->forService($serviceId);
    if ($existing !== null) {
        return (string) $existing['source'];
    }

    $settings = $container->productSettings()->forProduct((int) $params['pid']);
    if ($settings === null || empty($settings['default_theme_id']) || empty($settings['default_demo_id'])) {
        // Not an error yet: the plan is only needed once the agent asks for it,
        // and an operator can still set the selection from the admin area.
        return 'none';
    }

    $container->selections()->record(
        [
            'service_id' => $serviceId,
            'client_id' => (int) $params['userid'],
            'product_id' => (int) $params['pid'],
            'domain' => (string) $params['domain'],
        ],
        (int) $settings['default_theme_id'],
        (int) $settings['default_demo_id'],
        SelectionRepository::SOURCE_DEFAULT
    );

    return SelectionRepository::SOURCE_DEFAULT;
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function wpthemehost_payload(Container $container, array $params, string $selectionSource): array
{
    $client = is_array($params['clientsdetails'] ?? null) ? $params['clientsdetails'] : [];

    return [
        'site' => [
            'title' => (string) $params['domain'],
            'tagline' => '',
            'language' => (string) ($params['configoption4'] ?? ''),
            'scheme' => strtolower((string) ($params['configoption3'] ?? 'https')) === 'http' ? 'http' : 'https',
        ],
        'client' => [
            'id' => (int) $params['userid'],
            'email' => (string) ($client['email'] ?? ''),
            'firstname' => (string) ($client['firstname'] ?? ''),
            'lastname' => (string) ($client['lastname'] ?? ''),
        ],
        'selection_source' => $selectionSource,
        'product' => [
            'id' => (int) $params['pid'],
            'name' => (string) ($params['producttype'] ?? ''),
        ],
    ];
}

/**
 * "Install WordPress" is off for this product: create the account here and
 * mark the WordPress steps as skipped so the job closes cleanly.
 *
 * @param array<string,mixed> $params
 */
function wpthemehost_create_account_only(Container $container, array $params, int $jobId): string
{
    $panel = $container->panelFactory()->forServer((int) $params['serverid']);
    $account = $panel->createAccount([
        'username' => (string) $params['username'],
        'domain' => (string) $params['domain'],
        'password' => (string) $params['password'],
        'plan' => (string) ($params['configoption1'] ?? ''),
        'contactemail' => (string) ($params['clientsdetails']['email'] ?? ''),
    ]);

    foreach ($container->steps()->forJob($jobId) as $step) {
        $key = (string) $step['step_key'];
        if ($key === \Wtp\Shared\Job\StepCatalog::ACCOUNT_CREATE) {
            $container->steps()->start($jobId, $key);
            $container->steps()->complete($jobId, $key, ['username' => $account['username']]);
            continue;
        }
        $container->steps()->skip($jobId, $key, 'WordPress installation is disabled for this product');
    }

    $container->jobs()->transition($jobId, JobStatus::RUNNING, \Wtp\Shared\Job\StepCatalog::ACCOUNT_CREATE);
    $container->jobs()->transition($jobId, JobStatus::COMPLETED, \Wtp\Shared\Job\StepCatalog::JOB_VERIFY);
    $container->eventLog()->info($jobId, 'Account created without WordPress (disabled for this product)', [
        'username' => $account['username'],
    ]);

    return 'success';
}

/**
 * Log a module failure the way WHMCS expects and return a message the operator
 * can act on. Passwords never reach the module log.
 *
 * @param array<string,mixed> $params
 */
function wpthemehost_error(string $action, array $params, \Throwable $e): string
{
    $message = $e->getMessage();

    try {
        logModuleCall(
            'wpthemehost',
            $action,
            [
                'serviceid' => $params['serviceid'] ?? null,
                'domain' => $params['domain'] ?? null,
                'username' => $params['username'] ?? null,
            ],
            ['error' => $message],
            $e->getTraceAsString(),
            ['password', 'serverpassword', 'serveraccesshash']
        );
    } catch (\Throwable $ignored) {
        error_log('[WTP][' . $action . '] ' . $message);
    }

    return $message;
}
