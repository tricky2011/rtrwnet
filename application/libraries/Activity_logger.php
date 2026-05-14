<?php
// application/libraries/Activity_logger.php

class Activity_logger
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model('customer_log_model');
    }

    /**
     * Catat setiap aksi admin ke tabel customer_logs
     *
     * @param string $action     'create_customer','update_package','payment',...
     * @param int    $customer_id
     * @param array  $new_data   Data baru
     * @param array  $old_data   Data lama (untuk update)
     */
    public function log($action, $customer_id, $new_data = [], $old_data = [])
    {
        $admin = $this->CI->session->userdata('user');

        $this->CI->customer_log_model->insert([
            'customer_id'  => $customer_id,
            'action'       => $action,
            'description'  => $this->build_description($action, $new_data),
            'old_value'    => !empty($old_data) ? json_encode($old_data) : null,
            'new_value'    => !empty($new_data) ? json_encode($new_data) : null,
            'performed_by' => $admin ? $admin['id'] : null,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    private function build_description($action, $data)
    {
        $descriptions = [
            'create_customer' => 'Pelanggan baru didaftarkan',
            'update_package'  => 'Paket diubah ke ' . ($data['package_name'] ?? '?'),
            'payment'         => 'Pembayaran Rp ' . number_format($data['amount'] ?? 0),
            'isolate'         => 'Pelanggan diisolir (overdue)',
            'restore'         => 'Isolir dicabut (lunas)',
            'terminate'       => 'Pelanggan dihentikan',
        ];

        return $descriptions[$action] ?? $action;
    }
}