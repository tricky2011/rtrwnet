<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Cashflow_ui extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->database();
        $this->load->helper(array('url', 'form'));
    }

    public function index()
    {
        $search = trim((string) $this->input->get('search', true));
        $type_filter = $this->normalize_type_filter((string) $this->input->get('type', true));
        $period_filter = $this->normalize_period_filter((string) $this->input->get('period', true));
        $range = $this->resolve_period_range($period_filter);

        if ($this->db->table_exists('cashflow_transactions')) {
            $total_rows = $this->build_list_query($search, $type_filter, $range['start_date'], $range['end_date'], true)
                ->count_all_results();

            $pager = $this->init_pagination('cashflow', $total_rows, 20, 3);
            $rows = $this->build_list_query($search, $type_filter, $range['start_date'], $range['end_date'], false)
                ->limit($pager['per_page'], $pager['offset'])
                ->get()
                ->result_array();
            $txn_ids = array_values(array_filter(array_map(static function ($row) {
                return (int) ($row['id'] ?? 0);
            }, $rows), static function ($id) {
                return $id > 0;
            }));

            return $this->load->view('cashflow/list', array(
                'rows' => $rows,
                'search' => $search,
                'type_filter' => $type_filter,
                'type_options' => $this->type_filter_options(),
                'period_filter' => $period_filter,
                'period_range' => $range,
                'summary' => $this->build_summary($range['start_date'], $range['end_date']),
                'chart_data' => $this->build_chart_data($range['end_date']),
                'category_breakdown' => $this->build_category_breakdown($range['start_date'], $range['end_date']),
                'income_categories' => $this->get_income_category_options(),
                'expense_categories' => $this->get_expense_category_options(),
                'pagination' => $pager['links'],
                'total_rows' => $pager['total_rows'],
                'per_page' => (int) $pager['per_page'],
                'per_page_options' => $this->get_per_page_options(),
                'role' => (string) $this->session->userdata('role'),
                'pending_request_map' => $this->get_pending_request_action_map($txn_ids),
                'pending_requests' => $this->is_superadmin() ? $this->get_pending_change_requests(50) : array(),
            ));
        }

        $rows = $this->fallback_rows();
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static function ($row) use ($search) {
                $needle = strtolower($search);
                return strpos(strtolower((string) ($row['description'] ?? '')), $needle) !== false
                    || strpos(strtolower((string) ($row['type'] ?? '')), $needle) !== false
                    || strpos(strtolower((string) ($row['txn_number'] ?? '')), $needle) !== false;
            }));
        }

        if ($type_filter !== '') {
            $rows = array_values(array_filter($rows, static function ($row) use ($type_filter) {
                return strtolower((string) ($row['type'] ?? '')) === $type_filter;
            }));
        }

        $pager = $this->init_pagination('cashflow', count($rows), 20, 3);
        $paged_rows = array_slice($rows, $pager['offset'], $pager['per_page']);

        return $this->load->view('cashflow/list', array(
            'rows' => $paged_rows,
            'search' => $search,
            'type_filter' => $type_filter,
            'type_options' => $this->type_filter_options(),
            'period_filter' => $period_filter,
            'period_range' => $range,
            'summary' => $this->fallback_summary(),
            'chart_data' => $this->fallback_chart_data(),
            'category_breakdown' => $this->fallback_category_breakdown(),
            'income_categories' => $this->get_income_category_options(),
            'expense_categories' => $this->get_expense_category_options(),
            'pagination' => $pager['links'],
            'total_rows' => $pager['total_rows'],
            'per_page' => (int) $pager['per_page'],
            'per_page_options' => $this->get_per_page_options(),
            'role' => (string) $this->session->userdata('role'),
            'pending_request_map' => array(),
            'pending_requests' => array(),
        ));
    }

    public function add_income()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->db->table_exists('cashflow_transactions')) {
            $this->session->set_flashdata('cashflow_form_modal', 'income');
            $this->session->set_flashdata('error', 'Tabel cashflow_transactions tidak tersedia.');
            redirect('cashflow');
            return;
        }

        $date_raw = trim((string) $this->input->post('txn_date', true));
        $description = trim((string) $this->input->post('description', true));
        $amount_raw = trim((string) $this->input->post('amount', true));
        $category_input = trim((string) $this->input->post('category', true));

        $txn_date = $date_raw !== '' ? $date_raw : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txn_date) || strtotime($txn_date) === false) {
            $this->session->set_flashdata('cashflow_form_modal', 'income');
            $this->session->set_flashdata('error', 'Tanggal transaksi tidak valid.');
            redirect('cashflow');
            return;
        }

        if ($description === '') {
            $this->session->set_flashdata('cashflow_form_modal', 'income');
            $this->session->set_flashdata('error', 'Deskripsi pemasukan wajib diisi.');
            redirect('cashflow');
            return;
        }

        $amount_clean = str_replace(array('.', ','), '', $amount_raw);
        if ($amount_clean === '' || !is_numeric($amount_clean)) {
            $this->session->set_flashdata('cashflow_form_modal', 'income');
            $this->session->set_flashdata('error', 'Nominal pemasukan harus angka.');
            redirect('cashflow');
            return;
        }

        $amount = (float) $amount_clean;
        if ($amount <= 0) {
            $this->session->set_flashdata('cashflow_form_modal', 'income');
            $this->session->set_flashdata('error', 'Nominal pemasukan harus lebih dari 0.');
            redirect('cashflow');
            return;
        }

        $now = date('Y-m-d H:i:s');
        $payload = array(
            'type' => 'income',
            'amount' => $amount,
            'description' => $description,
        );

        if ($this->table_has_column('cashflow_transactions', 'txn_number')) {
            $payload['txn_number'] = $this->next_cashflow_txn_number($now);
        }

        if ($this->table_has_column('cashflow_transactions', 'txn_date')) {
            $payload['txn_date'] = $txn_date . ' ' . date('H:i:s');
        } elseif ($this->table_has_column('cashflow_transactions', 'transaction_date')) {
            $payload['transaction_date'] = $txn_date;
        } elseif ($this->table_has_column('cashflow_transactions', 'date')) {
            $payload['date'] = $txn_date;
        }

        $category_id = 0;
        if ($this->table_has_column('cashflow_transactions', 'category_id')) {
            $category_id = $this->ensure_income_category_id($category_input);
            if ($category_id <= 0) {
                $this->session->set_flashdata('cashflow_form_modal', 'income');
                $this->session->set_flashdata('error', 'Kategori pemasukan tidak valid. Pastikan master kategori income tersedia.');
                redirect('cashflow');
                return;
            }
            $payload['category_id'] = $category_id;
        } elseif (ctype_digit($category_input)) {
            $category_id = (int) $category_input;
        }

        $category_name = $this->resolve_category_name($category_id, $category_input, 'Subscription');
        if ($this->table_has_column('cashflow_transactions', 'category')) {
            $payload['category'] = $category_name;
        }

        if ($this->table_has_column('cashflow_transactions', 'created_by')) {
            $payload['created_by'] = (int) $this->session->userdata('user_id');
        } elseif ($this->table_has_column('cashflow_transactions', 'recorded_by')) {
            $payload['recorded_by'] = (int) $this->session->userdata('user_id');
        }

        if ($this->table_has_column('cashflow_transactions', 'reference_type')) {
            $payload['reference_type'] = 'manual_income';
        }

        if ($this->table_has_column('cashflow_transactions', 'created_at')) {
            $payload['created_at'] = $now;
        }
        if ($this->table_has_column('cashflow_transactions', 'updated_at')) {
            $payload['updated_at'] = $now;
        }
        $this->apply_cashflow_router_scope_payload($payload);

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('cashflow_transactions', $payload);
        $error = $this->db->error();
        $this->db->db_debug = $old_debug;

        if (!$ok) {
            log_message('error', '[CASHFLOW_UI][ADD_INCOME] insert failed: ' . json_encode($error) . ' payload=' . json_encode($payload));
            $this->session->set_flashdata('cashflow_form_modal', 'income');
            $this->session->set_flashdata('error', 'Gagal simpan pemasukan: ' . (string) ($error['message'] ?? 'unknown'));
            redirect('cashflow');
            return;
        }

        $this->session->set_flashdata('success', 'Pemasukan berhasil disimpan.');
        redirect('cashflow');
    }

    public function add_expense()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->db->table_exists('cashflow_transactions')) {
            $this->session->set_flashdata('cashflow_form_modal', 'expense');
            $this->session->set_flashdata('error', 'Tabel cashflow_transactions tidak tersedia.');
            redirect('cashflow');
            return;
        }

        $date_raw = trim((string) $this->input->post('txn_date', true));
        $description = trim((string) $this->input->post('description', true));
        $amount_raw = trim((string) $this->input->post('amount', true));
        $category_input = trim((string) $this->input->post('category', true));

        $txn_date = $date_raw !== '' ? $date_raw : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txn_date) || strtotime($txn_date) === false) {
            $this->session->set_flashdata('cashflow_form_modal', 'expense');
            $this->session->set_flashdata('error', 'Tanggal transaksi tidak valid.');
            redirect('cashflow');
            return;
        }

        if ($description === '') {
            $this->session->set_flashdata('cashflow_form_modal', 'expense');
            $this->session->set_flashdata('error', 'Deskripsi pengeluaran wajib diisi.');
            redirect('cashflow');
            return;
        }

        $amount_clean = str_replace(array('.', ','), '', $amount_raw);
        if ($amount_clean === '' || !is_numeric($amount_clean)) {
            $this->session->set_flashdata('cashflow_form_modal', 'expense');
            $this->session->set_flashdata('error', 'Nominal pengeluaran harus angka.');
            redirect('cashflow');
            return;
        }

        $amount = (float) $amount_clean;
        if ($amount <= 0) {
            $this->session->set_flashdata('cashflow_form_modal', 'expense');
            $this->session->set_flashdata('error', 'Nominal pengeluaran harus lebih dari 0.');
            redirect('cashflow');
            return;
        }

        $now = date('Y-m-d H:i:s');
        $payload = array(
            'type' => 'expense',
            'amount' => $amount,
            'description' => $description,
        );

        if ($this->table_has_column('cashflow_transactions', 'txn_number')) {
            $payload['txn_number'] = $this->next_cashflow_txn_number($now);
        }

        if ($this->table_has_column('cashflow_transactions', 'txn_date')) {
            $payload['txn_date'] = $txn_date . ' ' . date('H:i:s');
        } elseif ($this->table_has_column('cashflow_transactions', 'transaction_date')) {
            $payload['transaction_date'] = $txn_date;
        } elseif ($this->table_has_column('cashflow_transactions', 'date')) {
            $payload['date'] = $txn_date;
        }

        $category_id = 0;
        if ($this->table_has_column('cashflow_transactions', 'category_id')) {
            $category_id = $this->ensure_expense_category_id($category_input);
            if ($category_id <= 0) {
                $this->session->set_flashdata('cashflow_form_modal', 'expense');
                $this->session->set_flashdata('error', 'Kategori pengeluaran tidak valid. Pastikan master kategori expense tersedia.');
                redirect('cashflow');
                return;
            }
            $payload['category_id'] = $category_id;
        } elseif (ctype_digit($category_input)) {
            $category_id = (int) $category_input;
        }

        $category_name = $this->resolve_category_name($category_id, $category_input, 'Operational');

        if ($this->table_has_column('cashflow_transactions', 'category')) {
            $payload['category'] = $category_name;
        }

        if ($this->table_has_column('cashflow_transactions', 'created_by')) {
            $payload['created_by'] = (int) $this->session->userdata('user_id');
        } elseif ($this->table_has_column('cashflow_transactions', 'recorded_by')) {
            $payload['recorded_by'] = (int) $this->session->userdata('user_id');
        }

        if ($this->table_has_column('cashflow_transactions', 'reference_type')) {
            $payload['reference_type'] = 'manual_expense';
        }

        if ($this->table_has_column('cashflow_transactions', 'created_at')) {
            $payload['created_at'] = $now;
        }
        if ($this->table_has_column('cashflow_transactions', 'updated_at')) {
            $payload['updated_at'] = $now;
        }
        $this->apply_cashflow_router_scope_payload($payload);

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('cashflow_transactions', $payload);
        $error = $this->db->error();
        $this->db->db_debug = $old_debug;

        if (!$ok) {
            log_message('error', '[CASHFLOW_UI][ADD_EXPENSE] insert failed: ' . json_encode($error) . ' payload=' . json_encode($payload));
            $this->session->set_flashdata('cashflow_form_modal', 'expense');
            $this->session->set_flashdata('error', 'Gagal simpan pengeluaran: ' . (string) ($error['message'] ?? 'unknown'));
            redirect('cashflow');
            return;
        }

        $this->session->set_flashdata('success', 'Pengeluaran berhasil disimpan.');
        redirect('cashflow');
    }

    public function update($id)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $txn_id = (int) $id;
        if ($txn_id <= 0 || !$this->db->table_exists('cashflow_transactions')) {
            $this->session->set_flashdata('error', 'Transaksi tidak ditemukan.');
            redirect('cashflow');
            return;
        }

        $txn = $this->get_cashflow_transaction($txn_id);
        if (empty($txn)) {
            $this->session->set_flashdata('error', 'Transaksi tidak ditemukan.');
            redirect('cashflow');
            return;
        }

        $date_raw = trim((string) $this->input->post('txn_date', true));
        $description = trim((string) $this->input->post('description', true));
        $amount_raw = trim((string) $this->input->post('amount', true));
        $category_input = trim((string) $this->input->post('category', true));
        $request_reason = trim((string) $this->input->post('reason', true));

        $txn_date = $date_raw !== '' ? $date_raw : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txn_date) || strtotime($txn_date) === false) {
            $this->session->set_flashdata('error', 'Tanggal transaksi tidak valid.');
            redirect('cashflow');
            return;
        }
        if ($description === '') {
            $this->session->set_flashdata('error', 'Deskripsi transaksi wajib diisi.');
            redirect('cashflow');
            return;
        }

        $amount_clean = str_replace(array('.', ','), '', $amount_raw);
        if ($amount_clean === '' || !is_numeric($amount_clean)) {
            $this->session->set_flashdata('error', 'Nominal transaksi harus angka.');
            redirect('cashflow');
            return;
        }

        $amount = (float) $amount_clean;
        if ($amount <= 0) {
            $this->session->set_flashdata('error', 'Nominal transaksi harus lebih dari 0.');
            redirect('cashflow');
            return;
        }

        $type = strtolower(trim((string) ($txn['type'] ?? '')));
        if (!in_array($type, array('income', 'expense'), true)) {
            $this->session->set_flashdata('error', 'Tipe transaksi tidak valid.');
            redirect('cashflow');
            return;
        }

        $payload = array(
            'description' => $description,
            'amount' => $amount,
        );
        $date_col = $this->resolve_editable_txn_date_column();
        if ($date_col !== '') {
            $payload[$date_col] = $txn_date . ($date_col === 'txn_date' ? ' ' . date('H:i:s') : '');
        }

        $category_id = 0;
        if ($this->table_has_column('cashflow_transactions', 'category_id')) {
            if ($type === 'income') {
                $category_id = $this->ensure_income_category_id($category_input);
            } else {
                $category_id = $this->ensure_expense_category_id($category_input);
            }
            if ($category_id <= 0) {
                $this->session->set_flashdata('error', 'Kategori transaksi tidak valid.');
                redirect('cashflow');
                return;
            }
            $payload['category_id'] = $category_id;
        } elseif (ctype_digit($category_input)) {
            $category_id = (int) $category_input;
        }

        if ($this->table_has_column('cashflow_transactions', 'category')) {
            $fallback_category = $type === 'income' ? 'Subscription' : 'Operational';
            $payload['category'] = $this->resolve_category_name($category_id, $category_input, $fallback_category);
        }
        if ($this->table_has_column('cashflow_transactions', 'updated_at')) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        if ($this->is_superadmin()) {
            $old_debug = $this->db->db_debug;
            $this->db->db_debug = false;
            $ok = $this->db->where('id', $txn_id)->update('cashflow_transactions', $payload);
            $error = $this->db->error();
            $this->db->db_debug = $old_debug;

            if (!$ok) {
                log_message('error', '[CASHFLOW_UI][UPDATE] update failed txn_id=' . $txn_id . ' err=' . json_encode($error));
                $this->session->set_flashdata('error', 'Update transaksi gagal: ' . (string) ($error['message'] ?? 'unknown'));
                redirect('cashflow');
                return;
            }

            $this->session->set_flashdata('success', 'Transaksi berhasil diupdate.');
            redirect('cashflow');
            return;
        }

        if ($this->has_pending_change_request($txn_id)) {
            $this->session->set_flashdata('error', 'Masih ada request approval pending untuk transaksi ini.');
            redirect('cashflow');
            return;
        }

        $create_request = $this->create_cashflow_change_request($txn_id, 'edit', $txn, $payload, $request_reason);
        if (!$create_request['success']) {
            $this->session->set_flashdata('error', (string) $create_request['message']);
            redirect('cashflow');
            return;
        }

        $this->session->set_flashdata('success', 'Permintaan edit transaksi dikirim dan menunggu ACC superadmin.');
        redirect('cashflow');
    }

    public function delete($id)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $txn_id = (int) $id;
        if ($txn_id <= 0 || !$this->db->table_exists('cashflow_transactions')) {
            $this->session->set_flashdata('error', 'Transaksi tidak ditemukan.');
            redirect('cashflow');
            return;
        }

        $txn = $this->get_cashflow_transaction($txn_id);
        if (empty($txn)) {
            $this->session->set_flashdata('error', 'Transaksi tidak ditemukan.');
            redirect('cashflow');
            return;
        }

        if ($this->is_superadmin()) {
            $this->db->trans_begin();
            $this->db->where('id', $txn_id)->delete('cashflow_transactions');
            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'Hapus transaksi gagal.');
                redirect('cashflow');
                return;
            }
            $this->db->trans_commit();
            $this->session->set_flashdata('success', 'Transaksi berhasil dihapus permanen.');
            redirect('cashflow');
            return;
        }

        if ($this->has_pending_change_request($txn_id)) {
            $this->session->set_flashdata('error', 'Masih ada request approval pending untuk transaksi ini.');
            redirect('cashflow');
            return;
        }

        $request_reason = trim((string) $this->input->post('reason', true));
        $create_request = $this->create_cashflow_change_request($txn_id, 'delete', $txn, array(), $request_reason);
        if (!$create_request['success']) {
            $this->session->set_flashdata('error', (string) $create_request['message']);
            redirect('cashflow');
            return;
        }

        if ($this->table_has_column('cashflow_transactions', 'deleted_at')) {
            $soft_payload = array('deleted_at' => date('Y-m-d H:i:s'));
            if ($this->table_has_column('cashflow_transactions', 'updated_at')) {
                $soft_payload['updated_at'] = date('Y-m-d H:i:s');
            }
            if ($this->table_has_column('cashflow_transactions', 'deleted_by')) {
                $soft_payload['deleted_by'] = (int) $this->session->userdata('user_id');
            }
            $this->db->where('id', $txn_id)->update('cashflow_transactions', $soft_payload);
        }

        $this->session->set_flashdata('success', 'Permintaan hapus transaksi dikirim (soft delete) dan menunggu ACC superadmin.');
        redirect('cashflow');
    }

    public function review_request($request_id)
    {
        $this->require_role(array('superadmin'));

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $schema = $this->resolve_change_request_schema();
        if (!$schema['ready']) {
            $this->session->set_flashdata('error', 'Tabel persetujuan cashflow belum tersedia.');
            redirect('cashflow');
            return;
        }

        $decision = strtolower(trim((string) $this->input->post('decision', true)));
        if (!in_array($decision, array('approve', 'reject'), true)) {
            $this->session->set_flashdata('error', 'Keputusan approval tidak valid.');
            redirect('cashflow');
            return;
        }

        $review_note = trim((string) $this->input->post('review_note', true));
        $request_id = (int) $request_id;
        if ($request_id <= 0) {
            $this->session->set_flashdata('error', 'Request approval tidak valid.');
            redirect('cashflow');
            return;
        }

        $request = $this->db
            ->from($schema['table'] . ' r')
            ->where('r.id', $request_id)
            ->where('r.' . $schema['status_col'], 'pending')
            ->limit(1)
            ->get()
            ->row_array();
        if (empty($request)) {
            $this->session->set_flashdata('error', 'Request approval tidak ditemukan atau sudah diproses.');
            redirect('cashflow');
            return;
        }

        $txn_id = (int) ($request['txn_id'] ?? 0);
        $action = strtolower((string) ($request[$schema['action_col']] ?? ''));

        $this->db->trans_begin();
        $now = date('Y-m-d H:i:s');
        $status_value = $decision === 'approve' ? 'approved' : 'rejected';

        if ($decision === 'approve') {
            if ($action === 'edit') {
                if ($schema['new_data_col'] === '') {
                    $this->db->trans_rollback();
                    $this->session->set_flashdata('error', 'Skema approval edit tidak lengkap (new_data tidak tersedia).');
                    redirect('cashflow');
                    return;
                }
                $new_data = json_decode((string) ($request[$schema['new_data_col']] ?? ''), true);
                $new_data = is_array($new_data) ? $new_data : array();
                if (!$this->apply_cashflow_update_payload($txn_id, $new_data)) {
                    $this->db->trans_rollback();
                    $this->session->set_flashdata('error', 'Gagal menerapkan perubahan edit transaksi.');
                    redirect('cashflow');
                    return;
                }
            } elseif ($action === 'delete') {
                $this->db->where('id', $txn_id)->delete('cashflow_transactions');
                if ($this->db->affected_rows() < 0) {
                    $this->db->trans_rollback();
                    $this->session->set_flashdata('error', 'Gagal menghapus transaksi.');
                    redirect('cashflow');
                    return;
                }
            } else {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'Jenis request tidak dikenali.');
                redirect('cashflow');
                return;
            }
        }
        if ($decision === 'reject' && $action === 'delete' && $this->table_has_column('cashflow_transactions', 'deleted_at')) {
            $restore_payload = array('deleted_at' => null);
            if ($this->table_has_column('cashflow_transactions', 'deleted_by')) {
                $restore_payload['deleted_by'] = null;
            }
            if ($this->table_has_column('cashflow_transactions', 'updated_at')) {
                $restore_payload['updated_at'] = $now;
            }
            $this->db->where('id', $txn_id)->update('cashflow_transactions', $restore_payload);
            if ($this->db->affected_rows() < 0) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'Gagal restore transaksi soft delete.');
                redirect('cashflow');
                return;
            }
        }

        $review_payload = array(
            $schema['status_col'] => $status_value,
        );
        if ($schema['reviewed_by_col'] !== '') {
            $review_payload[$schema['reviewed_by_col']] = (int) $this->session->userdata('user_id');
        }
        if ($schema['reviewed_at_col'] !== '') {
            $review_payload[$schema['reviewed_at_col']] = $now;
        }
        if ($schema['review_note_col'] !== '') {
            $review_payload[$schema['review_note_col']] = $review_note;
        }
        if ($schema['updated_at_col'] !== '') {
            $review_payload[$schema['updated_at_col']] = $now;
        }

        $this->db->where('id', $request_id)->update($schema['table'], $review_payload);
        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Gagal menyimpan keputusan approval.');
            redirect('cashflow');
            return;
        }
        $this->db->trans_commit();

        $this->session->set_flashdata(
            'success',
            $decision === 'approve'
                ? 'Request berhasil di-ACC.'
                : 'Request berhasil ditolak.'
        );
        redirect('cashflow');
    }

    public function bulk_action()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->db->table_exists('cashflow_transactions')) {
            $this->session->set_flashdata('error', 'Tabel cashflow_transactions tidak tersedia.');
            redirect('cashflow');
            return;
        }

        $action = strtolower(trim((string) $this->input->post('bulk_action', true)));
        $raw_ids = $this->input->post('txn_ids');
        $txn_ids = array();
        if (is_array($raw_ids)) {
            foreach ($raw_ids as $raw_id) {
                $id = (int) $raw_id;
                if ($id > 0) {
                    $txn_ids[$id] = $id;
                }
            }
        }
        $txn_ids = array_values($txn_ids);

        if (empty($txn_ids)) {
            $this->session->set_flashdata('error', 'Pilih minimal 1 transaksi untuk bulk action.');
            redirect('cashflow');
            return;
        }

        if (!in_array($action, array('delete', 'set_type_income', 'set_type_expense'), true)) {
            $this->session->set_flashdata('error', 'Jenis bulk action tidak valid.');
            redirect('cashflow');
            return;
        }

        if ($action === 'delete') {
            $result = $this->run_bulk_delete($txn_ids);
            $message = $this->is_superadmin()
                ? sprintf('Bulk hapus selesai. Deleted: %d, Skipped: %d, Failed: %d.', $result['done'], $result['skipped'], $result['failed'])
                : sprintf('Bulk request hapus selesai. Requested: %d, Skipped: %d, Failed: %d.', $result['done'], $result['skipped'], $result['failed']);
            $this->session->set_flashdata($result['failed'] > 0 ? 'error' : 'success', $message);
            redirect('cashflow');
            return;
        }

        $target_type = $action === 'set_type_income' ? 'income' : 'expense';
        $result = $this->run_bulk_change_type($txn_ids, $target_type);
        $message = $this->is_superadmin()
            ? sprintf('Bulk ubah jenis ke %s selesai. Updated: %d, Skipped: %d, Failed: %d.', strtoupper($target_type), $result['done'], $result['skipped'], $result['failed'])
            : sprintf('Bulk request ubah jenis ke %s selesai. Requested: %d, Skipped: %d, Failed: %d.', strtoupper($target_type), $result['done'], $result['skipped'], $result['failed']);

        $this->session->set_flashdata($result['failed'] > 0 ? 'error' : 'success', $message);
        redirect('cashflow');
    }

    private function normalize_type_filter($type)
    {
        $type = strtolower(trim((string) $type));
        return in_array($type, array('income', 'expense'), true) ? $type : '';
    }

    private function normalize_period_filter($period)
    {
        $period = trim((string) $period);
        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            return $period;
        }

        return date('Y-m');
    }

    private function resolve_period_range($period)
    {
        $start_date = $period . '-01';
        $end_date = date('Y-m-t', strtotime($start_date));
        return array(
            'start_date' => $start_date,
            'end_date' => $end_date,
            'label' => date('F Y', strtotime($start_date)),
        );
    }

    private function type_filter_options()
    {
        return array(
            '' => 'Semua Tipe',
            'income' => 'Income',
            'expense' => 'Expense',
        );
    }

    private function table_has_column($table, $column)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }

        return in_array((string) $column, $this->db->list_fields($table), true);
    }

    private function apply_cashflow_router_scope(CI_DB_query_builder $qb, $alias = 'cft')
    {
        if (!$this->table_has_column('cashflow_transactions', 'router_id')) {
            return;
        }

        $this->applyRouterFilter($alias, $qb);
    }

    private function apply_cashflow_router_scope_payload(array &$payload)
    {
        if (!$this->table_has_column('cashflow_transactions', 'router_id')) {
            return;
        }

        $scope_router_id = $this->getEffectiveRouterId();
        if ($scope_router_id !== null) {
            $payload['router_id'] = (int) $scope_router_id;
            return;
        }

        if ($this->is_superadmin()) {
            $posted_router_id = (int) $this->input->post('router_id', true);
            if ($posted_router_id > 0) {
                $payload['router_id'] = $posted_router_id;
            }
        }
    }

    private function resolve_txn_datetime_column()
    {
        if ($this->table_has_column('cashflow_transactions', 'txn_date')) {
            return 'cft.txn_date';
        }

        if ($this->table_has_column('cashflow_transactions', 'transaction_date')) {
            return 'cft.transaction_date';
        }

        if ($this->table_has_column('cashflow_transactions', 'created_at')) {
            return 'cft.created_at';
        }

        return 'cft.id';
    }

    private function build_list_query($search, $type_filter, $start_date, $end_date, $count_only = false)
    {
        $date_col = $this->resolve_txn_datetime_column();
        $category_columns = $this->get_cashflow_category_columns();
        $category_name_col = $category_columns['name_col'];
        $can_join_categories = $this->db->table_exists('cashflow_categories')
            && $this->table_has_column('cashflow_transactions', 'category_id')
            && $this->table_has_column('cashflow_categories', 'id')
            && $category_name_col !== '';
        $has_category_text = $this->table_has_column('cashflow_transactions', 'category');

        $can_join_invoices = $this->db->table_exists('invoices')
            && $this->table_has_column('cashflow_transactions', 'invoice_id')
            && $this->table_has_column('invoices', 'id')
            && $this->table_has_column('invoices', 'invoice_number');

        $can_join_customers = $this->db->table_exists('customers')
            && $this->table_has_column('cashflow_transactions', 'customer_id')
            && $this->table_has_column('customers', 'id');

        $qb = $this->db->from('cashflow_transactions cft');
        $this->apply_cashflow_router_scope($qb, 'cft');

        if ($can_join_categories) {
            $qb->join('cashflow_categories cc', 'cc.id = cft.category_id', 'left');
        }
        if ($can_join_invoices) {
            $qb->join('invoices inv', 'inv.id = cft.invoice_id', 'left');
        }
        if ($can_join_customers) {
            $qb->join('customers c', 'c.id = cft.customer_id', 'left');
        }

        if (!$count_only) {
            $category_select = "'-' AS category_name";
            if ($can_join_categories && $has_category_text) {
                $category_select = "COALESCE(cc." . $category_name_col . ", cft.category, '-') AS category_name";
            } elseif ($can_join_categories) {
                $category_select = "COALESCE(cc." . $category_name_col . ", '-') AS category_name";
            } elseif ($has_category_text) {
                $category_select = "COALESCE(cft.category, '-') AS category_name";
            }
            $category_id_select = $this->table_has_column('cashflow_transactions', 'category_id')
                ? 'cft.category_id AS category_id'
                : 'NULL AS category_id';
            $category_text_select = $has_category_text
                ? "COALESCE(cft.category, '') AS category_text"
                : "'' AS category_text";
            $txn_number_select = $this->table_has_column('cashflow_transactions', 'txn_number')
                ? 'cft.txn_number AS txn_number'
                : "CONCAT('CF-', cft.id) AS txn_number";

            $customer_name_select = "'-' AS customer_name";
            if ($can_join_customers && $this->table_has_column('customers', 'full_name')) {
                $customer_name_select = "COALESCE(c.full_name, '-') AS customer_name";
            } elseif ($can_join_customers && $this->table_has_column('customers', 'nama')) {
                $customer_name_select = "COALESCE(c.nama, '-') AS customer_name";
            }

            $invoice_number_select = $can_join_invoices
                ? "COALESCE(inv.invoice_number, '-') AS invoice_number"
                : "'-' AS invoice_number";

            $qb->select("cft.id, {$date_col} AS txn_date, cft.type, cft.description, cft.amount, {$txn_number_select}, {$category_id_select}, {$category_text_select}, {$category_select}, {$customer_name_select}, {$invoice_number_select}", false);
        }

        $qb->where($date_col . ' >=', $start_date . ' 00:00:00');
        $qb->where($date_col . ' <=', $end_date . ' 23:59:59');
        if ($this->table_has_column('cashflow_transactions', 'deleted_at')) {
            $qb->where('cft.deleted_at IS NULL', null, false);
        }

        if ($type_filter !== '') {
            $qb->where('LOWER(cft.type)', $type_filter);
        }

        if ($search !== '') {
            $qb->group_start()
                ->like('cft.description', $search)
                ->or_like('cft.type', $search)
                ->or_like('cft.txn_number', $search);

            if ($has_category_text) {
                $qb->or_like('cft.category', $search);
            }
            if ($can_join_categories) {
                $qb->or_like('cc.' . $category_name_col, $search);
            }
            if ($can_join_invoices) {
                $qb->or_like('inv.invoice_number', $search);
            }
            if ($can_join_customers && $this->table_has_column('customers', 'full_name')) {
                $qb->or_like('c.full_name', $search);
            }
            if ($can_join_customers && $this->table_has_column('customers', 'nama')) {
                $qb->or_like('c.nama', $search);
            }

            $qb->group_end();
        }

        if (!$count_only) {
            $qb->order_by($date_col, 'DESC');
            $qb->order_by('cft.id', 'DESC');
        }

        return $qb;
    }

    private function build_summary($start_date, $end_date)
    {
        $date_col = $this->resolve_txn_datetime_column();
        $category_columns = $this->get_cashflow_category_columns();
        $can_join_categories = $this->db->table_exists('cashflow_categories')
            && $this->table_has_column('cashflow_transactions', 'category_id')
            && $this->table_has_column('cashflow_categories', 'id')
            && ($category_columns['name_col'] !== '' || $category_columns['code_col'] !== '');

        $category_expr = $this->build_category_expr_sql($can_join_categories, $category_columns);
        $internet_match = $this->build_category_match_sql($category_expr, array('subscription', 'internet', 'monthly', 'billing'));
        $installation_match = $this->build_category_match_sql($category_expr, array('installation', 'instalasi', 'installasi', 'pasang'));

        $qb = $this->db->from('cashflow_transactions cft');
        $this->apply_cashflow_router_scope($qb, 'cft');
        if ($can_join_categories) {
            $qb->join('cashflow_categories cc', 'cc.id = cft.category_id', 'left');
        }
        if ($this->table_has_column('cashflow_transactions', 'deleted_at')) {
            $qb->where('cft.deleted_at IS NULL', null, false);
        }

        $row = $qb
            ->select("
                COALESCE(SUM(CASE WHEN LOWER(cft.type)='income' THEN cft.amount ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN LOWER(cft.type)='expense' THEN cft.amount ELSE 0 END), 0) AS total_expense,
                COALESCE(SUM(CASE WHEN LOWER(cft.type)='income' AND {$internet_match} THEN cft.amount ELSE 0 END), 0) AS total_internet_income,
                COALESCE(SUM(CASE WHEN LOWER(cft.type)='income' AND {$installation_match} THEN cft.amount ELSE 0 END), 0) AS total_installation_income
            ", false)
            ->where($date_col . ' >=', $start_date . ' 00:00:00')
            ->where($date_col . ' <=', $end_date . ' 23:59:59')
            ->get()
            ->row_array();

        $income = (float) ($row['total_income'] ?? 0);
        $expense = (float) ($row['total_expense'] ?? 0);
        $internet_income = (float) ($row['total_internet_income'] ?? 0);
        $installation_income = (float) ($row['total_installation_income'] ?? 0);

        return array(
            'total_income' => round($income, 2),
            'total_expense' => round($expense, 2),
            'net_profit' => round($income - $expense, 2),
            'total_internet_income' => round($internet_income, 2),
            'total_installation_income' => round($installation_income, 2),
            'total_other_income' => round($income - $internet_income - $installation_income, 2),
        );
    }

    private function build_chart_data($period_end_date)
    {
        $date_col = $this->resolve_txn_datetime_column();
        $chart_end = date('Y-m-t', strtotime((string) $period_end_date));
        $chart_start = date('Y-m-01', strtotime($chart_end . ' -5 months'));

        $category_columns = $this->get_cashflow_category_columns();
        $can_join_categories = $this->db->table_exists('cashflow_categories')
            && $this->table_has_column('cashflow_transactions', 'category_id')
            && $this->table_has_column('cashflow_categories', 'id')
            && ($category_columns['name_col'] !== '' || $category_columns['code_col'] !== '');
        $category_expr = $this->build_category_expr_sql($can_join_categories, $category_columns);
        $internet_match = $this->build_category_match_sql($category_expr, array('subscription', 'internet', 'monthly', 'billing'));
        $installation_match = $this->build_category_match_sql($category_expr, array('installation', 'instalasi', 'installasi', 'pasang'));

        $qb = $this->db->from('cashflow_transactions cft');
        $this->apply_cashflow_router_scope($qb, 'cft');
        if ($can_join_categories) {
            $qb->join('cashflow_categories cc', 'cc.id = cft.category_id', 'left');
        }
        if ($this->table_has_column('cashflow_transactions', 'deleted_at')) {
            $qb->where('cft.deleted_at IS NULL', null, false);
        }

        $rows = $qb
            ->select("
                DATE_FORMAT({$date_col}, '%Y-%m') AS ym,
                COALESCE(SUM(CASE WHEN LOWER(cft.type)='income' THEN cft.amount ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN LOWER(cft.type)='income' AND {$internet_match} THEN cft.amount ELSE 0 END), 0) AS internet_income,
                COALESCE(SUM(CASE WHEN LOWER(cft.type)='income' AND {$installation_match} THEN cft.amount ELSE 0 END), 0) AS installation_income,
                COALESCE(SUM(CASE WHEN LOWER(cft.type)='expense' THEN cft.amount ELSE 0 END), 0) AS total_expense
            ", false)
            ->where($date_col . ' >=', $chart_start . ' 00:00:00')
            ->where($date_col . ' <=', $chart_end . ' 23:59:59')
            ->group_by("DATE_FORMAT({$date_col}, '%Y-%m')", false)
            ->order_by('ym', 'ASC')
            ->get()
            ->result_array();

        $bucket = array();
        $cursor = $chart_start;
        while ($cursor <= $chart_end) {
            $ym = date('Y-m', strtotime($cursor));
            $bucket[$ym] = array(
                'income' => 0.0,
                'internet_income' => 0.0,
                'installation_income' => 0.0,
                'expense' => 0.0,
            );
            $cursor = date('Y-m-01', strtotime($cursor . ' +1 month'));
        }

        foreach ($rows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if (!isset($bucket[$ym])) {
                continue;
            }
            $bucket[$ym]['income'] = (float) ($row['total_income'] ?? 0);
            $bucket[$ym]['internet_income'] = (float) ($row['internet_income'] ?? 0);
            $bucket[$ym]['installation_income'] = (float) ($row['installation_income'] ?? 0);
            $bucket[$ym]['expense'] = (float) ($row['total_expense'] ?? 0);
        }

        $labels = array();
        $income = array();
        $internet_income = array();
        $installation_income = array();
        $expense = array();
        $net = array();

        foreach ($bucket as $ym => $totals) {
            $labels[] = $ym;
            $income[] = round((float) $totals['income'], 2);
            $internet_income[] = round((float) $totals['internet_income'], 2);
            $installation_income[] = round((float) $totals['installation_income'], 2);
            $expense[] = round((float) $totals['expense'], 2);
            $net[] = round((float) $totals['income'] - (float) $totals['expense'], 2);
        }

        return array(
            'labels' => $labels,
            'income' => $income,
            'internet_income' => $internet_income,
            'installation_income' => $installation_income,
            'expense' => $expense,
            'net' => $net,
        );
    }

    private function build_category_breakdown($start_date, $end_date)
    {
        $date_col = $this->resolve_txn_datetime_column();
        $category_columns = $this->get_cashflow_category_columns();
        $category_name_col = $category_columns['name_col'];
        $can_join_categories = $this->db->table_exists('cashflow_categories')
            && $this->table_has_column('cashflow_transactions', 'category_id')
            && $this->table_has_column('cashflow_categories', 'id')
            && $category_name_col !== '';
        $has_category_text = $this->table_has_column('cashflow_transactions', 'category');

        $category_expr = "'-'";
        if ($can_join_categories && $has_category_text) {
            $category_expr = "COALESCE(cc." . $category_name_col . ", cft.category, '-')";
        } elseif ($can_join_categories) {
            $category_expr = "COALESCE(cc." . $category_name_col . ", '-')";
        } elseif ($has_category_text) {
            $category_expr = "COALESCE(cft.category, '-')";
        }

        $qb = $this->db->from('cashflow_transactions cft');
        $this->apply_cashflow_router_scope($qb, 'cft');
        if ($can_join_categories) {
            $qb->join('cashflow_categories cc', 'cc.id = cft.category_id', 'left');
        }
        if ($this->table_has_column('cashflow_transactions', 'deleted_at')) {
            $qb->where('cft.deleted_at IS NULL', null, false);
        }

        $rows = $qb
            ->select("LOWER(cft.type) AS type_key, {$category_expr} AS category_name, COUNT(*) AS total_txn, COALESCE(SUM(cft.amount), 0) AS total_amount", false)
            ->where($date_col . ' >=', $start_date . ' 00:00:00')
            ->where($date_col . ' <=', $end_date . ' 23:59:59')
            ->group_by('type_key')
            ->group_by('category_name')
            ->order_by('type_key', 'ASC')
            ->order_by('total_amount', 'DESC')
            ->get()
            ->result_array();

        $result = array(
            'income' => array(),
            'expense' => array(),
        );

        foreach ($rows as $row) {
            $type_key = strtolower((string) ($row['type_key'] ?? ''));
            if (!isset($result[$type_key])) {
                continue;
            }

            $result[$type_key][] = array(
                'category_name' => (string) ($row['category_name'] ?? '-'),
                'total_txn' => (int) ($row['total_txn'] ?? 0),
                'total_amount' => round((float) ($row['total_amount'] ?? 0), 2),
            );
        }

        return $result;
    }

    private function fallback_rows()
    {
        return array(
            array('txn_date' => '2026-02-01 09:00:00', 'type' => 'income', 'category_name' => 'Subscription', 'description' => 'Pembayaran INV-2026-0201', 'amount' => 350000, 'txn_number' => 'CF-20260201-0001', 'invoice_number' => 'INV-2026-0201', 'customer_name' => 'Ari'),
            array('txn_date' => '2026-02-02 10:10:00', 'type' => 'expense', 'category_name' => 'Operational', 'description' => 'Beli kabel fiber', 'amount' => 800000, 'txn_number' => 'CF-20260202-0001', 'invoice_number' => '-', 'customer_name' => '-'),
            array('txn_date' => '2026-02-03 11:20:00', 'type' => 'income', 'category_name' => 'Subscription', 'description' => 'Pembayaran INV-2026-0202', 'amount' => 280000, 'txn_number' => 'CF-20260203-0001', 'invoice_number' => 'INV-2026-0202', 'customer_name' => 'Budi'),
            array('txn_date' => '2026-02-04 14:00:00', 'type' => 'expense', 'category_name' => 'Maintenance', 'description' => 'Service OLT', 'amount' => 1200000, 'txn_number' => 'CF-20260204-0001', 'invoice_number' => '-', 'customer_name' => '-'),
            array('txn_date' => '2026-02-05 15:00:00', 'type' => 'income', 'category_name' => 'Installation', 'description' => 'Biaya instalasi', 'amount' => 500000, 'txn_number' => 'CF-20260205-0001', 'invoice_number' => '-', 'customer_name' => 'Citra'),
        );
    }

    private function fallback_summary()
    {
        return array(
            'total_income' => 1130000,
            'total_expense' => 2000000,
            'net_profit' => -870000,
            'total_internet_income' => 630000,
            'total_installation_income' => 500000,
            'total_other_income' => 0,
        );
    }

    private function fallback_chart_data()
    {
        return array(
            'labels' => array('2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02'),
            'income' => array(10500000, 10800000, 11200000, 11700000, 12200000, 12800000),
            'internet_income' => array(9800000, 10000000, 10400000, 10800000, 11200000, 11600000),
            'installation_income' => array(700000, 800000, 800000, 900000, 1000000, 1200000),
            'expense' => array(4400000, 4600000, 4700000, 4900000, 5100000, 5400000),
            'net' => array(6100000, 6200000, 6500000, 6800000, 7100000, 7400000),
        );
    }

    private function fallback_category_breakdown()
    {
        return array(
            'income' => array(
                array('category_name' => 'Subscription', 'total_txn' => 2, 'total_amount' => 630000),
                array('category_name' => 'Installation', 'total_txn' => 1, 'total_amount' => 500000),
            ),
            'expense' => array(
                array('category_name' => 'Maintenance', 'total_txn' => 1, 'total_amount' => 1200000),
                array('category_name' => 'Operational', 'total_txn' => 1, 'total_amount' => 800000),
            ),
        );
    }

    private function get_cashflow_transaction($txn_id)
    {
        if (!$this->db->table_exists('cashflow_transactions')) {
            return array();
        }

        $qb = $this->db
            ->from('cashflow_transactions cft')
            ->where('cft.id', (int) $txn_id);
        $this->apply_cashflow_router_scope($qb, 'cft');

        return (array) $qb
            ->limit(1)
            ->get()
            ->row_array();
    }

    private function get_cashflow_transactions_by_ids(array $txn_ids)
    {
        if (empty($txn_ids) || !$this->db->table_exists('cashflow_transactions')) {
            return array();
        }

        $qb = $this->db
            ->from('cashflow_transactions cft')
            ->where_in('cft.id', $txn_ids);
        $this->apply_cashflow_router_scope($qb, 'cft');

        $rows = $qb->get()->result_array();

        $mapped = array();
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $mapped[$id] = (array) $row;
            }
        }

        return $mapped;
    }

    private function resolve_editable_txn_date_column()
    {
        if ($this->table_has_column('cashflow_transactions', 'txn_date')) {
            return 'txn_date';
        }
        if ($this->table_has_column('cashflow_transactions', 'transaction_date')) {
            return 'transaction_date';
        }
        if ($this->table_has_column('cashflow_transactions', 'date')) {
            return 'date';
        }

        return '';
    }

    private function normalize_update_payload_for_table(array $payload)
    {
        $normalized = array();
        foreach ($payload as $key => $value) {
            if ($this->table_has_column('cashflow_transactions', $key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function apply_cashflow_update_payload($txn_id, array $payload)
    {
        $txn_id = (int) $txn_id;
        if ($txn_id <= 0 || !$this->db->table_exists('cashflow_transactions')) {
            return false;
        }

        $payload = $this->normalize_update_payload_for_table($payload);
        if (empty($payload)) {
            return false;
        }

        if ($this->table_has_column('cashflow_transactions', 'updated_at')) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $this->db->where('id', $txn_id);
        $this->applyRouterFilter(null, $this->db);
        $ok = $this->db->update('cashflow_transactions', $payload);
        $this->db->db_debug = $old_debug;
        return (bool) $ok;
    }

    private function resolve_change_request_schema()
    {
        $result = array(
            'ready' => false,
            'table' => 'cashflow_change_requests',
            'action_col' => '',
            'status_col' => '',
            'new_data_col' => '',
            'old_data_col' => '',
            'reviewed_by_col' => '',
            'reviewed_at_col' => '',
            'review_note_col' => '',
            'updated_at_col' => '',
        );

        if (!$this->db->table_exists($result['table'])) {
            return $result;
        }

        $fields = $this->db->list_fields($result['table']);
        if (!in_array('id', $fields, true) || !in_array('txn_id', $fields, true)) {
            return $result;
        }

        $action_col = in_array('action_type', $fields, true) ? 'action_type' : (in_array('action', $fields, true) ? 'action' : '');
        $status_col = in_array('request_status', $fields, true) ? 'request_status' : (in_array('status', $fields, true) ? 'status' : '');
        if ($action_col === '' || $status_col === '') {
            return $result;
        }

        $result['ready'] = true;
        $result['action_col'] = $action_col;
        $result['status_col'] = $status_col;
        $result['new_data_col'] = in_array('new_data', $fields, true) ? 'new_data' : '';
        $result['old_data_col'] = in_array('old_data', $fields, true) ? 'old_data' : '';
        $result['reviewed_by_col'] = in_array('reviewed_by', $fields, true) ? 'reviewed_by' : '';
        $result['reviewed_at_col'] = in_array('reviewed_at', $fields, true) ? 'reviewed_at' : '';
        $result['review_note_col'] = in_array('review_note', $fields, true) ? 'review_note' : '';
        $result['updated_at_col'] = in_array('updated_at', $fields, true) ? 'updated_at' : '';

        return $result;
    }

    private function has_pending_change_request($txn_id, $action = '')
    {
        $txn_id = (int) $txn_id;
        if ($txn_id <= 0) {
            return false;
        }

        $schema = $this->resolve_change_request_schema();
        if (!$schema['ready']) {
            return false;
        }

        $qb = $this->db
            ->from($schema['table'])
            ->where('txn_id', $txn_id)
            ->where($schema['status_col'], 'pending');
        $action = strtolower(trim((string) $action));
        if ($action !== '') {
            $qb->where($schema['action_col'], $action);
        }

        return (int) $qb->count_all_results() > 0;
    }

    private function create_cashflow_change_request($txn_id, $action, array $old_data, array $new_data, $reason = '')
    {
        $schema = $this->resolve_change_request_schema();
        if (!$schema['ready']) {
            return array(
                'success' => false,
                'message' => 'Tabel persetujuan cashflow belum tersedia. Jalankan migration cashflow approval.',
            );
        }

        $txn_id = (int) $txn_id;
        $action = strtolower(trim((string) $action));
        if ($txn_id <= 0 || !in_array($action, array('edit', 'delete'), true)) {
            return array(
                'success' => false,
                'message' => 'Request perubahan tidak valid.',
            );
        }
        if ($action === 'edit' && $schema['new_data_col'] === '') {
            return array(
                'success' => false,
                'message' => 'Skema approval belum mendukung request edit (kolom new_data tidak ada).',
            );
        }

        if ($this->has_pending_change_request($txn_id, $action)) {
            return array(
                'success' => false,
                'message' => 'Masih ada request ' . strtoupper($action) . ' yang pending untuk transaksi ini.',
            );
        }

        $fields = $this->db->list_fields($schema['table']);
        $now = date('Y-m-d H:i:s');
        $payload = array(
            'txn_id' => $txn_id,
            $schema['action_col'] => $action,
            $schema['status_col'] => 'pending',
        );
        if (in_array('requested_by', $fields, true)) {
            $payload['requested_by'] = (int) $this->session->userdata('user_id');
        }
        if (in_array('requested_role', $fields, true)) {
            $payload['requested_role'] = (string) $this->session->userdata('role');
        }
        if (in_array('reason', $fields, true)) {
            $payload['reason'] = $reason;
        }
        if ($schema['old_data_col'] !== '') {
            $payload[$schema['old_data_col']] = json_encode($old_data);
        }
        if ($schema['new_data_col'] !== '') {
            $payload[$schema['new_data_col']] = json_encode($new_data);
        }
        if (in_array('created_at', $fields, true)) {
            $payload['created_at'] = $now;
        }
        if ($schema['updated_at_col'] !== '') {
            $payload[$schema['updated_at_col']] = $now;
        }

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert($schema['table'], $payload);
        $error = $this->db->error();
        $this->db->db_debug = $old_debug;

        if (!$ok) {
            log_message('error', '[CASHFLOW_UI][REQUEST] create failed: ' . json_encode($error) . ' payload=' . json_encode($payload));
            return array(
                'success' => false,
                'message' => 'Gagal menyimpan request approval: ' . (string) ($error['message'] ?? 'unknown'),
            );
        }

        return array('success' => true);
    }

    private function get_pending_request_action_map(array $txn_ids)
    {
        $map = array();
        $txn_ids = array_values(array_filter(array_map('intval', $txn_ids), static function ($id) {
            return $id > 0;
        }));
        if (empty($txn_ids)) {
            return $map;
        }

        $schema = $this->resolve_change_request_schema();
        if (!$schema['ready']) {
            return $map;
        }

        $rows = $this->db
            ->select('txn_id, ' . $schema['action_col'] . ' AS action_name', false)
            ->from($schema['table'])
            ->where_in('txn_id', $txn_ids)
            ->where($schema['status_col'], 'pending')
            ->order_by('id', 'DESC')
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            $txn_id = (int) ($row['txn_id'] ?? 0);
            if ($txn_id <= 0 || isset($map[$txn_id])) {
                continue;
            }
            $map[$txn_id] = strtolower((string) ($row['action_name'] ?? ''));
        }

        return $map;
    }

    private function build_type_update_payload($target_type)
    {
        $target_type = strtolower(trim((string) $target_type));
        if (!in_array($target_type, array('income', 'expense'), true)) {
            return array();
        }

        $payload = array('type' => $target_type);
        if ($this->table_has_column('cashflow_transactions', 'category_id')) {
            $category_id = 0;
            if ($target_type === 'income') {
                $category_id = $this->ensure_income_category_id('Subscription');
            } else {
                $category_id = $this->ensure_expense_category_id('Operational');
            }
            if ($category_id > 0) {
                $payload['category_id'] = $category_id;
            }
        }
        if ($this->table_has_column('cashflow_transactions', 'category')) {
            $payload['category'] = $target_type === 'income' ? 'Subscription' : 'Operational';
        }
        if ($this->table_has_column('cashflow_transactions', 'updated_at')) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        return $payload;
    }

    private function run_bulk_change_type(array $txn_ids, $target_type)
    {
        $result = array('done' => 0, 'skipped' => 0, 'failed' => 0);
        $payload = $this->build_type_update_payload($target_type);
        if (empty($payload)) {
            $result['failed'] = count($txn_ids);
            return $result;
        }

        $txns = $this->get_cashflow_transactions_by_ids($txn_ids);
        foreach ($txn_ids as $txn_id) {
            if (!isset($txns[$txn_id])) {
                $result['skipped']++;
                continue;
            }

            $txn = $txns[$txn_id];
            $current_type = strtolower((string) ($txn['type'] ?? ''));
            if ($current_type === strtolower((string) $target_type)) {
                $result['skipped']++;
                continue;
            }

            if ($this->has_pending_change_request($txn_id)) {
                $result['skipped']++;
                continue;
            }

            if ($this->is_superadmin()) {
                $ok = $this->apply_cashflow_update_payload($txn_id, $payload);
                if ($ok) {
                    $result['done']++;
                } else {
                    $result['failed']++;
                }
                continue;
            }

            $request = $this->create_cashflow_change_request($txn_id, 'edit', $txn, $payload, 'Bulk ubah jenis transaksi');
            if (!empty($request['success'])) {
                $result['done']++;
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    private function run_bulk_delete(array $txn_ids)
    {
        $result = array('done' => 0, 'skipped' => 0, 'failed' => 0);
        $txns = $this->get_cashflow_transactions_by_ids($txn_ids);

        foreach ($txn_ids as $txn_id) {
            if (!isset($txns[$txn_id])) {
                $result['skipped']++;
                continue;
            }
            if ($this->has_pending_change_request($txn_id)) {
                $result['skipped']++;
                continue;
            }

            if ($this->is_superadmin()) {
                $old_debug = $this->db->db_debug;
                $this->db->db_debug = false;
                $ok = $this->db->where('id', $txn_id)->delete('cashflow_transactions');
                $this->db->db_debug = $old_debug;
                if ($ok) {
                    $result['done']++;
                } else {
                    $result['failed']++;
                }
                continue;
            }

            $request = $this->create_cashflow_change_request($txn_id, 'delete', $txns[$txn_id], array(), 'Bulk hapus transaksi');
            if (empty($request['success'])) {
                $result['failed']++;
                continue;
            }

            if ($this->table_has_column('cashflow_transactions', 'deleted_at')) {
                $soft_payload = array(
                    'deleted_at' => date('Y-m-d H:i:s'),
                );
                if ($this->table_has_column('cashflow_transactions', 'deleted_by')) {
                    $soft_payload['deleted_by'] = (int) $this->session->userdata('user_id');
                }
                if ($this->table_has_column('cashflow_transactions', 'updated_at')) {
                    $soft_payload['updated_at'] = date('Y-m-d H:i:s');
                }
                $this->db->where('id', $txn_id)->update('cashflow_transactions', $soft_payload);
            }

            $result['done']++;
        }

        return $result;
    }

    private function get_pending_change_requests($limit = 50)
    {
        $schema = $this->resolve_change_request_schema();
        if (!$schema['ready']) {
            return array();
        }

        $fields = $this->db->list_fields($schema['table']);

        $limit = max(1, min(200, (int) $limit));
        $qb = $this->db
            ->from($schema['table'] . ' r')
            ->where('r.' . $schema['status_col'], 'pending')
            ->order_by('r.id', 'DESC')
            ->limit($limit);

        if (in_array('requested_by', $fields, true) && $this->db->table_exists('users') && $this->table_has_column('users', 'id')) {
            $qb->join('users u', 'u.id = r.requested_by', 'left');
        }
        if ($this->db->table_exists('cashflow_transactions') && $this->table_has_column('cashflow_transactions', 'id')) {
            $qb->join('cashflow_transactions cft', 'cft.id = r.txn_id', 'left');
        }

        $requester_name_select = "'-' AS requester_name";
        if (in_array('requested_by', $fields, true) && $this->db->table_exists('users') && $this->table_has_column('users', 'name')) {
            $requester_name_select = "COALESCE(u.name, '-') AS requester_name";
        } elseif (in_array('requested_by', $fields, true) && $this->db->table_exists('users') && $this->table_has_column('users', 'username')) {
            $requester_name_select = "COALESCE(u.username, '-') AS requester_name";
        }
        $created_at_select = in_array('created_at', $fields, true) ? 'r.created_at' : 'NULL AS created_at';
        $reason_select = in_array('reason', $fields, true) ? 'r.reason' : "'' AS reason";
        $requested_role_select = in_array('requested_role', $fields, true) ? 'r.requested_role' : "'' AS requested_role";

        $rows = $qb
            ->select(
                "r.id, r.txn_id, r." . $schema['action_col'] . " AS action_name, " . $created_at_select . ", " . $reason_select . ", " . $requested_role_select . ", " .
                ($schema['old_data_col'] !== '' ? 'r.' . $schema['old_data_col'] . ' AS old_data_json, ' : "'' AS old_data_json, ") .
                ($schema['new_data_col'] !== '' ? 'r.' . $schema['new_data_col'] . ' AS new_data_json, ' : "'' AS new_data_json, ") .
                "COALESCE(cft.txn_number, '-') AS txn_number, COALESCE(cft.description, '-') AS txn_description, COALESCE(cft.amount, 0) AS txn_amount, " .
                $requester_name_select,
                false
            )
            ->get()
            ->result_array();

        foreach ($rows as &$row) {
            $row['old_data'] = json_decode((string) ($row['old_data_json'] ?? ''), true);
            $row['new_data'] = json_decode((string) ($row['new_data_json'] ?? ''), true);
            if (!is_array($row['old_data'])) {
                $row['old_data'] = array();
            }
            if (!is_array($row['new_data'])) {
                $row['new_data'] = array();
            }
            $row['action_name'] = strtolower((string) ($row['action_name'] ?? ''));
        }
        unset($row);

        return $rows;
    }

    private function get_expense_category_options()
    {
        $fallback = array(
            array('id' => '', 'label' => 'Operational'),
            array('id' => '', 'label' => 'Gaji'),
            array('id' => '', 'label' => 'Maintenance'),
            array('id' => '', 'label' => 'Pembelian Infrastruktur'),
            array('id' => '', 'label' => 'Other Expense'),
        );

        if (!$this->db->table_exists('cashflow_categories')) {
            return $fallback;
        }

        $fields = $this->db->list_fields('cashflow_categories');
        if (!in_array('id', $fields, true) || !in_array('type', $fields, true)) {
            return $fallback;
        }

        $columns = $this->get_cashflow_category_columns();
        $name_col = $columns['name_col'];
        if ($name_col === '') {
            return $fallback;
        }

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS label', false)
            ->from('cashflow_categories')
            ->where('type', 'expense');

        if ($columns['active_col'] !== '') {
            $qb->where($columns['active_col'], 1);
        }

        $rows = $qb->order_by($name_col, 'ASC')->get()->result_array();
        if (empty($rows)) {
            return $fallback;
        }

        $result = array();
        foreach ($fallback as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $result[strtolower($label)] = array(
                'id' => '',
                'label' => $label,
            );
        }

        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $result[strtolower($label)] = array(
                'id' => (string) ((int) ($row['id'] ?? 0)),
                'label' => $label,
            );
        }

        return !empty($result) ? array_values($result) : $fallback;
    }

    private function get_income_category_options()
    {
        $fallback = array(
            array('id' => '', 'label' => 'Subscription'),
            array('id' => '', 'label' => 'Installation'),
            array('id' => '', 'label' => 'Other Income'),
        );

        if (!$this->db->table_exists('cashflow_categories')) {
            return $fallback;
        }

        $fields = $this->db->list_fields('cashflow_categories');
        if (!in_array('id', $fields, true) || !in_array('type', $fields, true)) {
            return $fallback;
        }

        $columns = $this->get_cashflow_category_columns();
        $name_col = $columns['name_col'];
        if ($name_col === '') {
            return $fallback;
        }

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS label', false)
            ->from('cashflow_categories')
            ->where('type', 'income');

        if ($columns['active_col'] !== '') {
            $qb->where($columns['active_col'], 1);
        }

        $rows = $qb->order_by($name_col, 'ASC')->get()->result_array();
        if (empty($rows)) {
            return $fallback;
        }

        $result = array();
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $result[] = array(
                'id' => (string) ((int) ($row['id'] ?? 0)),
                'label' => $label,
            );
        }

        return !empty($result) ? $result : $fallback;
    }

    private function resolve_category_name($category_id, $category_input, $fallback_label = 'Operational')
    {
        $category_input = trim((string) $category_input);
        if ($category_id > 0 && $this->db->table_exists('cashflow_categories')) {
            $columns = $this->get_cashflow_category_columns();
            $name_col = $columns['name_col'];
            if ($name_col !== '') {
                $row = $this->db
                    ->select($name_col . ' AS label', false)
                    ->from('cashflow_categories')
                    ->where('id', $category_id)
                    ->limit(1)
                    ->get()
                    ->row_array();
                if (!empty($row['label'])) {
                    return (string) $row['label'];
                }
            }
        }

        if ($category_input !== '' && !ctype_digit($category_input)) {
            return $category_input;
        }

        return (string) $fallback_label;
    }

    private function build_category_expr_sql($can_join_categories, array $category_columns)
    {
        $parts = array();
        if ($can_join_categories && $category_columns['code_col'] !== '') {
            $parts[] = 'cc.' . $category_columns['code_col'];
        }
        if ($can_join_categories && $category_columns['name_col'] !== '') {
            $parts[] = 'cc.' . $category_columns['name_col'];
        }
        if ($this->table_has_column('cashflow_transactions', 'category')) {
            $parts[] = 'cft.category';
        }

        if (empty($parts)) {
            return "''";
        }

        return "LOWER(COALESCE(" . implode(', ', $parts) . ", ''))";
    }

    private function build_category_match_sql($category_expr, array $keywords)
    {
        if ($category_expr === "''") {
            return '(0=1)';
        }

        $conditions = array();
        foreach ($keywords as $keyword) {
            $keyword = strtolower(trim((string) $keyword));
            if ($keyword === '') {
                continue;
            }
            $conditions[] = $category_expr . ' LIKE ' . $this->db->escape('%' . $keyword . '%');
        }

        if (empty($conditions)) {
            return '(0=1)';
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    private function ensure_expense_category_id($category_input)
    {
        return $this->ensure_cashflow_category_id('expense', $category_input, 'Operational', 'operational');
    }

    private function ensure_income_category_id($category_input)
    {
        return $this->ensure_cashflow_category_id('income', $category_input, 'Subscription', 'subscription');
    }

    private function ensure_cashflow_category_id($type, $category_input, $default_name, $default_code)
    {
        $type = strtolower(trim((string) $type));
        $category_input = trim((string) $category_input);
        if (!$this->db->table_exists('cashflow_categories')) {
            return 0;
        }

        $columns = $this->get_cashflow_category_columns();
        if ($columns['name_col'] === '' || $columns['code_col'] === '') {
            return 0;
        }

        if (ctype_digit($category_input)) {
            $category_id = (int) $category_input;
            if ($category_id > 0) {
                $exists = (int) $this->db
                    ->from('cashflow_categories')
                    ->where('id', $category_id)
                    ->where('type', $type)
                    ->count_all_results();
                if ($exists > 0) {
                    return $category_id;
                }
            }
        }

        $normalized_name = $category_input !== '' ? $category_input : $default_name;
        $normalized_code = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $normalized_name));
        $normalized_code = trim($normalized_code, '_');
        if ($normalized_code === '') {
            $normalized_code = $default_code;
        }

        $existing = $this->db
            ->select('id')
            ->from('cashflow_categories')
            ->where('type', $type)
            ->group_start()
                ->where($columns['code_col'], $normalized_code)
                ->or_where($columns['name_col'], $normalized_name)
            ->group_end()
            ->order_by('id', 'ASC')
            ->limit(1)
            ->get()
            ->row_array();
        if (!empty($existing['id'])) {
            return (int) $existing['id'];
        }

        $payload = array(
            'type' => $type,
            $columns['code_col'] => $normalized_code,
            $columns['name_col'] => $normalized_name,
        );
        if ($columns['active_col'] !== '') {
            $payload[$columns['active_col']] = 1;
        }
        if ($this->table_has_column('cashflow_categories', 'created_at')) {
            $payload['created_at'] = date('Y-m-d H:i:s');
        }
        if ($this->table_has_column('cashflow_categories', 'updated_at')) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('cashflow_categories', $payload);
        $error = $this->db->error();
        $this->db->db_debug = $old_debug;
        if (!$ok) {
            log_message('error', '[CASHFLOW_UI][ENSURE_CATEGORY] create failed: ' . json_encode($error) . ' payload=' . json_encode($payload));
            $existing_retry = $this->db
                ->select('id')
                ->from('cashflow_categories')
                ->where('type', $type)
                ->group_start()
                    ->where($columns['code_col'], $normalized_code)
                    ->or_where($columns['name_col'], $normalized_name)
                ->group_end()
                ->order_by('id', 'ASC')
                ->limit(1)
                ->get()
                ->row_array();
            return !empty($existing_retry['id']) ? (int) $existing_retry['id'] : 0;
        }

        return (int) $this->db->insert_id();
    }

    private function get_cashflow_category_columns()
    {
        $name_col = '';
        $code_col = '';
        $active_col = '';

        if ($this->db->table_exists('cashflow_categories')) {
            $fields = $this->db->list_fields('cashflow_categories');
            $name_col = in_array('category_name', $fields, true) ? 'category_name' : (in_array('name', $fields, true) ? 'name' : '');
            $code_col = in_array('category_code', $fields, true) ? 'category_code' : (in_array('code', $fields, true) ? 'code' : '');
            $active_col = in_array('is_active', $fields, true) ? 'is_active' : '';
        }

        return array(
            'name_col' => $name_col,
            'code_col' => $code_col,
            'active_col' => $active_col,
        );
    }

    private function next_cashflow_txn_number($txn_date)
    {
        $prefix = 'CF-' . date('Ymd', strtotime($txn_date)) . '-';
        $qb = $this->db
            ->select('txn_number')
            ->from('cashflow_transactions cft')
            ->like('txn_number', $prefix, 'after')
            ->order_by('id', 'DESC')
            ->limit(1);
        $this->apply_cashflow_router_scope($qb, 'cft');

        $row = $qb->get()->row_array();

        $next = 1;
        if (!empty($row['txn_number'])) {
            $parts = explode('-', (string) $row['txn_number']);
            $tail = end($parts);
            if (ctype_digit((string) $tail)) {
                $next = (int) $tail + 1;
            }
        }

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
