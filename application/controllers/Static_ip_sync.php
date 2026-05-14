<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Static_ip_sync extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->database();
        $this->load->helper(array('url', 'form'));
        $this->load->library('session');
        $this->load->model('Static_ip_sync_model', 'static_ip_sync_model');
        $this->load->model('System_monitoring_model', 'system_monitoring_model');
        $this->load->model('settings_model');
    }

    public function index()
    {
        $role = function_exists('normalizeRole')
            ? normalizeRole((string) $this->session->userdata('role'))
            : strtolower(trim((string) $this->session->userdata('role')));
        $is_superadmin = ($role === 'superadmin');
        $effective_router_id = $this->getEffectiveRouterId();
        $effective_router_id = $effective_router_id !== null ? (int) $effective_router_id : 0;

        $selected_router_id = (int) $this->input->get('router_id', true);
        if ($selected_router_id <= 0) {
            $selected_router_id = $effective_router_id;
        }

        $router_options = $this->settings_model->get_active_routers(
            $is_superadmin ? null : ($effective_router_id > 0 ? $effective_router_id : null)
        );
        if ($selected_router_id <= 0 && count($router_options) === 1) {
            $selected_router_id = (int) ($router_options[0]['id'] ?? 0);
        }

        $this->load->view('router_sync/index', array(
            'router_options' => $router_options,
            'selected_router_id' => $selected_router_id,
            'is_superadmin_user' => $is_superadmin,
            'pppoe_data_form' => $this->settings_model->get_pppoe_sync_settings(),
            'pppoe_sync_logs' => $this->settings_model->get_sync_logs(20),
            'recent_runs' => $this->get_recent_static_sync_runs(20),
            'last_result' => $this->session->flashdata('static_sync_result'),
        ));
    }

    public function run_sync()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        try {
            $router_id = (int) $this->input->post('router_id', true);
            $result = $router_id > 0
                ? $this->static_ip_sync_model->sync_static_ip_arp($router_id)
                : $this->static_ip_sync_model->sync_static_ip_arp_all();
        } catch (Throwable $e) {
            $result = array(
                'success' => false,
                'message' => $e->getMessage(),
                'stats' => array(),
            );
            log_message('error', '[STATIC_IP_SYNC_UI][run_sync] ' . $e->getMessage());
        } finally {
            $this->static_ip_sync_model->disconnect_mikrotik();
        }

        $this->set_flash_result('sync', $result);
        $redirect_url = 'router-sync';
        $router_id = (int) $this->input->post('router_id', true);
        if ($router_id > 0) {
            $redirect_url .= '?router_id=' . $router_id;
        }
        return redirect($redirect_url);
    }

    public function run_check_isolir()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        try {
            $router_id = (int) $this->input->post('router_id', true);
            $result = $router_id > 0
                ? $this->static_ip_sync_model->check_static_isolir($router_id)
                : $this->static_ip_sync_model->check_static_isolir_all();
        } catch (Throwable $e) {
            $result = array(
                'success' => false,
                'message' => $e->getMessage(),
                'stats' => array(),
            );
            log_message('error', '[STATIC_IP_SYNC_UI][run_check_isolir] ' . $e->getMessage());
        } finally {
            $this->static_ip_sync_model->disconnect_mikrotik();
        }

        $this->set_flash_result('check_isolir', $result);
        $redirect_url = 'router-sync';
        $router_id = (int) $this->input->post('router_id', true);
        if ($router_id > 0) {
            $redirect_url .= '?router_id=' . $router_id;
        }
        return redirect($redirect_url);
    }

    private function set_flash_result($action, array $result)
    {
        $payload = array(
            'action' => (string) $action,
            'success' => !empty($result['success']),
            'message' => (string) ($result['message'] ?? ''),
            'stats' => (array) ($result['stats'] ?? array()),
            'run_at' => date('Y-m-d H:i:s'),
        );

        $this->session->set_flashdata('static_sync_result', $payload);

        if (!empty($payload['success'])) {
            $this->session->set_flashdata('success', $payload['message']);
        } else {
            $this->session->set_flashdata('error', $payload['message'] !== '' ? $payload['message'] : 'Eksekusi gagal.');
        }
    }

    private function get_recent_static_sync_runs($limit = 20)
    {
        $limit = max(1, (int) $limit);
        $result = $this->system_monitoring_model->get_last_cron_runs(100);
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        $filtered = array();

        foreach ($rows as $row) {
            $job = strtolower((string) ($row['job_name'] ?? ''));
            if (strpos($job, 'static_ip_cron.sync_static_ip_arp') === false && strpos($job, 'static_ip_cron.check_static_isolir') === false) {
                continue;
            }

            $meta = array();
            $meta_json = (string) ($row['meta_json'] ?? '');
            if ($meta_json !== '') {
                $decoded = json_decode($meta_json, true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $row['meta'] = $meta;
            $filtered[] = $row;

            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }
}
