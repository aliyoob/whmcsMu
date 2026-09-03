<?php
namespace Wtp\Tests;

use Wtp\Shared\Security\Redactor;

final class RedactorTest extends TestCase
{
    public function testMasksSensitiveArrayKeys(): void
    {
        $out = Redactor::scrub([
            'username' => 'acme01',
            'password' => 'hunter2-very-secret',
            'server_password' => 'root-pw',
            'nested' => ['api_key' => 'abcdef123456', 'domain' => 'example.com'],
        ]);

        $this->assertSame('acme01', $out['username']);
        $this->assertSame('example.com', $out['nested']['domain']);
        $this->assertNotContains('hunter2', json_encode($out));
        $this->assertNotContains('root-pw', json_encode($out));
        $this->assertNotContains('abcdef123456', json_encode($out));
    }

    public function testMaskIsStableForCorrelation(): void
    {
        $a = Redactor::mask('same-secret');
        $b = Redactor::mask('same-secret');
        $c = Redactor::mask('other-secret');
        $this->assertSame($a, $b);
        $this->assertFalse($a === $c);
    }

    public function testMasksAuthorizationHeaders(): void
    {
        $line = 'GET /v1/job  Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature';
        $out = Redactor::scrubString($line);
        $this->assertNotContains('eyJhbGciOiJIUzI1NiJ9', $out);
        $this->assertContains('[redacted]', $out);
    }

    public function testMasksBootstrapScheme(): void
    {
        $out = Redactor::scrubString('Authorization: WTP-Bootstrap 1234-uuid.aVeryLongBootstrapTokenValue123456');
        $this->assertNotContains('aVeryLongBootstrapTokenValue123456', $out);
    }

    public function testMasksSignatureAndTokenParameters(): void
    {
        $out = Redactor::scrubString('https://b.example/dl?job=x&grant=1&exp=2&sig=' . str_repeat('a', 64));
        $this->assertNotContains(str_repeat('a', 64), $out);
    }

    public function testKeepsSha256ChecksumsVisible(): void
    {
        $checksum = hash('sha256', 'theme.zip');
        $out = Redactor::scrubString('verified sha256 ' . $checksum);
        $this->assertContains($checksum, $out, 'integrity values are public, not secrets');
    }

    public function testMasksBareHighEntropyBlobs(): void
    {
        $secret = 'Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MFFXRVJUWXVpb3A';
        $out = Redactor::scrubString('agent secret is ' . $secret . ' ok');
        $this->assertNotContains($secret, $out);
    }

    public function testLeavesOrdinaryProseAlone(): void
    {
        $msg = 'Theme astra 4.6.1 installed for example.com in 12.4s';
        $this->assertSame($msg, Redactor::scrubString($msg));
    }

    public function testObjectsAreNotSerialisedIntoLogs(): void
    {
        $out = Redactor::scrub(['obj' => new \stdClass()]);
        $this->assertContains('[object:stdClass]', (string) $out['obj']);
    }

    public function testDepthIsBounded(): void
    {
        $deep = 'leaf';
        for ($i = 0; $i < 40; $i++) {
            $deep = ['next' => $deep];
        }
        $this->assertDoesNotThrow(static function () use ($deep) {
            Redactor::scrub($deep);
        });
    }
}
