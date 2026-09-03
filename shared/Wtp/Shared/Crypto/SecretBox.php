<?php
namespace Wtp\Shared\Crypto;

use Wtp\Shared\Support\Bytes;

/**
 * Authenticated encryption for secrets at rest (agent secrets, cPanel credentials).
 *
 * Format:  "WTP1" || iv(12) || tag(16) || ciphertext        (base64url encoded)
 * Cipher:  AES-256-GCM, key = HKDF-SHA256(masterKey, info=$context)
 *
 * The master key lives outside the web root (see docs/security.md) and is never
 * committed. Deriving a per-context subkey means a leak of one ciphertext class
 * cannot be decrypted with another context's key material.
 */
final class SecretBox
{
    private const MAGIC = 'WTP1';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    /** @var string raw 32-byte master key */
    private $masterKey;

    public function __construct(string $masterKey)
    {
        if (strlen($masterKey) < 32) {
            throw new CryptoException('WTP master key must be at least 32 bytes of raw entropy');
        }
        $this->masterKey = $masterKey;
    }

    /** Build from the base64url form stored in the environment file. */
    public static function fromEncodedKey(string $encoded): self
    {
        $raw = Bytes::base64UrlDecode(trim($encoded));
        return new self($raw);
    }

    /** Generate a fresh master key for `setup-whmcs.sh` / `bin/wtp-keygen.php`. */
    public static function generateMasterKey(): string
    {
        return Bytes::base64UrlEncode(Bytes::random(32));
    }

    public function encrypt(string $plaintext, string $context = 'default'): string
    {
        $key = $this->deriveKey($context);
        $iv = Bytes::random(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::MAGIC . '|' . $context,
            self::TAG_LEN
        );
        if ($cipher === false) {
            throw new CryptoException('Encryption failed');
        }
        return Bytes::base64UrlEncode(self::MAGIC . $iv . $tag . $cipher);
    }

    public function decrypt(string $envelope, string $context = 'default'): string
    {
        $raw = Bytes::base64UrlDecode($envelope);
        $magicLen = strlen(self::MAGIC);
        $min = $magicLen + self::IV_LEN + self::TAG_LEN;
        // '<' not '<=': an empty plaintext produces an empty ciphertext body.
        if (strlen($raw) < $min || substr($raw, 0, $magicLen) !== self::MAGIC) {
            throw new CryptoException('Malformed ciphertext envelope');
        }
        $iv = substr($raw, $magicLen, self::IV_LEN);
        $tag = substr($raw, $magicLen + self::IV_LEN, self::TAG_LEN);
        $cipher = substr($raw, $min);

        $plain = openssl_decrypt(
            $cipher,
            self::CIPHER,
            $this->deriveKey($context),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::MAGIC . '|' . $context
        );
        if ($plain === false) {
            // Wrong key, wrong context, or tampering — all indistinguishable on purpose.
            throw new CryptoException('Decryption failed (bad key, context or tampered data)');
        }
        return $plain;
    }

    /**
     * Derive a deterministic sub-key for a purpose that has no storage of its
     * own — currently the per-job download-URL signing key, which must exist
     * before the agent has a secret and must survive that secret's rotation.
     *
     * @return string raw bytes
     */
    public function derive(string $info, int $length = 32): string
    {
        return hash_hkdf('sha256', $this->masterKey, $length, 'wtp-derive/' . $info, 'wtp-secretbox-v1');
    }

    private function deriveKey(string $context): string
    {
        // HKDF-SHA256 with a fixed salt; the master key is already full-entropy.
        return hash_hkdf('sha256', $this->masterKey, 32, 'wtp/' . $context, 'wtp-secretbox-v1');
    }
}
