<?php
namespace Wtp\Tests;

use Wtp\Shared\Crypto\CryptoException;
use Wtp\Shared\Crypto\SecretBox;
use Wtp\Shared\Support\Bytes;

final class SecretBoxTest extends TestCase
{
    /** @var SecretBox */
    private $box;
    /** @var string */
    private $key;

    public function setUp(): void
    {
        $this->key = SecretBox::generateMasterKey();
        $this->box = SecretBox::fromEncodedKey($this->key);
    }

    public function testRoundTrip(): void
    {
        $secret = Bytes::token(32);
        $envelope = $this->box->encrypt($secret, 'job-secret');
        $this->assertSame($secret, $this->box->decrypt($envelope, 'job-secret'));
    }

    public function testCiphertextIsNotThePlaintext(): void
    {
        $envelope = $this->box->encrypt('super-secret-value', 'job-secret');
        $this->assertNotContains('super-secret-value', $envelope);
    }

    public function testEncryptionIsNonDeterministic(): void
    {
        $a = $this->box->encrypt('same', 'ctx');
        $b = $this->box->encrypt('same', 'ctx');
        $this->assertFalse($a === $b, 'IV reuse would be a critical flaw');
    }

    public function testWrongContextFails(): void
    {
        $envelope = $this->box->encrypt('value', 'job-secret');
        $this->assertThrows(CryptoException::class, function () use ($envelope) {
            $this->box->decrypt($envelope, 'cpanel-password');
        });
    }

    public function testWrongMasterKeyFails(): void
    {
        $envelope = $this->box->encrypt('value', 'ctx');
        $other = SecretBox::fromEncodedKey(SecretBox::generateMasterKey());
        $this->assertThrows(CryptoException::class, function () use ($other, $envelope) {
            $other->decrypt($envelope, 'ctx');
        });
    }

    public function testTamperedCiphertextFails(): void
    {
        $envelope = $this->box->encrypt('value-to-protect', 'ctx');
        $raw = Bytes::base64UrlDecode($envelope);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
        $tampered = Bytes::base64UrlEncode($raw);

        $this->assertThrows(CryptoException::class, function () use ($tampered) {
            $this->box->decrypt($tampered, 'ctx');
        });
    }

    public function testTruncatedEnvelopeFails(): void
    {
        $this->assertThrows(CryptoException::class, function () {
            $this->box->decrypt(Bytes::base64UrlEncode('WTP1short'), 'ctx');
        });
    }

    public function testShortMasterKeyIsRefused(): void
    {
        $this->assertThrows(CryptoException::class, static function () {
            new SecretBox('too-short');
        }, 'at least 32 bytes');
    }

    public function testEmptyPlaintextRoundTrips(): void
    {
        $envelope = $this->box->encrypt('', 'ctx');
        $this->assertSame('', $this->box->decrypt($envelope, 'ctx'));
    }
}
