<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Notification_model extends CI_Model
{
    protected $table = 'notifications';
    protected $table_ready = null;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function is_ready()
    {
        if ($this->table_ready !== null) {
            return $this->table_ready;
        }

        $this->table_ready = $this->db->table_exists($this->table);
        return $this->table_ready;
    }

    public function insert(array $data)
    {
        if (!$this->is_ready()) {
            return 0;
        }

        $payload = $this->sanitize_payload($data);
        $ok = $this->db->insert($this->table, $payload);
        if (!$ok) {
            return 0;
        }

        return (int) $this->db->insert_id();
    }

    public function insert_batch_rows(array $rows)
    {
        if (!$this->is_ready() || empty($rows)) {
            return array();
        }

        $inserted_ids = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $this->insert($row);
            if ($id > 0) {
                $inserted_ids[] = $id;
            }
        }

        return $inserted_ids;
    }

    public function get_recent_for_user($user_id, array $options = array())
    {
        if (!$this->is_ready()) {
            return array();
        }

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return array();
        }

        $role = $this->normalize_role((string) ($options['role'] ?? ''));
        $limit = (int) ($options['limit'] ?? 10);
        if ($limit <= 0) {
            $limit = 10;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $router_id = isset($options['router_id']) && $options['router_id'] !== null
            ? (int) $options['router_id']
            : 0;
        $brand_id = isset($options['brand_id']) && $options['brand_id'] !== null
            ? (int) $options['brand_id']
            : 0;

        $this->db
            ->from($this->table)
            ->select('id,user_id,brand_id,router_id,type,category,title,message,reference_id,reference_type,is_read,created_at')
            ->order_by('id', 'DESC')
            ->limit($limit);

        $this->db->group_start()
            ->where('user_id', $user_id)
            ->or_where('user_id IS NULL', null, false)
            ->group_end();

        if ($router_id > 0) {
            $this->db->group_start()
                ->where('router_id', $router_id)
                ->or_where('router_id IS NULL', null, false)
                ->group_end();
        }

        if ($brand_id > 0) {
            $this->db->group_start()
                ->where('brand_id', $brand_id)
                ->or_where('brand_id IS NULL', null, false)
                ->group_end();
        }

        // Superadmin dapat melihat semua jika tidak ada user-bound notif.
        if ($role === 'superadmin' && !empty($options['include_all_for_superadmin'])) {
            $this->db->or_where('user_id IS NOT NULL', null, false);
        }

        return $this->db->get()->result_array();
    }

    public function get_unread_count_for_user($user_id, array $options = array())
    {
        if (!$this->is_ready()) {
            return 0;
        }

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0;
        }

        $router_id = isset($options['router_id']) && $options['router_id'] !== null
            ? (int) $options['router_id']
            : 0;
        $brand_id = isset($options['brand_id']) && $options['brand_id'] !== null
            ? (int) $options['brand_id']
            : 0;

        $this->db->from($this->table)->where('is_read', 0);
        $this->db->group_start()
            ->where('user_id', $user_id)
            ->or_where('user_id IS NULL', null, false)
            ->group_end();

        if ($router_id > 0) {
            $this->db->group_start()
                ->where('router_id', $router_id)
                ->or_where('router_id IS NULL', null, false)
                ->group_end();
        }

        if ($brand_id > 0) {
            $this->db->group_start()
                ->where('brand_id', $brand_id)
                ->or_where('brand_id IS NULL', null, false)
                ->group_end();
        }

        return (int) $this->db->count_all_results();
    }

    public function mark_read_for_user($notification_id, $user_id, array $options = array())
    {
        if (!$this->is_ready()) {
            return false;
        }

        $notification_id = (int) $notification_id;
        $user_id = (int) $user_id;
        if ($notification_id <= 0 || $user_id <= 0) {
            return false;
        }

        $router_id = isset($options['router_id']) && $options['router_id'] !== null
            ? (int) $options['router_id']
            : 0;
        $brand_id = isset($options['brand_id']) && $options['brand_id'] !== null
            ? (int) $options['brand_id']
            : 0;

        $this->db->where('id', $notification_id);
        $this->db->group_start()
            ->where('user_id', $user_id)
            ->or_where('user_id IS NULL', null, false)
            ->group_end();

        if ($router_id > 0) {
            $this->db->group_start()
                ->where('router_id', $router_id)
                ->or_where('router_id IS NULL', null, false)
                ->group_end();
        }

        if ($brand_id > 0) {
            $this->db->group_start()
                ->where('brand_id', $brand_id)
                ->or_where('brand_id IS NULL', null, false)
                ->group_end();
        }

        return (bool) $this->db->update($this->table, array('is_read' => 1));
    }

    public function mark_all_read_for_user($user_id, array $options = array())
    {
        if (!$this->is_ready()) {
            return false;
        }

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        $router_id = isset($options['router_id']) && $options['router_id'] !== null
            ? (int) $options['router_id']
            : 0;
        $brand_id = isset($options['brand_id']) && $options['brand_id'] !== null
            ? (int) $options['brand_id']
            : 0;

        $this->db->where('is_read', 0);
        $this->db->group_start()
            ->where('user_id', $user_id)
            ->or_where('user_id IS NULL', null, false)
            ->group_end();

        if ($router_id > 0) {
            $this->db->group_start()
                ->where('router_id', $router_id)
                ->or_where('router_id IS NULL', null, false)
                ->group_end();
        }

        if ($brand_id > 0) {
            $this->db->group_start()
                ->where('brand_id', $brand_id)
                ->or_where('brand_id IS NULL', null, false)
                ->group_end();
        }

        return (bool) $this->db->update($this->table, array('is_read' => 1));
    }

    public function get_target_user_ids_by_roles(array $roles, $router_id = null)
    {
        if (!$this->db->table_exists('users')) {
            return array();
        }

        $roles = array_values(array_filter(array_map(array($this, 'normalize_role'), $roles)));
        if (empty($roles)) {
            return array();
        }

        $user_fields = $this->db->list_fields('users');
        $has_router_scope = in_array('router_scope_id', $user_fields, true);
        $has_status = in_array('status', $user_fields, true);
        $router_id = $router_id !== null ? (int) $router_id : 0;

        $this->db->from('users')->select('id,role');
        $this->db->where_in('role', $roles);
        if ($has_status) {
            $this->db->where('status', 'active');
        }

        if ($router_id > 0 && $has_router_scope) {
            $include_superadmin = in_array('superadmin', $roles, true);
            if ($include_superadmin) {
                $this->db->group_start()
                    ->where('router_scope_id', $router_id)
                    ->or_where('role', 'superadmin')
                    ->group_end();
            } else {
                $this->db->where('router_scope_id', $router_id);
            }
        }

        $rows = $this->db->order_by('id', 'ASC')->get()->result_array();
        if (empty($rows)) {
            return array();
        }

        $ids = array();
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public function exists_reference_recent($user_id, $reference_type, $reference_id, $hours = 6, $type = '')
    {
        if (!$this->is_ready()) {
            return false;
        }

        $user_id = (int) $user_id;
        $reference_id = (int) $reference_id;
        $reference_type = trim((string) $reference_type);
        $hours = max(1, (int) $hours);
        $type = trim((string) $type);

        if ($reference_type === '' || $reference_id <= 0) {
            return false;
        }

        $this->db->from($this->table)
            ->where('reference_type', $reference_type)
            ->where('reference_id', $reference_id)
            ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-' . $hours . ' hours')));

        if ($user_id > 0) {
            $this->db->where('user_id', $user_id);
        }
        if ($type !== '') {
            $this->db->where('type', $type);
        }

        return (int) $this->db->count_all_results() > 0;
    }

    protected function sanitize_payload(array $data)
    {
        $payload = array(
            'user_id' => isset($data['user_id']) && $data['user_id'] !== null ? (int) $data['user_id'] : null,
            'brand_id' => isset($data['brand_id']) && $data['brand_id'] !== null ? (int) $data['brand_id'] : null,
            'router_id' => isset($data['router_id']) && $data['router_id'] !== null ? (int) $data['router_id'] : null,
            'type' => substr(trim((string) ($data['type'] ?? 'info')), 0, 50),
            'category' => substr(trim((string) ($data['category'] ?? 'general')), 0, 50),
            'title' => substr(trim((string) ($data['title'] ?? 'Notifikasi')), 0, 255),
            'message' => trim((string) ($data['message'] ?? '')),
            'reference_id' => isset($data['reference_id']) && $data['reference_id'] !== null ? (int) $data['reference_id'] : null,
            'reference_type' => isset($data['reference_type']) && $data['reference_type'] !== null
                ? substr(trim((string) $data['reference_type']), 0, 50)
                : null,
            'is_read' => !empty($data['is_read']) ? 1 : 0,
            'created_at' => isset($data['created_at']) && trim((string) $data['created_at']) !== ''
                ? trim((string) $data['created_at'])
                : date('Y-m-d H:i:s'),
        );

        if ($payload['user_id'] !== null && $payload['user_id'] <= 0) {
            $payload['user_id'] = null;
        }
        if ($payload['router_id'] !== null && $payload['router_id'] <= 0) {
            $payload['router_id'] = null;
        }
        if ($payload['brand_id'] !== null && $payload['brand_id'] <= 0) {
            $payload['brand_id'] = null;
        }
        if ($payload['reference_id'] !== null && $payload['reference_id'] <= 0) {
            $payload['reference_id'] = null;
        }

        return $payload;
    }

    protected function normalize_role($role)
    {
        return strtolower(trim((string) $role));
    }
}

