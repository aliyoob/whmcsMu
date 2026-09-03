<?php
namespace Wtp\Addon\Provision;

use Wtp\Addon\Job\JobSecrets;
use Wtp\Addon\Panel\PanelException;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Support\Bytes;
use Wtp\Shared\Support\Json;

/**
 * Uploads the installer and everything it needs into the hosting account.
 *
 * Two files are written to the document root:
 *   wtp-bootstrap-<32 hex>.php   the installer itself, randomly named
 *   wtp-bootstrap-<32 hex>.json  its configuration, including credentials
 *
 * The configuration travels over the cPanel API (TLS to WHM), never over the
 * public trigger channel. The installer deletes both files when it finishes.
 */
final class BootstrapHandler implements StepHandler
{
    /** @var JobSecrets */
    private $secrets;
    /** @var CoreMirror */
    private $core;
    /** @var string absolute path to the dropper template */
    private $templatePath;

    public function __construct(JobSecrets $secrets, CoreMirror $core, string $templatePath)
    {
        $this->secrets = $secrets;
        $this->core = $core;
        $this->templatePath = $templatePath;
    }

    /** @return string[] */
    public function handles(): array
    {
        return [StepCatalog::BOOTSTRAP_DEPLOY];
    }

    public function execute(StepContext $context): StepOutcome
    {
        $container = $context->container();
        $config = $container->config();
        $payload = $context->payload();

        $account = (string) ($payload['cpanel']['username'] ?? '');
        $docroot = (string) ($payload['cpanel']['docroot'] ?? '');
        if ($account === '' || $docroot === '') {
            return StepOutcome::failed('account_username_missing', 'The hosting account has not been provisioned yet');
        }

        if (!is_file($this->templatePath)) {
            return StepOutcome::failed(
                'bootstrap_template_missing',
                'The installer template is missing from the WHMCS server: ' . basename($this->templatePath)
            );
        }

        // --- WordPress core ------------------------------------------------
        try {
            $wp = $this->resolveCore($config->getString('wp_core_source', 'mirror'), $config->getString('wp_version', 'latest'), $context);
        } catch (\Throwable $e) {
            return StepOutcome::failed('wp_core_unavailable', $e->getMessage());
        }

        // --- agent package --------------------------------------------------
        $agentPath = $config->getString('agent_package', 'agent/whmcs-theme-provisioner.zip');
        if (!$container->storage()->exists($agentPath)) {
            return StepOutcome::failed(
                'agent_package_missing',
                'The agent package is not in storage (' . $agentPath . '). Run `php bin/build.php` and deploy it.'
            );
        }
        $agentGrant = $this->grantFor($context, 'agent_package', $agentPath, basename($agentPath));

        // --- credentials ----------------------------------------------------
        $dbPassword = $this->secrets->tryOpen($payload['db']['password_enc'] ?? null, 'db');
        if ($dbPassword === '') {
            return StepOutcome::failed('database_credentials_missing', 'The database password could not be recovered');
        }

        $admin = $this->ensureAdminCredentials($context, $payload);
        $siteUrl = $this->siteUrl($context);

        // A fresh run token per deployment, so a leaked one from a previous
        // attempt cannot drive the new installer.
        $runToken = Bytes::token(24);
        $bootstrapToken = $container->jobs()->issueBootstrapToken(
            $context->jobId(),
            $config->getInt('bootstrap_ttl', 1800),
            'pending'
        );

        $suffix = Bytes::hex(16);
        $basename = 'wtp-bootstrap-' . $suffix;

        $installerConfig = [
            'job' => $context->jobUuid(),
            'run_token' => $runToken,
            'expires_at' => $container->clock()->now() + max(3600, $config->getInt('bootstrap_ttl', 1800) * 4),
            'max_runs' => 40,
            'api_base' => $container->apiBaseUrl(),
            'bootstrap_token' => $bootstrapToken,
            'wp' => $wp,
            'agent' => [
                'url' => $agentGrant['url'],
                'sha256' => $agentGrant['sha256'],
                'filename' => $agentGrant['filename'],
            ],
            'db' => [
                'host' => (string) ($payload['db']['host'] ?? 'localhost'),
                'name' => (string) ($payload['db']['name'] ?? ''),
                'user' => (string) ($payload['db']['user'] ?? ''),
                'password' => $dbPassword,
                'prefix' => (string) ($payload['db']['prefix'] ?? 'wp_'),
            ],
            'site' => [
                'url' => $siteUrl,
                'title' => (string) ($payload['site']['title'] ?? $context->domain()),
                'tagline' => (string) ($payload['site']['tagline'] ?? ''),
                'admin_user' => $admin['user'],
                'admin_password' => $admin['password'],
                'admin_email' => $admin['email'],
                'locale' => (string) ($payload['site']['language'] ?? ''),
                'discourage_search_engines' => false,
            ],
            'paths' => ['docroot' => $docroot],
        ];

        $installer = (string) file_get_contents($this->templatePath);

        try {
            // Configuration first: an installer that exists without its config
            // answers 410 and is harmless, the reverse would be a live file
            // with credentials and no code to consume them.
            $context->panel()->writeFile(
                $account,
                $docroot,
                $basename . '.json',
                Json::encode($installerConfig)
            );
            $context->panel()->writeFile($account, $docroot, $basename . '.php', $installer);
        } catch (PanelException $e) {
            return StepOutcome::failed($e->errorCode(), $e->getMessage());
        }

        $container->db()->update('mod_wtp_jobs', [
            'bootstrap_file' => $basename . '.php',
            'updated_at' => $container->clock()->nowSql(),
        ], ['id' => $context->jobId()]);

        $context->mergePayload([
            'site_url' => $siteUrl,
            'bootstrap' => [
                'file' => $basename . '.php',
                'config' => $basename . '.json',
                'run_token_enc' => $this->secrets->seal($runToken, 'run-token'),
                'deployed_at' => $container->clock()->now(),
            ],
            'wp' => ['version' => (string) $wp['version']],
        ]);

        $context->log()->info($context->jobId(), 'Deployed the WordPress installer', [
            'file' => $basename . '.php',
            'docroot' => $docroot,
            'wp_version' => $wp['version'],
            'core_source' => $wp['source'],
        ], $context->stepKey());

        return StepOutcome::completed([
            'installer' => $basename . '.php',
            'wp_version' => $wp['version'],
        ]);
    }

    /**
     * @return array<string,mixed> the `wp` block of the installer configuration
     */
    private function resolveCore(string $source, string $configuredVersion, StepContext $context): array
    {
        $version = $this->core->resolveVersion($configuredVersion);

        if ($source === 'wordpress.org') {
            $direct = $this->core->directSource($version);
            return [
                'source' => 'wordpress.org',
                'version' => $version,
                'url' => $direct['url'],
                'sha1' => $direct['sha1'],
                'sha256' => '',
            ];
        }

        $cached = $this->core->ensureCached($version);
        $grant = $this->grantFor($context, 'wp_core', $cached['path'], $cached['filename']);

        return [
            'source' => 'mirror',
            'version' => $version,
            'url' => $grant['url'],
            'sha256' => $grant['sha256'],
            'sha1' => '',
        ];
    }

    /**
     * @return array{url:string,sha256:string,filename:string}
     */
    private function grantFor(StepContext $context, string $kind, string $relativePath, string $filename): array
    {
        $container = $context->container();
        $storage = $container->storage();
        $config = $container->config();

        $sha = $storage->checksum($relativePath);
        // The installer may need several attempts across the worker's ticks, so
        // this link lives longer and allows more uses than an agent's.
        $ttl = max(3600, $config->getInt('bootstrap_ttl', 1800) * 2);

        $grantId = $container->grants()->issue(
            $context->jobId(),
            [
                'kind' => $kind,
                'ref' => $relativePath,
                'sha256' => $sha,
                'size' => $storage->size($relativePath),
                'filename' => $filename,
            ],
            $ttl,
            10
        );

        $url = $container->signedUrls()->make(
            $container->apiBaseUrl() . '/download',
            $context->jobUuid(),
            (string) $grantId,
            $container->jobs()->downloadKey($context->job()),
            $ttl
        );

        return ['url' => $url, 'sha256' => $sha, 'filename' => $filename];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{user:string,password:string,email:string}
     */
    private function ensureAdminCredentials(StepContext $context, array $payload): array
    {
        $existing = isset($payload['wp']) && is_array($payload['wp']) ? $payload['wp'] : [];

        $user = (string) ($existing['admin_user'] ?? '');
        $email = (string) ($existing['admin_email'] ?? ($payload['client']['email'] ?? ''));
        $password = $this->secrets->tryOpen($existing['admin_password_enc'] ?? null, 'wp-admin');

        if ($user === '') {
            $user = 'admin_' . substr(Bytes::hex(4), 0, 6);
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = 'admin@' . $context->domain();
        }
        if ($password === '') {
            // 24 URL-safe characters: comfortably beyond brute force, and safe
            // to paste into WordPress's login form.
            $password = Bytes::token(18);
        }

        $context->mergePayload([
            'wp' => array_merge($existing, [
                'admin_user' => $user,
                'admin_email' => $email,
                'admin_password_enc' => $this->secrets->seal($password, 'wp-admin'),
            ]),
        ]);

        return ['user' => $user, 'password' => $password, 'email' => $email];
    }

    private function siteUrl(StepContext $context): string
    {
        $domain = $context->domain();
        // The account is brand new, so its certificate may not exist yet. The
        // agent re-checks and upgrades the recorded URL during finalisation.
        $scheme = (string) $context->payloadValue('site.scheme', 'https');
        $scheme = $scheme === 'http' ? 'http' : 'https';
        return $scheme . '://' . $domain;
    }
}
