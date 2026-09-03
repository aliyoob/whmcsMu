-- WTP 001 : catalogue (themes, versions, demos, assets, product settings)
-- Engine/charset chosen to match WHMCS defaults (InnoDB / utf8mb4).

CREATE TABLE IF NOT EXISTS `mod_wtp_themes` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`              VARCHAR(96)  NOT NULL,
  `name`              VARCHAR(191) NOT NULL,
  `description`       TEXT NULL,
  `preview_image_url` VARCHAR(512) NULL,
  `homepage_url`      VARCHAR(512) NULL,
  `status`            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order`        INT NOT NULL DEFAULT 0,
  `created_at`        DATETIME NOT NULL,
  `updated_at`        DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wtp_theme_slug` (`slug`),
  KEY `ix_wtp_theme_status` (`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mod_wtp_theme_versions` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `theme_id`        INT UNSIGNED NOT NULL,
  `version`         VARCHAR(32)  NOT NULL,
  `is_default`      TINYINT(1)   NOT NULL DEFAULT 0,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  -- where the theme package comes from
  `source_type`     ENUM('storage','url','wporg') NOT NULL DEFAULT 'storage',
  `source_ref`      VARCHAR(512) NOT NULL,          -- storage path | absolute URL | wp.org slug
  `checksum_sha256` CHAR(64) NULL,                  -- required for storage/url
  `size_bytes`      BIGINT UNSIGNED NULL,
  `wp_min_version`  VARCHAR(16) NULL,
  `php_min_version` VARCHAR(16) NULL,
  `notes`           TEXT NULL,
  `created_at`      DATETIME NOT NULL,
  `updated_at`      DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wtp_theme_version` (`theme_id`,`version`),
  KEY `ix_wtp_theme_version_default` (`theme_id`,`is_default`,`status`),
  CONSTRAINT `fk_wtp_version_theme` FOREIGN KEY (`theme_id`)
    REFERENCES `mod_wtp_themes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mod_wtp_demos` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `theme_id`          INT UNSIGNED NOT NULL,
  `slug`              VARCHAR(96)  NOT NULL,
  `name`              VARCHAR(191) NOT NULL,
  `description`       TEXT NULL,
  `preview_image_url` VARCHAR(512) NULL,
  `preview_site_url`  VARCHAR(512) NULL,
  `status`            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order`        INT NOT NULL DEFAULT 0,
  `min_theme_version` VARCHAR(32) NULL,
  -- Declarative import plan. Validated against docs/schema/import-plan.schema.json
  -- before it is ever stored. Never contains secrets or absolute local paths.
  `import_plan`       LONGTEXT NULL,
  `required_plugins`  LONGTEXT NULL,   -- JSON array of {source,slug|asset_id,version,activate}
  `created_at`        DATETIME NOT NULL,
  `updated_at`        DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wtp_demo_slug` (`theme_id`,`slug`),
  KEY `ix_wtp_demo_status` (`theme_id`,`status`,`sort_order`),
  CONSTRAINT `fk_wtp_demo_theme` FOREIGN KEY (`theme_id`)
    REFERENCES `mod_wtp_themes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mod_wtp_demo_assets` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `demo_id`         INT UNSIGNED NOT NULL,
  `role`            ENUM('content','widgets','customizer','elementor_kit','plugin','media','other')
                    NOT NULL DEFAULT 'other',
  `filename`        VARCHAR(191) NOT NULL,
  `storage_path`    VARCHAR(512) NOT NULL,   -- relative to the configured storage root
  `mime`            VARCHAR(96)  NULL,
  `size_bytes`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `checksum_sha256` CHAR(64) NOT NULL,
  `sort_order`      INT NOT NULL DEFAULT 0,
  `created_at`      DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wtp_asset_demo` (`demo_id`,`role`,`sort_order`),
  CONSTRAINT `fk_wtp_asset_demo` FOREIGN KEY (`demo_id`)
    REFERENCES `mod_wtp_demos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mod_wtp_product_settings` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`         INT UNSIGNED NOT NULL,      -- tblproducts.id
  `enabled`            TINYINT(1) NOT NULL DEFAULT 0,
  `selection_required` TINYINT(1) NOT NULL DEFAULT 1,
  `allowed_theme_ids`  TEXT NULL,                  -- JSON array; NULL = all active themes
  `default_theme_id`   INT UNSIGNED NULL,
  `default_demo_id`    INT UNSIGNED NULL,
  `created_at`         DATETIME NOT NULL,
  `updated_at`         DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wtp_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
