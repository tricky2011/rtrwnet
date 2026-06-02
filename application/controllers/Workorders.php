<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Workorders extends MY_Controller
{
    private $work_order_fields = array();
    private $customer_fields = array();
    private $user_fields = array();
    private $service_fields = array();

    public function __construct()
    {
        parent::__construct();
        $this->require_module_access('work_orders', 'Akses ditolak. Modul Work Order hanya untuk role yang diizinkan.');
        $this->load->database();
        $this->load->helper(array('url', 'form', 'tenant'));
        $this->load->library('form_validation');

        if ($this->db->table_exists('work_orders')) {
            $this->work_order_fields = $this->db->list_fields('work_orders');
        }
        if ($this->db->table_exists('customers')) {
            $this->customer_fields = $this->db->list_fields('customers');
        }
        if ($this->db->table_exists('users')) {
            $this->user_fields = $this->db->list_fields('users');
        }
        if ($this->db->table_exists('customer_services')) {
            $this->service_fields = $this->db->list_fields('customer_services');
        }
    }

    public function index()
    {
        $search = trim((string) $this->input->get('search', true));
        $month = $this->normalize_month((int) $this->input->get('month', true));
        $year = $this->normalize_year((int) $this->input->get('year', true));

        if ($this->db->table_exists('work_orders')) {
            $total_rows = $this->build_list_query($search, $month, $year, true)->count_all_results();
            $pager = $this->init_pagination('workorders', $total_rows, 20, 3);
            $rows = $this->build_list_query($search, $month, $year, false)
                ->limit($pager['per_page'], $pager['offset'])
                ->get()
                ->result_array();

            return $this->load->view('workorders/list', array(
                'rows' => $rows,
                'search' => $search,
                'filter_month' => $month,
                'filter_year' => $year,
                'months' => $this->month_options(),
                'years' => $this->year_options(),
                'selected_period_label' => $this->format_period_label($month, $year),
                'pagination' => $pager['links'],
                'total_rows' => $pager['total_rows'],
                'can_create' => in_array((string) $this->session->userdata('role'), array('superadmin', 'admin'), true),
                'customer_options' => $this->get_customer_options(),
                'teknisi_options' => $this->get_teknisi_options(),
            ));
        }

        $rows = $this->filter_fallback_rows_by_period($this->fallback_rows(), $month, $year);
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static function ($row) use ($search) {
                $needle = strtolower($search);
                return strpos(strtolower((string) ($row['wo_number'] ?? '')), $needle) !== false
                    || strpos(strtolower((string) ($row['customer_name'] ?? '')), $needle) !== false;
            }));
        }

        $pager = $this->init_pagination('workorders', count($rows), 20, 3);
        $paged_rows = array_slice($rows, $pager['offset'], $pager['per_page']);

        return $this->load->view('workorders/list', array(
            'rows' => $paged_rows,
            'search' => $search,
            'filter_month' => $month,
            'filter_year' => $year,
            'months' => $this->month_options(),
            'years' => $this->year_options(),
            'selected_period_label' => $this->format_period_label($month, $year),
            'pagination' => $pager['links'],
            'total_rows' => $pager['total_rows'],
            'can_create' => in_array((string) $this->session->userdata('role'), array('superadmin', 'admin'), true),
            'customer_options' => $this->get_customer_options(),
            'teknisi_options' => $this->get_teknisi_options(),
        ));
    }

    public function store()
    {
        $this->require_role(array('superadmin', 'admin'));

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->db->table_exists('work_orders')) {
            $this->session->set_flashdata('error', 'Tabel work_orders tidak ditemukan.');
            redirect('workorders');
            return;
        }
        if (!$this->db->table_exists('customers')) {
            $this->session->set_flashdata('error', 'Tabel customers tidak ditemukan.');
            redirect('workorders');
            return;
        }

        $this->form_validation->set_rules('customer_id', 'Customer', 'trim|required|integer|greater_than[0]');
        $this->form_validation->set_rules('wo_type', 'Tipe WO', 'trim|required|in_list[installation,maintenance,relocation,termination,other]');
        $this->form_validation->set_rules('priority', 'Prioritas', 'trim|required|in_list[low,medium,high,critical]');
        $this->form_validation->set_rules('title', 'Judul', 'trim|required|max_length[200]');
        $this->form_validation->set_rules('description', 'Deskripsi', 'trim');
        $this->form_validation->set_rules('requested_date', 'Tanggal Request', 'trim');
        $this->form_validation->set_rules('scheduled_start_at', 'Jadwal Start', 'trim');
        $this->form_validation->set_rules('assigned_to', 'Teknisi', 'trim');

        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', trim(strip_tags(validation_errors(' ', ' '))));
            redirect('workorders');
            return;
        }

        $customer_id = (int) $this->input->post('customer_id', true);
        $customer_qb = $this->db
            ->select('id')
            ->from('customers')
            ->where('id', $customer_id);
        if (in_array('router_id', $this->customer_fields, true)) {
            $customer_qb->select('router_id');
            $this->apply_router_scope_clause($customer_qb, 'customers');
        }
        $customer = $customer_qb->limit(1)->get()->row_array();
        if (empty($customer)) {
            $this->session->set_flashdata('error', 'Customer tidak ditemukan.');
            redirect('workorders');
            return;
        }
        $resolved_router_id = (int) ($customer['router_id'] ?? 0);
        if ($resolved_router_id <= 0) {
            $resolved_router_id = $this->resolve_router_id_from_customer_service($customer_id);
        }
        if ($resolved_router_id <= 0) {
            $resolved_router_id = (int) $this->effective_router_id();
        }
        if ($resolved_router_id <= 0) {
            $this->session->set_flashdata('error', 'Router customer tidak valid. Pastikan customer terikat ke router.');
            redirect('workorders');
            return;
        }

        $assigned_to = (int) $this->input->post('assigned_to', true);
        if ($assigned_to > 0 && !$this->teknisi_exists($assigned_to)) {
            $this->session->set_flashdata('error', 'Teknisi tidak valid.');
            redirect('workorders');
            return;
        }

        $requested_date = trim((string) $this->input->post('requested_date', true));
        if (!$this->is_valid_ymd_date($requested_date)) {
            $requested_date = date('Y-m-d');
        }

        $scheduled_start_at = $this->normalize_datetime_input((string) $this->input->post('scheduled_start_at', true));
        if ($scheduled_start_at === '') {
            $scheduled_start_at = $requested_date . ' 09:00:00';
        }

        $wo_type_input = strtolower(trim((string) $this->input->post('wo_type', true)));
        if (!in_array($wo_type_input, array('installation', 'maintenance', 'relocation', 'termination', 'other'), true)) {
            $wo_type_input = 'installation';
        }
        $priority_input = strtolower(trim((string) $this->input->post('priority', true)));
        if (!in_array($priority_input, array('low', 'medium', 'high', 'critical'), true)) {
            $priority_input = 'medium';
        }

        $wo_number = $this->generate_wo_number();
        $status_open = $this->resolve_table_enum_value('work_orders', 'status', array('open', 'OPEN', 'new', 'pending'), 'open');
        $wo_type_value = $this->resolve_table_enum_value('work_orders', 'wo_type', array($wo_type_input, strtoupper($wo_type_input)), $wo_type_input);
        $priority_value = $this->resolve_table_enum_value('work_orders', 'priority', array($priority_input, strtoupper($priority_input)), $priority_input);

        $title = trim((string) $this->input->post('title', true));
        $description = trim((string) $this->input->post('description', true));
        if ($description === '' && $wo_type_input === 'installation') {
            $description = 'WO input manual untuk pemasangan existing sebelum sistem aktif.';
        }

        $now = date('Y-m-d H:i:s');
        $user_id = (int) $this->session->userdata('user_id');
        $payload = array();

        if (in_array('wo_number', $this->work_order_fields, true)) {
            $payload['wo_number'] = $wo_number;
        }
        if (in_array('customer_id', $this->work_order_fields, true)) {
            $payload['customer_id'] = $customer_id;
        }
        if (in_array('wo_type', $this->work_order_fields, true)) {
            $payload['wo_type'] = $wo_type_value;
        } elseif (in_array('type', $this->work_order_fields, true)) {
            $payload['type'] = $wo_type_value;
        }
        if (in_array('priority', $this->work_order_fields, true)) {
            $payload['priority'] = $priority_value;
        }
        if (in_array('title', $this->work_order_fields, true)) {
            $payload['title'] = $title;
        }
        if (in_array('description', $this->work_order_fields, true)) {
            $payload['description'] = $description;
        }
        if (in_array('status', $this->work_order_fields, true)) {
            $payload['status'] = $status_open;
        }
        if (in_array('requested_date', $this->work_order_fields, true)) {
            $payload['requested_date'] = $requested_date;
        }
        if (in_array('scheduled_start_at', $this->work_order_fields, true)) {
            $payload['scheduled_start_at'] = $scheduled_start_at;
        } elseif (in_array('scheduled_date', $this->work_order_fields, true)) {
            $payload['scheduled_date'] = substr($scheduled_start_at, 0, 10);
        }
        if (in_array('assigned_to', $this->work_order_fields, true) && $assigned_to > 0) {
            $payload['assigned_to'] = $assigned_to;
        }
        if (in_array('created_by', $this->work_order_fields, true)) {
            $payload['created_by'] = $user_id > 0 ? $user_id : 1;
        }
        if (in_array('router_id', $this->work_order_fields, true)) {
            $payload['router_id'] = $resolved_router_id;
        }
        if (in_array('created_at', $this->work_order_fields, true)) {
            $payload['created_at'] = $now;
        }
        if (in_array('updated_at', $this->work_order_fields, true)) {
            $payload['updated_at'] = $now;
        }

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('work_orders', $payload);
        $db_error = $this->db->error();
        $new_wo_id = (int) $this->db->insert_id();
        $this->db->db_debug = $old_debug;

        if (!$ok) {
            log_message('error', '[WORKORDERS][STORE] DB error: ' . json_encode($db_error) . ' payload=' . json_encode($payload));
            $this->session->set_flashdata('error', 'Gagal input WO: ' . (string) ($db_error['message'] ?? 'unknown'));
            redirect('workorders');
            return;
        }

        $telegram = $this->notify_wo_created($new_wo_id);

        $message = 'WO baru berhasil dibuat: ' . $wo_number;
        if (empty($telegram['success'])) {
            $message .= ' (Telegram: ' . (string) ($telegram['message'] ?? 'gagal terkirim') . ')';
        }
        $this->session->set_flashdata('success', $message);
        redirect('workorders');
    }

    public function mark_done($id = 0)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        if ($id <= 0) {
            $this->session->set_flashdata('error', 'ID Work Order tidak valid.');
            return redirect('workorders');
        }

        if (!$this->db->table_exists('work_orders')) {
            $this->session->set_flashdata('error', 'Tabel work_orders tidak ditemukan.');
            return redirect('workorders');
        }

        $fields = $this->db->list_fields('work_orders');
        if (empty($fields)) {
            $this->session->set_flashdata('error', 'Struktur tabel work_orders tidak terbaca.');
            return redirect('workorders');
        }

        $wo_qb = $this->db
            ->select(in_array('completion_notes', $fields, true) ? 'id, wo_number, status, completion_notes' : 'id, wo_number, status')
            ->from('work_orders')
            ->where('id', $id);
        if (in_array('router_id', $fields, true)) {
            $this->apply_router_scope_clause($wo_qb, 'work_orders');
        }
        $wo = $wo_qb->limit(1)->get()->row_array();
        if (empty($wo)) {
            $this->session->set_flashdata('error', 'Work Order tidak ditemukan.');
            return redirect('workorders');
        }

        $current_status = strtolower((string) ($wo['status'] ?? ''));
        if (in_array($current_status, array('done', 'activated', 'cancel', 'completed'), true)) {
            $this->session->set_flashdata('success', 'WO ' . (string) ($wo['wo_number'] ?? ('#' . $id)) . ' sudah selesai.');
            return redirect('workorders');
        }

        $status_done = $this->resolve_table_enum_value(
            'work_orders',
            'status',
            array('done', 'DONE', 'completed', 'COMPLETED'),
            'done'
        );

        $now = date('Y-m-d H:i:s');
        $update = array('status' => $status_done);
        if (in_array('actual_end_at', $fields, true)) {
            $update['actual_end_at'] = $now;
        }
        if (in_array('done_at', $fields, true)) {
            $update['done_at'] = $now;
        }
        if (in_array('updated_at', $fields, true)) {
            $update['updated_at'] = $now;
        }
        if (in_array('completion_notes', $fields, true)) {
            $note = 'WO selesai dari Work Order List';
            $existing = trim((string) ($wo['completion_notes'] ?? ''));
            $update['completion_notes'] = $existing !== '' ? ($existing . "\n" . $note) : $note;
        }

        $update_qb = $this->db->where('id', $id);
        if (in_array('router_id', $fields, true)) {
            $this->apply_router_scope_clause($update_qb, 'work_orders');
        }
        $ok = $update_qb->update('work_orders', $update);
        if (!$ok) {
            $error = $this->db->error();
            $this->session->set_flashdata('error', 'Gagal update WO: ' . (string) ($error['message'] ?? 'unknown'));
            return redirect('workorders');
        }

        $this->notify_wo_status_changed($id, $current_status, (string) $status_done);

        $this->session->set_flashdata(
            'success',
            'WO ' . (string) ($wo['wo_number'] ?? ('#' . $id)) . ' berhasil diubah ke DONE.'
        );
        return redirect('workorders');
    }

    public function delete($id = 0)
    {
        $this->require_role(array('superadmin', 'admin'));

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        if ($id <= 0) {
            $this->session->set_flashdata('error', 'ID Work Order tidak valid.');
            return redirect('workorders');
        }

        if (!$this->db->table_exists('work_orders')) {
            $this->session->set_flashdata('error', 'Tabel work_orders tidak ditemukan.');
            return redirect('workorders');
        }

        $fields = !empty($this->work_order_fields) ? $this->work_order_fields : $this->db->list_fields('work_orders');
        if (empty($fields)) {
            $this->session->set_flashdata('error', 'Struktur tabel work_orders tidak terbaca.');
            return redirect('workorders');
        }

        $wo = $this->db
            ->select('id, wo_number')
            ->where('id', $id);
        if (in_array('router_id', $fields, true)) {
            $this->apply_router_scope_clause($wo, 'work_orders');
        }
        $wo = $wo->limit(1)->get('work_orders')->row_array();

        if (empty($wo)) {
            $this->session->set_flashdata('error', 'Work Order tidak ditemukan.');
            return redirect('workorders');
        }

        $role = (string) $this->session->userdata('role');
        if ($role === 'superadmin') {
            $delete_qb = $this->db->where('id', $id);
            if (in_array('router_id', $fields, true)) {
                $this->apply_router_scope_clause($delete_qb, 'work_orders');
            }
            $ok = $delete_qb->delete('work_orders');
            if (!$ok) {
                $error = $this->db->error();
                $this->session->set_flashdata('error', 'Gagal hapus WO: ' . (string) ($error['message'] ?? 'unknown'));
                return redirect('workorders');
            }

            $this->session->set_flashdata('success', 'WO ' . (string) ($wo['wo_number'] ?? ('#' . $id)) . ' berhasil dihapus.');
            return redirect('workorders');
        }

        $soft_payload = $this->build_soft_delete_payload($fields);
        if (empty($soft_payload)) {
            $this->session->set_flashdata('error', 'Role admin hanya bisa soft delete, tetapi kolom soft delete tidak tersedia. Hubungi superadmin.');
            return redirect('workorders');
        }

        $soft_qb = $this->db->where('id', $id);
        if (in_array('router_id', $fields, true)) {
            $this->apply_router_scope_clause($soft_qb, 'work_orders');
        }
        $ok = $soft_qb->update('work_orders', $soft_payload);
        if (!$ok) {
            $error = $this->db->error();
            $this->session->set_flashdata('error', 'Gagal soft delete WO: ' . (string) ($error['message'] ?? 'unknown'));
            return redirect('workorders');
        }

        $this->session->set_flashdata('success', 'WO ' . (string) ($wo['wo_number'] ?? ('#' . $id)) . ' dihapus (soft delete) dan menunggu tindak lanjut superadmin.');
        return redirect('workorders');
    }

    private function build_list_query($search, $month, $year, $count_only = false)
    {
        $has_customers_table = $this->db->table_exists('customers');
        $qb = $this->db->from('work_orders w');
        $this->apply_router_scope_clause($qb, 'w');
        if (in_array('deleted_at', $this->work_order_fields, true)) {
            $qb->where('w.deleted_at IS NULL', null, false);
        } elseif (in_array('is_deleted', $this->work_order_fields, true)) {
            $qb->where('w.is_deleted', 0);
        } elseif (in_array('deleted', $this->work_order_fields, true)) {
            $qb->where('w.deleted', 0);
        }

        if (!$count_only) {
            $select = array(
                'w.id',
                'w.wo_number',
                'w.status',
            );

            if (in_array('wo_type', $this->work_order_fields, true)) {
                $select[] = 'w.wo_type';
            } elseif (in_array('type', $this->work_order_fields, true)) {
                $select[] = 'w.type AS wo_type';
            } else {
                $select[] = "'' AS wo_type";
            }

            if (in_array('scheduled_start_at', $this->work_order_fields, true)) {
                $select[] = 'w.scheduled_start_at';
            } elseif (in_array('scheduled_date', $this->work_order_fields, true)) {
                $select[] = 'w.scheduled_date AS scheduled_start_at';
            } else {
                $select[] = 'NULL AS scheduled_start_at';
            }

            if (in_array('requested_date', $this->work_order_fields, true)) {
                $select[] = 'w.requested_date';
            } else {
                $select[] = 'NULL AS requested_date';
            }

            $customer_name_parts = array();
            if ($has_customers_table) {
                if (in_array('full_name', $this->customer_fields, true)) {
                    $customer_name_parts[] = "NULLIF(c.full_name, '')";
                }
                if (in_array('nama', $this->customer_fields, true)) {
                    $customer_name_parts[] = "NULLIF(c.nama, '')";
                }
                if (in_array('customer_code', $this->customer_fields, true)) {
                    $customer_name_parts[] = "NULLIF(c.customer_code, '')";
                }
            }
            if (in_array('title', $this->work_order_fields, true)) {
                $customer_name_parts[] = "NULLIF(w.title, '')";
            }
            if (in_array('description', $this->work_order_fields, true)) {
                $customer_name_parts[] = "NULLIF(w.description, '')";
            }
            if (!empty($customer_name_parts)) {
                $select[] = 'COALESCE(' . implode(', ', $customer_name_parts) . ", '-') AS customer_name";
            } else {
                $select[] = "'-' AS customer_name";
            }

            $qb->select(implode(', ', $select), false);
        }

        if ($has_customers_table) {
            $qb->join('customers c', 'c.id = w.customer_id', 'left');
        }

        $date_column = $this->resolve_list_filter_date_column();
        if ($date_column !== '') {
            $this->apply_month_filter_clause($qb, 'w', $date_column, $month, $year);
        }

        if ($search !== '') {
            $has_searchable = in_array('wo_number', $this->work_order_fields, true)
                || in_array('title', $this->work_order_fields, true);
            if (!$has_searchable && $has_customers_table) {
                foreach (array('full_name', 'nama', 'customer_code') as $field) {
                    if (in_array($field, $this->customer_fields, true)) {
                        $has_searchable = true;
                        break;
                    }
                }
            }
            if (!$has_searchable) {
                return $qb;
            }

            $qb->group_start();
            $has_prev = false;

            if (in_array('wo_number', $this->work_order_fields, true)) {
                $qb->like('w.wo_number', $search);
                $has_prev = true;
            }

            if (in_array('title', $this->work_order_fields, true)) {
                if ($has_prev) {
                    $qb->or_like('w.title', $search);
                } else {
                    $qb->like('w.title', $search);
                    $has_prev = true;
                }
            }

            if ($has_customers_table) {
                foreach (array('full_name', 'nama', 'customer_code') as $field) {
                    if (!in_array($field, $this->customer_fields, true)) {
                        continue;
                    }

                    if ($has_prev) {
                        $qb->or_like('c.' . $field, $search);
                    } else {
                        $qb->like('c.' . $field, $search);
                        $has_prev = true;
                    }
                }
            }

            $qb->group_end();
        }

        if (!$count_only) {
            $qb->order_by('w.id', 'DESC');
        }

        return $qb;
    }

    private function build_soft_delete_payload(array $fields)
    {
        $now = date('Y-m-d H:i:s');
        $payload = array();

        if (in_array('deleted_at', $fields, true)) {
            $payload['deleted_at'] = $now;
        }
        if (in_array('deleted_by', $fields, true)) {
            $payload['deleted_by'] = (int) $this->session->userdata('user_id');
        }
        if (in_array('is_deleted', $fields, true)) {
            $payload['is_deleted'] = 1;
        }
        if (in_array('deleted', $fields, true)) {
            $payload['deleted'] = 1;
        }
        if (in_array('updated_at', $fields, true)) {
            $payload['updated_at'] = $now;
        }

        if (empty($payload)) {
            return array();
        }

        if (in_array('status', $fields, true)) {
            $payload['status'] = $this->resolve_table_enum_value(
                'work_orders',
                'status',
                array('cancelled', 'cancel', 'closed'),
                (string) ($payload['status'] ?? '')
            );
        }

        return $payload;
    }

    private function notify_wo_created($wo_id)
    {
        $wo_id = (int) $wo_id;
        if ($wo_id <= 0) {
            return array('success' => false, 'message' => 'WO ID tidak valid.');
        }

        $ctx = $this->get_wo_context($wo_id);
        if (empty($ctx)) {
            return array('success' => false, 'message' => 'Data WO tidak ditemukan.');
        }

        $message = '<b>🆕 WORK ORDER BARU</b>' . "\n"
            . '<b>No WO:</b> <code>' . html_escape((string) ($ctx['wo_number'] ?? '-')) . '</code>' . "\n"
            . '<b>Customer:</b> ' . html_escape((string) ($ctx['customer_name'] ?? '-')) . "\n"
            . '<b>Tipe:</b> ' . html_escape((string) ($ctx['wo_type'] ?? '-')) . "\n"
            . '<b>Prioritas:</b> ' . html_escape((string) ($ctx['priority'] ?? '-')) . "\n"
            . '<b>Status:</b> ' . html_escape($this->format_status_label((string) ($ctx['status'] ?? 'open'))) . "\n"
            . '<b>Teknisi:</b> ' . html_escape((string) ($ctx['assigned_name'] ?? '-'));

        $schedule = trim((string) ($ctx['scheduled_start_at'] ?? ''));
        if ($schedule !== '') {
            $message .= "\n" . '<b>Jadwal:</b> ' . html_escape($schedule);
        }

        $router_id = (int) ($ctx['router_id'] ?? 0);
        return $this->send_telegram_message($message, 'WO_CREATE', $router_id, array('teknisi', 'admin'));
    }

    private function notify_wo_status_changed($wo_id, $old_status, $new_status)
    {
        $wo_id = (int) $wo_id;
        if ($wo_id <= 0) {
            return array('success' => false, 'message' => 'WO ID tidak valid.');
        }

        $ctx = $this->get_wo_context($wo_id);
        if (empty($ctx)) {
            return array('success' => false, 'message' => 'Data WO tidak ditemukan.');
        }

        $old_label = $this->format_status_label((string) $old_status);
        $new_label = $this->format_status_label((string) $new_status);

        $message = '<b>✅ WORK ORDER UPDATE</b>' . "\n"
            . '<b>No WO:</b> <code>' . html_escape((string) ($ctx['wo_number'] ?? '-')) . '</code>' . "\n"
            . '<b>Customer:</b> ' . html_escape((string) ($ctx['customer_name'] ?? '-')) . "\n"
            . '<b>Status:</b> ' . html_escape($old_label) . ' → <b>' . html_escape($new_label) . '</b>' . "\n"
            . '<b>Teknisi:</b> ' . html_escape((string) ($ctx['assigned_name'] ?? '-')) . "\n"
            . '<b>Waktu:</b> ' . html_escape(date('Y-m-d H:i:s'));

        $router_id = (int) ($ctx['router_id'] ?? 0);
        return $this->send_telegram_message($message, 'WO_STATUS', $router_id, array('teknisi', 'admin'));
    }

    private function send_telegram_message($message, $tag = 'WO', $router_id = 0, array $types = array('teknisi', 'admin'))
    {
        if (trim((string) $message) === '') {
            return array('success' => false, 'message' => 'Message kosong.');
        }

        $router_id = (int) $router_id;
        $types = array_values(array_unique(array_filter(array_map(static function ($type) {
            return trim((string) $type);
        }, $types))));
        if (empty($types)) {
            $types = array('admin');
        }

        if (!function_exists('sendTelegramByRouter')) {
            return array('success' => false, 'message' => 'Helper sendTelegramByRouter belum tersedia.');
        }

        $sent = 0;
        $failed = 0;
        $deduped = 0;
        $reasons = array();
        $queued_retries = array();
        $queue_errors = array();
        foreach ($types as $type) {
            $result = sendTelegramByRouter($router_id, $type, $message, false);
            if (!empty($result['success'])) {
                $sent_now = max(0, (int) ($result['sent'] ?? 0));
                $deduped_now = max(0, (int) ($result['deduped'] ?? 0));
                $failed_now = max(0, (int) ($result['failed'] ?? 0));
                $sent += $sent_now;
                $deduped += $deduped_now;
                $failed += $failed_now;
                if (($sent_now + $deduped_now) === 0 && !empty($result['message'])) {
                    $reasons[] = '[' . $type . '] ' . (string) $result['message'];
                }
                continue;
            }

            $failed++;
            $reasons[] = '[' . $type . '] ' . (string) ($result['message'] ?? 'gagal kirim');
            log_message('error', '[WORKORDERS][' . $tag . '][ROUTER:' . $router_id . '] ' . end($reasons));

            $retry = $this->queue_telegram_retry($message, $router_id, $type, $tag);
            if (!empty($retry['success'])) {
                $queued_retries[] = array(
                    'type' => $type,
                    'job_id' => (int) ($retry['job_id'] ?? 0),
                );
                continue;
            }

            if (!empty($retry['attempted'])) {
                $queue_errors[] = '[' . $type . '] retry queue gagal: ' . (string) ($retry['message'] ?? 'unknown');
            }
        }

        $has_delivery = ($sent > 0 || $deduped > 0);
        $has_retry = !empty($queued_retries);
        $summary = '';
        if ($has_delivery) {
            $summary = 'Notifikasi Telegram terkirim. Berhasil=' . $sent . ', gagal=' . $failed;
        } elseif ($has_retry) {
            $summary = 'Notifikasi Telegram masuk antrian. Total=' . count($queued_retries) . ', gagal=' . $failed;
        } elseif (!empty($reasons)) {
            $summary = implode(' | ', $reasons);
        } else {
            $summary = 'Tidak ada group Telegram router yang cocok.';
        }

        if ($has_retry) {
            $queue_parts = array();
            foreach ($queued_retries as $queued_retry) {
                $queue_parts[] = $queued_retry['type'] . '#job:' . (int) $queued_retry['job_id'];
            }
            $summary .= ' | retry=' . implode(', ', $queue_parts);
        }
        if (!empty($queue_errors)) {
            $summary .= ' | ' . implode(' | ', array_slice($queue_errors, 0, 2));
        }

        return array(
            'success' => ($has_delivery || $has_retry),
            'message' => $summary,
            'sent' => $sent,
            'failed' => $failed,
            'deduped' => $deduped,
            'queued_retry' => count($queued_retries),
            'router_id' => $router_id,
        );
    }

    private function get_wo_context($wo_id)
    {
        $wo_id = (int) $wo_id;
        if ($wo_id <= 0 || !$this->db->table_exists('work_orders')) {
            return array();
        }

        $select = array(
            'w.id',
            'w.wo_number',
            'w.status',
        );
        if (in_array('customer_id', $this->work_order_fields, true)) {
            $select[] = 'w.customer_id';
        } else {
            $select[] = 'NULL AS customer_id';
        }
        if (in_array('router_id', $this->work_order_fields, true)) {
            $select[] = 'w.router_id';
        } else {
            $select[] = 'NULL AS router_id';
        }
        if (in_array('wo_type', $this->work_order_fields, true)) {
            $select[] = 'w.wo_type';
        } elseif (in_array('type', $this->work_order_fields, true)) {
            $select[] = 'w.type AS wo_type';
        } else {
            $select[] = "'' AS wo_type";
        }

        if (in_array('priority', $this->work_order_fields, true)) {
            $select[] = 'w.priority';
        } else {
            $select[] = "'' AS priority";
        }

        if (in_array('scheduled_start_at', $this->work_order_fields, true)) {
            $select[] = 'w.scheduled_start_at';
        } elseif (in_array('scheduled_date', $this->work_order_fields, true)) {
            $select[] = 'w.scheduled_date AS scheduled_start_at';
        } else {
            $select[] = "'' AS scheduled_start_at";
        }

        if (in_array('assigned_to', $this->work_order_fields, true)) {
            $select[] = 'w.assigned_to';
        } else {
            $select[] = 'NULL AS assigned_to';
        }

        $customer_name_parts = array();
        if ($this->db->table_exists('customers')) {
            if (in_array('full_name', $this->customer_fields, true)) {
                $customer_name_parts[] = "NULLIF(c.full_name, '')";
            }
            if (in_array('nama', $this->customer_fields, true)) {
                $customer_name_parts[] = "NULLIF(c.nama, '')";
            }
            if (in_array('customer_code', $this->customer_fields, true)) {
                $customer_name_parts[] = "NULLIF(c.customer_code, '')";
            }
        }
        if (in_array('title', $this->work_order_fields, true)) {
            $customer_name_parts[] = "NULLIF(w.title, '')";
        }
        $select[] = !empty($customer_name_parts)
            ? 'COALESCE(' . implode(', ', $customer_name_parts) . ", '-') AS customer_name"
            : "'-' AS customer_name";

        if ($this->db->table_exists('users')) {
            if (in_array('name', $this->user_fields, true)) {
                $select[] = "COALESCE(NULLIF(u.name, ''), NULLIF(u.username, ''), '-') AS assigned_name";
            } elseif (in_array('username', $this->user_fields, true)) {
                $select[] = "COALESCE(NULLIF(u.username, ''), '-') AS assigned_name";
            } else {
                $select[] = "'-' AS assigned_name";
            }
        } else {
            $select[] = "'-' AS assigned_name";
        }

        $qb = $this->db
            ->select(implode(', ', $select), false)
            ->from('work_orders w')
            ->where('w.id', $wo_id);
        $this->apply_router_scope_clause($qb, 'w');
        $qb->limit(1);

        if ($this->db->table_exists('customers')) {
            $qb->join('customers c', 'c.id = w.customer_id', 'left');
        }
        if ($this->db->table_exists('users') && in_array('assigned_to', $this->work_order_fields, true)) {
            $qb->join('users u', 'u.id = w.assigned_to', 'left');
        }

        $row = $qb->get()->row_array();
        if (!is_array($row) || empty($row)) {
            return array();
        }

        if ((int) ($row['router_id'] ?? 0) <= 0) {
            $row['router_id'] = $this->resolve_router_id_from_customer_service((int) ($row['customer_id'] ?? 0));
        }

        return $row;
    }

    private function resolve_router_id_from_customer_service($customer_id)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0 || !$this->db->table_exists('customer_services')) {
            return 0;
        }
        if (!in_array('customer_id', $this->service_fields, true) || !in_array('router_id', $this->service_fields, true)) {
            return 0;
        }

        $qb = $this->db
            ->select('router_id')
            ->from('customer_services')
            ->where('customer_id', $customer_id)
            ->where('router_id >', 0);

        if (in_array('status', $this->service_fields, true)) {
            $qb->where_in('LOWER(status)', array('active', 'suspended', 'isolated', 'isolir', 'pending'));
        }
        if (in_array('id', $this->service_fields, true)) {
            $qb->order_by('id', 'DESC');
        }

        $row = (array) $qb->limit(1)->get()->row_array();
        return (int) ($row['router_id'] ?? 0);
    }

    private function format_status_label($status)
    {
        $status = strtolower(trim((string) $status));
        if ($status === 'in_progress') {
            return 'PROCESS';
        }
        if ($status === 'completed') {
            return 'DONE';
        }
        if ($status === 'cancelled') {
            return 'CANCEL';
        }
        if ($status === '') {
            return '-';
        }
        return strtoupper($status);
    }

    private function resolve_table_enum_value($table, $column, array $candidates, $fallback = '')
    {
        $table = trim((string) $table);
        $column = trim((string) $column);
        if ($table === '' || $column === '' || empty($candidates)) {
            return (string) $fallback;
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return (string) $fallback;
        }
        if (!$this->db->table_exists($table)) {
            return (string) $fallback;
        }

        $query = $this->db->query("SHOW COLUMNS FROM `" . $this->db->escape_str($table) . "` LIKE " . $this->db->escape($column));
        $row = $query ? $query->row_array() : null;
        if (empty($row['Type']) || stripos((string) $row['Type'], 'enum(') !== 0) {
            return (string) $fallback;
        }

        $type = (string) $row['Type'];
        if (!preg_match_all("/'((?:\\\\'|[^'])*)'/", $type, $m) || empty($m[1])) {
            return (string) $fallback;
        }

        $values = array_map(static function ($value) {
            return str_replace("\\'", "'", (string) $value);
        }, $m[1]);

        foreach ($candidates as $candidate) {
            foreach ($values as $enum_value) {
                if (strcasecmp((string) $enum_value, (string) $candidate) === 0) {
                    return (string) $enum_value;
                }
            }
        }

        return in_array($fallback, $values, true) ? (string) $fallback : (string) ($values[0] ?? $fallback);
    }

    private function get_customer_options($limit = 2000)
    {
        if (!$this->db->table_exists('customers') || empty($this->customer_fields)) {
            return array();
        }

        $name_col = $this->resolve_customer_name_column();
        if ($name_col === '') {
            return array();
        }

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS customer_name', false)
            ->from('customers');
        $this->apply_router_scope_clause($qb, 'customers');

        if (in_array('deleted_at', $this->customer_fields, true)) {
            $qb->where('deleted_at IS NULL', null, false);
        } elseif (in_array('is_deleted', $this->customer_fields, true)) {
            $qb->where('is_deleted', 0);
        }

        return $qb->order_by($name_col, 'ASC')->limit((int) $limit)->get()->result_array();
    }

    private function get_teknisi_options()
    {
        if (!$this->db->table_exists('users') || empty($this->user_fields)) {
            return array();
        }
        if (!in_array('id', $this->user_fields, true) || !in_array('role', $this->user_fields, true)) {
            return array();
        }

        $name_col = in_array('name', $this->user_fields, true) ? 'name' : (in_array('username', $this->user_fields, true) ? 'username' : '');
        if ($name_col === '') {
            return array();
        }

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS name', false)
            ->from('users')
            ->where('role', 'teknisi');
        if (in_array('router_scope_id', $this->user_fields, true)) {
            $effective_router_id = $this->effective_router_id();
            if ($effective_router_id !== null) {
                $qb->where('router_scope_id', (int) $effective_router_id);
            } elseif (!$this->is_superadmin()) {
                $qb->where('1 = 0', null, false);
            }
        }

        if (in_array('status', $this->user_fields, true)) {
            $qb->where('status', 'active');
        }

        return $qb->order_by($name_col, 'ASC')->get()->result_array();
    }

    private function teknisi_exists($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !$this->db->table_exists('users')) {
            return false;
        }

        $qb = $this->db
            ->select('id')
            ->from('users')
            ->where('id', $user_id)
            ->where('role', 'teknisi')
            ->limit(1);
        if (in_array('router_scope_id', $this->user_fields, true)) {
            $effective_router_id = $this->effective_router_id();
            if ($effective_router_id !== null) {
                $qb->where('router_scope_id', (int) $effective_router_id);
            } elseif (!$this->is_superadmin()) {
                $qb->where('1 = 0', null, false);
            }
        }
        if (!empty($this->user_fields) && in_array('status', $this->user_fields, true)) {
            $qb->where('status', 'active');
        }

        return !empty($qb->get()->row_array());
    }

    private function resolve_customer_name_column()
    {
        foreach (array('full_name', 'nama', 'customer_name', 'name', 'customer_code') as $column) {
            if (in_array($column, $this->customer_fields, true)) {
                return $column;
            }
        }
        return '';
    }

    private function is_valid_ymd_date($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $ts = strtotime($value);
        return $ts !== false && date('Y-m-d', $ts) === $value;
    }

    private function normalize_datetime_input($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return '';
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function generate_wo_number()
    {
        $prefix = 'WO-' . date('YmdHis') . '-';
        for ($i = 0; $i < 20; $i++) {
            $candidate = $prefix . str_pad((string) random_int(1, 99), 2, '0', STR_PAD_LEFT);
            if (!in_array('wo_number', $this->work_order_fields, true)) {
                return $candidate;
            }

            $exists = $this->db
                ->select('id')
                ->from('work_orders')
                ->where('wo_number', $candidate)
                ->limit(1)
                ->get()
                ->row_array();
            if (empty($exists)) {
                return $candidate;
            }
        }

        return $prefix . str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT);
    }

    private function is_async_queue_enabled()
    {
        $this->config->load('queue', true);
        $cfg = (array) $this->config->item('queue');
        if (!is_array($cfg)) {
            return false;
        }

        return !empty($cfg['queue_enable_async']);
    }

    private function queue_telegram_retry($message, $router_id, $type, $tag)
    {
        $message = trim((string) $message);
        $type = trim((string) $type);
        $router_id = (int) $router_id;

        if ($message === '' || $type === '' || $router_id <= 0 || !$this->is_async_queue_enabled()) {
            return array(
                'success' => false,
                'attempted' => false,
                'message' => 'Retry queue dilewati.',
            );
        }

        $this->load->library('jobdispatcher');
        $dispatch = $this->jobdispatcher->dispatch(
            null,
            'telegram_send',
            array(
                'group_type' => $type,
                'router_id' => $router_id,
                'message' => $message,
                'parse_mode' => 'HTML',
            ),
            5
        );

        if (!empty($dispatch['success'])) {
            log_message(
                'info',
                '[WORKORDERS][' . $tag . '][ROUTER:' . $router_id . '] retry queued type=' . $type
                . ' job_id=' . (int) ($dispatch['job_id'] ?? 0)
            );
        } else {
            log_message(
                'error',
                '[WORKORDERS][' . $tag . '][ROUTER:' . $router_id . '] retry queue failed type=' . $type
                . ' message=' . (string) ($dispatch['message'] ?? 'unknown')
            );
        }

        $dispatch['attempted'] = true;
        return $dispatch;
    }

    private function effective_router_id()
    {
        $effective = $this->getEffectiveRouterId();
        if ($effective !== null && (int) $effective > 0) {
            return (int) $effective;
        }
        return null;
    }

    private function apply_router_scope_clause(CI_DB_query_builder $qb, $table_alias = '')
    {
        $effective_router_id = $this->effective_router_id();
        $prefix = trim((string) $table_alias) !== '' ? trim((string) $table_alias) . '.' : '';

        if ($effective_router_id !== null) {
            $qb->where($prefix . 'router_id', $effective_router_id);
            return;
        }

        if (!$this->is_superadmin()) {
            // Secure default untuk user scoped tanpa router.
            $qb->where('1 = 0', null, false);
        }
    }

    private function normalize_month($month)
    {
        $month = (int) $month;
        if ($month < 1 || $month > 12) {
            return (int) date('m');
        }
        return $month;
    }

    private function normalize_year($year)
    {
        $year = (int) $year;
        if ($year < 2000 || $year > 2100) {
            return (int) date('Y');
        }
        return $year;
    }

    private function month_options()
    {
        $months = array();
        for ($i = 1; $i <= 12; $i++) {
            $months[$i] = date('F', mktime(0, 0, 0, $i, 1));
        }
        return $months;
    }

    private function year_options()
    {
        $current = (int) date('Y');
        $years = array();
        for ($i = $current - 3; $i <= $current + 1; $i++) {
            $years[] = $i;
        }
        return $years;
    }

    private function format_period_label($month, $year)
    {
        $month = $this->normalize_month($month);
        $year = $this->normalize_year($year);

        return date('F Y', strtotime($year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-01'));
    }

    private function resolve_list_filter_date_column()
    {
        foreach (array('requested_date', 'scheduled_start_at', 'scheduled_date', 'created_at') as $column) {
            if (in_array($column, $this->work_order_fields, true)) {
                return $column;
            }
        }

        return '';
    }

    private function apply_month_filter_clause(CI_DB_query_builder $qb, $table_alias, $column, $month, $year)
    {
        $column = trim((string) $column);
        if ($column === '') {
            return;
        }

        $month = $this->normalize_month($month);
        $year = $this->normalize_year($year);
        $start_date = $year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-01';
        $end_date = date('Y-m-t', strtotime($start_date));
        $expr = trim((string) $table_alias) !== '' ? trim((string) $table_alias) . '.' . $column : $column;

        if (in_array($column, array('requested_date', 'scheduled_date'), true)) {
            $qb->where('DATE(' . $expr . ') >= ' . $this->db->escape($start_date), null, false);
            $qb->where('DATE(' . $expr . ') <= ' . $this->db->escape($end_date), null, false);
            return;
        }

        $qb->where($expr . ' >= ' . $this->db->escape($start_date . ' 00:00:00'), null, false);
        $qb->where($expr . ' <= ' . $this->db->escape($end_date . ' 23:59:59'), null, false);
    }

    private function filter_fallback_rows_by_period(array $rows, $month, $year)
    {
        $month = $this->normalize_month($month);
        $year = $this->normalize_year($year);

        return array_values(array_filter($rows, static function ($row) use ($month, $year) {
            $date_value = trim((string) ($row['requested_date'] ?? $row['scheduled_start_at'] ?? ''));
            if ($date_value === '' || strtotime($date_value) === false) {
                return false;
            }

            return (int) date('m', strtotime($date_value)) === $month
                && (int) date('Y', strtotime($date_value)) === $year;
        }));
    }

    private function fallback_rows()
    {
        return array(
            array('wo_number' => 'WO-202602-0012', 'customer_name' => 'Budi Santoso', 'wo_type' => 'installation', 'status' => 'open', 'scheduled_start_at' => '2026-02-21', 'requested_date' => '2026-02-21'),
            array('wo_number' => 'WO-202602-0011', 'customer_name' => 'Nina Saputri', 'wo_type' => 'installation', 'status' => 'in_progress', 'scheduled_start_at' => '2026-02-20', 'requested_date' => '2026-02-20'),
            array('wo_number' => 'WO-202602-0010', 'customer_name' => 'Rizal Pratama', 'wo_type' => 'maintenance', 'status' => 'completed', 'scheduled_start_at' => '2026-02-20', 'requested_date' => '2026-02-20'),
            array('wo_number' => 'WO-202602-0009', 'customer_name' => 'Sari Wulandari', 'wo_type' => 'installation', 'status' => 'open', 'scheduled_start_at' => '2026-02-22', 'requested_date' => '2026-02-22'),
        );
    }
}
