<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ont extends CI_Controller
{
    private $cfg = array();

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->config('tr069', true);
        $this->cfg = (array) $this->config->item('tr069');
        $this->load->model('tr069_acs_model');
    }

    public function set_wifi()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(405, array('success' => false, 'message' => 'Method Not Allowed'));
        }

        if (!$this->authorize_request()) {
            return;
        }

        $payload = $this->read_json_or_post();
        $customer_id = (int) ($payload['customer_id'] ?? 0);
        $ssid = trim((string) ($payload['ssid'] ?? ''));
        $password = trim((string) ($payload['password'] ?? ''));

        if ($customer_id <= 0 || $ssid === '' || $password === '') {
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'Field customer_id, ssid, password wajib diisi.',
            ));
        }

        if (strlen($password) < 8) {
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'Password WiFi minimal 8 karakter.',
            ));
        }

        $result = $this->tr069_acs_model->set_wifi($customer_id, $ssid, $password);
        if (empty($result['success'])) {
            return $this->json_response(400, $result);
        }

        return $this->json_response(200, $result);
    }

    public function reboot()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(405, array('success' => false, 'message' => 'Method Not Allowed'));
        }

        if (!$this->authorize_request()) {
            return;
        }

        $payload = $this->read_json_or_post();
        $customer_id = (int) ($payload['customer_id'] ?? 0);

        if ($customer_id <= 0) {
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'customer_id wajib diisi.',
            ));
        }

        $result = $this->tr069_acs_model->reboot_ont($customer_id);
        if (empty($result['success'])) {
            return $this->json_response(400, $result);
        }

        return $this->json_response(200, $result);
    }

    public function connected_devices()
    {
        if (strtoupper((string) $this->input->method()) !== 'GET') {
            return $this->json_response(405, array('success' => false, 'message' => 'Method Not Allowed'));
        }

        if (!$this->authorize_request()) {
            return;
        }

        $customer_id = (int) $this->input->get('customer_id', true);
        if ($customer_id <= 0) {
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'Query param customer_id wajib diisi.',
            ));
        }

        $result = $this->tr069_acs_model->get_connected_devices($customer_id);
        if (empty($result['success'])) {
            return $this->json_response(400, $result);
        }

        // Sesuai spesifikasi: return array hosts saja.
        $hosts = isset($result['data']['hosts']) && is_array($result['data']['hosts'])
            ? $result['data']['hosts']
            : array();

        return $this->json_response(200, array_values($hosts));
    }

    private function authorize_request()
    {
        $token = trim((string) ($this->cfg['tr069_api_token'] ?? ''));
        $basic_enabled = !empty($this->cfg['tr069_api_basic_auth_enabled']);

        if ($token === '' && !$basic_enabled) {
            $this->json_response(500, array(
                'success' => false,
                'message' => 'TR069 API security belum dikonfigurasi (token/basic auth kosong).',
            ));
            return false;
        }

        if ($token !== '' && $this->is_token_valid($token)) {
            return true;
        }

        if ($basic_enabled && $this->is_basic_auth_valid()) {
            return true;
        }

        $this->output
            ->set_status_header(401)
            ->set_header('WWW-Authenticate: Basic realm="TR069 API"')
            ->set_content_type('application/json')
            ->set_output(json_encode(array(
                'success' => false,
                'message' => 'Unauthorized',
            )));
        return false;
    }

    private function is_token_valid($expected_token)
    {
        $header_token = trim((string) $this->input->get_request_header('X-API-Key', true));
        if ($header_token === '') {
            $auth = trim((string) $this->input->get_request_header('Authorization', true));
            if (stripos($auth, 'Bearer ') === 0) {
                $header_token = trim(substr($auth, 7));
            }
        }

        return $header_token !== '' && hash_equals($expected_token, $header_token);
    }

    private function is_basic_auth_valid()
    {
        $expected_user = (string) ($this->cfg['tr069_api_basic_username'] ?? '');
        $expected_pass = (string) ($this->cfg['tr069_api_basic_password'] ?? '');
        if ($expected_user === '' || $expected_pass === '') {
            return false;
        }

        $creds = $this->read_basic_auth();
        if (empty($creds['username']) || !isset($creds['password'])) {
            return false;
        }

        return hash_equals($expected_user, (string) $creds['username'])
            && hash_equals($expected_pass, (string) $creds['password']);
    }

    private function read_basic_auth()
    {
        $username = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
        $password = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';

        if ($username !== '') {
            return array('username' => $username, 'password' => $password);
        }

        $header = trim((string) $this->input->get_request_header('Authorization', true));
        if (stripos($header, 'Basic ') !== 0) {
            return array();
        }

        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return array();
        }

        list($u, $p) = explode(':', $decoded, 2);
        return array('username' => $u, 'password' => $p);
    }

    private function read_json_or_post()
    {
        $raw = trim((string) $this->input->raw_input_stream);
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        $post = $this->input->post(NULL, true);
        return is_array($post) ? $post : array();
    }

    private function json_response($status, $data)
    {
        $this->output
            ->set_status_header((int) $status)
            ->set_content_type('application/json')
            ->set_output(json_encode($data));
        return;
    }
}
