-- ============================================================
-- MIGRATION: Bind data legacy single-router ke routers.Kalisari
-- Database : nawacore_db (single install)
-- Tujuan   :
--   1) Pastikan router Kalisari tersedia
--   2) Tambah router_id (nullable dulu) pada tabel legacy
--   3) Bind semua data NULL ke router Kalisari
--   4) Validasi NULL = 0
--   5) Ubah router_id menjadi NOT NULL + FK ON DELETE RESTRICT
--   6) Tambah users.router_scope_id dan set default by role
-- Sifat    : Idempotent, aman dijalankan berulang
-- ============================================================

SET @OLD_SQL_SAFE_UPDATES = @@SQL_SAFE_UPDATES;
SET SQL_SAFE_UPDATES = 0;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_bind_legacy_to_kalisari_router $$
CREATE PROCEDURE sp_bind_legacy_to_kalisari_router()
BEGIN
    DECLARE v_db VARCHAR(128) DEFAULT DATABASE();
    DECLARE v_exists INT DEFAULT 0;
    DECLARE v_router_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_null_count BIGINT DEFAULT 0;
    DECLARE v_is_nullable VARCHAR(3) DEFAULT NULL;
    DECLARE v_fk_name VARCHAR(128) DEFAULT NULL;
    DECLARE v_delete_rule VARCHAR(30) DEFAULT NULL;
    DECLARE v_sql TEXT;

    DROP TEMPORARY TABLE IF EXISTS tmp_migration_warnings;
    CREATE TEMPORARY TABLE tmp_migration_warnings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        level_type VARCHAR(16) NOT NULL,
        step_name VARCHAR(128) NOT NULL,
        detail VARCHAR(1000) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=MEMORY;

    DROP TEMPORARY TABLE IF EXISTS tmp_null_router_counts;
    CREATE TEMPORARY TABLE tmp_null_router_counts (
        table_name VARCHAR(128) NOT NULL PRIMARY KEY,
        null_router_id BIGINT NOT NULL
    ) ENGINE=MEMORY;

    -- ========================================================
    -- STEP 0 - Validasi tabel routers + pastikan router Kalisari
    -- ========================================================
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'routers';

    IF v_exists = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration aborted: tabel `routers` tidak ditemukan.';
    END IF;

    SELECT id INTO v_router_id
    FROM routers
    WHERE LOWER(COALESCE(name, '')) = 'kalisari'
    ORDER BY is_active DESC, id ASC
    LIMIT 1;

    IF v_router_id IS NULL THEN
        INSERT INTO routers (
            name,
            router_name,
            ip_address,
            api_host,
            api_port,
            username,
            password,
            api_username,
            api_password_enc,
            is_active,
            status,
            description,
            created_at,
            updated_at
        ) VALUES (
            'Kalisari',
            'Kalisari',
            NULL,
            '',
            8728,
            NULL,
            NULL,
            '',
            '',
            1,
            'active',
            'Auto-created by migration for legacy router binding',
            NOW(),
            NOW()
        );

        SET v_router_id = LAST_INSERT_ID();

        INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
        VALUES ('INFO', 'STEP0', CONCAT('Router Kalisari tidak ditemukan, dibuat otomatis dengan ID=', v_router_id));
    ELSE
        INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
        VALUES ('INFO', 'STEP0', CONCAT('Router Kalisari ditemukan dengan ID=', v_router_id));
    END IF;

    -- ========================================================
    -- Helper pattern per tabel target
    -- Tabel: customers, invoices, pppoe_secrets, work_orders,
    --        tickets, cashflow_transactions, telegram_groups
    -- ========================================================

    -- --------------------------------------------------------
    -- TABLE: customers
    -- --------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'customers';

    IF v_exists = 0 THEN
        INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
        VALUES ('WARN', 'customers', 'Tabel tidak ditemukan, step dilewati.');
    ELSE
        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'customers'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE customers
                ADD COLUMN router_id BIGINT(20) UNSIGNED NULL;
        END IF;

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'customers'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE customers
                ADD INDEX idx_customers_router_id (router_id);
        END IF;

        UPDATE customers
        SET router_id = v_router_id
        WHERE router_id IS NULL;

        SELECT COUNT(*) INTO v_null_count
        FROM customers
        WHERE router_id IS NULL;

        IF v_null_count > 0 THEN
            INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
            VALUES ('WARN', 'customers', CONCAT('Masih ada ', v_null_count, ' row router_id NULL. NOT NULL/FK tidak diterapkan.'));
        ELSE
            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'customers'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NOT NULL AND UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE customers DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;
            END IF;

            SELECT IS_NULLABLE INTO v_is_nullable
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = v_db
              AND TABLE_NAME = 'customers'
              AND COLUMN_NAME = 'router_id'
            LIMIT 1;

            IF v_is_nullable = 'YES' THEN
                ALTER TABLE customers
                    MODIFY router_id BIGINT(20) UNSIGNED NOT NULL;
            END IF;

            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'customers'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NULL THEN
                ALTER TABLE customers
                    ADD CONSTRAINT fk_customers_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            ELSEIF UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE customers DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;

                ALTER TABLE customers
                    ADD CONSTRAINT fk_customers_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            END IF;
        END IF;
    END IF;

    -- --------------------------------------------------------
    -- TABLE: invoices
    -- --------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'invoices';

    IF v_exists = 0 THEN
        INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
        VALUES ('WARN', 'invoices', 'Tabel tidak ditemukan, step dilewati.');
    ELSE
        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'invoices'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE invoices
                ADD COLUMN router_id BIGINT(20) UNSIGNED NULL;
        END IF;

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'invoices'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE invoices
                ADD INDEX idx_invoices_router_id (router_id);
        END IF;

        UPDATE invoices
        SET router_id = v_router_id
        WHERE router_id IS NULL;

        SELECT COUNT(*) INTO v_null_count
        FROM invoices
        WHERE router_id IS NULL;

        IF v_null_count > 0 THEN
            INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
            VALUES ('WARN', 'invoices', CONCAT('Masih ada ', v_null_count, ' row router_id NULL. NOT NULL/FK tidak diterapkan.'));
        ELSE
            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'invoices'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NOT NULL AND UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE invoices DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;
            END IF;

            SELECT IS_NULLABLE INTO v_is_nullable
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = v_db
              AND TABLE_NAME = 'invoices'
              AND COLUMN_NAME = 'router_id'
            LIMIT 1;

            IF v_is_nullable = 'YES' THEN
                ALTER TABLE invoices
                    MODIFY router_id BIGINT(20) UNSIGNED NOT NULL;
            END IF;

            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'invoices'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NULL THEN
                ALTER TABLE invoices
                    ADD CONSTRAINT fk_invoices_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            ELSEIF UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE invoices DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;

                ALTER TABLE invoices
                    ADD CONSTRAINT fk_invoices_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            END IF;
        END IF;
    END IF;

    -- --------------------------------------------------------
    -- TABLE: pppoe_secrets
    -- --------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'pppoe_secrets';

    IF v_exists = 0 THEN
        INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
        VALUES ('WARN', 'pppoe_secrets', 'Tabel tidak ditemukan, step dilewati.');
    ELSE
        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'pppoe_secrets'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE pppoe_secrets
                ADD COLUMN router_id BIGINT(20) UNSIGNED NULL;
        END IF;

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'pppoe_secrets'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE pppoe_secrets
                ADD INDEX idx_pppoe_secrets_router_id (router_id);
        END IF;

        UPDATE pppoe_secrets
        SET router_id = v_router_id
        WHERE router_id IS NULL;

        SELECT COUNT(*) INTO v_null_count
        FROM pppoe_secrets
        WHERE router_id IS NULL;

        IF v_null_count > 0 THEN
            INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
            VALUES ('WARN', 'pppoe_secrets', CONCAT('Masih ada ', v_null_count, ' row router_id NULL. NOT NULL/FK tidak diterapkan.'));
        ELSE
            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'pppoe_secrets'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NOT NULL AND UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE pppoe_secrets DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;
            END IF;

            SELECT IS_NULLABLE INTO v_is_nullable
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = v_db
              AND TABLE_NAME = 'pppoe_secrets'
              AND COLUMN_NAME = 'router_id'
            LIMIT 1;

            IF v_is_nullable = 'YES' THEN
                ALTER TABLE pppoe_secrets
                    MODIFY router_id BIGINT(20) UNSIGNED NOT NULL;
            END IF;

            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'pppoe_secrets'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NULL THEN
                ALTER TABLE pppoe_secrets
                    ADD CONSTRAINT fk_pppoe_secrets_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            ELSEIF UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE pppoe_secrets DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;

                ALTER TABLE pppoe_secrets
                    ADD CONSTRAINT fk_pppoe_secrets_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            END IF;
        END IF;
    END IF;

    -- --------------------------------------------------------
    -- TABLE: work_orders
    -- --------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'work_orders';

    IF v_exists = 0 THEN
        INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
        VALUES ('WARN', 'work_orders', 'Tabel tidak ditemukan, step dilewati.');
    ELSE
        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'work_orders'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE work_orders
                ADD COLUMN router_id BIGINT(20) UNSIGNED NULL;
        END IF;

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'work_orders'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE work_orders
                ADD INDEX idx_work_orders_router_id (router_id);
        END IF;

        UPDATE work_orders
        SET router_id = v_router_id
        WHERE router_id IS NULL;

        SELECT COUNT(*) INTO v_null_count
        FROM work_orders
        WHERE router_id IS NULL;

        IF v_null_count > 0 THEN
            INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
            VALUES ('WARN', 'work_orders', CONCAT('Masih ada ', v_null_count, ' row router_id NULL. NOT NULL/FK tidak diterapkan.'));
        ELSE
            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'work_orders'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NOT NULL AND UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE work_orders DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;
            END IF;

            SELECT IS_NULLABLE INTO v_is_nullable
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = v_db
              AND TABLE_NAME = 'work_orders'
              AND COLUMN_NAME = 'router_id'
            LIMIT 1;

            IF v_is_nullable = 'YES' THEN
                ALTER TABLE work_orders
                    MODIFY router_id BIGINT(20) UNSIGNED NOT NULL;
            END IF;

            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'work_orders'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NULL THEN
                ALTER TABLE work_orders
                    ADD CONSTRAINT fk_work_orders_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            ELSEIF UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE work_orders DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;

                ALTER TABLE work_orders
                    ADD CONSTRAINT fk_work_orders_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            END IF;
        END IF;
    END IF;

    -- --------------------------------------------------------
    -- TABLE: tickets
    -- --------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'tickets';

    IF v_exists = 0 THEN
        INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
        VALUES ('WARN', 'tickets', 'Tabel tidak ditemukan, step dilewati.');
    ELSE
        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'tickets'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE tickets
                ADD COLUMN router_id BIGINT(20) UNSIGNED NULL;
        END IF;

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'tickets'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE tickets
                ADD INDEX idx_tickets_router_id (router_id);
        END IF;

        UPDATE tickets
        SET router_id = v_router_id
        WHERE router_id IS NULL;

        SELECT COUNT(*) INTO v_null_count
        FROM tickets
        WHERE router_id IS NULL;

        IF v_null_count > 0 THEN
            INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
            VALUES ('WARN', 'tickets', CONCAT('Masih ada ', v_null_count, ' row router_id NULL. NOT NULL/FK tidak diterapkan.'));
        ELSE
            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'tickets'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NOT NULL AND UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE tickets DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;
            END IF;

            SELECT IS_NULLABLE INTO v_is_nullable
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = v_db
              AND TABLE_NAME = 'tickets'
              AND COLUMN_NAME = 'router_id'
            LIMIT 1;

            IF v_is_nullable = 'YES' THEN
                ALTER TABLE tickets
                    MODIFY router_id BIGINT(20) UNSIGNED NOT NULL;
            END IF;

            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'tickets'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NULL THEN
                ALTER TABLE tickets
                    ADD CONSTRAINT fk_tickets_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            ELSEIF UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE tickets DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;

                ALTER TABLE tickets
                    ADD CONSTRAINT fk_tickets_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            END IF;
        END IF;
    END IF;

    -- --------------------------------------------------------
    -- TABLE: cashflow_transactions
    -- --------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'cashflow_transactions';

    IF v_exists = 0 THEN
        INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
        VALUES ('WARN', 'cashflow_transactions', 'Tabel tidak ditemukan, step dilewati.');
    ELSE
        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'cashflow_transactions'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE cashflow_transactions
                ADD COLUMN router_id BIGINT(20) UNSIGNED NULL;
        END IF;

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'cashflow_transactions'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE cashflow_transactions
                ADD INDEX idx_cashflow_transactions_router_id (router_id);
        END IF;

        UPDATE cashflow_transactions
        SET router_id = v_router_id
        WHERE router_id IS NULL;

        SELECT COUNT(*) INTO v_null_count
        FROM cashflow_transactions
        WHERE router_id IS NULL;

        IF v_null_count > 0 THEN
            INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
            VALUES ('WARN', 'cashflow_transactions', CONCAT('Masih ada ', v_null_count, ' row router_id NULL. NOT NULL/FK tidak diterapkan.'));
        ELSE
            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'cashflow_transactions'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NOT NULL AND UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE cashflow_transactions DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;
            END IF;

            SELECT IS_NULLABLE INTO v_is_nullable
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = v_db
              AND TABLE_NAME = 'cashflow_transactions'
              AND COLUMN_NAME = 'router_id'
            LIMIT 1;

            IF v_is_nullable = 'YES' THEN
                ALTER TABLE cashflow_transactions
                    MODIFY router_id BIGINT(20) UNSIGNED NOT NULL;
            END IF;

            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'cashflow_transactions'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NULL THEN
                ALTER TABLE cashflow_transactions
                    ADD CONSTRAINT fk_cashflow_transactions_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            ELSEIF UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE cashflow_transactions DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;

                ALTER TABLE cashflow_transactions
                    ADD CONSTRAINT fk_cashflow_transactions_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            END IF;
        END IF;
    END IF;

    -- --------------------------------------------------------
    -- TABLE: telegram_groups
    -- --------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'telegram_groups';

    IF v_exists = 0 THEN
        INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
        VALUES ('WARN', 'telegram_groups', 'Tabel tidak ditemukan, step dilewati.');
    ELSE
        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'telegram_groups'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE telegram_groups
                ADD COLUMN router_id BIGINT(20) UNSIGNED NULL;
        END IF;

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'telegram_groups'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE telegram_groups
                ADD INDEX idx_telegram_groups_router_id (router_id);
        END IF;

        UPDATE telegram_groups
        SET router_id = v_router_id
        WHERE router_id IS NULL;

        SELECT COUNT(*) INTO v_null_count
        FROM telegram_groups
        WHERE router_id IS NULL;

        IF v_null_count > 0 THEN
            INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
            VALUES ('WARN', 'telegram_groups', CONCAT('Masih ada ', v_null_count, ' row router_id NULL. NOT NULL/FK tidak diterapkan.'));
        ELSE
            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'telegram_groups'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NOT NULL AND UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE telegram_groups DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;
            END IF;

            SELECT IS_NULLABLE INTO v_is_nullable
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = v_db
              AND TABLE_NAME = 'telegram_groups'
              AND COLUMN_NAME = 'router_id'
            LIMIT 1;

            IF v_is_nullable = 'YES' THEN
                ALTER TABLE telegram_groups
                    MODIFY router_id BIGINT(20) UNSIGNED NOT NULL;
            END IF;

            SET v_fk_name = NULL;
            SET v_delete_rule = NULL;
            SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
              INTO v_fk_name, v_delete_rule
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             AND rc.TABLE_NAME = kcu.TABLE_NAME
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = v_db
              AND kcu.TABLE_NAME = 'telegram_groups'
              AND kcu.COLUMN_NAME = 'router_id'
              AND kcu.REFERENCED_TABLE_NAME = 'routers'
              AND kcu.REFERENCED_COLUMN_NAME = 'id'
            LIMIT 1;

            IF v_fk_name IS NULL THEN
                ALTER TABLE telegram_groups
                    ADD CONSTRAINT fk_telegram_groups_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            ELSEIF UPPER(COALESCE(v_delete_rule, '')) <> 'RESTRICT' THEN
                SET v_sql = CONCAT('ALTER TABLE telegram_groups DROP FOREIGN KEY `', v_fk_name, '`');
                PREPARE stmt FROM v_sql;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;

                ALTER TABLE telegram_groups
                    ADD CONSTRAINT fk_telegram_groups_router
                    FOREIGN KEY (router_id) REFERENCES routers(id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT;
            END IF;
        END IF;
    END IF;

    -- ========================================================
    -- STEP 6 - users.router_scope_id
    -- superadmin -> NULL
    -- admin/teknisi -> Kalisari (jika NULL/0)
    -- ========================================================
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'users';

    IF v_exists = 0 THEN
        INSERT INTO tmp_migration_warnings(level_type, step_name, detail)
        VALUES ('WARN', 'users', 'Tabel users tidak ditemukan, step dilewati.');
    ELSE
        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'router_scope_id';

        IF v_exists = 0 THEN
            ALTER TABLE users
                ADD COLUMN router_scope_id BIGINT(20) UNSIGNED NULL AFTER role;
        END IF;

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'router_scope_id';

        IF v_exists = 0 THEN
            ALTER TABLE users
                ADD INDEX idx_users_router_scope_id (router_scope_id);
        END IF;

        UPDATE users
        SET router_scope_id = NULL
        WHERE LOWER(COALESCE(role, '')) = 'superadmin';

        UPDATE users
        SET router_scope_id = v_router_id
        WHERE LOWER(COALESCE(role, '')) IN ('admin', 'teknisi')
          AND (router_scope_id IS NULL OR router_scope_id = 0);

        SET v_fk_name = NULL;
        SET v_delete_rule = NULL;
        SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
          INTO v_fk_name, v_delete_rule
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
        JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
          ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
         AND rc.TABLE_NAME = kcu.TABLE_NAME
         AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
        WHERE kcu.TABLE_SCHEMA = v_db
          AND kcu.TABLE_NAME = 'users'
          AND kcu.COLUMN_NAME = 'router_scope_id'
          AND kcu.REFERENCED_TABLE_NAME = 'routers'
          AND kcu.REFERENCED_COLUMN_NAME = 'id'
        LIMIT 1;

        IF v_fk_name IS NULL THEN
            ALTER TABLE users
                ADD CONSTRAINT fk_users_router_scope
                FOREIGN KEY (router_scope_id) REFERENCES routers(id)
                ON UPDATE CASCADE
                ON DELETE SET NULL;
        END IF;
    END IF;

    -- ========================================================
    -- Output warnings + summary validasi
    -- ========================================================
    SELECT *
    FROM tmp_migration_warnings
    ORDER BY id;

    -- summary null count per tabel (tetap aman jika tabel tertentu tidak ada)
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'customers';
    IF v_exists > 0 THEN
        SELECT COUNT(*) INTO v_null_count FROM customers WHERE router_id IS NULL;
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('customers', v_null_count);
    ELSE
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('customers', -1);
    END IF;

    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'invoices';
    IF v_exists > 0 THEN
        SELECT COUNT(*) INTO v_null_count FROM invoices WHERE router_id IS NULL;
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('invoices', v_null_count);
    ELSE
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('invoices', -1);
    END IF;

    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'pppoe_secrets';
    IF v_exists > 0 THEN
        SELECT COUNT(*) INTO v_null_count FROM pppoe_secrets WHERE router_id IS NULL;
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('pppoe_secrets', v_null_count);
    ELSE
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('pppoe_secrets', -1);
    END IF;

    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'work_orders';
    IF v_exists > 0 THEN
        SELECT COUNT(*) INTO v_null_count FROM work_orders WHERE router_id IS NULL;
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('work_orders', v_null_count);
    ELSE
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('work_orders', -1);
    END IF;

    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'tickets';
    IF v_exists > 0 THEN
        SELECT COUNT(*) INTO v_null_count FROM tickets WHERE router_id IS NULL;
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('tickets', v_null_count);
    ELSE
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('tickets', -1);
    END IF;

    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'cashflow_transactions';
    IF v_exists > 0 THEN
        SELECT COUNT(*) INTO v_null_count FROM cashflow_transactions WHERE router_id IS NULL;
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('cashflow_transactions', v_null_count);
    ELSE
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('cashflow_transactions', -1);
    END IF;

    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'telegram_groups';
    IF v_exists > 0 THEN
        SELECT COUNT(*) INTO v_null_count FROM telegram_groups WHERE router_id IS NULL;
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('telegram_groups', v_null_count);
    ELSE
        INSERT INTO tmp_null_router_counts(table_name, null_router_id) VALUES ('telegram_groups', -1);
    END IF;

    SELECT table_name, null_router_id
    FROM tmp_null_router_counts
    ORDER BY table_name;

    SELECT
        SUM(CASE WHEN LOWER(COALESCE(role, '')) = 'superadmin' AND router_scope_id IS NULL THEN 1 ELSE 0 END) AS superadmin_scope_ok,
        SUM(CASE WHEN LOWER(COALESCE(role, '')) IN ('admin','teknisi') AND router_scope_id = v_router_id THEN 1 ELSE 0 END) AS admin_teknisi_bound_to_kalisari,
        SUM(CASE WHEN LOWER(COALESCE(role, '')) IN ('admin','teknisi') AND (router_scope_id IS NULL OR router_scope_id = 0) THEN 1 ELSE 0 END) AS admin_teknisi_scope_null
    FROM users;
END $$
DELIMITER ;

-- Eksekusi migration
CALL sp_bind_legacy_to_kalisari_router();

-- Cleanup procedure
DROP PROCEDURE IF EXISTS sp_bind_legacy_to_kalisari_router;

SET SQL_SAFE_UPDATES = @OLD_SQL_SAFE_UPDATES;

-- ============================================================
-- Catatan Eksekusi Aman (Production):
-- 1) Backup database dulu.
-- 2) Jalankan script ini sekali.
-- 3) Cek resultset warning dan null_router_id.
-- 4) Jika semua null_router_id = 0, migration sukses.
-- ============================================================
