<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->library(array('form_validation', 'session'));
        $this->load->helper(array('url', 'form', 'rbac'));
        $this->enforce_https_if_needed();
        $this->enforce_session_idle_timeout();
    }

    public function index()
    {
        return $this->login();
    }

    public function login()
    {
        if ($this->session->userdata('logged_in')) {
            $role = normalizeRole((string) $this->session->userdata('role'));
            if ($role === 'teknisi') {
                return redirect('teknisi-dashboard');
            }
            return redirect('dashboard');
        }

        $captcha = $this->issue_login_captcha();

        return $this->load->view('auth/login', array(
            'captcha_question' => $captcha['question'],
        ));
    }

    public function process_login()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $this->form_validation->set_rules('username', 'Username', 'trim|required');
        $this->form_validation->set_rules('password', 'Password', 'trim|required');
        $this->form_validation->set_rules('captcha_answer', 'Captcha', 'trim|required|integer');
        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('auth/login');
        }

        if (!$this->db->table_exists('users')) {
            $this->session->set_flashdata('error', 'Tabel users belum tersedia. Jalankan migration RBAC terlebih dahulu.');
            return redirect('auth/login');
        }

        $username = trim((string) $this->input->post('username', true));
        $password = (string) $this->input->post('password');
        $captcha_answer = trim((string) $this->input->post('captcha_answer', true));
        $wait_seconds = 0;

        if ($this->is_login_rate_limited($username, $wait_seconds)) {
            $wait_minutes = max(1, (int) ceil($wait_seconds / 60));
            $this->session->set_flashdata('error', 'Terlalu banyak percobaan login. Coba lagi dalam ' . $wait_minutes . ' menit.');
            return redirect('auth/login');
        }

        if (!$this->validate_login_captcha($captcha_answer)) {
            $this->record_failed_login_attempt($username);
            $this->session->set_flashdata('error', 'Captcha salah atau sudah kadaluarsa. Silakan coba lagi.');
            return redirect('auth/login');
        }

        $user = $this->db
            ->where('username', $username)
            ->where('status', 'active')
            ->limit(1)
            ->get('users')
            ->row();

        if (!$user || !password_verify($password, (string) $user->password)) {
            $this->record_failed_login_attempt($username);
            $this->session->set_flashdata('error', 'Username atau password salah.');
            return redirect('auth/login');
        }

        $normalized_role = normalizeRole((string) $user->role);
        if (!in_array($normalized_role, array('superadmin', 'admin', 'teknisi'), true)) {
            $this->record_failed_login_attempt($username);
            $this->session->set_flashdata('error', 'Role user tidak valid.');
            return redirect('auth/login');
        }

        $router_access_ids = array();
        $router_scope_id = null;
        if ($normalized_role !== 'superadmin') {
            $router_access_ids = $this->load_user_router_access_ids($user);
            $router_scope_id = !empty($router_access_ids) ? (int) $router_access_ids[0] : null;
        }

        $session_data = array(
            'user_id' => (int) $user->id,
            'name' => (string) $user->name,
            'role' => $normalized_role,
            'router_scope_id' => $router_scope_id,
            'router_access_ids' => $router_access_ids,
            'active_router' => $router_scope_id,
            'active_router_id' => $router_scope_id,
            'dashboard_router_id' => $router_scope_id,
            'logged_in' => true,
            'last_activity_ts' => time(),
            'user' => array(
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'role' => $normalized_role,
                'router_scope_id' => $router_scope_id,
                'router_access_ids' => $router_access_ids,
            ),
        );

        $this->session->sess_regenerate(true);
        $this->session->set_userdata($session_data);
        $this->clear_failed_login_attempt($username);

        $this->write_auth_activity_log(array(
            'user_id' => (int) $user->id,
            'user_name' => (string) $user->name,
            'user_role' => $normalized_role,
            'http_method' => 'POST',
            'action' => 'POST auth/process_login (success)',
            'controller' => 'auth',
            'method' => 'process_login',
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : 'auth/process_login',
            'query_string' => isset($_SERVER['QUERY_STRING']) ? trim((string) $_SERVER['QUERY_STRING']) : null,
            'payload_json' => null,
            'ip_address' => substr((string) $this->input->ip_address(), 0, 45),
            'user_agent' => substr((string) $this->input->user_agent(), 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ));

        if ($normalized_role === 'teknisi') {
            return redirect('teknisi-dashboard');
        }

        return redirect('dashboard');
    }

    private function load_user_router_access_ids($user)
    {
        if (!$user || empty($user->id)) {
            return array();
        }

        $ids = array();
        $user_id = (int) $user->id;

        if ($this->db->table_exists('user_router_access') && $this->db->table_exists('routers')) {
            $this->db
                ->select('ura.router_id')
                ->from('user_router_access ura')
                ->join('routers r', 'r.id = ura.router_id', 'inner')
                ->where('ura.user_id', $user_id)
                ->order_by('ura.router_id', 'ASC');

            $router_fields = $this->db->list_fields('routers');
            if (in_array('is_active', $router_fields, true)) {
                $this->db->where('r.is_active', 1);
            } elseif (in_array('status', $router_fields, true)) {
                $this->db->where('LOWER(r.status)', 'active');
            }

            $rows = $this->db->get()->result_array();
            foreach ($rows as $row) {
                $router_id = (int) ($row['router_id'] ?? 0);
                if ($router_id > 0) {
                    $ids[$router_id] = $router_id;
                }
            }
        }

        if (isset($user->router_scope_id)) {
            $legacy_id = (int) $user->router_scope_id;
            if ($legacy_id > 0) {
                $ids[$legacy_id] = $legacy_id;
            }
        }

        return array_values($ids);
    }

    public function logout()
    {
        $this->write_auth_activity_log(array(
            'user_id' => (int) $this->session->userdata('user_id'),
            'user_name' => (string) $this->session->userdata('name'),
            'user_role' => (string) $this->session->userdata('role'),
            'http_method' => 'GET',
            'action' => 'GET auth/logout',
            'controller' => 'auth',
            'method' => 'logout',
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : 'auth/logout',
            'query_string' => isset($_SERVER['QUERY_STRING']) ? trim((string) $_SERVER['QUERY_STRING']) : null,
            'payload_json' => null,
            'ip_address' => substr((string) $this->input->ip_address(), 0, 45),
            'user_agent' => substr((string) $this->input->user_agent(), 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ));

        $this->session->sess_destroy();
        return redirect('auth/login');
    }

    private function write_auth_activity_log(array $payload)
    {
        if (empty($payload) || !$this->db->table_exists('user_activity_logs')) {
            return false;
        }

        $allowed = array(
            'user_id', 'user_name', 'user_role',
            'http_method', 'action', 'controller', 'method',
            'request_uri', 'query_string', 'payload_json',
            'ip_address', 'user_agent', 'created_at',
        );
        $insert = array();
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $insert[$key] = $payload[$key];
            }
        }

        return !empty($insert) ? $this->db->insert('user_activity_logs', $insert) : false;
    }

    private function is_login_rate_limited($username, &$wait_seconds = 0)
    {
        $state = $this->read_login_attempt_state($username);
        if (empty($state['locked_until'])) {
            $wait_seconds = 0;
            return false;
        }

        $now = time();
        $locked_until = (int) $state['locked_until'];
        if ($locked_until <= $now) {
            $this->clear_failed_login_attempt($username);
            $wait_seconds = 0;
            return false;
        }

        $wait_seconds = max(1, $locked_until - $now);
        return true;
    }

    private function record_failed_login_attempt($username)
    {
        $username = strtolower(trim((string) $username));
        $state = $this->read_login_attempt_state($username);
        $now = time();

        $window_seconds = max(60, (int) $this->config->item('auth_login_window_seconds'));
        $max_attempts = max(3, (int) $this->config->item('auth_login_max_attempts'));
        $lock_seconds = max(60, (int) $this->config->item('auth_login_lock_seconds'));

        if (empty($state['first_attempt']) || ($now - (int) $state['first_attempt']) > $window_seconds) {
            $state = array(
                'first_attempt' => $now,
                'attempt_count' => 0,
                'last_attempt' => 0,
                'locked_until' => 0,
            );
        }

        $state['attempt_count'] = (int) ($state['attempt_count'] ?? 0) + 1;
        $state['last_attempt'] = $now;

        if ($state['attempt_count'] >= $max_attempts) {
            $state['locked_until'] = $now + $lock_seconds;
        }

        $this->write_login_attempt_state($username, $state);
    }

    private function clear_failed_login_attempt($username)
    {
        $file = $this->get_login_attempt_file($username);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function read_login_attempt_state($username)
    {
        $file = $this->get_login_attempt_file($username);
        if (!is_file($file)) {
            return array();
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return array();
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : array();
    }

    private function write_login_attempt_state($username, array $state)
    {
        $file = $this->get_login_attempt_file($username);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($file, json_encode($state), LOCK_EX);
    }

    private function get_login_attempt_file($username)
    {
        $ip = (string) $this->input->ip_address();
        $username = strtolower(trim((string) $username));
        $hash = hash('sha256', $ip . '|' . $username);
        $dir = APPPATH . 'cache/login_attempts';
        return $dir . '/' . $hash . '.json';
    }

    private function issue_login_captcha()
    {
        $operator = random_int(0, 1) === 1 ? '+' : '-';
        if ($operator === '+') {
            $left = random_int(1, 20);
            $right = random_int(1, 20);
            $answer = $left + $right;
        } else {
            $left = random_int(5, 20);
            $right = random_int(1, $left);
            $answer = $left - $right;
        }

        $question = $left . ' ' . $operator . ' ' . $right . ' = ?';
        $this->session->set_userdata(array(
            'login_captcha_question' => $question,
            'login_captcha_answer' => (string) $answer,
            'login_captcha_issued_at' => time(),
        ));

        return array(
            'question' => $question,
            'answer' => $answer,
        );
    }

    private function validate_login_captcha($input_answer)
    {
        $expected = $this->session->userdata('login_captcha_answer');
        $issued_at = (int) $this->session->userdata('login_captcha_issued_at');
        $this->session->unset_userdata(array(
            'login_captcha_question',
            'login_captcha_answer',
            'login_captcha_issued_at',
        ));

        if (!is_string($expected) || $expected === '' || $issued_at <= 0) {
            return false;
        }

        if ((time() - $issued_at) > 300) {
            return false;
        }

        $input_answer = trim((string) $input_answer);
        if ($input_answer === '' || !preg_match('/^-?\d+$/', $input_answer)) {
            return false;
        }

        return hash_equals($expected, $input_answer);
    }

    private function enforce_session_idle_timeout()
    {
        if (!$this->session->userdata('logged_in')) {
            return;
        }

        $idle_timeout = max(60, (int) $this->config->item('auth_session_idle_timeout'));
        $now = time();
        $last_activity = (int) $this->session->userdata('last_activity_ts');

        if ($last_activity > 0 && ($now - $last_activity) >= $idle_timeout) {
            $this->session->unset_userdata(array(
                'user_id',
                'name',
                'role',
                'logged_in',
                'user',
                'last_activity_ts',
            ));
            $this->session->sess_regenerate(true);
            $this->session->set_flashdata('error', 'Sesi berakhir otomatis setelah 10 menit tanpa aktivitas. Silakan login kembali.');
            redirect('auth/login');
            exit;
        }

        $this->session->set_userdata('last_activity_ts', $now);
    }

    private function enforce_https_if_needed()
    {
        if (is_cli()) {
            return;
        }

        if (!$this->config->item('force_https')) {
            return;
        }

        $is_https = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        );
        if ($is_https) {
            return;
        }

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        if ($host === '') {
            return;
        }

        redirect('https://' . $host . $uri, 'location', 301);
        exit;
    }
}
