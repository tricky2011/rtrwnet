<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Fiber_network_map_odc_router_geo extends CI_Migration
{
    public function up()
    {
        $this->ensure_fiber_odc_table();
        $this->ensure_fiber_odp_odc_column();
        $this->ensure_router_geo_columns();
    }

    public function down()
    {
        // Non-destructive rollback intentionally omitted.
    }

    private function ensure_fiber_odc_table()
    {
        if (!$this->db->table_exists('fiber_odc')) {
            $this->db->query("\
                CREATE TABLE `fiber_odc` (\
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\
                    `router_id` BIGINT(20) UNSIGNED NOT NULL,\
                    `olt_id` BIGINT(20) UNSIGNED NULL,\
                    `name` VARCHAR(120) NOT NULL,\
                    `latitude` DECIMAL(10,7) NULL,\
                    `longitude` DECIMAL(10,7) NULL,\
                    `capacity` INT(10) UNSIGNED NOT NULL DEFAULT 0,\
                    `used_ports` INT(10) UNSIGNED NOT NULL DEFAULT 0,\
                    `description` TEXT NULL,\
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,\
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\
                    PRIMARY KEY (`id`),\
                    KEY `idx_fiber_odc_router` (`router_id`),\
                    KEY `idx_fiber_odc_olt` (`olt_id`),\
                    KEY `idx_fiber_odc_active` (`is_active`)\
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\
            ");
            return;
        }

        $fields = $this->db->list_fields('fiber_odc');
        $this->add_column_if_missing('fiber_odc', $fields, 'router_id', "BIGINT(20) UNSIGNED NOT NULL DEFAULT 0");
        $this->add_column_if_missing('fiber_odc', $fields, 'olt_id', "BIGINT(20) UNSIGNED NULL");
        $this->add_column_if_missing('fiber_odc', $fields, 'name', "VARCHAR(120) NOT NULL DEFAULT ''");
        $this->add_column_if_missing('fiber_odc', $fields, 'latitude', "DECIMAL(10,7) NULL");
        $this->add_column_if_missing('fiber_odc', $fields, 'longitude', "DECIMAL(10,7) NULL");
        $this->add_column_if_missing('fiber_odc', $fields, 'capacity', "INT(10) UNSIGNED NOT NULL DEFAULT 0");
        $this->add_column_if_missing('fiber_odc', $fields, 'used_ports', "INT(10) UNSIGNED NOT NULL DEFAULT 0");
        $this->add_column_if_missing('fiber_odc', $fields, 'description', "TEXT NULL");
        $this->add_column_if_missing('fiber_odc', $fields, 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
        $this->add_column_if_missing('fiber_odc', $fields, 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $this->add_column_if_missing('fiber_odc', $fields, 'updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

        $this->add_index_if_missing('fiber_odc', 'idx_fiber_odc_router', array('router_id'));
        $this->add_index_if_missing('fiber_odc', 'idx_fiber_odc_olt', array('olt_id'));
        $this->add_index_if_missing('fiber_odc', 'idx_fiber_odc_active', array('is_active'));
    }

    private function ensure_fiber_odp_odc_column()
    {
        if (!$this->db->table_exists('fiber_odp')) {
            return;
        }

        $fields = $this->db->list_fields('fiber_odp');
        $this->add_column_if_missing('fiber_odp', $fields, 'odc_id', "BIGINT(20) UNSIGNED NULL");
        $this->add_index_if_missing('fiber_odp', 'idx_fiber_odp_odc', array('odc_id'));
    }

    private function ensure_router_geo_columns()
    {
        if (!$this->db->table_exists('routers')) {
            return;
        }

        $fields = $this->db->list_fields('routers');
        $this->add_column_if_missing('routers', $fields, 'latitude', "DECIMAL(10,7) NULL");
        $this->add_column_if_missing('routers', $fields, 'longitude', "DECIMAL(10,7) NULL");

        $this->add_index_if_missing('routers', 'idx_routers_geo', array('latitude', 'longitude'));
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
