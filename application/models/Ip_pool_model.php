<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ip_pool_model extends CI_Model
{
    private $table = 'ip_pools';
    private $fields = array();
    private $soft_delete_name_marker = '__DEL_';
    private $has_router_column = null;

    public function __construct()
    {
        parent::__construct();

        if ($this->db->table_exists($this->table)) {
            $this->fields = $this->db->list_fields($this->table);
        }
    }

    public function get_all()
    {
        if (!$this->db->table_exists($this->table)) {
            return array();
        }

        return $this->build_list_query()->get()->result_array();
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

    public function exists_pool_name($pool_name, $ignore_id = 0)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $qb = $this->db
            ->from($this->table)
            ->where('pool_name', trim((string) $pool_name));

        if ($this->table_has_router_column()) {
            $router_id = $this->resolve_write_router_id();
            if ($router_id > 0) {
                $qb->where('router_id', $router_id);
            }
        }

        if ((int) $ignore_id > 0) {
            $qb->where('id !=', (int) $ignore_id);
        }

        $qb->not_like('pool_name', $this->soft_delete_name_marker);
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

        $old_name = trim((string) ($row['pool_name'] ?? ''));
        $suffix = $this->soft_delete_name_marker . $id . '_' . date('ymdHis') . '_' . mt_rand(10, 99);
        $max_base_len = max(1, 100 - strlen($suffix));
        $new_name = substr($old_name, 0, $max_base_len) . $suffix;

        $payload = array(
            'pool_name' => $new_name,
            'updated_at' => date('Y-m-d H:i:s'),
        );

        if ($this->has_field('router_name')) {
            $old_router_name = trim((string) ($row['router_name'] ?? ''));
            $payload['router_name'] = trim($old_router_name . ' [SOFT_DELETE by=' . (int) $deleted_by . ']');
        }

        return $this->db
            ->where('id', $id)
            ->update($this->table, $payload);
    }

    private function normalize_payload(array $data, $is_update = false)
    {
        $payload = array(
            'pool_name' => trim((string) ($data['pool_name'] ?? '')),
            'range_start' => trim((string) ($data['range_start'] ?? '')),
            'range_end' => trim((string) ($data['range_end'] ?? '')),
            'updated_at' => date('Y-m-d H:i:s'),
        );

        $total_ips = $this->calculate_total_ips($payload['range_start'], $payload['range_end']);
        if ($this->has_field('total_ips') && $total_ips > 0) {
            $payload['total_ips'] = $total_ips;
        }
        if ($this->has_field('used_ips')) {
            if (isset($data['used_ips'])) {
                $payload['used_ips'] = max(0, (int) $data['used_ips']);
            } elseif (!$is_update) {
                $payload['used_ips'] = 0;
            }
        }
        if ($this->has_field('usage_percent')) {
            if (isset($data['usage_percent'])) {
                $payload['usage_percent'] = (float) $data['usage_percent'];
            } elseif (!$is_update && $total_ips > 0) {
                $payload['usage_percent'] = 0.00;
            }
        }

        $router_name = trim((string) ($data['router_name'] ?? ''));
        if ($router_name === '') {
            $router_name = trim((string) ($data['router_id'] ?? ''));
        }

        if ($this->has_field('router_name')) {
            $payload['router_name'] = $router_name !== '' ? $router_name : null;
        }

        if ($this->table_has_router_column()) {
            $router_id = isset($data['router_id']) ? (int) $data['router_id'] : 0;
            if ($router_id <= 0 && $router_name !== '' && ctype_digit($router_name)) {
                $router_id = (int) $router_name;
            }
            if ($router_id <= 0) {
                $router_id = $this->resolve_write_router_id();
            }
            if ($router_id > 0) {
                $payload['router_id'] = $router_id;
            }
        }

        if (!$is_update) {
            $payload['created_at'] = date('Y-m-d H:i:s');
        }

        return $payload;
    }

    private function has_field($field)
    {
        return in_array((string) $field, $this->fields, true);
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

    private function build_list_query($keyword = '', $count_only = false)
    {
        $qb = $this->db->from($this->table);
        $qb->not_like('pool_name', $this->soft_delete_name_marker);
        $this->apply_router_scope($qb);
        if (!$count_only) {
            $qb->order_by('id', 'DESC');
        }

        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            $qb->group_start()
                ->like('pool_name', $keyword)
                ->or_like('range_start', $keyword)
                ->or_like('range_end', $keyword);

            if ($this->has_field('router_name')) {
                $qb->or_like('router_name', $keyword);
            } elseif ($this->has_field('router_id')) {
                $qb->or_like('router_id', $keyword);
            }
            $qb->group_end();
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
