-- MIGRATION_ROUTER_ACS_CONFIG_UI.sql
-- Superapps Nawacore - Router ACS Config UI Foundation
-- Aman dijalankan berulang (idempotent).

SET @db_name := DATABASE();

-- 1) routers.acs_url
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'routers'
              AND COLUMN_NAME = 'acs_url'
        ),
        'SELECT 1',
        'ALTER TABLE routers ADD COLUMN acs_url VARCHAR(255) NULL AFTER invoice_footer'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) routers.acs_nbi_url
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'routers'
              AND COLUMN_NAME = 'acs_nbi_url'
        ),
        'SELECT 1',
        'ALTER TABLE routers ADD COLUMN acs_nbi_url VARCHAR(255) NULL AFTER acs_url'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) routers.acs_username
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'routers'
              AND COLUMN_NAME = 'acs_username'
        ),
        'SELECT 1',
        'ALTER TABLE routers ADD COLUMN acs_username VARCHAR(100) NULL AFTER acs_nbi_url'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) routers.acs_password
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'routers'
              AND COLUMN_NAME = 'acs_password'
        ),
        'SELECT 1',
        'ALTER TABLE routers ADD COLUMN acs_password VARCHAR(255) NULL AFTER acs_username'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) routers.acs_status
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'routers'
              AND COLUMN_NAME = 'acs_status'
        ),
        'SELECT 1',
        'ALTER TABLE routers ADD COLUMN acs_status ENUM(''connected'',''disconnected'') NOT NULL DEFAULT ''disconnected'' AFTER acs_password'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill empty status
UPDATE routers
SET acs_status = 'disconnected'
WHERE acs_status IS NULL OR TRIM(acs_status) = '';

