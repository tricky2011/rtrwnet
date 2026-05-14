<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Telegram automation library.
 * Fokus backend + format pesan.
 *
 * Fungsi wajib:
 * 1) sendTelegram(message, chat_id)
 * 2) sendInvoiceReminder(invoice_data)
 * 3) sendSystemStatusPing()
 *
 * Helper tambahan:
 * - sendNewWoNotification(wo_data)
 * - sendWoDoneNotification(wo_data)
 * - sendRoiNotification(roi_data)
 */
class Telegram_automation
{
    private $CI;
    private $cfg;
    private $token = '';
    private $api_url = '';

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->config('telegram_automation', true);
        $this->cfg = (array) $this->CI->config->item('telegram_automation');
        $this->token = isset($this->cfg['telegram_bot_token']) ? trim((string) $this->cfg['telegram_bot_token']) : '';
        $this->api_url = $this->token !== '' ? ('https://api.telegram.org/bot' . $this->token) : '';
    }

    /**
     * 1) Generic sender.
     *
     * @param string $message
     * @param string|int|null $chat_id
     * @return array ['success'=>bool,'message'=>string,'telegram'=>array|null]
     */
    public function sendTelegram($message, $chat_id = null)
    {
        $chat_id = $this->resolve_chat_id($chat_id, 'default');
        $message = trim((string) $message);

        if ($chat_id === '' || $message === '') {
            $err = 'chat_id atau message kosong';
            log_message('error', '[Telegram_automation::sendTelegram] ' . $err);
            return ['success' => false, 'message' => $err, 'telegram' => null];
        }

        if ($this->token === '' || $this->api_url === '') {
            $err = 'telegram_bot_token belum di-set';
            log_message('error', '[Telegram_automation::sendTelegram] ' . $err);
            return ['success' => false, 'message' => $err, 'telegram' => null];
        }

        $payload = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ];
        $res = $this->api_request('sendMessage', $payload);
        $ok = !empty($res['ok']);

        if (!$ok) {
            $desc = isset($res['description']) ? $res['description'] : 'Unknown Telegram error';
            log_message('error', '[Telegram_automation::sendTelegram] ' . $desc);
            return ['success' => false, 'message' => $desc, 'telegram' => $res];
        }

        return ['success' => true, 'message' => 'sent', 'telegram' => $res];
    }

    /**
     * 2) Reminder invoice overdue.
     * $invoice_data minimal:
     * - invoice_number
     * - customer_name
     * - amount
     * - due_date
     * Opsional:
     * - days_overdue
     * - customer_code
     * - chat_id
     * - pay_link
     */
    public function sendInvoiceReminder(array $invoice_data)
    {
        $chat_id = isset($invoice_data['chat_id']) ? $invoice_data['chat_id'] : null;
        $chat_id = $this->resolve_chat_id($chat_id, 'finance');

        $invoice_number = $this->safe($invoice_data, 'invoice_number', '-');
        $customer_name = $this->safe($invoice_data, 'customer_name', '-');
        $customer_code = $this->safe($invoice_data, 'customer_code', '-');
        $amount = (float) (isset($invoice_data['amount']) ? $invoice_data['amount'] : 0);
        $due_date = $this->safe($invoice_data, 'due_date', '-');
        $days_overdue = (int) (isset($invoice_data['days_overdue']) ? $invoice_data['days_overdue'] : 0);
        $pay_link = $this->safe($invoice_data, 'pay_link', '');

        $amount_label = 'Rp ' . number_format($amount, 0, ',', '.');
        $overdue_label = $days_overdue > 0 ? ($days_overdue . ' hari') : 'belum dihitung';

        $message = "⚠️ *INVOICE OVERDUE REMINDER*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "No Invoice: `{$invoice_number}`\n"
                 . "Customer: {$customer_name} ({$customer_code})\n"
                 . "Total Tagihan: *{$amount_label}*\n"
                 . "Jatuh Tempo: {$due_date}\n"
                 . "Terlambat: {$overdue_label}\n";

        if ($pay_link !== '') {
            $message .= "Link Bayar: {$pay_link}\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━━━━━\n"
                  . "Mohon follow-up pembayaran.";

        return $this->sendTelegram($message, $chat_id);
    }

    /**
     * 3) Ping status sistem.
     * Mengirim heartbeat + metric ringkas.
     */
    public function sendSystemStatusPing()
    {
        $chat_id = $this->resolve_chat_id(null, 'ops');
        $app_name = isset($this->cfg['telegram_app_name']) ? $this->cfg['telegram_app_name'] : 'RTRWNet';
        $ts = date('Y-m-d H:i:s');

        $wo_open = $this->safe_count('work_orders', ['status' => 'open']);
        $inv_overdue = $this->count_overdue_invoice();

        $message = "🟢 *SYSTEM STATUS PING*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "App: *{$app_name}*\n"
                 . "Timestamp: {$ts}\n"
                 . "WO OPEN: {$wo_open}\n"
                 . "Invoice Overdue: {$inv_overdue}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Status: NORMAL";

        return $this->sendTelegram($message, $chat_id);
    }

    /**
     * Helper: New WO notification.
     * $wo_data minimal: wo_number, customer_name, address, package_name, scheduled_date
     */
    public function sendNewWoNotification(array $wo_data)
    {
        $chat_id = isset($wo_data['chat_id']) ? $wo_data['chat_id'] : null;
        $chat_id = $this->resolve_chat_id($chat_id, 'ops');

        $message = "🆕 *NEW WORK ORDER*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "No WO: `{$this->safe($wo_data, 'wo_number', '-')}`\n"
                 . "Customer: {$this->safe($wo_data, 'customer_name', '-')}\n"
                 . "Alamat: {$this->safe($wo_data, 'address', '-')}\n"
                 . "Paket: {$this->safe($wo_data, 'package_name', '-')}\n"
                 . "Jadwal: {$this->safe($wo_data, 'scheduled_date', '-')}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━";

        return $this->sendTelegram($message, $chat_id);
    }

    /**
     * Helper: WO done notification.
     * $wo_data minimal: wo_number, customer_name, teknisi_name, done_at
     */
    public function sendWoDoneNotification(array $wo_data)
    {
        $chat_id = isset($wo_data['chat_id']) ? $wo_data['chat_id'] : null;
        $chat_id = $this->resolve_chat_id($chat_id, 'ops');

        $message = "✅ *WO DONE*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "No WO: `{$this->safe($wo_data, 'wo_number', '-')}`\n"
                 . "Customer: {$this->safe($wo_data, 'customer_name', '-')}\n"
                 . "Teknisi: {$this->safe($wo_data, 'teknisi_name', '-')}\n"
                 . "Selesai: {$this->safe($wo_data, 'done_at', date('Y-m-d H:i:s'))}\n";

        $notes = $this->safe($wo_data, 'notes', '');
        if ($notes !== '') {
            $message .= "Catatan: {$notes}\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━━━━━";
        return $this->sendTelegram($message, $chat_id);
    }

    /**
     * Helper: ROI notification.
     * $roi_data minimal: period, investment_total, net_profit, roi_percent
     * Opsional: revenue_total, payback_months, chat_id
     */
    public function sendRoiNotification(array $roi_data)
    {
        $chat_id = isset($roi_data['chat_id']) ? $roi_data['chat_id'] : null;
        $chat_id = $this->resolve_chat_id($chat_id, 'management');

        $period = $this->safe($roi_data, 'period', date('Y-m'));
        $investment = (float) (isset($roi_data['investment_total']) ? $roi_data['investment_total'] : 0);
        $revenue = (float) (isset($roi_data['revenue_total']) ? $roi_data['revenue_total'] : 0);
        $net_profit = (float) (isset($roi_data['net_profit']) ? $roi_data['net_profit'] : 0);
        $roi_percent = (float) (isset($roi_data['roi_percent']) ? $roi_data['roi_percent'] : 0);
        $payback = $this->safe($roi_data, 'payback_months', '-');

        $message = "📈 *ROI NOTIFICATION*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Periode: {$period}\n"
                 . "Investment: Rp " . number_format($investment, 0, ',', '.') . "\n"
                 . "Revenue: Rp " . number_format($revenue, 0, ',', '.') . "\n"
                 . "Net Profit: Rp " . number_format($net_profit, 0, ',', '.') . "\n"
                 . "ROI: *" . number_format($roi_percent, 2, ',', '.') . "%*\n"
                 . "Payback: {$payback}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━";

        return $this->sendTelegram($message, $chat_id);
    }

    private function resolve_chat_id($chat_id = null, $channel = 'default')
    {
        $chat_id = trim((string) $chat_id);
        if ($chat_id !== '') {
            return $chat_id;
        }

        if ($channel === 'finance' && !empty($this->cfg['telegram_finance_chat_id'])) {
            return (string) $this->cfg['telegram_finance_chat_id'];
        }
        if ($channel === 'ops' && !empty($this->cfg['telegram_ops_chat_id'])) {
            return (string) $this->cfg['telegram_ops_chat_id'];
        }
        if ($channel === 'management' && !empty($this->cfg['telegram_management_chat_id'])) {
            return (string) $this->cfg['telegram_management_chat_id'];
        }

        return isset($this->cfg['telegram_default_chat_id'])
            ? (string) $this->cfg['telegram_default_chat_id']
            : '';
    }

    private function api_request($method, array $payload)
    {
        $url = $this->api_url . '/' . $method;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'description' => 'cURL: ' . $error];
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'description' => 'Invalid JSON response'];
        }

        return $decoded;
    }

    private function safe(array $arr, $key, $default = '')
    {
        return isset($arr[$key]) && $arr[$key] !== '' ? (string) $arr[$key] : $default;
    }

    private function safe_count($table, array $where = [])
    {
        try {
            $qb = $this->CI->db->from($table);
            foreach ($where as $k => $v) {
                $qb->where($k, $v);
            }
            return (int) $qb->count_all_results();
        } catch (Exception $e) {
            log_message('error', '[Telegram_automation::safe_count] ' . $e->getMessage());
            return 0;
        }
    }

    private function count_overdue_invoice()
    {
        try {
            return (int) $this->CI->db
                ->from('invoices')
                ->where('status', 'overdue')
                ->count_all_results();
        } catch (Exception $e) {
            log_message('error', '[Telegram_automation::count_overdue_invoice] ' . $e->getMessage());
            return 0;
        }
    }
}
