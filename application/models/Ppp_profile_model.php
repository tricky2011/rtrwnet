<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ppp_profile_model extends CI_Model
{
    private $table = 'ppp_profiles';
    private $soft_delete_marker = '[SOFT_DELETE]';
    private $has_router_column = null;

    public function get_all()
    {
        if (!$this->db->table_exists($this->table)) {
            return array();
        }

        return $this->build_list_query()->get()->result_array();
    }

    public function get_static_packages_paginated($limit = 20, $offset = 0, $keyword = '')
    {
        if (!$this->db->table_exists($this->table)) {
            return array();
        }

        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);

        return $this->build_static_package_query($keyword)
            ->limit($limit, $offset)
            ->get()
            ->result_array();
    }

    public function count_static_packages($keyword = '')
    {
        if (!$this->db->table_exists($this->table)) {
            return 0;
        }

        return (int) $this->build_static_package_query($keyword, true)->count_all_results();
    }

    public function get_paginated($limit = 20, $offset = 0, $keyword = '')
    {
        if (!$this->db->table_exists($this->table)) {
            return array();
        }

        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);

        return $this->build_list_query($keyword)
            ->limit($limit, $offset)
            ->get()
            ->result_array();
    }

    public function count_filtered($keyword = '')
    {
        if (!$this->db->table_exists($this->table)) {
            return 0;
        }

        return (int) $this->build_list_query($keyword, true)->count_all_results();
    }

    public function get_by_id($id)
    {
        if (!$this->db->table_exists($this->table)) {
            return null;
        }

        $qb = $this->db
            ->from($this->table)
            ->where('id', (int) $id);
        $this->apply_router_scope($qb);

        return $qb->limit(1)->get()->row_array();
    }

    public function exists_name($name, $ignore_id = 0)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $qb = $this->db
            ->from($this->table)
            ->where('name', trim((string) $name));

        if ($this->table_has_router_column()) {
            $router_id = $this->resolve_write_router_id();
            if ($router_id > 0) {
                $qb->where('router_id', $router_id);
            }
        }

        if ((int) $ignore_id > 0) {
            $qb->where('id !=', (int) $ignore_id);
        }

        $qb->where("COALESCE(description, '') NOT LIKE " . $this->db->escape('%' . $this->soft_delete_marker . '%'), null, false);
        return $qb->count_all_results() > 0;
    }

    public function insert(array $data)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $payload = $this->normalize_payload($data);
        $ok = $this->db->insert($this->table, $payload);
        return $ok ? (int) $this->db->insert_id() : false;
    }

    public function update($id, array $data)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $payload = $this->normalize_payload($data, true);
        return $this->db
            ->where('id', (int) $id)
            ->update($this->table, $payload);
    }

    public function delete($id)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        return $this->db
            ->where('id', (int) $id)
            ->delete($this->table);
    }

    public function soft_delete($id, $deleted_by = 0)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $id = (int) $id;
        $row = $this->get_by_id($id);
        if (empty($row)) {
            return false;
        }

        $timestamp = date('ymdHis');
        $suffix = '__DEL_' . $id . '_' . $timestamp . '_' . mt_rand(10, 99);
        $base_name = trim((string) ($row['name'] ?? ''));
        $max_base_len = max(1, 100 - strlen($suffix));
        $new_name = substr($base_name, 0, $max_base_len) . $suffix;

        $old_description = trim((string) ($row['description'] ?? ''));
        $soft_delete_note = $this->soft_delete_marker . ' by=' . (int) $deleted_by . ' at=' . date('Y-m-d H:i:s');
        $new_description = (strpos($old_description, $this->soft_delete_marker) === false)
            ? trim($old_description . ' ' . $soft_delete_note)
            : $old_description;

        return $this->db
            ->where('id', $id)
            ->update($this->table, array(
                'name' => $new_name,
                'description' => $new_description !== '' ? $new_description : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ));
    }

    public function dropdown_options()
    {
        $rows = $this->get_all();
        $options = array();
        foreach ($rows as $row) {
            $options[] = array(
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'price' => (float) ($row['price'] ?? 0),
            );
        }

        return $options;
    }

    private function normalize_payload(array $data, $is_update = false)
    {
        $payload = array(
            'name' => trim((string) ($data['name'] ?? '')),
            'rate_limit' => trim((string) ($data['rate_limit'] ?? '')),
            'local_address' => trim((string) ($data['local_address'] ?? '')),
            'remote_address_pool' => trim((string) ($data['remote_address_pool'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'updated_at' => date('Y-m-d H:i:s'),
        );

        if (array_key_exists('price', $data)) {
            if ($data['price'] === null || $data['price'] === '') {
                $payload['price'] = null;
            } else {
                $payload['price'] = number_format((float) $data['price'], 2, '.', '');
            }
        }

        if (!$is_update) {
            $payload['created_at'] = date('Y-m-d H:i:s');
        }

        if ($payload['description'] === '') {
            $payload['description'] = null;
        }

        if ($this->table_has_router_column()) {
            $router_id = isset($data['router_id']) ? (int) $data['router_id'] : 0;
            if ($router_id <= 0) {
                $router_id = $this->resolve_write_router_id();
            }
            if ($router_id > 0) {
                $payload['router_id'] = $router_id;
            }
        }

        return $payload;
    }

    private function build_list_query($keyword = '', $count_only = false)
    {
        $qb = $this->db->from($this->table . ' p');
        if (!$count_only) {
            $qb->select('p.*');
        }

        if (!$count_only && $this->db->table_exists('ip_pools')) {
            $join_expr = 'ip.pool_name = p.remote_address_pool';
            if ($this->table_has_router_column() && $this->db->field_exists('router_id', 'ip_pools')) {
                $join_expr .= ' AND ip.router_id = p.router_id';
            }

            $qb->join('ip_pools ip', $join_expr, 'left', false);
            $qb->select('ip.pool_name as pool_name_label');
        }

        $this->apply_router_scope($qb, 'p');

        $qb->where("COALESCE(p.description, '') NOT LIKE " . $this->db->escape('%' . $this->soft_delete_marker . '%'), null, false);

        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            $qb->group_start()
                ->like('p.name', $keyword)
                ->or_like('p.rate_limit', $keyword)
                ->or_like('p.local_address', $keyword)
                ->or_like('p.remote_address_pool', $keyword)
                ->group_end();
        }

        if (!$count_only) {
            $qb->order_by('p.id', 'DESC');
        }

        return $qb;
    }

    private function build_static_package_query($keyword = '', $count_only = false)
    {
        $qb = $this->db->from($this->table . ' p');
        if (!$count_only) {
            $qb->select('p.id, p.name, p.rate_limit, p.price, p.description, p.updated_at');
        }

        $qb->where("COALESCE(p.description, '') NOT LIKE " . $this->db->escape('%' . $this->soft_delete_marker . '%'), null, false);
        $qb->where("UPPER(TRIM(p.name)) REGEXP '^[0-9]+M$'", null, false);

        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            $qb->group_start()
                ->like('p.name', $keyword)
                ->or_like('p.rate_limit', $keyword)
                ->group_end();
        }

        if (!$count_only) {
            $qb->order_by('CAST(REPLACE(UPPER(TRIM(p.name)), "M", "") AS UNSIGNED)', 'ASC', false);
        }

        return $qb;
    }

    private function table_has_router_column()
    {
        if ($this->has_router_column !== null) {
            return $this->has_router_column;
        }
        $this->has_router_column = $this->db->field_exists('router_id', $this->table);
        return $this->has_router_column;
    }

    private function apply_router_scope(CI_DB_query_builder $qb, $alias = null)
    {
        if (!$this->table_has_router_column()) {
            return;
        }

        $router_id = $this->resolve_read_router_id();
        if ($router_id > 0) {
            $prefix = ($alias !== null && trim((string) $alias) !== '') ? (trim((string) $alias) . '.') : '';
            $qb->where($prefix . 'router_id', $router_id);
            return;
        }

        if (function_exists('normalizeRole')) {
            $CI =& get_instance();
            $role = normalizeRole((string) $CI->session->userdata('role'));
            if ($role !== 'superadmin') {
                $qb->where('1=0', null, false);
            }
        }
    }

    private function resolve_read_router_id()
    {
        $CI =& get_instance();
        if (!isset($CI->session)) {
            $CI->load->library('session');
        }

        if (function_exists('normalizeRole')) {
            $role = normalizeRole((string) $CI->session->userdata('role'));
            if ($role === 'superadmin') {
                $active_router = (int) $CI->session->userdata('active_router_id');
                return $active_router > 0 ? $active_router : 0;
            }
        }

        $scope = (int) $CI->session->userdata('router_scope_id');
        return $scope > 0 ? $scope : 0;
    }

    private function resolve_write_router_id()
    {
        $router_id = $this->resolve_read_router_id();
        if ($router_id > 0) {
            return $router_id;
        }

        if (!$this->db->table_exists('routers')) {
            return 0;
        }

        $router_fields = $this->db->list_fields('routers');
        $qb = $this->db->from('routers');
        if (in_array('is_active', $router_fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $router_fields, true)) {
            $qb->where('status', 'active');
        }

        $row = $qb->order_by('id', 'ASC')->limit(1)->get()->row_array();
        return (int) ($row['id'] ?? 0);
    }
}
