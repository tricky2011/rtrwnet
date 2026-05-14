<?php
/**
 * application/libraries/Pppoe_manager.php
 *
 * Mengelola PPP Secret di MikroTik RouterOS.
 * Semua operasi CRUD PPPoE pelanggan ada di sini.
 *
 * PENTING:
 * - Remote IP TIDAK di-set manual. Profile PPP yang menentukan pool.
 * - Password format ddmmyy dari install_date.
 * - Comment berisi customer_code untuk identifikasi.
 * - PPP Secret TIDAK pernah di-disable untuk isolir.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Pppoe_manager
{
    private $CI;
    private $service;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->library('mikrotik_api');
        $this->CI->config->load('mikrotik', TRUE);

        $mk = $this->CI->config->item('mikrotik');
        $this->service = $mk['pppoe_service'];
    }

    // ═══════════════════════════════════════════════════════
    //  1. CREATE PPP SECRET (Pelanggan Baru)
    // ═══════════════════════════════════════════════════════

    /**
     * Buat PPP Secret baru di MikroTik
     *
     * Dipanggil saat:
     * - Pelanggan baru diinput DAN WO pemasangan selesai
     * - Atau langsung saat input jika tidak pakai WO flow
     *
     * @param  string $username       PPPoE username (contoh: 'cust001')
     * @param  string $install_date   Format 'Y-m-d' (contoh: '2026-01-15')
     * @param  string $profile        Nama profile MikroTik (contoh: 'pppoe-10m')
     * @param  string $customer_code  Kode pelanggan (contoh: 'CUST-0001')
     * @param  string $full_name      Nama pelanggan untuk comment
     * @return array  Response dari MikroTik
     * @throws Exception Jika gagal create
     *
     * Contoh:
     *   $this->pppoe_manager->create_secret(
     *       'cust001', '2026-01-15', 'pppoe-10m', 'CUST-0001', 'Budi Santoso'
     *   );
     *
     * Yang terjadi di MikroTik:
     *   /ppp secret add name=cust001 password=150126 service=pppoe
     *       profile=pppoe-10m comment="CUST-0001 | Budi Santoso"
     *
     * Remote IP:
     *   TIDAK di-set di sini. Profile 'pppoe-10m' sudah dikonfigurasi
     *   dengan remote-address=pool-pppoe. MikroTik auto-assign IP dari pool
     *   saat pelanggan connect.
     */
    public function create_secret($username, $install_date, $profile,
                                   $customer_code, $full_name)
    {
        // Validasi input
        $this->validate_username($username);
        $this->validate_profile($profile);

        // Generate password dari install_date (format: ddmmyy)
        $password = $this->generate_password($install_date);

        // Cek apakah username sudah ada
        $existing = $this->find_secret($username);
        if ($existing !== null) {
            throw new Exception(
                "PPP Secret '{$username}' sudah ada di MikroTik. " .
                "Tidak bisa duplikat."
            );
        }

        // Buat comment format: "CUST-0001 | Budi Santoso"
        $comment = "{$customer_code} | {$full_name}";

        // Kirim command ke MikroTik
        $response = $this->CI->mikrotik_api->command('/ppp/secret/add', [
            'name'     => $username,
            'password' => $password,
            'service'  => $this->service,
            'profile'  => $profile,
            'comment'  => $comment,
            // TIDAK ada 'remote-address' — auto assign dari profile pool
        ]);

        $this->log_action('CREATE', $username, [
            'profile'  => $profile,
            'password' => '(ddmmyy)',
            'comment'  => $comment,
        ]);

        return $response;
    }

    // ═══════════════════════════════════════════════════════
    //  2. UPDATE PPP PROFILE (Upgrade/Downgrade Paket)
    // ═══════════════════════════════════════════════════════

    /**
     * Update profile PPP Secret — untuk ganti paket pelanggan
     *
     * Dipanggil saat:
     * - Admin mengubah paket pelanggan di halaman edit
     *
     * @param  string $username     PPPoE username
     * @param  string $new_profile  Profile baru (contoh: 'pppoe-20m')
     * @return array  Response MikroTik
     * @throws Exception Jika secret tidak ditemukan
     *
     * Contoh:
     *   $this->pppoe_manager->update_profile('cust001', 'pppoe-20m');
     *
     * Yang terjadi di MikroTik:
     *   /ppp secret set [find name=cust001] profile=pppoe-20m
     *
     * CATATAN PENTING:
     *   Setelah update profile, perubahan bandwidth BARU berlaku
     *   setelah pelanggan reconnect. Untuk apply langsung:
     *   → panggil disconnect_active() setelah update
     *   → pelanggan auto reconnect via PPPoE
     *   → connect dengan profile baru
     */
    public function update_profile($username, $new_profile)
    {
        $this->validate_profile($new_profile);

        // Cari .id PPP Secret
        $entry = $this->find_secret($username);
        if ($entry === null) {
            throw new Exception(
                "PPP Secret '{$username}' tidak ditemukan di MikroTik."
            );
        }

        $old_profile = $entry['profile'] ?? 'unknown';

        // Update profile
        $response = $this->CI->mikrotik_api->command('/ppp/secret/set', [
            '.id'     => $entry['.id'],
            'profile' => $new_profile,
        ]);

        // Disconnect active session agar reconnect dengan profile baru
        $this->disconnect_active($username);

        $this->log_action('UPDATE_PROFILE', $username, [
            'old_profile' => $old_profile,
            'new_profile' => $new_profile,
        ]);

        return $response;
    }

    /**
     * Update password PPP Secret
     *
     * Dipanggil saat:
     * - Admin reset password pelanggan
     */
    public function update_password($username, $new_password)
    {
        $entry = $this->find_secret($username);
        if ($entry === null) {
            throw new Exception("PPP Secret '{$username}' tidak ditemukan.");
        }

        $response = $this->CI->mikrotik_api->command('/ppp/secret/set', [
            '.id'      => $entry['.id'],
            'password' => $new_password,
        ]);

        // Disconnect agar pelanggan login ulang dengan password baru
        $this->disconnect_active($username);

        $this->log_action('UPDATE_PASSWORD', $username, []);

        return $response;
    }

    // ═══════════════════════════════════════════════════════
    //  3. REMOVE PPP SECRET (Pelanggan Berhenti)
    // ═══════════════════════════════════════════════════════

    /**
     * Hapus PPP Secret dari MikroTik
     *
     * Dipanggil saat:
     * - Pelanggan di-terminate (berhenti langganan)
     *
     * @param  string $username  PPPoE username
     * @return bool   true jika berhasil dihapus atau sudah tidak ada
     *
     * Urutan operasi:
     *   1. Disconnect sesi aktif (jika ada)
     *   2. Hapus dari address-list ISOLIR (jika ada)
     *   3. Hapus PPP Secret
     *
     * CATATAN: Fungsi ini IDEMPOTENT. Aman dipanggil berkali-kali.
     * Jika secret sudah tidak ada, return true tanpa error.
     */
    public function remove_secret($username)
    {
        // Step 1: Disconnect active session
        $this->disconnect_active($username);

        // Step 2: Bersihkan dari address-list isolir (jika ada)
        $this->CI->load->library('isolir_engine');
        $this->CI->isolir_engine->remove_from_list_by_username($username);

        // Step 3: Cari dan hapus PPP Secret
        $entry = $this->find_secret($username);

        if ($entry === null) {
            // Sudah tidak ada — OK, idempotent
            $this->log_action('REMOVE', $username, ['note' => 'already absent']);
            return true;
        }

        $this->CI->mikrotik_api->command('/ppp/secret/remove', [
            '.id' => $entry['.id'],
        ]);

        $this->log_action('REMOVE', $username, ['removed_id' => $entry['.id']]);

        return true;
    }

    // ═══════════════════════════════════════════════════════
    //  HELPER: FIND, DISCONNECT, VALIDATE
    // ═══════════════════════════════════════════════════════

    /**
     * Cari PPP Secret berdasarkan username
     *
     * @param  string     $username
     * @return array|null Entry data atau null jika tidak ada
     */
    public function find_secret($username)
    {
        $result = $this->CI->mikrotik_api->command('/ppp/secret/print', [
            '?name' => $username,
        ]);

        // RouterOS mengembalikan array of entries
        if (!empty($result) && isset($result[0]) && is_array($result[0])) {
            return $result[0];
        }

        return null;
    }

    /**
     * Disconnect sesi PPPoE aktif
     *
     * Setelah disconnect, pelanggan akan auto-reconnect via PPPoE client
     * di router/ONU mereka. Ini transparent — pelanggan hanya mengalami
     * putus sesaat (~2-5 detik).
     *
     * @param  string $username  PPPoE username
     * @return bool   true jika berhasil disconnect atau tidak ada sesi aktif
     */
    public function disconnect_active($username)
    {
        $active = $this->CI->mikrotik_api->command_safe('/ppp/active/print', [
            '?name' => $username,
        ]);

        if ($active['success'] && !empty($active['data']) &&
            isset($active['data'][0]['.id'])) {

            $this->CI->mikrotik_api->command_safe('/ppp/active/remove', [
                '.id' => $active['data'][0]['.id'],
            ]);

            $this->log_action('DISCONNECT', $username, [
                'session_id' => $active['data'][0]['.id'],
            ]);

            return true;
        }

        return false; // tidak ada sesi aktif
    }

    /**
     * Ambil IP remote pelanggan dari sesi PPP aktif
     *
     * @param  string      $username
     * @return string|null IP address atau null jika offline
     */
    public function get_remote_ip($username)
    {
        $result = $this->CI->mikrotik_api->command_safe('/ppp/active/print', [
            '?name' => $username,
        ]);

        if ($result['success'] && !empty($result['data']) &&
            isset($result['data'][0]['address'])) {
            return $result['data'][0]['address'];
        }

        return null;
    }

    /**
     * Cek apakah pelanggan sedang online
     */
    public function is_online($username)
    {
        return $this->get_remote_ip($username) !== null;
    }

    /**
     * Generate password PPPoE dari install_date
     *
     * Format: ddmmyy
     * Contoh: '2026-01-15' → '150126'
     *         '2025-12-03' → '031225'
     *
     * @param  string $install_date  Format 'Y-m-d'
     * @return string Password 6 digit
     */
    public function generate_password($install_date)
    {
        $timestamp = strtotime($install_date);

        if ($timestamp === false) {
            throw new Exception("Format install_date tidak valid: {$install_date}");
        }

        return date('dmy', $timestamp);
    }

    /**
     * Validasi username PPPoE
     */
    private function validate_username($username)
    {
        if (empty($username)) {
            throw new Exception("PPPoE username tidak boleh kosong");
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
            throw new Exception(
                "PPPoE username hanya boleh alfanumerik, titik, underscore, dash"
            );
        }

        if (strlen($username) > 50) {
            throw new Exception("PPPoE username maksimal 50 karakter");
        }
    }

    /**
     * Validasi profile name
     */
    private function validate_profile($profile)
    {
        $allowed = ['pppoe-10m', 'pppoe-20m', 'pppoe-30m', 'pppoe-50m'];

        if (!in_array($profile, $allowed)) {
            throw new Exception(
                "Profile '{$profile}' tidak valid. Allowed: " .
                implode(', ', $allowed)
            );
        }
    }

    /**
     * Log aksi PPPoE
     */
    private function log_action($action, $username, $details)
    {
        $log_msg = "PPPOE {$action} user={$username} " .
                   json_encode($details);
        custom_log('api_mikrotik.log', $log_msg);
    }
}