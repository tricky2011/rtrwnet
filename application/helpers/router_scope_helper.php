<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('getRouterScopeId')) {
    /**
     * Ambil router scope dari session user.
     * - superadmin => NULL (akses semua router)
     * - admin/teknisi => INT router_scope_id jika tersedia
     *
     * @return int|null
     */
    function getRouterScopeId()
    {
        $CI =& get_instance();
        if (!isset($CI->session)) {
            $CI->load->library('session');
        }

        $role = normalizeRole((string) $CI->session->userdata('role'));
        if ($role === 'superadmin') {
            $active_router_id = 0;
            if (function_exists('active_router_id')) {
                $active_router_id = (int) active_router_id(0);
            } else {
                $active_router_id = (int) $CI->session->userdata('active_router_id');
                if ($active_router_id <= 0) {
                    $active_router_id = (int) $CI->session->userdata('active_router');
                }
                if ($active_router_id <= 0) {
                    $active_router_id = (int) $CI->session->userdata('dashboard_router_id');
                }
                if ($active_router_id <= 0) {
                    $active_router_id = (int) $CI->session->userdata('router_scope_id');
                }
            }

            return $active_router_id > 0 ? $active_router_id : null;
        }

        $scope = (int) $CI->session->userdata('router_scope_id');
        return $scope > 0 ? $scope : null;
    }
}

if (!function_exists('active_router_id')) {
    /**
     * Ambil router aktif dari session.
     * Urutan fallback:
     * 1) active_router_id
     * 2) dashboard_router_id
     * 3) router_scope_id
     * 4) nilai fallback (default 1)
     *
     * @param int $fallback
     * @return int
     */
    function active_router_id($fallback = 1)
    {
        $CI =& get_instance();
        if (!isset($CI->session)) {
            $CI->load->library('session');
        }

        $active_router_id = (int) $CI->session->userdata('active_router_id');
        if ($active_router_id <= 0) {
            $active_router_id = (int) $CI->session->userdata('active_router');
        }
        if ($active_router_id <= 0) {
            $active_router_id = (int) $CI->session->userdata('dashboard_router_id');
        }
        if ($active_router_id <= 0) {
            $active_router_id = (int) $CI->session->userdata('router_scope_id');
        }

        if ($active_router_id > 0) {
            return $active_router_id;
        }

        $fallback = (int) $fallback;
        return $fallback > 0 ? $fallback : 0;
    }
}

if (!function_exists('isRouterScopedUser')) {
    /**
     * Return true jika user non-superadmin.
     *
     * @return bool
     */
    function isRouterScopedUser()
    {
        $CI =& get_instance();
        if (!isset($CI->session)) {
            $CI->load->library('session');
        }

        $role = normalizeRole((string) $CI->session->userdata('role'));
        return $role !== 'superadmin';
    }
}

if (!function_exists('getActiveRouter')) {
    /**
     * Ambil konteks distribusi/router aktif untuk indikator UI global.
     *
     * Return contoh:
     * [
     *   'role' => 'superadmin',
     *   'router_id' => 3|null,
     *   'router_name' => 'BDG-MAIN',
     *   'is_all' => false|true,
     *   'label' => 'Distribusi: BDG-MAIN (#3)'
     * ]
     *
     * @return array
     */
    function getActiveRouter()
    {
        static $router_name_cache = array();

        $CI =& get_instance();
        if (!isset($CI->session)) {
            $CI->load->library('session');
        }

        $role = normalizeRole((string) $CI->session->userdata('role'));
        $context = array(
            'role' => $role,
            'router_id' => null,
            'router_name' => 'Semua',
            'is_all' => true,
            'label' => 'Distribusi: Semua',
        );

        if ($role === 'superadmin') {
            $active_router_id = (int) $CI->session->userdata('active_router_id');
            if ($active_router_id <= 0) {
                $active_router_id = (int) $CI->session->userdata('active_router');
            }
            if ($active_router_id <= 0) {
                $active_router_id = (int) $CI->session->userdata('dashboard_router_id');
            }
            if ($active_router_id <= 0) {
                $active_router_id = (int) $CI->session->userdata('router_scope_id');
            }
            if ($active_router_id > 0) {
                $router_name = '';
                if (isset($router_name_cache[$active_router_id])) {
                    $router_name = (string) $router_name_cache[$active_router_id];
                } else {
                    if (!isset($CI->db) || !is_object($CI->db)) {
                        $CI->load->database();
                    }
                    if (isset($CI->db) && is_object($CI->db) && $CI->db->table_exists('routers')) {
                        $router_fields = $CI->db->list_fields('routers');
                        $name_col = in_array('name', $router_fields, true)
                            ? 'name'
                            : (in_array('router_name', $router_fields, true) ? 'router_name' : '');
                        if ($name_col !== '') {
                            $row = $CI->db
                                ->select('id, ' . $name_col . ' AS name', false)
                                ->from('routers')
                                ->where('id', $active_router_id)
                                ->limit(1)
                                ->get()
                                ->row_array();
                            if (!empty($row['name'])) {
                                $router_name = (string) $row['name'];
                            }
                        }
                    }
                    $router_name_cache[$active_router_id] = $router_name;
                }

                $context['router_id'] = $active_router_id;
                $context['router_name'] = $router_name !== '' ? $router_name : ('Router #' . $active_router_id);
                $context['is_all'] = false;
                $context['label'] = 'Distribusi: ' . $context['router_name'];
                return $context;
            }

            return $context;
        }

        $scope_id = (int) $CI->session->userdata('router_scope_id');
        if ($scope_id <= 0) {
            if (!isset($CI->db) || !is_object($CI->db)) {
                $CI->load->database();
            }
            if (isset($CI->db) && is_object($CI->db) && $CI->db->table_exists('users')) {
                $user_fields = $CI->db->list_fields('users');
                if (in_array('router_scope_id', $user_fields, true)) {
                    $user_id = (int) $CI->session->userdata('user_id');
                    if ($user_id > 0) {
                        $row = $CI->db
                            ->select('router_scope_id')
                            ->from('users')
                            ->where('id', $user_id)
                            ->limit(1)
                            ->get()
                            ->row_array();
                        $scope_id = (int) ($row['router_scope_id'] ?? 0);
                        if ($scope_id > 0) {
                            $CI->session->set_userdata('router_scope_id', $scope_id);
                        }
                    }
                }
            }
        }

        if ($scope_id <= 0) {
            $context['router_name'] = 'Router Scope belum diset';
            $context['label'] = 'Distribusi: Router Scope belum diset';
            return $context;
        }

        $router_name = '';
        if (isset($router_name_cache[$scope_id])) {
            $router_name = (string) $router_name_cache[$scope_id];
        } else {
            if (!isset($CI->db) || !is_object($CI->db)) {
                $CI->load->database();
            }
            if (isset($CI->db) && is_object($CI->db) && $CI->db->table_exists('routers')) {
                $router_fields = $CI->db->list_fields('routers');
                $name_col = in_array('name', $router_fields, true)
                    ? 'name'
                    : (in_array('router_name', $router_fields, true) ? 'router_name' : '');
                if ($name_col !== '') {
                    $row = $CI->db
                        ->select('id, ' . $name_col . ' AS name', false)
                        ->from('routers')
                        ->where('id', $scope_id)
                        ->limit(1)
                        ->get()
                        ->row_array();
                    if (!empty($row['name'])) {
                        $router_name = (string) $row['name'];
                    }
                }
            }
            $router_name_cache[$scope_id] = $router_name;
        }

        $context['router_id'] = $scope_id;
        $context['router_name'] = $router_name !== '' ? ($router_name . ' (#' . $scope_id . ')') : ('Router #' . $scope_id);
        $context['is_all'] = false;
        $context['label'] = 'Distribusi: ' . $context['router_name'];

        return $context;
    }
}
