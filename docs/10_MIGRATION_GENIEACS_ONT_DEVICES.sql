-- ==========================================================
-- MIGRATION: GENIEACS ONT DEVICES (SAFE / IDEMPOTENT)
-- Project : Superapps Nawacore (CodeIgniter 3)
-- ==========================================================

USE `nawacore_db`;

CREATE TABLE IF NOT EXISTS `ont_devices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT NULL,
  `serial_number` VARCHAR(100) NOT NULL,
  `product_class` VARCHAR(100) NULL,
  `manufacturer` VARCHAR(100) NULL,
  `wan_ip` VARCHAR(50) NULL,
  `ssid` VARCHAR(100) NULL,
  `wifi_password` VARCHAR(100) NULL,
  `status` ENUM('online','offline') NOT NULL DEFAULT 'offline',
  `last_inform` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ont_devices_serial_number` (`serial_number`),
  KEY `idx_ont_devices_customer_id` (`customer_id`),
  KEY `idx_ont_devices_status` (`status`),
  KEY `idx_ont_devices_last_inform` (`last_inform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional FK (enable jika constraint customer sudah stabil)
-- ALTER TABLE `ont_devices`
--   ADD CONSTRAINT `fk_ont_devices_customer`
--   FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
--   ON UPDATE CASCADE ON DELETE SET NULL;

-- Validation
SELECT COUNT(*) AS total_ont FROM `ont_devices`;
SHOW INDEX FROM `ont_devices`;
