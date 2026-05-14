<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Helpdesk_dashboard extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin', 'teknisi'));
        $this->load->database();
        $this->load->helper(array('url', 'form'));
        $this->load->model('Helpdesk_stats_model', 'helpdesk_stats_model');
    }

    public function index()
    {
        list($month, $year) = $this->resolve_filter();
        $payload = $this->build_dashboard_payload($month, $year);

        $this->load->view('helpdesk/dashboard', $payload);
    }

    public function export_pdf()
    {
        $this->require_role(array('superadmin', 'admin'));
        list($month, $year) = $this->resolve_filter();
        $payload = $this->build_dashboard_payload($month, $year);

        $html = $this->load->view('helpdesk/dashboard_pdf', $payload, true);

        if (!class_exists('Dompdf\\Dompdf')) {
            $autoload = FCPATH . 'vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }

        if (!class_exists('Dompdf\\Dompdf')) {
            $this->session->set_flashdata('error', 'Dompdf belum terpasang. Jalankan: composer require dompdf/dompdf');
            redirect('helpdesk-dashboard?month=' . $month . '&year=' . $year);
            return;
        }

        $dompdf = new \Dompdf\Dompdf(array('isRemoteEnabled' => true));
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = 'helpdesk-dashboard-' . $year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.pdf';
        $dompdf->stream($filename, array('Attachment' => false));
    }

    public function export_excel()
    {
        $this->require_role(array('superadmin', 'admin'));
        list($month, $year) = $this->resolve_filter();
        $rows = $this->helpdesk_stats_model->get_monthly_ticket_rows($month, $year);

        $filename = 'helpdesk-dashboard-' . $year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            show_error('Gagal membuat file export.');
            return;
        }

        // UTF-8 BOM agar karakter tampil benar di Excel.
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, array(
            'No',
            'Ticket Number',
            'Customer',
            'Channel',
            'Category',
            'Priority',
            'Status',
            'Teknisi',
            'Opened At',
            'First Response At',
            'Resolved At',
            'Closed At',
            'Subject',
        ));

        $no = 1;
        foreach ($rows as $row) {
            fputcsv($out, array(
                $no++,
                (string) ($row['ticket_number'] ?? ''),
                (string) ($row['customer_name'] ?? '-'),
                strtoupper((string) ($row['channel'] ?? 'other')),
                strtoupper((string) ($row['category'] ?? 'other')),
                strtoupper((string) ($row['priority'] ?? 'medium')),
                strtoupper((string) ($row['status'] ?? 'open')),
                (string) ($row['technician_name'] ?? '-'),
                (string) ($row['opened_at'] ?? ''),
                (string) ($row['first_response_at'] ?? ''),
                (string) ($row['resolved_at'] ?? ''),
                (string) ($row['closed_at'] ?? ''),
                (string) ($row['subject'] ?? ''),
            ));
        }

        fclose($out);
    }

    private function build_dashboard_payload($month, $year)
    {
        $summary = $this->helpdesk_stats_model->get_summary($month, $year);
        $avg_response_minutes = $this->helpdesk_stats_model->get_avg_response_time($month, $year);
        $avg_resolve_minutes = $this->helpdesk_stats_model->get_avg_resolve_time($month, $year);
        $ticket_per_month = $this->helpdesk_stats_model->get_ticket_per_month($year);
        $ticket_by_status = $this->helpdesk_stats_model->get_ticket_by_status($month, $year);
        $ticket_by_category = $this->helpdesk_stats_model->get_ticket_by_category($month, $year);
        $ticket_by_channel = $this->helpdesk_stats_model->get_ticket_by_channel($month, $year);
        $technician_performance = $this->helpdesk_stats_model->get_technician_performance($month, $year);
        $top_customers = $this->helpdesk_stats_model->get_top_customers($month, $year, 5);

        $total_ticket = (int) ($summary['total_ticket'] ?? 0);
        $resolved_ticket = (int) ($summary['resolved'] ?? 0);
        $resolution_rate = $total_ticket > 0
            ? round(($resolved_ticket / $total_ticket) * 100, 2)
            : 0;

        $top_technician = !empty($technician_performance)
            ? (array) $technician_performance[0]
            : array();

        $chart_data = array(
            'line_ticket_per_month' => array(
                'labels' => array_column($ticket_per_month, 'month_label'),
                'values' => array_map('intval', array_column($ticket_per_month, 'total')),
            ),
            'pie_status' => array(
                'labels' => array_map('strtoupper', array_column($ticket_by_status, 'status')),
                'values' => array_map('intval', array_column($ticket_by_status, 'total')),
            ),
            'bar_category' => array(
                'labels' => array_map('strtoupper', array_column($ticket_by_category, 'category')),
                'values' => array_map('intval', array_column($ticket_by_category, 'total')),
            ),
            'bar_channel' => array(
                'labels' => array_map('strtoupper', array_column($ticket_by_channel, 'channel')),
                'values' => array_map('intval', array_column($ticket_by_channel, 'total')),
            ),
            'bar_technician' => array(
                'labels' => array_map(static function ($row) {
                    return (string) ($row['technician_name'] ?? '-');
                }, $technician_performance),
                'values' => array_map(static function ($row) {
                    return (int) ($row['resolved_total'] ?? 0);
                }, $technician_performance),
            ),
        );

        return array(
            'filter_month' => $month,
            'filter_year' => $year,
            'months' => $this->month_options(),
            'years' => $this->year_options(),
            'summary' => $summary,
            'avg_response_minutes' => $avg_response_minutes,
            'avg_resolve_minutes' => $avg_resolve_minutes,
            'resolution_rate' => $resolution_rate,
            'ticket_per_month' => $ticket_per_month,
            'ticket_by_status' => $ticket_by_status,
            'ticket_by_category' => $ticket_by_category,
            'ticket_by_channel' => $ticket_by_channel,
            'technician_performance' => $technician_performance,
            'top_customers' => $top_customers,
            'top_technician' => $top_technician,
            'chart_data' => $chart_data,
            'chart_data_json' => json_encode($chart_data),
            'is_low_resolution_rate' => $resolution_rate < 70,
        );
    }

    private function resolve_filter()
    {
        $month = (int) $this->input->get('month', true);
        $year = (int) $this->input->get('year', true);

        if ($month < 1 || $month > 12) {
            $month = (int) date('m');
        }

        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        return array($month, $year);
    }

    private function month_options()
    {
        $months = array();
        for ($i = 1; $i <= 12; $i++) {
            $months[$i] = date('F', mktime(0, 0, 0, $i, 1));
        }
        return $months;
    }

    private function year_options()
    {
        $current = (int) date('Y');
        $years = array();
        for ($i = $current - 3; $i <= $current + 1; $i++) {
            $years[] = $i;
        }
        return $years;
    }
}
