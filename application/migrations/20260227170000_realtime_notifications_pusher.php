<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Realtime_notifications_pusher extends CI_Migration
{
    public function up()
    {
        $this->ensure_notifications_table();
    }

    public function down()
    {
        // Non destructive rollback intentionally omitted.
    }

    protected function ensure_notifications_table()
    {
        if (!$this->db->table_exists('notifications')) {
            $this->db->query("
                CREATE TABLE `notifications` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT(20) UNSIGNED NULL,
                    `brand_id` BIGINT(20) UNSIGNED NULL,
                    `router_id` BIGINT(20) UNSIGNED NULL,
                    `type` VARCHAR(50) NOT NULL DEFAULT 'info',
                    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
                    `title` VARCHAR(255) NOT NULL,
                    `message` TEXT NOT NULL,
                    `reference_id` BIGINT(20) UNSIGNED NULL,
                    `reference_type` VARCHAR(50) NULL,
                    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_notifications_user_id` (`user_id`),
                    KEY `idx_notifications_brand_id` (`brand_id`),
                    KEY `idx_notifications_router_id` (`router_id`),
                    KEY `idx_notifications_is_read` (`is_read`),
                    KEY `idx_notifications_created_at` (`created_at`),
                    KEY `idx_notifications_user_read` (`user_id`,`is_read`,`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $this->add_column_if_missing('notifications', 'user_id', 'BIGINT(20) UNSIGNED NULL');
        $this->add_column_if_missing('notifications', 'brand_id', 'BIGINT(20) UNSIGNED NULL');
        $this->add_column_if_missing('notifications', 'router_id', 'BIGINT(20) UNSIGNED NULL');
        $this->add_column_if_missing('notifications', 'type', "VARCHAR(50) NOT NULL DEFAULT 'info'");
        $this->add_column_if_missing('notifications', 'category', "VARCHAR(50) NOT NULL DEFAULT 'general'");
        $this->add_column_if_missing('notifications', 'title', 'VARCHAR(255) NULL');
        $this->add_column_if_missing('notifications', 'message', 'TEXT NULL');
        $this->add_column_if_missing('notifications', 'reference_id', 'BIGINT(20) UNSIGNED NULL');
        $this->add_column_if_missing('notifications', 'reference_type', 'VARCHAR(50) NULL');
        $this->add_column_if_missing('notifications', 'is_read', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->add_column_if_missing('notifications', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        if (!$this->index_exists('notifications', 'idx_notifications_user_id')) {
            $this->db->query("ALTER TABLE `notifications` ADD INDEX `idx_notifications_user_id` (`user_id`)");
        }
        if (!$this->index_exists('notifications', 'idx_notifications_brand_id')) {
            $this->db->query("ALTER TABLE `notifications` ADD INDEX `idx_notifications_brand_id` (`brand_id`)");
        }
        if (!$this->index_exists('notifications', 'idx_notifications_router_id')) {
            $this->db->query("ALTER TABLE `notifications` ADD INDEX `idx_notifications_router_id` (`router_id`)");
        }
        if (!$this->index_exists('notifications', 'idx_notifications_is_read')) {
            $this->db->query("ALTER TABLE `notifications` ADD INDEX `idx_notifications_is_read` (`is_read`)");
        }
        if (!$this->index_exists('notifications', 'idx_notifications_created_at')) {
            $this->db->query("ALTER TABLE `notifications` ADD INDEX `idx_notifications_created_at` (`created_at`)");
        }
        if (!$this->index_exists('notifications', 'idx_notifications_user_read')) {
            $this->db->query("ALTER TABLE `notifications` ADD INDEX `idx_notifications_user_read` (`user_id`,`is_read`,`created_at`)");
        }
    }

    protected function add_column_if_missing($table, $column, $definition)
    {
        if (!$this->column_exists($table, $column)) {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    protected function column_exists($table, $column)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }
        return in_array($column, $this->db->list_fields($table), true);
    }

    protected function index_exists($table, $index)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }

        $sql = "SHOW INDEX FROM `{$table}` WHERE `Key_name` = " . $this->db->escape($index);
        return $this->db->query($sql)->num_rows() > 0;
    }
}

