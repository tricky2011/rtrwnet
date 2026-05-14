<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Fiber_network_map_module extends CI_Migration
{
    public function up()
    {
        $this->ensure_fiber_odp_table();
        $this->ensure_customers_columns();
        $this->ensure_master_olt_columns();
    }

    public function down()
    {
        // Non-destructive rollback intentionally omitted for production safety.
    }

    private function ensure_fiber_odp_table()
    {
        if (!$this->db->table_exists('fiber_odp')) {
            $this->db->query("
                CREATE TABLE `fiber_odp` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `router_id` BIGINT(20) UNSIGNED NOT NULL,
                    `olt_id` BIGINT(20) UNSIGNED NULL,
                    `pon_port` VARCHAR(50) NULL,
                    `name` VARCHAR(120) NOT NULL,
                    `latitude` DECIMAL(10,7) NULL,
                    `longitude` DECIMAL(10,7) NULL,
                    `capacity` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                    `used_ports` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                    `description` TEXT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_fiber_odp_router` (`router_id`),
                    KEY `idx_fiber_odp_olt` (`olt_id`),
                    KEY `idx_fiber_odp_pon` (`pon_port`),
                    KEY `idx_fiber_odp_active` (`is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $fields = $this->db->list_fields('fiber_odp');
        $this->add_column_if_missing('fiber_odp', $fields, 'router_id', "BIGINT(20) UNSIGNED NOT NULL DEFAULT 0");
        $this->add_column_if_missing('fiber_odp', $fields, 'olt_id', "BIGINT(20) UNSIGNED NULL");
        $this->add_column_if_missing('fiber_odp', $fields, 'pon_port', "VARCHAR(50) NULL");
        $this->add_column_if_missing('fiber_odp', $fields, 'name', "VARCHAR(120) NOT NULL DEFAULT ''");
        $this->add_column_if_missing('fiber_odp', $fields, 'latitude', "DECIMAL(10,7) NULL");
        $this->add_column_if_missing('fiber_odp', $fields, 'longitude', "DECIMAL(10,7) NULL");
        $this->add_column_if_missing('fiber_odp', $fields, 'capacity', "INT(10) UNSIGNED NOT NULL DEFAULT 0");
        $this->add_column_if_missing('fiber_odp', $fields, 'used_ports', "INT(10) UNSIGNED NOT NULL DEFAULT 0");
        $this->add_column_if_missing('fiber_odp', $fields, 'description', "TEXT NULL");
        $this->add_column_if_missing('fiber_odp', $fields, 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
        $this->add_column_if_missing('fiber_odp', $fields, 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->add_column_if_missing('fiber_odp', $fields, 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        $this->add_index_if_missing('fiber_odp', 'idx_fiber_odp_router', array('router_id'));
        $this->add_index_if_missing('fiber_odp', 'idx_fiber_odp_olt', array('olt_id'));
        $this->add_index_if_missing('fiber_odp', 'idx_fiber_odp_pon', array('pon_port'));
        $this->add_index_if_missing('fiber_odp', 'idx_fiber_odp_active', array('is_active'));
    }

    private function ensure_customers_columns()
    {
        if (!$this->db->table_exists('customers')) {
            return;
        }

        $fields = $this->db->list_fields('customers');
        $this->add_column_if_missing('customers', $fields, 'latitude', "DECIMAL(10,7) NULL");
        $this->add_column_if_missing('customers', $fields, 'longitude', "DECIMAL(10,7) NULL");
        $this->add_column_if_missing('customers', $fields, 'odp_id', "BIGINT(20) UNSIGNED NULL");
        $this->add_column_if_missing('customers', $fields, 'router_id', "BIGINT(20) UNSIGNED NULL");

        $this->add_index_if_missing('customers', 'idx_customers_odp_id', array('odp_id'));
        $this->add_index_if_missing('customers', 'idx_customers_router_odp', array('router_id', 'odp_id'));
    }

    private function ensure_master_olt_columns()
    {
        $table = null;
        if ($this->db->table_exists('master_olts')) {
            $table = 'master_olts';
        } elseif ($this->db->table_exists('master_olt')) {
            $table = 'master_olt';
        }

        if ($table === null) {
            return;
        }

        $fields = $this->db->list_fields($table);
        $this->add_column_if_missing($table, $fields, 'router_id', "BIGINT(20) UNSIGNED NULL");
        $this->add_column_if_missing($table, $fields, 'latitude', "DECIMAL(10,7) NULL");
        $this->add_column_if_missing($table, $fields, 'longitude', "DECIMAL(10,7) NULL");

        $this->add_index_if_missing($table, 'idx_' . $table . '_router_id', array('router_id'));
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
