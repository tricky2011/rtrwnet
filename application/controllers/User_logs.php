<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_logs extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->helper(array('url', 'form'));
        $this->load->model('user_activity_log_model');

        $this->require_role(array('superadmin', 'admin'));
    }

    public function index()
    {
        $allowed_roles = $this->resolve_allowed_roles_for_viewer();

        if (!$this->db->table_exists('user_activity_logs')) {
            $this->session->set_flashdata('error', 'Tabel user_activity_logs belum tersedia. Jalankan migration user logs.');
            return $this->load->view('user_logs/index', array(
                'rows' => array(),
                'filters' => $this->get_filters(),
                'pagination' => '',
                'total_rows' => 0,
                'user_options' => $this->user_activity_log_model->get_user_options($allowed_roles),
                'per_page' => 20,
                'per_page_options' => $this->get_per_page_options(),
                'allowed_roles' => $allowed_roles,
            ));
        }

        $filters = $this->get_filters();
        $filters['allowed_roles'] = $allowed_roles;
        $total_rows = $this->user_activity_log_model->count_logs($filters);
        $pager = $this->init_pagination('user-logs', $total_rows, 20, 3);
        $rows = $this->user_activity_log_model->get_logs($filters, $pager['per_page'], $pager['offset']);

        return $this->load->view('user_logs/index', array(
            'rows' => $rows,
            'filters' => $filters,
            'pagination' => $pager['links'],
            'total_rows' => $pager['total_rows'],
            'user_options' => $this->user_activity_log_model->get_user_options($allowed_roles),
            'per_page' => $pager['per_page'],
            'per_page_options' => $this->get_per_page_options(),
            'allowed_roles' => $allowed_roles,
        ));
    }

    private function get_filters()
    {
        return array(
            'search' => trim((string) $this->input->get('search', true)),
            'user_id' => (int) $this->input->get('user_id', true),
            'controller' => strtolower(trim((string) $this->input->get('controller', true))),
            'method' => strtolower(trim((string) $this->input->get('method', true))),
            'date_from' => trim((string) $this->input->get('date_from', true)),
            'date_to' => trim((string) $this->input->get('date_to', true)),
        );
    }

    private function resolve_allowed_roles_for_viewer()
    {
        $viewer_role = strtolower(trim((string) $this->session->userdata('role')));
        if ($viewer_role === 'admin') {
            return array('admin', 'teknisi');
        }

        return array();
    }
}
