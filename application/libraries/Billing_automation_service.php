<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Billing_automation_service
{
    private $CI;
    private $isolir_address_list;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->model('billing_automation_model');
        $this->CI->load->model('settings_model');
        $this->CI->load->library('MikrotikManager');
        $this->CI->load->helper('wa_template');
        $this->CI->load->library('Whatsapp_service');
        $this->CI->load->model('Whatsapp_log_model', 'whatsapp_log_model');

        $list_name = getenv('MIKROTIK_ISOLIR_LIST');
        if ($list_name === false || trim((string) $list_name) === '') {
            $list_name = 'ISOLIR';
        }
        $this->isolir_address_list = trim((string) $list_name);
    }

    public function generate_monthly_invoices($period_ym = null, $router_id = null)
    {
        $period_ym = $period_ym ? trim((string) $period_ym) : null;
        if ($period_ym !== null && $period_ym !== '') {
            return $this->generate_invoices_for_period($period_ym, $router_id);
        }

        return $this->generate_daily_rolling_invoices(null, $router_id);
    }

    public function generate_daily_rolling_invoices($run_date = null, $router_id = null)
    {
        $run_date = $run_date ? (string) $run_date : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $run_date)) {
            return array(
                'success' => false,
                'message' => 'Format run_date harus YYYY-MM-DD.',
            );
        }

        $period_ym = date('Y-m', strtotime($run_date));
        return $this->generate_invoices_internal($period_ym, $run_date, true, $router_id);
    }

    private function generate_invoices_for_period($period_ym, $router_id = null)
    {
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $period_ym)) {
            return array(
                'success' => false,
                'message' => 'Format period harus YYYY-MM.',
            );
        }

        return $this->generate_invoices_internal($period_ym, null, false, $router_id);
    }

    private function generate_invoices_internal($period_ym, $run_date, $daily_mode, $router_id = null)
    {
        $router_id = $router_id === null ? null : (int) $router_id;
        $schema = $this->CI->billing_automation_model->validate_required_schema();
        if (empty($schema['ok'])) {
            return array(
                'success' => false,
                'message' => 'Schema tabel billing belum lengkap.',
                'schema_missing' => $schema['missing'],
            );
        }

        $customers = $this->CI->billing_automation_model->get_active_customers_with_profile($router_id);
        $period_start = $period_ym . '-01';
        $period_end = date('Y-m-t', strtotime($period_start));
        $now = date('Y-m-d H:i:s');
        $router_names = $this->get_router_name_map();
        $invoices_has_router_id = $this->CI->db->table_exists('invoices')
            && in_array('router_id', $this->CI->db->list_fields('invoices'), true);

        $stats = array(
            'mode' => $daily_mode ? 'daily' : 'period',
            'period' => $period_ym,
            'run_date' => $run_date,
            'period_start' => $period_start,
            'period_end' => $period_end,
            'router_scope_id' => $router_id,
            'router_scope_label' => ($router_id !== null && $router_id > 0)
                ? ('Router #' . $router_id . ' - ' . ($router_names[$router_id] ?? '-'))
                : 'Semua Router',
            'due_rule' => 'billing_cycle_day = install_date day (last-day fallback)',
            'total_customer' => count($customers),
            'total_due_target' => 0,
            'total_created' => 0,
            'total_skipped_not_cycle' => 0,
            'total_skipped_duplicate' => 0,
            'total_failed' => 0,
            'total_wa_queued' => 0,
            'total_wa_skipped' => 0,
            'total_wa_failed' => 0,
            'errors' => array(),
            'first_error_message' => '',
            'router_summary' => array(),
        );

        foreach ($customers as $customer) {
            $customer_id = (int) ($customer['customer_id'] ?? $customer['id'] ?? 0);
            $router_key = (int) ($customer['service_router_id'] ?? 0);
            if ($router_key <= 0) {
                $router_key = (int) ($customer['customer_router_id'] ?? 0);
            }
            if (!isset($stats['router_summary'][$router_key])) {
                $stats['router_summary'][$router_key] = array(
                    'router_id' => $router_key,
                    'router_name' => $router_names[$router_key] ?? ($router_key > 0 ? ('Router #' . $router_key) : 'Unassigned'),
                    'total_customer' => 0,
                    'total_due_target' => 0,
                    'total_created' => 0,
                    'total_skipped_not_cycle' => 0,
                    'total_skipped_duplicate' => 0,
                    'total_failed' => 0,
                    'total_wa_queued' => 0,
                    'total_wa_skipped' => 0,
                    'total_wa_failed' => 0,
                );
            }
            $stats['router_summary'][$router_key]['total_customer']++;

            if ($customer_id <= 0) {
                $stats['total_failed']++;
                $stats['router_summary'][$router_key]['total_failed']++;
                $stats['errors'][] = array(
                    'customer_id' => 0,
                    'message' => 'customer_id tidak valid.',
                );
                continue;
            }

            $resolved_install_date = $this->resolve_install_date_for_customer($customer);
            $cycle_day = $this->extract_cycle_day($resolved_install_date);
            $due_date = $this->calculate_due_date_from_cycle_day($cycle_day, $period_start);

            if ($daily_mode) {
                if ($run_date !== $due_date) {
                    $stats['total_skipped_not_cycle']++;
                    $stats['router_summary'][$router_key]['total_skipped_not_cycle']++;
                    continue;
                }
            }

            $stats['total_due_target']++;
            $stats['router_summary'][$router_key]['total_due_target']++;

            if ($this->CI->billing_automation_model->invoice_exists_for_exact_period($customer_id, $period_start, $period_end)) {
                $stats['total_skipped_duplicate']++;
                $stats['router_summary'][$router_key]['total_skipped_duplicate']++;
                continue;
            }

            $price = $this->resolve_customer_price($customer);
            $tax_amount = 0.0;
            $discount_amount = 0.0;
            $total_amount = round($price + $tax_amount - $discount_amount, 2);
            $invoice_number = $this->CI->billing_automation_model->next_invoice_number($period_ym);

            $invoice_data = array(
                'invoice_number' => $invoice_number,
                'customer_id' => $customer_id,
                'customer_service_id' => null,
                'billing_period_start' => $period_start,
                'billing_period_end' => $period_end,
                // Period mode must keep issue_date <= due_date (DB check constraint).
                // Using period start prevents failures when generating after due day.
                'issue_date' => $daily_mode ? $run_date : $period_start,
                'due_date' => $due_date,
                'subtotal' => $price,
                'tax_amount' => $tax_amount,
                'discount_amount' => $discount_amount,
                'total_amount' => $total_amount,
                'paid_amount' => 0,
                'balance_amount' => $total_amount,
                'status' => 'issued',
                'notes' => 'Auto rolling invoice. cycle_day=' . $cycle_day . '; install_date=' . ($resolved_install_date !== '' ? $resolved_install_date : 'unknown'),
                'created_at' => $now,
                'updated_at' => $now,
            );

            if ($invoices_has_router_id) {
                $service_router_id = (int) ($customer['service_router_id'] ?? 0);
                $customer_router_id = (int) ($customer['customer_router_id'] ?? 0);
                $resolved_router_id = $service_router_id > 0
                    ? $service_router_id
                    : ($customer_router_id > 0 ? $customer_router_id : ((int) ($router_id ?? 0)));

                if ($resolved_router_id > 0) {
                    $invoice_data['router_id'] = $resolved_router_id;
                } else {
                    $stats['total_failed']++;
                    $stats['router_summary'][$router_key]['total_failed']++;
                    $msg = 'router_id invoice tidak ditemukan untuk customer_id=' . $customer_id;
                    $stats['errors'][] = array(
                        'customer_id' => $customer_id,
                        'invoice_number' => $invoice_number,
                        'error' => array('code' => 0, 'message' => $msg),
                        'router_id' => $router_key,
                    );
                    if ($stats['first_error_message'] === '') {
                        $stats['first_error_message'] = $msg;
                    }
                    log_message('error', '[Billing_automation_service::generate_invoices_internal] ' . $msg);
                    continue;
                }
            }

            if (array_key_exists('customer_service_id', $customer)
                && $customer['customer_service_id'] !== null
                && $customer['customer_service_id'] !== ''
            ) {
                $invoice_data['customer_service_id'] = (int) $customer['customer_service_id'];
            }

            $result = $this->CI->billing_automation_model->insert_invoice($invoice_data);
            if (!empty($result['success'])) {
                $stats['total_created']++;
                $stats['router_summary'][$router_key]['total_created']++;
                $wa_result = $this->queue_invoice_created_whatsapp((int) $result['id'], $customer, $invoice_data);
                if (!empty($wa_result['queued'])) {
                    $stats['total_wa_queued']++;
                    $stats['router_summary'][$router_key]['total_wa_queued']++;
                } elseif (!empty($wa_result['skipped'])) {
                    $stats['total_wa_skipped']++;
                    $stats['router_summary'][$router_key]['total_wa_skipped']++;
                } else {
                    $stats['total_wa_failed']++;
                    $stats['router_summary'][$router_key]['total_wa_failed']++;
                }
                continue;
            }

            $error_code = (int) ($result['error']['code'] ?? 0);
            if ($error_code === 1062) {
                $stats['total_skipped_duplicate']++;
                $stats['router_summary'][$router_key]['total_skipped_duplicate']++;
                continue;
            }

            $stats['total_failed']++;
            $stats['router_summary'][$router_key]['total_failed']++;
            $stats['errors'][] = array(
                'customer_id' => $customer_id,
                'invoice_number' => $invoice_number,
                'error' => $result['error'],
                'router_id' => $router_key,
            );
            if ($stats['first_error_message'] === '') {
                $stats['first_error_message'] = (string) ($result['error']['message'] ?? 'Insert invoice gagal.');
            }
            log_message('error', '[Billing_automation_service::generate_invoices_internal] insert invoice failed: ' . json_encode($result['error']));
        }

        if (!empty($stats['router_summary'])) {
            $stats['router_summary'] = array_values($stats['router_summary']);
            usort($stats['router_summary'], static function ($a, $b) {
                return ((int) ($a['router_id'] ?? 0) <=> (int) ($b['router_id'] ?? 0));
            });
        }

        return array(
            'success' => true,
            'message' => $daily_mode
                ? 'Generate invoice rolling harian selesai.'
                : 'Generate invoice per periode selesai.',
            'stats' => $stats,
        );
    }

    private function get_router_name_map()
    {
        $map = array();
        if (!$this->CI->db->table_exists('routers')) {
            return $map;
        }

        $fields = $this->CI->db->list_fields('routers');
        if (!in_array('id', $fields, true)) {
            return $map;
        }
        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : 'id');

        $rows = $this->CI->db
            ->select('id, ' . $name_col . ' as router_name', false)
            ->from('routers')
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = trim((string) ($row['router_name'] ?? ('Router #' . $id)));
        }

        return $map;
    }

    private function resolve_customer_price(array $customer)
    {
        $candidates = array(
            $customer['service_price'] ?? null,
            $customer['profile_price'] ?? null,
            $customer['price'] ?? null,
            $customer['package_price'] ?? null,
        );

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $value = (float) $candidate;
            if ($value >= 0) {
                return round($value, 2);
            }
        }

        return 0.0;
    }

    private function resolve_install_date_for_customer(array $customer)
    {
        foreach (array('install_date', 'service_install_date', 'join_date') as $column) {
            $candidate = trim((string) ($customer[$column] ?? ''));
            if ($this->is_valid_date($candidate)) {
                return $candidate;
            }
        }

        foreach (array('pppoe_password', 'ppp_password') as $column) {
            $parsed = $this->parse_install_date_from_password((string) ($customer[$column] ?? ''));
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $username_candidates = array();
        foreach (array('service_pppoe_username', 'pppoe_username', 'username') as $column) {
            $value = trim((string) ($customer[$column] ?? ''));
            if ($value !== '') {
                $username_candidates[$value] = $value;
            }
        }

        if (!empty($username_candidates) && $this->CI->db->table_exists('pppoe_secrets')) {
            $secret_fields = $this->CI->db->list_fields('pppoe_secrets');
            if (in_array('username', $secret_fields, true)) {
                $qb = $this->CI->db
                    ->select('username')
                    ->from('pppoe_secrets')
                    ->where_in('username', array_values($username_candidates))
                    ->limit(1);

                if (in_array('ppp_password', $secret_fields, true)) {
                    $qb->select('ppp_password');
                }
                if (in_array('password', $secret_fields, true)) {
                    $qb->select('password');
                }

                $secret = $qb->get()->row_array();
                if (!empty($secret)) {
                    foreach (array('ppp_password', 'password') as $column) {
                        $parsed = $this->parse_install_date_from_password((string) ($secret[$column] ?? ''));
                        if ($parsed !== null) {
                            return $parsed;
                        }
                    }
                }
            }
        }

        return '';
    }

    private function parse_install_date_from_password($password)
    {
        $password = trim((string) $password);
        if ($password === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $password)) {
            return $this->is_valid_date($password) ? $password : null;
        }

        if (preg_match('/^\d{8}$/', $password)) {
            $dd = (int) substr($password, 0, 2);
            $mm = (int) substr($password, 2, 2);
            $yyyy = (int) substr($password, 4, 4);
            if (checkdate($mm, $dd, $yyyy)) {
                return sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd);
            }

            $yyyy = (int) substr($password, 0, 4);
            $mm = (int) substr($password, 4, 2);
            $dd = (int) substr($password, 6, 2);
            if (checkdate($mm, $dd, $yyyy)) {
                return sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd);
            }
        }

        if (preg_match('/^\d{6}$/', $password)) {
            $dd = (int) substr($password, 0, 2);
            $mm = (int) substr($password, 2, 2);
            $yy = (int) substr($password, 4, 2);
            $yyyy = ($yy <= 69) ? (2000 + $yy) : (1900 + $yy);
            if (checkdate($mm, $dd, $yyyy)) {
                return sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd);
            }
        }

        return null;
    }

    private function is_valid_date($date)
    {
        $date = trim((string) $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $parts = explode('-', $date);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    private function extract_cycle_day($install_date)
    {
        if ($this->is_valid_date($install_date)) {
            return (int) date('d', strtotime($install_date));
        }

        return 1;
    }

    private function calculate_due_date_from_cycle_day($cycle_day, $period_start)
    {
        $period_start = trim((string) $period_start);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_start)) {
            $period_start = date('Y-m-01');
        }

        $period_ts = strtotime($period_start);
        $year = (int) date('Y', $period_ts);
        $month = (int) date('m', $period_ts);
        $last_day = (int) date('t', $period_ts);

        $cycle_day = (int) $cycle_day;
        if ($cycle_day < 1) {
            $cycle_day = 1;
        }
        if ($cycle_day > $last_day) {
            $cycle_day = $last_day;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $cycle_day);
    }

    public function generate_monthly_invoice($period_ym = null)
    {
        return $this->generate_monthly_invoices($period_ym);
    }

    public function auto_suspend($today = null, $grace_days = 5)
    {
        $today = $today ? (string) $today : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            return array(
                'success' => false,
                'message' => 'Format tanggal harus YYYY-MM-DD.',
            );
        }

        $grace_days = max(0, (int) $grace_days);

        $schema = $this->CI->billing_automation_model->validate_required_schema();
        if (empty($schema['ok'])) {
            return array(
                'success' => false,
                'message' => 'Schema tabel billing belum lengkap.',
                'schema_missing' => $schema['missing'],
            );
        }

        $targets = $this->CI->billing_automation_model->get_overdue_unpaid_customers($today, $grace_days);
        $stats = array(
            'date' => $today,
            'grace_days' => $grace_days,
            'due_threshold' => date('Y-m-d', strtotime($today . ' -' . $grace_days . ' day')),
            'total_target' => count($targets),
            'total_suspended' => 0,
            'total_isolir_added' => 0,
            'total_failed' => 0,
            'errors' => array(),
        );

        foreach ($targets as $row) {
            $customer_id = (int) $row['customer_id'];
            $username = trim((string) ($row['username'] ?? ''));
            $isolation_profile = trim((string) ($row['isolation_profile'] ?? ''));
            $remote_ip = trim((string) ($row['service_ip_address'] ?? $row['customer_ip_address'] ?? ''));
            $router_id = (int) ($row['service_router_id'] ?? 0);

            if ($username === '') {
                $username = $this->CI->billing_automation_model->get_customer_current_pppoe_username($customer_id);
            }

            if ($customer_id <= 0 || $username === '') {
                $stats['total_failed']++;
                $stats['errors'][] = array(
                    'customer_id' => $customer_id,
                    'username' => $username,
                    'message' => 'customer_id/username tidak valid.',
                );
                continue;
            }

            $this->CI->db->trans_begin();

            $this->CI->billing_automation_model->mark_customer_invoices_overdue($customer_id, $today, $grace_days);

            $customer_update = $this->CI->billing_automation_model->update_customer_status($customer_id, 'suspended');
            if (empty($customer_update['success'])) {
                $this->CI->db->trans_rollback();
                $stats['total_failed']++;
                $stats['errors'][] = array(
                    'customer_id' => $customer_id,
                    'username' => $username,
                    'message' => 'update customer status gagal',
                    'error' => $customer_update['error'],
                );
                continue;
            }

            $mk_result = $this->suspend_customer_in_mikrotik(
                $username,
                $isolation_profile,
                $remote_ip,
                $router_id,
                $customer_id
            );
            if (empty($mk_result['success'])) {
                $this->CI->db->trans_rollback();
                $stats['total_failed']++;
                $stats['errors'][] = array(
                    'customer_id' => $customer_id,
                    'username' => $username,
                    'message' => 'mikrotik suspend gagal',
                    'error' => $mk_result['message'],
                );
                continue;
            }

            if ($this->CI->db->trans_status() === false) {
                $this->CI->db->trans_rollback();
                $stats['total_failed']++;
                $stats['errors'][] = array(
                    'customer_id' => $customer_id,
                    'username' => $username,
                    'message' => 'transaction gagal',
                    'error' => $this->CI->db->error(),
                );
                continue;
            }

            $this->CI->db->trans_commit();
            $stats['total_suspended']++;
            if (!empty($mk_result['isolir_added'])) {
                $stats['total_isolir_added']++;
            }
        }

        return array(
            'success' => true,
            'message' => 'Auto suspend selesai.',
            'stats' => $stats,
        );
    }

    public function record_payment($invoice_id, $amount, $method, $payment_date = null)
    {
        $invoice_id = (int) $invoice_id;
        $amount = (float) $amount;
        $method = trim((string) $method);
        $payment_date = $payment_date ? (string) $payment_date : date('Y-m-d H:i:s');

        if ($invoice_id <= 0 || $amount <= 0 || $method === '') {
            return array(
                'success' => false,
                'message' => 'invoice_id, amount, dan method wajib valid.',
            );
        }

        $invoice = $this->CI->billing_automation_model->get_invoice_with_customer_profile($invoice_id);
        if (empty($invoice)) {
            return array(
                'success' => false,
                'message' => 'Invoice tidak ditemukan.',
            );
        }

        if ((string) $invoice['status'] === 'paid' || (float) ($invoice['balance_amount'] ?? 0) <= 0) {
            return array(
                'success' => false,
                'message' => 'Invoice sudah lunas.',
            );
        }

        $total_amount = (float) ($invoice['total_amount'] ?? 0);
        $current_paid = (float) ($invoice['paid_amount'] ?? 0);
        $current_balance = (float) ($invoice['balance_amount'] ?? $total_amount);
        if ($amount > $current_balance) {
            return array(
                'success' => false,
                'message' => 'Nominal pembayaran melebihi balance invoice.',
                'data' => array(
                    'balance_amount' => $current_balance,
                ),
            );
        }

        $new_paid = round($current_paid + $amount, 2);
        $new_balance = round($total_amount - $new_paid, 2);
        if ($new_balance < 0) {
            $new_balance = 0;
        }

        $new_status = ($new_balance <= 0) ? 'paid' : 'partially_paid';

        $this->CI->db->trans_begin();

        $payment_router_id = (int) ($invoice['service_router_id'] ?? $invoice['router_id'] ?? 0);
        if ($payment_router_id <= 0) {
            $payment_router_id = (int) $this->CI->billing_automation_model->get_customer_router_id(
                (int) ($invoice['customer_id'] ?? 0),
                (string) ($invoice['username'] ?? '')
            );
        }

        $payment_result = $this->CI->billing_automation_model->insert_payment(array(
            'invoice_id' => $invoice_id,
            'customer_id' => (int) ($invoice['customer_id'] ?? 0),
            'amount' => $amount,
            'payment_date' => $payment_date,
            'method' => $method,
            'status' => 'confirmed',
            'received_by' => (int) $this->CI->session->userdata('user_id'),
            'router_id' => $payment_router_id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ));

        if (empty($payment_result['success'])) {
            $this->CI->db->trans_rollback();
            return array(
                'success' => false,
                'message' => 'Insert payment gagal.',
                'error' => $payment_result['error'],
                'payload' => $payment_result['payload'] ?? null,
            );
        }

        $invoice_update = $this->CI->billing_automation_model->update_invoice_payment(
            $invoice_id,
            $new_paid,
            $new_balance,
            $new_status,
            $new_status === 'paid' ? $payment_date : null
        );

        if (empty($invoice_update['success'])) {
            $this->CI->db->trans_rollback();
            return array(
                'success' => false,
                'message' => 'Update invoice gagal.',
                'error' => $invoice_update['error'],
            );
        }

        $activation_done = false;
        $activation_skipped_static = false;
        $activation_skipped_mikrotik = false;
        $cashflow_logged = false;

        if ($new_status === 'paid') {
            $username = trim((string) ($invoice['username'] ?? ''));
            $profile_name = trim((string) ($invoice['profile_name'] ?? ''));
            $is_static_customer = $this->is_static_invoice_customer($invoice);
            $allow_mikrotik_on_mark_paid = (getenv('BILLING_APPLY_MIKROTIK_ON_MARK_PAID') === '1');
            if ($username === '') {
                $username = $this->CI->billing_automation_model->get_customer_current_pppoe_username((int) $invoice['customer_id']);
            }

            $customer_update = $this->CI->billing_automation_model->update_customer_status((int) $invoice['customer_id'], 'active');
            if (empty($customer_update['success'])) {
                $this->CI->db->trans_rollback();
                return array(
                    'success' => false,
                    'message' => 'Update customer status gagal.',
                    'error' => $customer_update['error'],
                );
            }

            // Customer STATIC: mark lunas hanya update DB + cashflow.
            // Aktivasi jaringan static ditangani oleh cron check_static_isolir.
            // Global default: mark lunas tidak mengubah state MikroTik.
            // Jika dibutuhkan, set env BILLING_APPLY_MIKROTIK_ON_MARK_PAID=1.
            if ($allow_mikrotik_on_mark_paid && !$is_static_customer) {
                $router_id = (int) ($invoice['service_router_id'] ?? 0);
                if ($username !== '') {
                    $mk_result = $this->activate_customer_in_mikrotik(
                        $username,
                        $profile_name,
                        (int) $invoice['customer_id'],
                        $router_id
                    );
                    if (empty($mk_result['success'])) {
                        $this->CI->db->trans_rollback();
                        return array(
                            'success' => false,
                            'message' => 'Activate profile di MikroTik gagal.',
                            'error' => $mk_result['message'],
                        );
                    }
                    $activation_done = true;
                }
            } else {
                $activation_skipped_mikrotik = true;
                if ($is_static_customer) {
                    $activation_skipped_static = true;
                }
            }

            $cashflow_result = $this->CI->billing_automation_model->insert_cashflow_income_once(
                $invoice_id,
                (int) $invoice['customer_id'],
                (int) $payment_result['id'],
                $total_amount,
                $payment_date,
                'Auto income invoice ' . (string) ($invoice['invoice_number'] ?? $invoice_id),
                (int) $this->CI->session->userdata('user_id'),
                (int) ($invoice['service_router_id'] ?? $invoice['router_id'] ?? 0)
            );

            if (empty($cashflow_result['success'])) {
                $this->CI->db->trans_rollback();
                return array(
                    'success' => false,
                    'message' => 'Pencatatan cashflow gagal.',
                    'error' => $cashflow_result,
                );
            }

            $cashflow_logged = empty($cashflow_result['skipped']);
        }

        if ($this->CI->db->trans_status() === false) {
            $this->CI->db->trans_rollback();
            return array(
                'success' => false,
                'message' => 'Transaction gagal.',
                'error' => $this->CI->db->error(),
            );
        }

        $this->CI->db->trans_commit();

        $wa_result = array('queued' => false, 'skipped' => true);
        if ($new_status === 'paid') {
            $wa_result = $this->queue_payment_received_whatsapp($invoice, $total_amount, $payment_date);
        }

        return array(
            'success' => true,
            'message' => $new_status === 'paid'
                ? 'Pembayaran lunas, customer aktif kembali.'
                : 'Pembayaran tercatat (partially paid).',
            'data' => array(
                'invoice_id' => $invoice_id,
                'payment_id' => (int) $payment_result['id'],
                'customer_id' => (int) $invoice['customer_id'],
                'invoice_status' => $new_status,
                'paid_amount' => $new_paid,
                'balance_amount' => $new_balance,
                'activated' => $activation_done,
                'activation_skipped_static' => $activation_skipped_static,
                'activation_skipped_mikrotik' => $activation_skipped_mikrotik,
                'cashflow_logged' => $cashflow_logged,
                'wa_queued' => !empty($wa_result['queued']),
            ),
        );
    }

    private function queue_invoice_created_whatsapp($invoice_id, array $customer, array $invoice_data)
    {
        try {
            $invoice_id = (int) $invoice_id;
            $customer_id = (int) ($invoice_data['customer_id'] ?? $customer['customer_id'] ?? $customer['id'] ?? 0);
            if ($invoice_id <= 0 || $customer_id <= 0) {
                return array('queued' => false, 'skipped' => true, 'message' => 'invoice/customer tidak valid');
            }

            if (!$this->CI->whatsapp_log_model->table_ready()) {
                return array('queued' => false, 'skipped' => true, 'message' => 'wa_message_logs belum tersedia');
            }

            if ($this->CI->whatsapp_log_model->exists_today($invoice_id, 'Tagihan Anda telah terbit')) {
                return array('queued' => false, 'skipped' => true, 'message' => 'duplikat invoice created hari ini');
            }

            $phone = $this->resolve_customer_phone($customer, $customer_id);
            if ($phone === '') {
                return array('queued' => false, 'skipped' => true, 'message' => 'nomor pelanggan kosong');
            }

            $message = invoice_created_message(
                $this->resolve_customer_name($customer, $customer_id),
                (string) ($invoice_data['invoice_number'] ?? ('INV-' . $invoice_id)),
                $this->resolve_invoice_amount($invoice_data),
                (string) ($invoice_data['due_date'] ?? ''),
                $this->whatsapp_payment_info()
            );

            $result = $this->CI->whatsapp_service->send_message($phone, $message, $invoice_id, $customer_id);
            return array(
                'queued' => !empty($result['queued']),
                'skipped' => false,
                'message' => (string) ($result['message'] ?? ''),
                'result' => $result,
            );
        } catch (Throwable $e) {
            log_message('error', '[Billing_automation_service] Queue WA invoice created gagal: ' . $e->getMessage());
            return array('queued' => false, 'skipped' => false, 'message' => $e->getMessage());
        }
    }

    private function queue_payment_received_whatsapp(array $invoice, $amount, $paid_at)
    {
        try {
            $invoice_id = (int) ($invoice['id'] ?? 0);
            $customer_id = (int) ($invoice['customer_id'] ?? 0);
            if ($invoice_id <= 0 || $customer_id <= 0) {
                return array('queued' => false, 'skipped' => true, 'message' => 'invoice/customer tidak valid');
            }

            if (!$this->CI->whatsapp_log_model->table_ready()) {
                return array('queued' => false, 'skipped' => true, 'message' => 'wa_message_logs belum tersedia');
            }

            $phone = $this->resolve_customer_phone($invoice, $customer_id);
            if ($phone === '') {
                return array('queued' => false, 'skipped' => true, 'message' => 'nomor pelanggan kosong');
            }

            $message = payment_received_message(
                $this->resolve_customer_name($invoice, $customer_id),
                (string) ($invoice['invoice_number'] ?? ('INV-' . $invoice_id)),
                $amount,
                $paid_at
            );

            $result = $this->CI->whatsapp_service->send_message($phone, $message, $invoice_id, $customer_id);
            return array(
                'queued' => !empty($result['queued']),
                'skipped' => false,
                'message' => (string) ($result['message'] ?? ''),
                'result' => $result,
            );
        } catch (Throwable $e) {
            log_message('error', '[Billing_automation_service] Queue WA payment received gagal: ' . $e->getMessage());
            return array('queued' => false, 'skipped' => false, 'message' => $e->getMessage());
        }
    }

    private function resolve_customer_phone(array $data, $customer_id)
    {
        foreach (array('phone', 'customer_phone', 'whatsapp', 'wa_number', 'mobile', 'no_hp', 'hp', 'telp') as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $row = $this->get_customer_contact_row((int) $customer_id);
        foreach (array('phone', 'whatsapp', 'wa_number', 'mobile', 'no_hp', 'hp', 'telp') as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolve_customer_name(array $data, $customer_id)
    {
        foreach (array('customer_name', 'customer_full_name', 'full_name', 'nama', 'name') as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $row = $this->get_customer_contact_row((int) $customer_id);
        foreach (array('full_name', 'nama', 'name', 'customer_code') as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Pelanggan';
    }

    private function get_customer_contact_row($customer_id)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0 || !$this->CI->db->table_exists('customers')) {
            return array();
        }

        $fields = $this->CI->db->list_fields('customers');
        $select = array('id');
        foreach (array('full_name', 'nama', 'name', 'customer_code', 'phone', 'whatsapp', 'wa_number', 'mobile', 'no_hp', 'hp', 'telp') as $field) {
            if (in_array($field, $fields, true)) {
                $select[] = $field;
            }
        }

        return (array) $this->CI->db
            ->select(implode(',', $select))
            ->from('customers')
            ->where('id', $customer_id)
            ->limit(1)
            ->get()
            ->row_array();
    }

    private function resolve_invoice_amount(array $invoice)
    {
        foreach (array('balance_amount', 'total_amount', 'amount', 'subtotal') as $key) {
            if (isset($invoice[$key]) && (float) $invoice[$key] > 0) {
                return (float) $invoice[$key];
            }
        }

        return 0;
    }

    private function whatsapp_payment_info()
    {
        $this->CI->load->config('whatsapp');
        $payment_info = trim((string) $this->CI->config->item('wa_payment_info'));
        return $payment_info !== '' ? $payment_info : 'Hubungi admin billing untuk informasi pembayaran.';
    }

    private function is_static_invoice_customer(array $invoice)
    {
        $connection_type = strtoupper(trim((string) ($invoice['customer_connection_type'] ?? '')));
        if ($connection_type === 'STATIC') {
            return true;
        }

        $pppoe_username = trim((string) ($invoice['customer_pppoe_username'] ?? ''));
        if ($pppoe_username !== '') {
            return false;
        }

        $full_name = strtoupper(trim((string) ($invoice['customer_full_name'] ?? '')));
        if ($full_name !== '' && strpos($full_name, 'STATIC ') === 0) {
            return true;
        }

        $notes = strtoupper((string) ($invoice['customer_notes'] ?? ''));
        if ($notes !== '' && strpos($notes, 'AUTO SYNC STATIC') !== false) {
            return true;
        }

        $username = trim((string) ($invoice['username'] ?? ''));
        if ($username !== '' && stripos($username, 'STATIC-') === 0) {
            return true;
        }

        return false;
    }

    public function get_dashboard_metrics()
    {
        return $this->CI->billing_automation_model->get_dashboard_metrics();
    }

    private function resolve_router_id_for_action($router_id = 0, $customer_id = 0, $username = '')
    {
        $router_id = (int) $router_id;
        if ($router_id > 0) {
            return $router_id;
        }

        $customer_id = (int) $customer_id;
        $username = trim((string) $username);

        return (int) $this->CI->billing_automation_model->get_customer_router_id($customer_id, $username);
    }

    public function suspend_customer_in_mikrotik($username, $isolation_profile, $remote_ip = '', $router_id = 0, $customer_id = 0)
    {
        $username = trim((string) $username);
        $isolation_profile = trim((string) $isolation_profile);
        $remote_ip = trim((string) $remote_ip);
        $customer_id = (int) $customer_id;

        if ($username === '') {
            return array('success' => false, 'message' => 'Username kosong.');
        }

        $router_id = $this->resolve_router_id_for_action($router_id, $customer_id, $username);
        if ($router_id <= 0) {
            return array('success' => false, 'message' => 'Router customer tidak ditemukan.');
        }

        $connect = $this->CI->mikrotikmanager->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return array('success' => false, 'message' => (string) ($connect['message'] ?? 'Koneksi router gagal.'));
        }

        try {
            $find = $this->CI->mikrotikmanager->command('/ppp/secret/print', array('?name' => $username));
            if (empty($find['success'])) {
                return array('success' => false, 'message' => 'PPPoE secret tidak ditemukan di router ini.');
            }

            $secret = array();
            foreach ((array) ($find['data'] ?? array()) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (trim((string) ($row['name'] ?? '')) === $username) {
                    $secret = $row;
                    break;
                }
            }
            if (empty($secret)) {
                return array('success' => false, 'message' => 'PPPoE secret tidak ditemukan.');
            }

            $secret_id = $this->extract_secret_id($secret);
            if ($secret_id === '') {
                return array('success' => false, 'message' => 'ID secret tidak ditemukan.');
            }

            if ($isolation_profile !== '') {
                $set_profile = $this->CI->mikrotikmanager->command('/ppp/secret/set', array(
                    '.id' => $secret_id,
                    'profile' => $isolation_profile,
                ));
                if (empty($set_profile['success'])) {
                    log_message('error', '[AUTO_SUSPEND] Gagal set isolation profile: ' . (string) ($set_profile['message'] ?? 'unknown'));
                }
            }

            if ($remote_ip === '') {
                $remote_ip = trim((string) ($secret['remote-address'] ?? ''));
            }

            $isolir_added = false;
            if ($remote_ip !== '' && filter_var($remote_ip, FILTER_VALIDATE_IP)) {
                $isolir_result = $this->add_ip_to_isolir_list($router_id, $remote_ip, $username);
                if (empty($isolir_result['success'])) {
                    return array(
                        'success' => false,
                        'message' => 'Gagal menambah address-list ISOLIR: ' . (string) ($isolir_result['message'] ?? 'unknown'),
                    );
                }
                $isolir_added = true;
            }

            return array(
                'success' => true,
                'message' => 'Customer berhasil di-isolir.',
                'isolir_added' => $isolir_added,
                'remote_ip' => $remote_ip,
                'router_id' => $router_id,
            );
        } finally {
            $this->CI->mikrotikmanager->disconnect();
        }
    }

    public function activate_customer_in_mikrotik($username, $normal_profile, $customer_id = 0, $router_id = 0)
    {
        $username = trim((string) $username);
        $normal_profile = trim((string) $normal_profile);
        $customer_id = (int) $customer_id;

        if ($username === '') {
            return array('success' => false, 'message' => 'Username kosong.');
        }

        $router_id = $this->resolve_router_id_for_action($router_id, $customer_id, $username);
        if ($router_id <= 0) {
            return array('success' => false, 'message' => 'Router customer tidak ditemukan.');
        }

        $connect = $this->CI->mikrotikmanager->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return array('success' => false, 'message' => (string) ($connect['message'] ?? 'Koneksi router gagal.'));
        }

        try {
            $find = $this->CI->mikrotikmanager->command('/ppp/secret/print', array('?name' => $username));
            if (empty($find['success'])) {
                return array('success' => false, 'message' => 'PPPoE secret tidak ditemukan.');
            }

            $secret = array();
            foreach ((array) ($find['data'] ?? array()) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (trim((string) ($row['name'] ?? '')) === $username) {
                    $secret = $row;
                    break;
                }
            }
            if (empty($secret)) {
                return array('success' => false, 'message' => 'PPPoE secret tidak ditemukan.');
            }

            $secret_id = $this->extract_secret_id($secret);
            if ($secret_id === '') {
                return array('success' => false, 'message' => 'ID secret tidak ditemukan.');
            }

            if ($normal_profile !== '') {
                $set_profile = $this->CI->mikrotikmanager->command('/ppp/secret/set', array(
                    '.id' => $secret_id,
                    'profile' => $normal_profile,
                ));
                if (empty($set_profile['success'])) {
                    return array(
                        'success' => false,
                        'message' => 'Gagal set profile normal: ' . (string) ($set_profile['message'] ?? 'unknown'),
                    );
                }
            }

            $enable = $this->CI->mikrotikmanager->command('/ppp/secret/set', array(
                '.id' => $secret_id,
                'disabled' => 'no',
            ));
            if (empty($enable['success'])) {
                return array(
                    'success' => false,
                    'message' => 'Gagal enable PPP secret: ' . (string) ($enable['message'] ?? 'unknown'),
                );
            }

            $remote_ip = '';
            if ($customer_id > 0) {
                $remote_ip = $this->CI->billing_automation_model->get_customer_primary_ip($customer_id);
            }
            if ($remote_ip === '') {
                $remote_ip = trim((string) ($secret['remote-address'] ?? ''));
            }

            if ($remote_ip !== '' && filter_var($remote_ip, FILTER_VALIDATE_IP)) {
                $remove_isolir = $this->remove_ip_from_isolir_list($router_id, $remote_ip);
                if (empty($remove_isolir['success'])) {
                    log_message('error', '[ACTIVATE_CUSTOMER] gagal remove isolir list: ' . (string) ($remove_isolir['message'] ?? 'unknown'));
                }
            }

            return array('success' => true, 'message' => 'PPP secret diaktifkan kembali.', 'router_id' => $router_id);
        } finally {
            $this->CI->mikrotikmanager->disconnect();
        }
    }

    private function add_ip_to_isolir_list($router_id, $ip_address, $username)
    {
        $result = $this->CI->mikrotikmanager->addToIsolirList((int) $router_id, (string) $ip_address, 'AUTO-ISOLIR ' . $username);
        return array(
            'success' => !empty($result['success']),
            'message' => (string) ($result['message'] ?? ''),
        );
    }

    private function remove_ip_from_isolir_list($router_id, $ip_address)
    {
        $result = $this->CI->mikrotikmanager->removeFromIsolirList((int) $router_id, (string) $ip_address);
        return array(
            'success' => !empty($result['success']),
            'message' => (string) ($result['message'] ?? ''),
        );
    }

    private function extract_secret_id(array $secret)
    {
        foreach (array('.id', '=.id', 'id') as $key) {
            if (!isset($secret[$key])) {
                continue;
            }
            $value = trim((string) $secret[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extract_router_id(array $row)
    {
        foreach (array('.id', '=.id', 'id') as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
