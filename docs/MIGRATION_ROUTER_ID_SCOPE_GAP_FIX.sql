SET @db_name := DATABASE();
SET @router_kalisari := (SELECT id FROM routers WHERE (`name`='Kalisari' OR `router_name`='Kalisari') ORDER BY id LIMIT 1);
SET @router_tembalang := (SELECT id FROM routers WHERE (`name`='Tembalang' OR `router_name`='Tembalang') ORDER BY id LIMIT 1);
SET @router_kalisari := IFNULL(@router_kalisari, (SELECT MIN(id) FROM routers));

-- ========== Add missing router_id columns (idempotent) ==========

-- payments.router_id
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db_name AND table_name='payments' AND column_name='router_id');
SET @sql := IF(@c=0,
  'ALTER TABLE `payments` ADD COLUMN `router_id` BIGINT(20) UNSIGNED NULL AFTER `customer_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db_name AND table_name='payments' AND index_name='idx_payments_router_id');
SET @sql := IF(@idx=0,
  'ALTER TABLE `payments` ADD INDEX `idx_payments_router_id` (`router_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- invoice_items.router_id
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db_name AND table_name='invoice_items' AND column_name='router_id');
SET @sql := IF(@c=0,
  'ALTER TABLE `invoice_items` ADD COLUMN `router_id` BIGINT(20) UNSIGNED NULL AFTER `invoice_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db_name AND table_name='invoice_items' AND index_name='idx_invoice_items_router_id');
SET @sql := IF(@idx=0,
  'ALTER TABLE `invoice_items` ADD INDEX `idx_invoice_items_router_id` (`router_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- service_plans.router_id
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db_name AND table_name='service_plans' AND column_name='router_id');
SET @sql := IF(@c=0,
  'ALTER TABLE `service_plans` ADD COLUMN `router_id` BIGINT(20) UNSIGNED NULL AFTER `id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db_name AND table_name='service_plans' AND index_name='idx_service_plans_router_id');
SET @sql := IF(@idx=0,
  'ALTER TABLE `service_plans` ADD INDEX `idx_service_plans_router_id` (`router_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- telegram_bots.router_id
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db_name AND table_name='telegram_bots' AND column_name='router_id');
SET @sql := IF(@c=0,
  'ALTER TABLE `telegram_bots` ADD COLUMN `router_id` BIGINT(20) UNSIGNED NULL AFTER `id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db_name AND table_name='telegram_bots' AND index_name='idx_telegram_bots_router_id');
SET @sql := IF(@idx=0,
  'ALTER TABLE `telegram_bots` ADD INDEX `idx_telegram_bots_router_id` (`router_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ========== Backfill router_id ==========

UPDATE `payments` p
JOIN `invoices` i ON i.id = p.invoice_id
SET p.router_id = i.router_id
WHERE p.router_id IS NULL AND i.router_id IS NOT NULL;

UPDATE `payments` p
JOIN `customers` c ON c.id = p.customer_id
SET p.router_id = c.router_id
WHERE p.router_id IS NULL AND c.router_id IS NOT NULL;

UPDATE `payments`
SET router_id = @router_kalisari
WHERE router_id IS NULL;

UPDATE `invoice_items` ii
JOIN `invoices` i ON i.id = ii.invoice_id
SET ii.router_id = i.router_id
WHERE ii.router_id IS NULL AND i.router_id IS NOT NULL;

UPDATE `invoice_items`
SET router_id = @router_kalisari
WHERE router_id IS NULL;

UPDATE `service_plans`
SET router_id = @router_kalisari
WHERE router_id IS NULL;

UPDATE `telegram_bots`
SET router_id = @router_kalisari
WHERE router_id IS NULL;

-- bind existing master refs to default router if missing
UPDATE `master_locations`
SET router_id = @router_kalisari
WHERE router_id IS NULL OR router_id = 0;

UPDATE `master_olts`
SET router_id = @router_kalisari
WHERE router_id IS NULL OR router_id = 0;

-- rebind WO/Tickets by customer router (if customer linked)
UPDATE `work_orders` w
JOIN `customers` c ON c.id = w.customer_id
SET w.router_id = c.router_id
WHERE c.router_id IS NOT NULL
  AND (w.router_id IS NULL OR w.router_id <> c.router_id);

UPDATE `tickets` t
JOIN `customers` c ON c.id = t.customer_id
SET t.router_id = c.router_id
WHERE c.router_id IS NOT NULL
  AND (t.router_id IS NULL OR t.router_id <> c.router_id);

-- orphan fix to Kalisari for selected tables
UPDATE `payments` p
LEFT JOIN `routers` r ON r.id = p.router_id
SET p.router_id = @router_kalisari
WHERE r.id IS NULL OR p.router_id IS NULL;

UPDATE `invoice_items` ii
LEFT JOIN `routers` r ON r.id = ii.router_id
SET ii.router_id = @router_kalisari
WHERE r.id IS NULL OR ii.router_id IS NULL;

UPDATE `service_plans` sp
LEFT JOIN `routers` r ON r.id = sp.router_id
SET sp.router_id = @router_kalisari
WHERE r.id IS NULL OR sp.router_id IS NULL;

UPDATE `telegram_bots` tb
LEFT JOIN `routers` r ON r.id = tb.router_id
SET tb.router_id = @router_kalisari
WHERE r.id IS NULL OR tb.router_id IS NULL;

-- ========== enforce NOT NULL + FK (idempotent) ==========

ALTER TABLE `payments` MODIFY COLUMN `router_id` BIGINT(20) UNSIGNED NOT NULL;
ALTER TABLE `invoice_items` MODIFY COLUMN `router_id` BIGINT(20) UNSIGNED NOT NULL;
ALTER TABLE `service_plans` MODIFY COLUMN `router_id` BIGINT(20) UNSIGNED NOT NULL;
ALTER TABLE `telegram_bots` MODIFY COLUMN `router_id` BIGINT(20) UNSIGNED NOT NULL;

SET @fk := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=@db_name AND table_name='payments' AND constraint_name='fk_payments_router');
SET @sql := IF(@fk=0,
  'ALTER TABLE `payments` ADD CONSTRAINT `fk_payments_router` FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=@db_name AND table_name='invoice_items' AND constraint_name='fk_invoice_items_router');
SET @sql := IF(@fk=0,
  'ALTER TABLE `invoice_items` ADD CONSTRAINT `fk_invoice_items_router` FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=@db_name AND table_name='service_plans' AND constraint_name='fk_service_plans_router');
SET @sql := IF(@fk=0,
  'ALTER TABLE `service_plans` ADD CONSTRAINT `fk_service_plans_router` FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=@db_name AND table_name='telegram_bots' AND constraint_name='fk_telegram_bots_router');
SET @sql := IF(@fk=0,
  'ALTER TABLE `telegram_bots` ADD CONSTRAINT `fk_telegram_bots_router` FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- telegram_groups unique per router (allow same chat per router)
SET @idx_old := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=@db_name
    AND table_name='telegram_groups'
    AND index_name='uq_telegram_groups_bot_type_chat'
);
SET @sql := IF(@idx_old > 0,
  'ALTER TABLE telegram_groups DROP INDEX uq_telegram_groups_bot_type_chat',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_new := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=@db_name
    AND table_name='telegram_groups'
    AND index_name='uq_telegram_groups_bot_router_type_chat'
);
SET @sql := IF(@idx_new = 0,
  'ALTER TABLE telegram_groups ADD UNIQUE KEY uq_telegram_groups_bot_router_type_chat (bot_id, router_id, type, chat_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- output validation snapshot
SELECT 'ROUTER_IDS' AS section, @router_kalisari AS kalisari_id, @router_tembalang AS tembalang_id;
SELECT 'payments' AS tbl, COUNT(*) total, SUM(router_id IS NULL) null_router FROM payments;
SELECT 'invoice_items' AS tbl, COUNT(*) total, SUM(router_id IS NULL) null_router FROM invoice_items;
SELECT 'service_plans' AS tbl, COUNT(*) total, SUM(router_id IS NULL) null_router FROM service_plans;
SELECT 'telegram_bots' AS tbl, COUNT(*) total, SUM(router_id IS NULL) null_router FROM telegram_bots;
SELECT 'work_orders' AS tbl, router_id, COUNT(*) cnt FROM work_orders GROUP BY router_id ORDER BY router_id;
SELECT 'tickets' AS tbl, router_id, COUNT(*) cnt FROM tickets GROUP BY router_id ORDER BY router_id;
