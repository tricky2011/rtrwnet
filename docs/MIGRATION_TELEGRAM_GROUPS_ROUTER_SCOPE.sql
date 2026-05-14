-- MIGRATION_TELEGRAM_GROUPS_ROUTER_SCOPE.sql
-- Tujuan:
-- 1) Menambahkan binding router pada telegram_groups.
-- 2) Menjaga kompatibilitas data lama.
-- 3) Aman dijalankan berulang (idempotent).

USE `nawacore_db`;

SET @db := DATABASE();

-- 1) Tambah kolom router_id jika belum ada
SET @has_router_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'telegram_groups'
    AND COLUMN_NAME = 'router_id'
);
SET @sql_router_col := IF(
  @has_router_col = 0,
  'ALTER TABLE `telegram_groups` ADD COLUMN `router_id` BIGINT(20) UNSIGNED NULL AFTER `type`',
  'SELECT "telegram_groups.router_id already exists"'
);
PREPARE stmt_router_col FROM @sql_router_col;
EXECUTE stmt_router_col;
DEALLOCATE PREPARE stmt_router_col;

-- 2) Tambah index router_id jika belum ada
SET @has_idx_router := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'telegram_groups'
    AND INDEX_NAME = 'idx_telegram_groups_router'
);
SET @sql_idx_router := IF(
  @has_idx_router = 0,
  'ALTER TABLE `telegram_groups` ADD INDEX `idx_telegram_groups_router` (`router_id`)',
  'SELECT "idx_telegram_groups_router already exists"'
);
PREPARE stmt_idx_router FROM @sql_idx_router;
EXECUTE stmt_idx_router;
DEALLOCATE PREPARE stmt_idx_router;

-- 3) Backfill router_id data lama ke router aktif pertama
SET @default_router_id := (
  SELECT id
  FROM routers
  WHERE (is_active = 1 OR status = 'active')
  ORDER BY id ASC
  LIMIT 1
);

UPDATE telegram_groups
SET router_id = @default_router_id
WHERE (router_id IS NULL OR router_id = 0)
  AND @default_router_id IS NOT NULL;

-- 4) Optional: tambah foreign key jika belum ada
SET @has_fk := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'telegram_groups'
    AND CONSTRAINT_NAME = 'fk_telegram_groups_router'
);
SET @sql_fk := IF(
  @has_fk = 0,
  'ALTER TABLE `telegram_groups` ADD CONSTRAINT `fk_telegram_groups_router` FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`) ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT "fk_telegram_groups_router already exists"'
);
PREPARE stmt_fk FROM @sql_fk;
EXECUTE stmt_fk;
DEALLOCATE PREPARE stmt_fk;

-- 5) Verifikasi
SELECT id, group_name, type, chat_id, router_id
FROM telegram_groups
ORDER BY id DESC;
