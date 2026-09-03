<?php
namespace Wtp\Addon\Support;

use Wtp\Shared\Security\Redactor;
use Wtp\Shared\Support\Clock;
use Wtp\Shared\Support\Json;
use Wtp\Shared\Support\Str;

/**
 * Append-only, structured, redacted job log.
 *
 * Everything a human or a support ticket needs to reconstruct what happened to
 * an order goes here — and nothing that would leak a credential if the log were
 * exported. Redaction happens on the way in, not on the way out, so a raw
 * `SELECT * FROM mod_wtp_job_events` is always safe to hand to someone.
 */
final class EventLog
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';

    private const LEVEL_WEIGHT = [
        self::DEBUG => 10,
        self::INFO => 20,
        self::WARNING => 30,
        self::ERROR => 40,
        self::CRITICAL => 50,
    ];

    /** @var Db */
    private $db;
    /** @var Clock */
    private $clock;
    /** @var string */
    private $minLevel;

    public function __construct(Db $db, Clock $clock, string $minLevel = self::INFO)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->minLevel = isset(self::LEVEL_WEIGHT[$minLevel]) ? $minLevel : self::INFO;
    }

    /** @param array<string,mixed> $context */
    public function log(
        int $jobId,
        string $level,
        string $message,
        array $context = [],
        ?string $stepKey = null,
        ?string $code = null,
        string $source = 'whmcs'
    ): void {
        if (!isset(self::LEVEL_WEIGHT[$level])) {
            $level = self::INFO;
        }
        if (self::LEVEL_WEIGHT[$level] < self::LEVEL_WEIGHT[$this->minLevel]) {
            return;
        }

        $safeMessage = Str::limit(Redactor::scrubString($message), 2000);
        $safeContext = $context === [] ? null : Json::encode(Redactor::scrub($context));

        try {
            $this->db->insert('mod_wtp_job_events', [
                'job_id' => $jobId,
                'step_key' => $stepKey !== null ? substr($stepKey, 0, 64) : null,
                'level' => $level,
                'code' => $code !== null ? substr($code, 0, 64) : null,
                'message' => $safeMessage,
                'context' => $safeContext === null ? null : Str::limit($safeContext, 16000),
                'source' => in_array($source, ['whmcs', 'agent', 'bootstrap', 'admin'], true) ? $source : 'whmcs',
                'created_at' => $this->clock->nowSql(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break provisioning. Fall back to PHP's log.
            error_log('[WTP] event log write failed: ' . $e->getMessage() . ' — ' . $safeMessage);
        }
    }

    /** @param array<string,mixed> $context */
    public function debug(int $jobId, string $message, array $context = [], ?string $stepKey = null): void
    {
        $this->log($jobId, self::DEBUG, $message, $context, $stepKey);
    }

    /** @param array<string,mixed> $context */
    public function info(int $jobId, string $message, array $context = [], ?string $stepKey = null): void
    {
        $this->log($jobId, self::INFO, $message, $context, $stepKey);
    }

    /** @param array<string,mixed> $context */
    public function warning(int $jobId, string $message, array $context = [], ?string $stepKey = null, ?string $code = null): void
    {
        $this->log($jobId, self::WARNING, $message, $context, $stepKey, $code);
    }

    /** @param array<string,mixed> $context */
    public function error(int $jobId, string $message, array $context = [], ?string $stepKey = null, ?string $code = null): void
    {
        $this->log($jobId, self::ERROR, $message, $context, $stepKey, $code);
    }

    /** @param array<string,mixed> $context */
    public function critical(int $jobId, string $message, array $context = [], ?string $stepKey = null, ?string $code = null): void
    {
        $this->log($jobId, self::CRITICAL, $message, $context, $stepKey, $code);
    }

    /** @return array<int,array<string,mixed>> */
    public function forJob(int $jobId, int $limit = 500, ?string $minLevel = null): array
    {
        $sql = 'SELECT * FROM mod_wtp_job_events WHERE job_id = :job';
        $bind = ['job' => $jobId];

        if ($minLevel !== null && isset(self::LEVEL_WEIGHT[$minLevel])) {
            $allowed = [];
            foreach (self::LEVEL_WEIGHT as $name => $weight) {
                if ($weight >= self::LEVEL_WEIGHT[$minLevel]) {
                    $allowed[] = $name;
                }
            }
            $placeholders = [];
            foreach ($allowed as $i => $name) {
                $placeholders[] = ':lvl' . $i;
                $bind['lvl' . $i] = $name;
            }
            $sql .= ' AND level IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(5000, $limit));
        return $this->db->select($sql, $bind);
    }

    /** Housekeeping, called by the worker. */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', $this->clock->now() - ($days * 86400));
        return $this->db->execute(
            'DELETE FROM mod_wtp_job_events WHERE created_at < :cutoff',
            ['cutoff' => $cutoff]
        );
    }
}
