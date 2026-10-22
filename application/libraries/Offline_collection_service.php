<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Offline_collection_service
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->model('settings_model');
        $this->CI->load->library('telegram_service');
    }

    public function run($preview = false, $preview_date = null)
    {
        $now = $this->current_time();
        $date = $preview && $preview_date !== null ? (string) $preview_date : $now->format('Y-m-d');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Jakarta'));
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return array('success' => false, 'message' => 'Tanggal preview harus YYYY-MM-DD yang valid.');
        }
        if (!$preview && ($now->format('d') !== '10' || (int) $now->format('H') < 8)) {
            return array('success' => true, 'skipped' => true, 'message' => 'Jadwal penagihan tanggal 10 mulai pukul 08.00 WIB.');
        }
        $db = $this->CI->db;
        if (!$db->field_exists('payment_collection_method', 'customers') || !$db->table_exists('offline_collection_deliveries')) {
            return array('success' => false, 'message' => 'Migrasi siklus tagihan dan penagihan offline belum dijalankan.');
        }
        $period = $parsed->format('Y-m');
        $lock_name = 'offline_collection_' . $period;
        $lock = $db->query('SELECT GET_LOCK(?, 0) AS acquired', array($lock_name))->row_array();
        if ((int) ($lock['acquired'] ?? 0) !== 1) {
            return array('success' => true, 'skipped' => true, 'message' => 'Laporan penagihan sedang diproses.');
        }
        $summary = array('success' => true, 'preview' => (bool) $preview, 'period' => $period, 'sent' => 0, 'already_sent' => 0, 'routers' => array());
        try {
            $routers = $db->select('id, name')->from('routers')->where('is_active', 1)->get()->result_array();
            foreach ($routers as $router) {
                $router_id = (int) $router['id'];
                // Deliberately no fallback to admin, owner, alert, or another router.
                $targets = $this->CI->settings_model->get_telegram_targets_by_router($router_id, 'collection');
                $rows = $this->get_outstanding($router_id, $date);
                if (empty($rows) && empty($targets)) {
                    continue;
                }
                $messages = $this->build_messages($router['name'], $date, $rows);
                $summary['routers'][] = array('router_id' => $router_id, 'customers' => count($rows), 'targets' => count($targets), 'parts' => count($messages));
                if (empty($targets)) {
                    $summary['success'] = false;
                    $summary['message'] = 'Ada pelanggan penagihan rumah tanpa grup Telegram collection aktif.';
                }
                foreach ($targets as $target) {
                    $token_raw = trim((string) ($target['bot_token'] ?? ''));
                    $token = trim((string) $this->CI->settings_model->decrypt_secret($token_raw));
                    if ($token === '' && strpos($token_raw, 'ci3:') !== 0 && strpos($token_raw, 'osl:') !== 0) {
                        $token = $token_raw;
                    }
                    if ($token === '' || empty($target['chat_id']) || (int) ($target['bot_is_active'] ?? 0) !== 1) {
                        $summary['success'] = false;
                        $summary['message'] = 'Bot grup penagihan tidak aktif atau token tidak dapat dibaca.';
                        continue;
                    }
                    if ($preview) {
                        continue;
                    }
                    $key = array('period' => $period, 'router_id' => $router_id, 'telegram_group_id' => (int) $target['id']);
                    $deliveries = $db->where($key)->order_by('part_no', 'ASC')->get('offline_collection_deliveries')->result_array();
                    if (empty($deliveries)) {
                        // Store the complete snapshot together so retries keep stable page numbers.
                        $db->trans_begin();
                        foreach ($messages as $part => $message) {
                            $db->insert('offline_collection_deliveries', array_merge($key, array(
                                'chat_id' => $target['chat_id'], 'part_no' => $part + 1,
                                'message' => $message, 'created_at' => $now->format('Y-m-d H:i:s'),
                            )));
                        }
                        if ($db->trans_status() === false) {
                            $db->trans_rollback();
                            throw new RuntimeException('Gagal menyimpan daftar penagihan.');
                        }
                        $db->trans_commit();
                        $deliveries = $db->where($key)->order_by('part_no', 'ASC')->get('offline_collection_deliveries')->result_array();
                    }
                    foreach ($deliveries as $delivery) {
                        if (!empty($delivery['sent_at'])) {
                            $summary['already_sent']++;
                            continue;
                        }
                        if ((string) $delivery['chat_id'] !== (string) $target['chat_id']) {
                            $summary['success'] = false;
                            break;
                        }
                        $result = $this->CI->telegram_service->send_message($token, $target['chat_id'], $delivery['message']);
                        if (empty($result['success'])) {
                            $summary['success'] = false;
                            $summary['message'] = 'Pengiriman Telegram gagal; bagian yang belum terkirim dicoba lagi pada jadwal berikutnya.';
                            break;
                        }
                        if (!$db->where('id', $delivery['id'])->update('offline_collection_deliveries', array('sent_at' => $now->format('Y-m-d H:i:s')))) {
                            throw new RuntimeException('Telegram terkirim, tetapi status pengiriman gagal disimpan.');
                        }
                        $summary['sent']++;
                    }
                }
            }
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', array($lock_name));
        }
        return $summary;
    }

    protected function current_time()
    {
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    }

    public function get_outstanding($router_id, $date)
    {
        $db = $this->CI->db;
        $qb = $db->select('c.id, c.full_name, c.phone, c.address, i.invoice_number, i.billing_period_start, i.billing_period_end, i.due_date, i.balance_amount')
            ->from('customers c')->join('invoices i', 'i.customer_id = c.id', 'inner')
            ->where('c.router_id', (int) $router_id)->where('i.router_id', (int) $router_id)
            ->where('c.payment_collection_method', 'cash_visit')
            ->where_in('LOWER(c.status)', array('active', 'suspended', 'isolated', 'isolir'))
            ->where_in('i.status', array('issued', 'overdue', 'partially_paid'))
            ->where('i.balance_amount >', 0)->where('i.billing_period_start <=', $date);
        if ($db->field_exists('deleted_at', 'customers')) {
            $qb->where('c.deleted_at IS NULL', null, false);
        }
        if ($db->field_exists('is_deleted', 'customers')) {
            $qb->where('c.is_deleted', 0);
        }
        $invoices = $qb->order_by('c.full_name', 'ASC')->order_by('c.id', 'ASC')->order_by('i.billing_period_start', 'ASC')->get()->result_array();
        $customers = array();
        foreach ($invoices as $invoice) {
            $id = (int) $invoice['id'];
            if (!isset($customers[$id])) {
                $customers[$id] = array('name' => $invoice['full_name'], 'phone' => $invoice['phone'], 'address' => $invoice['address'], 'amount' => 0, 'invoices' => array());
            }
            $customers[$id]['amount'] += (float) $invoice['balance_amount'];
            $customers[$id]['invoices'][] = $invoice;
        }
        return array_values($customers);
    }

    public function build_messages($router_name, $date, array $customers)
    {
        $escape = static function ($value, $limit = 240) {
            return htmlspecialchars(mb_substr((string) $value, 0, $limit), ENT_QUOTES, 'UTF-8');
        };
        $total = array_sum(array_column($customers, 'amount'));
        $header = '<b>Penagihan Rumah — ' . $escape($router_name, 60) . "</b>\n"
            . 'Daftar per ' . $escape($date) . " (WIB)\n"
            . count($customers) . ' pelanggan | Total Rp ' . number_format($total, 0, ',', '.') . "\n"
            . "Termasuk tunggakan dan tagihan periode berjalan yang belum lunas.\n\n";
        $pages = array();
        $body = '';
        foreach ($customers as $index => $customer) {
            $periods = array();
            foreach ($customer['invoices'] as $invoice) {
                $periods[] = substr($invoice['billing_period_start'], 0, 7) . ' s/d ' . substr($invoice['billing_period_end'], 0, 7);
            }
            $entry = ($index + 1) . '. <b>' . $escape($customer['name'], 100) . "</b>\n"
                . 'HP: ' . $escape($customer['phone'], 40) . "\n"
                . 'Alamat: ' . $escape($customer['address'], 180) . "\n"
                . 'Periode: ' . $escape(implode(', ', $periods), 120) . "\n"
                . 'Sisa: <b>Rp ' . number_format($customer['amount'], 0, ',', '.') . "</b>\n\n";
            if ($body !== '' && mb_strlen($header . $body . $entry) > 3500) {
                $pages[] = $header . $body;
                $body = '';
            }
            $body .= $entry;
        }
        $pages[] = $header . ($body !== '' ? $body : 'Tidak ada pelanggan yang perlu ditagih ke rumah.');
        foreach ($pages as $index => &$page) {
            $page .= "\nBagian " . ($index + 1) . '/' . count($pages);
        }
        unset($page);
        return $pages;
    }
}
