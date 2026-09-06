<?php
/**
 * Agent logging.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Wtp\Shared\Security\Redactor;

/**
 * Buffers structured events and ships them to WHMCS in batches.
 *
 * Batching matters: an import can emit hundreds of lines, and one HTTP round
 * trip per line would take longer than the import itself. Everything is
 * redacted here as well as on the server, because a log line that never leaves
 * the site (the WP debug log) deserves the same treatment.
 */
final class Wtp_Logger
{
    private const MAX_BUFFER = 40;

    /** @var array<int,array<string,mixed>> */
    private $buffer = [];
    /** @var Wtp_Api_Client|null set after the handshake */
    private $client;
    /** @var string|null */
    private $step;
    /** @var bool */
    private $debug;

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug || (defined('WP_DEBUG') && WP_DEBUG);
    }

    public function attach(Wtp_Api_Client $client): void
    {
        $this->client = $client;
    }

    public function forStep(?string $stepKey): void
    {
        $this->step = $stepKey;
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->record('debug', $message, $context);
        }
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->record('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = [], ?string $code = null): void
    {
        $this->record('warning', $message, $context, $code);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = [], ?string $code = null): void
    {
        $this->record('error', $message, $context, $code);
        $this->flush();
    }

    /** @param array<string,mixed> $context */
    private function record(string $level, string $message, array $context, ?string $code = null): void
    {
        $entry = [
            'level' => $level,
            'message' => Redactor::scrubString($message),
            'context' => Redactor::scrub($context),
            'step' => $this->step,
            'code' => $code,
            'at' => gmdate('c'),
        ];

        $this->buffer[] = $entry;

        if ($this->debug) {
            error_log('[WTP][' . $level . '] ' . $entry['message']);
        }
        if (count($this->buffer) >= self::MAX_BUFFER) {
            $this->flush();
        }
    }

    /** Ship whatever is buffered. Never throws: logging must not break a job. */
    public function flush(): void
    {
        if ($this->buffer === [] || $this->client === null) {
            return;
        }
        $batch = $this->buffer;
        $this->buffer = [];

        try {
            $this->client->sendEvents($batch);
        } catch (Throwable $e) {
            // Put them back so the next flush can try again, but do not grow
            // without bound if WHMCS stays unreachable.
            $this->buffer = array_slice(array_merge($batch, $this->buffer), -self::MAX_BUFFER);
            error_log('[WTP] could not ship agent events: ' . $e->getMessage());
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function buffered(): array
    {
        return $this->buffer;
    }
}
