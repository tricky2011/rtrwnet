<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PSEUDO Cron controller untuk billing harian.
 * Jalankan via CLI:
 *   php index.php cron/invoice_cron generate
 *   php index.php cron/invoice_cron check_overdue
 */
class Invoice_cron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();

        // Hard requirement: cron hanya via CLI.
        if (!$this->input->is_cli_request()) {
            show_error('Forbidden', 403);
        }

        $this->load->library('billing_engine');
        $this->load->model('system_monitoring_model');
    }

    /**
     * PSEUDO: Generate invoice harian berdasarkan rolling billing_date.
     *
     * Anti duplicate:
     * - Billing_engine cek exists(customer_id, period)
     * - INSERT IGNORE + UNIQUE KEY(customer_id, period_month)
     */
    public function generate()
    {
        $startedAt = date('Y-m-d H:i:s');
        $started = microtime(true);

        try {
            $stats = $this->billing_engine->generate_daily();
            $took_ms = (int) ((microtime(true) - $started) * 1000);
            $finishedAt = date('Y-m-d H:i:s');

            $this->system_monitoring_model->record_cron_run(
                'invoice_cron.generate',
                'success',
                $startedAt,
                $finishedAt,
                'Daily invoice generation done',
                ['stats' => $stats, 'duration_ms' => $took_ms]
            );

            log_message('info', '[Invoice_cron::generate] ' . json_encode([
                'stats' => $stats,
                'duration_ms' => $took_ms,
            ]));

            echo json_encode([
                'success' => true,
                'duration_ms' => $took_ms,
                'stats' => $stats,
            ], JSON_PRETTY_PRINT) . PHP_EOL;
        } catch (Exception $e) {
            $finishedAt = date('Y-m-d H:i:s');
            $this->system_monitoring_model->record_cron_run(
                'invoice_cron.generate',
                'error',
                $startedAt,
                $finishedAt,
                $e->getMessage()
            );

            log_message('error', '[Invoice_cron::generate] ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT) . PHP_EOL;
        }
    }

    /**
     * PSEUDO: Update invoice UNPAID menjadi OVERDUE jika lewat grace period.
     */
    public function check_overdue()
    {
        $startedAt = date('Y-m-d H:i:s');
        $started = microtime(true);

        try {
            $rows = $this->billing_engine->check_overdue();
            $took_ms = (int) ((microtime(true) - $started) * 1000);
            $finishedAt = date('Y-m-d H:i:s');

            $this->system_monitoring_model->record_cron_run(
                'invoice_cron.check_overdue',
                'success',
                $startedAt,
                $finishedAt,
                'Overdue check done',
                ['customers_to_isolate' => count($rows), 'duration_ms' => $took_ms]
            );

            echo json_encode([
                'success' => true,
                'customers_to_isolate' => count($rows),
                'data' => $rows,
            ], JSON_PRETTY_PRINT) . PHP_EOL;
        } catch (Exception $e) {
            $finishedAt = date('Y-m-d H:i:s');
            $this->system_monitoring_model->record_cron_run(
                'invoice_cron.check_overdue',
                'error',
                $startedAt,
                $finishedAt,
                $e->getMessage()
            );

            log_message('error', '[Invoice_cron::check_overdue] ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT) . PHP_EOL;
        }
    }
}
