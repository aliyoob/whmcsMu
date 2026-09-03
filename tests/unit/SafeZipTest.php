<?php
namespace Wtp\Tests;

use Wtp\Shared\Security\SafeZip;
use Wtp\Shared\Security\SecurityException;

final class SafeZipTest extends TestCase
{
    /** @var TempDir */
    private $tmp;

    public function setUp(): void
    {
        $this->tmp = new TempDir('wtp-zip-');
    }

    public function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    public function testExtractsAWellFormedThemePackage(): void
    {
        $zip = ZipFactory::make($this->tmp->path('theme.zip'), [
            'astra/style.css' => "/* Theme Name: Astra */\n",
            'astra/functions.php' => "<?php\n",
            'astra/assets/logo.png' => 'PNGDATA',
        ], ['astra/', 'astra/assets/']);

        $dest = $this->tmp->mkdir('out');
        $report = (new SafeZip())->extractTo($zip, $dest);

        $this->assertSame('astra', $report['root']);
        $this->assertTrue(is_file($dest . '/astra/style.css'));
        $this->assertTrue(is_file($dest . '/astra/functions.php'));
        $this->assertSame("/* Theme Name: Astra */\n", file_get_contents($dest . '/astra/style.css'));
    }

    public function testRejectsZipSlipViaDotDot(): void
    {
        $zip = ZipFactory::make($this->tmp->path('slip.zip'), [
            'good/file.txt' => 'ok',
            '../../../../etc/cron.d/pwned' => 'evil',
        ]);
        $dest = $this->tmp->mkdir('out');

        $this->assertThrows(SecurityException::class, static function () use ($zip, $dest) {
            (new SafeZip(null))->extractTo($zip, $dest);
        }, 'Path traversal');

        $this->assertFalse(is_file($this->tmp->path('etc/cron.d/pwned')));
    }

    public function testRejectsNestedDotDotSegments(): void
    {
        $zip = ZipFactory::make($this->tmp->path('slip2.zip'), [
            'theme/../../escape.txt' => 'evil',
        ]);
        $this->assertThrows(SecurityException::class, function () use ($zip) {
            (new SafeZip(null))->inspect($zip);
        }, 'Path traversal');
    }

    public function testRejectsAbsolutePaths(): void
    {
        $zip = ZipFactory::make($this->tmp->path('abs.zip'), [
            '/etc/passwd' => 'root:x:0:0',
        ]);
        $this->assertThrows(SecurityException::class, function () use ($zip) {
            (new SafeZip(null))->inspect($zip);
        }, 'Absolute path');
    }

    public function testRejectsBackslashTraversal(): void
    {
        $zip = ZipFactory::make($this->tmp->path('bs.zip'), [
            'theme\\..\\..\\evil.txt' => 'evil',
        ]);
        $this->assertThrows(SecurityException::class, function () use ($zip) {
            (new SafeZip(null))->inspect($zip);
        });
    }

    public function testRejectsSymlinkEntries(): void
    {
        $zip = ZipFactory::makeWithSymlink($this->tmp->path('link.zip'), 'theme/evil-link', '/etc/passwd');
        $this->assertThrows(SecurityException::class, function () use ($zip) {
            (new SafeZip(null))->inspect($zip);
        }, 'Symlinks are not allowed');
    }

    public function testContentProfileRejectsPhpFiles(): void
    {
        $zip = ZipFactory::make($this->tmp->path('content.zip'), [
            'demo/content.xml' => '<rss></rss>',
            'demo/backdoor.php' => '<?php system($_GET["c"]);',
        ]);
        $this->assertThrows(SecurityException::class, function () use ($zip) {
            (new SafeZip(SafeZip::CONTENT_EXTENSIONS))->inspect($zip);
        }, 'Disallowed file type');
    }

    public function testCodeProfileAllowsPhpFiles(): void
    {
        $zip = ZipFactory::make($this->tmp->path('plugin.zip'), [
            'plugin/plugin.php' => '<?php // plugin',
        ]);
        $this->assertDoesNotThrow(static function () use ($zip) {
            (new SafeZip(SafeZip::CODE_EXTENSIONS))->inspect($zip);
        });
    }

    public function testRejectsDoubleExtensionDisguise(): void
    {
        $zip = ZipFactory::make($this->tmp->path('dbl.zip'), [
            'demo/shell.php.png' => 'GIF89a<?php ?>',
        ]);
        $this->assertThrows(SecurityException::class, function () use ($zip) {
            (new SafeZip(SafeZip::CONTENT_EXTENSIONS))->inspect($zip);
        }, 'double extension');
    }

    public function testRejectsTooManyEntries(): void
    {
        $entries = [];
        for ($i = 0; $i < 60; $i++) {
            $entries['pkg/file' . $i . '.txt'] = 'x';
        }
        $zip = ZipFactory::make($this->tmp->path('many.zip'), $entries);

        $safe = (new SafeZip(null))->withLimits(10);
        $this->assertThrows(SecurityException::class, static function () use ($safe, $zip) {
            $safe->inspect($zip);
        }, 'too many entries');
    }

    public function testRejectsZipBombByCompressionRatio(): void
    {
        $zip = ZipFactory::bomb($this->tmp->path('bomb.zip'), 5000000);
        $safe = (new SafeZip(null))->withLimits(null, null, null, 50);

        $this->assertThrows(SecurityException::class, static function () use ($safe, $zip) {
            $safe->inspect($zip);
        }, 'zip bomb');
    }

    public function testRejectsArchiveOverTotalSizeLimit(): void
    {
        $zip = ZipFactory::make($this->tmp->path('big.zip'), [
            'a.txt' => str_repeat('a', 200000),
            'b.txt' => str_repeat('b', 200000),
        ]);
        $safe = (new SafeZip(null))->withLimits(null, 250000, null, 100000);

        $this->assertThrows(SecurityException::class, static function () use ($safe, $zip) {
            $safe->inspect($zip);
        }, 'total uncompressed size');
    }

    public function testRejectsUnreadableArchive(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            (new SafeZip())->inspect($this->tmp->path('missing.zip'));
        }, 'not readable');
    }

    public function testRejectsCorruptArchive(): void
    {
        $path = $this->tmp->write('corrupt.zip', 'this is definitely not a zip file');
        $this->assertThrows(SecurityException::class, static function () use ($path) {
            (new SafeZip())->inspect($path);
        });
    }

    public function testReportsMultipleRootsAsNull(): void
    {
        $zip = ZipFactory::make($this->tmp->path('multi.zip'), [
            'one/a.txt' => 'a',
            'two/b.txt' => 'b',
        ]);
        $report = (new SafeZip(null))->inspect($zip);
        $this->assertNull($report['root']);
    }

    public function testDotfilesAreRestrictedToAKnownList(): void
    {
        $ok = ZipFactory::make($this->tmp->path('dot-ok.zip'), ['pkg/.htaccess' => 'deny from all']);
        $this->assertDoesNotThrow(static function () use ($ok) {
            (new SafeZip(SafeZip::CODE_EXTENSIONS))->inspect($ok);
        });

        $bad = ZipFactory::make($this->tmp->path('dot-bad.zip'), ['pkg/.env' => 'SECRET=1']);
        $this->assertThrows(SecurityException::class, static function () use ($bad) {
            (new SafeZip(SafeZip::CODE_EXTENSIONS))->inspect($bad);
        }, 'Dotfile not allowed');
    }
}
