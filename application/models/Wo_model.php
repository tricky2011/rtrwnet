<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Work Order data access.
 */
class Wo_model extends CI_Model
{
    protected $table = 'work_orders';

    public function insert(array $data)
    {
        $this->db->insert($this->table, $data);
        return (int) $this->db->insert_id();
    }

    public function update($id, array $data)
    {
        return $this->db
            ->where('id', (int) $id)
            ->update($this->table, $data);
    }

    public function get($id)
    {
        return $this->db
            ->where('id', (int) $id)
            ->get($this->table)
            ->row();
    }

    public function get_by_number($wo_number)
    {
        return $this->db
            ->where('wo_number', $wo_number)
            ->get($this->table)
            ->row();
    }

    public function get_with_customer($id)
    {
        return $this->db
            ->select('w.*, c.customer_code, c.full_name AS customer_name, c.phone')
            ->from($this->table . ' w')
            ->join('customers c', 'c.id = w.customer_id', 'left')
            ->where('w.id', (int) $id)
            ->get()
            ->row();
    }

    public function get_by_status($status, $limit = 100)
    {
        return $this->db
            ->where('status', $status)
            ->order_by('open_at', 'asc')
            ->limit((int) $limit)
            ->get($this->table)
            ->result();
    }

    /**
     * WO open lama untuk reminder.
     */
    public function get_open_older_than_hours($hours = 24, $limit = 100)
    {
        $hours = (int) $hours;
        if ($hours < 1) {
            $hours = 24;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        return $this->db
            ->select('w.*, c.full_name AS customer_name')
            ->from($this->table . ' w')
            ->join('customers c', 'c.id = w.customer_id', 'left')
            ->where('w.status', 'open')
            ->where('w.open_at <', $cutoff)
            ->order_by('w.open_at', 'asc')
            ->limit((int) $limit)
            ->get()
            ->result();
    }
}
