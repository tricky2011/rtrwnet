-- Fiber Network Map module schema (CodeIgniter 3 / MySQL)
-- Date: 2026-03-08

CREATE TABLE IF NOT EXISTS `fiber_odp` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `router_id` BIGINT(20) UNSIGNED NOT NULL,
  `olt_id` BIGINT(20) UNSIGNED NULL,
  `pon_port` VARCHAR(50) NULL,
  `name` VARCHAR(120) NOT NULL,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `capacity` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `used_ports` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `description` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fiber_odp_router` (`router_id`),
  KEY `idx_fiber_odp_olt` (`olt_id`),
  KEY `idx_fiber_odp_pon` (`pon_port`),
  KEY `idx_fiber_odp_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `latitude` DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS `odp_id` BIGINT(20) UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `router_id` BIGINT(20) UNSIGNED NULL;

ALTER TABLE `customers`
  ADD INDEX `idx_customers_odp_id` (`odp_id`),
  ADD INDEX `idx_customers_router_odp` (`router_id`,`odp_id`);

ALTER TABLE `master_olts`
  ADD COLUMN IF NOT EXISTS `router_id` BIGINT(20) UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `latitude` DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(10,7) NULL;

ALTER TABLE `master_olts`
  ADD INDEX `idx_master_olts_router_id` (`router_id`);
