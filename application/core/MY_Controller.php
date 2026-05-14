<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class MY_Controller extends CI_Controller
{
    private static $user_activity_log_table_available = null;
    protected $tenant_id = null; // compatibility only (single-install mode)
    protected $is_platform_owner = false; // compatibility only (single-install mode)
    protected $router_scope_id = null;
    protected $is_router_scoped_user = false;

    public function __construct($require_login = true)
    {
        parent::__construct();
        $this->load->library('session');
        $this->load->helper(array('url', 'rbac', 'router_scope'));
        $this->enforce_https_if_needed();
        $this->enforce_session_idle_timeout();

        if ($require_login && !$this->session->userdata('logged_in')) {
            $this->session->set_flashdata('error', 'Silakan login terlebih dahulu.');
            redirect('auth/login');
            exit;
        }

        if ($this->session->userdata('logged_in')) {
            $this->enforce_user_status_active();
            $this->applyRouterScope();
            $this->share_active_router_indicator();
        }

        $this->log_user_activity();
    }

    protected function applyRouterScope()
    {
        $this->router_scope_id = null;
        $this->is_router_scoped_user = false;

        if (!$this->session->userdata('logged_in')) {
            return;
        }

        $role = normalizeRole((string) $this->session->userdata('role'));
        if ($role === 'superadmin') {
            $active_router_id = (int) $this->session->userdata('active_router_id');
            if ($active_router_id <= 0) {
                $active_router_id = (int) $this->session->userdata('active_router');
            }
            if ($active_router_id <= 0) {
                $active_router_id = (int) $this->session->userdata('dashboard_router_id');
            }
            if ($active_router_id <= 0) {
                $active_router_id = (int) $this->session->userdata('router_scope_id');
            }
            if ($active_router_id > 0) {
                $this->router_scope_id = $active_router_id;
                $this->session->set_userdata('active_router', $active_router_id);
                $this->session->set_userdata('router_scope_id', $active_router_id);
                $this->session->set_userdata('active_router_id', $active_router_id);
                $this->session->set_userdata('dashboard_router_id', $active_router_id);
            } else {
                $this->router_scope_id = null;
                $this->session->set_userdata('active_router', null);
                $this->session->set_userdata('active_router_id', null);
                $this->session->set_userdata('router_scope_id', null);
                $this->session->set_userdata('dashboard_router_id', null);
            }
            return;
        }

        $this->is_router_scoped_user = true;

        $scope_from_session = (int) $this->session->userdata('router_scope_id');

        if (!isset($this->db) || !is_object($this->db)) {
            $this->load->database();
        }

        if (!$this->db->table_exists('users')) {
            if ($scope_from_session > 0) {
                $this->router_scope_id = $scope_from_session;
            }
            return;
        }

        $user_fields = $this->db->list_fields('users');
        if (!in_array('router_scope_id', $user_fields, true)) {
            if ($scope_from_session > 0) {
                $this->router_scope_id = $scope_from_session;
            }
            return;
        }

        $user_id = (int) $this->session->userdata('user_id');
        if ($user_id <= 0) {
            return;
        }

        $row = $this->db
            ->select('router_scope_id')
            ->from('users')
            ->where('id', $user_id)
            ->limit(1)
            ->get()
            ->row_array();

        $scope_db = (int) ($row['router_scope_id'] ?? 0);
        if ($scope_db > 0) {
            $this->router_scope_id = $scope_db;
            $this->session->set_userdata('router_scope_id', $scope_db);
            return;
        }

        if ($scope_from_session > 0) {
            $this->router_scope_id = $scope_from_session;
        }
    }

    protected function applyRouterFilter($table_alias = null, CI_DB_query_builder $qb = null)
    {
        $builder = $qb instanceof CI_DB_query_builder ? $qb : (isset($this->db) && is_object($this->db) ? $this->db : null);
        if (!$builder instanceof CI_DB_query_builder) {
            return;
        }

        $prefix = '';
        if ($table_alias !== null) {
            $alias = trim((string) $table_alias);
            if ($alias !== '') {
                // Defensive: only allow alnum/underscore alias to prevent malformed identifiers.
                if (!preg_match('/^[A-Za-z0-9_]+$/', $alias)) {
                    $builder->where('1 = 0', null, false);
                    return;
                }
                $prefix = $alias . '.';
            }
        }

        $effective_router_id = $this->getEffectiveRouterId();
        if ($effective_router_id !== null) {
            $builder->where($prefix . 'router_id', (int) $effective_router_id);
            return;
        }

        if ($this->is_superadmin()) {
            return;
        }

        // Secure default: user non-superadmin tanpa router scope tidak boleh melihat data lintas router.
        $builder->where('1 = 0', null, false);
    }

    protected function current_router_scope_id()
    {
        return $this->getEffectiveRouterId();
    }

    protected function getEffectiveRouterId()
    {
        if (!$this->session->userdata('logged_in')) {
            return null;
        }

        $role = normalizeRole((string) $this->session->userdata('role'));
        if ($role === 'superadmin') {
            $active_router_id = (int) $this->session->userdata('active_router_id');
            if ($active_router_id <= 0) {
                $active_router_id = (int) $this->session->userdata('active_router');
            }
            if ($active_router_id <= 0) {
                $active_router_id = (int) $this->session->userdata('dashboard_router_id');
            }
            if ($active_router_id <= 0) {
                $active_router_id = (int) $this->session->userdata('router_scope_id');
            }
            return $active_router_id > 0 ? $active_router_id : null;
        }

        $scope_router_id = (int) $this->session->userdata('router_scope_id');
        if ($scope_router_id > 0) {
            return $scope_router_id;
        }

        return (int) $this->router_scope_id > 0 ? (int) $this->router_scope_id : null;
    }

    protected function share_active_router_indicator()
    {
        if (!function_exists('getActiveRouter')) {
            return;
        }

        $router_context = getActiveRouter();
        if (!is_array($router_context)) {
            return;
        }

        $this->load->vars(array(
            'active_router_context' => $router_context,
            'active_router_badge_text' => (string) ($router_context['label'] ?? 'Distribusi: -'),
        ));
    }

    protected function enforce_user_status_active()
    {
        if (!$this->session->userdata('logged_in')) {
            return;
        }

        if (!isset($this->db) || !is_object($this->db)) {
            $this->load->database();
        }

        if (!$this->db->table_exists('users')) {
            return;
        }

        $user_id = (int) $this->session->userdata('user_id');
        if ($user_id <= 0) {
            $this->session->sess_destroy();
            $this->session->set_flashdata('error', 'Sesi tidak valid. Silakan login kembali.');
            redirect('auth/login');
            exit;
        }

        $user = $this->db->select('id,role,status')
            ->from('users')
            ->where('id', $user_id)
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($user) || strtolower((string) ($user['status'] ?? '')) !== 'active') {
            $this->session->sess_destroy();
            $this->session->set_flashdata('error', 'Akun Anda tidak aktif.');
            redirect('auth/login');
            exit;
        }

        $role = normalizeRole((string) ($user['role'] ?? ''));
        if (!in_array($role, array('superadmin', 'admin', 'teknisi'), true)) {
            $this->session->sess_destroy();
            $this->session->set_flashdata('error', 'Role user tidak valid.');
            redirect('auth/login');
            exit;
        }

        $this->session->set_userdata('role', $role);
    }

    protected function enforce_tenant_context()
    {
        // SaaS middleware disabled (single-install mode)
        return;
    }

    protected function enforce_tenant_subscription()
    {
        // SaaS middleware disabled (single-install mode)
        return;
    }

    protected function enforce_tenant_lock_mode()
    {
        // SaaS middleware disabled (single-install mode)
        return;
    }

    protected function should_skip_subscription_guard()
    {
        return false;
    }

    protected function can_access_when_tenant_locked()
    {
        return true;
    }

    protected function deny_tenant_access($message)
    {
        // SaaS middleware disabled (single-install mode)
        return;
    }

    protected function assert_tenant_not_suspended($message = 'Akun Anda disuspend karena subscription tidak aktif.')
    {
        // Backward compatibility: always allowed in single-install mode.
        return true;
    }

    protected function enforce_session_idle_timeout()
    {
        if (!$this->session->userdata('logged_in')) {
            return;
        }

        $idle_timeout = max(60, (int) $this->config->item('auth_session_idle_timeout'));
        $now = time();
        $last_activity = (int) $this->session->userdata('last_activity_ts');

        if ($last_activity > 0 && ($now - $last_activity) >= $idle_timeout) {
            $this->session->unset_userdata(array(
                'user_id',
                'name',
                'role',
                'router_scope_id',
                'logged_in',
                'user',
                'last_activity_ts',
            ));
            $this->session->sess_regenerate(true);
            $this->session->set_flashdata('error', 'Sesi berakhir otomatis setelah 10 menit tanpa aktivitas. Silakan login kembali.');
            redirect('auth/login');
            exit;
        }

        $this->session->set_userdata('last_activity_ts', $now);
    }

    protected function enforce_https_if_needed()
    {
        if (is_cli()) {
            return;
        }

        if (!$this->config->item('force_https')) {
            return;
        }

        $is_https = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        );

        if ($is_https) {
            return;
        }

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        if ($host === '') {
            return;
        }

        redirect('https://' . $host . $uri, 'location', 301);
        exit;
    }

    protected function require_role(array $roles = array(), $message = 'Akses ditolak. Anda tidak memiliki izin.')
    {
        if (empty($roles)) {
            return true;
        }

        if (!hasRole($roles)) {
            show_error($message, 403);
            return false;
        }

        return true;
    }

    /**
     * Enforce akses modul berdasarkan roleMatrix() di helper RBAC.
     *
     * @param string $module
     * @param string $message
     * @return bool
     */
    protected function require_module_access($module, $message = 'Akses ditolak.')
    {
        $module = strtolower(trim((string) $module));
        if ($module === '') {
            show_error($message, 403);
            return false;
        }

        if (!function_exists('canAccessModule') || !canAccessModule($module)) {
            show_error($message, 403);
            return false;
        }

        return true;
    }

    protected function current_user()
    {
        return array(
            'id' => (int) $this->session->userdata('user_id'),
            'name' => (string) $this->session->userdata('name'),
            'role' => (string) $this->session->userdata('role'),
        );
    }

    protected function is_superadmin()
    {
        return hasRole(array('superadmin'));
    }

    protected function get_current_tenant_id()
    {
        return null;
    }

    protected function require_platform_owner($message = 'Akses ditolak. Hanya platform owner.')
    {
        // Single-install mode: alias platform owner to superadmin.
        if ($this->is_superadmin()) {
            return true;
        }

        show_error($message, 403);
        return false;
    }

    protected function get_per_page_options()
    {
        return array(20, 50, 100, 500);
    }

    protected function resolve_per_page($default = 20)
    {
        $allowed = $this->get_per_page_options();
        $default = (int) $default;
        if (!in_array($default, $allowed, true)) {
            $default = (int) reset($allowed);
        }

        $requested = (int) $this->input->get('per_page', true);
        if ($requested > 0 && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $default;
    }

    protected function init_pagination($base_url, $total_rows, $per_page = 20, $uri_segment = 3)
    {
        $this->load->library('pagination');
        $resolved_per_page = $this->resolve_per_page($per_page);

        $config = array(
            'base_url' => site_url(trim((string) $base_url, '/')),
            'total_rows' => max(0, (int) $total_rows),
            'per_page' => max(1, (int) $resolved_per_page),
            'uri_segment' => max(1, (int) $uri_segment),
            'page_query_string' => true,
            'query_string_segment' => 'page',
            'reuse_query_string' => true,
            'num_links' => 3,
            'full_tag_open' => '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">',
            'full_tag_close' => '</ul></nav>',
            'first_tag_open' => '<li class="page-item">',
            'first_tag_close' => '</li>',
            'last_tag_open' => '<li class="page-item">',
            'last_tag_close' => '</li>',
            'next_tag_open' => '<li class="page-item">',
            'next_tag_close' => '</li>',
            'prev_tag_open' => '<li class="page-item">',
            'prev_tag_close' => '</li>',
            'cur_tag_open' => '<li class="page-item active"><span class="page-link">',
            'cur_tag_close' => '</span></li>',
            'num_tag_open' => '<li class="page-item">',
            'num_tag_close' => '</li>',
            'attributes' => array('class' => 'page-link'),
            'first_link' => '&laquo;',
            'last_link' => '&raquo;',
            'next_link' => '&rsaquo;',
            'prev_link' => '&lsaquo;',
        );

        $this->pagination->initialize($config);

        $offset = (int) $this->input->get($config['query_string_segment'], true);
        if ($offset < 0) {
            $offset = 0;
        }
        if ($config['total_rows'] > 0 && $offset >= $config['total_rows']) {
            $offset = (int) (floor(($config['total_rows'] - 1) / $config['per_page']) * $config['per_page']);
        }

        return array(
            'offset' => $offset,
            'per_page' => (int) $config['per_page'],
            'links' => $this->pagination->create_links(),
            'total_rows' => (int) $config['total_rows'],
        );
    }

    protected function log_user_activity()
    {
        if (is_cli()) {
            return;
        }

        if (!$this->session->userdata('logged_in')) {
            return;
        }

        $request_method = strtoupper((string) $this->input->method());
        $controller = strtolower((string) $this->router->fetch_class());
        $method = strtolower((string) $this->router->fetch_method());

        if ($controller === '') {
            return;
        }

        if (!$this->should_log_activity_compact($request_method, $controller, $method)) {
            return;
        }

        if (!$this->is_user_activity_log_table_ready()) {
            return;
        }

        $this->load->model('user_activity_log_model');

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : uri_string();
        $query_string = isset($_SERVER['QUERY_STRING']) ? trim((string) $_SERVER['QUERY_STRING']) : '';
        $user_id = (int) $this->session->userdata('user_id');
        $user_name = (string) $this->session->userdata('name');
        $user_role = (string) $this->session->userdata('role');
        $action_type = $this->resolve_activity_type($request_method, $controller, $method);
        if ($action_type === '') {
            return;
        }

        $payload = array(
            'user_id' => $user_id > 0 ? $user_id : null,
            'user_name' => $user_name !== '' ? $user_name : null,
            'user_role' => $user_role !== '' ? $user_role : null,
            'http_method' => $request_method,
            'action' => $action_type . ' ' . $controller . '/' . $method,
            'controller' => $controller,
            'method' => $method,
            'request_uri' => substr($request_uri, 0, 255),
            'query_string' => $query_string !== '' ? substr($query_string, 0, 255) : null,
            'payload_json' => $this->build_compact_activity_payload_json($request_method),
            'ip_address' => substr((string) $this->input->ip_address(), 0, 45),
            'user_agent' => substr((string) $this->input->user_agent(), 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        );

        $this->user_activity_log_model->insert($payload);
    }

    protected function should_log_activity_compact($request_method, $controller, $method)
    {
        if ($controller === 'monitoring' && $method === 'snapshot_json') {
            return false;
        }

        if ($request_method === 'GET' && $this->input->is_ajax_request()) {
            return false;
        }

        if (in_array($request_method, array('POST', 'PUT', 'PATCH', 'DELETE'), true)) {
            return true;
        }

        if ($controller === 'auth' && $method === 'logout') {
            return true;
        }

        return false;
    }

    protected function resolve_activity_type($request_method, $controller, $method)
    {
        $request_method = strtoupper((string) $request_method);
        $controller = strtolower((string) $controller);
        $method = strtolower((string) $method);

        if ($controller === 'auth' && $method === 'process_login' && $request_method === 'POST') {
            return 'LOGIN';
        }
        if ($controller === 'auth' && $method === 'logout') {
            return 'LOGOUT';
        }

        if (preg_match('/(delete|remove|destroy|hard_delete|bulk_delete)/', $method)) {
            return 'DELETE';
        }

        if (preg_match('/(store|create|save|add|insert|sync|generate|import|register)/', $method)) {
            return 'CREATE';
        }

        if (preg_match('/(update|edit|mark|approve|reject|close|done|activate|release|isolate|bulk)/', $method)) {
            return 'UPDATE';
        }

        return '';
    }

    protected function is_user_activity_log_table_ready()
    {
        if (self::$user_activity_log_table_available !== null) {
            return self::$user_activity_log_table_available === true;
        }

        try {
            $this->load->database();
            self::$user_activity_log_table_available = $this->db->table_exists('user_activity_logs');
        } catch (Throwable $e) {
            self::$user_activity_log_table_available = false;
        }

        return self::$user_activity_log_table_available === true;
    }

    protected function build_compact_activity_payload_json($request_method)
    {
        $request_method = strtoupper((string) $request_method);
        if (!in_array($request_method, array('POST', 'PUT', 'PATCH', 'DELETE'), true)) {
            return null;
        }

        $summary = array();
        $post = $this->input->post(null, true);

        if (is_array($post) && !empty($post)) {
            $id_keys = array('id', 'user_id', 'customer_id', 'invoice_id', 'ticket_id', 'wo_id', 'service_id');
            foreach ($id_keys as $id_key) {
                if (isset($post[$id_key]) && $post[$id_key] !== '') {
                    $summary[$id_key] = is_scalar($post[$id_key]) ? (string) $post[$id_key] : '[complex]';
                    break;
                }
            }

            if (isset($post['ids'])) {
                if (is_array($post['ids'])) {
                    $summary['bulk_count'] = count($post['ids']);
                } elseif (is_string($post['ids'])) {
                    $ids = array_filter(array_map('trim', explode(',', $post['ids'])));
                    $summary['bulk_count'] = count($ids);
                }
            }

            $summary['field_count'] = count($post);
        } else {
            $raw = trim((string) $this->input->raw_input_stream);
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    if (isset($decoded['id'])) {
                        $summary['id'] = is_scalar($decoded['id']) ? (string) $decoded['id'] : '[complex]';
                    } elseif (isset($decoded['user_id'])) {
                        $summary['user_id'] = is_scalar($decoded['user_id']) ? (string) $decoded['user_id'] : '[complex]';
                    } elseif (isset($decoded['customer_id'])) {
                        $summary['customer_id'] = is_scalar($decoded['customer_id']) ? (string) $decoded['customer_id'] : '[complex]';
                    }
                    if (isset($decoded['ids']) && is_array($decoded['ids'])) {
                        $summary['bulk_count'] = count($decoded['ids']);
                    }
                    $summary['field_count'] = count($decoded);
                }
            }
        }

        if (empty($summary)) {
            return null;
        }

        $json = json_encode($summary, JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : null;
    }
}
