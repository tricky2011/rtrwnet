<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ip_pools extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->database();
        $this->load->model('ip_pool_model');
        $this->load->model('settings_model');
        $this->load->library(array('form_validation', 'session', 'mikrotik_api'));
        $this->load->helper(array('url', 'form'));
    }

    public function index()
    {
        $keyword = trim((string) $this->input->get('search', true));
        $pager = $this->init_pagination(
            'ip_pools',
            $this->ip_pool_model->count_filtered($keyword),
            20,
            3
        );

        $this->load->view('ip_pools/list', array(
            'pools' => $this->ip_pool_model->get_paginated($pager['per_page'], $pager['offset'], $keyword),
            'pagination' => $pager['links'],
            'search' => $keyword,
            'total_rows' => $pager['total_rows'],
        ));
    }

    public function create()
    {
        return $this->render_form('create');
    }

    public function sync_from_router()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->db->table_exists('ip_pools')) {
            $this->session->set_flashdata('error', 'Tabel ip_pools tidak ditemukan.');
            return redirect('ip-pools');
        }

        $router_context = $this->resolve_target_router_context();
        if (empty($router_context['success'])) {
            $this->session->set_flashdata('error', (string) ($router_context['message'] ?? 'Router tidak valid.'));
            return redirect('ip-pools');
        }
        $router_id = (int) ($router_context['router_id'] ?? 0);
        $router_name = (string) ($router_context['router_name'] ?? ('Router #' . $router_id));

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $api = null;
        $allowed_pool_map = $this->allowed_pool_name_map();

        try {
            $this->load->helper('tenant');
            $connect = connectRouter($router_id);
            if (empty($connect['success']) || empty($connect['api'])) {
                $this->session->set_flashdata('error', 'Gagal konek router `' . $router_name . '`: ' . (string) ($connect['message'] ?? 'unknown'));
                return redirect('ip-pools');
            }
            $api = $connect['api'];
            $pools = $api->comm('/ip/pool/print');

            if (!is_array($pools)) {
                $pools = array();
            }

            if (empty($pools)) {
                $test_identity = $api->comm('/system/identity/print');
                if (!is_array($test_identity) || empty($test_identity)) {
                    $this->session->set_flashdata('error', 'Koneksi API bermasalah: /system/identity/print kosong.');
                    return redirect('ip-pools');
                }

                $this->session->set_flashdata('error', 'API terkoneksi, tetapi /ip/pool/print kosong. Cek permission user API untuk menu IP Pool.');
                return redirect('ip-pools');
            }

            $secret_ips = $this->fetch_ppp_secret_remote_addresses($api);
            $fields = $this->db->list_fields('ip_pools');
            $now = date('Y-m-d H:i:s');

            foreach ($pools as $row) {
                if (!is_array($row)) {
                    $skipped++;
                    continue;
                }

                $pool_name = trim((string) $this->get_mikrotik_row_value($row, 'name'));
                if ($pool_name === '') {
                    $skipped++;
                    continue;
                }
                if (!isset($allowed_pool_map[strtolower($pool_name)])) {
                    $skipped++;
                    continue;
                }

                $ranges = trim((string) $this->get_mikrotik_row_value($row, 'ranges'));
                $parsed_range = $this->parse_pool_ranges($ranges);
                if (empty($parsed_range['valid'])) {
                    $skipped++;
                    continue;
                }

                $range_start = $parsed_range['start'];
                $range_end = $parsed_range['end'];
                $total_ips = $this->calculate_total_ips($range_start, $range_end);
                if ($total_ips <= 0) {
                    $skipped++;
                    continue;
                }

                $used_ips = $this->count_used_ips_in_range($secret_ips, $range_start, $range_end);
                $usage_percent = $this->calculate_usage_percent($used_ips, $total_ips);

                $payload = array(
                    'pool_name' => $pool_name,
                    'range_start' => $range_start,
                    'range_end' => $range_end,
                    'updated_at' => $now,
                    'total_ips' => $total_ips,
                    'used_ips' => $used_ips,
                    'usage_percent' => $usage_percent,
                );
                if ($this->table_has_column('ip_pools', 'router_id')) {
                    $payload['router_id'] = $router_id;
                }

                $payload = $this->filter_payload_by_fields($payload, $fields);
                $existing_qb = $this->db
                    ->from('ip_pools')
                    ->where('pool_name', $pool_name);
                if ($this->table_has_column('ip_pools', 'router_id')) {
                    $existing_qb->where('router_id', $router_id);
                }
                $existing = $existing_qb->limit(1)->get()->row_array();

                if ($existing) {
                    $ok = $this->db
                        ->where('id', (int) $existing['id'])
                        ->update('ip_pools', $payload);

                    if ($ok) {
                        $this->sync_pool_usage_to_ppp_profiles($router_id, $pool_name, $range_start, $range_end, $total_ips, $used_ips, $usage_percent);
                        $updated++;
                    } else {
                        $failed++;
                        log_message('error', '[IP_POOL_SYNC] Update gagal `' . $pool_name . '`: ' . json_encode($this->db->error()));
                    }
                    continue;
                }

                if (in_array('created_at', $fields, true)) {
                    $payload['created_at'] = $now;
                }

                $ok = $this->db->insert('ip_pools', $payload);
                if ($ok) {
                    $this->sync_pool_usage_to_ppp_profiles($router_id, $pool_name, $range_start, $range_end, $total_ips, $used_ips, $usage_percent);
                    $inserted++;
                } else {
                    $failed++;
                    log_message('error', '[IP_POOL_SYNC] Insert gagal `' . $pool_name . '`: ' . json_encode($this->db->error()));
                }
            }

            $message = 'IP Pools synced successfully [' . $router_name . ']. Inserted: ' . $inserted
                . ', Updated: ' . $updated
                . ', Skipped: ' . $skipped
                . ', Failed: ' . $failed;
            $this->session->set_flashdata('success', $message);
        } catch (Throwable $e) {
            log_message('error', '[IP_POOL_SYNC] Gagal sync IP Pool: ' . $e->getMessage());
            $this->session->set_flashdata('error', 'Gagal sync IP Pool dari MikroTik: ' . $e->getMessage());
        } finally {
            if (is_object($api) && method_exists($api, 'disconnect')) {
                $api->disconnect();
            }
        }

        return redirect('ip-pools');
    }

    public function refresh_usage()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->db->table_exists('ip_pools')) {
            $this->session->set_flashdata('error', 'Tabel ip_pools tidak ditemukan.');
            return redirect('ip-pools');
        }

        $router_context = $this->resolve_target_router_context();
        if (empty($router_context['success'])) {
            $this->session->set_flashdata('error', (string) ($router_context['message'] ?? 'Router tidak valid.'));
            return redirect('ip-pools');
        }
        $router_id = (int) ($router_context['router_id'] ?? 0);
        $router_name = (string) ($router_context['router_name'] ?? ('Router #' . $router_id));

        $fields = $this->db->list_fields('ip_pools');
        if (!in_array('range_start', $fields, true) || !in_array('range_end', $fields, true)) {
            $this->session->set_flashdata('error', 'Kolom range_start/range_end pada ip_pools belum lengkap.');
            return redirect('ip-pools');
        }

        $updated = 0;
        $failed = 0;
        $api = null;
        $allowed_pool_map = $this->allowed_pool_name_map();

        try {
            $this->load->helper('tenant');
            $connect = connectRouter($router_id);
            if (empty($connect['success']) || empty($connect['api'])) {
                $this->session->set_flashdata('error', 'Gagal konek router `' . $router_name . '`: ' . (string) ($connect['message'] ?? 'unknown'));
                return redirect('ip-pools');
            }
            $api = $connect['api'];
            $secret_ips = $this->fetch_ppp_secret_remote_addresses($api);

            $rows_qb = $this->db->from('ip_pools');
            if ($this->table_has_column('ip_pools', 'router_id')) {
                $rows_qb->where('router_id', $router_id);
            }
            $rows = $rows_qb->get()->result_array();
            foreach ($rows as $row) {
                $pool_name = trim((string) ($row['pool_name'] ?? ''));
                if ($pool_name === '' || !isset($allowed_pool_map[strtolower($pool_name)])) {
                    continue;
                }

                $range_start = trim((string) ($row['range_start'] ?? ''));
                $range_end = trim((string) ($row['range_end'] ?? ''));
                if ($range_start === '' || $range_end === '') {
                    continue;
                }

                $total_ips = $this->calculate_total_ips($range_start, $range_end);
                if ($total_ips <= 0) {
                    continue;
                }

                $used_ips = $this->count_used_ips_in_range($secret_ips, $range_start, $range_end);
                $usage_percent = $this->calculate_usage_percent($used_ips, $total_ips);

                $payload = $this->filter_payload_by_fields(array(
                    'total_ips' => $total_ips,
                    'used_ips' => $used_ips,
                    'usage_percent' => $usage_percent,
                    'updated_at' => date('Y-m-d H:i:s'),
                ), $fields);

                if (empty($payload)) {
                    continue;
                }

                $ok = $this->db
                    ->where('id', (int) ($row['id'] ?? 0))
                    ->update('ip_pools', $payload);
                if ($ok) {
                    $this->sync_pool_usage_to_ppp_profiles($router_id, $pool_name, $range_start, $range_end, $total_ips, $used_ips, $usage_percent);
                    $updated++;
                } else {
                    $failed++;
                    log_message('error', '[IP_POOL_USAGE] Update usage gagal id=' . (int) ($row['id'] ?? 0) . ': ' . json_encode($this->db->error()));
                }
            }

            $this->session->set_flashdata('success', 'IP Pool usage refreshed [' . $router_name . ']. Updated: ' . $updated . ', Failed: ' . $failed);
        } catch (Throwable $e) {
            log_message('error', '[IP_POOL_USAGE] Refresh usage gagal: ' . $e->getMessage());
            $this->session->set_flashdata('error', 'Gagal refresh IP Pool usage: ' . $e->getMessage());
        } finally {
            if (is_object($api) && method_exists($api, 'disconnect')) {
                $api->disconnect();
            }
        }

        return redirect('ip-pools');
    }

    public function store()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $this->set_validation_rules();
        if ($this->form_validation->run() === false) {
            return $this->render_form('create');
        }

        $payload = $this->collect_payload();
        if ($this->ip_pool_model->exists_pool_name($payload['pool_name'])) {
            $this->session->set_flashdata('error', 'Nama IP Pool sudah digunakan.');
            return redirect('ip-pools/create');
        }

        $this->db->trans_begin();

        $id = $this->ip_pool_model->insert($payload);
        if (!$id) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Gagal menyimpan IP Pool ke database.');
            return redirect('ip-pools/create');
        }

        $sync = $this->sync_create_to_mikrotik($payload);
        if (empty($sync['success'])) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', $sync['message']);
            return redirect('ip-pools/create');
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Transaction database gagal saat create IP Pool.');
            return redirect('ip-pools/create');
        }

        $this->db->trans_commit();
        $this->session->set_flashdata('success', 'IP Pool berhasil dibuat dan tersinkron ke MikroTik.');
        return redirect('ip-pools');
    }

    public function edit($id = null)
    {
        $id = (int) $id;
        $pool = $this->ip_pool_model->get_by_id($id);
        if (!$pool) {
            $this->session->set_flashdata('error', 'IP Pool tidak ditemukan.');
            return redirect('ip-pools');
        }

        return $this->render_form('edit', $pool);
    }

    public function update($id = null)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $existing = $this->ip_pool_model->get_by_id($id);
        if (!$existing) {
            $this->session->set_flashdata('error', 'IP Pool tidak ditemukan.');
            return redirect('ip-pools');
        }

        $this->set_validation_rules();
        if ($this->form_validation->run() === false) {
            return $this->render_form('edit', $existing);
        }

        $payload = $this->collect_payload();
        if ($this->ip_pool_model->exists_pool_name($payload['pool_name'], $id)) {
            $this->session->set_flashdata('error', 'Nama IP Pool sudah digunakan.');
            return redirect('ip-pools/edit/' . $id);
        }

        $this->db->trans_begin();

        $ok = $this->ip_pool_model->update($id, $payload);
        if (!$ok) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Gagal update IP Pool di database.');
            return redirect('ip-pools/edit/' . $id);
        }

        if ($this->db->table_exists('ppp_profiles') && (string) $existing['pool_name'] !== (string) $payload['pool_name']) {
            $this->db
                ->where('remote_address_pool', (string) $existing['pool_name'])
                ->update('ppp_profiles', array(
                    'remote_address_pool' => (string) $payload['pool_name'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ));
        }

        $sync = $this->sync_update_to_mikrotik((string) $existing['pool_name'], $payload);
        if (empty($sync['success'])) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', $sync['message']);
            return redirect('ip-pools/edit/' . $id);
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Transaction database gagal saat update IP Pool.');
            return redirect('ip-pools/edit/' . $id);
        }

        $this->db->trans_commit();
        $this->session->set_flashdata('success', 'IP Pool berhasil diupdate dan tersinkron ke MikroTik.');
        return redirect('ip-pools');
    }

    public function delete($id = null)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $existing = $this->ip_pool_model->get_by_id($id);
        if (!$existing) {
            $this->session->set_flashdata('error', 'IP Pool tidak ditemukan.');
            return redirect('ip-pools');
        }

        if ($this->db->table_exists('ppp_profiles')) {
            $used = (int) $this->db
                ->from('ppp_profiles')
                ->where('remote_address_pool', (string) $existing['pool_name'])
                ->count_all_results();
            if ($used > 0) {
                $this->session->set_flashdata('error', 'IP Pool masih dipakai PPP Profile dan tidak bisa dihapus.');
                return redirect('ip-pools');
            }
        }

        $role = (string) $this->session->userdata('role');
        if ($role !== 'superadmin') {
            $ok = $this->ip_pool_model->soft_delete($id, (int) $this->session->userdata('user_id'));
            if (!$ok) {
                $this->session->set_flashdata('error', 'Gagal hapus IP Pool.');
                return redirect('ip-pools');
            }

            $this->session->set_flashdata('success', 'IP Pool berhasil dihapus.');
            return redirect('ip-pools');
        }

        $this->db->trans_begin();

        $sync = $this->sync_delete_from_mikrotik((string) $existing['pool_name']);
        if (empty($sync['success'])) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', $sync['message']);
            return redirect('ip-pools');
        }

        $ok = $this->ip_pool_model->delete($id);
        if (!$ok) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Gagal hapus IP Pool dari database.');
            return redirect('ip-pools');
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Transaction database gagal saat hapus IP Pool.');
            return redirect('ip-pools');
        }

        $this->db->trans_commit();
        $this->session->set_flashdata('success', 'IP Pool berhasil dihapus.');
        return redirect('ip-pools');
    }

    private function set_validation_rules()
    {
        $this->form_validation->set_rules('pool_name', 'Pool Name', 'trim|required|max_length[100]');
        $this->form_validation->set_rules('range_start', 'Range Start', 'trim|required|max_length[64]');
        $this->form_validation->set_rules('range_end', 'Range End', 'trim|required|max_length[64]');
        $this->form_validation->set_rules('router_name', 'Router Name', 'trim|max_length[100]');
    }

    private function collect_payload()
    {
        $router_name = trim((string) $this->input->post('router_name', true));
        if ($router_name === '') {
            $router_name = trim((string) $this->input->post('router_id', true));
        }

        return array(
            'pool_name' => trim((string) $this->input->post('pool_name', true)),
            'range_start' => trim((string) $this->input->post('range_start', true)),
            'range_end' => trim((string) $this->input->post('range_end', true)),
            'router_name' => $router_name,
        );
    }

    private function render_form($mode, $pool = null)
    {
        $this->load->view('ip_pools/form', array(
            'mode' => $mode,
            'pool' => $pool,
        ));
    }

    private function sync_create_to_mikrotik(array $payload)
    {
        $mk = $this->settings_model->get_mikrotik_settings();
        if (empty($mk['host']) || empty($mk['username']) || empty($mk['password'])) {
            return array('success' => false, 'message' => 'Konfigurasi MikroTik belum lengkap. Isi di menu Settings terlebih dahulu.');
        }

        $this->mikrotik_api->configure($mk);
        return $this->mikrotik_api->add_ip_pool($payload['pool_name'], $payload['range_start'], $payload['range_end']);
    }

    private function sync_update_to_mikrotik($old_name, array $payload)
    {
        $mk = $this->settings_model->get_mikrotik_settings();
        if (empty($mk['host']) || empty($mk['username']) || empty($mk['password'])) {
            return array('success' => false, 'message' => 'Konfigurasi MikroTik belum lengkap. Isi di menu Settings terlebih dahulu.');
        }

        $this->mikrotik_api->configure($mk);
        return $this->mikrotik_api->update_ip_pool($old_name, $payload['pool_name'], $payload['range_start'], $payload['range_end']);
    }

    private function sync_delete_from_mikrotik($name)
    {
        $mk = $this->settings_model->get_mikrotik_settings();
        if (empty($mk['host']) || empty($mk['username']) || empty($mk['password'])) {
            return array('success' => false, 'message' => 'Konfigurasi MikroTik belum lengkap. Isi di menu Settings terlebih dahulu.');
        }

        $this->mikrotik_api->configure($mk);
        return $this->mikrotik_api->remove_ip_pool($name);
    }

    private function get_mikrotik_row_value(array $row, $key, $default = '')
    {
        $key = (string) $key;
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }

        $alt = '=' . $key;
        if (array_key_exists($alt, $row)) {
            return $row[$alt];
        }

        return $default;
    }

    private function parse_pool_ranges($ranges)
    {
        $ranges = trim((string) $ranges);
        if ($ranges === '') {
            return array('valid' => false, 'start' => '', 'end' => '');
        }

        $first_segment = explode(',', $ranges)[0];
        $pair = explode('-', trim($first_segment));
        if (count($pair) !== 2) {
            return array('valid' => false, 'start' => '', 'end' => '');
        }

        $start = trim((string) $pair[0]);
        $end = trim((string) $pair[1]);
        if (filter_var($start, FILTER_VALIDATE_IP) === false || filter_var($end, FILTER_VALIDATE_IP) === false) {
            return array('valid' => false, 'start' => '', 'end' => '');
        }

        if (ip2long($end) < ip2long($start)) {
            return array('valid' => false, 'start' => '', 'end' => '');
        }

        return array('valid' => true, 'start' => $start, 'end' => $end);
    }

    private function calculate_total_ips($start, $end)
    {
        $start_long = ip2long((string) $start);
        $end_long = ip2long((string) $end);
        if ($start_long === false || $end_long === false || $end_long < $start_long) {
            return 0;
        }

        return (int) (($end_long - $start_long) + 1);
    }

    private function calculate_usage_percent($used_ips, $total_ips)
    {
        $total_ips = (int) $total_ips;
        $used_ips = (int) $used_ips;
        if ($total_ips <= 0) {
            return 0.00;
        }

        return round(($used_ips / $total_ips) * 100, 2);
    }

    private function fetch_ppp_secret_remote_addresses($api = null)
    {
        $remote_ips = array();
        try {
            if (is_object($api) && method_exists($api, 'comm')) {
                $rows = $api->comm('/ppp/secret/print');
            } else {
                $this->mikrotik_api->connect();
                $rows = $this->mikrotik_api->command('/ppp/secret/print');
            }

            if (!is_array($rows)) {
                return $remote_ips;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $address = trim((string) $this->get_mikrotik_row_value($row, 'remote-address'));
                if ($address === '') {
                    continue;
                }

                $normalized = $this->normalize_ipv4($address);
                if ($normalized !== '') {
                    $remote_ips[$normalized] = true;
                }
            }
        } catch (Throwable $e) {
            log_message('error', '[IP_POOL_USAGE] Gagal ambil /ppp/secret/print remote-address: ' . $e->getMessage());
        } finally {
            if (!is_object($api)) {
                $this->mikrotik_api->disconnect();
            }
        }

        return array_keys($remote_ips);
    }

    private function count_used_ips_in_range(array $active_ips, $range_start, $range_end)
    {
        $count = 0;
        foreach ($active_ips as $ip) {
            if ($this->ip_in_range((string) $ip, (string) $range_start, (string) $range_end)) {
                $count++;
            }
        }
        return $count;
    }

    private function ip_in_range($ip, $range_start, $range_end)
    {
        $ip_long = ip2long((string) $ip);
        $start_long = ip2long((string) $range_start);
        $end_long = ip2long((string) $range_end);

        if ($ip_long === false || $start_long === false || $end_long === false) {
            return false;
        }

        return $ip_long >= $start_long && $ip_long <= $end_long;
    }

    private function normalize_ipv4($ip)
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '';
        }

        $clean = explode('/', $ip)[0];
        return filter_var($clean, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $clean : '';
    }

    private function filter_payload_by_fields(array $payload, array $fields)
    {
        $filtered = array();
        foreach ($payload as $key => $value) {
            if (in_array($key, $fields, true)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    private function allowed_pool_name_map()
    {
        $allowed = array(
            'pool-10M',
            'pool-20M',
            'pool-30M',
            'pool-50M',
            'pool-5M',
            'pool-7M',
            'pool_isolir',
        );

        $map = array();
        foreach ($allowed as $name) {
            $map[strtolower($name)] = true;
        }
        return $map;
    }

    private function sync_pool_usage_to_ppp_profiles($router_id, $pool_name, $range_start, $range_end, $total_ips, $used_ips, $usage_percent)
    {
        if (!$this->db->table_exists('ppp_profiles')) {
            return;
        }

        $fields = $this->db->list_fields('ppp_profiles');
        $payload = array(
            'ip_pool_name' => (string) $pool_name,
            'ip_pool_range' => (string) $range_start . '-' . (string) $range_end,
            'ip_total' => (int) $total_ips,
            'ip_used' => (int) $used_ips,
            'ip_usage_percent' => (float) $usage_percent,
            'updated_at' => date('Y-m-d H:i:s'),
        );
        $payload = $this->filter_payload_by_fields($payload, $fields);
        if (empty($payload)) {
            return;
        }

        $this->db->where('remote_address_pool', (string) $pool_name);
        if ($this->table_has_column('ppp_profiles', 'router_id')) {
            $this->db->where('router_id', (int) $router_id);
        }
        $this->db->update('ppp_profiles', $payload);
    }

    private function connect_mikweb_api(array $mk)
    {
        require_once APPPATH . 'third_party/RouterOS/routeros_api.class.php';

        $api = new RouterosAPI();
        $api->port = !empty($mk['api_port']) ? (int) $mk['api_port'] : 8728;
        $api->ssl = !empty($mk['use_ssl']);
        $api->timeout = 6;
        $api->attempts = 1;
        $api->delay = 0;
        $api->debug = false;

        $connected = $api->connect(
            (string) $mk['host'],
            (string) $mk['username'],
            (string) $mk['password']
        );
        if (!$connected) {
            $error_no = isset($api->error_no) ? $api->error_no : '';
            $error_str = isset($api->error_str) ? $api->error_str : 'unknown error';
            throw new RuntimeException('Koneksi MikroTik gagal. [' . $error_no . '] ' . $error_str);
        }

        return $api;
    }

    private function resolve_target_router_context()
    {
        $effective = $this->getEffectiveRouterId();
        $effective = $effective !== null ? (int) $effective : 0;
        if ($effective > 0) {
            $name = (string) $this->settings_model->get_router_name_by_id($effective);
            return array(
                'success' => true,
                'router_id' => $effective,
                'router_name' => $name !== '' ? $name : ('Router #' . $effective),
            );
        }

        $role = function_exists('normalizeRole')
            ? normalizeRole((string) $this->session->userdata('role'))
            : strtolower(trim((string) $this->session->userdata('role')));
        if ($role !== 'superadmin') {
            return array('success' => false, 'message' => 'Akun Anda belum memiliki router scope.');
        }

        $routers = $this->settings_model->get_active_routers();
        if (count($routers) === 1) {
            $rid = (int) ($routers[0]['id'] ?? 0);
            if ($rid > 0) {
                return array(
                    'success' => true,
                    'router_id' => $rid,
                    'router_name' => (string) ($routers[0]['name'] ?? ('Router #' . $rid)),
                );
            }
        }

        return array('success' => false, 'message' => 'Pilih distribusi/router aktif terlebih dahulu sebelum sinkronisasi.');
    }

    private function table_has_column($table, $column)
    {
        if (!$this->db->table_exists((string) $table)) {
            return false;
        }
        return in_array((string) $column, $this->db->list_fields((string) $table), true);
    }
}
