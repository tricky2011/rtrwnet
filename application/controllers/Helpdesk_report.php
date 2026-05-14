<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Helpdesk_report extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->database();
        $this->load->helper(array('url', 'form'));
        $this->load->model('Ticket_model', 'ticket_model');
    }

    public function index()
    {
        $filters = $this->collect_filters();
        $rows = $this->ticket_model->get_tickets($filters, 2000, 0, (string) $this->session->userdata('role'), (int) $this->session->userdata('user_id'));

        $summary = array(
            'total' => count($rows),
            'open' => 0,
            'assigned' => 0,
            'progress' => 0,
            'resolved' => 0,
            'closed' => 0,
        );

        foreach ($rows as $row) {
            $status = strtoupper((string) ($row['status'] ?? 'OPEN'));
            if ($status === 'OPEN') {
                $summary['open']++;
            } elseif ($status === 'ASSIGNED') {
                $summary['assigned']++;
            } elseif ($status === 'PROGRESS') {
                $summary['progress']++;
            } elseif ($status === 'RESOLVED') {
                $summary['resolved']++;
            } elseif ($status === 'CLOSED') {
                $summary['closed']++;
            }
        }

        $this->load->view('helpdesk/dashboard', array(
            'report_mode' => true,
            'report_rows' => $rows,
            'report_summary' => $summary,
            'report_filters' => $filters,
            'cards' => array(
                'today_total' => 0,
                'open_total' => $summary['open'],
                'progress_total' => $summary['progress'],
                'urgent_total' => 0,
                'sla_breached' => 0,
            ),
            'status_chart' => array(),
            'breached_rows' => array(),
            'recent_rows' => array(),
        ));
    }

    public function export_pdf()
    {
        $filters = $this->collect_filters();
        $rows = $this->ticket_model->get_tickets($filters, 5000, 0, (string) $this->session->userdata('role'), (int) $this->session->userdata('user_id'));

        $html = $this->load->view('helpdesk/report_pdf', array(
            'rows' => $rows,
            'filters' => $filters,
            'generated_at' => date('Y-m-d H:i:s'),
        ), true);

        if (!class_exists('Dompdf\\Dompdf')) {
            $autoload = FCPATH . 'vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }

        if (!class_exists('Dompdf\\Dompdf')) {
            $this->session->set_flashdata('error', 'Dompdf belum terpasang. Jalankan: composer require dompdf/dompdf');
            redirect('helpdesk');
            return;
        }

        $dompdf = new \Dompdf\Dompdf(array('isRemoteEnabled' => true));
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = 'helpdesk-report-' . (int) $filters['year'] . '-' . str_pad((string) ((int) $filters['month']), 2, '0', STR_PAD_LEFT) . '.pdf';
        $dompdf->stream($filename, array('Attachment' => false));
    }

    private function collect_filters()
    {
        return array(
            'month' => $this->normalize_month((int) $this->input->get('month', true)),
            'year' => $this->normalize_year((int) $this->input->get('year', true)),
            'search' => trim((string) $this->input->get('search', true)),
            'status' => strtoupper(trim((string) $this->input->get('status', true))),
            'priority' => strtoupper(trim((string) $this->input->get('priority', true))),
            'olt_id' => (int) $this->input->get('olt_id', true),
            'assigned_to' => (int) $this->input->get('assigned_to', true),
        );
    }

    private function normalize_month($month)
    {
        $month = (int) $month;
        if ($month < 1 || $month > 12) {
            return (int) date('m');
        }
        return $month;
    }

    private function normalize_year($year)
    {
        $year = (int) $year;
        if ($year < 2000 || $year > 2100) {
            return (int) date('Y');
        }
        return $year;
    }
}
