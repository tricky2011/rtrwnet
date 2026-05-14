-- Migration: Router scope for master_locations and master_olts
-- Safe for MariaDB 10.3 (uses information_schema + dynamic SQL)

SET @default_router_id := (
    SELECT id
    FROM routers
    WHERE LOWER(TRIM(name)) = 'kalisari'
    ORDER BY id ASC
    LIMIT 1
);
SET @default_router_id := COALESCE(
    @default_router_id,
    (SELECT id FROM routers WHERE is_active = 1 ORDER BY id ASC LIMIT 1),
    (SELECT id FROM routers ORDER BY id ASC LIMIT 1)
);

SELECT @default_router_id AS default_router_id;

-- =========================
-- master_locations
-- =========================
SET @has_col := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_locations'
      AND COLUMN_NAME = 'router_id'
);
SET @sql := IF(
    @has_col = 0,
    'ALTER TABLE master_locations ADD COLUMN router_id BIGINT(20) UNSIGNED NULL AFTER id',
    'SELECT "master_locations.router_id already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE master_locations
SET router_id = @default_router_id
WHERE (router_id IS NULL OR router_id = 0)
  AND @default_router_id IS NOT NULL;

UPDATE master_locations ml
LEFT JOIN routers r ON r.id = ml.router_id
SET ml.router_id = @default_router_id
WHERE r.id IS NULL
  AND @default_router_id IS NOT NULL;

SET @has_old_uq := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_locations'
      AND INDEX_NAME = 'uq_master_locations_name'
);
SET @sql := IF(
    @has_old_uq > 0,
    'ALTER TABLE master_locations DROP INDEX uq_master_locations_name',
    'SELECT "uq_master_locations_name already dropped"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_locations'
      AND INDEX_NAME = 'idx_master_locations_router'
);
SET @sql := IF(
    @has_idx = 0,
    'ALTER TABLE master_locations ADD INDEX idx_master_locations_router (router_id)',
    'SELECT "idx_master_locations_router exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_new_uq := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_locations'
      AND INDEX_NAME = 'uq_master_locations_router_name'
);
SET @sql := IF(
    @has_new_uq = 0,
    'ALTER TABLE master_locations ADD UNIQUE KEY uq_master_locations_router_name (router_id, name)',
    'SELECT "uq_master_locations_router_name exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @default_router_id IS NOT NULL,
    'ALTER TABLE master_locations MODIFY router_id BIGINT(20) UNSIGNED NOT NULL',
    'SELECT "default_router_id is NULL, skip NOT NULL master_locations"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_locations'
      AND COLUMN_NAME = 'router_id'
      AND REFERENCED_TABLE_NAME = 'routers'
);
SET @sql := IF(
    @has_fk = 0,
    'ALTER TABLE master_locations ADD CONSTRAINT fk_master_locations_router FOREIGN KEY (router_id) REFERENCES routers(id) ON UPDATE CASCADE ON DELETE RESTRICT',
    'SELECT "fk_master_locations_router exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================
-- master_olts
-- =========================
SET @has_col := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_olts'
      AND COLUMN_NAME = 'router_id'
);
SET @sql := IF(
    @has_col = 0,
    'ALTER TABLE master_olts ADD COLUMN router_id BIGINT(20) UNSIGNED NULL AFTER id',
    'SELECT "master_olts.router_id already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE master_olts
SET router_id = @default_router_id
WHERE (router_id IS NULL OR router_id = 0)
  AND @default_router_id IS NOT NULL;

UPDATE master_olts mo
LEFT JOIN routers r ON r.id = mo.router_id
SET mo.router_id = @default_router_id
WHERE r.id IS NULL
  AND @default_router_id IS NOT NULL;

SET @has_old_uq := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_olts'
      AND INDEX_NAME = 'uq_master_olts_name'
);
SET @sql := IF(
    @has_old_uq > 0,
    'ALTER TABLE master_olts DROP INDEX uq_master_olts_name',
    'SELECT "uq_master_olts_name already dropped"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_olts'
      AND INDEX_NAME = 'idx_master_olts_router'
);
SET @sql := IF(
    @has_idx = 0,
    'ALTER TABLE master_olts ADD INDEX idx_master_olts_router (router_id)',
    'SELECT "idx_master_olts_router exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_new_uq := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_olts'
      AND INDEX_NAME = 'uq_master_olts_router_name'
);
SET @sql := IF(
    @has_new_uq = 0,
    'ALTER TABLE master_olts ADD UNIQUE KEY uq_master_olts_router_name (router_id, name)',
    'SELECT "uq_master_olts_router_name exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @default_router_id IS NOT NULL,
    'ALTER TABLE master_olts MODIFY router_id BIGINT(20) UNSIGNED NOT NULL',
    'SELECT "default_router_id is NULL, skip NOT NULL master_olts"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_olts'
      AND COLUMN_NAME = 'router_id'
      AND REFERENCED_TABLE_NAME = 'routers'
);
SET @sql := IF(
    @has_fk = 0,
    'ALTER TABLE master_olts ADD CONSTRAINT fk_master_olts_router FOREIGN KEY (router_id) REFERENCES routers(id) ON UPDATE CASCADE ON DELETE RESTRICT',
    'SELECT "fk_master_olts_router exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT
    'master_locations' AS table_name,
    COUNT(*) AS total_rows,
    SUM(router_id IS NULL) AS null_router,
    SUM(router_id = @default_router_id) AS bound_to_default
FROM master_locations
UNION ALL
SELECT
    'master_olts' AS table_name,
    COUNT(*) AS total_rows,
    SUM(router_id IS NULL) AS null_router,
    SUM(router_id = @default_router_id) AS bound_to_default
FROM master_olts;
