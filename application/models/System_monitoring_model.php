<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * System monitoring data provider.
 * Menyediakan status cron, API MikroTik, Telegram bot, dan error terbaru.
 */
class System_monitoring_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function get_last_cron_runs($limit = 12)
    {
        $limit = max(1, (int) $limit);

        if ($this->db->table_exists('cron_run_logs')) {
            $rows = $this->db
                ->select('job_name, status, started_at, finished_at, duration_ms, message')
                ->from('cron_run_logs')
                ->order_by('started_at', 'desc')
                ->limit($limit)
                ->get()
                ->result_array();

            return [
                'source' => 'cron_run_logs',
                'rows' => $rows,
            ];
        }

        return [
            'source' => 'file:application/logs/cron.log',
            'rows' => $this->get_cron_log_tail($limit),
        ];
    }

    public function get_mikrotik_status()
    {
        $this->config->load('mikrotik', true);
        $cfg = (array) $this->config->item('mikrotik');

        $host = isset($cfg['mikrotik_host']) ? (string) $cfg['mikrotik_host'] : '';
        $port = isset($cfg['mikrotik_port']) ? (int) $cfg['mikrotik_port'] : 8728;

        if ($host === '' || $port <= 0) {
            return [
                'status' => 'unknown',
                'label' => 'Config Missing',
                'host' => $host,
                'port' => $port,
                'latency_ms' => null,
                'message' => 'mikrotik_host/mikrotik_port belum dikonfigurasi',
            ];
        }

        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 3);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if ($socket) {
            fclose($socket);
            return [
                'status' => 'online',
                'label' => 'Online',
                'host' => $host,
                'port' => $port,
                'latency_ms' => $latencyMs,
                'message' => 'Port API reachable',
            ];
        }

        return [
            'status' => 'offline',
            'label' => 'Offline',
            'host' => $host,
            'port' => $port,
            'latency_ms' => $latencyMs,
            'message' => $errstr !== '' ? $errstr : ('Socket error ' . $errno),
        ];
    }

    public function get_telegram_status()
    {
        $this->config->load('telegram_automation', true);
        $cfg = (array) $this->config->item('telegram_automation');
        $token = isset($cfg['telegram_bot_token']) ? trim((string) $cfg['telegram_bot_token']) : '';

        if ($token === '') {
            return [
                'status' => 'unknown',
                'label' => 'Config Missing',
                'latency_ms' => null,
                'bot_username' => null,
                'message' => 'telegram_bot_token belum di-set',
            ];
        }

        $url = 'https://api.telegram.org/bot' . $token . '/getMe';
        $start = microtime(true);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if ($curlError) {
            return [
                'status' => 'offline',
                'label' => 'Offline',
                'latency_ms' => $latencyMs,
                'bot_username' => null,
                'message' => 'cURL: ' . $curlError,
            ];
        }

        $decoded = json_decode((string) $response, true);
        if (is_array($decoded) && !empty($decoded['ok'])) {
            $username = isset($decoded['result']['username']) ? $decoded['result']['username'] : null;
            return [
                'status' => 'online',
                'label' => 'Online',
                'latency_ms' => $latencyMs,
                'bot_username' => $username,
                'message' => 'Telegram API reachable',
            ];
        }

        $desc = is_array($decoded) && isset($decoded['description'])
            ? $decoded['description']
            : 'Invalid Telegram response';

        return [
            'status' => 'offline',
            'label' => 'Offline',
            'latency_ms' => $latencyMs,
            'bot_username' => null,
            'message' => $desc,
        ];
    }

    public function get_last_errors($limit = 20)
    {
        $limit = max(1, (int) $limit);

        if ($this->db->table_exists('system_logs')) {
            $rows = $this->db
                ->select('log_time, level, module, action, message')
                ->from('system_logs')
                ->where_in('level', ['error', 'critical'])
                ->order_by('log_time', 'desc')
                ->limit($limit)
                ->get()
                ->result_array();

            return [
                'source' => 'system_logs',
                'rows' => $rows,
            ];
        }

        return [
            'source' => 'file:application/logs/*',
            'rows' => $this->scan_error_lines_from_log_files($limit),
        ];
    }

    public function get_system_metrics()
    {
        return [
            'open_wo' => $this->count_if_table_exists('work_orders', ['status' => 'open']),
            'overdue_invoices' => $this->count_if_table_exists('invoices', ['status' => 'overdue']),
            'pending_telegram_queue' => $this->count_if_table_exists('telegram_queue', ['status' => 'pending']),
            'failed_telegram_queue' => $this->count_if_table_exists('telegram_queue', ['status' => 'failed']),
        ];
    }

    public function record_cron_run($jobName, $status, $startedAt, $finishedAt, $message = '', array $meta = [])
    {
        if (!$this->db->table_exists('cron_run_logs')) {
            return false;
        }

        $durationMs = (int) round((strtotime($finishedAt) - strtotime($startedAt)) * 1000);
        if ($durationMs < 0) {
            $durationMs = null;
        }

        $payload = [
            'job_name' => (string) $jobName,
            'status' => (string) $status,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'message' => (string) $message,
            'meta_json' => !empty($meta) ? json_encode($meta) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        return (bool) $this->db->insert('cron_run_logs', $payload);
    }

    private function get_cron_log_tail($limit)
    {
        $path = APPPATH . 'logs/cron.log';
        if (!is_file($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $slice = array_slice($lines, -$limit);
        $rows = [];
        foreach (array_reverse($slice) as $line) {
            $row = [
                'job_name' => 'cron',
                'status' => $this->guess_status_from_line($line),
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => null,
                'message' => $line,
            ];

            if (preg_match('/^\\[(.*?)\\]/', $line, $m)) {
                $row['started_at'] = $m[1];
            }
            if (preg_match('/(invoice_cron\\s+\\w+|isolir_cron\\s+\\w+|telegram\\s+\\w+)/i', $line, $m)) {
                $row['job_name'] = strtolower($m[1]);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function scan_error_lines_from_log_files($limit)
    {
        $files = glob(APPPATH . 'logs/*');
        if (!$files) {
            return [];
        }

        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $rows = [];
        foreach ($files as $file) {
            if (!is_file($file) || filesize($file) <= 0) {
                continue;
            }

            $size = filesize($file);
            $readSize = min($size, 262144);
            $fp = fopen($file, 'r');
            if (!$fp) {
                continue;
            }

            fseek($fp, -$readSize, SEEK_END);
            $chunk = fread($fp, $readSize);
            fclose($fp);

            if (!is_string($chunk) || $chunk === '') {
                continue;
            }

            $lines = preg_split('/\\R/', $chunk);
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = trim($lines[$i]);
                if ($line === '') {
                    continue;
                }

                if (!$this->is_error_line($line)) {
                    continue;
                }

                $rows[] = [
                    'log_time' => $this->extract_time_from_log_line($line),
                    'level' => 'error',
                    'module' => basename($file),
                    'action' => null,
                    'message' => $line,
                ];

                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    private function count_if_table_exists($table, array $where)
    {
        try {
            if (!$this->db->table_exists($table)) {
                return 0;
            }
            $qb = $this->db->from($table);
            foreach ($where as $k => $v) {
                $qb->where($k, $v);
            }
            return (int) $qb->count_all_results();
        } catch (Exception $e) {
            log_message('error', '[System_monitoring_model::count_if_table_exists] ' . $e->getMessage());
            return 0;
        }
    }

    private function guess_status_from_line($line)
    {
        $upper = strtoupper((string) $line);
        if (strpos($upper, 'ERROR') !== false || strpos($upper, 'FAILED') !== false) {
            return 'error';
        }
        if (strpos($upper, 'DONE') !== false || strpos($upper, 'OK') !== false || strpos($upper, 'SUCCESS') !== false) {
            return 'success';
        }
        return 'info';
    }

    private function is_error_line($line)
    {
        $needle = ['ERROR', 'CRITICAL', 'FATAL', 'Exception', 'Failed'];
        foreach ($needle as $n) {
            if (stripos($line, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    private function extract_time_from_log_line($line)
    {
        if (preg_match('/\\[(.*?)\\]/', $line, $m)) {
            return $m[1];
        }
        return null;
    }
}
