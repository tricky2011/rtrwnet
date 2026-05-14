<?php
/**
 * application/libraries/Wo_manager.php
 *
 * Mengelola lifecycle Work Order.
 * Setiap perubahan status dicatat di wo_status_history.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Wo_manager
{
    private $CI;

    // Transisi status yang diizinkan
    private $transitions = [
        'open'    => ['process', 'cancel'],
        'process' => ['done', 'cancel'],
        'done'    => ['activated'],
        'activated' => [],     // terminal state
        'cancel'  => [],       // terminal state
    ];

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model(['wo_model', 'wo_history_model', 'customer_model']);
        $this->CI->load->library(['code_generator', 'telegram_notifier']);
    }

    /**
     * Buat WO pemasangan baru (dipanggil dari Provisioning_engine)
     *
     * @param  int   $customer_id
     * @param  array $details  Data pelanggan + teknis
     * @param  int   $assigned_to  Teknisi ID (null = grup)
     * @param  int   $created_by   Admin ID
     * @return array ['wo_id' => int, 'wo_number' => string]
     */
    public function create_installation_wo($customer_id, $details,
                                            $assigned_to, $created_by)
    {
        $wo_number = $this->CI->code_generator->next_wo();
        $now = date('Y-m-d H:i:s');

        $install_label = tanggal_indo($details['install_date'], true);

        $description = "Pemasangan baru pelanggan {$details['customer_code']} " .
                       "- {$details['full_name']}.\n" .
                       "Paket: {$details['package_name']} ({$details['bandwidth_mbps']} Mbps)\n" .
                       "ODP: {$details['odp_info']}\n" .
                       "VLAN: {$details['vlan_info']}\n" .
                       "Jadwal: {$install_label}";

        if (!empty($details['notes'])) {
            $description .= "\nCatatan: {$details['notes']}";
        }

        $wo_data = [
            'wo_number'       => $wo_number,
            'customer_id'     => $customer_id,
            'assigned_to'     => $assigned_to,
            'created_by'      => $created_by,
            'type'            => 'installation',
            'priority'        => 'high',
            'status'          => 'open',
            'open_at'         => $now,
            'scheduled_date'  => $details['install_date'],
            'scheduled_time'  => $details['scheduled_time'] ?? null,
            'description'     => $description,
            'odp_info'        => $details['odp_info'],
            'vlan_info'       => $details['vlan_info'],
            'pppoe_username'  => $details['pppoe_username'],
            'package_name'    => $details['package_name'],
            'bandwidth_mbps'  => $details['bandwidth_mbps'],
            'sla_hours'       => 24,
            'created_at'      => $now,
        ];

        $wo_id = $this->CI->wo_model->insert($wo_data);

        // History: null → open
        $this->record_history($wo_id, null, 'open', $created_by,
            'WO pemasangan dibuat');

        return ['wo_id' => $wo_id, 'wo_number' => $wo_number];
    }

    /**
     * Buat WO gangguan / repair
     */
    public function create_repair_wo($customer_id, $description,
                                      $priority, $assigned_to, $created_by)
    {
        $wo_number = $this->CI->code_generator->next_wo();
        $now = date('Y-m-d H:i:s');

        $wo_id = $this->CI->wo_model->insert([
            'wo_number'    => $wo_number,
            'customer_id'  => $customer_id,
            'assigned_to'  => $assigned_to,
            'created_by'   => $created_by,
            'type'         => 'repair',
            'priority'     => $priority,
            'status'       => 'open',
            'open_at'      => $now,
            'description'  => $description,
            'sla_hours'    => $priority === 'urgent' ? 4 : 24,
            'created_at'   => $now,
        ]);

        $this->record_history($wo_id, null, 'open', $created_by, $description);

        return ['wo_id' => $wo_id, 'wo_number' => $wo_number];
    }

    // ═══════════════════════════════════════════════════════════
    //  STATUS TRANSITIONS
    // ═══════════════════════════════════════════════════════════

    /**
     * Transisi WO ke status PROCESS (teknisi mulai kerja)
     */
    public function start_wo($wo_id, $teknisi_id, $notes = '')
    {
        return $this->transition($wo_id, 'process', $teknisi_id, $notes);
    }

    /**
     * Transisi WO ke status DONE (pekerjaan selesai)
     *
     * Jika type=installation → trigger aktivasi pelanggan
     */
    public function complete_wo($wo_id, $teknisi_id, $completion_data = [])
    {
        $wo = $this->CI->wo_model->get($wo_id);
        if (!$wo) {
            throw new Exception("WO #{$wo_id} tidak ditemukan");
        }

        // Validasi transisi
        $this->validate_transition($wo->status, 'done');

        $now = date('Y-m-d H:i:s');

        // Update WO
        $update = [
            'status'           => 'done',
            'done_at'          => $now,
            'completion_notes' => $completion_data['notes'] ?? null,
            'updated_at'       => $now,
        ];

        // Upload foto jika ada
        if (!empty($completion_data['photo_before'])) {
            $update['photo_before'] = $completion_data['photo_before'];
        }
        if (!empty($completion_data['photo_after'])) {
            $update['photo_after'] = $completion_data['photo_after'];
        }
        if (!empty($completion_data['photo_odp'])) {
            $update['photo_odp'] = $completion_data['photo_odp'];
        }

        // SLA check
        if ($wo->open_at && $wo->sla_hours) {
            $open_time = strtotime($wo->open_at);
            $done_time = strtotime($now);
            $elapsed_hours = ($done_time - $open_time) / 3600;

            if ($elapsed_hours > $wo->sla_hours) {
                $update['is_sla_breached'] = 1;
            }
        }

        $this->CI->wo_model->update($wo_id, $update);

        // History
        $this->record_history($wo_id, $wo->status, 'done', $teknisi_id,
            $completion_data['notes'] ?? 'Pekerjaan selesai');
        $this->notify_status_change(
            $wo,
            $wo->status,
            'done',
            $completion_data['notes'] ?? ''
        );

        // ── TRIGGER: Jika WO Pemasangan → Aktivasi ──
        if ($wo->type === 'installation') {
            try {
                $activation = $this->activate_wo(
                    $wo_id,
                    $teknisi_id,
                    'Aktivasi otomatis setelah WO DONE'
                );

                custom_log('wo_manager.log',
                    "WO {$wo->wo_number} DONE → ACTIVATED " .
                    "customer={$wo->customer_id} " .
                    "mikrotik=" . ($activation['mikrotik_ok'] ? 'OK' : 'RETRY'));
            } catch (Exception $e) {
                // WO tetap DONE, aktivasi bisa di-retry manual.
                custom_log('wo_manager.log',
                    "WO {$wo->wo_number} ACTIVATION FAILED: " . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Transisi DONE -> ACTIVATED.
     * Dipakai untuk auto-aktivasi setelah WO selesai atau retry manual.
     */
    public function activate_wo($wo_id, $user_id, $notes = '')
    {
        $wo = $this->CI->wo_model->get($wo_id);
        if (!$wo) {
            throw new Exception("WO #{$wo_id} tidak ditemukan");
        }

        $this->validate_transition($wo->status, 'activated');

        // Aktivasi service customer (status customer + PPP secret)
        $this->CI->load->library('provisioning_engine');
        $activation = $this->CI->provisioning_engine
            ->activate_customer($wo->customer_id, $wo_id, $user_id);

        $now = date('Y-m-d H:i:s');
        $this->CI->wo_model->update($wo_id, [
            'status'       => 'activated',
            'activated_at' => $now,
            'updated_at'   => $now,
        ]);

        $activation_note = trim($notes) !== '' ? $notes : 'Customer berhasil diaktivasi';
        $this->record_history($wo_id, $wo->status, 'activated', $user_id, $activation_note);
        $this->notify_status_change($wo, $wo->status, 'activated', $activation_note);

        return $activation;
    }

    /**
     * Transisi WO ke status CANCEL
     */
    public function cancel_wo($wo_id, $admin_id, $reason)
    {
        $wo = $this->CI->wo_model->get($wo_id);
        if (!$wo) {
            throw new Exception("WO #{$wo_id} tidak ditemukan");
        }

        $this->validate_transition($wo->status, 'cancel');

        $now = date('Y-m-d H:i:s');

        $this->CI->wo_model->update($wo_id, [
            'status'        => 'cancel',
            'cancel_at'     => $now,
            'cancel_reason' => $reason,
            'updated_at'    => $now,
        ]);

        $this->record_history($wo_id, $wo->status, 'cancel', $admin_id, $reason);
        $this->notify_status_change($wo, $wo->status, 'cancel', $reason);

        // Jika WO installation dan customer masih waiting_install
        // → customer tetap waiting_install (bisa buat WO baru)
        // → ATAU admin bisa terminate manual

        custom_log('wo_manager.log',
            "WO {$wo->wo_number} CANCELLED reason={$reason}");

        return true;
    }

    // ═══════════════════════════════════════════════════════════
    //  INTERNAL HELPERS
    // ═══════════════════════════════════════════════════════════

    /**
     * Generic transition handler
     */
    private function transition($wo_id, $new_status, $user_id, $notes = '')
    {
        $wo = $this->CI->wo_model->get($wo_id);
        if (!$wo) {
            throw new Exception("WO #{$wo_id} tidak ditemukan");
        }

        $this->validate_transition($wo->status, $new_status);

        $now = date('Y-m-d H:i:s');
        $update = [
            'status'     => $new_status,
            'updated_at' => $now,
        ];

        // Set timestamp sesuai status baru
        $timestamp_map = [
            'process' => 'process_at',
            'done'    => 'done_at',
            'activated' => 'activated_at',
            'cancel'  => 'cancel_at',
        ];

        if (isset($timestamp_map[$new_status])) {
            $update[$timestamp_map[$new_status]] = $now;
        }

        // Jika process: auto-assign teknisi
        if ($new_status === 'process' && empty($wo->assigned_to)) {
            $update['assigned_to'] = $user_id;
        }

        $this->CI->wo_model->update($wo_id, $update);

        $this->record_history($wo_id, $wo->status, $new_status, $user_id, $notes);
        $this->notify_status_change($wo, $wo->status, $new_status, $notes);

        return true;
    }

    /**
     * Validasi transisi status
     */
    private function validate_transition($current, $target)
    {
        $allowed = $this->transitions[$current] ?? [];

        if (!in_array($target, $allowed)) {
            throw new Exception(
                "Transisi status tidak valid: {$current} → {$target}. " .
                "Allowed: " . implode(', ', $allowed)
            );
        }
    }

    /**
     * Kirim notifikasi update status WO ke Telegram.
     */
    private function notify_status_change($wo, $old_status, $new_status, $notes = '')
    {
        try {
            $customer = $this->CI->customer_model->get($wo->customer_id);
            $customer_name = $customer ? $customer->full_name : ('Customer#' . $wo->customer_id);

            $this->CI->telegram_notifier->send_wo_status_update(
                $wo->wo_number,
                $customer_name,
                $old_status,
                $new_status,
                $notes
            );
        } catch (Exception $e) {
            custom_log(
                'wo_manager.log',
                "WO {$wo->wo_number} TELEGRAM STATUS FAILED: " . $e->getMessage()
            );
        }
    }

    /**
     * Catat history perubahan status
     */
    private function record_history($wo_id, $from, $to, $user_id, $notes = '')
    {
        $this->CI->wo_history_model->insert([
            'wo_id'       => $wo_id,
            'from_status' => $from,
            'to_status'   => $to,
            'changed_by'  => $user_id,
            'notes'       => $notes,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
