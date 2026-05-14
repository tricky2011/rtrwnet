ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `connection_type` ENUM('PPPOE','STATIC') NOT NULL DEFAULT 'PPPOE' AFTER `ip_address`,
  ADD COLUMN IF NOT EXISTS `queue_name` VARCHAR(120) NULL AFTER `connection_type`,
  ADD COLUMN IF NOT EXISTS `mac_address` VARCHAR(32) NULL AFTER `queue_name`,
  ADD COLUMN IF NOT EXISTS `last_seen` DATETIME NULL AFTER `mac_address`,
  ADD COLUMN IF NOT EXISTS `static_source` VARCHAR(40) NULL AFTER `last_seen`;

UPDATE `customers`
SET `connection_type` = 'STATIC'
WHERE `connection_type` <> 'STATIC'
  AND (
        `notes` LIKE 'Auto sync STATIC%'
        OR (`pppoe_username` IS NULL OR `pppoe_username` = '')
      )
  AND (`ip_address` IS NOT NULL AND `ip_address` <> '');

CREATE INDEX IF NOT EXISTS `idx_customers_connection_type` ON `customers` (`connection_type`);
CREATE INDEX IF NOT EXISTS `idx_customers_queue_name` ON `customers` (`queue_name`);
CREATE INDEX IF NOT EXISTS `idx_customers_ip_address` ON `customers` (`ip_address`);
CREATE INDEX IF NOT EXISTS `idx_customers_mac_address` ON `customers` (`mac_address`);
