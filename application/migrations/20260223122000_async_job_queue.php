<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Async_job_queue extends CI_Migration
{
    public function up()
    {
        $this->create_or_upgrade_background_jobs();
    }

    public function down()
    {
        // No destructive rollback to preserve job history.
    }

    private function create_or_upgrade_background_jobs()
    {
        if (!$this->table_exists('background_jobs')) {
            $this->db->query("
                CREATE TABLE `background_jobs` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id` BIGINT(20) UNSIGNED DEFAULT NULL,
                    `job_type` VARCHAR(100) NOT NULL,
                    `queue_name` VARCHAR(60) NOT NULL DEFAULT 'default',
                    `payload_json` LONGTEXT NOT NULL,
                    `status` ENUM('pending','queued','processing','success','failed','cancelled') NOT NULL DEFAULT 'pending',
                    `attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                    `max_attempts` INT(10) UNSIGNED NOT NULL DEFAULT 5,
                    `last_error` TEXT NULL,
                    `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `started_at` DATETIME DEFAULT NULL,
                    `finished_at` DATETIME DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_background_jobs_status_available` (`status`,`available_at`),
                    KEY `idx_background_jobs_tenant` (`tenant_id`),
                    KEY `idx_background_jobs_queue` (`queue_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $this->add_column_if_missing('background_jobs', 'tenant_id', "BIGINT(20) UNSIGNED NULL AFTER `id`");
        $this->add_column_if_missing('background_jobs', 'job_type', "VARCHAR(100) NOT NULL");
        $this->add_column_if_missing('background_jobs', 'queue_name', "VARCHAR(60) NOT NULL DEFAULT 'default'");
        $this->add_column_if_missing('background_jobs', 'payload_json', "LONGTEXT NOT NULL");
        $this->add_column_if_missing('background_jobs', 'status', "ENUM('pending','queued','processing','success','failed','cancelled') NOT NULL DEFAULT 'pending'");
        $this->add_column_if_missing('background_jobs', 'attempts', "INT(10) UNSIGNED NOT NULL DEFAULT 0");
        $this->add_column_if_missing('background_jobs', 'max_attempts', "INT(10) UNSIGNED NOT NULL DEFAULT 5");
        $this->add_column_if_missing('background_jobs', 'last_error', "TEXT NULL");
        $this->add_column_if_missing('background_jobs', 'available_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->add_column_if_missing('background_jobs', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->add_column_if_missing('background_jobs', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        $this->db->query("
            ALTER TABLE `background_jobs`
            MODIFY `status` ENUM('pending','queued','processing','success','failed','cancelled') NOT NULL DEFAULT 'pending'
        ");
        $this->db->query("UPDATE `background_jobs` SET `status` = 'pending' WHERE `status` = 'queued'");

        if (!$this->index_exists('background_jobs', 'idx_background_jobs_status_available')) {
            $this->db->query("ALTER TABLE `background_jobs` ADD INDEX `idx_background_jobs_status_available` (`status`,`available_at`)");
        }
        if (!$this->index_exists('background_jobs', 'idx_background_jobs_tenant')) {
            $this->db->query("ALTER TABLE `background_jobs` ADD INDEX `idx_background_jobs_tenant` (`tenant_id`)");
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

    private function add_column_if_missing($table, $column, $definition)
    {
        if ($this->column_exists($table, $column)) {
            return;
        }

        $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
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

