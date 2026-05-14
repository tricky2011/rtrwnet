-- ====================================================
-- 06_DB_REPAIR_MULTI_ROUTER_PPP_SYNC.sql
-- Database repair aman production untuk multi-router
-- Target DB: nawacore_db (CodeIgniter 3)
-- ====================================================

SET @__db := DATABASE();
SET @__now := NOW();
SET @__old_sql_safe_updates := @@SQL_SAFE_UPDATES;
SET SQL_SAFE_UPDATES = 0;

-- ====================================================
-- STEP 0 - PRECHECK
-- ====================================================
SELECT 'STEP 0 - PRECHECK' AS step;
SELECT @__db AS active_database, @__now AS executed_at;

-- ====================================================
-- Helper procedures (idempotent)
-- ====================================================
DROP PROCEDURE IF EXISTS sp_add_router_column_if_missing;
DELIMITER $$
CREATE PROCEDURE sp_add_router_column_if_missing(IN p_table VARCHAR(64))
BEGIN
    DECLARE v_table_exists INT DEFAULT 0;
    DECLARE v_column_exists INT DEFAULT 0;
    DECLARE v_idx_exists INT DEFAULT 0;
    DECLARE v_idx_name VARCHAR(64);

    SELECT COUNT(*) INTO v_table_exists
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = p_table;

    IF v_table_exists = 0 THEN
        SELECT p_table AS table_name, 'SKIP_TABLE_NOT_EXISTS' AS info;
    ELSE
        SELECT COUNT(*) INTO v_column_exists
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = 'router_id';

        IF v_column_exists = 0 THEN
            SET @sql_add_col = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `router_id` BIGINT(20) UNSIGNED NULL');
            PREPARE stmt_add_col FROM @sql_add_col;
            EXECUTE stmt_add_col;
            DEALLOCATE PREPARE stmt_add_col;
            SELECT p_table AS table_name, 'ADD_COLUMN_router_id' AS info;
        END IF;

        SET v_idx_name = CONCAT('idx_', p_table, '_router');
        SELECT COUNT(*) INTO v_idx_exists
        FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = v_idx_name;

        IF v_idx_exists = 0 THEN
            SET @sql_add_idx = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', v_idx_name, '` (`router_id`)');
            PREPARE stmt_add_idx FROM @sql_add_idx;
            EXECUTE stmt_add_idx;
            DEALLOCATE PREPARE stmt_add_idx;
            SELECT p_table AS table_name, CONCAT('ADD_INDEX_', v_idx_name) AS info;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_insert_router_if_missing;
DELIMITER $$
CREATE PROCEDURE sp_insert_router_if_missing(IN p_name VARCHAR(120), IN p_host VARCHAR(120))
BEGIN
    DECLARE v_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO v_exists
    FROM routers
    WHERE BINARY name = BINARY p_name;

    IF v_exists = 0 THEN
        SET @cols := '`name`';
        SET @vals := QUOTE(p_name);

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'router_name'
        ) THEN
            SET @cols := CONCAT(@cols, ',`router_name`');
            SET @vals := CONCAT(@vals, ',', QUOTE(p_name));
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'ip_address'
        ) THEN
            SET @cols := CONCAT(@cols, ',`ip_address`');
            SET @vals := CONCAT(@vals, ',', QUOTE(p_host));
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'api_host'
        ) THEN
            SET @cols := CONCAT(@cols, ',`api_host`');
            SET @vals := CONCAT(@vals, ',', QUOTE(p_host));
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'api_port'
        ) THEN
            SET @cols := CONCAT(@cols, ',`api_port`');
            SET @vals := CONCAT(@vals, ',8728');
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'username'
        ) THEN
            SET @cols := CONCAT(@cols, ',`username`');
            SET @vals := CONCAT(@vals, ',', QUOTE('admin'));
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'password'
        ) THEN
            SET @cols := CONCAT(@cols, ',`password`');
            SET @vals := CONCAT(@vals, ',', QUOTE(''));
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'api_username'
        ) THEN
            SET @cols := CONCAT(@cols, ',`api_username`');
            SET @vals := CONCAT(@vals, ',', QUOTE('admin'));
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'api_password_enc'
        ) THEN
            SET @cols := CONCAT(@cols, ',`api_password_enc`');
            SET @vals := CONCAT(@vals, ',', QUOTE(''));
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'is_active'
        ) THEN
            SET @cols := CONCAT(@cols, ',`is_active`');
            SET @vals := CONCAT(@vals, ',1');
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'status'
        ) THEN
            SET @cols := CONCAT(@cols, ',`status`');
            SET @vals := CONCAT(@vals, ',', QUOTE('active'));
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'created_at'
        ) THEN
            SET @cols := CONCAT(@cols, ',`created_at`');
            SET @vals := CONCAT(@vals, ',NOW()');
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'routers' AND column_name = 'updated_at'
        ) THEN
            SET @cols := CONCAT(@cols, ',`updated_at`');
            SET @vals := CONCAT(@vals, ',NOW()');
        END IF;

        SET @sql_insert_router := CONCAT(
            'INSERT INTO `routers` (', @cols, ') ',
            'SELECT ', @vals, ' WHERE NOT EXISTS (SELECT 1 FROM `routers` WHERE BINARY `name`=BINARY ', QUOTE(p_name), ')'
        );

        PREPARE stmt_insert_router FROM @sql_insert_router;
        EXECUTE stmt_insert_router;
        DEALLOCATE PREPARE stmt_insert_router;

        SELECT p_name AS router_name, 'INSERTED' AS info;
    ELSE
        SELECT p_name AS router_name, 'ALREADY_EXISTS' AS info;
    END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_router_orphan_report;
DELIMITER $$
CREATE PROCEDURE sp_router_orphan_report(IN p_table VARCHAR(64))
BEGIN
    DECLARE v_table_exists INT DEFAULT 0;
    DECLARE v_column_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO v_table_exists
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = p_table;

    IF v_table_exists = 0 THEN
        SELECT p_table AS table_name, 'TABLE_NOT_EXISTS' AS info;
    ELSE
        SELECT COUNT(*) INTO v_column_exists
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = 'router_id';

        IF v_column_exists = 0 THEN
            SELECT p_table AS table_name, 'ROUTER_COLUMN_MISSING' AS info;
        ELSE
            SET @sql_orphan_summary = CONCAT(
                'SELECT ', QUOTE(p_table), ' AS table_name, ',
                'COUNT(*) AS total_rows, ',
                'SUM(CASE WHEN t.router_id IS NULL THEN 1 ELSE 0 END) AS null_router_rows, ',
                'SUM(CASE WHEN t.router_id IS NOT NULL AND r.id IS NULL THEN 1 ELSE 0 END) AS orphan_router_rows ',
                'FROM `', p_table, '` t LEFT JOIN `routers` r ON r.id = t.router_id'
            );
            PREPARE stmt_orphan_summary FROM @sql_orphan_summary;
            EXECUTE stmt_orphan_summary;
            DEALLOCATE PREPARE stmt_orphan_summary;

            SET @sql_orphan_detail = CONCAT(
                'SELECT ', QUOTE(p_table), ' AS table_name, t.router_id, COUNT(*) AS rows_count ',
                'FROM `', p_table, '` t LEFT JOIN `routers` r ON r.id = t.router_id ',
                'WHERE t.router_id IS NULL OR r.id IS NULL ',
                'GROUP BY t.router_id ORDER BY rows_count DESC'
            );
            PREPARE stmt_orphan_detail FROM @sql_orphan_detail;
            EXECUTE stmt_orphan_detail;
            DEALLOCATE PREPARE stmt_orphan_detail;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_bind_router_default;
DELIMITER $$
CREATE PROCEDURE sp_bind_router_default(IN p_table VARCHAR(64), IN p_router_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_table_exists INT DEFAULT 0;
    DECLARE v_column_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO v_table_exists
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = p_table;

    IF v_table_exists = 0 THEN
        SELECT p_table AS table_name, 'SKIP_TABLE_NOT_EXISTS' AS info;
    ELSE
        SELECT COUNT(*) INTO v_column_exists
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = 'router_id';

        IF v_column_exists = 0 THEN
            SELECT p_table AS table_name, 'SKIP_ROUTER_COLUMN_MISSING' AS info;
        ELSE
            SET @sql_bind_null = CONCAT(
                'UPDATE `', p_table, '` SET router_id = ', p_router_id, ' WHERE router_id IS NULL'
            );
            PREPARE stmt_bind_null FROM @sql_bind_null;
            EXECUTE stmt_bind_null;
            DEALLOCATE PREPARE stmt_bind_null;

            SELECT p_table AS table_name, ROW_COUNT() AS updated_null_to_default;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_fix_orphan_router;
DELIMITER $$
CREATE PROCEDURE sp_fix_orphan_router(IN p_table VARCHAR(64), IN p_router_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_table_exists INT DEFAULT 0;
    DECLARE v_column_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO v_table_exists
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = p_table;

    IF v_table_exists = 0 THEN
        SELECT p_table AS table_name, 'SKIP_TABLE_NOT_EXISTS' AS info;
    ELSE
        SELECT COUNT(*) INTO v_column_exists
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = 'router_id';

        IF v_column_exists = 0 THEN
            SELECT p_table AS table_name, 'SKIP_ROUTER_COLUMN_MISSING' AS info;
        ELSE
            SET @sql_fix_orphan = CONCAT(
                'UPDATE `', p_table, '` t ',
                'LEFT JOIN `routers` r ON r.id = t.router_id ',
                'SET t.router_id = ', p_router_id, ' ',
                'WHERE t.router_id IS NOT NULL AND r.id IS NULL'
            );
            PREPARE stmt_fix_orphan FROM @sql_fix_orphan;
            EXECUTE stmt_fix_orphan;
            DEALLOCATE PREPARE stmt_fix_orphan;

            SELECT p_table AS table_name, ROW_COUNT() AS updated_orphan_to_default;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_rebuild_router_fk;
DELIMITER $$
CREATE PROCEDURE sp_rebuild_router_fk(IN p_table VARCHAR(64), IN p_fk_name VARCHAR(64), IN p_router_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_table_exists INT DEFAULT 0;
    DECLARE v_column_exists INT DEFAULT 0;
    DECLARE v_fk_exists INT DEFAULT 0;
    DECLARE v_fk_name VARCHAR(64);

    SELECT COUNT(*) INTO v_table_exists
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = p_table;

    IF v_table_exists = 0 THEN
        SELECT p_table AS table_name, 'SKIP_TABLE_NOT_EXISTS' AS info;
    ELSE
        SELECT COUNT(*) INTO v_column_exists
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = 'router_id';

        IF v_column_exists = 0 THEN
            SELECT p_table AS table_name, 'SKIP_ROUTER_COLUMN_MISSING' AS info;
        ELSE
            -- pastikan null diisi
            SET @sql_fill_null = CONCAT('UPDATE `', p_table, '` SET router_id = ', p_router_id, ' WHERE router_id IS NULL');
            PREPARE stmt_fill_null FROM @sql_fill_null;
            EXECUTE stmt_fill_null;
            DEALLOCATE PREPARE stmt_fill_null;

            -- drop FK existing yang refer ke routers melalui router_id
            SELECT COUNT(*), MAX(kcu.constraint_name)
            INTO v_fk_exists, v_fk_name
            FROM information_schema.key_column_usage kcu
            WHERE kcu.table_schema = DATABASE()
              AND kcu.table_name = p_table
              AND kcu.column_name = 'router_id'
              AND kcu.referenced_table_name = 'routers';

            IF v_fk_exists > 0 AND v_fk_name IS NOT NULL THEN
                SET @sql_drop_fk = CONCAT('ALTER TABLE `', p_table, '` DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt_drop_fk FROM @sql_drop_fk;
                EXECUTE stmt_drop_fk;
                DEALLOCATE PREPARE stmt_drop_fk;
                SELECT p_table AS table_name, CONCAT('DROP_FK_', v_fk_name) AS info;
            END IF;

            -- ubah router_id jadi NOT NULL BIGINT UNSIGNED
            SET @sql_modify_col = CONCAT('ALTER TABLE `', p_table, '` MODIFY COLUMN `router_id` BIGINT(20) UNSIGNED NOT NULL');
            PREPARE stmt_modify_col FROM @sql_modify_col;
            EXECUTE stmt_modify_col;
            DEALLOCATE PREPARE stmt_modify_col;

            -- pastikan index router_id ada
            SET @idx_name = CONCAT('idx_', p_table, '_router');
            IF NOT EXISTS (
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = @idx_name
            ) THEN
                SET @sql_add_idx_fk = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', @idx_name, '` (`router_id`)');
                PREPARE stmt_add_idx_fk FROM @sql_add_idx_fk;
                EXECUTE stmt_add_idx_fk;
                DEALLOCATE PREPARE stmt_add_idx_fk;
            END IF;

            -- tambah FK baru jika belum ada
            IF NOT EXISTS (
                SELECT 1 FROM information_schema.table_constraints
                WHERE table_schema = DATABASE()
                  AND table_name = p_table
                  AND constraint_type = 'FOREIGN KEY'
                  AND constraint_name = p_fk_name
            ) THEN
                SET @sql_add_fk = CONCAT(
                    'ALTER TABLE `', p_table, '` ',
                    'ADD CONSTRAINT `', p_fk_name, '` FOREIGN KEY (`router_id`) ',
                    'REFERENCES `routers`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT'
                );
                PREPARE stmt_add_fk FROM @sql_add_fk;
                EXECUTE stmt_add_fk;
                DEALLOCATE PREPARE stmt_add_fk;
            END IF;

            SELECT p_table AS table_name, p_fk_name AS fk_name, 'FK_READY' AS info;
        END IF;
    END IF;
END$$
DELIMITER ;

-- ====================================================
-- STEP 1 - VALIDASI ROUTERS TABLE
-- ====================================================
SELECT 'STEP 1 - VALIDASI ROUTERS TABLE' AS step;

CALL sp_insert_router_if_missing('Kalisari', '10.203.22.254');
CALL sp_insert_router_if_missing('Tembalang', '172.16.9.254');

SET @kalisari_id := (
    SELECT id FROM routers WHERE BINARY name = BINARY 'Kalisari' ORDER BY id ASC LIMIT 1
);
SET @tembalang_id := (
    SELECT id FROM routers WHERE BINARY name = BINARY 'Tembalang' ORDER BY id ASC LIMIT 1
);

SELECT @kalisari_id AS kalisari_id, @tembalang_id AS tembalang_id;

-- fail-safe jika Kalisari belum terdeteksi
SET @kalisari_id := IFNULL(@kalisari_id, (SELECT id FROM routers ORDER BY id ASC LIMIT 1));
SELECT @kalisari_id AS effective_kalisari_id;

-- ====================================================
-- STEP 1.5 - PASTIKAN KOLOM ROUTER_ID ADA DI MASTER TABLE
-- ====================================================
SELECT 'STEP 1.5 - ENSURE router_id MASTER TABLE' AS step;
CALL sp_add_router_column_if_missing('ppp_profiles');
CALL sp_add_router_column_if_missing('ip_pools');
CALL sp_add_router_column_if_missing('static_packages');

-- ====================================================
-- STEP 2 - DETECT ORPHAN ROUTER_ID
-- ====================================================
SELECT 'STEP 2 - DETECT ORPHAN ROUTER_ID' AS step;
CALL sp_router_orphan_report('customers');
CALL sp_router_orphan_report('pppoe_secrets');
CALL sp_router_orphan_report('ppp_profiles');
CALL sp_router_orphan_report('ip_pools');
CALL sp_router_orphan_report('static_packages');
CALL sp_router_orphan_report('work_orders');
CALL sp_router_orphan_report('tickets');
CALL sp_router_orphan_report('cashflow_transactions');

-- ====================================================
-- STEP 3 - BIND DATA LAMA KE KALISARI
-- ====================================================
SELECT 'STEP 3 - BIND NULL TO KALISARI' AS step;
CALL sp_bind_router_default('customers', @kalisari_id);
CALL sp_bind_router_default('ppp_profiles', @kalisari_id);
CALL sp_bind_router_default('ip_pools', @kalisari_id);
CALL sp_bind_router_default('static_packages', @kalisari_id);
CALL sp_bind_router_default('work_orders', @kalisari_id);
CALL sp_bind_router_default('tickets', @kalisari_id);
CALL sp_bind_router_default('cashflow_transactions', @kalisari_id);
CALL sp_bind_router_default('pppoe_secrets', @kalisari_id);

-- ====================================================
-- STEP 4 - FIX ORPHAN DATA
-- ====================================================
SELECT 'STEP 4 - FIX ORPHAN ROUTER_ID' AS step;
CALL sp_fix_orphan_router('customers', @kalisari_id);
CALL sp_fix_orphan_router('ppp_profiles', @kalisari_id);
CALL sp_fix_orphan_router('ip_pools', @kalisari_id);
CALL sp_fix_orphan_router('static_packages', @kalisari_id);
CALL sp_fix_orphan_router('work_orders', @kalisari_id);
CALL sp_fix_orphan_router('tickets', @kalisari_id);
CALL sp_fix_orphan_router('cashflow_transactions', @kalisari_id);
CALL sp_fix_orphan_router('pppoe_secrets', @kalisari_id);

-- ====================================================
-- STEP 4.5 - VALIDASI DATA (NULL/ORPHAN HARUS 0)
-- ====================================================
SELECT 'STEP 4.5 - VALIDASI PASCA BIND/FIX' AS step;
CALL sp_router_orphan_report('customers');
CALL sp_router_orphan_report('pppoe_secrets');
CALL sp_router_orphan_report('ppp_profiles');
CALL sp_router_orphan_report('ip_pools');
CALL sp_router_orphan_report('work_orders');
CALL sp_router_orphan_report('tickets');
CALL sp_router_orphan_report('cashflow_transactions');

-- ====================================================
-- STEP 5 - REBUILD FOREIGN KEY SAFELY
-- ====================================================
SELECT 'STEP 5 - REBUILD FK ROUTER' AS step;
CALL sp_rebuild_router_fk('customers', 'fk_customers_router', @kalisari_id);
CALL sp_rebuild_router_fk('pppoe_secrets', 'fk_pppoe_secrets_router', @kalisari_id);
CALL sp_rebuild_router_fk('ppp_profiles', 'fk_ppp_profiles_router', @kalisari_id);
CALL sp_rebuild_router_fk('ip_pools', 'fk_ip_pools_router', @kalisari_id);
CALL sp_rebuild_router_fk('static_packages', 'fk_static_packages_router', @kalisari_id);
CALL sp_rebuild_router_fk('work_orders', 'fk_work_orders_router', @kalisari_id);
CALL sp_rebuild_router_fk('tickets', 'fk_tickets_router', @kalisari_id);
CALL sp_rebuild_router_fk('cashflow_transactions', 'fk_cashflow_transactions_router', @kalisari_id);

-- ====================================================
-- STEP 5.5 - UNIQUE INDEX PPPoE MULTI ROUTER
-- username harus scoped by router_id
-- ====================================================
SELECT 'STEP 5.5 - UNIQUE INDEX pppoe_secrets(router_id,username)' AS step;

SET @has_pppoe := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'pppoe_secrets'
);

SET @dup_same_router := 0;
SET @sql_dup_same_router := IF(
    @has_pppoe > 0,
    'SELECT COUNT(*) INTO @dup_same_router FROM (SELECT router_id, username, COUNT(*) c FROM pppoe_secrets GROUP BY router_id, username HAVING COUNT(*) > 1) x',
    'SELECT 0 INTO @dup_same_router'
);
PREPARE stmt_dup_same_router FROM @sql_dup_same_router;
EXECUTE stmt_dup_same_router;
DEALLOCATE PREPARE stmt_dup_same_router;

SELECT @dup_same_router AS duplicate_router_username_rows;

SET @has_uq_username := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'pppoe_secrets'
      AND index_name = 'uq_pppoe_secrets_username'
      AND non_unique = 0
);

SET @has_uq_router_username := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'pppoe_secrets'
      AND index_name = 'uq_pppoe_secrets_router_username'
      AND non_unique = 0
);

SET @sql_drop_uq := IF(
    @has_pppoe > 0 AND @has_uq_username > 0,
    'ALTER TABLE `pppoe_secrets` DROP INDEX `uq_pppoe_secrets_username`',
    'SELECT ''SKIP_DROP_uq_pppoe_secrets_username'' AS info'
);
PREPARE stmt_drop_uq FROM @sql_drop_uq;
EXECUTE stmt_drop_uq;
DEALLOCATE PREPARE stmt_drop_uq;

SET @sql_add_uq := IF(
    @has_pppoe > 0 AND @dup_same_router = 0 AND @has_uq_router_username = 0,
    'ALTER TABLE `pppoe_secrets` ADD CONSTRAINT `uq_pppoe_secrets_router_username` UNIQUE (`router_id`,`username`)',
    'SELECT ''SKIP_ADD_uq_pppoe_secrets_router_username'' AS info'
);
PREPARE stmt_add_uq FROM @sql_add_uq;
EXECUTE stmt_add_uq;
DEALLOCATE PREPARE stmt_add_uq;

-- ====================================================
-- STEP 5.6 - UNIQUE INDEX MASTER PER ROUTER
-- ppp_profiles: UNIQUE(router_id,name)
-- ip_pools: UNIQUE(router_id,pool_name)
-- ====================================================
SELECT 'STEP 5.6 - UNIQUE INDEX MASTER PER ROUTER' AS step;

SET @dup_ppp_router_name := 0;
SET @dup_ip_router_pool := 0;

SET @sql_dup_ppp_router_name := IF(
    EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ppp_profiles'),
    'SELECT COUNT(*) INTO @dup_ppp_router_name FROM (SELECT router_id, name, COUNT(*) c FROM ppp_profiles GROUP BY router_id, name HAVING COUNT(*) > 1) x',
    'SELECT 0 INTO @dup_ppp_router_name'
);
PREPARE stmt_dup_ppp_router_name FROM @sql_dup_ppp_router_name;
EXECUTE stmt_dup_ppp_router_name;
DEALLOCATE PREPARE stmt_dup_ppp_router_name;

SET @sql_dup_ip_router_pool := IF(
    EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ip_pools'),
    'SELECT COUNT(*) INTO @dup_ip_router_pool FROM (SELECT router_id, pool_name, COUNT(*) c FROM ip_pools GROUP BY router_id, pool_name HAVING COUNT(*) > 1) x',
    'SELECT 0 INTO @dup_ip_router_pool'
);
PREPARE stmt_dup_ip_router_pool FROM @sql_dup_ip_router_pool;
EXECUTE stmt_dup_ip_router_pool;
DEALLOCATE PREPARE stmt_dup_ip_router_pool;

SELECT @dup_ppp_router_name AS duplicate_ppp_profile_per_router, @dup_ip_router_pool AS duplicate_ip_pool_per_router;

SET @has_uq_ppp_name := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'ppp_profiles'
      AND index_name = 'uq_ppp_profiles_name' AND non_unique = 0
);
SET @has_uq_ppp_router_name := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'ppp_profiles'
      AND index_name = 'uq_ppp_profiles_router_name' AND non_unique = 0
);

SET @sql_drop_uq_ppp_name := IF(
    @has_uq_ppp_name > 0,
    'ALTER TABLE `ppp_profiles` DROP INDEX `uq_ppp_profiles_name`',
    'SELECT ''SKIP_DROP_uq_ppp_profiles_name'' AS info'
);
PREPARE stmt_drop_uq_ppp_name FROM @sql_drop_uq_ppp_name;
EXECUTE stmt_drop_uq_ppp_name;
DEALLOCATE PREPARE stmt_drop_uq_ppp_name;

SET @sql_add_uq_ppp_router_name := IF(
    @dup_ppp_router_name = 0 AND @has_uq_ppp_router_name = 0
    AND EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ppp_profiles'),
    'ALTER TABLE `ppp_profiles` ADD CONSTRAINT `uq_ppp_profiles_router_name` UNIQUE (`router_id`,`name`)',
    'SELECT ''SKIP_ADD_uq_ppp_profiles_router_name'' AS info'
);
PREPARE stmt_add_uq_ppp_router_name FROM @sql_add_uq_ppp_router_name;
EXECUTE stmt_add_uq_ppp_router_name;
DEALLOCATE PREPARE stmt_add_uq_ppp_router_name;

SET @has_uq_ip_name := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'ip_pools'
      AND index_name = 'uq_ip_pools_name' AND non_unique = 0
);
SET @has_uq_ip_router_pool := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'ip_pools'
      AND index_name = 'uq_ip_pools_router_pool_name' AND non_unique = 0
);

SET @sql_drop_uq_ip_name := IF(
    @has_uq_ip_name > 0,
    'ALTER TABLE `ip_pools` DROP INDEX `uq_ip_pools_name`',
    'SELECT ''SKIP_DROP_uq_ip_pools_name'' AS info'
);
PREPARE stmt_drop_uq_ip_name FROM @sql_drop_uq_ip_name;
EXECUTE stmt_drop_uq_ip_name;
DEALLOCATE PREPARE stmt_drop_uq_ip_name;

SET @sql_add_uq_ip_router_pool := IF(
    @dup_ip_router_pool = 0 AND @has_uq_ip_router_pool = 0
    AND EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ip_pools'),
    'ALTER TABLE `ip_pools` ADD CONSTRAINT `uq_ip_pools_router_pool_name` UNIQUE (`router_id`,`pool_name`)',
    'SELECT ''SKIP_ADD_uq_ip_pools_router_pool_name'' AS info'
);
PREPARE stmt_add_uq_ip_router_pool FROM @sql_add_uq_ip_router_pool;
EXECUTE stmt_add_uq_ip_router_pool;
DEALLOCATE PREPARE stmt_add_uq_ip_router_pool;

-- ====================================================
-- STEP 6/7/8 - QUERY AUDIT OUTPUT
-- ====================================================
SELECT 'STEP 6/7/8 - FINAL AUDIT' AS step;

SELECT id, name, is_active, ip_address, api_port
FROM routers
ORDER BY id;

SELECT table_name, column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND column_name = 'router_id'
  AND table_name IN (
      'customers','pppoe_secrets','ppp_profiles','ip_pools','static_packages',
      'work_orders','tickets','cashflow_transactions'
  )
ORDER BY table_name;

SELECT table_name, constraint_name, delete_rule, update_rule
FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE()
  AND referenced_table_name = 'routers'
  AND table_name IN (
      'customers','pppoe_secrets','ppp_profiles','ip_pools','static_packages',
      'work_orders','tickets','cashflow_transactions'
  )
ORDER BY table_name, constraint_name;

SELECT 'customers' AS table_name, COUNT(*) AS total_rows, SUM(router_id IS NULL) AS null_router FROM customers
UNION ALL
SELECT 'pppoe_secrets', COUNT(*), SUM(router_id IS NULL) FROM pppoe_secrets
UNION ALL
SELECT 'ppp_profiles', COUNT(*), SUM(router_id IS NULL) FROM ppp_profiles
UNION ALL
SELECT 'ip_pools', COUNT(*), SUM(router_id IS NULL) FROM ip_pools
UNION ALL
SELECT 'work_orders', COUNT(*), SUM(router_id IS NULL) FROM work_orders
UNION ALL
SELECT 'tickets', COUNT(*), SUM(router_id IS NULL) FROM tickets
UNION ALL
SELECT 'cashflow_transactions', COUNT(*), SUM(router_id IS NULL) FROM cashflow_transactions;

SELECT 'DONE' AS repair_status, NOW() AS finished_at;

-- cleanup helper procedures
DROP PROCEDURE IF EXISTS sp_add_router_column_if_missing;
DROP PROCEDURE IF EXISTS sp_insert_router_if_missing;
DROP PROCEDURE IF EXISTS sp_router_orphan_report;
DROP PROCEDURE IF EXISTS sp_bind_router_default;
DROP PROCEDURE IF EXISTS sp_fix_orphan_router;
DROP PROCEDURE IF EXISTS sp_rebuild_router_fk;

SET SQL_SAFE_UPDATES = @__old_sql_safe_updates;
