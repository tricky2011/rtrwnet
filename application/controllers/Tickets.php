<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Tickets extends MY_Controller
{
    private $ticket_fields = array();
    private $user_fields = array();
    private $customer_fields = array();

    public function __construct()
    {
        parent::__construct();
        $this->require_module_access('tickets', 'Akses ditolak. Modul Ticket hanya untuk role yang diizinkan.');
        $this->load->database();
        $this->load->helper(array('url', 'form', 'tenant', 'notification'));
        $this->load->library('form_validation');
        $this->load->model('Settings_model', 'settings_model');
        $this->load->library('telegram_service');

        if ($this->db->table_exists('tickets')) {
            $this->ticket_fields = $this->db->list_fields('tickets');
        }
        if ($this->db->table_exists('users')) {
            $this->user_fields = $this->db->list_fields('users');
        }
        if ($this->db->table_exists('customers')) {
            $this->customer_fields = $this->db->list_fields('customers');
        }
    }

    public function index()
    {
        $search = trim((string) $this->input->get('search', true));
        $status_filter = $this->normalize_status_filter((string) $this->input->get('status', true));
        $priority_filter = $this->normalize_priority_filter((string) $this->input->get('priority', true));
        $role = (string) $this->session->userdata('role');
        $can_create = in_array($role, array('superadmin', 'admin'), true);

        if ($this->db->table_exists('tickets')) {
            $total_rows = $this->build_list_query($search, $status_filter, $priority_filter, true)->count_all_results();
            $pager = $this->init_pagination('tickets', $total_rows, 20, 3);
            $rows = $this->build_list_query($search, $status_filter, $priority_filter, false)
                ->limit($pager['per_page'], $pager['offset'])
                ->get()
                ->result_array();

            return $this->load->view('tickets/list', array(
                'rows' => $rows,
                'search' => $search,
                'status_filter' => $status_filter,
                'priority_filter' => $priority_filter,
                'status_options' => $this->status_filter_options(),
                'priority_options' => $this->priority_filter_options(),
                'role' => $role,
                'can_create' => $can_create,
                'teknisi_options' => $this->get_teknisi_options(),
                'customer_options' => $this->get_customer_options(),
                'pagination' => $pager['links'],
                'total_rows' => $pager['total_rows'],
                'per_page' => (int) $pager['per_page'],
                'per_page_options' => $this->get_per_page_options(),
            ));
        }

        $rows = $this->fallback_rows();
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static function ($row) use ($search) {
                $needle = strtolower($search);
                return strpos(strtolower((string) ($row['ticket_number'] ?? '')), $needle) !== false
                    || strpos(strtolower((string) ($row['subject'] ?? '')), $needle) !== false;
            }));
        }
        if ($status_filter !== '') {
            $rows = array_values(array_filter($rows, static function ($row) use ($status_filter) {
                return strtolower((string) ($row['status'] ?? '')) === $status_filter;
            }));
        }
        if ($priority_filter !== '') {
            $rows = array_values(array_filter($rows, static function ($row) use ($priority_filter) {
                return strtolower((string) ($row['priority'] ?? '')) === $priority_filter;
            }));
        }

        $pager = $this->init_pagination('tickets', count($rows), 20, 3);
        $paged_rows = array_slice($rows, $pager['offset'], $pager['per_page']);

        return $this->load->view('tickets/list', array(
            'rows' => $paged_rows,
            'search' => $search,
            'status_filter' => $status_filter,
            'priority_filter' => $priority_filter,
            'status_options' => $this->status_filter_options(),
            'priority_options' => $this->priority_filter_options(),
            'role' => $role,
            'can_create' => $can_create,
            'teknisi_options' => $this->get_teknisi_options(),
            'customer_options' => $this->get_customer_options(),
            'pagination' => $pager['links'],
            'total_rows' => $pager['total_rows'],
            'per_page' => (int) $pager['per_page'],
            'per_page_options' => $this->get_per_page_options(),
        ));
    }

    public function store()
    {
        $this->require_role(array('superadmin', 'admin'));

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->db->table_exists('tickets')) {
            $this->session->set_flashdata('error', 'Tabel tickets tidak ditemukan.');
            redirect('tickets');
            return;
        }

        $this->form_validation->set_rules('subject', 'Subject', 'trim|required|max_length[255]');
        $this->form_validation->set_rules('customer_name', 'Nama User', 'trim|max_length[150]');
        $this->form_validation->set_rules('area', 'Area', 'trim|max_length[150]');
        $this->form_validation->set_rules('customer_id', 'Customer', 'trim');
        $this->form_validation->set_rules('priority', 'Prioritas', 'trim|required|in_list[low,medium,high,critical]');
        $this->form_validation->set_rules('ticket_type', 'Jenis Tiket', 'trim|required|in_list[gangguan,maintenance]');
        $this->form_validation->set_rules('assigned_to', 'Assigned Teknisi', 'trim');
        $this->form_validation->set_rules('description', 'Deskripsi', 'trim');

        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', trim(strip_tags(validation_errors(' ', ' '))));
            redirect('tickets');
            return;
        }

        $subject = trim((string) $this->input->post('subject', true));
        $customer_id = (int) $this->input->post('customer_id', true);
        $customer_name = trim((string) $this->input->post('customer_name', true));
        $area = trim((string) $this->input->post('area', true));
        $priority = $this->normalize_priority_filter((string) $this->input->post('priority', true));
        $ticket_type = strtolower(trim((string) $this->input->post('ticket_type', true)));
        $description = trim((string) $this->input->post('description', true));
        $assigned_to = (int) $this->input->post('assigned_to', true);

        if ($customer_id > 0) {
            $customer_context = $this->get_customer_context($customer_id);
            if (empty($customer_context)) {
                $this->session->set_flashdata('error', 'Customer tidak ditemukan.');
                redirect('tickets');
                return;
            }

            if ($customer_name === '' && !empty($customer_context['name'])) {
                $customer_name = (string) $customer_context['name'];
            }
            if ($area === '' && !empty($customer_context['area'])) {
                $area = (string) $customer_context['area'];
            }
        }

        $customer_name = trim($customer_name);
        $area = trim($area);
        if ($customer_name === '' || $area === '') {
            $this->session->set_flashdata('error', 'Nama user dan area wajib diisi.');
            redirect('tickets');
            return;
        }

        if ($priority === '') {
            $priority = 'medium';
        }
        if (!in_array($ticket_type, array('gangguan', 'maintenance'), true)) {
            $ticket_type = 'gangguan';
        }

        $assigned_name = '-';
        if ($this->has_ticket_field('assigned_to') && $assigned_to > 0) {
            $tech = $this->get_teknisi_by_user_id($assigned_to);
            if (empty($tech)) {
                $this->session->set_flashdata('error', 'Assigned teknisi tidak valid atau tidak aktif.');
                redirect('tickets');
                return;
            }
            $assigned_name = (string) ($tech['name'] ?? $tech['username'] ?? ('User #' . $assigned_to));
        } else {
            $assigned_to = 0;
        }

        $now = date('Y-m-d H:i:s');
        $payload = array();
        if ($this->has_ticket_field('ticket_number')) {
            $payload['ticket_number'] = $this->next_ticket_number($now);
        }
        if ($this->has_ticket_field('subject')) {
            $payload['subject'] = $subject;
        }
        if ($this->has_ticket_field('priority')) {
            $payload['priority'] = $this->resolve_table_enum_value('tickets', 'priority', array($priority), $priority);
        }
        if ($this->has_ticket_field('status')) {
            $payload['status'] = $this->resolve_table_enum_value(
                'tickets',
                'status',
                array('open', 'OPEN', 'new', 'NEW'),
                'open'
            );
        }
        if ($this->has_ticket_field('customer_id') && $customer_id > 0) {
            $payload['customer_id'] = $customer_id;
        }
        if ($this->has_ticket_field('customer_name')) {
            $payload['customer_name'] = $customer_name;
        } elseif ($this->has_ticket_field('customer')) {
            $payload['customer'] = $customer_name;
        }
        if ($this->has_ticket_field('area')) {
            $payload['area'] = $area;
        } elseif ($this->has_ticket_field('lokasi')) {
            $payload['lokasi'] = $area;
        }
        $type_value = $ticket_type;
        if ($this->has_ticket_field('ticket_type')) {
            $payload['ticket_type'] = $type_value;
        } elseif ($this->has_ticket_field('type')) {
            $payload['type'] = $type_value;
        } elseif ($this->has_ticket_field('category')) {
            $payload['category'] = $type_value;
        }

        if ($this->has_ticket_field('assigned_to') && $assigned_to > 0) {
            $payload['assigned_to'] = $assigned_to;
        }
        $description_lines = array();
        $description_lines[] = 'User/Customer: ' . $customer_name;
        $description_lines[] = 'Area: ' . $area;
        if ($description !== '') {
            $description_lines[] = $description;
        }
        $description_compiled = trim(implode("\n", $description_lines));

        if ($description_compiled !== '') {
            if ($this->has_ticket_field('description')) {
                $payload['description'] = $description_compiled;
            } elseif ($this->has_ticket_field('details')) {
                $payload['details'] = $description_compiled;
            } elseif ($this->has_ticket_field('issue_description')) {
                $payload['issue_description'] = $description_compiled;
            } elseif ($this->has_ticket_field('notes')) {
                $payload['notes'] = $description_compiled;
            }
        }

        if ($this->has_ticket_field('created_by')) {
            $payload['created_by'] = (int) $this->session->userdata('user_id');
        }
        if ($this->has_ticket_field('requested_by')) {
            $payload['requested_by'] = (int) $this->session->userdata('user_id');
        }
        if ($this->has_ticket_field('open_at')) {
            $payload['open_at'] = $now;
        }
        if ($this->has_ticket_field('created_at')) {
            $payload['created_at'] = $now;
        }
        if ($this->has_ticket_field('updated_at')) {
            $payload['updated_at'] = $now;
        }

        if (empty($payload)) {
            $this->session->set_flashdata('error', 'Struktur tabel tickets belum mendukung input tiket.');
            redirect('tickets');
            return;
        }

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('tickets', $payload);
        $error = $this->db->error();
        $insert_id = (int) $this->db->insert_id();
        $this->db->db_debug = $old_debug;

        if (!$ok) {
            log_message('error', '[TICKETS][STORE] insert failed: ' . json_encode($error) . ' payload=' . json_encode($payload));
            $this->session->set_flashdata('error', 'Gagal membuat tiket: ' . (string) ($error['message'] ?? 'unknown'));
            redirect('tickets');
            return;
        }

        $ticket_number = (string) ($payload['ticket_number'] ?? ('#' . $insert_id));
        $telegram = $this->send_ticket_created_telegram(array(
            'ticket_number' => $ticket_number,
            'subject' => $subject,
            'priority' => $priority,
            'status' => (string) ($payload['status'] ?? 'open'),
            'ticket_type' => $ticket_type,
            'assigned_name' => $assigned_name,
            'description' => $description_compiled,
            'customer_name' => $customer_name,
            'area' => $area,
            'customer_id' => $customer_id,
        ));

        if (!empty($telegram['success'])) {
            $this->session->set_flashdata('success', 'Tiket ' . $ticket_number . ' berhasil dibuat dan notifikasi Telegram terkirim.');
        } else {
            $this->session->set_flashdata('success', 'Tiket ' . $ticket_number . ' berhasil dibuat. Telegram: ' . (string) ($telegram['message'] ?? 'gagal terkirim'));
        }

        $notif_type = 'info';
        if ($priority === 'critical') {
            $notif_type = 'critical';
        } elseif ($priority === 'high') {
            $notif_type = 'warning';
        }
        $router_id_notif = $this->resolve_router_id_from_customer($customer_id);
        if (function_exists('create_notification_for_roles')) {
            create_notification_for_roles(
                array('superadmin', 'admin', 'teknisi'),
                array(
                    'type' => $notif_type,
                    'category' => 'ticket',
                    'title' => 'Ticket baru: ' . $ticket_number,
                    'message' => 'Tiket ' . $ticket_number . ' (' . strtoupper($ticket_type) . ') untuk ' . $customer_name . ' - ' . $subject,
                    'reference_id' => $insert_id,
                    'reference_type' => 'ticket',
                ),
                $router_id_notif > 0 ? $router_id_notif : null
            );
        }

        redirect('tickets');
    }

    public function mark_done($id = 0)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        if ($id <= 0) {
            $this->session->set_flashdata('error', 'ID tiket tidak valid.');
            redirect('tickets');
            return;
        }
        if (!$this->db->table_exists('tickets')) {
            $this->session->set_flashdata('error', 'Tabel tickets tidak ditemukan.');
            redirect('tickets');
            return;
        }

        $ticket = $this->find_ticket_by_id($id);
        if (empty($ticket)) {
            $this->session->set_flashdata('error', 'Tiket tidak ditemukan.');
            redirect('tickets');
            return;
        }

        $role = (string) $this->session->userdata('role');
        $user_id = (int) $this->session->userdata('user_id');
        if ($role === 'teknisi' && $this->has_ticket_field('assigned_to')) {
            $assigned_to = (int) ($ticket['assigned_to'] ?? 0);
            if ($assigned_to > 0 && $assigned_to !== $user_id) {
                $this->session->set_flashdata('error', 'Anda tidak berhak menandai tiket ini.');
                redirect('tickets');
                return;
            }
        }

        $current_status = strtolower((string) ($ticket['status'] ?? ''));
        if (in_array($current_status, array('done', 'resolved', 'closed', 'completed', 'cancel'), true)) {
            $this->session->set_flashdata('success', 'Tiket sudah berstatus selesai.');
            redirect('tickets');
            return;
        }

        $done_note = trim((string) $this->input->post('done_note', true));
        if ($done_note === '') {
            $done_note = 'Tiket diselesaikan oleh teknisi via Helpdesk Tickets.';
        }

        $status_done = $this->resolve_table_enum_value(
            'tickets',
            'status',
            array('done', 'resolved', 'closed', 'completed', 'DONE', 'RESOLVED', 'CLOSED'),
            'resolved'
        );

        $now = date('Y-m-d H:i:s');
        $update = array();
        if ($this->has_ticket_field('status')) {
            $update['status'] = $status_done;
        }
        if ($this->has_ticket_field('resolved_at')) {
            $update['resolved_at'] = $now;
        }
        if ($this->has_ticket_field('closed_at')) {
            $update['closed_at'] = $now;
        }
        if ($this->has_ticket_field('updated_at')) {
            $update['updated_at'] = $now;
        }
        if ($this->has_ticket_field('resolved_by')) {
            $update['resolved_by'] = $user_id;
        }
        if ($this->has_ticket_field('handled_by')) {
            $update['handled_by'] = $user_id;
        }
        if ($this->has_ticket_field('resolution_notes')) {
            $existing = trim((string) ($ticket['resolution_notes'] ?? ''));
            $update['resolution_notes'] = $existing !== '' ? ($existing . "\n" . $done_note) : $done_note;
        } elseif ($this->has_ticket_field('notes')) {
            $existing = trim((string) ($ticket['notes'] ?? ''));
            $update['notes'] = $existing !== '' ? ($existing . "\n" . $done_note) : $done_note;
        }

        if (empty($update)) {
            $this->session->set_flashdata('error', 'Struktur tabel tickets belum mendukung update DONE.');
            redirect('tickets');
            return;
        }

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->where('id', $id)->update('tickets', $update);
        $error = $this->db->error();
        $this->db->db_debug = $old_debug;

        if (!$ok) {
            log_message('error', '[TICKETS][DONE] update failed: ' . json_encode($error) . ' ticket_id=' . $id);
            $this->session->set_flashdata('error', 'Gagal update status tiket: ' . (string) ($error['message'] ?? 'unknown'));
            redirect('tickets');
            return;
        }

        $ticket_number = (string) ($ticket['ticket_number'] ?? ('#' . $id));
        $this->session->set_flashdata('success', 'Tiket ' . $ticket_number . ' berhasil ditandai DONE.');
        $router_id_notif = $this->resolve_router_id_from_customer((int) ($ticket['customer_id'] ?? 0));
        if (function_exists('create_notification_for_roles')) {
            create_notification_for_roles(
                array('superadmin', 'admin', 'teknisi'),
                array(
                    'type' => 'success',
                    'category' => 'ticket',
                    'title' => 'Ticket resolved: ' . $ticket_number,
                    'message' => 'Tiket ' . $ticket_number . ' berhasil diselesaikan oleh ' . ((string) $this->session->userdata('name') ?: 'teknisi') . '.',
                    'reference_id' => $id,
                    'reference_type' => 'ticket',
                ),
                $router_id_notif > 0 ? $router_id_notif : null
            );
        }
        redirect('tickets');
    }

    private function build_list_query($search, $status_filter, $priority_filter, $count_only = false)
    {
        $can_join_users = $this->db->table_exists('users') && $this->has_ticket_field('assigned_to');
        $qb = $this->db->from('tickets t');
        if (!$count_only) {
            $select = array('t.id');
            if ($this->has_ticket_field('ticket_number')) {
                $select[] = 't.ticket_number';
            } else {
                $select[] = "CONCAT('TICKET-', t.id) AS ticket_number";
            }
            if ($this->has_ticket_field('subject')) {
                $select[] = 't.subject';
            } else {
                $select[] = "'-' AS subject";
            }
            if ($this->has_ticket_field('status')) {
                $select[] = 't.status';
            } else {
                $select[] = "'open' AS status";
            }
            if ($this->has_ticket_field('priority')) {
                $select[] = 't.priority';
            } else {
                $select[] = "'medium' AS priority";
            }
            if ($this->has_ticket_field('ticket_type')) {
                $select[] = 't.ticket_type';
            } elseif ($this->has_ticket_field('type')) {
                $select[] = 't.type AS ticket_type';
            } elseif ($this->has_ticket_field('category')) {
                $select[] = 't.category AS ticket_type';
            } else {
                $select[] = "'gangguan' AS ticket_type";
            }
            if ($this->has_ticket_field('description')) {
                $select[] = 't.description';
            } elseif ($this->has_ticket_field('details')) {
                $select[] = 't.details AS description';
            } elseif ($this->has_ticket_field('issue_description')) {
                $select[] = 't.issue_description AS description';
            } else {
                $select[] = "'' AS description";
            }
            if ($this->has_ticket_field('customer_name')) {
                $select[] = 't.customer_name';
            } elseif ($this->has_ticket_field('customer')) {
                $select[] = 't.customer AS customer_name';
            } else {
                $select[] = "'' AS customer_name";
            }
            if ($this->has_ticket_field('area')) {
                $select[] = 't.area';
            } elseif ($this->has_ticket_field('lokasi')) {
                $select[] = 't.lokasi AS area';
            } else {
                $select[] = "'' AS area";
            }
            if ($this->has_ticket_field('updated_at')) {
                $select[] = 't.updated_at';
            } elseif ($this->has_ticket_field('created_at')) {
                $select[] = 't.created_at AS updated_at';
            } else {
                $select[] = 'NULL AS updated_at';
            }
            if ($this->has_ticket_field('assigned_to')) {
                $select[] = 't.assigned_to';
            } else {
                $select[] = 'NULL AS assigned_to';
            }
            $assigned_name_select = "'-'";
            if ($can_join_users) {
                $assigned_name_select = $this->has_user_field('name') ? 'u.name' : ($this->has_user_field('username') ? 'u.username' : "'-'");
            }
            $select[] = "COALESCE(" . $assigned_name_select . ", '-') AS assigned_name";
            $qb->select(implode(', ', $select), false);
        }

        if ($can_join_users) {
            $qb->join('users u', 'u.id = t.assigned_to', 'left');
        }
        if ($this->has_ticket_field('deleted_at')) {
            $qb->where('t.deleted_at IS NULL', null, false);
        }

        $role = (string) $this->session->userdata('role');
        if ($role === 'teknisi' && $this->has_ticket_field('assigned_to')) {
            $qb->where('t.assigned_to', (int) $this->session->userdata('user_id'));
        }

        if ($status_filter !== '' && $this->has_ticket_field('status')) {
            $qb->where('LOWER(t.status)', $status_filter);
        }
        if ($priority_filter !== '' && $this->has_ticket_field('priority')) {
            $qb->where('LOWER(t.priority)', $priority_filter);
        }

        if ($search !== '') {
            $qb->group_start()
                ->like($this->has_ticket_field('ticket_number') ? 't.ticket_number' : 't.id', $search);
            if ($this->has_ticket_field('subject')) {
                $qb->or_like('t.subject', $search);
            }
            if ($this->has_ticket_field('status')) {
                $qb->or_like('t.status', $search);
            }
            if ($this->has_ticket_field('priority')) {
                $qb->or_like('t.priority', $search);
            }
            if ($this->has_ticket_field('description')) {
                $qb->or_like('t.description', $search);
            }
            if ($this->has_ticket_field('details')) {
                $qb->or_like('t.details', $search);
            }
            if ($this->has_ticket_field('customer_name')) {
                $qb->or_like('t.customer_name', $search);
            }
            if ($this->has_ticket_field('customer')) {
                $qb->or_like('t.customer', $search);
            }
            if ($this->has_ticket_field('area')) {
                $qb->or_like('t.area', $search);
            }
            if ($this->has_ticket_field('lokasi')) {
                $qb->or_like('t.lokasi', $search);
            }
            if ($this->db->table_exists('users') && $this->has_ticket_field('assigned_to')) {
                if ($this->has_user_field('name')) {
                    $qb->or_like('u.name', $search);
                }
                if ($this->has_user_field('username')) {
                    $qb->or_like('u.username', $search);
                }
            }
            $qb->group_end();
        }

        if (!$count_only) {
            $qb->order_by('t.id', 'DESC');
        }

        return $qb;
    }

    private function fallback_rows()
    {
        return array(
            array('id' => 1, 'ticket_number' => 'TCK-202602-001', 'subject' => 'Internet down area A', 'status' => 'open', 'priority' => 'high', 'ticket_type' => 'gangguan', 'assigned_name' => 'Andi', 'customer_name' => 'Budi', 'area' => 'KLS', 'description' => 'No internet sejak pagi.'),
            array('id' => 2, 'ticket_number' => 'TCK-202602-002', 'subject' => 'PPPoE auth gagal', 'status' => 'in_progress', 'priority' => 'medium', 'ticket_type' => 'gangguan', 'assigned_name' => 'Rama', 'customer_name' => 'Fajar', 'area' => 'BGN', 'description' => 'Customer gagal login PPPoE.'),
            array('id' => 3, 'ticket_number' => 'TCK-202602-003', 'subject' => 'Maintenance ODP sektor utara', 'status' => 'resolved', 'priority' => 'low', 'ticket_type' => 'maintenance', 'assigned_name' => 'Dian', 'customer_name' => 'Cluster Utara', 'area' => 'UTARA', 'description' => 'Preventive maintenance ODP.'),
            array('id' => 4, 'ticket_number' => 'TCK-202602-004', 'subject' => 'Router tidak bisa akses', 'status' => 'open', 'priority' => 'critical', 'ticket_type' => 'gangguan', 'assigned_name' => 'Andi', 'customer_name' => 'Nanta', 'area' => 'BEJI', 'description' => 'Router down total.'),
        );
    }

    private function normalize_status_filter($status)
    {
        $status = strtolower(trim((string) $status));
        $allowed = array('', 'open', 'in_progress', 'process', 'resolved', 'done', 'closed');
        return in_array($status, $allowed, true) ? $status : '';
    }

    private function normalize_priority_filter($priority)
    {
        $priority = strtolower(trim((string) $priority));
        $allowed = array('', 'low', 'medium', 'high', 'critical');
        return in_array($priority, $allowed, true) ? $priority : '';
    }

    private function status_filter_options()
    {
        return array(
            '' => 'Semua Status',
            'open' => 'OPEN',
            'in_progress' => 'PROCESS',
            'resolved' => 'DONE/RESOLVED',
            'closed' => 'CLOSED',
        );
    }

    private function priority_filter_options()
    {
        return array(
            '' => 'Semua Prioritas',
            'low' => 'LOW',
            'medium' => 'MEDIUM',
            'high' => 'HIGH',
            'critical' => 'CRITICAL',
        );
    }

    private function has_ticket_field($field)
    {
        return in_array((string) $field, $this->ticket_fields, true);
    }

    private function has_user_field($field)
    {
        return in_array((string) $field, $this->user_fields, true);
    }

    private function next_ticket_number($now)
    {
        $prefix = 'TCK-' . date('Ym', strtotime((string) $now)) . '-';
        $last = $this->db
            ->select('ticket_number')
            ->from('tickets')
            ->like('ticket_number', $prefix, 'after')
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();

        $next = 1;
        if (!empty($last['ticket_number'])) {
            $parts = explode('-', (string) $last['ticket_number']);
            $tail = end($parts);
            if (ctype_digit((string) $tail)) {
                $next = (int) $tail + 1;
            }
        }

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function get_teknisi_options()
    {
        $result = array();
        if (!$this->db->table_exists('users') || !$this->has_user_field('id')) {
            return $result;
        }

        $name_col = $this->has_user_field('name') ? 'name' : ($this->has_user_field('username') ? 'username' : '');
        if ($name_col === '' || !$this->has_user_field('role')) {
            return $result;
        }

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS label', false)
            ->from('users')
            ->where('role', 'teknisi');
        if ($this->has_user_field('status')) {
            $qb->where('status', 'active');
        }
        $rows = $qb->order_by($name_col, 'ASC')->get()->result_array();
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $label = trim((string) ($row['label'] ?? ''));
            if ($id <= 0 || $label === '') {
                continue;
            }
            $result[] = array('id' => $id, 'name' => $label);
        }

        return $result;
    }

    private function get_customer_options()
    {
        $result = array();
        if (!$this->db->table_exists('customers') || empty($this->customer_fields)) {
            return $result;
        }

        $name_col = $this->resolve_customer_name_column();
        if ($name_col === '') {
            return $result;
        }
        $area_col = $this->resolve_customer_area_column();

        $qb = $this->db
            ->from('customers')
            ->select('id')
            ->select($name_col . ' AS customer_name', false);
        if ($area_col !== '') {
            $qb->select($area_col . ' AS customer_area', false);
        } else {
            $qb->select("'' AS customer_area", false);
        }
        if (in_array('status', $this->customer_fields, true)) {
            $qb->where_in('status', array('active', 'pending', 'isolated', 'suspended'));
        }
        if (in_array('deleted_at', $this->customer_fields, true)) {
            $qb->where('deleted_at IS NULL', null, false);
        } elseif (in_array('is_deleted', $this->customer_fields, true)) {
            $qb->where('is_deleted', 0);
        }

        $rows = $qb->order_by($name_col, 'ASC')->limit(2000)->get()->result_array();
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['customer_name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $area = trim((string) ($row['customer_area'] ?? ''));
            $result[] = array(
                'id' => $id,
                'name' => $name,
                'area' => $area,
            );
        }

        return $result;
    }

    private function get_customer_context($customer_id)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0 || !$this->db->table_exists('customers') || empty($this->customer_fields)) {
            return array();
        }

        $name_col = $this->resolve_customer_name_column();
        if ($name_col === '') {
            return array();
        }
        $area_col = $this->resolve_customer_area_column();

        $qb = $this->db
            ->from('customers')
            ->select('id')
            ->select($name_col . ' AS customer_name', false)
            ->where('id', $customer_id)
            ->limit(1);
        if ($area_col !== '') {
            $qb->select($area_col . ' AS customer_area', false);
        } else {
            $qb->select("'' AS customer_area", false);
        }
        if (in_array('deleted_at', $this->customer_fields, true)) {
            $qb->where('deleted_at IS NULL', null, false);
        } elseif (in_array('is_deleted', $this->customer_fields, true)) {
            $qb->where('is_deleted', 0);
        }

        $row = (array) $qb->get()->row_array();
        if (empty($row)) {
            return array();
        }

        return array(
            'id' => (int) ($row['id'] ?? 0),
            'name' => trim((string) ($row['customer_name'] ?? '')),
            'area' => trim((string) ($row['customer_area'] ?? '')),
        );
    }

    private function resolve_customer_name_column()
    {
        foreach (array('full_name', 'nama', 'customer_name', 'name') as $column) {
            if (in_array($column, $this->customer_fields, true)) {
                return $column;
            }
        }
        return '';
    }

    private function resolve_customer_area_column()
    {
        foreach (array('area', 'lokasi') as $column) {
            if (in_array($column, $this->customer_fields, true)) {
                return $column;
            }
        }
        return '';
    }

    private function get_teknisi_by_user_id($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !$this->db->table_exists('users') || !$this->has_user_field('id') || !$this->has_user_field('role')) {
            return array();
        }

        $name_col = $this->has_user_field('name') ? 'name' : ($this->has_user_field('username') ? 'username' : '');
        if ($name_col === '') {
            return array();
        }

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS name', false)
            ->from('users')
            ->where('id', $user_id)
            ->where('role', 'teknisi');
        if ($this->has_user_field('status')) {
            $qb->where('status', 'active');
        }

        return (array) $qb->limit(1)->get()->row_array();
    }

    private function find_ticket_by_id($id)
    {
        return (array) $this->db
            ->from('tickets')
            ->where('id', (int) $id)
            ->limit(1)
            ->get()
            ->row_array();
    }

    private function send_ticket_created_telegram(array $ticket)
    {
        $router_id = $this->resolve_router_id_from_customer((int) ($ticket['customer_id'] ?? 0));
        $types = array('teknisi', 'admin');

        if (function_exists('sendTelegramByRouter')) {
            $ticket_number = html_escape((string) ($ticket['ticket_number'] ?? '-'));
            $subject = html_escape((string) ($ticket['subject'] ?? '-'));
            $priority = strtoupper((string) ($ticket['priority'] ?? '-'));
            $status = strtoupper((string) ($ticket['status'] ?? 'OPEN'));
            $type = strtoupper((string) ($ticket['ticket_type'] ?? 'GANGGUAN'));
            $customer_name = html_escape((string) ($ticket['customer_name'] ?? '-'));
            $area = html_escape((string) ($ticket['area'] ?? '-'));
            $assigned = html_escape((string) ($ticket['assigned_name'] ?? '-'));
            $description = trim((string) ($ticket['description'] ?? ''));
            $description = $description !== '' ? html_escape($description) : '-';

            $message = "🛠 <b>TIKET BARU " . $type . "</b>\n\n"
                . "No Tiket: <b>" . $ticket_number . "</b>\n"
                . "Issue: " . $subject . "\n"
                . "User: <b>" . $customer_name . "</b>\n"
                . "Area: <b>" . $area . "</b>\n"
                . "Prioritas: <b>" . $priority . "</b>\n"
                . "Status: <b>" . $status . "</b>\n"
                . "Assigned: <b>" . $assigned . "</b>\n"
                . "Detail: " . $description . "\n"
                . "Waktu: " . date('d-m-Y H:i');

            $sent = 0;
            $failed = 0;
            $deduped = 0;
            $messages = array();
            foreach ($types as $target_type) {
                $res = sendTelegramByRouter($router_id, $target_type, $message, false);
                if (!empty($res['success'])) {
                    $sent += (int) ($res['sent'] ?? 0);
                    $failed += (int) ($res['failed'] ?? 0);
                    $deduped += (int) ($res['deduped'] ?? 0);
                    if (((int) ($res['sent'] ?? 0) + (int) ($res['deduped'] ?? 0)) <= 0) {
                        $messages[] = '[' . $target_type . '] ' . (string) ($res['message'] ?? 'tidak ada target kirim');
                    }
                } else {
                    $failed++;
                    $messages[] = '[' . $target_type . '] ' . (string) ($res['message'] ?? 'gagal kirim');
                }
            }

            if ($sent > 0 || $deduped > 0) {
                return array(
                    'success' => true,
                    'message' => 'Telegram terkirim. sent=' . $sent . ', deduped=' . $deduped . ', failed=' . $failed,
                );
            }

            log_message('error', '[TICKETS][TELEGRAM][ROUTER] ' . implode(' | ', $messages));
            return array(
                'success' => false,
                'message' => !empty($messages) ? implode(' | ', $messages) : 'Group Telegram router tidak ditemukan.',
            );
        }

        $settings = $this->settings_model->get_telegram_settings();
        $bot_token = trim((string) ($settings['bot_token'] ?? ''));
        $chat_id = trim((string) ($settings['chat_id_admin'] ?? ''));
        $enabled = (int) ($settings['enable_notification'] ?? 0) === 1;

        if (!$enabled) {
            return array('success' => false, 'message' => 'Telegram notification nonaktif.');
        }
        if ($bot_token === '' || $chat_id === '') {
            return array('success' => false, 'message' => 'Telegram bot token/chat id belum diatur.');
        }

        $ticket_number = html_escape((string) ($ticket['ticket_number'] ?? '-'));
        $subject = html_escape((string) ($ticket['subject'] ?? '-'));
        $priority = strtoupper((string) ($ticket['priority'] ?? '-'));
        $status = strtoupper((string) ($ticket['status'] ?? 'OPEN'));
        $type = strtoupper((string) ($ticket['ticket_type'] ?? 'GANGGUAN'));
        $customer_name = html_escape((string) ($ticket['customer_name'] ?? '-'));
        $area = html_escape((string) ($ticket['area'] ?? '-'));
        $assigned = html_escape((string) ($ticket['assigned_name'] ?? '-'));
        $description = trim((string) ($ticket['description'] ?? ''));
        $description = $description !== '' ? html_escape($description) : '-';

        $message = "🛠 <b>TIKET BARU " . $type . "</b>\n\n"
            . "No Tiket: <b>" . $ticket_number . "</b>\n"
            . "Issue: " . $subject . "\n"
            . "User: <b>" . $customer_name . "</b>\n"
            . "Area: <b>" . $area . "</b>\n"
            . "Prioritas: <b>" . $priority . "</b>\n"
            . "Status: <b>" . $status . "</b>\n"
            . "Assigned: <b>" . $assigned . "</b>\n"
            . "Detail: " . $description . "\n"
            . "Waktu: " . date('d-m-Y H:i');

        $send = $this->telegram_service->send_message($bot_token, $chat_id, $message, 'HTML');
        if (empty($send['success'])) {
            log_message('error', '[TICKETS][TELEGRAM] send failed: ' . json_encode($send));
        }
        return $send;
    }

    private function resolve_router_id_from_customer($customer_id)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0 || !$this->db->table_exists('customer_services')) {
            return 0;
        }

        $fields = $this->db->list_fields('customer_services');
        if (!in_array('customer_id', $fields, true) || !in_array('router_id', $fields, true)) {
            return 0;
        }

        $qb = $this->db
            ->select('router_id')
            ->from('customer_services')
            ->where('customer_id', $customer_id)
            ->where('router_id >', 0);

        if (in_array('status', $fields, true)) {
            $qb->where_in('LOWER(status)', array('active', 'suspended', 'isolated', 'isolir', 'pending'));
        }
        if (in_array('id', $fields, true)) {
            $qb->order_by('id', 'DESC');
        }

        $row = (array) $qb->limit(1)->get()->row_array();
        return (int) ($row['router_id'] ?? 0);
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
}
