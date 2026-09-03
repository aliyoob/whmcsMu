<?php
namespace Wtp\Tests;

use Wtp\Shared\Security\SecurityException;
use Wtp\Shared\Security\UrlGuard;

final class UrlGuardTest extends TestCase
{
    /** DNS resolution is disabled so the suite never touches the network. */
    private function guard(array $schemes = ['https'], ?array $hosts = null): UrlGuard
    {
        return new UrlGuard($schemes, $hosts, false);
    }

    public function testAcceptsAPlainHttpsUrl(): void
    {
        $r = $this->guard()->validate('https://downloads.wordpress.org/theme/astra.zip');
        $this->assertSame('downloads.wordpress.org', $r['host']);
        $this->assertSame(443, $r['port']);
    }

    public function testRejectsHttpWhenOnlyHttpsIsAllowed(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            $this->guard()->validate('http://example.com/x.zip');
        }, 'Scheme not allowed');
    }

    public function testRejectsNonHttpSchemes(): void
    {
        foreach (['file:///etc/passwd', 'gopher://x/', 'ftp://x/', 'php://input'] as $url) {
            $this->assertThrows(SecurityException::class, function () use ($url) {
                $this->guard(['https', 'http'])->validate($url);
            });
        }
    }

    public function testRejectsCredentialsInUrl(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            $this->guard()->validate('https://user:pass@example.com/x');
        }, 'Credentials');
    }

    public function testRejectsLoopbackLiteral(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            $this->guard()->validate('https://127.0.0.1/admin');
        }, 'non-routable');
    }

    public function testRejectsPrivateRangeLiterals(): void
    {
        foreach (['10.0.0.5', '192.168.1.1', '172.16.9.9', '169.254.169.254', '100.64.0.1'] as $ip) {
            $this->assertThrows(SecurityException::class, function () use ($ip) {
                $this->guard()->validate('https://' . $ip . '/');
            }, 'non-routable');
        }
    }

    public function testRejectsIpv6LoopbackAndUniqueLocal(): void
    {
        foreach (['[::1]', '[fd00::1]', '[fe80::1]'] as $host) {
            $this->assertThrows(SecurityException::class, function () use ($host) {
                $this->guard()->validate('https://' . $host . '/');
            });
        }
    }

    public function testRejectsIpv4MappedIpv6Loopback(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            $this->guard()->validate('https://[::ffff:127.0.0.1]/');
        });
    }

    public function testAcceptsPublicIpLiteral(): void
    {
        $r = $this->guard()->validate('https://93.184.216.34/file.zip');
        $this->assertSame('93.184.216.34', $r['host']);
    }

    public function testHostAllowListIsEnforced(): void
    {
        $g = $this->guard(['https'], ['downloads.wordpress.org']);
        $this->assertDoesNotThrow(static function () use ($g) {
            $g->validate('https://downloads.wordpress.org/x.zip');
        });
        $this->assertThrows(SecurityException::class, static function () use ($g) {
            $g->validate('https://evil.example/x.zip');
        }, 'allow-list');
    }

    public function testRejectsControlCharacters(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            $this->guard()->validate("https://example.com/\r\nHost: evil");
        });
    }

    public function testRejectsOverlongUrl(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            $this->guard()->validate('https://example.com/' . str_repeat('a', 3000));
        });
    }

    public function testRelativeRedirectIsResolvedAgainstOrigin(): void
    {
        $r = $this->guard()->validateRedirect('/downloads/file.zip', 'https://cdn.example.com/a/b');
        $this->assertSame('cdn.example.com', $r['host']);
    }

    public function testAbsoluteRedirectToPrivateAddressIsRejected(): void
    {
        $this->assertThrows(SecurityException::class, function () {
            $this->guard()->validateRedirect('https://169.254.169.254/latest/meta-data/', 'https://cdn.example.com/a');
        });
    }

    public function testIsPublicIpClassification(): void
    {
        $this->assertTrue(UrlGuard::isPublicIp('8.8.8.8'));
        $this->assertTrue(UrlGuard::isPublicIp('2606:4700:4700::1111'));
        $this->assertFalse(UrlGuard::isPublicIp('0.0.0.0'));
        $this->assertFalse(UrlGuard::isPublicIp('::'));
        $this->assertFalse(UrlGuard::isPublicIp('not-an-ip'));
        $this->assertFalse(UrlGuard::isPublicIp('ff02::1'));
    }
}
