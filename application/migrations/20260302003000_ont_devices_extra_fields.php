<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Ont_devices_extra_fields extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('ont_devices')) {
            return;
        }

        $fields = $this->db->list_fields('ont_devices');

        if (!in_array('ont_username', $fields, true)) {
            $this->dbforge->add_column('ont_devices', array(
                'ont_username' => array(
                    'type' => 'VARCHAR',
                    'constraint' => 120,
                    'null' => true,
                    'after' => 'wifi_password',
                ),
            ));
            $this->db->query('ALTER TABLE `ont_devices` ADD INDEX `idx_ont_username` (`ont_username`)');
        }

        if (!in_array('optical_rx_dbm', $fields, true)) {
            $this->dbforge->add_column('ont_devices', array(
                'optical_rx_dbm' => array(
                    'type' => 'VARCHAR',
                    'constraint' => 50,
                    'null' => true,
                    'after' => 'ont_username',
                ),
            ));
        }
    }

    public function down()
    {
        if (!$this->db->table_exists('ont_devices')) {
            return;
        }

        $fields = $this->db->list_fields('ont_devices');

        if (in_array('optical_rx_dbm', $fields, true)) {
            $this->dbforge->drop_column('ont_devices', 'optical_rx_dbm');
        }
        if (in_array('ont_username', $fields, true)) {
            $this->db->query('ALTER TABLE `ont_devices` DROP INDEX `idx_ont_username`');
            $this->dbforge->drop_column('ont_devices', 'ont_username');
        }
    }
}

