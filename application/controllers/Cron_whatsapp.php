<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Cron_whatsapp extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->config('whatsapp');
        $this->load->config('cron');
        $this->load->helper('wa_template');
        $this->load->library('Whatsapp_service');
        $this->load->model('Whatsapp_log_model', 'whatsapp_log_model');
    }

    public function process_queue($limit = null)
    {
        $this->assert_cron_access();

        $limit = $limit !== null ? (int) $limit : (int) $this->config->item('wa_queue_limit');
        if ($limit <= 0) {
            $limit = 10;
        }

        return $this->respond($this->whatsapp_service->send_from_queue($limit));
    }

    public function reminder_due()
    {
        $this->assert_cron_access();

        if (!$this->whatsapp_log_model->table_ready()) {
            return $this->respond(array(
                'success' => false,
                'message' => 'Tabel wa_message_logs belum tersedia.',
                'checked' => 0,
                'queued' => 0,
            ));
        }

        $stats = array(
            'success' => true,
            'message' => 'Generate reminder due selesai.',
            'checked' => 0,
            'queued' => 0,
            'skipped_duplicate' => 0,
            'failed' => 0,
            'dates' => array(),
        );

        foreach ($this->due_reminder_days() as $day_offset) {
            $target_date = date('Y-m-d', strtotime('+' . (int) $day_offset . ' day'));
            $rows = $this->get_invoice_rows(array(
                'due_date' => $target_date,
                'statuses' => $this->unpaid_statuses(false),
                'limit' => (int) $this->config->item('wa_reminder_limit'),
            ));

            $stats['dates'][$target_date] = count($rows);
            foreach ($rows as $row) {
                $stats['checked']++;
                $invoice_id = (int) ($row['id'] ?? 0);
                if ($invoice_id <= 0) {
                    $stats['failed']++;
                    continue;
                }

                if ($this->whatsapp_log_model->exists_today($invoice_id, 'Kami mengingatkan')) {
                    $stats['skipped_duplicate']++;
                    continue;
                }

                $message = invoice_due_reminder_message(
                    $this->customer_name($row),
                    $this->invoice_number($row),
                    $this->invoice_amount_due($row),
                    $row['due_date'] ?? '',
                    $this->payment_info()
                );

                $queued = $this->whatsapp_service->send_message(
                    $this->customer_phone($row),
                    $message,
                    $invoice_id,
                    (int) ($row['customer_id'] ?? 0)
                );

                if (!empty($queued['queued'])) {
                    $stats['queued']++;
                } else {
                    $stats['failed']++;
                }
            }
        }

        return $this->respond($stats);
    }

    public function reminder_overdue()
    {
        $this->assert_cron_access();

        if (!$this->whatsapp_log_model->table_ready()) {
            return $this->respond(array(
                'success' => false,
                'message' => 'Tabel wa_message_logs belum tersedia.',
                'checked' => 0,
                'queued' => 0,
            ));
        }

        $rows = $this->get_invoice_rows(array(
            'due_before' => date('Y-m-d'),
            'statuses' => $this->unpaid_statuses(true),
            'limit' => (int) $this->config->item('wa_reminder_limit'),
        ));

        $stats = array(
            'success' => true,
            'message' => 'Generate reminder overdue selesai.',
            'checked' => count($rows),
            'queued' => 0,
            'skipped_duplicate' => 0,
            'failed' => 0,
        );

        foreach ($rows as $row) {
            $invoice_id = (int) ($row['id'] ?? 0);
            if ($invoice_id <= 0) {
                $stats['failed']++;
                continue;
            }

            if ($this->whatsapp_log_model->exists_today($invoice_id, 'melewati jatuh tempo')) {
                $stats['skipped_duplicate']++;
                continue;
            }

            $message = invoice_overdue_message(
                $this->customer_name($row),
                $this->invoice_number($row),
                $this->invoice_amount_due($row),
                $row['due_date'] ?? '',
                $this->payment_info()
            );

            $queued = $this->whatsapp_service->send_message(
                $this->customer_phone($row),
                $message,
                $invoice_id,
                (int) ($row['customer_id'] ?? 0)
            );

            if (!empty($queued['queued'])) {
                $stats['queued']++;
            } else {
                $stats['failed']++;
            }
        }

        return $this->respond($stats);
    }

    private function assert_cron_access()
    {
        if ($this->input->is_cli_request()) {
            return;
        }

        $secret = trim((string) $this->config->item('wa_cron_secret'));
        if ($secret === '') {
            $secret = trim((string) $this->config->item('cron_token'));
        }

        $provided = trim((string) $this->input->get('key', true));
        if ($provided === '') {
            $provided = trim((string) $this->input->get('secret', true));
        }
        if ($provided === '' && !empty($_SERVER['HTTP_X_CRON_SECRET'])) {
            $provided = trim((string) $_SERVER['HTTP_X_CRON_SECRET']);
        }

        if ($secret === '' || !hash_equals($secret, $provided)) {
            show_error('Forbidden', 403);
            exit;
        }
    }

    private function respond(array $data)
    {
        if ($this->input->is_cli_request()) {
            echo json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL;
            return;
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($data));
    }

    private function due_reminder_days()
    {
        $days = $this->config->item('wa_due_reminder_days');
        if (!is_array($days) || empty($days)) {
            $days = array(3, 1, 0);
        }

        $normalized = array();
        foreach ($days as $day) {
            $day = max(0, (int) $day);
            $normalized[$day] = $day;
        }

        return array_values($normalized);
    }

    private function get_invoice_rows(array $options)
    {
        if (!$this->db->table_exists('invoices') || !$this->db->table_exists('customers')) {
            return array();
        }

        $invoice_fields = $this->db->list_fields('invoices');
        if (!in_array('id', $invoice_fields, true)
            || !in_array('customer_id', $invoice_fields, true)
            || !in_array('due_date', $invoice_fields, true)
        ) {
            return array();
        }

        $customer_fields = $this->db->list_fields('customers');
        $select = array(
            'i.*',
            $this->customer_name_expression($customer_fields) . ' AS customer_name',
            $this->customer_phone_expression($customer_fields) . ' AS customer_phone',
        );

        $qb = $this->db
            ->select(implode(', ', $select), false)
            ->from('invoices i')
            ->join('customers c', 'c.id = i.customer_id', 'inner');

        if (!empty($options['due_date']) && in_array('due_date', $invoice_fields, true)) {
            $qb->where('i.due_date', (string) $options['due_date']);
        }
        if (!empty($options['due_before']) && in_array('due_date', $invoice_fields, true)) {
            $qb->where('i.due_date <', (string) $options['due_before']);
        }

        if (in_array('status', $invoice_fields, true)) {
            $qb->where_in('i.status', (array) ($options['statuses'] ?? $this->unpaid_statuses(true)));
        }

        if (in_array('balance_amount', $invoice_fields, true)) {
            $qb->where('i.balance_amount >', 0);
        }

        if (in_array('router_id', $invoice_fields, true)) {
            $router_id = $this->resolve_router_scope_from_cli();
            if ($router_id > 0) {
                $qb->where('i.router_id', $router_id);
            }
        }

        return $qb
            ->order_by('i.due_date', 'ASC')
            ->order_by('i.id', 'ASC')
            ->limit(max(1, (int) ($options['limit'] ?? 100)))
            ->get()
            ->result_array();
    }

    private function unpaid_statuses($include_overdue)
    {
        $statuses = array('issued', 'partially_paid', 'unpaid', 'pending', 'draft');
        if ($include_overdue) {
            $statuses[] = 'overdue';
        }

        return $statuses;
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

    private function customer_phone_expression(array $fields)
    {
        $parts = array();
        foreach (array('phone', 'whatsapp', 'wa_number', 'mobile', 'no_hp', 'hp', 'telp') as $col) {
            if (in_array($col, $fields, true)) {
                $parts[] = "NULLIF(c.{$col}, '')";
            }
        }

        return empty($parts) ? "''" : 'COALESCE(' . implode(', ', $parts) . ", '')";
    }

    private function customer_name(array $row)
    {
        return trim((string) ($row['customer_name'] ?? 'Pelanggan'));
    }

    private function customer_phone(array $row)
    {
        return trim((string) ($row['customer_phone'] ?? ''));
    }

    private function invoice_number(array $row)
    {
        $invoice_no = trim((string) ($row['invoice_number'] ?? ''));
        return $invoice_no !== '' ? $invoice_no : ('INV-' . (int) ($row['id'] ?? 0));
    }

    private function invoice_amount_due(array $row)
    {
        foreach (array('balance_amount', 'total_amount', 'amount', 'subtotal') as $col) {
            if (isset($row[$col]) && (float) $row[$col] > 0) {
                return (float) $row[$col];
            }
        }

        return 0;
    }

    private function payment_info()
    {
        $payment_info = trim((string) $this->config->item('wa_payment_info'));
        return $payment_info !== '' ? $payment_info : 'Hubungi admin billing untuk informasi pembayaran.';
    }

    private function resolve_router_scope_from_cli()
    {
        $router_id = (int) $this->input->get('router_id', true);
        if ($router_id > 0) {
            return $router_id;
        }

        return 0;
    }
}
