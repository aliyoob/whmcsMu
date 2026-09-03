<?php
namespace Wtp\Addon\Provision;

use Wtp\Addon\Job\JobSecrets;
use Wtp\Addon\Panel\PanelException;
use Wtp\Shared\Job\StepCatalog;
use Wtp\Shared\Support\Bytes;

/**
 * Creates and verifies the hosting account.
 *
 * `account.create` is idempotent at two levels: the panel adopts an account
 * that already exists, and the resulting facts (username, document root, IP)
 * are written into the job payload so later steps never have to ask again.
 */
final class AccountHandler implements StepHandler
{
    /** @var JobSecrets */
    private $secrets;

    public function __construct(JobSecrets $secrets)
    {
        $this->secrets = $secrets;
    }

    /** @return string[] */
    public function handles(): array
    {
        return [StepCatalog::ACCOUNT_CREATE, StepCatalog::ACCOUNT_VERIFY];
    }

    public function execute(StepContext $context): StepOutcome
    {
        return $context->stepKey() === StepCatalog::ACCOUNT_CREATE
            ? $this->create($context)
            : $this->verify($context);
    }

    private function create(StepContext $context): StepOutcome
    {
        $payload = $context->payload();
        $username = (string) ($payload['cpanel']['username'] ?? '');
        $domain = $context->domain();

        if ($username === '') {
            return StepOutcome::failed('account_username_missing', 'WHMCS did not supply a hosting username');
        }
        if ($domain === '') {
            return StepOutcome::failed('domain_invalid', 'The service has no domain');
        }

        $password = $this->secrets->tryOpen(
            $payload['cpanel']['password_enc'] ?? null,
            'cpanel',
            ''
        );
        if ($password === '') {
            return StepOutcome::failed(
                'account_password_missing',
                'The hosting password could not be recovered; re-run provisioning from the service'
            );
        }

        try {
            $account = $context->panel()->createAccount([
                'username' => $username,
                'domain' => $domain,
                'password' => $password,
                'plan' => (string) ($payload['cpanel']['plan'] ?? ''),
                'contactemail' => (string) ($payload['client']['email'] ?? ''),
                'ip' => (string) ($payload['cpanel']['ip'] ?? ''),
                'locale' => (string) ($payload['site']['language'] ?? ''),
            ]);
        } catch (PanelException $e) {
            return StepOutcome::failed($e->errorCode(), $e->getMessage());
        }

        $context->mergePayload([
            'cpanel' => array_merge(
                is_array($payload['cpanel'] ?? null) ? $payload['cpanel'] : [],
                [
                    'username' => $account['username'],
                    'docroot' => $account['docroot'],
                    'ip' => $account['ip'],
                    'domain' => $account['domain'],
                ]
            ),
        ]);

        $context->log()->info($context->jobId(), $account['created']
            ? 'Created the hosting account'
            : 'Adopted an existing hosting account', [
                'username' => $account['username'],
                'domain' => $account['domain'],
                'ip' => $account['ip'],
                'docroot' => $account['docroot'],
            ], $context->stepKey());

        return StepOutcome::completed([
            'username' => $account['username'],
            'created' => $account['created'],
            'ip' => $account['ip'],
        ]);
    }

    /**
     * Confirms the account is really usable before we start writing files into
     * it. Provisioning a WordPress into a suspended or over-quota account
     * produces a broken site and a support ticket.
     */
    private function verify(StepContext $context): StepOutcome
    {
        $username = (string) $context->payloadValue('cpanel.username', '');
        if ($username === '') {
            return StepOutcome::failed('account_username_missing', 'No hosting username recorded for this job');
        }

        try {
            $summary = $context->panel()->accountSummary($username);
        } catch (PanelException $e) {
            // The panel can lag behind createacct by a few seconds.
            if ($context->attempt() < 3) {
                return StepOutcome::waiting(15, 'Waiting for the panel to report the new account');
            }
            return StepOutcome::failed($e->errorCode(), $e->getMessage());
        }

        if ($summary['suspended']) {
            return StepOutcome::failed('account_suspended', 'The hosting account is suspended');
        }
        if ($summary['docroot'] === '') {
            return StepOutcome::failed('docroot_missing', 'The panel did not report a document root');
        }
        if ($summary['disk_limit'] > 0 && $summary['disk_used'] >= $summary['disk_limit']) {
            return StepOutcome::failed('disk_quota_exceeded', 'The account is already at its disk quota');
        }

        $context->mergePayload([
            'cpanel' => array_merge(
                is_array($context->payload()['cpanel'] ?? null) ? $context->payload()['cpanel'] : [],
                [
                    'docroot' => $summary['docroot'],
                    'ip' => $summary['ip'] !== '' ? $summary['ip'] : (string) $context->payloadValue('cpanel.ip', ''),
                ]
            ),
        ]);

        return StepOutcome::completed([
            'docroot' => $summary['docroot'],
            'disk_used' => $summary['disk_used'],
            'disk_limit' => $summary['disk_limit'],
        ]);
    }

    /**
     * Build the database identifiers cPanel will accept. cPanel prefixes both
     * with the account name and truncates aggressively, so the names are kept
     * short and deterministic per job.
     *
     * @return array{database:string,user:string,password:string}
     */
    public static function databaseIdentifiers(string $account, string $jobUuid): array
    {
        $suffix = substr(str_replace('-', '', $jobUuid), 0, 6);
        $prefix = substr(preg_replace('/[^a-z0-9]/', '', strtolower($account)) ?? 'wp', 0, 8);

        return [
            'database' => $prefix . '_wp' . $suffix,
            'user' => $prefix . '_u' . $suffix,
            'password' => Bytes::token(18),
        ];
    }
}
