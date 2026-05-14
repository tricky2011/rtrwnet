<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Settings extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $method = strtolower((string) $this->router->fetch_method());
        $admin_allowed_methods = array(
            'telegram',
            'save_telegram_group',
            'delete_telegram_group',
            'test_telegram_dispatch',
        );
        if (in_array($method, $admin_allowed_methods, true)) {
            $this->require_role(
                array('superadmin', 'admin'),
                'Akses ditolak. Hanya superadmin/admin yang dapat mengelola Telegram Group.'
            );
        } else {
            $this->require_module_access(
                'settings',
                'Akses ditolak. Hanya superadmin yang dapat mengakses System Settings.'
            );
        }
        $this->load->database();
        $this->load->model('settings_model');
        $this->load->library(array('form_validation', 'session'));
        $this->load->helper(array('url', 'form', 'tenant'));
    }

    public function index()
    {
        return redirect('settings/routers');
    }

    public function mikrotik()
    {
        $this->load->view('settings/mikrotik', array(
            'setting_menu' => 'mikrotik',
            'data_form' => $this->settings_model->get_mikrotik_settings(),
        ));
    }

    public function save_mikrotik()
    {
        if (!$this->is_post_request()) {
            return;
        }

        $this->form_validation->set_rules('host', 'Host / IP', 'trim|required');
        $this->form_validation->set_rules('username', 'Username', 'trim|required');
        $this->form_validation->set_rules('api_port', 'Port API', 'trim|required|integer|greater_than[0]');

        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('settings/mikrotik');
        }

        $ok = $this->settings_model->save_mikrotik_settings(array(
            'host' => $this->input->post('host', true),
            'username' => $this->input->post('username', true),
            'password' => $this->input->post('password', true),
            'api_port' => (int) $this->input->post('api_port', true),
            'use_ssl' => $this->input->post('use_ssl', true) === '1' ? 1 : 0,
        ));

        if ($ok) {
            $this->session->set_flashdata('success', 'Setting MikroTik berhasil disimpan.');
        } else {
            $this->session->set_flashdata('error', 'Gagal simpan setting MikroTik. Pastikan tabel settings_mikrotik sudah dibuat.');
        }

        return redirect('settings/mikrotik');
    }

    public function test_mikrotik()
    {
        if (!$this->is_post_request()) {
            return;
        }

        $this->form_validation->set_rules('host', 'Host / IP', 'trim|required');
        $this->form_validation->set_rules('username', 'Username', 'trim|required');
        $this->form_validation->set_rules('password', 'Password', 'trim|required');
        $this->form_validation->set_rules('api_port', 'Port API', 'trim|required|integer|greater_than[0]');

        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('settings/mikrotik');
        }

        $this->load->library('mikrotik_api');
        $result = $this->mikrotik_api->test_connection(array(
            'host' => $this->input->post('host', true),
            'username' => $this->input->post('username', true),
            'password' => $this->input->post('password', true),
            'api_port' => (int) $this->input->post('api_port', true),
            'use_ssl' => $this->input->post('use_ssl', true) === '1' ? 1 : 0,
            'timeout' => 4,
            'retry_max' => 1,
            'retry_delay' => 0,
        ));

        $this->session->set_flashdata($result['success'] ? 'success' : 'error', $result['message']);
        return redirect('settings/mikrotik');
    }

    public function database()
    {
        $this->load->view('settings/database', array(
            'setting_menu' => 'database',
            'data_form' => $this->settings_model->get_database_settings(),
        ));
    }

    public function save_database()
    {
        if (!$this->is_post_request()) {
            return;
        }

        $this->form_validation->set_rules('db_host', 'DB Host', 'trim|required');
        $this->form_validation->set_rules('db_username', 'DB Username', 'trim|required');
        $this->form_validation->set_rules('db_name', 'DB Name', 'trim|required');

        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('settings/database');
        }

        $ok = $this->settings_model->save_database_settings(array(
            'db_host' => $this->input->post('db_host', true),
            'db_username' => $this->input->post('db_username', true),
            'db_password' => $this->input->post('db_password', true),
            'db_name' => $this->input->post('db_name', true),
        ));

        if ($ok) {
            $this->session->set_flashdata('success', 'Setting database berhasil disimpan.');
        } else {
            $this->session->set_flashdata('error', 'Gagal simpan setting database. Pastikan tabel settings_database sudah dibuat.');
        }

        return redirect('settings/database');
    }

    public function test_database()
    {
        if (!$this->is_post_request()) {
            return;
        }

        $this->form_validation->set_rules('db_host', 'DB Host', 'trim|required');
        $this->form_validation->set_rules('db_username', 'DB Username', 'trim|required');
        $this->form_validation->set_rules('db_name', 'DB Name', 'trim|required');

        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('settings/database');
        }

        $result = $this->settings_model->test_database_connection(array(
            'db_host' => $this->input->post('db_host', true),
            'db_username' => $this->input->post('db_username', true),
            'db_password' => $this->input->post('db_password', true),
            'db_name' => $this->input->post('db_name', true),
        ));

        $this->session->set_flashdata($result['success'] ? 'success' : 'error', $result['message']);
        return redirect('settings/database');
    }

    public function telegram()
    {
        $role = function_exists('normalizeRole')
            ? normalizeRole((string) $this->session->userdata('role'))
            : strtolower(trim((string) $this->session->userdata('role')));
        $is_superadmin = ($role === 'superadmin');
        $effective_router_id = $this->getEffectiveRouterId();
        $router_scope_id = $effective_router_id !== null ? (int) $effective_router_id : 0;

        $bots = $this->settings_model->get_telegram_bots();
        $groups = $this->settings_model->get_telegram_groups();
        $router_options = $this->settings_model->get_active_routers($is_superadmin ? null : ($router_scope_id > 0 ? $router_scope_id : null));
        $scoped_router_name = $router_scope_id > 0
            ? $this->settings_model->get_router_name_by_id($router_scope_id)
            : '';

        $this->load->view('settings/telegram', array(
            'setting_menu' => 'telegram',
            'data_form' => $this->settings_model->get_telegram_settings(),
            'telegram_bots' => $bots,
            'telegram_groups' => $groups,
            'router_options' => $router_options,
            'is_superadmin_user' => $is_superadmin,
            'scoped_router_id' => $router_scope_id,
            'scoped_router_name' => $scoped_router_name,
            'telegram_type_options' => array(
                'teknisi' => 'Teknisi',
                'admin' => 'Admin',
                'owner' => 'Owner',
                'alert' => 'Alert',
            ),
        ));
    }

    public function save_telegram()
    {
        if (!$this->is_post_request()) {
            return;
        }

        $this->form_validation->set_rules('chat_id_admin', 'Chat ID Admin', 'trim|required');
        if ($this->input->post('bot_token', true) !== '') {
            $this->form_validation->set_rules('bot_token', 'Bot Token', 'trim');
        }

        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('settings/telegram');
        }

        $ok = $this->settings_model->save_telegram_settings(array(
            'bot_token' => $this->input->post('bot_token', true),
            'chat_id_admin' => $this->input->post('chat_id_admin', true),
            'enable_notification' => $this->input->post('enable_notification', true) === '1' ? 1 : 0,
        ));

        if ($ok) {
            $this->session->set_flashdata('success', 'Setting Telegram berhasil disimpan.');
        } else {
            $this->session->set_flashdata('error', 'Gagal simpan setting Telegram legacy. Gunakan form Multi Bot/Group di bawah.');
        }

        return redirect('settings/telegram');
    }

    public function save_telegram_bot()
    {
        if (!$this->is_post_request()) {
            return;
        }

        $actor_role = function_exists('normalizeRole')
            ? normalizeRole((string) $this->session->userdata('role'))
            : strtolower(trim((string) $this->session->userdata('role')));

        $effective_router_id = $this->getEffectiveRouterId();
        $effective_router_id = $effective_router_id !== null ? (int) $effective_router_id : 0;

        $result = $this->settings_model->save_telegram_bot(array(
            'id' => (int) $this->input->post('id', true),
            'bot_name' => $this->input->post('bot_name', true),
            'bot_token' => $this->input->post('bot_token', true),
            'router_id' => (int) $this->input->post('router_id', true),
            'is_active' => $this->input->post('is_active', true) === '1' ? 1 : 0,
            'actor_role' => $actor_role,
            'actor_router_scope_id' => (int) $this->session->userdata('router_scope_id'),
            'actor_active_router_id' => $effective_router_id,
        ));

        $this->session->set_flashdata(!empty($result['success']) ? 'success' : 'error', (string) ($result['message'] ?? 'Unknown error.'));
        return redirect('settings/telegram');
    }

    public function delete_telegram_bot($id = 0)
    {
        if (!$this->is_post_request()) {
            return;
        }

        $result = $this->settings_model->delete_telegram_bot((int) $id);
        $this->session->set_flashdata(!empty($result['success']) ? 'success' : 'error', (string) ($result['message'] ?? 'Unknown error.'));
        return redirect('settings/telegram');
    }

    public function save_telegram_group()
    {
        if (!$this->is_post_request()) {
            return;
        }

        $actor_role = function_exists('normalizeRole')
            ? normalizeRole((string) $this->session->userdata('role'))
            : strtolower(trim((string) $this->session->userdata('role')));

        $effective_router_id = $this->getEffectiveRouterId();
        $effective_router_id = $effective_router_id !== null ? (int) $effective_router_id : 0;

        $result = $this->settings_model->save_telegram_group(array(
            'id' => (int) $this->input->post('id', true),
            'bot_id' => (int) $this->input->post('bot_id', true),
            'router_id' => (int) $this->input->post('router_id', true),
            'group_name' => $this->input->post('group_name', true),
            'chat_id' => $this->input->post('chat_id', true),
            'type' => $this->input->post('type', true),
            'is_active' => $this->input->post('is_active', true) === '1' ? 1 : 0,
            'actor_role' => $actor_role,
            'actor_router_scope_id' => (int) $this->session->userdata('router_scope_id'),
            'actor_active_router_id' => $effective_router_id,
        ));

        $this->session->set_flashdata(!empty($result['success']) ? 'success' : 'error', (string) ($result['message'] ?? 'Unknown error.'));
        return redirect('settings/telegram');
    }

    public function delete_telegram_group($id = 0)
    {
        if (!$this->is_post_request()) {
            return;
        }

        $result = $this->settings_model->delete_telegram_group((int) $id, array(
            'role' => function_exists('normalizeRole')
                ? normalizeRole((string) $this->session->userdata('role'))
                : strtolower(trim((string) $this->session->userdata('role'))),
            'router_scope_id' => (int) $this->session->userdata('router_scope_id'),
        ));
        $this->session->set_flashdata(!empty($result['success']) ? 'success' : 'error', (string) ($result['message'] ?? 'Unknown error.'));
        return redirect('settings/telegram');
    }

    public function test_telegram_dispatch()
    {
        if (!$this->is_post_request()) {
            return;
        }

        $type = strtolower(trim((string) $this->input->post('type', true)));
        $message = trim((string) $this->input->post('message', true));
        if ($type === '') {
            $type = 'admin';
        }
        if ($message === '') {
            $message = 'Test Telegram multi-chat dari RTRWNet (' . date('Y-m-d H:i:s') . ')';
        }
        $router_id = (int) $this->input->post('router_id', true);
        if ($router_id > 0) {
            $result = sendTelegramByRouter($router_id, $type, $message);
        } else {
            $result = sendTelegramByType($type, $message);
        }

        $flash_key = !empty($result['success']) ? 'success' : 'error';
        if (!empty($result['skipped']) || (!empty($result['success']) && (int) ($result['sent'] ?? 0) <= 0)) {
            $flash_key = 'error';
        }

        $this->session->set_flashdata($flash_key, (string) ($result['message'] ?? 'Unknown error.'));
        return redirect('settings/telegram');
    }

    public function test_telegram()
    {
        if (!$this->is_post_request()) {
            return;
        }

        $this->form_validation->set_rules('bot_token', 'Bot Token', 'trim|required');
        $this->form_validation->set_rules('chat_id_admin', 'Chat ID Admin', 'trim|required');
        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('settings/telegram');
        }

        $this->load->library('telegram_service');
        $result = $this->telegram_service->send_message(
            $this->input->post('bot_token', true),
            $this->input->post('chat_id_admin', true),
            'Test message dari RTRWNet (' . date('Y-m-d H:i:s') . ')',
            'HTML'
        );

        $this->session->set_flashdata($result['success'] ? 'success' : 'error', $result['message']);
        return redirect('settings/telegram');
    }

    public function pppoe_sync()
    {
        $selected_router_id = (int) $this->input->get('router_id', true);
        $redirect_url = 'router-sync';
        if ($selected_router_id > 0) {
            $redirect_url .= '?router_id=' . $selected_router_id;
        }
        return redirect($redirect_url);
    }

    public function save_pppoe_sync()
    {
        if (!$this->is_post_request()) {
            return;
        }

        $this->form_validation->set_rules('interval_minutes', 'Interval (minutes)', 'trim|required|integer|greater_than_equal_to[5]');
        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('router-sync');
        }

        $ok = $this->settings_model->save_pppoe_sync_settings(array(
            'auto_sync' => $this->input->post('auto_sync', true) === '1' ? 1 : 0,
            'interval_minutes' => (int) $this->input->post('interval_minutes', true),
        ));

        if ($ok) {
            $this->session->set_flashdata('success', 'Setting PPPoE Sync berhasil disimpan.');
        } else {
            $this->session->set_flashdata('error', 'Gagal simpan setting PPPoE Sync.');
        }

        return redirect('router-sync');
    }

    public function sync_pppoe_now($router_id = null)
    {
        return $this->sync_pppoe($router_id);
    }

    public function migrate_ppp_secret()
    {
        if (!$this->is_post_request()) {
            return;
        }

        $this->require_role(
            array('superadmin'),
            'Akses ditolak. Hanya superadmin yang dapat menjalankan migrasi customer.'
        );

        if (!$this->db->table_exists('customers')) {
            $msg = 'Tabel customers tidak ditemukan.';
            $this->settings_model->insert_sync_log('failed', $msg, 0, 'ppp_secret_migration', array(
                'total_found' => 0,
                'total_insert' => 0,
                'total_update' => 0,
                'total_failed' => 0,
                'sync_date' => date('Y-m-d H:i:s'),
            ));
            $this->session->set_flashdata('error', $msg);
            return redirect('router-sync');
        }

        $router_context = $this->resolve_router_for_pppoe_sync(null);
        if (empty($router_context['success'])) {
            $msg = (string) ($router_context['message'] ?? 'Router tidak valid untuk migrasi.');
            $this->settings_model->insert_sync_log('failed', $msg, 0, 'ppp_secret_migration', array(
                'total_found' => 0,
                'total_insert' => 0,
                'total_update' => 0,
                'total_failed' => 0,
                'sync_date' => date('Y-m-d H:i:s'),
            ));
            $this->session->set_flashdata('error', $msg);
            return redirect('router-sync');
        }

        $router_id = (int) $router_context['router_id'];
        $router_name = (string) ($router_context['router_name'] ?? ('Router #' . $router_id));
        $redirect_url = 'router-sync?router_id=' . $router_id;
        $this->load->helper('tenant');
        $connect = connectRouter($router_id);
        if (empty($connect['success']) || empty($connect['api'])) {
            $msg = 'API gagal terkoneksi ke router `' . $router_name . '`: ' . (string) ($connect['message'] ?? 'unknown');
            $this->settings_model->insert_sync_log('failed', $msg, 0, 'ppp_secret_migration', array(
                'total_found' => 0,
                'total_insert' => 0,
                'total_update' => 0,
                'total_failed' => 0,
                'sync_date' => date('Y-m-d H:i:s'),
            ));
            $this->session->set_flashdata('error', $msg);
            return redirect($redirect_url);
        }

        $api = $connect['api'];
        $debug_lines = array('migrate_router_id=' . $router_id, 'migrate_router_name=' . $router_name);

        try {
            $api_result = $this->load_pppoe_secrets_router_v6($api, $debug_lines);
            if (empty($api_result['success'])) {
                $msg = 'Gagal membaca /ppp/secret/print dari router `' . $router_name . '`.';
                $detail = trim((string) ($api_result['message'] ?? ''));
                if ($detail !== '') {
                    $msg .= ' ' . $detail;
                }

                $this->settings_model->insert_sync_log('failed', $msg, 0, 'ppp_secret_migration', array(
                    'total_found' => 0,
                    'total_insert' => 0,
                    'total_update' => 0,
                    'total_failed' => 0,
                    'sync_date' => date('Y-m-d H:i:s'),
                ));
                $this->session->set_flashdata('error', $msg);
                $this->session->set_flashdata('debug_raw', implode("\n", $debug_lines));
                return redirect($redirect_url);
            }

            $secrets = isset($api_result['data']) && is_array($api_result['data'])
                ? $api_result['data']
                : array();
            if (empty($secrets)) {
                $msg = 'Data PPP Secret kosong. Tidak ada data yang bisa dimigrasi.';
                $this->settings_model->insert_sync_log('failed', $msg, 0, 'ppp_secret_migration', array(
                    'total_found' => 0,
                    'total_insert' => 0,
                    'total_update' => 0,
                    'total_failed' => 0,
                    'sync_date' => date('Y-m-d H:i:s'),
                ));
                $this->session->set_flashdata('error', $msg);
                $this->session->set_flashdata('debug_raw', implode("\n", $debug_lines));
                return redirect($redirect_url);
            }

            $customer_fields = $this->db->list_fields('customers');
            $username_columns = array_values(array_intersect(
                array('username', 'pppoe_username', 'customer_code'),
                $customer_fields
            ));
            if (empty($username_columns)) {
                $msg = 'Tabel customers tidak memiliki kolom identity (username/pppoe_username/customer_code).';
                $this->settings_model->insert_sync_log('failed', $msg, 0, 'ppp_secret_migration', array(
                    'total_found' => (int) count($secrets),
                    'total_insert' => 0,
                    'total_update' => 0,
                    'total_failed' => (int) count($secrets),
                    'sync_date' => date('Y-m-d H:i:s'),
                ));
                $this->session->set_flashdata('error', $msg);
                return redirect($redirect_url);
            }

            $profile_map = $this->build_profile_map($router_id);
            $today = date('Y-m-d');
            $now = date('Y-m-d H:i:s');
            $total_found = count($secrets);
            $total_insert = 0;
            $total_skipped = 0;
            $total_failed = 0;

            foreach ($secrets as $row) {
                $row = (array) $row;
                $username = trim((string) $this->get_mikrotik_value($row, 'name'));
                if ($username === '') {
                    $total_skipped++;
                    continue;
                }

                if ($this->customer_exists_by_username($username, $username_columns, $router_id)) {
                    $total_skipped++;
                    continue;
                }

                $name_parts = $this->parse_secret_name($username);
                $pppoe_username = $username;
                $pppoe_password = trim((string) $this->get_mikrotik_value($row, 'password'));
                $profile_name = trim((string) $this->get_mikrotik_value($row, 'profile'));
                $profile_key = strtolower($profile_name);
                $profile_data = isset($profile_map[$profile_key]) ? $profile_map[$profile_key] : null;
                $profile_id = is_array($profile_data) ? (int) ($profile_data['id'] ?? 0) : null;
                if ($profile_id <= 0) {
                    $profile_id = null;
                }
                $package_price = is_array($profile_data) ? (float) ($profile_data['price'] ?? 0) : 0;
                $install_date = $this->parse_install_date_from_password($pppoe_password);
                $ip_address = $this->normalize_ipv4((string) $this->get_mikrotik_value($row, 'remote-address'));

                $payload = array();
                if (in_array('username', $customer_fields, true)) {
                    $payload['username'] = $username;
                }
                if (in_array('pppoe_username', $customer_fields, true)) {
                    $payload['pppoe_username'] = $pppoe_username;
                }
                if (in_array('pppoe_password', $customer_fields, true)) {
                    $payload['pppoe_password'] = $pppoe_password;
                }
                if (in_array('customer_code', $customer_fields, true)) {
                    $payload['customer_code'] = $username;
                }
                if (in_array('nama', $customer_fields, true)) {
                    $payload['nama'] = $name_parts['nama'];
                }
                if (in_array('full_name', $customer_fields, true)) {
                    $payload['full_name'] = $name_parts['nama'] !== null ? $name_parts['nama'] : $username;
                }
                if (in_array('lokasi', $customer_fields, true)) {
                    $payload['lokasi'] = $name_parts['lokasi'];
                }
                if (in_array('olt', $customer_fields, true)) {
                    $payload['olt'] = $name_parts['olt'];
                }
                if (in_array('profile_id', $customer_fields, true)) {
                    $payload['profile_id'] = $profile_id;
                }
                if (in_array('router_id', $customer_fields, true)) {
                    $payload['router_id'] = $router_id;
                }
                if (in_array('package_price', $customer_fields, true)) {
                    $payload['package_price'] = number_format($package_price, 2, '.', '');
                }
                if (in_array('install_date', $customer_fields, true) && $install_date !== null) {
                    $payload['install_date'] = $install_date;
                }
                if (in_array('join_date', $customer_fields, true)) {
                    $payload['join_date'] = $install_date !== null ? $install_date : $today;
                }
                if (in_array('ip_address', $customer_fields, true) && $ip_address !== '') {
                    $payload['ip_address'] = $ip_address;
                }
                if (in_array('status', $customer_fields, true)) {
                    $payload['status'] = 'active';
                }
                if (in_array('address', $customer_fields, true)) {
                    $payload['address'] = $name_parts['lokasi'] !== null ? $name_parts['lokasi'] : '-';
                }
                if (in_array('notes', $customer_fields, true)) {
                    $notes = array(
                        'Migrated from /ppp/secret',
                        'source_name=' . $username,
                        'lokasi=' . ($name_parts['lokasi'] !== null ? $name_parts['lokasi'] : '-'),
                        'olt=' . ($name_parts['olt'] !== null ? $name_parts['olt'] : '-'),
                        'profile=' . ($profile_name !== '' ? $profile_name : '-'),
                        'router=' . $router_name . ' (#' . $router_id . ')',
                        'package_price=' . number_format($package_price, 2, '.', ''),
                        'remote_ip=' . ($ip_address !== '' ? $ip_address : '-'),
                    );
                    $payload['notes'] = implode('; ', $notes);
                }
                if (in_array('created_at', $customer_fields, true)) {
                    $payload['created_at'] = $now;
                }
                if (in_array('updated_at', $customer_fields, true)) {
                    $payload['updated_at'] = $now;
                }

                if (empty($payload)) {
                    $total_failed++;
                    log_message('error', '[PPP_SECRET_MIGRATE] Payload kosong untuk username=' . $username);
                    continue;
                }

                $ok = $this->db->insert('customers', $payload);
                if (!$ok) {
                    $total_failed++;
                    $db_error = $this->db->error();
                    log_message(
                        'error',
                        '[PPP_SECRET_MIGRATE] DB INSERT ERROR username=' . $username
                        . ' error=' . json_encode($db_error)
                    );
                    continue;
                }

                $total_insert++;
            }

            $status = 'success';
            if ($total_failed > 0 && $total_insert > 0) {
                $status = 'partial';
            } elseif ($total_failed > 0 && $total_insert === 0) {
                $status = 'failed';
            }

            $message = 'Migrasi PPP Secret selesai. Found=' . $total_found
                . ', Inserted=' . $total_insert
                . ', Skipped=' . $total_skipped
                . ', Failed=' . $total_failed . '. Router=' . $router_name . ' (#' . $router_id . ').';

            $this->settings_model->insert_sync_log(
                $status,
                $message,
                (int) $total_insert,
                'ppp_secret_migration',
                array(
                    'total_found' => (int) $total_found,
                    'total_insert' => (int) $total_insert,
                    'total_update' => 0,
                    'total_failed' => (int) $total_failed,
                    'sync_date' => date('Y-m-d H:i:s'),
                )
            );

            log_message('info', '[PPP_SECRET_MIGRATE] ' . $message);
            $this->session->set_flashdata($status === 'failed' ? 'error' : 'success', $message);
            $this->session->set_flashdata('debug_raw', implode("\n", $debug_lines));
            return redirect($redirect_url);
        } catch (Throwable $e) {
            $msg = 'Migrasi PPP Secret gagal: ' . $e->getMessage();
            log_message('error', '[PPP_SECRET_MIGRATE] ' . $msg);
            $this->settings_model->insert_sync_log('failed', $msg, 0, 'ppp_secret_migration', array(
                'total_found' => 0,
                'total_insert' => 0,
                'total_update' => 0,
                'total_failed' => 0,
                'sync_date' => date('Y-m-d H:i:s'),
            ));
            $this->session->set_flashdata('error', $msg);
            $this->session->set_flashdata('debug_raw', implode("\n", $debug_lines));
            return redirect($redirect_url);
        } finally {
            if (is_object($api) && method_exists($api, 'disconnect')) {
                $api->disconnect();
            }
        }
    }

    public function sync_pppoe($router_id = null)
    {
        if (!$this->is_post_request()) {
            return;
        }

        $router_context = $this->resolve_router_for_pppoe_sync($router_id);
        if (empty($router_context['success'])) {
            $message = (string) ($router_context['message'] ?? 'Router tidak valid untuk sync.');
            $this->settings_model->insert_sync_log('failed', $message, 0, 'pppoe_router_sync', array(
                'total_found' => 0,
                'total_insert' => 0,
                'total_update' => 0,
                'total_failed' => 0,
                'sync_date' => date('Y-m-d H:i:s'),
            ));
            $this->session->set_flashdata('error', $message);
            return redirect('router-sync');
        }

        $router_id = (int) $router_context['router_id'];
        $router_name = (string) ($router_context['router_name'] ?? ('Router #' . $router_id));
        $redirect_url = 'router-sync?router_id=' . $router_id;

        $this->load->helper('tenant');
        $connect = connectRouter($router_id);
        if (empty($connect['success']) || empty($connect['api'])) {
            $message = 'API gagal terkoneksi ke router `' . $router_name . '`: ' . (string) ($connect['message'] ?? 'unknown');
            log_message('error', '[PPPOE_SYNC] ' . $message);
            $this->settings_model->insert_sync_log('failed', $message, 0, 'pppoe_router_sync', array(
                'total_found' => 0,
                'total_insert' => 0,
                'total_update' => 0,
                'total_failed' => 0,
                'sync_date' => date('Y-m-d H:i:s'),
            ));
            $this->session->set_flashdata('error', $message);
            return redirect($redirect_url);
        }

        $api = $connect['api'];
        $debug_lines = array(
            'router_id=' . $router_id,
            'router_name=' . $router_name,
        );

        try {
            $load = $this->load_pppoe_secrets_router_v6($api, $debug_lines);
            if (empty($load['success'])) {
                $message = (string) ($load['message'] ?? 'API tidak bisa membaca PPP secret.');
                $this->settings_model->insert_sync_log('failed', $message, 0, 'pppoe_router_sync', array(
                    'total_found' => 0,
                    'total_insert' => 0,
                    'total_update' => 0,
                    'total_failed' => 0,
                    'sync_date' => date('Y-m-d H:i:s'),
                ));
                $this->session->set_flashdata('debug_raw', implode("\n", $debug_lines));
                $this->session->set_flashdata('error', $message);
                return redirect($redirect_url);
            }

            $secrets = (array) ($load['data'] ?? array());
            if (empty($secrets)) {
                $message = 'API terkoneksi tapi data kosong pada router `' . $router_name . '`.';
                $this->settings_model->insert_sync_log('failed', $message, 0, 'pppoe_router_sync', array(
                    'total_found' => 0,
                    'total_insert' => 0,
                    'total_update' => 0,
                    'total_failed' => 0,
                    'sync_date' => date('Y-m-d H:i:s'),
                ));
                $this->session->set_flashdata('debug_raw', implode("\n", $debug_lines));
                $this->session->set_flashdata('error', $message);
                return redirect($redirect_url);
            }

            if (!$this->db->table_exists('pppoe_secrets')) {
                $message = 'Tabel pppoe_secrets tidak ditemukan.';
                $this->settings_model->insert_sync_log('failed', $message, 0, 'pppoe_router_sync', array(
                    'total_found' => count($secrets),
                    'total_insert' => 0,
                    'total_update' => 0,
                    'total_failed' => count($secrets),
                    'sync_date' => date('Y-m-d H:i:s'),
                ));
                $this->session->set_flashdata('debug_raw', implode("\n", $debug_lines));
                $this->session->set_flashdata('error', $message);
                return redirect($redirect_url);
            }

            $table_columns = $this->db->list_fields('pppoe_secrets');
            $required_columns = array('username', 'ppp_password', 'profile', 'service', 'disabled', 'comment', 'last_logged_out');
            if (in_array('router_id', $table_columns, true)) {
                $required_columns[] = 'router_id';
            }
            $missing_columns = array_values(array_diff($required_columns, $table_columns));
            if (!empty($missing_columns)) {
                $message = 'Kolom pppoe_secrets tidak lengkap: ' . implode(', ', $missing_columns) . '.';
                $this->settings_model->insert_sync_log('failed', $message, 0, 'pppoe_router_sync', array(
                    'total_found' => count($secrets),
                    'total_insert' => 0,
                    'total_update' => 0,
                    'total_failed' => count($secrets),
                    'sync_date' => date('Y-m-d H:i:s'),
                ));
                $this->session->set_flashdata('debug_raw', implode("\n", $debug_lines));
                $this->session->set_flashdata('error', $message);
                return redirect($redirect_url);
            }

            $total_found = count($secrets);
            $total_insert = 0;
            $total_update = 0;
            $total_failed = 0;
            $first_db_error = null;
            $sample_data = null;
            $has_router_column = in_array('router_id', $table_columns, true);
            $now = date('Y-m-d H:i:s');

            foreach ($secrets as $row) {
                $row = (array) $row;
                $username = trim((string) $this->get_mikrotik_value($row, 'name'));
                if ($username === '') {
                    $total_failed++;
                    continue;
                }

                $disabled_raw = strtolower(trim((string) $this->get_mikrotik_value($row, 'disabled', 'false')));
                $disabled = in_array($disabled_raw, array('true', '1', 'yes'), true) ? 1 : 0;

                $data = array(
                    'username' => $username,
                    'ppp_password' => (string) $this->get_mikrotik_value($row, 'password'),
                    'profile' => (string) $this->get_mikrotik_value($row, 'profile'),
                    'service' => (string) $this->get_mikrotik_value($row, 'service'),
                    'disabled' => $disabled,
                    'comment' => (string) $this->get_mikrotik_value($row, 'comment'),
                    'last_logged_out' => (string) $this->get_mikrotik_value($row, 'last-logged-out', $this->get_mikrotik_value($row, 'last_logged_out')),
                    'updated_at' => $now,
                );
                if ($has_router_column) {
                    $data['router_id'] = $router_id;
                }
                if ($data['comment'] === '') {
                    $data['comment'] = null;
                }
                if ($data['last_logged_out'] === '') {
                    $data['last_logged_out'] = null;
                }

                if ($sample_data === null) {
                    $sample_data = $data;
                    $safe_sample = $sample_data;
                    $safe_sample['ppp_password'] = $safe_sample['ppp_password'] !== '' ? '********' : '';
                    log_message('debug', '[PPPOE_SYNC] SAMPLE router_id=' . $router_id . ' data=' . json_encode($safe_sample));
                }

                $qb = $this->db->from('pppoe_secrets')->where('username', $username);
                if ($has_router_column) {
                    $qb->where('router_id', $router_id);
                }
                $existing = $qb->limit(1)->get()->row_array();

                if (!empty($existing)) {
                    $ok = $this->db->where('id', (int) $existing['id'])->update('pppoe_secrets', $data);
                    if ($ok) {
                        $total_update++;
                    } else {
                        $total_failed++;
                        $error = $this->db->error();
                        if ($first_db_error === null) {
                            $first_db_error = $error;
                        }
                        log_message('error', '[PPPOE_SYNC] UPDATE FAIL router_id=' . $router_id . ' username=' . $username . ' err=' . json_encode($error));
                    }
                    continue;
                }

                $insert_data = $data;
                if (in_array('created_at', $table_columns, true)) {
                    $insert_data['created_at'] = $now;
                }
                $ok = $this->db->insert('pppoe_secrets', $insert_data);
                if ($ok) {
                    $total_insert++;
                } else {
                    $total_failed++;
                    $error = $this->db->error();
                    if ($first_db_error === null) {
                        $first_db_error = $error;
                    }
                    log_message('error', '[PPPOE_SYNC] INSERT FAIL router_id=' . $router_id . ' username=' . $username . ' err=' . json_encode($error));
                }
            }

            $status = 'success';
            if ($total_failed > 0 && ($total_insert + $total_update) > 0) {
                $status = 'partial';
            } elseif ($total_failed > 0 && ($total_insert + $total_update) === 0) {
                $status = 'failed';
            }

            $first_db_error_text = '';
            if ($first_db_error !== null) {
                $first_db_error_text = trim((string) ($first_db_error['code'] ?? '') . ' ' . (string) ($first_db_error['message'] ?? ''));
            }

            $message = 'Sync PPPoE [' . $router_name . '] selesai. Found=' . $total_found
                . ', Insert=' . $total_insert
                . ', Update=' . $total_update
                . ', Failed=' . $total_failed . '.';
            if ($first_db_error_text !== '') {
                $message .= ' first_db_error=' . $first_db_error_text;
            }

            $debug_lines[] = 'found=' . $total_found;
            $debug_lines[] = 'insert=' . $total_insert;
            $debug_lines[] = 'update=' . $total_update;
            $debug_lines[] = 'failed=' . $total_failed;
            if ($first_db_error_text !== '') {
                $debug_lines[] = 'first_db_error=' . $first_db_error_text;
            }

            $this->settings_model->touch_last_sync_time();
            $this->settings_model->insert_sync_log($status, $message, (int) ($total_insert + $total_update), 'pppoe_router_sync', array(
                'total_found' => (int) $total_found,
                'total_insert' => (int) $total_insert,
                'total_update' => (int) $total_update,
                'total_failed' => (int) $total_failed,
                'sync_date' => date('Y-m-d H:i:s'),
            ));

            $this->session->set_flashdata('debug_raw', implode("\n", $debug_lines));
            $this->session->set_flashdata($status === 'failed' ? 'error' : 'success', $message);
            return redirect($redirect_url);
        } catch (Throwable $e) {
            $message = 'Sync PPPoE router `' . $router_name . '` gagal: ' . $e->getMessage();
            log_message('error', '[PPPOE_SYNC] ' . $message);
            $this->settings_model->insert_sync_log('failed', $message, 0, 'pppoe_router_sync', array(
                'total_found' => 0,
                'total_insert' => 0,
                'total_update' => 0,
                'total_failed' => 0,
                'sync_date' => date('Y-m-d H:i:s'),
            ));
            $this->session->set_flashdata('debug_raw', implode("\n", $debug_lines));
            $this->session->set_flashdata('error', $message);
            return redirect($redirect_url);
        } finally {
            if (is_object($api) && method_exists($api, 'disconnect')) {
                $api->disconnect();
            }
        }
    }

    private function resolve_router_for_pppoe_sync($router_id_raw = null)
    {
        $role = function_exists('normalizeRole')
            ? normalizeRole((string) $this->session->userdata('role'))
            : strtolower(trim((string) $this->session->userdata('role')));

        $requested = $router_id_raw;
        if ($requested === null || $requested === '') {
            $requested = $this->input->post('router_id', true);
        }
        if ($requested === null || $requested === '') {
            $requested = $this->input->get('router_id', true);
        }

        $requested_id = (int) $requested;
        $effective_id = $this->getEffectiveRouterId();
        $effective_id = $effective_id !== null ? (int) $effective_id : 0;

        if ($role !== 'superadmin') {
            $scoped_id = $effective_id > 0 ? $effective_id : (int) $this->session->userdata('router_scope_id');
            if ($scoped_id <= 0) {
                return array('success' => false, 'message' => 'Akun Anda belum memiliki router scope.');
            }
            return $this->resolve_router_context_by_id($scoped_id);
        }

        $target_id = $requested_id > 0 ? $requested_id : 0;
        if ($target_id <= 0 && $effective_id > 0) {
            $target_id = $effective_id;
        }
        if ($target_id <= 0) {
            $routers = $this->settings_model->get_active_routers();
            if (count($routers) === 1) {
                $target_id = (int) ($routers[0]['id'] ?? 0);
            }
        }

        if ($target_id <= 0) {
            return array('success' => false, 'message' => 'Pilih router terlebih dahulu sebelum sync PPPoE.');
        }

        return $this->resolve_router_context_by_id($target_id);
    }

    private function resolve_router_context_by_id($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return array('success' => false, 'message' => 'Router tidak valid.');
        }

        $router_fields = $this->db->list_fields('routers');
        $name_col = in_array('name', $router_fields, true)
            ? 'name'
            : (in_array('router_name', $router_fields, true) ? 'router_name' : null);

        $qb = $this->db->from('routers')->where('id', $router_id);
        if (in_array('is_active', $router_fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $router_fields, true)) {
            $qb->where('status', 'active');
        }

        $router = $qb->limit(1)->get()->row_array();
        if (empty($router)) {
            return array('success' => false, 'message' => 'Router tidak ditemukan atau nonaktif.');
        }

        $router_name = $name_col !== null ? trim((string) ($router[$name_col] ?? '')) : '';
        if ($router_name === '') {
            $router_name = 'Router #' . $router_id;
        }

        return array(
            'success' => true,
            'router_id' => $router_id,
            'router_name' => $router_name,
            'router' => $router,
        );
    }

    private function load_pppoe_secrets_router_v6($api, array &$debug_lines)
    {
        $normalize_rows = function ($rows) {
            $normalized = array();
            if (!is_array($rows)) {
                return $normalized;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (isset($row['!done']) || isset($row['ret']) || isset($row['!trap'])) {
                    continue;
                }

                $clean = array();
                foreach ($row as $key => $value) {
                    $clean_key = ltrim((string) $key, '=');
                    $clean[$clean_key] = $value;
                }

                $username = trim((string) ($clean['name'] ?? ''));
                if ($username !== '') {
                    $normalized[] = $clean;
                }
            }

            return $normalized;
        };

        // Primary RouterOS v6-compatible read.
        if (method_exists($api, 'comm')) {
            $rows = $api->comm('/ppp/secret/print');
            $parsed = $normalize_rows($rows);
            $debug_lines[] = 'primary_comm_rows=' . (is_array($rows) ? count($rows) : 0);
            $debug_lines[] = 'primary_parsed=' . count($parsed);
            if (!empty($parsed)) {
                return array('success' => true, 'data' => $parsed, 'method' => 'comm:/ppp/secret/print');
            }
        }

        // Fallback A: write + .proplist + parseResponse
        if (method_exists($api, 'write') && method_exists($api, 'read')) {
            $api->write('/ppp/secret/print', false);
            $api->write('=.proplist=name,password,profile,service,disabled,comment,last-logged-out', true);
            $raw = $api->read(false);
            $debug_lines[] = 'fallbackA_raw=' . (is_array($raw) ? count($raw) : 0);

            $parsed_raw = $raw;
            if (method_exists($api, 'parseResponse')) {
                $parsed_raw = $api->parseResponse($raw);
            }
            $parsed = $normalize_rows($parsed_raw);
            $debug_lines[] = 'fallbackA_parsed=' . count($parsed);
            if (!empty($parsed)) {
                return array('success' => true, 'data' => $parsed, 'method' => 'write_proplist');
            }
        }

        // Fallback B: without-paging
        if (method_exists($api, 'write') && method_exists($api, 'read')) {
            $api->write('/ppp/secret/print', false);
            $api->write('=without-paging=', true);
            $raw = $api->read(false);
            $debug_lines[] = 'fallbackB_raw=' . (is_array($raw) ? count($raw) : 0);

            $parsed_raw = $raw;
            if (method_exists($api, 'parseResponse')) {
                $parsed_raw = $api->parseResponse($raw);
            }
            $parsed = $normalize_rows($parsed_raw);
            $debug_lines[] = 'fallbackB_parsed=' . count($parsed);
            if (!empty($parsed)) {
                return array('success' => true, 'data' => $parsed, 'method' => 'write_without_paging');
            }
        }

        return array(
            'success' => false,
            'message' => 'PPPoE Secret tidak ditemukan atau API tidak memiliki akses.',
            'data' => array(),
        );
    }

    private function customer_exists_by_username($username, array $username_columns, $router_id = null)
    {
        $username = trim((string) $username);
        if ($username === '') {
            return false;
        }

        $qb = $this->db->from('customers');
        $has_where = false;
        foreach ($username_columns as $column) {
            if (!$has_where) {
                $qb->where($column, $username);
                $has_where = true;
            } else {
                $qb->or_where($column, $username);
            }
        }

        if (!$has_where) {
            return false;
        }

        $router_id = $router_id !== null ? (int) $router_id : 0;
        if ($router_id > 0 && $this->db->table_exists('customers')) {
            $customer_fields = $this->db->list_fields('customers');
            if (in_array('router_id', $customer_fields, true)) {
                $qb->where('router_id', $router_id);
            }
        }

        return $qb->count_all_results() > 0;
    }

    private function build_profile_map($router_id = null)
    {
        if (!$this->db->table_exists('ppp_profiles')) {
            return array();
        }

        $router_id = $router_id !== null ? (int) $router_id : 0;
        $qb = $this->db
            ->select('id, name, price')
            ->from('ppp_profiles');

        $fields = $this->db->list_fields('ppp_profiles');
        if ($router_id > 0 && in_array('router_id', $fields, true)) {
            $qb->where('router_id', $router_id);
        }

        $rows = $qb->get()->result_array();

        $map = array();
        foreach ($rows as $row) {
            $name = strtolower(trim((string) ($row['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $map[$name] = array(
                'id' => (int) ($row['id'] ?? 0),
                'price' => (float) ($row['price'] ?? 0),
            );
        }

        return $map;
    }

    private function parse_secret_name($username)
    {
        $parts = explode('-', trim((string) $username));
        $parts = array_values(array_filter($parts, static function ($value) {
            return trim((string) $value) !== '';
        }));

        $nama = isset($parts[0]) ? trim((string) $parts[0]) : null;
        $lokasi = isset($parts[1]) ? trim((string) $parts[1]) : null;
        $olt = null;
        if (count($parts) >= 3) {
            $olt = trim(implode('-', array_slice($parts, 2)));
        }

        return array(
            'nama' => $nama !== '' ? $nama : null,
            'lokasi' => $lokasi !== '' ? $lokasi : null,
            'olt' => $olt !== '' ? $olt : null,
        );
    }

    private function parse_install_date_from_password($password)
    {
        $password = trim((string) $password);
        if ($password === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $password)) {
            list($year, $month, $day) = explode('-', $password);
            if (checkdate((int) $month, (int) $day, (int) $year)) {
                return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
            }
        }

        if (preg_match('/^\d{8}$/', $password)) {
            $day = (int) substr($password, 0, 2);
            $month = (int) substr($password, 2, 2);
            $year = (int) substr($password, 4, 4);
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }

    private function normalize_ipv4($ip)
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '';
        }

        $ip = explode('/', $ip)[0];
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
    }

    private function to_boolean($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('1', 'true', 'yes', 'on'), true);
    }

    private function get_mikrotik_value(array $row, $key, $default = '')
    {
        $key = (string) $key;
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }

        $prefixed = '=' . $key;
        if (array_key_exists($prefixed, $row)) {
            return $row[$prefixed];
        }

        return $default;
    }

    private function is_post_request()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return false;
        }

        return true;
    }
}
