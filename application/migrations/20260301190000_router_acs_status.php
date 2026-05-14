<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Router_acs_status extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('routers')) {
            return;
        }

        $fields = $this->db->list_fields('routers');

        if (!in_array('acs_url', $fields, true)) {
            $this->db->query("ALTER TABLE `routers` ADD COLUMN `acs_url` VARCHAR(255) NULL");
        }
        if (!in_array('acs_nbi_url', $fields, true)) {
            $this->db->query("ALTER TABLE `routers` ADD COLUMN `acs_nbi_url` VARCHAR(255) NULL");
        }
        if (!in_array('acs_username', $fields, true)) {
            $this->db->query("ALTER TABLE `routers` ADD COLUMN `acs_username` VARCHAR(100) NULL");
        }
        if (!in_array('acs_password', $fields, true)) {
            $this->db->query("ALTER TABLE `routers` ADD COLUMN `acs_password` VARCHAR(255) NULL");
        }
        if (!in_array('acs_status', $fields, true)) {
            $this->db->query("ALTER TABLE `routers` ADD COLUMN `acs_status` ENUM('connected','disconnected') NOT NULL DEFAULT 'disconnected'");
        }

        $this->db->query("UPDATE `routers` SET `acs_status`='disconnected' WHERE `acs_status` IS NULL OR TRIM(`acs_status`)=''");
    }

    public function down()
    {
        // Keep non-destructive for production safety.
    }
}

