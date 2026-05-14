<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Monitoring extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->database();
        $this->load->model('Monitoring_model', 'monitoring_model');
        $this->load->library(array('router_monitoring_service', 'form_validation', 'session'));
        $this->load->helper(array('url', 'form'));

        $this->config->load('monitoring', true);
    }

    public function index()
    {
        $scope = $this->resolve_router_scope_context();
        $effective_router_id = $scope['router_id'] !== null ? (int) $scope['router_id'] : 0;
        $snapshot = $this->router_monitoring_service->get_dashboard_snapshot(
            $scope['router_id'],
            true,
            $scope['all_router_mode'],
            $scope['cache_ttl']
        );
        $refresh_seconds = (int) $this->get_monitoring_config_item('refresh_seconds', 10);
        if ($refresh_seconds <= 0) {
            $refresh_seconds = 10;
        }

        $interface_settings = array(
            'can_edit' => !$scope['all_router_mode'] && $effective_router_id > 0,
            'router_id' => $effective_router_id,
            'router_name' => '',
            'interfaces' => array(),
            'down_watchlist' => array(),
            'available_interfaces' => array(),
            'catalog_error' => '',
        );

        if ($interface_settings['can_edit']) {
            $interface_settings = array_merge(
                $interface_settings,
                $this->get_router_interface_config($effective_router_id)
            );

            $catalog = $this->monitoring_model->get_interface_candidates($effective_router_id);
            if (!empty($catalog['success'])) {
                $interface_settings['available_interfaces'] = (array) ($catalog['rows'] ?? array());
            } else {
                $interface_settings['catalog_error'] = (string) ($catalog['message'] ?? 'Gagal membaca daftar interface router.');
            }
        }

        $this->load->view('monitoring/index', array(
            'page_title' => 'System Monitoring - RTRWNet',
            'page_heading' => 'System Monitoring',
            'page_subheading' => 'Router resource, traffic, PPP online, billing health, dan alert real-time.',
            'active_menu' => 'monitoring',
            'snapshot' => $snapshot,
            'refresh_seconds' => $refresh_seconds,
            'snapshot_json' => $this->safe_json_encode($snapshot, '{}'),
            'router_scope' => $scope,
            'interface_settings' => $interface_settings,
        ));
    }

    public function snapshot_json()
    {
        $scope = $this->resolve_router_scope_context();
        $snapshot = $this->router_monitoring_service->get_dashboard_snapshot(
            $scope['router_id'],
            true,
            $scope['all_router_mode'],
            $scope['cache_ttl']
        );
        $payload = array(
            'success' => true,
            'generated_at' => date('Y-m-d H:i:s'),
            'data' => $snapshot,
            'scope' => array(
                'router_id' => $scope['router_id'],
                'all_router_mode' => $scope['all_router_mode'],
            ),
        );

        return $this->output
            ->set_content_type('application/json')
            ->set_output($this->safe_json_encode($payload, '{"success":false,"message":"Invalid monitoring payload"}'));
    }

    public function check_now()
    {
        $this->require_role(array('superadmin', 'admin'));
        $scope = $this->resolve_router_scope_context();
        $result = $this->router_monitoring_service->run_health_checks(
            $scope['router_id'],
            $scope['all_router_mode']
        );

        return $this->output
            ->set_content_type('application/json')
            ->set_output($this->safe_json_encode($result, '{"success":false,"message":"Invalid monitoring result"}'));
    }

    public function save_interface_config()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $scope = $this->resolve_router_scope_context();
        $role = normalizeRole((string) $this->session->userdata('role'));
        $effective_router_id = $scope['router_id'] !== null ? (int) $scope['router_id'] : 0;
        $posted_router_id = (int) $this->input->post('router_id', true);

        if ($role === 'superadmin') {
            $target_router_id = $posted_router_id > 0 ? $posted_router_id : $effective_router_id;
        } else {
            $target_router_id = $effective_router_id;
        }

        if ($target_router_id <= 0) {
            $this->session->set_flashdata('error', 'Pilih distribusi/router terlebih dahulu sebelum menyimpan interface monitoring.');
            redirect('monitoring');
            return;
        }

        $router = $this->db
            ->select('id, name')
            ->from('routers')
            ->where('id', $target_router_id)
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($router)) {
            $this->session->set_flashdata('error', 'Router tidak ditemukan.');
            redirect('monitoring');
            return;
        }

        $router_fields = $this->db->list_fields('routers');
        if (!in_array('monitor_interfaces', $router_fields, true) || !in_array('monitor_down_watchlist', $router_fields, true)) {
            $this->session->set_flashdata('error', 'Kolom monitoring per-router belum tersedia. Jalankan migration monitoring router scope.');
            redirect('monitoring');
            return;
        }

        $monitor_list = $this->normalize_interface_post_values(
            $this->input->post('monitor_interfaces'),
            (string) $this->input->post('monitor_interfaces_custom', true)
        );
        $down_list = $this->normalize_interface_post_values(
            $this->input->post('monitor_down_watchlist'),
            (string) $this->input->post('monitor_down_watchlist_custom', true)
        );

        $catalog = $this->monitoring_model->get_interface_candidates($target_router_id);
        if (empty($catalog['success'])) {
            $this->session->set_flashdata(
                'error',
                'Gagal validasi interface router: ' . (string) ($catalog['message'] ?? 'router unreachable')
            );
            redirect('monitoring');
            return;
        }

        $allowed_map = array();
        foreach ((array) ($catalog['rows'] ?? array()) as $row) {
            $n = strtolower(trim((string) ($row['normalized_name'] ?? $row['name'] ?? '')));
            if ($n !== '') {
                $allowed_map[$n] = true;
            }
        }

        if (!empty($monitor_list)) {
            $monitor_list = array_values(array_filter($monitor_list, function ($v) use ($allowed_map) {
                return isset($allowed_map[strtolower(trim((string) $v))]);
            }));
        }
        if (!empty($down_list)) {
            $down_list = array_values(array_filter($down_list, function ($v) use ($allowed_map) {
                return isset($allowed_map[strtolower(trim((string) $v))]);
            }));
        }

        $monitor_csv = !empty($monitor_list) ? implode(',', $monitor_list) : null;
        $down_csv = !empty($down_list) ? implode(',', $down_list) : null;

        $updated = $this->db
            ->where('id', $target_router_id)
            ->update('routers', array(
                'monitor_interfaces' => $monitor_csv,
                'monitor_down_watchlist' => $down_csv,
                'updated_at' => date('Y-m-d H:i:s'),
            ));

        if (!$updated) {
            $err = $this->db->error();
            $this->session->set_flashdata('error', 'Gagal menyimpan konfigurasi interface monitoring: ' . (string) ($err['message'] ?? 'unknown error'));
            redirect('monitoring');
            return;
        }

        $this->router_monitoring_service->purge_snapshot_cache($target_router_id, true, false);
        $this->router_monitoring_service->purge_snapshot_cache($target_router_id, false, false);
        $this->router_monitoring_service->purge_snapshot_cache(null, true, true);
        $this->router_monitoring_service->purge_snapshot_cache(null, false, true);

        $router_name = trim((string) ($router['name'] ?? 'Router #' . $target_router_id));
        $this->session->set_flashdata(
            'success',
            'Konfigurasi interface monitoring router ' . $router_name . ' berhasil disimpan.'
        );
        redirect('monitoring');
    }

    private function resolve_router_scope_context()
    {
        $router_id = $this->getEffectiveRouterId();
        $all_router_mode = $this->is_superadmin() && ($router_id === null || (int) $router_id <= 0);
        $cache_ttl = (int) $this->get_monitoring_config_item('snapshot_cache_ttl', 45);
        if ($cache_ttl < 0) {
            $cache_ttl = 0;
        }

        return array(
            'router_id' => $router_id !== null ? (int) $router_id : null,
            'all_router_mode' => $all_router_mode,
            'cache_ttl' => $cache_ttl,
        );
    }

    private function get_router_interface_config($router_id)
    {
        $router_id = (int) $router_id;
        $result = array(
            'router_id' => $router_id,
            'router_name' => '',
            'interfaces' => array(),
            'down_watchlist' => array(),
        );

        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return $result;
        }

        $fields = $this->db->list_fields('routers');
        if (!in_array('monitor_interfaces', $fields, true) || !in_array('monitor_down_watchlist', $fields, true)) {
            return $result;
        }

        $row = $this->db
            ->select('id, name, monitor_interfaces, monitor_down_watchlist')
            ->from('routers')
            ->where('id', $router_id)
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($row)) {
            return $result;
        }

        $result['router_name'] = trim((string) ($row['name'] ?? ''));
        $result['interfaces'] = $this->normalize_interface_post_values($row['monitor_interfaces'] ?? null, '');
        $result['down_watchlist'] = $this->normalize_interface_post_values($row['monitor_down_watchlist'] ?? null, '');
        return $result;
    }

    private function normalize_interface_post_values($values, $extra_values = '')
    {
        $items = array();

        if (is_string($values) && trim($values) !== '') {
            $values = preg_split('/[\r\n,;]+/', $values);
        }
        if (is_array($values)) {
            foreach ($values as $value) {
                $value = strtolower(trim((string) $value));
                if ($value === '') {
                    continue;
                }
                if (!in_array($value, $items, true)) {
                    $items[] = $value;
                }
            }
        }

        $extra_values = trim((string) $extra_values);
        if ($extra_values !== '') {
            $parts = preg_split('/[\r\n,;]+/', $extra_values);
            foreach ((array) $parts as $value) {
                $value = strtolower(trim((string) $value));
                if ($value === '') {
                    continue;
                }
                if (!in_array($value, $items, true)) {
                    $items[] = $value;
                }
            }
        }

        return $items;
    }

    private function safe_json_encode($payload, $fallback = '{}')
    {
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        if (defined('JSON_UNESCAPED_UNICODE')) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }

        $json = json_encode($payload, $flags);
        if ($json !== false && $json !== null && $json !== '') {
            return $json;
        }

        $clean = $this->sanitize_payload_for_json($payload);
        $json = json_encode($clean, $flags);
        if ($json !== false && $json !== null && $json !== '') {
            return $json;
        }

        if (function_exists('json_last_error_msg')) {
            log_message('error', '[MONITORING] JSON encode failed: ' . json_last_error_msg());
        } else {
            log_message('error', '[MONITORING] JSON encode failed.');
        }
        return (string) $fallback;
    }

    private function sanitize_payload_for_json($payload)
    {
        if (is_array($payload)) {
            $clean = array();
            foreach ($payload as $key => $value) {
                $clean[$key] = $this->sanitize_payload_for_json($value);
            }
            return $clean;
        }

        if (is_object($payload)) {
            $clean = new stdClass();
            foreach (get_object_vars($payload) as $key => $value) {
                $clean->$key = $this->sanitize_payload_for_json($value);
            }
            return $clean;
        }

        if (is_float($payload)) {
            if (is_nan($payload) || is_infinite($payload)) {
                return 0;
            }
            return $payload;
        }

        if (is_string($payload)) {
            if (preg_match('//u', $payload)) {
                return $payload;
            }

            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $payload);
                if ($converted !== false) {
                    return $converted;
                }
            }

            return utf8_encode($payload);
        }

        return $payload;
    }

    private function get_monitoring_config_item($key, $default = null)
    {
        $section = $this->config->item('monitoring', 'monitoring');
        if (is_array($section)) {
            if (isset($section[$key])) {
                return $section[$key];
            }
            if (isset($section['monitoring']) && is_array($section['monitoring']) && array_key_exists($key, $section['monitoring'])) {
                return $section['monitoring'][$key];
            }
        }

        $top = $this->config->item('monitoring');
        if (is_array($top)) {
            if (array_key_exists($key, $top)) {
                return $top[$key];
            }
            if (isset($top['monitoring']) && is_array($top['monitoring']) && array_key_exists($key, $top['monitoring'])) {
                return $top['monitoring'][$key];
            }
        }

        return $default;
    }
}
