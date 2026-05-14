<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Static_ip_sync_customer_columns extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('customers')) {
            return;
        }

        $this->add_column_if_missing('customers', 'connection_type', "ENUM('PPPOE','STATIC') NOT NULL DEFAULT 'PPPOE' AFTER `ip_address`");
        $this->add_column_if_missing('customers', 'queue_name', "VARCHAR(120) NULL AFTER `connection_type`");
        $this->add_column_if_missing('customers', 'mac_address', "VARCHAR(32) NULL AFTER `queue_name`");
        $this->add_column_if_missing('customers', 'last_seen', "DATETIME NULL AFTER `mac_address`");
        $this->add_column_if_missing('customers', 'static_source', "VARCHAR(40) NULL AFTER `last_seen`");

        $this->db->query("
            UPDATE `customers`
            SET `connection_type` = 'STATIC'
            WHERE `connection_type` <> 'STATIC'
              AND (
                    `notes` LIKE 'Auto sync STATIC%'
                    OR (`pppoe_username` IS NULL OR `pppoe_username` = '')
                  )
              AND (`ip_address` IS NOT NULL AND `ip_address` <> '')
        ");

        $this->add_index_if_missing('customers', 'idx_customers_connection_type', array('connection_type'));
        $this->add_index_if_missing('customers', 'idx_customers_queue_name', array('queue_name'));
        $this->add_index_if_missing('customers', 'idx_customers_ip_address', array('ip_address'));
        $this->add_index_if_missing('customers', 'idx_customers_mac_address', array('mac_address'));
    }

    public function down()
    {
        // Non-destructive rollback: kolom sync static IP tidak dihapus otomatis.
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
