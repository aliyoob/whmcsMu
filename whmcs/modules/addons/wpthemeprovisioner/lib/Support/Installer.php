<?php
namespace Wtp\Addon\Support;

use Throwable;
use Wtp\Shared\Support\Clock;

/**
 * Schema installation and upgrades.
 *
 * Migrations are plain .sql files applied in filename order and recorded in
 * mod_wtp_settings, so re-activating the addon is a no-op and an upgrade only
 * runs what is new. Every statement is `CREATE TABLE IF NOT EXISTS` or an
 * additive ALTER: activating this addon never destroys data.
 */
final class Installer
{
    public const SCHEMA_KEY = 'schema_version';

    /** @var Db */
    private $db;
    /** @var Clock */
    private $clock;
    /** @var string */
    private $migrationsDir;

    public function __construct(Db $db, Clock $clock, string $migrationsDir)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->migrationsDir = rtrim($migrationsDir, '/');
    }

    /**
     * @return array{applied:string[],skipped:string[],errors:string[]}
     */
    public function migrate(): array
    {
        $result = ['applied' => [], 'skipped' => [], 'errors' => []];

        $files = glob($this->migrationsDir . '/*.sql');
        if ($files === false || $files === []) {
            $result['errors'][] = 'No migrations found in ' . $this->migrationsDir;
            return $result;
        }
        sort($files);

        $this->ensureSettingsTable();
        $applied = $this->appliedMigrations();

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                $result['skipped'][] = $name;
                continue;
            }

            $sql = (string) file_get_contents($file);
            try {
                foreach ($this->splitStatements($sql) as $statement) {
                    $this->db->execute($statement);
                }
                $applied[] = $name;
                $result['applied'][] = $name;
            } catch (Throwable $e) {
                $result['errors'][] = $name . ': ' . $e->getMessage();
                break; // stop at the first failure; the rest may depend on it
            }
        }

        $this->recordApplied($applied);
        return $result;
    }

    /** Values the addon needs before an operator has configured anything. */
    public function seedDefaults(): void
    {
        $this->ensureSettingsTable();
        $defaults = [
            'agent_package' => 'agent/whmcs-theme-provisioner.zip',
            'wp_core_source' => 'mirror',
            'wp_version' => 'latest',
        ];
        foreach ($defaults as $key => $value) {
            $existing = $this->db->selectOne('SELECT k FROM mod_wtp_settings WHERE k = :k', ['k' => $key]);
            if ($existing === null) {
                $this->db->insert('mod_wtp_settings', [
                    'k' => $key,
                    'v' => $value,
                    'updated_at' => $this->clock->nowSql(),
                ]);
            }
        }
    }

    /** @return string[] */
    public function appliedMigrations(): array
    {
        $row = $this->db->selectOne('SELECT v FROM mod_wtp_settings WHERE k = :k', ['k' => self::SCHEMA_KEY]);
        if ($row === null || $row['v'] === null || $row['v'] === '') {
            return [];
        }
        $decoded = json_decode((string) $row['v'], true);
        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    /** @return string[] the tables this addon owns */
    public static function tables(): array
    {
        return [
            'mod_wtp_themes', 'mod_wtp_theme_versions', 'mod_wtp_demos', 'mod_wtp_demo_assets',
            'mod_wtp_product_settings', 'mod_wtp_service_selection', 'mod_wtp_jobs',
            'mod_wtp_job_steps', 'mod_wtp_job_events', 'mod_wtp_api_nonces',
            'mod_wtp_idempotency', 'mod_wtp_rate_limits', 'mod_wtp_download_grants',
            'mod_wtp_settings',
        ];
    }

    /** @return array<string,bool> */
    public function tableStatus(): array
    {
        $status = [];
        foreach (self::tables() as $table) {
            $status[$table] = $this->db->tableExists($table);
        }
        return $status;
    }

    /**
     * Deactivation deliberately leaves the tables alone. A customer's site is
     * already provisioned from this data; dropping it because an operator
     * clicked "Deactivate" would destroy the audit trail for live services.
     */
    public function isFullyInstalled(): bool
    {
        foreach ($this->tableStatus() as $exists) {
            if (!$exists) {
                return false;
            }
        }
        return true;
    }

    private function ensureSettingsTable(): void
    {
        if ($this->db->tableExists('mod_wtp_settings')) {
            return;
        }
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS `mod_wtp_settings` (
                `k` VARCHAR(96) NOT NULL,
                `v` LONGTEXT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`k`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @param string[] $applied */
    private function recordApplied(array $applied): void
    {
        $value = json_encode(array_values(array_unique($applied)));
        $affected = $this->db->update(
            'mod_wtp_settings',
            ['v' => $value, 'updated_at' => $this->clock->nowSql()],
            ['k' => self::SCHEMA_KEY]
        );
        if ($affected === 0) {
            $this->db->insert('mod_wtp_settings', [
                'k' => self::SCHEMA_KEY,
                'v' => $value,
                'updated_at' => $this->clock->nowSql(),
            ]);
        }
    }

    /**
     * Split a migration into statements.
     *
     * Deliberately simple: these files are ours, contain no stored procedures
     * and no semicolons inside string literals. Anything more clever would be
     * a parser we do not need.
     *
     * @return string[]
     */
    public function splitStatements(string $sql): array
    {
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql);
        $parts = explode(';', (string) $withoutComments);

        $statements = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
        }
        return $statements;
    }
}
