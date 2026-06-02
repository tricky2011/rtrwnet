<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('getTenantId')) {
    /**
     * Mengambil tenant_id aktif dari session.
     * Return NULL untuk platform owner / superadmin legacy / context tanpa tenant.
     *
     * @return int|null
     */
    function getTenantId()
    {
        if (is_cli()) {
            return null;
        }

        $CI =& get_instance();
        if (!isset($CI->session)) {
            $CI->load->library('session');
        }

        if (!$CI->session->userdata('logged_in')) {
            return null;
        }

        $role = strtolower(trim((string) $CI->session->userdata('role')));
        if (in_array($role, array('platform_owner', 'superadmin'), true)) {
            $scope_tenant = (int) $CI->session->userdata('current_tenant_id');
            return $scope_tenant > 0 ? $scope_tenant : null;
        }

        $tenant_id = (int) $CI->session->userdata('tenant_id');
        if ($tenant_id <= 0) {
            $tenant_id = (int) $CI->session->userdata('current_tenant_id');
        }

        return $tenant_id > 0 ? $tenant_id : null;
    }
}

if (!function_exists('isPlatformOwner')) {
    /**
     * @return bool
     */
    function isPlatformOwner()
    {
        if (is_cli()) {
            return false;
        }

        $CI =& get_instance();
        if (!isset($CI->session)) {
            $CI->load->library('session');
        }

        $role = strtolower(trim((string) $CI->session->userdata('role')));
        return in_array($role, array('platform_owner', 'superadmin'), true);
    }
}

if (!function_exists('connectRouter')) {
    /**
     * Router connector (single install + kompatibel tenant lama).
     *
     * @param int $router_id
     * @return array
     */
    function connectRouter($router_id)
    {
        $CI =& get_instance();
        $CI->load->database();

        if (!$CI->db->table_exists('routers')) {
            return array(
                'success' => false,
                'message' => 'Tabel routers belum tersedia.',
                'router' => null,
                'api' => null,
            );
        }

        $router_id = (int) $router_id;
        if ($router_id <= 0) {
            return array(
                'success' => false,
                'message' => 'Router belum valid.',
                'router' => null,
                'api' => null,
            );
        }

        $fields = $CI->db->list_fields('routers');
        $qb = $CI->db
            ->from('routers')
            ->where('id', $router_id);

        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        if (in_array('tenant_id', $fields, true)) {
            $tenant_id = getTenantId();
            if ($tenant_id !== null && (int) $tenant_id > 0) {
                $qb->where('tenant_id', (int) $tenant_id);
            }
        }

        $router = $qb->limit(1)->get()->row_array();

        if (empty($router)) {
            return array(
                'success' => false,
                'message' => 'Router tidak ditemukan atau nonaktif.',
                'router' => null,
                'api' => null,
            );
        }

        $pick_non_empty = static function (array $row, array $columns) {
            foreach ($columns as $column) {
                if (!array_key_exists($column, $row)) {
                    continue;
                }
                $value = trim((string) $row[$column]);
                if ($value !== '') {
                    return $value;
                }
            }
            return '';
        };

        $host = $pick_non_empty($router, array('ip_address', 'api_host', 'host'));
        $username = $pick_non_empty($router, array('username', 'api_username', 'user'));
        $password_raw = $pick_non_empty($router, array('password', 'api_password_enc', 'pass', 'password_enc'));

        $port = (int) ($router['api_port'] ?? 8728);
        if ($port <= 0) {
            $port = 8728;
        }
        $use_ssl = !empty($router['use_ssl']);
        $timeout = (int) ($router['timeout_seconds'] ?? 5);
        if ($timeout <= 0) {
            $timeout = 5;
        }

        $password = $password_raw;
        if ($password_raw !== '') {
            try {
                $CI->load->model('settings_model');
                if (method_exists($CI->settings_model, 'decrypt_secret')) {
                    $decrypted = (string) $CI->settings_model->decrypt_secret($password_raw);
                    if ($decrypted !== '') {
                        $password = $decrypted;
                    }
                }
            } catch (Throwable $e) {
                log_message('error', '[ROUTER_HELPER] decrypt_secret error: ' . $e->getMessage());
            }

            if ($password === $password_raw) {
                $CI->load->library('encryption');
                $decoded = $CI->encryption->decrypt($password_raw);
                if (is_string($decoded) && trim($decoded) !== '') {
                    $password = $decoded;
                }
            }
        }

        if ($host === '' || $username === '' || $password === '') {
            try {
                $CI->load->model('settings_model');
                $fallback = (array) $CI->settings_model->get_mikrotik_settings($router_id);

                if ($host === '') {
                    $host = trim((string) ($fallback['host'] ?? ''));
                }
                if ($username === '') {
                    $username = trim((string) ($fallback['username'] ?? ''));
                }
                if ($password === '') {
                    $password = trim((string) ($fallback['password'] ?? ''));
                }
                if (empty($router['api_port']) && !empty($fallback['api_port'])) {
                    $port = (int) $fallback['api_port'];
                }
                if (!array_key_exists('use_ssl', $router) && array_key_exists('use_ssl', $fallback)) {
                    $use_ssl = !empty($fallback['use_ssl']);
                }
            } catch (Throwable $e) {
                log_message('error', '[ROUTER_HELPER] fallback mikrotik settings error: ' . $e->getMessage());
            }
        }

        if ($host === '' || $username === '' || $password === '') {
            return array(
                'success' => false,
                'message' => 'Konfigurasi router belum lengkap (host/username/password).',
                'router' => $router,
                'api' => null,
            );
        }

        $router_class = APPPATH . 'third_party/RouterOS/routeros_api.class.php';
        if (!is_file($router_class)) {
            return array(
                'success' => false,
                'message' => 'RouterOS API class tidak ditemukan.',
                'router' => $router,
                'api' => null,
            );
        }

        require_once $router_class;
        $api = new RouterosAPI();
        $api->port = $port;
        $api->timeout = $timeout;
        $api->ssl = $use_ssl;

        $ok = @$api->connect($host, $username, $password);
        if (!$ok) {
            return array(
                'success' => false,
                'message' => 'Koneksi router gagal.',
                'router' => $router,
                'api' => null,
            );
        }

        return array(
            'success' => true,
            'message' => 'Router connected.',
            'router' => $router,
            'api' => $api,
            'config' => array(
                'host' => $host,
                'username' => $username,
                'password' => $password,
                'api_port' => $port,
                'use_ssl' => $use_ssl,
                'timeout' => $timeout,
            ),
        );
    }
}

if (!function_exists('telegram_get_groups_by_type')) {
    /**
     * Ambil target telegram group berdasarkan type + optional router_id.
     *
     * @param string $type
     * @param int|null $router_id
     * @param bool $allow_router_fallback
     * @return array
     */
    function telegram_get_groups_by_type($type, $router_id = null, $allow_router_fallback = false)
    {
        $CI =& get_instance();
        $CI->load->database();

        $type = trim((string) $type);
        $router_id = $router_id === null ? null : (int) $router_id;
        if ($type === '') {
            return array(
                'success' => false,
                'message' => 'Type telegram wajib diisi.',
                'groups' => array(),
                'router_filtered' => false,
                'router_fallback_used' => false,
            );
        }

        if (!$CI->db->table_exists('telegram_groups')) {
            return array(
                'success' => false,
                'message' => 'Tabel telegram_groups belum tersedia.',
                'groups' => array(),
                'router_filtered' => false,
                'router_fallback_used' => false,
            );
        }

        $group_fields = $CI->db->list_fields('telegram_groups');
        $type_column = in_array('type', $group_fields, true) ? 'type' : (in_array('group_type', $group_fields, true) ? 'group_type' : '');
        if ($type_column === '') {
            return array(
                'success' => false,
                'message' => 'Kolom type/group_type tidak ditemukan.',
                'groups' => array(),
                'router_filtered' => false,
                'router_fallback_used' => false,
            );
        }
        $query_type = $type;
        if ($type_column === 'group_type') {
            $legacy_map = array(
                'teknisi' => 'ops',
                'admin' => 'billing',
                'owner' => 'general',
                'alert' => 'monitoring',
            );
            if (isset($legacy_map[$type])) {
                $query_type = $legacy_map[$type];
            }
        }

        $router_column = in_array('router_id', $group_fields, true)
            ? 'router_id'
            : (in_array('router_scope_id', $group_fields, true) ? 'router_scope_id' : '');
        $router_column_exists = $router_column !== '';
        if (!$router_column_exists && $router_id !== null && $router_id > 0) {
            return array(
                'success' => false,
                'message' => 'Kolom router_id/router_scope_id pada telegram_groups belum tersedia.',
                'groups' => array(),
                'router_filtered' => false,
                'router_fallback_used' => false,
            );
        }
        $router_filter_applied = $router_column_exists && $router_id !== null && $router_id > 0;
        $router_fallback_used = false;

        $query_builder = static function () use ($CI, $type_column, $query_type, $group_fields) {
            $qb = $CI->db->from('telegram_groups')->where($type_column, $query_type);
            if (in_array('is_active', $group_fields, true)) {
                $qb->where('is_active', 1);
            }
            if (in_array('tenant_id', $group_fields, true)) {
                $tenant_id = getTenantId();
                if ($tenant_id !== null && (int) $tenant_id > 0) {
                    $qb->where('tenant_id', (int) $tenant_id);
                }
            }
            return $qb;
        };

        $groups = array();
        if ($router_filter_applied) {
            $groups = $query_builder()->where($router_column, (int) $router_id)->get()->result_array();

            if (empty($groups) && $allow_router_fallback) {
                $groups = $query_builder()
                    ->group_start()
                    ->where($router_column, 0)
                    ->or_where($router_column . ' IS NULL', null, false)
                    ->group_end()
                    ->get()
                    ->result_array();
                $router_fallback_used = !empty($groups);
            }
        } else {
            $groups = $query_builder()->get()->result_array();
        }

        if (empty($groups)) {
            $router_suffix = $router_filter_applied ? ' untuk router ini' : '';
            return array(
                'success' => false,
                'message' => 'Grup Telegram ' . $type . $router_suffix . ' belum tersedia.',
                'groups' => array(),
                'router_filtered' => $router_filter_applied,
                'router_fallback_used' => false,
            );
        }

        return array(
            'success' => true,
            'message' => 'Target group ditemukan.',
            'groups' => $groups,
            'router_filtered' => $router_filter_applied,
            'router_fallback_used' => $router_fallback_used,
        );
    }
}

if (!function_exists('telegram_dispatch_to_groups')) {
    /**
     * Kirim message ke daftar telegram_groups.
     *
     * @param array $groups
     * @param string $message
     * @param array $options
     * @return array
     */
    function telegram_dispatch_to_groups(array $groups, $message, array $options = array())
    {
        $CI =& get_instance();
        $CI->load->database();
        $CI->load->model('Settings_model', 'settings_model');
        $CI->load->library('encryption');
        static $dispatch_cache = array();

        $parse_mode = trim((string) ($options['parse_mode'] ?? 'HTML'));
        if ($parse_mode === '') {
            $parse_mode = 'HTML';
        }
        $reply_markup = isset($options['reply_markup']) && is_array($options['reply_markup'])
            ? $options['reply_markup']
            : array();

        if (empty($groups)) {
            return array('success' => false, 'message' => 'Tidak ada target group.', 'sent' => 0, 'failed' => 0, 'deduped' => 0);
        }

        $message = trim((string) $message);
        if ($message === '') {
            return array('success' => false, 'message' => 'Message Telegram kosong.', 'sent' => 0, 'failed' => 0, 'deduped' => 0);
        }

        $bots = array();
        if ($CI->db->table_exists('telegram_bots')) {
            foreach ($CI->db->get('telegram_bots')->result_array() as $bot) {
                $bots[(int) ($bot['id'] ?? 0)] = $bot;
            }
        }

        $sent = 0;
        $failed = 0;
        $deduped = 0;
        $failure_messages = array();
        foreach ($groups as $group) {
            $chat_id = trim((string) ($group['chat_id'] ?? ''));
            if ($chat_id === '') {
                $failed++;
                $failure_messages[] = 'chat_id kosong pada telegram_groups.id=' . (int) ($group['id'] ?? 0);
                continue;
            }

            $token = '';
            $bot_id = (int) ($group['bot_id'] ?? 0);
            if ($bot_id > 0 && isset($bots[$bot_id])) {
                $bot = $bots[$bot_id];
                if (isset($bot['is_active']) && (int) $bot['is_active'] !== 1) {
                    $failed++;
                    continue;
                }

                $token_raw = trim((string) ($bot['bot_token'] ?? ''));
                if ($token_raw !== '') {
                    $decoded = $CI->settings_model->decrypt_secret($token_raw);
                    if (!is_string($decoded) || trim($decoded) === '') {
                        $decoded = $CI->encryption->decrypt($token_raw);
                    }
                    if (is_string($decoded) && trim($decoded) !== '') {
                        $token = trim($decoded);
                    } elseif (strpos($token_raw, 'osl:') !== 0 && strpos($token_raw, 'ci3:') !== 0) {
                        $token = $token_raw;
                    }
                }
            }

            if ($token === '' && !empty($group['bot_token_enc'])) {
                $decoded = $CI->settings_model->decrypt_secret((string) $group['bot_token_enc']);
                if (!is_string($decoded) || trim($decoded) === '') {
                    $decoded = $CI->encryption->decrypt((string) $group['bot_token_enc']);
                }
                $token = (is_string($decoded) && trim($decoded) !== '') ? trim($decoded) : '';
            }
            if ($token === '' && !empty($group['bot_token'])) {
                $token = trim((string) $group['bot_token']);
            }

            if ($token === '') {
                $failed++;
                $failure_messages[] = 'bot token kosong/tidak bisa didekripsi untuk chat_id=' . $chat_id;
                continue;
            }

            $dispatch_key = ($bot_id > 0 ? (string) $bot_id : md5($token))
                . '|' . $chat_id
                . '|' . md5($message . '|' . json_encode($reply_markup));
            if (isset($dispatch_cache[$dispatch_key])) {
                $deduped++;
                continue;
            }

            $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
            $body = array(
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => $parse_mode,
                'disable_web_page_preview' => true,
            );
            if (!empty($reply_markup)) {
                $body['reply_markup'] = $reply_markup;
            }

            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                CURLOPT_POSTFIELDS => json_encode($body),
            ));
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                $failed++;
                log_message('error', '[TELEGRAM] cURL error: ' . $err);
                $failure_messages[] = 'cURL error untuk chat_id=' . $chat_id . ': ' . $err;
                continue;
            }

            $json = json_decode((string) $resp, true);
            if (is_array($json) && !empty($json['ok'])) {
                $sent++;
                $dispatch_cache[$dispatch_key] = true;
                continue;
            }

            $failed++;
            $description = '';
            if (is_array($json) && !empty($json['description'])) {
                $description = trim((string) $json['description']);
            }
            if ($description !== '') {
                $failure_messages[] = 'Telegram API untuk chat_id=' . $chat_id . ': ' . $description;
            } else {
                $failure_messages[] = 'Telegram API gagal untuk chat_id=' . $chat_id;
            }
            log_message('error', '[TELEGRAM] send failed: ' . substr((string) $resp, 0, 300));
        }

        $summary = 'Pengiriman Telegram selesai. Berhasil=' . $sent . ', gagal=' . $failed;
        if ($deduped > 0) {
            $summary .= ', deduped=' . $deduped;
        }
        if (!empty($failure_messages)) {
            $summary .= ' | ' . implode(' | ', array_slice($failure_messages, 0, 3));
        }

        return array(
            'success' => ($sent > 0 || $deduped > 0),
            'message' => $summary,
            'sent' => $sent,
            'failed' => $failed,
            'deduped' => $deduped,
        );
    }
}

if (!function_exists('sendTelegram')) {
    /**
     * Kirim notifikasi Telegram berdasarkan type/group.
     *
     * @param string $type
     * @param string $message
     * @return array
     */
    function sendTelegram($type, $message)
    {
        $target = telegram_get_groups_by_type($type, null, false);
        if (empty($target['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($target['message'] ?? 'Target group tidak ditemukan.'),
                'sent' => 0,
                'failed' => 0,
            );
        }
        return telegram_dispatch_to_groups((array) ($target['groups'] ?? array()), $message);
    }
}

if (!function_exists('sendTelegramByRouter')) {
    /**
     * Kirim notifikasi Telegram berdasarkan router + type.
     *
     * @param int $router_id
     * @param string $type
     * @param string $message
     * @param bool $allow_router_fallback
     * @return array
     */
    function sendTelegramByRouter($router_id, $type, $message, $allow_router_fallback = true)
    {
        $CI =& get_instance();
        $CI->load->database();

        $router_id = (int) $router_id;
        $type = trim((string) $type);
        $allow_router_fallback = (bool) $allow_router_fallback;
        if ($router_id <= 0) {
            return array(
                'success' => false,
                'message' => 'Router tujuan Telegram tidak valid.',
                'sent' => 0,
                'failed' => 0,
                'deduped' => 0,
                'router_id' => $router_id,
                'fallback' => 'none',
                'skipped' => true,
            );
        }

        $target = telegram_get_groups_by_type($type, $router_id, $allow_router_fallback);
        $groups = array();
        $fallback = 'none';

        if (!empty($target['success'])) {
            $groups = (array) ($target['groups'] ?? array());
            if (!empty($target['router_fallback_used'])) {
                $fallback = 'global_type';
            }
        }

        if (empty($groups) && $CI->db->table_exists('telegram_groups')) {
            $group_fields = $CI->db->list_fields('telegram_groups');
            $router_column = in_array('router_id', $group_fields, true)
                ? 'router_id'
                : (in_array('router_scope_id', $group_fields, true) ? 'router_scope_id' : '');

            if ($router_column !== '') {
                $qb = $CI->db->from('telegram_groups')->where($router_column, $router_id);
                if (in_array('is_active', $group_fields, true)) {
                    $qb->where('is_active', 1);
                }
                if (in_array('tenant_id', $group_fields, true)) {
                    $tenant_id = function_exists('getTenantId') ? getTenantId() : null;
                    if ($tenant_id !== null && (int) $tenant_id > 0) {
                        $qb->where('tenant_id', (int) $tenant_id);
                    }
                }
                $groups = $qb->order_by('id', 'ASC')->get()->result_array();
                if (!empty($groups)) {
                    $fallback = 'router_any_type';
                }
            }
        }

        if (empty($groups)) {
            $msg = 'Grup Telegram untuk router ini belum tersedia.';
            if ($allow_router_fallback) {
                $msg .= ' Silakan lengkapi pengaturan Telegram.';
            }
            log_message('debug', '[TELEGRAM][ROUTER] ' . $msg);
            return array(
                'success' => false,
                'message' => $msg,
                'sent' => 0,
                'failed' => 0,
                'deduped' => 0,
                'router_id' => $router_id,
                'fallback' => $fallback,
                'skipped' => true,
            );
        }

        $res = telegram_dispatch_to_groups($groups, $message);
        $res['router_id'] = $router_id;
        $res['fallback'] = $fallback;
        $res['skipped'] = false;
        $res['requested_type'] = $type;
        if ($fallback === 'global_type') {
            $res['message'] .= ' | menggunakan grup utama';
        } elseif ($fallback === 'router_any_type') {
            $res['message'] .= ' | menggunakan grup router';
        }
        return $res;
    }
}

if (!function_exists('sendByRouter')) {
    /**
     * Alias helper untuk dispatch Telegram berbasis router.
     *
     * @param int $router_id
     * @param string $type
     * @param string $message
     * @return array
     */
    function sendByRouter($router_id, $type = 'admin', $message = '')
    {
        return sendTelegramByRouter((int) $router_id, (string) $type, (string) $message);
    }
}

if (!function_exists('sendTelegramByType')) {
    /**
     * Backward compatibility wrapper.
     *
     * @param string $type
     * @param string $message
     * @return array
     */
    function sendTelegramByType($type, $message)
    {
        return sendTelegram($type, $message);
    }
}
