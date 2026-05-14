<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class MikrotikCreateSecretJob
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->model('settings_model');
    }

    /**
     * @param array $payload
     * @param array $job
     * @return array
     */
    public function handle(array $payload, array $job = array())
    {
        $tenant_id = (int) ($payload['tenant_id'] ?? $job['tenant_id'] ?? 0);
        $router_id = (int) ($payload['router_id'] ?? 0);
        $username = trim((string) ($payload['username'] ?? ''));
        $password = trim((string) ($payload['password'] ?? ''));
        $profile = trim((string) ($payload['profile'] ?? ''));
        $remote_ip = trim((string) ($payload['remote_ip'] ?? ''));
        $service = trim((string) ($payload['service'] ?? 'pppoe'));
        $comment = trim((string) ($payload['comment'] ?? 'ASYNC-CREATE'));

        if ($username === '' || $password === '' || $profile === '') {
            return array(
                'success' => false,
                'message' => 'Payload tidak valid: username/password/profile wajib.',
                'retryable' => false,
            );
        }

        try {
            $this->CI->load->library('mikrotik_api');
            $router_cfg = $this->resolve_router_config($tenant_id, $router_id);
            if (!empty($router_cfg['success']) && !empty($router_cfg['config'])) {
                $this->CI->mikrotik_api->configure($router_cfg['config']);
            } elseif ($router_id > 0 && !empty($router_cfg['message'])) {
                return array(
                    'success' => false,
                    'message' => $router_cfg['message'],
                    'retryable' => false,
                );
            }

            $find = $this->CI->mikrotik_api->command_safe('/ppp/secret/print', array('?name' => $username));
            if (empty($find['success'])) {
                return array(
                    'success' => false,
                    'message' => 'Gagal query secret: ' . (string) ($find['error'] ?? 'unknown'),
                    'retryable' => true,
                );
            }

            $secret_row = $this->pick_secret_by_name((array) ($find['data'] ?? array()), $username);
            if (!empty($secret_row)) {
                $secret_id = trim((string) ($secret_row['.id'] ?? ''));
                $params = array(
                    'password' => $password,
                    'profile' => $profile,
                    'service' => $service !== '' ? $service : 'pppoe',
                    'disabled' => 'no',
                );
                if ($comment !== '') {
                    $params['comment'] = $comment;
                }
                if ($remote_ip !== '') {
                    $params['remote-address'] = $remote_ip;
                }
                if ($secret_id !== '') {
                    $params['.id'] = $secret_id;
                } else {
                    $params['numbers'] = $username;
                }

                $set = $this->CI->mikrotik_api->command_safe('/ppp/secret/set', $params);
                if (empty($set['success']) && isset($params['.id'])) {
                    unset($params['.id']);
                    $params['numbers'] = $username;
                    $set = $this->CI->mikrotik_api->command_safe('/ppp/secret/set', $params);
                }
                if (empty($set['success'])) {
                    return array(
                        'success' => false,
                        'message' => 'Gagal update secret `' . $username . '`: ' . (string) ($set['error'] ?? 'unknown'),
                        'retryable' => true,
                    );
                }

                return array(
                    'success' => true,
                    'message' => 'PPP secret `' . $username . '` sudah ada, profil/password di-update.',
                    'retryable' => false,
                );
            }

            $add_params = array(
                'name' => $username,
                'password' => $password,
                'profile' => $profile,
                'service' => $service !== '' ? $service : 'pppoe',
                'disabled' => 'no',
            );
            if ($comment !== '') {
                $add_params['comment'] = $comment;
            }
            if ($remote_ip !== '') {
                $add_params['remote-address'] = $remote_ip;
            }

            $add = $this->CI->mikrotik_api->command_safe('/ppp/secret/add', $add_params);
            if (empty($add['success'])) {
                // idempotency: jika add fail karena already exists, treat success.
                $error = strtolower((string) ($add['error'] ?? ''));
                if (strpos($error, 'already have') !== false || strpos($error, 'already exists') !== false) {
                    return array(
                        'success' => true,
                        'message' => 'PPP secret `' . $username . '` sudah ada (idempotent).',
                        'retryable' => false,
                    );
                }
                return array(
                    'success' => false,
                    'message' => 'Gagal create secret `' . $username . '`: ' . (string) ($add['error'] ?? 'unknown'),
                    'retryable' => true,
                );
            }

            return array(
                'success' => true,
                'message' => 'PPP secret `' . $username . '` berhasil dibuat.',
                'retryable' => false,
            );
        } catch (Throwable $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'retryable' => true,
            );
        } finally {
            if (isset($this->CI->mikrotik_api)) {
                $this->CI->mikrotik_api->disconnect();
            }
        }
    }

    private function resolve_router_config($tenant_id, $router_id)
    {
        $tenant_id = (int) $tenant_id;
        $router_id = (int) $router_id;
        if ($router_id <= 0) {
            return array('success' => false, 'message' => 'Router override tidak digunakan.', 'config' => null);
        }
        if (!$this->CI->db->table_exists('routers')) {
            return array('success' => false, 'message' => 'Tabel routers tidak tersedia.', 'config' => null);
        }

        $fields = $this->CI->db->list_fields('routers');
        $qb = $this->CI->db
            ->from('routers')
            ->where('id', $router_id);

        if (in_array('tenant_id', $fields, true) && $tenant_id > 0) {
            $qb->where('tenant_id', $tenant_id);
        }
        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        $router = $qb->limit(1)->get()->row_array();

        if (empty($router)) {
            return array('success' => false, 'message' => 'Router tidak ditemukan.', 'config' => null);
        }

        $password = '';
        $password_raw = trim((string) ($router['password'] ?? $router['api_password_enc'] ?? ''));
        if ($password_raw !== '') {
            $decrypted = '';
            if (isset($this->CI->settings_model) && method_exists($this->CI->settings_model, 'decrypt_secret')) {
                $decrypted = (string) $this->CI->settings_model->decrypt_secret($password_raw);
            }

            if (!is_string($decrypted) || trim($decrypted) === '') {
                $this->CI->load->library('encryption');
                $decrypted = $this->CI->encryption->decrypt($password_raw);
            }

            if (is_string($decrypted) && trim($decrypted) !== '') {
                $password = trim($decrypted);
            } elseif (strpos($password_raw, 'osl:') !== 0 && strpos($password_raw, 'ci3:') !== 0) {
                $password = $password_raw;
            }
        }

        if ($password === '') {
            return array('success' => false, 'message' => 'Password API router kosong.', 'config' => null);
        }

        return array(
            'success' => true,
            'message' => 'OK',
            'config' => array(
                'host' => (string) ($router['ip_address'] ?? $router['api_host'] ?? ''),
                'username' => (string) ($router['username'] ?? $router['api_username'] ?? ''),
                'password' => $password,
                'api_port' => (int) ($router['api_port'] ?? 8728),
                'use_ssl' => !empty($router['use_ssl']),
                'timeout' => (int) ($router['timeout_seconds'] ?? 5),
            ),
        );
    }

    private function pick_secret_by_name(array $rows, $username)
    {
        $username = trim((string) $username);
        if ($username === '') {
            return array();
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['name'] ?? '')) === $username) {
                return $row;
            }
        }

        return array();
    }
}
