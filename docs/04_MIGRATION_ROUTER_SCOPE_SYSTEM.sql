-- ============================================================
-- MIGRATION: Router Scope System (Single Install CI3)
-- Database : nawacore_db
-- Tujuan   : Scope user per router + router_id di modul utama
-- Sifat    : Idempotent (aman dijalankan berulang)
-- ============================================================

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 1;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_migrate_router_scope_system $$
CREATE PROCEDURE sp_migrate_router_scope_system()
BEGIN
    DECLARE v_db VARCHAR(128);
    DECLARE v_exists INT DEFAULT 0;

    SET v_db = DATABASE();

    -- ------------------------------------------------------------
    -- STEP 0: validasi tabel routers
    -- ------------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'routers';

    IF v_exists = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration aborted: tabel `routers` tidak ditemukan.';
    END IF;

    -- ------------------------------------------------------------
    -- STEP 1: users.router_scope_id (nullable)
    -- NULL = akses semua router (superadmin)
    -- ------------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'router_scope_id';

    IF v_exists = 0 THEN
        ALTER TABLE users
            ADD COLUMN router_scope_id BIGINT(20) UNSIGNED NULL AFTER role;
    END IF;

    -- Index users.router_scope_id
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'router_scope_id';

    IF v_exists = 0 THEN
        ALTER TABLE users
            ADD INDEX idx_users_router_scope_id (router_scope_id);
    END IF;

    -- FK users.router_scope_id -> routers.id
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'users'
      AND CONSTRAINT_NAME = 'fk_users_router_scope_router'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY';

    IF v_exists = 0 THEN
        UPDATE users
        SET router_scope_id = NULL
        WHERE router_scope_id IS NOT NULL
          AND router_scope_id NOT IN (SELECT id FROM routers);

        ALTER TABLE users
            ADD CONSTRAINT fk_users_router_scope_router
            FOREIGN KEY (router_scope_id) REFERENCES routers(id)
            ON UPDATE CASCADE
            ON DELETE SET NULL;
    END IF;

    -- ------------------------------------------------------------
    -- STEP 2: customer_services.router_id
    -- ------------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'customer_services';

    IF v_exists > 0 THEN
        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'customer_services'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE customer_services
                ADD COLUMN router_id BIGINT(20) UNSIGNED NULL;
        END IF;

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'customer_services'
          AND COLUMN_NAME = 'router_id';

        IF v_exists = 0 THEN
            ALTER TABLE customer_services
                ADD INDEX idx_customer_services_router_id (router_id);
        END IF;

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'customer_services'
          AND CONSTRAINT_NAME = 'fk_customer_services_router'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY';

        IF v_exists = 0 THEN
            UPDATE customer_services
            SET router_id = NULL
            WHERE router_id IS NOT NULL
              AND router_id NOT IN (SELECT id FROM routers);

            ALTER TABLE customer_services
                ADD CONSTRAINT fk_customer_services_router
                FOREIGN KEY (router_id) REFERENCES routers(id)
                ON UPDATE CASCADE
                ON DELETE SET NULL;
        END IF;
    END IF;

    -- ------------------------------------------------------------
    -- STEP 3: invoices.router_id
    -- ------------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'invoices';

    IF v_exists > 0 THEN
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

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'invoices'
          AND CONSTRAINT_NAME = 'fk_invoices_router'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY';

        IF v_exists = 0 THEN
            UPDATE invoices
            SET router_id = NULL
            WHERE router_id IS NOT NULL
              AND router_id NOT IN (SELECT id FROM routers);

            ALTER TABLE invoices
                ADD CONSTRAINT fk_invoices_router
                FOREIGN KEY (router_id) REFERENCES routers(id)
                ON UPDATE CASCADE
                ON DELETE SET NULL;
        END IF;
    END IF;

    -- ------------------------------------------------------------
    -- STEP 4: cashflow_transactions.router_id
    -- ------------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'cashflow_transactions';

    IF v_exists > 0 THEN
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

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'cashflow_transactions'
          AND CONSTRAINT_NAME = 'fk_cashflow_transactions_router'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY';

        IF v_exists = 0 THEN
            UPDATE cashflow_transactions
            SET router_id = NULL
            WHERE router_id IS NOT NULL
              AND router_id NOT IN (SELECT id FROM routers);

            ALTER TABLE cashflow_transactions
                ADD CONSTRAINT fk_cashflow_transactions_router
                FOREIGN KEY (router_id) REFERENCES routers(id)
                ON UPDATE CASCADE
                ON DELETE SET NULL;
        END IF;
    END IF;

    -- ------------------------------------------------------------
    -- STEP 5: work_orders.router_id
    -- ------------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'work_orders';

    IF v_exists > 0 THEN
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

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'work_orders'
          AND CONSTRAINT_NAME = 'fk_work_orders_router'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY';

        IF v_exists = 0 THEN
            UPDATE work_orders
            SET router_id = NULL
            WHERE router_id IS NOT NULL
              AND router_id NOT IN (SELECT id FROM routers);

            ALTER TABLE work_orders
                ADD CONSTRAINT fk_work_orders_router
                FOREIGN KEY (router_id) REFERENCES routers(id)
                ON UPDATE CASCADE
                ON DELETE SET NULL;
        END IF;
    END IF;

    -- ------------------------------------------------------------
    -- STEP 6: tickets.router_id
    -- ------------------------------------------------------------
    SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = v_db
      AND TABLE_NAME = 'tickets';

    IF v_exists > 0 THEN
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

        SELECT COUNT(*) INTO v_exists
        FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = v_db
          AND TABLE_NAME = 'tickets'
          AND CONSTRAINT_NAME = 'fk_tickets_router'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY';

        IF v_exists = 0 THEN
            UPDATE tickets
            SET router_id = NULL
            WHERE router_id IS NOT NULL
              AND router_id NOT IN (SELECT id FROM routers);

            ALTER TABLE tickets
                ADD CONSTRAINT fk_tickets_router
                FOREIGN KEY (router_id) REFERENCES routers(id)
                ON UPDATE CASCADE
                ON DELETE SET NULL;
        END IF;
    END IF;
END $$
DELIMITER ;

-- Jalankan migration
CALL sp_migrate_router_scope_system();

-- Cleanup prosedur
DROP PROCEDURE IF EXISTS sp_migrate_router_scope_system;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- ============================================================
-- VALIDASI CEPAT (opsional, jalankan setelah migration)
-- ============================================================
-- SHOW COLUMNS FROM users;
-- SHOW COLUMNS FROM customer_services;
-- SHOW COLUMNS FROM invoices;
-- SHOW COLUMNS FROM cashflow_transactions;
-- SHOW COLUMNS FROM work_orders;
-- SHOW COLUMNS FROM tickets;
--
-- SELECT TABLE_NAME, COLUMN_NAME
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND COLUMN_NAME IN ('router_scope_id', 'router_id')
--   AND TABLE_NAME IN ('users','customer_services','invoices','cashflow_transactions','work_orders','tickets');
--
-- SELECT TABLE_NAME, CONSTRAINT_NAME
-- FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND CONSTRAINT_TYPE = 'FOREIGN KEY'
--   AND CONSTRAINT_NAME IN (
--     'fk_users_router_scope_router',
--     'fk_customer_services_router',
--     'fk_invoices_router',
--     'fk_cashflow_transactions_router',
--     'fk_work_orders_router',
--     'fk_tickets_router'
--   );
