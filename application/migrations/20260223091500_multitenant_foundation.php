<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Multitenant_foundation extends CI_Migration
{
    private $tenant_tables = array(
        'users' => true,
        'customers' => false,
        'customer_services' => false,
        'invoices' => false,
        'invoice_items' => false,
        'payments' => false,
        'tickets' => false,
        'ticket_comments' => false,
        'work_orders' => false,
        'cashflow_transactions' => false,
        'ppp_profiles' => false,
        'ip_pools' => false,
        'pppoe_secrets' => false,
        'sync_logs' => false,
        'system_logs' => false,
        'user_activity_logs' => false,
    );

    private $tenant_fk_map = array(
        'users' => array('fk' => 'fk_users_tenant', 'on_delete' => 'SET NULL'),
        'customers' => array('fk' => 'fk_customers_tenant', 'on_delete' => 'RESTRICT'),
        'customer_services' => array('fk' => 'fk_customer_services_tenant', 'on_delete' => 'RESTRICT'),
        'invoices' => array('fk' => 'fk_invoices_tenant', 'on_delete' => 'RESTRICT'),
        'invoice_items' => array('fk' => 'fk_invoice_items_tenant', 'on_delete' => 'RESTRICT'),
        'payments' => array('fk' => 'fk_payments_tenant', 'on_delete' => 'RESTRICT'),
        'tickets' => array('fk' => 'fk_tickets_tenant', 'on_delete' => 'RESTRICT'),
        'ticket_comments' => array('fk' => 'fk_ticket_comments_tenant', 'on_delete' => 'RESTRICT'),
        'work_orders' => array('fk' => 'fk_work_orders_tenant', 'on_delete' => 'RESTRICT'),
        'cashflow_transactions' => array('fk' => 'fk_cashflow_tenant', 'on_delete' => 'RESTRICT'),
        'ppp_profiles' => array('fk' => 'fk_ppp_profiles_tenant', 'on_delete' => 'RESTRICT'),
        'ip_pools' => array('fk' => 'fk_ip_pools_tenant', 'on_delete' => 'RESTRICT'),
        'pppoe_secrets' => array('fk' => 'fk_pppoe_secrets_tenant', 'on_delete' => 'RESTRICT'),
        'sync_logs' => array('fk' => 'fk_sync_logs_tenant', 'on_delete' => 'RESTRICT'),
        'system_logs' => array('fk' => 'fk_system_logs_tenant', 'on_delete' => 'RESTRICT'),
        'user_activity_logs' => array('fk' => 'fk_user_activity_logs_tenant', 'on_delete' => 'RESTRICT'),
    );

    private $unique_scope_map = array(
        'users' => array(
            array('old' => 'uq_users_username', 'new' => 'uq_users_tenant_username', 'columns' => array('tenant_id', 'username'), 'legacy_columns' => array('username')),
        ),
        'customers' => array(
            array('old' => 'uq_customers_code', 'new' => 'uq_customers_tenant_code', 'columns' => array('tenant_id', 'customer_code'), 'legacy_columns' => array('customer_code')),
        ),
        'customer_services' => array(
            array('old' => 'uq_customer_services_number', 'new' => 'uq_customer_services_tenant_number', 'columns' => array('tenant_id', 'service_number'), 'legacy_columns' => array('service_number')),
            array('old' => 'uq_customer_services_pppoe', 'new' => 'uq_customer_services_tenant_pppoe', 'columns' => array('tenant_id', 'pppoe_username'), 'legacy_columns' => array('pppoe_username')),
        ),
        'invoices' => array(
            array('old' => 'uq_invoices_number', 'new' => 'uq_invoices_tenant_number', 'columns' => array('tenant_id', 'invoice_number'), 'legacy_columns' => array('invoice_number')),
        ),
        'payments' => array(
            array('old' => 'uq_payments_number', 'new' => 'uq_payments_tenant_number', 'columns' => array('tenant_id', 'payment_number'), 'legacy_columns' => array('payment_number')),
        ),
        'tickets' => array(
            array('old' => 'uq_tickets_number', 'new' => 'uq_tickets_tenant_number', 'columns' => array('tenant_id', 'ticket_number'), 'legacy_columns' => array('ticket_number')),
        ),
        'work_orders' => array(
            array('old' => 'uq_work_orders_number', 'new' => 'uq_work_orders_tenant_number', 'columns' => array('tenant_id', 'wo_number'), 'legacy_columns' => array('wo_number')),
        ),
        'cashflow_transactions' => array(
            array('old' => 'uq_cashflow_txn_number', 'new' => 'uq_cashflow_tenant_txn_number', 'columns' => array('tenant_id', 'txn_number'), 'legacy_columns' => array('txn_number')),
        ),
        'ppp_profiles' => array(
            array('old' => 'name', 'new' => 'uq_ppp_profiles_tenant_name', 'columns' => array('tenant_id', 'name'), 'legacy_columns' => array('name')),
        ),
        'ip_pools' => array(
            array('old' => 'pool_name', 'new' => 'uq_ip_pools_tenant_pool_name', 'columns' => array('tenant_id', 'pool_name'), 'legacy_columns' => array('pool_name')),
        ),
        'pppoe_secrets' => array(
            array('old' => 'uk_pppoe_secrets_username', 'new' => 'uq_pppoe_secrets_tenant_username', 'columns' => array('tenant_id', 'username'), 'legacy_columns' => array('username')),
        ),
    );

    public function up()
    {
        $this->create_foundation_tables();
        $default_tenant_id = $this->ensure_default_tenant();

        foreach ($this->tenant_tables as $table => $is_nullable) {
            $this->ensure_tenant_column($table, (bool) $is_nullable, $default_tenant_id);
        }

        $this->upgrade_roles($default_tenant_id);
        $this->scope_unique_constraints();
        $this->add_tenant_foreign_keys();
    }

    public function down()
    {
        $this->drop_tenant_foreign_keys();
        $this->restore_legacy_unique_constraints();
        $this->downgrade_roles();

        foreach (array_keys($this->tenant_tables) as $table) {
            $this->drop_tenant_column($table);
        }

        $drop_tables = array(
            'background_jobs',
            'telegram_groups',
            'routers',
            'tenant_invoices',
            'tenant_subscriptions',
            'packages',
            'tenants',
        );

        foreach ($drop_tables as $table) {
            if ($this->table_exists($table)) {
                $this->db->query("DROP TABLE `{$table}`");
            }
        }
    }

    private function create_foundation_tables()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `packages` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `package_code` VARCHAR(50) NOT NULL,
                `package_name` VARCHAR(120) NOT NULL,
                `billing_cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
                `price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `max_customers` INT(10) UNSIGNED NOT NULL DEFAULT 1000,
                `max_routers` INT(10) UNSIGNED NOT NULL DEFAULT 3,
                `features_json` LONGTEXT NULL,
                `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_packages_code` (`package_code`),
                KEY `idx_packages_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `tenants` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_code` VARCHAR(50) NOT NULL,
                `tenant_name` VARCHAR(150) NOT NULL,
                `domain` VARCHAR(190) DEFAULT NULL,
                `timezone` VARCHAR(50) NOT NULL DEFAULT 'Asia/Jakarta',
                `status` ENUM('trial','active','suspended','terminated') NOT NULL DEFAULT 'active',
                `max_customers` INT(10) UNSIGNED NOT NULL DEFAULT 1000,
                `metadata_json` LONGTEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_tenants_code` (`tenant_code`),
                UNIQUE KEY `uq_tenants_domain` (`domain`),
                KEY `idx_tenants_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `tenant_subscriptions` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
                `package_id` BIGINT(20) UNSIGNED NOT NULL,
                `start_date` DATE NOT NULL,
                `end_date` DATE DEFAULT NULL,
                `next_billing_date` DATE DEFAULT NULL,
                `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `status` ENUM('trial','active','grace','expired','cancelled') NOT NULL DEFAULT 'active',
                `notes` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_tenant_subscriptions_tenant` (`tenant_id`),
                KEY `idx_tenant_subscriptions_package` (`package_id`),
                KEY `idx_tenant_subscriptions_status` (`status`),
                CONSTRAINT `fk_tenant_subscriptions_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_tenant_subscriptions_package` FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `tenant_invoices` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
                `subscription_id` BIGINT(20) UNSIGNED NOT NULL,
                `invoice_number` VARCHAR(50) NOT NULL,
                `period_month` CHAR(7) NOT NULL,
                `issue_date` DATE NOT NULL,
                `due_date` DATE NOT NULL,
                `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `balance_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `status` ENUM('issued','partially_paid','paid','overdue','cancelled') NOT NULL DEFAULT 'issued',
                `paid_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_tenant_invoices_tenant_number` (`tenant_id`, `invoice_number`),
                KEY `idx_tenant_invoices_subscription` (`subscription_id`),
                KEY `idx_tenant_invoices_period` (`tenant_id`, `period_month`),
                KEY `idx_tenant_invoices_status_due` (`status`, `due_date`),
                CONSTRAINT `fk_tenant_invoices_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_tenant_invoices_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `tenant_subscriptions`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `routers` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
                `router_name` VARCHAR(120) NOT NULL,
                `api_host` VARCHAR(120) NOT NULL,
                `api_port` INT(10) UNSIGNED NOT NULL DEFAULT 8728,
                `api_username` VARCHAR(100) NOT NULL,
                `api_password_enc` TEXT NOT NULL,
                `use_ssl` TINYINT(1) NOT NULL DEFAULT 0,
                `timeout_seconds` INT(10) UNSIGNED NOT NULL DEFAULT 5,
                `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
                `metadata_json` LONGTEXT NULL,
                `last_seen_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_routers_tenant_name` (`tenant_id`, `router_name`),
                KEY `idx_routers_tenant_status` (`tenant_id`, `status`),
                KEY `idx_routers_api_host` (`api_host`),
                CONSTRAINT `fk_routers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `telegram_groups` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
                `group_type` ENUM('ops','billing','helpdesk','monitoring','finance','marketing','general') NOT NULL DEFAULT 'general',
                `group_name` VARCHAR(120) NOT NULL,
                `chat_id` VARCHAR(80) NOT NULL,
                `bot_token_enc` TEXT NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_telegram_groups_tenant_type_chat` (`tenant_id`, `group_type`, `chat_id`),
                KEY `idx_telegram_groups_tenant_active` (`tenant_id`, `is_active`),
                CONSTRAINT `fk_telegram_groups_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `background_jobs` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_id` BIGINT(20) UNSIGNED DEFAULT NULL,
                `job_type` VARCHAR(100) NOT NULL,
                `queue_name` VARCHAR(60) NOT NULL DEFAULT 'default',
                `payload_json` LONGTEXT NOT NULL,
                `status` ENUM('queued','processing','success','failed','cancelled') NOT NULL DEFAULT 'queued',
                `attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts` INT(10) UNSIGNED NOT NULL DEFAULT 5,
                `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `started_at` DATETIME DEFAULT NULL,
                `finished_at` DATETIME DEFAULT NULL,
                `last_error` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_background_jobs_tenant` (`tenant_id`),
                KEY `idx_background_jobs_status_available` (`status`, `available_at`),
                KEY `idx_background_jobs_queue` (`queue_name`),
                CONSTRAINT `fk_background_jobs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function ensure_default_tenant()
    {
        $row = $this->db->where('tenant_code', 'legacy-default')->limit(1)->get('tenants')->row_array();
        if (!empty($row)) {
            return (int) $row['id'];
        }

        $this->db->insert('tenants', array(
            'tenant_code' => 'legacy-default',
            'tenant_name' => 'Legacy Default Tenant',
            'domain' => null,
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'max_customers' => 10000,
            'metadata_json' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ));

        return (int) $this->db->insert_id();
    }

    private function ensure_tenant_column($table, $is_nullable, $default_tenant_id)
    {
        if (!$this->table_exists($table)) {
            return;
        }

        if (!$this->column_exists($table, 'tenant_id')) {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `tenant_id` BIGINT(20) UNSIGNED NULL AFTER `id`");
        }

        $tenant_index = 'idx_' . $table . '_tenant';
        if (!$this->index_exists($table, $tenant_index)) {
            $this->db->query("ALTER TABLE `{$table}` ADD INDEX `{$tenant_index}` (`tenant_id`)");
        }

        if ($table !== 'users') {
            $this->db->query("UPDATE `{$table}` SET `tenant_id` = " . (int) $default_tenant_id . " WHERE `tenant_id` IS NULL OR `tenant_id` = 0");
        }

        if (!$is_nullable) {
            $this->db->query("ALTER TABLE `{$table}` MODIFY `tenant_id` BIGINT(20) UNSIGNED NOT NULL");
        } else {
            $this->db->query("ALTER TABLE `{$table}` MODIFY `tenant_id` BIGINT(20) UNSIGNED NULL");
        }
    }

    private function upgrade_roles($default_tenant_id)
    {
        if (!$this->table_exists('users') || !$this->column_exists('users', 'role')) {
            return;
        }

        $this->try_query("ALTER TABLE `users` MODIFY `role` ENUM('superadmin','platform_owner','tenant_owner','admin','teknisi') NOT NULL");
        $this->try_query("UPDATE `users` SET `role` = 'platform_owner' WHERE `role` = 'superadmin'");
        $this->try_query("UPDATE `users` SET `role` = 'admin' WHERE `role` NOT IN ('platform_owner','tenant_owner','admin','teknisi')");
        $this->try_query("UPDATE `users` SET `tenant_id` = NULL WHERE `role` = 'platform_owner'");
        $this->try_query("UPDATE `users` SET `tenant_id` = " . (int) $default_tenant_id . " WHERE `role` IN ('tenant_owner','admin','teknisi') AND (`tenant_id` IS NULL OR `tenant_id` = 0)");
        $this->try_query("ALTER TABLE `users` MODIFY `role` ENUM('platform_owner','tenant_owner','admin','teknisi') NOT NULL");
    }

    private function scope_unique_constraints()
    {
        foreach ($this->unique_scope_map as $table => $rules) {
            if (!$this->table_exists($table)) {
                continue;
            }

            foreach ($rules as $rule) {
                $old = (string) $rule['old'];
                $new = (string) $rule['new'];
                $columns = (array) $rule['columns'];

                if (!$this->columns_exist($table, $columns)) {
                    continue;
                }

                if ($this->index_exists($table, $old)) {
                    $this->db->query("ALTER TABLE `{$table}` DROP INDEX `{$old}`");
                }
                if ($new !== $old && $this->index_exists($table, $new)) {
                    $this->db->query("ALTER TABLE `{$table}` DROP INDEX `{$new}`");
                }

                $columns_sql = '`' . implode('`,`', $columns) . '`';
                $this->db->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$new}` ({$columns_sql})");
            }
        }
    }

    private function add_tenant_foreign_keys()
    {
        foreach ($this->tenant_fk_map as $table => $meta) {
            if (!$this->table_exists($table) || !$this->column_exists($table, 'tenant_id')) {
                continue;
            }

            $constraint = (string) $meta['fk'];
            if ($this->constraint_exists($table, $constraint)) {
                continue;
            }

            $on_delete = isset($meta['on_delete']) ? (string) $meta['on_delete'] : 'RESTRICT';
            $this->db->query(
                "ALTER TABLE `{$table}` " .
                "ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) " .
                "ON UPDATE CASCADE ON DELETE {$on_delete}"
            );
        }
    }

    private function drop_tenant_foreign_keys()
    {
        foreach ($this->tenant_fk_map as $table => $meta) {
            if (!$this->table_exists($table)) {
                continue;
            }

            $constraint = (string) $meta['fk'];
            if ($this->constraint_exists($table, $constraint)) {
                $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
            }
        }
    }

    private function restore_legacy_unique_constraints()
    {
        foreach ($this->unique_scope_map as $table => $rules) {
            if (!$this->table_exists($table)) {
                continue;
            }

            foreach ($rules as $rule) {
                $old = (string) $rule['old'];
                $new = (string) $rule['new'];
                $legacy_columns = (array) $rule['legacy_columns'];

                if ($this->index_exists($table, $new)) {
                    $this->db->query("ALTER TABLE `{$table}` DROP INDEX `{$new}`");
                }

                if (!$this->columns_exist($table, $legacy_columns)) {
                    continue;
                }

                if (!$this->index_exists($table, $old)) {
                    $columns_sql = '`' . implode('`,`', $legacy_columns) . '`';
                    $this->db->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$old}` ({$columns_sql})");
                }
            }
        }
    }

    private function downgrade_roles()
    {
        if (!$this->table_exists('users') || !$this->column_exists('users', 'role')) {
            return;
        }

        $this->try_query("ALTER TABLE `users` MODIFY `role` ENUM('superadmin','platform_owner','tenant_owner','admin','teknisi') NOT NULL");
        $this->try_query("UPDATE `users` SET `role` = 'superadmin' WHERE `role` = 'platform_owner'");
        $this->try_query("UPDATE `users` SET `role` = 'admin' WHERE `role` = 'tenant_owner'");
        $this->try_query("ALTER TABLE `users` MODIFY `role` ENUM('superadmin','admin','teknisi') NOT NULL");
    }

    private function drop_tenant_column($table)
    {
        if (!$this->table_exists($table) || !$this->column_exists($table, 'tenant_id')) {
            return;
        }

        $tenant_index = 'idx_' . $table . '_tenant';
        if ($this->index_exists($table, $tenant_index)) {
            $this->db->query("ALTER TABLE `{$table}` DROP INDEX `{$tenant_index}`");
        }

        $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `tenant_id`");
    }

    private function table_exists($table)
    {
        return $this->db->table_exists($table);
    }

    private function column_exists($table, $column)
    {
        if (!$this->table_exists($table)) {
            return false;
        }
        return in_array($column, $this->db->list_fields($table), true);
    }

    private function columns_exist($table, array $columns)
    {
        if (!$this->table_exists($table)) {
            return false;
        }
        $fields = $this->db->list_fields($table);
        foreach ($columns as $column) {
            if (!in_array($column, $fields, true)) {
                return false;
            }
        }
        return true;
    }

    private function index_exists($table, $index_name)
    {
        if (!$this->table_exists($table)) {
            return false;
        }
        $sql = "SHOW INDEX FROM `{$table}` WHERE `Key_name` = " . $this->db->escape($index_name);
        return $this->db->query($sql)->num_rows() > 0;
    }

    private function constraint_exists($table, $constraint_name)
    {
        $sql = "
            SELECT 1
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND CONSTRAINT_NAME = ?
            LIMIT 1
        ";
        $query = $this->db->query($sql, array($this->db->database, $table, $constraint_name));
        return $query->num_rows() > 0;
    }

    private function try_query($sql)
    {
        try {
            $this->db->query($sql);
            return true;
        } catch (Throwable $e) {
            log_message('error', '[Migration_Multitenant_foundation] ' . $e->getMessage() . ' | SQL: ' . $sql);
            return false;
        }
    }
}
