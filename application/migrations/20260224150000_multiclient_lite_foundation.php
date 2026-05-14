<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Multiclient_lite_foundation extends CI_Migration
{
    public function up()
    {
        $this->ensure_tenants_table();
        $default_tenant_id = $this->ensure_default_tenant();
        $this->ensure_tenants_lite_columns($default_tenant_id);
        $this->ensure_routers_table($default_tenant_id);
        $this->ensure_telegram_groups_table($default_tenant_id);
        $this->ensure_tenant_scope_columns($default_tenant_id);
        $this->ensure_router_binding_columns($default_tenant_id);
    }

    public function down()
    {
        // Non-destructive rollback intentionally omitted.
    }

    private function ensure_tenants_table()
    {
        if ($this->db->table_exists('tenants')) {
            return;
        }

        $this->db->query("
            CREATE TABLE `tenants` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_name` VARCHAR(150) NOT NULL,
                `contact_name` VARCHAR(120) DEFAULT NULL,
                `contact_phone` VARCHAR(50) DEFAULT NULL,
                `max_router` INT(10) UNSIGNED NOT NULL DEFAULT 1,
                `max_telegram` INT(10) UNSIGNED NOT NULL DEFAULT 3,
                `max_user` INT(10) UNSIGNED NOT NULL DEFAULT 5,
                `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
                `expired_at` DATE DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_tenants_status` (`status`),
                KEY `idx_tenants_expired` (`expired_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function ensure_default_tenant()
    {
        if (!$this->db->table_exists('tenants')) {
            return 0;
        }

        $fields = $this->db->list_fields('tenants');

        if (in_array('tenant_code', $fields, true)) {
            $row = $this->db->where('tenant_code', 'legacy-default')->limit(1)->get('tenants')->row_array();
            if (!empty($row)) {
                return (int) ($row['id'] ?? 0);
            }

            $payload = array(
                'tenant_code' => 'legacy-default',
                'tenant_name' => 'Legacy Default Tenant',
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            );
            if (in_array('company_name', $fields, true)) {
                $payload['company_name'] = 'Legacy Default Tenant';
            }
            if (in_array('max_router', $fields, true)) {
                $payload['max_router'] = 5;
            }
            if (in_array('max_telegram', $fields, true)) {
                $payload['max_telegram'] = 10;
            }
            if (in_array('max_user', $fields, true)) {
                $payload['max_user'] = 50;
            }

            $this->db->insert('tenants', $payload);
            return (int) $this->db->insert_id();
        }

        $first = $this->db->order_by('id', 'ASC')->limit(1)->get('tenants')->row_array();
        if (!empty($first)) {
            return (int) ($first['id'] ?? 0);
        }

        $payload = array(
            'company_name' => 'Legacy Default Tenant',
            'contact_name' => null,
            'contact_phone' => null,
            'max_router' => 5,
            'max_telegram' => 10,
            'max_user' => 50,
            'status' => 'active',
            'expired_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );

        $this->db->insert('tenants', $payload);
        return (int) $this->db->insert_id();
    }

    private function ensure_tenants_lite_columns($default_tenant_id)
    {
        if (!$this->db->table_exists('tenants')) {
            return;
        }

        $this->add_column_if_missing('tenants', 'company_name', "VARCHAR(150) NULL");
        $this->add_column_if_missing('tenants', 'contact_name', "VARCHAR(120) NULL");
        $this->add_column_if_missing('tenants', 'contact_phone', "VARCHAR(50) NULL");
        $this->add_column_if_missing('tenants', 'max_router', "INT(10) UNSIGNED NOT NULL DEFAULT 1");
        $this->add_column_if_missing('tenants', 'max_telegram', "INT(10) UNSIGNED NOT NULL DEFAULT 3");
        $this->add_column_if_missing('tenants', 'max_user', "INT(10) UNSIGNED NOT NULL DEFAULT 5");
        $this->add_column_if_missing('tenants', 'expired_at', "DATE NULL");
        $this->add_column_if_missing('tenants', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->add_column_if_missing('tenants', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        if ($this->column_exists('tenants', 'tenant_name')) {
            $this->db->query("UPDATE `tenants` SET `company_name` = `tenant_name` WHERE (`company_name` IS NULL OR TRIM(`company_name`) = '') AND `tenant_name` IS NOT NULL");
        }
        if ($this->column_exists('tenants', 'max_routers')) {
            $this->db->query("UPDATE `tenants` SET `max_router` = `max_routers` WHERE (`max_router` IS NULL OR `max_router` = 0) AND `max_routers` IS NOT NULL");
        }
        if ($this->column_exists('tenants', 'max_customers')) {
            $this->db->query("UPDATE `tenants` SET `max_user` = LEAST(`max_customers`, 5000) WHERE (`max_user` IS NULL OR `max_user` = 0) AND `max_customers` IS NOT NULL");
        }

        $this->db->query("UPDATE `tenants` SET `max_router` = 1 WHERE `max_router` IS NULL OR `max_router` <= 0");
        $this->db->query("UPDATE `tenants` SET `max_telegram` = 3 WHERE `max_telegram` IS NULL OR `max_telegram` <= 0");
        $this->db->query("UPDATE `tenants` SET `max_user` = 5 WHERE `max_user` IS NULL OR `max_user` <= 0");
        $this->db->query("UPDATE `tenants` SET `company_name` = CONCAT('Tenant #', `id`) WHERE `company_name` IS NULL OR TRIM(`company_name`) = ''");
        $this->db->query("UPDATE `tenants` SET `status` = 'active' WHERE `status` IS NULL OR TRIM(`status`) = ''");

        if ($default_tenant_id > 0 && $this->column_exists('tenants', 'expired_at')) {
            $this->db->query("UPDATE `tenants` SET `expired_at` = DATE_ADD(CURDATE(), INTERVAL 365 DAY) WHERE `id` = " . (int) $default_tenant_id . " AND (`expired_at` IS NULL OR `expired_at` = '0000-00-00')");
        }

        if (!$this->index_exists('tenants', 'idx_tenants_status')) {
            $this->db->query("ALTER TABLE `tenants` ADD INDEX `idx_tenants_status` (`status`)");
        }
        if (!$this->index_exists('tenants', 'idx_tenants_expired')) {
            $this->db->query("ALTER TABLE `tenants` ADD INDEX `idx_tenants_expired` (`expired_at`)");
        }
    }

    private function ensure_routers_table($default_tenant_id)
    {
        if (!$this->db->table_exists('routers')) {
            $this->db->query("
                CREATE TABLE `routers` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
                    `name` VARCHAR(120) NOT NULL,
                    `ip_address` VARCHAR(120) NOT NULL,
                    `api_port` INT(10) UNSIGNED NOT NULL DEFAULT 8728,
                    `username` VARCHAR(100) NOT NULL,
                    `password` TEXT NOT NULL,
                    `description` TEXT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_routers_tenant_name` (`tenant_id`,`name`),
                    KEY `idx_routers_tenant_active` (`tenant_id`,`is_active`),
                    KEY `idx_routers_ip` (`ip_address`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $this->add_column_if_missing('routers', 'tenant_id', "BIGINT(20) UNSIGNED NULL");
        $this->add_column_if_missing('routers', 'name', "VARCHAR(120) NULL");
        $this->add_column_if_missing('routers', 'ip_address', "VARCHAR(120) NULL");
        $this->add_column_if_missing('routers', 'api_port', "INT(10) UNSIGNED NOT NULL DEFAULT 8728");
        $this->add_column_if_missing('routers', 'username', "VARCHAR(100) NULL");
        $this->add_column_if_missing('routers', 'password', "TEXT NULL");
        $this->add_column_if_missing('routers', 'description', "TEXT NULL");
        $this->add_column_if_missing('routers', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
        $this->add_column_if_missing('routers', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->add_column_if_missing('routers', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        if ($this->column_exists('routers', 'router_name')) {
            $this->db->query("UPDATE `routers` SET `name` = `router_name` WHERE (`name` IS NULL OR TRIM(`name`) = '') AND `router_name` IS NOT NULL");
        }
        if ($this->column_exists('routers', 'api_host')) {
            $this->db->query("UPDATE `routers` SET `ip_address` = `api_host` WHERE (`ip_address` IS NULL OR TRIM(`ip_address`) = '') AND `api_host` IS NOT NULL");
        }
        if ($this->column_exists('routers', 'api_username')) {
            $this->db->query("UPDATE `routers` SET `username` = `api_username` WHERE (`username` IS NULL OR TRIM(`username`) = '') AND `api_username` IS NOT NULL");
        }
        if ($this->column_exists('routers', 'api_password_enc')) {
            $this->db->query("UPDATE `routers` SET `password` = `api_password_enc` WHERE (`password` IS NULL OR TRIM(`password`) = '') AND `api_password_enc` IS NOT NULL");
        }
        if ($this->column_exists('routers', 'metadata_json')) {
            $this->db->query("UPDATE `routers` SET `description` = `metadata_json` WHERE (`description` IS NULL OR TRIM(`description`) = '') AND `metadata_json` IS NOT NULL");
        }
        if ($this->column_exists('routers', 'status')) {
            $this->db->query("UPDATE `routers` SET `is_active` = IF(LOWER(`status`) = 'active', 1, 0) WHERE `status` IS NOT NULL");
        }

        if ($default_tenant_id > 0) {
            $this->db->query("UPDATE `routers` SET `tenant_id` = " . (int) $default_tenant_id . " WHERE `tenant_id` IS NULL OR `tenant_id` = 0");
        }

        if (!$this->index_exists('routers', 'idx_routers_tenant_active')) {
            $this->db->query("ALTER TABLE `routers` ADD INDEX `idx_routers_tenant_active` (`tenant_id`,`is_active`)");
        }
        if (!$this->index_exists('routers', 'idx_routers_ip')) {
            $this->db->query("ALTER TABLE `routers` ADD INDEX `idx_routers_ip` (`ip_address`)");
        }
    }

    private function ensure_telegram_groups_table($default_tenant_id)
    {
        if (!$this->db->table_exists('telegram_groups')) {
            $this->db->query("
                CREATE TABLE `telegram_groups` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
                    `group_name` VARCHAR(150) NOT NULL,
                    `chat_id` VARCHAR(100) NOT NULL,
                    `type` ENUM('teknisi','admin','owner','alert') NOT NULL DEFAULT 'admin',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_telegram_groups_tenant_type_chat` (`tenant_id`,`type`,`chat_id`),
                    KEY `idx_telegram_groups_tenant_active` (`tenant_id`,`is_active`),
                    KEY `idx_telegram_groups_type_active` (`type`,`is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $this->add_column_if_missing('telegram_groups', 'tenant_id', "BIGINT(20) UNSIGNED NULL");
        $this->add_column_if_missing('telegram_groups', 'group_name', "VARCHAR(150) NULL");
        $this->add_column_if_missing('telegram_groups', 'chat_id', "VARCHAR(100) NULL");
        if (!$this->column_exists('telegram_groups', 'type')) {
            $this->db->query("ALTER TABLE `telegram_groups` ADD COLUMN `type` ENUM('teknisi','admin','owner','alert') NOT NULL DEFAULT 'admin'");
        }
        $this->add_column_if_missing('telegram_groups', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
        $this->add_column_if_missing('telegram_groups', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->add_column_if_missing('telegram_groups', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        if ($this->column_exists('telegram_groups', 'group_type')) {
            $this->db->query("
                UPDATE `telegram_groups`
                SET `type` = CASE
                    WHEN LOWER(`group_type`) IN ('ops','helpdesk','teknisi') THEN 'teknisi'
                    WHEN LOWER(`group_type`) IN ('billing','admin') THEN 'admin'
                    WHEN LOWER(`group_type`) IN ('owner') THEN 'owner'
                    WHEN LOWER(`group_type`) IN ('monitoring','alert') THEN 'alert'
                    ELSE `type`
                END
            ");
        }

        if ($default_tenant_id > 0) {
            $this->db->query("UPDATE `telegram_groups` SET `tenant_id` = " . (int) $default_tenant_id . " WHERE `tenant_id` IS NULL OR `tenant_id` = 0");
        }

        if (!$this->index_exists('telegram_groups', 'idx_telegram_groups_tenant_active')) {
            $this->db->query("ALTER TABLE `telegram_groups` ADD INDEX `idx_telegram_groups_tenant_active` (`tenant_id`,`is_active`)");
        }
        if (!$this->index_exists('telegram_groups', 'idx_telegram_groups_type_active')) {
            $this->db->query("ALTER TABLE `telegram_groups` ADD INDEX `idx_telegram_groups_type_active` (`type`,`is_active`)");
        }
    }

    private function ensure_tenant_scope_columns($default_tenant_id)
    {
        $tables = array(
            'users',
            'customers',
            'customer_services',
            'invoices',
            'invoice_items',
            'payments',
            'tickets',
            'ticket_comments',
            'work_orders',
            'cashflow_transactions',
            'ppp_profiles',
            'ip_pools',
            'pppoe_secrets',
            'sync_logs',
            'system_logs',
            'user_activity_logs',
        );

        foreach ($tables as $table) {
            if (!$this->db->table_exists($table)) {
                continue;
            }

            $this->add_column_if_missing($table, 'tenant_id', "BIGINT(20) UNSIGNED NULL");

            $index_name = 'idx_' . $table . '_tenant';
            if (!$this->index_exists($table, $index_name)) {
                $this->db->query("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (`tenant_id`)");
            }

            if ($default_tenant_id <= 0) {
                continue;
            }

            if ($table === 'users') {
                if ($this->column_exists('users', 'role')) {
                    $this->db->query("
                        UPDATE `users`
                        SET `tenant_id` = " . (int) $default_tenant_id . "
                        WHERE (`tenant_id` IS NULL OR `tenant_id` = 0)
                          AND LOWER(`role`) NOT IN ('platform_owner','superadmin')
                    ");
                } else {
                    $this->db->query("UPDATE `users` SET `tenant_id` = " . (int) $default_tenant_id . " WHERE `tenant_id` IS NULL OR `tenant_id` = 0");
                }
            } else {
                $this->db->query("UPDATE `{$table}` SET `tenant_id` = " . (int) $default_tenant_id . " WHERE `tenant_id` IS NULL OR `tenant_id` = 0");
            }
        }
    }

    private function ensure_router_binding_columns($default_tenant_id)
    {
        foreach (array('customer_services', 'pppoe_secrets', 'work_orders') as $table) {
            if (!$this->db->table_exists($table)) {
                continue;
            }

            $this->add_column_if_missing($table, 'router_id', "BIGINT(20) UNSIGNED NULL");
            $index_name = 'idx_' . $table . '_router';
            if (!$this->index_exists($table, $index_name)) {
                $this->db->query("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (`router_id`)");
            }

            if ($default_tenant_id <= 0 || !$this->db->table_exists('routers')) {
                continue;
            }

            $default_router = $this->db
                ->from('routers')
                ->where('tenant_id', (int) $default_tenant_id)
                ->order_by('id', 'ASC')
                ->limit(1)
                ->get()
                ->row_array();

            $router_id = (int) ($default_router['id'] ?? 0);
            if ($router_id > 0) {
                $this->db->query("UPDATE `{$table}` SET `router_id` = " . $router_id . " WHERE `router_id` IS NULL OR `router_id` = 0");
            }
        }
    }

    private function add_column_if_missing($table, $column, $definition)
    {
        if ($this->column_exists($table, $column)) {
            return;
        }
        $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }

    private function column_exists($table, $column)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }
        return in_array($column, $this->db->list_fields($table), true);
    }

    private function index_exists($table, $index)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }
        $sql = "SHOW INDEX FROM `{$table}` WHERE `Key_name` = " . $this->db->escape($index);
        return $this->db->query($sql)->num_rows() > 0;
    }
}
