<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth_guard
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
    }

    /**
     * Cek apakah user sudah login
     */
    public function require_login()
    {
        if (!$this->CI->session->userdata('logged_in')) {
            redirect('auth/login');
            exit;
        }
    }

    /**
     * Cek apakah user punya role yang diizinkan
     *
     * @param array $allowed_roles ['superadmin', 'admin']
     */
    public function require_role(array $allowed_roles)
    {
        $this->require_login();

        $user_role = $this->CI->session->userdata('role');
        if (!in_array($user_role, $allowed_roles)) {
            show_error('Anda tidak memiliki akses ke halaman ini.', 403);
            exit;
        }
    }

    /**
     * Ambil data user yang sedang login
     */
    public function current_user()
    {
        return $this->CI->session->userdata('user');
    }

    /**
     * Ambil ID user yang sedang login
     */
    public function user_id()
    {
        $user = $this->current_user();
        return $user ? $user['id'] : null;
    }
}