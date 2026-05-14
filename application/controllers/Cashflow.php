<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Cashflow backend controller.
 * Tanpa UI, fokus API-like response + export.
 */
class Cashflow extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->model('cashflow_model');
    }

    /**
     * UI list page (tanpa mengganggu endpoint backend existing).
     */
    public function index()
    {
        $this->load->view('cashflow/list');
    }

    /**
     * Input expense manual oleh admin.
     * POST:
     * - category
     * - description
     * - amount
     * - transaction_date (optional, YYYY-MM-DD)
     * - recorded_by (optional)
     */
    public function add_expense()
    {
        $category = trim((string) $this->input->post('category'));
        $description = trim((string) $this->input->post('description'));
        $amount = (float) $this->input->post('amount');
        $transaction_date = trim((string) $this->input->post('transaction_date'));
        $recorded_by = (int) $this->input->post('recorded_by');

        if ($category === '' || $description === '' || $amount <= 0) {
            return $this->json_response([
                'success' => false,
                'message' => 'category, description, amount wajib valid',
            ], 422);
        }

        if ($transaction_date === '') {
            $transaction_date = date('Y-m-d');
        }
        if (!$this->is_valid_date($transaction_date)) {
            return $this->json_response([
                'success' => false,
                'message' => 'Format transaction_date harus YYYY-MM-DD',
            ], 422);
        }

        try {
            $id = $this->cashflow_model->create_expense([
                'category' => $category,
                'description' => $description,
                'amount' => $amount,
                'transaction_date' => $transaction_date,
                'recorded_by' => $recorded_by > 0 ? $recorded_by : null,
            ]);

            return $this->json_response([
                'success' => true,
                'cashflow_id' => $id,
                'message' => 'Expense berhasil disimpan',
            ]);
        } catch (Exception $e) {
            log_message('error', '[Cashflow::add_expense] ' . $e->getMessage());
            return $this->json_response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Laporan bulanan cashflow.
     * GET: period=YYYY-MM (optional, default bulan ini)
     */
    public function monthly_report()
    {
        $period = trim((string) $this->input->get('period', true));
        if (!$this->is_valid_period($period)) {
            $period = date('Y-m');
        }

        try {
            $report = $this->cashflow_model->get_monthly_report($period);
            return $this->json_response([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Exception $e) {
            log_message('error', '[Cashflow::monthly_report] ' . $e->getMessage());
            return $this->json_response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Contoh export Excel laporan bulanan.
     * GET: period=YYYY-MM
     *
     * Jika PhpSpreadsheet belum tersedia, fallback CSV agar tetap bisa di-export.
     */
    public function export_excel()
    {
        $period = trim((string) $this->input->get('period', true));
        if (!$this->is_valid_period($period)) {
            $period = date('Y-m');
        }

        $report = $this->cashflow_model->get_monthly_report($period);

        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->export_xlsx($period, $report);
            return;
        }

        $this->export_csv_fallback($period, $report);
    }

    private function export_xlsx($period, array $report)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cashflow');

        $sheet->setCellValue('A1', 'Period');
        $sheet->setCellValue('B1', $period);
        $sheet->setCellValue('A2', 'Total Income');
        $sheet->setCellValue('B2', $report['summary']['total_income']);
        $sheet->setCellValue('A3', 'Total Expense');
        $sheet->setCellValue('B3', $report['summary']['total_expense']);
        $sheet->setCellValue('A4', 'Net Profit');
        $sheet->setCellValue('B4', $report['summary']['net_profit']);

        $sheet->setCellValue('A6', 'Date');
        $sheet->setCellValue('B6', 'Type');
        $sheet->setCellValue('C6', 'Category');
        $sheet->setCellValue('D6', 'Description');
        $sheet->setCellValue('E6', 'Amount');

        $rowNum = 7;
        foreach ($report['transactions'] as $row) {
            $sheet->setCellValue('A' . $rowNum, $row['transaction_date']);
            $sheet->setCellValue('B' . $rowNum, $row['type']);
            $sheet->setCellValue('C' . $rowNum, $row['category']);
            $sheet->setCellValue('D' . $rowNum, $row['description']);
            $sheet->setCellValue('E' . $rowNum, (float) $row['amount']);
            $rowNum++;
        }

        $filename = 'cashflow_' . $period . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    private function export_csv_fallback($period, array $report)
    {
        $filename = 'cashflow_' . $period . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Period', $period]);
        fputcsv($out, ['Total Income', $report['summary']['total_income']]);
        fputcsv($out, ['Total Expense', $report['summary']['total_expense']]);
        fputcsv($out, ['Net Profit', $report['summary']['net_profit']]);
        fputcsv($out, []);
        fputcsv($out, ['Date', 'Type', 'Category', 'Description', 'Amount']);

        foreach ($report['transactions'] as $row) {
            fputcsv($out, [
                $row['transaction_date'],
                $row['type'],
                $row['category'],
                $row['description'],
                $row['amount'],
            ]);
        }

        fclose($out);
        exit;
    }

    private function is_valid_date($date)
    {
        return is_string($date)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && strtotime($date) !== false;
    }

    private function is_valid_period($period)
    {
        return is_string($period)
            && preg_match('/^\d{4}-\d{2}$/', $period);
    }

    private function json_response(array $payload, $status_code = 200)
    {
        return $this->output
            ->set_content_type('application/json')
            ->set_status_header((int) $status_code)
            ->set_output(json_encode($payload));
    }
}
