<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Customer_model extends CI_Model
{
    protected $table = 'customers';
    protected $fields = array();
    protected $tenant_id = null;
    protected $router_scope_id = null;
    protected $status_values = null;

    public function __construct()
    {
        parent::__construct();
        $this->load->helper(array('tenant', 'router_scope'));
        $this->tenant_id = $this->resolve_tenant_id();
        $this->router_scope_id = $this->resolve_router_scope_id();

        if ($this->db->table_exists($this->table)) {
            $this->fields = $this->db->list_fields($this->table);
        }
    }

    public function set_router_scope($router_scope_id)
    {
        $router_scope_id = (int) $router_scope_id;
        $this->router_scope_id = $router_scope_id > 0 ? $router_scope_id : null;
        return $this;
    }

    public function get_all()
    {
        if (!$this->db->table_exists($this->table)) {
            return array();
        }

        return $this->build_list_query()->get()->result();
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
            ->result();
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

        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        if (!$this->can_access_customer_by_router_scope($id)) {
            return null;
        }

        $qb = $this->db
            ->from($this->table)
            ->where('id', $id)
            ->limit(1);

        $this->apply_tenant_scope($qb);
        $this->apply_soft_delete_scope($qb);

        return $qb->get()->row();
    }

    public function get($id)
    {
        return $this->get_by_id($id);
    }

    public function insert($data)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $payload = $this->filter_payload((array) $data);
        if (empty($payload)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        if ($this->has_field('tenant_id') && !isset($payload['tenant_id'])) {
            if ($this->tenant_id !== null) {
                $payload['tenant_id'] = (int) $this->tenant_id;
            }
        }
        if ($this->has_field('created_at') && empty($payload['created_at'])) {
            $payload['created_at'] = $now;
        }
        if ($this->has_field('updated_at')) {
            $payload['updated_at'] = $now;
        }

        $ok = $this->db->insert($this->table, $payload);
        return $ok ? (int) $this->db->insert_id() : false;
    }

    public function update($id, $data)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        if (!$this->can_access_customer_by_router_scope($id)) {
            return false;
        }

        $payload = $this->filter_payload((array) $data);
        if (empty($payload)) {
            return false;
        }

        if ($this->has_field('updated_at')) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        $qb = $this->db->where('id', $id);
        $this->apply_tenant_scope($qb);

        return $qb->update($this->table, $payload);
    }

    public function soft_delete($id)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        if (!$this->can_access_customer_by_router_scope($id)) {
            return false;
        }

        $payload = array();
        $now = date('Y-m-d H:i:s');

        if ($this->has_field('deleted_at')) {
            $payload['deleted_at'] = $now;
        }
        if ($this->has_field('is_deleted')) {
            $payload['is_deleted'] = 1;
        }
        if ($this->has_field('deleted')) {
            $payload['deleted'] = 1;
        }
        if (empty($payload) && $this->has_field('status')) {
            $status = $this->resolve_status_value(array('terminated', 'inactive', 'disabled'));
            if ($status !== null) {
                $payload['status'] = $status;
            }
        }
        if ($this->has_field('updated_at')) {
            $payload['updated_at'] = $now;
        }

        if (empty($payload)) {
            return false;
        }

        $qb = $this->db->where('id', $id);
        $this->apply_tenant_scope($qb);

        return $qb->update($this->table, $payload);
    }

    public function exists_pppoe_username($username, $ignore_id = 0)
    {
        return $this->exists_username_any($username, $ignore_id);
    }

    public function exists_username_any($username, $ignore_id = 0)
    {
        $username = trim((string) $username);
        if ($username === '') {
            return false;
        }

        if ($this->db->table_exists($this->table)) {
            foreach (array('pppoe_username', 'username') as $column) {
                if (!$this->has_field($column)) {
                    continue;
                }

                $qb = $this->db
                    ->from($this->table)
                    ->where($column, $username);

                if ((int) $ignore_id > 0) {
                    $qb->where('id !=', (int) $ignore_id);
                }

                $this->apply_tenant_scope($qb);
                $this->apply_soft_delete_scope($qb);

                if ($qb->count_all_results() > 0) {
                    return true;
                }
            }
        }

        if ($this->db->table_exists('pppoe_secrets')) {
            $secret_qb = $this->db
                ->from('pppoe_secrets')
                ->where('username', $username);

            if ($this->tenant_id !== null) {
                $secret_fields = $this->db->list_fields('pppoe_secrets');
                if (in_array('tenant_id', $secret_fields, true)) {
                    $secret_qb->where('tenant_id', (int) $this->tenant_id);
                }
            }

            $exists_secret = $secret_qb->count_all_results() > 0;
            if ($exists_secret) {
                return true;
            }
        }

        return false;
    }

    public function get_by_pppoe($username)
    {
        if (!$this->db->table_exists($this->table)) {
            return null;
        }

        foreach (array('pppoe_username', 'username') as $column) {
            if (!$this->has_field($column)) {
                continue;
            }

            $qb = $this->db
                ->from($this->table)
                ->where($column, trim((string) $username))
                ->limit(1);

            $this->apply_tenant_scope($qb);
            $this->apply_soft_delete_scope($qb);

            $row = $qb->get()->row();
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    public function get_billable_by_day($billing_day)
    {
        if (!$this->db->table_exists($this->table)) {
            return array();
        }

        if (!$this->has_field('billing_date')) {
            return array();
        }

        $qb = $this->db
            ->select('c.*')
            ->from('customers c')
            ->where('c.billing_date', (int) $billing_day);

        if ($this->has_field('status')) {
            $qb->where_in('c.status', array('active', 'isolated', 'suspended'));
        }

        if ($this->db->table_exists('packages') && $this->has_field('package_id')) {
            $qb->join('packages p', 'p.id = c.package_id', 'left')
               ->select('p.package_name, p.price');
        }

        $this->apply_tenant_scope($qb, 'c');
        $this->apply_soft_delete_scope($qb, 'c');

        return $qb->get()->result();
    }

    public function get_with_package($customer_id)
    {
        if (!$this->db->table_exists($this->table)) {
            return null;
        }

        $qb = $this->db
            ->select('c.*')
            ->from('customers c')
            ->where('c.id', (int) $customer_id)
            ->limit(1);

        if ($this->db->table_exists('packages') && $this->has_field('package_id')) {
            $qb->join('packages p', 'p.id = c.package_id', 'left')
               ->select('p.package_name, p.price');
        }

        $this->apply_tenant_scope($qb, 'c');
        $this->apply_soft_delete_scope($qb, 'c');

        return $qb->get()->row();
    }

    private function filter_payload(array $data)
    {
        $filtered = array();
        foreach ($data as $key => $value) {
            if ($this->has_field($key)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    private function has_field($field)
    {
        return in_array($field, $this->fields, true);
    }

    private function build_list_query($keyword = '', $count_only = false)
    {
        $qb = $this->db->from($this->table . ' c');
        if (!$count_only) {
            $qb->select('c.*');
        }

        $has_profile_join = false;
        $has_service_join = false;
        $has_service_username = false;
        $has_technician_join = false;
        $has_wo_assignment_join = false;
        $tech_has_name = false;
        $tech_has_username = false;
        $technician_join_expr = null;
        $customer_has_profile_id = $this->has_field('profile_id');
        $profile_join_expr = null;
        $cs_fields = array();
        if ($this->db->table_exists('customer_services')) {
            $cs_fields = $this->db->list_fields('customer_services');
            $has_service_username = in_array('pppoe_username', $cs_fields, true);
            $cs_table = $this->db->dbprefix('customer_services');
            $sub_latest_service = "(\n                SELECT cs1.*\n                FROM {$cs_table} cs1\n                INNER JOIN (\n                    SELECT customer_id, MAX(id) AS max_id\n                    FROM {$cs_table}\n                    GROUP BY customer_id\n                ) cs2 ON cs2.max_id = cs1.id\n            ) cs";

            $qb->join($sub_latest_service, 'cs.customer_id = c.id', 'left', false);
            $has_service_join = true;

            if (!$count_only) {
                if (in_array('ppp_profile_id', $cs_fields, true)) {
                    $qb->select('cs.ppp_profile_id');
                }
                if (in_array('price', $cs_fields, true)) {
                    $qb->select('cs.price as service_price');
                }
                if (in_array('status', $cs_fields, true)) {
                    $qb->select('cs.status as service_status');
                }
                if ($has_service_username) {
                    $qb->select('cs.pppoe_username as service_pppoe_username');
                }
            }

            if (in_array('ppp_profile_id', $cs_fields, true)) {
                $profile_join_expr = $customer_has_profile_id
                    ? 'COALESCE(cs.ppp_profile_id, c.profile_id)'
                    : 'cs.ppp_profile_id';
            } elseif ($customer_has_profile_id) {
                $profile_join_expr = 'c.profile_id';
            }
        } elseif ($customer_has_profile_id) {
            $profile_join_expr = 'c.profile_id';
        }

        if ($this->db->table_exists('ppp_profiles') && $profile_join_expr !== null) {
            $qb->join('ppp_profiles p', 'p.id = ' . $profile_join_expr, 'left', false);
            $has_profile_join = true;
            if (!$count_only) {
                $qb->select('p.name as profile_name, p.id as effective_profile_id, p.price as profile_price');
            }
        } elseif (!$count_only && $customer_has_profile_id) {
            $qb->select('c.profile_id as effective_profile_id', false);
        }

        if ($this->db->table_exists('work_orders')) {
            $wo_fields = $this->db->list_fields('work_orders');
            if (in_array('customer_id', $wo_fields, true) && in_array('assigned_to', $wo_fields, true)) {
                $wo_table = $this->db->dbprefix('work_orders');
                $sub_latest_wo = "(\n                    SELECT w1.customer_id, w1.assigned_to\n                    FROM {$wo_table} w1\n                    INNER JOIN (\n                        SELECT customer_id, MAX(id) AS max_id\n                        FROM {$wo_table}\n                        GROUP BY customer_id\n                    ) w2 ON w2.max_id = w1.id\n                ) cwo";
                $qb->join($sub_latest_wo, 'cwo.customer_id = c.id', 'left', false);
                $has_wo_assignment_join = true;
                if (!$count_only) {
                    $qb->select('cwo.assigned_to AS wo_assigned_to', false);
                }
            }
        }

        if (($this->has_field('technician_id') || $has_wo_assignment_join) && $this->db->table_exists('users')) {
            $user_fields = $this->db->list_fields('users');
            if (in_array('id', $user_fields, true)) {
                $tech_has_name = in_array('name', $user_fields, true);
                $tech_has_username = in_array('username', $user_fields, true);
                $tech_name_col = in_array('name', $user_fields, true)
                    ? 'name'
                    : (in_array('username', $user_fields, true) ? 'username' : '');
                if ($tech_name_col !== '') {
                    if ($this->has_field('technician_id') && $has_wo_assignment_join) {
                        $technician_join_expr = 'COALESCE(c.technician_id, cwo.assigned_to)';
                    } elseif ($this->has_field('technician_id')) {
                        $technician_join_expr = 'c.technician_id';
                    } else {
                        $technician_join_expr = 'cwo.assigned_to';
                    }

                    $qb->join('users tech', 'tech.id = ' . $technician_join_expr, 'left', false);
                    $has_technician_join = true;
                    if (!$count_only) {
                        $qb->select('tech.' . $tech_name_col . ' AS technician_name', false);
                    }
                }
            }
        }

        if ($this->db->table_exists('pppoe_secrets')) {
            $secret_fields = $this->db->list_fields('pppoe_secrets');
            if (in_array('username', $secret_fields, true)) {
                $customer_username_expr = null;
                if ($this->has_field('pppoe_username') && $this->has_field('username')) {
                    $customer_username_expr = "COALESCE(NULLIF(c.pppoe_username,''), NULLIF(c.username,''))";
                } elseif ($this->has_field('pppoe_username')) {
                    $customer_username_expr = "NULLIF(c.pppoe_username,'')";
                } elseif ($this->has_field('username')) {
                    $customer_username_expr = "NULLIF(c.username,'')";
                }

                if ($has_service_join && $has_service_username) {
                    if ($customer_username_expr !== null) {
                        $customer_username_expr = "COALESCE({$customer_username_expr}, NULLIF(cs.pppoe_username,''))";
                    } else {
                        $customer_username_expr = "NULLIF(cs.pppoe_username,'')";
                    }
                }

                if ($customer_username_expr !== null) {
                    $secret_username_expr = 'CONVERT(sps.username USING utf8mb4) COLLATE utf8mb4_unicode_ci';
                    $customer_username_join_expr = 'CONVERT((' . $customer_username_expr . ') USING utf8mb4) COLLATE utf8mb4_unicode_ci';
                    $qb->join('pppoe_secrets sps', $secret_username_expr . ' = ' . $customer_username_join_expr, 'left', false);
                    if (!$count_only) {
                        if (in_array('ppp_password', $secret_fields, true)) {
                            $qb->select('sps.ppp_password as secret_ppp_password');
                        } elseif (in_array('password', $secret_fields, true)) {
                            $qb->select('sps.password as secret_ppp_password');
                        }
                    }
                }
            }
        }

        if ($this->db->table_exists('invoices')) {
            $inv_table = $this->db->dbprefix('invoices');
            $sub_invoice = "(\n                SELECT customer_id,\n                       SUM(balance_amount) AS outstanding_amount,\n                       MIN(due_date) AS next_due_date\n                FROM {$inv_table}\n                WHERE status IN ('issued', 'overdue', 'partially_paid')\n                GROUP BY customer_id\n            ) inv";

            $qb->join($sub_invoice, 'inv.customer_id = c.id', 'left', false);
            if (!$count_only) {
                $qb->select('inv.outstanding_amount, inv.next_due_date');
            }
        }

        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            $qb->group_start();
            $search_columns = array('full_name', 'nama', 'pppoe_username', 'username', 'customer_code', 'address', 'lokasi');
            $has_like = false;
            foreach ($search_columns as $column) {
                if (!$this->has_field($column)) {
                    continue;
                }

                if (!$has_like) {
                    $qb->like('c.' . $column, $keyword);
                    $has_like = true;
                } else {
                    $qb->or_like('c.' . $column, $keyword);
                }
            }

            if ($has_profile_join) {
                if ($has_like) {
                    $qb->or_like('p.name', $keyword);
                } else {
                    $qb->like('p.name', $keyword);
                    $has_like = true;
                }
            }

            if ($has_service_join && $has_service_username) {
                if ($has_like) {
                    $qb->or_like('cs.pppoe_username', $keyword);
                } else {
                    $qb->like('cs.pppoe_username', $keyword);
                    $has_like = true;
                }
            }

            if ($has_technician_join) {
                if ($tech_has_name || $tech_has_username) {
                    if ($has_like) {
                        if ($tech_has_name) {
                            $qb->or_like('tech.name', $keyword);
                        }
                        if ($tech_has_username) {
                            $qb->or_like('tech.username', $keyword);
                        }
                    } else {
                        $qb->group_start();
                        if ($tech_has_name) {
                            $qb->like('tech.name', $keyword);
                        }
                        if ($tech_has_username) {
                            if ($tech_has_name) {
                                $qb->or_like('tech.username', $keyword);
                            } else {
                                $qb->like('tech.username', $keyword);
                            }
                        }
                        $qb->group_end();
                        $has_like = true;
                    }
                }
            }

            if (!$has_like) {
                $qb->where('1 = 0', null, false);
            }
            $qb->group_end();
        }

        $this->apply_tenant_scope($qb, 'c');
        $this->apply_soft_delete_scope($qb, 'c');
        $this->apply_router_scope_to_list_query($qb, $has_service_join, $cs_fields);
        if (!$count_only && $this->has_field('id')) {
            $qb->order_by('c.id', 'DESC');
        }

        return $qb;
    }

    private function resolve_tenant_id()
    {
        if (function_exists('getTenantId')) {
            $tenant_id = getTenantId();
            return $tenant_id !== null ? (int) $tenant_id : null;
        }

        return null;
    }

    private function resolve_router_scope_id()
    {
        if (function_exists('getRouterScopeId')) {
            $scope = getRouterScopeId();
            return $scope !== null ? (int) $scope : null;
        }

        return null;
    }

    private function apply_tenant_scope(CI_DB_query_builder &$qb, $alias = '')
    {
        if (!$this->has_field('tenant_id') || $this->tenant_id === null) {
            return;
        }

        $prefix = ($alias !== '') ? ($alias . '.') : '';
        $qb->where($prefix . 'tenant_id', (int) $this->tenant_id);
    }

    private function apply_soft_delete_scope(CI_DB_query_builder &$qb, $alias = '')
    {
        $prefix = ($alias !== '') ? ($alias . '.') : '';

        if ($this->has_field('deleted_at')) {
            $qb->where($prefix . 'deleted_at IS NULL', null, false);
        }
        if ($this->has_field('is_deleted')) {
            $qb->where($prefix . 'is_deleted', 0);
        }
        if ($this->has_field('deleted')) {
            $qb->where($prefix . 'deleted', 0);
        }
        if ($this->has_field('status')) {
            $deleted_statuses = $this->resolve_existing_status_values(array('terminated', 'deleted', 'inactive', 'disabled'));
            if (!empty($deleted_statuses)) {
                $qb->where_not_in($prefix . 'status', $deleted_statuses);
            }
        }
    }

    private function resolve_status_value(array $candidates)
    {
        $values = $this->get_status_values();
        if (!empty($values)) {
            foreach ($candidates as $candidate) {
                $candidate = strtolower(trim((string) $candidate));
                if ($candidate !== '' && in_array($candidate, $values, true)) {
                    return $candidate;
                }
            }
            return null;
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function resolve_existing_status_values(array $candidates)
    {
        $values = $this->get_status_values();
        if (empty($values)) {
            return array_values(array_filter(array_map('trim', $candidates), static function ($value) {
                return $value !== '';
            }));
        }

        $matched = array();
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ($candidate !== '' && in_array($candidate, $values, true)) {
                $matched[] = $candidate;
            }
        }

        return array_values(array_unique($matched));
    }

    private function get_status_values()
    {
        if ($this->status_values !== null) {
            return $this->status_values;
        }

        $this->status_values = array();
        if (!$this->has_field('status')) {
            return $this->status_values;
        }

        $table = (string) $this->db->dbprefix($this->table);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return $this->status_values;
        }

        $row = $this->db
            ->query("SHOW COLUMNS FROM `" . $this->db->escape_str($table) . "` LIKE " . $this->db->escape('status'))
            ->row_array();
        if (empty($row['Type']) || !preg_match('/^enum\((.*)\)$/i', (string) $row['Type'], $matches)) {
            return $this->status_values;
        }

        $values = str_getcsv($matches[1], ',', "'");
        foreach ($values as $value) {
            $value = strtolower(trim((string) $value));
            if ($value !== '') {
                $this->status_values[] = $value;
            }
        }

        return $this->status_values;
    }

    private function apply_router_scope_to_list_query(CI_DB_query_builder &$qb, $has_service_join, array $cs_fields)
    {
        if ($this->router_scope_id === null) {
            return;
        }

        $scope_router_id = (int) $this->router_scope_id;
        $customer_has_router_id = $this->has_field('router_id');
        $service_has_router_id = $has_service_join && in_array('router_id', $cs_fields, true);

        if ($service_has_router_id && $customer_has_router_id) {
            // Legacy data bisa belum memiliki customer_services.router_id.
            // Gunakan fallback ke customers.router_id agar data lama tetap tampil sesuai distribusi.
            $qb->where('COALESCE(cs.router_id, c.router_id) = ' . $scope_router_id, null, false);
            return;
        }

        if ($service_has_router_id) {
            $qb->where('cs.router_id', $scope_router_id);
            return;
        }

        if ($customer_has_router_id) {
            $qb->where('c.router_id', $scope_router_id);
            return;
        }

        // Secure default: jika tidak ada kolom router_id sama sekali, blok data.
        $qb->where('1 = 0', null, false);
    }

    private function can_access_customer_by_router_scope($customer_id)
    {
        if ($this->router_scope_id === null) {
            return true;
        }

        $scope_router_id = (int) $this->router_scope_id;

        if ($this->has_field('router_id')) {
            $customer_qb = $this->db
                ->from($this->table)
                ->where('id', (int) $customer_id)
                ->where('router_id', $scope_router_id);

            $this->apply_tenant_scope($customer_qb);
            $this->apply_soft_delete_scope($customer_qb);

            if ((int) $customer_qb->count_all_results() > 0) {
                return true;
            }
        }

        if (!$this->db->table_exists('customer_services')) {
            return false;
        }

        $service_fields = $this->db->list_fields('customer_services');
        if (!in_array('customer_id', $service_fields, true) || !in_array('router_id', $service_fields, true)) {
            return false;
        }

        $count = (int) $this->db
            ->from('customer_services')
            ->where('customer_id', (int) $customer_id)
            ->where('router_id', $scope_router_id)
            ->count_all_results();

        return $count > 0;
    }
}
