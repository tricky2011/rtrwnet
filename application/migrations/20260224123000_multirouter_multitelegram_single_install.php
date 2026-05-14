<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Multirouter_multitelegram_single_install extends CI_Migration
{
    public function up()
    {
        $this->ensure_routers_table();
        $this->ensure_telegram_tables();
        $this->ensure_router_id_scoping_columns();
    }

    public function down()
    {
        // Non-destructive rollback intentionally omitted.
    }

    private function ensure_routers_table()
    {
        if (!$this->db->table_exists('routers')) {
            $this->db->query("
                CREATE TABLE `routers` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
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
                    KEY `idx_routers_active` (`is_active`),
                    KEY `idx_routers_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $this->add_column_if_missing('routers', 'name', "VARCHAR(120) NULL AFTER `id`");
        $this->add_column_if_missing('routers', 'ip_address', "VARCHAR(120) NULL AFTER `name`");
        $this->add_column_if_missing('routers', 'api_port', "INT(10) UNSIGNED NOT NULL DEFAULT 8728 AFTER `ip_address`");
        $this->add_column_if_missing('routers', 'username', "VARCHAR(100) NULL AFTER `api_port`");
        $this->add_column_if_missing('routers', 'password', "TEXT NULL AFTER `username`");
        $this->add_column_if_missing('routers', 'description', "TEXT NULL AFTER `password`");
        $this->add_column_if_missing('routers', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `description`");
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

        if (!$this->index_exists('routers', 'idx_routers_active')) {
            $this->db->query("ALTER TABLE `routers` ADD INDEX `idx_routers_active` (`is_active`)");
        }
        if (!$this->index_exists('routers', 'idx_routers_name')) {
            $this->db->query("ALTER TABLE `routers` ADD INDEX `idx_routers_name` (`name`)");
        }
    }

    private function ensure_telegram_tables()
    {
        if (!$this->db->table_exists('telegram_bots')) {
            $this->db->query("
                CREATE TABLE `telegram_bots` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `bot_name` VARCHAR(120) NOT NULL,
                    `bot_token` TEXT NOT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_telegram_bots_active` (`is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if (!$this->db->table_exists('telegram_groups')) {
            $this->db->query("
                CREATE TABLE `telegram_groups` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `bot_id` BIGINT(20) UNSIGNED NOT NULL,
                    `group_name` VARCHAR(150) NOT NULL,
                    `chat_id` VARCHAR(100) NOT NULL,
                    `type` ENUM('teknisi','admin','owner','alert') NOT NULL DEFAULT 'admin',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_telegram_groups_type_active` (`type`,`is_active`),
                    KEY `idx_telegram_groups_bot` (`bot_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $this->add_column_if_missing('telegram_groups', 'bot_id', "BIGINT(20) UNSIGNED NULL AFTER `id`");
        $this->add_column_if_missing('telegram_groups', 'group_name', "VARCHAR(150) NULL");
        $this->add_column_if_missing('telegram_groups', 'chat_id', "VARCHAR(100) NULL");
        if (!$this->column_exists('telegram_groups', 'type')) {
            $this->db->query("ALTER TABLE `telegram_groups` ADD COLUMN `type` ENUM('teknisi','admin','owner','alert') NOT NULL DEFAULT 'admin'");
        }
        $this->add_column_if_missing('telegram_groups', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
        $this->add_column_if_missing('telegram_groups', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");

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

        if (!$this->index_exists('telegram_groups', 'idx_telegram_groups_type_active')) {
            $this->db->query("ALTER TABLE `telegram_groups` ADD INDEX `idx_telegram_groups_type_active` (`type`,`is_active`)");
        }
        if (!$this->index_exists('telegram_groups', 'idx_telegram_groups_bot')) {
            $this->db->query("ALTER TABLE `telegram_groups` ADD INDEX `idx_telegram_groups_bot` (`bot_id`)");
        }

        $default_bot_id = $this->ensure_default_telegram_bot();
        if ($default_bot_id > 0) {
            $this->db->query("UPDATE `telegram_groups` SET `bot_id` = " . $default_bot_id . " WHERE (`bot_id` IS NULL OR `bot_id` = 0)");
        }
    }

    private function ensure_default_telegram_bot()
    {
        if (!$this->db->table_exists('telegram_bots')) {
            return 0;
        }

        $existing = $this->db->order_by('id', 'ASC')->limit(1)->get('telegram_bots')->row_array();
        if (!empty($existing)) {
            return (int) ($existing['id'] ?? 0);
        }

        $token = '';
        if ($this->db->table_exists('settings_telegram')) {
            $fields = $this->db->list_fields('settings_telegram');
            if (in_array('bot_token', $fields, true)) {
                $row = $this->db->select('bot_token')->limit(1)->get('settings_telegram')->row_array();
                $token = trim((string) ($row['bot_token'] ?? ''));
            }
        }

        if ($token === '') {
            return 0;
        }

        $this->db->insert('telegram_bots', array(
            'bot_name' => 'Default Bot',
            'bot_token' => $token,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ));

        return (int) $this->db->insert_id();
    }

    private function ensure_router_id_scoping_columns()
    {
        $default_router_id = $this->resolve_default_router_id();

        foreach (array('customer_services', 'pppoe_secrets', 'work_orders') as $table) {
            if (!$this->db->table_exists($table)) {
                continue;
            }

            $this->add_column_if_missing($table, 'router_id', "BIGINT(20) UNSIGNED NULL");
            $index_name = 'idx_' . $table . '_router';
            if (!$this->index_exists($table, $index_name)) {
                $this->db->query("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (`router_id`)");
            }

            if ($default_router_id > 0) {
                $this->db->query("UPDATE `{$table}` SET `router_id` = " . $default_router_id . " WHERE (`router_id` IS NULL OR `router_id` = 0)");
            }
        }
    }

    private function resolve_default_router_id()
    {
        if (!$this->db->table_exists('routers')) {
            return 0;
        }

        $fields = $this->db->list_fields('routers');
        $qb = $this->db->from('routers');
        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        $row = $qb->order_by('id', 'ASC')->limit(1)->get()->row_array();
        return (int) ($row['id'] ?? 0);
    }

    private function add_column_if_missing($table, $column, $definition)
    {
        if ($this->column_exists($table, $column)) {
            return;
        }
        $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
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

    private function index_exists($table, $index)
    {
        if (!$this->table_exists($table)) {
            return false;
        }
        $sql = "SHOW INDEX FROM `{$table}` WHERE `Key_name` = " . $this->db->escape($index);
        return $this->db->query($sql)->num_rows() > 0;
    }
}

