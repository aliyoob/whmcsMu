<?php
/**
 * Concurrency control for the agent.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prevents two ticks running at once.
 *
 * Uses MySQL's advisory GET_LOCK where available — it is genuinely atomic and
 * releases itself when the connection dies, which matters when PHP is killed
 * mid-import. Falls back to an option-based lease with an expiry, acquired
 * through the uniqueness of `add_option`, which is the only atomic primitive
 * WordPress offers without a real object cache.
 */
final class Wtp_Lock
{
    public const OPTION = 'wtp_agent_lock';

    /** @var string */
    private $name;
    /** @var bool */
    private $usingDbLock = false;
    /** @var bool */
    private $held = false;

    public function __construct(string $name = 'wtp_agent')
    {
        // The lock name is namespaced by database so two sites sharing a MySQL
        // server never block each other.
        global $wpdb;
        $suffix = isset($wpdb->dbname) ? substr(md5((string) $wpdb->dbname . DB_NAME), 0, 8) : 'default';
        $this->name = $name . '_' . $suffix;
    }

    public function acquire(int $ttlSeconds = 120): bool
    {
        global $wpdb;

        if ($wpdb instanceof wpdb) {
            $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->name));
            if ($acquired !== null) {
                $this->usingDbLock = true;
                $this->held = ((int) $acquired === 1);
                return $this->held;
            }
        }

        // Fallback: an option that only one caller can create.
        $expires = time() + max(30, $ttlSeconds);
        if (add_option(self::OPTION, (string) $expires, '', 'no')) {
            $this->held = true;
            return true;
        }

        $current = (int) get_option(self::OPTION, 0);
        if ($current > 0 && $current < time()) {
            // A previous holder died. Reclaim it.
            update_option(self::OPTION, (string) $expires, false);
            $this->held = true;
            return true;
        }

        return false;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        global $wpdb;

        if ($this->usingDbLock && $wpdb instanceof wpdb) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->name));
        } else {
            delete_option(self::OPTION);
        }
        $this->held = false;
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    public function __destruct()
    {
        $this->release();
    }
}
