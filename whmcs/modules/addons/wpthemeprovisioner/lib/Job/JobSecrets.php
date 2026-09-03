<?php
namespace Wtp\Addon\Job;

use Wtp\Shared\Crypto\CryptoException;
use Wtp\Shared\Crypto\SecretBox;

/**
 * Encrypts the operational secrets a job has to remember: the site database
 * password, the WordPress administrator password and the installer's run token.
 *
 * These live in the job payload, which is a plain LONGTEXT column that support
 * staff and backups can read — so nothing sensitive goes in unencrypted, and
 * every value is bound to its own context string.
 */
final class JobSecrets
{
    public const CONTEXT = 'job-payload-secret';

    /** @var SecretBox */
    private $box;

    public function __construct(SecretBox $box)
    {
        $this->box = $box;
    }

    public function seal(string $plaintext, string $label): string
    {
        return $this->box->encrypt($plaintext, self::CONTEXT . ':' . $label);
    }

    /** @throws CryptoException when the payload was written under a different master key */
    public function open(string $envelope, string $label): string
    {
        return $this->box->decrypt($envelope, self::CONTEXT . ':' . $label);
    }

    public function tryOpen(?string $envelope, string $label, string $default = ''): string
    {
        if ($envelope === null || $envelope === '') {
            return $default;
        }
        try {
            return $this->open($envelope, $label);
        } catch (CryptoException $e) {
            return $default;
        }
    }
}
