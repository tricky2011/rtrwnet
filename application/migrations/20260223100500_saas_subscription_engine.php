<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Saas_subscription_engine extends CI_Migration
{
    public function up()
    {
        $this->ensure_packages_schema();
        $this->ensure_tenant_subscriptions_schema();
        $this->ensure_tenant_invoices_schema();
        $this->ensure_tenant_payments_schema();
        $this->seed_default_subscription_if_needed();
    }

    public function down()
    {
        if ($this->table_exists('tenant_payments')) {
            $this->db->query('DROP TABLE `tenant_payments`');
        }
    }

    private function ensure_packages_schema()
    {
        if (!$this->table_exists('packages')) {
            $this->db->query("
                CREATE TABLE `packages` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(120) NOT NULL,
                    `price_monthly` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                    `price_yearly` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                    `max_router` INT(10) UNSIGNED NOT NULL DEFAULT 1,
                    `max_customer` INT(10) UNSIGNED NOT NULL DEFAULT 100,
                    `max_user` INT(10) UNSIGNED NOT NULL DEFAULT 5,
                    `max_telegram_group` INT(10) UNSIGNED NOT NULL DEFAULT 2,
                    `max_technician` INT(10) UNSIGNED NOT NULL DEFAULT 3,
                    `features_json` LONGTEXT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_packages_name` (`name`),
                    KEY `idx_packages_price_monthly` (`price_monthly`),
                    KEY `idx_packages_price_yearly` (`price_yearly`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $this->add_column_if_missing('packages', 'name', "VARCHAR(120) NULL AFTER `id`");
        $this->add_column_if_missing('packages', 'price_monthly', "DECIMAL(14,2) NOT NULL DEFAULT 0.00");
        $this->add_column_if_missing('packages', 'price_yearly', "DECIMAL(14,2) NOT NULL DEFAULT 0.00");
        $this->add_column_if_missing('packages', 'max_router', "INT(10) UNSIGNED NOT NULL DEFAULT 1");
        $this->add_column_if_missing('packages', 'max_customer', "INT(10) UNSIGNED NOT NULL DEFAULT 100");
        $this->add_column_if_missing('packages', 'max_user', "INT(10) UNSIGNED NOT NULL DEFAULT 5");
        $this->add_column_if_missing('packages', 'max_telegram_group', "INT(10) UNSIGNED NOT NULL DEFAULT 2");
        $this->add_column_if_missing('packages', 'max_technician', "INT(10) UNSIGNED NOT NULL DEFAULT 3");
        $this->add_column_if_missing('packages', 'features_json', "LONGTEXT NULL");
        $this->add_column_if_missing('packages', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->add_column_if_missing('packages', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        if ($this->column_exists('packages', 'package_name')) {
            $this->db->query("UPDATE `packages` SET `name` = `package_name` WHERE (`name` IS NULL OR `name` = '') AND `package_name` IS NOT NULL");
        }
        if ($this->column_exists('packages', 'price')) {
            $this->db->query("UPDATE `packages` SET `price_monthly` = `price` WHERE `price_monthly` = 0");
        }
        if ($this->column_exists('packages', 'max_routers')) {
            $this->db->query("UPDATE `packages` SET `max_router` = `max_routers` WHERE `max_router` = 1");
        }
        if ($this->column_exists('packages', 'max_customers')) {
            $this->db->query("UPDATE `packages` SET `max_customer` = `max_customers` WHERE `max_customer` = 100");
        }

        $this->db->query("UPDATE `packages` SET `name` = CONCAT('Package #', id) WHERE `name` IS NULL OR `name` = ''");
        $this->db->query("ALTER TABLE `packages` MODIFY `name` VARCHAR(120) NOT NULL");

        if (!$this->index_exists('packages', 'uq_packages_name')) {
            $this->db->query("ALTER TABLE `packages` ADD UNIQUE KEY `uq_packages_name` (`name`)");
        }
        if (!$this->index_exists('packages', 'idx_packages_price_monthly')) {
            $this->db->query("ALTER TABLE `packages` ADD INDEX `idx_packages_price_monthly` (`price_monthly`)");
        }
        if (!$this->index_exists('packages', 'idx_packages_price_yearly')) {
            $this->db->query("ALTER TABLE `packages` ADD INDEX `idx_packages_price_yearly` (`price_yearly`)");
        }
    }

    private function ensure_tenant_subscriptions_schema()
    {
        if (!$this->table_exists('tenant_subscriptions')) {
            $this->db->query("
                CREATE TABLE `tenant_subscriptions` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
                    `package_id` BIGINT(20) UNSIGNED NOT NULL,
                    `billing_cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
                    `start_date` DATE NOT NULL,
                    `end_date` DATE NOT NULL,
                    `status` ENUM('active','expired','suspended') NOT NULL DEFAULT 'active',
                    `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_tenant_subs_tenant` (`tenant_id`),
                    KEY `idx_tenant_subs_status_end` (`status`,`end_date`),
                    KEY `idx_tenant_subs_package` (`package_id`),
                    CONSTRAINT `fk_tenant_subs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                    CONSTRAINT `fk_tenant_subs_package` FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $this->add_column_if_missing('tenant_subscriptions', 'billing_cycle', "ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly'");
        $this->add_column_if_missing('tenant_subscriptions', 'auto_renew', "TINYINT(1) NOT NULL DEFAULT 1");
        $this->add_column_if_missing('tenant_subscriptions', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->add_column_if_missing('tenant_subscriptions', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        if ($this->column_exists('tenant_subscriptions', 'status')) {
            $this->db->query("
                UPDATE `tenant_subscriptions`
                SET `status` = CASE
                    WHEN `status` IN ('active') THEN 'active'
                    WHEN `status` IN ('expired') THEN 'expired'
                    ELSE 'suspended'
                END
            ");
            $this->db->query("ALTER TABLE `tenant_subscriptions` MODIFY `status` ENUM('active','expired','suspended') NOT NULL DEFAULT 'active'");
        }

        if (!$this->index_exists('tenant_subscriptions', 'idx_tenant_subs_tenant')) {
            $this->db->query("ALTER TABLE `tenant_subscriptions` ADD INDEX `idx_tenant_subs_tenant` (`tenant_id`)");
        }
        if (!$this->index_exists('tenant_subscriptions', 'idx_tenant_subs_status_end')) {
            $this->db->query("ALTER TABLE `tenant_subscriptions` ADD INDEX `idx_tenant_subs_status_end` (`status`, `end_date`)");
        }
    }

    private function ensure_tenant_invoices_schema()
    {
        if (!$this->table_exists('tenant_invoices')) {
            $this->db->query("
                CREATE TABLE `tenant_invoices` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
                    `subscription_id` BIGINT(20) UNSIGNED NOT NULL,
                    `invoice_number` VARCHAR(50) NOT NULL,
                    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                    `due_date` DATE NOT NULL,
                    `paid_at` DATETIME NULL,
                    `status` ENUM('pending','paid','overdue') NOT NULL DEFAULT 'pending',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_tenant_invoices_number` (`tenant_id`,`invoice_number`),
                    KEY `idx_tenant_invoices_status_due` (`status`,`due_date`),
                    KEY `idx_tenant_invoices_tenant` (`tenant_id`),
                    KEY `idx_tenant_invoices_subscription` (`subscription_id`),
                    CONSTRAINT `fk_tenant_invoices_tenant_ref` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                    CONSTRAINT `fk_tenant_invoices_subscription_ref` FOREIGN KEY (`subscription_id`) REFERENCES `tenant_subscriptions`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $this->add_column_if_missing('tenant_invoices', 'amount', "DECIMAL(14,2) NOT NULL DEFAULT 0.00");
        $this->add_column_if_missing('tenant_invoices', 'paid_at', "DATETIME NULL");
        $this->add_column_if_missing('tenant_invoices', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->add_column_if_missing('tenant_invoices', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        if ($this->column_exists('tenant_invoices', 'total_amount')) {
            $this->db->query("UPDATE `tenant_invoices` SET `amount` = `total_amount` WHERE `amount` = 0");
        } elseif ($this->column_exists('tenant_invoices', 'subtotal')) {
            $this->db->query("UPDATE `tenant_invoices` SET `amount` = `subtotal` WHERE `amount` = 0");
        }

        if ($this->column_exists('tenant_invoices', 'status')) {
            $this->db->query("
                UPDATE `tenant_invoices`
                SET `status` = CASE
                    WHEN `status` = 'paid' THEN 'paid'
                    WHEN `status` = 'overdue' THEN 'overdue'
                    ELSE 'pending'
                END
            ");
            $this->db->query("ALTER TABLE `tenant_invoices` MODIFY `status` ENUM('pending','paid','overdue') NOT NULL DEFAULT 'pending'");
        }

        if ($this->index_exists('tenant_invoices', 'uq_tenant_invoices_tenant_number')) {
            $this->db->query("ALTER TABLE `tenant_invoices` DROP INDEX `uq_tenant_invoices_tenant_number`");
        }
        if (!$this->index_exists('tenant_invoices', 'uq_tenant_invoices_number')) {
            $this->db->query("ALTER TABLE `tenant_invoices` ADD UNIQUE KEY `uq_tenant_invoices_number` (`tenant_id`,`invoice_number`)");
        }
        if (!$this->index_exists('tenant_invoices', 'idx_tenant_invoices_tenant')) {
            $this->db->query("ALTER TABLE `tenant_invoices` ADD INDEX `idx_tenant_invoices_tenant` (`tenant_id`)");
        }
        if (!$this->index_exists('tenant_invoices', 'idx_tenant_invoices_status_due')) {
            $this->db->query("ALTER TABLE `tenant_invoices` ADD INDEX `idx_tenant_invoices_status_due` (`status`, `due_date`)");
        }
    }

    private function ensure_tenant_payments_schema()
    {
        if ($this->table_exists('tenant_payments')) {
            if (!$this->index_exists('tenant_payments', 'idx_tenant_payments_tenant')) {
                $this->db->query("ALTER TABLE `tenant_payments` ADD INDEX `idx_tenant_payments_tenant` (`tenant_id`)");
            }
            return;
        }

        $this->db->query("
            CREATE TABLE `tenant_payments` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `tenant_invoice_id` BIGINT(20) UNSIGNED NOT NULL,
                `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
                `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `paid_at` DATETIME NOT NULL,
                `method` VARCHAR(50) NOT NULL,
                `reference_number` VARCHAR(100) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_tenant_payments_tenant` (`tenant_id`),
                KEY `idx_tenant_payments_invoice` (`tenant_invoice_id`),
                KEY `idx_tenant_payments_paid_at` (`paid_at`),
                CONSTRAINT `fk_tenant_payments_invoice` FOREIGN KEY (`tenant_invoice_id`) REFERENCES `tenant_invoices`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_tenant_payments_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function seed_default_subscription_if_needed()
    {
        if (!$this->table_exists('tenants') || !$this->table_exists('packages') || !$this->table_exists('tenant_subscriptions')) {
            return;
        }

        $package = $this->db
            ->select('id')
            ->from('packages')
            ->order_by('id', 'ASC')
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($package)) {
            $this->db->insert('packages', array(
                'name' => 'Starter',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'max_router' => 1,
                'max_customer' => 1000,
                'max_user' => 20,
                'max_telegram_group' => 3,
                'max_technician' => 10,
                'features_json' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ));
            $package = array('id' => (int) $this->db->insert_id());
        }

        $tenants = $this->db->select('id')->get('tenants')->result_array();
        foreach ($tenants as $tenant) {
            $tenant_id = (int) ($tenant['id'] ?? 0);
            if ($tenant_id <= 0) {
                continue;
            }
            $exists = $this->db
                ->where('tenant_id', $tenant_id)
                ->count_all_results('tenant_subscriptions') > 0;

            if ($exists) {
                continue;
            }

            $this->db->insert('tenant_subscriptions', array(
                'tenant_id' => $tenant_id,
                'package_id' => (int) $package['id'],
                'billing_cycle' => 'monthly',
                'start_date' => date('Y-m-01'),
                'end_date' => date('Y-m-d', strtotime('+5 years')),
                'status' => 'active',
                'auto_renew' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ));
        }
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

    private function add_column_if_missing($table, $column, $definition_sql)
    {
        if ($this->column_exists($table, $column)) {
            return;
        }
        $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition_sql}");
    }

    private function index_exists($table, $index_name)
    {
        if (!$this->table_exists($table)) {
            return false;
        }
        $sql = "SHOW INDEX FROM `{$table}` WHERE `Key_name` = " . $this->db->escape($index_name);
        return $this->db->query($sql)->num_rows() > 0;
    }
}
