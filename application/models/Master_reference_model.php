<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Master_reference_model extends CI_Model
{
    private $table_locations = 'master_locations';
    private $table_olts = 'master_olts';
    private $router_scope_id = null;

    public function set_router_scope($router_id = null)
    {
        $router_id = (int) $router_id;
        $this->router_scope_id = $router_id > 0 ? $router_id : null;
        return $this;
    }

    public function get_locations($active_only = false)
    {
        if (!$this->db->table_exists($this->table_locations)) {
            return array();
        }

        $qb = $this->db
            ->from($this->table_locations)
            ->order_by('name', 'ASC');

        $this->apply_common_filter($qb, $this->table_locations, '', $active_only);

        return $qb->get()->result_array();
    }

    public function get_locations_paginated($limit, $offset, $keyword = '', $active_only = false)
    {
        if (!$this->db->table_exists($this->table_locations)) {
            return array();
        }

        $qb = $this->db
            ->from($this->table_locations)
            ->order_by('name', 'ASC')
            ->limit((int) $limit, (int) $offset);

        $this->apply_common_filter($qb, $this->table_locations, $keyword, $active_only);

        return $qb->get()->result_array();
    }

    public function count_locations($keyword = '', $active_only = false)
    {
        if (!$this->db->table_exists($this->table_locations)) {
            return 0;
        }

        $qb = $this->db
            ->from($this->table_locations);

        $this->apply_common_filter($qb, $this->table_locations, $keyword, $active_only);
        return (int) $qb->count_all_results();
    }

    public function get_olts($active_only = false)
    {
        if (!$this->db->table_exists($this->table_olts)) {
            return array();
        }

        $qb = $this->db
            ->from($this->table_olts)
            ->order_by('name', 'ASC');

        $this->apply_common_filter($qb, $this->table_olts, '', $active_only);

        return $qb->get()->result_array();
    }

    public function get_olts_paginated($limit, $offset, $keyword = '', $active_only = false)
    {
        if (!$this->db->table_exists($this->table_olts)) {
            return array();
        }

        $qb = $this->db
            ->from($this->table_olts)
            ->order_by('name', 'ASC')
            ->limit((int) $limit, (int) $offset);

        $this->apply_common_filter($qb, $this->table_olts, $keyword, $active_only);

        return $qb->get()->result_array();
    }

    public function count_olts($keyword = '', $active_only = false)
    {
        if (!$this->db->table_exists($this->table_olts)) {
            return 0;
        }

        $qb = $this->db
            ->from($this->table_olts);

        $this->apply_common_filter($qb, $this->table_olts, $keyword, $active_only);
        return (int) $qb->count_all_results();
    }

    public function dropdown_locations()
    {
        $rows = $this->get_locations(true);
        $result = array();
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $result[] = $name;
            }
        }

        return $result;
    }

    public function dropdown_olts()
    {
        $rows = $this->get_olts(true);
        $result = array();
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $result[] = $name;
            }
        }

        return $result;
    }

    public function insert_location(array $data)
    {
        if (!$this->db->table_exists($this->table_locations)) {
            return false;
        }

        $payload = $this->normalize_payload($this->table_locations, $data);
        $ok = $this->db->insert($this->table_locations, $payload);
        return $ok ? (int) $this->db->insert_id() : false;
    }

    public function insert_olt(array $data)
    {
        if (!$this->db->table_exists($this->table_olts)) {
            return false;
        }

        $payload = $this->normalize_payload($this->table_olts, $data);
        $ok = $this->db->insert($this->table_olts, $payload);
        return $ok ? (int) $this->db->insert_id() : false;
    }

    public function update_location($id, array $data)
    {
        if (!$this->db->table_exists($this->table_locations)) {
            return false;
        }

        if (!$this->row_in_scope($this->table_locations, (int) $id)) {
            return false;
        }

        $payload = $this->normalize_payload($this->table_locations, $data, true);
        $qb = $this->db
            ->where('id', (int) $id);
        $scope_where = $this->build_router_scope_where($this->table_locations);
        if (!empty($scope_where)) {
            $qb->where($scope_where);
        }

        return $qb->update($this->table_locations, $payload);
    }

    public function update_olt($id, array $data)
    {
        if (!$this->db->table_exists($this->table_olts)) {
            return false;
        }

        if (!$this->row_in_scope($this->table_olts, (int) $id)) {
            return false;
        }

        $payload = $this->normalize_payload($this->table_olts, $data, true);
        $qb = $this->db
            ->where('id', (int) $id);
        $scope_where = $this->build_router_scope_where($this->table_olts);
        if (!empty($scope_where)) {
            $qb->where($scope_where);
        }

        return $qb->update($this->table_olts, $payload);
    }

    public function delete_location($id)
    {
        if (!$this->db->table_exists($this->table_locations)) {
            return false;
        }

        if (!$this->row_in_scope($this->table_locations, (int) $id)) {
            return false;
        }

        $qb = $this->db
            ->where('id', (int) $id);
        $scope_where = $this->build_router_scope_where($this->table_locations);
        if (!empty($scope_where)) {
            $qb->where($scope_where);
        }

        return $qb->delete($this->table_locations);
    }

    public function bulk_update_location_status(array $ids, $is_active)
    {
        if (!$this->db->table_exists($this->table_locations) || empty($ids)) {
            return 0;
        }

        $payload = array(
            'is_active' => ((int) $is_active === 1 ? 1 : 0),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        $payload = $this->filter_payload_by_columns($this->table_locations, $payload);
        if (empty($payload)) {
            return -1;
        }

        $qb = $this->db
            ->where_in('id', $ids);
        $scope_where = $this->build_router_scope_where($this->table_locations);
        if (!empty($scope_where)) {
            $qb->where($scope_where);
        }

        $ok = $qb->update($this->table_locations, $payload);

        return $ok ? (int) $this->db->affected_rows() : -1;
    }

    public function bulk_delete_locations(array $ids)
    {
        if (!$this->db->table_exists($this->table_locations) || empty($ids)) {
            return 0;
        }

        $qb = $this->db
            ->where_in('id', $ids);
        $scope_where = $this->build_router_scope_where($this->table_locations);
        if (!empty($scope_where)) {
            $qb->where($scope_where);
        }

        $ok = $qb->delete($this->table_locations);

        return $ok ? (int) $this->db->affected_rows() : -1;
    }

    public function delete_olt($id)
    {
        if (!$this->db->table_exists($this->table_olts)) {
            return false;
        }

        if (!$this->row_in_scope($this->table_olts, (int) $id)) {
            return false;
        }

        $qb = $this->db
            ->where('id', (int) $id);
        $scope_where = $this->build_router_scope_where($this->table_olts);
        if (!empty($scope_where)) {
            $qb->where($scope_where);
        }

        return $qb->delete($this->table_olts);
    }

    public function bulk_update_olt_status(array $ids, $is_active)
    {
        if (!$this->db->table_exists($this->table_olts) || empty($ids)) {
            return 0;
        }

        $payload = array(
            'is_active' => ((int) $is_active === 1 ? 1 : 0),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        $payload = $this->filter_payload_by_columns($this->table_olts, $payload);
        if (empty($payload)) {
            return -1;
        }

        $qb = $this->db
            ->where_in('id', $ids);
        $scope_where = $this->build_router_scope_where($this->table_olts);
        if (!empty($scope_where)) {
            $qb->where($scope_where);
        }

        $ok = $qb->update($this->table_olts, $payload);

        return $ok ? (int) $this->db->affected_rows() : -1;
    }

    public function bulk_delete_olts(array $ids)
    {
        if (!$this->db->table_exists($this->table_olts) || empty($ids)) {
            return 0;
        }

        $qb = $this->db
            ->where_in('id', $ids);
        $scope_where = $this->build_router_scope_where($this->table_olts);
        if (!empty($scope_where)) {
            $qb->where($scope_where);
        }

        $ok = $qb->delete($this->table_olts);

        return $ok ? (int) $this->db->affected_rows() : -1;
    }

    private function normalize_payload($table, array $data, $is_update = false)
    {
        $payload = array(
            'name' => strtoupper(trim((string) ($data['name'] ?? ''))),
            'description' => trim((string) ($data['description'] ?? '')),
            'updated_at' => date('Y-m-d H:i:s'),
        );

        if ($this->table_has_column($table, 'latitude')) {
            $latitude = trim((string) ($data['latitude'] ?? ''));
            $payload['latitude'] = $latitude !== '' ? $latitude : null;
        }

        if ($this->table_has_column($table, 'longitude')) {
            $longitude = trim((string) ($data['longitude'] ?? ''));
            $payload['longitude'] = $longitude !== '' ? $longitude : null;
        }

        if ($this->table_has_column($table, 'router_id') && !$is_update) {
            $router_id = isset($data['router_id']) ? (int) $data['router_id'] : 0;
            if ($router_id <= 0 && $this->router_scope_id !== null) {
                $router_id = (int) $this->router_scope_id;
            }
            if ($router_id > 0) {
                $payload['router_id'] = $router_id;
            }
        }

        if ($this->table_has_column($table, 'is_active')) {
            $payload['is_active'] = !empty($data['is_active']) ? 1 : 0;
        }

        if (!$is_update) {
            $payload['created_at'] = date('Y-m-d H:i:s');
            if (!array_key_exists('is_active', $data) && $this->table_has_column($table, 'is_active')) {
                $payload['is_active'] = 1;
            }
        }

        if ($payload['description'] === '') {
            $payload['description'] = null;
        }

        return $payload;
    }

    private function apply_common_filter($qb, $table, $keyword = '', $active_only = false)
    {
        $this->apply_router_scope($qb, $table);

        if ($active_only && $this->table_has_column($table, 'is_active')) {
            $qb->where('is_active', 1);
        }

        $keyword = trim((string) $keyword);
        if ($keyword === '') {
            return;
        }

        $qb->group_start()->like('name', $keyword);
        if ($this->table_has_column($table, 'description')) {
            $qb->or_like('description', $keyword);
        }
        $qb->group_end();
    }

    private function apply_router_scope($qb, $table)
    {
        if (!$this->table_has_column($table, 'router_id')) {
            return;
        }

        if ($this->router_scope_id !== null && (int) $this->router_scope_id > 0) {
            $qb->where($table . '.router_id', (int) $this->router_scope_id);
        }
    }

    private function build_router_scope_where($table)
    {
        if (
            !$this->table_has_column($table, 'router_id')
            || $this->router_scope_id === null
            || (int) $this->router_scope_id <= 0
        ) {
            return array();
        }

        return array('router_id' => (int) $this->router_scope_id);
    }

    private function row_in_scope($table, $id)
    {
        $id = (int) $id;
        if ($id <= 0 || !$this->db->table_exists($table)) {
            return false;
        }

        $qb = $this->db
            ->from($table)
            ->where('id', $id);

        $this->apply_router_scope($qb, $table);
        return (int) $qb->count_all_results() > 0;
    }

    private function filter_payload_by_columns($table, array $payload)
    {
        if (!$this->db->table_exists($table)) {
            return array();
        }

        $fields = $this->db->list_fields($table);
        $result = array();
        foreach ($payload as $key => $value) {
            if (in_array($key, $fields, true)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function table_has_column($table, $column)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }

        return in_array($column, $this->db->list_fields($table), true);
    }
}
