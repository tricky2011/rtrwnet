<?php
/**
 * application/libraries/Telegram_notifier.php
 *
 * Mengelola format pesan dan pengiriman notifikasi Telegram.
 * Semua pesan di-queue ke tabel telegram_queue,
 * diproses oleh cron setiap menit.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Telegram_notifier
{
    private $CI;
    private $group_chat_id;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model(['telegram_queue_model', 'setting_model', 'user_model']);

        $this->group_chat_id = $this->CI->setting_model
            ->get_value('telegram_group_teknisi');
    }

    // ═══════════════════════════════════════════════════════════
    //  WO BARU — PEMASANGAN
    // ═══════════════════════════════════════════════════════════

    /**
     * Kirim notifikasi WO pemasangan baru
     *
     * @param  string    $wo_number
     * @param  array     $details
     * @param  int|null  $teknisi_id  Null = kirim ke grup
     */
    public function send_new_wo($wo_number, $details, $teknisi_id = null)
    {
        $install_label = tanggal_indo($details['install_date'], true);
        $price_label   = rupiah($details['price']);

        $message = "🔧 *WO PEMASANGAN BARU*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "\n"
                 . "📋 *No WO:* `{$wo_number}`\n"
                 . "\n"
                 . "*── DATA PELANGGAN ──*\n"
                 . "👤 *Nama:* {$details['full_name']}\n"
                 . "📍 *Alamat:* {$details['address']}\n"
                 . "📱 *HP:* {$details['phone']}\n"
                 . "\n"
                 . "*── PAKET LAYANAN ──*\n"
                 . "📦 *Paket:* {$details['package_name']}\n"
                 . "⚡ *Bandwidth:* {$details['bandwidth_mbps']} Mbps\n"
                 . "💰 *Harga:* {$price_label}/bln\n"
                 . "\n"
                 . "*── DATA TEKNIS ──*\n"
                 . "🌐 *PPPoE User:* `{$details['pppoe_username']}`\n"
                 . "🔑 *PPPoE Pass:* `{$details['pppoe_password']}`\n"
                 . "📌 *ODP:* {$details['odp_info']}\n"
                 . "🏷️ *VLAN:* {$details['vlan_info']}\n"
                 . "\n"
                 . "*── JADWAL ──*\n"
                 . "📅 *Tanggal:* {$install_label}\n"
                 . "🕐 *Jam:* {$details['scheduled_time']}\n";

        if (!empty($details['notes']) && $details['notes'] !== '-') {
            $message .= "\n📝 *Catatan:* {$details['notes']}\n";
        }

        $message .= "\n━━━━━━━━━━━━━━━━━━━━━━\n"
                  .  "✅ Silakan konfirmasi jika sudah mulai.";

        // Tentukan tujuan: teknisi spesifik atau grup
        if ($teknisi_id) {
            $teknisi = $this->CI->user_model->get($teknisi_id);
            if ($teknisi && $teknisi->telegram_chat_id) {
                $this->queue($teknisi->telegram_chat_id, $message, 'wo_new');
            }
        }

        // Selalu kirim ke grup juga
        if ($this->group_chat_id) {
            $this->queue($this->group_chat_id, $message, 'wo_new');
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  WO STATUS UPDATE
    // ═══════════════════════════════════════════════════════════

    /**
     * Notifikasi perubahan status WO
     */
    public function send_wo_status_update($wo_number, $customer_name,
                                           $old_status, $new_status, $notes = '')
    {
        $icons = [
            'process' => '🔄',
            'done'    => '✅',
            'activated' => '🟢',
            'cancel'  => '❌',
        ];

        $labels = [
            'open'    => 'OPEN',
            'process' => 'DIKERJAKAN',
            'done'    => 'SELESAI',
            'activated' => 'AKTIVATED',
            'cancel'  => 'DIBATALKAN',
        ];

        $icon  = $icons[$new_status] ?? '📋';
        $label = $labels[$new_status] ?? strtoupper($new_status);
        $old_label = $labels[$old_status] ?? strtoupper($old_status);

        $message = "{$icon} *WO UPDATE: {$label}*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "\n"
                 . "📋 *No WO:* `{$wo_number}`\n"
                 . "👤 *Pelanggan:* {$customer_name}\n"
                 . "📊 *Status:* {$old_label} → *{$label}*\n";

        if ($notes) {
            $message .= "📝 *Catatan:* {$notes}\n";
        }

        $message .= "\n━━━━━━━━━━━━━━━━━━━━━━";

        if ($this->group_chat_id) {
            $this->queue($this->group_chat_id, $message, 'wo_update');
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  AKTIVASI SELESAI
    // ═══════════════════════════════════════════════════════════

    /**
     * Notifikasi aktivasi pelanggan selesai
     */
    public function send_activation_complete($customer_code, $full_name,
                                              $pppoe_username, $mikrotik_ok)
    {
        $mt_status = $mikrotik_ok
            ? '✅ PPP Secret berhasil dibuat'
            : '⚠️ PPP Secret gagal — akan di-retry otomatis';

        $message = "🎉 *PELANGGAN AKTIF*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "\n"
                 . "👤 *{$customer_code}* - {$full_name}\n"
                 . "🌐 PPPoE: `{$pppoe_username}`\n"
                 . "🔌 MikroTik: {$mt_status}\n"
                 . "📊 Status: *ACTIVE*\n"
                 . "💳 Billing: Mulai periode berikutnya\n"
                 . "\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━";

        if ($this->group_chat_id) {
            $this->queue($this->group_chat_id, $message, 'wo_update');
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  WO REMINDER (dipanggil cron)
    // ═══════════════════════════════════════════════════════════

    /**
     * Kirim reminder untuk WO yang sudah open > 24 jam
     */
    public function send_wo_overdue_reminder($wo_list)
    {
        if (empty($wo_list)) return;

        $message = "⏰ *REMINDER: WO BELUM DIKERJAKAN*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n\n";

        foreach ($wo_list as $wo) {
            $hours = round((time() - strtotime($wo->open_at)) / 3600);
            $message .= "📋 `{$wo->wo_number}` — {$wo->customer_name}\n"
                      . "   ⏱️ Sudah {$hours} jam\n"
                      . "   📅 Jadwal: " . tanggal_indo($wo->scheduled_date) . "\n\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━━━━━\n"
                  .  "Segera tindak lanjuti.";

        if ($this->group_chat_id) {
            $this->queue($this->group_chat_id, $message, 'reminder');
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  INTERNAL: QUEUE
    // ═══════════════════════════════════════════════════════════

    /**
     * Masukkan pesan ke antrian (diproses cron per menit)
     */
    private function queue($chat_id, $message, $type)
    {
        $this->CI->telegram_queue_model->insert([
            'chat_id'    => $chat_id,
            'message'    => $message,
            'type'       => $type,
            'status'     => 'pending',
            'attempts'   => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
