<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Static_ip_cron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->config->load('cron', true, true);
        $this->load->database();
        $this->load->model('Static_ip_sync_model', 'static_ip_sync_model');
        $this->load->model('System_monitoring_model', 'system_monitoring_model');
    }

    public function sync_static_ip_arp($router_id = null)
    {
        if (!$this->authorize_cron()) {
            return;
        }

        $started_at = date('Y-m-d H:i:s');
        $started = microtime(true);

        try {
            $router_id = $this->resolve_router_id($router_id);
            $result = $router_id > 0
                ? $this->static_ip_sync_model->sync_static_ip_arp($router_id)
                : $this->static_ip_sync_model->sync_static_ip_arp_all();
            $status = !empty($result['success']) ? 'success' : 'error';
            $http_status = !empty($result['success']) ? 200 : 422;
            $message = (string) ($result['message'] ?? '-');
        } catch (Throwable $e) {
            $result = array(
                'success' => false,
                'message' => $e->getMessage(),
                'stats' => array(),
            );
            $status = 'error';
            $http_status = 500;
            $message = $e->getMessage();
            log_message('error', '[STATIC_IP_CRON][sync_static_ip_arp] ' . $e->getMessage());
        } finally {
            $this->static_ip_sync_model->disconnect_mikrotik();
        }

        $duration_ms = (int) ((microtime(true) - $started) * 1000);
        $this->record_cron_run(
            'static_ip_cron.sync_static_ip_arp',
            $status,
            $started_at,
            date('Y-m-d H:i:s'),
            $message,
            array(
                'duration_ms' => $duration_ms,
                'result' => $result,
            )
        );

        $response = array(
            'success' => !empty($result['success']),
            'message' => (string) ($result['message'] ?? ''),
            'stats' => (array) ($result['stats'] ?? array()),
            'duration_ms' => $duration_ms,
        );

        return $this->output
            ->set_status_header($http_status)
            ->set_content_type('application/json')
            ->set_output(json_encode($response));
    }

    public function check_static_isolir($router_id = null)
    {
        if (!$this->authorize_cron()) {
            return;
        }

        $started_at = date('Y-m-d H:i:s');
        $started = microtime(true);

        try {
            $router_id = $this->resolve_router_id($router_id);
            $result = $router_id > 0
                ? $this->static_ip_sync_model->check_static_isolir($router_id)
                : $this->static_ip_sync_model->check_static_isolir_all();
            $status = !empty($result['success']) ? 'success' : 'error';
            $http_status = !empty($result['success']) ? 200 : 422;
            $message = (string) ($result['message'] ?? '-');
        } catch (Throwable $e) {
            $result = array(
                'success' => false,
                'message' => $e->getMessage(),
                'stats' => array(),
            );
            $status = 'error';
            $http_status = 500;
            $message = $e->getMessage();
            log_message('error', '[STATIC_IP_CRON][check_static_isolir] ' . $e->getMessage());
        } finally {
            $this->static_ip_sync_model->disconnect_mikrotik();
        }

        $duration_ms = (int) ((microtime(true) - $started) * 1000);
        $this->record_cron_run(
            'static_ip_cron.check_static_isolir',
            $status,
            $started_at,
            date('Y-m-d H:i:s'),
            $message,
            array(
                'duration_ms' => $duration_ms,
                'result' => $result,
            )
        );

        $response = array(
            'success' => !empty($result['success']),
            'message' => (string) ($result['message'] ?? ''),
            'stats' => (array) ($result['stats'] ?? array()),
            'duration_ms' => $duration_ms,
        );

        return $this->output
            ->set_status_header($http_status)
            ->set_content_type('application/json')
            ->set_output(json_encode($response));
    }

    private function authorize_cron()
    {
        if ($this->input->is_cli_request()) {
            return true;
        }

        $expected = $this->resolve_cron_token();
        if ($expected === '') {
            log_message('error', '[STATIC_IP_CRON] cron token not configured.');
            show_error('Forbidden', 403);
            return false;
        }

        $actual = trim((string) $this->input->get('token', true));
        if ($expected !== '' && hash_equals($expected, $actual)) {
            return true;
        }

        show_error('Forbidden', 403);
        return false;
    }

    private function resolve_cron_token()
    {
        $from_env = trim((string) getenv('CRON_TOKEN'));
        if ($from_env !== '') {
            return $from_env;
        }

        $from_config = trim((string) $this->config->item('cron_token', 'cron'));
        if ($from_config !== '') {
            return $from_config;
        }

        return trim((string) config_item('cron_token'));
    }

    private function resolve_router_id($router_id = null)
    {
        if ($router_id !== null && $router_id !== '') {
            return max(0, (int) $router_id);
        }

        $raw = $this->input->get('router_id', true);
        if ($raw !== null && $raw !== '') {
            return max(0, (int) $raw);
        }

        return 0;
    }

    private function record_cron_run($job_name, $status, $started_at, $finished_at, $message, array $extra = array())
    {
        if (!method_exists($this->system_monitoring_model, 'record_cron_run')) {
            return;
        }

        $this->system_monitoring_model->record_cron_run(
            (string) $job_name,
            (string) $status,
            (string) $started_at,
            (string) $finished_at,
            (string) $message,
            $extra
        );
    }
}
