<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends CI_Model
{
    private $table = 'users';

    public function __construct()
    {
        parent::__construct();
    }

    public function get_all($exclude_superadmin = false)
    {
        if (!$this->db->table_exists($this->table)) {
            return array();
        }

        $qb = $this->db
            ->from($this->table . ' u')
            ->select('u.*');

        if ($this->has_router_scope_column() && $this->db->table_exists('routers')) {
            $router_fields = $this->db->list_fields('routers');
            $router_name_col = in_array('name', $router_fields, true)
                ? 'name'
                : (in_array('router_name', $router_fields, true) ? 'router_name' : '');

            if ($router_name_col !== '') {
                $qb->select('r.' . $router_name_col . ' AS router_scope_name', false);
            }

            $qb->join('routers r', 'r.id = u.router_scope_id', 'left');
        }

        if ($exclude_superadmin) {
            $qb->where('u.role !=', 'superadmin');
        }

        $this->apply_tenant_scope($qb);

        return $qb
            ->order_by('u.id', 'DESC')
            ->get()
            ->result();
    }

    public function get_by_id($id)
    {
        if (!$this->db->table_exists($this->table)) {
            return null;
        }

        $qb = $this->db
            ->from($this->table)
            ->where('id', (int) $id)
            ->limit(1);

        $this->apply_tenant_scope($qb);

        return $qb->get()->row();
    }

    public function get_by_username($username)
    {
        if (!$this->db->table_exists($this->table)) {
            return null;
        }

        $qb = $this->db
            ->from($this->table)
            ->where('username', trim((string) $username))
            ->limit(1);

        $this->apply_tenant_scope($qb);

        return $qb->get()->row();
    }

    public function exists_username($username, $ignore_id = 0)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $qb = $this->db
            ->from($this->table)
            ->where('username', trim((string) $username));

        if ((int) $ignore_id > 0) {
            $qb->where('id !=', (int) $ignore_id);
        }

        $this->apply_tenant_scope($qb);
        return $qb->count_all_results() > 0;
    }

    public function insert(array $data)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $payload = array(
            'name' => trim((string) ($data['name'] ?? '')),
            'username' => trim((string) ($data['username'] ?? '')),
            'password' => (string) ($data['password'] ?? ''),
            'role' => (string) ($data['role'] ?? 'teknisi'),
            'status' => (string) ($data['status'] ?? 'active'),
        );
        if ($this->has_router_scope_column()) {
            $scope = isset($data['router_scope_id']) ? (int) $data['router_scope_id'] : 0;
            $payload['router_scope_id'] = $scope > 0 ? $scope : null;
        }

        $ok = $this->db->insert($this->table, $payload);
        return $ok ? (int) $this->db->insert_id() : false;
    }

    public function update($id, array $data)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $payload = array(
            'name' => trim((string) ($data['name'] ?? '')),
            'username' => trim((string) ($data['username'] ?? '')),
            'role' => (string) ($data['role'] ?? 'teknisi'),
            'status' => (string) ($data['status'] ?? 'active'),
        );
        if ($this->has_router_scope_column()) {
            $scope = isset($data['router_scope_id']) ? (int) $data['router_scope_id'] : 0;
            $payload['router_scope_id'] = $scope > 0 ? $scope : null;
        }

        if (!empty($data['password'])) {
            $payload['password'] = (string) $data['password'];
        }

        $qb = $this->db->where('id', (int) $id);
        $this->apply_tenant_scope($qb);

        return $qb->update($this->table, $payload);
    }

    public function delete($id)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $qb = $this->db->where('id', (int) $id);
        $this->apply_tenant_scope($qb);
        return $qb->delete($this->table);
    }

    public function soft_delete($id)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $qb = $this->db->where('id', (int) $id);
        $this->apply_tenant_scope($qb);
        return $qb->update($this->table, array('status' => 'inactive'));
    }

    private function apply_tenant_scope(CI_DB_query_builder &$qb)
    {
        // Single-install mode: no tenant scope.
        return;
    }

    private function column_exists($column)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        return in_array($column, $this->db->list_fields($this->table), true);
    }

    public function has_router_scope_column()
    {
        return $this->column_exists('router_scope_id');
    }

    public function get_active_routers($scope_router_id = null)
    {
        if (!$this->db->table_exists('routers')) {
            return array();
        }

        $fields = $this->db->list_fields('routers');
        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : '');
        if ($name_col === '') {
            return array();
        }

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS name', false)
            ->from('routers');

        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('LOWER(status)', 'active');
        }

        $scope_router_id = (int) $scope_router_id;
        if ($scope_router_id > 0) {
            $qb->where('id', $scope_router_id);
        }

        $rows = $qb
            ->order_by($name_col, 'ASC')
            ->get()
            ->result_array();

        foreach ($rows as &$row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['name'] = (string) ($row['name'] ?? '');
        }
        unset($row);

        return $rows;
    }

    public function router_exists($router_id, $active_only = true)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return false;
        }

        $fields = $this->db->list_fields('routers');
        $qb = $this->db
            ->from('routers')
            ->where('id', $router_id);

        if ($active_only) {
            if (in_array('is_active', $fields, true)) {
                $qb->where('is_active', 1);
            } elseif (in_array('status', $fields, true)) {
                $qb->where('LOWER(status)', 'active');
            }
        }

        return $qb->count_all_results() > 0;
    }
}
