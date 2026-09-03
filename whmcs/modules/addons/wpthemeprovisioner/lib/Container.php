<?php
namespace Wtp\Addon;

use PDO;
use Wtp\Addon\Api\Authenticator;
use Wtp\Addon\Api\DbRateLimitStore;
use Wtp\Addon\Api\GrantRepository;
use Wtp\Addon\Api\IdempotencyStore;
use Wtp\Addon\Api\NonceStore;
use Wtp\Addon\Catalog\CatalogRepository;
use Wtp\Addon\Catalog\PlanBuilder;
use Wtp\Addon\Catalog\ProductSettingsRepository;
use Wtp\Addon\Job\JobRepository;
use Wtp\Addon\Job\JobSecrets;
use Wtp\Addon\Job\PlanResolver;
use Wtp\Addon\Job\Worker;
use Wtp\Addon\Panel\PanelFactory;
use Wtp\Addon\Panel\ServerCredentialsProvider;
use Wtp\Addon\Panel\WhmcsServerCredentials;
use Wtp\Addon\Provision\AccountHandler;
use Wtp\Addon\Provision\BootstrapHandler;
use Wtp\Addon\Provision\CoreMirror;
use Wtp\Addon\Provision\DatabaseHandler;
use Wtp\Addon\Provision\InstallerHandler;
use Wtp\Addon\Provision\VerifyHandler;
use Wtp\Addon\Job\SelectionRepository;
use Wtp\Addon\Job\StepRepository;
use Wtp\Addon\Support\Config;
use Wtp\Addon\Support\Db;
use Wtp\Addon\Support\EventLog;
use Wtp\Addon\Support\HttpClient;
use Wtp\Addon\Support\Storage;
use Wtp\Shared\Crypto\SecretBox;
use Wtp\Shared\Http\HmacSigner;
use Wtp\Shared\Http\SignedUrl;
use Wtp\Shared\Job\Backoff;
use Wtp\Shared\Job\ErrorClassifier;
use Wtp\Shared\Job\JobStateMachine;
use Wtp\Shared\Security\RateLimiter;
use Wtp\Shared\Support\Clock;
use Wtp\Shared\Support\SystemClock;

/**
 * A deliberately small service locator.
 *
 * Not a DI framework — WHMCS already loads one composer graph and adding a
 * second is a reliable way to break someone's install. Every service is built
 * lazily and memoised, and every dependency is explicit in the constructor of
 * the class that needs it, so the test suite can build any subgraph by hand.
 */
final class Container
{
    /** @var array<string,mixed> */
    private $instances = [];
    /** @var array<string,callable> */
    private $factories = [];
    /** @var string */
    private $systemUrl = '';

    public function __construct(Db $db, Config $config, ?Clock $clock = null)
    {
        $this->instances[Db::class] = $db;
        $this->instances[Config::class] = $config;
        $this->instances[Clock::class] = $clock !== null ? $clock : new SystemClock();
    }

    /** Build the container the way it is built inside WHMCS. */
    public static function boot(?string $systemUrl = null): self
    {
        $db = Db::fromWhmcs();
        $settings = self::readSettings($db);
        $container = new self($db, new Config($settings));

        if ($systemUrl === null && function_exists('\\WHMCS\\Config\\Setting::getValue')) {
            $systemUrl = (string) \WHMCS\Config\Setting::getValue('SystemURL');
        }
        if ($systemUrl === null && isset($GLOBALS['CONFIG']['SystemURL'])) {
            $systemUrl = (string) $GLOBALS['CONFIG']['SystemURL'];
        }
        $container->setSystemUrl((string) $systemUrl);

        return $container;
    }

    /** @return array<string,string> */
    private static function readSettings(Db $db): array
    {
        if (!$db->tableExists('mod_wtp_settings')) {
            return [];
        }
        $out = [];
        foreach ($db->select('SELECT k, v FROM mod_wtp_settings') as $row) {
            $out[(string) $row['k']] = (string) $row['v'];
        }
        return $out;
    }

    public function setSystemUrl(string $url): void
    {
        $this->systemUrl = rtrim($url, '/');
    }

    public function systemUrl(): string
    {
        return $this->systemUrl;
    }

    public function apiBaseUrl(): string
    {
        return $this->config()->apiBaseUrl($this->systemUrl);
    }

    // ------------------------------------------------------------ services

    public function db(): Db
    {
        return $this->instances[Db::class];
    }

    public function config(): Config
    {
        return $this->instances[Config::class];
    }

    public function clock(): Clock
    {
        return $this->instances[Clock::class];
    }

    public function secretBox(): SecretBox
    {
        return $this->make(SecretBox::class, function (): SecretBox {
            return SecretBox::fromEncodedKey($this->config()->masterKey());
        });
    }

    public function stateMachine(): JobStateMachine
    {
        return $this->make(JobStateMachine::class, static function (): JobStateMachine {
            return new JobStateMachine();
        });
    }

    public function eventLog(): EventLog
    {
        return $this->make(EventLog::class, function (): EventLog {
            return new EventLog($this->db(), $this->clock(), $this->config()->getString('log_level', 'info'));
        });
    }

    public function steps(): StepRepository
    {
        return $this->make(StepRepository::class, function (): StepRepository {
            return new StepRepository($this->db(), $this->clock(), $this->stateMachine());
        });
    }

    public function jobs(): JobRepository
    {
        return $this->make(JobRepository::class, function (): JobRepository {
            return new JobRepository(
                $this->db(),
                $this->clock(),
                $this->stateMachine(),
                $this->steps(),
                $this->secretBox()
            );
        });
    }

    public function catalog(): CatalogRepository
    {
        return $this->make(CatalogRepository::class, function (): CatalogRepository {
            return new CatalogRepository($this->db(), $this->clock());
        });
    }

    public function productSettings(): ProductSettingsRepository
    {
        return $this->make(ProductSettingsRepository::class, function (): ProductSettingsRepository {
            return new ProductSettingsRepository($this->db(), $this->clock());
        });
    }

    public function planBuilder(): PlanBuilder
    {
        return $this->make(PlanBuilder::class, function (): PlanBuilder {
            return new PlanBuilder($this->catalog());
        });
    }

    public function planResolver(): PlanResolver
    {
        return $this->make(PlanResolver::class, function (): PlanResolver {
            return new PlanResolver(
                $this->selections(),
                $this->productSettings(),
                $this->planBuilder(),
                $this->config()->getInt('http_timeout', 30)
            );
        });
    }

    public function selections(): SelectionRepository
    {
        return $this->make(SelectionRepository::class, function (): SelectionRepository {
            return new SelectionRepository($this->db(), $this->clock(), $this->catalog());
        });
    }

    public function signer(): HmacSigner
    {
        return $this->make(HmacSigner::class, function (): HmacSigner {
            return new HmacSigner($this->clock(), $this->config()->getInt('clock_skew', 300));
        });
    }

    public function signedUrls(): SignedUrl
    {
        return $this->make(SignedUrl::class, function (): SignedUrl {
            return new SignedUrl($this->clock());
        });
    }

    public function nonces(): NonceStore
    {
        return $this->make(NonceStore::class, function (): NonceStore {
            return new NonceStore($this->db(), $this->clock(), $this->config()->getInt('nonce_ttl', 900));
        });
    }

    public function idempotency(): IdempotencyStore
    {
        return $this->make(IdempotencyStore::class, function (): IdempotencyStore {
            return new IdempotencyStore($this->db(), $this->clock(), $this->config()->getInt('idempotency_ttl', 86400));
        });
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->make(RateLimiter::class, function (): RateLimiter {
            return new RateLimiter(new DbRateLimitStore($this->db()), $this->clock());
        });
    }

    public function grants(): GrantRepository
    {
        return $this->make(GrantRepository::class, function (): GrantRepository {
            return new GrantRepository($this->db(), $this->clock());
        });
    }

    public function authenticator(): Authenticator
    {
        return $this->make(Authenticator::class, function (): Authenticator {
            return new Authenticator(
                $this->jobs(),
                $this->signer(),
                $this->nonces(),
                $this->rateLimiter(),
                $this->eventLog(),
                $this->config()
            );
        });
    }

    public function storage(): Storage
    {
        return $this->make(Storage::class, function (): Storage {
            return new Storage($this->config()->getString('storage_path'));
        });
    }

    public function backoff(): Backoff
    {
        return $this->make(Backoff::class, static function (): Backoff {
            return new Backoff(15, 600, 0.2);
        });
    }

    public function errorClassifier(): ErrorClassifier
    {
        return $this->make(ErrorClassifier::class, static function (): ErrorClassifier {
            return new ErrorClassifier();
        });
    }

    public function httpClient(): HttpClient
    {
        return $this->make(HttpClient::class, function (): HttpClient {
            return new HttpClient(
                $this->config()->getInt('http_timeout', 30),
                $this->config()->getInt('http_connect_timeout', 10)
            );
        });
    }

    public function jobSecrets(): JobSecrets
    {
        return $this->make(JobSecrets::class, function (): JobSecrets {
            return new JobSecrets($this->secretBox());
        });
    }

    public function serverCredentials(): ServerCredentialsProvider
    {
        return $this->make(ServerCredentialsProvider::class, function (): ServerCredentialsProvider {
            return new WhmcsServerCredentials($this->db());
        });
    }

    public function panelFactory(): PanelFactory
    {
        return $this->make(PanelFactory::class, function (): PanelFactory {
            return new PanelFactory($this->serverCredentials(), $this->httpClient());
        });
    }

    public function coreMirror(): CoreMirror
    {
        return $this->make(CoreMirror::class, function (): CoreMirror {
            return new CoreMirror($this->storage(), $this->httpClient(), $this->db(), $this->clock());
        });
    }

    /** Absolute path to the installer template shipped with this addon. */
    public function bootstrapTemplatePath(): string
    {
        $configured = $this->config()->getString('bootstrap_template', '');
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }
        return (defined('WTP_ADDON_DIR') ? WTP_ADDON_DIR : __DIR__ . '/..') . '/resources/wtp-bootstrap.php';
    }

    /** The queue worker with every WHMCS-side step handler registered. */
    public function worker(?callable $reporter = null): Worker
    {
        $worker = new Worker($this, $this->panelFactory(), $reporter);

        $worker->register(new AccountHandler($this->jobSecrets()));
        $worker->register(new DatabaseHandler($this->jobSecrets()));
        $worker->register(new BootstrapHandler(
            $this->jobSecrets(),
            $this->coreMirror(),
            $this->bootstrapTemplatePath()
        ));
        $worker->register(new InstallerHandler($this->jobSecrets()));
        $worker->register(new VerifyHandler());

        return $worker;
    }

    // ------------------------------------------------------------ plumbing

    /** Override any service — used by the test suite and by the fake panels. */
    public function set(string $key, $instance): void
    {
        $this->instances[$key] = $instance;
    }

    public function has(string $key): bool
    {
        return isset($this->instances[$key]) || isset($this->factories[$key]);
    }

    /** @return mixed */
    private function make(string $key, callable $factory)
    {
        if (!isset($this->instances[$key])) {
            $this->instances[$key] = $factory();
        }
        return $this->instances[$key];
    }
}
