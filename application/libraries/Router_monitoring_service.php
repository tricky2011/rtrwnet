<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Service monitoring multi-router dengan cache + fail-safe.
 *
 * - Single router: ambil snapshot router terpilih.
 * - All routers (superadmin memilih "Semua"): agregasi seluruh router aktif.
 */
class Router_monitoring_service
{
    /** @var CI_Controller */
    protected $CI;

    /** @var int */
    protected $default_cache_ttl = 45;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->model('Monitoring_model', 'monitoring_model');
        $this->CI->load->driver('cache', array('adapter' => 'file', 'backup' => 'dummy'));

        $this->CI->config->load('monitoring', true);
        $cfg = (array) $this->CI->config->item('monitoring', 'monitoring');
        if (isset($cfg['monitoring']) && is_array($cfg['monitoring'])) {
            $cfg = (array) $cfg['monitoring'];
        }
        $ttl = isset($cfg['snapshot_cache_ttl']) ? (int) $cfg['snapshot_cache_ttl'] : 45;
        if ($ttl < 0) {
            $ttl = 0;
        }
        $this->default_cache_ttl = $ttl;
    }

    /**
     * Ambil snapshot monitoring dengan router scope aktif.
     *
     * @param int|null $router_id
     * @param bool $include_logs
     * @param bool $all_router_mode
     * @param int|null $cache_ttl
     * @return array
     */
    public function get_dashboard_snapshot($router_id = null, $include_logs = true, $all_router_mode = false, $cache_ttl = null)
    {
        $router_id = $router_id === null ? null : (int) $router_id;
        $cache_ttl = $cache_ttl === null ? $this->default_cache_ttl : max(0, (int) $cache_ttl);
        $cache_key = $this->build_cache_key($router_id, (bool) $include_logs, (bool) $all_router_mode);

        if ($cache_ttl > 0) {
            $cached = $this->CI->cache->get($cache_key);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        $snapshot = array();
        if ($all_router_mode && ($router_id === null || $router_id <= 0)) {
            $snapshot = $this->get_all_router_snapshot((bool) $include_logs);
        } else {
            $snapshot = $this->get_single_router_snapshot((int) $router_id, (bool) $include_logs);
        }

        if ($cache_ttl > 0) {
            $this->CI->cache->save($cache_key, $snapshot, $cache_ttl);
        }

        return $snapshot;
    }

    /**
     * Jalankan health check sekarang sesuai mode router.
     *
     * @param int|null $router_id
     * @param bool $all_router_mode
     * @return array
     */
    public function run_health_checks($router_id = null, $all_router_mode = false)
    {
        $router_id = $router_id === null ? null : (int) $router_id;

        if (!$all_router_mode || ($router_id !== null && $router_id > 0)) {
            $result = $this->CI->monitoring_model->run_health_checks_and_alerts((int) $router_id);
            $this->purge_snapshot_cache($router_id, true, $all_router_mode);
            return $result;
        }

        $routers = $this->get_active_routers();
        $rows = array();
        $success_count = 0;
        $failed_count = 0;

        foreach ($routers as $router) {
            $rid = (int) ($router['id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }

            try {
                $res = $this->CI->monitoring_model->run_health_checks_and_alerts($rid);
                $ok = !empty($res['success']);
                if ($ok) {
                    $success_count++;
                } else {
                    $failed_count++;
                }

                $rows[] = array(
                    'router_id' => $rid,
                    'router_name' => (string) ($router['name'] ?? ('Router #' . $rid)),
                    'success' => $ok,
                    'message' => (string) ($res['message'] ?? ($ok ? 'OK' : 'Failed')),
                );
            } catch (Throwable $e) {
                $failed_count++;
                $rows[] = array(
                    'router_id' => $rid,
                    'router_name' => (string) ($router['name'] ?? ('Router #' . $rid)),
                    'success' => false,
                    'message' => $e->getMessage(),
                );
                log_message('error', '[ROUTER_MONITORING_SERVICE][CHECK_ALL] router_id=' . $rid . ' ' . $e->getMessage());
            }
        }

        $this->purge_snapshot_cache(null, true, true);

        return array(
            'success' => $failed_count === 0,
            'mode' => 'all',
            'generated_at' => date('Y-m-d H:i:s'),
            'total_router' => count($rows),
            'total_success' => $success_count,
            'total_failed' => $failed_count,
            'rows' => $rows,
            'message' => 'Health check all routers selesai. success=' . $success_count . ', failed=' . $failed_count,
        );
    }

    /**
     * Hapus cache snapshot monitoring.
     *
     * @param int|null $router_id
     * @param bool $include_logs
     * @param bool $all_router_mode
     * @return void
     */
    public function purge_snapshot_cache($router_id = null, $include_logs = true, $all_router_mode = false)
    {
        $router_id = $router_id === null ? null : (int) $router_id;
        $keys = array(
            $this->build_cache_key($router_id, (bool) $include_logs, (bool) $all_router_mode),
            $this->build_cache_key($router_id, false, (bool) $all_router_mode),
            $this->build_cache_key($router_id, true, (bool) $all_router_mode),
        );

        if ($all_router_mode) {
            $keys[] = $this->build_cache_key(null, false, true);
            $keys[] = $this->build_cache_key(null, true, true);
        }

        foreach (array_unique($keys) as $key) {
            $this->CI->cache->delete($key);
        }
    }

    private function get_single_router_snapshot($router_id, $include_logs)
    {
        $snapshot = array();
        try {
            $snapshot = $this->CI->monitoring_model->get_dashboard_snapshot((bool) $include_logs, (int) $router_id);
        } catch (Throwable $e) {
            log_message('error', '[ROUTER_MONITORING_SERVICE][SINGLE] router_id=' . $router_id . ' ' . $e->getMessage());
            $snapshot = $this->build_unreachable_snapshot($router_id, $e->getMessage());
        }

        $router_name = $this->resolve_router_name($router_id);
        $router_label = $router_id > 0
            ? ($router_name !== '' ? $router_name : ('Router #' . $router_id))
            : 'Default Router';

        if (!isset($snapshot['router']) || !is_array($snapshot['router'])) {
            $snapshot['router'] = array();
        }
        $snapshot['router']['label'] = $router_label;

        $snapshot['router_scope'] = array(
            'is_all' => false,
            'selected_router_id' => $router_id > 0 ? $router_id : null,
            'selected_router_name' => $router_label,
            'total_router' => 1,
            'online_router' => !empty($snapshot['router']['online']) ? 1 : 0,
        );
        $snapshot['router_summary'] = array(
            'rows' => array(
                $this->build_router_summary_row($router_id, $router_label, $snapshot),
            ),
        );

        return $snapshot;
    }

    private function get_all_router_snapshot($include_logs)
    {
        $routers = $this->get_active_routers();
        if (empty($routers)) {
            $snapshot = $this->get_single_router_snapshot(0, $include_logs);
            $snapshot['router_scope'] = array(
                'is_all' => true,
                'selected_router_id' => null,
                'selected_router_name' => 'Semua Router',
                'total_router' => 0,
                'online_router' => !empty($snapshot['router']['online']) ? 1 : 0,
            );
            return $snapshot;
        }

        $router_rows = array();
        $snapshots = array();
        foreach ($routers as $router) {
            $rid = (int) ($router['id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }

            try {
                $snap = $this->CI->monitoring_model->get_dashboard_snapshot((bool) $include_logs, $rid);
            } catch (Throwable $e) {
                $snap = $this->build_unreachable_snapshot($rid, $e->getMessage());
                log_message('error', '[ROUTER_MONITORING_SERVICE][ALL] router_id=' . $rid . ' ' . $e->getMessage());
            }

            $router_name = trim((string) ($router['name'] ?? ''));
            if ($router_name === '') {
                $router_name = 'Router #' . $rid;
            }

            $snapshots[] = array(
                'router_id' => $rid,
                'router_name' => $router_name,
                'ip_address' => (string) ($router['ip_address'] ?? ''),
                'snapshot' => $snap,
            );
            $router_rows[] = $this->build_router_summary_row($rid, $router_name, $snap, (string) ($router['ip_address'] ?? ''));
        }

        return $this->aggregate_snapshots($snapshots, $router_rows, (bool) $include_logs);
    }

    private function aggregate_snapshots(array $snapshots, array $router_rows, $include_logs)
    {
        $generated_at = date('Y-m-d H:i:s');
        $total_router = count($router_rows);
        $online_router = 0;

        $cpu_sum = 0.0;
        $mem_sum = 0.0;
        $disk_sum = 0.0;
        $cpu_count = 0;

        $ppp_total = 0;
        $ppp_today_total = 0;
        $billing_sum = array(
            'lunas' => 0,
            'jatuh_tempo' => 0,
            'belum_bayar' => 0,
            'isolir' => 0,
            'unpaid_invoice_total' => 0,
            'revenue_today' => 0.0,
        );

        $iface_rows = array();
        $iface_total_rx = 0.0;
        $iface_total_tx = 0.0;
        $iface_down_count = 0;

        $ppp_rows = array();
        $system_logs = array();

        $gw_latency = array();
        $gw_loss = array();
        $gw_down = 0;
        $pub_latency = array();
        $pub_loss = array();
        $pub_down = 0;

        $thresholds = array(
            'cpu_alert_percent' => 80,
            'gateway_rto_seconds' => 300,
        );

        foreach ($snapshots as $bundle) {
            $router_name = (string) ($bundle['router_name'] ?? '-');
            $snap = (array) ($bundle['snapshot'] ?? array());
            $router = (array) ($snap['router'] ?? array());
            $interfaces = (array) ($snap['interfaces'] ?? array());
            $ppp = (array) ($snap['ppp_active'] ?? array());
            $billing = (array) ($snap['billing'] ?? array());
            $network = (array) ($snap['network'] ?? array());

            if (!empty($router['online'])) {
                $online_router++;
                $cpu_sum += (float) ($router['cpu_load_percent'] ?? 0);
                $mem_sum += (float) ($router['memory_usage_percent'] ?? 0);
                $disk_sum += (float) ($router['disk_usage_percent'] ?? 0);
                $cpu_count++;
            }

            $ppp_total += (int) ($ppp['total_active'] ?? 0);
            $ppp_today_total += (int) ($ppp['online_today'] ?? 0);

            $billing_sum['lunas'] += (int) ($billing['lunas'] ?? 0);
            $billing_sum['jatuh_tempo'] += (int) ($billing['jatuh_tempo'] ?? 0);
            $billing_sum['belum_bayar'] += (int) ($billing['belum_bayar'] ?? 0);
            $billing_sum['isolir'] += (int) ($billing['isolir'] ?? 0);
            $billing_sum['unpaid_invoice_total'] += (int) ($billing['unpaid_invoice_total'] ?? 0);
            $billing_sum['revenue_today'] += (float) ($billing['revenue_today'] ?? 0);

            $totals = (array) ($interfaces['totals'] ?? array());
            $iface_total_rx += (float) ($totals['rx_bps'] ?? 0);
            $iface_total_tx += (float) ($totals['tx_bps'] ?? 0);
            $iface_down_count += (int) ($interfaces['down_count'] ?? 0);

            foreach (array_slice((array) ($interfaces['rows'] ?? array()), 0, 5) as $row) {
                $row = (array) $row;
                $row['name'] = $router_name . ' :: ' . (string) ($row['name'] ?? '-');
                $iface_rows[] = $row;
                if (count($iface_rows) >= 60) {
                    break;
                }
            }

            foreach (array_slice((array) ($ppp['rows'] ?? array()), 0, 20) as $row) {
                $row = (array) $row;
                $row['username'] = $router_name . ' / ' . (string) ($row['username'] ?? '-');
                $ppp_rows[] = $row;
                if (count($ppp_rows) >= 160) {
                    break;
                }
            }

            if ($include_logs) {
                foreach (array_slice((array) (($snap['system_logs']['rows'] ?? array())), 0, 20) as $log) {
                    $log = (array) $log;
                    $log['topics'] = $router_name . ' | ' . (string) ($log['topics'] ?? '-');
                    $system_logs[] = $log;
                    if (count($system_logs) >= 120) {
                        break;
                    }
                }
            }

            $gateway = (array) ($network['gateway'] ?? array());
            $public = (array) ($network['public_dns'] ?? array());
            if (strtolower((string) ($gateway['status'] ?? 'down')) !== 'up') {
                $gw_down++;
            }
            if (strtolower((string) ($public['status'] ?? 'down')) !== 'up') {
                $pub_down++;
            }
            if (isset($gateway['latency_ms']) && $gateway['latency_ms'] !== null) {
                $gw_latency[] = (float) $gateway['latency_ms'];
            }
            if (isset($gateway['packet_loss_percent']) && $gateway['packet_loss_percent'] !== null) {
                $gw_loss[] = (float) $gateway['packet_loss_percent'];
            }
            if (isset($public['latency_ms']) && $public['latency_ms'] !== null) {
                $pub_latency[] = (float) $public['latency_ms'];
            }
            if (isset($public['packet_loss_percent']) && $public['packet_loss_percent'] !== null) {
                $pub_loss[] = (float) $public['packet_loss_percent'];
            }

            if (!empty($snap['thresholds']) && is_array($snap['thresholds'])) {
                $thresholds = array_merge($thresholds, (array) $snap['thresholds']);
            }
        }

        $cpu_avg = $cpu_count > 0 ? round($cpu_sum / $cpu_count, 2) : 0;
        $mem_avg = $cpu_count > 0 ? round($mem_sum / $cpu_count, 2) : 0;
        $disk_avg = $cpu_count > 0 ? round($disk_sum / $cpu_count, 2) : 0;

        $snapshot = array(
            'generated_at' => $generated_at,
            'router' => array(
                'online' => $online_router > 0,
                'label' => 'Summary All Routers',
                'message' => 'Summary ' . $total_router . ' router, online ' . $online_router,
                'cpu_load_percent' => $cpu_avg,
                'memory_usage_percent' => $mem_avg,
                'disk_usage_percent' => $disk_avg,
                'memory_used_bytes' => 0,
                'memory_total_bytes' => 0,
                'disk_used_bytes' => 0,
                'disk_total_bytes' => 0,
                'uptime' => '-',
                'temperature_celsius' => null,
                'platform' => '-',
                'version' => '-',
                'board_name' => '-',
            ),
            'interfaces' => array(
                'rows' => $iface_rows,
                'totals' => array(
                    'rx_bps' => round($iface_total_rx, 2),
                    'tx_bps' => round($iface_total_tx, 2),
                    'rx_mbps' => round($iface_total_rx / 1000000, 3),
                    'tx_mbps' => round($iface_total_tx / 1000000, 3),
                ),
                'down_count' => $iface_down_count,
                'message' => 'Summary all routers',
            ),
            'ppp_active' => array(
                'total_active' => $ppp_total,
                'online_today' => $ppp_today_total,
                'rows' => $ppp_rows,
                'message' => 'Summary all routers',
            ),
            'billing' => $billing_sum,
            'network' => array(
                'gateway' => array(
                    'target' => 'ALL ROUTERS',
                    'status' => $gw_down > 0 ? 'down' : 'up',
                    'latency_ms' => !empty($gw_latency) ? round(array_sum($gw_latency) / count($gw_latency), 2) : null,
                    'packet_loss_percent' => !empty($gw_loss) ? round(array_sum($gw_loss) / count($gw_loss), 2) : null,
                    'raw' => array(),
                ),
                'public_dns' => array(
                    'target' => 'ALL ROUTERS',
                    'status' => $pub_down > 0 ? 'down' : 'up',
                    'latency_ms' => !empty($pub_latency) ? round(array_sum($pub_latency) / count($pub_latency), 2) : null,
                    'packet_loss_percent' => !empty($pub_loss) ? round(array_sum($pub_loss) / count($pub_loss), 2) : null,
                    'raw' => array(),
                ),
            ),
            'system_logs' => array(
                'rows' => $system_logs,
                'message' => 'Summary all routers',
            ),
            'summary' => array(
                'total_ppp_online' => $ppp_total,
                'cpu_load_percent' => $cpu_avg,
                'revenue_today' => (float) ($billing_sum['revenue_today'] ?? 0),
                'total_unpaid_invoice' => (int) ($billing_sum['unpaid_invoice_total'] ?? 0),
                'total_customer_isolir' => (int) ($billing_sum['isolir'] ?? 0),
            ),
            'thresholds' => $thresholds,
            'router_scope' => array(
                'is_all' => true,
                'selected_router_id' => null,
                'selected_router_name' => 'Semua Router',
                'total_router' => $total_router,
                'online_router' => $online_router,
            ),
            'router_summary' => array(
                'rows' => $router_rows,
            ),
        );

        return $snapshot;
    }

    private function build_router_summary_row($router_id, $router_name, array $snapshot, $ip_address = '')
    {
        $router = (array) ($snapshot['router'] ?? array());
        $interfaces = (array) ($snapshot['interfaces'] ?? array());
        $ppp = (array) ($snapshot['ppp_active'] ?? array());

        return array(
            'router_id' => (int) $router_id,
            'router_name' => (string) $router_name,
            'ip_address' => (string) $ip_address,
            'online' => !empty($router['online']),
            'cpu_load_percent' => (float) ($router['cpu_load_percent'] ?? 0),
            'memory_usage_percent' => (float) ($router['memory_usage_percent'] ?? 0),
            'ppp_active' => (int) ($ppp['total_active'] ?? 0),
            'rx_mbps' => (float) (($interfaces['totals']['rx_mbps'] ?? 0)),
            'tx_mbps' => (float) (($interfaces['totals']['tx_mbps'] ?? 0)),
            'message' => (string) ($router['message'] ?? ''),
        );
    }

    private function build_unreachable_snapshot($router_id, $message = '')
    {
        return array(
            'generated_at' => date('Y-m-d H:i:s'),
            'router' => array(
                'online' => false,
                'message' => $message !== '' ? $message : 'Router unreachable',
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
            ),
            'interfaces' => array(
                'rows' => array(),
                'totals' => array('rx_bps' => 0, 'tx_bps' => 0, 'rx_mbps' => 0, 'tx_mbps' => 0),
                'down_count' => 0,
                'message' => 'Router unreachable',
            ),
            'ppp_active' => array(
                'total_active' => 0,
                'online_today' => 0,
                'rows' => array(),
                'message' => 'Router unreachable',
            ),
            'billing' => array(
                'lunas' => 0,
                'jatuh_tempo' => 0,
                'belum_bayar' => 0,
                'isolir' => 0,
                'unpaid_invoice_total' => 0,
                'revenue_today' => 0,
            ),
            'network' => array(
                'gateway' => array('target' => '-', 'status' => 'down', 'latency_ms' => null, 'packet_loss_percent' => 100),
                'public_dns' => array('target' => '-', 'status' => 'down', 'latency_ms' => null, 'packet_loss_percent' => 100),
            ),
            'system_logs' => array('rows' => array(), 'message' => 'Router unreachable'),
            'summary' => array(
                'total_ppp_online' => 0,
                'cpu_load_percent' => 0,
                'revenue_today' => 0,
                'total_unpaid_invoice' => 0,
                'total_customer_isolir' => 0,
            ),
            'thresholds' => array(
                'cpu_alert_percent' => 80,
                'gateway_rto_seconds' => 300,
            ),
            'router_scope' => array(
                'is_all' => false,
                'selected_router_id' => $router_id > 0 ? (int) $router_id : null,
                'selected_router_name' => $router_id > 0 ? ('Router #' . (int) $router_id) : 'Router',
                'total_router' => 1,
                'online_router' => 0,
            ),
            'router_summary' => array('rows' => array()),
        );
    }

    private function build_cache_key($router_id, $include_logs, $all_router_mode)
    {
        $router_part = ($router_id === null || (int) $router_id <= 0) ? 'all' : ('router_' . (int) $router_id);
        return 'monitoring_snapshot_v2_' . $router_part . '_' . ((int) !!$include_logs) . '_' . ((int) !!$all_router_mode);
    }

    private function get_active_routers()
    {
        if (!$this->CI->db->table_exists('routers')) {
            return array();
        }

        $fields = $this->CI->db->list_fields('routers');
        if (empty($fields)) {
            return array();
        }

        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : 'id');

        $ip_col = in_array('ip_address', $fields, true)
            ? 'ip_address'
            : (in_array('api_host', $fields, true) ? 'api_host' : '');

        $qb = $this->CI->db
            ->select('id, ' . $name_col . ' AS name' . ($ip_col !== '' ? ', ' . $ip_col . ' AS ip_address' : ''), false)
            ->from('routers');

        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('LOWER(status)', 'active');
        }

        return $qb->order_by($name_col, 'ASC')->get()->result_array();
    }

    private function resolve_router_name($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->CI->db->table_exists('routers')) {
            return '';
        }

        $fields = $this->CI->db->list_fields('routers');
        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : '');
        if ($name_col === '') {
            return '';
        }

        $row = $this->CI->db
            ->select($name_col . ' AS name', false)
            ->from('routers')
            ->where('id', $router_id)
            ->limit(1)
            ->get()
            ->row_array();

        return trim((string) ($row['name'] ?? ''));
    }
}
