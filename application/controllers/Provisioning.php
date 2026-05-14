<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Provisioning extends MY_Controller
{
    private $customer_fields = array();
    private $pppoe_fields = array();

    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->database();
        $this->load->library(array('form_validation', 'session'));
        $this->load->helper(array('url'));
        $this->load->model(array('settings_model'));

        if ($this->db->table_exists('customers')) {
            $this->customer_fields = $this->db->list_fields('customers');
        }
        if ($this->db->table_exists('pppoe_secrets')) {
            $this->pppoe_fields = $this->db->list_fields('pppoe_secrets');
        }
    }

    public function store()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(405, array(
                'success' => false,
                'message' => 'Method Not Allowed',
            ));
        }

        if (!$this->db->table_exists('technicians')) {
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Tabel technicians tidak ditemukan.',
            ));
        }
        if (!$this->db->table_exists('customers')) {
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Tabel customers tidak ditemukan.',
            ));
        }
        if (!$this->db->table_exists('pppoe_secrets')) {
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Tabel pppoe_secrets tidak ditemukan.',
            ));
        }
        $required_customer_columns = array('full_name', 'address', 'status', 'installation_status');
        $missing_customer_columns = array_values(array_diff($required_customer_columns, $this->customer_fields));
        if (!empty($missing_customer_columns)) {
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Kolom customers belum lengkap: ' . implode(', ', $missing_customer_columns),
            ));
        }
        $status_enum = $this->get_enum_values('customers', 'status');
        if (!empty($status_enum) && !in_array('pending', $status_enum, true)) {
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Kolom customers.status belum mendukung nilai `pending`.',
            ));
        }
        $install_enum = $this->get_enum_values('customers', 'installation_status');
        if (!empty($install_enum) && (!in_array('waiting', $install_enum, true) || !in_array('completed', $install_enum, true))) {
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Kolom customers.installation_status harus mendukung `waiting` dan `completed`.',
            ));
        }

        $this->form_validation->set_rules('full_name', 'Nama pelanggan', 'trim|required|min_length[3]');
        $this->form_validation->set_rules('address', 'Alamat', 'trim|required');
        $this->form_validation->set_rules('profile', 'Profile PPPoE', 'trim|required');
        $this->form_validation->set_rules('phone', 'No HP', 'trim');
        $this->form_validation->set_rules('email', 'Email', 'trim|valid_email');
        $this->form_validation->set_rules('latitude', 'Latitude', 'trim');
        $this->form_validation->set_rules('longitude', 'Longitude', 'trim');

        if ($this->form_validation->run() === false) {
            return $this->json_response(422, array(
                'success' => false,
                'message' => trim(strip_tags(validation_errors())),
            ));
        }

        $full_name = trim((string) $this->input->post('full_name', true));
        $address = trim((string) $this->input->post('address', true));
        $phone = trim((string) $this->input->post('phone', true));
        $email = trim((string) $this->input->post('email', true));
        $latitude = trim((string) $this->input->post('latitude', true));
        $longitude = trim((string) $this->input->post('longitude', true));
        $profile = trim((string) $this->input->post('profile', true));
        $service = trim((string) $this->input->post('service', true));
        if ($service === '') {
            $service = 'pppoe';
        }

        $technician = $this->assign_technician();
        if (empty($technician)) {
            return $this->json_response(404, array(
                'success' => false,
                'message' => 'Teknisi tidak ditemukan.',
            ));
        }

        try {
            $username = $this->generate_username($full_name);
            $password = $this->generate_password(8);
        } catch (Throwable $e) {
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Gagal generate credential: ' . $e->getMessage(),
            ));
        }

        $now = date('Y-m-d H:i:s');
        $customer_data = array(
            'full_name' => $full_name,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'address' => $address,
            'latitude' => $latitude !== '' ? $latitude : null,
            'longitude' => $longitude !== '' ? $longitude : null,
            'status' => 'pending',
            'installation_status' => 'waiting',
            'join_date' => date('Y-m-d'),
            'pppoe_username' => $username,
            'pppoe_password' => $password,
            'technician_id' => (int) $technician['id'],
            'created_at' => $now,
            'updated_at' => $now,
        );
        $customer_data = $this->filter_payload($customer_data, $this->customer_fields);

        $pppoe_data = array(
            'username' => $username,
            'ppp_password' => $password,
            'profile' => $profile,
            'service' => $service,
            'disabled' => 0,
            'comment' => 'AUTO-PROVISION ' . $full_name,
            'last_logged_out' => null,
            'created_at' => $now,
            'updated_at' => $now,
        );
        $pppoe_data = $this->filter_payload($pppoe_data, $this->pppoe_fields);

        $this->db->trans_begin();

        $ok_customer = $this->db->insert('customers', $customer_data);
        if (!$ok_customer) {
            $error = $this->db->error();
            $this->db->trans_rollback();
            log_message('error', '[PROVISIONING] customers insert failed: ' . json_encode($error));
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Gagal insert customers.',
                'error' => $error,
            ));
        }
        $customer_id = (int) $this->db->insert_id();

        if (in_array('customer_id', $this->pppoe_fields, true)) {
            $pppoe_data['customer_id'] = $customer_id;
        }

        $ok_pppoe = $this->db->insert('pppoe_secrets', $pppoe_data);
        if (!$ok_pppoe) {
            $error = $this->db->error();
            $this->db->trans_rollback();
            log_message('error', '[PROVISIONING] pppoe_secrets insert failed: ' . json_encode($error));
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Gagal insert pppoe_secrets.',
                'error' => $error,
            ));
        }

        $mk = $this->settings_model->get_mikrotik_settings();
        if ($mk['host'] === '' || $mk['username'] === '' || $mk['password'] === '') {
            $this->db->trans_rollback();
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Setting MikroTik belum lengkap.',
            ));
        }

        $this->load->library('mikrotik_api');
        $this->mikrotik_api->configure($mk);
        $mk_result = $this->mikrotik_api->command_safe('/ppp/secret/add', array(
            'name' => $username,
            'password' => $password,
            'profile' => $profile,
            'service' => $service,
            'comment' => 'CID:' . $customer_id,
        ));

        if (empty($mk_result['success'])) {
            $this->db->trans_rollback();
            $mk_error = (string) ($mk_result['error'] ?? 'Unknown MikroTik error');
            log_message('error', '[PROVISIONING] MikroTik add secret failed: ' . $mk_error);
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Rollback: gagal membuat PPP secret di MikroTik.',
                'error' => $mk_error,
            ));
        }

        if ($this->db->trans_status() === false) {
            $error = $this->db->error();
            $this->db->trans_rollback();
            log_message('error', '[PROVISIONING] transaction failed: ' . json_encode($error));
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Transaksi database gagal.',
                'error' => $error,
            ));
        }

        $this->db->trans_commit();

        $telegram_result = $this->send_telegram_with_button(
            $technician,
            array(
                'id' => $customer_id,
                'full_name' => $full_name,
                'phone' => $phone,
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'pppoe_username' => $username,
                'pppoe_password' => $password,
                'profile' => $profile,
                'service' => $service,
            )
        );

        if (empty($telegram_result['success'])) {
            log_message('error', '[PROVISIONING] Telegram send failed: ' . ($telegram_result['message'] ?? '-'));
        }

        return $this->json_response(200, array(
            'success' => true,
            'message' => 'Provisioning berhasil.',
            'data' => array(
                'customer_id' => $customer_id,
                'username' => $username,
                'password' => $password,
                'technician' => array(
                    'id' => (int) $technician['id'],
                    'name' => (string) $technician['name'],
                ),
                'telegram_sent' => !empty($telegram_result['success']),
            ),
        ));
    }

    public function generate_username($full_name)
    {
        $area_code = 'CST';

        $name_code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $full_name), 0, 3));
        if (strlen($name_code) < 3) {
            $name_code = str_pad($name_code, 3, 'X');
        }

        for ($i = 0; $i < 30; $i++) {
            $candidate = $area_code . '-' . $name_code . '-' . (string) random_int(100, 999);
            if (!$this->username_exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Username PPPoE unik tidak ditemukan setelah 30 percobaan.');
    }

    public function generate_password($length = 8)
    {
        $length = max(8, (int) $length);
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max_index = strlen($characters) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $characters[random_int(0, $max_index)];
        }

        return $password;
    }

    public function assign_technician()
    {
        $fallback = $this->db
            ->from('technicians')
            ->order_by('id', 'ASC')
            ->limit(1)
            ->get()
            ->row_array();

        return !empty($fallback) ? $fallback : null;
    }

    public function send_telegram_with_button(array $technician, array $customer)
    {
        $bot_token = $this->settings_model->get_telegram_settings()['bot_token'] ?? '';
        $chat_id = (string) ($technician['telegram_chat_id'] ?? '');
        if (trim($bot_token) === '' || trim($chat_id) === '') {
            return array(
                'success' => false,
                'message' => 'Bot token atau chat ID teknisi kosong.',
            );
        }

        $maps_link = '-';
        if (!empty($customer['latitude']) && !empty($customer['longitude'])) {
            $maps_link = 'https://maps.google.com/?q=' . $customer['latitude'] . ',' . $customer['longitude'];
        }

        $message = "<b>WO BARU - INSTALLATION</b>\n"
            . "Customer: <b>" . html_escape((string) $customer['full_name']) . "</b>\n"
            . "Alamat: " . html_escape((string) $customer['address']) . "\n"
            . "HP: " . html_escape((string) ($customer['phone'] ?? '-')) . "\n"
            . "PPPoE: <code>" . html_escape((string) $customer['pppoe_username']) . "</code>\n"
            . "Password: <code>" . html_escape((string) $customer['pppoe_password']) . "</code>\n"
            . "Profile: <code>" . html_escape((string) $customer['profile']) . "</code>\n"
            . "Map: " . $maps_link;

        $this->load->library('telegram_service');
        $result = $this->telegram_service->send_message(
            $bot_token,
            $chat_id,
            $message,
            'HTML'
        );

        log_message('info', '[PROVISIONING] Telegram message sent to technician_id='
            . (int) $technician['id'] . ' result=' . json_encode($result));

        return $result;
    }

    private function username_exists($username)
    {
        $exists_pppoe = $this->db
            ->from('pppoe_secrets')
            ->where('username', (string) $username)
            ->count_all_results() > 0;
        if ($exists_pppoe) {
            return true;
        }

        if (in_array('pppoe_username', $this->customer_fields, true)) {
            $exists_customer = $this->db
                ->from('customers')
                ->where('pppoe_username', (string) $username)
                ->count_all_results() > 0;
            if ($exists_customer) {
                return true;
            }
        }

        return false;
    }

    private function filter_payload(array $payload, array $allowed_fields)
    {
        $filtered = array();
        foreach ($payload as $key => $value) {
            if (in_array($key, $allowed_fields, true)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    private function build_callback_signature($customer_id)
    {
        $customer_id = (int) $customer_id;
        $secret = $this->get_provisioning_callback_secret();
        if ($secret === '') {
            return '';
        }

        return substr(hash_hmac('sha256', (string) $customer_id, $secret), 0, 16);
    }

    private function get_provisioning_callback_secret()
    {
        $secret = trim((string) getenv('PROVISIONING_CALLBACK_SECRET'));
        if ($secret !== '') {
            return $secret;
        }

        $secret = trim((string) config_item('provisioning_callback_secret'));
        if ($secret !== '') {
            return $secret;
        }

        return trim((string) config_item('encryption_key'));
    }

    private function json_response($http_code, array $payload)
    {
        return $this->output
            ->set_status_header((int) $http_code)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
    }

    private function get_enum_values($table, $column)
    {
        $table = trim((string) $table);
        $column = trim((string) $column);
        if ($table === '' || $column === '') {
            return array();
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return array();
        }
        if (!$this->db->table_exists($table)) {
            return array();
        }

        $sql = "SHOW COLUMNS FROM `" . $this->db->escape_str($table) . "` LIKE " . $this->db->escape($column);
        $row = $this->db->query($sql)->row_array();
        if (!$row || empty($row['Type'])) {
            return array();
        }

        $type = (string) $row['Type'];
        if (strpos($type, 'enum(') !== 0) {
            return array();
        }

        preg_match_all("/'([^']*)'/", $type, $matches);
        return isset($matches[1]) ? array_map('strtolower', $matches[1]) : array();
    }
}
