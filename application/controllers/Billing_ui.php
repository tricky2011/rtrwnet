<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Billing_ui extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_module_access('billing', 'Akses ditolak. Modul Billing hanya untuk superadmin/admin.');
        $this->load->database();
        $this->load->helper(array('url', 'form'));
    }

    public function index()
    {
        $search = trim((string) $this->input->get('search', true));
        $status = $this->normalize_status_filter((string) $this->input->get('status', true));
        $all_periods = ((string) $this->input->get('all_periods', true) === '1');
        $period = $this->normalize_period_filter((string) $this->input->get('period', true), $all_periods);
        $bw = $this->normalize_bw_filter((string) $this->input->get('bw', true));
        $status_summary = $this->build_status_summary($search, $period);
        $bw_summary = $this->build_bw_summary($search, $status, $period);

        if ($this->db->table_exists('invoices')) {
            $total_rows = $this->build_list_query($search, $status, $period, $bw, true)->count_all_results();
            $pager = $this->init_pagination('billing', $total_rows, 20, 3);
            $rows = $this->build_list_query($search, $status, $period, $bw, false)
                ->limit($pager['per_page'], $pager['offset'])
                ->get()
                ->result_array();

            return $this->load->view('billing/list', array(
                'rows' => $rows,
                'search' => $search,
                'status_filter' => $status,
                'period_filter' => $period,
                'all_periods' => $period === 'all',
                'bw_filter' => $bw,
                'status_summary' => $status_summary,
                'status_options' => $this->status_filter_options(),
                'bw_summary' => $bw_summary,
                'bw_options' => $this->bw_filter_options(),
                'pagination' => $pager['links'],
                'total_rows' => $pager['total_rows'],
                'per_page' => $pager['per_page'],
                'per_page_options' => $this->get_per_page_options(),
                'is_superadmin' => $this->is_superadmin(),
            ));
        }

        $rows = $this->apply_fallback_filters($this->fallback_rows(), $search, $status, $period, $bw);
        $pager = $this->init_pagination('billing', count($rows), 20, 3);
        $paged_rows = array_slice($rows, $pager['offset'], $pager['per_page']);

        return $this->load->view('billing/list', array(
            'rows' => $paged_rows,
            'search' => $search,
            'status_filter' => $status,
            'period_filter' => $period,
            'all_periods' => $period === 'all',
            'bw_filter' => $bw,
            'status_summary' => $status_summary,
            'status_options' => $this->status_filter_options(),
            'bw_summary' => $bw_summary,
            'bw_options' => $this->bw_filter_options(),
            'pagination' => $pager['links'],
            'total_rows' => $pager['total_rows'],
            'per_page' => $pager['per_page'],
            'per_page_options' => $this->get_per_page_options(),
            'is_superadmin' => $this->is_superadmin(),
        ));
    }

    private function normalize_status_filter($status)
    {
        $status = strtolower(trim((string) $status));
        if ($status === 'paid') {
            return 'lunas';
        }
        if ($status === 'unpaid') {
            return 'pending';
        }
        if ($status === 'belum_lunas') {
            return 'pending';
        }
        if ($status === 'cancel') {
            return 'cancel';
        }

        $allowed = array('pending', 'lunas', 'overdue', 'cancel');
        return in_array($status, $allowed, true) ? $status : '';
    }

    private function normalize_period_filter($period, $all_periods = false)
    {
        $period = trim((string) $period);
        if ($all_periods || strtolower($period) === 'all') {
            return 'all';
        }

        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            return $period;
        }

        return date('Y-m');
    }

    private function normalize_bw_filter($bw)
    {
        $bw = strtoupper(preg_replace('/\s+/', '', trim((string) $bw)));
        return in_array($bw, $this->supported_bw_labels(), true) ? $bw : '';
    }

    private function status_filter_options()
    {
        return array(
            '' => 'Semua Status',
            'pending' => 'Belum Lunas',
            'lunas' => 'Lunas',
            'overdue' => 'Overdue',
            'cancel' => 'Cancel',
        );
    }

    private function bw_filter_options()
    {
        return array(
            '' => 'Semua BW',
            '7M' => '7 M',
            '10M' => '10 M',
            '20M' => '20 M',
            '30M' => '30 M',
        );
    }

    private function map_status_key_to_actual($status_key)
    {
        $map = array(
            'pending' => array('draft', 'issued', 'partially_paid'),
            'lunas' => array('paid'),
            'overdue' => array('overdue'),
            'cancel' => array('void'),
        );

        return isset($map[$status_key]) ? $map[$status_key] : array();
    }

    private function supported_bw_labels()
    {
        return array('7M', '10M', '20M', '30M');
    }

    private function standard_bw_price_map()
    {
        return array(
            '7M' => 100000.00,
            '10M' => 140000.00,
            '20M' => 166500.00,
            '30M' => 200000.00,
        );
    }

    private function table_has_column($table, $column)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }

        return in_array((string) $column, $this->db->list_fields($table), true);
    }

    private function get_pppoe_user_options()
    {
        $values = array();

        if ($this->db->table_exists('pppoe_secrets') && $this->table_has_column('pppoe_secrets', 'username')) {
            $qb = $this->db
                ->select('username')
                ->from('pppoe_secrets')
                ->where('username !=', '')
                ->order_by('username', 'ASC')
                ->limit(3000);
            if ($this->table_has_column('pppoe_secrets', 'router_id')) {
                $this->applyRouterFilter(null, $qb);
            }

            $rows = $qb->get()->result_array();

            foreach ($rows as $row) {
                $username = trim((string) ($row['username'] ?? ''));
                if ($username !== '') {
                    $values[$username] = $username;
                }
            }
        }

        if ($this->db->table_exists('customer_services') && $this->table_has_column('customer_services', 'pppoe_username')) {
            $qb = $this->db
                ->select('pppoe_username')
                ->from('customer_services')
                ->where('pppoe_username !=', '')
                ->order_by('pppoe_username', 'ASC')
                ->limit(3000);
            if ($this->table_has_column('customer_services', 'router_id')) {
                $this->applyRouterFilter(null, $qb);
            }

            $rows = $qb->get()->result_array();

            foreach ($rows as $row) {
                $username = trim((string) ($row['pppoe_username'] ?? ''));
                if ($username !== '') {
                    $values[$username] = $username;
                }
            }
        }

        if ($this->db->table_exists('customers')) {
            if ($this->table_has_column('customers', 'pppoe_username')) {
                $rows = $this->db
                    ->select('pppoe_username')
                    ->from('customers')
                    ->where('pppoe_username !=', '')
                    ->order_by('pppoe_username', 'ASC')
                    ->limit(3000)
                    ->get()
                    ->result_array();

                foreach ($rows as $row) {
                    $username = trim((string) ($row['pppoe_username'] ?? ''));
                    if ($username !== '') {
                        $values[$username] = $username;
                    }
                }
            }

            if ($this->table_has_column('customers', 'username')) {
                $rows = $this->db
                    ->select('username')
                    ->from('customers')
                    ->where('username !=', '')
                    ->order_by('username', 'ASC')
                    ->limit(3000)
                    ->get()
                    ->result_array();

                foreach ($rows as $row) {
                    $username = trim((string) ($row['username'] ?? ''));
                    if ($username !== '') {
                        $values[$username] = $username;
                    }
                }
            }
        }

        if (empty($values)) {
            return array();
        }

        $list = array_values($values);
        natcasesort($list);
        return array_values($list);
    }

    private function build_invoice_scope_query($search, $status, $period, $bw = '')
    {
        $customer_exists = $this->db->table_exists('customers');
        $customer_fields = $customer_exists ? $this->db->list_fields('customers') : array();
        $customer_has_full_name = in_array('full_name', $customer_fields, true);
        $customer_has_phone = in_array('phone', $customer_fields, true);
        $customer_has_profile_id = in_array('profile_id', $customer_fields, true);
        $customer_has_pppoe_username = in_array('pppoe_username', $customer_fields, true);
        $customer_has_username = in_array('username', $customer_fields, true);
        $customer_has_package_price = in_array('package_price', $customer_fields, true);

        $invoice_has_customer_service_id = $this->table_has_column('invoices', 'customer_service_id');
        $invoice_has_subtotal = $this->table_has_column('invoices', 'subtotal');

        $service_exists = $this->db->table_exists('customer_services');
        $service_fields = $service_exists ? $this->db->list_fields('customer_services') : array();
        $service_has_id = in_array('id', $service_fields, true);
        $service_has_customer_id = in_array('customer_id', $service_fields, true);
        $service_has_profile_id = in_array('ppp_profile_id', $service_fields, true);
        $service_has_pppoe_username = in_array('pppoe_username', $service_fields, true);
        $service_has_price = in_array('price', $service_fields, true);

        $can_join_latest_service = $customer_exists && $service_exists && $service_has_customer_id;
        $can_join_invoice_service = $service_exists && $service_has_id && $invoice_has_customer_service_id;

        $profile_exists = $this->db->table_exists('ppp_profiles');
        $profile_fields = $profile_exists ? $this->db->list_fields('ppp_profiles') : array();
        $profile_has_name = in_array('name', $profile_fields, true);
        $profile_has_rate_limit = in_array('rate_limit', $profile_fields, true);
        $profile_has_price = in_array('price', $profile_fields, true);

        $qb = $this->db->from('invoices i');
        if ($this->table_has_column('invoices', 'router_id')) {
            $effective_router_id = $this->getEffectiveRouterId();
            if ($effective_router_id !== null) {
                $qb->where('i.router_id', (int) $effective_router_id);
            } elseif (!$this->is_superadmin()) {
                $qb->where('1 = 0', null, false);
            }
        }

        if ($customer_exists) {
            $qb->join('customers c', 'c.id = i.customer_id', 'left');
        }

        if ($can_join_latest_service) {
            $cs_table = $this->db->dbprefix('customer_services');
            $latest_service_select = array('cs1.customer_id');
            if ($service_has_profile_id) {
                $latest_service_select[] = 'cs1.ppp_profile_id';
            }
            if ($service_has_pppoe_username) {
                $latest_service_select[] = 'cs1.pppoe_username';
            }
            if ($service_has_price) {
                $latest_service_select[] = 'cs1.price';
            }
            $sub_latest_service = "(
                SELECT " . implode(', ', $latest_service_select) . "
                FROM {$cs_table} cs1
                INNER JOIN (
                    SELECT customer_id, MAX(id) AS max_id
                    FROM {$cs_table}
                    GROUP BY customer_id
                ) cs2 ON cs2.max_id = cs1.id
            ) cs_last";
            $qb->join($sub_latest_service, 'cs_last.customer_id = c.id', 'left', false);
        }

        if ($can_join_invoice_service) {
            $qb->join('customer_services cs_invoice', 'cs_invoice.id = i.customer_service_id', 'left');
        }

        $has_invoice_profile_join = false;
        if ($profile_exists && $profile_has_name && $can_join_invoice_service && $service_has_profile_id) {
            $qb->join('ppp_profiles p_invoice', 'p_invoice.id = cs_invoice.ppp_profile_id', 'left', false);
            $has_invoice_profile_join = true;
        }

        $current_profile_join_expr = null;
        if ($profile_exists && $profile_has_name) {
            if ($can_join_latest_service && $service_has_profile_id) {
                $current_profile_join_expr = $customer_has_profile_id
                    ? 'COALESCE(cs_last.ppp_profile_id, c.profile_id)'
                    : 'cs_last.ppp_profile_id';
            } elseif ($customer_has_profile_id) {
                $current_profile_join_expr = 'c.profile_id';
            }
        }

        $has_current_profile_join = false;
        if ($current_profile_join_expr !== null) {
            $qb->join('ppp_profiles p_current', 'p_current.id = ' . $current_profile_join_expr, 'left', false);
            $has_current_profile_join = true;
        }

        $actual_statuses = $this->map_status_key_to_actual($status);
        if (!empty($actual_statuses)) {
            $qb->where_in('i.status', $actual_statuses);
        }

        if ($period !== '' && preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period_start = $period . '-01';
            $period_end = date('Y-m-t', strtotime($period_start));
            $qb->where('i.billing_period_start >=', $period_start)
                ->where('i.billing_period_start <=', $period_end);
        }

        if ($search !== '') {
            $qb->group_start();
            $qb->like('i.invoice_number', $search);

            if ($customer_exists) {
                if ($customer_has_full_name) {
                    $qb->or_like('c.full_name', $search);
                }
                if ($customer_has_phone) {
                    $qb->or_like('c.phone', $search);
                }
                if ($customer_has_pppoe_username) {
                    $qb->or_like('c.pppoe_username', $search);
                }
                if ($customer_has_username) {
                    $qb->or_like('c.username', $search);
                }
            }

            if ($can_join_invoice_service && $service_has_pppoe_username) {
                $qb->or_like('cs_invoice.pppoe_username', $search);
            }
            if ($can_join_latest_service && $service_has_pppoe_username) {
                $qb->or_like('cs_last.pppoe_username', $search);
            }

            $qb->group_end();
        }

        $invoice_profile_source_expr = $has_invoice_profile_join
            ? 'UPPER(REPLACE(COALESCE('
                . ($profile_has_name ? "NULLIF(p_invoice.name, '')" : "''")
                . ', '
                . ($profile_has_rate_limit ? "NULLIF(p_invoice.rate_limit, '')" : "''")
                . ", ''), ' ', ''))"
            : "''";
        $current_profile_source_expr = $has_current_profile_join
            ? 'UPPER(REPLACE(COALESCE('
                . ($profile_has_name ? "NULLIF(p_current.name, '')" : "''")
                . ', '
                . ($profile_has_rate_limit ? "NULLIF(p_current.rate_limit, '')" : "''")
                . ", ''), ' ', ''))"
            : "''";
        $invoice_nominal_expr = $invoice_has_subtotal
            ? 'ROUND(COALESCE(NULLIF(i.subtotal, 0), NULLIF(i.total_amount, 0), 0), 2)'
            : 'ROUND(COALESCE(NULLIF(i.total_amount, 0), 0), 2)';

        $fallback_price_candidates = array();
        if ($can_join_invoice_service && $service_has_price) {
            $fallback_price_candidates[] = 'NULLIF(cs_invoice.price, 0)';
        }
        if ($can_join_latest_service && $service_has_price) {
            $fallback_price_candidates[] = 'NULLIF(cs_last.price, 0)';
        }
        if ($customer_exists && $customer_has_package_price) {
            $fallback_price_candidates[] = 'NULLIF(c.package_price, 0)';
        }
        if ($has_current_profile_join && $profile_has_price) {
            $fallback_price_candidates[] = 'NULLIF(p_current.price, 0)';
        }
        $fallback_price_expr = !empty($fallback_price_candidates)
            ? 'ROUND(COALESCE(' . implode(', ', $fallback_price_candidates) . ', 0), 2)'
            : '0';

        $profile_name_parts = array();
        if ($has_invoice_profile_join && $profile_has_name) {
            $profile_name_parts[] = "NULLIF(p_invoice.name, '')";
        }
        if ($has_current_profile_join && $profile_has_name) {
            $profile_name_parts[] = "NULLIF(p_current.name, '')";
        }
        $profile_name_expr = !empty($profile_name_parts)
            ? 'COALESCE(' . implode(', ', $profile_name_parts) . ", '')"
            : "''";

        $service_pppoe_parts = array();
        if ($can_join_invoice_service && $service_has_pppoe_username) {
            $service_pppoe_parts[] = "NULLIF(cs_invoice.pppoe_username, '')";
        }
        if ($can_join_latest_service && $service_has_pppoe_username) {
            $service_pppoe_parts[] = "NULLIF(cs_last.pppoe_username, '')";
        }
        $service_pppoe_expr = !empty($service_pppoe_parts)
            ? 'COALESCE(' . implode(', ', $service_pppoe_parts) . ", '')"
            : "''";

        $bw_case_expr = $this->build_bw_case_expression(
            $invoice_profile_source_expr,
            $invoice_nominal_expr,
            $current_profile_source_expr,
            $fallback_price_expr
        );

        if ($bw !== '') {
            $qb->where('(' . $bw_case_expr . ') = ' . $this->db->escape($bw), null, false);
        }

        return array(
            'qb' => $qb,
            'bw_case_expr' => $bw_case_expr,
            'profile_name_expr' => $profile_name_expr,
            'service_pppoe_expr' => $service_pppoe_expr,
            'meta' => array(
                'customer_exists' => $customer_exists,
                'customer_has_full_name' => $customer_has_full_name,
                'customer_has_phone' => $customer_has_phone,
                'customer_has_pppoe_username' => $customer_has_pppoe_username,
                'customer_has_username' => $customer_has_username,
            ),
        );
    }

    private function build_list_query($search, $status, $period, $bw = '', $count_only = false)
    {
        $scope = $this->build_invoice_scope_query($search, $status, $period, $bw);
        $qb = $scope['qb'];
        $meta = isset($scope['meta']) && is_array($scope['meta']) ? $scope['meta'] : array();

        if (!$count_only) {
            $customer_pppoe_select = !empty($meta['customer_has_pppoe_username'])
                ? 'c.pppoe_username as customer_pppoe_username'
                : "'' as customer_pppoe_username";
            $customer_username_select = !empty($meta['customer_has_username'])
                ? 'c.username as customer_username'
                : "'' as customer_username";
            $customer_name_select = !empty($meta['customer_exists']) && !empty($meta['customer_has_full_name'])
                ? 'c.full_name as customer_name'
                : 'NULL as customer_name';
            $customer_phone_select = !empty($meta['customer_exists']) && !empty($meta['customer_has_phone'])
                ? 'c.phone as customer_phone'
                : 'NULL as customer_phone';

            $qb->select(
                'i.id, i.invoice_number, i.billing_period_start, i.billing_period_end, i.issue_date, i.due_date, i.total_amount, i.paid_amount, i.balance_amount, i.status, i.notes, '
                . $customer_name_select . ', '
                . $customer_phone_select . ', '
                . $customer_pppoe_select . ', '
                . $customer_username_select . ', '
                . $scope['service_pppoe_expr'] . ' as service_pppoe_username, '
                . $scope['profile_name_expr'] . ' as profile_name, '
                . $scope['bw_case_expr'] . ' as bw_label',
                false
            );
            $qb->order_by('i.id', 'DESC');
        }

        return $qb;
    }

    private function build_bw_match_condition($expression, $bw)
    {
        $bw = strtoupper(trim((string) $bw));
        return '(' . $expression . ' = ' . $this->db->escape($bw)
            . ' OR ' . $expression . ' LIKE ' . $this->db->escape($bw . '/%')
            . ' OR ' . $expression . ' LIKE ' . $this->db->escape('%/' . $bw)
            . ' OR ' . $expression . ' LIKE ' . $this->db->escape('%/' . $bw . '/%')
            . ')';
    }

    private function build_bw_case_expression($invoice_profile_expr, $invoice_nominal_expr, $current_profile_expr, $fallback_price_expr)
    {
        $cases = array();
        foreach ($this->supported_bw_labels() as $bw) {
            $cases[] = 'WHEN ' . $this->build_bw_match_condition($invoice_profile_expr, $bw) . ' THEN ' . $this->db->escape($bw);
        }
        foreach ($this->standard_bw_price_map() as $bw => $price) {
            $cases[] = 'WHEN ' . $invoice_nominal_expr . ' = ' . number_format((float) $price, 2, '.', '') . ' THEN ' . $this->db->escape($bw);
        }
        foreach ($this->supported_bw_labels() as $bw) {
            $cases[] = 'WHEN ' . $this->build_bw_match_condition($current_profile_expr, $bw) . ' THEN ' . $this->db->escape($bw);
        }
        foreach ($this->standard_bw_price_map() as $bw => $price) {
            $cases[] = 'WHEN ' . $fallback_price_expr . ' = ' . number_format((float) $price, 2, '.', '') . ' THEN ' . $this->db->escape($bw);
        }

        return 'CASE ' . implode(' ', $cases) . " ELSE '' END";
    }

    private function fallback_rows()
    {
        $rows = array(
            array('id' => 1, 'invoice_number' => 'INV-2026-0201', 'customer_name' => 'Budi Santoso', 'billing_period_start' => '2026-02-01', 'due_date' => '2026-02-10', 'total_amount' => 350000, 'paid_amount' => 0, 'balance_amount' => 350000, 'status' => 'issued'),
            array('id' => 2, 'invoice_number' => 'INV-2026-0202', 'customer_name' => 'Nina Saputri', 'billing_period_start' => '2026-02-01', 'due_date' => '2026-02-10', 'total_amount' => 280000, 'paid_amount' => 0, 'balance_amount' => 280000, 'status' => 'overdue'),
            array('id' => 3, 'invoice_number' => 'INV-2026-0203', 'customer_name' => 'Rizal Pratama', 'billing_period_start' => '2026-02-01', 'due_date' => '2026-02-10', 'total_amount' => 500000, 'paid_amount' => 500000, 'balance_amount' => 0, 'status' => 'paid'),
        );

        foreach ($rows as &$row) {
            $row['profile_name'] = '';
            $row['bw_label'] = $this->resolve_bw_from_inputs('', '', (float) ($row['total_amount'] ?? 0), 0.0);
            $row['service_pppoe_username'] = '';
            $row['customer_pppoe_username'] = '';
            $row['customer_username'] = '';
            $row['customer_phone'] = (string) ($row['customer_phone'] ?? '');
        }
        unset($row);

        return $rows;
    }

    private function build_status_summary($search, $period)
    {
        $summary = array(
            'all' => 0,
            'pending' => 0,
            'lunas' => 0,
            'overdue' => 0,
        );

        if ($this->db->table_exists('invoices')) {
            $summary['all'] = (int) $this->build_list_query($search, '', $period, '', true)->count_all_results();
            $summary['pending'] = (int) $this->build_list_query($search, 'pending', $period, '', true)->count_all_results();
            $summary['lunas'] = (int) $this->build_list_query($search, 'lunas', $period, '', true)->count_all_results();
            $summary['overdue'] = (int) $this->build_list_query($search, 'overdue', $period, '', true)->count_all_results();

            return $summary;
        }

        $rows = $this->apply_fallback_filters($this->fallback_rows(), $search, '', $period, '');
        $summary['all'] = count($rows);

        foreach ($rows as $row) {
            $status_raw = strtolower(trim((string) ($row['status'] ?? '')));
            if (in_array($status_raw, $this->map_status_key_to_actual('pending'), true)) {
                $summary['pending']++;
                continue;
            }
            if (in_array($status_raw, $this->map_status_key_to_actual('lunas'), true)) {
                $summary['lunas']++;
                continue;
            }
            if (in_array($status_raw, $this->map_status_key_to_actual('overdue'), true)) {
                $summary['overdue']++;
            }
        }

        return $summary;
    }

    private function build_bw_summary($search, $status, $period)
    {
        $summary = array();
        foreach ($this->supported_bw_labels() as $bw) {
            $summary[$bw] = array(
                'bw' => $bw,
                'customer_total' => 0,
                'total_amount' => 0.0,
            );
        }

        if ($this->db->table_exists('invoices')) {
            $scope = $this->build_invoice_scope_query($search, $status, $period, '');
            $rows = $scope['qb']
                ->select($scope['bw_case_expr'] . ' AS bw_label', false)
                ->select('COUNT(DISTINCT i.customer_id) AS customer_total', false)
                ->select('COALESCE(SUM(i.total_amount), 0) AS total_amount', false)
                ->group_by($scope['bw_case_expr'], false)
                ->get()
                ->result_array();

            foreach ($rows as $row) {
                $bw = strtoupper(trim((string) ($row['bw_label'] ?? '')));
                if (!isset($summary[$bw])) {
                    continue;
                }

                $summary[$bw]['customer_total'] = (int) ($row['customer_total'] ?? 0);
                $summary[$bw]['total_amount'] = (float) ($row['total_amount'] ?? 0);
            }

            return $summary;
        }

        $rows = $this->apply_fallback_filters($this->fallback_rows(), $search, $status, $period, '');
        foreach ($rows as $row) {
            $bw = strtoupper(trim((string) ($row['bw_label'] ?? '')));
            if (!isset($summary[$bw])) {
                continue;
            }

            $summary[$bw]['customer_total']++;
            $summary[$bw]['total_amount'] += (float) ($row['total_amount'] ?? 0);
        }

        return $summary;
    }

    private function resolve_bw_from_profile_string($profile)
    {
        $profile = strtoupper(preg_replace('/\s+/', '', trim((string) $profile)));
        if ($profile === '') {
            return '';
        }

        foreach ($this->supported_bw_labels() as $bw) {
            if ($profile === $bw
                || strpos($profile, $bw . '/') === 0
                || strpos($profile, '/' . $bw) !== false
                || substr($profile, -strlen($bw)) === $bw
            ) {
                return $bw;
            }
        }

        return '';
    }

    private function resolve_bw_from_price($amount)
    {
        $amount = round((float) $amount, 2);
        foreach ($this->standard_bw_price_map() as $bw => $price) {
            if (abs($amount - (float) $price) < 0.01) {
                return $bw;
            }
        }

        return '';
    }

    private function resolve_bw_from_inputs($profile_name, $profile_rate_limit, $invoice_amount, $fallback_price = 0.0)
    {
        $bw = $this->resolve_bw_from_profile_string($profile_name);
        if ($bw !== '') {
            return $bw;
        }

        $bw = $this->resolve_bw_from_profile_string($profile_rate_limit);
        if ($bw !== '') {
            return $bw;
        }

        $bw = $this->resolve_bw_from_price($invoice_amount);
        if ($bw !== '') {
            return $bw;
        }

        return $this->resolve_bw_from_price($fallback_price);
    }

    private function apply_fallback_filters(array $rows, $search, $status, $period, $bw = '')
    {
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static function ($row) use ($search) {
                $needle = strtolower($search);
                return strpos(strtolower((string) ($row['invoice_number'] ?? '')), $needle) !== false
                    || strpos(strtolower((string) ($row['customer_name'] ?? '')), $needle) !== false;
            }));
        }

        if ($status !== '') {
            $rows = array_values(array_filter($rows, function ($row) use ($status) {
                $status_raw = strtolower((string) ($row['status_label'] ?? $row['status'] ?? ''));
                return in_array($status_raw, $this->map_status_key_to_actual($status), true);
            }));
        }

        if ($period !== '' && preg_match('/^\d{4}-\d{2}$/', $period)) {
            $rows = array_values(array_filter($rows, static function ($row) use ($period) {
                $period_start = (string) ($row['billing_period_start'] ?? '');
                if ($period_start !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_start)) {
                    return date('Y-m', strtotime($period_start)) === $period;
                }

                return false;
            }));
        }

        if ($bw !== '') {
            $rows = array_values(array_filter($rows, static function ($row) use ($bw) {
                return strtoupper(trim((string) ($row['bw_label'] ?? ''))) === $bw;
            }));
        }

        return $rows;
    }
}
