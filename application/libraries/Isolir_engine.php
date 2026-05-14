<?php
/**
 * application/libraries/Isolir_engine.php
 *
 * Engine untuk isolir dan restore akses pelanggan.
 *
 * METODE ISOLIR: ADDRESS-LIST + NAT REDIRECT
 *
 * Cara kerja:
 * 1. IP pelanggan ditambahkan ke address-list "ISOLIR"
 * 2. Firewall NAT rule redirect HTTP ke halaman isolir
 * 3. Firewall filter DROP semua traffic selain HTTP redirect
 * 4. PPP Secret TETAP AKTIF (tidak di-disable, tidak di-disconnect)
 *
 * Kenapa tidak disable PPP Secret?
 * - Jika PPP di-disable, pelanggan tidak bisa konek sama sekali
 * - Mereka tidak akan melihat halaman isolir
 * - Mereka tidak tahu kenapa internet mati
 * - Dengan address-list redirect, mereka buka browser dan
 *   langsung lihat info tagihan + cara bayar
 *
 * Kenapa tidak disconnect paksa?
 * - Jika disconnect tapi secret masih enabled,
 *   pelanggan langsung reconnect otomatis
 * - Tidak ada gunanya disconnect tanpa disable
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Isolir_engine
{
    private $CI;
    private $address_list;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->library(['mikrotik_api', 'pppoe_manager']);
        $this->CI->load->model(['customer_model', 'invoice_model', 'isolir_model']);
        $this->CI->config->load('mikrotik', TRUE);

        $mk = $this->CI->config->item('mikrotik');
        $this->address_list = $mk['isolir_address_list'];
    }

    // ═══════════════════════════════════════════════════════
    //  ADD TO ISOLIR LIST
    // ═══════════════════════════════════════════════════════

    /**
     * Tambahkan pelanggan ke address-list ISOLIR
     *
     * Langkah:
     * 1. Ambil IP remote dari PPP active session
     * 2. Jika online → tambahkan IP ke address-list
     * 3. Jika offline → tambahkan berbasis comment saja.
     *    Saat pelanggan connect nanti, script on-up di profile
     *    akan mengecek apakah username ada di daftar isolir.
     *    ATAU: cron periodik menambahkan IP baru yang terdeteksi.
     *
     * @param  int       $customer_id  ID pelanggan dari database
     * @param  int|null  $invoice_id   Invoice terkait (opsional)
     * @param  int|null  $performed_by User ID admin (null = cron)
     * @return array     ['success' => bool, 'method' => string]
     */
    public function add_to_isolir_list($customer_id, $invoice_id = null,
                                        $performed_by = null)
    {
        $customer = $this->CI->customer_model->get($customer_id);

        if (!$customer) {
            throw new Exception("Customer #{$customer_id} tidak ditemukan");
        }

        // Jika sudah isolated, skip
        if ($customer->status === 'isolated') {
            return ['success' => true, 'method' => 'already_isolated'];
        }

        // Ambil IP remote dari sesi aktif
        $remote_ip = $this->CI->pppoe_manager
            ->get_remote_ip($customer->pppoe_username);

        $method = 'offline';

        if ($remote_ip) {
            // Pelanggan ONLINE — tambahkan IP ke address-list
            $method = 'ip_added';

            // Cek apakah sudah ada di address-list
            $existing = $this->find_in_list($customer->customer_code);

            if (empty($existing)) {
                $this->CI->mikrotik_api->command(
                    '/ip/firewall/address-list/add',
                    [
                        'list'    => $this->address_list,
                        'address' => $remote_ip,
                        'comment' => $customer->customer_code,
                        'timeout' => '0s',  // permanent, tidak auto-expire
                    ]
                );
            }
        }

        // Update status di database
        $this->CI->customer_model->update($customer_id, [
            'status'     => 'isolated',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Update invoice status
        $this->CI->invoice_model->mark_overdue($customer_id);

        // Catat log isolir
        $this->CI->isolir_model->insert([
            'customer_id'  => $customer_id,
            'action'       => 'isolate',
            'reason'       => $performed_by ? 'manual_isolir' : 'overdue_auto',
            'invoice_id'   => $invoice_id,
            'performed_by' => $performed_by,
            'performed_at' => date('Y-m-d H:i:s'),
        ]);

        custom_log('api_mikrotik.log',
            "ISOLIR ADD {$customer->customer_code} " .
            "ip={$remote_ip} method={$method}");

        return ['success' => true, 'method' => $method, 'ip' => $remote_ip];
    }

    // ═══════════════════════════════════════════════════════
    //  REMOVE FROM ISOLIR LIST
    // ═══════════════════════════════════════════════════════

    /**
     * Hapus pelanggan dari address-list ISOLIR (restore akses)
     *
     * Dipanggil saat:
     * - Semua invoice pelanggan sudah LUNAS
     * - Admin manual restore dari UI
     *
     * PENTING: Cek SEMUA invoice lunas sebelum restore.
     * Jangan restore jika masih ada tunggakan.
     *
     * @param  int       $customer_id   ID pelanggan
     * @param  int|null  $performed_by  Admin ID (null = auto)
     * @param  bool      $force         true = bypass cek invoice
     * @return array
     */
    public function remove_from_isolir_list($customer_id,
                                             $performed_by = null,
                                             $force = false)
    {
        $customer = $this->CI->customer_model->get($customer_id);

        if (!$customer) {
            throw new Exception("Customer #{$customer_id} tidak ditemukan");
        }

        if ($customer->status !== 'isolated') {
            return ['success' => true, 'method' => 'not_isolated'];
        }

        // Cek apakah semua invoice lunas
        if (!$force) {
            $unpaid = $this->CI->invoice_model->count_unpaid($customer_id);

            if ($unpaid > 0) {
                return [
                    'success' => false,
                    'method'  => 'still_has_unpaid',
                    'unpaid'  => $unpaid,
                    'message' => "Masih ada {$unpaid} invoice belum lunas",
                ];
            }
        }

        // Hapus SEMUA entry customer ini dari address-list
        $removed_count = $this->remove_entries_by_comment(
            $customer->customer_code
        );

        // Update status pelanggan
        $this->CI->customer_model->update($customer_id, [
            'status'     => 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Catat log restore
        $this->CI->isolir_model->insert([
            'customer_id'  => $customer_id,
            'action'       => 'restore',
            'reason'       => $performed_by ? 'manual_restore' : 'payment_received',
            'performed_by' => $performed_by,
            'performed_at' => date('Y-m-d H:i:s'),
        ]);

        custom_log('api_mikrotik.log',
            "ISOLIR REMOVE {$customer->customer_code} " .
            "entries_removed={$removed_count}");

        return [
            'success' => true,
            'method'  => 'restored',
            'entries_removed' => $removed_count,
        ];
    }

    /**
     * Hapus dari address-list berdasarkan PPPoE username
     * Dipakai oleh Pppoe_manager saat remove_secret
     */
    public function remove_from_list_by_username($username)
    {
        // Cari customer_code dari username
        $this->CI->load->model('customer_model');
        $customer = $this->CI->customer_model->get_by_pppoe($username);

        if ($customer) {
            $this->remove_entries_by_comment($customer->customer_code);
        }
    }

    // ═══════════════════════════════════════════════════════
    //  SYNC ADDRESS-LIST (CRON: Handle IP changes)
    // ═══════════════════════════════════════════════════════

    /**
     * Sinkronisasi address-list dengan pelanggan yang status=isolated
     *
     * Masalah: IP remote PPPoE bisa berubah jika pelanggan reconnect
     * (karena auto-assign dari pool). Entry lama di address-list
     * jadi tidak valid.
     *
     * Solusi: Cron ini dijalankan setiap 15 menit untuk:
     * 1. Hapus entry lama yang IP-nya sudah tidak aktif
     * 2. Tambahkan entry baru untuk pelanggan isolir yang sedang online
     *
     * Dipanggil dari: cron/isolir_cron sync_list
     */
    public function sync_address_list()
    {
        $stats = ['checked' => 0, 'added' => 0, 'removed' => 0, 'errors' => 0];

        // Ambil semua pelanggan berstatus isolated
        $isolated_customers = $this->CI->customer_model
            ->get_by_status('isolated');

        foreach ($isolated_customers as $cust) {
            $stats['checked']++;

            try {
                // Ambil IP aktif saat ini
                $current_ip = $this->CI->pppoe_manager
                    ->get_remote_ip($cust->pppoe_username);

                // Hapus entry lama
                $this->remove_entries_by_comment($cust->customer_code);

                // Jika online, tambahkan IP baru
                if ($current_ip) {
                    $this->CI->mikrotik_api->command(
                        '/ip/firewall/address-list/add',
                        [
                            'list'    => $this->address_list,
                            'address' => $current_ip,
                            'comment' => $cust->customer_code,
                            'timeout' => '0s',
                        ]
                    );
                    $stats['added']++;
                }
            } catch (Exception $e) {
                $stats['errors']++;
                custom_log('api_mikrotik.log',
                    "ISOLIR SYNC ERROR {$cust->customer_code}: " .
                    $e->getMessage());
            }
        }

        custom_log('api_mikrotik.log',
            "ISOLIR SYNC complete: " . json_encode($stats));

        return $stats;
    }

    // ═══════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════

    /**
     * Cari entry di address-list berdasarkan comment (customer_code)
     */
    private function find_in_list($customer_code)
    {
        $result = $this->CI->mikrotik_api->command_safe(
            '/ip/firewall/address-list/print',
            [
                '?list'    => $this->address_list,
                '?comment' => $customer_code,
            ]
        );

        return $result['success'] ? $result['data'] : [];
    }

    /**
     * Hapus semua entry di address-list berdasarkan comment
     *
     * @return int Jumlah entry yang dihapus
     */
    private function remove_entries_by_comment($customer_code)
    {
        $entries = $this->find_in_list($customer_code);
        $removed = 0;

        foreach ($entries as $entry) {
            if (isset($entry['.id'])) {
                $result = $this->CI->mikrotik_api->command_safe(
                    '/ip/firewall/address-list/remove',
                    ['.id' => $entry['.id']]
                );

                if ($result['success']) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}