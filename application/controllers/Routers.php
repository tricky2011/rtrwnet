<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Routers extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'), 'Akses ditolak. Router Management hanya untuk admin.');
        $this->load->database();
        $this->load->model('router_model');
        $this->load->library(array('form_validation', 'session'));
        $this->load->helper(array('url', 'form', 'tenant'));
    }

    public function index()
    {
        if (!$this->router_model->table_exists()) {
            $this->session->set_flashdata('error', 'Tabel routers belum tersedia. Jalankan migration multi-router terlebih dahulu.');
            return $this->load->view('routers/list', array(
                'rows' => array(),
                'search' => '',
                'pagination' => '',
                'total_rows' => 0,
                'per_page' => 20,
                'per_page_options' => $this->get_per_page_options(),
            ));
        }

        $search = trim((string) $this->input->get('search', true));
        $total_rows = $this->router_model->count_filtered($search);
        $pager = $this->init_pagination('routers', $total_rows, 20, 3);
        $rows = $this->router_model->get_paginated($pager['per_page'], $pager['offset'], $search);

        return $this->load->view('routers/list', array(
            'rows' => $rows,
            'search' => $search,
            'pagination' => $pager['links'],
            'total_rows' => $pager['total_rows'],
            'per_page' => $pager['per_page'],
            'per_page_options' => $this->get_per_page_options(),
        ));
    }

    public function create()
    {
        $this->set_validation_rules(true);
        return $this->render_form('create');
    }

    public function store()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->router_model->table_exists()) {
            $this->session->set_flashdata('error', 'Tabel routers belum tersedia.');
            return redirect('routers');
        }

        $this->set_validation_rules(true);
        if ($this->form_validation->run() === false) {
            return $this->render_form('create');
        }

        $payload = $this->collect_payload();
        $logo_upload = $this->process_brand_logo_upload();
        if (empty($logo_upload['success'])) {
            $this->session->set_flashdata('error', (string) ($logo_upload['message'] ?? 'Upload logo gagal.'));
            return redirect('routers/create');
        }
        if (!empty($logo_upload['path'])) {
            $payload['brand_logo'] = (string) $logo_upload['path'];
        }
        if ($this->router_model->exists_name($payload['name'])) {
            $this->session->set_flashdata('error', 'Nama router sudah digunakan.');
            return redirect('routers/create');
        }

        $id = $this->router_model->insert($payload);
        if (!$id) {
            $err = $this->db->error();
            log_message('error', '[ROUTERS][STORE] gagal insert: ' . json_encode($err));
            $this->session->set_flashdata('error', 'Gagal menyimpan router.');
            return redirect('routers/create');
        }

        $this->session->set_flashdata('success', 'Router berhasil ditambahkan.');
        return redirect('routers');
    }

    public function edit($id = null)
    {
        $id = (int) $id;
        $row = $this->router_model->get_by_id($id);
        if (!$row) {
            $this->session->set_flashdata('error', 'Router tidak ditemukan.');
            return redirect('routers');
        }

        $this->set_validation_rules(false);
        return $this->render_form('edit', $row);
    }

    public function update($id = null)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $existing = $this->router_model->get_by_id($id);
        if (!$existing) {
            $this->session->set_flashdata('error', 'Router tidak ditemukan.');
            return redirect('routers');
        }

        $this->set_validation_rules(false);
        if ($this->form_validation->run() === false) {
            return $this->render_form('edit', $existing);
        }

        $payload = $this->collect_payload($existing);
        $logo_upload = $this->process_brand_logo_upload();
        if (empty($logo_upload['success'])) {
            $this->session->set_flashdata('error', (string) ($logo_upload['message'] ?? 'Upload logo gagal.'));
            return redirect('routers/edit/' . $id);
        }
        if (!empty($logo_upload['path'])) {
            $payload['brand_logo'] = (string) $logo_upload['path'];
        }
        if ($this->router_model->exists_name($payload['name'], $id)) {
            $this->session->set_flashdata('error', 'Nama router sudah digunakan.');
            return redirect('routers/edit/' . $id);
        }

        $ok = $this->router_model->update($id, $payload);
        if (!$ok) {
            $err = $this->db->error();
            log_message('error', '[ROUTERS][UPDATE] gagal update id ' . $id . ': ' . json_encode($err));
            $this->session->set_flashdata('error', 'Gagal update router.');
            return redirect('routers/edit/' . $id);
        }

        $this->session->set_flashdata('success', 'Router berhasil diupdate.');
        return redirect('routers');
    }

    public function delete($id = null)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $existing = $this->router_model->get_by_id($id);
        if (!$existing) {
            $this->session->set_flashdata('error', 'Router tidak ditemukan.');
            return redirect('routers');
        }

        $role = strtolower(trim((string) $this->session->userdata('role')));
        $ok = $this->router_model->delete_by_role($id, $role);
        if (!$ok) {
            $err = $this->db->error();
            log_message('error', '[ROUTERS][DELETE] gagal delete id ' . $id . ': ' . json_encode($err));
            $this->session->set_flashdata('error', 'Gagal menghapus router.');
            return redirect('routers');
        }

        if ($role === 'superadmin') {
            $this->session->set_flashdata('success', 'Router berhasil dihapus.');
        } else {
            $this->session->set_flashdata('success', 'Router dinonaktifkan (soft delete admin).');
        }

        return redirect('routers');
    }

    public function test_connection($id = null)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $row = $this->router_model->get_by_id($id);
        if (!$row) {
            $this->session->set_flashdata('error', 'Router tidak ditemukan.');
            return redirect('routers');
        }

        if (!function_exists('connectRouter')) {
            $this->session->set_flashdata('error', 'Helper connectRouter belum tersedia.');
            return redirect('routers');
        }

        $connect = connectRouter($id);
        if (empty($connect['success']) || empty($connect['api'])) {
            $this->session->set_flashdata('error', 'Test koneksi gagal: ' . (string) ($connect['message'] ?? 'unknown error'));
            return redirect('routers');
        }

        $api = $connect['api'];
        $identity = array();
        try {
            if (method_exists($api, 'comm')) {
                $identity = $api->comm('/system/identity/print');
            }
        } catch (Throwable $e) {
            log_message('error', '[ROUTERS][TEST] router id ' . $id . ' error: ' . $e->getMessage());
        }

        if (is_object($api) && method_exists($api, 'disconnect')) {
            $api->disconnect();
        }

        $name = '';
        if (is_array($identity) && !empty($identity[0]['name'])) {
            $name = (string) $identity[0]['name'];
        }
        if ($name === '') {
            $name = $row['name'];
        }

        $this->session->set_flashdata('success', 'Koneksi router berhasil: ' . $name);
        return redirect('routers');
    }

    private function render_form($mode = 'create', $row = null)
    {
        $row = is_array($row) ? $row : array(
            'id' => 0,
            'name' => '',
            'ip_address' => '',
            'api_port' => 8728,
            'username' => '',
            'description' => '',
            'is_active' => 1,
            'use_ssl' => 0,
            'timeout_seconds' => 5,
            'brand_name' => '',
            'brand_logo' => '',
            'brand_address' => '',
            'brand_phone' => '',
            'brand_email' => '',
            'brand_website' => '',
            'brand_bank_name' => '',
            'brand_bank_account' => '',
            'brand_bank_holder' => '',
            'invoice_footer' => '',
            'acs_url' => '',
            'acs_nbi_url' => '',
            'acs_username' => '',
            'acs_password' => '',
        );

        return $this->load->view('routers/form', array(
            'mode' => $mode,
            'row' => $row,
        ));
    }

    private function collect_payload(array $existing = null)
    {
        $existing = is_array($existing) ? $existing : array();

        return array(
            'name' => trim((string) $this->input->post('name', true)),
            'ip_address' => trim((string) $this->input->post('ip_address', true)),
            'api_port' => (int) $this->input->post('api_port', true),
            'username' => trim((string) $this->input->post('username', true)),
            'password' => (string) $this->input->post('password', true),
            'description' => trim((string) $this->input->post('description', true)),
            'use_ssl' => $this->input->post('use_ssl', true) ? 1 : 0,
            'timeout_seconds' => (int) $this->input->post('timeout_seconds', true),
            'is_active' => $this->input->post('is_active', true) ? 1 : 0,
            'brand_name' => trim((string) $this->input->post('brand_name', true)),
            'brand_logo' => trim((string) $this->input->post('brand_logo', true)),
            'brand_address' => trim((string) $this->input->post('brand_address', true)),
            'brand_phone' => trim((string) $this->input->post('brand_phone', true)),
            'brand_email' => trim((string) $this->input->post('brand_email', true)),
            'brand_website' => trim((string) $this->input->post('brand_website', true)),
            'brand_bank_name' => trim((string) $this->input->post('brand_bank_name', true)),
            'brand_bank_account' => trim((string) $this->input->post('brand_bank_account', true)),
            'brand_bank_holder' => trim((string) $this->input->post('brand_bank_holder', true)),
            'invoice_footer' => trim((string) $this->input->post('invoice_footer', true)),
            'acs_url' => trim((string) $this->input->post('acs_url', true)),
            'acs_nbi_url' => trim((string) $this->input->post('acs_nbi_url', true)),
            'acs_username' => trim((string) $this->input->post('acs_username', true)),
            'acs_password' => trim((string) $this->input->post('acs_password', true)),
        );
    }

    private function set_validation_rules($password_required = false)
    {
        $password_rule = $password_required ? 'required|min_length[3]' : 'min_length[3]';
        $this->form_validation->set_rules('name', 'Nama Router', 'trim|required|max_length[120]');
        $this->form_validation->set_rules('ip_address', 'Host / IP Router', 'trim|required|max_length[120]');
        $this->form_validation->set_rules('api_port', 'API Port', 'trim|required|integer|greater_than[0]');
        $this->form_validation->set_rules('username', 'Username API', 'trim|required|max_length[100]');
        $this->form_validation->set_rules('password', 'Password API', 'trim|' . $password_rule);
        $this->form_validation->set_rules('timeout_seconds', 'Timeout', 'trim|required|integer|greater_than[0]');
        $this->form_validation->set_rules('brand_name', 'Nama Brand', 'trim|max_length[150]');
        $this->form_validation->set_rules('brand_logo', 'Logo Brand', 'trim|max_length[255]');
        $this->form_validation->set_rules('brand_phone', 'No HP Brand', 'trim|max_length[50]');
        $this->form_validation->set_rules('brand_email', 'Email Brand', 'trim|valid_email|max_length[100]');
        $this->form_validation->set_rules('brand_website', 'Website Brand', 'trim|max_length[150]');
        $this->form_validation->set_rules('brand_bank_name', 'Nama Bank', 'trim|max_length[150]');
        $this->form_validation->set_rules('brand_bank_account', 'No Rekening', 'trim|max_length[100]');
        $this->form_validation->set_rules('brand_bank_holder', 'Nama Pemilik Rekening', 'trim|max_length[150]');
        $this->form_validation->set_rules('acs_url', 'ACS Inform URL', 'trim|max_length[255]');
        $this->form_validation->set_rules('acs_nbi_url', 'ACS NBI URL', 'trim|max_length[255]');
        $this->form_validation->set_rules('acs_username', 'ACS Username', 'trim|max_length[100]');
        $this->form_validation->set_rules('acs_password', 'ACS Password', 'trim|max_length[100]');
    }

    private function process_brand_logo_upload()
    {
        if (empty($_FILES['brand_logo_file']['name'])) {
            return array('success' => true, 'path' => null);
        }

        $upload_path = FCPATH . 'uploads/router-branding/';
        if (!is_dir($upload_path) && !@mkdir($upload_path, 0755, true) && !is_dir($upload_path)) {
            return array('success' => false, 'message' => 'Folder upload logo tidak dapat dibuat.');
        }

        $config = array(
            'upload_path' => $upload_path,
            'allowed_types' => 'jpg|jpeg|png|webp',
            'max_size' => 2048,
            'encrypt_name' => true,
        );

        $this->load->library('upload');
        $this->upload->initialize($config);

        if (!$this->upload->do_upload('brand_logo_file')) {
            return array(
                'success' => false,
                'message' => trim(strip_tags($this->upload->display_errors('', ''))),
            );
        }

        $upload_data = (array) $this->upload->data();
        $file_name = trim((string) ($upload_data['file_name'] ?? ''));
        if ($file_name === '') {
            return array('success' => false, 'message' => 'File logo tidak valid.');
        }

        return array(
            'success' => true,
            'path' => 'uploads/router-branding/' . $file_name,
        );
    }
}
