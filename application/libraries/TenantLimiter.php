<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class TenantLimiter
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->helper(array('tenant', 'subscription'));
    }

    public function canAddRouter()
    {
        return $this->checkLimit('router', 'routers', 'max_router', array('is_active' => 1, 'status' => 'active'));
    }

    public function canAddCustomer()
    {
        return $this->checkLimit('customer', 'customers', 'max_customer', array());
    }

    public function canAddUser()
    {
        return $this->checkLimit('user', 'users', 'max_user', array('status !=' => 'inactive'));
    }

    public function canAddTelegramGroup()
    {
        return $this->checkLimit('telegram group', 'telegram_groups', 'max_telegram', array('is_active' => 1));
    }

    public function canAddTechnician()
    {
        return $this->checkLimit('teknisi', 'users', 'max_technician', array('role' => 'teknisi', 'status !=' => 'inactive'));
    }

    public function getUsageSummary($tenant_id = null)
    {
        $tenant_id = $tenant_id !== null ? (int) $tenant_id : (int) (getTenantId() ?: 0);
        if ($tenant_id <= 0) {
            return array(
                'success' => false,
                'message' => 'Tenant context tidak tersedia.',
                'subscription' => null,
                'usage' => array(),
            );
        }

        $subscription = getActiveTenantSubscription($tenant_id);
        $tenant_limits = $this->getTenantLimits($tenant_id);

        $limits = array(
            'router' => $this->resolveLimit('max_router', $subscription, $tenant_limits),
            'customer' => $this->resolveLimit('max_customer', $subscription, $tenant_limits),
            'user' => $this->resolveLimit('max_user', $subscription, $tenant_limits),
            'telegram_group' => $this->resolveLimit('max_telegram', $subscription, $tenant_limits),
            'technician' => $this->resolveLimit('max_technician', $subscription, $tenant_limits),
        );

        $usage = array(
            'router' => $this->countScoped('routers', $tenant_id, array('status' => 'active')),
            'customer' => $this->countScoped('customers', $tenant_id),
            'user' => $this->countScoped('users', $tenant_id, array('status !=' => 'inactive')),
            'telegram_group' => $this->countScoped('telegram_groups', $tenant_id, array('is_active' => 1)),
            'technician' => $this->countScoped('users', $tenant_id, array('role' => 'teknisi', 'status !=' => 'inactive')),
        );

        return array(
            'success' => true,
            'message' => 'OK',
            'subscription' => $subscription,
            'limits' => $limits,
            'usage' => $usage,
        );
    }

    private function checkLimit($resource_label, $table, $limit_column, array $extra_where = array())
    {
        if ($this->isLimiterBypassRole()) {
            return array(
                'allowed' => true,
                'message' => 'Superadmin/Platform owner tidak dibatasi paket tenant.',
                'resource' => $resource_label,
                'current' => 0,
                'max' => -1,
            );
        }

        $tenant_id = (int) (getTenantId() ?: 0);
        if ($tenant_id <= 0) {
            return array(
                'allowed' => false,
                'message' => 'Tenant context tidak valid.',
                'resource' => $resource_label,
                'current' => 0,
                'max' => 0,
            );
        }

        $subscription = getActiveTenantSubscription($tenant_id);
        $tenant_limits = $this->getTenantLimits($tenant_id);
        $max = $this->resolveLimit($limit_column, $subscription, $tenant_limits);
        if ($max <= 0) {
            return array(
                'allowed' => true,
                'message' => 'Limit ' . $resource_label . ' belum diatur (mode unlimited).',
                'resource' => $resource_label,
                'current' => 0,
                'max' => $max,
            );
        }

        $current = $this->countScoped($table, $tenant_id, $extra_where);
        if ($current >= $max) {
            return array(
                'allowed' => false,
                'message' => 'Limit ' . $resource_label . ' paket Anda telah tercapai (' . $current . '/' . $max . ').',
                'resource' => $resource_label,
                'current' => $current,
                'max' => $max,
            );
        }

        return array(
            'allowed' => true,
            'message' => 'OK',
            'resource' => $resource_label,
            'current' => $current,
            'max' => $max,
        );
    }

    private function countScoped($table, $tenant_id, array $extra_where = array())
    {
        if (!$this->CI->db->table_exists($table)) {
            return 0;
        }

        $fields = $this->CI->db->list_fields($table);
        $qb = $this->CI->db->from($table);

        if (in_array('tenant_id', $fields, true)) {
            $qb->where('tenant_id', (int) $tenant_id);
        }

        foreach ($extra_where as $key => $value) {
            $field = trim((string) $key);
            $base_field = $field;
            if (strpos($field, ' ') !== false) {
                $base_field = substr($field, 0, strpos($field, ' '));
            }

            if (!in_array($base_field, $fields, true)) {
                continue;
            }

            $qb->where($key, $value);
        }

        return (int) $qb->count_all_results();
    }

    private function resolveLimit($limit_key, $subscription, array $tenant_limits)
    {
        $candidate_map = array(
            'max_router' => array('max_router', 'max_routers'),
            'max_customer' => array('max_customer', 'max_customers'),
            'max_user' => array('max_user'),
            'max_telegram' => array('max_telegram', 'max_telegram_group'),
            'max_telegram_group' => array('max_telegram_group', 'max_telegram'),
            'max_technician' => array('max_technician', 'max_user'),
        );

        $candidates = isset($candidate_map[$limit_key]) ? $candidate_map[$limit_key] : array($limit_key);

        if (is_array($subscription) && !empty($subscription)) {
            foreach ($candidates as $candidate) {
                if (isset($subscription[$candidate]) && (int) $subscription[$candidate] > 0) {
                    return (int) $subscription[$candidate];
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($tenant_limits[$candidate]) && (int) $tenant_limits[$candidate] > 0) {
                return (int) $tenant_limits[$candidate];
            }
        }

        return 0;
    }

    private function getTenantLimits($tenant_id)
    {
        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0 || !$this->CI->db->table_exists('tenants')) {
            return array();
        }

        $fields = $this->CI->db->list_fields('tenants');
        $wanted = array('max_router', 'max_routers', 'max_customer', 'max_customers', 'max_user', 'max_telegram', 'max_telegram_group', 'max_technician');
        $select = array();
        foreach ($wanted as $field) {
            if (in_array($field, $fields, true)) {
                $select[] = $field;
            }
        }

        if (empty($select)) {
            return array();
        }

        $row = $this->CI->db
            ->select(implode(',', $select), false)
            ->from('tenants')
            ->where('id', $tenant_id)
            ->limit(1)
            ->get()
            ->row_array();

        return is_array($row) ? $row : array();
    }

    private function isLimiterBypassRole()
    {
        $role = strtolower(trim((string) $this->CI->session->userdata('role')));
        if (in_array($role, array('platform_owner', 'superadmin'), true)) {
            return true;
        }

        return function_exists('isPlatformOwner') ? (bool) isPlatformOwner() : false;
    }
}
