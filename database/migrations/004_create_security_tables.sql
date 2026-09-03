-- WTP 004 : replay protection, idempotency, rate limiting and download grants.

CREATE TABLE IF NOT EXISTS `mod_wtp_api_nonces` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`     INT UNSIGNED NOT NULL,
  `nonce`      CHAR(32) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wtp_nonce` (`job_id`,`nonce`),
  KEY `ix_wtp_nonce_gc` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mod_wtp_idempotency` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`        INT UNSIGNED NOT NULL,
  `scope`         VARCHAR(96) NOT NULL,      -- e.g. "POST /v1/steps/theme.install"
  `idem_key`      VARCHAR(128) NOT NULL,
  `request_hash`  CHAR(64) NOT NULL,
  `status_code`   SMALLINT UNSIGNED NOT NULL,
  `response`      LONGTEXT NOT NULL,
  `expires_at`    DATETIME NOT NULL,
  `created_at`    DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wtp_idem` (`job_id`,`scope`,`idem_key`),
  KEY `ix_wtp_idem_gc` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mod_wtp_rate_limits` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket`      VARCHAR(191) NOT NULL,       -- e.g. "ip:203.0.113.7:auth"
  `tokens`      DECIMAL(10,3) NOT NULL DEFAULT 0,
  `updated_at`  DATETIME NOT NULL,
  `expires_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wtp_bucket` (`bucket`),
  KEY `ix_wtp_bucket_gc` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mod_wtp_download_grants` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`     INT UNSIGNED NOT NULL,
  `asset_kind` ENUM('demo_asset','theme_package','agent_package','wp_core') NOT NULL,
  `asset_ref`  VARCHAR(191) NOT NULL,        -- asset id, version id, or a storage token
  `sha256`     CHAR(64) NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `filename`   VARCHAR(191) NOT NULL,
  `max_uses`   INT UNSIGNED NOT NULL DEFAULT 3,
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wtp_grant_job` (`job_id`,`expires_at`),
  CONSTRAINT `fk_wtp_grant_job` FOREIGN KEY (`job_id`)
    REFERENCES `mod_wtp_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mod_wtp_settings` (
  `k`          VARCHAR(96) NOT NULL,
  `v`          LONGTEXT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
