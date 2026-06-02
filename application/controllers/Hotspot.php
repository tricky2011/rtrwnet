<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Hotspot extends MY_Controller
{
    private $hotspot_cache_ttl = 120;
    private $table_fields_cache = array();

    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->database();
        $this->load->library(array('session'));
        $this->load->helper(array('url', 'form', 'tenant'));
    }

    public function index()
    {
        $router = $this->resolve_router_context();
        $profiles = array();
        $servers = array();
        $users = array();
        $active = array();
        $error = '';
        $cache_info = array(
            'loaded' => false,
            'from_cache' => false,
            'fetched_at' => '',
            'age_seconds' => 0,
        );
        $force_refresh = (string) $this->input->get('refresh', true) === '1';

        if (empty($router['success'])) {
            $error = (string) ($router['message'] ?? 'Router belum dipilih.');
        } else {
            $router_id = (int) $router['router_id'];
            $cached = $this->read_hotspot_cache($router_id, !$force_refresh);
            if (!$force_refresh && !empty($cached['success'])) {
                $profiles = (array) ($cached['data']['profiles'] ?? array());
                $servers = (array) ($cached['data']['servers'] ?? array());
                $users = (array) ($cached['data']['users'] ?? array());
                $active = (array) ($cached['data']['active'] ?? array());
                $cache_info = array(
                    'loaded' => true,
                    'from_cache' => true,
                    'fetched_at' => (string) ($cached['data']['fetched_at'] ?? ''),
                    'age_seconds' => (int) ($cached['age_seconds'] ?? 0),
                );
            } else {
                if (!$force_refresh && empty($cached['success'])) {
                    $error = 'Data hotspot belum dimuat. Klik Refresh dari Router untuk mengambil data terbaru.';
                } else {
                    $connect = connectRouter($router_id);
                    if (empty($connect['success']) || empty($connect['api'])) {
                        $stale = $this->read_hotspot_cache($router_id, true);
                        if (!empty($stale['success'])) {
                            $profiles = (array) ($stale['data']['profiles'] ?? array());
                            $servers = (array) ($stale['data']['servers'] ?? array());
                            $users = (array) ($stale['data']['users'] ?? array());
                            $active = (array) ($stale['data']['active'] ?? array());
                            $cache_info = array(
                                'loaded' => true,
                                'from_cache' => true,
                                'fetched_at' => (string) ($stale['data']['fetched_at'] ?? ''),
                                'age_seconds' => (int) ($stale['age_seconds'] ?? 0),
                            );
                        }
                        $error = 'Gagal konek router: ' . (string) ($connect['message'] ?? 'unknown');
                    } else {
                        $api = $connect['api'];
                        try {
                            $profiles = $this->router_rows($api, '/ip/hotspot/user/profile/print', array(
                                '.proplist' => '.id,name,shared-users,rate-limit,comment',
                            ));
                            $servers = $this->router_rows($api, '/ip/hotspot/print', array(
                                '.proplist' => '.id,name,disabled,interface,profile',
                            ));
                            $users = $this->router_rows($api, '/ip/hotspot/user/print', array(
                                '.proplist' => '.id,name,profile,server,limit-uptime,uptime,limit-bytes-total,bytes-in,bytes-out,mac-address,disabled,comment',
                            ));
                            $active = $this->router_rows($api, '/ip/hotspot/active/print', array(
                                '.proplist' => '.id,user,address,mac-address,uptime,bytes-in,bytes-out',
                            ));

                            $fetched_at = date('Y-m-d H:i:s');
                            $this->write_hotspot_cache($router_id, array(
                                'profiles' => $profiles,
                                'servers' => $servers,
                                'users' => $users,
                                'active' => $active,
                                'fetched_at' => $fetched_at,
                            ));
                            $cache_info = array(
                                'loaded' => true,
                                'from_cache' => false,
                                'fetched_at' => $fetched_at,
                                'age_seconds' => 0,
                            );
                        } catch (Throwable $e) {
                            $error = 'Gagal membaca hotspot dari router: ' . $e->getMessage();
                            log_message('error', '[HOTSPOT][INDEX] ' . $e->getMessage());
                        } finally {
                            if (is_object($api) && method_exists($api, 'disconnect')) {
                                $api->disconnect();
                            }
                        }
                    }
                }
            }
        }

        $this->load->view('hotspot/index', array(
            'router' => $router,
            'error' => $error,
            'profiles' => $profiles,
            'servers' => $servers,
            'users' => $users,
            'active' => $active,
            'generated_users' => (array) $this->session->flashdata('hotspot_generated'),
            'cache_info' => $cache_info,
        ));
    }

    public function add_user()
    {
        if (!$this->ensure_post()) {
            return;
        }

        $router = $this->resolve_router_context();
        if (empty($router['success'])) {
            return $this->flash_redirect('error', (string) ($router['message'] ?? 'Router belum dipilih.'));
        }

        $username = $this->clean_username((string) $this->input->post('username', true), 64);
        $password = trim((string) $this->input->post('password', true));
        $profile = trim((string) $this->input->post('profile', true));
        $server = trim((string) $this->input->post('server', true));
        $time_limit = $this->normalize_router_time((string) $this->input->post('time_limit', true));
        $data_limit = $this->parse_data_limit(
            (string) $this->input->post('data_limit_value', true),
            (string) $this->input->post('data_limit_unit', true)
        );
        $comment = trim((string) $this->input->post('comment', true));
        $profile_meta = array();

        if ($username === '' || $password === '') {
            return $this->flash_redirect('error', 'Username dan password hotspot wajib diisi.');
        }
        if ($time_limit === false || $data_limit === false) {
            return $this->flash_redirect('error', 'Time limit atau data limit tidak valid.');
        }

        $connect = connectRouter((int) $router['router_id']);
        if (empty($connect['success']) || empty($connect['api'])) {
            return $this->flash_redirect('error', 'Gagal konek router: ' . (string) ($connect['message'] ?? 'unknown'));
        }

        $api = $connect['api'];
        try {
            if ($this->hotspot_user_exists($api, $username)) {
                return $this->flash_redirect('error', 'Hotspot user `' . $username . '` sudah ada.');
            }
            $profile_meta = $this->hotspot_profile_metadata($api, $profile);

            $payload = $this->build_hotspot_user_payload($username, $password, $profile, $server, $time_limit, $data_limit, $comment);
            $resp = $api->comm('/ip/hotspot/user/add', $payload);
            if ($this->has_router_error($resp)) {
                return $this->flash_redirect('error', 'Gagal tambah user: ' . $this->router_error($resp));
            }
        } catch (Throwable $e) {
            log_message('error', '[HOTSPOT][ADD_USER] ' . $e->getMessage());
            return $this->flash_redirect('error', 'Gagal tambah user: ' . $e->getMessage());
        } finally {
            if (is_object($api) && method_exists($api, 'disconnect')) {
                $api->disconnect();
            }
        }

        $cashflow = $this->record_hotspot_cashflow((int) $router['router_id'], $profile, 1, $profile_meta, 'hotspot_user', 'Penjualan hotspot user ' . $username);
        $message = 'Hotspot user `' . $username . '` berhasil ditambahkan.';
        if (empty($cashflow['success']) && empty($cashflow['skipped'])) {
            $message .= ' Cashflow belum tercatat: ' . (string) ($cashflow['message'] ?? 'unknown');
        }

        return $this->flash_redirect('success', $message);
    }

    public function generate_users()
    {
        if (!$this->ensure_post()) {
            return;
        }

        $router = $this->resolve_router_context();
        if (empty($router['success'])) {
            return $this->flash_redirect('error', (string) ($router['message'] ?? 'Router belum dipilih.'));
        }

        $count = (int) $this->input->post('count', true);
        $length = (int) $this->input->post('username_length', true);
        $mode = trim((string) $this->input->post('mode', true));
        $prefix = $this->clean_username((string) $this->input->post('prefix', true), 8);
        $profile = trim((string) $this->input->post('profile', true));
        $server = trim((string) $this->input->post('server', true));
        $time_limit = $this->normalize_router_time((string) $this->input->post('time_limit', true));
        $data_limit = $this->parse_data_limit(
            (string) $this->input->post('data_limit_value', true),
            (string) $this->input->post('data_limit_unit', true)
        );
        $comment = trim((string) $this->input->post('comment', true));

        if ($count < 1 || $count > 500) {
            return $this->flash_redirect('error', 'Jumlah generate wajib 1 sampai 500 user.');
        }
        if ($length < 1 || $length > 8) {
            return $this->flash_redirect('error', 'Panjang username maksimal 8 karakter.');
        }
        if (strlen($prefix) >= $length) {
            return $this->flash_redirect('error', 'Prefix harus lebih pendek dari panjang username.');
        }
        if (!in_array($mode, array('username_password', 'username_equals_password'), true)) {
            return $this->flash_redirect('error', 'Mode generate tidak valid.');
        }
        if ($time_limit === false || $data_limit === false) {
            return $this->flash_redirect('error', 'Time limit atau data limit tidak valid.');
        }

        $connect = connectRouter((int) $router['router_id']);
        if (empty($connect['success']) || empty($connect['api'])) {
            return $this->flash_redirect('error', 'Gagal konek router: ' . (string) ($connect['message'] ?? 'unknown'));
        }

        $api = $connect['api'];
        $created = array();
        $failed = 0;
        $profile_meta = array();

        try {
            $existing = $this->hotspot_usernames($api);
            $profile_meta = $this->hotspot_profile_metadata($api, $profile);
            for ($i = 0; $i < $count; $i++) {
                $username = $this->generate_unique_username($prefix, $length, $existing);
                if ($username === '') {
                    $failed++;
                    continue;
                }

                $password = $mode === 'username_equals_password'
                    ? $username
                    : $this->random_token($length);
                $batch_comment = trim($comment . ' batch=' . date('YmdHis'));
                $payload = $this->build_hotspot_user_payload($username, $password, $profile, $server, $time_limit, $data_limit, $batch_comment);
                $resp = $api->comm('/ip/hotspot/user/add', $payload);
                if ($this->has_router_error($resp)) {
                    $failed++;
                    continue;
                }

                $existing[$username] = true;
                $created[] = array(
                    'username' => $username,
                    'password' => $password,
                    'profile' => $profile,
                    'time_limit' => $time_limit,
                    'data_limit' => $data_limit,
                    'price' => $profile_meta['price'] ?? '',
                    'selling_price' => $profile_meta['selling_price'] ?? '',
                );
            }
        } catch (Throwable $e) {
            log_message('error', '[HOTSPOT][GENERATE] ' . $e->getMessage());
            return $this->flash_redirect('error', 'Generate gagal: ' . $e->getMessage());
        } finally {
            if (is_object($api) && method_exists($api, 'disconnect')) {
                $api->disconnect();
            }
        }

        $cashflow = $this->record_hotspot_cashflow((int) $router['router_id'], $profile, count($created), $profile_meta, 'hotspot_generate', 'Penjualan voucher hotspot ' . ($profile !== '' ? $profile : 'default'));
        $this->session->set_flashdata('hotspot_generated', $created);
        $message = 'Generate selesai. Berhasil: ' . count($created) . ', gagal: ' . $failed . '.';
        if (empty($cashflow['success']) && empty($cashflow['skipped'])) {
            $message .= ' Cashflow belum tercatat: ' . (string) ($cashflow['message'] ?? 'unknown');
        }

        return $this->flash_redirect(
            $failed > 0 ? 'error' : 'success',
            $message
        );
    }

    public function add_profile()
    {
        if (!$this->ensure_post()) {
            return;
        }

        $router = $this->resolve_router_context();
        if (empty($router['success'])) {
            return $this->flash_redirect('error', (string) ($router['message'] ?? 'Router belum dipilih.'));
        }

        $name = trim((string) $this->input->post('name', true));
        $validity = $this->normalize_router_time((string) $this->input->post('validity', true));
        $expire_mode = trim((string) $this->input->post('expire_mode', true));
        $record = $this->input->post('record') ? true : false;
        $user_lock = $this->input->post('user_lock') ? true : false;
        $price = $this->parse_money((string) $this->input->post('price', true));
        $selling_price = $this->parse_money((string) $this->input->post('selling_price', true));

        if (!$this->is_valid_profile_name($name)) {
            return $this->flash_redirect('error', 'Nama profile hanya boleh huruf, angka, dan tanda minus (-).');
        }
        if ($validity === false) {
            return $this->flash_redirect('error', 'Validity tidak valid.');
        }
        if (!in_array($expire_mode, array('remove', 'remove_record', 'notice', 'notice_record'), true)) {
            return $this->flash_redirect('error', 'Mode expired tidak valid.');
        }
        if (strpos($expire_mode, '_record') !== false) {
            $record = true;
        }

        $connect = connectRouter((int) $router['router_id']);
        if (empty($connect['success']) || empty($connect['api'])) {
            return $this->flash_redirect('error', 'Gagal konek router: ' . (string) ($connect['message'] ?? 'unknown'));
        }

        $api = $connect['api'];
        try {
            if ($this->hotspot_profile_exists($api, $name)) {
                return $this->flash_redirect('error', 'Hotspot profile `' . $name . '` sudah ada.');
            }

            $metadata = array(
                'validity' => $validity,
                'expire_mode' => $expire_mode,
                'record' => $record ? '1' : '0',
                'user_lock' => $user_lock ? '1' : '0',
                'price' => (string) $price,
                'selling_price' => (string) $selling_price,
            );

            $payload = array(
                'name' => $name,
                'comment' => $this->build_profile_comment($metadata),
            );
            if ($user_lock) {
                $payload['shared-users'] = '1';
            }

            $on_login = $this->build_profile_on_login_script($validity, $expire_mode, $record, $user_lock);
            if ($on_login !== '') {
                $payload['on-login'] = $on_login;
            }

            $resp = $api->comm('/ip/hotspot/user/profile/add', $payload);
            if ($this->has_router_error($resp)) {
                return $this->flash_redirect('error', 'Gagal tambah profile: ' . $this->router_error($resp));
            }
        } catch (Throwable $e) {
            log_message('error', '[HOTSPOT][ADD_PROFILE] ' . $e->getMessage());
            return $this->flash_redirect('error', 'Gagal tambah profile: ' . $e->getMessage());
        } finally {
            if (is_object($api) && method_exists($api, 'disconnect')) {
                $api->disconnect();
            }
        }

        return $this->flash_redirect('success', 'Hotspot profile `' . $name . '` berhasil dibuat.');
    }

    private function resolve_router_context()
    {
        $router_id = $this->getEffectiveRouterId();
        $router_id = $router_id !== null ? (int) $router_id : 0;
        if ($router_id <= 0) {
            return array(
                'success' => false,
                'message' => 'Pilih router aktif dulu dari switcher header.',
                'router_id' => 0,
                'router_name' => '',
            );
        }

        $name = $this->router_name($router_id);
        return array(
            'success' => true,
            'router_id' => $router_id,
            'router_name' => $name !== '' ? $name : ('Router #' . $router_id),
        );
    }

    private function router_rows($api, $command, array $params = array())
    {
        $rows = $api->comm($command, $params);
        return is_array($rows) ? $rows : array();
    }

    private function hotspot_cache_path($router_id)
    {
        return APPPATH . 'cache/hotspot/router_' . (int) $router_id . '.json';
    }

    private function read_hotspot_cache($router_id, $allow_stale = false)
    {
        $path = $this->hotspot_cache_path($router_id);
        if (!is_file($path) || !is_readable($path)) {
            return array('success' => false);
        }

        $raw = file_get_contents($path);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return array('success' => false);
        }

        $fetched_ts = (int) ($data['fetched_ts'] ?? 0);
        if ($fetched_ts <= 0) {
            return array('success' => false);
        }

        $age = time() - $fetched_ts;
        if (!$allow_stale && $age > $this->hotspot_cache_ttl) {
            return array('success' => false, 'stale' => true, 'age_seconds' => $age);
        }

        return array(
            'success' => true,
            'data' => $data,
            'age_seconds' => max(0, $age),
        );
    }

    private function write_hotspot_cache($router_id, array $data)
    {
        $dir = APPPATH . 'cache/hotspot';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            log_message('error', '[HOTSPOT][CACHE] gagal membuat folder cache hotspot.');
            return false;
        }

        $data['fetched_ts'] = time();
        $path = $this->hotspot_cache_path($router_id);
        return file_put_contents($path, json_encode($data), LOCK_EX) !== false;
    }

    private function hotspot_user_exists($api, $username)
    {
        $rows = $this->router_rows($api, '/ip/hotspot/user/print', array(
            '?name' => $username,
            '.proplist' => '.id,name',
        ));
        foreach ($rows as $row) {
            if (isset($row['name']) && (string) $row['name'] === (string) $username) {
                return true;
            }
        }
        return false;
    }

    private function hotspot_profile_exists($api, $name)
    {
        $rows = $this->router_rows($api, '/ip/hotspot/user/profile/print', array(
            '?name' => $name,
            '.proplist' => '.id,name',
        ));
        foreach ($rows as $row) {
            if (isset($row['name']) && (string) $row['name'] === (string) $name) {
                return true;
            }
        }
        return false;
    }

    private function hotspot_usernames($api)
    {
        $rows = $this->router_rows($api, '/ip/hotspot/user/print', array('.proplist' => 'name'));
        $names = array();
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        return $names;
    }

    private function hotspot_profile_metadata($api, $profile)
    {
        $profile = trim((string) $profile);
        if ($profile === '') {
            return array();
        }

        $rows = $this->router_rows($api, '/ip/hotspot/user/profile/print', array(
            '?name' => $profile,
            '.proplist' => 'name,comment',
        ));
        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') === $profile) {
                return $this->parse_profile_comment((string) ($row['comment'] ?? ''));
            }
        }

        return array();
    }

    private function build_hotspot_user_payload($username, $password, $profile, $server, $time_limit, $data_limit, $comment)
    {
        $payload = array(
            'name' => $username,
            'password' => $password,
            'disabled' => 'no',
        );
        if ($profile !== '') {
            $payload['profile'] = $profile;
        }
        if ($server !== '') {
            $payload['server'] = $server;
        }
        if ($time_limit !== '') {
            $payload['limit-uptime'] = $time_limit;
        }
        if ($data_limit !== '') {
            $payload['limit-bytes-total'] = $data_limit;
        }
        if ($comment !== '') {
            $payload['comment'] = $comment;
        }
        return $payload;
    }

    private function generate_unique_username($prefix, $length, array $existing)
    {
        $length = max(1, min(8, (int) $length));
        $prefix = substr($prefix, 0, $length - 1);
        $tail_length = $length - strlen($prefix);

        for ($i = 0; $i < 1000; $i++) {
            $candidate = $prefix . $this->random_token($tail_length);
            if (!isset($existing[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }

    private function random_token($length)
    {
        $length = max(1, (int) $length);
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $max = strlen($chars) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    private function clean_username($value, $max_length)
    {
        $value = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value);
        return substr((string) $value, 0, max(1, (int) $max_length));
    }

    private function normalize_router_time($value)
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^[0-9smhdw:]+$/', $value)) {
            return false;
        }
        return $value;
    }

    private function parse_data_limit($amount, $unit)
    {
        $amount = trim((string) $amount);
        if ($amount === '') {
            return '';
        }
        if (!preg_match('/^[0-9]+(?:[.,][0-9]+)?$/', $amount)) {
            return false;
        }

        $num = (float) str_replace(',', '.', $amount);
        $unit = strtoupper(trim((string) $unit));
        $multiplier = array(
            'B' => 1,
            'KB' => 1024,
            'MB' => 1048576,
            'GB' => 1073741824,
        );
        if (!isset($multiplier[$unit])) {
            $unit = 'MB';
        }

        return (string) max(1, (int) round($num * $multiplier[$unit]));
    }

    private function parse_money($value)
    {
        $value = preg_replace('/[^0-9]/', '', (string) $value);
        if ($value === '') {
            return 0;
        }
        return (float) $value;
    }

    private function is_valid_profile_name($name)
    {
        return is_string($name)
            && $name !== ''
            && strlen($name) <= 64
            && (bool) preg_match('/^[A-Za-z0-9-]+$/', $name);
    }

    private function build_profile_comment(array $metadata)
    {
        $parts = array('RTRWNET-HOTSPOT');
        foreach ($metadata as $key => $value) {
            $parts[] = $key . '=' . str_replace(array(';', '|'), '', (string) $value);
        }
        return implode(';', $parts);
    }

    private function parse_profile_comment($comment)
    {
        $comment = trim((string) $comment);
        if ($comment === '' || strpos($comment, 'RTRWNET-HOTSPOT') !== 0) {
            return array();
        }

        $meta = array();
        foreach (explode(';', $comment) as $part) {
            $pos = strpos($part, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($part, 0, $pos));
            $value = trim(substr($part, $pos + 1));
            if ($key !== '') {
                $meta[$key] = $value;
            }
        }
        return $meta;
    }

    private function build_profile_on_login_script($validity, $expire_mode, $record, $user_lock)
    {
        $lines = array();
        $lines[] = ':local uname $user';
        $lines[] = ':local uid [/ip hotspot user find where name=$uname]';
        $lines[] = ':if ([:len $uid] > 0) do={';

        if ($record) {
            $lines[] = ':local c [/ip hotspot user get $uid comment]';
            $lines[] = ':if ([:find $c "first-login="] = nil) do={ /ip hotspot user set $uid comment=($c . " first-login=" . [/system clock get date] . " " . [/system clock get time]) }';
        }

        if ($user_lock) {
            $lines[] = ':local mac $"mac-address"';
            $lines[] = ':local oldmac [/ip hotspot user get $uid mac-address]';
            $lines[] = ':if (($oldmac = "") || ($oldmac = "00:00:00:00:00:00")) do={ /ip hotspot user set $uid mac-address=$mac }';
        }

        if ($validity !== '') {
            $lines[] = ':local sname ("hs-exp-" . $uname)';
            $lines[] = ':if ([:len [/system scheduler find where name=$sname]] = 0) do={';
            if (strpos($expire_mode, 'remove') === 0) {
                $lines[] = ':local ev ("/ip hotspot user remove [find where name=\\"" . $uname . "\\"]; /system scheduler remove [find where name=\\"" . $sname . "\\"]")';
            } else {
                $lines[] = ':local ev ("/ip hotspot user set [find where name=\\"" . $uname . "\\"] disabled=yes comment=\\"EXPIRED\\"; /system scheduler remove [find where name=\\"" . $sname . "\\"]")';
            }
            $lines[] = '/system scheduler add name=$sname interval=' . $validity . ' on-event=$ev comment=("RTRWNET hotspot expire " . $uname)';
            $lines[] = '}';
        }

        $lines[] = '}';
        return implode('; ', $lines);
    }

    private function record_hotspot_cashflow($router_id, $profile, $qty, array $metadata, $reference_type, $description)
    {
        $qty = (int) $qty;
        $router_id = (int) $router_id;
        $unit_price = (float) ($metadata['price'] ?? 0);
        if ($qty <= 0 || $unit_price <= 0) {
            return array('success' => true, 'skipped' => true, 'message' => 'price kosong');
        }
        if (!$this->db->table_exists('cashflow_transactions')) {
            return array('success' => true, 'skipped' => true, 'message' => 'cashflow_transactions tidak tersedia');
        }

        $now = date('Y-m-d H:i:s');
        $payload = array(
            'type' => 'income',
            'amount' => $unit_price * $qty,
            'description' => trim($description . ' x' . $qty),
        );

        if ($this->table_has_column('cashflow_transactions', 'txn_number')) {
            $payload['txn_number'] = $this->next_cashflow_txn_number($now, $router_id);
        }
        if ($this->table_has_column('cashflow_transactions', 'txn_date')) {
            $payload['txn_date'] = $now;
        } elseif ($this->table_has_column('cashflow_transactions', 'transaction_date')) {
            $payload['transaction_date'] = date('Y-m-d');
        } elseif ($this->table_has_column('cashflow_transactions', 'date')) {
            $payload['date'] = date('Y-m-d');
        }
        if ($this->table_has_column('cashflow_transactions', 'category_id')) {
            $category_id = $this->ensure_hotspot_income_category_id();
            if ($category_id > 0) {
                $payload['category_id'] = $category_id;
            }
        }
        if ($this->table_has_column('cashflow_transactions', 'category')) {
            $payload['category'] = 'Hotspot';
        }
        if ($this->table_has_column('cashflow_transactions', 'reference_type')) {
            $payload['reference_type'] = $reference_type;
        }
        if ($this->table_has_column('cashflow_transactions', 'created_by')) {
            $payload['created_by'] = (int) $this->session->userdata('user_id');
        } elseif ($this->table_has_column('cashflow_transactions', 'recorded_by')) {
            $payload['recorded_by'] = (int) $this->session->userdata('user_id');
        }
        if ($this->table_has_column('cashflow_transactions', 'router_id') && $router_id > 0) {
            $payload['router_id'] = $router_id;
        }
        if ($this->table_has_column('cashflow_transactions', 'tenant_id') && function_exists('getTenantId')) {
            $tenant_id = getTenantId();
            if ($tenant_id !== null && (int) $tenant_id > 0) {
                $payload['tenant_id'] = (int) $tenant_id;
            }
        }
        if ($this->table_has_column('cashflow_transactions', 'created_at')) {
            $payload['created_at'] = $now;
        }
        if ($this->table_has_column('cashflow_transactions', 'updated_at')) {
            $payload['updated_at'] = $now;
        }

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('cashflow_transactions', $payload);
        $error = $this->db->error();
        $this->db->db_debug = $old_debug;

        if (!$ok) {
            log_message('error', '[HOTSPOT][CASHFLOW] insert failed: ' . json_encode($error) . ' payload=' . json_encode($payload));
            return array('success' => false, 'skipped' => false, 'message' => (string) ($error['message'] ?? 'insert cashflow gagal'));
        }

        return array('success' => true, 'skipped' => false, 'id' => (int) $this->db->insert_id());
    }

    private function ensure_hotspot_income_category_id()
    {
        if (!$this->db->table_exists('cashflow_categories')) {
            return 0;
        }

        $fields = $this->db->list_fields('cashflow_categories');
        $name_col = in_array('category_name', $fields, true) ? 'category_name' : (in_array('name', $fields, true) ? 'name' : '');
        $code_col = in_array('category_code', $fields, true) ? 'category_code' : (in_array('code', $fields, true) ? 'code' : '');
        if ($name_col === '' || $code_col === '') {
            return 0;
        }

        $qb = $this->db
            ->select('id')
            ->from('cashflow_categories')
            ->group_start()
                ->where($code_col, 'hotspot')
                ->or_where($name_col, 'Hotspot')
            ->group_end()
            ->limit(1);
        if (in_array('type', $fields, true)) {
            $qb->where('type', 'income');
        }
        $existing = $qb->get()->row_array();
        if (!empty($existing['id'])) {
            return (int) $existing['id'];
        }

        $payload = array(
            $code_col => 'hotspot',
            $name_col => 'Hotspot',
        );
        if (in_array('type', $fields, true)) {
            $payload['type'] = 'income';
        }
        if (in_array('is_active', $fields, true)) {
            $payload['is_active'] = 1;
        }
        if (in_array('created_at', $fields, true)) {
            $payload['created_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $fields, true)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('cashflow_categories', $payload);
        $error = $this->db->error();
        $this->db->db_debug = $old_debug;
        if (!$ok) {
            log_message('error', '[HOTSPOT][CASHFLOW_CATEGORY] insert failed: ' . json_encode($error));
            return 0;
        }

        return (int) $this->db->insert_id();
    }

    private function next_cashflow_txn_number($txn_date, $router_id)
    {
        $prefix = 'CF-' . date('Ymd', strtotime($txn_date)) . '-';
        $qb = $this->db
            ->select('txn_number')
            ->from('cashflow_transactions')
            ->like('txn_number', $prefix, 'after')
            ->order_by('id', 'DESC')
            ->limit(1);
        if ($this->table_has_column('cashflow_transactions', 'router_id') && (int) $router_id > 0) {
            $qb->where('router_id', (int) $router_id);
        }

        $row = $qb->get()->row_array();
        $next = 1;
        if (!empty($row['txn_number'])) {
            $parts = explode('-', (string) $row['txn_number']);
            $tail = end($parts);
            if (ctype_digit((string) $tail)) {
                $next = (int) $tail + 1;
            }
        }

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function table_has_column($table, $column)
    {
        $table = (string) $table;
        if (!array_key_exists($table, $this->table_fields_cache)) {
            $this->table_fields_cache[$table] = $this->db->table_exists($table)
                ? $this->db->list_fields($table)
                : array();
        }

        if (empty($this->table_fields_cache[$table])) {
            return false;
        }
        return in_array($column, $this->table_fields_cache[$table], true);
    }

    private function has_router_error($response)
    {
        if (!is_array($response)) {
            return false;
        }
        if (isset($response['!trap']) || isset($response['!fatal'])) {
            return true;
        }
        foreach ($response as $row) {
            if (is_array($row) && (isset($row['!trap']) || isset($row['!fatal']) || isset($row['message']))) {
                return true;
            }
        }
        return false;
    }

    private function router_error($response)
    {
        if (!is_array($response)) {
            return 'unknown router error';
        }
        foreach ($response as $row) {
            if (is_array($row) && isset($row['message'])) {
                return (string) $row['message'];
            }
        }
        if (isset($response['message'])) {
            return (string) $response['message'];
        }
        return json_encode($response);
    }

    private function router_name($router_id)
    {
        if (!$this->db->table_exists('routers')) {
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
            ->where('id', (int) $router_id)
            ->limit(1)
            ->get()
            ->row_array();

        return trim((string) ($row['name'] ?? ''));
    }

    private function ensure_post()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return false;
        }
        return true;
    }

    private function flash_redirect($type, $message)
    {
        $this->session->set_flashdata($type, $message);
        redirect('hotspot');
        return;
    }
}
