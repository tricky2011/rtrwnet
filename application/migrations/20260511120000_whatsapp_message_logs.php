<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Whatsapp_message_logs extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('wa_message_logs')) {
            $this->db->query("
                CREATE TABLE `wa_message_logs` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `customer_id` BIGINT(20) UNSIGNED NULL,
                    `invoice_id` BIGINT(20) UNSIGNED NULL,
                    `phone` VARCHAR(40) NOT NULL,
                    `normalized_phone` VARCHAR(20) NOT NULL,
                    `message` TEXT NOT NULL,
                    `status` ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
                    `provider_response` LONGTEXT NULL,
                    `error_message` TEXT NULL,
                    `retry_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                    `sent_at` DATETIME NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_wa_message_logs_customer_id` (`customer_id`),
                    KEY `idx_wa_message_logs_invoice_id` (`invoice_id`),
                    KEY `idx_wa_message_logs_status` (`status`),
                    KEY `idx_wa_message_logs_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $this->add_column_if_missing('wa_message_logs', 'customer_id', "BIGINT(20) UNSIGNED NULL");
        $this->add_column_if_missing('wa_message_logs', 'invoice_id', "BIGINT(20) UNSIGNED NULL");
        $this->add_column_if_missing('wa_message_logs', 'phone', "VARCHAR(40) NOT NULL DEFAULT ''");
        $this->add_column_if_missing('wa_message_logs', 'normalized_phone', "VARCHAR(20) NOT NULL DEFAULT ''");
        $this->add_column_if_missing('wa_message_logs', 'message', "TEXT NOT NULL");
        $this->add_column_if_missing('wa_message_logs', 'status', "ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending'");
        $this->add_column_if_missing('wa_message_logs', 'provider_response', "LONGTEXT NULL");
        $this->add_column_if_missing('wa_message_logs', 'error_message', "TEXT NULL");
        $this->add_column_if_missing('wa_message_logs', 'retry_count', "INT(10) UNSIGNED NOT NULL DEFAULT 0");
        $this->add_column_if_missing('wa_message_logs', 'sent_at', "DATETIME NULL");
        $this->add_column_if_missing('wa_message_logs', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->add_column_if_missing('wa_message_logs', 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        $this->add_index_if_missing('wa_message_logs', 'idx_wa_message_logs_customer_id', array('customer_id'));
        $this->add_index_if_missing('wa_message_logs', 'idx_wa_message_logs_invoice_id', array('invoice_id'));
        $this->add_index_if_missing('wa_message_logs', 'idx_wa_message_logs_status', array('status'));
        $this->add_index_if_missing('wa_message_logs', 'idx_wa_message_logs_created_at', array('created_at'));
    }

    public function down()
    {
        // Non-destructive rollback: log pengiriman tidak dihapus otomatis.
    }

    private function add_column_if_missing($table, $column, $definition)
    {
        if (!$this->db->table_exists($table)) {
            return;
        }
        if (in_array($column, $this->db->list_fields($table), true)) {
            return;
        }

        $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }

    private function add_index_if_missing($table, $index_name, array $columns)
    {
        if ($this->index_exists($table, $index_name)) {
            return;
        }

        $escaped = array();
        foreach ($columns as $column) {
            $escaped[] = '`' . $column . '`';
        }
        $this->db->query("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (" . implode(',', $escaped) . ")");
    }

    private function index_exists($table, $index_name)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }

        $sql = "SHOW INDEX FROM `{$table}` WHERE `Key_name` = " . $this->db->escape($index_name);
        return $this->db->query($sql)->num_rows() > 0;
    }
}
