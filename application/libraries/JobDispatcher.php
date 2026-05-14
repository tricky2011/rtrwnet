<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class JobDispatcher
{
    private $CI;
    private $queue_cfg = array();
    private $redis_client = null;
    private $redis_checked = false;
    private $pending_status = null;
    private $job_table_fields = null;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->config->load('queue', true);
        $cfg = (array) $this->CI->config->item('queue');
        $this->queue_cfg = is_array($cfg) ? $cfg : array();
    }

    /**
     * Dispatch async job.
     *
     * @param int|null $tenant_id
     * @param string $job_type
     * @param array $payload
     * @param int $delay
     * @param int|null $max_attempts
     * @param string|null $queue_name
     * @return array
     */
    public function dispatch($tenant_id, $job_type, array $payload, $delay = 0, $max_attempts = null, $queue_name = null)
    {
        if (!$this->CI->db->table_exists('background_jobs')) {
            return array(
                'success' => false,
                'message' => 'Table background_jobs belum tersedia.',
                'job_id' => null,
            );
        }

        $job_type = trim((string) $job_type);
        if ($job_type === '') {
            return array(
                'success' => false,
                'message' => 'job_type wajib diisi.',
                'job_id' => null,
            );
        }

        $delay = max(0, (int) $delay);
        $tenant_id = $tenant_id !== null ? (int) $tenant_id : null;
        $queue_name = trim((string) ($queue_name ?: $this->cfg('queue_name', 'default')));
        if ($queue_name === '') {
            $queue_name = 'default';
        }

        $max = $max_attempts !== null
            ? max(1, (int) $max_attempts)
            : max(1, (int) $this->cfg('queue_default_max_attempts', 5));

        $available_at = date('Y-m-d H:i:s', time() + $delay);
        $now = date('Y-m-d H:i:s');

        $insert = array(
            'job_type' => $job_type,
            'queue_name' => $queue_name,
            'payload_json' => json_encode($payload),
            'status' => $this->get_pending_status_value(),
            'attempts' => 0,
            'max_attempts' => $max,
            'last_error' => null,
            'available_at' => $available_at,
            'created_at' => $now,
            'updated_at' => $now,
        );
        if ($this->has_job_field('tenant_id')) {
            $insert['tenant_id'] = $tenant_id > 0 ? $tenant_id : null;
        }
        $insert = $this->filter_insert_by_job_fields($insert);

        $ok = $this->CI->db->insert('background_jobs', $insert);
        if (!$ok) {
            $error = $this->CI->db->error();
            return array(
                'success' => false,
                'message' => 'Insert job gagal: ' . (string) ($error['message'] ?? 'unknown'),
                'job_id' => null,
            );
        }

        $job_id = (int) $this->CI->db->insert_id();
        $queued = false;
        $driver = strtolower((string) $this->cfg('queue_driver', 'database'));
        if ($driver === 'redis') {
            $queued = $this->push_to_redis($job_id, $delay, $queue_name);
        }

        return array(
            'success' => true,
            'message' => $queued ? 'Job dispatched ke Redis queue.' : 'Job tersimpan ke database queue.',
            'job_id' => $job_id,
            'driver' => $queued ? 'redis' : 'database',
            'available_at' => $available_at,
        );
    }

    public function cfg($key, $default = null)
    {
        return array_key_exists($key, $this->queue_cfg) ? $this->queue_cfg[$key] : $default;
    }

    private function get_pending_status_value()
    {
        if ($this->pending_status !== null) {
            return $this->pending_status;
        }

        $enum_values = $this->get_enum_values('background_jobs', 'status');
        if (empty($enum_values)) {
            $this->pending_status = 'pending';
            return $this->pending_status;
        }

        $this->pending_status = in_array('pending', $enum_values, true)
            ? 'pending'
            : (in_array('queued', $enum_values, true) ? 'queued' : (string) reset($enum_values));

        return $this->pending_status;
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

        $row = $this->CI->db->query(
            "SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            array($this->CI->db->database, $table, $column)
        )->row_array();

        $column_type = (string) ($row['COLUMN_TYPE'] ?? '');
        if (strpos($column_type, 'enum(') !== 0) {
            return array();
        }

        $raw = substr($column_type, 5, -1);
        $parts = str_getcsv($raw, ',', "'");
        $values = array();
        foreach ($parts as $part) {
            $value = trim((string) $part);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function push_to_redis($job_id, $delay, $queue_name)
    {
        $redis = $this->get_redis_client();
        if ($redis === null) {
            return false;
        }

        $queue_key = $this->redis_key('queue:' . $queue_name);
        $delayed_key = $this->redis_key('queue:' . $queue_name . ':delayed');
        $payload = (string) $job_id;

        try {
            if ($delay > 0) {
                $score = time() + $delay;
                if ($redis instanceof Redis) {
                    $redis->zAdd($delayed_key, $score, $payload);
                } else {
                    $redis->zadd($delayed_key, array($payload => $score));
                }
                return true;
            }

            if ($redis instanceof Redis) {
                $redis->rPush($queue_key, $payload);
            } else {
                $redis->rpush($queue_key, $payload);
            }
            return true;
        } catch (Throwable $e) {
            log_message('error', '[JobDispatcher] Redis push failed: ' . $e->getMessage());
            return false;
        }
    }

    private function get_redis_client()
    {
        if ($this->redis_checked) {
            return $this->redis_client;
        }
        $this->redis_checked = true;

        $host = (string) $this->cfg('redis_host', '127.0.0.1');
        $port = (int) $this->cfg('redis_port', 6379);
        $db = (int) $this->cfg('redis_db', 0);
        $timeout = (float) $this->cfg('redis_timeout', 2.0);
        $password = $this->cfg('redis_password', null);

        try {
            if (class_exists('Redis')) {
                $redis = new Redis();
                $connected = $redis->connect($host, $port, $timeout);
                if (!$connected) {
                    throw new RuntimeException('Redis extension connect() gagal.');
                }
                if (!empty($password)) {
                    $redis->auth((string) $password);
                }
                if ($db > 0) {
                    $redis->select($db);
                }
                $this->redis_client = $redis;
                return $this->redis_client;
            }

            if (class_exists('Predis\\Client')) {
                $params = array(
                    'scheme' => 'tcp',
                    'host' => $host,
                    'port' => $port,
                    'database' => $db,
                );
                if (!empty($password)) {
                    $params['password'] = (string) $password;
                }
                $redis = new Predis\Client($params);
                $redis->connect();
                $this->redis_client = $redis;
                return $this->redis_client;
            }
        } catch (Throwable $e) {
            log_message('error', '[JobDispatcher] Redis unavailable: ' . $e->getMessage());
        }

        $this->redis_client = null;
        return null;
    }

    private function redis_key($suffix)
    {
        $prefix = (string) $this->cfg('redis_prefix', 'rtrwnet:');
        return $prefix . $suffix;
    }

    private function has_job_field($field)
    {
        $fields = $this->get_job_table_fields();
        return in_array((string) $field, $fields, true);
    }

    private function get_job_table_fields()
    {
        if (is_array($this->job_table_fields)) {
            return $this->job_table_fields;
        }

        if (!$this->CI->db->table_exists('background_jobs')) {
            $this->job_table_fields = array();
            return $this->job_table_fields;
        }

        $fields = $this->CI->db->list_fields('background_jobs');
        $this->job_table_fields = is_array($fields) ? $fields : array();
        return $this->job_table_fields;
    }

    private function filter_insert_by_job_fields(array $insert)
    {
        $fields = $this->get_job_table_fields();
        if (empty($fields)) {
            return $insert;
        }

        $filtered = array();
        foreach ($insert as $k => $v) {
            if (in_array((string) $k, $fields, true)) {
                $filtered[$k] = $v;
            }
        }

        return $filtered;
    }
}
