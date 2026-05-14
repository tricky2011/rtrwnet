<?php
/**
 * application/libraries/Mikrotik_api.php
 *
 * Low-level wrapper untuk komunikasi dengan MikroTik RouterOS API.
 * Library ini HANYA handle koneksi, kirim command, dan terima response.
 * Business logic ada di Pppoe_manager dan Isolir_engine.
 *
 * Fitur:
 * - Auto-reconnect jika koneksi putus
 * - Retry mechanism dengan exponential backoff
 * - Logging setiap command dan response
 * - Timeout handling
 * - Credential encryption
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Mikrotik_api
{
    private $CI;
    private $api;           // Instance RouterosAPI
    private $connected;
    private $host;
    private $port;
    private $user;
    private $pass;
    private $ssl;
    private $timeout;
    private $retry_max;
    private $retry_delay;
    private $debug;
    private $log_file;

    // ─── CONSTRUCTOR ────────────────────────────────────────

    public function __construct()
    {
        $ci = get_instance();
        $this->CI = $ci;
        $this->CI->config->load('mikrotik', TRUE);
        $this->CI->load->model('setting_model');

        // Load config
        $mk = $this->CI->config->item('mikrotik');
        $mk = is_array($mk) ? $mk : array();

        $this->host        = isset($mk['mikrotik_host']) ? $mk['mikrotik_host'] : '';
        $this->port        = isset($mk['mikrotik_port']) ? (int) $mk['mikrotik_port'] : 8728;
        $this->user        = isset($mk['mikrotik_user']) ? $mk['mikrotik_user'] : '';
        $this->pass        = isset($mk['mikrotik_pass']) ? $mk['mikrotik_pass'] : '';
        $this->ssl         = !empty($mk['mikrotik_ssl']);
        $this->timeout     = isset($mk['mikrotik_timeout']) ? (int) $mk['mikrotik_timeout'] : 5;
        $this->retry_max   = isset($mk['mikrotik_retry_max']) ? (int) $mk['mikrotik_retry_max'] : 3;
        $this->retry_delay = isset($mk['mikrotik_retry_delay']) ? (int) $mk['mikrotik_retry_delay'] : 1;
        $this->debug       = !empty($mk['mikrotik_debug']);
        $this->log_file    = isset($mk['mikrotik_log_file']) ? $mk['mikrotik_log_file'] : 'api_mikrotik.log';
        $this->connected   = false;

        // Override dari settings GUI jika tersedia.
        $db_host = (string) $this->CI->setting_model->get_value('mikrotik_ip', '');
        $db_user = (string) $this->CI->setting_model->get_value('mikrotik_user', '');
        $db_pass = (string) $this->CI->setting_model->get_value('mikrotik_pass', '');
        $db_port = (int) $this->CI->setting_model->get_value('mikrotik_port', 0);
        $db_ssl  = $this->CI->setting_model->get_value('mikrotik_ssl', null);

        if ($db_host !== '') {
            $this->host = $db_host;
        }
        if ($db_user !== '') {
            $this->user = $db_user;
        }
        if ($db_pass !== '') {
            $this->pass = $db_pass;
        }
        if ($db_port > 0) {
            $this->port = $db_port;
        }
        if ($db_ssl !== null) {
            $this->ssl = (bool) $db_ssl;
        }

        // Load third-party library
        require_once APPPATH . 'third_party/RouterOS/routeros_api.class.php';
        $this->api = new RouterosAPI();
        $this->api->port = $this->port;
        $this->api->ssl = $this->ssl;
        $this->api->timeout = $this->timeout;
        // Hindari retry berlapis: retry dikelola oleh wrapper ini.
        $this->api->attempts = 1;
        $this->api->delay = 0;
        $this->api->debug   = $this->debug;
    }

    // ─── CONNECTION ─────────────────────────────────────────

    /**
     * Buka koneksi ke MikroTik
     * Bisa dipanggil berkali-kali — skip jika sudah connected
     *
     * @return $this
     * @throws Exception Jika gagal connect setelah retry
     */
    public function connect()
    {
        if ($this->connected) {
            return $this;
        }

        if ($this->host === '' || $this->user === '' || $this->pass === '') {
            throw new Exception('Konfigurasi MikroTik belum lengkap.');
        }

        $last_error = '';

        for ($attempt = 1; $attempt <= $this->retry_max; $attempt++) {
            $this->log("CONNECT attempt {$attempt}/{$this->retry_max} " .
                       "→ {$this->host}:{$this->port}");

            try {
                $this->api->port = $this->port;
                $this->api->ssl = $this->ssl;
                $result = @$this->api->connect($this->host, $this->user, $this->pass);

                if ($result) {
                    $this->connected = true;
                    $this->log("CONNECT OK → {$this->host}:{$this->port}");
                    return $this;
                }

                $last_error = "Authentication failed atau port tidak merespon";
                $this->log("CONNECT FAILED attempt {$attempt}: {$last_error}");

            } catch (Exception $e) {
                $last_error = $e->getMessage();
                $this->log("CONNECT ERROR attempt {$attempt}: {$last_error}");
            }

            // Exponential backoff: 1s, 2s, 4s
            if ($attempt < $this->retry_max) {
                $delay = $this->retry_delay * pow(2, $attempt - 1);
                $this->log("RETRY in {$delay}s...");
                sleep($delay);
            }
        }

        // Semua retry gagal
        $error_msg = "Gagal konek ke MikroTik {$this->host}:{$this->port} " .
                     "setelah {$this->retry_max} percobaan. Error: {$last_error}";
        $this->log("CONNECT ABORT: {$error_msg}");
        throw new Exception($error_msg);
    }

    /**
     * Cek apakah sedang terkoneksi
     */
    public function is_connected()
    {
        return $this->connected;
    }

    /**
     * Putus koneksi
     */
    public function disconnect()
    {
        if ($this->connected) {
            $this->api->disconnect();
            $this->connected = false;
            $this->log("DISCONNECT OK");
        }
    }

    // ─── COMMAND EXECUTION ──────────────────────────────────

    /**
     * Kirim command ke MikroTik dan terima response
     *
     * Contoh penggunaan:
     *   $this->command('/ppp/secret/print', ['?name' => 'cust001']);
     *   $this->command('/ppp/secret/add', [
     *       'name'     => 'cust001',
     *       'password' => '150126',
     *       'profile'  => 'pppoe-10m',
     *       'service'  => 'pppoe',
     *   ]);
     *
     * @param  string $command  RouterOS command path
     * @param  array  $params   Parameter key-value
     * @return array            Response dari MikroTik
     * @throws Exception        Jika command gagal atau return error
     */
    public function command($command, array $params = [])
    {
        $this->connect();

        // Log command
        $param_log = $this->sanitize_params_for_log($params);
        $this->log("CMD {$command} " . json_encode($param_log));

        // Kirim command dengan sentence terminator yang benar.
        // NOTE:
        // Pada routeros_api.class.php ini, write('') TIDAK mengirim terminator.
        // Terminator hanya terkirim jika param2=true pada write().
        $words = array();
        foreach ($params as $key => $value) {
            $word = $this->build_api_word($key, $value);
            if ($word !== '') {
                $words[] = $word;
            }
        }

        $this->api->write($command, empty($words));
        $last_idx = count($words) - 1;
        foreach ($words as $idx => $word) {
            $this->api->write($word, $idx === $last_idx);
        }

        // Baca response RAW lalu parse sekali.
        // Penting: jangan read() default (parsed) lalu parseResponse() lagi,
        // karena akan menyebabkan response kosong/tidak valid.
        $raw_response = $this->api->read(false);
        $response = $this->api->parseResponse($raw_response);

        // Log response
        $this->log("RES {$command}: " . json_encode($response));

        // Cek apakah ada error dari MikroTik
        if ($this->has_error($response)) {
            $error_msg = $this->extract_error($response);
            $this->log("ERR {$command}: {$error_msg}");
            throw new Exception("MikroTik error pada {$command}: {$error_msg}");
        }

        return $response;
    }

    /**
     * Build API word dengan format yang kompatibel RouterOS:
     * - normal param: =name=value
     * - query filter: ?name=value
     * - internal id : =.id=*1
     * - raw word key (numeric array): gunakan value apa adanya
     */
    private function build_api_word($key, $value)
    {
        if (is_int($key)) {
            $raw = trim((string) $value);
            return $raw;
        }

        $key = trim((string) $key);
        $value = (string) $value;
        if ($key === '') {
            return '';
        }

        // Query filter RouterOS (contoh: ?name=user1)
        if (strpos($key, '?') === 0) {
            return $key . '=' . $value;
        }

        // Sudah berupa assignment prefix (contoh: =.proplist)
        if (strpos($key, '=') === 0) {
            return $key . '=' . $value;
        }

        // Default assignment RouterOS
        return '=' . $key . '=' . $value;
    }

    public function configure(array $settings)
    {
        if (isset($settings['host'])) {
            $host = trim((string) $settings['host']);
            if ($host !== '') {
                $this->host = $host;
            }
        }
        if (isset($settings['username'])) {
            $username = trim((string) $settings['username']);
            if ($username !== '') {
                $this->user = $username;
            }
        }
        if (isset($settings['password'])) {
            $password = (string) $settings['password'];
            if ($password !== '') {
                $this->pass = $password;
            }
        }
        if (isset($settings['api_port'])) {
            $api_port = (int) $settings['api_port'];
            if ($api_port > 0) {
                $this->port = $api_port;
            }
        }
        if (isset($settings['use_ssl'])) {
            $this->ssl = !empty($settings['use_ssl']);
        }
        if (isset($settings['timeout'])) {
            $this->timeout = max(1, (int) $settings['timeout']);
            $this->api->timeout = $this->timeout;
        }
        if (isset($settings['retry_max'])) {
            $this->retry_max = max(1, (int) $settings['retry_max']);
        }
        if (isset($settings['retry_delay'])) {
            $this->retry_delay = max(0, (int) $settings['retry_delay']);
        }

        $this->connected = false;
        return $this;
    }

    public function test_connection(array $settings = [])
    {
        try {
            if (!isset($settings['timeout'])) {
                $settings['timeout'] = 4;
            }
            if (!isset($settings['retry_max'])) {
                $settings['retry_max'] = 1;
            }
            if (!isset($settings['retry_delay'])) {
                $settings['retry_delay'] = 0;
            }
            if (!empty($settings)) {
                $this->configure($settings);
            }

            $this->connect();
            $identity = $this->command('/system/identity/print');
            $name = '-';
            if (!empty($identity[0]['name'])) {
                $name = $identity[0]['name'];
            }

            $this->disconnect();
            return array(
                'success' => true,
                'message' => 'Koneksi MikroTik berhasil. Identity: ' . $name,
            );
        } catch (Exception $e) {
            $this->disconnect();
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        }
    }

    public function get_ppp_secrets(array $settings = [])
    {
        try {
            if (!empty($settings)) {
                $this->configure($settings);
            }

            $rows = $this->command('/ppp/secret/print');
            return array(
                'success' => true,
                'message' => 'OK',
                'data' => is_array($rows) ? $rows : array(),
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'data' => array(),
            );
        } finally {
            $this->disconnect();
        }
    }

    public function get_ppp_secrets_with_fallback(array $settings = [])
    {
        $debug_rows = array();
        $last_error = '';
        $read_error = false;

        try {
            if (!empty($settings)) {
                $this->configure($settings);
            }

            $this->connect();

            // Validasi API: identity wajib bisa dibaca dulu.
            $identity = $this->execute_v6_primary_parsed('/system/identity/print');
            $debug_rows[] = $this->format_attempt_log(
                'identity',
                '/system/identity/print',
                $identity['raw_preview'],
                $identity['parsed_preview'],
                count($identity['rows'])
            );
            log_message('debug', '[PPPOE_SYNC] ' . end($debug_rows));

            if (!empty($identity['error'])) {
                $last_error = (string) $identity['error'];
                log_message('error', '[PPPOE_SYNC] Identity check gagal: ' . $last_error);
                return array(
                    'success' => false,
                    'message' => 'API tidak valid.',
                    'data' => array(),
                    'debug' => $debug_rows,
                    'error' => $last_error,
                    'error_type' => 'api_invalid',
                );
            }

            // Primary method kompatibel RouterOS v6.
            $primary = $this->execute_v6_primary_parsed('/ppp/secret/print');
            $debug_rows[] = $this->format_attempt_log(
                'primary',
                '/ppp/secret/print',
                $primary['raw_preview'],
                $primary['parsed_preview'],
                count($primary['rows'])
            );
            log_message('debug', '[PPPOE_SYNC] ' . end($debug_rows));

            if (!empty($primary['rows'])) {
                return array(
                    'success' => true,
                    'message' => 'PPPoE secret berhasil diambil dengan command /ppp/secret/print.',
                    'data' => $primary['rows'],
                    'debug' => $debug_rows,
                    'error' => null,
                    'error_type' => null,
                );
            }
            if (!empty($primary['error'])) {
                $last_error = (string) $primary['error'];
                $read_error = true;
            }

            // Fallback A:
            // /ppp/secret/print + .proplist
            $fallback_a = $this->execute_raw_command(
                '/ppp/secret/print',
                array('=.proplist=name,password,profile,service,disabled'),
                true
            );
            $debug_rows[] = $this->format_attempt_log(
                'fallback_a',
                '/ppp/secret/print + .proplist',
                $fallback_a['raw_preview'],
                $fallback_a['parsed_preview'],
                count($fallback_a['rows'])
            );
            log_message('debug', '[PPPOE_SYNC] ' . end($debug_rows));

            if (!empty($fallback_a['rows'])) {
                return array(
                    'success' => true,
                    'message' => 'PPPoE secret berhasil diambil dengan fallback .proplist.',
                    'data' => $fallback_a['rows'],
                    'debug' => $debug_rows,
                    'error' => null,
                    'error_type' => null,
                );
            }
            if (!empty($fallback_a['error'])) {
                $last_error = (string) $fallback_a['error'];
                $read_error = true;
                log_message('error', '[PPPOE_SYNC] Fallback .proplist gagal: ' . $last_error);
            }

            // Fallback B:
            // /ppp/secret/print + without-paging
            $fallback_b = $this->execute_raw_command(
                '/ppp/secret/print',
                array('=without-paging='),
                true
            );
            $debug_rows[] = $this->format_attempt_log(
                'fallback_b',
                '/ppp/secret/print without-paging',
                $fallback_b['raw_preview'],
                $fallback_b['parsed_preview'],
                count($fallback_b['rows'])
            );
            log_message('debug', '[PPPOE_SYNC] ' . end($debug_rows));

            if (!empty($fallback_b['rows'])) {
                return array(
                    'success' => true,
                    'message' => 'PPPoE secret berhasil diambil dengan fallback without-paging.',
                    'data' => $fallback_b['rows'],
                    'debug' => $debug_rows,
                    'error' => null,
                    'error_type' => null,
                );
            }
            if (!empty($fallback_b['error'])) {
                $last_error = (string) $fallback_b['error'];
                $read_error = true;
                log_message('error', '[PPPOE_SYNC] Fallback without-paging gagal: ' . $last_error);
            }

            $message = $read_error
                ? 'API tidak bisa membaca PPP secret.'
                : 'API terkoneksi tapi data kosong.';
            if ($last_error !== '') {
                $message .= ' Detail: ' . $last_error;
            }

            return array(
                'success' => false,
                'message' => $message,
                'data' => array(),
                'debug' => $debug_rows,
                'error' => $last_error,
                'error_type' => $read_error ? 'ppp_read_failed' : 'ppp_data_empty',
            );
        } catch (Exception $e) {
            $message = 'Koneksi/API MikroTik gagal: ' . $e->getMessage();
            $this->log('ERR get_ppp_secrets_with_fallback: ' . $message);
            log_message('error', '[PPPOE_SYNC] ' . $message);

            return array(
                'success' => false,
                'message' => $message,
                'data' => array(),
                'debug' => $debug_rows,
                'error' => $e->getMessage(),
                'error_type' => 'api_connect_failed',
            );
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Kirim command tanpa throw exception jika gagal
     * Berguna untuk operasi yang boleh gagal (contoh: remove entry yang mungkin sudah dihapus)
     *
     * @return array ['success' => bool, 'data' => array, 'error' => string|null]
     */
    public function command_safe($command, array $params = [])
    {
        try {
            $data = $this->command($command, $params);
            return ['success' => true, 'data' => $data, 'error' => null];
        } catch (Exception $e) {
            return ['success' => false, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    public function set_ppp_secret_profile($username, $profile)
    {
        $username = trim((string) $username);
        $profile = trim((string) $profile);
        if ($username === '' || $profile === '') {
            return array(
                'success' => false,
                'message' => 'username dan profile wajib diisi.',
            );
        }

        try {
            $this->connect();

            $find = $this->command('/ppp/secret/print', array('?name' => $username));
            if (empty($find) || !is_array($find) || empty($find[0]['.id'])) {
                return array(
                    'success' => false,
                    'message' => 'PPPoE secret `' . $username . '` tidak ditemukan.',
                );
            }

            $secret_id = (string) $find[0]['.id'];
            $set = $this->command_safe('/ppp/secret/set', array(
                '.id' => $secret_id,
                'profile' => $profile,
            ));

            if (empty($set['success'])) {
                return array(
                    'success' => false,
                    'message' => 'Gagal set profile secret: ' . (string) ($set['error'] ?? 'unknown error'),
                );
            }

            return array(
                'success' => true,
                'message' => 'Profile secret `' . $username . '` berhasil diubah ke `' . $profile . '`.',
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        } finally {
            $this->disconnect();
        }
    }

    public function add_ppp_profile(array $payload)
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $local_address = trim((string) ($payload['local_address'] ?? ''));
        $remote_pool = trim((string) ($payload['remote_address_pool'] ?? ''));
        $rate_limit = trim((string) ($payload['rate_limit'] ?? ''));

        if ($name === '' || $local_address === '' || $remote_pool === '') {
            return array(
                'success' => false,
                'message' => 'name, local_address, dan remote_address_pool wajib diisi.',
            );
        }

        try {
            $this->connect();
            $result = $this->command_safe('/ppp/profile/add', array(
                'name' => $name,
                'local-address' => $local_address,
                'remote-address' => $remote_pool,
                'rate-limit' => $rate_limit,
            ));

            if (empty($result['success'])) {
                return array(
                    'success' => false,
                    'message' => 'Gagal add PPP profile di MikroTik: ' . (string) ($result['error'] ?? 'unknown'),
                );
            }

            return array(
                'success' => true,
                'message' => 'PPP profile berhasil dibuat di MikroTik.',
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        } finally {
            $this->disconnect();
        }
    }

    public function update_ppp_profile($current_name, array $payload)
    {
        $current_name = trim((string) $current_name);
        $name = trim((string) ($payload['name'] ?? ''));
        $local_address = trim((string) ($payload['local_address'] ?? ''));
        $remote_pool = trim((string) ($payload['remote_address_pool'] ?? ''));
        $rate_limit = trim((string) ($payload['rate_limit'] ?? ''));

        if ($current_name === '' || $name === '' || $local_address === '' || $remote_pool === '') {
            return array(
                'success' => false,
                'message' => 'Nama profile lama/baru, local_address, dan remote_address_pool wajib diisi.',
            );
        }

        try {
            $this->connect();
            $found = $this->command('/ppp/profile/print', array('?name' => $current_name));
            if (empty($found) || empty($found[0]['.id'])) {
                return array(
                    'success' => false,
                    'message' => 'PPP profile `' . $current_name . '` tidak ditemukan di MikroTik.',
                );
            }

            $profile_id = (string) $found[0]['.id'];
            $result = $this->command_safe('/ppp/profile/set', array(
                '.id' => $profile_id,
                'name' => $name,
                'local-address' => $local_address,
                'remote-address' => $remote_pool,
                'rate-limit' => $rate_limit,
            ));

            if (empty($result['success'])) {
                return array(
                    'success' => false,
                    'message' => 'Gagal update PPP profile di MikroTik: ' . (string) ($result['error'] ?? 'unknown'),
                );
            }

            return array(
                'success' => true,
                'message' => 'PPP profile berhasil diupdate di MikroTik.',
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        } finally {
            $this->disconnect();
        }
    }

    public function remove_ppp_profile($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return array(
                'success' => false,
                'message' => 'Nama PPP profile wajib diisi.',
            );
        }

        try {
            $this->connect();
            $found = $this->command('/ppp/profile/print', array('?name' => $name));
            if (empty($found) || empty($found[0]['.id'])) {
                return array(
                    'success' => false,
                    'message' => 'PPP profile `' . $name . '` tidak ditemukan di MikroTik.',
                );
            }

            $profile_id = (string) $found[0]['.id'];
            $result = $this->command_safe('/ppp/profile/remove', array(
                '.id' => $profile_id,
            ));

            if (empty($result['success'])) {
                return array(
                    'success' => false,
                    'message' => 'Gagal hapus PPP profile di MikroTik: ' . (string) ($result['error'] ?? 'unknown'),
                );
            }

            return array(
                'success' => true,
                'message' => 'PPP profile berhasil dihapus dari MikroTik.',
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        } finally {
            $this->disconnect();
        }
    }

    public function add_ip_pool($pool_name, $range_start, $range_end)
    {
        $pool_name = trim((string) $pool_name);
        $range_start = trim((string) $range_start);
        $range_end = trim((string) $range_end);
        if ($pool_name === '' || $range_start === '' || $range_end === '') {
            return array(
                'success' => false,
                'message' => 'pool_name, range_start, dan range_end wajib diisi.',
            );
        }

        try {
            $this->connect();
            $ranges = $range_start . '-' . $range_end;
            $result = $this->command_safe('/ip/pool/add', array(
                'name' => $pool_name,
                'ranges' => $ranges,
            ));

            if (empty($result['success'])) {
                return array(
                    'success' => false,
                    'message' => 'Gagal add IP pool di MikroTik: ' . (string) ($result['error'] ?? 'unknown'),
                );
            }

            return array(
                'success' => true,
                'message' => 'IP pool berhasil dibuat di MikroTik.',
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        } finally {
            $this->disconnect();
        }
    }

    public function update_ip_pool($current_name, $new_name, $range_start, $range_end)
    {
        $current_name = trim((string) $current_name);
        $new_name = trim((string) $new_name);
        $range_start = trim((string) $range_start);
        $range_end = trim((string) $range_end);
        if ($current_name === '' || $new_name === '' || $range_start === '' || $range_end === '') {
            return array(
                'success' => false,
                'message' => 'Nama pool lama/baru dan range wajib diisi.',
            );
        }

        try {
            $this->connect();
            $found = $this->command('/ip/pool/print', array('?name' => $current_name));
            if (empty($found) || empty($found[0]['.id'])) {
                return array(
                    'success' => false,
                    'message' => 'IP pool `' . $current_name . '` tidak ditemukan di MikroTik.',
                );
            }

            $pool_id = (string) $found[0]['.id'];
            $ranges = $range_start . '-' . $range_end;
            $result = $this->command_safe('/ip/pool/set', array(
                '.id' => $pool_id,
                'name' => $new_name,
                'ranges' => $ranges,
            ));

            if (empty($result['success'])) {
                return array(
                    'success' => false,
                    'message' => 'Gagal update IP pool di MikroTik: ' . (string) ($result['error'] ?? 'unknown'),
                );
            }

            return array(
                'success' => true,
                'message' => 'IP pool berhasil diupdate di MikroTik.',
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        } finally {
            $this->disconnect();
        }
    }

    public function remove_ip_pool($pool_name)
    {
        $pool_name = trim((string) $pool_name);
        if ($pool_name === '') {
            return array(
                'success' => false,
                'message' => 'Nama IP pool wajib diisi.',
            );
        }

        try {
            $this->connect();
            $found = $this->command('/ip/pool/print', array('?name' => $pool_name));
            if (empty($found) || empty($found[0]['.id'])) {
                return array(
                    'success' => false,
                    'message' => 'IP pool `' . $pool_name . '` tidak ditemukan di MikroTik.',
                );
            }

            $pool_id = (string) $found[0]['.id'];
            $result = $this->command_safe('/ip/pool/remove', array(
                '.id' => $pool_id,
            ));

            if (empty($result['success'])) {
                return array(
                    'success' => false,
                    'message' => 'Gagal hapus IP pool di MikroTik: ' . (string) ($result['error'] ?? 'unknown'),
                );
            }

            return array(
                'success' => true,
                'message' => 'IP pool berhasil dihapus dari MikroTik.',
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        } finally {
            $this->disconnect();
        }
    }

    // ─── HELPER: ERROR DETECTION ────────────────────────────

    /**
     * Cek apakah response mengandung error
     */
    private function has_error($response)
    {
        if (!is_array($response)) {
            // RouterOS bisa return scalar "ret" pada command tertentu (mis. add).
            // Itu bukan error.
            return false;
        }

        if (isset($response['!trap']) && !empty($response['!trap'])) {
            return true;
        }

        if (isset($response['!fatal']) && !empty($response['!fatal'])) {
            return true;
        }

        return false;
    }

    /**
     * Ekstrak pesan error dari response
     */
    private function extract_error($response)
    {
        if (isset($response['!trap'][0]['message'])) {
            return $response['!trap'][0]['message'];
        }

        if (isset($response['!fatal'][0]['message'])) {
            return $response['!fatal'][0]['message'];
        }

        return 'Unknown MikroTik error: ' . json_encode($response);
    }

    private function execute_v6_primary_parsed($command)
    {
        // Pola kompatibel RouterOS v6:
        // $API->write('/ppp/secret/print');
        // $response = $API->read();
        // $parsed = $response;
        $this->api->write($command);
        $response = $this->api->read();
        $parsed = $response;

        $parsed_preview = $this->sanitize_parsed_preview($parsed);
        $this->log('PARSED ' . $command . ': ' . $parsed_preview);
        log_message('debug', '[PPPOE_SYNC] PARSED ' . $command . ': ' . $parsed_preview);

        if ($this->has_error($parsed)) {
            return array(
                'raw' => array(),
                'rows' => array(),
                'error' => $this->extract_error($parsed),
                'raw_preview' => '[mode read() parsed; raw unavailable]',
                'parsed_preview' => $parsed_preview,
            );
        }

        return array(
            'raw' => array(),
            'rows' => $this->extract_data_rows($parsed),
            'error' => null,
            'raw_preview' => '[mode read() parsed; raw unavailable]',
            'parsed_preview' => $parsed_preview,
        );
    }

    private function execute_raw_command($command, array $arguments = array(), $close_on_last_argument = false)
    {
        if ($close_on_last_argument && !empty($arguments)) {
            $this->api->write($command, false);
            $last_idx = count($arguments) - 1;
            foreach ($arguments as $idx => $argument) {
                $this->api->write($argument, $idx === $last_idx);
            }
        } else {
            $words = array();
            foreach ($arguments as $argument) {
                $argument = trim((string) $argument);
                if ($argument !== '') {
                    $words[] = $argument;
                }
            }

            $this->api->write($command, empty($words));
            $last_idx = count($words) - 1;
            foreach ($words as $idx => $word) {
                $this->api->write($word, $idx === $last_idx);
            }
        }

        // Debug raw response sebelum parse.
        $raw_response = $this->api->read(false);
        $parsed = $this->api->parseResponse($raw_response);

        $raw_preview = $this->sanitize_raw_preview($raw_response);
        $parsed_preview = $this->sanitize_parsed_preview($parsed);
        $this->log('RAW ' . $command . ': ' . $raw_preview);
        $this->log('PARSED ' . $command . ': ' . $parsed_preview);
        log_message('debug', '[PPPOE_SYNC] RAW ' . $command . ': ' . $raw_preview);
        log_message('debug', '[PPPOE_SYNC] PARSED ' . $command . ': ' . $parsed_preview);

        if ($this->has_error($parsed)) {
            return array(
                'raw' => is_array($raw_response) ? $raw_response : array(),
                'rows' => array(),
                'error' => $this->extract_error($parsed),
                'raw_preview' => $raw_preview,
                'parsed_preview' => $parsed_preview,
            );
        }

        return array(
            'raw' => is_array($raw_response) ? $raw_response : array(),
            'rows' => $this->extract_data_rows($parsed),
            'error' => null,
            'raw_preview' => $raw_preview,
            'parsed_preview' => $parsed_preview,
        );
    }

    private function extract_data_rows($parsed)
    {
        if (!is_array($parsed) || empty($parsed)) {
            return array();
        }

        if (isset($parsed['!trap']) || isset($parsed['!fatal'])) {
            return array();
        }

        $rows = array();
        foreach ($parsed as $key => $row) {
            if (is_int($key) && is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    // ─── HELPER: LOGGING ────────────────────────────────────

    /**
     * Log ke file khusus MikroTik
     */
    private function log($message)
    {
        $path = APPPATH . 'logs/' . $this->log_file;
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Sanitize parameter untuk log — sembunyikan password
     */
    private function sanitize_params_for_log($params)
    {
        $safe = $params;
        $sensitive_keys = ['password', 'pass', 'secret'];

        foreach ($sensitive_keys as $key) {
            if (isset($safe[$key])) {
                $safe[$key] = '********';
            }
        }

        return $safe;
    }

    private function sanitize_raw_preview($raw_response)
    {
        if (!is_array($raw_response)) {
            return '[non-array response]';
        }

        $sanitized = array();
        foreach ($raw_response as $line) {
            $line = (string) $line;
            $line = preg_replace('/(=password=)(.*)$/i', '$1********', $line);
            $sanitized[] = $line;
        }

        if (empty($sanitized)) {
            return '[]';
        }

        $preview = json_encode(array_slice($sanitized, 0, 8));
        if (count($sanitized) > 8) {
            $preview .= ' ...(' . count($sanitized) . ' lines)';
        }

        return $preview;
    }

    private function sanitize_parsed_preview($parsed_response)
    {
        $data = $this->mask_sensitive_in_array($parsed_response);
        if (!is_array($data)) {
            return (string) $data;
        }

        $preview = json_encode(array_slice($data, 0, 8, true));
        if (count($data) > 8) {
            $preview .= ' ...(' . count($data) . ' entries)';
        }

        return $preview;
    }

    private function mask_sensitive_in_array($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $masked = array();
        foreach ($value as $key => $item) {
            $key_string = strtolower((string) $key);
            if ($key_string === 'password' || $key_string === 'pass' || $key_string === 'secret') {
                $masked[$key] = '********';
                continue;
            }
            $masked[$key] = $this->mask_sensitive_in_array($item);
        }

        return $masked;
    }

    private function format_attempt_log($label, $command, $raw_preview, $parsed_preview, $found_rows)
    {
        return sprintf(
            '%s %s | raw=%s | parsed=%s | found=%d',
            $label,
            $command,
            $raw_preview,
            $parsed_preview,
            (int) $found_rows
        );
    }

    // ─── DESTRUCTOR ─────────────────────────────────────────

    public function __destruct()
    {
        $this->disconnect();
    }
}
