<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Reusable RouterOS API library for CodeIgniter 3.
 *
 * Public methods:
 * - create_ppp_secret(username, password, profile)
 * - update_ppp_profile(username, profile)
 * - remove_ppp_secret(username)
 * - add_to_isolir_list(ip)
 * - remove_from_isolir_list(ip)
 *
 * Return format for each public method:
 *   ['success' => bool, 'message' => string]
 */
class Mikrotik_routeros
{
    private $CI;
    private $api;
    private $connected = false;

    private $host;
    private $port;
    private $user;
    private $pass;
    private $timeout;
    private $debug;
    private $ssl;
    private $pppoe_service;
    private $isolir_address_list;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->config->load('mikrotik', true);

        $cfg = $this->CI->config->item('mikrotik');
        if (!is_array($cfg)) {
            $cfg = array();
        }

        $this->host = isset($cfg['mikrotik_host']) ? $cfg['mikrotik_host'] : '';
        $this->port = isset($cfg['mikrotik_port']) ? (int) $cfg['mikrotik_port'] : 8728;
        $this->user = isset($cfg['mikrotik_user']) ? $cfg['mikrotik_user'] : '';
        $this->pass = isset($cfg['mikrotik_pass']) ? $cfg['mikrotik_pass'] : '';
        $this->timeout = isset($cfg['mikrotik_timeout']) ? (int) $cfg['mikrotik_timeout'] : 5;
        $this->debug = !empty($cfg['mikrotik_debug']);
        $this->ssl = !empty($cfg['mikrotik_ssl']);
        $this->pppoe_service = isset($cfg['pppoe_service']) ? $cfg['pppoe_service'] : 'pppoe';
        $this->isolir_address_list = isset($cfg['isolir_address_list']) ? $cfg['isolir_address_list'] : 'ISOLIR';

        require_once APPPATH . 'third_party/RouterOS/routeros_api.class.php';
        $this->api = new RouterosAPI();
        $this->api->port = $this->port;
        $this->api->timeout = $this->timeout;
        $this->api->ssl = $this->ssl;
        $this->api->debug = $this->debug;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function create_ppp_secret($username, $password, $profile)
    {
        if (!$this->validate_secret_input($username, $password, $profile)) {
            return $this->fail('Parameter PPP secret tidak valid.');
        }

        $secret = $this->find_ppp_secret($username);
        if (!$secret['success']) {
            return $secret;
        }

        if (!empty($secret['data'])) {
            return $this->fail("PPP secret '{$username}' sudah ada.");
        }

        $result = $this->run('/ppp/secret/add', array(
            'name' => $username,
            'password' => $password,
            'profile' => $profile,
            'service' => $this->pppoe_service,
        ));

        if (!$result['success']) {
            return $result;
        }

        return $this->ok("PPP secret '{$username}' berhasil dibuat.");
    }

    public function update_ppp_profile($username, $profile)
    {
        if (trim((string) $username) === '' || trim((string) $profile) === '') {
            return $this->fail('Username dan profile wajib diisi.');
        }

        $secret = $this->find_ppp_secret($username);
        if (!$secret['success']) {
            return $secret;
        }

        if (empty($secret['data']) || !isset($secret['data'][0]['.id'])) {
            return $this->fail("PPP secret '{$username}' tidak ditemukan.");
        }

        $id = $secret['data'][0]['.id'];
        $result = $this->run('/ppp/secret/set', array(
            '.id' => $id,
            'profile' => $profile,
        ));

        if (!$result['success']) {
            return $result;
        }

        return $this->ok("Profile PPP '{$username}' berhasil diubah.");
    }

    public function remove_ppp_secret($username)
    {
        if (trim((string) $username) === '') {
            return $this->fail('Username wajib diisi.');
        }

        $secret = $this->find_ppp_secret($username);
        if (!$secret['success']) {
            return $secret;
        }

        if (empty($secret['data']) || !isset($secret['data'][0]['.id'])) {
            return $this->ok("PPP secret '{$username}' tidak ditemukan (sudah tidak ada).");
        }

        $id = $secret['data'][0]['.id'];
        $result = $this->run('/ppp/secret/remove', array('.id' => $id));

        if (!$result['success']) {
            return $result;
        }

        return $this->ok("PPP secret '{$username}' berhasil dihapus.");
    }

    public function add_to_isolir_list($ip)
    {
        $ip = trim((string) $ip);
        if (!$this->is_valid_ip($ip)) {
            return $this->fail('IP tidak valid.');
        }

        $existing = $this->find_isolir_entries($ip);
        if (!$existing['success']) {
            return $existing;
        }

        if (!empty($existing['data'])) {
            return $this->ok("IP {$ip} sudah ada di list isolir.");
        }

        $result = $this->run('/ip/firewall/address-list/add', array(
            'list' => $this->isolir_address_list,
            'address' => $ip,
            'comment' => 'Added by RTRWNet',
        ));

        if (!$result['success']) {
            return $result;
        }

        return $this->ok("IP {$ip} berhasil ditambahkan ke list isolir.");
    }

    public function remove_from_isolir_list($ip)
    {
        $ip = trim((string) $ip);
        if (!$this->is_valid_ip($ip)) {
            return $this->fail('IP tidak valid.');
        }

        $entries = $this->find_isolir_entries($ip);
        if (!$entries['success']) {
            return $entries;
        }

        if (empty($entries['data'])) {
            return $this->ok("IP {$ip} tidak ditemukan di list isolir.");
        }

        foreach ($entries['data'] as $row) {
            if (!isset($row['.id'])) {
                continue;
            }

            $remove = $this->run('/ip/firewall/address-list/remove', array(
                '.id' => $row['.id'],
            ));
            if (!$remove['success']) {
                return $remove;
            }
        }

        return $this->ok("IP {$ip} berhasil dihapus dari list isolir.");
    }

    private function find_ppp_secret($username)
    {
        $result = $this->run('/ppp/secret/print', array('?name' => $username));
        if (!$result['success']) {
            return $result;
        }

        return array(
            'success' => true,
            'message' => 'OK',
            'data' => isset($result['data']) ? $result['data'] : array(),
        );
    }

    private function find_isolir_entries($ip)
    {
        $result = $this->run('/ip/firewall/address-list/print', array(
            '?list' => $this->isolir_address_list,
            '?address' => $ip,
        ));
        if (!$result['success']) {
            return $result;
        }

        return array(
            'success' => true,
            'message' => 'OK',
            'data' => isset($result['data']) ? $result['data'] : array(),
        );
    }

    private function run($command, array $params = array())
    {
        if (!$this->connect()) {
            return $this->fail('Tidak dapat terhubung ke MikroTik.');
        }

        try {
            $this->api->write($command, false);
            foreach ($params as $k => $v) {
                $this->api->write('=' . $k . '=' . $v, false);
            }
            $this->api->write('');

            $raw = $this->api->read();
            $parsed = $this->api->parseResponse($raw);

            if ($this->has_trap($parsed)) {
                $message = $this->extract_trap_message($parsed);
                return $this->fail("RouterOS trap pada {$command}: {$message}");
            }

            $rows = is_array($parsed) ? $parsed : array();
            return array(
                'success' => true,
                'message' => 'OK',
                'data' => $rows,
            );
        } catch (Exception $e) {
            return $this->fail('Exception RouterOS: ' . $e->getMessage());
        }
    }

    private function connect()
    {
        if ($this->connected) {
            return true;
        }

        if ($this->host === '' || $this->user === '' || $this->pass === '') {
            $this->log_error('Konfigurasi mikrotik_host/mikrotik_user/mikrotik_pass belum lengkap.');
            return false;
        }

        $ok = @$this->api->connect($this->host, $this->user, $this->pass);
        if (!$ok) {
            $this->log_error("Gagal koneksi ke MikroTik {$this->host}:{$this->port}.");
            return false;
        }

        $this->connected = true;
        return true;
    }

    private function disconnect()
    {
        if ($this->connected) {
            $this->api->disconnect();
            $this->connected = false;
        }
    }

    private function has_trap($parsed)
    {
        return is_array($parsed) && isset($parsed['!trap']) && !empty($parsed['!trap']);
    }

    private function extract_trap_message($parsed)
    {
        if (!is_array($parsed) || empty($parsed['!trap'][0])) {
            return 'Unknown error';
        }

        if (isset($parsed['!trap'][0]['message'])) {
            return $parsed['!trap'][0]['message'];
        }

        return 'Unknown error';
    }

    private function validate_secret_input($username, $password, $profile)
    {
        return trim((string) $username) !== ''
            && trim((string) $password) !== ''
            && trim((string) $profile) !== '';
    }

    private function is_valid_ip($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function ok($message)
    {
        return array('success' => true, 'message' => $message);
    }

    private function fail($message)
    {
        $this->log_error($message);
        return array('success' => false, 'message' => $message);
    }

    private function log_error($message)
    {
        log_message('error', '[Mikrotik_routeros] ' . $message);
    }
}
