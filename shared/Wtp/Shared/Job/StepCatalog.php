<?php
namespace Wtp\Shared\Job;

/**
 * The canonical, ordered list of provisioning steps.
 *
 * `owner` decides which control loop advances the step:
 *   whmcs -> the cron worker on the WHMCS server
 *   agent -> the MU plugin inside the customer's WordPress
 *
 * Adding a step here is all that is needed for it to appear in the admin
 * timeline, the client progress panel, and the retry machinery.
 */
final class StepCatalog
{
    public const ACCOUNT_CREATE      = 'account.create';
    public const ACCOUNT_VERIFY      = 'account.verify';
    public const DATABASE_PROVISION  = 'database.provision';
    public const BOOTSTRAP_DEPLOY    = 'bootstrap.deploy';
    public const WORDPRESS_DOWNLOAD  = 'wordpress.download';
    public const WORDPRESS_INSTALL   = 'wordpress.install';
    public const AGENT_INSTALL       = 'agent.install';
    public const AGENT_HANDSHAKE     = 'agent.handshake';
    public const PLAN_FETCH          = 'plan.fetch';
    public const THEME_INSTALL       = 'theme.install';
    public const PLUGINS_INSTALL     = 'plugins.install';
    public const CONTENT_IMPORT      = 'content.import';
    public const WIDGETS_IMPORT      = 'widgets.import';
    public const CUSTOMIZER_IMPORT   = 'customizer.import';
    public const EXTRAS_IMPORT       = 'extras.import';
    public const SITE_FINALIZE       = 'site.finalize';
    public const JOB_VERIFY          = 'job.verify';

    /**
     * @return array<int,array{key:string,seq:int,owner:string,label:string,timeout:int,max_attempts:int,optional:bool}>
     */
    public static function all(): array
    {
        $rows = [
            [self::ACCOUNT_CREATE,     'whmcs', 'Create hosting account',       180, 3, false],
            [self::ACCOUNT_VERIFY,     'whmcs', 'Verify hosting account',        90, 5, false],
            [self::DATABASE_PROVISION, 'whmcs', 'Provision database',           120, 5, false],
            [self::BOOTSTRAP_DEPLOY,   'whmcs', 'Deploy installer',             180, 5, false],
            [self::WORDPRESS_DOWNLOAD, 'whmcs', 'Download WordPress',           600, 5, false],
            [self::WORDPRESS_INSTALL,  'whmcs', 'Install WordPress',            600, 5, false],
            [self::AGENT_INSTALL,      'whmcs', 'Install provisioning agent',   300, 5, false],
            [self::AGENT_HANDSHAKE,    'agent', 'Agent handshake',              600, 5, false],
            [self::PLAN_FETCH,         'agent', 'Fetch theme &amp; demo plan',  300, 5, false],
            [self::THEME_INSTALL,      'agent', 'Install theme',                900, 5, false],
            [self::PLUGINS_INSTALL,    'agent', 'Install required plugins',     900, 5, true],
            [self::CONTENT_IMPORT,     'agent', 'Import demo content',         3600, 5, true],
            [self::WIDGETS_IMPORT,     'agent', 'Import widgets',               300, 5, true],
            [self::CUSTOMIZER_IMPORT,  'agent', 'Import customizer settings',   300, 5, true],
            [self::EXTRAS_IMPORT,      'agent', 'Import demo extras',           900, 5, true],
            [self::SITE_FINALIZE,      'agent', 'Finalise site',                300, 5, false],
            [self::JOB_VERIFY,         'whmcs', 'Verify site',                  180, 5, false],
        ];

        $out = [];
        $seq = 0;
        foreach ($rows as $r) {
            $out[] = [
                'key' => $r[0],
                'seq' => ++$seq,
                'owner' => $r[1],
                'label' => html_entity_decode($r[2], ENT_QUOTES, 'UTF-8'),
                'timeout' => $r[3],
                'max_attempts' => $r[4],
                'optional' => $r[5],
            ];
        }
        return $out;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** @return array{key:string,seq:int,owner:string,label:string,timeout:int,max_attempts:int,optional:bool}|null */
    public static function get(string $key): ?array
    {
        foreach (self::all() as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }
        return null;
    }

    public static function seq(string $key): int
    {
        $step = self::get($key);
        return $step === null ? PHP_INT_MAX : $step['seq'];
    }

    public static function ownerOf(string $key): string
    {
        $step = self::get($key);
        return $step === null ? 'whmcs' : $step['owner'];
    }

    /** @return string[] every step at or after $key, in order */
    public static function from(string $key): array
    {
        $seq = self::seq($key);
        $out = [];
        foreach (self::all() as $step) {
            if ($step['seq'] >= $seq) {
                $out[] = $step['key'];
            }
        }
        return $out;
    }

    /** The job status a step implies while it is running. */
    public static function statusFor(string $key): string
    {
        switch ($key) {
            case self::AGENT_HANDSHAKE:
            case self::PLAN_FETCH:
                return JobStatus::AWAITING_AGENT;
            case self::THEME_INSTALL:
            case self::PLUGINS_INSTALL:
            case self::CONTENT_IMPORT:
            case self::WIDGETS_IMPORT:
            case self::CUSTOMIZER_IMPORT:
            case self::EXTRAS_IMPORT:
            case self::SITE_FINALIZE:
                return JobStatus::IMPORTING;
            case self::JOB_VERIFY:
                return JobStatus::VERIFYING;
            default:
                return JobStatus::RUNNING;
        }
    }
}
