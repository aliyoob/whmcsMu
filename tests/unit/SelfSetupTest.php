<?php
namespace Wtp\Tests;

use Wtp\Addon\Support\Config;
use Wtp\Addon\Support\SelfSetup;

/**
 * Activation is the only hook WHMCS gives an addon at install time, so
 * SelfSetup runs unattended, on a live billing system, possibly twice because
 * someone clicked Activate again. The tests that matter are about what it
 * refuses to do.
 */
final class SelfSetupTest extends TestCase
{
    /** @var string */
    private $base;

    private function fixture(bool $cpanel = true): SelfSetup
    {
        $this->base = sys_get_temp_dir() . '/wtp-selfsetup-' . bin2hex(random_bytes(5));
        mkdir($this->base . '/whmcs/modules/addons/wpthemeprovisioner', 0700, true);
        mkdir($this->base . '/home', 0700, true);
        file_put_contents($this->base . '/whmcs/init.php', "<?php\n");

        return new SelfSetup(
            $this->base . '/whmcs/modules/addons/wpthemeprovisioner',
            $this->base . '/whmcs',
            $this->base . '/home',
            $cpanel
        );
    }

    private function cleanUp(): void
    {
        if ($this->base === '' || !is_dir($this->base)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
        }
        @rmdir($this->base);
        Config::resetEnvCache();
    }

    public function testItCreatesStorageAndAKeyInsideTheHomeDirectory(): void
    {
        $setup = $this->fixture();
        try {
            $result = $setup->run('https://billing.example.com');

            $this->assertSame([], $result['errors'], implode(' ', $result['errors']));
            $this->assertContains('/home/.whmcs-wtp/wtp.env', $setup->envPath());
            $this->assertTrue(is_dir($setup->storagePath() . '/agent'));

            $env = Config::loadEnv($setup->envPath());
            $this->assertTrue(($env['WTP_MASTER_KEY'] ?? '') !== '', 'a key should have been generated');
            $this->assertSame('https://billing.example.com', $env['WTP_API_BASE_URL'] ?? '');
        } finally {
            $this->cleanUp();
        }
    }

    public function testTheKeyFileIsNotReadableByAnyoneElse(): void
    {
        $setup = $this->fixture();
        try {
            $setup->run();
            $mode = fileperms($setup->envPath()) & 0777;
            $this->assertSame('600', decoct($mode));
        } finally {
            $this->cleanUp();
        }
    }

    public function testASecondRunNeverReplacesAnExistingKey(): void
    {
        // The failure this guards against is unrecoverable: a regenerated key
        // makes every stored credential undecryptable, and Activate is a button
        // people click twice.
        $setup = $this->fixture();
        try {
            $setup->run();
            $first = Config::loadEnv($setup->envPath())['WTP_MASTER_KEY'] ?? '';

            Config::resetEnvCache();
            $second = $setup->run();
            $after = Config::loadEnv($setup->envPath())['WTP_MASTER_KEY'] ?? '';

            $this->assertTrue($first !== '', 'the first run should produce a key');
            $this->assertSame($first, $after);
            $this->assertSame([], $second['errors']);
        } finally {
            $this->cleanUp();
        }
    }

    public function testItWritesAPointerSoTheKeyIsFoundWithoutAnEnvironmentVariable(): void
    {
        $setup = $this->fixture();
        try {
            $setup->run();
            $pointer = $setup->pointerPath();
            $this->assertTrue(is_file($pointer), 'the pointer file should exist');

            $value = include $pointer;
            $this->assertSame($setup->envPath(), $value);
        } finally {
            $this->cleanUp();
        }
    }

    public function testItInstallsAShippedAgentPackage(): void
    {
        $setup = $this->fixture();
        try {
            $resources = dirname($setup->pointerPath()) . '/resources';
            mkdir($resources, 0700, true);
            file_put_contents($resources . '/agent.zip', 'pretend-zip');

            $setup->run();

            $this->assertTrue(is_file($setup->agentPackagePath()));
            $this->assertSame('pretend-zip', file_get_contents($setup->agentPackagePath()));
        } finally {
            $this->cleanUp();
        }
    }

    public function testItReportsAMissingAgentPackageRatherThanFailing(): void
    {
        $setup = $this->fixture();
        try {
            $result = $setup->run();
            $this->assertSame([], $result['errors']);
            $this->assertContains('agent package', implode(' ', $result['notes']));
        } finally {
            $this->cleanUp();
        }
    }

    public function testTheCronCommandNamesTheRunningPhpBinary(): void
    {
        // `php` on PATH is regularly a different version from the one the site
        // runs, which is exactly the mistake this line exists to prevent.
        $setup = $this->fixture();
        try {
            $command = $setup->cronCommand();
            $this->assertContains(PHP_BINARY, $command);
            $this->assertContains('cron/worker.php', $command);
        } finally {
            $this->cleanUp();
        }
    }

    public function testReportChangesNothing(): void
    {
        $setup = $this->fixture();
        try {
            $report = $setup->report();
            $this->assertFalse($report['env_exists']);
            $this->assertFalse($report['storage_exists']);
            $this->assertFalse(is_dir($setup->storagePath()), 'report() must not create storage');
            $this->assertFalse(is_file($setup->envPath()), 'report() must not create a key');
        } finally {
            $this->cleanUp();
        }
    }
}
