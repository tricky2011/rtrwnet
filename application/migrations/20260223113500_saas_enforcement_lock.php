<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Saas_enforcement_lock extends CI_Migration
{
    public function up()
    {
        $this->ensure_tenants_columns();
        $this->ensure_subscription_columns();
        $this->ensure_indexes();
    }

    public function down()
    {
        // Down migration sengaja tidak drop kolom untuk mencegah kehilangan histori status suspend/resume.
    }

    private function ensure_tenants_columns()
    {
        if (!$this->table_exists('tenants')) {
            return;
        }

        $this->add_column_if_missing('tenants', 'suspended_at', "DATETIME NULL");
        $this->add_column_if_missing('tenants', 'suspend_reason', "VARCHAR(190) NULL");
        $this->add_column_if_missing('tenants', 'resumed_at', "DATETIME NULL");
    }

    private function ensure_subscription_columns()
    {
        if (!$this->table_exists('tenant_subscriptions')) {
            return;
        }

        $this->add_column_if_missing('tenant_subscriptions', 'suspended_at', "DATETIME NULL");
        $this->add_column_if_missing('tenant_subscriptions', 'suspend_reason', "VARCHAR(190) NULL");
    }

    private function ensure_indexes()
    {
        if ($this->table_exists('tenants') && !$this->index_exists('tenants', 'idx_tenants_status_updated')) {
            $this->db->query("ALTER TABLE `tenants` ADD INDEX `idx_tenants_status_updated` (`status`, `updated_at`)");
        }

        if ($this->table_exists('tenant_subscriptions') && !$this->index_exists('tenant_subscriptions', 'idx_tenant_subscriptions_status_end')) {
            $this->db->query("ALTER TABLE `tenant_subscriptions` ADD INDEX `idx_tenant_subscriptions_status_end` (`status`, `end_date`)");
        }

        if ($this->table_exists('tenant_invoices') && !$this->index_exists('tenant_invoices', 'idx_tenant_invoices_tenant_status_due')) {
            $this->db->query("ALTER TABLE `tenant_invoices` ADD INDEX `idx_tenant_invoices_tenant_status_due` (`tenant_id`, `status`, `due_date`)");
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

