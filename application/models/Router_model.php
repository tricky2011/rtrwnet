<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Router_model extends CI_Model
{
    private $table = 'routers';
    private $fields_cache = null;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('encryption');
        $this->load->model('settings_model');
    }

    public function table_exists()
    {
        return $this->db->table_exists($this->table);
    }

    public function count_filtered($search = '')
    {
        if (!$this->table_exists()) {
            return 0;
        }

        $qb = $this->db->from($this->table);
        $this->apply_search($qb, $search);
        return (int) $qb->count_all_results();
    }

    public function get_paginated($limit = 20, $offset = 0, $search = '')
    {
        if (!$this->table_exists()) {
            return array();
        }

        $qb = $this->db->from($this->table);
        $this->apply_search($qb, $search);
        $rows = $qb
            ->order_by('id', 'DESC')
            ->limit((int) $limit, (int) $offset)
            ->get()
            ->result_array();

        return array_map(array($this, 'normalize_row'), $rows);
    }

    public function get_by_id($id)
    {
        if (!$this->table_exists()) {
            return null;
        }

        $row = $this->db
            ->from($this->table)
            ->where('id', (int) $id)
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($row)) {
            return null;
        }

        return $this->normalize_row($row);
    }

    public function exists_name($name, $exclude_id = 0)
    {
        if (!$this->table_exists()) {
            return false;
        }

        $name = trim((string) $name);
        if ($name === '') {
            return false;
        }

        $qb = $this->db->from($this->table);
        if ((int) $exclude_id > 0) {
            $qb->where('id !=', (int) $exclude_id);
        }

        $qb->group_start();
        if ($this->has_field('name')) {
            $qb->where('name', $name);
        }
        if ($this->has_field('router_name')) {
            if ($this->has_field('name')) {
                $qb->or_where('router_name', $name);
            } else {
                $qb->where('router_name', $name);
            }
        }
        $qb->group_end();

        return $qb->count_all_results() > 0;
    }

    public function insert(array $payload)
    {
        if (!$this->table_exists()) {
            return false;
        }

        $data = $this->build_payload($payload);
        if (empty($data)) {
            return false;
        }

        $ok = $this->db->insert($this->table, $data);
        if (!$ok) {
            return false;
        }

        return (int) $this->db->insert_id();
    }

    public function update($id, array $payload)
    {
        if (!$this->table_exists()) {
            return false;
        }

        $existing = $this->db
            ->from($this->table)
            ->where('id', (int) $id)
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($existing)) {
            return false;
        }

        $data = $this->build_payload($payload, $existing);
        if (empty($data)) {
            return false;
        }

        return $this->db->where('id', (int) $id)->update($this->table, $data);
    }

    public function update_acs($id, array $payload)
    {
        if (!$this->table_exists()) {
            return false;
        }

        $existing = $this->db
            ->from($this->table)
            ->where('id', (int) $id)
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($existing)) {
            return false;
        }

        $data = array();
        if ($this->has_field('acs_url')) {
            $acs_url = trim((string) ($payload['acs_url'] ?? ''));
            $data['acs_url'] = $acs_url !== '' ? $acs_url : null;
        }
        if ($this->has_field('acs_nbi_url')) {
            $acs_nbi_url = trim((string) ($payload['acs_nbi_url'] ?? ''));
            $data['acs_nbi_url'] = $acs_nbi_url !== '' ? $acs_nbi_url : null;
        }
        if ($this->has_field('acs_username')) {
            $acs_username = trim((string) ($payload['acs_username'] ?? ''));
            $data['acs_username'] = $acs_username !== '' ? $acs_username : null;
        }
        if ($this->has_field('acs_password') && array_key_exists('acs_password', $payload)) {
            $acs_password = trim((string) $payload['acs_password']);
            if ($acs_password !== '') {
                $encrypted = $this->encrypt_router_secret($acs_password);
                if ($encrypted === false) {
                    return false;
                }
                $data['acs_password'] = $encrypted;
            } else {
                $current_password = trim((string) ($existing['acs_password'] ?? ''));
                $data['acs_password'] = $current_password !== '' ? $current_password : null;
            }
        }
        if ($this->has_field('acs_status') && array_key_exists('acs_status', $payload)) {
            $acs_status = strtolower(trim((string) $payload['acs_status']));
            if (!in_array($acs_status, array('connected', 'disconnected'), true)) {
                $acs_status = strtolower(trim((string) ($existing['acs_status'] ?? 'disconnected')));
            }
            if (!in_array($acs_status, array('connected', 'disconnected'), true)) {
                $acs_status = 'disconnected';
            }
            $data['acs_status'] = $acs_status;
        }
        if ($this->has_field('updated_at')) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        if (empty($data)) {
            return true;
        }

        return $this->db->where('id', (int) $id)->update($this->table, $data);
    }

    public function delete_by_role($id, $role)
    {
        if (!$this->table_exists()) {
            return false;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $role = strtolower(trim((string) $role));
        if ($role === 'superadmin') {
            return $this->db->where('id', $id)->delete($this->table);
        }

        $data = array();
        if ($this->has_field('is_active')) {
            $data['is_active'] = 0;
        }
        if ($this->has_field('status')) {
            $data['status'] = 'inactive';
        }
        if ($this->has_field('updated_at')) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        if (empty($data)) {
            return $this->db->where('id', $id)->delete($this->table);
        }

        return $this->db->where('id', $id)->update($this->table, $data);
    }

    private function build_payload(array $input, array $existing = null)
    {
        $name = trim((string) ($input['name'] ?? ''));
        $host = trim((string) ($input['ip_address'] ?? ''));
        $port = (int) ($input['api_port'] ?? 8728);
        $username = trim((string) ($input['username'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $brand_name = trim((string) ($input['brand_name'] ?? ''));
        $brand_logo = trim((string) ($input['brand_logo'] ?? ''));
        $brand_address = trim((string) ($input['brand_address'] ?? ''));
        $brand_phone = trim((string) ($input['brand_phone'] ?? ''));
        $brand_email = trim((string) ($input['brand_email'] ?? ''));
        $brand_website = trim((string) ($input['brand_website'] ?? ''));
        $brand_bank_name = trim((string) ($input['brand_bank_name'] ?? ''));
        $brand_bank_account = trim((string) ($input['brand_bank_account'] ?? ''));
        $brand_bank_holder = trim((string) ($input['brand_bank_holder'] ?? ''));
        $invoice_footer = trim((string) ($input['invoice_footer'] ?? ''));
        $acs_url = trim((string) ($input['acs_url'] ?? ''));
        $acs_nbi_url = trim((string) ($input['acs_nbi_url'] ?? ''));
        $acs_username = trim((string) ($input['acs_username'] ?? ''));
        $acs_password = array_key_exists('acs_password', $input)
            ? trim((string) $input['acs_password'])
            : '';
        $acs_status = strtolower(trim((string) ($input['acs_status'] ?? '')));
        $use_ssl = !empty($input['use_ssl']) ? 1 : 0;
        $timeout_seconds = (int) ($input['timeout_seconds'] ?? 5);
        $is_active = !empty($input['is_active']) ? 1 : 0;
        $raw_password = array_key_exists('password', $input) ? trim((string) $input['password']) : '';

        if ($port <= 0) {
            $port = 8728;
        }
        if ($timeout_seconds <= 0) {
            $timeout_seconds = 5;
        }

        $password_enc = '';
        if ($raw_password !== '') {
            $password_enc = $this->encrypt_router_secret($raw_password);
            if ($password_enc === false) {
                return array();
            }
        } elseif (is_array($existing)) {
            $password_enc = trim((string) ($existing['password'] ?? $existing['api_password_enc'] ?? ''));
        }

        $data = array();
        if ($this->has_field('name')) {
            $data['name'] = $name;
        }
        if ($this->has_field('router_name')) {
            $data['router_name'] = $name;
        }
        if ($this->has_field('ip_address')) {
            $data['ip_address'] = $host;
        }
        if ($this->has_field('api_host')) {
            $data['api_host'] = $host;
        }
        if ($this->has_field('api_port')) {
            $data['api_port'] = $port;
        }
        if ($this->has_field('username')) {
            $data['username'] = $username;
        }
        if ($this->has_field('api_username')) {
            $data['api_username'] = $username;
        }
        if ($this->has_field('password')) {
            $data['password'] = $password_enc;
        }
        if ($this->has_field('api_password_enc')) {
            $data['api_password_enc'] = $password_enc;
        }
        if ($this->has_field('description')) {
            $data['description'] = $description !== '' ? $description : null;
        }
        if ($this->has_field('brand_name')) {
            $data['brand_name'] = $brand_name !== '' ? $brand_name : null;
        }
        if ($this->has_field('brand_logo')) {
            $data['brand_logo'] = $brand_logo !== '' ? $brand_logo : null;
        }
        if ($this->has_field('brand_address')) {
            $data['brand_address'] = $brand_address !== '' ? $brand_address : null;
        }
        if ($this->has_field('brand_phone')) {
            $data['brand_phone'] = $brand_phone !== '' ? $brand_phone : null;
        }
        if ($this->has_field('brand_email')) {
            $data['brand_email'] = $brand_email !== '' ? $brand_email : null;
        }
        if ($this->has_field('brand_website')) {
            $data['brand_website'] = $brand_website !== '' ? $brand_website : null;
        }
        if ($this->has_field('brand_bank_name')) {
            $data['brand_bank_name'] = $brand_bank_name !== '' ? $brand_bank_name : null;
        }
        if ($this->has_field('brand_bank_account')) {
            $data['brand_bank_account'] = $brand_bank_account !== '' ? $brand_bank_account : null;
        }
        if ($this->has_field('brand_bank_holder')) {
            $data['brand_bank_holder'] = $brand_bank_holder !== '' ? $brand_bank_holder : null;
        }
        if ($this->has_field('invoice_footer')) {
            $data['invoice_footer'] = $invoice_footer !== '' ? $invoice_footer : null;
        }
        if ($this->has_field('acs_url')) {
            $data['acs_url'] = $acs_url !== '' ? $acs_url : null;
        }
        if ($this->has_field('acs_nbi_url')) {
            $data['acs_nbi_url'] = $acs_nbi_url !== '' ? $acs_nbi_url : null;
        }
        if ($this->has_field('acs_username')) {
            $data['acs_username'] = $acs_username !== '' ? $acs_username : null;
        }
        if ($this->has_field('acs_password')) {
            if ($acs_password !== '') {
                $encrypted_acs_password = $this->encrypt_router_secret($acs_password);
                if ($encrypted_acs_password === false) {
                    return array();
                }
                $data['acs_password'] = $encrypted_acs_password;
            } elseif (is_array($existing)) {
                $data['acs_password'] = trim((string) ($existing['acs_password'] ?? '')) !== ''
                    ? (string) $existing['acs_password']
                    : null;
            } else {
                $data['acs_password'] = null;
            }
        }
        if ($this->has_field('acs_status')) {
            if (!in_array($acs_status, array('connected', 'disconnected'), true)) {
                if (is_array($existing) && !empty($existing['acs_status'])) {
                    $acs_status = strtolower(trim((string) $existing['acs_status']));
                }
            }
            if (!in_array($acs_status, array('connected', 'disconnected'), true)) {
                $acs_status = 'disconnected';
            }
            $data['acs_status'] = $acs_status;
        }
        if ($this->has_field('metadata_json')) {
            $data['metadata_json'] = $description !== '' ? json_encode(array('description' => $description)) : null;
        }
        if ($this->has_field('use_ssl')) {
            $data['use_ssl'] = $use_ssl;
        }
        if ($this->has_field('timeout_seconds')) {
            $data['timeout_seconds'] = $timeout_seconds;
        }
        if ($this->has_field('is_active')) {
            $data['is_active'] = $is_active;
        }
        if ($this->has_field('status')) {
            $data['status'] = $is_active === 1 ? 'active' : 'inactive';
        }
        if ($this->has_field('updated_at')) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        if (!is_array($existing) && $this->has_field('created_at')) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        return $data;
    }

    private function apply_search(CI_DB_query_builder &$qb, $search = '')
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }

        $qb->group_start();
        if ($this->has_field('name')) {
            $qb->like('name', $search);
        }
        if ($this->has_field('router_name')) {
            if ($this->has_field('name')) {
                $qb->or_like('router_name', $search);
            } else {
                $qb->like('router_name', $search);
            }
        }
        if ($this->has_field('ip_address')) {
            $qb->or_like('ip_address', $search);
        }
        if ($this->has_field('api_host')) {
            $qb->or_like('api_host', $search);
        }
        if ($this->has_field('username')) {
            $qb->or_like('username', $search);
        }
        if ($this->has_field('api_username')) {
            $qb->or_like('api_username', $search);
        }
        if ($this->has_field('brand_name')) {
            $qb->or_like('brand_name', $search);
        }
        if ($this->has_field('acs_url')) {
            $qb->or_like('acs_url', $search);
        }
        if ($this->has_field('acs_nbi_url')) {
            $qb->or_like('acs_nbi_url', $search);
        }
        $qb->group_end();
    }

    private function normalize_row(array $row)
    {
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['router_name'] ?? ''));
        }
        if ($name === '') {
            $name = 'Router #' . $id;
        }

        $ip = trim((string) ($row['ip_address'] ?? ''));
        if ($ip === '') {
            $ip = trim((string) ($row['api_host'] ?? ''));
        }

        $username = trim((string) ($row['username'] ?? ''));
        if ($username === '') {
            $username = trim((string) ($row['api_username'] ?? ''));
        }

        $password_enc = trim((string) ($row['password'] ?? ''));
        if ($password_enc === '') {
            $password_enc = trim((string) ($row['api_password_enc'] ?? ''));
        }

        $description = trim((string) ($row['description'] ?? ''));
        if ($description === '') {
            $meta = trim((string) ($row['metadata_json'] ?? ''));
            if ($meta !== '') {
                $decoded = json_decode($meta, true);
                if (is_array($decoded) && !empty($decoded['description'])) {
                    $description = trim((string) $decoded['description']);
                }
            }
        }

        $is_active = 1;
        if (array_key_exists('is_active', $row)) {
            $is_active = (int) $row['is_active'] === 1 ? 1 : 0;
        } elseif (array_key_exists('status', $row)) {
            $is_active = strtolower((string) $row['status']) === 'active' ? 1 : 0;
        }

        return array(
            'id' => $id,
            'name' => $name,
            'ip_address' => $ip,
            'api_port' => (int) ($row['api_port'] ?? 8728),
            'username' => $username,
            'password_enc' => $password_enc,
            'description' => $description,
            'brand_name' => trim((string) ($row['brand_name'] ?? '')),
            'brand_logo' => trim((string) ($row['brand_logo'] ?? '')),
            'brand_address' => trim((string) ($row['brand_address'] ?? '')),
            'brand_phone' => trim((string) ($row['brand_phone'] ?? '')),
            'brand_email' => trim((string) ($row['brand_email'] ?? '')),
            'brand_website' => trim((string) ($row['brand_website'] ?? '')),
            'brand_bank_name' => trim((string) ($row['brand_bank_name'] ?? '')),
            'brand_bank_account' => trim((string) ($row['brand_bank_account'] ?? '')),
            'brand_bank_holder' => trim((string) ($row['brand_bank_holder'] ?? '')),
            'invoice_footer' => trim((string) ($row['invoice_footer'] ?? '')),
            'acs_url' => trim((string) ($row['acs_url'] ?? '')),
            'acs_nbi_url' => trim((string) ($row['acs_nbi_url'] ?? '')),
            'acs_username' => trim((string) ($row['acs_username'] ?? '')),
            'acs_password' => trim((string) ($row['acs_password'] ?? '')),
            'acs_status' => strtolower(trim((string) ($row['acs_status'] ?? 'disconnected'))),
            'is_active' => $is_active,
            'use_ssl' => (int) ($row['use_ssl'] ?? 0),
            'timeout_seconds' => (int) ($row['timeout_seconds'] ?? 5),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        );
    }

    private function has_field($field)
    {
        $fields = $this->get_fields();
        return in_array($field, $fields, true);
    }

    private function get_fields()
    {
        if ($this->fields_cache === null) {
            $this->fields_cache = $this->table_exists()
                ? $this->db->list_fields($this->table)
                : array();
        }
        return $this->fields_cache;
    }

    private function encrypt_router_secret($plain_text)
    {
        $plain_text = trim((string) $plain_text);
        if ($plain_text === '') {
            return '';
        }

        if (isset($this->settings_model) && method_exists($this->settings_model, 'encrypt_secret')) {
            $encrypted = (string) $this->settings_model->encrypt_secret($plain_text);
            if ($encrypted !== '') {
                return $encrypted;
            }
        }

        $encrypted = $this->encryption->encrypt($plain_text);
        if (is_string($encrypted) && $encrypted !== '') {
            return $encrypted;
        }

        log_message('error', '[ROUTER_MODEL] encrypt_router_secret failed; aborting secret write to avoid plaintext storage.');
        return false;
    }
}
