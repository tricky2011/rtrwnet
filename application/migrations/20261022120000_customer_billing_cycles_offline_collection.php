<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Customer_billing_cycles_offline_collection extends CI_Migration
{
    public function up()
    {
        foreach (array(
            'billing_interval_months' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
            'billing_cycle_start' => 'DATE NULL',
            'payment_collection_method' => "VARCHAR(20) NOT NULL DEFAULT 'unspecified'",
        ) as $column => $definition) {
            if (!$this->db->field_exists($column, 'customers')) {
                $this->db->query("ALTER TABLE `customers` ADD COLUMN `{$column}` {$definition}");
            }
        }
        // Preserve all existing enum values, including legacy group types.
        foreach (array('type', 'group_type') as $column) {
            if (!$this->db->field_exists($column, 'telegram_groups')) {
                continue;
            }
            $field = $this->db->query('SHOW COLUMNS FROM `telegram_groups` WHERE Field = ?', array($column))->row_array();
            $type = (string) $field['Type'];
            if (strpos($type, 'enum(') === 0 && strpos($type, "'collection'") === false) {
                $type = substr($type, 0, -1) . ",'collection')";
                $default = $field['Default'] !== null ? ' DEFAULT ' . $this->db->escape($field['Default']) : '';
                $this->db->query("ALTER TABLE `telegram_groups` MODIFY COLUMN `{$column}` {$type} "
                    . ($field['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . $default);
            }
        }
        $this->db->query("CREATE TABLE IF NOT EXISTS `offline_collection_deliveries` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `period` CHAR(7) NOT NULL,
            `router_id` BIGINT UNSIGNED NOT NULL,
            `telegram_group_id` BIGINT UNSIGNED NOT NULL,
            `chat_id` VARCHAR(100) NOT NULL,
            `part_no` INT UNSIGNED NOT NULL,
            `message` TEXT NOT NULL,
            `sent_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_offline_collection_part` (`period`,`router_id`,`telegram_group_id`,`part_no`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        // Keep customer preferences and delivery history on rollback.
    }
}
