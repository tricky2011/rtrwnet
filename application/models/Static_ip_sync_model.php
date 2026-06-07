<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Static_ip_sync_model extends CI_Model
{
    private $customer_table = 'customers';
    private $customer_fields = array();
    private $customer_meta = array();
    private $invoice_fields = null;
    private $package_profiles_cache = null;
    private $active_router_id = 0;
    private $active_router_name = '';

    public function __construct()
    {
        parent::__construct();
        $this->load->library('mikrotik_api');
        $this->load->model('settings_model');
        $this->refresh_customer_schema();
        $this->configure_mikrotik();
    }

    public function get_simple_queue()
    {
        $result = $this->mikrotik_api->command_safe('/queue/simple/print');
        if (empty($result['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($result['error'] ?? 'Gagal membaca Simple Queue'),
                'data' => array(),
            );
        }

        $rows = isset($result['data']) && is_array($result['data']) ? $result['data'] : array();
        return array(
            'success' => true,
            'message' => 'OK',
            'data' => $rows,
        );
    }

    public function get_active_arp()
    {
        $result = $this->mikrotik_api->command_safe('/ip/arp/print');
        if (empty($result['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($result['error'] ?? 'Gagal membaca /ip/arp/print'),
                'data' => array(),
            );
        }

        $rows = isset($result['data']) && is_array($result['data']) ? $result['data'] : array();
        $active = array();
        foreach ($rows as $row) {
            $address = trim((string) ($row['address'] ?? ''));
            if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            if ($this->is_truthy($row['disabled'] ?? false)) {
                continue;
            }

            if ($this->is_truthy($row['invalid'] ?? false)) {
                continue;
            }

            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if ($status === 'failed' || $status === 'incomplete' || $status === 'stale') {
                continue;
            }

            $active[] = $row;
        }

        return array(
            'success' => true,
            'message' => 'OK',
            'data' => $active,
        );
    }

    public function get_dhcp_leases()
    {
        $result = $this->mikrotik_api->command_safe('/ip/dhcp-server/lease/print');
        if (empty($result['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($result['error'] ?? 'Gagal membaca /ip/dhcp-server/lease/print'),
                'data' => array(),
            );
        }

        $rows = isset($result['data']) && is_array($result['data']) ? $result['data'] : array();
        $leases = array();
        foreach ($rows as $row) {
            $address = trim((string) ($row['address'] ?? ''));
            if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if ($this->is_truthy($row['disabled'] ?? false)) {
                continue;
            }

            $leases[] = $row;
        }

        return array(
            'success' => true,
            'message' => 'OK',
            'data' => $leases,
        );
    }

    public function filter_ip_cidr($ip, $cidr = '10.0.0.0/10')
    {
        $ip = trim((string) $ip);
        $cidr = trim((string) $cidr);

        if ($ip === '' || $cidr === '' || strpos($cidr, '/') === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        list($network, $prefix) = explode('/', $cidr, 2);
        $network = trim((string) $network);
        $prefix = (int) $prefix;

        if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        if ($prefix < 0 || $prefix > 32) {
            return false;
        }

        $ip_long = ip2long($ip);
        $network_long = ip2long($network);
        if ($ip_long === false || $network_long === false) {
            return false;
        }

        $ip_long = (int) sprintf('%u', $ip_long);
        $network_long = (int) sprintf('%u', $network_long);

        $mask = $prefix === 0 ? 0 : ((~0 << (32 - $prefix)) & 0xFFFFFFFF);
        $mask = (int) sprintf('%u', $mask);

        return (($ip_long & $mask) === ($network_long & $mask));
    }

    public function parse_target_ip($target)
    {
        $ips = $this->parse_queue_target_ips($target);
        if (empty($ips)) {
            return '';
        }

        return (string) $ips[0];
    }

    public function disable_queue($queue_name)
    {
        return $this->set_queue_state($queue_name, true);
    }

    public function enable_queue($queue_name)
    {
        return $this->set_queue_state($queue_name, false);
    }

    public function sync_static_ip_arp($router_id = 0)
    {
        if (!$this->db->table_exists($this->customer_table)) {
            return array(
                'success' => false,
                'message' => 'Tabel customers tidak ditemukan.',
                'stats' => array(),
            );
        }

        $this->configure_mikrotik((int) $router_id);
        $this->refresh_customer_schema();

        $queue_result = $this->get_simple_queue();
        if (empty($queue_result['success'])) {
            return array(
                'success' => false,
                'message' => 'Gagal sinkronisasi Static IP router #' . (int) $this->active_router_id . ': ' . (string) $queue_result['message'],
                'stats' => array(),
            );
        }

        $arp_result = $this->get_active_arp();
        if (empty($arp_result['success'])) {
            return array(
                'success' => false,
                'message' => 'Gagal sync ARP aktif router #' . (int) $this->active_router_id . ': ' . (string) $arp_result['message'],
                'stats' => array(),
            );
        }

        $lease_result = $this->get_dhcp_leases();

        $queue_rows = $queue_result['data'];
        $arp_rows = $arp_result['data'];
        $arp_map = $this->map_active_arp_by_ip($arp_rows);
        $lease_rows = !empty($lease_result['success']) ? (array) ($lease_result['data'] ?? array()) : array();
        $lease_map = $this->map_dhcp_leases_by_ip($lease_rows);
        $allowed_cidrs = $this->get_static_sync_cidrs();

        $stats = array(
            'router_id' => (int) $this->active_router_id,
            'router_name' => (string) $this->active_router_name,
            'allowed_cidrs' => implode(',', $allowed_cidrs),
            'queue_total' => count($queue_rows),
            'arp_active_total' => count($arp_map),
            'dhcp_lease_total' => count($lease_map),
            'candidate_total' => 0,
            'inserted' => 0,
            'updated' => 0,
            'failed' => 0,
            'skipped_outside_cidr' => 0,
            'skipped_no_arp' => 0,
            'skipped_no_lease' => 0,
            'skipped_pppoe' => 0,
            'skipped_deleted_static' => 0,
            'terminated_missing_queue' => 0,
            'prune_checked' => 0,
            'lease_warning' => !empty($lease_result['success']) ? '' : (string) ($lease_result['message'] ?? ''),
            'errors' => array(),
        );
        $seen_ips = array();
        $seen_queue_names = array();

        foreach ($queue_rows as $queue_row) {
            $queue_name = trim((string) ($queue_row['name'] ?? ''));
            $targets = $this->parse_queue_target_ips((string) ($queue_row['target'] ?? ''));
            $package = $this->resolve_static_package_from_queue($queue_row);

            if (empty($targets)) {
                continue;
            }

            foreach ($targets as $ip) {
                if (!$this->ip_matches_any_cidr($ip, $allowed_cidrs)) {
                    $stats['skipped_outside_cidr']++;
                    continue;
                }

                $seen_ips[$ip] = $ip;
                if ($queue_name !== '') {
                    $seen_queue_names[$queue_name] = $queue_name;
                }

                $arp = isset($arp_map[$ip]) ? $arp_map[$ip] : array();
                $lease = isset($lease_map[$ip]) ? $lease_map[$ip] : array();
                if (empty($arp)) {
                    // Tetap sync walau ARP belum aktif agar data static tidak hilang.
                    // Counter ini sekarang menjadi indikator "no arp active",
                    // bukan alasan skip insert/update.
                    $stats['skipped_no_arp']++;
                }
                if (empty($lease)) {
                    $stats['skipped_no_lease']++;
                }
                $observation = $this->merge_static_observation($ip, $arp, $lease);

                $stats['candidate_total']++;
                if (empty($package['profile_id'])) {
                    $fallback_package = $this->resolve_package_from_existing_pppoe_customer_ip($ip);
                    if (!empty($fallback_package['profile_id'])) {
                        $package = $fallback_package;
                    } else {
                        $queue_debug = trim((string) ($queue_row['name'] ?? ''));
                        $max_limit_debug = trim((string) ($queue_row['max-limit'] ?? $queue_row['max_limit'] ?? ''));
                        log_message('debug', '[STATIC_IP_SYNC] package unresolved queue=' . $queue_debug . ' ip=' . $ip . ' max-limit=' . $max_limit_debug);
                    }
                }

                $existing = $this->find_existing_customer_for_static_ip($ip, $queue_name);
                if (!empty($existing)) {
                    if ($this->is_pppoe_customer($existing)) {
                        $stats['skipped_pppoe']++;
                        $existing_static = $this->find_existing_static_customer_for_sync($ip, $queue_name);
                        if (!empty($existing_static)) {
                            $existing = $existing_static;
                        } else {
                            // Jangan sentuh data PPP existing.
                            // Buat record STATIC terpisah jika belum ada pasangan static.
                            $existing = array();
                        }
                    }
                }

                if (!empty($existing)) {
                    $update = $this->build_static_update_payload($queue_name, $observation, $package);
                    if (empty($update)) {
                        continue;
                    }

                    $ok = $this->db->where('id', (int) $existing['id'])->update($this->customer_table, $update);
                    if ($ok) {
                        $stats['updated']++;
                    } else {
                        $stats['failed']++;
                        $stats['errors'][] = array(
                            'type' => 'update',
                            'customer_id' => (int) $existing['id'],
                            'ip' => $ip,
                            'queue_name' => $queue_name,
                            'error' => $this->db->error(),
                        );
                        log_message('error', '[STATIC_IP_SYNC] update gagal: ' . json_encode(end($stats['errors'])));
                    }
                    continue;
                }

                if ($this->has_deleted_static_tombstone($ip, $queue_name)) {
                    $stats['skipped_deleted_static']++;
                    continue;
                }

                $insert = $this->build_static_insert_payload($ip, $queue_name, $observation, $package);
                if (empty($insert)) {
                    $stats['failed']++;
                    $stats['errors'][] = array(
                        'type' => 'insert',
                        'ip' => $ip,
                        'queue_name' => $queue_name,
                        'error' => 'Payload insert kosong.',
                    );
                    continue;
                }

                $ok = $this->db->insert($this->customer_table, $insert);
                if ($ok) {
                    $stats['inserted']++;
                } else {
                    $stats['failed']++;
                    $stats['errors'][] = array(
                        'type' => 'insert',
                        'ip' => $ip,
                        'queue_name' => $queue_name,
                        'error' => $this->db->error(),
                    );
                    log_message('error', '[STATIC_IP_SYNC] insert gagal: ' . json_encode(end($stats['errors'])));
                }
            }
        }

        $prune = $this->prune_missing_static_customers(array_values($seen_ips), array_values($seen_queue_names));
        $stats['terminated_missing_queue'] = (int) ($prune['terminated'] ?? 0);
        $stats['prune_checked'] = (int) ($prune['checked'] ?? 0);
        if (!empty($prune['errors'])) {
            $stats['errors'] = array_merge($stats['errors'], (array) $prune['errors']);
            $stats['failed'] += count((array) $prune['errors']);
        }

        $synced = (int) $stats['inserted'] + (int) $stats['updated'];
        return array(
            'success' => true,
            'message' => 'Sinkronisasi Static IP selesai. Diperbarui: ' . $synced . ', dinonaktifkan: ' . (int) $stats['terminated_missing_queue'] . '.',
            'stats' => $stats,
        );
    }

    public function sync_static_ip_arp_all()
    {
        $routers = $this->get_active_router_targets();
        if (empty($routers)) {
            return $this->sync_static_ip_arp(0);
        }

        $aggregate = $this->empty_aggregate_stats();
        $results = array();
        $all_success = true;

        foreach ($routers as $router) {
            $router_id = (int) ($router['id'] ?? 0);
            try {
                $result = $this->sync_static_ip_arp($router_id);
            } catch (Throwable $e) {
                $result = array(
                    'success' => false,
                    'message' => $e->getMessage(),
                    'stats' => array('router_id' => $router_id),
                );
            } finally {
                $this->disconnect_mikrotik();
            }

            if (empty($result['success'])) {
                $all_success = false;
            }
            $results[] = $result;
            $this->merge_aggregate_stats($aggregate, (array) ($result['stats'] ?? array()));
        }

        return array(
            'success' => $all_success,
            'message' => 'Sync Static IP semua router selesai. Synced: ' . ((int) $aggregate['inserted'] + (int) $aggregate['updated']) . ', failed: ' . (int) $aggregate['failed'] . '.',
            'stats' => $aggregate,
            'results' => $results,
        );
    }

    public function check_static_isolir($router_id = 0)
    {
        if (!$this->db->table_exists($this->customer_table)) {
            return array(
                'success' => false,
                'message' => 'Tabel customers tidak ditemukan.',
                'stats' => array(),
            );
        }

        $this->configure_mikrotik((int) $router_id);
        $this->refresh_customer_schema();

        $stats = array(
            'router_id' => (int) $this->active_router_id,
            'router_name' => (string) $this->active_router_name,
            'total_static' => 0,
            'isolir_added' => 0,
            'isolir_removed' => 0,
            // Backward compatibility untuk UI lama:
            'disabled_queue' => 0,
            'enabled_queue' => 0,
            'skipped_no_queue' => 0,
            'skipped_no_ip' => 0,
            'failed' => 0,
            'errors' => array(),
        );

        $customers = $this->get_static_customers();
        $stats['total_static'] = count($customers);

        foreach ($customers as $customer) {
            $customer_id = (int) ($customer['id'] ?? 0);
            $queue_name = trim((string) ($customer['queue_name'] ?? $customer['username'] ?? ''));
            $remote_ip = trim((string) ($customer['ip_address'] ?? ''));
            $status = strtolower(trim((string) ($customer['status'] ?? 'active')));
            $isolate_by_invoice = $this->customer_has_overdue_for_isolir($customer_id, 5);

            if (!filter_var($remote_ip, FILTER_VALIDATE_IP)) {
                $stats['skipped_no_ip']++;
                $stats['skipped_no_queue']++;
                continue;
            }

            if ($status === 'suspended' || $isolate_by_invoice) {
                $result = $this->add_ip_to_isolir_list($remote_ip, 'AUTO-STATIC ' . $queue_name);
                if (!empty($result['success'])) {
                    $stats['isolir_added']++;
                    $stats['disabled_queue']++;
                } else {
                    $stats['failed']++;
                    $stats['errors'][] = array(
                        'action' => 'add_address_list',
                        'customer_id' => $customer_id,
                        'queue_name' => $queue_name,
                        'ip_address' => $remote_ip,
                        'error' => (string) ($result['message'] ?? 'unknown'),
                    );
                    log_message('error', '[STATIC_IP_ISOLIR] add address-list gagal: ' . json_encode(end($stats['errors'])));
                }
                continue;
            }

            if ($status === 'active' && !$isolate_by_invoice) {
                $result = $this->remove_ip_from_isolir_list($remote_ip);
                if (!empty($result['success'])) {
                    $stats['isolir_removed']++;
                    $stats['enabled_queue']++;
                } else {
                    $stats['failed']++;
                    $stats['errors'][] = array(
                        'action' => 'remove_address_list',
                        'customer_id' => $customer_id,
                        'queue_name' => $queue_name,
                        'ip_address' => $remote_ip,
                        'error' => (string) ($result['message'] ?? 'unknown'),
                    );
                    log_message('error', '[STATIC_IP_ISOLIR] remove address-list gagal: ' . json_encode(end($stats['errors'])));
                }
            }
        }

        return array(
            'success' => true,
            'message' => 'Check static isolir selesai.',
            'stats' => $stats,
        );
    }

    public function check_static_isolir_all()
    {
        $routers = $this->get_active_router_targets();
        if (empty($routers)) {
            return $this->check_static_isolir(0);
        }

        $aggregate = array(
            'total_static' => 0,
            'isolir_added' => 0,
            'isolir_removed' => 0,
            'disabled_queue' => 0,
            'enabled_queue' => 0,
            'skipped_no_queue' => 0,
            'skipped_no_ip' => 0,
            'failed' => 0,
            'errors' => array(),
        );
        $results = array();
        $all_success = true;

        foreach ($routers as $router) {
            $router_id = (int) ($router['id'] ?? 0);
            try {
                $result = $this->check_static_isolir($router_id);
            } catch (Throwable $e) {
                $result = array(
                    'success' => false,
                    'message' => $e->getMessage(),
                    'stats' => array('router_id' => $router_id),
                );
            } finally {
                $this->disconnect_mikrotik();
            }

            if (empty($result['success'])) {
                $all_success = false;
            }
            $results[] = $result;
            $stats = (array) ($result['stats'] ?? array());
            foreach ($aggregate as $key => $value) {
                if ($key === 'errors') {
                    if (!empty($stats['errors']) && is_array($stats['errors'])) {
                        $aggregate['errors'] = array_merge($aggregate['errors'], $stats['errors']);
                    }
                    continue;
                }
                if (isset($stats[$key]) && is_numeric($stats[$key])) {
                    $aggregate[$key] += (int) $stats[$key];
                }
            }
        }

        return array(
            'success' => $all_success,
            'message' => 'Check static isolir semua router selesai. Isolir added: ' . (int) $aggregate['isolir_added'] . ', failed: ' . (int) $aggregate['failed'] . '.',
            'stats' => $aggregate,
            'results' => $results,
        );
    }

    private function get_active_router_targets()
    {
        if (!method_exists($this->settings_model, 'get_active_routers')) {
            return array();
        }

        $routers = $this->settings_model->get_active_routers(null);
        if (!is_array($routers)) {
            return array();
        }

        $targets = array();
        foreach ($routers as $router) {
            $id = (int) ($router['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $targets[] = $router;
        }

        return $targets;
    }

    private function empty_aggregate_stats()
    {
        return array(
            'router_id' => 0,
            'router_name' => 'Semua Router',
            'allowed_cidrs' => implode(',', $this->get_static_sync_cidrs()),
            'queue_total' => 0,
            'arp_active_total' => 0,
            'dhcp_lease_total' => 0,
            'candidate_total' => 0,
            'inserted' => 0,
            'updated' => 0,
            'failed' => 0,
            'skipped_outside_cidr' => 0,
            'skipped_no_arp' => 0,
            'skipped_no_lease' => 0,
            'skipped_pppoe' => 0,
            'skipped_deleted_static' => 0,
            'terminated_missing_queue' => 0,
            'prune_checked' => 0,
            'lease_warning' => '',
            'errors' => array(),
        );
    }

    private function merge_aggregate_stats(array &$aggregate, array $stats)
    {
        foreach ($aggregate as $key => $value) {
            if ($key === 'errors') {
                if (!empty($stats['errors']) && is_array($stats['errors'])) {
                    $aggregate['errors'] = array_merge($aggregate['errors'], $stats['errors']);
                }
                continue;
            }
            if (in_array($key, array('router_id', 'router_name', 'allowed_cidrs', 'lease_warning'), true)) {
                if ($key === 'lease_warning' && !empty($stats[$key])) {
                    $aggregate[$key] = trim((string) $aggregate[$key] . ' ' . (string) $stats[$key]);
                }
                continue;
            }
            if (isset($stats[$key]) && is_numeric($stats[$key])) {
                $aggregate[$key] += (int) $stats[$key];
            }
        }
    }

    public function disconnect_mikrotik()
    {
        if (is_object($this->mikrotik_api) && method_exists($this->mikrotik_api, 'disconnect')) {
            $this->mikrotik_api->disconnect();
        }
    }

    private function configure_mikrotik($router_id = 0)
    {
        $router_id = (int) $router_id;
        $settings = $this->settings_model->get_mikrotik_settings($router_id);
        $has_required = is_array($settings)
            && trim((string) ($settings['host'] ?? '')) !== ''
            && trim((string) ($settings['username'] ?? '')) !== ''
            && (string) ($settings['password'] ?? '') !== '';

        if ($has_required && method_exists($this->mikrotik_api, 'configure')) {
            $this->disconnect_mikrotik();
            $this->mikrotik_api->configure($settings);
        }

        $this->active_router_id = (int) ($settings['router_id'] ?? $router_id);
        $this->active_router_name = trim((string) ($settings['router_name'] ?? ''));
    }

    private function refresh_customer_schema()
    {
        if (!$this->db->table_exists($this->customer_table)) {
            $this->customer_fields = array();
            $this->customer_meta = array();
            return;
        }

        $this->customer_fields = $this->db->list_fields($this->customer_table);
        $this->customer_meta = array();

        $table_name = (string) $this->db->dbprefix($this->customer_table);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table_name)) {
            $this->customer_meta = array();
            return;
        }

        $cols = $this->db
            ->query('SHOW COLUMNS FROM `' . $this->db->escape_str($table_name) . '`')
            ->result_array();
        foreach ($cols as $col) {
            $field = (string) ($col['Field'] ?? '');
            if ($field !== '') {
                $this->customer_meta[$field] = $col;
            }
        }
    }

    private function has_customer_field($field)
    {
        return in_array((string) $field, $this->customer_fields, true);
    }

    private function is_truthy($value)
    {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, array('1', 'true', 'yes', 'y', 'on'), true);
    }

    private function parse_queue_target_ips($target)
    {
        $target = trim((string) $target);
        if ($target === '') {
            return array();
        }

        $tokens = preg_split('/\s*,\s*/', $target);
        $ips = array();

        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            // Queue PPP dynamic: target berupa <pppoe-username>, abaikan.
            if (strpos($token, '<') !== false || strpos($token, '>') !== false) {
                continue;
            }

            // Jika format CIDR, hanya terima host /32.
            if (preg_match('/\/(\d+)$/', $token, $prefix_match)) {
                $prefix = (int) $prefix_match[1];
                if ($prefix < 32) {
                    continue;
                }
            }

            if (strpos($token, '-') !== false) {
                // Range target tidak diproses pada sync static customer host.
                continue;
            }

            $ip = preg_replace('/\/\d+$/', '', $token);
            $ip = trim((string) $ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            if (!isset($ips[$ip])) {
                $ips[$ip] = $ip;
            }
        }

        return array_values($ips);
    }

    private function map_active_arp_by_ip(array $arp_rows)
    {
        $map = array();
        foreach ($arp_rows as $row) {
            $ip = trim((string) ($row['address'] ?? ''));
            if ($ip === '') {
                continue;
            }
            if (!isset($map[$ip])) {
                $map[$ip] = $row;
            }
        }
        return $map;
    }

    private function map_dhcp_leases_by_ip(array $lease_rows)
    {
        $map = array();
        foreach ($lease_rows as $row) {
            $ip = trim((string) ($row['address'] ?? ''));
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!isset($map[$ip])) {
                $map[$ip] = $row;
            }
        }

        return $map;
    }

    private function merge_static_observation($ip, array $arp, array $lease)
    {
        $ip = trim((string) $ip);
        $mac = trim((string) ($arp['mac-address'] ?? $arp['mac_address'] ?? ''));
        if ($mac === '') {
            $mac = trim((string) ($lease['mac-address'] ?? $lease['mac_address'] ?? ''));
        }

        $source = array();
        if (!empty($arp)) {
            $source[] = 'arp';
        }
        if (!empty($lease)) {
            $source[] = 'dhcp_lease';
        }
        if (empty($source)) {
            $source[] = 'simple_queue';
        }

        return array(
            'address' => $ip,
            'mac-address' => $mac,
            'host-name' => trim((string) ($lease['host-name'] ?? $lease['host_name'] ?? '')),
            'comment' => trim((string) ($lease['comment'] ?? $arp['comment'] ?? '')),
            '_observed' => !empty($arp) || !empty($lease),
            '_source' => implode('+', $source),
        );
    }

    private function get_static_sync_cidrs()
    {
        $raw = trim((string) getenv('STATIC_IP_SYNC_CIDR'));
        if ($raw === '') {
            $raw = trim((string) config_item('static_ip_sync_cidr'));
        }
        if ($raw === '') {
            $raw = '10.0.0.0/10';
        }

        $cidrs = array();
        foreach (preg_split('/\s*,\s*/', $raw) as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr !== '' && strpos($cidr, '/') !== false) {
                $cidrs[] = $cidr;
            }
        }

        return !empty($cidrs) ? $cidrs : array('10.0.0.0/10');
    }

    private function ip_matches_any_cidr($ip, array $cidrs)
    {
        foreach ($cidrs as $cidr) {
            if ($this->filter_ip_cidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function apply_customer_router_scope(CI_DB_query_builder &$qb, $alias = '')
    {
        if ($this->active_router_id <= 0 || !$this->has_customer_field('router_id')) {
            return;
        }

        $prefix = $alias !== '' ? $alias . '.' : '';
        $qb->where($prefix . 'router_id', (int) $this->active_router_id);
    }

    private function apply_customer_soft_delete_scope(CI_DB_query_builder &$qb, $alias = '')
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        foreach (array('deleted_at' => null, 'is_deleted' => 0, 'deleted' => 0) as $column => $expected) {
            if (!$this->has_customer_field($column)) {
                continue;
            }

            if ($column === 'deleted_at') {
                $qb->where($prefix . $column . ' IS NULL', null, false);
            } else {
                $qb->where($prefix . $column, $expected);
            }
        }

        if ($this->has_customer_field('status')) {
            $deleted_statuses = $this->resolve_existing_customer_status_values(array('terminated', 'deleted', 'inactive', 'disabled'));
            if (!empty($deleted_statuses)) {
                $qb->where_not_in($prefix . 'status', $deleted_statuses);
            }
        }
    }

    private function find_existing_customer_for_static_ip($ip, $queue_name)
    {
        $ip = trim((string) $ip);
        $queue_name = trim((string) $queue_name);
        if ($ip === '' && $queue_name === '') {
            return array();
        }

        $qb = $this->db->from($this->customer_table);
        $qb->group_start();
        if ($ip !== '' && $this->has_customer_field('ip_address')) {
            $qb->where('ip_address', $ip);
        }
        if ($queue_name !== '' && $this->has_customer_field('queue_name')) {
            if ($ip !== '' && $this->has_customer_field('ip_address')) {
                $qb->or_where('queue_name', $queue_name);
            } else {
                $qb->where('queue_name', $queue_name);
            }
        }
        $qb->group_end();
        $this->apply_customer_soft_delete_scope($qb);
        $this->apply_customer_router_scope($qb);

        $row = $qb->order_by('id', 'DESC')->limit(1)->get()->row_array();
        return is_array($row) ? $row : array();
    }

    private function find_existing_static_customer_for_sync($ip, $queue_name)
    {
        $ip = trim((string) $ip);
        $queue_name = trim((string) $queue_name);
        $normalized_username = $this->normalize_queue_username($queue_name);

        if ($ip === '' && $queue_name === '' && $normalized_username === '') {
            return array();
        }

        $qb = $this->db->from($this->customer_table);
        $qb->group_start();

        $has_condition = false;
        if ($queue_name !== '' && $this->has_customer_field('queue_name')) {
            $qb->where('queue_name', $queue_name);
            $has_condition = true;
        }

        if ($normalized_username !== '' && $this->has_customer_field('username')) {
            if ($has_condition) {
                $qb->or_where('username', $normalized_username);
            } else {
                $qb->where('username', $normalized_username);
                $has_condition = true;
            }
        }

        if ($ip !== '' && $this->has_customer_field('ip_address')) {
            if ($has_condition) {
                $qb->or_where('ip_address', $ip);
            } else {
                $qb->where('ip_address', $ip);
            }
        }

        $qb->group_end();
        $this->apply_customer_soft_delete_scope($qb);
        $this->apply_customer_router_scope($qb);

        if ($this->has_customer_field('connection_type')) {
            $qb->where('UPPER(connection_type)', 'STATIC');
        } elseif ($this->has_customer_field('pppoe_username')) {
            $qb->where("COALESCE(pppoe_username, '') = ''", null, false);
        } elseif ($this->has_customer_field('notes')) {
            $qb->like('notes', 'Auto sync STATIC', 'after');
        }

        $row = $qb->order_by('id', 'DESC')->limit(1)->get()->row_array();
        return is_array($row) ? $row : array();
    }

    private function has_deleted_static_tombstone($ip, $queue_name)
    {
        if (!$this->has_customer_field('status')) {
            return false;
        }

        $deleted_statuses = $this->resolve_existing_customer_status_values(array('terminated', 'deleted', 'inactive', 'disabled'));
        if (empty($deleted_statuses)) {
            return false;
        }

        $ip = trim((string) $ip);
        $queue_name = trim((string) $queue_name);
        $normalized_username = $this->normalize_queue_username($queue_name);
        if ($ip === '' && $queue_name === '' && $normalized_username === '') {
            return false;
        }

        $qb = $this->db->from($this->customer_table);
        $qb->group_start();

        $has_condition = false;
        if ($queue_name !== '' && $this->has_customer_field('queue_name')) {
            $qb->where('queue_name', $queue_name);
            $has_condition = true;
        }
        if ($normalized_username !== '' && $this->has_customer_field('username')) {
            if ($has_condition) {
                $qb->or_where('username', $normalized_username);
            } else {
                $qb->where('username', $normalized_username);
                $has_condition = true;
            }
        }
        if ($ip !== '' && $this->has_customer_field('ip_address')) {
            if ($has_condition) {
                $qb->or_where('ip_address', $ip);
            } else {
                $qb->where('ip_address', $ip);
                $has_condition = true;
            }
        }

        if (!$has_condition) {
            $qb->where('1 = 0', null, false);
        }
        $qb->group_end();

        if ($this->has_customer_field('connection_type')) {
            $qb->where('UPPER(connection_type)', 'STATIC');
        }
        if ($this->has_customer_field('pppoe_username')) {
            $qb->where("COALESCE(pppoe_username, '') = ''", null, false);
        }
        if ($this->has_customer_field('router_id') && $this->active_router_id > 0) {
            $qb->where('router_id', (int) $this->active_router_id);
        }
        $qb->where_in('status', $deleted_statuses);

        return (int) $qb->count_all_results() > 0;
    }

    private function is_pppoe_customer(array $customer)
    {
        $connection_type = strtoupper(trim((string) ($customer['connection_type'] ?? '')));
        if ($connection_type === 'PPPOE') {
            return true;
        }

        if ($this->has_customer_field('pppoe_username')) {
            $pppoe_username = trim((string) ($customer['pppoe_username'] ?? ''));
            if ($pppoe_username !== '') {
                return true;
            }
        }

        return false;
    }

    private function build_static_update_payload($queue_name, array $arp, array $package = array())
    {
        $payload = array();
        $now = date('Y-m-d H:i:s');
        $normalized_username = $this->normalize_queue_username($queue_name);
        $has_valid_arp = !empty($arp) && filter_var((string) ($arp['address'] ?? ''), FILTER_VALIDATE_IP) !== false;

        if ($this->has_customer_field('connection_type')) {
            $payload['connection_type'] = 'STATIC';
        }
        if ($this->has_customer_field('router_id') && $this->active_router_id > 0) {
            $payload['router_id'] = (int) $this->active_router_id;
        }
        if ($this->has_customer_field('queue_name') && $queue_name !== '') {
            $payload['queue_name'] = $queue_name;
        } elseif ($this->has_customer_field('username') && $normalized_username !== '') {
            $payload['username'] = $normalized_username;
        }
        if ($this->has_customer_field('mac_address')) {
            $mac = trim((string) ($arp['mac-address'] ?? $arp['mac_address'] ?? ''));
            if ($mac !== '') {
                $payload['mac_address'] = $mac;
            }
        }
        if ($this->has_customer_field('last_seen') && $has_valid_arp && !empty($arp['_observed'])) {
            $payload['last_seen'] = $now;
        }
        if ($this->has_customer_field('static_source')) {
            $payload['static_source'] = (string) ($arp['_source'] ?? 'simple_queue');
        }
        if ($this->has_customer_field('updated_at')) {
            $payload['updated_at'] = $now;
        }
        if ($this->has_customer_field('profile_id') && !empty($package['profile_id'])) {
            $payload['profile_id'] = (int) $package['profile_id'];
        }
        if ($this->has_customer_field('package_price') && array_key_exists('price', $package) && $package['price'] !== null) {
            $payload['package_price'] = number_format((float) $package['price'], 2, '.', '');
        }

        return $payload;
    }

    private function build_static_insert_payload($ip, $queue_name, array $arp, array $package = array())
    {
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $safe_name = trim((string) $queue_name) !== '' ? trim((string) $queue_name) : ('STATIC-' . str_replace('.', '-', $ip));
        $safe_full_name = 'STATIC ' . $safe_name;
        $safe_username = $this->normalize_queue_username($safe_name);
        $has_valid_arp = !empty($arp) && filter_var((string) ($arp['address'] ?? ''), FILTER_VALIDATE_IP) !== false;

        $payload = array();

        if ($this->has_customer_field('connection_type')) {
            $payload['connection_type'] = 'STATIC';
        }
        if ($this->has_customer_field('router_id') && $this->active_router_id > 0) {
            $payload['router_id'] = (int) $this->active_router_id;
        }
        if ($this->has_customer_field('due_date_day')) {
            $due_date_day = $this->default_due_date_day_for_active_router();
            if ($due_date_day > 0) {
                $payload['due_date_day'] = $due_date_day;
            }
        }
        if ($this->has_customer_field('queue_name')) {
            $payload['queue_name'] = $safe_name;
        }
        if ($this->has_customer_field('ip_address')) {
            $payload['ip_address'] = $ip;
        }
        if ($this->has_customer_field('mac_address')) {
            $payload['mac_address'] = trim((string) ($arp['mac-address'] ?? $arp['mac_address'] ?? ''));
        }
        if ($this->has_customer_field('last_seen') && $has_valid_arp && !empty($arp['_observed'])) {
            $payload['last_seen'] = $now;
        }
        if ($this->has_customer_field('static_source')) {
            $payload['static_source'] = (string) ($arp['_source'] ?? 'simple_queue');
        }
        if ($this->has_customer_field('status')) {
            $payload['status'] = 'active';
        }
        if ($this->has_customer_field('profile_id') && !empty($package['profile_id'])) {
            $payload['profile_id'] = (int) $package['profile_id'];
        }
        if ($this->has_customer_field('package_price')) {
            $payload['package_price'] = number_format((float) ($package['price'] ?? 0), 2, '.', '');
        }
        if ($this->has_customer_field('full_name')) {
            $payload['full_name'] = $safe_full_name;
        }
        if ($this->has_customer_field('nama')) {
            $payload['nama'] = $safe_full_name;
        }
        if ($this->has_customer_field('username')) {
            $payload['username'] = $this->ensure_unique_static_username($safe_username, $ip);
        }
        if ($this->has_customer_field('customer_code')) {
            $payload['customer_code'] = $this->next_static_customer_code();
        }
        if ($this->has_customer_field('address')) {
            $payload['address'] = (string) ($payload['address'] ?? '-');
        }
        if ($this->has_customer_field('notes')) {
            $payload['notes'] = 'Data dibuat otomatis dari sinkronisasi Static IP.';
        }
        if ($this->has_customer_field('join_date')) {
            $payload['join_date'] = $today;
        }
        if ($this->has_customer_field('install_date')) {
            $payload['install_date'] = $today;
        }
        if ($this->has_customer_field('created_at')) {
            $payload['created_at'] = $now;
        }
        if ($this->has_customer_field('updated_at')) {
            $payload['updated_at'] = $now;
        }

        return $this->fill_required_defaults($payload);
    }

    private function default_due_date_day_for_active_router()
    {
        $router_name = strtolower(trim((string) $this->active_router_name));
        if ($router_name === '' && $this->active_router_id > 0 && $this->db->table_exists('routers')) {
            $row = $this->db
                ->select('name')
                ->from('routers')
                ->where('id', (int) $this->active_router_id)
                ->limit(1)
                ->get()
                ->row_array();
            $router_name = strtolower(trim((string) ($row['name'] ?? '')));
        }

        return $router_name === 'kalisari' ? 20 : 0;
    }

    private function resolve_static_package_from_queue(array $queue_row)
    {
        $profiles = $this->get_static_package_profiles();
        if (empty($profiles['by_code'])) {
            return array(
                'profile_id' => 0,
                'package_code' => '',
                'package_label' => '',
                'price' => 0.00,
                'speed_mbps' => 0,
            );
        }

        $code = '';
        $speed_mbps = 0;
        $max_limit = trim((string) ($queue_row['max-limit'] ?? $queue_row['max_limit'] ?? ''));
        if ($max_limit !== '') {
            list($speed_mbps, $code) = $this->extract_speed_from_rate_token($max_limit);
        }

        if ($code === '') {
            $queue_name = strtoupper(trim((string) ($queue_row['name'] ?? '')));
            if (preg_match('/(\d+)\s*M\b/', $queue_name, $m)) {
                $speed_mbps = (int) $m[1];
                $code = $speed_mbps . 'M';
            }
        }

        $profile = null;
        if ($code !== '' && isset($profiles['by_code'][$code])) {
            $profile = $profiles['by_code'][$code];
        } elseif ($speed_mbps > 0 && isset($profiles['by_speed'][$speed_mbps])) {
            $profile = $profiles['by_speed'][$speed_mbps];
        }

        if ($profile === null) {
            return array(
                'profile_id' => 0,
                'package_code' => $code,
                'package_label' => $this->format_package_label($speed_mbps, $code),
                'price' => 0.00,
                'speed_mbps' => $speed_mbps,
            );
        }

        $speed = (int) ($profile['speed_mbps'] ?? $speed_mbps);
        if ($speed <= 0) {
            $speed = $speed_mbps;
        }
        $package_code = (string) ($profile['code'] ?? $code);
        return array(
            'profile_id' => (int) ($profile['id'] ?? 0),
            'package_code' => $package_code,
            'package_label' => $this->format_package_label($speed, $package_code),
            'price' => (float) ($profile['price'] ?? 0),
            'speed_mbps' => $speed,
        );
    }

    private function get_static_package_profiles()
    {
        if ($this->package_profiles_cache !== null) {
            return $this->package_profiles_cache;
        }

        $cache = array(
            'by_code' => array(),
            'by_speed' => array(),
        );

        if (!$this->db->table_exists('ppp_profiles')) {
            $this->package_profiles_cache = $cache;
            return $cache;
        }

        $rows = $this->db
            ->select('id, name, rate_limit, price')
            ->from('ppp_profiles')
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            $name = strtoupper(trim((string) ($row['name'] ?? '')));
            $rate_limit = trim((string) ($row['rate_limit'] ?? ''));
            $speed_mbps = 0;
            $code = '';

            if (preg_match('/^(\d+)\s*M$/', $name, $m)) {
                $speed_mbps = (int) $m[1];
                $code = $speed_mbps . 'M';
            }

            if ($code === '' && $rate_limit !== '') {
                list($speed_mbps_from_rate, $code_from_rate) = $this->extract_speed_from_rate_token($rate_limit);
                if ($code_from_rate !== '') {
                    $speed_mbps = $speed_mbps_from_rate;
                    $code = $code_from_rate;
                }
            }

            if ($code === '') {
                continue;
            }

            $normalized = array(
                'id' => (int) ($row['id'] ?? 0),
                'name' => trim((string) ($row['name'] ?? '')),
                'price' => (float) ($row['price'] ?? 0),
                'code' => $code,
                'speed_mbps' => (int) $speed_mbps,
            );

            if (!isset($cache['by_code'][$code])) {
                $cache['by_code'][$code] = $normalized;
            }
            if ($speed_mbps > 0 && !isset($cache['by_speed'][$speed_mbps])) {
                $cache['by_speed'][$speed_mbps] = $normalized;
            }
        }

        $this->package_profiles_cache = $cache;
        return $cache;
    }

    private function extract_speed_from_rate_token($raw_rate)
    {
        $raw_rate = trim((string) $raw_rate);
        if ($raw_rate === '') {
            return array(0, '');
        }

        $download = $raw_rate;
        if (strpos($raw_rate, '/') !== false) {
            $parts = explode('/', $raw_rate, 2);
            $download = trim((string) ($parts[0] ?? ''));
        }
        $download = trim((string) $download);
        if ($download === '' || $download === '0') {
            return array(0, '');
        }

        $bps = $this->parse_routeros_rate_to_bps($download);
        if ($bps <= 0) {
            return array(0, '');
        }

        $speed_mbps = (int) round($bps / 1000000);
        if ($speed_mbps <= 0) {
            return array(0, '');
        }

        return array($speed_mbps, $speed_mbps . 'M');
    }

    private function parse_routeros_rate_to_bps($value)
    {
        $value = strtoupper(trim((string) $value));
        if ($value === '') {
            return 0.0;
        }

        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)([KMG])?$/', $value, $m)) {
            $number = (float) $m[1];
            $unit = isset($m[2]) ? $m[2] : '';
            switch ($unit) {
                case 'G':
                    return $number * 1000000000;
                case 'M':
                    return $number * 1000000;
                case 'K':
                    return $number * 1000;
                default:
                    return $number;
            }
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function format_package_label($speed_mbps, $package_code = '')
    {
        $speed_mbps = (int) $speed_mbps;
        $package_code = trim((string) $package_code);
        if ($speed_mbps > 0) {
            return $speed_mbps . ' M (' . $speed_mbps . ' Mbps)';
        }
        if ($package_code !== '') {
            return strtoupper($package_code);
        }

        return '-';
    }

    private function resolve_package_from_existing_pppoe_customer_ip($ip)
    {
        $ip = trim((string) $ip);
        if ($ip === '' || !$this->db->table_exists($this->customer_table) || !$this->has_customer_field('ip_address')) {
            return array();
        }

        $qb = $this->db
            ->from($this->customer_table)
            ->where('ip_address', $ip);
        if ($this->has_customer_field('profile_id')) {
            $qb->where('profile_id IS NOT NULL', null, false);
        }
        if ($this->has_customer_field('pppoe_username')) {
            $qb->where("COALESCE(pppoe_username, '') <> ''", null, false);
        }
        $this->apply_customer_router_scope($qb);
        $this->apply_customer_soft_delete_scope($qb);
        $row = $qb->order_by('id', 'DESC')->limit(1)->get()->row_array();

        if (empty($row)) {
            return array();
        }

        $profile_id = (int) ($row['profile_id'] ?? 0);
        if ($profile_id <= 0) {
            return array();
        }

        $profile = array();
        if ($this->db->table_exists('ppp_profiles')) {
            $profile = $this->db
                ->select('id, name, price')
                ->from('ppp_profiles')
                ->where('id', $profile_id)
                ->limit(1)
                ->get()
                ->row_array();
        }

        $name = trim((string) ($profile['name'] ?? ''));
        $code = '';
        if ($name !== '' && preg_match('/^(\d+)\s*M$/i', $name, $m)) {
            $code = (int) $m[1] . 'M';
        }

        $price = 0.00;
        if (!empty($profile)) {
            $price = (float) ($profile['price'] ?? 0);
        } elseif ($this->has_customer_field('package_price')) {
            $price = (float) ($row['package_price'] ?? 0);
        }

        return array(
            'profile_id' => $profile_id,
            'package_code' => $code,
            'package_label' => $name,
            'price' => $price,
            'speed_mbps' => 0,
        );
    }

    private function normalize_queue_username($value)
    {
        $safe = str_replace(' ', '-', trim((string) $value));
        $safe = preg_replace('/[^A-Za-z0-9\-_]/', '', $safe);
        $safe = trim((string) $safe, '-_');
        if ($safe === '') {
            return '';
        }

        return $safe;
    }

    private function ensure_unique_static_username($base_username, $ip = '')
    {
        $base = $this->normalize_queue_username($base_username);
        if ($base === '') {
            $base = 'STATIC-' . str_replace('.', '-', trim((string) $ip));
            $base = $this->normalize_queue_username($base);
        }
        if ($base === '') {
            $base = 'STATIC-' . date('YmdHis');
        }

        if (!$this->has_customer_field('username')) {
            return $base;
        }

        $candidate = $base;
        $max_try = 200;
        for ($i = 0; $i <= $max_try; $i++) {
            $qb = $this->db
                ->from($this->customer_table)
                ->where('username', $candidate);
            $this->apply_customer_soft_delete_scope($qb);
            $exists = $qb->count_all_results() > 0;
            if (!$exists) {
                return $candidate;
            }

            $candidate = $base . '-S' . ($i + 1);
        }

        return $base . '-S' . strtoupper(substr(md5(uniqid('', true)), 0, 4));
    }

    private function fill_required_defaults(array $payload)
    {
        foreach ($this->customer_meta as $field => $meta) {
            if (array_key_exists($field, $payload)) {
                continue;
            }
            if ($field === 'id') {
                continue;
            }

            $null_allowed = strtoupper((string) ($meta['Null'] ?? 'YES')) === 'YES';
            $default = $meta['Default'] ?? null;
            if ($null_allowed || $default !== null) {
                continue;
            }

            $type = strtolower((string) ($meta['Type'] ?? ''));
            if (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
                $payload[$field] = 0;
                continue;
            }

            if (strpos($type, 'date') !== false && strpos($type, 'time') === false) {
                $payload[$field] = date('Y-m-d');
                continue;
            }

            if (strpos($type, 'datetime') !== false || strpos($type, 'timestamp') !== false) {
                $payload[$field] = date('Y-m-d H:i:s');
                continue;
            }

            if (strpos($type, 'enum') === 0) {
                $payload[$field] = $this->default_enum_value($type);
                continue;
            }

            $payload[$field] = '';
        }

        return $payload;
    }

    private function default_enum_value($enum_type)
    {
        if (preg_match_all("/'([^']+)'/", (string) $enum_type, $matches) && !empty($matches[1])) {
            return (string) $matches[1][0];
        }
        return '';
    }

    private function next_static_customer_code()
    {
        $prefix = 'STC-' . date('Ymd') . '-';
        $base = $prefix . strtoupper(substr(md5(uniqid('', true)), 0, 6));

        if (!$this->has_customer_field('customer_code')) {
            return $base;
        }

        $exists = $this->db
            ->from($this->customer_table)
            ->where('customer_code', $base)
            ->count_all_results() > 0;

        if (!$exists) {
            return $base;
        }

        return $prefix . strtoupper(substr(md5(uniqid('x', true)), 0, 6));
    }

    private function set_queue_state($queue_name, $disable)
    {
        $queue_name = trim((string) $queue_name);
        if ($queue_name === '') {
            return array('success' => false, 'message' => 'Nama target kosong.');
        }

        $find = $this->mikrotik_api->command_safe('/queue/simple/print', array('?name' => $queue_name));
        if (empty($find['success'])) {
            return array(
                'success' => false,
                'message' => 'Gagal mencari target `' . $queue_name . '`: ' . (string) ($find['error'] ?? 'unknown'),
            );
        }

        $rows = isset($find['data']) && is_array($find['data']) ? $find['data'] : array();
        if (empty($rows[0]['.id'])) {
            $fallback = $this->mikrotik_api->command_safe('/queue/simple/print');
            if (!empty($fallback['success']) && !empty($fallback['data']) && is_array($fallback['data'])) {
                $target_name = strtolower($queue_name);
                $target_key = $this->normalize_queue_lookup_key($queue_name);
                foreach ($fallback['data'] as $row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    if ($name === '' || empty($row['.id'])) {
                        continue;
                    }

                    $name_lc = strtolower($name);
                    $name_key = $this->normalize_queue_lookup_key($name);
                    if ($name_lc === $target_name || ($target_key !== '' && $name_key === $target_key)) {
                        $rows = array($row);
                        break;
                    }
                }
            }
        }

        if (empty($rows[0]['.id'])) {
            return array(
                'success' => false,
                'message' => 'Target `' . $queue_name . '` tidak ditemukan.',
            );
        }

        $queue_id = (string) $rows[0]['.id'];
        $set = $this->mikrotik_api->command_safe('/queue/simple/set', array(
            '.id' => $queue_id,
            'disabled' => $disable ? 'yes' : 'no',
        ));

        if (empty($set['success'])) {
            return array(
                'success' => false,
                'message' => 'Gagal mengubah status target `' . $queue_name . '`: ' . (string) ($set['error'] ?? 'unknown'),
            );
        }

        return array(
            'success' => true,
            'message' => 'Status target `' . $queue_name . '` berhasil diperbarui.',
        );
    }

    private function get_static_customers()
    {
        $qb = $this->db->from($this->customer_table);
        if ($this->has_customer_field('id')) {
            $qb->select('id');
        }
        foreach (array('status', 'queue_name', 'username', 'ip_address', 'connection_type', 'pppoe_username') as $field) {
            if ($this->has_customer_field($field)) {
                $qb->select($field);
            }
        }

        if ($this->has_customer_field('connection_type')) {
            $qb->where('UPPER(connection_type)', 'STATIC');
        } else {
            if ($this->has_customer_field('queue_name')) {
                $qb->where("COALESCE(queue_name, '') <> ''", null, false);
            } elseif ($this->has_customer_field('notes')) {
                $qb->like('notes', 'Auto sync STATIC', 'after');
            } elseif ($this->has_customer_field('pppoe_username')) {
                $qb->where("COALESCE(pppoe_username, '') = ''", null, false);
            }
        }

        $this->apply_customer_soft_delete_scope($qb);
        $this->apply_customer_router_scope($qb);
        return $qb->get()->result_array();
    }

    private function prune_missing_static_customers(array $active_ips, array $active_queue_names)
    {
        $result = array(
            'checked' => 0,
            'terminated' => 0,
            'skipped' => 0,
            'errors' => array(),
        );

        if ($this->active_router_id <= 0 || !$this->db->table_exists($this->customer_table) || !$this->has_customer_field('id')) {
            return $result;
        }

        $active_ip_map = array();
        foreach ($active_ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '') {
                $active_ip_map[$ip] = true;
            }
        }

        $active_queue_exact = array();
        $active_queue_key = array();
        foreach ($active_queue_names as $queue_name) {
            $queue_name = trim((string) $queue_name);
            if ($queue_name === '') {
                continue;
            }
            $active_queue_exact[strtolower($queue_name)] = true;
            $key = $this->normalize_queue_lookup_key($queue_name);
            if ($key !== '') {
                $active_queue_key[$key] = true;
            }
        }

        $select = array('id');
        foreach (array('status', 'queue_name', 'username', 'ip_address', 'connection_type', 'pppoe_username', 'static_source', 'notes') as $field) {
            if ($this->has_customer_field($field)) {
                $select[] = $field;
            }
        }

        $qb = $this->db
            ->select(implode(', ', array_unique($select)), false)
            ->from($this->customer_table);

        if ($this->has_customer_field('connection_type')) {
            $qb->where('UPPER(connection_type)', 'STATIC');
        } else {
            $qb->group_start();
            $managed_filter = false;
            if ($this->has_customer_field('queue_name')) {
                $qb->where("COALESCE(queue_name, '') <> ''", null, false);
                $managed_filter = true;
            }
            if ($this->has_customer_field('static_source')) {
                $managed_filter ? $qb->or_like('static_source', 'simple_queue') : $qb->like('static_source', 'simple_queue');
                $managed_filter = true;
            }
            if ($this->has_customer_field('notes')) {
                $managed_filter ? $qb->or_like('notes', 'Auto sync STATIC') : $qb->like('notes', 'Auto sync STATIC');
                $managed_filter = true;
            }
            if (!$managed_filter) {
                $qb->where('1 = 0', null, false);
            }
            $qb->group_end();
        }

        if ($this->has_customer_field('pppoe_username')) {
            $qb->where("COALESCE(pppoe_username, '') = ''", null, false);
        }
        if ($this->has_customer_field('status')) {
            $terminated = $this->resolve_customer_status_value(array('terminated', 'inactive', 'disabled'));
            if ($terminated !== null) {
                $qb->where('status !=', $terminated);
            }
        }

        $this->apply_customer_soft_delete_scope($qb);
        $this->apply_customer_router_scope($qb);
        $rows = $qb->get()->result_array();

        foreach ($rows as $row) {
            $result['checked']++;

            $ip = trim((string) ($row['ip_address'] ?? ''));
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && !$this->ip_matches_any_cidr($ip, $this->get_static_sync_cidrs())) {
                $result['skipped']++;
                continue;
            }

            $queue_name = trim((string) ($row['queue_name'] ?? ''));
            $queue_key = $this->normalize_queue_lookup_key($queue_name !== '' ? $queue_name : (string) ($row['username'] ?? ''));

            $still_exists = false;
            if ($ip !== '' && isset($active_ip_map[$ip])) {
                $still_exists = true;
            }
            if (!$still_exists && $queue_name !== '' && isset($active_queue_exact[strtolower($queue_name)])) {
                $still_exists = true;
            }
            if (!$still_exists && $queue_key !== '' && isset($active_queue_key[$queue_key])) {
                $still_exists = true;
            }

            if ($still_exists) {
                continue;
            }

            $payload = $this->build_missing_static_payload($row);
            if (empty($payload)) {
                $result['skipped']++;
                continue;
            }

            $update_qb = $this->db->where('id', (int) ($row['id'] ?? 0));
            if ($this->has_customer_field('router_id')) {
                $update_qb->where('router_id', (int) $this->active_router_id);
            }
            $ok = $update_qb->update($this->customer_table, $payload);
            if ($ok) {
                $result['terminated']++;
                continue;
            }

            $error = $this->db->error();
            $result['errors'][] = array(
                'type' => 'prune_missing_static',
                'customer_id' => (int) ($row['id'] ?? 0),
                'ip' => $ip,
                'queue_name' => $queue_name,
                'error' => $error,
            );
            log_message('error', '[STATIC_IP_SYNC] prune missing static gagal: ' . json_encode(end($result['errors'])));
        }

        return $result;
    }

    private function build_missing_static_payload(array $row)
    {
        $payload = array();
        $now = date('Y-m-d H:i:s');

        if ($this->has_customer_field('status')) {
            $status = $this->resolve_customer_status_value(array('terminated', 'inactive', 'disabled'));
            if ($status !== null) {
                $payload['status'] = $status;
            }
        }
        if ($this->has_customer_field('static_source')) {
            $payload['static_source'] = 'simple_queue_missing';
        }
        if ($this->has_customer_field('notes')) {
            $note = 'Layanan static dinonaktifkan otomatis pada ' . $now;
            $existing = trim((string) ($row['notes'] ?? ''));
            if ($existing === '') {
                $payload['notes'] = $note;
            } elseif (strpos($existing, 'Layanan static dinonaktifkan otomatis') === false) {
                $payload['notes'] = $existing . "\n" . $note;
            }
        }
        if ($this->has_customer_field('updated_at')) {
            $payload['updated_at'] = $now;
        }

        if (!isset($payload['status'])) {
            unset($payload['static_source'], $payload['notes'], $payload['updated_at']);
        }

        return $payload;
    }

    private function resolve_customer_status_value(array $candidates)
    {
        if (!$this->has_customer_field('status')) {
            return null;
        }

        $allowed = $this->get_customer_status_values();
        if (!empty($allowed)) {
            foreach ($candidates as $candidate) {
                $candidate = strtolower(trim((string) $candidate));
                if ($candidate !== '' && in_array($candidate, $allowed, true)) {
                    return $candidate;
                }
            }
            return null;
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function resolve_existing_customer_status_values(array $candidates)
    {
        $allowed = $this->get_customer_status_values();
        if (empty($allowed)) {
            return array_values(array_filter(array_map('trim', $candidates), static function ($value) {
                return $value !== '';
            }));
        }

        $matched = array();
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ($candidate !== '' && in_array($candidate, $allowed, true)) {
                $matched[] = $candidate;
            }
        }

        return array_values(array_unique($matched));
    }

    private function get_customer_status_values()
    {
        if (isset($this->customer_meta['status']['_enum_values'])) {
            return (array) $this->customer_meta['status']['_enum_values'];
        }
        if (!$this->has_customer_field('status')) {
            return array();
        }

        $type = strtolower((string) ($this->customer_meta['status']['Type'] ?? ''));
        if ($type === '' || !preg_match('/^enum\((.*)\)$/i', $type, $matches)) {
            return array();
        }

        $values = array();
        foreach (str_getcsv($matches[1], ',', "'") as $value) {
            $value = strtolower(trim((string) $value));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        $this->customer_meta['status']['_enum_values'] = $values;
        return $values;
    }

    private function customer_has_overdue_for_isolir($customer_id, $grace_days = 5, $today = null)
    {
        if (!$this->db->table_exists('invoices')) {
            return false;
        }

        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return false;
        }
        $grace_days = max(0, (int) $grace_days);
        $today = $today ? (string) $today : date('Y-m-d');
        $cutoff = date('Y-m-d', strtotime($today . ' -' . $grace_days . ' day'));

        if ($this->invoice_fields === null) {
            $this->invoice_fields = $this->db->list_fields('invoices');
        }

        $qb = $this->db
            ->from('invoices')
            ->where('customer_id', $customer_id);

        if (in_array('balance_amount', $this->invoice_fields, true)) {
            $qb->where('balance_amount >', 0);
        } elseif (in_array('amount', $this->invoice_fields, true) && in_array('paid_amount', $this->invoice_fields, true)) {
            $qb->where('amount > paid_amount', null, false);
        } elseif (in_array('amount', $this->invoice_fields, true)) {
            $qb->where('amount >', 0);
        }

        $has_status = in_array('status', $this->invoice_fields, true);
        $has_due_date = in_array('due_date', $this->invoice_fields, true);
        $has_updated_at = in_array('updated_at', $this->invoice_fields, true);

        if ($has_status) {
            $qb->group_start();
            if ($has_due_date) {
                $qb->group_start()
                    ->where_in('status', array('issued', 'ISSUED', 'partially_paid', 'PARTIALLY_PAID', 'unpaid', 'UNPAID'))
                    ->where('due_date <=', $cutoff)
                ->group_end();
            }

            $qb->or_group_start()
                ->where_in('status', array('overdue', 'OVERDUE'));

            if ($has_updated_at) {
                $qb->where('DATE(updated_at) <=', $cutoff);
            } elseif ($has_due_date) {
                $qb->where('due_date <=', $cutoff);
            }

            $qb->group_end();
            $qb->group_end();
        } elseif ($has_due_date) {
            $qb->where('due_date <=', $cutoff);
        }

        return $qb->count_all_results() > 0;
    }

    private function normalize_queue_lookup_key($value)
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return '';
        }

        return preg_replace('/[^a-z0-9]/', '', $value);
    }

    private function get_isolir_list_name()
    {
        $name = trim((string) config_item('isolir_address_list'));
        return $name !== '' ? $name : 'ISOLIR';
    }

    private function add_ip_to_isolir_list($ip_address, $comment_label = '')
    {
        $ip_address = trim((string) $ip_address);
        if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
            return array('success' => false, 'message' => 'IP address tidak valid.');
        }

        $list_name = $this->get_isolir_list_name();
        $check = $this->mikrotik_api->command_safe('/ip/firewall/address-list/print', array(
            '?list' => $list_name,
            '?address' => $ip_address,
        ));

        if (!empty($check['success']) && !empty($check['data'])) {
            return array('success' => true, 'message' => 'IP sudah ada di ISOLIR');
        }

        $add = $this->mikrotik_api->command_safe('/ip/firewall/address-list/add', array(
            'list' => $list_name,
            'address' => $ip_address,
            'comment' => trim((string) $comment_label) !== '' ? trim((string) $comment_label) : ('AUTO-STATIC ' . $ip_address),
        ));

        if (empty($add['success'])) {
            return array('success' => false, 'message' => (string) ($add['error'] ?? 'Gagal add address-list'));
        }

        return array('success' => true, 'message' => 'IP ditambahkan ke ISOLIR');
    }

    private function remove_ip_from_isolir_list($ip_address)
    {
        $ip_address = trim((string) $ip_address);
        if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
            return array('success' => false, 'message' => 'IP address tidak valid.');
        }

        $list_name = $this->get_isolir_list_name();
        $find = $this->mikrotik_api->command_safe('/ip/firewall/address-list/print', array(
            '?list' => $list_name,
            '?address' => $ip_address,
        ));

        if (empty($find['success'])) {
            return array('success' => false, 'message' => (string) ($find['error'] ?? 'Gagal cek address-list'));
        }

        $rows = isset($find['data']) && is_array($find['data']) ? $find['data'] : array();
        if (empty($rows)) {
            return array('success' => true, 'message' => 'IP tidak ada di ISOLIR');
        }

        foreach ($rows as $row) {
            $id = trim((string) ($row['.id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $remove = $this->mikrotik_api->command_safe('/ip/firewall/address-list/remove', array('.id' => $id));
            if (empty($remove['success'])) {
                return array('success' => false, 'message' => (string) ($remove['error'] ?? 'Gagal remove address-list'));
            }
        }

        return array('success' => true, 'message' => 'IP dihapus dari ISOLIR');
    }
}
