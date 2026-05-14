<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Work Order status history data access.
 */
class Wo_history_model extends CI_Model
{
    protected $table = 'wo_status_history';

    public function insert(array $data)
    {
        $this->db->insert($this->table, $data);
        return (int) $this->db->insert_id();
    }

    public function get_by_wo($wo_id)
    {
        return $this->db
            ->where('wo_id', (int) $wo_id)
            ->order_by('id', 'asc')
            ->get($this->table)
            ->result();
    }
}
