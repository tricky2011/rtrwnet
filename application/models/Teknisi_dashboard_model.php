<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Teknisi_dashboard_model extends CI_Model
{
    const DEFAULT_TARGET_INSTALLATION = 30;
    const DEFAULT_TARGET_TICKET = 50;

    const POINT_WO_DONE = 10;
    const POINT_TICKET_DONE = 5;
    const POINT_TICKET_PENDING = -2;

    private $table_work_orders = 'work_orders';
    private $table_tickets = 'tickets';
    private $table_users = 'users';
    private $table_customers = 'customers';
    private $table_customer_services = 'customer_services';
    private $table_ppp_profiles = 'ppp_profiles';

    private $wo_fields = array();
    private $ticket_fields = array();
    private $user_fields = array();
    private $customer_fields = array();
    private $customer_service_fields = array();
    private $ppp_profile_fields = array();
    private $router_scope_id = null;
    private $is_superadmin_view = false;

    public function __construct()
    {
        parent::__construct();

        if ($this->db->table_exists($this->table_work_orders)) {
            $this->wo_fields = $this->db->list_fields($this->table_work_orders);
        }
        if ($this->db->table_exists($this->table_tickets)) {
            $this->ticket_fields = $this->db->list_fields($this->table_tickets);
        }
        if ($this->db->table_exists($this->table_users)) {
            $this->user_fields = $this->db->list_fields($this->table_users);
        }
        if ($this->db->table_exists($this->table_customers)) {
            $this->customer_fields = $this->db->list_fields($this->table_customers);
        }
        if ($this->db->table_exists($this->table_customer_services)) {
            $this->customer_service_fields = $this->db->list_fields($this->table_customer_services);
        }
        if ($this->db->table_exists($this->table_ppp_profiles)) {
            $this->ppp_profile_fields = $this->db->list_fields($this->table_ppp_profiles);
        }
    }

    public function set_router_scope($router_id = null, $is_superadmin = false)
    {
        $router_id = (int) $router_id;
        $this->router_scope_id = $router_id > 0 ? $router_id : null;
        $this->is_superadmin_view = (bool) $is_superadmin;
    }

    public function get_points_rule()
    {
        return array(
            'wo_done' => self::POINT_WO_DONE,
            'ticket_done' => self::POINT_TICKET_DONE,
            'ticket_pending' => self::POINT_TICKET_PENDING,
        );
    }

    public function get_teknisi_options()
    {
        if (!$this->db->table_exists($this->table_users) || !$this->has_user_field('id') || !$this->has_user_field('role')) {
            return array();
        }

        $name_col = $this->resolve_user_name_column();
        if ($name_col === '') {
            return array();
        }

        $qb = $this->db
            ->from($this->table_users)
            ->select('id')
            ->select($this->field_expr('', $name_col) . ' AS name', false)
            ->where('role', 'teknisi');

        if ($this->has_user_field('status')) {
            $qb->where('status', 'active');
        }
        $this->apply_user_router_scope($qb);

        return $qb
            ->order_by($name_col, 'ASC')
            ->get()
            ->result_array();
    }

    public function normalize_filters(array $filters, $viewer_role, $viewer_user_id, $router_id = null)
    {
        $month = isset($filters['month']) ? (int) $filters['month'] : (int) date('m');
        $year = isset($filters['year']) ? (int) $filters['year'] : (int) date('Y');
        $period = strtolower(trim((string) ($filters['period'] ?? 'month')));
        $start_date = $this->normalize_date((string) ($filters['start_date'] ?? ''));
        $end_date = $this->normalize_date((string) ($filters['end_date'] ?? ''));
        $technician_id = isset($filters['technician_id']) ? (int) $filters['technician_id'] : 0;
        $target_installation = isset($filters['target_installation']) ? (int) $filters['target_installation'] : self::DEFAULT_TARGET_INSTALLATION;
        $target_ticket = isset($filters['target_ticket']) ? (int) $filters['target_ticket'] : self::DEFAULT_TARGET_TICKET;

        if ($month < 1 || $month > 12) {
            $month = (int) date('m');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }
        if (!in_array($period, array('today', 'week', 'month'), true)) {
            $period = 'month';
        }

        if ($start_date === '' || $end_date === '') {
            $start_date = date('Y-m-01', strtotime($year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-01'));
            $end_date = date('Y-m-t', strtotime($start_date));
        }

        if (strtotime($start_date) === false) {
            $start_date = date('Y-m-01');
        }
        if (strtotime($end_date) === false) {
            $end_date = date('Y-m-t');
        }
        if (strtotime($start_date) > strtotime($end_date)) {
            $tmp = $start_date;
            $start_date = $end_date;
            $end_date = $tmp;
        }

        $viewer_role = strtolower(trim((string) $viewer_role));
        $viewer_user_id = (int) $viewer_user_id;
        if ($viewer_role === 'teknisi') {
            $technician_id = $viewer_user_id;
        } elseif ($technician_id < 0) {
            $technician_id = 0;
        }

        if ($target_installation < 1) {
            $target_installation = self::DEFAULT_TARGET_INSTALLATION;
        }
        if ($target_ticket < 1) {
            $target_ticket = self::DEFAULT_TARGET_TICKET;
        }

        $router_id = (int) $router_id;
        if ($router_id > 0) {
            $this->router_scope_id = $router_id;
        }

        return array(
            'month' => $month,
            'year' => $year,
            'period' => $period,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'start_datetime' => $start_date . ' 00:00:00',
            'end_datetime' => $end_date . ' 23:59:59',
            'technician_id' => $technician_id,
            'target_installation' => $target_installation,
            'target_ticket' => $target_ticket,
            'viewer_role' => $viewer_role,
            'viewer_user_id' => $viewer_user_id,
            'router_id' => $this->router_scope_id !== null ? (int) $this->router_scope_id : 0,
        );
    }

    public function get_dashboard_payload(array $ctx)
    {
        $kpi = $this->get_kpi_summary($ctx);
        $targets = $this->get_target_progress($ctx, $kpi);
        $wo_chart = $this->get_work_order_weekly_chart($ctx);
        $ticket_chart = $this->get_ticket_trend_chart($ctx);
        $work_orders = $this->get_work_order_details($ctx, 120);
        $tickets = $this->get_ticket_details($ctx, 120);
        $ranking = $this->get_technician_ranking($ctx, 10);

        $top_rank = !empty($ranking) ? $ranking[0] : array();
        $selected_technician_name = $this->resolve_selected_technician_name($ctx['technician_id']);

        return array(
            'kpi' => $kpi,
            'targets' => $targets,
            'charts' => array(
                'work_order' => $wo_chart,
                'ticket' => $ticket_chart,
            ),
            'work_order_rows' => $work_orders,
            'ticket_rows' => $tickets,
            'ranking_rows' => $ranking,
            'top_rank' => $top_rank,
            'selected_technician_name' => $selected_technician_name,
            'points_rule' => $this->get_points_rule(),
        );
    }

    private function get_kpi_summary(array $ctx)
    {
        $result = array(
            'total_wo' => 0,
            'wo_done' => 0,
            'wo_done_percent' => 0.0,
            'ticket_total' => 0,
            'ticket_done' => 0,
            'ticket_pending' => 0,
            'ticket_done_percent' => 0.0,
            'total_points' => 0,
        );

        $wo_total = 0;
        $wo_done = 0;

        if ($this->db->table_exists($this->table_work_orders)) {
            $wo_status_col = $this->resolve_wo_status_column();
            $wo_assignee_col = $this->resolve_wo_assignee_column();
            $wo_date_col = $this->resolve_wo_filter_date_column();
            $wo_type_col = $this->resolve_wo_type_column();

            $done_condition = $wo_status_col !== ''
                ? 'LOWER(' . $this->field_expr('w', $wo_status_col) . ') IN (' . $this->escaped_in_list($this->wo_done_statuses()) . ')'
                : '0 = 1';

            $this->db
                ->from($this->table_work_orders . ' w')
                ->select('COUNT(*) AS total_wo', false)
                ->select('SUM(CASE WHEN ' . $done_condition . ' THEN 1 ELSE 0 END) AS wo_done', false);

            if ($wo_type_col !== '') {
                $this->db->where('LOWER(' . $this->field_expr('w', $wo_type_col) . ') IN (' . $this->escaped_in_list(array('installation', 'instalasi')) . ')', null, false);
            }

            $this->apply_date_range($this->db, 'w', $wo_date_col, $ctx['start_datetime'], $ctx['end_datetime']);
            $this->apply_technician_filter($this->db, 'w', $wo_assignee_col, (int) $ctx['technician_id']);
            $this->apply_router_scope($this->db, 'w', $this->wo_fields);

            $row = $this->db->get()->row_array();
            $wo_total = (int) ($row['total_wo'] ?? 0);
            $wo_done = (int) ($row['wo_done'] ?? 0);
        }

        $ticket_total = 0;
        $ticket_done = 0;
        $ticket_pending = 0;

        if ($this->db->table_exists($this->table_tickets)) {
            $ticket_status_col = $this->resolve_ticket_status_column();
            $ticket_assignee_col = $this->resolve_ticket_assignee_column();
            $ticket_date_col = $this->resolve_ticket_opened_column();

            $resolved_condition = $ticket_status_col !== ''
                ? 'LOWER(' . $this->field_expr('t', $ticket_status_col) . ') IN (' . $this->escaped_in_list($this->ticket_resolved_statuses()) . ')'
                : '0 = 1';
            $pending_condition = $ticket_status_col !== ''
                ? 'LOWER(' . $this->field_expr('t', $ticket_status_col) . ') IN (' . $this->escaped_in_list($this->ticket_pending_statuses()) . ')'
                : '0 = 1';

            $this->db
                ->from($this->table_tickets . ' t')
                ->select('COUNT(*) AS total_ticket', false)
                ->select('SUM(CASE WHEN ' . $resolved_condition . ' THEN 1 ELSE 0 END) AS total_done', false)
                ->select('SUM(CASE WHEN ' . $pending_condition . ' THEN 1 ELSE 0 END) AS total_pending', false);

            $this->apply_date_range($this->db, 't', $ticket_date_col, $ctx['start_datetime'], $ctx['end_datetime']);
            $this->apply_technician_filter($this->db, 't', $ticket_assignee_col, (int) $ctx['technician_id']);
            $this->apply_router_scope($this->db, 't', $this->ticket_fields);

            $row = $this->db->get()->row_array();
            $ticket_total = (int) ($row['total_ticket'] ?? 0);
            $ticket_done = (int) ($row['total_done'] ?? 0);
            $ticket_pending = (int) ($row['total_pending'] ?? 0);
        }

        $total_points = $this->calculate_points($wo_done, $ticket_done, $ticket_pending);

        $result['total_wo'] = $wo_total;
        $result['wo_done'] = $wo_done;
        $result['wo_done_percent'] = $wo_total > 0 ? round(($wo_done / $wo_total) * 100, 2) : 0.0;
        $result['ticket_total'] = $ticket_total;
        $result['ticket_done'] = $ticket_done;
        $result['ticket_pending'] = $ticket_pending;
        $result['ticket_done_percent'] = $ticket_total > 0 ? round(($ticket_done / $ticket_total) * 100, 2) : 0.0;
        $result['total_points'] = $total_points;

        return $result;
    }

    private function get_target_progress(array $ctx, array $kpi)
    {
        $target_installation = (int) $ctx['target_installation'];
        $target_ticket = (int) $ctx['target_ticket'];
        $real_installation = (int) ($kpi['wo_done'] ?? 0);
        $real_ticket = (int) ($kpi['ticket_done'] ?? 0);

        $install_percent = $target_installation > 0 ? round(($real_installation / $target_installation) * 100, 2) : 0;
        $ticket_percent = $target_ticket > 0 ? round(($real_ticket / $target_ticket) * 100, 2) : 0;

        return array(
            'target_installation' => $target_installation,
            'real_installation' => $real_installation,
            'installation_percent' => $install_percent,
            'target_ticket' => $target_ticket,
            'real_ticket' => $real_ticket,
            'ticket_percent' => $ticket_percent,
        );
    }

    private function get_work_order_weekly_chart(array $ctx)
    {
        $labels = array('Minggu 1', 'Minggu 2', 'Minggu 3', 'Minggu 4', 'Minggu 5');
        $values = array(0, 0, 0, 0, 0);

        if (!$this->db->table_exists($this->table_work_orders)) {
            return array('labels' => $labels, 'values' => $values);
        }

        $wo_status_col = $this->resolve_wo_status_column();
        $wo_assignee_col = $this->resolve_wo_assignee_column();
        $wo_type_col = $this->resolve_wo_type_column();
        $chart_date_col = $this->resolve_wo_chart_date_column();

        if ($wo_status_col === '' || $chart_date_col === '') {
            return array('labels' => $labels, 'values' => $values);
        }

        $status_condition = 'LOWER(' . $this->field_expr('w', $wo_status_col) . ') IN (' . $this->escaped_in_list($this->wo_done_statuses()) . ')';
        $date_expr = $this->field_expr('w', $chart_date_col);
        $week_expr = 'LEAST(5, CEIL(DAY(' . $date_expr . ') / 7))';

        $this->db
            ->from($this->table_work_orders . ' w')
            ->select($week_expr . ' AS week_no', false)
            ->select('COUNT(*) AS total', false)
            ->where($status_condition, null, false)
            ->where('MONTH(' . $date_expr . ') =', (int) $ctx['month'], false)
            ->where('YEAR(' . $date_expr . ') =', (int) $ctx['year'], false)
            ->group_by('week_no')
            ->order_by('week_no', 'ASC');

        if ($wo_type_col !== '') {
            $this->db->where('LOWER(' . $this->field_expr('w', $wo_type_col) . ') IN (' . $this->escaped_in_list(array('installation', 'instalasi')) . ')', null, false);
        }

        $this->apply_technician_filter($this->db, 'w', $wo_assignee_col, (int) $ctx['technician_id']);
        $this->apply_router_scope($this->db, 'w', $this->wo_fields);

        $rows = $this->db->get()->result_array();
        foreach ($rows as $row) {
            $week_no = (int) ($row['week_no'] ?? 0);
            if ($week_no >= 1 && $week_no <= 5) {
                $values[$week_no - 1] = (int) ($row['total'] ?? 0);
            }
        }

        return array(
            'labels' => $labels,
            'values' => $values,
        );
    }

    private function get_ticket_trend_chart(array $ctx)
    {
        $range = $this->resolve_ticket_period_range($ctx['period'], (int) $ctx['month'], (int) $ctx['year']);
        $labels = $range['labels'];
        $incoming = array_fill(0, count($labels), 0);
        $resolved = array_fill(0, count($labels), 0);
        $pending = array_fill(0, count($labels), 0);

        if (!$this->db->table_exists($this->table_tickets)) {
            return array(
                'labels' => $labels,
                'incoming' => $incoming,
                'resolved' => $resolved,
                'pending' => $pending,
            );
        }

        $ticket_status_col = $this->resolve_ticket_status_column();
        $ticket_assignee_col = $this->resolve_ticket_assignee_column();
        $opened_col = $this->resolve_ticket_opened_column();

        if ($opened_col === '' || $ticket_status_col === '') {
            return array(
                'labels' => $labels,
                'incoming' => $incoming,
                'resolved' => $resolved,
                'pending' => $pending,
            );
        }

        $opened_expr = $this->field_expr('t', $opened_col);
        $bucket_expr = $range['bucket'] === 'hour'
            ? 'HOUR(' . $opened_expr . ')'
            : 'DATE(' . $opened_expr . ')';
        $bucket_alias = 'bucket_key';

        $resolved_condition = 'LOWER(' . $this->field_expr('t', $ticket_status_col) . ') IN (' . $this->escaped_in_list($this->ticket_resolved_statuses()) . ')';
        $pending_condition = 'LOWER(' . $this->field_expr('t', $ticket_status_col) . ') IN (' . $this->escaped_in_list($this->ticket_pending_statuses()) . ')';

        $this->db
            ->from($this->table_tickets . ' t')
            ->select($bucket_expr . ' AS ' . $bucket_alias, false)
            ->select('COUNT(*) AS incoming_total', false)
            ->select('SUM(CASE WHEN ' . $resolved_condition . ' THEN 1 ELSE 0 END) AS resolved_total', false)
            ->select('SUM(CASE WHEN ' . $pending_condition . ' THEN 1 ELSE 0 END) AS pending_total', false)
            ->where($opened_expr . ' >= ' . $this->db->escape((string) $range['start_datetime']), null, false)
            ->where($opened_expr . ' <= ' . $this->db->escape((string) $range['end_datetime']), null, false)
            ->group_by($bucket_alias)
            ->order_by($bucket_alias, 'ASC');

        $this->apply_technician_filter($this->db, 't', $ticket_assignee_col, (int) $ctx['technician_id']);
        $this->apply_router_scope($this->db, 't', $this->ticket_fields);

        $rows = $this->db->get()->result_array();
        $map = array();
        foreach ($rows as $row) {
            $map[(string) ($row[$bucket_alias] ?? '')] = array(
                'incoming' => (int) ($row['incoming_total'] ?? 0),
                'resolved' => (int) ($row['resolved_total'] ?? 0),
                'pending' => (int) ($row['pending_total'] ?? 0),
            );
        }

        foreach ($labels as $index => $label) {
            $bucket_key = $range['bucket'] === 'hour'
                ? (string) $index
                : $range['bucket_values'][$index];

            if (isset($map[$bucket_key])) {
                $incoming[$index] = $map[$bucket_key]['incoming'];
                $resolved[$index] = $map[$bucket_key]['resolved'];
                $pending[$index] = $map[$bucket_key]['pending'];
            }
        }

        return array(
            'labels' => $labels,
            'incoming' => $incoming,
            'resolved' => $resolved,
            'pending' => $pending,
        );
    }

    private function get_work_order_details(array $ctx, $limit = 100)
    {
        if (!$this->db->table_exists($this->table_work_orders)) {
            return array();
        }

        $limit = max(1, (int) $limit);

        $wo_status_col = $this->resolve_wo_status_column();
        $wo_assignee_col = $this->resolve_wo_assignee_column();
        $wo_type_col = $this->resolve_wo_type_column();
        $wo_date_col = $this->resolve_wo_filter_date_column();

        $this->db->from($this->table_work_orders . ' w');
        $this->db->select('w.id');

        if ($this->has_wo_field('wo_number')) {
            $this->db->select($this->field_expr('w', 'wo_number') . ' AS wo_number', false);
        } else {
            $this->db->select("CONCAT('WO#', w.id) AS wo_number", false);
        }

        if ($wo_status_col !== '') {
            $this->db->select($this->field_expr('w', $wo_status_col) . ' AS status', false);
        } else {
            $this->db->select("'open' AS status", false);
        }

        if ($wo_date_col !== '') {
            $this->db->select($this->field_expr('w', $wo_date_col) . ' AS work_date', false);
        } else {
            $this->db->select('NULL AS work_date', false);
        }

        $start_expr = $this->resolve_wo_start_time_expr('w');
        $end_expr = $this->resolve_wo_end_time_expr('w');
        if ($start_expr !== '') {
            $this->db->select($start_expr . ' AS start_time', false);
        } else {
            $this->db->select('NULL AS start_time', false);
        }
        if ($end_expr !== '') {
            $this->db->select($end_expr . ' AS end_time', false);
        } else {
            $this->db->select('NULL AS end_time', false);
        }

        if ($this->has_wo_field('package_name')) {
            $this->db->select($this->field_expr('w', 'package_name') . ' AS wo_package_name', false);
        } else {
            $this->db->select("'' AS wo_package_name", false);
        }

        if ($this->has_wo_field('customer_id') && $this->db->table_exists($this->table_customers)) {
            $customer_name_col = $this->resolve_customer_name_column();
            if ($customer_name_col !== '') {
                $this->db->select($this->field_expr('c', $customer_name_col) . ' AS customer_name', false);
            } else {
                $this->db->select("'' AS customer_name", false);
            }
            $this->db->join($this->table_customers . ' c', 'c.id = w.customer_id', 'left');
        } else {
            $this->db->select("'' AS customer_name", false);
        }

        $can_join_profile = $this->db->table_exists($this->table_customer_services)
            && $this->db->table_exists($this->table_ppp_profiles)
            && in_array('customer_id', $this->customer_service_fields, true)
            && in_array('id', $this->customer_service_fields, true)
            && in_array('ppp_profile_id', $this->customer_service_fields, true)
            && in_array('id', $this->ppp_profile_fields, true)
            && in_array('name', $this->ppp_profile_fields, true)
            && $this->has_wo_field('customer_id');

        if ($can_join_profile) {
            $latest_service_subquery = "(
                SELECT cs1.*
                FROM " . $this->table_customer_services . " cs1
                INNER JOIN (
                    SELECT customer_id, MAX(id) AS max_id
                    FROM " . $this->table_customer_services . "
                    GROUP BY customer_id
                ) cs2 ON cs2.max_id = cs1.id
            ) cs";
            $this->db->join($latest_service_subquery, 'cs.customer_id = w.customer_id', 'left', false);
            $this->db->join($this->table_ppp_profiles . ' p', 'p.id = cs.ppp_profile_id', 'left');
            $this->db->select($this->field_expr('p', 'name') . ' AS profile_package_name', false);
        } else {
            $this->db->select("'' AS profile_package_name", false);
        }

        if ($wo_type_col !== '') {
            $this->db->where('LOWER(' . $this->field_expr('w', $wo_type_col) . ') IN (' . $this->escaped_in_list(array('installation', 'instalasi')) . ')', null, false);
        }

        $this->apply_date_range($this->db, 'w', $wo_date_col, $ctx['start_datetime'], $ctx['end_datetime']);
        $this->apply_technician_filter($this->db, 'w', $wo_assignee_col, (int) $ctx['technician_id']);
        $this->apply_router_scope($this->db, 'w', $this->wo_fields);

        $order_col = $wo_date_col !== '' ? $this->field_expr('w', $wo_date_col) : 'w.id';
        $rows = $this->db
            ->order_by($order_col, 'DESC', false)
            ->limit($limit)
            ->get()
            ->result_array();

        $result = array();
        foreach ($rows as $row) {
            $status_raw = (string) ($row['status'] ?? '');
            $status_key = strtolower($status_raw);
            $display_status = $this->normalize_wo_status_label($status_raw);

            $customer_name = trim((string) ($row['customer_name'] ?? ''));
            if ($customer_name === '') {
                $customer_name = '-';
            }

            $package_name = trim((string) ($row['wo_package_name'] ?? ''));
            if ($package_name === '') {
                $package_name = trim((string) ($row['profile_package_name'] ?? ''));
            }
            if ($package_name === '') {
                $package_name = '-';
            }

            $result[] = array(
                'wo_number' => (string) ($row['wo_number'] ?? ('WO#' . (int) ($row['id'] ?? 0))),
                'work_date' => $this->format_datetime((string) ($row['work_date'] ?? '')),
                'customer_name' => $customer_name,
                'package_name' => $package_name,
                'status' => $display_status,
                'status_key' => $status_key,
                'work_duration' => $this->build_duration_label((string) ($row['start_time'] ?? ''), (string) ($row['end_time'] ?? '')),
            );
        }

        return $result;
    }

    private function get_ticket_details(array $ctx, $limit = 100)
    {
        if (!$this->db->table_exists($this->table_tickets)) {
            return array();
        }

        $limit = max(1, (int) $limit);
        $ticket_status_col = $this->resolve_ticket_status_column();
        $ticket_assignee_col = $this->resolve_ticket_assignee_column();
        $ticket_opened_col = $this->resolve_ticket_opened_column();
        $ticket_resolved_expr = $this->resolve_ticket_resolved_expr('t');

        $this->db->from($this->table_tickets . ' t');
        $this->db->select('t.id');

        $ticket_number_col = $this->resolve_ticket_number_column();
        if ($ticket_number_col !== '') {
            $this->db->select($this->field_expr('t', $ticket_number_col) . ' AS ticket_number', false);
        } else {
            $this->db->select("CONCAT('TICKET#', t.id) AS ticket_number", false);
        }

        if ($ticket_status_col !== '') {
            $this->db->select($this->field_expr('t', $ticket_status_col) . ' AS status', false);
        } else {
            $this->db->select("'open' AS status", false);
        }

        $issue_col = $this->resolve_ticket_issue_column();
        if ($issue_col !== '') {
            $this->db->select($this->field_expr('t', $issue_col) . ' AS issue_type', false);
        } else {
            $this->db->select("'Gangguan' AS issue_type", false);
        }

        if ($this->has_ticket_field('sla_deadline')) {
            $this->db->select($this->field_expr('t', 'sla_deadline') . ' AS sla_deadline', false);
        } else {
            $this->db->select('NULL AS sla_deadline', false);
        }

        if ($ticket_opened_col !== '') {
            $this->db->select($this->field_expr('t', $ticket_opened_col) . ' AS opened_at', false);
        } else {
            $this->db->select('NULL AS opened_at', false);
        }

        if ($ticket_resolved_expr !== '') {
            $this->db->select($ticket_resolved_expr . ' AS resolved_at', false);
        } else {
            $this->db->select('NULL AS resolved_at', false);
        }

        if ($this->has_ticket_field('customer_id') && $this->db->table_exists($this->table_customers)) {
            $customer_name_col = $this->resolve_customer_name_column();
            if ($customer_name_col !== '') {
                $this->db->select($this->field_expr('c', $customer_name_col) . ' AS customer_name', false);
            } else {
                $this->db->select("'' AS customer_name", false);
            }
            $this->db->join($this->table_customers . ' c', 'c.id = t.customer_id', 'left');
        } else {
            $this->db->select("'' AS customer_name", false);
        }

        $this->apply_date_range($this->db, 't', $ticket_opened_col, $ctx['start_datetime'], $ctx['end_datetime']);
        $this->apply_technician_filter($this->db, 't', $ticket_assignee_col, (int) $ctx['technician_id']);
        $this->apply_router_scope($this->db, 't', $this->ticket_fields);

        $order_col = $ticket_opened_col !== '' ? $this->field_expr('t', $ticket_opened_col) : 't.id';
        $rows = $this->db
            ->order_by($order_col, 'DESC', false)
            ->limit($limit)
            ->get()
            ->result_array();

        $result = array();
        foreach ($rows as $row) {
            $status_raw = (string) ($row['status'] ?? '');
            $status_key = strtolower($status_raw);
            $opened_at = (string) ($row['opened_at'] ?? '');
            $resolved_at = (string) ($row['resolved_at'] ?? '');

            $result[] = array(
                'ticket_number' => (string) ($row['ticket_number'] ?? ('TICKET#' . (int) ($row['id'] ?? 0))),
                'customer_name' => trim((string) ($row['customer_name'] ?? '')) !== '' ? (string) $row['customer_name'] : '-',
                'issue_type' => trim((string) ($row['issue_type'] ?? '')) !== '' ? (string) $row['issue_type'] : '-',
                'sla_deadline' => $this->format_datetime((string) ($row['sla_deadline'] ?? '')),
                'status' => $this->normalize_ticket_status_label($status_raw),
                'status_key' => $status_key,
                'duration' => $this->build_ticket_duration_label($status_key, $opened_at, $resolved_at),
            );
        }

        return $result;
    }

    private function get_technician_ranking(array $ctx, $limit = 10)
    {
        $limit = max(1, (int) $limit);

        $ranking = array();
        $teknisi_options = $this->get_teknisi_options();
        foreach ($teknisi_options as $tech) {
            $tech_id = (int) ($tech['id'] ?? 0);
            if ($tech_id <= 0) {
                continue;
            }
            if ((int) $ctx['technician_id'] > 0 && $tech_id !== (int) $ctx['technician_id']) {
                continue;
            }

            $ranking[$tech_id] = array(
                'technician_id' => $tech_id,
                'technician_name' => (string) ($tech['name'] ?? ('Teknisi #' . $tech_id)),
                'wo_done' => 0,
                'ticket_done' => 0,
                'ticket_pending' => 0,
                'avg_resolve_minutes' => null,
                'total_points' => 0,
            );
        }

        if (empty($ranking) && (int) $ctx['technician_id'] > 0) {
            $tech_id = (int) $ctx['technician_id'];
            $ranking[$tech_id] = array(
                'technician_id' => $tech_id,
                'technician_name' => $this->resolve_selected_technician_name($tech_id),
                'wo_done' => 0,
                'ticket_done' => 0,
                'ticket_pending' => 0,
                'avg_resolve_minutes' => null,
                'total_points' => 0,
            );
        }

        $wo_assignee_col = $this->resolve_wo_assignee_column();
        $wo_status_col = $this->resolve_wo_status_column();
        $wo_type_col = $this->resolve_wo_type_column();
        $wo_date_col = $this->resolve_wo_filter_date_column();
        if ($this->db->table_exists($this->table_work_orders) && $wo_assignee_col !== '' && $wo_status_col !== '') {
            $done_condition = 'LOWER(' . $this->field_expr('w', $wo_status_col) . ') IN (' . $this->escaped_in_list($this->wo_done_statuses()) . ')';

            $this->db
                ->from($this->table_work_orders . ' w')
                ->select($this->field_expr('w', $wo_assignee_col) . ' AS technician_id', false)
                ->select('SUM(CASE WHEN ' . $done_condition . ' THEN 1 ELSE 0 END) AS wo_done', false)
                ->where($this->field_expr('w', $wo_assignee_col) . ' IS NOT NULL', null, false)
                ->group_by($this->field_expr('w', $wo_assignee_col));

            if ($wo_type_col !== '') {
                $this->db->where('LOWER(' . $this->field_expr('w', $wo_type_col) . ') IN (' . $this->escaped_in_list(array('installation', 'instalasi')) . ')', null, false);
            }

            $this->apply_date_range($this->db, 'w', $wo_date_col, $ctx['start_datetime'], $ctx['end_datetime']);
            $this->apply_technician_filter($this->db, 'w', $wo_assignee_col, (int) $ctx['technician_id']);
            $this->apply_router_scope($this->db, 'w', $this->wo_fields);

            $rows = $this->db->get()->result_array();
            foreach ($rows as $row) {
                $tech_id = (int) ($row['technician_id'] ?? 0);
                if ($tech_id <= 0) {
                    continue;
                }
                if (!isset($ranking[$tech_id])) {
                    $ranking[$tech_id] = array(
                        'technician_id' => $tech_id,
                        'technician_name' => 'Teknisi #' . $tech_id,
                        'wo_done' => 0,
                        'ticket_done' => 0,
                        'ticket_pending' => 0,
                        'avg_resolve_minutes' => null,
                        'total_points' => 0,
                    );
                }
                $ranking[$tech_id]['wo_done'] = (int) ($row['wo_done'] ?? 0);
            }
        }

        $ticket_assignee_col = $this->resolve_ticket_assignee_column();
        $ticket_status_col = $this->resolve_ticket_status_column();
        $ticket_opened_col = $this->resolve_ticket_opened_column();
        $ticket_resolved_expr = $this->resolve_ticket_resolved_expr('t');
        if ($this->db->table_exists($this->table_tickets) && $ticket_assignee_col !== '' && $ticket_status_col !== '') {
            $resolved_condition = 'LOWER(' . $this->field_expr('t', $ticket_status_col) . ') IN (' . $this->escaped_in_list($this->ticket_resolved_statuses()) . ')';
            $pending_condition = 'LOWER(' . $this->field_expr('t', $ticket_status_col) . ') IN (' . $this->escaped_in_list($this->ticket_pending_statuses()) . ')';

            $this->db
                ->from($this->table_tickets . ' t')
                ->select($this->field_expr('t', $ticket_assignee_col) . ' AS technician_id', false)
                ->select('SUM(CASE WHEN ' . $resolved_condition . ' THEN 1 ELSE 0 END) AS ticket_done', false)
                ->select('SUM(CASE WHEN ' . $pending_condition . ' THEN 1 ELSE 0 END) AS ticket_pending', false)
                ->where($this->field_expr('t', $ticket_assignee_col) . ' IS NOT NULL', null, false)
                ->group_by($this->field_expr('t', $ticket_assignee_col));

            if ($ticket_opened_col !== '' && $ticket_resolved_expr !== '') {
                $duration_expr = 'AVG(CASE WHEN ' . $resolved_condition
                    . ' AND ' . $this->field_expr('t', $ticket_opened_col) . ' IS NOT NULL'
                    . ' AND ' . $ticket_resolved_expr . ' IS NOT NULL'
                    . ' THEN TIMESTAMPDIFF(MINUTE, '
                    . $this->field_expr('t', $ticket_opened_col) . ', '
                    . $ticket_resolved_expr . ') END)';
                $this->db->select($duration_expr . ' AS avg_resolve_minutes', false);
            } else {
                $this->db->select('NULL AS avg_resolve_minutes', false);
            }

            $this->apply_date_range($this->db, 't', $ticket_opened_col, $ctx['start_datetime'], $ctx['end_datetime']);
            $this->apply_technician_filter($this->db, 't', $ticket_assignee_col, (int) $ctx['technician_id']);
            $this->apply_router_scope($this->db, 't', $this->ticket_fields);

            $rows = $this->db->get()->result_array();
            foreach ($rows as $row) {
                $tech_id = (int) ($row['technician_id'] ?? 0);
                if ($tech_id <= 0) {
                    continue;
                }
                if (!isset($ranking[$tech_id])) {
                    $ranking[$tech_id] = array(
                        'technician_id' => $tech_id,
                        'technician_name' => 'Teknisi #' . $tech_id,
                        'wo_done' => 0,
                        'ticket_done' => 0,
                        'ticket_pending' => 0,
                        'avg_resolve_minutes' => null,
                        'total_points' => 0,
                    );
                }
                $ranking[$tech_id]['ticket_done'] = (int) ($row['ticket_done'] ?? 0);
                $ranking[$tech_id]['ticket_pending'] = (int) ($row['ticket_pending'] ?? 0);
                $ranking[$tech_id]['avg_resolve_minutes'] = $row['avg_resolve_minutes'] !== null
                    ? (float) $row['avg_resolve_minutes']
                    : null;
            }
        }

        foreach ($ranking as $tech_id => $row) {
            $ranking[$tech_id]['total_points'] = $this->calculate_points(
                (int) $row['wo_done'],
                (int) $row['ticket_done'],
                (int) $row['ticket_pending']
            );
        }

        $rows = array_values($ranking);
        usort($rows, static function ($a, $b) {
            if ((int) $a['total_points'] === (int) $b['total_points']) {
                if ((int) $a['ticket_done'] === (int) $b['ticket_done']) {
                    return strcmp((string) $a['technician_name'], (string) $b['technician_name']);
                }
                return ((int) $a['ticket_done'] > (int) $b['ticket_done']) ? -1 : 1;
            }
            return ((int) $a['total_points'] > (int) $b['total_points']) ? -1 : 1;
        });

        return array_slice($rows, 0, $limit);
    }

    private function resolve_ticket_period_range($period, $month, $year)
    {
        $period = strtolower(trim((string) $period));
        if (!in_array($period, array('today', 'week', 'month'), true)) {
            $period = 'month';
        }

        if ($period === 'today') {
            $date = date('Y-m-d');
            $labels = array();
            $bucket_values = array();
            for ($h = 0; $h <= 23; $h++) {
                $labels[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00';
                $bucket_values[] = (string) $h;
            }
            return array(
                'start_datetime' => $date . ' 00:00:00',
                'end_datetime' => $date . ' 23:59:59',
                'labels' => $labels,
                'bucket_values' => $bucket_values,
                'bucket' => 'hour',
            );
        }

        if ($period === 'week') {
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date = date('Y-m-d', strtotime('sunday this week'));
        } else {
            $start_date = date('Y-m-01', strtotime($year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-01'));
            $end_date = date('Y-m-t', strtotime($start_date));
        }

        $labels = array();
        $bucket_values = array();
        $cursor = $start_date;
        while (strtotime($cursor) <= strtotime($end_date)) {
            $labels[] = date('d M', strtotime($cursor));
            $bucket_values[] = $cursor;
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        return array(
            'start_datetime' => $start_date . ' 00:00:00',
            'end_datetime' => $end_date . ' 23:59:59',
            'labels' => $labels,
            'bucket_values' => $bucket_values,
            'bucket' => 'date',
        );
    }

    private function apply_date_range($qb, $alias, $column, $start_datetime, $end_datetime)
    {
        $column = trim((string) $column);
        if ($column === '') {
            return;
        }

        $expr = $this->field_expr($alias, $column);
        if ($this->is_date_only_column($column)) {
            $start_date = substr((string) $start_datetime, 0, 10);
            $end_date = substr((string) $end_datetime, 0, 10);
            $qb->where('DATE(' . $expr . ') >= ' . $this->db->escape($start_date), null, false);
            $qb->where('DATE(' . $expr . ') <= ' . $this->db->escape($end_date), null, false);
        } else {
            $qb->where($expr . ' >= ' . $this->db->escape((string) $start_datetime), null, false);
            $qb->where($expr . ' <= ' . $this->db->escape((string) $end_datetime), null, false);
        }
    }

    private function apply_technician_filter($qb, $alias, $column, $technician_id)
    {
        $technician_id = (int) $technician_id;
        if ($technician_id <= 0 || trim((string) $column) === '') {
            return;
        }

        $qb->where($this->field_expr($alias, $column) . ' = ' . $this->db->escape($technician_id), null, false);
    }

    private function apply_router_scope($qb, $alias, array $available_fields)
    {
        if (!in_array('router_id', $available_fields, true)) {
            return;
        }

        if ($this->router_scope_id !== null && (int) $this->router_scope_id > 0) {
            $qb->where($this->field_expr((string) $alias, 'router_id') . ' = ' . $this->db->escape((int) $this->router_scope_id), null, false);
            return;
        }

        if (!$this->is_superadmin_view) {
            // Secure default: non-superadmin tanpa router scope tidak boleh membaca data lintas router.
            $qb->where('1 = 0', null, false);
        }
    }

    private function apply_user_router_scope($qb, $alias = '')
    {
        if (!$this->has_user_field('router_scope_id')) {
            return;
        }

        $prefix = trim((string) $alias);
        $column = $prefix !== '' ? $this->field_expr($prefix, 'router_scope_id') : '`router_scope_id`';

        if ($this->router_scope_id !== null && (int) $this->router_scope_id > 0) {
            $qb->where($column . ' = ' . $this->db->escape((int) $this->router_scope_id), null, false);
            return;
        }

        if (!$this->is_superadmin_view) {
            $qb->where('1 = 0', null, false);
        }
    }

    private function calculate_points($wo_done, $ticket_done, $ticket_pending)
    {
        return ((int) $wo_done * self::POINT_WO_DONE)
            + ((int) $ticket_done * self::POINT_TICKET_DONE)
            + ((int) $ticket_pending * self::POINT_TICKET_PENDING);
    }

    private function build_duration_label($start, $end)
    {
        if (strtotime($start) === false || strtotime($end) === false) {
            return '-';
        }

        $minutes = (int) round((strtotime($end) - strtotime($start)) / 60);
        if ($minutes < 0) {
            return '-';
        }

        return $this->format_minutes($minutes);
    }

    private function build_ticket_duration_label($status_key, $opened_at, $resolved_at)
    {
        if (strtotime($opened_at) === false) {
            return '-';
        }

        $status_key = strtolower((string) $status_key);
        $is_done = in_array($status_key, $this->ticket_resolved_statuses(), true);

        if ($is_done) {
            if (strtotime($resolved_at) !== false) {
                $minutes = (int) round((strtotime($resolved_at) - strtotime($opened_at)) / 60);
                if ($minutes < 0) {
                    return '-';
                }
                return $this->format_minutes($minutes);
            }
            return '-';
        }

        $minutes = (int) round((time() - strtotime($opened_at)) / 60);
        if ($minutes < 0) {
            $minutes = 0;
        }
        return $this->format_minutes($minutes) . ' (ongoing)';
    }

    private function format_minutes($minutes)
    {
        $minutes = (int) $minutes;
        if ($minutes < 60) {
            return $minutes . ' menit';
        }

        $hours = (int) floor($minutes / 60);
        $rem = $minutes % 60;
        if ($rem <= 0) {
            return $hours . ' jam';
        }

        return $hours . ' jam ' . $rem . ' menit';
    }

    private function format_datetime($value)
    {
        $value = trim((string) $value);
        if ($value === '' || strtotime($value) === false) {
            return '-';
        }

        return date('d-m-Y H:i', strtotime($value));
    }

    private function normalize_wo_status_label($status)
    {
        $status = strtolower(trim((string) $status));
        if (in_array($status, array('done', 'completed'), true)) {
            return 'SELESAI';
        }
        if ($status === 'activated') {
            return 'AKTIF';
        }
        if (in_array($status, array('process', 'in_progress'), true)) {
            return 'PENDING';
        }
        if (in_array($status, array('cancel', 'cancelled'), true)) {
            return 'CANCEL';
        }

        return strtoupper($status !== '' ? $status : 'OPEN');
    }

    private function normalize_ticket_status_label($status)
    {
        $status = strtolower(trim((string) $status));
        if (in_array($status, array('in_progress', 'progress', 'assigned'), true)) {
            return 'PENDING';
        }
        if (in_array($status, array('resolved', 'closed', 'done'), true)) {
            return 'SELESAI';
        }
        if (in_array($status, array('cancel', 'cancelled'), true)) {
            return 'CANCEL';
        }
        return strtoupper($status !== '' ? $status : 'OPEN');
    }

    private function resolve_selected_technician_name($technician_id)
    {
        $technician_id = (int) $technician_id;
        if ($technician_id <= 0) {
            return 'Semua Teknisi';
        }

        $options = $this->get_teknisi_options();
        foreach ($options as $option) {
            if ((int) ($option['id'] ?? 0) === $technician_id) {
                return (string) ($option['name'] ?? ('Teknisi #' . $technician_id));
            }
        }

        return 'Teknisi #' . $technician_id;
    }

    private function has_wo_field($field)
    {
        return in_array((string) $field, $this->wo_fields, true);
    }

    private function has_ticket_field($field)
    {
        return in_array((string) $field, $this->ticket_fields, true);
    }

    private function has_user_field($field)
    {
        return in_array((string) $field, $this->user_fields, true);
    }

    private function resolve_wo_status_column()
    {
        return $this->first_available($this->wo_fields, array('status'));
    }

    private function resolve_wo_assignee_column()
    {
        return $this->first_available($this->wo_fields, array('assigned_to', 'technician_id', 'handled_by'));
    }

    private function resolve_wo_type_column()
    {
        return $this->first_available($this->wo_fields, array('wo_type', 'type'));
    }

    private function resolve_wo_filter_date_column()
    {
        return $this->first_available($this->wo_fields, array('open_at', 'scheduled_start_at', 'scheduled_date', 'requested_date', 'created_at', 'updated_at'));
    }

    private function resolve_wo_chart_date_column()
    {
        return $this->first_available($this->wo_fields, array('done_at', 'activated_at', 'scheduled_start_at', 'scheduled_date', 'open_at', 'created_at'));
    }

    private function resolve_wo_start_time_column()
    {
        return $this->first_available($this->wo_fields, array('actual_start_at', 'process_at', 'open_at', 'scheduled_start_at', 'created_at'));
    }

    private function resolve_wo_end_time_column()
    {
        return $this->first_available($this->wo_fields, array('actual_end_at', 'done_at', 'activated_at', 'updated_at'));
    }

    private function resolve_ticket_status_column()
    {
        return $this->first_available($this->ticket_fields, array('status'));
    }

    private function resolve_ticket_assignee_column()
    {
        return $this->first_available($this->ticket_fields, array('assigned_to', 'technician_id', 'handled_by'));
    }

    private function resolve_ticket_opened_column()
    {
        return $this->first_available($this->ticket_fields, array('opened_at', 'open_at', 'created_at'));
    }

    private function resolve_ticket_resolved_column()
    {
        return $this->first_available($this->ticket_fields, array('resolved_at', 'closed_at', 'updated_at'));
    }

    private function resolve_wo_start_time_expr($alias = 'w')
    {
        return $this->build_datetime_coalesce_expr(
            (string) $alias,
            $this->wo_fields,
            array('actual_start_at', 'process_at', 'open_at', 'scheduled_start_at', 'created_at')
        );
    }

    private function resolve_wo_end_time_expr($alias = 'w')
    {
        return $this->build_datetime_coalesce_expr(
            (string) $alias,
            $this->wo_fields,
            array('actual_end_at', 'done_at', 'activated_at', 'updated_at')
        );
    }

    private function resolve_ticket_resolved_expr($alias = 't')
    {
        return $this->build_datetime_coalesce_expr(
            (string) $alias,
            $this->ticket_fields,
            array('resolved_at', 'closed_at', 'updated_at')
        );
    }

    private function build_datetime_coalesce_expr($alias, array $available_fields, array $candidates)
    {
        $parts = array();
        foreach ($candidates as $field) {
            if (!in_array((string) $field, $available_fields, true)) {
                continue;
            }
            $parts[] = $this->field_expr((string) $alias, (string) $field);
        }

        if (empty($parts)) {
            return '';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function resolve_ticket_number_column()
    {
        return $this->first_available($this->ticket_fields, array('ticket_number', 'ticket_code'));
    }

    private function resolve_ticket_issue_column()
    {
        return $this->first_available($this->ticket_fields, array('category', 'ticket_type', 'subject'));
    }

    private function resolve_customer_name_column()
    {
        return $this->first_available($this->customer_fields, array('full_name', 'nama', 'name', 'username'));
    }

    private function resolve_user_name_column()
    {
        return $this->first_available($this->user_fields, array('name', 'full_name', 'username'));
    }

    private function first_available(array $haystack, array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (in_array((string) $candidate, $haystack, true)) {
                return (string) $candidate;
            }
        }

        return '';
    }

    private function wo_done_statuses()
    {
        return array('done', 'activated', 'completed');
    }

    private function ticket_resolved_statuses()
    {
        return array('resolved', 'closed', 'done');
    }

    private function ticket_pending_statuses()
    {
        return array('open', 'assigned', 'progress', 'in_progress', 'pending', 'new');
    }

    private function escaped_in_list(array $values)
    {
        $escaped = array();
        foreach ($values as $value) {
            $escaped[] = $this->db->escape((string) $value);
        }
        return implode(', ', $escaped);
    }

    private function field_expr($alias, $field)
    {
        $field = str_replace('`', '', (string) $field);
        $alias = trim((string) $alias);
        if ($alias === '') {
            return '`' . $field . '`';
        }
        return $alias . '.`' . $field . '`';
    }

    private function is_date_only_column($column)
    {
        $column = strtolower(trim((string) $column));
        return in_array($column, array('scheduled_date', 'requested_date', 'install_date', 'date'), true);
    }

    private function normalize_date($value)
    {
        $value = trim((string) $value);
        if ($value === '' || strtotime($value) === false) {
            return '';
        }
        return date('Y-m-d', strtotime($value));
    }
}
