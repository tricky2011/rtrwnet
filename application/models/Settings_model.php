<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Settings_model extends CI_Model
{
    private $secret_cipher = 'AES-256-CBC';
    private $pppoe_debug_mode = false;
    private $pppoe_sample_shown = false;
    private $table_fields_cache = array();

    public function __construct()
    {
        parent::__construct();
        $this->load->library('encryption');
    }

    public function get_mikrotik_settings($router_id = 0)
    {
        $router_id = (int) $router_id;

        if ($router_id > 0) {
            $router_settings = $this->get_mikrotik_settings_from_router_table($router_id);
            if (!empty($router_settings)) {
                return $router_settings;
            }
        }

        $row = $this->get_single_row('settings_mikrotik', array(
            'host' => '',
            'username' => '',
            'password_enc' => '',
            'api_port' => 8728,
            'use_ssl' => 0,
        ));

        $legacy = array(
            'host' => (string) $row['host'],
            'username' => (string) $row['username'],
            'password' => $this->decrypt_secret($row['password_enc']),
            'api_port' => (int) $row['api_port'],
            'use_ssl' => (int) $row['use_ssl'],
        );

        if ($legacy['host'] !== '' && $legacy['username'] !== '' && $legacy['password'] !== '') {
            return $legacy;
        }

        $router_fallback = $this->get_mikrotik_settings_from_router_table(0);
        if (!empty($router_fallback)) {
            return $router_fallback;
        }

        $this->config->load('mikrotik', true);
        $mk_cfg = (array) $this->config->item('mikrotik');
        if (empty($mk_cfg)) {
            $mk_cfg = array(
                'mikrotik_host' => (string) config_item('mikrotik_host'),
                'mikrotik_user' => (string) config_item('mikrotik_user'),
                'mikrotik_pass' => (string) config_item('mikrotik_pass'),
                'mikrotik_port' => (int) config_item('mikrotik_port'),
                'mikrotik_ssl' => (int) config_item('mikrotik_ssl'),
            );
        }

        $config_fallback = array(
            'host' => trim((string) ($mk_cfg['mikrotik_host'] ?? '')),
            'username' => trim((string) ($mk_cfg['mikrotik_user'] ?? '')),
            'password' => (string) ($mk_cfg['mikrotik_pass'] ?? ''),
            'api_port' => (int) ($mk_cfg['mikrotik_port'] ?? 8728),
            'use_ssl' => !empty($mk_cfg['mikrotik_ssl']) ? 1 : 0,
        );
        if ($config_fallback['host'] !== '' && $config_fallback['username'] !== '' && $config_fallback['password'] !== '') {
            return $config_fallback;
        }

        return $legacy;
    }

    public function save_mikrotik_settings(array $data)
    {
        $current = $this->get_mikrotik_settings();
        $password = array_key_exists('password', $data)
            ? trim((string) $data['password'])
            : '';

        $payload = array(
            'host' => trim((string) ($data['host'] ?? $current['host'])),
            'username' => trim((string) ($data['username'] ?? $current['username'])),
            'api_port' => (int) ($data['api_port'] ?? $current['api_port']),
            'use_ssl' => !empty($data['use_ssl']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        );

        if ($password !== '') {
            $password_enc = $this->encrypt_secret($password);
            if ($password_enc === '') {
                return false;
            }
            $payload['password_enc'] = $password_enc;
        } else {
            $existing = $this->get_single_row('settings_mikrotik', array('password_enc' => ''));
            $payload['password_enc'] = (string) ($existing['password_enc'] ?? '');
        }

        return $this->upsert_single_row('settings_mikrotik', $payload);
    }

    private function get_mikrotik_settings_from_router_table($router_id = 0)
    {
        if (!$this->db->table_exists('routers')) {
            return array();
        }

        $fields = $this->db->list_fields('routers');
        if (empty($fields)) {
            return array();
        }

        $name_col = in_array('name', $fields, true) ? 'name' : (in_array('router_name', $fields, true) ? 'router_name' : null);
        $host_candidates = array_values(array_filter(array('ip_address', 'api_host', 'host'), static function ($col) use ($fields) {
            return in_array($col, $fields, true);
        }));
        $user_candidates = array_values(array_filter(array('username', 'api_username', 'user'), static function ($col) use ($fields) {
            return in_array($col, $fields, true);
        }));
        $pass_candidates = array_values(array_filter(array('password', 'api_password_enc', 'pass', 'password_enc'), static function ($col) use ($fields) {
            return in_array($col, $fields, true);
        }));

        if (empty($host_candidates) || empty($user_candidates) || empty($pass_candidates)) {
            return array();
        }

        $qb = $this->db->from('routers');
        $router_id = (int) $router_id;
        if ($router_id > 0) {
            $qb->where('id', $router_id);
        }
        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        if ($router_id <= 0) {
            if (in_array('is_active', $fields, true)) {
                $qb->order_by('is_active', 'DESC');
            } elseif (in_array('status', $fields, true)) {
                $qb->order_by("CASE WHEN status='active' THEN 0 ELSE 1 END", 'ASC', false);
            }
            $qb->order_by('id', 'ASC');
        }

        $row = $qb->limit(1)->get()->row_array();
        if (empty($row)) {
            return array();
        }

        $host = $this->pick_first_non_empty_value($row, $host_candidates);
        $username = $this->pick_first_non_empty_value($row, $user_candidates);

        $password = '';
        foreach ($pass_candidates as $pass_col) {
            $raw = trim((string) ($row[$pass_col] ?? ''));
            if ($raw === '') {
                continue;
            }

            $decrypted = $this->decrypt_secret($raw);
            if ($decrypted === '') {
                $fallback = $this->encryption->decrypt($raw);
                if (is_string($fallback) && $fallback !== '') {
                    $decrypted = $fallback;
                }
            }

            $password = $decrypted !== '' ? $decrypted : $raw;
            if ($password !== '') {
                break;
            }
        }

        if ($host === '' || $username === '' || $password === '') {
            return array();
        }

        $label = '';
        if ($name_col !== null) {
            $label = trim((string) ($row[$name_col] ?? ''));
        }

        return array(
            'host' => $host,
            'username' => $username,
            'password' => $password,
            'api_port' => (int) ($row['api_port'] ?? 8728),
            'use_ssl' => !empty($row['use_ssl']) ? 1 : 0,
            'router_id' => (int) ($row['id'] ?? 0),
            'router_name' => $label,
        );
    }

    private function pick_first_non_empty_value(array $row, array $columns)
    {
        foreach ($columns as $column) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    public function get_database_settings()
    {
        $row = $this->get_single_row('settings_database', array(
            'db_host' => '',
            'db_username' => '',
            'db_password_enc' => '',
            'db_name' => '',
        ));

        return array(
            'db_host' => (string) $row['db_host'],
            'db_username' => (string) $row['db_username'],
            'db_password' => $this->decrypt_secret($row['db_password_enc']),
            'db_name' => (string) $row['db_name'],
        );
    }

    public function save_database_settings(array $data)
    {
        $current = $this->get_database_settings();
        $password = array_key_exists('db_password', $data)
            ? trim((string) $data['db_password'])
            : '';

        $payload = array(
            'db_host' => trim((string) ($data['db_host'] ?? $current['db_host'])),
            'db_username' => trim((string) ($data['db_username'] ?? $current['db_username'])),
            'db_name' => trim((string) ($data['db_name'] ?? $current['db_name'])),
            'updated_at' => date('Y-m-d H:i:s'),
        );

        if ($password !== '') {
            $password_enc = $this->encrypt_secret($password);
            if ($password_enc === '') {
                return false;
            }
            $payload['db_password_enc'] = $password_enc;
        } else {
            $existing = $this->get_single_row('settings_database', array('db_password_enc' => ''));
            $payload['db_password_enc'] = (string) ($existing['db_password_enc'] ?? '');
        }

        return $this->upsert_single_row('settings_database', $payload);
    }

    public function get_telegram_settings()
    {
        $row = $this->get_single_row('settings_telegram', array(
            'bot_token_enc' => '',
            'chat_id_admin' => '',
            'enable_notification' => 0,
        ));

        $data = array(
            'bot_token' => $this->decrypt_secret($row['bot_token_enc']),
            'chat_id_admin' => (string) $row['chat_id_admin'],
            'enable_notification' => (int) $row['enable_notification'],
        );

        if ($data['bot_token'] !== '' && $data['chat_id_admin'] !== '') {
            return $data;
        }

        $fallback = $this->resolve_default_telegram_from_multi_tables();
        if (!empty($fallback['bot_token'])) {
            $data['bot_token'] = (string) $fallback['bot_token'];
        }
        if (!empty($fallback['chat_id_admin'])) {
            $data['chat_id_admin'] = (string) $fallback['chat_id_admin'];
        }
        if (!empty($fallback['enable_notification'])) {
            $data['enable_notification'] = 1;
        }

        return $data;
    }

    public function save_telegram_settings(array $data)
    {
        $current = $this->get_telegram_settings();
        $bot_token = array_key_exists('bot_token', $data)
            ? trim((string) $data['bot_token'])
            : '';

        $payload = array(
            'chat_id_admin' => trim((string) ($data['chat_id_admin'] ?? $current['chat_id_admin'])),
            'enable_notification' => !empty($data['enable_notification']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        );

        if ($bot_token !== '') {
            $bot_token_enc = $this->encrypt_secret($bot_token);
            if ($bot_token_enc === '') {
                return false;
            }
            $payload['bot_token_enc'] = $bot_token_enc;
        } else {
            $existing = $this->get_single_row('settings_telegram', array('bot_token_enc' => ''));
            $payload['bot_token_enc'] = (string) ($existing['bot_token_enc'] ?? '');
        }

        return $this->upsert_single_row('settings_telegram', $payload);
    }

    public function get_telegram_bots()
    {
        if (!$this->db->table_exists('telegram_bots')) {
            return array();
        }

        $fields = $this->db->list_fields('telegram_bots');
        $actor = $this->resolve_settings_actor_context();
        $qb = $this->db
            ->from('telegram_bots')
            ->order_by('is_active', 'DESC')
            ->order_by('id', 'ASC');

        if (in_array('router_id', $fields, true)) {
            if ($actor['role'] === 'superadmin' && (int) $actor['active_router_id'] > 0) {
                $qb->where('router_id', (int) $actor['active_router_id']);
            } elseif ($actor['role'] !== 'superadmin') {
                if ((int) $actor['router_scope_id'] > 0) {
                    $qb->where('router_id', (int) $actor['router_scope_id']);
                } else {
                    $qb->where('1 = 0', null, false);
                }
            }
        }

        $rows = $qb->get()->result_array();

        foreach ($rows as &$row) {
            $raw = trim((string) ($row['bot_token'] ?? ''));
            $token = $this->decrypt_secret($raw);
            if ($token === '') {
                $token = $raw;
            }
            $len = strlen($token);
            $row['token_preview'] = $len > 12
                ? substr($token, 0, 6) . str_repeat('*', max(4, $len - 10)) . substr($token, -4)
                : str_repeat('*', max(6, $len));
        }
        unset($row);

        return $rows;
    }

    public function get_telegram_groups()
    {
        if (!$this->db->table_exists('telegram_groups')) {
            return array();
        }

        $fields = $this->db->list_fields('telegram_groups');
        $type_column = in_array('type', $fields, true) ? 'type' : (in_array('group_type', $fields, true) ? 'group_type' : '');
        if ($type_column === '') {
            return array();
        }
        $router_column = $this->resolve_telegram_group_router_column($fields);
        $actor = $this->resolve_settings_actor_context();

        $qb = $this->db
            ->from('telegram_groups g')
            ->select(
                'g.id, g.bot_id, g.group_name, g.chat_id, g.' . $type_column . ' AS type, g.is_active, g.created_at'
                . ($router_column !== null ? ', g.' . $router_column . ' AS router_id' : ', NULL AS router_id'),
                false
            )
            ->order_by('g.is_active', 'DESC')
            ->order_by('g.id', 'DESC');

        if ($this->db->table_exists('telegram_bots')) {
            $qb->select('b.bot_name');
            $qb->join('telegram_bots b', 'b.id = g.bot_id', 'left');
        } else {
            $qb->select("'-' AS bot_name", false);
        }
        if ($this->db->table_exists('routers') && $router_column !== null) {
            $router_fields = $this->db->list_fields('routers');
            $router_name_col = in_array('name', $router_fields, true)
                ? 'name'
                : (in_array('router_name', $router_fields, true) ? 'router_name' : '');
            if ($router_name_col !== '') {
                $qb->select('r.' . $router_name_col . ' AS router_name', false);
                $qb->join('routers r', 'r.id = g.' . $router_column, 'left');
            } else {
                $qb->select("'-' AS router_name", false);
            }
        } else {
            $qb->select("'-' AS router_name", false);
        }

        if ($router_column !== null) {
            if ($actor['role'] === 'superadmin' && (int) $actor['active_router_id'] > 0) {
                $qb->where('g.' . $router_column, (int) $actor['active_router_id']);
            } elseif ($actor['role'] !== 'superadmin') {
                if ($actor['router_scope_id'] > 0) {
                    $qb->where('g.' . $router_column, (int) $actor['router_scope_id']);
                } else {
                    $qb->where('1 = 0', null, false);
                }
            }
        }

        return $qb->get()->result_array();
    }

    public function get_telegram_targets_by_router($router_id, $type = '')
    {
        $router_id = (int) $router_id;
        $type = strtolower(trim((string) $type));

        if ($router_id <= 0) {
            return array();
        }
        if (!$this->db->table_exists('telegram_groups')) {
            return array();
        }

        $fields = $this->db->list_fields('telegram_groups');
        $type_column = in_array('type', $fields, true) ? 'type' : (in_array('group_type', $fields, true) ? 'group_type' : '');
        $router_column = $this->resolve_telegram_group_router_column($fields);
        if ($type_column === '' || $router_column === null) {
            return array();
        }

        $query_type = $type;
        if ($query_type !== '' && $type_column === 'group_type') {
            $query_type = $this->map_group_type_legacy($query_type);
        }

        $qb = $this->db
            ->from('telegram_groups g')
            ->select(
                'g.id, g.bot_id, g.group_name, g.chat_id, g.' . $type_column . ' AS type, g.is_active, g.created_at'
                . ($router_column !== null ? ', g.' . $router_column . ' AS router_id' : ', NULL AS router_id'),
                false
            )
            ->where('g.' . $router_column, $router_id);

        if (in_array('is_active', $fields, true)) {
            $qb->where('g.is_active', 1);
        }
        if ($query_type !== '') {
            $qb->where('g.' . $type_column, $query_type);
        }

        if ($this->db->table_exists('telegram_bots')) {
            $qb->select('b.bot_name, b.bot_token, b.is_active AS bot_is_active');
            $qb->join('telegram_bots b', 'b.id = g.bot_id', 'left');
            $qb->group_start()
                ->where('b.id IS NULL', null, false)
                ->or_where('b.is_active', 1)
                ->group_end();
        } else {
            $qb->select("'-' AS bot_name, '' AS bot_token, 1 AS bot_is_active", false);
        }

        return $qb
            ->order_by('g.id', 'DESC')
            ->get()
            ->result_array();
    }

    public function save_telegram_bot(array $data)
    {
        if (!$this->db->table_exists('telegram_bots')) {
            return array('success' => false, 'message' => 'Tabel telegram_bots belum tersedia.');
        }

        $id = (int) ($data['id'] ?? 0);
        $bot_name = trim((string) ($data['bot_name'] ?? ''));
        $bot_token = trim((string) ($data['bot_token'] ?? ''));
        $is_active = !empty($data['is_active']) ? 1 : 0;
        $requested_router_id = (int) ($data['router_id'] ?? 0);
        $actor = $this->resolve_settings_actor_context($data);
        $fields = $this->db->list_fields('telegram_bots');

        if ($bot_name === '') {
            return array('success' => false, 'message' => 'Nama bot wajib diisi.');
        }

        $effective_router_id = 0;
        if (in_array('router_id', $fields, true)) {
            if ($actor['role'] === 'superadmin') {
                $effective_router_id = $requested_router_id > 0
                    ? $requested_router_id
                    : (int) ($actor['active_router_id'] ?? 0);
                if ($effective_router_id <= 0) {
                    $effective_router_id = (int) $this->db
                        ->select('id')
                        ->from('routers')
                        ->where('is_active', 1)
                        ->order_by('id', 'ASC')
                        ->limit(1)
                        ->get()
                        ->row('id');
                }
            } else {
                $effective_router_id = (int) ($actor['router_scope_id'] ?? 0);
            }

            if ($effective_router_id <= 0 || !$this->router_exists($effective_router_id)) {
                return array('success' => false, 'message' => 'Router bot tidak valid.');
            }
        }

        $payload = array(
            'bot_name' => $bot_name,
            'is_active' => $is_active,
        );
        if (in_array('router_id', $fields, true)) {
            $payload['router_id'] = $effective_router_id;
        }

        if ($bot_token !== '') {
            $encrypted_bot_token = $this->encrypt_secret($bot_token);
            if ($encrypted_bot_token === '') {
                return array('success' => false, 'message' => 'Gagal mengenkripsi bot token.');
            }
            $payload['bot_token'] = $encrypted_bot_token;
        }

        if ($id > 0) {
            $existing = $this->db->where('id', $id)->get('telegram_bots')->row_array();
            if (empty($existing)) {
                return array('success' => false, 'message' => 'Bot tidak ditemukan.');
            }
            if (
                in_array('router_id', $fields, true)
                && $actor['role'] !== 'superadmin'
                && (int) ($existing['router_id'] ?? 0) !== (int) $effective_router_id
            ) {
                return array('success' => false, 'message' => 'Anda hanya bisa mengubah bot sesuai router scope Anda.');
            }
            if ($bot_token === '') {
                $payload['bot_token'] = (string) ($existing['bot_token'] ?? '');
            }

            $ok = $this->db->where('id', $id)->update('telegram_bots', $payload);
            return array('success' => (bool) $ok, 'message' => $ok ? 'Bot Telegram berhasil diupdate.' : 'Gagal update bot Telegram.');
        }

        if ($bot_token === '') {
            return array('success' => false, 'message' => 'Bot token wajib diisi untuk bot baru.');
        }

        $payload['created_at'] = date('Y-m-d H:i:s');
        $ok = $this->db->insert('telegram_bots', $payload);
        return array('success' => (bool) $ok, 'message' => $ok ? 'Bot Telegram berhasil ditambahkan.' : 'Gagal menambah bot Telegram.');
    }

    public function delete_telegram_bot($id)
    {
        if (!$this->db->table_exists('telegram_bots')) {
            return array('success' => false, 'message' => 'Tabel telegram_bots belum tersedia.');
        }

        $id = (int) $id;
        if ($id <= 0) {
            return array('success' => false, 'message' => 'Bot ID tidak valid.');
        }

        if ($this->db->table_exists('telegram_groups')) {
            $in_use = (int) $this->db->where('bot_id', $id)->count_all_results('telegram_groups');
            if ($in_use > 0) {
                return array('success' => false, 'message' => 'Bot masih digunakan di Telegram Group.');
            }
        }

        $ok = $this->db->where('id', $id)->delete('telegram_bots');
        return array('success' => (bool) $ok, 'message' => $ok ? 'Bot Telegram berhasil dihapus.' : 'Gagal menghapus bot Telegram.');
    }

    public function save_telegram_group(array $data)
    {
        if (!$this->db->table_exists('telegram_groups')) {
            return array('success' => false, 'message' => 'Tabel telegram_groups belum tersedia.');
        }

        $id = (int) ($data['id'] ?? 0);
        $bot_id = (int) ($data['bot_id'] ?? 0);
        $requested_router_id = (int) ($data['router_id'] ?? 0);
        $group_name = trim((string) ($data['group_name'] ?? ''));
        $chat_id = trim((string) ($data['chat_id'] ?? ''));
        $type = strtolower(trim((string) ($data['type'] ?? 'admin')));
        $is_active = !empty($data['is_active']) ? 1 : 0;
        $actor = $this->resolve_settings_actor_context($data);

        if ($chat_id === '') {
            return array('success' => false, 'message' => 'Chat ID wajib diisi.');
        }
        if ($group_name === '') {
            $group_name = strtoupper($type) . ' - ' . $chat_id;
        }
        if ($bot_id <= 0) {
            return array('success' => false, 'message' => 'Pilih bot Telegram terlebih dahulu.');
        }
        if (!in_array($type, array('teknisi', 'admin', 'owner', 'alert'), true)) {
            return array('success' => false, 'message' => 'Type Telegram group tidak valid.');
        }

        $fields = $this->db->list_fields('telegram_groups');
        $router_column = $this->resolve_telegram_group_router_column($fields);
        if ($router_column === null) {
            return array('success' => false, 'message' => 'Kolom router_id/router_scope_id di telegram_groups belum tersedia.');
        }

        $effective_router_id = 0;
        if ($actor['role'] === 'superadmin') {
            $effective_router_id = $requested_router_id > 0
                ? $requested_router_id
                : (int) ($actor['active_router_id'] ?? 0);
            if ($effective_router_id <= 0) {
                $effective_router_id = (int) $this->db
                    ->select('id')
                    ->from('routers')
                    ->where('is_active', 1)
                    ->order_by('id', 'ASC')
                    ->limit(1)
                    ->get()
                    ->row('id');
            }
            if ($effective_router_id <= 0) {
                return array('success' => false, 'message' => 'Pilih router untuk Telegram group.');
            }
        } else {
            $scope_router_id = (int) ($actor['router_scope_id'] ?? 0);
            if ($scope_router_id <= 0) {
                return array('success' => false, 'message' => 'Akun Anda belum memiliki router scope.');
            }
            if ($requested_router_id > 0 && $requested_router_id !== $scope_router_id) {
                return array('success' => false, 'message' => 'Router tidak valid untuk scope akun Anda.');
            }
            $effective_router_id = $scope_router_id;
        }

        if (!$this->router_exists($effective_router_id)) {
            return array('success' => false, 'message' => 'Router tidak ditemukan atau tidak aktif.');
        }

        if ($this->db->table_exists('telegram_bots')) {
            $bot = $this->db->where('id', $bot_id)->limit(1)->get('telegram_bots')->row_array();
            if (empty($bot)) {
                return array('success' => false, 'message' => 'Bot Telegram tidak ditemukan.');
            }

            $bot_fields = $this->db->list_fields('telegram_bots');
            if (in_array('is_active', $bot_fields, true) && (int) ($bot['is_active'] ?? 0) !== 1) {
                return array('success' => false, 'message' => 'Bot Telegram yang dipilih sedang nonaktif.');
            }

            if (in_array('router_id', $bot_fields, true)) {
                $bot_router_id = (int) ($bot['router_id'] ?? 0);
                if ($bot_router_id > 0 && $bot_router_id !== $effective_router_id) {
                    return array('success' => false, 'message' => 'Bot Telegram yang dipilih terikat ke router lain.');
                }
            }
        }

        $payload = array(
            'bot_id' => $bot_id > 0 ? $bot_id : null,
            'group_name' => $group_name,
            'chat_id' => $chat_id,
            'is_active' => $is_active,
            $router_column => $effective_router_id,
        );
        if (in_array('type', $fields, true)) {
            $payload['type'] = $type;
        }
        if (in_array('group_type', $fields, true)) {
            $payload['group_type'] = $this->map_group_type_legacy($type);
        }
        if (in_array('updated_at', $fields, true)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        if (in_array('bot_token_enc', $fields, true)) {
            $token_enc = '';
            if ($bot_id > 0 && $this->db->table_exists('telegram_bots')) {
                $bot = $this->db->select('bot_token')->where('id', $bot_id)->get('telegram_bots')->row_array();
                $token_enc = (string) ($bot['bot_token'] ?? '');
            }
            $payload['bot_token_enc'] = $token_enc;
        }

        if ($id > 0) {
            $existing = $this->db->where('id', $id)->get('telegram_groups')->row_array();
            if (empty($existing)) {
                return array('success' => false, 'message' => 'Telegram group tidak ditemukan.');
            }
            if (
                $actor['role'] !== 'superadmin'
                && (int) ($existing[$router_column] ?? 0) !== (int) $effective_router_id
            ) {
                return array('success' => false, 'message' => 'Anda hanya dapat mengubah group sesuai router scope Anda.');
            }
            if (isset($payload['bot_token_enc']) && $payload['bot_token_enc'] === '') {
                $payload['bot_token_enc'] = (string) ($existing['bot_token_enc'] ?? '');
            }

            $ok = $this->db->where('id', $id)->update('telegram_groups', $payload);
            return array('success' => (bool) $ok, 'message' => $ok ? 'Telegram group berhasil diupdate.' : 'Gagal update Telegram group.');
        }

        if (in_array('created_at', $fields, true)) {
            $payload['created_at'] = date('Y-m-d H:i:s');
        }

        // Prevent duplicate hard-fail. If same bot+type+chat already exists, update it.
        $dup = $this->find_duplicate_telegram_group($fields, $bot_id, $effective_router_id, $type, $chat_id);
        if (!empty($dup)) {
            if ($actor['role'] !== 'superadmin' && (int) ($dup[$router_column] ?? 0) !== (int) $effective_router_id) {
                return array('success' => false, 'message' => 'Chat ID ini sudah terdaftar di router lain. Hanya superadmin yang bisa memindahkan.');
            }

            unset($payload['created_at']);
            $ok = $this->db->where('id', (int) $dup['id'])->update('telegram_groups', $payload);
            if ($ok) {
                return array('success' => true, 'message' => 'Telegram group sudah ada, data berhasil diperbarui.');
            }

            $err = $this->db->error();
            return array('success' => false, 'message' => 'Gagal update Telegram group existing: ' . (string) ($err['message'] ?? 'unknown'));
        }

        $ok = $this->db->insert('telegram_groups', $payload);
        if ($ok) {
            return array('success' => true, 'message' => 'Telegram group berhasil ditambahkan.');
        }

        $err = $this->db->error();
        $msg = trim((string) ($err['message'] ?? ''));
        if ($msg !== '') {
            if (stripos($msg, 'Duplicate entry') !== false) {
                return array('success' => false, 'message' => 'Chat ID untuk bot/type tersebut sudah terdaftar.');
            }
            return array('success' => false, 'message' => 'Gagal menambah Telegram group: ' . $msg);
        }
        return array('success' => false, 'message' => 'Gagal menambah Telegram group.');
    }

    public function delete_telegram_group($id, array $actor_context = array())
    {
        if (!$this->db->table_exists('telegram_groups')) {
            return array('success' => false, 'message' => 'Tabel telegram_groups belum tersedia.');
        }

        $id = (int) $id;
        if ($id <= 0) {
            return array('success' => false, 'message' => 'Group ID tidak valid.');
        }

        $fields = $this->db->list_fields('telegram_groups');
        $router_column = $this->resolve_telegram_group_router_column($fields);
        $actor = $this->resolve_settings_actor_context(array(
            'actor_role' => (string) ($actor_context['role'] ?? ''),
            'actor_router_scope_id' => (int) ($actor_context['router_scope_id'] ?? 0),
        ));

        if ($actor['role'] !== 'superadmin' && $router_column !== null) {
            $scope_router_id = (int) ($actor['router_scope_id'] ?? 0);
            if ($scope_router_id <= 0) {
                return array('success' => false, 'message' => 'Akun Anda belum memiliki router scope.');
            }
            $this->db->where($router_column, $scope_router_id);
        }

        $ok = $this->db->where('id', $id)->delete('telegram_groups');
        if (!$ok) {
            return array('success' => false, 'message' => 'Gagal menghapus Telegram group.');
        }
        if ((int) $this->db->affected_rows() === 0) {
            return array('success' => false, 'message' => 'Telegram group tidak ditemukan atau bukan bagian router scope Anda.');
        }

        return array('success' => true, 'message' => 'Telegram group berhasil dihapus.');
    }

    public function get_active_routers($scope_router_id = null)
    {
        if (!$this->db->table_exists('routers')) {
            return array();
        }

        $fields = $this->db->list_fields('routers');
        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : '');
        if ($name_col === '') {
            return array();
        }

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS name', false)
            ->from('routers');
        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        $scope_router_id = $scope_router_id === null ? null : (int) $scope_router_id;
        if ($scope_router_id !== null) {
            if ($scope_router_id <= 0) {
                return array();
            }
            $qb->where('id', $scope_router_id);
        }

        return $qb->order_by($name_col, 'ASC')->get()->result_array();
    }

    public function get_router_name_by_id($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return '';
        }

        $fields = $this->db->list_fields('routers');
        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : '');
        if ($name_col === '') {
            return '';
        }

        $row = $this->db
            ->select($name_col . ' AS name', false)
            ->from('routers')
            ->where('id', $router_id)
            ->limit(1)
            ->get()
            ->row_array();

        return trim((string) ($row['name'] ?? ''));
    }

    private function map_group_type_legacy($type)
    {
        $type = strtolower(trim((string) $type));
        if ($type === 'teknisi') {
            return 'ops';
        }
        if ($type === 'admin') {
            return 'billing';
        }
        if ($type === 'alert') {
            return 'monitoring';
        }
        if ($type === 'owner') {
            return 'general';
        }
        return 'general';
    }

    private function find_duplicate_telegram_group(array $fields, $bot_id, $router_id, $type, $chat_id)
    {
        $type_column = in_array('type', $fields, true) ? 'type' : (in_array('group_type', $fields, true) ? 'group_type' : '');
        if ($type_column === '') {
            return array();
        }
        $router_column = $this->resolve_telegram_group_router_column($fields);

        $query_type = $type;
        if ($type_column === 'group_type') {
            $query_type = $this->map_group_type_legacy($type);
        }

        $qb = $this->db
            ->from('telegram_groups')
            ->where('bot_id', (int) $bot_id)
            ->where($type_column, $query_type)
            ->where('chat_id', (string) $chat_id)
            ->order_by('id', 'ASC')
            ->limit(1);

        if ($router_column !== null) {
            $qb->where($router_column, (int) $router_id);
        }

        return (array) $qb->get()->row_array();
    }

    private function resolve_default_telegram_from_multi_tables()
    {
        $result = array(
            'bot_token' => '',
            'chat_id_admin' => '',
            'enable_notification' => 0,
        );

        if (!$this->db->table_exists('telegram_groups')) {
            return $result;
        }

        $group_fields = $this->db->list_fields('telegram_groups');
        $type_col = in_array('type', $group_fields, true) ? 'type' : (in_array('group_type', $group_fields, true) ? 'group_type' : '');
        if ($type_col === '') {
            return $result;
        }

        $qb = $this->db->from('telegram_groups');
        if (in_array('is_active', $group_fields, true)) {
            $qb->where('is_active', 1);
        }
        if ($type_col === 'type') {
            $qb->group_start()
                ->where($type_col, 'admin')
                ->or_where($type_col, 'owner')
                ->or_where($type_col, 'alert')
                ->or_where($type_col, 'teknisi')
                ->group_end();
        } else {
            $qb->group_start()
                ->where($type_col, 'billing')
                ->or_where($type_col, 'monitoring')
                ->or_where($type_col, 'ops')
                ->or_where($type_col, 'general')
                ->or_where($type_col, 'helpdesk')
                ->group_end();
        }
        $qb->order_by('id', 'ASC')->limit(1);

        $group = $qb->get()->row_array();
        if (empty($group)) {
            return $result;
        }

        $result['chat_id_admin'] = trim((string) ($group['chat_id'] ?? ''));
        $token = '';

        if ($this->db->table_exists('telegram_bots')) {
            $bot_id = (int) ($group['bot_id'] ?? 0);
            if ($bot_id > 0) {
                $bot = $this->db->where('id', $bot_id)->limit(1)->get('telegram_bots')->row_array();
                if (!empty($bot)) {
                    $raw = trim((string) ($bot['bot_token'] ?? ''));
                    $token = $this->decrypt_secret($raw);
                    if ($token === '') {
                        $token = $raw;
                    }
                }
            }
        }

        if ($token === '' && !empty($group['bot_token_enc'])) {
            $token = $this->decrypt_secret((string) $group['bot_token_enc']);
        }

        $result['bot_token'] = trim((string) $token);
        $result['enable_notification'] = ($result['bot_token'] !== '' && $result['chat_id_admin'] !== '') ? 1 : 0;
        return $result;
    }

    public function get_pppoe_sync_settings()
    {
        $row = $this->get_single_row('settings_pppoe_sync', array(
            'auto_sync' => 0,
            'interval_minutes' => 60,
            'last_sync_at' => null,
        ));

        return array(
            'auto_sync' => (int) $row['auto_sync'],
            'interval_minutes' => (int) $row['interval_minutes'],
            'last_sync_at' => $row['last_sync_at'],
        );
    }

    public function save_pppoe_sync_settings(array $data)
    {
        $payload = array(
            'auto_sync' => !empty($data['auto_sync']) ? 1 : 0,
            'interval_minutes' => max(5, (int) ($data['interval_minutes'] ?? 60)),
            'updated_at' => date('Y-m-d H:i:s'),
        );

        return $this->upsert_single_row('settings_pppoe_sync', $payload);
    }

    public function touch_last_sync_time()
    {
        if (!$this->db->table_exists('settings_pppoe_sync')) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $exists = $this->db->where('id', 1)->get('settings_pppoe_sync')->row_array();
        if ($exists) {
            return $this->db
                ->where('id', 1)
                ->update('settings_pppoe_sync', array(
                    'last_sync_at' => $now,
                    'updated_at' => $now,
                ));
        }

        return $this->db->insert('settings_pppoe_sync', array(
            'id' => 1,
            'auto_sync' => 0,
            'interval_minutes' => 60,
            'last_sync_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

    public function test_database_connection(array $params)
    {
        $config = array(
            'dsn' => '',
            'hostname' => (string) ($params['db_host'] ?? ''),
            'username' => (string) ($params['db_username'] ?? ''),
            'password' => (string) ($params['db_password'] ?? ''),
            'database' => (string) ($params['db_name'] ?? ''),
            'dbdriver' => 'mysqli',
            'dbprefix' => '',
            'pconnect' => false,
            'db_debug' => false,
            'cache_on' => false,
            'cachedir' => '',
            'char_set' => 'utf8',
            'dbcollat' => 'utf8_general_ci',
            'swap_pre' => '',
            'encrypt' => false,
            'compress' => false,
            'stricton' => false,
            'failover' => array(),
            'save_queries' => false,
        );

        try {
            $db = $this->load->database($config, true);
            $ok = $db && $db->initialize();
            if (!$ok || !$db->conn_id) {
                return array('success' => false, 'message' => 'Koneksi database gagal.');
            }

            $db->close();
            return array('success' => true, 'message' => 'Koneksi database berhasil.');
        } catch (Throwable $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    public function sync_pppoe_secrets(array $secrets, $debug_db = false, $router_id = 0)
    {
        $this->pppoe_debug_mode = !empty($debug_db);
        $this->pppoe_sample_shown = false;
        $router_id = (int) $router_id;

        $stats = array(
            'total_found' => count($secrets),
            'total_insert' => 0,
            'total_update' => 0,
            'total_failed' => 0,
            'synced_count' => 0,
            'status' => 'success',
            'message' => '',
        );

        if (!$this->db->table_exists('pppoe_secrets')) {
            $stats['status'] = 'failed';
            $stats['message'] = 'Tabel pppoe_secrets tidak ditemukan.';
            return $stats;
        }

        foreach ($secrets as $row) {
            $parsed = $this->parse_pppoe_secret_row((array) $row);
            if ($parsed['username'] === '') {
                $stats['total_failed']++;
                log_message('error', '[PPPOE_SYNC] Username kosong, baris dilewati.');
                continue;
            }

            if ($router_id > 0) {
                $parsed['router_id'] = $router_id;
            }

            $result = $this->upsert_pppoe_secret($parsed, $this->pppoe_debug_mode);
            if (!empty($result['success']) && $result['action'] === 'inserted') {
                $stats['total_insert']++;
            } elseif (!empty($result['success']) && $result['action'] === 'updated') {
                $stats['total_update']++;
            } elseif (empty($result['success'])) {
                $stats['total_failed']++;
            }
        }

        $stats['synced_count'] = $stats['total_insert'] + $stats['total_update'];
        if ($stats['total_failed'] > 0 && $stats['synced_count'] > 0) {
            $stats['status'] = 'partial';
        } elseif ($stats['total_failed'] > 0 && $stats['synced_count'] === 0) {
            $stats['status'] = 'failed';
        }

        $stats['message'] = 'Sync PPPoE selesai. Found=' . $stats['total_found']
            . ', Insert=' . $stats['total_insert']
            . ', Update=' . $stats['total_update']
            . ', Failed=' . $stats['total_failed'] . '.';

        return $stats;
    }

    public function parse_pppoe_secret_row(array $row)
    {
        $username = trim((string) ($row['name'] ?? ''));
        $disabled_raw = strtolower(trim((string) ($row['disabled'] ?? 'false')));
        $disabled = in_array($disabled_raw, array('true', '1', 'yes'), true) ? 1 : 0;

        return array(
            'username' => $username,
            'password' => isset($row['password']) ? (string) $row['password'] : '',
            'profile' => isset($row['profile']) ? (string) $row['profile'] : '',
            'service' => isset($row['service']) ? (string) $row['service'] : '',
            'disabled' => $disabled,
            'comment' => isset($row['comment']) ? (string) $row['comment'] : '',
            'last_logged_out' => isset($row['last-logged-out']) ? (string) $row['last-logged-out'] : '',
            'router_id' => isset($row['router_id']) ? (int) $row['router_id'] : 0,
        );
    }

    public function upsert_pppoe_secret(array $secret, $debug_db = null)
    {
        if ($debug_db !== null) {
            $this->pppoe_debug_mode = !empty($debug_db);
        }

        if (!$this->db->table_exists('pppoe_secrets')) {
            return array('success' => false, 'action' => 'failed', 'message' => 'Tabel pppoe_secrets tidak ditemukan.');
        }

        $parsed = $this->parse_pppoe_secret_row($secret);
        if ($parsed['username'] === '') {
            return array('success' => false, 'action' => 'failed', 'message' => 'Field `name` kosong.');
        }

        $table_fields = $this->get_table_fields_cached('pppoe_secrets');
        if (empty($table_fields)) {
            return array('success' => false, 'action' => 'failed', 'message' => 'Gagal membaca struktur tabel pppoe_secrets.');
        }

        $has_router_id_column = in_array('router_id', $table_fields, true);
        $router_id = (int) ($parsed['router_id'] ?? 0);
        if ($has_router_id_column && $router_id <= 0) {
            return array(
                'success' => false,
                'action' => 'failed',
                'message' => 'router_id wajib diisi untuk pppoe_secrets.',
                'data' => $parsed,
            );
        }

        $existing_qb = $this->db
            ->from('pppoe_secrets')
            ->where('username', $parsed['username']);
        if ($has_router_id_column) {
            $existing_qb->where('router_id', $router_id);
        }
        $existing = $existing_qb->limit(1)->get()->row_array();

        $insert_payload = array(
            'username' => $parsed['username'],
            'ppp_password' => $parsed['password'],
            'profile' => $parsed['profile'],
            'service' => $parsed['service'],
            'disabled' => (int) $parsed['disabled'],
            'comment' => $parsed['comment'],
            'last_logged_out' => $parsed['last_logged_out'] !== '' ? $parsed['last_logged_out'] : null,
        );
        if ($has_router_id_column) {
            $insert_payload['router_id'] = $router_id;
        }
        if (in_array('created_at', $table_fields, true)) {
            $insert_payload['created_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $table_fields, true)) {
            $insert_payload['updated_at'] = date('Y-m-d H:i:s');
        }
        $insert_payload = $this->filter_payload_by_table_fields($insert_payload, $table_fields);

        $update_payload = array(
            'ppp_password' => $parsed['password'],
            'profile' => $parsed['profile'],
            'service' => $parsed['service'],
            'disabled' => (int) $parsed['disabled'],
            'comment' => $parsed['comment'],
            'last_logged_out' => $parsed['last_logged_out'] !== '' ? $parsed['last_logged_out'] : null,
        );
        if (in_array('updated_at', $table_fields, true)) {
            $update_payload['updated_at'] = date('Y-m-d H:i:s');
        }
        $update_payload = $this->filter_payload_by_table_fields($update_payload, $table_fields);

        if (!empty($existing)) {
            $this->print_sample_payload_once('UPDATE', $parsed['username'], $update_payload);

            $update_qb = $this->db
                ->where('username', $parsed['username']);
            if ($has_router_id_column) {
                $update_qb->where('router_id', $router_id);
            }
            $result = $update_qb->update('pppoe_secrets', $update_payload);

            if (!$result) {
                $this->handle_db_query_error('UPDATE', $parsed['username'], $update_payload);
                return array(
                    'success' => false,
                    'action' => 'failed',
                    'message' => 'Update PPPoE secret gagal: ' . $this->format_db_error($this->db->error()),
                    'data' => $parsed,
                );
            }

            return array(
                'success' => true,
                'action' => 'updated',
                'message' => 'Update PPPoE secret berhasil.',
                'data' => $parsed,
            );
        }

        $this->print_sample_payload_once('INSERT', $parsed['username'], $insert_payload);

        $result = $this->db->insert('pppoe_secrets', $insert_payload);
        if (!$result) {
            $this->handle_db_query_error('INSERT', $parsed['username'], $insert_payload);
            return array(
                'success' => false,
                'action' => 'failed',
                'message' => 'Insert PPPoE secret gagal: ' . $this->format_db_error($this->db->error()),
                'data' => $parsed,
            );
        }

        return array(
            'success' => true,
            'action' => 'inserted',
            'message' => 'Insert PPPoE secret berhasil.',
            'data' => $parsed,
        );
    }

    public function insert_sync_log($status, $message, $synced_count = 0, $sync_type = 'pppoe', array $extra = array())
    {
        if (!$this->db->table_exists('sync_logs')) {
            return false;
        }

        $payload = array(
            'sync_type' => (string) $sync_type,
            'status' => (string) $status,
            'message' => (string) $message,
            'synced_count' => (int) $synced_count,
            'created_at' => date('Y-m-d H:i:s'),
        );

        if ($this->column_exists('sync_logs', 'total_found')) {
            $payload['total_found'] = (int) ($extra['total_found'] ?? 0);
        }
        if ($this->column_exists('sync_logs', 'total_insert')) {
            $payload['total_insert'] = (int) ($extra['total_insert'] ?? 0);
        }
        if ($this->column_exists('sync_logs', 'total_update')) {
            $payload['total_update'] = (int) ($extra['total_update'] ?? 0);
        }
        if ($this->column_exists('sync_logs', 'total_failed')) {
            $payload['total_failed'] = (int) ($extra['total_failed'] ?? 0);
        }
        if ($this->column_exists('sync_logs', 'sync_date')) {
            $payload['sync_date'] = (string) ($extra['sync_date'] ?? date('Y-m-d H:i:s'));
        }

        return $this->db->insert('sync_logs', $payload);
    }

    public function get_sync_logs($limit = 20)
    {
        if (!$this->db->table_exists('sync_logs')) {
            return array();
        }

        $qb = $this->db->from('sync_logs');
        if ($this->column_exists('sync_logs', 'sync_date')) {
            $qb->order_by('sync_date', 'DESC');
        } else {
            $qb->order_by('id', 'DESC');
        }

        return $qb->limit((int) $limit)->get()->result();
    }

    public function encrypt_secret($plain_text)
    {
        $plain_text = (string) $plain_text;
        if ($plain_text === '') {
            return '';
        }

        $encrypted = $this->encryption->encrypt($plain_text);
        if ($encrypted !== false) {
            return 'ci3:' . $encrypted;
        }

        $key = $this->get_secret_key();
        if ($key === '') {
            log_message('error', '[SETTINGS_MODEL] encrypt_secret failed: configured secret key unavailable.');
            return '';
        }

        $iv_len = openssl_cipher_iv_length($this->secret_cipher);
        $iv = random_bytes($iv_len);
        $cipher_raw = openssl_encrypt($plain_text, $this->secret_cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher_raw === false) {
            log_message('error', '[SETTINGS_MODEL] encrypt_secret failed: OpenSSL encryption error.');
            return '';
        }

        return 'osl:' . base64_encode($iv) . ':' . base64_encode($cipher_raw);
    }

    public function decrypt_secret($cipher_text)
    {
        $cipher_text = (string) $cipher_text;
        if ($cipher_text === '') {
            return '';
        }

        if (strpos($cipher_text, 'ci3:') === 0) {
            $decrypted = $this->encryption->decrypt(substr($cipher_text, 4));
            return $decrypted !== false ? $decrypted : '';
        }

        if (strpos($cipher_text, 'osl:') === 0) {
            $payload = substr($cipher_text, 4);
            $parts = explode(':', $payload, 2);
            if (count($parts) !== 2) {
                return '';
            }

            $iv = base64_decode($parts[0], true);
            $raw = base64_decode($parts[1], true);
            if ($iv === false || $raw === false) {
                return '';
            }

            foreach ($this->get_secret_key_candidates() as $key) {
                $plain = openssl_decrypt($raw, $this->secret_cipher, $key, OPENSSL_RAW_DATA, $iv);
                if ($plain !== false && $plain !== '') {
                    return $plain;
                }
            }

            return '';
        }

        $decrypted = $this->encryption->decrypt($cipher_text);
        if ($decrypted !== false) {
            return $decrypted;
        }

        return '';
    }

    private function get_single_row($table, array $default_row)
    {
        if (!$this->db->table_exists($table)) {
            return $default_row;
        }

        $row = $this->db->where('id', 1)->get($table)->row_array();
        if (!$row) {
            return $default_row;
        }

        return array_merge($default_row, $row);
    }

    private function upsert_single_row($table, array $payload)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }

        $exists = $this->db->where('id', 1)->get($table)->row_array();
        if ($exists) {
            return $this->db->where('id', 1)->update($table, $payload);
        }

        if (!isset($payload['id'])) {
            $payload['id'] = 1;
        }
        if (!isset($payload['created_at'])) {
            $payload['created_at'] = date('Y-m-d H:i:s');
        }
        if (!isset($payload['updated_at'])) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        return $this->db->insert($table, $payload);
    }

    private function get_secret_key()
    {
        $candidates = $this->get_secret_key_candidates(false);
        $key = reset($candidates);
        return is_string($key) ? $key : '';
    }

    private function get_secret_key_candidates($include_derived = true)
    {
        $keys = array();
        $push = static function (&$keys, $base) {
            $base = trim((string) $base);
            if ($base === '') {
                return;
            }

            $hash = hash('sha256', $base, true);
            $keys[bin2hex($hash)] = $hash;
        };

        foreach (array(
            (string) config_item('encryption_key'),
            (string) getenv('APP_SECRET_KEY'),
            (string) getenv('CI_ENCRYPTION_KEY'),
            (string) getenv('APP_KEY'),
        ) as $base) {
            $push($keys, $base);
        }

        if (!$include_derived) {
            return array_values($keys);
        }

        $host = php_uname('n');
        $fallback_paths = array();
        $push_path = static function (&$fallback_paths, $path) {
            $path = trim((string) $path);
            if ($path === '') {
                return;
            }

            $fallback_paths[$path] = $path;
        };

        $push_path($fallback_paths, __FILE__);
        $push_path($fallback_paths, realpath(__FILE__));

        if (defined('FCPATH')) {
            $front = rtrim((string) FCPATH, '/\\');
            $push_path($fallback_paths, $front . '/application/models/Settings_model.php');
            $push_path($fallback_paths, realpath($front . '/application/models/Settings_model.php'));

            $base_dir = dirname($front);
            $push_path($fallback_paths, $base_dir . '/application/models/Settings_model.php');
            $push_path($fallback_paths, realpath($base_dir . '/application/models/Settings_model.php'));
        }

        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $push_path($fallback_paths, rtrim($cwd, '/\\') . '/application/models/Settings_model.php');
        }

        foreach (array_values($fallback_paths) as $path) {
            if (strpos($path, '/var/www/html/') === 0) {
                $push_path($fallback_paths, str_replace('/var/www/html/', '/var/www/', $path));
            } elseif (strpos($path, '/var/www/') === 0) {
                $push_path($fallback_paths, str_replace('/var/www/', '/var/www/html/', $path));
            }
        }

        foreach (array_values($fallback_paths) as $path) {
            $push($keys, $path . PHP_VERSION . $host);
        }

        $push($keys, 'rtrwnet-secret|' . $host);

        return array_values($keys);
    }

    private function pick_existing_column(array $fields, array $candidates)
    {
        foreach ($candidates as $column) {
            if (in_array($column, $fields, true)) {
                return $column;
            }
        }
        return null;
    }

    private function column_exists($table, $column)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }
        return in_array($column, $this->db->list_fields($table), true);
    }

    private function is_numeric_column_type($table, $column)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }

        $numeric_types = array(
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'decimal', 'float', 'double', 'real', 'numeric',
        );

        foreach ($this->db->field_data($table) as $meta) {
            if ($meta->name === $column) {
                return in_array(strtolower((string) $meta->type), $numeric_types, true);
            }
        }

        return false;
    }

    private function map_disabled_to_status_value($disabled, $table, $column)
    {
        $disabled = (int) $disabled === 1 ? 1 : 0;
        if ($this->is_numeric_column_type($table, $column)) {
            return $disabled;
        }

        $enum_values = $this->get_enum_values($table, $column);
        if (!empty($enum_values)) {
            if ($disabled === 0) {
                foreach (array('active', 'enabled', 'open') as $candidate) {
                    if (in_array($candidate, $enum_values, true)) {
                        return $candidate;
                    }
                }
                return $enum_values[0];
            }

            foreach (array('isolated', 'suspended', 'inactive', 'disabled', 'terminated') as $candidate) {
                if (in_array($candidate, $enum_values, true)) {
                    return $candidate;
                }
            }

            return end($enum_values);
        }

        return $disabled === 1 ? 'isolated' : 'active';
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

    private function format_db_error(array $db_error)
    {
        $code = (string) ($db_error['code'] ?? '');
        $message = (string) ($db_error['message'] ?? '');
        if ($code === '' && $message === '') {
            return 'unknown error';
        }
        return trim($code . ' ' . $message);
    }

    private function sanitize_secret_for_log(array $payload)
    {
        $safe = $payload;
        if (isset($safe['password']) && $safe['password'] !== '') {
            $safe['password'] = '********';
        }
        if (isset($safe['ppp_password']) && $safe['ppp_password'] !== '') {
            $safe['ppp_password'] = '********';
        }
        return $safe;
    }

    private function get_table_fields_cached($table)
    {
        if (isset($this->table_fields_cache[$table])) {
            return $this->table_fields_cache[$table];
        }

        if (!$this->db->table_exists($table)) {
            $this->table_fields_cache[$table] = array();
            return array();
        }

        $fields = $this->db->list_fields($table);
        $this->table_fields_cache[$table] = is_array($fields) ? $fields : array();
        return $this->table_fields_cache[$table];
    }

    private function filter_payload_by_table_fields(array $payload, array $table_fields)
    {
        $filtered = array();
        foreach ($payload as $key => $value) {
            if (in_array($key, $table_fields, true)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    private function print_sample_payload_once($action, $username, array $payload)
    {
        if ($this->pppoe_sample_shown) {
            return;
        }

        $sample = array(
            'action' => (string) $action,
            'username' => (string) $username,
            'table' => 'pppoe_secrets',
            'payload' => $this->sanitize_secret_for_log($payload),
        );

        log_message('debug', '[PPPOE_SYNC] SAMPLE DATA: ' . json_encode($sample));

        if ($this->pppoe_debug_mode) {
            echo '<pre>';
            echo "PPPOE SAMPLE DATA BEFORE QUERY\n";
            print_r($sample);
            echo '</pre>';
        }

        $this->pppoe_sample_shown = true;
    }

    private function handle_db_query_error($action, $username, array $payload)
    {
        $error = $this->db->error();
        $last_query = $this->db->last_query();
        $log_payload = array(
            'action' => (string) $action,
            'username' => (string) $username,
            'error' => $error,
            'sql' => $last_query,
            'payload' => $this->sanitize_secret_for_log($payload),
        );

        log_message('error', 'DB ERROR: ' . json_encode($error));
        log_message('error', '[PPPOE_SYNC] DB QUERY ERROR: ' . json_encode($log_payload));

        if ($this->pppoe_debug_mode) {
            echo '<pre>';
            echo "DB ERROR DETAIL\n";
            print_r($log_payload);
            echo '</pre>';
            exit;
        }
    }

    private function resolve_telegram_group_router_column(array $fields)
    {
        if (in_array('router_id', $fields, true)) {
            return 'router_id';
        }
        if (in_array('router_scope_id', $fields, true)) {
            return 'router_scope_id';
        }

        return null;
    }

    private function resolve_settings_actor_context(array $data = array())
    {
        $CI =& get_instance();
        if (!isset($CI->session) || !is_object($CI->session)) {
            $CI->load->library('session');
        }

        $role = '';
        if (isset($data['actor_role'])) {
            $role = (string) $data['actor_role'];
        } else {
            $role = (string) $CI->session->userdata('role');
        }
        $role = function_exists('normalizeRole') ? normalizeRole($role) : strtolower(trim($role));

        $router_scope_id = 0;
        if (isset($data['actor_router_scope_id'])) {
            $router_scope_id = (int) $data['actor_router_scope_id'];
        } else {
            $router_scope_id = (int) $CI->session->userdata('router_scope_id');
        }

        $active_router_id = 0;
        if (isset($data['actor_active_router_id'])) {
            $active_router_id = (int) $data['actor_active_router_id'];
        } else {
            $active_router_id = (int) $CI->session->userdata('active_router_id');
            if ($active_router_id <= 0) {
                $active_router_id = (int) $CI->session->userdata('dashboard_router_id');
            }
        }

        if ($active_router_id <= 0 && $router_scope_id > 0) {
            $active_router_id = (int) $router_scope_id;
        }
        if ($router_scope_id <= 0 && $active_router_id > 0) {
            $router_scope_id = (int) $active_router_id;
        }

        return array(
            'role' => $role,
            'router_scope_id' => $router_scope_id > 0 ? $router_scope_id : 0,
            'active_router_id' => $active_router_id > 0 ? $active_router_id : 0,
        );
    }

    private function router_exists($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return false;
        }

        $fields = $this->db->list_fields('routers');
        $qb = $this->db->from('routers')->where('id', $router_id);
        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        return (int) $qb->count_all_results() > 0;
    }
}
