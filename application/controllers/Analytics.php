<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Analytics controller (backend logic).
 * Menyusun data chart revenue agar siap dirender di view.
 */
class Analytics extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->model('revenue_analytics_model');
    }

    /**
     * Halaman analytics revenue.
     * Query params:
     * - start=YYYY-MM-DD
     * - end=YYYY-MM-DD
     */
    public function revenue()
    {
        $start_date = $this->input->get('start', true);
        $end_date = $this->input->get('end', true);

        // Default: 12 bulan terakhir sampai akhir bulan saat ini.
        if (!$this->is_valid_date($start_date) || !$this->is_valid_date($end_date)) {
            $end_date = date('Y-m-t');
            $start_date = date('Y-m-01', strtotime('-11 months'));
        }

        if ($start_date > $end_date) {
            $tmp = $start_date;
            $start_date = $end_date;
            $end_date = $tmp;
        }

        $monthly_revenue = $this->revenue_analytics_model->get_monthly_revenue_series($start_date, $end_date);
        $monthly_arpu = $this->revenue_analytics_model->get_monthly_arpu_series($start_date, $end_date);
        $revenue_by_package = $this->revenue_analytics_model->get_revenue_by_package($start_date, $end_date);

        $chart_data = [
            'labels' => array_column($monthly_revenue, 'label'),
            'revenue' => array_map('floatval', array_column($monthly_revenue, 'revenue')),
            'arpu' => array_map('floatval', array_column($monthly_arpu, 'arpu')),
            'paying_customers' => array_map('intval', array_column($monthly_arpu, 'paying_customers')),
            'package_labels' => array_column($revenue_by_package, 'package_name'),
            'package_revenue' => array_map('floatval', array_column($revenue_by_package, 'revenue')),
        ];

        $summary = [
            'total_revenue' => array_sum($chart_data['revenue']),
            'average_arpu' => count($chart_data['arpu']) > 0
                ? round(array_sum($chart_data['arpu']) / count($chart_data['arpu']), 2)
                : 0.00,
        ];

        $this->load->view('analytics/revenue', [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'summary' => $summary,
            'chart_data' => $chart_data,
            'monthly_revenue' => $monthly_revenue,
            'monthly_arpu' => $monthly_arpu,
            'revenue_by_package' => $revenue_by_package,
        ]);
    }

    private function is_valid_date($date)
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        return strtotime($date) !== false;
    }
}
