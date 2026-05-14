<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Helpdesk_stats_model extends CI_Model
{
    private $table_tickets = 'tickets';
    private $table_users = 'users';
    private $table_customers = 'customers';

    public function __construct()
    {
        parent::__construct();

        // Optional compatibility with existing Helpdesk module.
        if (file_exists(APPPATH . 'models/Ticket_model.php')) {
            $this->load->model('Ticket_model', 'ticket_model');
        }
    }

    public function get_summary($month, $year)
    {
        if (!$this->db->table_exists($this->table_tickets)) {
            return $this->empty_summary();
        }

        list($month, $year) = $this->normalize_month_year($month, $year);

        $row = $this->db
            ->select('COUNT(*) AS total_ticket', false)
            ->select("SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_total", false)
            ->select("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_total", false)
            ->select("SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_total", false)
            ->select("SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_total", false)
            ->select("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_total", false)
            ->select("SUM(CASE WHEN priority = 'critical' THEN 1 ELSE 0 END) AS critical_total", false)
            ->from($this->table_tickets)
            ->where('MONTH(opened_at) =', $month, false)
            ->where('YEAR(opened_at) =', $year, false)
            ->get()
            ->row_array();

        $summary = array(
            'total_ticket' => (int) ($row['total_ticket'] ?? 0),
            'open' => (int) ($row['open_total'] ?? 0),
            'in_progress' => (int) ($row['in_progress_total'] ?? 0),
            'resolved' => (int) ($row['resolved_total'] ?? 0),
            'closed' => (int) ($row['closed_total'] ?? 0),
            'cancelled' => (int) ($row['cancelled_total'] ?? 0),
            'critical' => (int) ($row['critical_total'] ?? 0),
            'channel_counts' => array(
                'phone' => 0,
                'whatsapp' => 0,
                'telegram' => 0,
                'web' => 0,
                'other' => 0,
            ),
        );

        $channels = $this->db
            ->select('channel, COUNT(*) AS total', false)
            ->from($this->table_tickets)
            ->where('MONTH(opened_at) =', $month, false)
            ->where('YEAR(opened_at) =', $year, false)
            ->group_by('channel')
            ->get()
            ->result_array();

        foreach ($channels as $channel) {
            $name = strtolower((string) ($channel['channel'] ?? 'other'));
            if (!array_key_exists($name, $summary['channel_counts'])) {
                $name = 'other';
            }
            $summary['channel_counts'][$name] += (int) ($channel['total'] ?? 0);
        }

        return $summary;
    }

    public function get_ticket_per_month($year)
    {
        if (!$this->db->table_exists($this->table_tickets)) {
            return $this->build_empty_monthly();
        }

        $year = (int) $year;
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $rows = $this->db
            ->select('MONTH(opened_at) AS month_no, COUNT(*) AS total', false)
            ->from($this->table_tickets)
            ->where('YEAR(opened_at) =', $year, false)
            ->group_by('MONTH(opened_at)', false)
            ->order_by('MONTH(opened_at)', 'ASC', false)
            ->get()
            ->result_array();

        $result = $this->build_empty_monthly();
        foreach ($rows as $row) {
            $month_no = (int) ($row['month_no'] ?? 0);
            if ($month_no >= 1 && $month_no <= 12) {
                $result[$month_no - 1]['total'] = (int) ($row['total'] ?? 0);
            }
        }

        return $result;
    }

    public function get_ticket_by_status($month, $year)
    {
        $statuses = array('open', 'in_progress', 'resolved', 'closed', 'cancelled');
        $result = array();
        foreach ($statuses as $status) {
            $result[$status] = 0;
        }

        if (!$this->db->table_exists($this->table_tickets)) {
            return $this->map_dimension_result($result, 'status');
        }

        list($month, $year) = $this->normalize_month_year($month, $year);

        $rows = $this->db
            ->select('status, COUNT(*) AS total', false)
            ->from($this->table_tickets)
            ->where('MONTH(opened_at) =', $month, false)
            ->where('YEAR(opened_at) =', $year, false)
            ->group_by('status')
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            if (array_key_exists($status, $result)) {
                $result[$status] = (int) ($row['total'] ?? 0);
            }
        }

        return $this->map_dimension_result($result, 'status');
    }

    public function get_ticket_by_category($month, $year)
    {
        $categories = array('internet_down', 'slow_speed', 'billing', 'device', 'other');
        $result = array();
        foreach ($categories as $category) {
            $result[$category] = 0;
        }

        if (!$this->db->table_exists($this->table_tickets)) {
            return $this->map_dimension_result($result, 'category');
        }

        list($month, $year) = $this->normalize_month_year($month, $year);

        $rows = $this->db
            ->select('category, COUNT(*) AS total', false)
            ->from($this->table_tickets)
            ->where('MONTH(opened_at) =', $month, false)
            ->where('YEAR(opened_at) =', $year, false)
            ->group_by('category')
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            $category = strtolower((string) ($row['category'] ?? 'other'));
            if (!array_key_exists($category, $result)) {
                $category = 'other';
            }
            $result[$category] += (int) ($row['total'] ?? 0);
        }

        return $this->map_dimension_result($result, 'category');
    }

    public function get_ticket_by_channel($month, $year)
    {
        $channels = array('phone', 'whatsapp', 'telegram', 'web', 'other');
        $result = array();
        foreach ($channels as $channel) {
            $result[$channel] = 0;
        }

        if (!$this->db->table_exists($this->table_tickets)) {
            return $this->map_dimension_result($result, 'channel');
        }

        list($month, $year) = $this->normalize_month_year($month, $year);

        $rows = $this->db
            ->select('channel, COUNT(*) AS total', false)
            ->from($this->table_tickets)
            ->where('MONTH(opened_at) =', $month, false)
            ->where('YEAR(opened_at) =', $year, false)
            ->group_by('channel')
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            $channel = strtolower((string) ($row['channel'] ?? 'other'));
            if (!array_key_exists($channel, $result)) {
                $channel = 'other';
            }
            $result[$channel] += (int) ($row['total'] ?? 0);
        }

        return $this->map_dimension_result($result, 'channel');
    }

    public function get_technician_performance($month, $year)
    {
        if (!$this->db->table_exists($this->table_tickets)) {
            return array();
        }

        list($month, $year) = $this->normalize_month_year($month, $year);

        $name_expr = $this->resolve_user_name_expression('u');
        $select_name = $name_expr !== ''
            ? $name_expr
            : "CONCAT('Teknisi #', t.assigned_to)";

        $this->db
            ->from($this->table_tickets . ' t')
            ->select('t.assigned_to')
            ->select($select_name . ' AS technician_name', false)
            ->select("SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_total", false)
            ->select("AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.opened_at, t.resolved_at) END) AS avg_resolve_minutes", false)
            ->where('MONTH(t.opened_at) =', $month, false)
            ->where('YEAR(t.opened_at) =', $year, false)
            ->where('t.assigned_to IS NOT NULL', null, false);

        if ($this->db->table_exists($this->table_users)) {
            $this->db->join($this->table_users . ' u', 'u.id = t.assigned_to', 'left');
        }

        $rows = $this->db
            ->group_by('t.assigned_to')
            ->order_by('resolved_total', 'DESC')
            ->order_by('avg_resolve_minutes', 'ASC')
            ->get()
            ->result_array();

        foreach ($rows as &$row) {
            $row['assigned_to'] = (int) ($row['assigned_to'] ?? 0);
            $row['resolved_total'] = (int) ($row['resolved_total'] ?? 0);
            $row['avg_resolve_minutes'] = $row['avg_resolve_minutes'] !== null
                ? (float) $row['avg_resolve_minutes']
                : null;
        }

        return $rows;
    }

    public function get_avg_response_time($month, $year)
    {
        if (!$this->db->table_exists($this->table_tickets)) {
            return 0.0;
        }

        list($month, $year) = $this->normalize_month_year($month, $year);

        $row = $this->db
            ->select('AVG(TIMESTAMPDIFF(MINUTE, opened_at, first_response_at)) AS avg_minutes', false)
            ->from($this->table_tickets)
            ->where('MONTH(opened_at) =', $month, false)
            ->where('YEAR(opened_at) =', $year, false)
            ->where('first_response_at IS NOT NULL', null, false)
            ->get()
            ->row_array();

        return (float) ($row['avg_minutes'] ?? 0);
    }

    public function get_avg_resolve_time($month, $year)
    {
        if (!$this->db->table_exists($this->table_tickets)) {
            return 0.0;
        }

        list($month, $year) = $this->normalize_month_year($month, $year);

        $row = $this->db
            ->select('AVG(TIMESTAMPDIFF(MINUTE, opened_at, resolved_at)) AS avg_minutes', false)
            ->from($this->table_tickets)
            ->where('MONTH(opened_at) =', $month, false)
            ->where('YEAR(opened_at) =', $year, false)
            ->where('resolved_at IS NOT NULL', null, false)
            ->get()
            ->row_array();

        return (float) ($row['avg_minutes'] ?? 0);
    }

    public function get_top_customers($month, $year, $limit = 5)
    {
        if (!$this->db->table_exists($this->table_tickets)) {
            return array();
        }

        list($month, $year) = $this->normalize_month_year($month, $year);
        $limit = max(1, (int) $limit);

        $customer_name_expr = $this->resolve_customer_name_expression('c');
        $select_name = $customer_name_expr !== ''
            ? $customer_name_expr
            : "CONCAT('Customer #', t.customer_id)";

        $this->db
            ->from($this->table_tickets . ' t')
            ->select('t.customer_id')
            ->select($select_name . ' AS customer_name', false)
            ->select('COUNT(*) AS total_ticket', false)
            ->where('MONTH(t.opened_at) =', $month, false)
            ->where('YEAR(t.opened_at) =', $year, false)
            ->where('t.customer_id IS NOT NULL', null, false);

        if ($this->db->table_exists($this->table_customers)) {
            $this->db->join($this->table_customers . ' c', 'c.id = t.customer_id', 'left');
        }

        $rows = $this->db
            ->group_by('t.customer_id')
            ->order_by('total_ticket', 'DESC')
            ->limit($limit)
            ->get()
            ->result_array();

        foreach ($rows as &$row) {
            $row['customer_id'] = (int) ($row['customer_id'] ?? 0);
            $row['total_ticket'] = (int) ($row['total_ticket'] ?? 0);
        }

        return $rows;
    }

    public function get_monthly_ticket_rows($month, $year)
    {
        if (!$this->db->table_exists($this->table_tickets)) {
            return array();
        }

        list($month, $year) = $this->normalize_month_year($month, $year);

        $customer_name_expr = $this->resolve_customer_name_expression('c');
        $user_name_expr = $this->resolve_user_name_expression('u');

        $select_customer = $customer_name_expr !== ''
            ? $customer_name_expr
            : "'-'";
        $select_tech = $user_name_expr !== ''
            ? $user_name_expr
            : "CONCAT('Teknisi #', t.assigned_to)";

        $this->db
            ->from($this->table_tickets . ' t')
            ->select('t.id, t.ticket_number, t.channel, t.category, t.priority, t.subject, t.status, t.opened_at, t.first_response_at, t.resolved_at, t.closed_at')
            ->select($select_customer . ' AS customer_name', false)
            ->select($select_tech . ' AS technician_name', false)
            ->where('MONTH(t.opened_at) =', $month, false)
            ->where('YEAR(t.opened_at) =', $year, false);

        if ($this->db->table_exists($this->table_customers)) {
            $this->db->join($this->table_customers . ' c', 'c.id = t.customer_id', 'left');
        }
        if ($this->db->table_exists($this->table_users)) {
            $this->db->join($this->table_users . ' u', 'u.id = t.assigned_to', 'left');
        }

        return $this->db
            ->order_by('t.opened_at', 'DESC')
            ->get()
            ->result_array();
    }

    public function dashboard_cards($viewer_role = '', $viewer_user_id = 0)
    {
        if (!isset($this->ticket_model) || !$this->ticket_model->table_ready()) {
            $summary = $this->get_summary((int) date('m'), (int) date('Y'));
            return array(
                'today_total' => 0,
                'open_total' => (int) $summary['open'],
                'progress_total' => (int) $summary['in_progress'],
                'urgent_total' => (int) $summary['critical'],
                'sla_breached' => 0,
            );
        }

        $viewer_role = strtolower((string) $viewer_role);
        $viewer_user_id = (int) $viewer_user_id;

        $today = date('Y-m-d');

        return array(
            'today_total' => $this->count_with_filter(array('created_date' => $today), $viewer_role, $viewer_user_id),
            'open_total' => $this->count_with_filter(array('status' => 'OPEN'), $viewer_role, $viewer_user_id),
            'progress_total' => $this->count_with_filter(array('status' => 'PROGRESS'), $viewer_role, $viewer_user_id),
            'urgent_total' => $this->count_with_filter(array('priority' => 'URGENT'), $viewer_role, $viewer_user_id),
            'sla_breached' => $this->count_sla_breached($viewer_role, $viewer_user_id),
        );
    }

    public function status_chart($viewer_role = '', $viewer_user_id = 0)
    {
        $statuses = array('OPEN', 'ASSIGNED', 'PROGRESS', 'RESOLVED', 'CLOSED');
        $data = array();

        foreach ($statuses as $status) {
            $data[] = array(
                'status' => $status,
                'total' => $this->count_with_filter(array('status' => $status), $viewer_role, (int) $viewer_user_id),
            );
        }

        return $data;
    }

    public function recent_sla_breached($viewer_role = '', $viewer_user_id = 0, $limit = 10)
    {
        if (!isset($this->ticket_model) || !$this->ticket_model->table_ready()) {
            return array();
        }

        $rows = $this->ticket_model->get_tickets(array(), 500, 0, $viewer_role, (int) $viewer_user_id);
        $now = time();
        $result = array();

        foreach ($rows as $row) {
            $status = strtoupper((string) ($row['status'] ?? ''));
            if (in_array($status, array('RESOLVED', 'CLOSED'), true)) {
                continue;
            }

            $deadline = (string) ($row['sla_deadline'] ?? '');
            if ($deadline === '' || strtotime($deadline) === false) {
                continue;
            }

            if (strtotime($deadline) < $now) {
                $result[] = $row;
            }
        }

        return array_slice($result, 0, max(1, (int) $limit));
    }

    private function count_with_filter(array $filter, $viewer_role, $viewer_user_id)
    {
        if (!$this->ticket_model->table_ready()) {
            return 0;
        }

        if (!empty($filter['created_date'])) {
            $date = (string) $filter['created_date'];
            $rows = $this->ticket_model->get_tickets(array(), 5000, 0, $viewer_role, (int) $viewer_user_id);
            $count = 0;
            foreach ($rows as $row) {
                $created_at = (string) ($row['created_at'] ?? '');
                if ($created_at !== '' && strtotime($created_at) !== false && date('Y-m-d', strtotime($created_at)) === $date) {
                    $count++;
                }
            }
            return $count;
        }

        $filters = array();
        if (!empty($filter['status'])) {
            $filters['status'] = (string) $filter['status'];
        }
        if (!empty($filter['priority'])) {
            $filters['priority'] = (string) $filter['priority'];
        }

        return $this->ticket_model->count_tickets($filters, $viewer_role, (int) $viewer_user_id);
    }

    private function count_sla_breached($viewer_role, $viewer_user_id)
    {
        $rows = $this->ticket_model->get_tickets(array(), 5000, 0, $viewer_role, (int) $viewer_user_id);
        $now = time();
        $count = 0;

        foreach ($rows as $row) {
            $status = strtoupper((string) ($row['status'] ?? ''));
            if (in_array($status, array('RESOLVED', 'CLOSED'), true)) {
                continue;
            }

            $deadline = (string) ($row['sla_deadline'] ?? '');
            if ($deadline === '' || strtotime($deadline) === false) {
                continue;
            }

            if (strtotime($deadline) < $now) {
                $count++;
            }
        }

        return $count;
    }

    private function normalize_month_year($month, $year)
    {
        $month = (int) $month;
        $year = (int) $year;

        if ($month < 1 || $month > 12) {
            $month = (int) date('m');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        return array($month, $year);
    }

    private function build_empty_monthly()
    {
        $rows = array();
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = array(
                'month_no' => $i,
                'month_label' => date('M', mktime(0, 0, 0, $i, 1)),
                'total' => 0,
            );
        }
        return $rows;
    }

    private function map_dimension_result(array $source, $key_name)
    {
        $rows = array();
        foreach ($source as $key => $total) {
            $rows[] = array(
                $key_name => $key,
                'total' => (int) $total,
            );
        }

        return $rows;
    }

    private function empty_summary()
    {
        return array(
            'total_ticket' => 0,
            'open' => 0,
            'in_progress' => 0,
            'resolved' => 0,
            'closed' => 0,
            'cancelled' => 0,
            'critical' => 0,
            'channel_counts' => array(
                'phone' => 0,
                'whatsapp' => 0,
                'telegram' => 0,
                'web' => 0,
                'other' => 0,
            ),
        );
    }

    private function resolve_user_name_expression($alias)
    {
        if (!$this->db->table_exists($this->table_users)) {
            return '';
        }

        $fields = $this->db->list_fields($this->table_users);
        if (in_array('name', $fields, true)) {
            return $alias . '.name';
        }
        if (in_array('full_name', $fields, true)) {
            return $alias . '.full_name';
        }
        if (in_array('username', $fields, true)) {
            return $alias . '.username';
        }

        return '';
    }

    private function resolve_customer_name_expression($alias)
    {
        if (!$this->db->table_exists($this->table_customers)) {
            return '';
        }

        $fields = $this->db->list_fields($this->table_customers);
        if (in_array('full_name', $fields, true)) {
            return $alias . '.full_name';
        }
        if (in_array('nama', $fields, true)) {
            return $alias . '.nama';
        }
        if (in_array('name', $fields, true)) {
            return $alias . '.name';
        }
        if (in_array('username', $fields, true)) {
            return $alias . '.username';
        }

        return '';
    }
}
