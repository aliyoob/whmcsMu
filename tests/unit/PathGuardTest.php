<?php
namespace Wtp\Tests;

use Wtp\Shared\Security\PathGuard;
use Wtp\Shared\Security\SecurityException;

final class PathGuardTest extends TestCase
{
    /** @var TempDir */
    private $tmp;
    /** @var PathGuard */
    private $guard;

    public function setUp(): void
    {
        $this->tmp = new TempDir('wtp-path-');
        $this->tmp->mkdir('base');
        $this->guard = new PathGuard($this->tmp->path('base'));
    }

    public function tearDown(): void
    {
        $this->tmp->cleanup();
    }

    public function testResolvesASimpleRelativePath(): void
    {
        $p = $this->guard->resolve('themes/astra/style.css');
        $this->assertContains('/base/themes/astra/style.css', $p);
        $this->assertTrue($this->guard->isWithin(dirname($p)));
    }

    public function testCollapsesRedundantSegments(): void
    {
        $p = $this->guard->resolve('./a//b/./c.txt');
        $this->assertSame($this->guard->base() . '/a/b/c.txt', $p);
    }

    public function testRejectsTraversal(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            $this->guard->resolve('../outside.txt');
        }, 'traversal');
    }

    public function testRejectsDeepTraversalThatNetsOut(): void
    {
        // a/../../x escapes even though it contains a real first segment
        $this->assertThrows(SecurityException::class, function () {
            $this->guard->resolve('a/../../x');
        });
    }

    public function testAllowsTraversalThatStaysInside(): void
    {
        $p = $this->guard->resolve('a/b/../c.txt');
        $this->assertSame($this->guard->base() . '/a/c.txt', $p);
    }

    public function testRejectsAbsolutePaths(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            $this->guard->resolve('/etc/passwd');
        }, 'Absolute');
    }

    public function testRejectsNullBytes(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            $this->guard->resolve("ok.txt\0.php");
        }, 'Null byte');
    }

    public function testRejectsEmptyPath(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            $this->guard->resolve('');
        });
    }

    public function testRejectsSymlinkedAncestorEscapingTheBase(): void
    {
        $this->tmp->mkdir('elsewhere');
        symlink($this->tmp->path('elsewhere'), $this->tmp->path('base/link'));

        $this->assertThrows(SecurityException::class, function () {
            $this->guard->resolve('link/evil.txt');
        }, 'escapes');
    }

    public function testMissingBaseDirectoryIsRefused(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            new PathGuard($this->tmp->path('does-not-exist'));
        });
    }

    public function testNormalizeReturnsNullOnEscape(): void
    {
        $this->assertNull(PathGuard::normalize('../x'));
        $this->assertNull(PathGuard::normalize('a/../../x'));
        $this->assertNull(PathGuard::normalize('.'));
        $this->assertSame('a/b', PathGuard::normalize('a/./b'));
    }

    public function testIsWithinRejectsSiblingPrefix(): void
    {
        // "/tmp/base-evil" must not count as inside "/tmp/base"
        $this->assertFalse($this->guard->isWithin($this->guard->base() . '-evil'));
        $this->assertTrue($this->guard->isWithin($this->guard->base()));
    }
}
