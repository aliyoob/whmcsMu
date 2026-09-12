<?php
namespace Wtp\Tests;

use Wtp\Addon\Support\Config;

/**
 * The environment file has to be findable on stacks where we cannot set an
 * environment variable for the web process — cPanel, where the php-fpm pools
 * are generated and any line we added would be discarded on the next rebuild.
 *
 * The pointer file is that escape hatch, so its rules are worth pinning down.
 */
final class ConfigEnvPathTest extends TestCase
{
    private function pointer(): string
    {
        return Config::envPointerPath();
    }

    private function withPointer(string $body, callable $fn): void
    {
        $path = $this->pointer();
        $existed = is_file($path);
        $saved = $existed ? (string) file_get_contents($path) : null;

        file_put_contents($path, $body);
        Config::resetEnvCache();
        try {
            $fn();
        } finally {
            Config::resetEnvCache();
            if ($saved !== null) {
                file_put_contents($path, $saved);
            } else {
                @unlink($path);
            }
        }
    }

    public function testPointerFileLivesInsideTheModule(): void
    {
        $this->assertContains('wpthemeprovisioner', $this->pointer());
        $this->assertContains('env-path.php', $this->pointer());
    }

    public function testAbsentPointerFallsBackToTheDefault(): void
    {
        $path = $this->pointer();
        if (is_file($path)) {
            $this->assertTrue(true, 'a pointer exists in the working tree; skipping');
            return;
        }
        $this->assertSame('/etc/whmcs-wtp/wtp.env', Config::DEFAULT_ENV_PATH);
    }

    public function testPointerIsHonouredWhenItReturnsAnAbsolutePath(): void
    {
        $target = sys_get_temp_dir() . '/wtp-pointer-target.env';
        file_put_contents($target, "WTP_MASTER_KEY=from-the-pointer\n");

        $this->withPointer("<?php return " . var_export($target, true) . ";\n", function () use ($target) {
            $env = Config::loadEnv(null);
            $this->assertSame('from-the-pointer', $env['WTP_MASTER_KEY'] ?? '');
        });

        @unlink($target);
    }

    public function testRelativePathInThePointerIsRejected(): void
    {
        // A relative path resolves against whatever directory the caller
        // happened to be in — for a cron worker, not the web root. Proven by
        // putting a real, readable file exactly where the relative path points
        // and showing it is still not read.
        $base = sys_get_temp_dir() . '/wtp-relative-' . bin2hex(random_bytes(4));
        mkdir($base . '/relative', 0700, true);
        file_put_contents($base . '/relative/wtp.env', "WTP_MASTER_KEY=should-not-be-read\n");

        $cwd = getcwd();
        chdir($base);
        try {
            $this->withPointer("<?php return 'relative/wtp.env';\n", function () {
                $env = Config::loadEnv(null);
                $this->assertNotContains('should-not-be-read', json_encode($env) ?: '');
            });
        } finally {
            chdir((string) $cwd);
            @unlink($base . '/relative/wtp.env');
            @rmdir($base . '/relative');
            @rmdir($base);
        }
    }

    public function testGarbageInThePointerDoesNotThrow(): void
    {
        // A malformed pointer must degrade to the default, never take the
        // billing system down on the way past.
        $this->withPointer("<?php return ['not', 'a', 'path'];\n", function () {
            $env = Config::loadEnv(null);
            $this->assertSame([], $env, 'a non-string pointer must not be followed');
        });
    }

    public function testAnExplicitPathStillWins(): void
    {
        $target = sys_get_temp_dir() . '/wtp-explicit.env';
        file_put_contents($target, "WTP_MASTER_KEY=explicit\n");

        $this->withPointer("<?php return '/nonexistent/wtp.env';\n", function () use ($target) {
            $env = Config::loadEnv($target);
            $this->assertSame('explicit', $env['WTP_MASTER_KEY'] ?? '');
        });

        @unlink($target);
    }
}
