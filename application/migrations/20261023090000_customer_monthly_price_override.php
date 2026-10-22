<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Customer_monthly_price_override extends CI_Migration
{
    public function up()
    {
        if ($this->db->table_exists('customers')
            && !$this->db->field_exists('monthly_price_override', 'customers')) {
            $table = $this->db->dbprefix('customers');
            if (!$this->db->query("ALTER TABLE `{$table}` ADD COLUMN `monthly_price_override` DECIMAL(15,2) NULL DEFAULT NULL")) {
                throw new RuntimeException('Gagal menambahkan kolom harga khusus customer.');
            }
            unset($this->db->data_cache['field_names']);
        }
    }

    public function down()
    {
        // Preserve agreed customer prices on rollback.
    }
}
