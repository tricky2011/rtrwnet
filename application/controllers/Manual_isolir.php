<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Manual_isolir extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->database();
        $this->load->helper(array('url', 'form'));
        $this->load->library(array('billing_automation_service'));
        $this->load->model('settings_model');
        $this->load->model('static_ip_sync_model');
    }

    public function index()
    {
        $scope_router_id = (int) $this->resolve_manual_isolir_router_scope_id();
        $router_scope_required = $this->is_superadmin() && $scope_router_id <= 0;

        $this->load->view('isolir/manual', array(
            'user_options' => $router_scope_required ? array() : $this->get_user_target_options('', 3000),
            'router_scope_required' => $router_scope_required,
            'scope_router_id' => $scope_router_id,
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
        ));
    }

    public function popup()
    {
        $scope_router_id = (int) $this->resolve_manual_isolir_router_scope_id();
        $router_scope_required = $this->is_superadmin() && $scope_router_id <= 0;

        $this->load->view('isolir/manual_popup', array(
            'user_options' => $router_scope_required ? array() : $this->get_user_target_options('', 3000),
            'router_scope_required' => $router_scope_required,
            'scope_router_id' => $scope_router_id,
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
        ));
    }

    public function suggest_user()
    {
        if ($this->is_superadmin() && (int) $this->resolve_manual_isolir_router_scope_id() <= 0) {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Pilih router aktif terlebih dahulu sebelum menggunakan Manual Isolir.',
                'rows' => array(),
            ), 422);
        }

        $keyword = trim((string) $this->input->get('q', true));
        $rows = $this->get_user_target_options($keyword, 20);

        return $this->json_response(array(
            'success' => true,
            'rows' => $rows,
        ));
    }

    public function isolate_user()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Method Not Allowed',
            ), 405);
        }

        $target = trim((string) $this->input->post('pppoe_username', true));
        if ($target === '') {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Target user/queue wajib diisi.',
            ), 422);
        }

        try {
            if ($this->is_superadmin() && (int) $this->resolve_manual_isolir_router_scope_id() <= 0) {
                throw new Exception('Pilih router aktif terlebih dahulu sebelum isolir manual.');
            }

            $context = $this->resolve_customer_context_by_username($target);
            $customer_id = (int) ($context['customer_id'] ?? 0);
            $router_id = (int) ($context['router_id'] ?? 0);

            if ($customer_id <= 0 || $router_id <= 0) {
                $static_context = $this->resolve_static_context_by_identifier($target);
                $static_router_id = (int) ($static_context['router_id'] ?? 0);
                if ($static_router_id <= 0) {
                    $static_router_id = (int) $this->resolve_manual_isolir_router_scope_id();
                }
                $this->load->library('mikrotik_api');
                $mk = $this->settings_model->get_mikrotik_settings($static_router_id);
                $this->mikrotik_api->configure($mk);

                $static_result = $this->isolate_static_target($target, $static_context);
                log_message(
                    'info',
                    '[MANUAL_ISOLIR_UI] isolate static target=' . $target
                    . ' queue=' . (string) ($static_result['data']['queue_name'] ?? '')
                    . ' remote_ip=' . (string) ($static_result['data']['remote_ip'] ?? '')
                    . ' customer_id=' . (int) ($static_result['data']['customer_id'] ?? 0)
                );

                return $this->json_response($static_result);
            }

            $username = $target;
            $remote_ip = trim((string) ($context['remote_ip'] ?? ''));
            if (!filter_var($remote_ip, FILTER_VALIDATE_IP)) {
                $remote_ip = $this->resolve_remote_ip_from_local($username);
            }
            if (!filter_var($remote_ip, FILTER_VALIDATE_IP)) {
                throw new Exception('Remote IP kosong/tidak valid. Pastikan user online agar bisa redirect web proxy isolir.');
            }

            $isolation_profile = $this->resolve_isolation_profile_for_username($username);
            $suspend_result = $this->billing_automation_service->suspend_customer_in_mikrotik(
                $username,
                $isolation_profile,
                $remote_ip,
                $router_id,
                $customer_id
            );
            if (empty($suspend_result['success'])) {
                throw new Exception((string) ($suspend_result['message'] ?? 'Manual isolir gagal'));
            }

            $now = date('Y-m-d H:i:s');

            if ($customer_id > 0 && $this->db->table_exists('customers')) {
                $payload = array('status' => 'suspended');
                if ($this->table_has_column('customers', 'updated_at')) {
                    $payload['updated_at'] = $now;
                }
                $this->db->where('id', $customer_id)->update('customers', $payload);
            }

            if ($customer_id > 0 && $this->db->table_exists('customer_services')
                && $this->table_has_column('customer_services', 'customer_id')
                && $this->table_has_column('customer_services', 'status')
            ) {
                $service_payload = array('status' => 'suspended');
                if ($this->table_has_column('customer_services', 'updated_at')) {
                    $service_payload['updated_at'] = $now;
                }
                $this->db
                    ->where('customer_id', $customer_id)
                    ->where_in('status', array('active', 'ACTIVE'))
                    ->update('customer_services', $service_payload);
            }

            if ($customer_id > 0 && $this->db->table_exists('invoices')) {
                $invoice_payload = array('status' => 'overdue');
                if ($this->table_has_column('invoices', 'updated_at')) {
                    $invoice_payload['updated_at'] = $now;
                }

                $this->db
                    ->where('customer_id', $customer_id)
                    ->where_in('status', array('issued', 'partially_paid'))
                    ->where('balance_amount >', 0)
                    ->update('invoices', $invoice_payload);
            }

            log_message('info', '[MANUAL_ISOLIR_UI] isolate username=' . $username . ' remote_ip=' . $remote_ip . ' customer_id=' . $customer_id);

            return $this->json_response(array(
                'success' => true,
                'message' => 'User `' . $username . '` berhasil diisolir di router_id=' . $router_id . '. Remote IP `' . $remote_ip . '` masuk address-list ISOLIR.',
                'data' => array(
                    'username' => $username,
                    'remote_ip' => $remote_ip,
                    'customer_id' => $customer_id,
                    'router_id' => $router_id,
                ),
            ));
        } catch (Throwable $e) {
            log_message('error', '[MANUAL_ISOLIR_UI] isolate failed: ' . $e->getMessage());
            return $this->json_response(array(
                'success' => false,
                'message' => $e->getMessage(),
            ), 422);
        } finally {
            if (isset($this->mikrotik_api) && is_object($this->mikrotik_api)) {
                $this->mikrotik_api->disconnect();
            }
        }
    }

    public function release_user()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Method Not Allowed',
            ), 405);
        }

        $target = trim((string) $this->input->post('pppoe_username', true));
        if ($target === '') {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Target user/queue wajib diisi.',
            ), 422);
        }

        try {
            if ($this->is_superadmin() && (int) $this->resolve_manual_isolir_router_scope_id() <= 0) {
                throw new Exception('Pilih router aktif terlebih dahulu sebelum release manual.');
            }

            $context = $this->resolve_customer_context_by_username($target);
            $customer_id = (int) ($context['customer_id'] ?? 0);
            $router_id = (int) ($context['router_id'] ?? 0);

            if ($customer_id <= 0 || $router_id <= 0) {
                $static_context = $this->resolve_static_context_by_identifier($target);
                $static_router_id = (int) ($static_context['router_id'] ?? 0);
                if ($static_router_id <= 0) {
                    $static_router_id = (int) $this->resolve_manual_isolir_router_scope_id();
                }
                $this->load->library('mikrotik_api');
                $mk = $this->settings_model->get_mikrotik_settings($static_router_id);
                $this->mikrotik_api->configure($mk);

                $static_result = $this->release_static_target($target, $static_context);
                log_message(
                    'info',
                    '[MANUAL_ISOLIR_UI] release static target=' . $target
                    . ' queue=' . (string) ($static_result['data']['queue_name'] ?? '')
                    . ' customer_id=' . (int) ($static_result['data']['customer_id'] ?? 0)
                );

                return $this->json_response($static_result);
            }

            $username = $target;
            $normal_profile = $this->resolve_normal_profile_for_username($username, array(), $context);

            $activate_result = $this->billing_automation_service->activate_customer_in_mikrotik(
                $username,
                $normal_profile,
                $customer_id,
                $router_id
            );
            if (empty($activate_result['success'])) {
                throw new Exception((string) ($activate_result['message'] ?? 'Manual release gagal'));
            }

            $now = date('Y-m-d H:i:s');
            if ($customer_id > 0 && $this->db->table_exists('customers')) {
                $payload = array('status' => 'active');
                if ($this->table_has_column('customers', 'updated_at')) {
                    $payload['updated_at'] = $now;
                }
                $this->db->where('id', $customer_id)->update('customers', $payload);
            }

            if ($customer_id > 0 && $this->db->table_exists('customer_services')
                && $this->table_has_column('customer_services', 'customer_id')
                && $this->table_has_column('customer_services', 'status')
            ) {
                $service_payload = array('status' => 'active');
                if ($this->table_has_column('customer_services', 'updated_at')) {
                    $service_payload['updated_at'] = $now;
                }
                $this->db
                    ->where('customer_id', $customer_id)
                    ->where_in('status', array('suspended', 'isolated', 'isolir', 'disabled', 'inactive', 'SUSPENDED', 'ISOLATED', 'ISOLIR', 'DISABLED', 'INACTIVE'))
                    ->update('customer_services', $service_payload);
            }

            log_message('info', '[MANUAL_ISOLIR_UI] release username=' . $username . ' customer_id=' . $customer_id . ' profile=' . $normal_profile);

            return $this->json_response(array(
                'success' => true,
                'message' => 'User `' . $username . '` berhasil di-release dari ISOLIR (router_id=' . $router_id . ').',
                'data' => array(
                    'username' => $username,
                    'customer_id' => $customer_id,
                    'profile' => $normal_profile,
                    'router_id' => $router_id,
                ),
            ));
        } catch (Throwable $e) {
            log_message('error', '[MANUAL_ISOLIR_UI] release failed: ' . $e->getMessage());
            return $this->json_response(array(
                'success' => false,
                'message' => $e->getMessage(),
            ), 422);
        } finally {
            if (isset($this->mikrotik_api) && is_object($this->mikrotik_api)) {
                $this->mikrotik_api->disconnect();
            }
        }
    }

    private function get_user_target_options($keyword = '', $limit = 50)
    {
        $keyword = trim((string) $keyword);
        $limit = max(1, (int) $limit);
        $values = array();
        $scope_router_id = $this->resolve_manual_isolir_router_scope_id();
        if ($this->is_superadmin() && (int) $scope_router_id <= 0) {
            return array();
        }

        if ($this->db->table_exists('pppoe_secrets') && $this->table_has_column('pppoe_secrets', 'username')) {
            $qb = $this->db
                ->select('username')
                ->from('pppoe_secrets')
                ->where('username !=', '');
            if ($scope_router_id > 0 && $this->table_has_column('pppoe_secrets', 'router_id')) {
                $qb->where('router_id', $scope_router_id);
            }
            if ($keyword !== '') {
                $qb->like('username', $keyword);
            }
            $rows = $qb->order_by('username', 'ASC')->limit($limit)->get()->result_array();
            foreach ($rows as $row) {
                $username = trim((string) ($row['username'] ?? ''));
                if ($username !== '') {
                    $values[$username] = $username;
                }
            }
        }

        if (count($values) < $limit && $this->db->table_exists('customer_services') && $this->table_has_column('customer_services', 'pppoe_username')) {
            $qb = $this->db
                ->select('pppoe_username')
                ->from('customer_services')
                ->where('pppoe_username !=', '');
            if ($scope_router_id > 0 && $this->table_has_column('customer_services', 'router_id')) {
                $qb->where('router_id', $scope_router_id);
            }
            if ($keyword !== '') {
                $qb->like('pppoe_username', $keyword);
            }
            $rows = $qb->order_by('pppoe_username', 'ASC')->limit($limit)->get()->result_array();
            foreach ($rows as $row) {
                $username = trim((string) ($row['pppoe_username'] ?? ''));
                if ($username !== '') {
                    $values[$username] = $username;
                }
            }
        }

        if (count($values) < $limit && $this->db->table_exists('customers')) {
            $customer_fields = $this->db->list_fields('customers');
            foreach (array('pppoe_username', 'username') as $col) {
                if (!in_array($col, $customer_fields, true)) {
                    continue;
                }

                $qb = $this->db
                    ->select($col)
                    ->from('customers')
                    ->where($col . ' !=', '');
                if ($scope_router_id > 0 && in_array('router_id', $customer_fields, true)) {
                    $qb->where('router_id', $scope_router_id);
                }
                if ($keyword !== '') {
                    $qb->like($col, $keyword);
                }
                $rows = $qb->order_by($col, 'ASC')->limit($limit)->get()->result_array();
                foreach ($rows as $row) {
                    $username = trim((string) ($row[$col] ?? ''));
                    if ($username !== '') {
                        $values[$username] = $username;
                    }
                }
            }

            if (in_array('queue_name', $customer_fields, true)) {
                $qb = $this->db
                    ->select('queue_name')
                    ->from('customers')
                    ->where('queue_name !=', '');

                if (in_array('connection_type', $customer_fields, true)) {
                    $qb->where('UPPER(connection_type)', 'STATIC');
                }
                if ($scope_router_id > 0 && in_array('router_id', $customer_fields, true)) {
                    $qb->where('router_id', $scope_router_id);
                }
                if ($keyword !== '') {
                    $qb->like('queue_name', $keyword);
                }

                $rows = $qb->order_by('queue_name', 'ASC')->limit($limit)->get()->result_array();
                foreach ($rows as $row) {
                    $queue_name = trim((string) ($row['queue_name'] ?? ''));
                    if ($queue_name !== '') {
                        $values[$queue_name] = $queue_name;
                    }
                }
            }
        }

        if (empty($values)) {
            return array();
        }

        $list = array_values($values);
        natcasesort($list);
        $list = array_values($list);
        if (count($list) > $limit) {
            $list = array_slice($list, 0, $limit);
        }

        return $list;
    }

    private function isolate_static_target($target, array $context = array())
    {
        if (empty($context)) {
            $context = $this->resolve_static_context_by_identifier($target);
        }
        $queue_name = trim((string) ($context['queue_name'] ?? ''));
        if ($queue_name === '') {
            $queue_name = trim((string) $target);
        }
        if ($queue_name === '') {
            throw new Exception('Queue STATIC tidak ditemukan untuk target `' . $target . '`.');
        }

        $remote_ip = trim((string) ($context['remote_ip'] ?? ''));
        if (!filter_var($remote_ip, FILTER_VALIDATE_IP)) {
            $queue_row = $this->find_queue_row_by_name($queue_name);
            if (!empty($queue_row)) {
                $remote_ip = $this->extract_ip_from_queue_target((string) ($queue_row['target'] ?? ''));
            }
        }

        $isolir_added = false;
        if (filter_var($remote_ip, FILTER_VALIDATE_IP)) {
            $isolir_result = $this->add_ip_to_isolir_list($remote_ip, 'STATIC-' . $queue_name);
            if (empty($isolir_result['success'])) {
                throw new Exception('Gagal add address-list ISOLIR: ' . (string) ($isolir_result['message'] ?? 'unknown'));
            }
            $isolir_added = true;
        }

        $customer_id = (int) ($context['customer_id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        if ($customer_id > 0 && $this->db->table_exists('customers')) {
            $payload = array('status' => 'suspended');
            if ($this->table_has_column('customers', 'updated_at')) {
                $payload['updated_at'] = $now;
            }
            $this->db->where('id', $customer_id)->update('customers', $payload);
        }

        if ($customer_id > 0 && $this->db->table_exists('customer_services')
            && $this->table_has_column('customer_services', 'customer_id')
            && $this->table_has_column('customer_services', 'status')
        ) {
            $service_payload = array('status' => 'suspended');
            if ($this->table_has_column('customer_services', 'updated_at')) {
                $service_payload['updated_at'] = $now;
            }
            $this->db
                ->where('customer_id', $customer_id)
                ->where_in('status', array('active', 'ACTIVE'))
                ->update('customer_services', $service_payload);
        }

        if ($customer_id > 0 && $this->db->table_exists('invoices')) {
            $invoice_payload = array('status' => 'overdue');
            if ($this->table_has_column('invoices', 'updated_at')) {
                $invoice_payload['updated_at'] = $now;
            }

            $this->db
                ->where('customer_id', $customer_id)
                ->where_in('status', array('issued', 'partially_paid'))
                ->where('balance_amount >', 0)
                ->update('invoices', $invoice_payload);
        }

        $msg = 'Target STATIC `' . $queue_name . '` berhasil diisolir.';
        if ($isolir_added) {
            $msg .= ' Remote IP `' . $remote_ip . '` masuk address-list ISOLIR.';
        } elseif ($remote_ip === '') {
            $msg .= ' Remote IP tidak ditemukan, address-list dilewati.';
        }

        return array(
            'success' => true,
            'message' => $msg,
            'data' => array(
                'target' => $target,
                'queue_name' => $queue_name,
                'remote_ip' => $remote_ip,
                'customer_id' => $customer_id,
            ),
        );
    }

    private function release_static_target($target, array $context = array())
    {
        if (empty($context)) {
            $context = $this->resolve_static_context_by_identifier($target);
        }
        $queue_name = trim((string) ($context['queue_name'] ?? ''));
        if ($queue_name === '') {
            $queue_name = trim((string) $target);
        }
        if ($queue_name === '') {
            throw new Exception('Queue STATIC tidak ditemukan untuk target `' . $target . '`.');
        }

        $remote_ip = trim((string) ($context['remote_ip'] ?? ''));
        if (!filter_var($remote_ip, FILTER_VALIDATE_IP)) {
            $queue_row = $this->find_queue_row_by_name($queue_name);
            if (!empty($queue_row)) {
                $remote_ip = $this->extract_ip_from_queue_target((string) ($queue_row['target'] ?? ''));
            }
        }
        if (filter_var($remote_ip, FILTER_VALIDATE_IP)) {
            $remove_result = $this->remove_ip_from_isolir_list($remote_ip);
            if (empty($remove_result['success'])) {
                log_message('error', '[MANUAL_ISOLIR_UI] release static gagal remove address-list: ' . (string) ($remove_result['message'] ?? 'unknown'));
            }
        }

        $customer_id = (int) ($context['customer_id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        if ($customer_id > 0 && $this->db->table_exists('customers')) {
            $payload = array('status' => 'active');
            if ($this->table_has_column('customers', 'updated_at')) {
                $payload['updated_at'] = $now;
            }
            $this->db->where('id', $customer_id)->update('customers', $payload);
        }

        if ($customer_id > 0 && $this->db->table_exists('customer_services')
            && $this->table_has_column('customer_services', 'customer_id')
            && $this->table_has_column('customer_services', 'status')
        ) {
            $service_payload = array('status' => 'active');
            if ($this->table_has_column('customer_services', 'updated_at')) {
                $service_payload['updated_at'] = $now;
            }
            $this->db
                ->where('customer_id', $customer_id)
                ->where_in('status', array('suspended', 'isolated', 'isolir', 'disabled', 'inactive', 'SUSPENDED', 'ISOLATED', 'ISOLIR', 'DISABLED', 'INACTIVE'))
                ->update('customer_services', $service_payload);
        }

        return array(
            'success' => true,
            'message' => 'Target STATIC `' . $queue_name . '` berhasil di-release.',
            'data' => array(
                'target' => $target,
                'queue_name' => $queue_name,
                'remote_ip' => $remote_ip,
                'customer_id' => $customer_id,
            ),
        );
    }

    private function resolve_static_context_by_identifier($identifier)
    {
        $identifier = trim((string) $identifier);
        $context = array(
            'customer_id' => 0,
            'queue_name' => '',
            'remote_ip' => '',
            'router_id' => 0,
        );
        $scope_router_id = $this->resolve_manual_isolir_router_scope_id();
        if ($this->is_superadmin() && (int) $scope_router_id <= 0) {
            return $context;
        }

        if ($identifier === '' || !$this->db->table_exists('customers')) {
            return $context;
        }

        $fields = $this->db->list_fields('customers');
        $match_cols = array();
        foreach (array('queue_name', 'username', 'pppoe_username') as $col) {
            if (in_array($col, $fields, true)) {
                $match_cols[] = $col;
            }
        }
        if (empty($match_cols)) {
            return $context;
        }

        $select_cols = array('id');
        foreach (array('queue_name', 'ip_address', 'connection_type', 'router_id') as $col) {
            if (in_array($col, $fields, true)) {
                $select_cols[] = $col;
            }
        }

        $qb = $this->db->select(implode(',', $select_cols))->from('customers');
        $qb->group_start();
        foreach ($match_cols as $idx => $col) {
            if ($idx === 0) {
                $qb->where($col, $identifier);
            } else {
                $qb->or_where($col, $identifier);
            }
        }
        $qb->group_end();

        if (in_array('connection_type', $fields, true)) {
            $qb->group_start()
                ->where('UPPER(connection_type)', 'STATIC');
            if (in_array('queue_name', $fields, true)) {
                $qb->or_where('queue_name', $identifier);
            }
            $qb->group_end();
        }
        if ($scope_router_id > 0 && in_array('router_id', $fields, true)) {
            $qb->where('router_id', $scope_router_id);
        }

        $row = (array) $qb->order_by('id', 'DESC')->limit(1)->get()->row_array();
        if (!empty($row)) {
            $context['customer_id'] = (int) ($row['id'] ?? 0);
            $context['queue_name'] = trim((string) ($row['queue_name'] ?? ''));
            $context['remote_ip'] = trim((string) ($row['ip_address'] ?? ''));
            $context['router_id'] = (int) ($row['router_id'] ?? 0);
        }

        return $context;
    }

    private function find_queue_row_by_name($queue_name)
    {
        $queue_name = trim((string) $queue_name);
        if ($queue_name === '') {
            return array();
        }

        $result = $this->mikrotik_api->command_safe('/queue/simple/print', array('?name' => $queue_name));
        if (empty($result['success']) || empty($result['data'][0]) || !is_array($result['data'][0])) {
            return array();
        }

        return (array) $result['data'][0];
    }

    private function extract_ip_from_queue_target($target)
    {
        $target = trim((string) $target);
        if ($target === '') {
            return '';
        }

        $chunks = preg_split('/\s*,\s*/', $target);
        foreach ($chunks as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '' || strpos($chunk, '<') !== false || strpos($chunk, '>') !== false) {
                continue;
            }

            if (strpos($chunk, '-') !== false) {
                continue;
            }

            $ip = preg_replace('/\/\d+$/', '', $chunk);
            $ip = trim((string) $ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }
        }

        return '';
    }

    private function get_isolir_list_name()
    {
        $name = trim((string) $this->config->item('isolir_address_list'));
        return $name !== '' ? $name : 'ISOLIR';
    }

    private function add_ip_to_isolir_list($ip_address, $comment_label = '')
    {
        $ip_address = trim((string) $ip_address);
        if ($ip_address === '' || !filter_var($ip_address, FILTER_VALIDATE_IP)) {
            return array('success' => false, 'message' => 'IP address tidak valid');
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
            'comment' => trim((string) $comment_label) !== '' ? trim((string) $comment_label) : ('MANUAL-ISOLIR ' . $ip_address),
        ));

        if (empty($add['success'])) {
            return array('success' => false, 'message' => (string) ($add['error'] ?? 'unknown'));
        }

        return array('success' => true, 'message' => 'IP ditambahkan ke ISOLIR');
    }

    private function remove_ip_from_isolir_list($ip_address)
    {
        $ip_address = trim((string) $ip_address);
        if ($ip_address === '' || !filter_var($ip_address, FILTER_VALIDATE_IP)) {
            return array('success' => false, 'message' => 'IP address tidak valid');
        }

        $list_name = $this->get_isolir_list_name();
        $find = $this->mikrotik_api->command_safe('/ip/firewall/address-list/print', array(
            '?list' => $list_name,
            '?address' => $ip_address,
        ));
        if (empty($find['success'])) {
            return array('success' => false, 'message' => (string) ($find['error'] ?? 'unknown'));
        }
        if (empty($find['data']) || !is_array($find['data'])) {
            return array('success' => true, 'message' => 'IP tidak ada di ISOLIR');
        }

        foreach ($find['data'] as $row) {
            $id = trim((string) (($row['.id'] ?? $row['=.id'] ?? $row['id'] ?? '')));
            if ($id === '') {
                continue;
            }

            $remove = $this->mikrotik_api->command_safe('/ip/firewall/address-list/remove', array('.id' => $id));
            if (empty($remove['success'])) {
                return array('success' => false, 'message' => (string) ($remove['error'] ?? 'unknown'));
            }
        }

        return array('success' => true, 'message' => 'IP dihapus dari ISOLIR');
    }

    private function table_has_column($table, $column)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }

        return in_array((string) $column, $this->db->list_fields($table), true);
    }

    private function resolve_remote_ip_from_local($username)
    {
        $username = trim((string) $username);
        if ($username === '') {
            return '';
        }

        $context = $this->resolve_customer_context_by_username($username);
        $ip = trim((string) ($context['remote_ip'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return '';
    }

    private function resolve_isolation_profile_for_username($username, array $secret = array())
    {
        $context = $this->resolve_customer_context_by_username($username);
        $isolation_profile = trim((string) ($context['isolation_profile'] ?? ''));
        if ($isolation_profile !== '') {
            return $isolation_profile;
        }

        $secret_profile = trim((string) ($secret['profile'] ?? ''));
        if ($secret_profile !== ''
            && $this->db->table_exists('ppp_profiles')
            && $this->table_has_column('ppp_profiles', 'name')
            && $this->table_has_column('ppp_profiles', 'isolation_profile')
        ) {
            $row = (array) $this->db
                ->select('isolation_profile')
                ->from('ppp_profiles')
                ->where('name', $secret_profile)
                ->limit(1)
                ->get()
                ->row_array();
            $isolation_profile = trim((string) ($row['isolation_profile'] ?? ''));
            if ($isolation_profile !== '') {
                return $isolation_profile;
            }
        }

        return '';
    }

    private function resolve_normal_profile_for_username($username, array $secret = array(), array $context = array())
    {
        if (empty($context)) {
            $context = $this->resolve_customer_context_by_username($username);
        }

        $normal_profile = trim((string) ($context['profile_name'] ?? ''));
        if ($normal_profile !== '') {
            return $normal_profile;
        }

        $secret_profile = trim((string) ($secret['profile'] ?? ''));
        if ($secret_profile !== '') {
            return $secret_profile;
        }

        return '';
    }

    private function resolve_customer_context_by_username($username)
    {
        $username = trim((string) $username);
        $context = array(
            'customer_id' => 0,
            'profile_id' => 0,
            'profile_name' => '',
            'isolation_profile' => '',
            'remote_ip' => '',
            'router_id' => 0,
        );
        $scope_router_id = $this->resolve_manual_isolir_router_scope_id();
        if ($this->is_superadmin() && (int) $scope_router_id <= 0) {
            return $context;
        }

        if ($username === '') {
            return $context;
        }

        if ($this->db->table_exists('customer_services')
            && $this->table_has_column('customer_services', 'pppoe_username')
            && $this->table_has_column('customer_services', 'customer_id')
        ) {
            $select = array('customer_id');
            if ($this->table_has_column('customer_services', 'ppp_profile_id')) {
                $select[] = 'ppp_profile_id';
            }
            if ($this->table_has_column('customer_services', 'ip_address')) {
                $select[] = 'ip_address';
            }
            if ($this->table_has_column('customer_services', 'router_id')) {
                $select[] = 'router_id';
            }

            $qb = $this->db
                ->select(implode(',', $select))
                ->from('customer_services')
                ->where('pppoe_username', $username);
            if ($scope_router_id > 0 && $this->table_has_column('customer_services', 'router_id')) {
                $qb->where('router_id', $scope_router_id);
            }

            $row = (array) $qb->order_by('id', 'DESC')->limit(1)->get()->row_array();

            if (!empty($row)) {
                $context['customer_id'] = (int) ($row['customer_id'] ?? 0);
                $context['profile_id'] = (int) ($row['ppp_profile_id'] ?? 0);
                $context['remote_ip'] = trim((string) ($row['ip_address'] ?? ''));
                $context['router_id'] = (int) ($row['router_id'] ?? 0);
            }
        }

        if ($this->db->table_exists('customers')) {
            $customer_fields = $this->db->list_fields('customers');
            $username_cols = array();
            if (in_array('pppoe_username', $customer_fields, true)) {
                $username_cols[] = 'pppoe_username';
            }
            if (in_array('username', $customer_fields, true)) {
                $username_cols[] = 'username';
            }

            if (!empty($username_cols)) {
                $select = array('id');
                if (in_array('profile_id', $customer_fields, true)) {
                    $select[] = 'profile_id';
                }
                if (in_array('ip_address', $customer_fields, true)) {
                    $select[] = 'ip_address';
                }
                if (in_array('router_id', $customer_fields, true)) {
                    $select[] = 'router_id';
                }

                $qb = $this->db
                    ->select(implode(',', $select))
                    ->from('customers');

                $qb->group_start();
                foreach ($username_cols as $idx => $col) {
                    if ($idx === 0) {
                        $qb->where($col, $username);
                    } else {
                        $qb->or_where($col, $username);
                    }
                }
                $qb->group_end();
                if ($scope_router_id > 0 && in_array('router_id', $customer_fields, true)) {
                    $qb->where('router_id', $scope_router_id);
                }

                $row = (array) $qb->limit(1)->get()->row_array();
                if (!empty($row)) {
                    if ($context['customer_id'] <= 0) {
                        $context['customer_id'] = (int) ($row['id'] ?? 0);
                    }
                    if ($context['profile_id'] <= 0) {
                        $context['profile_id'] = (int) ($row['profile_id'] ?? 0);
                    }
                    if ($context['remote_ip'] === '') {
                        $context['remote_ip'] = trim((string) ($row['ip_address'] ?? ''));
                    }
                    if ($context['router_id'] <= 0) {
                        $context['router_id'] = (int) ($row['router_id'] ?? 0);
                    }
                }
            }
        }

        if ($context['profile_id'] > 0 && $this->db->table_exists('ppp_profiles')) {
            $select = array();
            if ($this->table_has_column('ppp_profiles', 'name')) {
                $select[] = 'name';
            }
            if ($this->table_has_column('ppp_profiles', 'isolation_profile')) {
                $select[] = 'isolation_profile';
            }

            if (!empty($select)) {
                $qb = $this->db
                    ->select(implode(',', $select))
                    ->from('ppp_profiles')
                    ->where('id', $context['profile_id']);
                if ($scope_router_id > 0 && $this->table_has_column('ppp_profiles', 'router_id')) {
                    $qb->where('router_id', $scope_router_id);
                }

                $row = (array) $qb->limit(1)->get()->row_array();
                $context['profile_name'] = trim((string) ($row['name'] ?? ''));
                $context['isolation_profile'] = trim((string) ($row['isolation_profile'] ?? ''));
            }
        }

        return $context;
    }

    private function resolve_manual_isolir_router_scope_id()
    {
        $effective_router_id = $this->getEffectiveRouterId();
        return ($effective_router_id !== null && (int) $effective_router_id > 0)
            ? (int) $effective_router_id
            : 0;
    }

    private function json_response(array $payload, $status_code = 200)
    {
        $payload['csrf_name'] = $this->security->get_csrf_token_name();
        $payload['csrf_hash'] = $this->security->get_csrf_hash();

        return $this->output
            ->set_status_header((int) $status_code)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
    }
}
