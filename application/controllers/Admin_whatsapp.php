<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin_whatsapp extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_module_access('billing', 'Akses ditolak. Modul Billing hanya untuk superadmin/admin.');
        $this->load->database();
        $this->load->helper(array('url', 'form'));
        $this->load->library('Whatsapp_service');
        $this->load->model('Whatsapp_log_model', 'whatsapp_log_model');
    }

    public function index()
    {
        $filters = $this->collect_filters();
        $total_rows = $this->whatsapp_log_model->count_logs($filters);
        $pager = $this->init_pagination('admin-whatsapp', $total_rows, 20, 3);
        $rows = $this->whatsapp_log_model->get_logs($filters, $pager['per_page'], $pager['offset']);

        $this->load->view('admin/whatsapp_logs/index', array(
            'rows' => $rows,
            'filters' => $filters,
            'stats' => $this->whatsapp_log_model->get_stats(),
            'pagination' => $pager['links'],
            'total_rows' => $pager['total_rows'],
            'per_page' => $pager['per_page'],
            'per_page_options' => $this->get_per_page_options(),
            'table_ready' => $this->whatsapp_log_model->table_ready(),
        ));
    }

    public function detail($id)
    {
        $log = $this->whatsapp_log_model->get_log((int) $id);
        if (empty($log)) {
            show_404();
            return;
        }

        $this->load->view('admin/whatsapp_logs/detail', array(
            'log' => $log,
        ));
    }

    public function resend($id)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $result = $this->whatsapp_service->resend_message((int) $id);
        $this->session->set_flashdata(
            !empty($result['success']) ? 'success' : 'error',
            (string) ($result['message'] ?? (!empty($result['success']) ? 'Pesan masuk antrian.' : 'Kirim ulang gagal.'))
        );

        $return_to = trim((string) $this->input->post('return_to', true));
        if ($return_to !== '' && strpos($return_to, site_url()) === 0) {
            redirect($return_to);
            return;
        }

        redirect('admin-whatsapp');
    }

    private function collect_filters()
    {
        $status = strtolower(trim((string) $this->input->get('status', true)));
        if (!in_array($status, array('pending', 'processing', 'sent', 'failed'), true)) {
            $status = '';
        }

        $date_from = trim((string) $this->input->get('date_from', true));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $date_from = '';
        }

        $date_to = trim((string) $this->input->get('date_to', true));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $date_to = '';
        }

        return array(
            'status' => $status,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'customer_id' => max(0, (int) $this->input->get('customer_id', true)),
            'invoice_id' => max(0, (int) $this->input->get('invoice_id', true)),
            'search' => trim((string) $this->input->get('search', true)),
        );
    }
}
