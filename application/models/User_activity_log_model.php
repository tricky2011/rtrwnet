<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_activity_log_model extends CI_Model
{
    private $table = 'user_activity_logs';
    private $table_exists_cache = null;

    private function table_ready()
    {
        if ($this->table_exists_cache === null) {
            $this->table_exists_cache = $this->db->table_exists($this->table);
        }

        return $this->table_exists_cache === true;
    }

    public function insert(array $data)
    {
        if (!$this->table_ready()) {
            return false;
        }

        return $this->db->insert($this->table, $data);
    }

    public function count_logs(array $filters = array())
    {
        if (!$this->table_ready()) {
            return 0;
        }

        $qb = $this->db->from($this->table . ' l');
        $this->apply_filters($qb, $filters);
        return (int) $qb->count_all_results();
    }

    public function get_logs(array $filters = array(), $limit = 20, $offset = 0)
    {
        if (!$this->table_ready()) {
            return array();
        }

        $qb = $this->db
            ->select('l.*')
            ->from($this->table . ' l');

        if ($this->db->table_exists('users')) {
            $qb->select("COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), l.user_name, '-') AS actor_name", false);
            $qb->join('users u', 'u.id = l.user_id', 'left');
        } else {
            $qb->select("COALESCE(NULLIF(l.user_name, ''), '-') AS actor_name", false);
        }

        $this->apply_filters($qb, $filters);

        return $qb
            ->order_by('l.id', 'DESC')
            ->limit((int) $limit, (int) $offset)
            ->get()
            ->result_array();
    }

    public function get_user_options(array $allowed_roles = array())
    {
        if (!$this->db->table_exists('users')) {
            return array();
        }

        $fields = $this->db->list_fields('users');
        if (empty($fields) || !in_array('id', $fields, true)) {
            return array();
        }

        $name_expr = in_array('name', $fields, true)
            ? "COALESCE(NULLIF(name, ''), username)"
            : 'username';

        $qb = $this->db
            ->select('id, ' . $name_expr . ' AS label', false)
            ->from('users');

        if (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }
        if (!empty($allowed_roles) && in_array('role', $fields, true)) {
            $qb->where_in('role', $this->normalize_roles($allowed_roles));
        }

        return $qb->order_by('label', 'ASC')->get()->result_array();
    }

    private function apply_filters(CI_DB_query_builder &$qb, array $filters)
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $user_id = (int) ($filters['user_id'] ?? 0);
        $controller = trim((string) ($filters['controller'] ?? ''));
        $method = trim((string) ($filters['method'] ?? ''));
        $date_from = trim((string) ($filters['date_from'] ?? ''));
        $date_to = trim((string) ($filters['date_to'] ?? ''));
        $allowed_roles = $this->normalize_roles($filters['allowed_roles'] ?? array());

        if (!empty($allowed_roles)) {
            $escaped = array();
            foreach ($allowed_roles as $role) {
                $escaped[] = $this->db->escape($role);
            }
            $qb->where('LOWER(l.user_role) IN (' . implode(',', $escaped) . ')', null, false);
        }

        if ($user_id > 0) {
            $qb->where('l.user_id', $user_id);
        }
        if ($controller !== '') {
            $qb->where('l.controller', strtolower($controller));
        }
        if ($method !== '') {
            $qb->where('l.method', strtolower($method));
        }

        if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $qb->where('l.created_at >=', $date_from . ' 00:00:00');
        }
        if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $qb->where('l.created_at <=', $date_to . ' 23:59:59');
        }

        if ($search !== '') {
            $qb->group_start()
                ->like('l.action', $search)
                ->or_like('l.request_uri', $search)
                ->or_like('l.user_name', $search)
                ->or_like('l.ip_address', $search)
                ->or_like('l.payload_json', $search)
            ->group_end();
        }
    }

    private function normalize_roles($roles)
    {
        if (!is_array($roles)) {
            $roles = array($roles);
        }

        $normalized = array();
        foreach ($roles as $role) {
            $role = strtolower(trim((string) $role));
            if ($role === '') {
                continue;
            }
            $normalized[$role] = $role;
        }

        return array_values($normalized);
    }
}
