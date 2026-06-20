<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Billing extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_module_access('billing', 'Akses ditolak. Modul Billing hanya untuk superadmin/admin.');
        $this->load->database();
        $this->load->helper(array('url', 'form', 'notification'));
        $this->load->library(array('billing_automation_service', 'form_validation'));
        $this->load->model('billing_automation_model');
        $this->load->model('ont_device_model');
    }

    public function index()
    {
        redirect('billing');
    }

    public function generate_monthly_invoices()
    {
        $period = trim((string) $this->input->post('period'));
        if ($period === '') {
            $period = trim((string) $this->input->get('period', true));
        }
        $router_raw = $this->input->post('router_id', true);
        if ($router_raw === null || $router_raw === '') {
            $router_raw = $this->input->get('router_id', true);
        }
        $scope = $this->resolve_generate_router_scope($router_raw);
        if (empty($scope['success'])) {
            return $this->output
                ->set_status_header(422)
                ->set_content_type('application/json')
                ->set_output(json_encode(array(
                    'success' => false,
                    'message' => (string) ($scope['message'] ?? 'Router scope tidak valid.'),
                )));
        }

        $result = $this->billing_automation_service->generate_monthly_invoices(
            $period ?: null,
            $scope['router_id']
        );
        if (!empty($result['success'])) {
            $result['scope'] = array(
                'router_id' => $scope['router_id'],
                'router_label' => (string) ($scope['router_label'] ?? ''),
            );
        }
        $code = !empty($result['success']) ? 200 : 422;

        return $this->output
            ->set_status_header($code)
            ->set_content_type('application/json')
            ->set_output(json_encode($result));
    }

    public function manual_generate_invoice()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $mode = strtolower(trim((string) $this->input->post('mode', true)));
        $period = trim((string) $this->input->post('period', true));
        $run_date = trim((string) $this->input->post('run_date', true));
        $router_raw = $this->input->post('router_id', true);
        $scope = $this->resolve_generate_router_scope($router_raw);
        if (empty($scope['success'])) {
            $this->session->set_flashdata('error', (string) ($scope['message'] ?? 'Router scope tidak valid.'));
            redirect('billing');
            return;
        }

        if ($mode === 'period') {
            if ($period === '') {
                $period = date('Y-m');
            }

            if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
                $this->session->set_flashdata('error', 'Format periode tidak valid. Gunakan YYYY-MM.');
                redirect('billing');
                return;
            }

            $result = $this->billing_automation_service->generate_monthly_invoices($period, $scope['router_id']);
            if (!empty($result['success'])) {
                $stats = (array) ($result['stats'] ?? array());
                $created = (int) ($stats['total_created'] ?? 0);
                $duplicate = (int) ($stats['total_skipped_duplicate'] ?? $stats['total_skipped'] ?? 0);
                $failed = (int) ($stats['total_failed'] ?? 0);
                $first_error = trim((string) ($stats['first_error_message'] ?? ''));
                $suffix_error = ($failed > 0 && $first_error !== '')
                    ? (' FirstError: ' . $first_error)
                    : '';
                $this->session->set_flashdata(
                    'success',
                    'Generate invoice manual periode ' . $period . ' [' . (string) ($scope['router_label'] ?? 'Semua Router') . '] selesai. Created: ' . $created . ', Duplicate: ' . $duplicate . ', Failed: ' . $failed . '.' . $suffix_error
                );
                $this->push_billing_notification(
                    'info',
                    'Generate invoice manual selesai',
                    'Periode ' . $period . ' [' . (string) ($scope['router_label'] ?? 'Semua Router') . '] - Created: ' . $created . ', Duplicate: ' . $duplicate . ', Failed: ' . $failed . '.',
                    array('router_id' => $scope['router_id'] ?? null)
                );
            } else {
                $this->session->set_flashdata('error', 'Generate invoice manual gagal: ' . (string) ($result['message'] ?? 'unknown'));
            }

            redirect('billing');
            return;
        }

        if ($run_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $run_date)) {
            $this->session->set_flashdata('error', 'Format tanggal run tidak valid. Gunakan YYYY-MM-DD.');
            redirect('billing');
            return;
        }

        $result = $this->billing_automation_service->generate_daily_rolling_invoices(
            $run_date !== '' ? $run_date : null,
            $scope['router_id']
        );
        if (!empty($result['success'])) {
            $stats = (array) ($result['stats'] ?? array());
            $run_used = (string) ($stats['run_date'] ?? ($run_date !== '' ? $run_date : date('Y-m-d')));
            $created = (int) ($stats['total_created'] ?? 0);
            $target = (int) ($stats['total_due_target'] ?? 0);
            $duplicate = (int) ($stats['total_skipped_duplicate'] ?? 0);
            $failed = (int) ($stats['total_failed'] ?? 0);
            $first_error = trim((string) ($stats['first_error_message'] ?? ''));
            $suffix_error = ($failed > 0 && $first_error !== '')
                ? (' FirstError: ' . $first_error)
                : '';
            $this->session->set_flashdata(
                'success',
                'Generate rolling manual (' . $run_used . ') [' . (string) ($scope['router_label'] ?? 'Semua Router') . '] selesai. Target: ' . $target . ', Created: ' . $created . ', Duplicate: ' . $duplicate . ', Failed: ' . $failed . '.' . $suffix_error
            );
            $this->push_billing_notification(
                'info',
                'Generate rolling invoice selesai',
                'Tanggal ' . $run_used . ' [' . (string) ($scope['router_label'] ?? 'Semua Router') . '] - Target: ' . $target . ', Created: ' . $created . ', Duplicate: ' . $duplicate . ', Failed: ' . $failed . '.',
                array('router_id' => $scope['router_id'] ?? null)
            );
        } else {
            $this->session->set_flashdata('error', 'Generate rolling manual gagal: ' . (string) ($result['message'] ?? 'unknown'));
        }

        redirect('billing');
    }

    private function resolve_generate_router_scope($requested_router_id = null)
    {
        $role = normalizeRole((string) $this->session->userdata('role'));
        $requested = null;
        if ($requested_router_id !== null && $requested_router_id !== '') {
            $requested = (int) $requested_router_id;
        }

        if ($role === 'superadmin') {
            $router_id = null;
            if ($requested !== null) {
                $router_id = $requested > 0 ? $requested : null;
            } else {
                $effective = $this->getEffectiveRouterId();
                $router_id = $effective !== null ? (int) $effective : null;
            }

            if ($router_id !== null && $router_id > 0) {
                $router = $this->get_router_row($router_id);
                if (empty($router)) {
                    return array(
                        'success' => false,
                        'message' => 'Router terpilih tidak ditemukan atau nonaktif.',
                    );
                }
                return array(
                    'success' => true,
                    'router_id' => $router_id,
                    'router_label' => 'Router: ' . (string) ($router['name'] ?? ('#' . $router_id)),
                );
            }

            return array(
                'success' => true,
                'router_id' => null,
                'router_label' => 'Semua Router',
            );
        }

        $scope_router_id = (int) $this->getEffectiveRouterId();
        if ($scope_router_id <= 0) {
            return array(
                'success' => false,
                'message' => 'Router scope user belum diatur. Hubungi superadmin.',
            );
        }

        $router = $this->get_router_row($scope_router_id);
        return array(
            'success' => true,
            'router_id' => $scope_router_id,
            'router_label' => 'Router: ' . (string) ($router['name'] ?? ('#' . $scope_router_id)),
        );
    }

    private function get_router_row($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return array();
        }

        $fields = $this->db->list_fields('routers');
        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : 'id');

        $qb = $this->db
            ->select('id, ' . $name_col . ' as name', false)
            ->from('routers')
            ->where('id', $router_id);

        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        return (array) $qb->limit(1)->get()->row_array();
    }

    private function push_billing_notification($type, $title, $message, array $invoice = array())
    {
        if (!function_exists('create_notification_for_roles')) {
            return;
        }

        $router_id = 0;
        if (isset($invoice['router_id'])) {
            $router_id = (int) $invoice['router_id'];
        }
        if ($router_id <= 0 && isset($invoice['service_router_id'])) {
            $router_id = (int) $invoice['service_router_id'];
        }
        if ($router_id <= 0) {
            $effective_router_id = $this->getEffectiveRouterId();
            $router_id = $effective_router_id !== null ? (int) $effective_router_id : 0;
        }

        create_notification_for_roles(
            array('superadmin', 'admin'),
            array(
                'type' => trim((string) $type) !== '' ? (string) $type : 'info',
                'category' => 'billing',
                'title' => trim((string) $title) !== '' ? (string) $title : 'Billing Notification',
                'message' => (string) $message,
                'reference_id' => isset($invoice['id']) ? (int) $invoice['id'] : null,
                'reference_type' => 'invoice',
            ),
            $router_id > 0 ? $router_id : null
        );
    }

    private function billing_return_url($fallback = 'billing')
    {
        $raw = trim((string) $this->input->post('return_url', true));
        if ($raw === '') {
            $raw = trim((string) $this->input->get('return_url', true));
        }

        $raw = str_replace(array("\r", "\n"), '', $raw);
        if ($raw === '' || preg_match('/^(https?:)?\/\//i', $raw)) {
            return $fallback;
        }

        $raw = ltrim($raw, '/');
        if (strpos($raw, 'billing') === 0 || strpos($raw, 'invoice') === 0) {
            return $raw;
        }

        return $fallback;
    }

    private function billing_edit_return_url($invoice_id)
    {
        $url = 'billing/edit/' . (int) $invoice_id;
        $return_url = $this->billing_return_url('');
        if ($return_url !== '') {
            $url .= '?return_url=' . rawurlencode($return_url);
        }

        return $url;
    }

    public function manual_isolir()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $username = trim((string) $this->input->post('pppoe_username', true));
        if ($username === '') {
            $this->session->set_flashdata('error', 'Pilih User PPP terlebih dahulu.');
            redirect('billing');
            return;
        }

        try {
            $context = $this->resolve_customer_context_by_username($username);
            $customer_id = (int) ($context['customer_id'] ?? 0);
            $router_id = (int) ($context['router_id'] ?? 0);
            if ($router_id <= 0) {
                throw new Exception('Router customer tidak ditemukan untuk user `' . $username . '`.');
            }

            $remote_ip = trim((string) ($context['remote_ip'] ?? ''));
            if (!filter_var($remote_ip, FILTER_VALIDATE_IP)) {
                $remote_ip = $this->resolve_remote_ip_from_local($username);
            }
            if (!filter_var($remote_ip, FILTER_VALIDATE_IP)) {
                throw new Exception('Remote IP untuk user `' . $username . '` tidak valid/kosong. Pastikan user online agar bisa redirect ke web proxy isolir.');
            }

            $isolation_profile = $this->resolve_isolation_profile_for_username($username);
            $suspend_result = $this->billing_automation_service->suspend_customer_in_mikrotik(
                $username,
                $isolation_profile,
                $remote_ip,
                $router_id,
                $customer_id
            );
            if (empty($suspend_result['success'])) {
                throw new Exception((string) ($suspend_result['message'] ?? 'Manual isolir gagal'));
            }

            $now = date('Y-m-d H:i:s');

            if ($customer_id > 0 && $this->db->table_exists('customers')) {
                $payload = array('status' => 'suspended');
                if ($this->table_has_column('customers', 'updated_at')) {
                    $payload['updated_at'] = $now;
                }
                $this->db->where('id', $customer_id)->update('customers', $payload);
            }

            if ($customer_id > 0 && $this->db->table_exists('customer_services')
                && $this->table_has_column('customer_services', 'customer_id')
                && $this->table_has_column('customer_services', 'status')
            ) {
                $service_payload = array('status' => 'suspended');
                if ($this->table_has_column('customer_services', 'updated_at')) {
                    $service_payload['updated_at'] = $now;
                }
                $this->db
                    ->where('customer_id', $customer_id)
                    ->where('status', 'active')
                    ->update('customer_services', $service_payload);
            }

            if ($customer_id > 0 && $this->db->table_exists('invoices')) {
                $invoice_payload = array('status' => 'overdue');
                if ($this->table_has_column('invoices', 'updated_at')) {
                    $invoice_payload['updated_at'] = $now;
                }

                $this->db
                    ->where('customer_id', $customer_id)
                    ->where_in('status', array('issued', 'partially_paid'))
                    ->where('balance_amount >', 0)
                    ->update('invoices', $invoice_payload);
            }

            log_message('info', '[MANUAL_ISOLIR] username=' . $username . ' remote_ip=' . $remote_ip . ' customer_id=' . $customer_id);
            $this->session->set_flashdata('success', 'Manual isolir berhasil untuk user PPP `' . $username . '`.');
        } catch (Throwable $e) {
            log_message('error', '[MANUAL_ISOLIR] ' . $e->getMessage());
            $this->session->set_flashdata('error', 'Manual isolir gagal: ' . $e->getMessage());
        }

        redirect('billing');
    }

    public function auto_suspend()
    {
        $date = trim((string) $this->input->post('date'));
        if ($date === '') {
            $date = trim((string) $this->input->get('date', true));
        }
        $grace_days_raw = $this->input->post('grace_days', true);
        if ($grace_days_raw === null || $grace_days_raw === '') {
            $grace_days_raw = $this->input->get('grace_days', true);
        }
        $grace_days = ($grace_days_raw === null || $grace_days_raw === '') ? 5 : (int) $grace_days_raw;
        if ($grace_days < 0) {
            $grace_days = 5;
        }

        $result = $this->billing_automation_service->auto_suspend($date ?: null, $grace_days);
        if (!empty($result['success'])) {
            $stats = (array) ($result['stats'] ?? array());
            $total_suspended = (int) ($stats['total_suspended'] ?? 0);
            $total_failed = (int) ($stats['total_failed'] ?? 0);
            if ($total_suspended > 0 || $total_failed > 0) {
                $type = $total_failed > 0 ? 'warning' : 'info';
                $message = 'Auto isolir selesai. Suspended: ' . $total_suspended . ', Failed: ' . $total_failed . '.';
                $this->push_billing_notification($type, 'Auto isolir billing', $message, array());
            }
        }
        $code = !empty($result['success']) ? 200 : 422;

        return $this->output
            ->set_status_header($code)
            ->set_content_type('application/json')
            ->set_output(json_encode($result));
    }

    public function record_payment()
    {
        $invoice_id = (int) $this->input->post('invoice_id');
        $amount = (float) $this->input->post('amount');
        $method = trim((string) $this->input->post('method'));
        $payment_date = trim((string) $this->input->post('payment_date'));
        if ($payment_date === '') {
            $payment_date = null;
        }

        $result = $this->billing_automation_service->record_payment(
            $invoice_id,
            $amount,
            $method,
            $payment_date
        );
        if (!empty($result['success'])) {
            $invoice = $this->get_invoice_detail($invoice_id);
            if (!empty($invoice)) {
                $this->push_billing_notification(
                    'success',
                    'Pembayaran tercatat',
                    'Pembayaran invoice ' . (string) ($invoice['invoice_number'] ?? ('#' . $invoice_id)) . ' pelanggan ' . (string) ($invoice['customer_name'] ?? '-') . ' berhasil dicatat.',
                    $invoice
                );
            }
        }
        $code = !empty($result['success']) ? 200 : 422;

        return $this->output
            ->set_status_header($code)
            ->set_content_type('application/json')
            ->set_output(json_encode($result));
    }

    public function view_invoice($id)
    {
        $invoice = $this->get_invoice_detail((int) $id);
        if (empty($invoice)) {
            show_404();
            return;
        }
        $embed_only = ((string) $this->input->get('embed', true) === '1');
        $auto_print = ((string) $this->input->get('print', true) === '1');

        $phone = $this->normalize_phone_for_whatsapp((string) ($invoice['customer_phone'] ?? ''));
        $wa_url = '';
        if ($phone !== '') {
            $message = "*Invoice Internet*\n"
                . "No: {$invoice['invoice_number']}\n"
                . "Pelanggan: {$invoice['customer_name']}\n"
                . "Periode: " . date('F Y', strtotime((string) $invoice['billing_period_start'])) . "\n"
                . "Jatuh Tempo: " . date('d-m-Y', strtotime((string) $invoice['due_date'])) . "\n"
                . "Total: Rp " . number_format((float) ($invoice['total_amount'] ?? 0), 0, ',', '.') . "\n"
                . "Status: " . strtoupper((string) ($invoice['status'] ?? '-'));
            $wa_url = 'https://wa.me/' . rawurlencode($phone) . '?text=' . rawurlencode($message);
        }

        $this->load->view('billing/view', array(
            'invoice' => $invoice,
            'router' => $this->resolve_invoice_router_branding($invoice),
            'wa_url' => $wa_url,
            'embed_only' => $embed_only,
            'auto_print' => $auto_print,
            'return_url' => $this->billing_return_url(),
        ));
    }

    public function edit_invoice($id)
    {
        $invoice = $this->get_invoice_detail((int) $id);
        if (empty($invoice)) {
            show_404();
            return;
        }

        $this->load->view('billing/edit', array(
            'invoice' => $invoice,
        ));
    }

    public function update_invoice($id)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $invoice = $this->get_invoice_detail($id);
        if (empty($invoice)) {
            $this->session->set_flashdata('error', 'Invoice tidak ditemukan.');
            redirect($this->billing_return_url());
            return;
        }

        $this->form_validation->set_rules('issue_date', 'Issue Date', 'required|regex_match[/^\d{4}-\d{2}-\d{2}$/]');
        $this->form_validation->set_rules('due_date', 'Due Date', 'required|regex_match[/^\d{4}-\d{2}-\d{2}$/]');
        $this->form_validation->set_rules('subtotal', 'Subtotal', 'required|numeric');
        $this->form_validation->set_rules('tax_amount', 'Tax Amount', 'required|numeric');
        $this->form_validation->set_rules('discount_amount', 'Discount Amount', 'required|numeric');
        $this->form_validation->set_rules('status', 'Status', 'required|in_list[draft,issued,partially_paid,paid,overdue,void,pending,unpaid]');

        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors(' ', ' '));
            redirect($this->billing_edit_return_url($id));
            return;
        }

        $subtotal = round((float) $this->input->post('subtotal', true), 2);
        $tax_amount = round((float) $this->input->post('tax_amount', true), 2);
        $discount_amount = round((float) $this->input->post('discount_amount', true), 2);
        $total_amount = round($subtotal + $tax_amount - $discount_amount, 2);
        if ($total_amount < 0) {
            $total_amount = 0;
        }

        $requested_status = $this->normalize_editable_invoice_status((string) $this->input->post('status', true));
        $stored_paid_amount = round((float) ($invoice['paid_amount'] ?? 0), 2);
        $confirmed_paid_amount = $this->get_confirmed_payment_total($id);
        $paid_amount_source = max($stored_paid_amount, $confirmed_paid_amount);
        $notes = trim((string) $this->input->post('notes', true));
        $reset_paid_state = $requested_status !== 'paid'
            && $requested_status !== 'void'
            && $this->invoice_requires_payment_reset($invoice, $stored_paid_amount, $confirmed_paid_amount);
        $note_tag = '[MANUAL_STATUS ' . strtoupper((string) ($invoice['status'] ?? 'ISSUED')) . '->' . strtoupper($requested_status) . ' user=' . (int) $this->session->userdata('user_id') . ' ' . date('Y-m-d H:i:s') . ']';
        if ($reset_paid_state) {
            $notes = trim($notes . ' ' . $note_tag);
        }
        if ($requested_status === 'overdue' && strpos($notes, '[MANUAL_OVERDUE]') === false) {
            $notes = trim($notes . ' [MANUAL_OVERDUE] ' . date('Y-m-d'));
        }

        $this->db->trans_begin();

        if ($reset_paid_state) {
            $reset = $this->reset_invoice_payment_state($id);
            if (empty($reset['success'])) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'Reset histori pembayaran gagal: ' . (string) ($reset['message'] ?? 'unknown'));
                redirect($this->billing_edit_return_url($id));
                return;
            }

            $stored_paid_amount = 0.0;
            $confirmed_paid_amount = 0.0;
            $paid_amount_source = 0.0;
        }

        if ($confirmed_paid_amount > ($total_amount + 0.01)) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Total payment terkonfirmasi melebihi total invoice baru. Silakan cek histori pembayaran invoice ini dulu.');
            redirect($this->billing_edit_return_url($id));
            return;
        }

        $paid_amount = min($paid_amount_source, $total_amount);
        $balance_amount = max(0, round($total_amount - $paid_amount, 2));
        $status = $requested_status;

        if ($status === 'void') {
            $balance_amount = 0;
        } elseif ($status === 'paid') {
            if ($balance_amount > 0.01) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'Status Paid di menu edit hanya bisa dipakai jika pembayaran invoice sudah penuh. Untuk melunasi invoice, gunakan tombol Lunas di list/detail.');
                redirect($this->billing_edit_return_url($id));
                return;
            }

            $paid_amount = $total_amount;
            $balance_amount = 0;
            $status = 'paid';
        } elseif ($status === 'overdue') {
            $status = 'overdue';
        } elseif ($paid_amount > 0) {
            $status = 'partially_paid';
        } else {
            $status = 'issued';
        }

        $payload = array(
            'issue_date' => (string) $this->input->post('issue_date', true),
            'due_date' => (string) $this->input->post('due_date', true),
            'subtotal' => $subtotal,
            'tax_amount' => $tax_amount,
            'discount_amount' => $discount_amount,
            'total_amount' => $total_amount,
            'paid_amount' => $paid_amount,
            'balance_amount' => $balance_amount,
            'status' => $status,
            'notes' => $notes,
            'updated_at' => date('Y-m-d H:i:s'),
        );

        if ($this->table_has_column('invoices', 'paid_date')) {
            if ($status === 'paid') {
                $payload['paid_date'] = !empty($invoice['last_payment_date'])
                    ? (string) $invoice['last_payment_date']
                    : date('Y-m-d H:i:s');
            } else {
                $payload['paid_date'] = null;
            }
        }

        $ok = $this->db->where('id', $id)->update('invoices', $payload);
        if (!$ok) {
            $err = $this->db->error();
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Update invoice gagal: ' . ($err['message'] ?? 'unknown'));
            redirect($this->billing_edit_return_url($id));
            return;
        }

        if ($this->db->trans_status() === false) {
            $err = $this->db->error();
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Update invoice gagal: ' . ($err['message'] ?? 'unknown'));
            redirect($this->billing_edit_return_url($id));
            return;
        }

        $this->db->trans_commit();
        $this->session->set_flashdata('success', 'Invoice berhasil diperbarui.');
        redirect($this->billing_return_url());
    }

    private function normalize_editable_invoice_status($status)
    {
        $status = strtolower(trim((string) $status));
        if (in_array($status, array('paid', 'overdue', 'void'), true)) {
            return $status;
        }

        return 'issued';
    }

    private function invoice_requires_payment_reset(array $invoice, $stored_paid_amount, $confirmed_paid_amount)
    {
        $current_status = strtolower((string) ($invoice['status'] ?? 'issued'));
        $current_total = round((float) ($invoice['total_amount'] ?? 0), 2);
        $current_paid = max(round((float) $stored_paid_amount, 2), round((float) $confirmed_paid_amount, 2));
        $current_balance = round((float) ($invoice['balance_amount'] ?? max(0, $current_total - (float) ($invoice['paid_amount'] ?? 0))), 2);

        if ($current_status === 'paid') {
            return true;
        }

        if ($current_paid <= 0) {
            return false;
        }

        return $current_balance <= 0 || ($current_total > 0 && $current_paid >= $current_total);
    }

    private function reset_invoice_payment_state($invoice_id)
    {
        $invoice_id = (int) $invoice_id;
        if ($invoice_id <= 0) {
            return array(
                'success' => false,
                'message' => 'ID invoice tidak valid.',
            );
        }

        $payment_ids = array();
        if ($this->db->table_exists('payments')) {
            $payment_rows = $this->db
                ->select('id')
                ->from('payments')
                ->where('invoice_id', $invoice_id)
                ->get()
                ->result_array();

            $payment_ids = array_values(array_filter(array_map(static function ($row) {
                return (int) ($row['id'] ?? 0);
            }, $payment_rows)));

            if ($this->table_has_column('payments', 'status')) {
                $payment_payload = array(
                    'status' => 'refunded',
                );
                if ($this->table_has_column('payments', 'updated_at')) {
                    $payment_payload['updated_at'] = date('Y-m-d H:i:s');
                }

                $ok = $this->db
                    ->where('invoice_id', $invoice_id)
                    ->where('status !=', 'refunded')
                    ->update('payments', $payment_payload);

                if (!$ok) {
                    $err = $this->db->error();
                    return array(
                        'success' => false,
                        'message' => (string) ($err['message'] ?? 'update payment refunded gagal'),
                    );
                }
            } else {
                $ok = $this->db->where('invoice_id', $invoice_id)->delete('payments');
                if (!$ok) {
                    $err = $this->db->error();
                    return array(
                        'success' => false,
                        'message' => (string) ($err['message'] ?? 'hapus payment gagal'),
                    );
                }
            }
        }

        if ($this->db->table_exists('cashflow_transactions')) {
            if (!empty($payment_ids)) {
                $ok = $this->db->where_in('payment_id', $payment_ids)->delete('cashflow_transactions');
                if (!$ok) {
                    $err = $this->db->error();
                    return array(
                        'success' => false,
                        'message' => (string) ($err['message'] ?? 'hapus cashflow by payment gagal'),
                    );
                }
            }

            $ok = $this->db->where('invoice_id', $invoice_id)->delete('cashflow_transactions');
            if (!$ok) {
                $err = $this->db->error();
                return array(
                    'success' => false,
                    'message' => (string) ($err['message'] ?? 'hapus cashflow by invoice gagal'),
                );
            }
        }

        return array(
            'success' => true,
            'payment_count' => count($payment_ids),
        );
    }

    private function get_confirmed_payment_total($invoice_id)
    {
        $invoice_id = (int) $invoice_id;
        if ($invoice_id <= 0 || !$this->db->table_exists('payments')) {
            return 0.0;
        }

        if (!$this->table_has_column('payments', 'invoice_id')
            || !$this->table_has_column('payments', 'amount')
        ) {
            return 0.0;
        }

        $qb = $this->db
            ->select_sum('amount', 'total_paid')
            ->from('payments')
            ->where('invoice_id', $invoice_id);

        if ($this->table_has_column('payments', 'status')) {
            $qb->where('status', 'confirmed');
        }

        $row = (array) $qb->get()->row_array();

        return round((float) ($row['total_paid'] ?? 0), 2);
    }

    public function mark_paid($id)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $invoice = $this->get_invoice_detail($id);
        if (empty($invoice)) {
            $this->session->set_flashdata('error', 'Invoice tidak ditemukan.');
            redirect($this->billing_return_url());
            return;
        }

        if ((string) $invoice['status'] === 'paid') {
            $this->session->set_flashdata('success', 'Invoice sudah lunas sebelumnya.');
            redirect($this->billing_return_url());
            return;
        }

        if ((string) $invoice['status'] === 'void') {
            $this->session->set_flashdata('error', 'Invoice cancel/void tidak bisa dilunasi.');
            redirect($this->billing_return_url());
            return;
        }

        $remaining = round((float) ($invoice['balance_amount'] ?? 0), 2);
        if ($remaining <= 0) {
            $remaining = round((float) ($invoice['total_amount'] ?? 0) - (float) ($invoice['paid_amount'] ?? 0), 2);
        }
        if ($remaining <= 0) {
            $this->session->set_flashdata('error', 'Sisa tagihan invoice tidak valid.');
            redirect($this->billing_return_url());
            return;
        }

        $result = $this->billing_automation_service->record_payment($id, $remaining, 'cash', date('Y-m-d H:i:s'));
        if (!empty($result['success'])) {
            $this->session->set_flashdata('success', (string) ($result['message'] ?? 'Invoice berhasil ditandai lunas.'));
            $this->push_billing_notification(
                'success',
                'Pembayaran berhasil',
                'Invoice ' . (string) ($invoice['invoice_number'] ?? ('#' . $id)) . ' pelanggan ' . (string) ($invoice['customer_name'] ?? '-') . ' berhasil dilunasi.',
                $invoice
            );
        } else {
            $this->session->set_flashdata('error', 'Gagal mark lunas: ' . (string) ($result['message'] ?? 'unknown'));
        }

        redirect($this->billing_return_url());
    }

    public function mark_overdue($id)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $invoice = $this->get_invoice_detail($id);
        if (empty($invoice)) {
            $this->session->set_flashdata('error', 'Invoice tidak ditemukan.');
            redirect($this->billing_return_url());
            return;
        }

        if ((string) $invoice['status'] === 'paid') {
            $this->session->set_flashdata('error', 'Invoice sudah lunas, tidak bisa overdue.');
            redirect($this->billing_return_url());
            return;
        }

        if ((string) $invoice['status'] === 'void') {
            $this->session->set_flashdata('error', 'Invoice cancel/void tidak bisa overdue.');
            redirect($this->billing_return_url());
            return;
        }

        $manual_tag = '[MANUAL_OVERDUE]';
        $old_notes = trim((string) ($invoice['notes'] ?? ''));
        if (strpos($old_notes, $manual_tag) === false) {
            $new_notes = trim($old_notes . ' ' . $manual_tag . ' ' . date('Y-m-d'));
        } else {
            $new_notes = $old_notes;
        }

        $payload = array(
            'status' => 'overdue',
            'notes' => $new_notes,
            'updated_at' => date('Y-m-d H:i:s'),
        );

        $ok = $this->db->where('id', $id)->update('invoices', $payload);
        if (!$ok) {
            $err = $this->db->error();
            $this->session->set_flashdata('error', 'Gagal mark overdue: ' . ($err['message'] ?? 'unknown'));
            redirect($this->billing_return_url());
            return;
        }

        $this->session->set_flashdata('success', 'Invoice ditandai OVERDUE. Jika belum lunas, sistem akan auto isolir setelah 5 hari.');
        $this->push_billing_notification(
            'warning',
            'Invoice overdue',
            'Invoice ' . (string) ($invoice['invoice_number'] ?? ('#' . $id)) . ' pelanggan ' . (string) ($invoice['customer_name'] ?? '-') . ' ditandai OVERDUE.',
            $invoice
        );
        redirect($this->billing_return_url());
    }

    public function delete_invoice($id)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $result = $this->delete_invoice_by_role($id, $this->is_superadmin(), '[SINGLE_DELETE]');
        if (!empty($result['success'])) {
            $this->session->set_flashdata('success', 'Invoice berhasil dihapus.');
        } else {
            $this->session->set_flashdata('error', 'Hapus invoice gagal: ' . (string) ($result['message'] ?? 'unknown'));
        }

        redirect($this->billing_return_url());
    }

    public function delete_ont($id)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $invoice = $this->get_invoice_detail((int) $id);
        if (empty($invoice)) {
            $this->session->set_flashdata('error', 'Invoice tidak ditemukan.');
            redirect($this->billing_return_url());
            return;
        }

        $target = $this->resolve_invoice_ont_delete_target($invoice);
        if (empty($target['success'])) {
            $this->session->set_flashdata('error', (string) ($target['message'] ?? 'Target ONT tidak ditemukan.'));
            redirect($this->billing_return_url());
            return;
        }

        try {
            $router_id = (int) $target['router_id'];
            $genieacs = $this->load_genieacs_client($router_id);
            $device_id = trim((string) ($target['device_id'] ?? ''));
            $serial = trim((string) ($target['serial_number'] ?? ''));
            $result = $device_id !== ''
                ? $genieacs->deleteDeviceById($device_id)
                : $genieacs->deleteDevice($serial);

            if (empty($result['success'])) {
                $this->session->set_flashdata('error', 'Hapus ONT GenieACS gagal: ' . (string) ($result['message'] ?? 'unknown'));
                redirect($this->billing_return_url());
                return;
            }

            $label = $device_id !== '' ? $device_id : $serial;
            $customer_name = trim((string) ($invoice['customer_name'] ?? ''));
            $suffix = $customer_name !== '' ? (' pelanggan ' . $customer_name) : '';
            $this->session->set_flashdata(
                'success',
                'ONT ' . $label . $suffix . ' berhasil diproses hapus dari GenieACS. Source: ' . (string) ($target['source'] ?? '-')
            );
        } catch (Throwable $e) {
            log_message('error', '[BILLING][DELETE_ONT] ' . $e->getMessage());
            $this->session->set_flashdata('error', 'Hapus ONT GenieACS gagal: ' . $e->getMessage());
        }

        redirect($this->billing_return_url());
    }

    public function acs_gap()
    {
        $search = trim((string) $this->input->get('search', true));
        $report = $this->build_acs_gap_report($search);

        return $this->load->view('billing/acs_gap', array(
            'search' => $search,
            'summary' => (array) ($report['summary'] ?? array()),
            'missing_rows' => (array) ($report['missing_rows'] ?? array()),
            'error_message' => (string) ($report['error_message'] ?? ''),
            'scope_router_id' => $this->getEffectiveRouterId(),
        ));
    }

    public function bulk_action()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $action = strtolower(trim((string) $this->input->post('bulk_action', true)));
        $ids = $this->collect_invoice_ids();

        if (empty($ids)) {
            $this->session->set_flashdata('error', 'Pilih minimal 1 invoice.');
            redirect($this->billing_return_url());
            return;
        }

        if (!in_array($action, array('mark_paid', 'mark_overdue', 'delete'), true)) {
            $this->session->set_flashdata('error', 'Bulk action tidak valid.');
            redirect($this->billing_return_url());
            return;
        }

        $success = 0;
        $failed = 0;
        $voided = 0;
        $hard_deleted = 0;

        if ($action === 'mark_paid') {
            foreach ($ids as $id) {
                $invoice = $this->get_invoice_detail($id);
                if (empty($invoice) || (string) ($invoice['status'] ?? '') === 'paid' || (string) ($invoice['status'] ?? '') === 'void') {
                    $failed++;
                    continue;
                }

                $remaining = round((float) ($invoice['balance_amount'] ?? 0), 2);
                if ($remaining <= 0) {
                    $remaining = round((float) ($invoice['total_amount'] ?? 0) - (float) ($invoice['paid_amount'] ?? 0), 2);
                }
                if ($remaining <= 0) {
                    $failed++;
                    continue;
                }

                $result = $this->billing_automation_service->record_payment($id, $remaining, 'cash', date('Y-m-d H:i:s'));
                if (!empty($result['success'])) {
                    $success++;
                } else {
                    $failed++;
                }
            }
        } elseif ($action === 'mark_overdue') {
            foreach ($ids as $id) {
                $invoice = $this->get_invoice_detail($id);
                if (empty($invoice) || in_array((string) ($invoice['status'] ?? ''), array('paid', 'void'), true)) {
                    $failed++;
                    continue;
                }

                $manual_tag = '[MANUAL_OVERDUE]';
                $old_notes = trim((string) ($invoice['notes'] ?? ''));
                $new_notes = strpos($old_notes, $manual_tag) === false
                    ? trim($old_notes . ' ' . $manual_tag . ' ' . date('Y-m-d'))
                    : $old_notes;

                $ok = $this->db->where('id', $id)->update('invoices', array(
                    'status' => 'overdue',
                    'notes' => $new_notes,
                    'updated_at' => date('Y-m-d H:i:s'),
                ));

                if ($ok) {
                    $success++;
                } else {
                    $failed++;
                }
            }
        } else {
            $is_superadmin = $this->is_superadmin();
            foreach ($ids as $id) {
                $result = $this->delete_invoice_by_role($id, $is_superadmin, '[BULK_DELETE]');
                if (!empty($result['success'])) {
                    $success++;
                    if (($result['mode'] ?? '') === 'hard') {
                        $hard_deleted++;
                    } else {
                        $voided++;
                    }
                } else {
                    $failed++;
                }
            }
        }

        if ($action === 'delete') {
            $this->session->set_flashdata('success', 'Bulk hapus selesai. Berhasil: ' . $success . ', gagal: ' . $failed . '.');
        } elseif ($action === 'mark_paid') {
            $this->session->set_flashdata('success', 'Bulk mark lunas selesai. Berhasil: ' . $success . ', gagal: ' . $failed . '.');
        } else {
            $this->session->set_flashdata('success', 'Bulk mark overdue selesai. Berhasil: ' . $success . ', gagal: ' . $failed . '.');
        }

        redirect($this->billing_return_url());
    }

    private function collect_invoice_ids()
    {
        $raw = $this->input->post('invoice_ids');
        if (!is_array($raw)) {
            $raw = array();
        }

        $csv = trim((string) $this->input->post('invoice_ids_csv', true));
        if ($csv !== '') {
            $parts = explode(',', $csv);
            foreach ($parts as $part) {
                $raw[] = $part;
            }
        }

        $ids = array();
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function table_has_column($table, $column)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }

        return in_array((string) $column, $this->db->list_fields($table), true);
    }

    private function resolve_invoice_ont_delete_target(array $invoice)
    {
        $router_id = $this->resolve_invoice_router_id($invoice);
        if ($router_id <= 0) {
            return array('success' => false, 'message' => 'Router ONT tidak ditemukan.');
        }
        if (!$this->can_access_billing_router($router_id)) {
            return array('success' => false, 'message' => 'Akses router ONT ditolak.');
        }

        $customer_id = (int) ($invoice['customer_id'] ?? 0);
        $device_id = trim((string) ($invoice['customer_ont_device_id'] ?? ''));
        $serial = trim((string) ($invoice['customer_ont_serial'] ?? ''));
        $source = '';

        if ($device_id !== '') {
            $source = 'customers.ont_device_id';
        } elseif ($serial !== '') {
            $source = 'customers.ont_serial';
        }

        $wan_ip = trim((string) ($invoice['customer_ip_address'] ?? ''));
        if (!$this->is_usable_wan_ip($wan_ip)) {
            $wan_ip = trim((string) ($invoice['remote_ip'] ?? ''));
        }

        if ($device_id === '' && $serial === '' && $this->is_usable_wan_ip($wan_ip) && $this->ont_device_model->table_exists()) {
            $local_ont = (array) $this->ont_device_model->find_by_wan_ip($wan_ip, $router_id);
            if (!empty($local_ont['serial_number'])) {
                $serial = trim((string) $local_ont['serial_number']);
                $source = 'ont_devices.wan_ip';
            }
        }

        if ($device_id === '' && $serial === '' && $customer_id > 0) {
            $local_ont = $this->find_local_ont_by_customer_id($customer_id, $router_id);
            if (!empty($local_ont['serial_number'])) {
                $serial = trim((string) $local_ont['serial_number']);
                $source = 'ont_devices.customer_id';
            }
        }

        if ($device_id === '' && $serial === '') {
            return array(
                'success' => false,
                'message' => 'Customer belum punya ONT di ACS/local mirror. Isi ont_device_id/ont_serial atau sync ONT dulu.',
            );
        }

        return array(
            'success' => true,
            'router_id' => $router_id,
            'customer_id' => $customer_id,
            'device_id' => $device_id,
            'serial_number' => $serial,
            'wan_ip' => $wan_ip,
            'source' => $source,
        );
    }

    private function find_local_ont_by_customer_id($customer_id, $router_id)
    {
        $customer_id = (int) $customer_id;
        $router_id = (int) $router_id;
        if ($customer_id <= 0 || !$this->db->table_exists('ont_devices')) {
            return array();
        }

        $qb = $this->db
            ->from('ont_devices')
            ->where('customer_id', $customer_id)
            ->order_by('last_inform', 'DESC')
            ->order_by('id', 'DESC')
            ->limit(1);

        if ($router_id > 0 && $this->table_has_column('ont_devices', 'router_id')) {
            $qb->where('router_id', $router_id);
        }

        $row = $qb->get()->row_array();
        return is_array($row) ? $row : array();
    }

    private function build_acs_gap_report($search = '')
    {
        $summary = array(
            'customer_with_ip' => 0,
            'registered_in_acs' => 0,
            'missing_in_acs' => 0,
            'total_acs_ont' => $this->count_scoped_ont_devices(),
        );
        $missing_rows = array();

        if (!$this->db->table_exists('customers')) {
            return array(
                'summary' => $summary,
                'missing_rows' => array(),
                'error_message' => 'Tabel customers tidak ditemukan.',
            );
        }
        if (!$this->ont_device_model->table_exists()) {
            return array(
                'summary' => $summary,
                'missing_rows' => array(),
                'error_message' => 'Tabel ont_devices belum tersedia. Jalankan migration/sync GenieACS terlebih dahulu.',
            );
        }

        $customers = $this->get_customer_wan_ip_rows($search);
        foreach ($customers as $customer) {
            $wan_ip = trim((string) ($customer['ip_address'] ?? ''));
            if (!$this->is_usable_wan_ip($wan_ip)) {
                continue;
            }

            $summary['customer_with_ip']++;
            $router_id = (int) ($customer['router_id'] ?? 0);
            $matched_ont = (array) $this->ont_device_model->find_by_wan_ip($wan_ip, $router_id > 0 ? $router_id : null);
            if (!empty($matched_ont['id'])) {
                $summary['registered_in_acs']++;
                continue;
            }

            $summary['missing_in_acs']++;
            $customer['pppoe_label'] = trim((string) ($customer['pppoe_username'] ?? ''));
            if ($customer['pppoe_label'] === '') {
                $customer['pppoe_label'] = trim((string) ($customer['username'] ?? ''));
            }
            $missing_rows[] = $customer;
        }

        return array(
            'summary' => $summary,
            'missing_rows' => $missing_rows,
            'error_message' => '',
        );
    }

    private function get_customer_wan_ip_rows($search = '')
    {
        if (!$this->db->table_exists('customers')) {
            return array();
        }

        $fields = $this->db->list_fields('customers');
        if (!in_array('ip_address', $fields, true)) {
            return array();
        }

        $has_router_id = in_array('router_id', $fields, true);
        $select = array('c.id', 'c.ip_address');
        $select[] = in_array('customer_code', $fields, true) ? 'c.customer_code' : "'' AS customer_code";
        $select[] = in_array('full_name', $fields, true)
            ? 'c.full_name'
            : (in_array('nama', $fields, true) ? 'c.nama AS full_name' : "'' AS full_name");
        $select[] = $has_router_id ? 'c.router_id' : '0 AS router_id';
        $select[] = in_array('status', $fields, true) ? 'c.status AS customer_status' : "'' AS customer_status";
        $select[] = in_array('connection_type', $fields, true) ? 'c.connection_type' : "'' AS connection_type";
        $select[] = in_array('pppoe_username', $fields, true) ? 'c.pppoe_username' : "'' AS pppoe_username";
        $select[] = in_array('username', $fields, true) ? 'c.username' : "'' AS username";
        $select[] = in_array('ont_device_id', $fields, true) ? 'c.ont_device_id' : "'' AS ont_device_id";
        $select[] = in_array('ont_serial', $fields, true) ? 'c.ont_serial' : "'' AS ont_serial";

        if ($has_router_id && $this->db->table_exists('routers')) {
            $select[] = 'r.name AS router_name';
        } else {
            $select[] = "'' AS router_name";
        }

        $qb = $this->db
            ->select(implode(', ', $select), false)
            ->from('customers c')
            ->where('c.ip_address IS NOT NULL', null, false)
            ->where('c.ip_address <>', '');

        if ($has_router_id && $this->db->table_exists('routers')) {
            $qb->join('routers r', 'r.id = c.router_id', 'left');
        }

        if (in_array('status', $fields, true)) {
            $qb->where_in('c.status', array('active', 'suspended'));
        }
        if ($has_router_id) {
            $this->applyRouterFilter('c', $qb);
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $qb->group_start()
                ->like('c.ip_address', $search);
            foreach (array('customer_code', 'full_name', 'nama', 'pppoe_username', 'username') as $col) {
                if (in_array($col, $fields, true)) {
                    $qb->or_like('c.' . $col, $search);
                }
            }
            $qb->group_end();
        }

        if ($has_router_id) {
            $qb->order_by('c.router_id', 'ASC');
        }
        $qb->order_by(in_array('full_name', $fields, true) ? 'c.full_name' : 'c.id', 'ASC');

        return $qb->get()->result_array();
    }

    private function count_scoped_ont_devices()
    {
        if (!$this->db->table_exists('ont_devices')) {
            return 0;
        }

        $qb = $this->db->from('ont_devices d');
        if ($this->table_has_column('ont_devices', 'router_id')) {
            $this->applyRouterFilter('d', $qb);
        }

        return (int) $qb->count_all_results();
    }

    private function is_usable_wan_ip($ip)
    {
        $ip = trim((string) $ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return !in_array($ip, array('0.0.0.0', '::', '::0'), true);
    }

    private function resolve_invoice_router_id(array $invoice)
    {
        $router_id = (int) ($invoice['router_id'] ?? 0);
        if ($router_id <= 0) {
            $router_id = (int) ($invoice['customer_router_id'] ?? 0);
        }
        if ($router_id <= 0) {
            $effective = $this->getEffectiveRouterId();
            $router_id = $effective !== null ? (int) $effective : 0;
        }

        return $router_id;
    }

    private function can_access_billing_router($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0) {
            return false;
        }
        if ($this->is_superadmin()) {
            return true;
        }

        $effective = $this->getEffectiveRouterId();
        return $effective !== null && (int) $effective === $router_id;
    }

    private function load_genieacs_client($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0) {
            throw new RuntimeException('Router ID tidak valid untuk GenieACS.');
        }

        $alias = 'genieacs_billing_client_' . $router_id;
        $this->load->library('genieacs', array('router_id' => $router_id), $alias);
        return $this->{$alias};
    }

    private function resolve_remote_ip_from_local($username)
    {
        $username = trim((string) $username);
        if ($username === '') {
            return '';
        }

        $context = $this->resolve_customer_context_by_username($username);
        $ip = trim((string) ($context['remote_ip'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return '';
    }

    private function resolve_isolation_profile_for_username($username, array $secret = array())
    {
        $context = $this->resolve_customer_context_by_username($username);
        $isolation_profile = trim((string) ($context['isolation_profile'] ?? ''));
        if ($isolation_profile !== '') {
            return $isolation_profile;
        }

        $secret_profile = trim((string) ($secret['profile'] ?? ''));
        if ($secret_profile !== ''
            && $this->db->table_exists('ppp_profiles')
            && $this->table_has_column('ppp_profiles', 'name')
            && $this->table_has_column('ppp_profiles', 'isolation_profile')
        ) {
            $row = (array) $this->db
                ->select('isolation_profile')
                ->from('ppp_profiles')
                ->where('name', $secret_profile)
                ->limit(1)
                ->get()
                ->row_array();
            $isolation_profile = trim((string) ($row['isolation_profile'] ?? ''));
            if ($isolation_profile !== '') {
                return $isolation_profile;
            }
        }

        return '';
    }

    private function resolve_customer_context_by_username($username)
    {
        $username = trim((string) $username);
        $context = array(
            'customer_id' => 0,
            'profile_id' => 0,
            'isolation_profile' => '',
            'remote_ip' => '',
            'router_id' => 0,
        );

        if ($username === '') {
            return $context;
        }

        if ($this->db->table_exists('customer_services')
            && $this->table_has_column('customer_services', 'pppoe_username')
            && $this->table_has_column('customer_services', 'customer_id')
        ) {
            $select = array('customer_id');
            if ($this->table_has_column('customer_services', 'ppp_profile_id')) {
                $select[] = 'ppp_profile_id';
            }
            if ($this->table_has_column('customer_services', 'ip_address')) {
                $select[] = 'ip_address';
            }
            if ($this->table_has_column('customer_services', 'router_id')) {
                $select[] = 'router_id';
            }

            $row = (array) $this->db
                ->select(implode(',', $select))
                ->from('customer_services')
                ->where('pppoe_username', $username)
                ->order_by('id', 'DESC')
                ->limit(1)
                ->get()
                ->row_array();

            if (!empty($row)) {
                $context['customer_id'] = (int) ($row['customer_id'] ?? 0);
                $context['profile_id'] = (int) ($row['ppp_profile_id'] ?? 0);
                $context['remote_ip'] = trim((string) ($row['ip_address'] ?? ''));
                $context['router_id'] = (int) ($row['router_id'] ?? 0);
            }
        }

        if ($this->db->table_exists('customers')) {
            $customer_fields = $this->db->list_fields('customers');
            $username_cols = array();
            if (in_array('pppoe_username', $customer_fields, true)) {
                $username_cols[] = 'pppoe_username';
            }
            if (in_array('username', $customer_fields, true)) {
                $username_cols[] = 'username';
            }

            if (!empty($username_cols)) {
                $select = array('id');
                if (in_array('profile_id', $customer_fields, true)) {
                    $select[] = 'profile_id';
                }
                if (in_array('ip_address', $customer_fields, true)) {
                    $select[] = 'ip_address';
                }
                if (in_array('router_id', $customer_fields, true)) {
                    $select[] = 'router_id';
                }

                $qb = $this->db
                    ->select(implode(',', $select))
                    ->from('customers');

                $qb->group_start();
                foreach ($username_cols as $idx => $col) {
                    if ($idx === 0) {
                        $qb->where($col, $username);
                    } else {
                        $qb->or_where($col, $username);
                    }
                }
                $qb->group_end();

                $row = (array) $qb->limit(1)->get()->row_array();
                if (!empty($row)) {
                    if ($context['customer_id'] <= 0) {
                        $context['customer_id'] = (int) ($row['id'] ?? 0);
                    }
                    if ($context['profile_id'] <= 0) {
                        $context['profile_id'] = (int) ($row['profile_id'] ?? 0);
                    }
                    if ($context['remote_ip'] === '') {
                        $context['remote_ip'] = trim((string) ($row['ip_address'] ?? ''));
                    }
                    if ($context['router_id'] <= 0) {
                        $context['router_id'] = (int) ($row['router_id'] ?? 0);
                    }
                }
            }
        }

        if ($context['profile_id'] > 0 && $this->db->table_exists('ppp_profiles')) {
            $select = array();
            if ($this->table_has_column('ppp_profiles', 'isolation_profile')) {
                $select[] = 'isolation_profile';
            }
            if (!empty($select)) {
                $row = (array) $this->db
                    ->select(implode(',', $select))
                    ->from('ppp_profiles')
                    ->where('id', $context['profile_id'])
                    ->limit(1)
                    ->get()
                    ->row_array();
                $context['isolation_profile'] = trim((string) ($row['isolation_profile'] ?? ''));
            }
        }

        return $context;
    }

    private function normalize_phone_for_whatsapp($phone)
    {
        $phone = preg_replace('/\D+/', '', (string) $phone);
        if ($phone === '') {
            return '';
        }

        if (strpos($phone, '0') === 0) {
            $phone = '62' . substr($phone, 1);
        }

        if (strpos($phone, '62') !== 0) {
            return '';
        }

        return $phone;
    }

    private function resolve_invoice_router_branding(array $invoice)
    {
        if (!$this->db->table_exists('routers')) {
            return array();
        }

        $router_id = (int) ($invoice['router_id'] ?? 0);
        if ($router_id <= 0 && $this->db->table_exists('customers') && !empty($invoice['customer_id'])) {
            $customer_fields = $this->db->list_fields('customers');
            if (in_array('router_id', $customer_fields, true)) {
                $row = (array) $this->db
                    ->select('router_id')
                    ->from('customers')
                    ->where('id', (int) $invoice['customer_id'])
                    ->limit(1)
                    ->get()
                    ->row_array();
                $router_id = (int) ($row['router_id'] ?? 0);
            }
        }

        if ($router_id <= 0) {
            $effective = $this->getEffectiveRouterId();
            $router_id = $effective !== null ? (int) $effective : 0;
        }

        if ($router_id <= 0) {
            return array();
        }

        $qb = $this->db->from('routers')->where('id', $router_id)->limit(1);
        $row = (array) $qb->get()->row_array();
        return is_array($row) ? $row : array();
    }

    private function get_invoice_detail($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return array();
        }

        $customer_has_profile_id = $this->db->table_exists('customers') && in_array('profile_id', $this->db->list_fields('customers'), true);
        $customer_has_pppoe_username = $this->db->table_exists('customers') && in_array('pppoe_username', $this->db->list_fields('customers'), true);
        $customer_has_username = $this->db->table_exists('customers') && in_array('username', $this->db->list_fields('customers'), true);
        $customer_has_ip = $this->db->table_exists('customers') && in_array('ip_address', $this->db->list_fields('customers'), true);
        $customer_has_ont_device_id = $this->db->table_exists('customers') && in_array('ont_device_id', $this->db->list_fields('customers'), true);
        $customer_has_ont_serial = $this->db->table_exists('customers') && in_array('ont_serial', $this->db->list_fields('customers'), true);
        $customer_has_router_id = $this->db->table_exists('customers') && in_array('router_id', $this->db->list_fields('customers'), true);

        $select = array(
            'i.*',
            'c.full_name as customer_name',
            'c.phone as customer_phone',
            'c.address as customer_address',
            'c.customer_code',
        );
        $select[] = $customer_has_profile_id ? 'c.profile_id as customer_profile_id' : 'NULL as customer_profile_id';
        $select[] = $customer_has_pppoe_username ? 'c.pppoe_username as customer_pppoe_username' : "'' as customer_pppoe_username";
        $select[] = $customer_has_username ? 'c.username as customer_username' : "'' as customer_username";
        $select[] = $customer_has_ip ? 'c.ip_address as customer_ip_address' : "'' as customer_ip_address";
        $select[] = $customer_has_ont_device_id ? 'c.ont_device_id as customer_ont_device_id' : "'' as customer_ont_device_id";
        $select[] = $customer_has_ont_serial ? 'c.ont_serial as customer_ont_serial' : "'' as customer_ont_serial";
        $select[] = $customer_has_router_id ? 'c.router_id as customer_router_id' : "0 as customer_router_id";

        $this->db
            ->select(implode(', ', $select), false)
            ->from('invoices i')
            ->join('customers c', 'c.id = i.customer_id', 'left')
            ->where('i.id', $id);

        if ($this->table_has_column('invoices', 'router_id')) {
            $this->applyRouterFilter('i', $this->db);
        }

        $invoice = (array) $this->db
            ->limit(1)
            ->get()
            ->row_array();
        if (empty($invoice)) {
            return array();
        }

        $service = array();
        if ($this->db->table_exists('customer_services')) {
            $service_fields = $this->db->list_fields('customer_services');
            $service_has_customer_id = in_array('customer_id', $service_fields, true);
            $service_has_profile_id = in_array('ppp_profile_id', $service_fields, true);
            $service_has_username = in_array('pppoe_username', $service_fields, true);
            $service_has_ip = in_array('ip_address', $service_fields, true);

            if ($service_has_customer_id) {
                if (!empty($invoice['customer_service_id'])) {
                    $service = (array) $this->db
                        ->from('customer_services')
                        ->where('id', (int) $invoice['customer_service_id'])
                        ->limit(1)
                        ->get()
                        ->row_array();
                }

                if (empty($service)) {
                    $service = (array) $this->db
                        ->from('customer_services')
                        ->where('customer_id', (int) ($invoice['customer_id'] ?? 0))
                        ->order_by('id', 'DESC')
                        ->limit(1)
                        ->get()
                        ->row_array();
                }
            }

            $service_username = $service_has_username ? trim((string) ($service['pppoe_username'] ?? '')) : '';
            $service_ip = $service_has_ip ? trim((string) ($service['ip_address'] ?? '')) : '';
            $service_profile_id = $service_has_profile_id ? (int) ($service['ppp_profile_id'] ?? 0) : 0;

            $customer_username = trim((string) ($invoice['customer_username'] ?? ''));
            $customer_pppoe_username = trim((string) ($invoice['customer_pppoe_username'] ?? ''));
            $customer_ip = trim((string) ($invoice['customer_ip_address'] ?? ''));

            $resolved_username = $service_username !== '' ? $service_username : ($customer_pppoe_username !== '' ? $customer_pppoe_username : $customer_username);
            $resolved_ip = $service_ip !== '' ? $service_ip : $customer_ip;

            $profile_id = $service_profile_id > 0 ? $service_profile_id : (int) ($invoice['customer_profile_id'] ?? 0);
            $profile_name = '';
            if ($profile_id > 0 && $this->db->table_exists('ppp_profiles')) {
                $profile = (array) $this->db
                    ->select('name')
                    ->from('ppp_profiles')
                    ->where('id', $profile_id)
                    ->limit(1)
                    ->get()
                    ->row_array();
                $profile_name = trim((string) ($profile['name'] ?? ''));
            }
            if ($profile_name === '' && $profile_id > 0) {
                $profile_name = 'Profile #' . $profile_id;
            }

            $invoice['pppoe_username'] = $resolved_username;
            $invoice['remote_ip'] = $resolved_ip;
            $invoice['profile_name'] = $profile_name;
        } else {
            $invoice['pppoe_username'] = trim((string) ($invoice['customer_pppoe_username'] ?? $invoice['customer_username'] ?? ''));
            $invoice['remote_ip'] = trim((string) ($invoice['customer_ip_address'] ?? ''));
            $invoice['profile_name'] = '';
        }

        if (trim((string) ($invoice['pppoe_username'] ?? '')) === '') {
            $invoice['pppoe_username'] = '-';
        }
        if (trim((string) ($invoice['remote_ip'] ?? '')) === '') {
            $invoice['remote_ip'] = '-';
        }
        if (trim((string) ($invoice['profile_name'] ?? '')) === '') {
            $invoice['profile_name'] = '-';
        }

        if ($this->db->table_exists('payments')) {
            $payment = (array) $this->db
                ->select('payment_date, amount, method, status')
                ->from('payments')
                ->where('invoice_id', $id)
                ->order_by('id', 'DESC')
                ->limit(1)
                ->get()
                ->row_array();

            if (!empty($payment)) {
                $invoice['last_payment_date'] = (string) ($payment['payment_date'] ?? '');
                $invoice['last_payment_amount'] = (float) ($payment['amount'] ?? 0);
                $invoice['last_payment_method'] = (string) ($payment['method'] ?? '');
                $invoice['last_payment_status'] = (string) ($payment['status'] ?? '');
            } else {
                $invoice['last_payment_date'] = '';
                $invoice['last_payment_amount'] = 0;
                $invoice['last_payment_method'] = '';
                $invoice['last_payment_status'] = '';
            }
        }

        return $invoice;
    }

    private function delete_invoice_by_role($id, $is_superadmin, $note_prefix = '[DELETE]')
    {
        $id = (int) $id;
        if ($id <= 0) {
            return array('success' => false, 'mode' => '', 'message' => 'ID invoice tidak valid.');
        }

        $invoice = $this->get_invoice_detail($id);
        if (empty($invoice)) {
            return array('success' => false, 'mode' => '', 'message' => 'Invoice tidak ditemukan.');
        }

        if (!$is_superadmin) {
            $existing_status = strtolower((string) ($invoice['status'] ?? ''));
            if ($existing_status === 'void') {
                return array('success' => true, 'mode' => 'soft', 'message' => 'Invoice sudah VOID.');
            }

            $void_note = trim((string) ($invoice['notes'] ?? ''));
            $void_note = trim($void_note . ' ' . $note_prefix . '->VOID_ADMIN user=' . (int) $this->session->userdata('user_id') . ' ' . date('Y-m-d H:i:s'));
            $ok = $this->db->where('id', $id)->update('invoices', array(
                'status' => 'void',
                'notes' => $void_note,
                'updated_at' => date('Y-m-d H:i:s'),
            ));

            if (!$ok) {
                $err = $this->db->error();
                return array('success' => false, 'mode' => 'soft', 'message' => (string) ($err['message'] ?? 'update void gagal'));
            }

            return array('success' => true, 'mode' => 'soft', 'message' => 'Invoice di-void.');
        }

        $this->db->trans_begin();

        // Hard delete superadmin: hapus payment terkait dulu agar FK invoices -> payments tidak menahan delete.
        if ($this->db->table_exists('payments')) {
            $payment_ids = $this->db->select('id')->from('payments')->where('invoice_id', $id)->get()->result_array();
            $payment_ids = array_values(array_filter(array_map(static function ($row) {
                return (int) ($row['id'] ?? 0);
            }, $payment_ids)));

            if (!empty($payment_ids) && $this->db->table_exists('cashflow_transactions')) {
                $this->db->where_in('payment_id', $payment_ids)->delete('cashflow_transactions');
            }

            if (!empty($payment_ids)) {
                $this->db->where('invoice_id', $id)->delete('payments');
            }
        }

        if ($this->db->table_exists('cashflow_transactions')) {
            $this->db->where('invoice_id', $id)->delete('cashflow_transactions');
        }

        $this->db->where('id', $id)->delete('invoices');

        if ($this->db->trans_status() === false) {
            $err = $this->db->error();
            $this->db->trans_rollback();
            return array('success' => false, 'mode' => 'hard', 'message' => (string) ($err['message'] ?? 'hard delete gagal'));
        }

        $this->db->trans_commit();
        return array('success' => true, 'mode' => 'hard', 'message' => 'Invoice dihapus permanen.');
    }
}
