-- WTP 003 : provisioning jobs, steps and the append-only event log.

CREATE TABLE IF NOT EXISTS `mod_wtp_jobs` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`                  CHAR(36) NOT NULL,          -- provisioning_job_id
  `service_id`            INT UNSIGNED NOT NULL,
  `order_id`              INT UNSIGNED NULL,
  `client_id`             INT UNSIGNED NULL,
  `product_id`            INT UNSIGNED NULL,
  `server_id`             INT UNSIGNED NULL,
  `domain`                VARCHAR(191) NOT NULL,
  `status`                ENUM('pending','running','awaiting_agent','importing','verifying',
                              'completed','failed','cancelled','needs_attention')
                          NOT NULL DEFAULT 'pending',
  `phase`                 VARCHAR(64) NOT NULL DEFAULT 'account.create',
  `attempt`               INT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts`          INT UNSIGNED NOT NULL DEFAULT 5,
  -- agent credentials (never stored in clear)
  `secret_ciphertext`     BLOB NULL,
  `secret_fingerprint`    CHAR(16) NULL,              -- first 8 bytes of sha256(secret), for logs
  `secret_issued_at`      DATETIME NULL,
  `secret_expires_at`     DATETIME NULL,
  `secret_revoked_at`     DATETIME NULL,
  `bootstrap_token_hash`  CHAR(64) NULL,
  `bootstrap_expires_at`  DATETIME NULL,
  `bootstrap_used_at`     DATETIME NULL,
  `bootstrap_file`        VARCHAR(191) NULL,          -- randomised dropper filename
  `bootstrap_runs`        INT UNSIGNED NOT NULL DEFAULT 0,
  `payload`               LONGTEXT NULL,              -- immutable snapshot of the plan inputs
  `result`                LONGTEXT NULL,              -- site url, admin url, importer summary
  `last_error_code`       VARCHAR(64) NULL,
  `last_error`            TEXT NULL,
  `scheduled_at`          DATETIME NULL,              -- next worker pickup
  `started_at`            DATETIME NULL,
  `heartbeat_at`          DATETIME NULL,
  `completed_at`          DATETIME NULL,
  `created_at`            DATETIME NOT NULL,
  `updated_at`            DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wtp_job_uuid` (`uuid`),
  KEY `ix_wtp_job_queue` (`status`,`scheduled_at`),
  KEY `ix_wtp_job_service` (`service_id`,`status`),
  KEY `ix_wtp_job_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mod_wtp_job_steps` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`          INT UNSIGNED NOT NULL,
  `step_key`        VARCHAR(64) NOT NULL,
  `seq`             INT UNSIGNED NOT NULL,
  `owner`           ENUM('whmcs','agent') NOT NULL DEFAULT 'whmcs',
  `status`          ENUM('pending','running','completed','failed','skipped')
                    NOT NULL DEFAULT 'pending',
  `attempt`         INT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts`    INT UNSIGNED NOT NULL DEFAULT 5,
  `timeout_seconds` INT UNSIGNED NOT NULL DEFAULT 300,
  `progress`        TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0..100
  `cursor`          LONGTEXT NULL,                          -- opaque resume cursor
  `meta`            LONGTEXT NULL,
  `error_code`      VARCHAR(64) NULL,
  `error_message`   TEXT NULL,
  `started_at`      DATETIME NULL,
  `heartbeat_at`    DATETIME NULL,
  `completed_at`    DATETIME NULL,
  `created_at`      DATETIME NOT NULL,
  `updated_at`      DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wtp_step` (`job_id`,`step_key`),
  KEY `ix_wtp_step_seq` (`job_id`,`seq`),
  CONSTRAINT `fk_wtp_step_job` FOREIGN KEY (`job_id`)
    REFERENCES `mod_wtp_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mod_wtp_job_events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`     INT UNSIGNED NOT NULL,
  `step_key`   VARCHAR(64) NULL,
  `level`      ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
  `code`       VARCHAR(64) NULL,
  `message`    TEXT NOT NULL,
  `context`    LONGTEXT NULL,     -- redacted JSON
  `source`     ENUM('whmcs','agent','bootstrap','admin') NOT NULL DEFAULT 'whmcs',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wtp_event_job` (`job_id`,`id`),
  KEY `ix_wtp_event_level` (`job_id`,`level`),
  CONSTRAINT `fk_wtp_event_job` FOREIGN KEY (`job_id`)
    REFERENCES `mod_wtp_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
