<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Genieacs_ont_devices extends CI_Migration
{
    public function up()
    {
        if ($this->db->table_exists('ont_devices')) {
            return;
        }

        $this->dbforge->add_field(array(
            'id' => array(
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ),
            'customer_id' => array(
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
            ),
            'serial_number' => array(
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
            ),
            'product_class' => array(
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ),
            'manufacturer' => array(
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ),
            'wan_ip' => array(
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
            ),
            'ssid' => array(
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ),
            'wifi_password' => array(
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
            ),
            'status' => array(
                'type' => 'ENUM("online","offline")',
                'default' => 'offline',
                'null' => false,
            ),
            'last_inform' => array(
                'type' => 'DATETIME',
                'null' => true,
            ),
            'created_at' => array(
                'type' => 'TIMESTAMP',
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
            ),
            'updated_at' => array(
                'type' => 'DATETIME',
                'null' => true,
            ),
        ));
        $this->dbforge->add_key('id', true);
        $this->dbforge->add_key('serial_number', true);
        $this->dbforge->add_key('customer_id');
        $this->dbforge->add_key('status');
        $this->dbforge->create_table('ont_devices', true, array('ENGINE' => 'InnoDB'));
    }

    public function down()
    {
        if ($this->db->table_exists('ont_devices')) {
            $this->dbforge->drop_table('ont_devices', true);
        }
    }
}
