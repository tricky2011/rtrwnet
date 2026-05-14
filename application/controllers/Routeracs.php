<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Routeracs extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'), 'Akses ditolak. Config ACS hanya untuk admin.');
        $this->load->database();
        $this->load->model(array('router_model', 'settings_model'));
        $this->load->library(array('form_validation', 'session', 'encryption'));
        $this->load->helper(array('url', 'form'));
    }

    public function index()
    {
        if (!$this->router_model->table_exists()) {
            $this->session->set_flashdata('error', 'Tabel routers belum tersedia.');
            return $this->load->view('router/acs_index', array(
                'rows' => array(),
            ));
        }

        $rows = $this->router_model->get_paginated(1000, 0, '');
        foreach ($rows as &$row) {
            $row['acs_status'] = $this->normalize_status($row['acs_status'] ?? '');
            $row['acs_url'] = trim((string) ($row['acs_url'] ?? ''));
            $row['acs_nbi_url'] = trim((string) ($row['acs_nbi_url'] ?? ''));
        }
        unset($row);

        return $this->load->view('router/acs_index', array(
            'rows' => $rows,
        ));
    }

    public function edit($id = null)
    {
        $id = (int) $id;
        $row = $this->router_model->get_by_id($id);
        if (!$row) {
            $this->session->set_flashdata('error', 'Router tidak ditemukan.');
            return redirect('router-acs');
        }

        $this->set_validation_rules();
        return $this->load->view('router/acs_form', array(
            'row' => $row,
        ));
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
            return redirect('router-acs');
        }

        $this->set_validation_rules();
        if ($this->form_validation->run() === false) {
            return $this->load->view('router/acs_form', array(
                'row' => $existing,
            ));
        }

        $payload = array(
            'acs_url' => trim((string) $this->input->post('acs_url', true)),
            'acs_nbi_url' => trim((string) $this->input->post('acs_nbi_url', true)),
            'acs_username' => trim((string) $this->input->post('acs_username', true)),
            'acs_password' => trim((string) $this->input->post('acs_password', true)),
        );

        $ok = $this->router_model->update_acs($id, $payload);
        if (!$ok) {
            $this->session->set_flashdata('error', 'Gagal menyimpan konfigurasi ACS router.');
            return redirect('router-acs/edit/' . $id);
        }

        $this->session->set_flashdata('success', 'Konfigurasi ACS router berhasil diperbarui.');
        return redirect('router-acs');
    }

    public function test_connection($id = null)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $router = $this->router_model->get_by_id($id);
        if (!$router) {
            return $this->respond_test(array(
                'success' => false,
                'status' => 'disconnected',
                'message' => 'Router tidak ditemukan.',
                'http_code' => 404,
            ), $id);
        }

        $nbi_url = rtrim(trim((string) ($router['acs_nbi_url'] ?? '')), '/');
        if ($nbi_url === '') {
            $this->update_acs_status($id, 'disconnected');
            return $this->respond_test(array(
                'success' => false,
                'status' => 'disconnected',
                'message' => 'ACS NBI URL belum diisi.',
                'http_code' => 0,
            ), $id);
        }

        $test_url = $nbi_url . '/devices?limit=1';
        $username = trim((string) ($router['acs_username'] ?? ''));
        $password = $this->decrypt_secret_value((string) ($router['acs_password'] ?? ''));

        $ch = curl_init($test_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));

        if ($username !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        }

        if (stripos($test_url, 'https://') === 0) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $raw = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $connected = ($raw !== false && $http_code === 200);
        $status = $connected ? 'connected' : 'disconnected';
        $this->update_acs_status($id, $status);

        if ($connected) {
            $message = 'Koneksi ACS berhasil (HTTP 200).';
        } else {
            $message = 'Koneksi ACS gagal'
                . ($curl_error !== '' ? ': ' . $curl_error : ($http_code > 0 ? ' (HTTP ' . $http_code . ')' : '.'));

            $curl_error_lc = strtolower((string) $curl_error);
            if (strpos($curl_error_lc, 'no route to host') !== false) {
                $message .= ' Pastikan server aplikasi punya route ke host ACS. Jika NBI GenieACS ada di server ini, gunakan http://127.0.0.1:7557.';
            } elseif (strpos($curl_error_lc, 'connection refused') !== false) {
                $message .= ' Endpoint tidak menerima koneksi. Pastikan service genieacs-nbi berjalan dan port benar.';
            } elseif ($http_code === 405) {
                $message .= ' HTTP 405 biasanya karena URL mengarah ke port CWMP (7547). Untuk test NBI gunakan URL NBI (umumnya port 7557), contoh http://127.0.0.1:7557.';
            }
        }

        return $this->respond_test(array(
            'success' => $connected,
            'status' => $status,
            'message' => $message,
            'http_code' => $http_code,
        ), $id);
    }

    private function set_validation_rules()
    {
        $this->form_validation->set_rules('acs_url', 'ACS Inform URL', 'trim|required|valid_url|max_length[255]');
        $this->form_validation->set_rules('acs_nbi_url', 'ACS NBI URL', 'trim|required|valid_url|max_length[255]');
        $this->form_validation->set_rules('acs_username', 'ACS Username', 'trim|max_length[100]');
        $this->form_validation->set_rules('acs_password', 'ACS Password', 'trim|max_length[100]');
    }

    private function normalize_status($status)
    {
        $status = strtolower(trim((string) $status));
        if (!in_array($status, array('connected', 'disconnected'), true)) {
            return 'disconnected';
        }
        return $status;
    }

    private function update_acs_status($router_id, $status)
    {
        if (!$this->db->table_exists('routers')) {
            return;
        }

        $fields = $this->db->list_fields('routers');
        if (!in_array('acs_status', $fields, true)) {
            return;
        }

        $status = $this->normalize_status($status);
        $payload = array('acs_status' => $status);
        if (in_array('updated_at', $fields, true)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        $this->db->where('id', (int) $router_id)->update('routers', $payload);
    }

    private function decrypt_secret_value($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $decrypted = '';
        if (isset($this->settings_model) && method_exists($this->settings_model, 'decrypt_secret')) {
            $decrypted = (string) $this->settings_model->decrypt_secret($raw);
        }

        if ($decrypted !== '') {
            return $decrypted;
        }

        $fallback = $this->encryption->decrypt($raw);
        if (is_string($fallback) && trim($fallback) !== '') {
            return trim($fallback);
        }

        return $raw;
    }

    private function respond_test(array $payload, $router_id)
    {
        $accept = strtolower((string) $this->input->get_request_header('Accept'));
        $is_ajax = $this->input->is_ajax_request()
            || strpos($accept, 'application/json') !== false;

        if ($is_ajax) {
            $payload['csrf_name'] = $this->security->get_csrf_token_name();
            $payload['csrf_hash'] = $this->security->get_csrf_hash();
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode($payload));
        }

        if (!empty($payload['success'])) {
            $this->session->set_flashdata('success', (string) ($payload['message'] ?? 'Test koneksi ACS berhasil.'));
        } else {
            $this->session->set_flashdata('error', (string) ($payload['message'] ?? 'Test koneksi ACS gagal.'));
        }
        return redirect('router-acs/edit/' . (int) $router_id);
    }
}
