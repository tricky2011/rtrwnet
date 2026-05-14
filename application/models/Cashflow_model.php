<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Cashflow model (income + expense).
 * Income dicatat otomatis saat invoice paid (dari Payment_processor).
 * Expense dicatat manual oleh admin.
 */
class Cashflow_model extends CI_Model
{
    protected $table = 'cashflows';

    /**
     * Insert cashflow generic.
     * Dipakai oleh Payment_processor untuk income otomatis.
     */
    public function insert(array $data)
    {
        $payload = [
            'type' => isset($data['type']) ? $data['type'] : 'income',
            'category' => isset($data['category']) ? $data['category'] : 'other',
            'description' => isset($data['description']) ? $data['description'] : '',
            'amount' => isset($data['amount']) ? (float) $data['amount'] : 0,
            'transaction_date' => isset($data['transaction_date']) ? $data['transaction_date'] : date('Y-m-d'),
            'payment_id' => isset($data['payment_id']) ? (int) $data['payment_id'] : null,
            'reference_type' => isset($data['reference_type']) ? $data['reference_type'] : null,
            'reference_id' => isset($data['reference_id']) ? (int) $data['reference_id'] : null,
            'recorded_by' => isset($data['recorded_by']) ? (int) $data['recorded_by'] : null,
            'created_at' => isset($data['created_at']) ? $data['created_at'] : date('Y-m-d H:i:s'),
            'updated_at' => isset($data['updated_at']) ? $data['updated_at'] : date('Y-m-d H:i:s'),
        ];

        if ($payload['amount'] <= 0) {
            throw new InvalidArgumentException('Amount harus lebih dari 0');
        }

        $this->db->insert($this->table, $payload);
        return (int) $this->db->insert_id();
    }

    /**
     * Expense manual oleh admin.
     */
    public function create_expense(array $data)
    {
        $payload = [
            'type' => 'expense',
            'category' => isset($data['category']) ? trim((string) $data['category']) : 'operational',
            'description' => isset($data['description']) ? trim((string) $data['description']) : '',
            'amount' => isset($data['amount']) ? (float) $data['amount'] : 0,
            'transaction_date' => isset($data['transaction_date']) ? $data['transaction_date'] : date('Y-m-d'),
            'payment_id' => null,
            'reference_type' => isset($data['reference_type']) ? $data['reference_type'] : 'manual_expense',
            'reference_id' => isset($data['reference_id']) ? (int) $data['reference_id'] : null,
            'recorded_by' => isset($data['recorded_by']) ? (int) $data['recorded_by'] : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($payload['description'] === '') {
            throw new InvalidArgumentException('Description wajib diisi');
        }
        if ($payload['amount'] <= 0) {
            throw new InvalidArgumentException('Amount expense harus lebih dari 0');
        }

        $this->db->insert($this->table, $payload);
        return (int) $this->db->insert_id();
    }

    /**
     * Laporan bulanan:
     * - total income
     * - total expense
     * - net profit
     * - breakdown harian
     * - breakdown kategori
     */
    public function get_monthly_report($period)
    {
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $period)) {
            throw new InvalidArgumentException('Format period harus YYYY-MM');
        }

        $start = $period . '-01';
        $end = date('Y-m-t', strtotime($start));

        $summary = $this->get_summary_between($start, $end);
        $daily = $this->get_daily_breakdown($start, $end);
        $income_categories = $this->get_category_breakdown($start, $end, 'income');
        $expense_categories = $this->get_category_breakdown($start, $end, 'expense');
        $transactions = $this->get_transactions_between($start, $end);

        return [
            'period' => $period,
            'start_date' => $start,
            'end_date' => $end,
            'summary' => $summary,
            'daily' => $daily,
            'income_by_category' => $income_categories,
            'expense_by_category' => $expense_categories,
            'transactions' => $transactions,
        ];
    }

    public function get_summary_between($start_date, $end_date)
    {
        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense
            FROM {$this->table}
            WHERE transaction_date BETWEEN ? AND ?
        ";

        $row = $this->db->query($sql, [$start_date, $end_date])->row_array();
        $income = (float) $row['total_income'];
        $expense = (float) $row['total_expense'];

        return [
            'total_income' => round($income, 2),
            'total_expense' => round($expense, 2),
            'net_profit' => round($income - $expense, 2),
        ];
    }

    public function get_daily_breakdown($start_date, $end_date)
    {
        $sql = "
            SELECT
                transaction_date,
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense
            FROM {$this->table}
            WHERE transaction_date BETWEEN ? AND ?
            GROUP BY transaction_date
            ORDER BY transaction_date ASC
        ";

        $rows = $this->db->query($sql, [$start_date, $end_date])->result_array();
        foreach ($rows as &$row) {
            $income = (float) $row['income'];
            $expense = (float) $row['expense'];
            $row['income'] = round($income, 2);
            $row['expense'] = round($expense, 2);
            $row['net_profit'] = round($income - $expense, 2);
        }

        return $rows;
    }

    public function get_category_breakdown($start_date, $end_date, $type)
    {
        $sql = "
            SELECT
                category,
                SUM(amount) AS total,
                COUNT(*) AS total_txn
            FROM {$this->table}
            WHERE transaction_date BETWEEN ? AND ?
              AND type = ?
            GROUP BY category
            ORDER BY total DESC
        ";

        $rows = $this->db->query($sql, [$start_date, $end_date, $type])->result_array();
        foreach ($rows as &$row) {
            $row['total'] = (float) $row['total'];
            $row['total_txn'] = (int) $row['total_txn'];
        }

        return $rows;
    }

    public function get_transactions_between($start_date, $end_date)
    {
        return $this->db
            ->from($this->table)
            ->where('transaction_date >=', $start_date)
            ->where('transaction_date <=', $end_date)
            ->order_by('transaction_date', 'asc')
            ->order_by('id', 'asc')
            ->get()
            ->result_array();
    }
}
