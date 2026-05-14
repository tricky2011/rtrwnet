<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Teknisi_dashboard extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin', 'teknisi'));
        $this->load->database();
        $this->load->helper(array('url', 'form'));
        $this->load->model('Teknisi_dashboard_model', 'teknisi_dashboard_model');
    }

    public function index()
    {
        $role = (string) $this->session->userdata('role');
        $user_id = (int) $this->session->userdata('user_id');
        $effective_router_id = $this->getEffectiveRouterId();

        if (method_exists($this->teknisi_dashboard_model, 'set_router_scope')) {
            $this->teknisi_dashboard_model->set_router_scope($effective_router_id, $this->is_superadmin());
        }

        $raw_filters = array(
            'month' => $this->input->get('month', true),
            'year' => $this->input->get('year', true),
            'period' => 'month',
            'technician_id' => $this->input->get('technician_id', true),
            'start_date' => '',
            'end_date' => '',
            'target_installation' => $this->input->get('target_installation', true),
            'target_ticket' => $this->input->get('target_ticket', true),
        );

        $filters = $this->teknisi_dashboard_model->normalize_filters($raw_filters, $role, $user_id, $effective_router_id);
        $payload = $this->teknisi_dashboard_model->get_dashboard_payload($filters);

        $chart_data_json = json_encode(
            $payload['charts'],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($chart_data_json === false) {
            $chart_data_json = '{}';
        }

        $view_data = array(
            'role' => $role,
            'filters' => $filters,
            'filter_query' => $this->public_filter_query($filters),
            'selected_period_label' => $this->format_period_label((int) $filters['month'], (int) $filters['year']),
            'months' => $this->month_options(),
            'years' => $this->year_options(),
            'teknisi_options' => $this->teknisi_dashboard_model->get_teknisi_options(),
            'can_filter_teknisi' => in_array($role, array('superadmin', 'admin'), true),
            'can_export' => in_array($role, array('superadmin', 'admin'), true),
            'kpi' => $payload['kpi'],
            'targets' => $payload['targets'],
            'work_order_rows' => $payload['work_order_rows'],
            'ticket_rows' => $payload['ticket_rows'],
            'ranking_rows' => $payload['ranking_rows'],
            'top_rank' => $payload['top_rank'],
            'selected_technician_name' => $payload['selected_technician_name'],
            'points_rule' => $payload['points_rule'],
            'chart_data' => $payload['charts'],
            'chart_data_json' => $chart_data_json,
        );

        $this->load->view('teknisi/dashboard', $view_data);
    }

    public function export_pdf()
    {
        $role = (string) $this->session->userdata('role');
        if (!in_array($role, array('superadmin', 'admin'), true)) {
            show_error('Akses export hanya untuk admin/superadmin.', 403);
            return;
        }

        $user_id = (int) $this->session->userdata('user_id');
        $effective_router_id = $this->getEffectiveRouterId();

        if (method_exists($this->teknisi_dashboard_model, 'set_router_scope')) {
            $this->teknisi_dashboard_model->set_router_scope($effective_router_id, $this->is_superadmin());
        }
        $raw_filters = array(
            'month' => $this->input->get('month', true),
            'year' => $this->input->get('year', true),
            'period' => 'month',
            'technician_id' => $this->input->get('technician_id', true),
            'start_date' => '',
            'end_date' => '',
            'target_installation' => $this->input->get('target_installation', true),
            'target_ticket' => $this->input->get('target_ticket', true),
        );

        $filters = $this->teknisi_dashboard_model->normalize_filters($raw_filters, $role, $user_id, $effective_router_id);
        $payload = $this->teknisi_dashboard_model->get_dashboard_payload($filters);

        $view_data = array(
            'filters' => $filters,
            'selected_period_label' => $this->format_period_label((int) $filters['month'], (int) $filters['year']),
            'kpi' => $payload['kpi'],
            'targets' => $payload['targets'],
            'work_order_rows' => $payload['work_order_rows'],
            'ticket_rows' => $payload['ticket_rows'],
            'ranking_rows' => $payload['ranking_rows'],
            'selected_technician_name' => $payload['selected_technician_name'],
            'points_rule' => $payload['points_rule'],
        );

        $html = $this->load->view('teknisi/dashboard_pdf', $view_data, true);

        if (!class_exists('Dompdf\\Dompdf')) {
            $autoload = FCPATH . 'vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }

        if (!class_exists('Dompdf\\Dompdf')) {
            $this->session->set_flashdata('error', 'Dompdf belum terpasang. Jalankan: composer require dompdf/dompdf');
            redirect('teknisi-dashboard?' . http_build_query($this->public_filter_query($filters)));
            return;
        }

        $dompdf = new \Dompdf\Dompdf(array('isRemoteEnabled' => true));
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = 'teknisi-dashboard-' . $filters['year'] . '-' . str_pad((string) $filters['month'], 2, '0', STR_PAD_LEFT) . '.pdf';
        $dompdf->stream($filename, array('Attachment' => false));
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

    private function period_options()
    {
        return array(
            'today' => 'Hari Ini',
            'week' => 'Minggu Ini',
            'month' => 'Bulan Ini',
        );
    }

    private function format_period_label($month, $year)
    {
        $month = (int) $month;
        $year = (int) $year;

        if ($month < 1 || $month > 12) {
            $month = (int) date('m');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        return date('F Y', mktime(0, 0, 0, $month, 1, $year));
    }

    private function public_filter_query(array $filters)
    {
        return array(
            'month' => (int) ($filters['month'] ?? date('m')),
            'year' => (int) ($filters['year'] ?? date('Y')),
            'period' => 'month',
            'technician_id' => (int) ($filters['technician_id'] ?? 0),
            'start_date' => '',
            'end_date' => '',
            'target_installation' => (int) ($filters['target_installation'] ?? 30),
            'target_ticket' => (int) ($filters['target_ticket'] ?? 50),
        );
    }
}
