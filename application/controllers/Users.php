<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Users extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->model('user_model');
        $this->load->library(array('form_validation', 'session'));
        $this->load->helper(array('url', 'form'));

        $this->require_role(array('superadmin', 'admin'));
    }

    public function index()
    {
        if (!$this->db->table_exists('users')) {
            $this->session->set_flashdata('error', 'Tabel users belum tersedia. Jalankan migration RBAC terlebih dahulu.');
            return $this->load->view('users/list', array('users' => array()));
        }

        $users = $this->user_model->get_all(!$this->is_superadmin());
        if (!$this->is_superadmin()) {
            $users = array_values(array_filter($users, function ($user) {
                return $this->can_manage_target_user($user);
            }));
        }

        $this->load->view('users/list', array(
            'users' => $users,
            'is_superadmin' => $this->is_superadmin(),
        ));
    }

    public function create()
    {
        return $this->render_form('create');
    }

    public function store()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->validate_form(true)) {
            return $this->render_form('create');
        }

        $username = trim((string) $this->input->post('username', true));
        if ($this->user_model->exists_username($username)) {
            $this->session->set_flashdata('error', 'Username sudah digunakan.');
            return redirect('users/create');
        }

        $password = (string) $this->input->post('password');
        $role = normalizeRole((string) $this->input->post('role', true));

        $router_scope = $this->resolve_router_scope_for_submission($role);
        if (empty($router_scope['success'])) {
            $this->session->set_flashdata('error', (string) ($router_scope['message'] ?? 'Validasi router scope gagal.'));
            return redirect('users/create');
        }

        $insert_id = $this->user_model->insert(array(
            'name' => $this->input->post('name', true),
            'username' => $username,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'role' => $role,
            'status' => $this->input->post('status', true),
            'router_scope_id' => $router_scope['router_scope_id'],
        ));

        if (!$insert_id) {
            $this->session->set_flashdata('error', 'Gagal menambahkan user.');
            return redirect('users/create');
        }
        $this->user_model->sync_router_access((int) $insert_id, (array) ($router_scope['router_ids'] ?? array()));

        $this->session->set_flashdata('success', 'User berhasil ditambahkan.');
        return redirect('users');
    }

    public function edit($id = null)
    {
        $id = (int) $id;
        $user = $this->user_model->get_by_id($id);
        if (!$user) {
            $this->session->set_flashdata('error', 'User tidak ditemukan.');
            return redirect('users');
        }

        if (!$this->can_manage_target_user($user)) {
            $this->session->set_flashdata('error', 'Akses ditolak. Admin tidak dapat mengelola user superadmin.');
            return redirect('users');
        }

        return $this->render_form('edit', $user);
    }

    public function update($id = null)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $existing = $this->user_model->get_by_id($id);
        if (!$existing) {
            $this->session->set_flashdata('error', 'User tidak ditemukan.');
            return redirect('users');
        }

        if (!$this->can_manage_target_user($existing)) {
            $this->session->set_flashdata('error', 'Akses ditolak. Admin tidak dapat mengelola user superadmin.');
            return redirect('users');
        }

        if (!$this->validate_form(false)) {
            return $this->render_form('edit', $existing);
        }

        $username = trim((string) $this->input->post('username', true));
        if ($this->user_model->exists_username($username, $id)) {
            $this->session->set_flashdata('error', 'Username sudah digunakan.');
            return redirect('users/edit/' . $id);
        }

        $payload = array(
            'name' => $this->input->post('name', true),
            'username' => $username,
            'role' => normalizeRole((string) $this->input->post('role', true)),
            'status' => $this->input->post('status', true),
        );

        $router_scope = $this->resolve_router_scope_for_submission($payload['role']);
        if (empty($router_scope['success'])) {
            $this->session->set_flashdata('error', (string) ($router_scope['message'] ?? 'Validasi router scope gagal.'));
            return redirect('users/edit/' . $id);
        }
        $payload['router_scope_id'] = $router_scope['router_scope_id'];

        $new_password = trim((string) $this->input->post('password'));
        if ($new_password !== '') {
            $payload['password'] = password_hash($new_password, PASSWORD_BCRYPT);
        }

        $ok = $this->user_model->update($id, $payload);
        if (!$ok) {
            $this->session->set_flashdata('error', 'Gagal update user.');
            return redirect('users/edit/' . $id);
        }
        $this->user_model->sync_router_access($id, (array) ($router_scope['router_ids'] ?? array()));

        $this->session->set_flashdata('success', 'User berhasil diperbarui.');
        return redirect('users');
    }

    public function delete($id = null)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        if ($id <= 0) {
            $this->session->set_flashdata('error', 'ID user tidak valid.');
            return redirect('users');
        }

        $current_user_id = (int) $this->session->userdata('user_id');
        if ($id === $current_user_id) {
            $this->session->set_flashdata('error', 'User yang sedang login tidak dapat dihapus.');
            return redirect('users');
        }

        $target = $this->user_model->get_by_id($id);
        if (!$target) {
            $this->session->set_flashdata('error', 'User tidak ditemukan.');
            return redirect('users');
        }

        if (!$this->can_manage_target_user($target)) {
            $this->session->set_flashdata('error', 'Akses ditolak. Admin tidak dapat menghapus user superadmin.');
            return redirect('users');
        }

        if ($this->is_superadmin()) {
            $ok = $this->user_model->delete($id);
            $message = 'User berhasil dihapus permanen.';
        } else {
            $ok = $this->user_model->soft_delete($id);
            $message = 'User berhasil dihapus (soft delete / status inactive).';
        }

        if (!$ok) {
            $this->session->set_flashdata('error', 'Gagal menghapus user.');
            return redirect('users');
        }

        $this->session->set_flashdata('success', $message);
        return redirect('users');
    }

    private function render_form($mode, $user = null)
    {
        $is_edit = $mode === 'edit';
        $action = $is_edit ? 'users/update/' . (int) $user->id : 'users/store';
        $router_form = $this->build_router_form_config($user);

        return $this->load->view('users/form', array(
            'mode' => $mode,
            'user' => $user,
            'action' => $action,
            'allowed_roles' => $this->allowed_assign_roles(),
            'is_superadmin' => $this->is_superadmin(),
            'router_form' => $router_form,
        ));
    }

    private function validate_form($is_create)
    {
        $this->form_validation->set_rules('name', 'Name', 'trim|required|min_length[3]|max_length[100]');
        $this->form_validation->set_rules('username', 'Username', 'trim|required|min_length[3]|max_length[50]');
        $this->form_validation->set_rules('role', 'Role', 'trim|required|in_list[' . implode(',', $this->allowed_assign_roles()) . ']');
        $this->form_validation->set_rules('status', 'Status', 'trim|required|in_list[active,inactive]');

        if ($is_create) {
            $this->form_validation->set_rules('password', 'Password', 'trim|required|min_length[6]');
        } else {
            $this->form_validation->set_rules('password', 'Password', 'trim|min_length[6]');
        }

        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return false;
        }

        return true;
    }

    private function allowed_assign_roles()
    {
        $role = strtolower((string) $this->session->userdata('role'));
        if ($role === 'superadmin') {
            return array('superadmin', 'admin', 'teknisi');
        }

        return array('admin', 'teknisi');
    }

    private function can_manage_target_user($user)
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) $this->session->userdata('role'));
        if ($role === 'superadmin') {
            return true;
        }

        if (strtolower((string) $user->role) === 'superadmin') {
            return false;
        }

        $actor_ids = $this->actor_router_access_ids();
        if (empty($actor_ids) || empty($user->id)) {
            return false;
        }
        $actor_map = array_fill_keys(array_map('intval', $actor_ids), true);
        $target_ids = $this->user_model->get_router_access_ids((int) $user->id, true);
        if (empty($target_ids)) {
            return false;
        }

        foreach ($target_ids as $router_id) {
            if (!isset($actor_map[(int) $router_id])) {
                return false;
            }
        }

        return true;
    }

    private function build_router_form_config($user = null)
    {
        $has_router_scope_column = $this->user_model->has_router_scope_column();
        $current_role = normalizeRole((string) $this->session->userdata('role'));
        $is_superadmin = $current_role === 'superadmin';

        if ($is_superadmin) {
            $router_options = $this->user_model->get_active_routers(null);
        } else {
            $router_options = $this->user_model->get_active_routers($this->actor_router_access_ids());
        }
        $router_count = count($router_options);

        $selected_router_scope_ids = $this->posted_router_scope_ids();
        if (empty($selected_router_scope_ids) && $user && !empty($user->id)) {
            $selected_router_scope_ids = $this->user_model->get_router_access_ids((int) $user->id, true);
        }

        if ($router_count === 1 && empty($selected_router_scope_ids)) {
            $selected_router_scope_ids = array((int) $router_options[0]['id']);
        }

        $selected_router_scope_id = !empty($selected_router_scope_ids) ? (int) $selected_router_scope_ids[0] : null;

        return array(
            'enabled' => $has_router_scope_column,
            'actor_role' => $current_role,
            'can_select_all' => $is_superadmin,
            'router_options' => $router_options,
            'router_count' => $router_count,
            'single_router_auto_id' => $router_count === 1 ? (int) $router_options[0]['id'] : null,
            'selected_router_scope_id' => $selected_router_scope_id,
            'selected_router_scope_ids' => $selected_router_scope_ids,
        );
    }

    private function resolve_router_scope_for_submission($target_role)
    {
        $target_role = normalizeRole((string) $target_role);
        if (!$this->user_model->has_router_scope_column()) {
            return array(
                'success' => true,
                'router_scope_id' => null,
                'router_ids' => array(),
            );
        }

        if ($target_role === 'superadmin') {
            return array(
                'success' => true,
                'router_scope_id' => null,
                'router_ids' => array(),
            );
        }

        $actor_role = normalizeRole((string) $this->session->userdata('role'));

        if ($actor_role === 'superadmin') {
            $router_options = $this->user_model->get_active_routers(null);
        } else {
            $router_options = $this->user_model->get_active_routers($this->actor_router_access_ids());
        }
        $router_count = count($router_options);

        if ($router_count <= 0) {
            return array(
                'success' => false,
                'message' => 'Tidak ada router aktif yang dapat dipakai untuk router scope user.',
            );
        }

        if ($router_count === 1) {
            $router_id = (int) $router_options[0]['id'];
            return array(
                'success' => true,
                'router_scope_id' => $router_id,
                'router_ids' => array($router_id),
            );
        }

        $posted_router_ids = $this->posted_router_scope_ids();
        if (empty($posted_router_ids)) {
            return array(
                'success' => false,
                'message' => 'Minimal 1 router wajib dipilih.',
            );
        }

        $allowed_ids = array();
        foreach ($router_options as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $allowed_ids[$id] = true;
            }
        }

        $router_ids = array();
        foreach ($posted_router_ids as $router_id) {
            $router_id = (int) $router_id;
            if ($router_id <= 0 || !isset($allowed_ids[$router_id]) || !$this->user_model->router_exists($router_id, true)) {
                return array(
                    'success' => false,
                    'message' => 'Ada router yang dipilih tidak valid, tidak aktif, atau berada di luar scope Anda.',
                );
            }
            $router_ids[$router_id] = $router_id;
        }
        $router_ids = array_values($router_ids);

        return array(
            'success' => true,
            'router_scope_id' => !empty($router_ids) ? (int) $router_ids[0] : null,
            'router_ids' => $router_ids,
        );
    }

    private function posted_router_scope_ids()
    {
        $posted = $this->input->post('router_scope_ids', true);
        if ($posted === null) {
            $posted = array();
        }
        if (!is_array($posted)) {
            $posted = array($posted);
        }

        $legacy = $this->input->post('router_scope_id', true);
        if ($legacy !== null && trim((string) $legacy) !== '') {
            $posted[] = $legacy;
        }

        $ids = array();
        foreach ($posted as $router_id) {
            $router_id = (int) $router_id;
            if ($router_id > 0) {
                $ids[$router_id] = $router_id;
            }
        }

        return array_values($ids);
    }

    private function actor_router_access_ids()
    {
        $actor_user_id = (int) $this->session->userdata('user_id');
        $ids = $this->user_model->get_router_access_ids($actor_user_id, true);
        if (empty($ids)) {
            $scope_id = (int) $this->session->userdata('router_scope_id');
            if ($scope_id > 0) {
                $ids[] = $scope_id;
            }
        }
        return $ids;
    }
}
