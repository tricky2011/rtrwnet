<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ont extends MY_Controller
{
    private $connection_request_reachability = array();

    public function __construct()
    {
        $is_cli = is_cli();
        parent::__construct(!$is_cli);
        if (!$is_cli) {
            $this->require_role(array('superadmin', 'admin', 'teknisi'), 'Akses ditolak. Monitoring ONT hanya untuk user internal.');
        }

        $this->load->database();
        $this->load->model('ont_device_model');
        $this->load->library(array('form_validation', 'session'));
        $this->load->helper(array('url', 'form', 'router_scope'));
    }

    public function index($router_id = null)
    {
        if (!$this->ont_device_model->table_exists()) {
            $this->session->set_flashdata('error', 'Tabel ont_devices belum tersedia. Jalankan migration GenieACS terlebih dahulu.');
            return $this->load->view('ont/index', array(
                'rows' => array(),
                'pagination' => '',
                'search' => '',
                'status_filter' => '',
                'summary' => array('online' => 0, 'offline' => 0, 'total' => 0),
                'total_rows' => 0,
                'scope_router_id' => null,
            ));
        }

        $scope_router_id = $this->resolve_router_id_for_context($router_id);
        $status_filter = strtolower(trim((string) $this->input->get('status', true)));
        if (!in_array($status_filter, array('', 'online', 'offline'), true)) {
            $status_filter = '';
        }
        $search = trim((string) $this->input->get('search', true));

        $total_rows = $this->ont_device_model->count_filtered($status_filter, $search, $scope_router_id);
        $pager = $this->init_pagination('ont', $total_rows, 20, 3);
        $rows = $this->ont_device_model->get_paginated($pager['per_page'], $pager['offset'], $status_filter, $search, $scope_router_id);

        return $this->load->view('ont/index', array(
            'rows' => $rows,
            'pagination' => $pager['links'],
            'search' => $search,
            'status_filter' => $status_filter,
            'summary' => $this->ont_device_model->get_counts($scope_router_id),
            'total_rows' => $pager['total_rows'],
            'scope_router_id' => $scope_router_id,
        ));
    }

    public function online($router_id = null)
    {
        $_GET['status'] = 'online';
        return $this->index($router_id);
    }

    public function offline($router_id = null)
    {
        $_GET['status'] = 'offline';
        return $this->index($router_id);
    }

    public function detail($serial = '')
    {
        $serial = urldecode(trim((string) $serial));
        if ($serial === '') {
            show_404();
            return;
        }

        $requested_router_id = (int) $this->input->get('router_id', true);
        $scope_router_id = $this->resolve_router_id_for_context($requested_router_id > 0 ? $requested_router_id : null);

        $db_row = $this->ont_device_model->find_by_serial($serial, $scope_router_id);
        if (!$db_row) {
            $db_row = $this->ont_device_model->find_by_serial($serial, null);
        }

        $target_router_id = (int) ($db_row['router_id'] ?? 0);
        if ($target_router_id <= 0) {
            $target_router_id = $scope_router_id;
        }
        if ($target_router_id <= 0) {
            $this->session->set_flashdata('error', 'Router ONT tidak ditemukan. Pilih router aktif terlebih dahulu.');
            redirect('ont');
            return;
        }
        if (!$this->can_access_router($target_router_id)) {
            show_error('Akses ditolak untuk router tersebut.', 403);
            return;
        }

        $genieacs = $this->load_genieacs_client($target_router_id);
        $device = $genieacs->getDevice($serial);
        if (empty($device['success']) || empty($device['data'])) {
            $this->session->set_flashdata('error', 'Detail ONT gagal: ' . (string) ($device['message'] ?? 'Device tidak ditemukan.'));
            return redirect('ont');
        }

        return $this->load->view('ont/detail', array(
            'serial' => $serial,
            'device' => (array) $device['data'],
            'db_row' => $db_row,
            'scope_router_id' => $target_router_id,
        ));
    }

    public function reboot($serial = '')
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $serial = urldecode(trim((string) $serial));
        if ($serial === '') {
            $this->session->set_flashdata('error', 'Serial ONT kosong.');
            return redirect('ont');
        }

        $router_id = (int) $this->input->post('router_id', true);
        if ($router_id <= 0) {
            $db_row = $this->ont_device_model->find_by_serial($serial, null);
            $router_id = (int) ($db_row['router_id'] ?? 0);
        }
        if ($router_id <= 0) {
            $router_id = $this->resolve_router_id_for_context(null);
        }
        if ($router_id <= 0) {
            $this->session->set_flashdata('error', 'Router ONT tidak ditemukan.');
            return redirect('ont');
        }
        if (!$this->can_access_router($router_id)) {
            show_error('Akses ditolak untuk router tersebut.', 403);
            return;
        }

        $genieacs = $this->load_genieacs_client($router_id);
        $result = $genieacs->rebootDevice($serial);
        if (empty($result['success'])) {
            $this->session->set_flashdata('error', 'Reboot gagal: ' . (string) ($result['message'] ?? 'unknown error'));
            return redirect('ont');
        }

        $this->session->set_flashdata('success', 'Task reboot berhasil dikirim untuk serial ' . $serial . '.');
        return redirect('ont');
    }

    public function set_wifi()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $this->form_validation->set_rules('serial', 'Serial Number', 'trim|required|max_length[100]');
        $this->form_validation->set_rules('ssid', 'SSID', 'trim|required|max_length[100]');
        $this->form_validation->set_rules('password', 'Password WiFi', 'trim|required|min_length[8]|max_length[100]');
        $this->form_validation->set_rules('router_id', 'Router', 'trim|required|integer|greater_than[0]');

        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors(' ', ' '));
            return redirect('ont');
        }

        $serial = trim((string) $this->input->post('serial', true));
        $ssid = trim((string) $this->input->post('ssid', true));
        $password = (string) $this->input->post('password', true);
        $router_id = (int) $this->input->post('router_id', true);

        if (!$this->can_access_router($router_id)) {
            show_error('Akses ditolak untuk router tersebut.', 403);
            return;
        }

        $genieacs = $this->load_genieacs_client($router_id);
        $result = $genieacs->setWifi($serial, $ssid, $password);
        if (empty($result['success'])) {
            $this->session->set_flashdata('error', 'Set WiFi gagal: ' . (string) ($result['message'] ?? 'unknown error'));
            return redirect('ont');
        }

        if ($this->ont_device_model->table_exists()) {
            $this->ont_device_model->upsert(array(
                'router_id' => $router_id,
                'serial_number' => $serial,
                'ssid' => $ssid,
                'wifi_password' => $password,
            ));
        }

        $this->session->set_flashdata('success', 'SSID/PASSWORD berhasil diupdate dan reboot dikirim.');
        return redirect('ont');
    }

    public function sync($router_id = null)
    {
        $is_cli = is_cli();
        if (!$is_cli && !hasRole(array('superadmin', 'admin'))) {
            show_error('Akses ditolak.', 403);
            return;
        }

        if (!$this->ont_device_model->table_exists()) {
            $msg = 'Tabel ont_devices belum tersedia.';
            if ($is_cli) {
                echo $msg . PHP_EOL;
                return;
            }
            $this->session->set_flashdata('error', $msg);
            redirect('ont');
            return;
        }

        $requested_router_id = (int) $router_id;
        if ($requested_router_id <= 0) {
            $requested_router_id = (int) $this->input->get('router_id', true);
        }

        $router_ids = $this->resolve_sync_router_ids($requested_router_id, $is_cli);
        if (empty($router_ids)) {
            $msg = 'Tidak ada router target untuk sync ONT.';
            if ($is_cli) {
                echo $msg . PHP_EOL;
                return;
            }
            $this->session->set_flashdata('error', $msg);
            redirect('ont');
            return;
        }

        $agg = array(
            'total' => 0,
            'inserted' => 0,
            'updated' => 0,
            'online' => 0,
            'offline' => 0,
            'failed' => 0,
            'first_error' => '',
        );

        foreach ($router_ids as $rid) {
            $rid = (int) $rid;
            if ($rid <= 0) {
                continue;
            }

            try {
                $router = $this->get_router_row($rid);
                if (!$router) {
                    $agg['failed']++;
                    if ($agg['first_error'] === '') {
                        $agg['first_error'] = 'Router ID ' . $rid . ' tidak ditemukan.';
                    }
                    continue;
                }
                $router_nbi = trim((string) ($router['acs_nbi_url'] ?? ''));
                if ($router_nbi === '') {
                    $agg['failed']++;
                    if ($agg['first_error'] === '') {
                        $agg['first_error'] = 'Router ' . (string) ($router['name'] ?? ('#' . $rid)) . ' belum memiliki ACS NBI URL.';
                    }
                    continue;
                }

                $genieacs = $this->load_genieacs_client($rid);
                $result = $this->sync_devices_for_router($rid, $genieacs);
                $agg['total'] += (int) $result['total'];
                $agg['inserted'] += (int) $result['inserted'];
                $agg['updated'] += (int) $result['updated'];
                $agg['online'] += (int) $result['online'];
                $agg['offline'] += (int) $result['offline'];
                $agg['failed'] += (int) $result['failed'];

                if ($agg['first_error'] === '' && !empty($result['first_error'])) {
                    $agg['first_error'] = (string) $result['first_error'];
                }
            } catch (Throwable $e) {
                $agg['failed']++;
                log_message('error', '[ONT][SYNC][' . $rid . '] ' . $e->getMessage());
                if ($agg['first_error'] === '') {
                    $agg['first_error'] = 'Router #' . $rid . ': ' . $e->getMessage();
                }
            }
        }

        $message = sprintf(
            'Sync ONT selesai. Router: %d, Total: %d, Inserted: %d, Updated: %d, Online: %d, Offline: %d, Failed: %d',
            count($router_ids),
            (int) $agg['total'],
            (int) $agg['inserted'],
            (int) $agg['updated'],
            (int) $agg['online'],
            (int) $agg['offline'],
            (int) $agg['failed']
        );

        if ($is_cli) {
            echo $message . PHP_EOL;
            if (!empty($agg['first_error'])) {
                echo 'First error: ' . $agg['first_error'] . PHP_EOL;
            }
            return;
        }

        if (!empty($agg['failed'])) {
            $this->session->set_flashdata('error', $message . (!empty($agg['first_error']) ? ' (' . $agg['first_error'] . ')' : ''));
        } else {
            $this->session->set_flashdata('success', $message);
        }
        redirect('ont');
    }

    private function sync_devices_for_router($router_id, $genieacs)
    {
        $inserted = 0;
        $updated = 0;
        $failed = 0;
        $online = 0;
        $offline = 0;
        $first_error = '';

        $resp = $genieacs->getDevices(5000);
        if (empty($resp['success']) || !is_array($resp['data'])) {
            return array(
                'total' => 0,
                'inserted' => 0,
                'updated' => 0,
                'online' => 0,
                'offline' => 0,
                'failed' => 1,
                'first_error' => (string) ($resp['message'] ?? 'Gagal ambil data device dari GenieACS.'),
            );
        }

        $rows = $resp['data'];
        $total = count($rows);
        $genieacs_cfg = (array) $this->config->item('genieacs');
        $allowVirtualRefresh = !empty($genieacs_cfg['genieacs_sync_refresh_virtual_parameters']);
        $allowConnectionRequestOnlineCheck = !empty($genieacs_cfg['genieacs_connection_request_online_check']);
        $customer_fields = $this->db->table_exists('customers') ? $this->db->list_fields('customers') : array();
        $has_ont_device_id = in_array('ont_device_id', $customer_fields, true);
        $has_ont_serial = in_array('ont_serial', $customer_fields, true);
        $has_customer_router = in_array('router_id', $customer_fields, true);
        $has_pppoe_username = in_array('pppoe_username', $customer_fields, true);
        $has_username = in_array('username', $customer_fields, true);
        $has_ip_address = in_array('ip_address', $customer_fields, true);
        $customer_select = array('id');
        if ($has_pppoe_username) {
            $customer_select[] = 'pppoe_username';
        }
        if ($has_username) {
            $customer_select[] = 'username';
        }

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : array();
            $serial = trim((string) $genieacs->extractSerial($row));
            if ($serial === '') {
                $failed++;
                if ($first_error === '') {
                    $first_error = 'Ada device tanpa serial.';
                }
                continue;
            }

            $lastInformRaw = trim((string) $genieacs->extractLastInform($row));
            $lastInform = $this->normalize_datetime($lastInformRaw);
            $isOnline = $this->is_online($lastInform);
            if (!$isOnline && $allowConnectionRequestOnlineCheck) {
                $isOnline = $this->is_connection_request_reachable($genieacs->extractConnectionRequestUrl($row));
            }
            if ($isOnline) {
                $online++;
            } else {
                $offline++;
            }

            $deviceId = trim((string) ($row['_id'] ?? ''));
            $wanIp = trim((string) $genieacs->extractWanIp($row));
            $ssid = trim((string) $genieacs->extractSsid($row));
            $wifiPassword = trim((string) $genieacs->extractWifiPassword($row));
            $pppoeUsername = trim((string) $genieacs->extractPppoeUsername($row));
            $opticalRxDbm = trim((string) $genieacs->extractOpticalRxDbm($row));
            $matchedCustomer = array();

            // Beberapa vendor tidak mengirim value langsung; trigger refresh VirtualParameters
            // agar nilai redaman/password/pppoe bisa terbaca saat sync berikutnya.
            $needsVirtualRefresh = $allowVirtualRefresh
                && $isOnline
                && $deviceId !== ''
                && ($opticalRxDbm === '' || $wifiPassword === '' || $pppoeUsername === '');

            if ($needsVirtualRefresh) {
                $refreshedRow = $this->try_refresh_virtual_parameters($genieacs, $deviceId);
                if (!empty($refreshedRow)) {
                    $wanIp = trim((string) $genieacs->extractWanIp($refreshedRow)) ?: $wanIp;
                    $ssid = trim((string) $genieacs->extractSsid($refreshedRow)) ?: $ssid;
                    $wifiPassword = trim((string) $genieacs->extractWifiPassword($refreshedRow)) ?: $wifiPassword;
                    $pppoeUsername = trim((string) $genieacs->extractPppoeUsername($refreshedRow)) ?: $pppoeUsername;
                    $opticalRxDbm = trim((string) $genieacs->extractOpticalRxDbm($refreshedRow)) ?: $opticalRxDbm;
                }
            }

            $customerId = null;
            if ($has_ont_device_id || $has_ont_serial || $has_pppoe_username || $has_username || $has_ip_address) {
                // 1) Mapping utama berdasarkan ont_device_id / ont_serial.
                $this->db->select(implode(',', $customer_select))->from('customers');
                if ($has_customer_router && (int) $router_id > 0) {
                    $this->db->where('router_id', (int) $router_id);
                }
                if ($has_ont_device_id && $deviceId !== '') {
                    $this->db->group_start()->where('ont_device_id', $deviceId);
                    if ($has_ont_serial) {
                        $this->db->or_where('ont_serial', $serial);
                    }
                    $this->db->group_end();
                } elseif ($has_ont_serial) {
                    $this->db->where('ont_serial', $serial);
                }
                $c = $this->db->limit(1)->get()->row_array();
                if (!empty($c['id'])) {
                    $customerId = (int) $c['id'];
                    $matchedCustomer = $c;
                }

                // 2) Fallback berdasarkan username PPP.
                if (!$customerId && $pppoeUsername !== '' && ($has_pppoe_username || $has_username)) {
                    $this->db->select(implode(',', $customer_select))->from('customers');
                    if ($has_customer_router && (int) $router_id > 0) {
                        $this->db->where('router_id', (int) $router_id);
                    }
                    $this->db->group_start();
                    if ($has_pppoe_username) {
                        $this->db->where('pppoe_username', $pppoeUsername);
                    }
                    if ($has_username) {
                        if ($has_pppoe_username) {
                            $this->db->or_where('username', $pppoeUsername);
                        } else {
                            $this->db->where('username', $pppoeUsername);
                        }
                    }
                    $this->db->group_end();
                    $c = $this->db->limit(1)->get()->row_array();
                    if (!empty($c['id'])) {
                        $customerId = (int) $c['id'];
                        $matchedCustomer = $c;
                    }
                }

                // 3) Fallback berdasarkan WAN IP.
                if (!$customerId && $wanIp !== '' && $has_ip_address) {
                    $this->db->select(implode(',', $customer_select))->from('customers');
                    if ($has_customer_router && (int) $router_id > 0) {
                        $this->db->where('router_id', (int) $router_id);
                    }
                    $this->db->where('ip_address', $wanIp);
                    $c = $this->db->limit(1)->get()->row_array();
                    if (!empty($c['id'])) {
                        $customerId = (int) $c['id'];
                        $matchedCustomer = $c;
                    }
                }
            }

            $existing = $this->ont_device_model->find_by_serial($serial, (int) $router_id);
            if (!$customerId && !empty($existing['customer_id'])) {
                $customerId = (int) $existing['customer_id'];
            }
            if ($customerId && empty($matchedCustomer)) {
                $matchedCustomer = $this->load_customer_pppoe_row((int) $customerId, $customer_select);
            }
            if ($pppoeUsername === '' && !empty($matchedCustomer)) {
                $pppoeUsername = $this->customer_pppoe_value($matchedCustomer);
            }

            $payload = array(
                'router_id' => (int) $router_id,
                'customer_id' => $customerId,
                'serial_number' => $serial,
                'product_class' => $genieacs->extractProductClass($row),
                'manufacturer' => $genieacs->extractManufacturer($row),
                'wan_ip' => $wanIp,
                'ssid' => $ssid,
                'wifi_password' => $wifiPassword,
                'ont_username' => $pppoeUsername,
                'optical_rx_dbm' => $opticalRxDbm,
                'status' => $isOnline ? 'online' : 'offline',
                'last_inform' => $lastInform,
            );

            // Jangan timpa data existing dengan nilai kosong dari perangkat.
            if (!empty($existing)) {
                foreach (array('product_class', 'manufacturer', 'wan_ip', 'ssid', 'wifi_password', 'ont_username', 'optical_rx_dbm') as $f) {
                    $newVal = trim((string) ($payload[$f] ?? ''));
                    if ($newVal === '' && !empty($existing[$f])) {
                        if ($f === 'ont_username' && $this->is_likely_ont_identifier((string) $existing[$f], $serial)) {
                            continue;
                        }
                        $payload[$f] = $existing[$f];
                    }
                }
                if ((int) ($payload['customer_id'] ?? 0) <= 0 && !empty($existing['customer_id'])) {
                    $payload['customer_id'] = (int) $existing['customer_id'];
                }
            }

            $ok = $this->ont_device_model->upsert($payload);
            if (!$ok) {
                $failed++;
                $dbErr = $this->db->error();
                if ($first_error === '') {
                    $first_error = !empty($dbErr['message']) ? (string) $dbErr['message'] : ('Upsert gagal untuk serial ' . $serial);
                }
                continue;
            }

            if ($existing) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        return array(
            'total' => $total,
            'inserted' => $inserted,
            'updated' => $updated,
            'online' => $online,
            'offline' => $offline,
            'failed' => $failed,
            'first_error' => $first_error,
        );
    }

    private function resolve_sync_router_ids($requested_router_id, $is_cli)
    {
        $requested_router_id = (int) $requested_router_id;

        if ($requested_router_id > 0) {
            if (!$is_cli && !$this->can_access_router($requested_router_id)) {
                return array();
            }
            return array($requested_router_id);
        }

        if (!$is_cli) {
            $effective = (int) $this->getEffectiveRouterId();
            if ($effective > 0) {
                return array($effective);
            }
            if ($this->is_superadmin()) {
                return $this->get_active_router_ids();
            }
            return array();
        }

        return $this->get_active_router_ids();
    }

    private function get_active_router_ids()
    {
        if (!$this->db->table_exists('routers')) {
            return array();
        }

        $fields = $this->db->list_fields('routers');
        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : 'id');

        $qb = $this->db->select('id,' . $name_col . ' AS name', false)->from('routers');
        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('LOWER(status)', 'active');
        }
        if (in_array('acs_nbi_url', $fields, true)) {
            $qb->where('acs_nbi_url IS NOT NULL', null, false)->where('acs_nbi_url <>', '');
        }

        $rows = $qb->order_by($name_col, 'ASC')->get()->result_array();
        $ids = array();
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function get_router_row($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return null;
        }

        $fields = $this->db->list_fields('routers');
        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : 'id');

        $select = array('id', $name_col . ' AS name');
        if (in_array('acs_nbi_url', $fields, true)) {
            $select[] = 'acs_nbi_url';
        }
        if (in_array('acs_url', $fields, true)) {
            $select[] = 'acs_url';
        }

        return $this->db
            ->select(implode(',', $select), false)
            ->from('routers')
            ->where('id', $router_id)
            ->limit(1)
            ->get()
            ->row_array();
    }

    private function resolve_router_id_for_context($explicit_router_id = null)
    {
        $explicit_router_id = $explicit_router_id !== null ? (int) $explicit_router_id : 0;
        if ($explicit_router_id > 0) {
            if (!$this->can_access_router($explicit_router_id)) {
                show_error('Akses ditolak untuk router tersebut.', 403);
                return null;
            }

            if ($this->is_superadmin()) {
                $this->session->set_userdata('active_router', $explicit_router_id);
                $this->session->set_userdata('active_router_id', $explicit_router_id);
                $this->session->set_userdata('router_scope_id', $explicit_router_id);
                $this->session->set_userdata('dashboard_router_id', $explicit_router_id);
            }
            return $explicit_router_id;
        }

        $from_active = (int) $this->session->userdata('active_router_id');
        if ($from_active <= 0) {
            $from_active = (int) $this->session->userdata('active_router');
        }
        if ($from_active > 0) {
            return $from_active;
        }

        $effective = (int) $this->getEffectiveRouterId();
        return $effective > 0 ? $effective : null;
    }

    private function can_access_router($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0) {
            return false;
        }

        if ($this->is_superadmin()) {
            return true;
        }

        $scope = (int) $this->getEffectiveRouterId();
        return $scope > 0 && $scope === $router_id;
    }

    private function load_genieacs_client($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0) {
            throw new RuntimeException('Router ID tidak valid untuk load GenieACS client.');
        }

        // CI3 loader menyimpan instance library per alias; gunakan alias unik per router
        // agar loop multi-router tidak reuse client router sebelumnya.
        $alias = 'genieacs_client_' . $router_id;
        $this->load->library('genieacs', array('router_id' => $router_id), $alias);
        return $this->{$alias};
    }

    private function normalize_datetime($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function is_online($lastInform)
    {
        if (empty($lastInform)) {
            return false;
        }
        $ts = strtotime((string) $lastInform);
        if ($ts === false) {
            return false;
        }
        return $ts >= (time() - 600);
    }

    private function is_connection_request_reachable($url)
    {
        $url = trim((string) $url);
        if ($url === '' || !function_exists('curl_init')) {
            return false;
        }

        if (isset($this->connection_request_reachability[$url])) {
            return (bool) $this->connection_request_reachability[$url];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 800,
            CURLOPT_TIMEOUT_MS => 1500,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ));
        curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $reachable = $errno === 0 && $httpCode > 0 && $httpCode < 500;
        $this->connection_request_reachability[$url] = $reachable;
        return $reachable;
    }

    private function try_refresh_virtual_parameters($genieacs, $device_id)
    {
        $device_id = trim((string) $device_id);
        if ($device_id === '' || !is_object($genieacs)) {
            return array();
        }
        if (!method_exists($genieacs, 'refreshVirtualParameters') || !method_exists($genieacs, 'getDeviceById')) {
            return array();
        }

        $refreshNames = array(
            'RXPower',
            'WlanPassword',
            'pppoeUsername',
            'pppoeUsername2',
            'pppoeIP',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.4.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.4.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.5.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.5.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_EponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_GponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_PONInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_WANPONInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.WANPONInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CU_WANEPONInterfaceConfig.OpticalTransceiver.RXPower',
            'Device.PON.Interface.1.OpticalSignalLevel',
            'Device.PON.Interface.1.RXPower',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.PreSharedKey.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.PreSharedKey.1.PreSharedKey',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase',
            'Device.WiFi.AccessPoint.1.Security.KeyPassphrase',
            'Device.WiFi.AccessPoint.1.Security.PreSharedKey',
        );

        $refresh = $genieacs->refreshVirtualParameters($device_id, $refreshNames);
        if (empty($refresh['success'])) {
            return array();
        }

        // Beri jeda singkat bila task diproses via connection request.
        usleep(200000);

        $device = $genieacs->getDeviceById($device_id, array(
            'VirtualParameters.RXPower',
            'VirtualParameters.WlanPassword',
            'VirtualParameters.pppoeUsername',
            'VirtualParameters.pppoeUsername2',
            'VirtualParameters.pppoeIP',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_EponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_GponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_PONInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_WANPONInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.WANPONInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CU_WANEPONInterfaceConfig.OpticalTransceiver.RXPower',
            'Device.PON.Interface.1.OpticalSignalLevel',
            'Device.PON.Interface.1.RXPower',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.4.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.4.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.5.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.5.WANPPPConnection.1.Username',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.PreSharedKey.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.PreSharedKey.1.PreSharedKey',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase',
            'Device.WiFi.AccessPoint.1.Security.KeyPassphrase',
            'Device.WiFi.AccessPoint.1.Security.PreSharedKey',
        ));

        if (empty($device['success']) || empty($device['data']) || !is_array($device['data'])) {
            return array();
        }

        return $device['data'];
    }

    private function load_customer_pppoe_row($customer_id, array $select)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0 || !$this->db->table_exists('customers')) {
            return array();
        }

        $row = $this->db
            ->select(implode(',', $select))
            ->from('customers')
            ->where('id', $customer_id)
            ->limit(1)
            ->get()
            ->row_array();

        return is_array($row) ? $row : array();
    }

    private function customer_pppoe_value(array $row)
    {
        foreach (array('pppoe_username', 'username') as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function is_likely_ont_identifier($value, $serial = '')
    {
        $value = strtoupper(trim((string) $value));
        $serial = strtoupper(trim((string) $serial));
        if ($value === '') {
            return false;
        }
        if ($serial !== '' && $value === $serial) {
            return true;
        }
        if ($serial !== '' && strlen($value) >= 6 && substr($serial, -strlen($value)) === $value) {
            return true;
        }
        if (in_array($value, array('ADMIN', 'USER', 'ROOT', 'GLOBAL', 'XCU', 'XCT', 'CMCC', 'CU', 'CT'), true)) {
            return true;
        }
        if (!preg_match('/^[A-Z0-9]+$/', $value)) {
            return false;
        }
        return (bool) preg_match('/^(ZICG|ZXIC|CIOT|FHTT|CMDC|RTEG|ALCL|HWTC)[A-Z0-9]{6,}$/', $value);
    }
}
