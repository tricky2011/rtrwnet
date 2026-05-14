<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Worker extends CI_Controller
{
    private $queue_cfg = array();
    private $redis_client = null;
    private $redis_checked = false;
    private $pending_statuses = array('pending', 'queued');
    private $processing_status = 'processing';
    private $cancelled_status = 'cancelled';

    public function __construct()
    {
        parent::__construct();

        if (!$this->input->is_cli_request()) {
            show_error('Worker hanya bisa dijalankan via CLI.', 403);
            exit;
        }

        if (!defined('JOB_WORKER_CONTEXT')) {
            define('JOB_WORKER_CONTEXT', true);
        }

        $this->load->database();
        $this->load->helper(array('subscription'));
        $this->config->load('queue', true);
        $cfg = (array) $this->config->item('queue');
        $this->queue_cfg = is_array($cfg) ? $cfg : array();
    }

    /**
     * php index.php worker run
     * php index.php worker run once
     * php index.php worker run once 10
     */
    public function run($mode = 'daemon', $max_jobs = 0)
    {
        if (!$this->db->table_exists('background_jobs')) {
            echo "[worker] background_jobs table not found.\n";
            return;
        }

        $this->normalize_job_statuses();

        $mode = strtolower(trim((string) $mode));
        $run_once = in_array($mode, array('once', 'single', 'one'), true);
        $max_jobs = max(0, (int) $max_jobs);
        $poll_sleep = max(1, (int) $this->cfg('queue_poll_sleep_seconds', 1));
        $processed = 0;

        echo "[worker] started mode=" . ($run_once ? 'once' : 'daemon') . ", max_jobs={$max_jobs}\n";

        while (true) {
            $this->release_delayed_jobs_to_ready_queue();

            $job = $this->reserve_next_job();
            if (empty($job)) {
                if ($run_once) {
                    break;
                }
                if ($max_jobs > 0 && $processed >= $max_jobs) {
                    break;
                }
                sleep($poll_sleep);
                continue;
            }

            $processed++;
            $this->process_job($job);

            if ($run_once) {
                break;
            }
            if ($max_jobs > 0 && $processed >= $max_jobs) {
                break;
            }
        }

        echo "[worker] stopped. processed={$processed}\n";
    }

    private function process_job(array $job)
    {
        $job_id = (int) ($job['id'] ?? 0);
        $job_type = trim((string) ($job['job_type'] ?? ''));
        $tenant_id = isset($job['tenant_id']) ? (int) $job['tenant_id'] : 0;

        if ($job_id <= 0 || $job_type === '') {
            return;
        }

        if ($tenant_id > 0 && !canRunTenantBackgroundJobs($tenant_id)) {
            $this->mark_job_cancelled($job_id, 'Tenant suspended / subscription inactive.');
            return;
        }

        $payload_raw = (string) ($job['payload_json'] ?? '');
        $payload = json_decode($payload_raw, true);
        if (!is_array($payload)) {
            $payload = array();
        }

        $result = array(
            'success' => false,
            'message' => 'Unhandled job.',
            'retryable' => true,
        );

        try {
            $handler = $this->resolve_job_handler($job_type);
            if ($handler === null) {
                $result = array(
                    'success' => false,
                    'message' => 'Handler not found for job_type: ' . $job_type,
                    'retryable' => false,
                );
            } else {
                $result = $handler->handle($payload, $job);
                if (!is_array($result)) {
                    $result = array('success' => false, 'message' => 'Handler result invalid.', 'retryable' => true);
                }
                if (!array_key_exists('retryable', $result)) {
                    $result['retryable'] = true;
                }
            }
        } catch (Throwable $e) {
            $result = array(
                'success' => false,
                'message' => $e->getMessage(),
                'retryable' => true,
            );
            log_message('error', '[WORKER] exception job_id=' . $job_id . ' type=' . $job_type . ' err=' . $e->getMessage());
        }

        if (!empty($result['success'])) {
            $this->mark_job_success($job_id, (string) ($result['message'] ?? 'OK'));
            return;
        }

        $this->retry_or_fail_job($job, $result);
    }

    private function retry_or_fail_job(array $job, array $result)
    {
        $job_id = (int) ($job['id'] ?? 0);
        $attempts = (int) ($job['attempts'] ?? 0) + 1;
        $max_attempts = max(1, (int) ($job['max_attempts'] ?? $this->cfg('queue_default_max_attempts', 5)));
        $retryable = !isset($result['retryable']) || (bool) $result['retryable'];
        $message = trim((string) ($result['message'] ?? 'Job failed.'));
        if ($message === '') {
            $message = 'Job failed.';
        }

        if (!$retryable || $attempts >= $max_attempts) {
            $this->db->where('id', $job_id)->update('background_jobs', array(
                'status' => 'failed',
                'attempts' => $attempts,
                'last_error' => $message,
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ));
            return;
        }

        $delay = $this->calculate_backoff_delay($attempts);
        $available_at = date('Y-m-d H:i:s', time() + $delay);

        $pending_status = $this->pending_statuses[0];
        $this->db->where('id', $job_id)->update('background_jobs', array(
            'status' => $pending_status,
            'attempts' => $attempts,
            'last_error' => $message,
            'available_at' => $available_at,
            'started_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ));

        $queue_name = trim((string) ($job['queue_name'] ?? $this->cfg('queue_name', 'default')));
        if ($queue_name === '') {
            $queue_name = 'default';
        }

        $this->push_job_to_redis($job_id, $queue_name, $delay);
    }

    private function mark_job_success($job_id, $message = 'OK')
    {
        $this->db->where('id', (int) $job_id)->update('background_jobs', array(
            'status' => 'success',
            'last_error' => null,
            'finished_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ));
    }

    private function mark_job_cancelled($job_id, $message)
    {
        $this->db->where('id', (int) $job_id)->update('background_jobs', array(
            'status' => $this->cancelled_status,
            'last_error' => (string) $message,
            'finished_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ));
    }

    private function reserve_next_job()
    {
        $driver = strtolower((string) $this->cfg('queue_driver', 'database'));
        if ($driver === 'redis') {
            $job = $this->reserve_next_job_from_redis();
            if (!empty($job)) {
                return $job;
            }
        }

        return $this->reserve_next_job_from_database();
    }

    private function reserve_next_job_from_redis()
    {
        $redis = $this->get_redis_client();
        if ($redis === null) {
            return null;
        }

        $queue_name = (string) $this->cfg('queue_name', 'default');
        if ($queue_name === '') {
            $queue_name = 'default';
        }
        $queue_key = $this->redis_key('queue:' . $queue_name);
        $timeout = max(1, (int) $this->cfg('queue_pop_timeout_seconds', 2));

        try {
            if ($redis instanceof Redis) {
                $item = $redis->brPop(array($queue_key), $timeout);
            } else {
                $item = $redis->brpop(array($queue_key), $timeout);
            }
        } catch (Throwable $e) {
            log_message('error', '[WORKER] redis pop failed: ' . $e->getMessage());
            return null;
        }

        if (!is_array($item) || count($item) < 2) {
            return null;
        }

        $job_id = (int) $item[1];
        if ($job_id <= 0) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $this->db
            ->where('id', $job_id)
            ->where_in('status', $this->pending_statuses)
            ->where('available_at <=', $now)
            ->update('background_jobs', array(
                'status' => $this->processing_status,
                'started_at' => $now,
                'updated_at' => $now,
            ));

        if ($this->db->affected_rows() <= 0) {
            // Job belum due atau sudah diproses worker lain.
            $job = $this->db->where('id', $job_id)->limit(1)->get('background_jobs')->row_array();
            if (!empty($job) && isset($job['available_at']) && strtotime((string) $job['available_at']) > time()) {
                $delay = max(1, strtotime((string) $job['available_at']) - time());
                $this->push_job_to_redis($job_id, (string) ($job['queue_name'] ?? 'default'), $delay);
            }
            return null;
        }

        return $this->db->where('id', $job_id)->limit(1)->get('background_jobs')->row_array();
    }

    private function reserve_next_job_from_database()
    {
        $now = date('Y-m-d H:i:s');
        $job = $this->db
            ->from('background_jobs')
            ->where_in('status', $this->pending_statuses)
            ->where('available_at <=', $now)
            ->order_by('available_at', 'ASC')
            ->order_by('id', 'ASC')
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($job)) {
            return null;
        }

        $this->db
            ->where('id', (int) $job['id'])
            ->where_in('status', $this->pending_statuses)
            ->where('available_at <=', $now)
            ->update('background_jobs', array(
                'status' => $this->processing_status,
                'started_at' => $now,
                'updated_at' => $now,
            ));

        if ($this->db->affected_rows() <= 0) {
            return null;
        }

        return $this->db->where('id', (int) $job['id'])->limit(1)->get('background_jobs')->row_array();
    }

    private function release_delayed_jobs_to_ready_queue()
    {
        $redis = $this->get_redis_client();
        if ($redis === null) {
            return;
        }

        $queue_name = (string) $this->cfg('queue_name', 'default');
        if ($queue_name === '') {
            $queue_name = 'default';
        }

        $delayed_key = $this->redis_key('queue:' . $queue_name . ':delayed');
        $ready_key = $this->redis_key('queue:' . $queue_name);
        $now = time();

        try {
            if ($redis instanceof Redis) {
                $job_ids = $redis->zRangeByScore($delayed_key, '-inf', (string) $now, array('limit' => array(0, 50)));
            } else {
                $job_ids = $redis->zrangebyscore($delayed_key, '-inf', (string) $now, array('limit' => array(0, 50)));
            }
        } catch (Throwable $e) {
            log_message('error', '[WORKER] release delayed failed: ' . $e->getMessage());
            return;
        }

        if (empty($job_ids) || !is_array($job_ids)) {
            return;
        }

        foreach ($job_ids as $job_id_raw) {
            $job_id = (int) $job_id_raw;
            if ($job_id <= 0) {
                continue;
            }
            try {
                if ($redis instanceof Redis) {
                    $redis->zRem($delayed_key, (string) $job_id);
                    $redis->rPush($ready_key, (string) $job_id);
                } else {
                    $redis->zrem($delayed_key, (string) $job_id);
                    $redis->rpush($ready_key, (string) $job_id);
                }
            } catch (Throwable $e) {
                log_message('error', '[WORKER] delayed enqueue failed job=' . $job_id . ' err=' . $e->getMessage());
            }
        }
    }

    private function resolve_job_handler($job_type)
    {
        $job_type = strtolower(trim((string) $job_type));
        if ($job_type === '') {
            return null;
        }

        $map = array(
            'mikrotik_create_secret' => 'MikrotikCreateSecretJob',
            'mikrotik_sync' => 'MikrotikCreateSecretJob',
            'telegram_send' => 'TelegramSendJob',
            'billing_generate' => 'BillingGenerateJob',
            'isolir_check' => 'IsolirJob',
            'isolir' => 'IsolirJob',
            'monitoring' => 'MonitoringJob',
            'monitoring_check' => 'MonitoringJob',
        );

        if (!isset($map[$job_type])) {
            return null;
        }

        $class = $map[$job_type];
        $path = APPPATH . 'jobs/' . $class . '.php';
        if (!is_file($path)) {
            return null;
        }

        require_once $path;
        if (!class_exists($class, false)) {
            return null;
        }

        return new $class($this);
    }

    private function calculate_backoff_delay($attempts)
    {
        $attempts = max(1, (int) $attempts);
        $base = max(1, (int) $this->cfg('queue_backoff_base_seconds', 5));
        $max = max($base, (int) $this->cfg('queue_backoff_max_seconds', 300));
        $delay = $base * (int) pow(2, max(0, $attempts - 1));

        return min($max, $delay);
    }

    private function push_job_to_redis($job_id, $queue_name, $delay = 0)
    {
        $redis = $this->get_redis_client();
        if ($redis === null) {
            return false;
        }

        $job_id = (int) $job_id;
        if ($job_id <= 0) {
            return false;
        }

        $queue_name = trim((string) $queue_name);
        if ($queue_name === '') {
            $queue_name = 'default';
        }

        $queue_key = $this->redis_key('queue:' . $queue_name);
        $delay_key = $this->redis_key('queue:' . $queue_name . ':delayed');

        try {
            if ((int) $delay > 0) {
                $score = time() + (int) $delay;
                if ($redis instanceof Redis) {
                    $redis->zAdd($delay_key, $score, (string) $job_id);
                } else {
                    $redis->zadd($delay_key, array((string) $job_id => $score));
                }
                return true;
            }

            if ($redis instanceof Redis) {
                $redis->rPush($queue_key, (string) $job_id);
            } else {
                $redis->rpush($queue_key, (string) $job_id);
            }
            return true;
        } catch (Throwable $e) {
            log_message('error', '[WORKER] push retry redis failed: ' . $e->getMessage());
            return false;
        }
    }

    private function get_redis_client()
    {
        if ($this->redis_checked) {
            return $this->redis_client;
        }
        $this->redis_checked = true;

        if (strtolower((string) $this->cfg('queue_driver', 'database')) !== 'redis') {
            $this->redis_client = null;
            return null;
        }

        $host = (string) $this->cfg('redis_host', '127.0.0.1');
        $port = (int) $this->cfg('redis_port', 6379);
        $db = (int) $this->cfg('redis_db', 0);
        $timeout = (float) $this->cfg('redis_timeout', 2.0);
        $password = $this->cfg('redis_password', null);

        try {
            if (class_exists('Redis')) {
                $redis = new Redis();
                if (!$redis->connect($host, $port, $timeout)) {
                    throw new RuntimeException('Redis extension connect gagal.');
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
            log_message('error', '[WORKER] redis unavailable: ' . $e->getMessage());
        }

        $this->redis_client = null;
        return null;
    }

    private function redis_key($suffix)
    {
        $prefix = (string) $this->cfg('redis_prefix', 'rtrwnet:');
        return $prefix . $suffix;
    }

    private function cfg($key, $default = null)
    {
        return array_key_exists($key, $this->queue_cfg) ? $this->queue_cfg[$key] : $default;
    }

    private function normalize_job_statuses()
    {
        $values = $this->get_enum_values('background_jobs', 'status');
        if (empty($values)) {
            $this->pending_statuses = array('pending', 'queued');
            return;
        }

        $pending = array();
        if (in_array('pending', $values, true)) {
            $pending[] = 'pending';
        }
        if (in_array('queued', $values, true)) {
            $pending[] = 'queued';
        }
        if (empty($pending)) {
            $pending[] = (string) reset($values);
        }

        $this->pending_statuses = array_values(array_unique($pending));
        if (in_array('processing', $values, true)) {
            $this->processing_status = 'processing';
        }
        if (in_array('cancelled', $values, true)) {
            $this->cancelled_status = 'cancelled';
        }
    }

    private function get_enum_values($table, $column)
    {
        $row = $this->db->query(
            "SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            array($this->db->database, (string) $table, (string) $column)
        )->row_array();

        $column_type = (string) ($row['COLUMN_TYPE'] ?? '');
        if (strpos($column_type, 'enum(') !== 0) {
            return array();
        }

        $raw = substr($column_type, 5, -1);
        $parts = str_getcsv($raw, ',', "'");
        $values = array();
        foreach ($parts as $part) {
            $v = trim((string) $part);
            if ($v !== '') {
                $values[] = $v;
            }
        }
        return array_values(array_unique($values));
    }
}

