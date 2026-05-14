<?php
/**
 * application/libraries/Payment_processor.php
 *
 * Khusus menangani pembayaran invoice.
 * Terpisah dari Billing_engine agar Single Responsibility.
 *
 * ALUR:
 * 1. Validasi invoice (ada, belum lunas, belum cancel)
 * 2. Insert payment
 * 3. Update invoice paid_amount
 * 4. Jika lunas: set paid_date, status = paid
 * 5. Insert cashflow income (OTOMATIS)
 * 6. Jika customer isolated + semua lunas: trigger restore
 *
 * SEMUA dalam 1 DB transaction.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Payment_processor
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model([
            'invoice_model', 'payment_model',
            'cashflow_model', 'customer_model',
        ]);
        $this->CI->load->library([
            'code_generator', 'activity_logger', 'isolir_engine',
        ]);
        $this->CI->load->helper('billing');
    }

    /**
     * Proses pembayaran invoice
     *
     * @param  int    $invoice_id      ID invoice
     * @param  float  $amount          Nominal bayar
     * @param  string $method          'cash'|'transfer'|'qris'|'auto_debit'
     * @param  int    $admin_id        ID admin penerima
     * @param  string $notes           Catatan opsional
     * @param  string $receipt_number  Nomor kwitansi manual
     * @return array  Hasil proses
     */
    public function process($invoice_id, $amount, $method,
                            $admin_id, $notes = '', $receipt_number = null)
    {
        // ── STEP 0: VALIDASI ──
        $invoice = $this->CI->invoice_model->get_with_customer($invoice_id);

        if (!$invoice) {
            return $this->fail('Invoice tidak ditemukan');
        }

        if ($invoice->status === 'paid') {
            return $this->fail(
                "Invoice {$invoice->invoice_number} sudah LUNAS pada " .
                tanggal_indo($invoice->paid_date)
            );
        }

        if ($invoice->status === 'cancelled') {
            return $this->fail(
                "Invoice {$invoice->invoice_number} sudah dibatalkan"
            );
        }

        // Validasi nominal
        $amount = (float) $amount;
        if ($amount <= 0) {
            return $this->fail('Nominal pembayaran harus lebih dari 0');
        }

        $remaining = $invoice->amount - $invoice->paid_amount;
        if ($amount > $remaining) {
            return $this->fail(
                "Nominal melebihi sisa tagihan. " .
                "Sisa: " . rupiah($remaining)
            );
        }

        // ── STEP 1: BEGIN TRANSACTION ──
        $this->CI->db->trans_begin();

        try {
            // ── STEP 2: INSERT PAYMENT ──
            $payment_code = $this->CI->code_generator->next_payment();
            $payment_date = date('Y-m-d H:i:s');

            $payment_id = $this->CI->payment_model->insert([
                'payment_code'   => $payment_code,
                'invoice_id'     => $invoice_id,
                'customer_id'    => $invoice->customer_id,
                'amount'         => $amount,
                'payment_method' => $method,
                'payment_date'   => $payment_date,
                'received_by'    => $admin_id,
                'receipt_number' => $receipt_number,
                'notes'          => $notes,
                'created_at'     => $payment_date,
            ]);

            // ── STEP 3: UPDATE INVOICE ──
            $new_paid_total = $invoice->paid_amount + $amount;
            $is_fully_paid  = ($new_paid_total >= $invoice->amount);

            $invoice_update = [
                'paid_amount' => $new_paid_total,
                'updated_at'  => $payment_date,
            ];

            if ($is_fully_paid) {
                $invoice_update['status']    = 'paid';
                $invoice_update['paid_date'] = $payment_date;
            }
            // Jika belum lunas: status tetap (unpaid/overdue)

            $this->CI->invoice_model->update($invoice_id, $invoice_update);

            // ── STEP 4: INSERT CASHFLOW INCOME ──
            //
            // UNIQUE KEY (payment_id) di tabel cashflows
            // menjamin 1 payment = 1 cashflow entry.
            // Jika process() dijalankan ulang (retry), INSERT ini
            // akan gagal di constraint → tidak double income.
            //
            $this->CI->cashflow_model->insert([
                'type'             => 'income',
                'category'         => 'subscription',
                'description'      => "Pembayaran {$invoice->invoice_number} " .
                                     "- {$invoice->customer_name}",
                'amount'           => $amount,
                'transaction_date' => date('Y-m-d'),
                'payment_id'       => $payment_id,
                'reference_type'   => 'invoice',
                'reference_id'     => $invoice_id,
                'recorded_by'      => $admin_id,
                'created_at'       => $payment_date,
            ]);

            // ── STEP 5: ACTIVITY LOG ──
            $this->CI->activity_logger->log(
                'payment_received',
                $invoice->customer_id,
                [
                    'payment_code' => $payment_code,
                    'amount'       => $amount,
                    'method'       => $method,
                    'invoice'      => $invoice->invoice_number,
                    'fully_paid'   => $is_fully_paid,
                ]
            );

            // ── COMMIT ──
            if ($this->CI->db->trans_status() === FALSE) {
                throw new Exception('Database transaction failed');
            }
            $this->CI->db->trans_commit();

        } catch (Exception $e) {
            $this->CI->db->trans_rollback();
            custom_log('billing_engine.log',
                "PAYMENT ERROR inv={$invoice->invoice_number}: " .
                $e->getMessage());
            return $this->fail('Gagal memproses pembayaran: ' . $e->getMessage());
        }

        // ── POST-COMMIT: RESTORE ISOLIR (boleh gagal tanpa rollback) ──
        $restored = false;
        if ($is_fully_paid && $invoice->customer_status === 'isolated') {
            try {
                $restore_result = $this->CI->isolir_engine
                    ->remove_from_isolir_list($invoice->customer_id, $admin_id);
                $restored = $restore_result['success'];
            } catch (Exception $e) {
                // Log error tapi payment TETAP sukses
                custom_log('billing_engine.log',
                    "RESTORE FAILED after payment " .
                    "{$invoice->customer_code}: " . $e->getMessage());
            }
        }

        custom_log('billing_engine.log',
            "PAYMENT OK {$payment_code} inv={$invoice->invoice_number} " .
            "amount={$amount} fully_paid=" . ($is_fully_paid ? 'YES' : 'NO') .
            " restored=" . ($restored ? 'YES' : 'NO'));

        return [
            'success'      => true,
            'payment_code' => $payment_code,
            'payment_id'   => $payment_id,
            'amount'       => $amount,
            'remaining'    => max(0, $remaining - $amount),
            'fully_paid'   => $is_fully_paid,
            'restored'     => $restored,
            'message'      => $is_fully_paid
                ? "Invoice {$invoice->invoice_number} LUNAS"
                : "Pembayaran parsial dicatat. Sisa: " .
                  rupiah($remaining - $amount),
        ];
    }

    /**
     * Pembayaran cepat — bayar langsung lunas
     * Shortcut untuk kasus umum: pelanggan bayar full
     */
    public function pay_full($invoice_id, $method, $admin_id, $notes = '')
    {
        $invoice = $this->CI->invoice_model->get($invoice_id);
        if (!$invoice) {
            return $this->fail('Invoice tidak ditemukan');
        }

        $remaining = $invoice->amount - $invoice->paid_amount;
        return $this->process($invoice_id, $remaining, $method, $admin_id, $notes);
    }

    /**
     * Bayar semua tunggakan sekaligus
     */
    public function pay_all_outstanding($customer_id, $method, $admin_id, $notes = '')
    {
        $unpaid = $this->CI->invoice_model->get_unpaid_by_customer($customer_id);
        $results = [];

        foreach ($unpaid as $invoice) {
            $remaining = $invoice->amount - $invoice->paid_amount;
            $results[] = $this->process(
                $invoice->id, $remaining, $method, $admin_id,
                $notes ?: "Bayar semua tunggakan"
            );
        }

        return $results;
    }

    /**
     * Helper: return error
     */
    private function fail($message)
    {
        return ['success' => false, 'message' => $message];
    }
}