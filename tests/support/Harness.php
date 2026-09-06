<?php
namespace Wtp\Tests;

use PDO;
use Wtp\Addon\Container;
use Wtp\Addon\Support\Config;
use Wtp\Addon\Support\Db;
use Wtp\Shared\Crypto\SecretBox;
use Wtp\Shared\Support\FrozenClock;
use Wtp\Shared\Support\Json;

/**
 * A complete, self-contained WHMCS-side installation for integration tests.
 *
 * Real code all the way down: the real migrations, the real repositories, the
 * real API router, the real signing. Only the two things that genuinely cannot
 * exist in a test are replaced — the cPanel server and the customer's
 * WordPress — and both are replaced by fakes that behave like the real thing
 * including their failure modes.
 */
final class Harness
{
    public const SERVER_ID = 7;
    public const PRODUCT_ID = 3;
    public const CLIENT_ID = 42;
    public const ORDER_ID = 1024;
    public const SERVICE_ID = 555;
    public const DOMAIN = 'acme-example.test';

    /** @var TempDir */
    public $tmp;
    /** @var PDO */
    public $pdo;
    /** @var Db */
    public $db;
    /** @var FrozenClock */
    public $clock;
    /** @var Container */
    public $container;
    /** @var FakePanel */
    public $panel;
    /** @var string */
    public $storagePath;

    /** @var array<string,int> */
    public $ids = [];
    /** @var FakeInstaller|null created once the installer has been deployed */
    public $installer;
    /** @var FakeAgent|null created once the agent package has been "installed" */
    public $agent;
    /** @var bool */
    public $siteReachable = true;
    /** @var string */
    public $siteBody = '<html><head><title>Acme</title></head><body><div class="wp-content">Hello</div>'
        . '<script src="/wp-includes/js/x.js"></script></body></html>';

    public function __construct()
    {
        $root = dirname(__DIR__, 2);

        $this->tmp = new TempDir('wtp-harness-');
        $this->storagePath = $this->tmp->mkdir('storage');
        $this->tmp->mkdir('storage/agent');
        $this->tmp->mkdir('storage/demos');
        $this->tmp->mkdir('storage/themes');
        $this->tmp->mkdir('storage/core');

        $this->pdo = new PDO('sqlite::memory:');
        $schema = new SqliteSchema($this->pdo);
        $schema->migrate($root . '/database/migrations');
        $schema->createWhmcsTables();

        $this->db = new Db($this->pdo);
        $this->clock = new FrozenClock(1700000000);

        // A dedicated env file keeps the harness from picking up a developer's
        // real /etc/whmcs-wtp/wtp.env.
        $envFile = $this->tmp->write('wtp.env', implode("\n", [
            'WTP_MASTER_KEY=' . SecretBox::generateMasterKey(),
            'WTP_STORAGE_PATH=' . $this->storagePath,
            'WTP_ENFORCE_HTTPS=0',
            'WTP_AGENT_PACKAGE=agent/whmcs-theme-provisioner.zip',
            'WTP_WP_CORE_SOURCE=mirror',
            'WTP_WP_VERSION=6.5.2',
            'WTP_LOG_LEVEL=debug',
        ]));

        $config = new Config([], $envFile);
        $this->container = new Container($this->db, $config, $this->clock);
        $this->container->setSystemUrl('https://billing.example.test');
        $this->container->set(
            \Wtp\Addon\Support\HttpClient::class,
            new RecordingHttpClient()
        );

        $this->panel = new FakePanel();
        $this->container->panelFactory()->set(self::SERVER_ID, $this->panel);

        $this->seedWhmcs();
        $this->seedAgentPackage();
        $this->seedCoreArchive();
    }

    /**
     * Stand up the customer's server as far as HTTP is concerned: the
     * installer, the agent's trigger endpoint and the finished site.
     *
     * Handlers resolve their state lazily, because the run token and the
     * bootstrap token do not exist until the deploy step has written them into
     * the (fake) hosting account.
     */
    public function wireSite(): void
    {
        $http = $this->http();

        // Handlers are matched most-recently-registered first, so the broadest
        // pattern goes in first: "://domain/" would otherwise swallow the
        // installer and admin-ajax URLs that live underneath it.
        $http->on('://' . self::DOMAIN . '/', function (): array {
            if (!$this->siteReachable) {
                return [
                    'status' => 0,
                    'body' => '',
                    'error' => 'Could not connect',
                    'error_code' => 'connection_refused',
                ];
            }
            return ['status' => 200, 'body' => $this->siteBody];
        });

        // wordpress.org is reachable in the harness so the "latest" resolution
        // path can be exercised, but the harness pins a version by default so
        // no test depends on what wordpress.org happens to publish today.
        $http->on('api.wordpress.org/core/version-check', static function (): array {
            return [
                'status' => 200,
                'body' => (string) json_encode(['offers' => [['current' => '6.5.2']]]),
            ];
        });
        $http->on('wordpress.org/wordpress-', static function (string $method, string $url): array {
            return substr($url, -5) === '.sha1'
                ? ['status' => 200, 'body' => str_repeat('a', 40)]
                : ['status' => 404, 'body' => ''];
        });

        $http->on('/wp-admin/admin-ajax.php', function (string $method, string $url, array $options): array {
            $agent = $this->agent();
            if ($agent === null) {
                return ['status' => 404, 'body' => '{"error":"no agent"}'];
            }
            // One tick per poke: the worker gets to observe and act between
            // the agent's steps, exactly as it would against a real site.
            $outcome = $agent->tick();
            return [
                'status' => 200,
                'body' => (string) json_encode([
                    'success' => $outcome['ok'],
                    'phase' => $outcome['phase'],
                    'completed' => $agent->completedSteps,
                ]),
            ];
        });

        $http->on('wtp-bootstrap-', function (string $method, string $url, array $options): array {
            $installer = $this->installer();
            return $installer === null
                ? ['status' => 404, 'body' => '{"error":"not found"}']
                : $installer->handle($method, $url, $options);
        });
    }

    public function http(): RecordingHttpClient
    {
        /** @var RecordingHttpClient $client */
        $client = $this->container->httpClient();
        return $client;
    }

    /** The installer, once the deploy step has put its configuration in place. */
    public function installer(): ?FakeInstaller
    {
        if ($this->installer !== null) {
            return $this->installer;
        }
        $config = $this->panel->installerConfig('acmeexam');
        if ($config === null) {
            return null;
        }
        $this->installer = new FakeInstaller((string) $config['run_token']);
        return $this->installer;
    }

    /** The agent, once the installer has "installed" it. */
    public function agent(): ?FakeAgent
    {
        if ($this->agent !== null) {
            return $this->agent;
        }
        $config = $this->panel->installerConfig('acmeexam');
        if ($config === null) {
            return null;
        }
        $installer = $this->installer();
        if ($installer === null || empty($installer->completed['agent'])) {
            return null; // the agent is not on the box yet
        }

        $this->agent = new FakeAgent(
            $this->container,
            (string) $config['job'],
            (string) $config['bootstrap_token']
        );
        return $this->agent;
    }

    /**
     * Run the worker until $stop returns true, or the job settles, or the tick
     * budget runs out. Lets a test position the pipeline precisely — most
     * failure scenarios need to intervene mid-flight, not at the end.
     *
     * @param callable(array<string,mixed>):bool $stop
     * @return array{ticks:int,job:array<string,mixed>|null}
     */
    public function runWorkerUntil(callable $stop, int $maxTicks = 60): array
    {
        $ticks = 0;
        while ($ticks++ < $maxTicks) {
            $job = $this->container->jobs()->latestForService(self::SERVICE_ID);
            if ($job === null) {
                break;
            }
            if ($stop($job)) {
                break;
            }
            if (in_array((string) $job['status'], ['completed', 'cancelled', 'failed', 'needs_attention'], true)) {
                break;
            }

            $this->container->worker()->tick(5);
            $this->advanceToNextSchedule();
        }

        return ['ticks' => $ticks, 'job' => $this->container->jobs()->latestForService(self::SERVICE_ID)];
    }

    /** Stop as soon as the agent package is on the box and can be driven. */
    public function runWorkerUntilAgentInstalled(int $maxTicks = 30): array
    {
        return $this->runWorkerUntil(function (array $job): bool {
            $step = $this->container->steps()->find((int) $job['id'], 'agent.install');
            return $step !== null && (string) $step['status'] === 'completed';
        }, $maxTicks);
    }

    private function advanceToNextSchedule(): void
    {
        $refreshed = $this->container->jobs()->latestForService(self::SERVICE_ID);
        if ($refreshed !== null && !empty($refreshed['scheduled_at'])) {
            $due = (int) strtotime((string) $refreshed['scheduled_at'] . ' UTC');
            if ($due > $this->clock->now()) {
                $this->clock->set($due);
            }
        }
        $this->clock->advance(1);
    }

    /** Run the worker until the job settles or the tick budget runs out. */
    public function runWorker(int $maxTicks = 60): array
    {
        $worker = $this->container->worker();
        $ticks = 0;

        while ($ticks++ < $maxTicks) {
            $job = $this->container->jobs()->latestForService(self::SERVICE_ID);
            if ($job === null) {
                break;
            }
            $status = (string) $job['status'];
            if (in_array($status, ['completed', 'cancelled', 'failed', 'needs_attention'], true)) {
                break;
            }

            $worker->tick(5);

            // Jobs schedule themselves into the future after a retry; move the
            // clock rather than sleeping.
            $refreshed = $this->container->jobs()->latestForService(self::SERVICE_ID);
            if ($refreshed !== null && !empty($refreshed['scheduled_at'])) {
                $due = (int) strtotime((string) $refreshed['scheduled_at'] . ' UTC');
                if ($due > $this->clock->now()) {
                    $this->clock->set($due);
                }
            }
            $this->clock->advance(1);
        }

        return [
            'ticks' => $ticks,
            'job' => $this->container->jobs()->latestForService(self::SERVICE_ID),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function steps(): array
    {
        $job = $this->job();
        return $this->container->steps()->forJob((int) $job['id']);
    }

    /** @return array<string,string> step key => status */
    public function stepStatuses(): array
    {
        $out = [];
        foreach ($this->steps() as $step) {
            $out[(string) $step['step_key']] = (string) $step['status'];
        }
        return $out;
    }

    /** @return string[] */
    public function eventCodes(): array
    {
        $job = $this->job();
        $events = $this->container->eventLog()->forJob((int) $job['id'], 500);
        return array_values(array_filter(array_map(static function (array $e): string {
            return (string) ($e['code'] ?? '');
        }, $events)));
    }

    public function cleanup(): void
    {
        $this->tmp->cleanup();
    }

    // --------------------------------------------------------------- seeding

    private function seedWhmcs(): void
    {
        $this->db->insert('tblservers', [
            'id' => self::SERVER_ID,
            'ipaddress' => '198.51.100.10',
            'hostname' => 'srv1.example.test',
            'username' => 'root',
            'password' => '',
            'accesshash' => 'fake-whm-api-token',
            'secure' => 1,
            'port' => 2087,
            'disabled' => 0,
        ]);
        $this->db->insert('tblproductgroups', ['id' => 1, 'name' => 'Hosting']);
        $this->db->insert('tblproducts', [
            'id' => self::PRODUCT_ID,
            'gid' => 1,
            'type' => 'hostingaccount',
            'name' => 'WordPress Starter',
            'servertype' => 'wpthemehost',
        ]);
        $this->db->insert('tblorders', ['id' => self::ORDER_ID, 'userid' => self::CLIENT_ID, 'status' => 'Active']);
        $this->db->insert('tblhosting', [
            'id' => self::SERVICE_ID,
            'userid' => self::CLIENT_ID,
            'orderid' => self::ORDER_ID,
            'packageid' => self::PRODUCT_ID,
            'server' => self::SERVER_ID,
            'domain' => self::DOMAIN,
            'username' => 'acmeexam',
            'domainstatus' => 'Active',
        ]);
    }

    /** A catalogue with one theme, one version and one fully equipped demo. */
    public function seedCatalogue(): void
    {
        $catalog = $this->container->catalog();

        $themeId = $catalog->createTheme([
            'name' => 'Astra',
            'slug' => 'astra',
            'description' => 'A fast, lightweight theme.',
            'preview_image_url' => 'https://cdn.example.test/astra.png',
            'status' => 'active',
        ]);

        $themeZip = $this->storeFixture('themes/astra-4.6.1.zip', $this->makeThemeZip('astra'));

        $versionId = $catalog->createVersion($themeId, [
            'version' => '4.6.1',
            'source_type' => 'storage',
            'source_ref' => $themeZip['path'],
            'checksum_sha256' => $themeZip['sha256'],
            'size_bytes' => $themeZip['size'],
            'is_default' => true,
            'status' => 'active',
        ]);

        $demoId = $catalog->createDemo($themeId, [
            'name' => 'Agency',
            'slug' => 'agency',
            'description' => 'A demo for agencies.',
            'preview_image_url' => 'https://cdn.example.test/agency.png',
            'preview_site_url' => 'https://demo.example.test/agency',
            'status' => 'active',
            'required_plugins' => Json::encode([
                ['source' => 'wporg', 'slug' => 'elementor', 'activate' => true],
            ]),
            'import_plan' => Json::encode([
                'site' => [
                    'front_page' => 'Home',
                    'menus' => ['primary' => 'Main Menu'],
                    'permalink_structure' => '/%postname%/',
                ],
                'options' => ['source_site_url' => 'https://demo.example.test/agency'],
            ]),
        ]);

        $content = $this->storeFixture('demos/agency-content.xml', $this->makeWxr());
        $widgets = $this->storeFixture('demos/agency-widgets.json', '{"sidebar-1":{"text-2":{"title":"Hi"}}}');

        $contentAssetId = $catalog->createAsset($demoId, [
            'role' => 'content',
            'filename' => 'agency-content.xml',
            'storage_path' => $content['path'],
            'size_bytes' => $content['size'],
            'checksum_sha256' => $content['sha256'],
        ]);
        $widgetAssetId = $catalog->createAsset($demoId, [
            'role' => 'widgets',
            'filename' => 'agency-widgets.json',
            'storage_path' => $widgets['path'],
            'size_bytes' => $widgets['size'],
            'checksum_sha256' => $widgets['sha256'],
        ]);

        $this->container->productSettings()->save(self::PRODUCT_ID, [
            'enabled' => true,
            'selection_required' => true,
            'allowed_theme_ids' => [$themeId],
            'default_theme_id' => $themeId,
            'default_demo_id' => $demoId,
        ]);

        $this->ids = [
            'theme' => $themeId,
            'version' => $versionId,
            'demo' => $demoId,
            'content_asset' => $contentAssetId,
            'widget_asset' => $widgetAssetId,
        ];
    }

    /** Bind the service to the seeded theme/demo, as checkout would. */
    public function seedSelection(?int $themeId = null, ?int $demoId = null): void
    {
        $this->container->selections()->record(
            [
                'service_id' => self::SERVICE_ID,
                'order_id' => self::ORDER_ID,
                'client_id' => self::CLIENT_ID,
                'product_id' => self::PRODUCT_ID,
                'domain' => self::DOMAIN,
            ],
            $themeId ?? $this->ids['theme'],
            $demoId ?? $this->ids['demo']
        );
    }

    /**
     * Create the job the provisioning module would create, with the payload it
     * would supply.
     *
     * @return array<string,mixed>
     */
    public function createJob(): array
    {
        $result = $this->container->jobs()->openFor([
            'service_id' => self::SERVICE_ID,
            'order_id' => self::ORDER_ID,
            'client_id' => self::CLIENT_ID,
            'product_id' => self::PRODUCT_ID,
            'server_id' => self::SERVER_ID,
            'domain' => self::DOMAIN,
            'payload' => [
                'site' => ['title' => 'Acme', 'language' => 'en_US', 'scheme' => 'https'],
                'client' => ['id' => self::CLIENT_ID, 'email' => 'buyer@example.test'],
            ],
        ]);

        $job = $result['job'];
        $this->container->jobs()->mergePayload((int) $job['id'], [
            'cpanel' => [
                'username' => 'acmeexam',
                'password_enc' => $this->container->jobSecrets()->seal('cpanel-secret-pw', 'cpanel'),
                'plan' => 'starter',
                'ip' => '198.51.100.10',
            ],
        ]);

        return $this->container->jobs()->require((int) $job['id']);
    }

    /** @return array<string,mixed> */
    public function job(): array
    {
        $job = $this->container->jobs()->latestForService(self::SERVICE_ID);
        if ($job === null) {
            throw new \RuntimeException('No job has been created in this harness');
        }
        return $job;
    }

    // -------------------------------------------------------------- fixtures

    /** @return array{path:string,sha256:string,size:int} */
    public function storeFixture(string $relativePath, string $contents): array
    {
        $absolute = $this->storagePath . '/' . $relativePath;
        $directory = dirname($absolute);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($absolute, $contents);

        return [
            'path' => $relativePath,
            'sha256' => hash('sha256', $contents),
            'size' => strlen($contents),
        ];
    }

    private function seedAgentPackage(): void
    {
        $zipPath = $this->storagePath . '/agent/whmcs-theme-provisioner.zip';
        ZipFactory::make($zipPath, [
            'whmcs-theme-provisioner/whmcs-theme-provisioner.php' => "<?php // agent\n",
            'whmcs-theme-provisioner/includes/class-plugin.php' => "<?php // plugin\n",
        ]);
    }

    private function seedCoreArchive(): void
    {
        ZipFactory::make($this->storagePath . '/core/wordpress-6.5.2.zip', [
            'wordpress/index.php' => "<?php // wp\n",
            'wordpress/wp-config-sample.php' => "<?php\ndefine('DB_NAME', 'database_name_here');\n",
            'wordpress/wp-settings.php' => "<?php // settings\n",
        ]);
    }

    private function makeThemeZip(string $slug): string
    {
        $path = $this->tmp->path('theme-build.zip');
        ZipFactory::make($path, [
            $slug . '/style.css' => "/*\nTheme Name: Astra\nVersion: 4.6.1\n*/\n",
            $slug . '/functions.php' => "<?php\n",
            $slug . '/index.php' => "<?php\n",
        ]);
        $contents = (string) file_get_contents($path);
        @unlink($path);
        return $contents;
    }

    private function makeWxr(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <wp:base_site_url>https://demo.example.test/agency</wp:base_site_url>
    <wp:base_blog_url>https://demo.example.test/agency</wp:base_blog_url>
    <wp:category>
        <wp:term_id>2</wp:term_id>
        <wp:category_nicename>news</wp:category_nicename>
        <wp:cat_name>News</wp:cat_name>
    </wp:category>
    <item>
        <title>Home</title>
        <link>https://demo.example.test/agency/home</link>
        <dc:creator>demo</dc:creator>
        <content:encoded><![CDATA[<p>Welcome to https://demo.example.test/agency</p>]]></content:encoded>
        <wp:post_id>10</wp:post_id>
        <wp:post_name>home</wp:post_name>
        <wp:status>publish</wp:status>
        <wp:post_type>page</wp:post_type>
        <wp:post_parent>0</wp:post_parent>
    </item>
    <item>
        <title>About</title>
        <link>https://demo.example.test/agency/about</link>
        <dc:creator>demo</dc:creator>
        <content:encoded><![CDATA[<p>About us</p>]]></content:encoded>
        <wp:post_id>11</wp:post_id>
        <wp:post_name>about</wp:post_name>
        <wp:status>publish</wp:status>
        <wp:post_type>page</wp:post_type>
        <wp:post_parent>10</wp:post_parent>
    </item>
</channel>
</rss>
XML;
    }
}
