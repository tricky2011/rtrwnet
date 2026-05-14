<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Model inti invoice.
 * Dirancang untuk mendukung:
 * - rolling billing
 * - auto generate harian
 * - anti duplicate per customer per period
 * - status paid/unpaid/overdue
 * - invoice tetap disimpan (tidak dihapus)
 */
class Invoice_model extends CI_Model
{
    protected $table = 'invoices';

    public function get($id)
    {
        return $this->db->get_where($this->table, ['id' => (int) $id])->row();
    }

    public function get_with_customer($invoice_id)
    {
        return $this->db
            ->select('i.*, c.full_name AS customer_name, c.customer_code, c.status AS customer_status')
            ->from($this->table . ' i')
            ->join('customers c', 'c.id = i.customer_id', 'inner')
            ->where('i.id', (int) $invoice_id)
            ->get()
            ->row();
    }

    /**
     * Cek duplicate invoice untuk 1 customer di 1 period (YYYY-MM).
     */
    public function exists($customer_id, $period)
    {
        return $this->db
            ->where('customer_id', (int) $customer_id)
            ->where('period_month', $period)
            ->where_in('status', ['unpaid', 'overdue', 'paid'])
            ->count_all_results($this->table) > 0;
    }

    public function insert(array $data)
    {
        $this->db->insert($this->table, $data);
        return (int) $this->db->insert_id();
    }

    /**
     * Anti duplicate layer di DB:
     * butuh UNIQUE KEY (customer_id, period_month).
     *
     * Return:
     * - insert_id jika sukses insert
     * - 0 jika duplicate (ignored)
     */
    public function insert_ignore(array $data)
    {
        $sql = $this->db->insert_string($this->table, $data);
        $sql = preg_replace('/^INSERT/i', 'INSERT IGNORE', $sql, 1);
        $this->db->query($sql);

        return (int) $this->db->insert_id();
    }

    public function update($id, array $data)
    {
        return $this->db
            ->where('id', (int) $id)
            ->update($this->table, $data);
    }

    /**
     * Tandai invoice unpaid -> overdue jika due_date lewat cutoff_date.
     */
    public function mark_overdue_batch($cutoff_date)
    {
        $this->db
            ->set('status', 'overdue')
            ->where('status', 'unpaid')
            ->where('due_date <', $cutoff_date)
            ->update($this->table);

        return $this->db->affected_rows();
    }

    public function mark_overdue($customer_id)
    {
        return $this->db
            ->set('status', 'overdue')
            ->where('customer_id', (int) $customer_id)
            ->where('status', 'unpaid')
            ->update($this->table);
    }

    public function count_unpaid($customer_id)
    {
        return (int) $this->db
            ->where('customer_id', (int) $customer_id)
            ->where_in('status', ['unpaid', 'overdue'])
            ->count_all_results($this->table);
    }

    public function get_unpaid_by_customer($customer_id)
    {
        return $this->db
            ->where('customer_id', (int) $customer_id)
            ->where_in('status', ['unpaid', 'overdue'])
            ->order_by('period_month', 'asc')
            ->get($this->table)
            ->result();
    }

    /**
     * Dipakai untuk proses isolir (ambil customer active yang punya overdue).
     */
    public function get_customers_with_overdue($customer_status = 'active')
    {
        return $this->db
            ->select('DISTINCT c.id, c.customer_code, c.full_name, c.status')
            ->from($this->table . ' i')
            ->join('customers c', 'c.id = i.customer_id', 'inner')
            ->where('i.status', 'overdue')
            ->where('c.status', $customer_status)
            ->get()
            ->result();
    }
}
