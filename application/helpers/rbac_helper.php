<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('normalizeRole')) {
    /**
     * Normalisasi role legacy SaaS ke role single-install.
     *
     * @param string|null $role
     * @return string
     */
    function normalizeRole($role)
    {
        $role = strtolower(trim((string) $role));
        $map = array(
            'platform_owner' => 'superadmin',
            'platform_user' => 'superadmin',
            'owner' => 'superadmin',
            'tenant_owner' => 'admin',
            'tenant_admin' => 'admin',
            'technician' => 'teknisi',
            'tech' => 'teknisi',
        );

        if (isset($map[$role])) {
            return $map[$role];
        }

        if (in_array($role, array('superadmin', 'admin', 'teknisi'), true)) {
            return $role;
        }

        return $role;
    }
}

if (!function_exists('hasRole')) {
    /**
     * Cek role user terhadap daftar role yang diizinkan.
     *
     * @param array|string $roles
     * @param string|null $currentRole
     * @return bool
     */
    function hasRole($roles, $currentRole = null)
    {
        if (!is_array($roles)) {
            $roles = array($roles);
        }

        if ($currentRole === null) {
            $CI =& get_instance();
            if (!isset($CI->session)) {
                $CI->load->library('session');
            }
            $currentRole = (string) $CI->session->userdata('role');
        }

        $current = normalizeRole($currentRole);
        $allowed = array();
        foreach ($roles as $role) {
            $allowed[] = normalizeRole($role);
        }

        return in_array($current, $allowed, true);
    }
}

if (!function_exists('roleMatrix')) {
    /**
     * Matrix akses role sistem single-install.
     *
     * @return array
     */
    function roleMatrix()
    {
        return array(
            'customers' => array('superadmin', 'admin'),
            'billing' => array('superadmin', 'admin'),
            'work_orders' => array('superadmin', 'admin', 'teknisi'),
            'tickets' => array('superadmin', 'admin', 'teknisi'),
            'settings' => array('superadmin'),
        );
    }
}

if (!function_exists('canAccessModule')) {
    /**
     * Cek akses role ke modul berdasarkan role matrix global.
     *
     * @param string $module
     * @param string|null $currentRole
     * @return bool
     */
    function canAccessModule($module, $currentRole = null)
    {
        $module = strtolower(trim((string) $module));
        $matrix = roleMatrix();
        if (!isset($matrix[$module])) {
            return false;
        }

        return hasRole((array) $matrix[$module], $currentRole);
    }
}
