-- WTP 002 : binding between a WHMCS service and the customer's theme/demo choice.

CREATE TABLE IF NOT EXISTS `mod_wtp_service_selection` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id`       INT UNSIGNED NOT NULL,   -- tblhosting.id
  `order_id`         INT UNSIGNED NULL,       -- tblorders.id
  `client_id`        INT UNSIGNED NULL,       -- tblclients.id
  `product_id`       INT UNSIGNED NULL,       -- tblproducts.id
  `domain`           VARCHAR(191) NULL,
  `theme_id`         INT UNSIGNED NOT NULL,
  `theme_version_id` INT UNSIGNED NULL,
  `demo_id`          INT UNSIGNED NOT NULL,
  `source`           ENUM('client','admin','default','api') NOT NULL DEFAULT 'client',
  `locked`           TINYINT(1) NOT NULL DEFAULT 0,  -- set once provisioning starts
  `selected_at`      DATETIME NOT NULL,
  `updated_at`       DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wtp_selection_service` (`service_id`),
  KEY `ix_wtp_selection_order` (`order_id`),
  KEY `ix_wtp_selection_theme` (`theme_id`,`demo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
