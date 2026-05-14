<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Customer_upgrade_feature extends CI_Migration
{
    public function up()
    {
        $this->create_customer_service_history_table();
        $this->ensure_customers_columns();
        $this->ensure_invoices_columns();
    }

    public function down()
    {
        // Non-destructive rollback intentionally omitted for production safety.
    }

    private function create_customer_service_history_table()
    {
        if (!$this->db->table_exists('customer_service_history')) {
            $this->db->query("\n                CREATE TABLE `customer_service_history` (\n                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n                    `customer_id` BIGINT(20) UNSIGNED NOT NULL,\n                    `old_plan_id` BIGINT(20) UNSIGNED NOT NULL,\n                    `new_plan_id` BIGINT(20) UNSIGNED NOT NULL,\n                    `upgrade_type` ENUM('upgrade','downgrade') NOT NULL DEFAULT 'upgrade',\n                    `old_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,\n                    `new_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,\n                    `prorate_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,\n                    `upgrade_date` DATE NOT NULL,\n                    `created_by` BIGINT(20) UNSIGNED DEFAULT NULL,\n                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                    PRIMARY KEY (`id`),\n                    KEY `idx_csh_customer` (`customer_id`),\n                    KEY `idx_csh_upgrade_date` (`upgrade_date`)\n                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n            ");
            return;
        }

        $fields = $this->db->list_fields('customer_service_history');
        $this->add_column_if_missing('customer_service_history', $fields, 'customer_id', "BIGINT(20) UNSIGNED NOT NULL DEFAULT 0");
        $this->add_column_if_missing('customer_service_history', $fields, 'old_plan_id', "BIGINT(20) UNSIGNED NOT NULL DEFAULT 0");
        $this->add_column_if_missing('customer_service_history', $fields, 'new_plan_id', "BIGINT(20) UNSIGNED NOT NULL DEFAULT 0");
        if (!in_array('upgrade_type', $fields, true)) {
            $this->db->query("ALTER TABLE `customer_service_history` ADD COLUMN `upgrade_type` ENUM('upgrade','downgrade') NOT NULL DEFAULT 'upgrade'");
        }
        $this->add_column_if_missing('customer_service_history', $fields, 'old_price', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
        $this->add_column_if_missing('customer_service_history', $fields, 'new_price', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
        $this->add_column_if_missing('customer_service_history', $fields, 'prorate_amount', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
        $this->add_column_if_missing('customer_service_history', $fields, 'upgrade_date', "DATE NULL");
        $this->add_column_if_missing('customer_service_history', $fields, 'created_by', "BIGINT(20) UNSIGNED NULL");
        $this->add_column_if_missing('customer_service_history', $fields, 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");

        $this->add_index_if_missing('customer_service_history', 'idx_csh_customer', array('customer_id'));
        $this->add_index_if_missing('customer_service_history', 'idx_csh_upgrade_date', array('upgrade_date'));
    }

    private function ensure_customers_columns()
    {
        if (!$this->db->table_exists('customers')) {
            return;
        }

        $fields = $this->db->list_fields('customers');
        $this->add_column_if_missing('customers', $fields, 'service_plan_id', "BIGINT(20) UNSIGNED NULL");
        $this->add_column_if_missing('customers', $fields, 'price', "DECIMAL(12,2) NULL");

        $this->add_index_if_missing('customers', 'idx_customers_service_plan_id', array('service_plan_id'));
    }

    private function ensure_invoices_columns()
    {
        if (!$this->db->table_exists('invoices')) {
            return;
        }

        $fields = $this->db->list_fields('invoices');
        if (!in_array('invoice_type', $fields, true)) {
            $this->db->query("ALTER TABLE `invoices` ADD COLUMN `invoice_type` ENUM('regular','upgrade') NOT NULL DEFAULT 'regular'");
        }

        $this->add_index_if_missing('invoices', 'idx_invoices_invoice_type', array('invoice_type'));
    }

    private function add_column_if_missing($table, array &$fields, $column, $definition)
    {
        if (in_array($column, $fields, true)) {
            return;
        }

        $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        $fields[] = $column;
    }

    private function add_index_if_missing($table, $index_name, array $columns)
    {
        if ($this->index_exists($table, $index_name)) {
            return;
        }

        $escaped_columns = array();
        foreach ($columns as $column) {
            $escaped_columns[] = '`' . $column . '`';
        }

        $this->db->query("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (" . implode(',', $escaped_columns) . ")");
    }

    private function index_exists($table, $index_name)
    {
        $row = $this->db->query(
            "SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1",
            array($table, $index_name)
        )->row_array();

        return !empty($row);
    }
}
