<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ont_device_model extends CI_Model
{
    private $table = 'ont_devices';
    private $has_router_id = null;
    private $table_fields = null;
    private $customer_fields = null;

    public function table_exists()
    {
        return $this->db->table_exists($this->table);
    }

    public function upsert(array $payload)
    {
        $serial = trim((string) ($payload['serial_number'] ?? ''));
        if ($serial === '') {
            return false;
        }

        $router_id = (int) ($payload['router_id'] ?? 0);
        if ($this->has_router_id() && $router_id <= 0) {
            return false;
        }

        $payload = $this->sanitize_payload($payload);
        if (empty($payload)) {
            return false;
        }

        $qb = $this->db->select('id')->from($this->table)->where('serial_number', $serial);
        if ($this->has_router_id()) {
            $qb->where('router_id', $router_id);
        }
        $existing = $qb->limit(1)->get()->row_array();

        if (!empty($existing['id'])) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
            return (bool) $this->db->where('id', (int) $existing['id'])->update($this->table, $payload);
        }

        $payload['created_at'] = date('Y-m-d H:i:s');
        $payload['updated_at'] = date('Y-m-d H:i:s');
        return (bool) $this->db->insert($this->table, $payload);
    }

    public function get_paginated($limit = 20, $offset = 0, $status = '', $search = '', $router_id = null)
    {
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);
        $status = strtolower(trim((string) $status));
        $search = trim((string) $search);
        $router_id = $router_id !== null ? (int) $router_id : null;

        $this->db
            ->select('d.*, ' . $this->customer_name_expression('c') . ' AS customer_name, r.name AS router_name', false)
            ->from($this->table . ' d')
            ->join('customers c', 'c.id = d.customer_id', 'left')
            ->join('routers r', 'r.id = d.router_id', 'left')
            ->order_by('d.last_inform', 'DESC')
            ->order_by('d.id', 'DESC')
            ->limit($limit, $offset);

        if ($this->has_router_id() && $router_id !== null && $router_id > 0) {
            $this->db->where('d.router_id', $router_id);
        }

        if ($status === 'online' || $status === 'offline') {
            $this->db->where('d.status', $status);
        }

        if ($search !== '') {
            $ont_fields = $this->table_fields();
            $this->db->group_start()
                ->like('d.serial_number', $search)
                ->or_like('d.product_class', $search)
                ->or_like('d.manufacturer', $search)
                ->or_like('d.wan_ip', $search);

            if (in_array('ssid', $ont_fields, true)) {
                $this->db->or_like('d.ssid', $search);
            }
            if (in_array('ont_username', $ont_fields, true)) {
                $this->db->or_like('d.ont_username', $search);
            }

            foreach ($this->customer_search_columns() as $col) {
                $this->db->or_like('c.' . $col, $search);
            }

            $this->db->group_end();
        }

        return $this->db->get()->result_array();
    }

    public function count_filtered($status = '', $search = '', $router_id = null)
    {
        $status = strtolower(trim((string) $status));
        $search = trim((string) $search);
        $router_id = $router_id !== null ? (int) $router_id : null;

        $this->db
            ->from($this->table . ' d')
            ->join('customers c', 'c.id = d.customer_id', 'left');

        if ($this->has_router_id() && $router_id !== null && $router_id > 0) {
            $this->db->where('d.router_id', $router_id);
        }

        if ($status === 'online' || $status === 'offline') {
            $this->db->where('d.status', $status);
        }

        if ($search !== '') {
            $ont_fields = $this->table_fields();
            $this->db->group_start()
                ->like('d.serial_number', $search)
                ->or_like('d.product_class', $search)
                ->or_like('d.manufacturer', $search)
                ->or_like('d.wan_ip', $search);

            if (in_array('ssid', $ont_fields, true)) {
                $this->db->or_like('d.ssid', $search);
            }
            if (in_array('ont_username', $ont_fields, true)) {
                $this->db->or_like('d.ont_username', $search);
            }

            foreach ($this->customer_search_columns() as $col) {
                $this->db->or_like('c.' . $col, $search);
            }

            $this->db->group_end();
        }

        return (int) $this->db->count_all_results();
    }

    public function find_by_serial($serial, $router_id = null)
    {
        $serial = trim((string) $serial);
        if ($serial === '') {
            return null;
        }
        $router_id = $router_id !== null ? (int) $router_id : null;
        $this->db->select('d.*, ' . $this->customer_name_expression('c') . ' AS customer_name, r.name AS router_name', false)
            ->from($this->table . ' d')
            ->join('customers c', 'c.id = d.customer_id', 'left')
            ->join('routers r', 'r.id = d.router_id', 'left')
            ->where('d.serial_number', $serial);

        if ($this->has_router_id() && $router_id !== null && $router_id > 0) {
            $this->db->where('d.router_id', $router_id);
        }

        return $this->db->limit(1)->get()->row_array();
    }

    public function get_counts($router_id = null)
    {
        $router_id = $router_id !== null ? (int) $router_id : null;
        $online_qb = $this->db->from($this->table)->where('status', 'online');
        if ($this->has_router_id() && $router_id !== null && $router_id > 0) {
            $online_qb->where('router_id', $router_id);
        }
        $online = (int) $online_qb->count_all_results();

        $offline_qb = $this->db->from($this->table)->where('status', 'offline');
        if ($this->has_router_id() && $router_id !== null && $router_id > 0) {
            $offline_qb->where('router_id', $router_id);
        }
        $offline = (int) $offline_qb->count_all_results();

        return array(
            'online' => $online,
            'offline' => $offline,
            'total' => $online + $offline,
        );
    }

    private function has_router_id()
    {
        if ($this->has_router_id !== null) {
            return $this->has_router_id;
        }

        if (!$this->table_exists()) {
            $this->has_router_id = false;
            return false;
        }

        $fields = $this->db->list_fields($this->table);
        $this->has_router_id = in_array('router_id', $fields, true);
        return $this->has_router_id;
    }

    private function sanitize_payload(array $payload)
    {
        $fields = $this->table_fields();
        if (empty($fields)) {
            return array();
        }

        $clean = array();
        foreach ($payload as $key => $value) {
            if (!in_array($key, $fields, true)) {
                continue;
            }
            if ($key === 'customer_id') {
                $cid = (int) $value;
                $clean[$key] = $cid > 0 ? $cid : null;
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    private function table_fields()
    {
        if ($this->table_fields !== null) {
            return $this->table_fields;
        }

        if (!$this->table_exists()) {
            $this->table_fields = array();
            return $this->table_fields;
        }

        $this->table_fields = $this->db->list_fields($this->table);
        return $this->table_fields;
    }

    private function customer_fields()
    {
        if ($this->customer_fields !== null) {
            return $this->customer_fields;
        }

        if (!$this->db->table_exists('customers')) {
            $this->customer_fields = array();
            return $this->customer_fields;
        }

        $this->customer_fields = $this->db->list_fields('customers');
        return $this->customer_fields;
    }

    private function customer_name_expression($alias)
    {
        $fields = $this->customer_fields();
        $parts = array();
        foreach (array('full_name', 'nama', 'customer_name', 'name', 'username') as $col) {
            if (in_array($col, $fields, true)) {
                $parts[] = 'NULLIF(' . $alias . '.' . $col . ", '')";
            }
        }
        if (empty($parts)) {
            return "'-'";
        }
        return 'COALESCE(' . implode(', ', $parts) . ", '-')";
    }

    private function customer_search_columns()
    {
        $fields = $this->customer_fields();
        $cols = array();
        foreach (array('full_name', 'nama', 'customer_name', 'name', 'username', 'pppoe_username') as $col) {
            if (in_array($col, $fields, true)) {
                $cols[] = $col;
            }
        }
        return $cols;
    }
}
