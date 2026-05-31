<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dashboard extends MY_Controller
{
    protected $dashboard_router_id = null;
    protected $dashboard_router_options = array();

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->model('billing_automation_model');
        $this->load->model('Teknisi_dashboard_model', 'teknisi_dashboard_model');
        $this->load->helper(array('tenant', 'rbac'));
    }

    public function index()
    {
        $role = normalizeRole((string) $this->session->userdata('role'));
        if ($role === 'teknisi') {
            redirect('teknisi-dashboard');
            return;
        }

        $router_context = $this->resolve_dashboard_router_context($role);
        $this->dashboard_router_id = $router_context['selected_router_id'];
        $this->dashboard_router_options = $router_context['router_options'];
        if (isset($this->teknisi_dashboard_model) && is_object($this->teknisi_dashboard_model) && method_exists($this->teknisi_dashboard_model, 'set_router_scope')) {
            $this->teknisi_dashboard_model->set_router_scope($this->dashboard_router_id, $this->is_superadmin());
        }

        $metrics = array(
            'total_customer' => 0,
            'active_customer' => 0,
            'suspended_customer' => 0,
            'total_unpaid' => 0,
            'income_month' => 0,
            'expense_month' => 0,
            'profit_month' => 0,
        );
        $monthly_summary = array(
            'instalasi_baru' => 0,
            'ticket_bulan_ini' => 0,
            'total_pendapatan' => 0,
            'error_log' => 0,
            'ppp_active' => 0,
        );
        $show_monthly_summary = in_array($role, array('superadmin', 'admin'), true);
        $teknisi_achievement_rows = array();
        $chart_series = array(
            'labels' => array(),
            'revenue' => array(),
            'ppp_active' => array(),
        );

        try {
            if ($this->db->table_exists('customers')
                && $this->db->table_exists('invoices')
                && $this->db->table_exists('payments')
            ) {
                $metrics = $this->billing_automation_model->get_dashboard_metrics($this->dashboard_router_id);
            }

            if ($show_monthly_summary) {
                $monthly_summary = $this->build_monthly_summary();
                $teknisi_achievement_rows = $this->build_teknisi_achievements($role);
            }
            $chart_series = $this->build_chart_series($this->dashboard_router_id);
        } catch (Throwable $e) {
            log_message('error', '[DASHBOARD][INDEX] ' . $e->getMessage());
        }

        $this->load->view('dashboard/index', array(
            'metrics' => $metrics,
            'monthly_summary' => $monthly_summary,
            'show_monthly_summary' => $show_monthly_summary,
            'teknisi_achievement_rows' => $teknisi_achievement_rows,
            'router_context' => $router_context,
            'chart_series' => $chart_series,
        ));
    }

    public function switch_router($router_id = null)
    {
        $this->require_role(array('superadmin', 'admin', 'teknisi'));

        $role = normalizeRole((string) $this->session->userdata('role'));
        $is_superadmin = ($role === 'superadmin');
        $router_context = $this->resolve_dashboard_router_context($role);
        $router_options = (array) ($router_context['router_options'] ?? array());
        $allowed_ids = array();
        foreach ($router_options as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $allowed_ids[$id] = true;
            }
        }

        $request_method = strtoupper((string) $this->input->method());
        if ($request_method === 'POST') {
            $selected = (int) $this->input->post('router_id', true);
        } else {
            $selected = (int) $router_id;
        }

        if ($request_method !== 'POST' && $selected <= 0 && $router_id !== null) {
            $selected = 0;
        }

        if ($selected <= 0) {
            if (!$is_superadmin) {
                $this->session->set_flashdata('error', 'Router wajib dipilih sesuai akses Anda.');
                $this->redirect_back();
                return;
            }
            $this->session->set_userdata('active_router', null);
            $this->session->set_userdata('active_router_id', null);
            $this->session->set_userdata('router_scope_id', null);
            $this->session->set_userdata('dashboard_router_id', null);
            $this->session->set_flashdata('success', 'Scope router diubah ke Semua Distribusi.');
            $this->redirect_back();
            return;
        }

        if (!isset($allowed_ids[$selected])) {
            $this->session->set_flashdata('error', 'Router tidak valid atau nonaktif.');
            $this->redirect_back();
            return;
        }

        $this->session->set_userdata('active_router', $selected);
        $this->session->set_userdata('active_router_id', $selected);
        $this->session->set_userdata('router_scope_id', $selected);
        $this->session->set_userdata('dashboard_router_id', $selected);
        $this->session->set_flashdata('success', 'Scope router aktif berhasil diubah.');
        $this->redirect_back();
    }

    private function redirect_back()
    {
        $redirect_to = trim((string) $this->input->post('redirect_to', true));
        if ($this->is_safe_internal_url($redirect_to)) {
            redirect($redirect_to);
            return;
        }

        $referer = isset($_SERVER['HTTP_REFERER']) ? trim((string) $_SERVER['HTTP_REFERER']) : '';
        if ($this->is_safe_internal_url($referer)) {
            redirect($referer);
            return;
        }

        redirect('dashboard');
    }

    private function is_safe_internal_url($url)
    {
        if ($url === '') {
            return false;
        }

        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            if (strpos($url, '//') === 0) {
                return false;
            }
            return true;
        }

        $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
        if ($host === '') {
            return false;
        }

        $parsed_host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return $parsed_host !== '' && $parsed_host === $host;
    }

    private function build_teknisi_achievements($viewer_role)
    {
        if (!isset($this->teknisi_dashboard_model) || !is_object($this->teknisi_dashboard_model)) {
            return array();
        }

        $ctx = $this->teknisi_dashboard_model->normalize_filters(
            array(
                'month' => (int) date('m'),
                'year' => (int) date('Y'),
                'period' => 'month',
                'technician_id' => 0,
            ),
            (string) $viewer_role,
            (int) $this->session->userdata('user_id')
        );

        $payload = $this->teknisi_dashboard_model->get_dashboard_payload($ctx);
        $rows = isset($payload['ranking_rows']) && is_array($payload['ranking_rows'])
            ? $payload['ranking_rows']
            : array();

        return array_slice($rows, 0, 10);
    }

    private function build_monthly_summary()
    {
        $range = $this->month_range();
        $router_id = $this->dashboard_router_id;

        return array(
            'instalasi_baru' => $this->count_installations_this_month($router_id),
            'ticket_bulan_ini' => $this->count_tickets_this_month($router_id),
            'total_pendapatan' => $this->query_income_per_router($router_id, $range),
            'error_log' => $this->count_error_logs_this_month(),
            'ppp_active' => $this->query_ppp_active_per_router($router_id),
            'total_pengeluaran' => $this->query_expense_per_router($router_id, $range),
            'total_customer_router' => $this->query_customer_addition_per_router($router_id, $range),
        );
    }

    private function count_installations_this_month($router_id = null)
    {
        if (!$this->db->table_exists('work_orders')) {
            return 0;
        }

        $fields = $this->db->list_fields('work_orders');
        if (empty($fields)) {
            return 0;
        }

        $type_col = $this->first_available_column($fields, array('wo_type', 'type'));
        $date_col = $this->first_available_column($fields, array('scheduled_start_at', 'scheduled_date', 'requested_date', 'open_at', 'created_at'));
        if ($date_col === '') {
            return 0;
        }

        $range = $this->month_range();
        $this->db->from('work_orders');
        if ($type_col !== '') {
            $this->db->where(
                'LOWER(' . $this->field_expr($type_col) . ') IN (\'installation\',\'instalasi\')',
                null,
                false
            );
        }
        $this->apply_router_filter_for_table('work_orders', $fields, $router_id);

        $this->apply_month_filter($date_col, $fields, $range);

        return (int) $this->db->count_all_results();
    }

    private function count_tickets_this_month($router_id = null)
    {
        if (!$this->db->table_exists('tickets')) {
            return 0;
        }

        $fields = $this->db->list_fields('tickets');
        if (empty($fields)) {
            return 0;
        }

        $date_col = $this->first_available_column($fields, array('opened_at', 'open_at', 'created_at'));
        if ($date_col === '') {
            return 0;
        }

        $range = $this->month_range();
        $this->db->from('tickets');
        $this->apply_router_filter_for_table('tickets', $fields, $router_id);
        $this->apply_month_filter($date_col, $fields, $range);

        return (int) $this->db->count_all_results();
    }

    private function sum_revenue_this_month($router_id = null)
    {
        $range = $this->month_range();

        if ($this->db->table_exists('payments')) {
            $fields = $this->db->list_fields('payments');
            if (in_array('amount', $fields, true)) {
                $date_col = $this->first_available_column($fields, array('payment_date', 'created_at'));
                if ($date_col !== '') {
                    $router_id = (int) $router_id;
                    if ($router_id > 0
                        && $this->db->table_exists('invoices')
                        && in_array('router_id', $this->db->list_fields('invoices'), true)
                        && in_array('invoice_id', $fields, true)
                    ) {
                        $this->db->select_sum('p.amount', 'total_amount');
                        $this->db->from('payments p');
                        $this->db->join('invoices i', 'i.id = p.invoice_id', 'inner');
                        $this->db->where('i.router_id', $router_id);
                        $this->apply_month_filter('p.' . $date_col, $fields, $range);
                        $row = $this->db->get()->row_array();
                        return (float) ($row['total_amount'] ?? 0);
                    }

                    $this->db->select_sum('amount', 'total_amount');
                    $this->db->from('payments');
                    $this->apply_month_filter($date_col, $fields, $range);
                    $row = $this->db->get()->row_array();
                    return (float) ($row['total_amount'] ?? 0);
                }
            }
        }

        if (!$this->db->table_exists('invoices')) {
            return 0;
        }

        $fields = $this->db->list_fields('invoices');
        $sum_col = in_array('paid_amount', $fields, true)
            ? 'paid_amount'
            : (in_array('total_amount', $fields, true) ? 'total_amount' : '');
        $date_col = $this->first_available_column($fields, array('paid_date', 'updated_at'));
        if ($sum_col === '' || $date_col === '') {
            return 0;
        }

        $this->db->select_sum($sum_col, 'total_amount');
        $this->db->from('invoices');
        $invoice_fields = $this->db->list_fields('invoices');
        $this->apply_router_filter_for_table('invoices', $invoice_fields, $router_id);
        if (in_array('status', $fields, true)) {
            $this->db->where('LOWER(' . $this->field_expr('status') . ') = \'paid\'', null, false);
        }
        $this->apply_month_filter($date_col, $fields, $range);
        $row = $this->db->get()->row_array();

        return (float) ($row['total_amount'] ?? 0);
    }

    private function count_error_logs_this_month()
    {
        if ($this->db->table_exists('system_logs')) {
            $fields = $this->db->list_fields('system_logs');
            if (!empty($fields)) {
                $date_col = $this->first_available_column($fields, array('created_at', 'log_time', 'logged_at', 'date'));
                $level_col = $this->first_available_column($fields, array('level', 'severity', 'type'));
                if ($date_col !== '') {
                    $range = $this->month_range();
                    $this->db->from('system_logs');
                    if ($level_col !== '') {
                        $this->db->where(
                            'LOWER(' . $this->field_expr($level_col) . ') IN (\'error\',\'critical\',\'fatal\')',
                            null,
                            false
                        );
                    }
                    $this->apply_router_filter_for_table('system_logs', $fields, $this->dashboard_router_id);
                    $this->apply_month_filter($date_col, $fields, $range);

                    return (int) $this->db->count_all_results();
                }
            }
        }

        return 0;
    }

    private function query_income_per_router($router_id, array $range)
    {
        return $this->query_income_per_router_range($router_id, $range);
    }

    private function query_income_per_router_range($router_id, array $range)
    {
        if ($this->db->table_exists('cashflow_transactions')) {
            $fields = $this->db->list_fields('cashflow_transactions');
            if (in_array('amount', $fields, true) && in_array('type', $fields, true)) {
                $date_col = $this->first_available_column($fields, array('txn_date', 'transaction_date', 'created_at'));
                if ($date_col !== '') {
                    $this->db->select_sum('amount', 'total_income');
                    $this->db->from('cashflow_transactions');
                    $this->db->where('LOWER(type)', 'income');
                    $this->apply_router_filter_for_table('cashflow_transactions', $fields, $router_id);
                    $this->apply_month_filter($date_col, $fields, $range);
                    $row = $this->db->get()->row_array();
                    return (float) ($row['total_income'] ?? 0);
                }
            }
        }

        return (float) $this->sum_revenue_in_range($router_id, $range);
    }

    private function sum_revenue_in_range($router_id, array $range)
    {
        if ($this->db->table_exists('payments')) {
            $fields = $this->db->list_fields('payments');
            if (in_array('amount', $fields, true)) {
                $date_col = $this->first_available_column($fields, array('payment_date', 'created_at', 'paid_at'));
                if ($date_col !== '') {
                    $router_id = (int) $router_id;
                    if ($router_id > 0
                        && $this->db->table_exists('invoices')
                        && in_array('router_id', $this->db->list_fields('invoices'), true)
                        && in_array('invoice_id', $fields, true)
                    ) {
                        $this->db->select_sum('p.amount', 'total_amount');
                        $this->db->from('payments p');
                        $this->db->join('invoices i', 'i.id = p.invoice_id', 'inner');
                        $this->db->where('i.router_id', $router_id);
                        $this->db->where('p.' . $date_col . ' >=', $range['start_datetime']);
                        $this->db->where('p.' . $date_col . ' <=', $range['end_datetime']);
                        $row = $this->db->get()->row_array();
                        return (float) ($row['total_amount'] ?? 0);
                    }

                    $this->db->select_sum('amount', 'total_amount');
                    $this->db->from('payments');
                    if ($router_id > 0 && in_array('router_id', $fields, true)) {
                        $this->db->where('router_id', $router_id);
                    }
                    $this->db->where($date_col . ' >=', $range['start_datetime']);
                    $this->db->where($date_col . ' <=', $range['end_datetime']);
                    $row = $this->db->get()->row_array();
                    return (float) ($row['total_amount'] ?? 0);
                }
            }
        }

        return 0.0;
    }

    private function query_expense_per_router($router_id, array $range)
    {
        if (!$this->db->table_exists('cashflow_transactions')) {
            return 0;
        }

        $fields = $this->db->list_fields('cashflow_transactions');
        if (!in_array('amount', $fields, true) || !in_array('type', $fields, true)) {
            return 0;
        }

        $date_col = $this->first_available_column($fields, array('txn_date', 'transaction_date', 'created_at'));
        if ($date_col === '') {
            return 0;
        }

        $this->db->select_sum('amount', 'total_expense');
        $this->db->from('cashflow_transactions');
        $this->db->where('LOWER(type)', 'expense');
        $this->apply_router_filter_for_table('cashflow_transactions', $fields, $router_id);
        $this->apply_month_filter($date_col, $fields, $range);
        $row = $this->db->get()->row_array();

        return (float) ($row['total_expense'] ?? 0);
    }

    private function query_customer_addition_per_router($router_id = null, array $range = array())
    {
        if (!$this->db->table_exists('customers')) {
            return 0;
        }

        $fields = $this->db->list_fields('customers');
        if (empty($fields)) {
            return 0;
        }

        $date_col = $this->first_available_column($fields, array('created_at', 'join_date', 'install_date'));
        if ($date_col === '') {
            return 0;
        }

        if (empty($range)) {
            $range = $this->month_range();
        }

        $this->db->from('customers');
        if (in_array('deleted_at', $fields, true)) {
            $this->db->where('deleted_at IS NULL', null, false);
        } elseif (in_array('is_deleted', $fields, true)) {
            $this->db->where('is_deleted', 0);
        }

        $this->apply_router_filter_for_table('customers', $fields, $router_id);
        $this->apply_month_filter($date_col, $fields, $range);

        return (int) $this->db->count_all_results();
    }

    private function query_ppp_active_per_router($router_id = null)
    {
        if ($router_id === null || (int) $router_id <= 0) {
            $total = 0;
            foreach ($this->dashboard_router_options as $router) {
                $rid = (int) ($router['id'] ?? 0);
                if ($rid > 0) {
                    $total += $this->query_ppp_active_per_router($rid);
                }
            }
            return $total;
        }

        if (function_exists('connectRouter')) {
            $connect = connectRouter((int) $router_id);
            if (!empty($connect['success']) && !empty($connect['api'])) {
                try {
                    $active = $connect['api']->comm('/ppp/active/print');
                    if (is_array($active)) {
                        return count($active);
                    }
                } catch (Throwable $e) {
                    log_message('error', '[DASHBOARD][PPP_ACTIVE] router=' . (int) $router_id . ' ' . $e->getMessage());
                } finally {
                    if (!empty($connect['api']) && method_exists($connect['api'], 'disconnect')) {
                        $connect['api']->disconnect();
                    }
                }
            }
        }

        if ($this->db->table_exists('pppoe_secrets')) {
            $fields = $this->db->list_fields('pppoe_secrets');
            if (in_array('router_id', $fields, true)) {
                $this->db->from('pppoe_secrets');
                $this->db->where('router_id', (int) $router_id);
                if (in_array('disabled', $fields, true)) {
                    $this->db->where('disabled', 0);
                }
                return (int) $this->db->count_all_results();
            }
        }

        return 0;
    }

    private function query_ppp_active_snapshot($router_id, $month_end_datetime)
    {
        if (!$this->db->table_exists('pppoe_secrets')) {
            return $this->query_ppp_active_per_router($router_id);
        }

        $fields = $this->db->list_fields('pppoe_secrets');
        if (empty($fields)) {
            return $this->query_ppp_active_per_router($router_id);
        }

        $this->db->from('pppoe_secrets');
        if ($router_id !== null && (int) $router_id > 0 && in_array('router_id', $fields, true)) {
            $this->db->where('router_id', (int) $router_id);
        }
        if (in_array('disabled', $fields, true)) {
            $this->db->where('disabled', 0);
        }
        if (in_array('created_at', $fields, true)) {
            $this->db->where('created_at <=', $month_end_datetime);
        }

        return (int) $this->db->count_all_results();
    }

    private function build_chart_series($router_id = null)
    {
        $labels = array();
        $revenue = array();
        $ppp_active = array();

        $months = 6;
        for ($i = $months - 1; $i >= 0; $i--) {
            $month_ts = strtotime(date('Y-m-01') . ' -' . $i . ' month');
            $start_date = date('Y-m-01', $month_ts);
            $end_date = date('Y-m-t', $month_ts);
            $start_datetime = $start_date . ' 00:00:00';
            $end_datetime = $end_date . ' 23:59:59';

            $labels[] = date('M Y', $month_ts);
            $revenue[] = round($this->query_income_per_router_range($router_id, array(
                'start_date' => $start_date,
                'end_date' => $end_date,
                'start_datetime' => $start_datetime,
                'end_datetime' => $end_datetime,
            )), 2);
            $ppp_active[] = $this->query_ppp_active_snapshot($router_id, $end_datetime);
        }

        return array(
            'labels' => $labels,
            'revenue' => $revenue,
            'ppp_active' => $ppp_active,
        );
    }

    private function resolve_dashboard_router_context($role)
    {
        $role = normalizeRole((string) $role);
        $is_superadmin = ($role === 'superadmin');
        $scope_router_id = $this->current_router_scope_id();
        $options = $this->get_active_router_options();
        $selected_router_id = null;

        if ($is_superadmin) {
            $session_selected = (int) $this->session->userdata('active_router_id');
            if ($session_selected <= 0) {
                $session_selected = (int) $this->session->userdata('dashboard_router_id');
            }
            if ($session_selected <= 0) {
                $session_selected = (int) $this->session->userdata('router_scope_id');
            }
            $allowed = array();
            foreach ($options as $row) {
                $rid = (int) ($row['id'] ?? 0);
                if ($rid > 0) {
                    $allowed[$rid] = true;
                }
            }

            if ($session_selected > 0 && isset($allowed[$session_selected])) {
                $selected_router_id = $session_selected;
            } else {
                $selected_router_id = null;
                $this->session->set_userdata('active_router_id', null);
                $this->session->set_userdata('dashboard_router_id', null);
            }
        } else {
            $allowed_router_ids = $this->session->userdata('router_access_ids');
            $allowed_router_ids = is_array($allowed_router_ids) ? $allowed_router_ids : array();
            if (empty($allowed_router_ids) && $scope_router_id !== null) {
                $allowed_router_ids = array((int) $scope_router_id);
            }

            $allowed_map = array();
            foreach ($allowed_router_ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $allowed_map[$id] = true;
                }
            }

            $options = array_values(array_filter($options, static function ($row) use ($allowed_map) {
                return isset($allowed_map[(int) ($row['id'] ?? 0)]);
            }));

            $session_selected = (int) $this->session->userdata('active_router_id');
            if ($session_selected <= 0) {
                $session_selected = (int) $this->session->userdata('dashboard_router_id');
            }
            if ($session_selected <= 0) {
                $session_selected = (int) $this->session->userdata('router_scope_id');
            }

            if ($session_selected > 0 && isset($allowed_map[$session_selected])) {
                $selected_router_id = $session_selected;
            } elseif (!empty($options)) {
                $selected_router_id = (int) ($options[0]['id'] ?? 0);
            } else {
                $selected_router_id = null;
            }

            if ($selected_router_id !== null && $selected_router_id > 0) {
                $this->session->set_userdata('active_router', $selected_router_id);
                $this->session->set_userdata('active_router_id', $selected_router_id);
                $this->session->set_userdata('router_scope_id', $selected_router_id);
                $this->session->set_userdata('dashboard_router_id', $selected_router_id);
            }
        }

        $selected_router_name = 'Semua Router';
        if ($selected_router_id !== null) {
            foreach ($options as $row) {
                if ((int) ($row['id'] ?? 0) === (int) $selected_router_id) {
                    $selected_router_name = (string) ($row['name'] ?? ('Router #' . $selected_router_id));
                    break;
                }
            }
        }

        return array(
            'is_superadmin' => $is_superadmin,
            'scope_router_id' => $scope_router_id,
            'selected_router_id' => $selected_router_id,
            'selected_router_name' => $selected_router_name,
            'router_options' => $options,
            'can_switch_router' => $is_superadmin || count($options) > 1,
        );
    }

    private function get_active_router_options()
    {
        if (!$this->db->table_exists('routers')) {
            return array();
        }

        $fields = $this->db->list_fields('routers');
        if (!in_array('id', $fields, true)) {
            return array();
        }

        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : 'id');
        $ip_col = in_array('ip_address', $fields, true)
            ? 'ip_address'
            : (in_array('api_host', $fields, true) ? 'api_host' : '');

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS name' . ($ip_col !== '' ? ', ' . $ip_col . ' AS ip_address' : ''), false)
            ->from('routers');

        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('LOWER(status)', 'active');
        }

        return $qb->order_by($name_col, 'ASC')->get()->result_array();
    }

    private function apply_router_filter_for_table($table_name, array $table_fields, $router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !in_array('router_id', $table_fields, true)) {
            return;
        }

        $this->db->where($table_name . '.router_id', $router_id);
    }

    private function apply_month_filter($date_col, array $all_fields, array $range)
    {
        $date_only_columns = array('scheduled_date', 'requested_date', 'date', 'install_date');
        $is_date_only = in_array($date_col, $date_only_columns, true);

        if ($is_date_only) {
            $this->db->where($date_col . ' >=', $range['start_date']);
            $this->db->where($date_col . ' <=', $range['end_date']);
            return;
        }

        $this->db->where($date_col . ' >=', $range['start_datetime']);
        $this->db->where($date_col . ' <=', $range['end_datetime']);
    }

    private function month_range()
    {
        return array(
            'start_date' => date('Y-m-01'),
            'end_date' => date('Y-m-t'),
            'start_datetime' => date('Y-m-01 00:00:00'),
            'end_datetime' => date('Y-m-t 23:59:59'),
        );
    }

    private function first_available_column(array $fields, array $candidates)
    {
        foreach ($candidates as $column) {
            if (in_array($column, $fields, true)) {
                return $column;
            }
        }

        return '';
    }

    private function field_expr($column)
    {
        return '`' . str_replace('`', '', (string) $column) . '`';
    }
}
