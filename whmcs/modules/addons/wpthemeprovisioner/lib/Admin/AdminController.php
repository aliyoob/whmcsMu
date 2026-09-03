<?php
namespace Wtp\Addon\Admin;

use Throwable;
use Wtp\Addon\Catalog\CatalogException;
use Wtp\Addon\Container;
use Wtp\Addon\Job\JobException;
use Wtp\Addon\Support\Installer;
use Wtp\Shared\Job\JobStatus;
use Wtp\Shared\Job\StatusPresenter;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Support\Json;

/**
 * The addon's admin area.
 *
 * Every state-changing action is a POST guarded by a CSRF token, and every
 * value rendered back out is escaped by View::e(). Theme names, demo
 * descriptions and customer domains all reach these screens from outside, so
 * neither protection is optional.
 */
final class AdminController
{
    /** @var Container */
    private $container;
    /** @var View */
    private $view;
    /** @var string */
    private $moduleLink;
    /** @var string[] */
    private $notices = [];
    /** @var string[] */
    private $errors = [];

    public function __construct(Container $container, View $view, string $moduleLink)
    {
        $this->container = $container;
        $this->view = $view;
        $this->moduleLink = $moduleLink;
    }

    /**
     * @param array<string,mixed> $request  the merged GET/POST for this request
     */
    public function handle(array $request): string
    {
        $action = isset($request['action']) ? (string) $request['action'] : 'dashboard';
        $isPost = isset($request['_method']) ? $request['_method'] === 'POST' : false;

        if ($isPost) {
            try {
                $this->assertCsrf($request);
                $this->dispatchCommand($action, $request);
            } catch (CatalogException $e) {
                $this->errors[] = $e->getMessage();
            } catch (JobException $e) {
                $this->errors[] = $e->getMessage();
            } catch (Throwable $e) {
                $this->errors[] = 'Unexpected error: ' . $e->getMessage();
                error_log('[WTP][admin] ' . $e->getMessage());
            }
        }

        $this->view->share([
            'link' => $this->moduleLink,
            'csrf' => $this->csrfToken(),
            'notices' => $this->notices,
            'errors' => $this->errors,
            'action' => $action,
        ]);

        try {
            return $this->renderScreen($action, $request);
        } catch (Throwable $e) {
            error_log('[WTP][admin] render failure: ' . $e->getMessage());
            return $this->view->renderInLayout('error', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------- screens

    /** @param array<string,mixed> $request */
    private function renderScreen(string $action, array $request): string
    {
        $catalog = $this->container->catalog();

        switch ($action) {
            case 'themes':
                return $this->view->renderInLayout('themes', [
                    'themes' => $catalog->listThemes(),
                    'demoCounts' => $this->demoCounts(),
                ]);

            case 'theme':
                $id = (int) ($request['id'] ?? 0);
                $theme = $id > 0 ? $catalog->findTheme($id) : null;
                return $this->view->renderInLayout('theme-form', [
                    'theme' => $theme,
                    'versions' => $theme === null ? [] : $catalog->listVersions($id),
                    'demos' => $theme === null ? [] : $catalog->listDemos($id),
                ]);

            case 'demo':
                $id = (int) ($request['id'] ?? 0);
                $themeId = (int) ($request['theme_id'] ?? 0);
                $demo = $id > 0 ? $catalog->findDemo($id) : null;
                if ($demo !== null) {
                    $themeId = (int) $demo['theme_id'];
                }
                return $this->view->renderInLayout('demo-form', [
                    'demo' => $demo,
                    'theme' => $themeId > 0 ? $catalog->findTheme($themeId) : null,
                    'assets' => $demo === null ? [] : $catalog->listAssets((int) $demo['id']),
                ]);

            case 'products':
                return $this->view->renderInLayout('products', [
                    'products' => $this->products(),
                    'settings' => $this->productSettingsMap(),
                    'themes' => $catalog->listThemes(true),
                ]);

            case 'jobs':
                $filters = [];
                if (!empty($request['status'])) {
                    $filters['status'] = [(string) $request['status']];
                }
                if (!empty($request['q'])) {
                    $filters['q'] = (string) $request['q'];
                }
                $page = max(1, (int) ($request['page'] ?? 1));
                return $this->view->renderInLayout('jobs', [
                    'jobs' => $this->container->jobs()->search($filters, 50, ($page - 1) * 50),
                    'filters' => $filters,
                    'page' => $page,
                    'statuses' => JobStatus::all(),
                ]);

            case 'job':
                $job = $this->requireJob($request);
                return $this->view->renderInLayout('job', [
                    'job' => $job,
                    'steps' => $this->container->steps()->forJob((int) $job['id']),
                    'events' => $this->container->eventLog()->forJob((int) $job['id'], 300),
                    'checklist' => StatusPresenter::checklist($this->container->steps()->forJob((int) $job['id'])),
                    'percentage' => StatusPresenter::percentage($this->container->steps()->forJob((int) $job['id'])),
                    'selection' => $this->container->selections()->forService((int) $job['service_id']),
                    'result' => Json::tryDecode($job['result'] ?? null, []),
                    'stepKeys' => StepCatalog::keys(),
                ]);

            case 'settings':
                return $this->view->renderInLayout('settings', [
                    'config' => $this->container->config(),
                    'tables' => $this->installer()->tableStatus(),
                    'migrations' => $this->installer()->appliedMigrations(),
                    'diagnostics' => $this->diagnostics(),
                ]);

            case 'dashboard':
            default:
                return $this->view->renderInLayout('dashboard', [
                    'counts' => $this->jobCounts(),
                    'recent' => $this->container->jobs()->search([], 10),
                    'attention' => $this->container->jobs()->search([
                        'status' => [JobStatus::FAILED, JobStatus::NEEDS_ATTENTION],
                    ], 10),
                    'themes' => count($catalog->listThemes(true)),
                    'diagnostics' => $this->diagnostics(),
                ]);
        }
    }

    // ------------------------------------------------------------ commands

    /** @param array<string,mixed> $request */
    private function dispatchCommand(string $action, array $request): void
    {
        $command = isset($request['do']) ? (string) $request['do'] : '';
        $catalog = $this->container->catalog();

        switch ($command) {
            case 'save-theme':
                $id = (int) ($request['id'] ?? 0);
                if ($id > 0) {
                    $catalog->updateTheme($id, $request);
                    $this->notices[] = 'Theme updated.';
                } else {
                    $id = $catalog->createTheme($request);
                    $this->notices[] = 'Theme created.';
                }
                $this->redirect('theme', ['id' => $id]);
                return;

            case 'toggle-theme':
                $id = (int) $request['id'];
                $theme = $catalog->findTheme($id);
                if ($theme === null) {
                    throw new CatalogException('Theme not found');
                }
                $next = $theme['status'] === 'active' ? 'inactive' : 'active';
                $catalog->setThemeStatus($id, $next);
                $this->notices[] = 'Theme is now ' . $next . '.';
                return;

            case 'delete-theme':
                $catalog->deleteTheme((int) $request['id']);
                $this->notices[] = 'Theme deleted.';
                $this->redirect('themes');
                return;

            case 'save-version':
                $themeId = (int) $request['theme_id'];
                $versionId = (int) ($request['version_id'] ?? 0);
                if ($versionId > 0) {
                    $catalog->updateVersion($versionId, $request);
                    $this->notices[] = 'Version updated.';
                } else {
                    $catalog->createVersion($themeId, $request);
                    $this->notices[] = 'Version added.';
                }
                $this->redirect('theme', ['id' => $themeId]);
                return;

            case 'delete-version':
                $catalog->deleteVersion((int) $request['version_id']);
                $this->notices[] = 'Version deleted.';
                $this->redirect('theme', ['id' => (int) $request['theme_id']]);
                return;

            case 'save-demo':
                $themeId = (int) $request['theme_id'];
                $demoId = (int) ($request['id'] ?? 0);
                if ($demoId > 0) {
                    $catalog->updateDemo($demoId, $request);
                    $this->notices[] = 'Demo updated.';
                } else {
                    $demoId = $catalog->createDemo($themeId, $request);
                    $this->notices[] = 'Demo created.';
                }
                $this->redirect('demo', ['id' => $demoId]);
                return;

            case 'toggle-demo':
                $id = (int) $request['id'];
                $demo = $catalog->findDemo($id);
                if ($demo === null) {
                    throw new CatalogException('Demo not found');
                }
                $next = $demo['status'] === 'active' ? 'inactive' : 'active';
                $catalog->setDemoStatus($id, $next);
                $this->notices[] = 'Demo is now ' . $next . '.';
                return;

            case 'delete-demo':
                $themeId = (int) $request['theme_id'];
                $catalog->deleteDemo((int) $request['id']);
                $this->notices[] = 'Demo deleted.';
                $this->redirect('theme', ['id' => $themeId]);
                return;

            case 'upload-asset':
                $this->uploadAsset($request);
                return;

            case 'delete-asset':
                $asset = $catalog->findAsset((int) $request['asset_id']);
                if ($asset !== null) {
                    // The file itself is content-addressed and may be shared by
                    // another demo, so only the row goes.
                    $catalog->deleteAsset((int) $asset['id']);
                    $this->notices[] = 'Asset removed from this demo.';
                }
                return;

            case 'save-product':
                $productId = (int) $request['product_id'];
                $this->container->productSettings()->save($productId, [
                    'enabled' => !empty($request['enabled']),
                    'selection_required' => !empty($request['selection_required']),
                    'allowed_theme_ids' => $request['allowed_theme_ids'] ?? null,
                    'default_theme_id' => $request['default_theme_id'] ?? null,
                    'default_demo_id' => $request['default_demo_id'] ?? null,
                ]);
                $this->notices[] = 'Product settings saved.';
                return;

            case 'retry-step':
                $this->retryStep($request);
                return;

            case 'retry-job':
                $this->retryJob($request);
                return;

            case 'cancel-job':
                $job = $this->requireJob($request);
                $this->container->jobs()->transition((int) $job['id'], JobStatus::CANCELLED);
                $this->container->grants()->revokeForJob((int) $job['id']);
                $this->container->eventLog()->warning((int) $job['id'], 'Job cancelled by an administrator', [], null, 'job_cancelled');
                $this->notices[] = 'Job cancelled.';
                return;

            case 'rotate-secret':
                $job = $this->requireJob($request);
                $this->container->jobs()->rotateSecret(
                    (int) $job['id'],
                    $this->container->config()->getInt('secret_ttl', 86400)
                );
                $this->container->eventLog()->warning((int) $job['id'], 'Agent secret rotated by an administrator', [], null, 'secret_rotated');
                $this->notices[] = 'The agent secret has been rotated. The site will need to be re-bootstrapped to receive the new one.';
                return;

            case 'run-migrations':
                $result = $this->installer()->migrate();
                $this->installer()->seedDefaults();
                if ($result['errors'] !== []) {
                    $this->errors = array_merge($this->errors, $result['errors']);
                } else {
                    $this->notices[] = count($result['applied']) . ' migration(s) applied, '
                        . count($result['skipped']) . ' already present.';
                }
                return;

            default:
                $this->errors[] = 'Unknown action.';
        }
    }

    /** @param array<string,mixed> $request */
    private function retryStep(array $request): void
    {
        $job = $this->requireJob($request);
        $stepKey = (string) ($request['step'] ?? '');
        if (!StepCatalog::exists($stepKey)) {
            throw new JobException('Unknown step: ' . $stepKey, 'step_not_found');
        }

        $fromHere = !empty($request['from_here']);
        $jobId = (int) $job['id'];

        if ($fromHere) {
            $count = $this->container->steps()->requeueFrom($jobId, $stepKey);
            $this->notices[] = 'Reset ' . $count . ' step(s) from "' . $stepKey . '" onwards.';
        } else {
            $this->container->steps()->requeue($jobId, $stepKey, true);
            $this->notices[] = 'Step "' . $stepKey . '" queued for another attempt.';
        }

        $this->requeueJob($job, $stepKey);
    }

    /** @param array<string,mixed> $request */
    private function retryJob(array $request): void
    {
        $job = $this->requireJob($request);
        $jobId = (int) $job['id'];

        $failed = $this->container->steps()->firstFailed($jobId);
        $stepKey = $failed !== null ? (string) $failed['step_key'] : StepCatalog::ACCOUNT_CREATE;

        $this->container->steps()->requeueFrom($jobId, $stepKey);
        $this->requeueJob($job, $stepKey);
        $this->notices[] = 'Job requeued from "' . $stepKey . '".';
    }

    /** @param array<string,mixed> $job */
    private function requeueJob(array $job, string $stepKey): void
    {
        $jobId = (int) $job['id'];
        $jobs = $this->container->jobs();

        $current = (string) $jobs->require($jobId)['status'];
        if (JobStatus::isTerminal($current)) {
            throw new JobException('This job is finished and cannot be retried', 'job_terminal');
        }
        if ($current === JobStatus::FAILED || $current === JobStatus::NEEDS_ATTENTION) {
            $jobs->transition($jobId, JobStatus::PENDING, $stepKey);
        } else {
            $jobs->setPhase($jobId, $stepKey);
        }

        $jobs->clearError($jobId);
        $jobs->scheduleAt($jobId, $this->container->clock()->now());
        $this->container->eventLog()->info($jobId, 'Requeued by an administrator', ['from_step' => $stepKey], $stepKey);
    }

    /** @param array<string,mixed> $request */
    private function uploadAsset(array $request): void
    {
        $demoId = (int) ($request['demo_id'] ?? 0);
        if ($demoId <= 0 || $this->container->catalog()->findDemo($demoId) === null) {
            throw new CatalogException('Choose a demo before uploading an asset');
        }
        if (!isset($_FILES['asset']) || !is_array($_FILES['asset'])) {
            throw new CatalogException('No file was uploaded');
        }

        $file = $_FILES['asset'];
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new CatalogException('Upload failed with error code ' . (int) $file['error']);
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new CatalogException('The uploaded file is not valid');
        }

        $role = (string) ($request['role'] ?? 'other');
        $original = (string) $file['name'];
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        $allowed = ['zip', 'xml', 'json', 'dat', 'wie', 'txt', 'csv'];
        if (!in_array($extension, $allowed, true)) {
            throw new CatalogException('Only these file types are accepted: ' . implode(', ', $allowed));
        }
        if ((int) $file['size'] > 536870912) {
            throw new CatalogException('That file is larger than the 512 MB limit');
        }

        // A zip is going to be extracted inside a customer's site, so it is
        // validated here, once, rather than trusted later.
        if ($extension === 'zip') {
            $profile = $role === 'plugin'
                ? \Wtp\Shared\Security\SafeZip::CODE_EXTENSIONS
                : \Wtp\Shared\Security\SafeZip::CONTENT_EXTENSIONS;
            (new \Wtp\Shared\Security\SafeZip($profile))->inspect((string) $file['tmp_name']);
        }

        $stored = $this->container->storage()->put((string) $file['tmp_name'], $original, 'demos');

        $this->container->catalog()->createAsset($demoId, [
            'role' => $role,
            'filename' => basename($original),
            'storage_path' => $stored['path'],
            'mime' => (string) ($file['type'] ?? ''),
            'size_bytes' => $stored['size'],
            'checksum_sha256' => $stored['sha256'],
            'sort_order' => (int) ($request['sort_order'] ?? 0),
        ]);

        $this->notices[] = 'Asset uploaded (' . View::bytes($stored['size']) . ', sha256 '
            . substr($stored['sha256'], 0, 12) . '…).';
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function requireJob(array $request): array
    {
        $id = (int) ($request['job'] ?? $request['job_id'] ?? 0);
        $job = $id > 0 ? $this->container->jobs()->find($id) : null;

        if ($job === null && !empty($request['uuid'])) {
            $job = $this->container->jobs()->findByUuid((string) $request['uuid']);
        }
        if ($job === null) {
            throw new JobException('No such provisioning job', 'job_not_found');
        }
        return $job;
    }

    /** @return array<string,int> */
    private function jobCounts(): array
    {
        $counts = [];
        foreach (JobStatus::all() as $status) {
            $counts[$status] = (int) $this->container->db()->scalar(
                'SELECT COUNT(*) FROM mod_wtp_jobs WHERE status = :s',
                ['s' => $status]
            );
        }
        return $counts;
    }

    /** @return array<int,int> theme id => demo count */
    private function demoCounts(): array
    {
        $rows = $this->container->db()->select(
            'SELECT theme_id, COUNT(*) AS total FROM mod_wtp_demos GROUP BY theme_id'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['theme_id']] = (int) $row['total'];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> WHMCS products, read-only */
    private function products(): array
    {
        try {
            return $this->container->db()->select(
                "SELECT p.id, p.name, p.type, p.servertype, g.name AS groupname
                 FROM tblproducts p
                 LEFT JOIN tblproductgroups g ON g.id = p.gid
                 WHERE p.type = 'hostingaccount' OR p.servertype <> ''
                 ORDER BY g.name ASC, p.name ASC"
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function productSettingsMap(): array
    {
        $out = [];
        foreach ($this->container->productSettings()->all() as $row) {
            $out[(int) $row['product_id']] = $row;
        }
        return $out;
    }

    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    private function diagnostics(): array
    {
        $checks = [];

        $checks[] = $this->probe('Database schema', function (): string {
            if (!$this->installer()->isFullyInstalled()) {
                throw new \RuntimeException('Some tables are missing — run the migrations');
            }
            return count(Installer::tables()) . ' tables present';
        });

        $checks[] = $this->probe('Encryption master key', function (): string {
            $box = $this->container->secretBox();
            $box->decrypt($box->encrypt('probe', 'diagnostic'), 'diagnostic');
            return 'configured in ' . $this->container->config()->envPath();
        });

        $checks[] = $this->probe('Package storage', function (): string {
            return $this->container->storage()->root();
        });

        $checks[] = $this->probe('Agent package', function (): string {
            $path = $this->container->config()->getString('agent_package', 'agent/whmcs-theme-provisioner.zip');
            if (!$this->container->storage()->exists($path)) {
                throw new \RuntimeException('missing ' . $path);
            }
            return $path . ' (' . View::bytes($this->container->storage()->size($path)) . ')';
        });

        $checks[] = $this->probe('Installer template', function (): string {
            $path = $this->container->bootstrapTemplatePath();
            if (!is_file($path)) {
                throw new \RuntimeException('missing ' . $path);
            }
            return basename($path);
        });

        $checks[] = $this->probe('Agent API URL', function (): string {
            return $this->container->apiBaseUrl();
        });

        return $checks;
    }

    /** @return array{label:string,ok:bool,detail:string} */
    private function probe(string $label, callable $check): array
    {
        try {
            return ['label' => $label, 'ok' => true, 'detail' => (string) $check()];
        } catch (Throwable $e) {
            return ['label' => $label, 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    private function installer(): Installer
    {
        return new Installer(
            $this->container->db(),
            $this->container->clock(),
            (defined('WTP_ADDON_DIR') ? WTP_ADDON_DIR : __DIR__ . '/../..') . '/migrations'
        );
    }

    /** @param array<string,scalar> $params */
    private function redirect(string $action, array $params = []): void
    {
        // WHMCS has already emitted its admin chrome, so a header redirect is
        // not available. The screens read `action` from the request, so simply
        // rewriting it gives the same effect without a second round trip.
        $_REQUEST['action'] = $action;
        $_GET['action'] = $action;
        foreach ($params as $key => $value) {
            $_REQUEST[$key] = $value;
            $_GET[$key] = $value;
        }
    }

    // ---------------------------------------------------------------- CSRF

    private function csrfToken(): string
    {
        if (function_exists('generate_token')) {
            $token = generate_token('plain');
            if (is_string($token) && $token !== '') {
                return $token;
            }
        }
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['wtp_csrf'])) {
            $_SESSION['wtp_csrf'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['wtp_csrf'];
    }

    /** @param array<string,mixed> $request */
    private function assertCsrf(array $request): void
    {
        $provided = (string) ($request['token'] ?? '');
        if ($provided === '' || !hash_equals($this->csrfToken(), $provided)) {
            throw new JobException(
                'Your session token did not match. Reload the page and try again.',
                'csrf_mismatch'
            );
        }
    }
}
