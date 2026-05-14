<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Monitoring_cron extends CI_Controller
{
    private $monitoring_cfg = array();

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->model('Monitoring_model', 'monitoring_model');
        $this->load->model('System_monitoring_model', 'system_monitoring_model');
        $this->config->load('monitoring', true);
        $this->monitoring_cfg = $this->resolve_monitoring_config();
    }

    public function check_health()
    {
        $started_at = date('Y-m-d H:i:s');
        $start = microtime(true);

        if (!$this->authorize_request()) {
            return $this->output
                ->set_status_header(403)
                ->set_content_type('application/json')
                ->set_output(json_encode(array(
                    'success' => false,
                    'message' => 'Unauthorized cron call',
                )));
        }

        try {
            $routers = $this->get_active_routers();
            $runs = array();
            if (empty($routers)) {
                $runs[] = array(
                    'router_id' => 0,
                    'router_name' => 'default',
                    'result' => $this->monitoring_model->run_health_checks_and_alerts(0),
                );
            } else {
                foreach ($routers as $router) {
                    $router_id = (int) ($router['id'] ?? 0);
                    $runs[] = array(
                        'router_id' => $router_id,
                        'router_name' => (string) ($router['name'] ?? ('Router #' . $router_id)),
                        'result' => $this->monitoring_model->run_health_checks_and_alerts($router_id),
                    );
                }
            }

            $result = array(
                'multi_router' => !empty($routers),
                'total_router' => count($runs),
                'runs' => $runs,
            );
            $finished_at = date('Y-m-d H:i:s');

            if (method_exists($this->system_monitoring_model, 'record_cron_run')) {
                $this->system_monitoring_model->record_cron_run(
                    'monitoring_cron.check_health',
                    'success',
                    $started_at,
                    $finished_at,
                    'Monitoring health check sukses',
                    array(
                        'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                        'router_count' => (int) ($result['total_router'] ?? 0),
                    )
                );
            }

            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(array(
                    'success' => true,
                    'run_at' => $finished_at,
                    'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                    'result' => $result,
                )));
        } catch (Throwable $e) {
            $finished_at = date('Y-m-d H:i:s');
            log_message('error', '[MONITORING_CRON] ' . $e->getMessage());

            if (method_exists($this->system_monitoring_model, 'record_cron_run')) {
                $this->system_monitoring_model->record_cron_run(
                    'monitoring_cron.check_health',
                    'failed',
                    $started_at,
                    $finished_at,
                    $e->getMessage(),
                    array()
                );
            }

            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(array(
                    'success' => false,
                    'message' => $e->getMessage(),
                    'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                )));
        }
    }

    private function authorize_request()
    {
        if (is_cli()) {
            return true;
        }

        $token = trim((string) $this->input->get('token', true));
        $expected = $this->resolve_cron_token();
        if ($expected === '') {
            log_message('error', '[MONITORING_CRON] monitoring cron token not configured.');
            return false;
        }

        return hash_equals($expected, $token);
    }

    private function resolve_cron_token()
    {
        $expected = trim((string) $this->cfg_item('cron_token', ''));
        if ($expected !== '') {
            return $expected;
        }

        $from_section = trim((string) $this->config->item('cron_token', 'monitoring'));
        if ($from_section !== '') {
            return $from_section;
        }

        return '';
    }

    private function resolve_monitoring_config()
    {
        $cfg = (array) $this->config->item('monitoring', 'monitoring');
        if (isset($cfg['monitoring']) && is_array($cfg['monitoring'])) {
            $cfg = (array) $cfg['monitoring'];
        }

        if (empty($cfg)) {
            $top = $this->config->item('monitoring');
            if (is_array($top)) {
                if (isset($top['monitoring']) && is_array($top['monitoring'])) {
                    $cfg = (array) $top['monitoring'];
                } else {
                    $cfg = (array) $top;
                }
            }
        }

        return is_array($cfg) ? $cfg : array();
    }

    private function cfg_item($key, $default = null)
    {
        return array_key_exists($key, $this->monitoring_cfg) ? $this->monitoring_cfg[$key] : $default;
    }

    private function get_active_routers()
    {
        if (!$this->db->table_exists('routers')) {
            return array();
        }

        $fields = $this->db->list_fields('routers');
        if (!in_array('id', $fields, true)) {
            return array();
        }

        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : 'id');

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS name', false)
            ->from('routers');

        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        return $qb->order_by('id', 'ASC')->get()->result_array();
    }
}
