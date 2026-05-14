<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Monitoring_model extends CI_Model
{
    private $cfg = array();
    private $active_router_id = 0;
    private $router_monitoring_settings_cache = array();

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->helper('tenant');
        $this->load->model('settings_model');
        $this->load->library('mikrotik_api');
        $this->load->library('telegram_service');

        $this->config->load('monitoring', true);
        $this->cfg = $this->resolve_monitoring_config();
    }

    public function get_dashboard_snapshot($include_logs = true, $router_id = 0)
    {
        $this->configure_mikrotik((int) $router_id);

        $router = $this->get_router_resource();
        $interfaces = $this->get_interface_traffic();
        $ppp = $this->get_ppp_active();
        $billing = $this->get_customer_status_monitoring();
        $network = $this->get_network_health();
        $logs = $include_logs ? $this->get_system_logs() : array();

        $summary = $this->build_summary_cards($router, $ppp, $billing);

        $this->mikrotik_api->disconnect();

        return array(
            'generated_at' => date('Y-m-d H:i:s'),
            'router' => $router,
            'interfaces' => $interfaces,
            'ppp_active' => $ppp,
            'billing' => $billing,
            'network' => $network,
            'system_logs' => $logs,
            'summary' => $summary,
            'thresholds' => array(
                'cpu_alert_percent' => (int) $this->cfg_item('alert_cpu_threshold', 80),
                'gateway_rto_seconds' => (int) $this->cfg_item('alert_gateway_rto_seconds', 300),
            ),
        );
    }

    public function run_health_checks_and_alerts($router_id = 0)
    {
        $this->configure_mikrotik((int) $router_id);

        $snapshot = $this->get_dashboard_snapshot(false, (int) $router_id);
        $alerts = array();

        $cpu_threshold = (int) $this->cfg_item('alert_cpu_threshold', 80);
        $rto_seconds = (int) $this->cfg_item('alert_gateway_rto_seconds', 300);
        $cooldown = (int) $this->cfg_item('alert_cooldown_seconds', 300);

        $cpu_load = (int) ($snapshot['router']['cpu_load_percent'] ?? 0);
        $alerts[] = $this->process_alert_state(
            'cpu_high',
            $cpu_load > $cpu_threshold,
            'CPU MikroTik tinggi: ' . $cpu_load . '%',
            array('cpu_load_percent' => $cpu_load, 'threshold' => $cpu_threshold),
            $cooldown,
            0
        );

        $down_interfaces = array();
        $down_watchlist = $this->get_interface_down_watchlist();
        foreach ((array) ($snapshot['interfaces']['rows'] ?? array()) as $iface) {
            $iface_name = strtolower(trim((string) ($iface['name'] ?? '')));
            if (!empty($down_watchlist) && !in_array($iface_name, $down_watchlist, true)) {
                continue;
            }

            $is_down = strtolower((string) ($iface['status'] ?? 'down')) === 'down';
            $is_disabled = !empty($iface['disabled']);
            if ($is_down && !$is_disabled) {
                $down_interfaces[] = (string) ($iface['name'] ?? '-');
            }
        }

        $alerts[] = $this->process_alert_state(
            'interface_down',
            !empty($down_interfaces),
            'Interface down: ' . implode(', ', $down_interfaces),
            array('interfaces' => $down_interfaces),
            $cooldown,
            0
        );

        $gateway = (array) ($snapshot['network']['gateway'] ?? array());
        $gateway_down = strtolower((string) ($gateway['status'] ?? 'down')) !== 'up';
        $gateway_msg = 'Gateway RTO: ' . (string) ($gateway['target'] ?? '-') . ' (status ' . strtoupper((string) ($gateway['status'] ?? 'down')) . ')';
        $alerts[] = $this->process_alert_state(
            'gateway_rto',
            $gateway_down,
            $gateway_msg,
            $gateway,
            $cooldown,
            $rto_seconds
        );

        $sync = $this->sync_isolir_customers_to_mikrotik();

        $this->mikrotik_api->disconnect();

        $compact_snapshot = $snapshot;
        if (!empty($compact_snapshot['ppp_active']['rows'])) {
            $compact_snapshot['ppp_active']['rows'] = array_slice($compact_snapshot['ppp_active']['rows'], 0, 20);
        }
        if (!empty($compact_snapshot['system_logs']['rows'])) {
            $compact_snapshot['system_logs']['rows'] = array_slice($compact_snapshot['system_logs']['rows'], 0, 20);
        }

        return array(
            'success' => true,
            'generated_at' => date('Y-m-d H:i:s'),
            'snapshot' => $compact_snapshot,
            'alerts' => $alerts,
            'isolir_sync' => $sync,
        );
    }

    public function get_interface_candidates($router_id = 0)
    {
        $router_id = (int) $router_id;
        $this->active_router_id = $router_id > 0 ? $router_id : 0;
        if ($router_id > 0) {
            $router_cfg = $this->resolve_router_config($router_id);
            if (empty($router_cfg['success']) || empty($router_cfg['config'])) {
                return array(
                    'success' => false,
                    'message' => 'Konfigurasi router belum lengkap atau router tidak aktif.',
                    'rows' => array(),
                );
            }
            $this->mikrotik_api->configure((array) $router_cfg['config']);
        } else {
            $this->configure_mikrotik($router_id);
        }

        $result = $this->mikrotik_api->command_safe('/interface/print');
        $this->mikrotik_api->disconnect();

        if (empty($result['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($result['error'] ?? 'Gagal membaca daftar interface router'),
                'rows' => array(),
            );
        }

        $rows = array();
        foreach ((array) ($result['data'] ?? array()) as $iface) {
            $iface = (array) $iface;
            $name = trim((string) ($iface['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $type = strtolower(trim((string) ($iface['type'] ?? '')));
            if (!$this->is_allowed_monitor_interface_type($type)) {
                continue;
            }

            $rows[] = array(
                'name' => $name,
                'normalized_name' => strtolower($name),
                'type' => (string) ($iface['type'] ?? '-'),
                'running' => $this->to_bool($iface['running'] ?? false),
                'disabled' => $this->to_bool($iface['disabled'] ?? false),
            );
        }

        usort($rows, static function ($a, $b) {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return array(
            'success' => true,
            'message' => 'OK',
            'rows' => $rows,
        );
    }

    public function sync_isolir_customers_to_mikrotik($limit = null)
    {
        if (!$this->db->table_exists('customers')) {
            return array(
                'success' => false,
                'message' => 'Tabel customers tidak ditemukan.',
                'total_target' => 0,
                'total_disabled' => 0,
                'total_already_disabled' => 0,
                'total_failed' => 0,
            );
        }

        $username_col = $this->resolve_customer_username_column();
        if ($username_col === '') {
            return array(
                'success' => false,
                'message' => 'Kolom username PPP tidak ditemukan pada customers.',
                'total_target' => 0,
                'total_disabled' => 0,
                'total_already_disabled' => 0,
                'total_failed' => 0,
            );
        }

        $limit = $limit === null
            ? (int) $this->cfg_item('isolir_sync_limit', 200)
            : max(1, (int) $limit);

        $status_values = array('isolir', 'isolated', 'suspended', 'disabled', 'inactive');
        $rows = $this->db
            ->select('id, ' . $username_col . ' AS ppp_username, status', false)
            ->from('customers')
            ->where_in('LOWER(status)', $status_values)
            ->where($username_col . ' IS NOT NULL', null, false)
            ->where("TRIM(" . $username_col . ") <> ''", null, false)
            ->limit($limit)
            ->get()
            ->result_array();

        $stats = array(
            'success' => true,
            'message' => 'Sync isolir selesai.',
            'total_target' => count($rows),
            'total_disabled' => 0,
            'total_already_disabled' => 0,
            'total_failed' => 0,
            'errors' => array(),
        );

        foreach ($rows as $row) {
            $username = trim((string) ($row['ppp_username'] ?? ''));
            if ($username === '') {
                continue;
            }

            $find = $this->mikrotik_api->command_safe('/ppp/secret/print', array('?name' => $username));
            if (empty($find['success']) || empty($find['data'][0])) {
                $stats['total_failed']++;
                $stats['errors'][] = 'Secret `' . $username . '` tidak ditemukan.';
                continue;
            }

            $secret = (array) $find['data'][0];
            $secret_id = $this->extract_router_id($secret);
            if ($secret_id === '') {
                $stats['total_failed']++;
                $stats['errors'][] = 'ID secret `' . $username . '` tidak ditemukan.';
                continue;
            }

            $disabled_raw = strtolower(trim((string) ($secret['disabled'] ?? 'false')));
            $already_disabled = in_array($disabled_raw, array('true', '1', 'yes'), true);
            if ($already_disabled) {
                $stats['total_already_disabled']++;
                continue;
            }

            $set = $this->mikrotik_api->command_safe('/ppp/secret/set', array(
                '.id' => $secret_id,
                'disabled' => 'yes',
            ));

            if (empty($set['success'])) {
                $stats['total_failed']++;
                $stats['errors'][] = 'Gagal disable `' . $username . '`: ' . (string) ($set['error'] ?? 'unknown');
                continue;
            }

            $stats['total_disabled']++;
        }

        if ($stats['total_failed'] > 0) {
            $stats['message'] = 'Sync isolir selesai dengan error parsial.';
        }

        return $stats;
    }

    private function get_router_resource()
    {
        $result = $this->mikrotik_api->command_safe('/system/resource/print');
        if (empty($result['success']) || empty($result['data'][0])) {
            return array(
                'online' => false,
                'message' => (string) ($result['error'] ?? 'Gagal membaca /system/resource/print'),
                'cpu_load_percent' => 0,
                'memory_usage_percent' => 0,
                'memory_used_bytes' => 0,
                'memory_total_bytes' => 0,
                'disk_usage_percent' => 0,
                'disk_used_bytes' => 0,
                'disk_total_bytes' => 0,
                'uptime' => '-',
                'temperature_celsius' => null,
                'platform' => '-',
                'version' => '-',
                'board_name' => '-',
            );
        }

        $row = (array) $result['data'][0];
        $memory_total = $this->to_float($row['total-memory'] ?? 0);
        $memory_free = $this->to_float($row['free-memory'] ?? 0);
        $memory_used = max(0, $memory_total - $memory_free);
        $memory_pct = $memory_total > 0 ? ($memory_used / $memory_total) * 100 : 0;

        $disk_total = $this->to_float($row['total-hdd-space'] ?? 0);
        $disk_free = $this->to_float($row['free-hdd-space'] ?? 0);
        $disk_used = max(0, $disk_total - $disk_free);
        $disk_pct = $disk_total > 0 ? ($disk_used / $disk_total) * 100 : 0;

        $temperature = null;
        foreach (array('temperature', 'cpu-temperature', 'board-temperature') as $k) {
            if (isset($row[$k]) && trim((string) $row[$k]) !== '') {
                $temperature = $this->to_float($row[$k]);
                break;
            }
        }

        return array(
            'online' => true,
            'message' => 'OK',
            'cpu_load_percent' => (int) $this->to_float($row['cpu-load'] ?? 0),
            'memory_usage_percent' => round($memory_pct, 2),
            'memory_used_bytes' => (float) $memory_used,
            'memory_total_bytes' => (float) $memory_total,
            'disk_usage_percent' => round($disk_pct, 2),
            'disk_used_bytes' => (float) $disk_used,
            'disk_total_bytes' => (float) $disk_total,
            'uptime' => (string) ($row['uptime'] ?? '-'),
            'temperature_celsius' => $temperature,
            'platform' => (string) ($row['platform'] ?? '-'),
            'version' => (string) ($row['version'] ?? '-'),
            'board_name' => (string) ($row['board-name'] ?? '-'),
        );
    }

    private function get_interface_traffic()
    {
        $limit = max(1, (int) $this->cfg_item('interface_limit', 8));
        $monitored_only = $this->get_monitored_interfaces();
        if (empty($monitored_only)) {
            $monitored_only = array('ether10');
        }
        $use_monitored_only = !empty($monitored_only);
        $result = $this->mikrotik_api->command_safe('/interface/print');
        if (empty($result['success'])) {
            return array(
                'rows' => array(),
                'totals' => array('rx_bps' => 0, 'tx_bps' => 0),
                'down_count' => 0,
                'message' => (string) ($result['error'] ?? 'Gagal membaca /interface/print'),
            );
        }

        $rows = array();
        $total_rx = 0.0;
        $total_tx = 0.0;
        $down_count = 0;
        $found_map = array();
        $allowed_name_map = array();

        foreach ((array) $result['data'] as $iface) {
            $iface = (array) $iface;
            $name = trim((string) ($iface['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $type = strtolower(trim((string) ($iface['type'] ?? '')));
            if (!$this->is_allowed_monitor_interface_type($type)) {
                continue;
            }

            $normalized_name = strtolower($name);
            $allowed_name_map[$normalized_name] = true;
            if ($use_monitored_only && !in_array($normalized_name, $monitored_only, true)) {
                continue;
            }

            if (!$use_monitored_only && count($rows) >= $limit) {
                break;
            }

            $running = $this->to_bool($iface['running'] ?? false);
            $disabled = $this->to_bool($iface['disabled'] ?? false);
            $status = ($running && !$disabled) ? 'running' : 'down';

            if ($status === 'down' && !$disabled) {
                $down_count++;
            }

            $rx_bps = 0.0;
            $tx_bps = 0.0;

            $traffic = $this->mikrotik_api->command_safe('/interface/monitor-traffic', array(
                'interface' => $name,
                'once' => '',
            ));

            if (!empty($traffic['success']) && !empty($traffic['data'][0])) {
                $trow = (array) $traffic['data'][0];
                $rx_bps = $this->to_float($trow['rx-bits-per-second'] ?? $trow['rx-bps'] ?? 0);
                $tx_bps = $this->to_float($trow['tx-bits-per-second'] ?? $trow['tx-bps'] ?? 0);
            }

            $drop = $this->to_float($iface['rx-drop'] ?? 0) + $this->to_float($iface['tx-drop'] ?? 0);
            $errors = $this->to_float($iface['rx-error'] ?? 0) + $this->to_float($iface['tx-error'] ?? 0);

            $rows[] = array(
                'name' => $name,
                'type' => (string) ($iface['type'] ?? '-'),
                'status' => $status,
                'running' => $running,
                'disabled' => $disabled,
                'rx_bps' => round($rx_bps, 2),
                'tx_bps' => round($tx_bps, 2),
                'rx_mbps' => round($rx_bps / 1000000, 3),
                'tx_mbps' => round($tx_bps / 1000000, 3),
                'packet_drop' => round($drop, 2),
                'packet_error' => round($errors, 2),
            );
            $found_map[$normalized_name] = true;

            $total_rx += $rx_bps;
            $total_tx += $tx_bps;
        }

        if ($use_monitored_only) {
            foreach ($monitored_only as $iface_name) {
                if (!isset($allowed_name_map[$iface_name])) {
                    continue;
                }
                if (isset($found_map[$iface_name])) {
                    continue;
                }

                $rows[] = array(
                    'name' => $iface_name,
                    'type' => '-',
                    'status' => 'down',
                    'running' => false,
                    'disabled' => false,
                    'rx_bps' => 0.0,
                    'tx_bps' => 0.0,
                    'rx_mbps' => 0.0,
                    'tx_mbps' => 0.0,
                    'packet_drop' => 0.0,
                    'packet_error' => 0.0,
                );
                $down_count++;
            }
        }

        return array(
            'rows' => $rows,
            'totals' => array(
                'rx_bps' => round($total_rx, 2),
                'tx_bps' => round($total_tx, 2),
                'rx_mbps' => round($total_rx / 1000000, 3),
                'tx_mbps' => round($total_tx / 1000000, 3),
            ),
            'down_count' => $down_count,
            'message' => 'OK',
        );
    }

    private function is_allowed_monitor_interface_type($type)
    {
        $type = strtolower(trim((string) $type));
        if ($type === '') {
            return false;
        }

        return in_array($type, array('bridge', 'ether', 'ethernet', 'vlan'), true);
    }

    private function get_ppp_active()
    {
        $result = $this->mikrotik_api->command_safe('/ppp/active/print');
        if (empty($result['success'])) {
            return array(
                'total_active' => 0,
                'online_today' => 0,
                'rows' => array(),
                'message' => (string) ($result['error'] ?? 'Gagal membaca /ppp/active/print'),
            );
        }

        $display_limit = max(20, (int) $this->cfg_item('ppp_active_display_limit', 120));
        $all_rows = (array) $result['data'];
        $rows = array();
        foreach ($all_rows as $idx => $row) {
            if ($idx >= $display_limit) {
                break;
            }

            $row = (array) $row;
            $rows[] = array(
                'username' => (string) ($row['name'] ?? '-'),
                'ip_address' => (string) ($row['address'] ?? '-'),
                'caller_id' => (string) ($row['caller-id'] ?? '-'),
                'service' => (string) ($row['service'] ?? '-'),
                'login_time' => (string) ($row['started'] ?? '-'),
                'session_duration' => (string) ($row['uptime'] ?? '-'),
            );
        }

        return array(
            'total_active' => count($all_rows),
            'online_today' => $this->estimate_online_today(),
            'rows' => $rows,
            'message' => 'OK',
        );
    }

    private function get_customer_status_monitoring()
    {
        $router_id = (int) $this->active_router_id;
        $response = array(
            'lunas' => 0,
            'jatuh_tempo' => 0,
            'belum_bayar' => 0,
            'isolir' => 0,
            'unpaid_invoice_total' => 0,
            'revenue_today' => 0.0,
        );

        if ($this->db->table_exists('invoices')) {
            $today = date('Y-m-d');
            $latest_invoice_ids = $this->latest_invoice_id_per_customer_subquery($router_id);

            if ($latest_invoice_ids !== '') {
                $qb = $this->db
                    ->select('i.status, i.due_date')
                    ->from('invoices i')
                    ->join('(' . $latest_invoice_ids . ') li', 'li.max_id = i.id', 'inner', false);
                if ($router_id > 0 && $this->invoice_has_column('router_id')) {
                    $qb->where('i.router_id', $router_id);
                }
                $latest = $qb->get()->result_array();

                foreach ($latest as $inv) {
                    $status = strtolower((string) ($inv['status'] ?? ''));
                    $due = (string) ($inv['due_date'] ?? '');
                    $is_overdue_date = ($due !== '' && $due < $today);

                    if ($status === 'paid') {
                        $response['lunas']++;
                        continue;
                    }

                    if (in_array($status, array('overdue', 'unpaid'), true) || $is_overdue_date) {
                        $response['belum_bayar']++;
                        continue;
                    }

                    if (in_array($status, array('issued', 'partially_paid', 'draft'), true)) {
                        $response['jatuh_tempo']++;
                    }
                }
            }

            $unpaid_statuses = $this->existing_unpaid_statuses();
            if (!empty($unpaid_statuses)) {
                $this->db->from('invoices');
                if ($router_id > 0 && $this->invoice_has_column('router_id')) {
                    $this->db->where('router_id', $router_id);
                }
                $this->db->where_in('status', $unpaid_statuses);
                if ($this->invoice_has_column('balance_amount')) {
                    $this->db->where('balance_amount >', 0);
                }
                $response['unpaid_invoice_total'] = (int) $this->db->count_all_results();
            }
        }

        if ($this->db->table_exists('customers') && $this->customer_has_column('status')) {
            $isolir_statuses = array('suspended', 'isolated', 'isolir', 'disabled', 'inactive');
            $qb = $this->db->from('customers');
            if ($router_id > 0 && $this->customer_has_column('router_id')) {
                $qb->where('router_id', $router_id);
            }
            $response['isolir'] = (int) $qb
                ->where_in('LOWER(status)', $isolir_statuses)
                ->count_all_results();
        }

        $response['revenue_today'] = $this->get_revenue_today($router_id);
        return $response;
    }

    private function get_network_health()
    {
        $gateway_target = (string) $this->cfg_item('gateway_target', '192.168.88.1');
        $public_target = (string) $this->cfg_item('public_target', '8.8.8.8');
        $ping_count = max(1, (int) $this->cfg_item('ping_count', 3));

        $gateway = $this->run_ping_target($gateway_target, $ping_count);
        $public = $this->run_ping_target($public_target, $ping_count);

        $this->persist_network_check('gateway', $gateway);
        $this->persist_network_check('public_dns', $public);

        return array(
            'gateway' => $gateway,
            'public_dns' => $public,
        );
    }

    private function get_system_logs()
    {
        $limit = max(1, (int) $this->cfg_item('system_log_limit', 30));
        $result = $this->mikrotik_api->command_safe('/log/print');
        if (empty($result['success'])) {
            return array(
                'rows' => array(),
                'message' => (string) ($result['error'] ?? 'Gagal membaca log router'),
            );
        }

        $rows = array();
        foreach ((array) $result['data'] as $row) {
            $row = (array) $row;
            $topics = strtolower((string) ($row['topics'] ?? ''));
            $message = strtolower((string) ($row['message'] ?? ''));

            if (!$this->is_important_log($topics, $message)) {
                continue;
            }

            $severity = 'info';
            if (strpos($message, 'failed') !== false || strpos($message, 'error') !== false || strpos($message, 'down') !== false) {
                $severity = 'danger';
            } elseif (strpos($message, 'reboot') !== false) {
                $severity = 'warning';
            }

            $rows[] = array(
                'time' => trim((string) ($row['time'] ?? '')),
                'topics' => (string) ($row['topics'] ?? '-'),
                'message' => (string) ($row['message'] ?? '-'),
                'severity' => $severity,
            );

            if (count($rows) >= $limit) {
                break;
            }
        }

        return array(
            'rows' => $rows,
            'message' => 'OK',
        );
    }

    private function build_summary_cards(array $router, array $ppp, array $billing)
    {
        return array(
            'total_ppp_online' => (int) ($ppp['total_active'] ?? 0),
            'cpu_load_percent' => (int) ($router['cpu_load_percent'] ?? 0),
            'revenue_today' => (float) ($billing['revenue_today'] ?? 0),
            'total_unpaid_invoice' => (int) ($billing['unpaid_invoice_total'] ?? 0),
            'total_customer_isolir' => (int) ($billing['isolir'] ?? 0),
        );
    }

    private function run_ping_target($target, $count = 3)
    {
        $target = trim((string) $target);
        $count = max(1, (int) $count);
        if ($target === '') {
            return array(
                'target' => '-',
                'status' => 'down',
                'latency_ms' => null,
                'packet_loss_percent' => 100,
                'raw' => array(),
            );
        }

        $result = $this->mikrotik_api->command_safe('/ping', array(
            'address' => $target,
            'count' => (string) $count,
        ));

        if (empty($result['success'])) {
            return array(
                'target' => $target,
                'status' => 'down',
                'latency_ms' => null,
                'packet_loss_percent' => 100,
                'raw' => array(),
                'error' => (string) ($result['error'] ?? 'ping command failed'),
            );
        }

        $parsed = $this->parse_ping_result((array) $result['data'], $count);
        $parsed['target'] = $target;
        return $parsed;
    }

    private function parse_ping_result(array $rows, $count)
    {
        if (empty($rows)) {
            return array(
                'status' => 'down',
                'latency_ms' => null,
                'packet_loss_percent' => 100,
                'raw' => array(),
            );
        }

        $times = array();
        $packet_loss = null;

        foreach ($rows as $row) {
            $row = (array) $row;
            if (isset($row['packet-loss'])) {
                $packet_loss = $this->to_float($row['packet-loss']);
            }

            foreach (array('avg-rtt', 'time', 'latency') as $key) {
                if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                    $value = $this->to_float($row[$key]);
                    if ($value >= 0) {
                        $times[] = $value;
                        break;
                    }
                }
            }
        }

        $latency = !empty($times)
            ? round(array_sum($times) / count($times), 2)
            : null;

        if ($packet_loss === null) {
            $success = count($times);
            $packet_loss = $count > 0
                ? max(0, round((($count - $success) / $count) * 100, 2))
                : 100;
        }

        $status = ($packet_loss >= 100 || $latency === null) ? 'down' : 'up';

        return array(
            'status' => $status,
            'latency_ms' => $latency,
            'packet_loss_percent' => (float) $packet_loss,
            'raw' => $rows,
        );
    }

    private function process_alert_state($alert_key, $is_active, $message, array $payload, $cooldown_seconds, $min_active_seconds)
    {
        $now = date('Y-m-d H:i:s');
        $state = $this->get_alert_state($alert_key);

        if (!$is_active) {
            if (!empty($state) && (int) ($state['is_active'] ?? 0) === 1) {
                $this->set_alert_state($alert_key, array(
                    'is_active' => 0,
                    'resolved_at' => $now,
                    'updated_at' => $now,
                ));
            }

            return array(
                'alert_key' => $alert_key,
                'active' => false,
                'sent' => false,
                'message' => 'normal',
            );
        }

        $first_triggered_at = !empty($state['first_triggered_at'])
            ? (string) $state['first_triggered_at']
            : $now;
        $last_sent_at = !empty($state['last_sent_at'])
            ? (string) $state['last_sent_at']
            : null;

        if (empty($state) || (int) ($state['is_active'] ?? 0) !== 1) {
            $this->set_alert_state($alert_key, array(
                'is_active' => 1,
                'first_triggered_at' => $now,
                'last_triggered_at' => $now,
                'updated_at' => $now,
                'resolved_at' => null,
            ));
            $first_triggered_at = $now;
            $last_sent_at = null;
        } else {
            $this->set_alert_state($alert_key, array(
                'is_active' => 1,
                'last_triggered_at' => $now,
                'updated_at' => $now,
                'resolved_at' => null,
            ));
        }

        $active_for = max(0, time() - strtotime($first_triggered_at));
        $can_send_by_rto = $active_for >= max(0, (int) $min_active_seconds);
        $can_send_by_cooldown = true;
        if ($last_sent_at !== null && strtotime($last_sent_at) !== false) {
            $can_send_by_cooldown = (time() - strtotime($last_sent_at)) >= max(1, (int) $cooldown_seconds);
        }

        $sent = false;
        $send_message = '';

        if ($can_send_by_rto && $can_send_by_cooldown) {
            $telegram = $this->send_alert_to_telegram($alert_key, $message, $payload);
            $sent = !empty($telegram['success']);
            $send_message = (string) ($telegram['message'] ?? '');
            $this->persist_alert_log($alert_key, $message, $payload, $sent, $send_message);

            if ($sent) {
                $this->set_alert_state($alert_key, array(
                    'last_sent_at' => $now,
                    'updated_at' => $now,
                ));
            }
        }

        return array(
            'alert_key' => $alert_key,
            'active' => true,
            'sent' => $sent,
            'message' => $message,
            'telegram_message' => $send_message,
            'active_for_seconds' => $active_for,
        );
    }

    private function send_alert_to_telegram($alert_key, $message, array $payload)
    {
        if (function_exists('sendTelegram')) {
            $title = strtoupper(str_replace('_', ' ', (string) $alert_key));
            $body = "⚠️ <b>MONITORING ALERT</b>\n"
                  . "<b>Type:</b> " . html_escape($title) . "\n"
                  . "<b>Waktu:</b> " . date('Y-m-d H:i:s') . "\n"
                  . "<b>Message:</b> " . html_escape((string) $message);

            if (!empty($payload)) {
                $body .= "\n<b>Payload:</b> <code>" . html_escape(substr(json_encode($payload), 0, 700)) . "</code>";
            }

            $dispatch = sendTelegram('alert', $body);
            if (!empty($dispatch['success'])) {
                return array(
                    'success' => true,
                    'message' => 'Alert telegram terkirim ke ' . (int) ($dispatch['sent'] ?? 0) . ' chat.',
                );
            }
        }

        $settings = $this->settings_model->get_telegram_settings();
        $enabled = (int) ($settings['enable_notification'] ?? 0) === 1;
        $token = trim((string) ($settings['bot_token'] ?? ''));
        $chat_id = trim((string) ($settings['chat_id_admin'] ?? ''));

        if (!$enabled) {
            return array('success' => false, 'message' => 'Telegram notification nonaktif');
        }
        if ($token === '' || $chat_id === '') {
            return array('success' => false, 'message' => 'Bot token/chat id belum diatur');
        }

        $title = strtoupper(str_replace('_', ' ', (string) $alert_key));
        $body = "⚠️ <b>MONITORING ALERT</b>\n"
              . "<b>Type:</b> " . html_escape($title) . "\n"
              . "<b>Waktu:</b> " . date('Y-m-d H:i:s') . "\n"
              . "<b>Message:</b> " . html_escape((string) $message);

        if (!empty($payload)) {
            $body .= "\n<b>Payload:</b> <code>" . html_escape(substr(json_encode($payload), 0, 700)) . "</code>";
        }

        return $this->telegram_service->send_message($token, $chat_id, $body, 'HTML');
    }

    private function persist_network_check($check_type, array $result)
    {
        if (!$this->db->table_exists('monitoring_network_checks')) {
            return;
        }

        $payload = array(
            'check_type' => (string) $check_type,
            'target' => (string) ($result['target'] ?? ''),
            'status' => (string) ($result['status'] ?? 'down'),
            'latency_ms' => isset($result['latency_ms']) ? (float) $result['latency_ms'] : null,
            'packet_loss_percent' => isset($result['packet_loss_percent']) ? (float) $result['packet_loss_percent'] : null,
            'checked_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        );

        $this->db->insert('monitoring_network_checks', $payload);
    }

    private function persist_alert_log($alert_key, $message, array $payload, $sent, $telegram_message)
    {
        if (!$this->db->table_exists('monitoring_alert_logs')) {
            return;
        }

        $log = array(
            'alert_key' => (string) $alert_key,
            'severity' => 'warning',
            'message' => (string) $message,
            'payload_json' => !empty($payload) ? json_encode($payload) : null,
            'telegram_sent' => $sent ? 1 : 0,
            'telegram_response' => (string) $telegram_message,
            'created_at' => date('Y-m-d H:i:s'),
        );
        $this->db->insert('monitoring_alert_logs', $log);
    }

    private function get_alert_state($alert_key)
    {
        if (!$this->db->table_exists('monitoring_alert_state')) {
            return array();
        }

        return (array) $this->db
            ->from('monitoring_alert_state')
            ->where('alert_key', (string) $alert_key)
            ->limit(1)
            ->get()
            ->row_array();
    }

    private function set_alert_state($alert_key, array $data)
    {
        if (!$this->db->table_exists('monitoring_alert_state')) {
            return false;
        }

        $existing = $this->get_alert_state($alert_key);
        if (empty($existing)) {
            $payload = array_merge(array(
                'alert_key' => (string) $alert_key,
                'is_active' => 0,
                'first_triggered_at' => null,
                'last_triggered_at' => null,
                'last_sent_at' => null,
                'resolved_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ), $data);

            return $this->db->insert('monitoring_alert_state', $payload);
        }

        return $this->db
            ->where('alert_key', (string) $alert_key)
            ->update('monitoring_alert_state', $data);
    }

    private function estimate_online_today()
    {
        $result = $this->mikrotik_api->command_safe('/log/print');
        if (empty($result['success'])) {
            return 0;
        }

        $today_day = date('d');
        $count = 0;

        foreach ((array) $result['data'] as $row) {
            $row = (array) $row;
            $message = strtolower((string) ($row['message'] ?? ''));
            $topics = strtolower((string) ($row['topics'] ?? ''));
            $time_raw = (string) ($row['time'] ?? '');

            if (strpos($topics, 'ppp') === false) {
                continue;
            }
            if (strpos($message, 'logged in') === false && strpos($message, 'login') === false) {
                continue;
            }

            // RouterOS format waktu bisa bervariasi. Minimal cek day-of-month ada di string.
            if ($time_raw !== '' && strpos($time_raw, $today_day) === false && strlen($time_raw) > 5) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    private function latest_invoice_id_per_customer_subquery($router_id = 0)
    {
        if (!$this->db->table_exists('invoices') || !$this->invoice_has_column('customer_id')) {
            return '';
        }

        $qb = $this->db
            ->select('MAX(id) AS max_id', false)
            ->from('invoices')
            ->where('customer_id IS NOT NULL', null, false);
        if ((int) $router_id > 0 && $this->invoice_has_column('router_id')) {
            $qb->where('router_id', (int) $router_id);
        }
        return $qb->group_by('customer_id')->get_compiled_select();
    }

    private function existing_unpaid_statuses()
    {
        $statuses = array('issued', 'overdue', 'partially_paid', 'unpaid');
        $available = array();
        foreach ($statuses as $status) {
            $available[] = $status;
        }
        return $available;
    }

    private function get_revenue_today($router_id = 0)
    {
        $router_id = (int) $router_id;
        $today_start = date('Y-m-d 00:00:00');
        $tomorrow_start = date('Y-m-d 00:00:00', strtotime('+1 day'));

        if ($this->db->table_exists('payments')) {
            $this->db->from('payments');
            if ($router_id > 0 && $this->payments_has_column('router_id')) {
                $this->db->where('router_id', $router_id);
            } elseif ($router_id > 0 && $this->payments_has_column('invoice_id') && $this->db->table_exists('invoices') && $this->invoice_has_column('router_id')) {
                $this->db->join('invoices i', 'i.id = payments.invoice_id', 'inner');
                $this->db->where('i.router_id', $router_id);
            }
            if ($this->payments_has_column('payment_date')) {
                $this->db->where('payment_date >=', $today_start);
                $this->db->where('payment_date <', $tomorrow_start);
            }
            if ($this->payments_has_column('status')) {
                $this->db->where('status', 'confirmed');
            }

            if ($this->payments_has_column('amount')) {
                $row = $this->db->select_sum('amount', 'total')->get()->row_array();
                return (float) ($row['total'] ?? 0);
            }
        }

        if ($this->db->table_exists('invoices')) {
            $this->db->from('invoices')->where('status', 'paid');
            if ($router_id > 0 && $this->invoice_has_column('router_id')) {
                $this->db->where('router_id', $router_id);
            }

            if ($this->invoice_has_column('paid_date')) {
                $this->db->where('paid_date >=', $today_start);
                $this->db->where('paid_date <', $tomorrow_start);
            } elseif ($this->invoice_has_column('updated_at')) {
                $this->db->where('updated_at >=', $today_start);
                $this->db->where('updated_at <', $tomorrow_start);
            }

            if ($this->invoice_has_column('paid_amount')) {
                $row = $this->db->select_sum('paid_amount', 'total')->get()->row_array();
                return (float) ($row['total'] ?? 0);
            }
            if ($this->invoice_has_column('total_amount')) {
                $row = $this->db->select_sum('total_amount', 'total')->get()->row_array();
                return (float) ($row['total'] ?? 0);
            }
        }

        return 0.0;
    }

    private function is_important_log($topics, $message)
    {
        $topics = strtolower((string) $topics);
        $message = strtolower((string) $message);

        if (strpos($message, 'login failed') !== false || strpos($message, 'authentication failed') !== false) {
            return true;
        }
        if (strpos($topics, 'interface') !== false && strpos($message, 'down') !== false) {
            return true;
        }
        if (strpos($message, 'reboot') !== false) {
            return true;
        }
        if (strpos($topics, 'ppp') !== false && (strpos($message, 'failed') !== false || strpos($message, 'error') !== false)) {
            return true;
        }

        return false;
    }

    private function configure_mikrotik($router_id = 0)
    {
        $router_id = (int) $router_id;
        $this->active_router_id = $router_id > 0 ? $router_id : 0;
        if ($router_id > 0 && $this->db->table_exists('routers')) {
            $router = $this->resolve_router_config($router_id);
            if (!empty($router['success']) && !empty($router['config'])) {
                $this->mikrotik_api->configure((array) $router['config']);
                return;
            }
        }

        $mk = $this->settings_model->get_mikrotik_settings();
        $this->mikrotik_api->configure($mk);
    }

    private function resolve_router_config($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return array('success' => false, 'config' => null);
        }

        $fields = $this->db->list_fields('routers');
        $qb = $this->db->from('routers')->where('id', $router_id);
        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }
        $router = $qb->limit(1)->get()->row_array();
        if (empty($router)) {
            return array('success' => false, 'config' => null);
        }

        $host = trim((string) ($router['ip_address'] ?? $router['api_host'] ?? ''));
        $username = trim((string) ($router['username'] ?? $router['api_username'] ?? ''));
        $password_raw = trim((string) ($router['password'] ?? $router['api_password_enc'] ?? ''));
        $password = '';
        if ($password_raw !== '') {
            $decoded = '';
            if (isset($this->settings_model) && method_exists($this->settings_model, 'decrypt_secret')) {
                $decoded = (string) $this->settings_model->decrypt_secret($password_raw);
            }

            if (!is_string($decoded) || trim($decoded) === '') {
                $this->load->library('encryption');
                $decoded = $this->encryption->decrypt($password_raw);
            }

            if (is_string($decoded) && trim($decoded) !== '') {
                $password = trim($decoded);
            } elseif (strpos($password_raw, 'osl:') !== 0 && strpos($password_raw, 'ci3:') !== 0) {
                $password = $password_raw;
            }
        }

        if ($host === '' || $username === '' || $password === '') {
            return array('success' => false, 'config' => null);
        }

        return array(
            'success' => true,
            'config' => array(
                'host' => $host,
                'username' => $username,
                'password' => $password,
                'api_port' => (int) ($router['api_port'] ?? 8728),
                'use_ssl' => !empty($router['use_ssl']),
                'timeout' => (int) ($router['timeout_seconds'] ?? 5),
            ),
        );
    }

    private function resolve_customer_username_column()
    {
        if (!$this->db->table_exists('customers')) {
            return '';
        }

        $fields = $this->db->list_fields('customers');
        foreach (array('pppoe_username', 'username') as $column) {
            if (in_array($column, $fields, true)) {
                return $column;
            }
        }
        return '';
    }

    private function invoice_has_column($column)
    {
        if (!$this->db->table_exists('invoices')) {
            return false;
        }
        return in_array((string) $column, $this->db->list_fields('invoices'), true);
    }

    private function payments_has_column($column)
    {
        if (!$this->db->table_exists('payments')) {
            return false;
        }
        return in_array((string) $column, $this->db->list_fields('payments'), true);
    }

    private function customer_has_column($column)
    {
        if (!$this->db->table_exists('customers')) {
            return false;
        }
        return in_array((string) $column, $this->db->list_fields('customers'), true);
    }

    private function extract_router_id(array $row)
    {
        foreach (array('.id', '=.id', 'id') as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }
        return '';
    }

    private function cfg_item($key, $default = null)
    {
        return array_key_exists($key, $this->cfg) ? $this->cfg[$key] : $default;
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

    private function get_monitored_interfaces()
    {
        if ((int) $this->active_router_id > 0) {
            $router_settings = $this->get_router_monitoring_settings((int) $this->active_router_id);
            if (!empty($router_settings['interfaces']) && is_array($router_settings['interfaces'])) {
                return $router_settings['interfaces'];
            }
        }

        return $this->normalize_interface_list($this->cfg_item('monitored_interfaces', array()));
    }

    private function get_interface_down_watchlist()
    {
        if ((int) $this->active_router_id > 0) {
            $router_settings = $this->get_router_monitoring_settings((int) $this->active_router_id);
            if (!empty($router_settings['down_watchlist']) && is_array($router_settings['down_watchlist'])) {
                return $router_settings['down_watchlist'];
            }
        }

        return $this->normalize_interface_list($this->cfg_item('interface_down_watchlist', array()));
    }

    private function get_router_monitoring_settings($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return array('interfaces' => array(), 'down_watchlist' => array());
        }

        if (isset($this->router_monitoring_settings_cache[$router_id])) {
            return $this->router_monitoring_settings_cache[$router_id];
        }

        $fields = $this->db->list_fields('routers');
        if (!in_array('monitor_interfaces', $fields, true) && !in_array('monitor_down_watchlist', $fields, true)) {
            $settings = array('interfaces' => array(), 'down_watchlist' => array());
            $this->router_monitoring_settings_cache[$router_id] = $settings;
            return $settings;
        }

        $this->db->from('routers')->where('id', $router_id)->limit(1);
        if (in_array('monitor_interfaces', $fields, true) && in_array('monitor_down_watchlist', $fields, true)) {
            $this->db->select('monitor_interfaces, monitor_down_watchlist');
        } elseif (in_array('monitor_interfaces', $fields, true)) {
            $this->db->select('monitor_interfaces');
        } else {
            $this->db->select('monitor_down_watchlist');
        }
        $row = (array) $this->db->get()->row_array();

        $settings = array(
            'interfaces' => $this->normalize_interface_list($row['monitor_interfaces'] ?? array()),
            'down_watchlist' => $this->normalize_interface_list($row['monitor_down_watchlist'] ?? array()),
        );
        $this->router_monitoring_settings_cache[$router_id] = $settings;
        return $settings;
    }

    private function normalize_interface_list($raw)
    {
        $items = array();
        if (is_string($raw)) {
            $raw = preg_split('/[\r\n,;]+/', $raw);
        }
        if (!is_array($raw)) {
            return $items;
        }

        foreach ($raw as $name) {
            $name = strtolower(trim((string) $name));
            if ($name === '') {
                continue;
            }
            if (!in_array($name, $items, true)) {
                $items[] = $name;
            }
        }

        return $items;
    }

    private function to_bool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        $v = strtolower(trim((string) $value));
        return in_array($v, array('true', 'yes', '1', 'running', 'up'), true);
    }

    private function to_float($value)
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }

        if (preg_match('/-?\d+(\.\d+)?/', $raw, $m)) {
            return (float) $m[0];
        }

        return 0.0;
    }
}
