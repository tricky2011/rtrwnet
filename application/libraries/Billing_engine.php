<?php
/**
 * application/libraries/Billing_engine.php
 *
 * Inti dari sistem billing Nawacore.
 * Bertanggung jawab untuk:
 * - Generate invoice otomatis (cron)
 * - Generate invoice manual (admin)
 * - Proses pembayaran
 * - Cek overdue
 * - Query revenue
 *
 * PRINSIP:
 * 1. Invoice TIDAK PERNAH dihapus, hanya ganti status
 * 2. Revenue dihitung dari paid_date, BUKAN period_month
 * 3. INSERT IGNORE untuk idempotency
 * 4. Snapshot harga paket saat generate
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Billing_engine
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model([
            'invoice_model',
            'payment_model',
            'cashflow_model',
            'customer_model',
            'package_model',
            'setting_model',
        ]);
        $this->CI->load->library(['code_generator', 'activity_logger']);
        $this->CI->load->helper('billing');
    }

    // ═══════════════════════════════════════════════════════════
    //  A. GENERATE INVOICE
    // ═══════════════════════════════════════════════════════════

    /**
     * Generate invoice untuk SEMUA pelanggan yang billing_date = hari ini
     *
     * Dipanggil oleh: cron/invoice_cron generate (harian 00:05)
     *
     * @return array Stats hasil generate
     *
     * Flow:
     *   1. Ambil hari ini (1-28)
     *   2. Query semua customer dengan billing_date = hari ini
     *      DAN status IN (active, isolated)
     *   3. Untuk setiap customer: generate_single_invoice()
     *   4. Return stats
     */
    public function generate_daily()
    {
        $today_day = (int) date('j'); // 1-31
        $period    = date('Y-m');     // "2026-01"
        $now       = date('Y-m-d H:i:s');

        $stats = [
            'date'       => date('Y-m-d'),
            'billing_day'=> $today_day,
            'period'     => $period,
            'total'      => 0,
            'created'    => 0,
            'skipped'    => 0,
            'error'      => 0,
            'errors'     => [],
        ];

        // Ambil pelanggan yang jatuh tempo hari ini
        // Status active DAN isolated tetap ditagih
        // Status suspended dan terminated TIDAK ditagih
        $customers = $this->CI->customer_model->get_billable_by_day($today_day);
        $stats['total'] = count($customers);

        custom_log('billing_engine.log',
            "GENERATE START day={$today_day} period={$period} " .
            "eligible={$stats['total']}");

        foreach ($customers as $cust) {
            try {
                $result = $this->generate_single_invoice($cust, $period);

                if ($result['status'] === 'created') {
                    $stats['created']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (Exception $e) {
                $stats['error']++;
                $stats['errors'][] = [
                    'customer_id'   => $cust->id,
                    'customer_code' => $cust->customer_code,
                    'error'         => $e->getMessage(),
                ];
                custom_log('billing_engine.log',
                    "GENERATE ERROR {$cust->customer_code}: " . $e->getMessage());
            }
        }

        custom_log('billing_engine.log',
            "GENERATE DONE: " . json_encode($stats));

        return $stats;
    }

    /**
     * Generate invoice untuk 1 pelanggan, 1 periode
     *
     * @param  object $customer  Row customer JOIN package
     * @param  string $period    Format "YYYY-MM"
     * @return array  ['status' => 'created'|'skipped', 'invoice_id' => int|null]
     */
    public function generate_single_invoice($customer, $period = null)
    {
        if ($period === null) {
            $period = date('Y-m');
        }

        // ── LAPIS 2: Application-level duplicate check ──
        $exists = $this->CI->invoice_model->exists(
            $customer->id,
            $period
        );

        if ($exists) {
            return ['status' => 'skipped', 'invoice_id' => null,
                    'reason' => 'already_exists'];
        }

        // Hitung due_date
        $due_date = $this->calculate_due_date($customer->billing_date, $period);

        // Generate invoice number: INV-YYYYMM-XXXX
        $invoice_number = $this->CI->code_generator->next_invoice($period);

        // Snapshot harga paket saat generate
        // Ini KRUSIAL: jika pelanggan ganti paket bulan depan,
        // invoice bulan ini tetap pakai harga lama
        $data = [
            'invoice_number' => $invoice_number,
            'customer_id'    => $customer->id,
            'period_month'   => $period,
            'package_id'     => $customer->package_id,
            'package_name'   => $customer->package_name,
            'amount'         => $customer->price,
            'due_date'       => $due_date,
            'status'         => 'unpaid',
            'paid_amount'    => 0.00,
            'paid_date'      => null,
            'generated_by'   => 'cron',
            'created_at'     => date('Y-m-d H:i:s'),
        ];

        // ── LAPIS 1+2: INSERT IGNORE (database constraint safety net) ──
        $invoice_id = $this->CI->invoice_model->insert_ignore($data);

        if ($invoice_id) {
            custom_log('billing_engine.log',
                "INVOICE CREATED {$invoice_number} " .
                "cust={$customer->customer_code} " .
                "period={$period} amount={$customer->price}");

            return ['status' => 'created', 'invoice_id' => $invoice_id];
        }

        // INSERT IGNORE returned 0 = duplicate key, skip
        return ['status' => 'skipped', 'invoice_id' => null,
                'reason' => 'duplicate_key'];
    }

    /**
     * Generate invoice manual oleh admin
     * Untuk kasus pelanggan baru di tengah bulan atau koreksi
     */
    public function generate_manual($customer_id, $period, $amount = null, $notes = '')
    {
        $customer = $this->CI->customer_model->get_with_package($customer_id);
        if (!$customer) {
            throw new Exception("Pelanggan #{$customer_id} tidak ditemukan");
        }

        if ($amount === null) {
            $amount = $customer->price; // Harga paket saat ini
        }

        $exists = $this->CI->invoice_model->exists($customer_id, $period);
        if ($exists) {
            throw new Exception(
                "Invoice untuk {$customer->customer_code} " .
                "periode {$period} sudah ada"
            );
        }

        $due_date = $this->calculate_due_date($customer->billing_date, $period);
        $invoice_number = $this->CI->code_generator->next_invoice($period);

        $data = [
            'invoice_number' => $invoice_number,
            'customer_id'    => $customer_id,
            'period_month'   => $period,
            'package_id'     => $customer->package_id,
            'package_name'   => $customer->package_name,
            'amount'         => $amount,
            'due_date'       => $due_date,
            'status'         => 'unpaid',
            'paid_amount'    => 0.00,
            'generated_by'   => 'manual',
            'notes'          => $notes,
            'created_at'     => date('Y-m-d H:i:s'),
        ];

        $invoice_id = $this->CI->invoice_model->insert($data);

        $this->CI->activity_logger->log(
            'create_invoice_manual',
            $customer_id,
            $data
        );

        return $invoice_id;
    }

    // ═══════════════════════════════════════════════════════════
    //  B. OVERDUE CHECK
    // ═══════════════════════════════════════════════════════════

    /**
     * Cek dan tandai invoice yang overdue
     *
     * Dipanggil oleh: cron/isolir_cron check_overdue (harian 02:00)
     *
     * Logic:
     *   1. Ambil grace_days dari settings (default 3)
     *   2. Query invoice unpaid yang due_date < today - grace_days
     *   3. Update status → 'overdue'
     *   4. Return list customer_id untuk proses isolir
     *
     * @return array Customer IDs yang perlu diisolir
     */
    public function check_overdue()
    {
        $grace_days = (int) $this->CI->setting_model
            ->get_value('isolir_grace_days') ?: 3;

        $cutoff_date = date('Y-m-d', strtotime("-{$grace_days} days"));

        custom_log('billing_engine.log',
            "OVERDUE CHECK grace={$grace_days} cutoff={$cutoff_date}");

        // Step 1: Update semua invoice unpaid yang melewati grace period
        $overdue_count = $this->CI->invoice_model->mark_overdue_batch($cutoff_date);

        // Step 2: Ambil customer yang punya invoice overdue DAN masih active
        $customers_to_isolate = $this->CI->invoice_model
            ->get_customers_with_overdue('active');

        custom_log('billing_engine.log',
            "OVERDUE RESULT: {$overdue_count} invoices marked overdue, " .
            count($customers_to_isolate) . " customers to isolate");

        return $customers_to_isolate;
    }

    // ═══════════════════════════════════════════════════════════
    //  C. DUE DATE CALCULATION
    // ═══════════════════════════════════════════════════════════

    /**
     * Hitung due_date berdasarkan billing_date dan period
     *
     * Edge cases yang ditangani:
     * - billing_date=31, bulan April (30 hari) → 30 April
     * - billing_date=29/30/31, bulan Februari → 28 Feb (atau 29 jika kabisat)
     * - billing_date=28, semua bulan → selalu 28 (aman)
     *
     * Karena billing_date sudah di-cap ke 28 saat input customer,
     * edge case di atas jarang terjadi. Tapi fungsi ini tetap handle
     * sebagai safety net.
     *
     * @param  int    $billing_date  Tanggal jatuh tempo (1-28)
     * @param  string $period        "YYYY-MM"
     * @return string Date "YYYY-MM-DD"
     */
    public function calculate_due_date($billing_date, $period)
    {
        // Parse period
        list($year, $month) = explode('-', $period);
        $year  = (int) $year;
        $month = (int) $month;

        // Cari hari terakhir bulan tersebut
        $last_day = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

        // Cap billing_date ke hari terakhir bulan jika melebihi
        $actual_day = min($billing_date, $last_day);

        return sprintf('%04d-%02d-%02d', $year, $month, $actual_day);
    }

    // ═══════════════════════════════════════════════════════════
    //  D. CANCEL INVOICE
    // ═══════════════════════════════════════════════════════════

    /**
     * Cancel invoice — BUKAN delete
     *
     * Dipanggil saat:
     * - Pelanggan terminate di tengah bulan
     * - Koreksi admin (invoice salah)
     *
     * Invoice cancelled:
     * - Tetap di database (tidak dihapus)
     * - Tidak masuk revenue
     * - Tidak masuk perhitungan overdue
     * - Bisa dilihat di histori
     */
    public function cancel_invoice($invoice_id, $reason, $admin_id)
    {
        $invoice = $this->CI->invoice_model->get($invoice_id);

        if (!$invoice) {
            throw new Exception("Invoice #{$invoice_id} tidak ditemukan");
        }

        if ($invoice->status === 'paid') {
            throw new Exception(
                "Invoice {$invoice->invoice_number} sudah LUNAS. " .
                "Tidak bisa dibatalkan. Gunakan refund jika perlu."
            );
        }

        if ($invoice->status === 'cancelled') {
            throw new Exception("Invoice sudah dibatalkan sebelumnya");
        }

        $this->CI->invoice_model->update($invoice_id, [
            'status'     => 'cancelled',
            'notes'      => "CANCELLED: {$reason}",
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->CI->activity_logger->log(
            'cancel_invoice',
            $invoice->customer_id,
            ['invoice_number' => $invoice->invoice_number, 'reason' => $reason],
            ['status' => $invoice->status]
        );

        custom_log('billing_engine.log',
            "INVOICE CANCELLED {$invoice->invoice_number} reason={$reason}");

        return true;
    }

    // ═══════════════════════════════════════════════════════════
    //  E. PRORATED BILLING (Pelanggan Baru Tengah Bulan)
    // ═══════════════════════════════════════════════════════════

    /**
     * Hitung tagihan prorata untuk pelanggan baru
     *
     * Contoh:
     *   Pasang tanggal 20, paket Rp 140.000/bulan
     *   Sisa hari bulan ini: 11 hari (dari 31)
     *   Prorata: 140.000 × (11/31) = Rp 49.677
     *
     * @param  float  $monthly_price   Harga bulanan paket
     * @param  string $install_date    Format 'Y-m-d'
     * @return array  ['amount' => float, 'days_remaining' => int, 'days_in_month' => int]
     */
    public function calculate_prorata($monthly_price, $install_date)
    {
        $install_day    = (int) date('j', strtotime($install_date));
        $days_in_month  = (int) date('t', strtotime($install_date));
        $days_remaining = $days_in_month - $install_day + 1; // termasuk hari pasang

        // Jika pasang di tanggal 1, full price
        if ($install_day === 1) {
            return [
                'amount'         => $monthly_price,
                'days_remaining' => $days_in_month,
                'days_in_month'  => $days_in_month,
                'is_prorata'     => false,
            ];
        }

        $prorata_amount = round(
            $monthly_price * ($days_remaining / $days_in_month),
            0 // bulatkan ke rupiah
        );

        return [
            'amount'         => $prorata_amount,
            'days_remaining' => $days_remaining,
            'days_in_month'  => $days_in_month,
            'is_prorata'     => true,
        ];
    }
}