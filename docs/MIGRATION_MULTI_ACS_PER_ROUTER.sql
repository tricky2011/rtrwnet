-- MIGRATION: Multi ACS per Router (GenieACS)
-- Project: Superapps Nawacore (CI3)
-- Safe to run multiple times on MariaDB/MySQL that supports IF [NOT] EXISTS in ALTER.

SET @db_name := DATABASE();

-- 0) Resolve fallback router (prefer Kalisari, else first active, else first id).
SET @default_router_id := (
    SELECT id FROM routers WHERE LOWER(name) = 'kalisari' LIMIT 1
);
SET @default_router_id := COALESCE(
    @default_router_id,
    (SELECT id FROM routers WHERE is_active = 1 ORDER BY id ASC LIMIT 1),
    (SELECT id FROM routers ORDER BY id ASC LIMIT 1)
);

SELECT 'default_router_id' AS key_name, @default_router_id AS value_id;

-- 1) Routers: add ACS columns.
ALTER TABLE routers ADD COLUMN IF NOT EXISTS acs_url VARCHAR(255) NULL AFTER timeout_seconds;
ALTER TABLE routers ADD COLUMN IF NOT EXISTS acs_nbi_url VARCHAR(255) NULL AFTER acs_url;
ALTER TABLE routers ADD COLUMN IF NOT EXISTS acs_username VARCHAR(100) NULL AFTER acs_nbi_url;
ALTER TABLE routers ADD COLUMN IF NOT EXISTS acs_password VARCHAR(100) NULL AFTER acs_username;

-- 2) Seed example values only when empty (can be edited from Router Management UI).
UPDATE routers
SET acs_url = 'http://10.10.10.2:7547',
    acs_nbi_url = 'http://10.10.10.2:7557'
WHERE LOWER(name) = 'kalisari'
  AND (acs_url IS NULL OR acs_url = '' OR acs_nbi_url IS NULL OR acs_nbi_url = '');

UPDATE routers
SET acs_url = 'http://10.20.20.2:7547',
    acs_nbi_url = 'http://10.20.20.2:7557'
WHERE LOWER(name) = 'tembalang'
  AND (acs_url IS NULL OR acs_url = '' OR acs_nbi_url IS NULL OR acs_nbi_url = '');

-- 3) ont_devices: add router_id + index.
ALTER TABLE ont_devices ADD COLUMN IF NOT EXISTS router_id BIGINT(20) UNSIGNED NULL AFTER customer_id;
ALTER TABLE ont_devices ADD INDEX IF NOT EXISTS idx_ont_devices_router_id (router_id);

-- 4) Backfill ont_devices.router_id from customers.router_id when possible.
UPDATE ont_devices od
JOIN customers c ON c.id = od.customer_id
SET od.router_id = c.router_id
WHERE od.router_id IS NULL
  AND c.router_id IS NOT NULL;

-- 5) Backfill remaining NULL to fallback router.
UPDATE ont_devices
SET router_id = @default_router_id
WHERE router_id IS NULL
  AND @default_router_id IS NOT NULL;

-- 6) Switch unique serial to unique router+serial.
SET @has_old_uq := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = @db_name
    AND table_name = 'ont_devices'
    AND index_name = 'uq_ont_devices_serial_number'
);
SET @sql_drop_old_uq := IF(@has_old_uq > 0,
  'ALTER TABLE ont_devices DROP INDEX uq_ont_devices_serial_number',
  'SELECT ''skip drop old unique''');
PREPARE stmt_drop_old_uq FROM @sql_drop_old_uq;
EXECUTE stmt_drop_old_uq;
DEALLOCATE PREPARE stmt_drop_old_uq;

SET @has_new_uq := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = @db_name
    AND table_name = 'ont_devices'
    AND index_name = 'uq_ont_devices_router_serial'
);
SET @sql_add_new_uq := IF(@has_new_uq = 0,
  'ALTER TABLE ont_devices ADD UNIQUE KEY uq_ont_devices_router_serial (router_id, serial_number)',
  'SELECT ''skip add new unique''');
PREPARE stmt_add_new_uq FROM @sql_add_new_uq;
EXECUTE stmt_add_new_uq;
DEALLOCATE PREPARE stmt_add_new_uq;

-- 7) Set NOT NULL only when clean (no NULL left).
SET @null_router_cnt := (SELECT COUNT(*) FROM ont_devices WHERE router_id IS NULL);
SET @sql_set_not_null := IF(@null_router_cnt = 0,
  'ALTER TABLE ont_devices MODIFY router_id BIGINT(20) UNSIGNED NOT NULL',
  'SELECT ''WARNING: ont_devices.router_id still NULL, NOT NULL skipped'' AS warn');
PREPARE stmt_set_not_null FROM @sql_set_not_null;
EXECUTE stmt_set_not_null;
DEALLOCATE PREPARE stmt_set_not_null;

-- 8) Add FK ont_devices.router_id -> routers.id if missing.
SET @has_fk := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints tc
  WHERE tc.constraint_schema = @db_name
    AND tc.table_name = 'ont_devices'
    AND tc.constraint_name = 'fk_ont_devices_router'
    AND tc.constraint_type = 'FOREIGN KEY'
);
SET @sql_add_fk := IF(@has_fk = 0,
  'ALTER TABLE ont_devices ADD CONSTRAINT fk_ont_devices_router FOREIGN KEY (router_id) REFERENCES routers(id) ON UPDATE CASCADE ON DELETE RESTRICT',
  'SELECT ''skip add fk''');
PREPARE stmt_add_fk FROM @sql_add_fk;
EXECUTE stmt_add_fk;
DEALLOCATE PREPARE stmt_add_fk;

-- 9) Validation outputs.
SELECT id, name, acs_url, acs_nbi_url, acs_username
FROM routers
ORDER BY id;

SELECT COUNT(*) AS ont_router_null
FROM ont_devices
WHERE router_id IS NULL;

SELECT router_id, COUNT(*) AS total_ont
FROM ont_devices
GROUP BY router_id
ORDER BY router_id;
