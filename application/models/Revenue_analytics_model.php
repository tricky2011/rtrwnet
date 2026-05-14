<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Revenue analytics model (cash basis).
 * Revenue dihitung dari invoice PAID berdasarkan tanggal pembayaran final.
 */
class Revenue_analytics_model extends CI_Model
{
    private function table_has_column($table, $column)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }

        return in_array((string) $column, $this->db->list_fields($table), true);
    }

    private function build_paid_date_subquery()
    {
        $qb = $this->db
            ->select('invoice_id, MAX(payment_date) AS paid_at', false)
            ->from('payments');

        if ($this->table_has_column('payments', 'status')) {
            $qb->where('status', 'confirmed');
        }

        $qb->group_by('invoice_id');
        return $qb->get_compiled_select();
    }

    public function get_monthly_revenue_series($start_date, $end_date)
    {
        if (!$this->db->table_exists('invoices') || !$this->db->table_exists('payments')) {
            return $this->normalize_monthly_series($start_date, $end_date, array(), 'revenue');
        }

        $paid_sub = $this->build_paid_date_subquery();

        $rows = $this->db
            ->select("DATE_FORMAT(pd.paid_at, '%Y-%m') AS month_key, COALESCE(SUM(i.total_amount), 0) AS revenue", false)
            ->from('invoices i')
            ->join('(' . $paid_sub . ') pd', 'pd.invoice_id = i.id', 'inner', false)
            ->where('i.status', 'paid')
            ->where('DATE(pd.paid_at) >=', $start_date)
            ->where('DATE(pd.paid_at) <=', $end_date)
            ->group_by("DATE_FORMAT(pd.paid_at, '%Y-%m')", false)
            ->order_by('month_key', 'ASC')
            ->get()
            ->result_array();

        return $this->normalize_monthly_series($start_date, $end_date, $rows, 'revenue');
    }

    /**
     * ARPU bulanan (cash basis):
     * ARPU = revenue paid bulan / jumlah customer yang invoice-nya paid bulan tsb.
     */
    public function get_monthly_arpu_series($start_date, $end_date)
    {
        if (!$this->db->table_exists('invoices') || !$this->db->table_exists('payments')) {
            return array();
        }

        $paid_sub = $this->build_paid_date_subquery();

        $rows = $this->db
            ->select("DATE_FORMAT(pd.paid_at, '%Y-%m') AS month_key, COALESCE(SUM(i.total_amount), 0) AS revenue, COUNT(DISTINCT i.customer_id) AS paying_customers", false)
            ->from('invoices i')
            ->join('(' . $paid_sub . ') pd', 'pd.invoice_id = i.id', 'inner', false)
            ->where('i.status', 'paid')
            ->where('DATE(pd.paid_at) >=', $start_date)
            ->where('DATE(pd.paid_at) <=', $end_date)
            ->group_by("DATE_FORMAT(pd.paid_at, '%Y-%m')", false)
            ->order_by('month_key', 'ASC')
            ->get()
            ->result_array();

        $map = array();
        foreach ($rows as $row) {
            $revenue = (float) $row['revenue'];
            $users = (int) $row['paying_customers'];
            $map[$row['month_key']] = array(
                'revenue' => round($revenue, 2),
                'paying_customers' => $users,
                'arpu' => $users > 0 ? round($revenue / $users, 2) : 0.00,
            );
        }

        $result = array();
        foreach ($this->month_range($start_date, $end_date) as $month_key) {
            $default = array('revenue' => 0.00, 'paying_customers' => 0, 'arpu' => 0.00);
            $row = isset($map[$month_key]) ? $map[$month_key] : $default;

            $result[] = array(
                'month_key' => $month_key,
                'label' => date('M Y', strtotime($month_key . '-01')),
                'revenue' => (float) $row['revenue'],
                'paying_customers' => (int) $row['paying_customers'],
                'arpu' => (float) $row['arpu'],
            );
        }

        return $result;
    }

    public function get_revenue_by_package($start_date, $end_date, $limit = 20)
    {
        $limit = (int) $limit;
        if ($limit < 1) {
            $limit = 20;
        }

        if (!$this->db->table_exists('invoices') || !$this->db->table_exists('payments')) {
            return array();
        }

        $paid_sub = $this->build_paid_date_subquery();

        $rows = $this->db
            ->select("COALESCE(NULLIF(TRIM(p.name), ''), CONCAT('Profile#', cs.ppp_profile_id), 'Unknown') AS package_name, COALESCE(SUM(i.total_amount), 0) AS revenue, COUNT(*) AS paid_invoices, COUNT(DISTINCT i.customer_id) AS customers", false)
            ->from('invoices i')
            ->join('(' . $paid_sub . ') pd', 'pd.invoice_id = i.id', 'inner', false)
            ->join('customer_services cs', 'cs.id = i.customer_service_id', 'left')
            ->join('ppp_profiles p', 'p.id = cs.ppp_profile_id', 'left')
            ->where('i.status', 'paid')
            ->where('DATE(pd.paid_at) >=', $start_date)
            ->where('DATE(pd.paid_at) <=', $end_date)
            ->group_by('package_name')
            ->order_by('revenue', 'DESC')
            ->limit($limit)
            ->get()
            ->result_array();

        foreach ($rows as &$row) {
            $row['revenue'] = (float) $row['revenue'];
            $row['paid_invoices'] = (int) $row['paid_invoices'];
            $row['customers'] = (int) $row['customers'];
        }

        return $rows;
    }

    private function normalize_monthly_series($start_date, $end_date, array $rows, $value_key)
    {
        $map = array();
        foreach ($rows as $row) {
            $map[$row['month_key']] = (float) $row[$value_key];
        }

        $result = array();
        foreach ($this->month_range($start_date, $end_date) as $month_key) {
            $result[] = array(
                'month_key' => $month_key,
                'label' => date('M Y', strtotime($month_key . '-01')),
                $value_key => isset($map[$month_key]) ? round($map[$month_key], 2) : 0.00,
            );
        }

        return $result;
    }

    private function month_range($start_date, $end_date)
    {
        $months = array();
        $start = strtotime(date('Y-m-01', strtotime($start_date)));
        $end = strtotime(date('Y-m-01', strtotime($end_date)));

        while ($start <= $end) {
            $months[] = date('Y-m', $start);
            $start = strtotime('+1 month', $start);
        }

        return $months;
    }
}
