<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Router-scoped MikroTik manager.
 *
 * Semua operasi wajib melalui router_id agar command hanya dieksekusi
 * ke router customer yang benar.
 */
class MikrotikManager
{
    /** @var CI_Controller */
    protected $CI;

    /** @var object|null RouterOS API object dari helper connectRouter() */
    protected $api = null;

    /** @var int */
    protected $active_router_id = 0;

    /** @var array */
    protected $active_router = array();

    /** @var string */
    protected $isolir_list = 'ISOLIR';

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->helper(array('tenant'));

        $list = trim((string) getenv('MIKROTIK_ISOLIR_LIST'));
        if ($list === '') {
            $cfg = (array) $this->CI->config->item('mikrotik');
            $list = trim((string) ($cfg['isolir_address_list'] ?? ''));
        }
        if ($list !== '') {
            $this->isolir_list = $list;
        }
    }

    /**
     * Connect ke router berdasarkan router_id.
     *
     * @param int $router_id
     * @return array
     */
    public function connectByRouterId($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0) {
            return $this->fail('router_id tidak valid.');
        }

        $this->disconnect();
        $connect = function_exists('connectRouter') ? connectRouter($router_id) : array(
            'success' => false,
            'message' => 'Helper connectRouter() tidak tersedia.',
        );

        if (empty($connect['success']) || empty($connect['api'])) {
            return $this->fail((string) ($connect['message'] ?? 'Koneksi router gagal.'));
        }

        $this->api = $connect['api'];
        $this->active_router_id = $router_id;
        $this->active_router = (array) ($connect['router'] ?? array());

        return $this->ok('Router connected.', array(
            'router_id' => $this->active_router_id,
            'router' => $this->active_router,
        ));
    }

    /**
     * Disconnect aktif.
     */
    public function disconnect()
    {
        if (is_object($this->api) && method_exists($this->api, 'disconnect')) {
            try {
                $this->api->disconnect();
            } catch (Throwable $e) {
                log_message('error', '[MikrotikManager][disconnect] ' . $e->getMessage());
            }
        }

        $this->api = null;
        $this->active_router_id = 0;
        $this->active_router = array();
    }

    /**
     * Execute command ke router yang sudah connected.
     *
     * @param string $command
     * @param array $params
     * @return array
     */
    public function command($command, array $params = array())
    {
        if (!is_object($this->api) || !method_exists($this->api, 'comm')) {
            return $this->fail('Router belum terhubung.');
        }

        try {
            $response = $this->api->comm((string) $command, $params);
            if ($this->has_router_error($response)) {
                return $this->fail($this->extract_router_error($response), array(
                    'router_id' => $this->active_router_id,
                    'command' => $command,
                    'data' => $response,
                ));
            }

            return array(
                'success' => true,
                'message' => 'Command success.',
                'router_id' => $this->active_router_id,
                'command' => (string) $command,
                'data' => is_array($response) ? $response : array(),
            );
        } catch (Throwable $e) {
            return $this->fail($e->getMessage(), array(
                'router_id' => $this->active_router_id,
                'command' => $command,
            ));
        }
    }

    /**
     * Shortcut: connect + command + disconnect.
     *
     * @param int $router_id
     * @param string $command
     * @param array $params
     * @return array
     */
    public function runOnRouter($router_id, $command, array $params = array())
    {
        $connect = $this->connectByRouterId((int) $router_id);
        if (empty($connect['success'])) {
            return $connect;
        }

        try {
            return $this->command($command, $params);
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Cari PPP secret by username pada router tertentu.
     *
     * @param int $router_id
     * @param string $username
     * @return array
     */
    public function findPppSecretByRouterId($router_id, $username)
    {
        $username = trim((string) $username);
        if ($username === '') {
            return $this->fail('Username PPP kosong.');
        }

        $router_id = (int) $router_id;
        $connect = $this->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return $connect;
        }

        try {
            $searched = array();

            $find = $this->command('/ppp/secret/print', array(
                '?name' => $username,
                '.proplist' => '.id,name,profile,remote-address,disabled',
            ));
            $searched[] = '/ppp/secret/print ?name=' . $username;
            if (!empty($find['success'])) {
                $secret = $this->pickSecretByUsername((array) ($find['data'] ?? array()), $username);
                if (!empty($secret)) {
                    return array(
                        'success' => true,
                        'message' => 'Secret ditemukan.',
                        'secret' => $secret,
                        'router_id' => $router_id,
                        'data' => array(
                            'router_id' => $router_id,
                            'secret' => $secret,
                        ),
                    );
                }
            }

            // Fallback: scan seluruh secret karena beberapa router tidak konsisten untuk query ?name.
            $scan = $this->command('/ppp/secret/print', array(
                '.proplist' => '.id,name,profile,remote-address,disabled',
            ));
            $searched[] = '/ppp/secret/print (scan)';
            if (!empty($scan['success'])) {
                $secret = $this->pickSecretByUsername((array) ($scan['data'] ?? array()), $username);
                if (!empty($secret)) {
                    return array(
                        'success' => true,
                        'message' => 'Secret ditemukan.',
                        'secret' => $secret,
                        'router_id' => $router_id,
                        'data' => array(
                            'router_id' => $router_id,
                            'secret' => $secret,
                        ),
                    );
                }
            }

            return array(
                'success' => false,
                'message' => 'PPP secret tidak ditemukan untuk username `' . $username . '` pada router #' . $router_id . ' (checked: ' . implode(', ', $searched) . ').',
                'secret' => null,
                'router_id' => $router_id,
                'data' => array(
                    'router_id' => $router_id,
                    'secret' => null,
                ),
            );
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Create PPP secret baru.
     *
     * @param int $router_id
     * @param string $username
     * @param string $password
     * @param string $profile
     * @param string $remote_ip
     * @param string $comment
     * @return array
     */
    public function createPppSecret($router_id, $username, $password, $profile, $remote_ip = '', $comment = '')
    {
        $username = trim((string) $username);
        $password = (string) $password;
        $profile = trim((string) $profile);
        $remote_ip = trim((string) $remote_ip);
        $comment = trim((string) $comment);

        if ($username === '' || $password === '' || $profile === '') {
            return $this->fail('Username/password/profile wajib diisi.');
        }

        $existing = $this->findPppSecretByRouterId((int) $router_id, $username);
        if (!empty($existing['success'])) {
            return $this->fail('PPP secret `' . $username . '` sudah ada.');
        }

        $params = array(
            'name' => $username,
            'password' => $password,
            'profile' => $profile,
            'service' => 'pppoe',
            'disabled' => 'no',
        );
        if ($remote_ip !== '') {
            $params['remote-address'] = $remote_ip;
        }
        if ($comment !== '') {
            $params['comment'] = $comment;
        }

        return $this->runOnRouter((int) $router_id, '/ppp/secret/add', $params);
    }

    /**
     * Upsert PPP secret (create jika belum ada, update jika sudah ada).
     *
     * @param int $router_id
     * @param string $username
     * @param string $password
     * @param string $profile
     * @param string $remote_ip
     * @param string $comment
     * @return array
     */
    public function upsertPppSecret($router_id, $username, $password, $profile, $remote_ip = '', $comment = '')
    {
        $router_id = (int) $router_id;
        $username = trim((string) $username);
        $password = (string) $password;
        $profile = trim((string) $profile);
        $remote_ip = trim((string) $remote_ip);
        $comment = trim((string) $comment);

        if ($router_id <= 0 || $username === '' || $password === '' || $profile === '') {
            return $this->fail('router_id/username/password/profile tidak valid.');
        }

        $connect = $this->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return $connect;
        }

        try {
            $find = $this->command('/ppp/secret/print', array('?name' => $username));
            if (empty($find['success'])) {
                return $find;
            }

            $secret = $this->pickSecretByUsername((array) ($find['data'] ?? array()), $username);
            if (empty($secret)) {
                $scan = $this->command('/ppp/secret/print', array('.proplist' => '.id,name,profile,remote-address,disabled'));
                if (!empty($scan['success'])) {
                    $secret = $this->pickSecretByUsername((array) ($scan['data'] ?? array()), $username);
                }
            }

            $params = array(
                'password' => $password,
                'profile' => $profile,
                'service' => 'pppoe',
                'disabled' => 'no',
            );
            if ($remote_ip !== '') {
                $params['remote-address'] = $remote_ip;
            }
            if ($comment !== '') {
                $params['comment'] = $comment;
            }

            if (!empty($secret)) {
                $id = $this->extractId($secret);
                if ($id !== '') {
                    $params['.id'] = $id;
                } else {
                    $params['numbers'] = $username;
                }

                $set = $this->command('/ppp/secret/set', $params);
                if (!empty($set['success'])) {
                    return $this->ok('PPP secret updated.', array(
                        'router_id' => $router_id,
                        'action' => 'update',
                    ));
                }

                if (isset($params['.id'])) {
                    unset($params['.id']);
                    $params['numbers'] = $username;
                    $set = $this->command('/ppp/secret/set', $params);
                }

                return !empty($set['success'])
                    ? $this->ok('PPP secret updated.', array('router_id' => $router_id, 'action' => 'update'))
                    : $set;
            }

            $create = $this->command('/ppp/secret/add', array_merge(array(
                'name' => $username,
            ), $params));

            return !empty($create['success'])
                ? $this->ok('PPP secret created.', array('router_id' => $router_id, 'action' => 'create'))
                : $create;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Disable PPP secret di router tertentu.
     *
     * @param int $router_id
     * @param string $username
     * @return array
     */
    public function disablePppSecret($router_id, $username)
    {
        $router_id = (int) $router_id;
        $username = trim((string) $username);
        if ($router_id <= 0 || $username === '') {
            return $this->fail('router_id/username tidak valid.');
        }

        $connect = $this->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return $connect;
        }

        try {
            $find = $this->command('/ppp/secret/print', array('?name' => $username));
            if (empty($find['success'])) {
                return $find;
            }

            $secret = $this->pickSecretByUsername((array) ($find['data'] ?? array()), $username);
            if (empty($secret)) {
                $scan = $this->command('/ppp/secret/print', array('.proplist' => '.id,name,profile,remote-address,disabled'));
                if (!empty($scan['success'])) {
                    $secret = $this->pickSecretByUsername((array) ($scan['data'] ?? array()), $username);
                }
            }
            if (empty($secret)) {
                return $this->fail('PPP secret `' . $username . '` tidak ditemukan.');
            }

            $id = $this->extractId($secret);
            $params = array('disabled' => 'yes');
            if ($id !== '') {
                $params['.id'] = $id;
            } else {
                $params['numbers'] = $username;
            }

            $set = $this->command('/ppp/secret/set', $params);
            if (empty($set['success']) && isset($params['.id'])) {
                $set = $this->command('/ppp/secret/set', array(
                    'numbers' => $username,
                    'disabled' => 'yes',
                ));
            }

            return !empty($set['success'])
                ? $this->ok('PPP secret disabled.', array('router_id' => $router_id, 'username' => $username))
                : $set;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Enable PPP secret di router tertentu.
     *
     * @param int $router_id
     * @param string $username
     * @return array
     */
    public function enablePppSecret($router_id, $username)
    {
        $router_id = (int) $router_id;
        $username = trim((string) $username);
        if ($router_id <= 0 || $username === '') {
            return $this->fail('router_id/username tidak valid.');
        }

        $connect = $this->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return $connect;
        }

        try {
            $find = $this->command('/ppp/secret/print', array('?name' => $username));
            if (empty($find['success'])) {
                return $find;
            }

            $secret = $this->pickSecretByUsername((array) ($find['data'] ?? array()), $username);
            if (empty($secret)) {
                $scan = $this->command('/ppp/secret/print', array('.proplist' => '.id,name,profile,remote-address,disabled'));
                if (!empty($scan['success'])) {
                    $secret = $this->pickSecretByUsername((array) ($scan['data'] ?? array()), $username);
                }
            }
            if (empty($secret)) {
                return $this->fail('PPP secret `' . $username . '` tidak ditemukan.');
            }

            $id = $this->extractId($secret);
            $params = array('disabled' => 'no');
            if ($id !== '') {
                $params['.id'] = $id;
            } else {
                $params['numbers'] = $username;
            }

            $set = $this->command('/ppp/secret/set', $params);
            if (empty($set['success']) && isset($params['.id'])) {
                $set = $this->command('/ppp/secret/set', array(
                    'numbers' => $username,
                    'disabled' => 'no',
                ));
            }

            return !empty($set['success'])
                ? $this->ok('PPP secret enabled.', array('router_id' => $router_id, 'username' => $username))
                : $set;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Add IP ke address-list isolir.
     *
     * @param int $router_id
     * @param string $ip_address
     * @param string $comment
     * @return array
     */
    public function addToIsolirList($router_id, $ip_address, $comment = '')
    {
        $ip_address = trim((string) $ip_address);
        if (!filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->fail('IP address tidak valid.');
        }

        $router_id = (int) $router_id;
        $connect = $this->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return $connect;
        }

        try {
            $find = $this->command('/ip/firewall/address-list/print', array(
                '?list' => $this->isolir_list,
                '?address' => $ip_address,
            ));
            if (!empty($find['success']) && !empty($find['data'])) {
                return $this->ok('IP sudah ada di list isolir.', array(
                    'router_id' => $router_id,
                    'ip_address' => $ip_address,
                ));
            }

            $add = $this->command('/ip/firewall/address-list/add', array(
                'list' => $this->isolir_list,
                'address' => $ip_address,
                'comment' => $comment !== '' ? $comment : ('AUTO-ISOLIR ' . $ip_address),
            ));

            return !empty($add['success'])
                ? $this->ok('IP ditambahkan ke list isolir.', array('router_id' => $router_id, 'ip_address' => $ip_address))
                : $add;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Remove IP dari address-list isolir.
     *
     * @param int $router_id
     * @param string $ip_address
     * @return array
     */
    public function removeFromIsolirList($router_id, $ip_address)
    {
        $ip_address = trim((string) $ip_address);
        if (!filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->fail('IP address tidak valid.');
        }

        $router_id = (int) $router_id;
        $connect = $this->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return $connect;
        }

        try {
            $find = $this->command('/ip/firewall/address-list/print', array(
                '?list' => $this->isolir_list,
                '?address' => $ip_address,
            ));
            if (empty($find['success'])) {
                return $find;
            }
            if (empty($find['data']) || !is_array($find['data'])) {
                return $this->ok('IP tidak ada di list isolir.', array(
                    'router_id' => $router_id,
                    'ip_address' => $ip_address,
                ));
            }

            foreach ($find['data'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = $this->extractId($row);
                if ($id === '') {
                    continue;
                }
                $remove = $this->command('/ip/firewall/address-list/remove', array('.id' => $id));
                if (empty($remove['success'])) {
                    return $remove;
                }
            }

            return $this->ok('IP dihapus dari list isolir.', array(
                'router_id' => $router_id,
                'ip_address' => $ip_address,
            ));
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Isolir user PPP (address-list only).
     *
     * @param int $router_id
     * @param string $username
     * @param string $remote_ip
     * @return array
     */
    public function isolateUser($router_id, $username, $remote_ip = '')
    {
        $router_id = (int) $router_id;
        $username = trim((string) $username);
        $remote_ip = trim((string) $remote_ip);

        if ($router_id <= 0 || $username === '') {
            return $this->fail('router_id/username tidak valid.');
        }

        if ($remote_ip === '') {
            $secret_result = $this->findPppSecretByRouterId($router_id, $username);
            if (!empty($secret_result['success']) && !empty($secret_result['secret'])) {
                $remote_ip = trim((string) ($secret_result['secret']['remote-address'] ?? ''));
            }
        }

        if ($remote_ip === '' || !filter_var($remote_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->fail('Remote IP tidak valid untuk isolir user `' . $username . '`.');
        }

        return $this->addToIsolirList($router_id, $remote_ip, 'AUTO-ISOLIR ' . $username);
    }

    /**
     * Release user PPP (hapus dari address-list + enable secret).
     *
     * @param int $router_id
     * @param string $username
     * @param string $remote_ip
     * @return array
     */
    public function releaseUser($router_id, $username, $remote_ip = '')
    {
        $router_id = (int) $router_id;
        $username = trim((string) $username);
        $remote_ip = trim((string) $remote_ip);

        if ($router_id <= 0 || $username === '') {
            return $this->fail('router_id/username tidak valid.');
        }

        if ($remote_ip === '') {
            $secret_result = $this->findPppSecretByRouterId($router_id, $username);
            if (!empty($secret_result['success']) && !empty($secret_result['secret'])) {
                $remote_ip = trim((string) ($secret_result['secret']['remote-address'] ?? ''));
            }
        }

        if ($remote_ip !== '' && filter_var($remote_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $remove = $this->removeFromIsolirList($router_id, $remote_ip);
            if (empty($remove['success'])) {
                return $remove;
            }
        }

        return $this->enablePppSecret($router_id, $username);
    }

    /**
     * Disconnect semua sesi PPP active berdasarkan username.
     *
     * @param int $router_id
     * @param string $username
     * @return array
     */
    public function disconnectPppActiveByUsername($router_id, $username)
    {
        $router_id = (int) $router_id;
        $username = trim((string) $username);
        if ($router_id <= 0 || $username === '') {
            return $this->fail('router_id/username tidak valid.');
        }

        $connect = $this->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return $connect;
        }

        try {
            $active = $this->command('/ppp/active/print', array(
                '?name' => $username,
                '.proplist' => '.id,name,address,caller-id',
            ));
            $rows = !empty($active['success']) ? (array) ($active['data'] ?? array()) : array();

            if (empty($rows)) {
                $scan = $this->command('/ppp/active/print', array(
                    '.proplist' => '.id,name,address,caller-id',
                ));
                $rows = !empty($scan['success']) ? (array) ($scan['data'] ?? array()) : array();
            }

            $targets = $this->pickActiveRowsByUsername($rows, $username);
            if (empty($targets)) {
                return $this->ok('Tidak ada sesi PPP active untuk username tersebut.', array(
                    'router_id' => $router_id,
                    'removed' => 0,
                ));
            }

            $removed = 0;
            foreach ($targets as $row) {
                $id = $this->extractId($row);
                if ($id === '') {
                    continue;
                }
                $rm = $this->command('/ppp/active/remove', array('.id' => $id));
                if (!empty($rm['success'])) {
                    $removed++;
                }
            }

            return $this->ok('Sesi PPP active diputus.', array(
                'router_id' => $router_id,
                'removed' => $removed,
            ));
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Ambil remote-address dari PPP profile (bisa nama pool atau static IP).
     *
     * @param int $router_id
     * @param string $profile_name
     * @return array
     */
    public function resolveProfileRemoteAddress($router_id, $profile_name)
    {
        $router_id = (int) $router_id;
        $profile_name = trim((string) $profile_name);
        if ($router_id <= 0 || $profile_name === '') {
            return $this->fail('router_id/profile_name tidak valid.');
        }

        $connect = $this->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return $connect;
        }

        try {
            $row = null;
            $find = $this->command('/ppp/profile/print', array(
                '?name' => $profile_name,
                '.proplist' => 'name,remote-address',
            ));
            if (!empty($find['success'])) {
                foreach ((array) ($find['data'] ?? array()) as $candidate) {
                    if (!is_array($candidate)) {
                        continue;
                    }
                    $name = trim((string) $this->readField($candidate, 'name'));
                    if ($this->isSameUsername($name, $profile_name)) {
                        $row = $candidate;
                        break;
                    }
                }
            }

            if (!is_array($row)) {
                $scan = $this->command('/ppp/profile/print', array(
                    '.proplist' => 'name,remote-address',
                ));
                if (!empty($scan['success'])) {
                    foreach ((array) ($scan['data'] ?? array()) as $candidate) {
                        if (!is_array($candidate)) {
                            continue;
                        }
                        $name = trim((string) $this->readField($candidate, 'name'));
                        if ($this->isSameUsername($name, $profile_name)) {
                            $row = $candidate;
                            break;
                        }
                    }
                }
            }

            if (!is_array($row)) {
                return $this->fail('PPP profile `' . $profile_name . '` tidak ditemukan di router #' . $router_id . '.');
            }

            $remote = trim((string) $this->readField($row, 'remote-address'));
            return $this->ok('PPP profile ditemukan.', array(
                'router_id' => $router_id,
                'profile_name' => $profile_name,
                'remote_address' => $remote,
            ));
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Cari IP kosong dari pool.
     *
     * @param int $router_id
     * @param string $pool_name
     * @param string $exclude_username
     * @return array
     */
    public function findFreeIpInPool($router_id, $pool_name, $exclude_username = '')
    {
        $router_id = (int) $router_id;
        $pool_name = trim((string) $pool_name);
        $exclude_username = trim((string) $exclude_username);
        if ($router_id <= 0 || $pool_name === '') {
            return $this->fail('router_id/pool_name tidak valid.');
        }

        $connect = $this->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return $connect;
        }

        try {
            $pool = $this->command('/ip/pool/print', array(
                '?name' => $pool_name,
                '.proplist' => 'name,ranges',
            ));
            if (empty($pool['success']) || empty($pool['data'])) {
                return $this->fail('IP pool `' . $pool_name . '` tidak ditemukan di router #' . $router_id . '.');
            }

            $pool_row = null;
            foreach ((array) ($pool['data'] ?? array()) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string) $this->readField($row, 'name'));
                if ($this->isSameUsername($name, $pool_name)) {
                    $pool_row = $row;
                    break;
                }
            }
            if (!is_array($pool_row)) {
                $pool_row = (array) ($pool['data'][0] ?? array());
            }

            $ranges = trim((string) $this->readField($pool_row, 'ranges'));
            if ($ranges === '') {
                $ranges = trim((string) $this->readField($pool_row, 'range'));
            }
            $segments = $this->extractPoolRanges($ranges);
            if (empty($segments)) {
                $db_ranges = $this->getPoolRangesFromDatabase($pool_name, $router_id);
                if (!empty($db_ranges)) {
                    $segments = $db_ranges;
                }
            }
            if (empty($segments)) {
                return $this->fail('Range IP pool `' . $pool_name . '` tidak valid. Raw ranges: `' . ($ranges !== '' ? $ranges : '(empty)') . '`.');
            }

            $used = array();

            $secret_used = $this->command('/ppp/secret/print', array(
                '.proplist' => 'name,remote-address',
            ));
            if (!empty($secret_used['success'])) {
                foreach ((array) ($secret_used['data'] ?? array()) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $name = trim((string) $this->readField($row, 'name'));
                    if ($exclude_username !== '' && $this->isSameUsername($name, $exclude_username)) {
                        continue;
                    }
                    $ip = $this->normalizeIpv4((string) $this->readField($row, 'remote-address'));
                    if ($ip !== '') {
                        $used[$ip] = true;
                    }
                }
            }

            $active_used = $this->command('/ppp/active/print', array(
                '.proplist' => 'name,address',
            ));
            if (!empty($active_used['success'])) {
                foreach ((array) ($active_used['data'] ?? array()) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $name = trim((string) $this->readField($row, 'name'));
                    if ($exclude_username !== '' && $this->isSameUsername($name, $exclude_username)) {
                        continue;
                    }
                    $ip = $this->normalizeIpv4((string) $this->readField($row, 'address'));
                    if ($ip !== '') {
                        $used[$ip] = true;
                    }
                }
            }

            $max_scan = 262144;
            $scanned = 0;
            foreach ($segments as $segment) {
                $start = (int) ($segment['start'] ?? 0);
                $end = (int) ($segment['end'] ?? 0);
                if ($end < $start) {
                    continue;
                }

                for ($current = $start; $current <= $end; $current++) {
                    $scanned++;
                    if ($scanned > $max_scan) {
                        return $this->fail('Range pool terlalu besar untuk auto-allocation (>' . $max_scan . ' IP).');
                    }

                    $candidate = $this->unsignedToIpv4($current);
                    if ($candidate === '') {
                        continue;
                    }
                    if (!isset($used[$candidate])) {
                        return $this->ok('IP kosong ditemukan dari pool.', array(
                            'router_id' => $router_id,
                            'pool_name' => $pool_name,
                            'ip_address' => $candidate,
                        ));
                    }
                }
            }

            return $this->fail('IP pool `' . $pool_name . '` penuh.');
        } finally {
            $this->disconnect();
        }
    }

    protected function pickSecretByUsername(array $rows, $username)
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) $this->readField($row, 'name'));
            if ($this->isSameUsername($name, $username)) {
                return $row;
            }
        }

        return array();
    }

    protected function pickActiveRowsByUsername(array $rows, $username)
    {
        $matched = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) $this->readField($row, 'name'));
            if ($this->isSameUsername($name, $username)) {
                $matched[] = $row;
            }
        }

        return $matched;
    }

    protected function readField(array $row, $field, $default = '')
    {
        $field = (string) $field;
        if ($field === '') {
            return $default;
        }

        foreach (array($field, '=' . $field) as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return $default;
    }

    protected function isSameUsername($left, $right)
    {
        return $this->normalizeUsernameKey($left) !== '' &&
            $this->normalizeUsernameKey($left) === $this->normalizeUsernameKey($right);
    }

    protected function normalizeUsernameKey($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        // Samakan varian dash dari copy/paste Winbox/browser.
        $value = str_replace(
            array("\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x92", "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x88\x92"),
            '-',
            $value
        );
        $value = preg_replace('/\s+/', '', $value);
        return strtoupper((string) $value);
    }

    protected function normalizeIpv4($ip)
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '';
        }

        $ip = explode('/', $ip, 2)[0];
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
    }

    protected function extractPoolRanges($ranges)
    {
        $ranges = trim((string) $ranges);
        if ($ranges === '') {
            return array();
        }

        $segments = array();
        foreach (explode(',', $ranges) as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            $bounds = explode('-', $part, 2);
            $start_raw = trim((string) ($bounds[0] ?? ''));
            $end_raw = trim((string) ($bounds[1] ?? ($bounds[0] ?? '')));

            $start_ip = $this->normalizeIpv4($start_raw);
            $end_ip = $this->normalizeIpv4($end_raw);
            if ($start_ip !== '' && $end_ip === '') {
                $end_ip = $this->expandPoolRangeEndpoint($start_ip, $end_raw);
            }
            if ($start_ip === '' && $end_ip !== '') {
                $start_ip = $this->expandPoolRangeEndpoint($end_ip, $start_raw);
            }
            if ($start_ip === '' || $end_ip === '') {
                continue;
            }

            $start = $this->ipv4ToUnsigned($start_ip);
            $end = $this->ipv4ToUnsigned($end_ip);
            if ($start === null || $end === null) {
                continue;
            }

            if ($end < $start) {
                $tmp = $start;
                $start = $end;
                $end = $tmp;
            }

            $segments[] = array(
                'start' => $start,
                'end' => $end,
            );
        }

        return $segments;
    }

    protected function expandPoolRangeEndpoint($reference_ip, $endpoint)
    {
        $reference_ip = $this->normalizeIpv4($reference_ip);
        $endpoint = trim((string) $endpoint);
        if ($reference_ip === '' || $endpoint === '') {
            return '';
        }

        $endpoint = explode('/', $endpoint, 2)[0];
        if ($this->normalizeIpv4($endpoint) !== '') {
            return $this->normalizeIpv4($endpoint);
        }

        // Format singkat RouterOS: 172.16.20.2-254 (endpoint hanya oktet terakhir).
        if (!preg_match('/^\d{1,3}$/', $endpoint)) {
            return '';
        }
        $last = (int) $endpoint;
        if ($last < 0 || $last > 255) {
            return '';
        }

        $parts = explode('.', $reference_ip);
        if (count($parts) !== 4) {
            return '';
        }
        $parts[3] = (string) $last;
        return $this->normalizeIpv4(implode('.', $parts));
    }

    protected function getPoolRangesFromDatabase($pool_name, $router_id = 0)
    {
        $pool_name = trim((string) $pool_name);
        $router_id = (int) $router_id;
        if ($pool_name === '') {
            return array();
        }

        if (!isset($this->CI->db) || !is_object($this->CI->db)) {
            $this->CI->load->database();
        }
        if (!isset($this->CI->db) || !is_object($this->CI->db) || !$this->CI->db->table_exists('ip_pools')) {
            return array();
        }

        $fields = $this->CI->db->list_fields('ip_pools');
        if (!in_array('pool_name', $fields, true)) {
            return array();
        }

        $qb = $this->CI->db->from('ip_pools');
        $qb->where('LOWER(pool_name) =', strtolower($pool_name));
        if ($router_id > 0 && in_array('router_id', $fields, true)) {
            $qb->where('router_id', $router_id);
        }

        $row = (array) $qb->limit(1)->get()->row_array();
        if (empty($row)) {
            return array();
        }

        $start_ip = $this->normalizeIpv4((string) ($row['range_start'] ?? ''));
        $end_ip = $this->normalizeIpv4((string) ($row['range_end'] ?? ''));
        if ($start_ip === '' || $end_ip === '') {
            return array();
        }

        $start = $this->ipv4ToUnsigned($start_ip);
        $end = $this->ipv4ToUnsigned($end_ip);
        if ($start === null || $end === null) {
            return array();
        }
        if ($end < $start) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }

        return array(
            array(
                'start' => $start,
                'end' => $end,
            ),
        );
    }

    protected function ipv4ToUnsigned($ip)
    {
        $long = ip2long((string) $ip);
        if ($long === false) {
            return null;
        }

        return (int) sprintf('%u', $long);
    }

    protected function unsignedToIpv4($value)
    {
        $value = (int) $value;
        if ($value < 0) {
            return '';
        }

        $packed = pack('N', $value);
        $ip = @inet_ntop($packed);
        if (!is_string($ip) || $ip === '') {
            return '';
        }

        return $this->normalizeIpv4($ip);
    }

    protected function has_router_error($response)
    {
        if (!is_array($response)) {
            return false;
        }

        foreach ($response as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (isset($row['!trap']) || isset($row['category']) || isset($row['message'])) {
                return true;
            }
        }

        return false;
    }

    protected function extract_router_error($response)
    {
        if (!is_array($response)) {
            return 'Unknown RouterOS API error.';
        }

        foreach ($response as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['message'])) {
                return (string) $row['message'];
            }
            if (!empty($row['!trap'])) {
                return (string) $row['!trap'];
            }
        }

        return 'Unknown RouterOS API error.';
    }

    protected function extractId(array $row)
    {
        foreach (array('.id', '=.id', 'id', '=id') as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    protected function ok($message, array $data = array())
    {
        return array(
            'success' => true,
            'message' => (string) $message,
            'data' => $data,
        );
    }

    protected function fail($message, array $data = array())
    {
        log_message('error', '[MikrotikManager] ' . (string) $message);
        return array(
            'success' => false,
            'message' => (string) $message,
            'data' => $data,
        );
    }
}
