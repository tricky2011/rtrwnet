<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Whatsapp_log_model extends CI_Model
{
    protected $table = 'wa_message_logs';

    public function table_ready()
    {
        return $this->db->table_exists($this->table);
    }

    public function insert_log(array $data)
    {
        if (!$this->table_ready()) {
            log_message('error', '[Whatsapp_log_model] Tabel wa_message_logs belum tersedia.');
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $payload = array(
            'customer_id' => !empty($data['customer_id']) ? (int) $data['customer_id'] : null,
            'invoice_id' => !empty($data['invoice_id']) ? (int) $data['invoice_id'] : null,
            'phone' => (string) ($data['phone'] ?? ''),
            'normalized_phone' => (string) ($data['normalized_phone'] ?? ''),
            'message' => (string) ($data['message'] ?? ''),
            'status' => (string) ($data['status'] ?? 'pending'),
            'provider_response' => isset($data['provider_response']) ? (string) $data['provider_response'] : null,
            'error_message' => isset($data['error_message']) ? (string) $data['error_message'] : null,
            'retry_count' => isset($data['retry_count']) ? (int) $data['retry_count'] : 0,
            'sent_at' => isset($data['sent_at']) ? $data['sent_at'] : null,
            'created_at' => (string) ($data['created_at'] ?? $now),
            'updated_at' => (string) ($data['updated_at'] ?? $now),
        );

        $ok = $this->db->insert($this->table, $payload);
        return $ok ? (int) $this->db->insert_id() : false;
    }

    public function update_status($id, $status, $provider_response = null, $error_message = null, array $extra = array())
    {
        if (!$this->table_ready()) {
            return false;
        }

        $payload = array(
            'status' => (string) $status,
            'updated_at' => date('Y-m-d H:i:s'),
        );

        if ($provider_response !== null) {
            $payload['provider_response'] = is_string($provider_response)
                ? $provider_response
                : json_encode($provider_response);
        }
        if ($error_message !== null) {
            $payload['error_message'] = (string) $error_message;
        }
        if ((string) $status === 'sent') {
            $payload['sent_at'] = date('Y-m-d H:i:s');
            $payload['error_message'] = null;
        }

        foreach ($extra as $key => $value) {
            if (in_array($key, array('retry_count', 'sent_at'), true)) {
                $payload[$key] = $value;
            }
        }

        return $this->db
            ->where('id', (int) $id)
            ->update($this->table, $payload);
    }

    public function mark_processing($id)
    {
        if (!$this->table_ready()) {
            return false;
        }

        $this->db
            ->where('id', (int) $id)
            ->where('status', 'pending')
            ->update($this->table, array(
                'status' => 'processing',
                'updated_at' => date('Y-m-d H:i:s'),
            ));

        return $this->db->affected_rows() > 0;
    }

    public function mark_sent($id, $provider_response)
    {
        return $this->update_status($id, 'sent', $provider_response, null);
    }

    public function mark_failed($id, $provider_response, $error_message)
    {
        if (!$this->table_ready()) {
            return false;
        }

        $this->db
            ->set('status', 'failed')
            ->set('provider_response', is_string($provider_response) ? $provider_response : json_encode($provider_response))
            ->set('error_message', (string) $error_message)
            ->set('retry_count', 'retry_count + 1', false)
            ->set('updated_at', date('Y-m-d H:i:s'))
            ->where('id', (int) $id)
            ->update($this->table);

        return $this->db->affected_rows() >= 0;
    }

    public function get_pending_messages($limit = 10, $max_retry = 3)
    {
        if (!$this->table_ready()) {
            return array();
        }

        return $this->db
            ->from($this->table)
            ->where('status', 'pending')
            ->where('retry_count <', max(1, (int) $max_retry))
            ->order_by('created_at', 'ASC')
            ->order_by('id', 'ASC')
            ->limit(max(1, (int) $limit))
            ->get()
            ->result_array();
    }

    public function release_stale_processing($minutes = 15)
    {
        if (!$this->table_ready()) {
            return 0;
        }

        $minutes = max(1, (int) $minutes);
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $minutes . ' minutes'));

        $this->db
            ->where('status', 'processing')
            ->where('updated_at <', $cutoff)
            ->update($this->table, array(
                'status' => 'pending',
                'error_message' => 'Processing timeout, dikembalikan ke queue.',
                'updated_at' => date('Y-m-d H:i:s'),
            ));

        return (int) $this->db->affected_rows();
    }

    public function get_log($id)
    {
        if (!$this->table_ready()) {
            return array();
        }

        return (array) $this->db
            ->from($this->table)
            ->where('id', (int) $id)
            ->limit(1)
            ->get()
            ->row_array();
    }

    public function retry_message($id, $max_retry = 3)
    {
        $log = $this->get_log($id);
        if (empty($log)) {
            return array('success' => false, 'message' => 'Log WhatsApp tidak ditemukan.');
        }

        $status = strtolower((string) ($log['status'] ?? ''));
        if ($status === 'processing') {
            return array('success' => false, 'message' => 'Pesan sedang diproses.');
        }

        if ($status === 'sent' || (int) ($log['retry_count'] ?? 0) >= (int) $max_retry) {
            $new_id = $this->clone_for_resend((int) $id);
            return $new_id
                ? array('success' => true, 'message' => 'Pesan masuk kembali ke antrian.', 'id' => $new_id)
                : array('success' => false, 'message' => 'Gagal menambahkan pesan ke antrian.');
        }

        $ok = $this->db
            ->where('id', (int) $id)
            ->update($this->table, array(
                'status' => 'pending',
                'error_message' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ));

        return array(
            'success' => (bool) $ok,
            'message' => $ok ? 'Pesan dikembalikan ke queue.' : 'Gagal retry pesan.',
            'id' => (int) $id,
        );
    }

    public function clone_for_resend($id)
    {
        $log = $this->get_log($id);
        if (empty($log)) {
            return false;
        }

        return $this->insert_log(array(
            'customer_id' => $log['customer_id'] ?? null,
            'invoice_id' => $log['invoice_id'] ?? null,
            'phone' => $log['phone'] ?? '',
            'normalized_phone' => $log['normalized_phone'] ?? '',
            'message' => $log['message'] ?? '',
            'status' => 'pending',
            'retry_count' => 0,
        ));
    }

    public function exists_today($invoice_id, $message_keyword = '')
    {
        if (!$this->table_ready()) {
            return false;
        }

        $invoice_id = (int) $invoice_id;
        if ($invoice_id <= 0) {
            return false;
        }

        $qb = $this->db
            ->from($this->table)
            ->where('invoice_id', $invoice_id)
            ->where('created_at >=', date('Y-m-d') . ' 00:00:00')
            ->where('created_at <=', date('Y-m-d') . ' 23:59:59');

        $message_keyword = trim((string) $message_keyword);
        if ($message_keyword !== '') {
            $qb->like('message', $message_keyword);
        }

        return $qb->count_all_results() > 0;
    }

    public function count_logs(array $filters = array())
    {
        if (!$this->table_ready()) {
            return 0;
        }

        return (int) $this->build_query($filters, true)->count_all_results();
    }

    public function get_logs(array $filters = array(), $limit = 20, $offset = 0)
    {
        if (!$this->table_ready()) {
            return array();
        }

        return $this->build_query($filters, false)
            ->order_by('wl.created_at', 'DESC')
            ->order_by('wl.id', 'DESC')
            ->limit(max(1, (int) $limit), max(0, (int) $offset))
            ->get()
            ->result_array();
    }

    public function get_stats()
    {
        if (!$this->table_ready()) {
            return array('pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0);
        }

        $rows = $this->db
            ->select('status, COUNT(*) as total')
            ->from($this->table)
            ->group_by('status')
            ->get()
            ->result_array();

        $stats = array('pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0);
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($stats[$status])) {
                $stats[$status] = (int) ($row['total'] ?? 0);
            }
        }

        return $stats;
    }

    private function build_query(array $filters, $count_only = false)
    {
        $qb = $this->db->from($this->table . ' wl');
        if (!$count_only) {
            $qb->select('wl.*');
        }

        $invoice_fields = $this->db->table_exists('invoices') ? $this->db->list_fields('invoices') : array();
        if (!empty($invoice_fields) && in_array('id', $invoice_fields, true)) {
            $qb->join('invoices i', 'i.id = wl.invoice_id', 'left');
            if (!$count_only && in_array('invoice_number', $invoice_fields, true)) {
                $qb->select('i.invoice_number');
            }
        }

        $customer_fields = $this->db->table_exists('customers') ? $this->db->list_fields('customers') : array();
        if (!empty($customer_fields) && in_array('id', $customer_fields, true)) {
            $qb->join('customers c', 'c.id = wl.customer_id', 'left');
            if (!$count_only) {
                $name_expr = $this->customer_name_expression($customer_fields);
                $phone_expr = in_array('phone', $customer_fields, true) ? 'c.phone' : "''";
                $qb->select($name_expr . ' AS customer_name, ' . $phone_expr . ' AS customer_phone', false);
            }
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if (in_array($status, array('pending', 'processing', 'sent', 'failed'), true)) {
            $qb->where('wl.status', $status);
        }

        if (!empty($filters['invoice_id'])) {
            $qb->where('wl.invoice_id', (int) $filters['invoice_id']);
        }
        if (!empty($filters['customer_id'])) {
            $qb->where('wl.customer_id', (int) $filters['customer_id']);
        }

        $date_from = trim((string) ($filters['date_from'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $qb->where('wl.created_at >=', $date_from . ' 00:00:00');
        }

        $date_to = trim((string) ($filters['date_to'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $qb->where('wl.created_at <=', $date_to . ' 23:59:59');
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $qb->group_start()
                ->like('wl.phone', $search)
                ->or_like('wl.normalized_phone', $search)
                ->or_like('wl.message', $search)
                ->or_like('wl.error_message', $search);

            if (!empty($invoice_fields) && in_array('invoice_number', $invoice_fields, true)) {
                $qb->or_like('i.invoice_number', $search);
            }
            if (!empty($customer_fields)) {
                foreach (array('full_name', 'nama', 'name', 'customer_code') as $col) {
                    if (in_array($col, $customer_fields, true)) {
                        $qb->or_like('c.' . $col, $search);
                    }
                }
            }

            $qb->group_end();
        }

        return $qb;
    }

    private function customer_name_expression(array $fields)
    {
        $parts = array();
        foreach (array('full_name', 'nama', 'name') as $col) {
            if (in_array($col, $fields, true)) {
                $parts[] = "NULLIF(c.{$col}, '')";
            }
        }

        if (empty($parts)) {
            return "CONCAT('Customer #', c.id)";
        }

        $parts[] = "CONCAT('Customer #', c.id)";
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }
}
