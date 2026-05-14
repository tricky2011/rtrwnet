<?php
/**
 * application/libraries/Provisioning_engine.php
 *
 * Orkestrator utama proses provisioning pelanggan baru.
 * Mengoordinasikan semua langkah: simpan DB, buat WO, Telegram.
 *
 * PRINSIP:
 * - Database DULUAN, API KEMUDIAN (fail-safe)
 * - DB operations dalam 1 transaction
 * - API calls post-commit (boleh gagal, bisa retry)
 * - Setiap langkah di-log
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Provisioning_engine
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model([
            'customer_model', 'package_model', 'setting_model',
        ]);
        $this->CI->load->library([
            'wo_manager', 'telegram_notifier',
            'code_generator', 'activity_logger',
        ]);
        $this->CI->load->helper('billing');
    }

    /**
     * Registrasi pelanggan baru — FULL FLOW
     *
     * @param  array  $input    Data dari form
     * @param  object $package  Package object
     * @param  int    $admin_id Admin yang input
     * @return array  Hasil registrasi
     * @throws Exception Jika DB gagal
     */
    public function register_new_customer($input, $package, $admin_id)
    {
        $install_date = $input['install_date'];
        $pppoe_pass   = generate_pppoe_password($install_date);
        $billing_date = calculate_billing_date($install_date);
        $customer_code = $this->CI->code_generator->next_customer();
        $now = date('Y-m-d H:i:s');

        // ═══ DATABASE TRANSACTION ═══
        $this->CI->db->trans_begin();

        try {
            // ── STEP 1: Insert Customer ──
            $customer_data = [
                'customer_code'   => $customer_code,
                'full_name'       => $input['full_name'],
                'phone'           => $input['phone'],
                'address'         => $input['address'],
                'area_id'         => $input['area_id'],
                'package_id'      => $package->id,
                'pppoe_username'  => $input['pppoe_username'],
                'pppoe_password'  => $pppoe_pass,
                'install_date'    => $install_date,
                'billing_date'    => $billing_date,
                'odp_info'        => $input['odp_info'] ?? null,
                'vlan_info'       => $input['vlan_info'] ?? null,
                'onu_sn'          => $input['onu_sn'] ?? null,
                'status'          => 'waiting_install',
                'mikrotik_synced' => 0,
                'notes'           => $input['notes'] ?? null,
                'registered_by'   => $admin_id,
                'created_at'      => $now,
            ];

            $customer_id = $this->CI->customer_model->insert($customer_data);

            // ── STEP 2: Create Work Order ──
            $wo_data = $this->CI->wo_manager->create_installation_wo(
                $customer_id,
                [
                    'customer_code'  => $customer_code,
                    'full_name'      => $input['full_name'],
                    'address'        => $input['address'],
                    'phone'          => $input['phone'],
                    'package_name'   => $package->package_name,
                    'bandwidth_mbps' => $package->bandwidth_mbps,
                    'pppoe_username' => $input['pppoe_username'],
                    'pppoe_password' => $pppoe_pass,
                    'odp_info'       => $input['odp_info'] ?? '',
                    'vlan_info'      => $input['vlan_info'] ?? '',
                    'install_date'   => $install_date,
                    'scheduled_time' => $input['scheduled_time'] ?? null,
                    'notes'          => $input['notes'] ?? '',
                ],
                $input['assigned_to'] ?? null,
                $admin_id
            );

            // ── STEP 3: Activity Log ──
            $this->CI->activity_logger->log(
                'register_customer',
                $customer_id,
                array_merge($customer_data, ['wo_number' => $wo_data['wo_number']])
            );

            // ── COMMIT ──
            if ($this->CI->db->trans_status() === FALSE) {
                throw new Exception('Database transaction failed');
            }
            $this->CI->db->trans_commit();

        } catch (Exception $e) {
            $this->CI->db->trans_rollback();
            custom_log('provisioning.log',
                "REGISTER FAILED {$customer_code}: " . $e->getMessage());
            throw $e;
        }

        // ═══ POST-COMMIT: TELEGRAM (boleh gagal) ═══
        try {
            $this->CI->telegram_notifier->send_new_wo(
                $wo_data['wo_number'],
                [
                    'customer_code'  => $customer_code,
                    'full_name'      => $input['full_name'],
                    'address'        => $input['address'],
                    'phone'          => $input['phone'],
                    'package_name'   => $package->package_name,
                    'bandwidth_mbps' => $package->bandwidth_mbps,
                    'price'          => $package->price,
                    'pppoe_username' => $input['pppoe_username'],
                    'pppoe_password' => $pppoe_pass,
                    'odp_info'       => $input['odp_info'] ?? '-',
                    'vlan_info'      => $input['vlan_info'] ?? '-',
                    'install_date'   => $install_date,
                    'scheduled_time' => $input['scheduled_time'] ?? 'Fleksibel',
                    'notes'          => $input['notes'] ?? '-',
                ],
                $input['assigned_to'] ?? null
            );
        } catch (Exception $e) {
            // Telegram gagal ≠ registrasi gagal
            custom_log('provisioning.log',
                "TELEGRAM FAILED for {$customer_code}: " . $e->getMessage());
        }

        custom_log('provisioning.log',
            "REGISTER OK {$customer_code} WO={$wo_data['wo_number']}");

        return [
            'customer_id'   => $customer_id,
            'customer_code' => $customer_code,
            'wo_id'         => $wo_data['wo_id'],
            'wo_number'     => $wo_data['wo_number'],
        ];
    }

    /**
     * Aktivasi pelanggan setelah WO selesai
     *
     * Dipanggil dari: WO_manager→complete_wo() → saat type=installation
     *
     * @param  int $customer_id
     * @param  int $wo_id
     * @param  int $teknisi_id
     * @return array Hasil aktivasi
     */
    public function activate_customer($customer_id, $wo_id, $teknisi_id)
    {
        $customer = $this->CI->customer_model->get_with_package($customer_id);

        if (!$customer) {
            throw new Exception("Customer #{$customer_id} tidak ditemukan");
        }

        if ($customer->status !== 'waiting_install') {
            throw new Exception(
                "Customer {$customer->customer_code} status={$customer->status}, " .
                "expected=waiting_install"
            );
        }

        $now = date('Y-m-d H:i:s');

        // ── DB UPDATE ──
        $this->CI->db->trans_begin();

        try {
            $this->CI->customer_model->update($customer_id, [
                'status'     => 'active',
                'updated_at' => $now,
            ]);

            $this->CI->activity_logger->log(
                'activate_customer',
                $customer_id,
                [
                    'wo_id'        => $wo_id,
                    'activated_by' => $teknisi_id,
                    'old_status'   => 'waiting_install',
                    'new_status'   => 'active',
                ]
            );

            if ($this->CI->db->trans_status() === FALSE) {
                throw new Exception('DB transaction failed on activation');
            }
            $this->CI->db->trans_commit();

        } catch (Exception $e) {
            $this->CI->db->trans_rollback();
            throw $e;
        }

        // ── POST-COMMIT: CREATE PPP SECRET ──
        $mikrotik_ok = false;
        try {
            $this->CI->load->library('pppoe_manager');

            $this->CI->pppoe_manager->create_secret(
                $customer->pppoe_username,
                $customer->install_date,
                $customer->mikrotik_profile,
                $customer->customer_code,
                $customer->full_name
            );

            // Flag synced
            $this->CI->customer_model->update($customer_id, [
                'mikrotik_synced' => 1,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            $mikrotik_ok = true;

        } catch (Exception $e) {
            // PPP gagal ≠ aktivasi gagal
            // Customer sudah active, PPP akan di-retry oleh cron
            custom_log('provisioning.log',
                "PPP CREATE FAILED {$customer->customer_code}: " .
                $e->getMessage());

            $this->CI->customer_model->update($customer_id, [
                'mikrotik_synced' => 0,
            ]);
        }

        // ── POST-COMMIT: TELEGRAM NOTIFIKASI ──
        try {
            $this->CI->telegram_notifier->send_activation_complete(
                $customer->customer_code,
                $customer->full_name,
                $customer->pppoe_username,
                $mikrotik_ok
            );
        } catch (Exception $e) {
            // Silent fail
        }

        custom_log('provisioning.log',
            "ACTIVATE OK {$customer->customer_code} " .
            "mikrotik=" . ($mikrotik_ok ? 'OK' : 'FAILED'));

        return [
            'success'      => true,
            'customer_code'=> $customer->customer_code,
            'mikrotik_ok'  => $mikrotik_ok,
        ];
    }
}