<?php
namespace Wtp\Addon\Provision;

use Wtp\Addon\Job\JobSecrets;
use Wtp\Addon\Panel\PanelException;
use Wtp\Shared\Job\StepCatalog;

/**
 * Creates the site database, its user and the grant between them.
 *
 * The credentials are generated once and remembered (encrypted) in the job
 * payload, so a retry reuses the same database rather than leaving orphaned
 * ones behind on the customer's account.
 */
final class DatabaseHandler implements StepHandler
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
        return [StepCatalog::DATABASE_PROVISION];
    }

    public function execute(StepContext $context): StepOutcome
    {
        $payload = $context->payload();
        $account = (string) ($payload['cpanel']['username'] ?? '');
        if ($account === '') {
            return StepOutcome::failed('account_username_missing', 'No hosting username recorded for this job');
        }

        $existing = isset($payload['db']) && is_array($payload['db']) ? $payload['db'] : [];

        if (!empty($existing['name']) && !empty($existing['user']) && !empty($existing['password_enc'])) {
            $identifiers = [
                'database' => (string) $existing['name'],
                'user' => (string) $existing['user'],
                'password' => $this->secrets->tryOpen((string) $existing['password_enc'], 'db'),
            ];
            if ($identifiers['password'] === '') {
                return StepOutcome::failed(
                    'master_key_missing',
                    'The stored database password cannot be decrypted with the current master key'
                );
            }
        } else {
            $identifiers = AccountHandler::databaseIdentifiers($account, $context->jobUuid());
        }

        try {
            $context->panel()->provisionDatabase(
                $account,
                $identifiers['database'],
                $identifiers['user'],
                $identifiers['password']
            );
        } catch (PanelException $e) {
            return StepOutcome::failed($e->errorCode(), $e->getMessage());
        }

        $context->mergePayload([
            'db' => [
                'host' => 'localhost',
                'name' => $identifiers['database'],
                'user' => $identifiers['user'],
                'password_enc' => $this->secrets->seal($identifiers['password'], 'db'),
                'prefix' => (string) ($existing['prefix'] ?? ('wp' . substr(bin2hex(random_bytes(2)), 0, 3) . '_')),
            ],
        ]);

        $context->log()->info($context->jobId(), 'Provisioned the site database', [
            'database' => $identifiers['database'],
            'user' => $identifiers['user'],
        ], $context->stepKey());

        return StepOutcome::completed([
            'database' => $identifiers['database'],
            'user' => $identifiers['user'],
        ]);
    }
}
