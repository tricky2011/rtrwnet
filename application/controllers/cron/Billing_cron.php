<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Billing_cron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->config->load('cron', true, true);
        $this->load->library('billing_automation_service');
        $this->load->library('jobdispatcher');
        $this->load->model('system_monitoring_model');
    }

    public function generate_invoice()
    {
        if (!$this->authorize_cron()) {
            return;
        }

        $started_at = date('Y-m-d H:i:s');
        $started = microtime(true);

        $period = trim((string) $this->input->get('period', true));
        if ($period === '') {
            $period = date('Y-m');
        }
        $run_date = trim((string) $this->input->get('date', true));
        $mode = strtolower(trim((string) $this->input->get('mode', true)));
        $async = ((string) $this->input->get('async', true) === '1');
        $router_id_raw = $this->input->get('router_id', true);
        $router_id = ($router_id_raw !== null && $router_id_raw !== '') ? (int) $router_id_raw : null;
        if ($router_id !== null && $router_id <= 0) {
            $router_id = null;
        }

        if ($mode === '') {
            $mode = 'monthly';
        }

        if ($async) {
            $payload = array(
                'mode' => $mode === 'daily' || $mode === 'rolling' ? 'daily' : 'monthly',
                'period' => $period,
                'date' => $run_date,
                'router_id' => $router_id,
            );

            $result = $this->jobdispatcher->dispatch(
                null,
                'billing_generate',
                $payload,
                0
            );
            if (!empty($result['success'])) {
                $result['success'] = true;
                $result['message'] = 'Billing job masuk queue. Job ID: ' . (int) ($result['job_id'] ?? 0);
            }
        } elseif ($mode === 'daily' || $mode === 'rolling') {
            $result = $this->billing_automation_service->generate_daily_rolling_invoices(
                $run_date !== '' ? $run_date : null,
                $router_id
            );
        } else {
            $result = $this->billing_automation_service->generate_monthly_invoices($period, $router_id);
        }

        $duration_ms = (int) ((microtime(true) - $started) * 1000);
        $status = !empty($result['success']) ? 'success' : 'error';

        $this->system_monitoring_model->record_cron_run(
            'billing_cron.generate_invoice',
            $status,
            $started_at,
            date('Y-m-d H:i:s'),
            (string) ($result['message'] ?? '-'),
            array('duration_ms' => $duration_ms, 'result' => $result)
        );

        $result['duration_ms'] = $duration_ms;
        $result['mode'] = $async ? 'async_' . $mode : $mode;
        $result['period'] = $period;

        return $this->respond($result, !empty($result['success']) ? ($async ? 202 : 200) : 422);
    }

    public function auto_suspend()
    {
        if (!$this->authorize_cron()) {
            return;
        }

        $started_at = date('Y-m-d H:i:s');
        $started = microtime(true);

        $date = trim((string) $this->input->get('date', true));
        $grace_raw = $this->input->get('grace_days', true);
        $grace_days = ($grace_raw === null || $grace_raw === '') ? 5 : (int) $grace_raw;
        if ($grace_days < 0) {
            $grace_days = 5;
        }

        $async = ((string) $this->input->get('async', true) === '1');
        if ($async) {
            $payload = array(
                'date' => $date,
                'grace_days' => $grace_days,
            );
            $result = $this->jobdispatcher->dispatch(
                null,
                'isolir_check',
                $payload,
                0
            );
            if (!empty($result['success'])) {
                $result['success'] = true;
                $result['message'] = 'Isolir job masuk queue. Job ID: ' . (int) ($result['job_id'] ?? 0);
            }
        } else {
            $result = $this->billing_automation_service->auto_suspend($date !== '' ? $date : null, $grace_days);
        }

        $duration_ms = (int) ((microtime(true) - $started) * 1000);
        $status = !empty($result['success']) ? 'success' : 'error';

        $this->system_monitoring_model->record_cron_run(
            'billing_cron.auto_suspend',
            $status,
            $started_at,
            date('Y-m-d H:i:s'),
            (string) ($result['message'] ?? '-'),
            array('duration_ms' => $duration_ms, 'result' => $result)
        );

        $result['duration_ms'] = $duration_ms;
        $result['mode'] = $async ? 'async' : 'direct';

        return $this->respond($result, !empty($result['success']) ? ($async ? 202 : 200) : 422);
    }

    private function authorize_cron()
    {
        if ($this->input->is_cli_request()) {
            return true;
        }

        $expected = $this->resolve_cron_token();
        if ($expected === '') {
            log_message('error', '[BILLING_CRON] cron token not configured.');
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

    private function respond(array $payload, $status_code = 200)
    {
        if ($this->input->is_cli_request()) {
            echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
            return;
        }

        return $this->output
            ->set_status_header((int) $status_code)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
    }
}
