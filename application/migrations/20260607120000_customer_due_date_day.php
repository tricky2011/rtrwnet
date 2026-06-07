<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Customer_due_date_day extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('customers')) {
            return;
        }

        $this->add_column_if_missing('customers', 'due_date_day', "TINYINT(3) UNSIGNED NULL AFTER `join_date`");
        $this->add_index_if_missing('customers', 'idx_customers_due_date_day', array('due_date_day'));

        if ($this->db->table_exists('routers')) {
            $fields = $this->db->list_fields('customers');
            $updated_at_set = in_array('updated_at', $fields, true)
                ? ", c.`updated_at` = NOW()"
                : "";

            $this->db->query("
                UPDATE `customers` c
                INNER JOIN `routers` r ON r.id = c.router_id
                SET c.`due_date_day` = 20{$updated_at_set}
                WHERE LOWER(r.`name`) = 'kalisari'
                  AND (c.`due_date_day` IS NULL OR c.`due_date_day` <> 20)
            ");
        }
    }

    public function down()
    {
        // Non-destructive rollback: kolom jatuh tempo customer tidak dihapus otomatis.
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
