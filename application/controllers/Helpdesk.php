<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Helpdesk extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_module_access('tickets', 'Akses ditolak. Modul Ticket hanya untuk role yang diizinkan.');
        $this->load->database();
        $this->load->helper(array('url', 'form', 'helpdesk_telegram'));
        $this->load->library(array('form_validation', 'upload'));
        $this->load->model('Ticket_model', 'ticket_model');
        $this->load->model('Helpdesk_stats_model', 'helpdesk_stats_model');
        $this->load->model('Settings_model', 'settings_model');
        $this->load->library('mikrotik_api');
    }

    public function index()
    {
        $role = (string) $this->session->userdata('role');
        $user_id = (int) $this->session->userdata('user_id');
        $month = $this->normalize_month((int) $this->input->get('month', true));
        $year = $this->normalize_year((int) $this->input->get('year', true));

        $filters = array(
            'month' => $month,
            'year' => $year,
            'search' => trim((string) $this->input->get('search', true)),
            'status' => strtoupper(trim((string) $this->input->get('status', true))),
            'priority' => strtoupper(trim((string) $this->input->get('priority', true))),
            'olt_id' => (int) $this->input->get('olt_id', true),
            'assigned_to' => (int) $this->input->get('assigned_to', true),
        );

        $total_rows = $this->ticket_model->count_tickets($filters, $role, $user_id);
        $pager = $this->init_pagination('helpdesk', $total_rows, 20, 3);
        $rows = $this->ticket_model->get_tickets($filters, $pager['per_page'], $pager['offset'], $role, $user_id);

        $this->load->view('helpdesk/index', array(
            'rows' => $rows,
            'filters' => $filters,
            'status_options' => $this->ticket_model->list_statuses(),
            'priority_options' => $this->ticket_model->list_priorities(),
            'olt_options' => $this->ticket_model->get_olt_options(),
            'teknisi_options' => $this->ticket_model->get_technicians(),
            'pagination' => $pager['links'],
            'total_rows' => $pager['total_rows'],
            'per_page' => (int) $pager['per_page'],
            'per_page_options' => $this->get_per_page_options(),
            'months' => $this->month_options(),
            'years' => $this->year_options(),
            'selected_period_label' => $this->format_period_label($month, $year),
            'role' => $role,
            'is_superadmin' => $this->is_superadmin(),
        ));
    }

    public function dashboard()
    {
        $role = (string) $this->session->userdata('role');
        $user_id = (int) $this->session->userdata('user_id');

        $cards = $this->helpdesk_stats_model->dashboard_cards($role, $user_id);
        $status_chart = $this->helpdesk_stats_model->status_chart($role, $user_id);
        $breached = $this->helpdesk_stats_model->recent_sla_breached($role, $user_id, 8);

        $recent = $this->ticket_model->get_tickets(array(), 12, 0, $role, $user_id);

        $this->load->view('helpdesk/dashboard', array(
            'cards' => $cards,
            'status_chart' => $status_chart,
            'breached_rows' => $breached,
            'recent_rows' => $recent,
        ));
    }

    public function create()
    {
        $this->require_role(array('superadmin', 'admin'));

        $this->load->view('helpdesk/create', array(
            'customers' => $this->ticket_model->get_customer_options(),
            'priority_options' => $this->ticket_model->list_priorities(),
            'olt_options' => $this->ticket_model->get_olt_options(),
            'teknisi_options' => $this->ticket_model->get_technicians(),
        ));
    }

    public function store()
    {
        $this->require_role(array('superadmin', 'admin'));

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->ticket_model->table_ready()) {
            $this->session->set_flashdata('error', 'Tabel tickets belum tersedia. Jalankan SQL helpdesk terlebih dahulu.');
            redirect('helpdesk/create');
            return;
        }

        $this->form_validation->set_rules('customer_id', 'Customer', 'trim|required|integer|greater_than[0]');
        $this->form_validation->set_rules('issue_type', 'Jenis Gangguan', 'trim|required|in_list[fo_cut,router_replace,adapter_replace]');
        $this->form_validation->set_rules('subject', 'Subject', 'trim|max_length[200]');
        $this->form_validation->set_rules('description', 'Deskripsi', 'trim|required');
        $this->form_validation->set_rules('priority', 'Prioritas', 'trim|required|in_list[LOW,MEDIUM,HIGH,URGENT]');
        $this->form_validation->set_rules('olt_id', 'OLT', 'trim');
        $this->form_validation->set_rules('assigned_to', 'Assigned teknisi', 'trim');

        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', trim(strip_tags(validation_errors(' ', ' '))));
            redirect('helpdesk/create');
            return;
        }

        $customer_id = (int) $this->input->post('customer_id', true);
        $context = $this->ticket_model->get_customer_context($customer_id);
        if (empty($context)) {
            $this->session->set_flashdata('error', 'Customer tidak ditemukan.');
            redirect('helpdesk/create');
            return;
        }

        $assigned_to = (int) $this->input->post('assigned_to', true);
        $selected_olt_id = (int) $this->input->post('olt_id', true);
        $olt_id = $selected_olt_id > 0 ? $selected_olt_id : (int) ($context['olt_id'] ?? 0);

        $input = array(
            'customer_id' => $customer_id,
            'olt_id' => $olt_id,
            'router_id' => (int) ($context['router_id'] ?? 0),
            'issue_type' => trim((string) $this->input->post('issue_type', true)),
            'subject' => trim((string) $this->input->post('subject', true)),
            'description' => trim((string) $this->input->post('description', true)),
            'priority' => strtoupper(trim((string) $this->input->post('priority', true))),
            'assigned_to' => $assigned_to,
        );
        if ($input['subject'] === '') {
            $input['subject'] = $this->map_issue_type_label((string) $input['issue_type']);
        }

        $result = $this->ticket_model->create_ticket($input, (int) $this->session->userdata('user_id'));
        if (empty($result['success'])) {
            $this->session->set_flashdata('error', (string) ($result['message'] ?? 'Gagal membuat tiket.'));
            redirect('helpdesk/create');
            return;
        }

        $ticket_id = (int) ($result['ticket_id'] ?? 0);
        $ticket = $this->ticket_model->get_ticket_by_id($ticket_id, 'superadmin', (int) $this->session->userdata('user_id'));
        if (!empty($ticket)) {
            $ticket['issue_type'] = (string) $input['issue_type'];
            $ticket['issue_type_label'] = $this->map_issue_type_label((string) $input['issue_type']);
            $ticket['ppp_username'] = (string) ($context['ppp_username'] ?? ($ticket['ppp_username'] ?? ''));
            $ticket['ppp_password'] = (string) ($context['ppp_password'] ?? '');
            $telegram = helpdesk_telegram_ticket_created($ticket);
            if (empty($telegram['success'])) {
                log_message('error', '[HELPDESK][TELEGRAM][CREATE] ' . (string) ($telegram['message'] ?? 'unknown'));
            }
        }

        $this->session->set_flashdata('success', 'Tiket ' . (string) ($result['ticket_code'] ?? ('#' . $ticket_id)) . ' berhasil dibuat.');
        redirect('helpdesk/detail/' . $ticket_id);
    }

    public function detail($ticket_id = 0)
    {
        $ticket_id = (int) $ticket_id;
        $role = (string) $this->session->userdata('role');
        $user_id = (int) $this->session->userdata('user_id');

        $ticket = $this->ticket_model->get_ticket_by_id($ticket_id, $role, $user_id);
        if (empty($ticket)) {
            show_404();
            return;
        }

        $this->load->view('helpdesk/detail', array(
            'ticket' => $ticket,
            'replies' => $this->ticket_model->get_ticket_replies($ticket_id),
            'attachments' => $this->ticket_model->get_ticket_attachments($ticket_id),
            'status_options' => $this->ticket_model->list_statuses(),
            'teknisi_options' => $this->ticket_model->get_technicians(),
            'role' => $role,
            'is_superadmin' => $this->is_superadmin(),
        ));
    }

    public function customer_ppp_detail($customer_id = 0)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return $this->json_response(array('success' => false, 'message' => 'Customer tidak valid.'), 422);
        }

        $context = $this->ticket_model->get_customer_context($customer_id);
        if (empty($context)) {
            return $this->json_response(array('success' => false, 'message' => 'Customer tidak ditemukan.'), 404);
        }

        $ppp_username = trim((string) ($context['ppp_username'] ?? ''));
        if ($ppp_username === '') {
            return $this->json_response(array(
                'success' => true,
                'ppp_status' => 'UNKNOWN',
                'ppp_username' => '',
                'ip_address' => '-',
                'profile' => '-',
                'service' => '-',
                'alert_html' => '<div class="alert alert-warning mb-0">Username PPP tidak ditemukan pada data customer.</div>',
            ));
        }

        $router_id = (int) ($context['router_id'] ?? 0);
        $mk_settings = $this->settings_model->get_mikrotik_settings($router_id);
        $this->mikrotik_api->configure($mk_settings);

        $status = 'DISCONNECTED';
        $ip_address = '-';
        $profile = '-';
        $service = 'pppoe';

        try {
            $secret = $this->mikrotik_api->command_safe('/ppp/secret/print', array('?name' => $ppp_username));
            if (!empty($secret['success']) && !empty($secret['data'][0])) {
                $secret_row = (array) $secret['data'][0];
                if (!empty($secret_row['profile'])) {
                    $profile = (string) $secret_row['profile'];
                }
                if (!empty($secret_row['service'])) {
                    $service = (string) $secret_row['service'];
                }
                if (!empty($secret_row['remote-address'])) {
                    $ip_address = (string) $secret_row['remote-address'];
                }
            }

            $active = $this->mikrotik_api->command_safe('/ppp/active/print', array('?name' => $ppp_username));
            if (!empty($active['success']) && !empty($active['data'][0])) {
                $active_row = (array) $active['data'][0];
                $status = 'CONNECTED';
                if (!empty($active_row['address'])) {
                    $ip_address = (string) $active_row['address'];
                }
                if (!empty($active_row['service'])) {
                    $service = (string) $active_row['service'];
                }
            }
        } catch (Throwable $e) {
            log_message('error', '[HELPDESK][PPP_DETAIL] ' . $e->getMessage());
            return $this->json_response(array(
                'success' => false,
                'message' => 'Gagal mengambil data PPP: ' . $e->getMessage(),
            ), 500);
        } finally {
            $this->mikrotik_api->disconnect();
        }

        $alert_html = '';
        if ($status !== 'CONNECTED') {
            $alert_html = '<div class="alert alert-danger mb-0">PPP OFFLINE</div>';
        }

        return $this->json_response(array(
            'success' => true,
            'ppp_status' => $status,
            'ppp_username' => $ppp_username,
            'ip_address' => $ip_address,
            'profile' => $profile,
            'service' => $service,
            'alert_html' => $alert_html,
            'customer_name' => (string) ($context['customer_name'] ?? '-'),
            'area_name' => (string) ($context['area_name'] ?? '-'),
        ));
    }

    public function update_status()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(array('success' => false, 'message' => 'Method Not Allowed'), 405);
        }

        $ticket_id = (int) $this->input->post('ticket_id', true);
        $new_status = strtoupper(trim((string) $this->input->post('status', true)));
        $note = trim((string) $this->input->post('note', true));
        $assigned_to = (int) $this->input->post('assigned_to', true);

        $role = (string) $this->session->userdata('role');
        $user_id = (int) $this->session->userdata('user_id');
        $ticket_before = $this->ticket_model->get_ticket_by_id($ticket_id, $role, $user_id);

        if ($ticket_id <= 0 || $new_status === '') {
            return $this->json_response(array('success' => false, 'message' => 'Parameter tidak valid.'), 422);
        }

        if ($new_status === 'ASSIGNED') {
            if (!in_array($role, array('superadmin', 'admin'), true)) {
                return $this->json_response(array('success' => false, 'message' => 'Hanya admin/superadmin yang bisa assign teknisi.'), 403);
            }
            if ($assigned_to <= 0) {
                return $this->json_response(array('success' => false, 'message' => 'Pilih teknisi terlebih dahulu.'), 422);
            }

            $assign = $this->ticket_model->assign_ticket($ticket_id, $assigned_to, $user_id);
            if (empty($assign['success'])) {
                return $this->json_response(array('success' => false, 'message' => (string) ($assign['message'] ?? 'Assign gagal')), 422);
            }

            $ticket_after = $this->ticket_model->get_ticket_by_id($ticket_id, $role, $user_id);
            if (!empty($ticket_after)) {
                $telegram = helpdesk_telegram_ticket_status_updated(
                    $ticket_after,
                    (string) ($ticket_before['status'] ?? ''),
                    'ASSIGNED',
                    $note
                );
                if (empty($telegram['success'])) {
                    log_message('error', '[HELPDESK][TELEGRAM][STATUS] ' . (string) ($telegram['message'] ?? 'unknown'));
                }
            }

            return $this->json_response(array('success' => true, 'message' => 'Ticket berhasil di-assign.', 'status' => 'ASSIGNED'));
        }

        $update = $this->ticket_model->update_ticket_status($ticket_id, $new_status, $note, $user_id, $role);
        if (empty($update['success'])) {
            return $this->json_response(array('success' => false, 'message' => (string) ($update['message'] ?? 'Update status gagal')), 422);
        }

        $ticket_after = $this->ticket_model->get_ticket_by_id($ticket_id, $role, $user_id);
        if (!empty($ticket_after)) {
            $telegram = helpdesk_telegram_ticket_status_updated(
                $ticket_after,
                (string) ($ticket_before['status'] ?? ''),
                (string) ($update['status'] ?? $new_status),
                $note
            );
            if (empty($telegram['success'])) {
                log_message('error', '[HELPDESK][TELEGRAM][STATUS] ' . (string) ($telegram['message'] ?? 'unknown'));
            }
        }

        return $this->json_response(array(
            'success' => true,
            'message' => (string) ($update['message'] ?? 'Status diperbarui.'),
            'status' => (string) ($update['status'] ?? $new_status),
        ));
    }

    public function mark_done($ticket_id = 0)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $role = strtolower(trim((string) $this->session->userdata('role')));
        $user_id = (int) $this->session->userdata('user_id');
        if ($role !== 'teknisi') {
            $this->session->set_flashdata('error', 'Hanya teknisi yang bisa menggunakan aksi DONE dari list Helpdesk.');
            redirect('helpdesk');
            return;
        }

        $ticket_id = (int) $ticket_id;
        if ($ticket_id <= 0) {
            $this->session->set_flashdata('error', 'Ticket ID tidak valid.');
            redirect('helpdesk');
            return;
        }

        $ticket_before = $this->ticket_model->get_ticket_by_id($ticket_id, $role, $user_id);
        if (empty($ticket_before)) {
            $this->session->set_flashdata('error', 'Tiket tidak ditemukan atau tidak bisa diakses.');
            redirect('helpdesk');
            return;
        }

        $current_status = strtoupper(trim((string) ($ticket_before['status'] ?? 'OPEN')));
        if (in_array($current_status, array('RESOLVED', 'DONE', 'CLOSED'), true)) {
            $this->session->set_flashdata(
                'success',
                'Tiket ' . (string) ($ticket_before['ticket_code'] ?? ('#' . $ticket_id)) . ' sudah DONE.'
            );
            redirect('helpdesk');
            return;
        }

        $note = 'Ticket selesai dari Helpdesk List';
        $update = $this->ticket_model->update_ticket_status($ticket_id, 'DONE', $note, $user_id, $role);
        if (empty($update['success'])) {
            $this->session->set_flashdata('error', (string) ($update['message'] ?? 'Gagal mengubah tiket ke DONE.'));
            redirect('helpdesk');
            return;
        }

        $ticket_after = $this->ticket_model->get_ticket_by_id($ticket_id, $role, $user_id);
        if (!empty($ticket_after)) {
            $telegram = helpdesk_telegram_ticket_status_updated(
                $ticket_after,
                (string) ($ticket_before['status'] ?? ''),
                (string) ($update['status'] ?? 'RESOLVED'),
                $note
            );
            if (empty($telegram['success'])) {
                log_message('error', '[HELPDESK][TELEGRAM][MARK_DONE] ' . (string) ($telegram['message'] ?? 'unknown'));
            }
        }

        $this->session->set_flashdata(
            'success',
            'Tiket ' . (string) ($ticket_before['ticket_code'] ?? ('#' . $ticket_id)) . ' berhasil diubah ke DONE.'
        );
        redirect('helpdesk');
    }

    public function add_reply($ticket_id = 0)
    {
        $ticket_id = (int) $ticket_id;
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $role = (string) $this->session->userdata('role');
        $user_id = (int) $this->session->userdata('user_id');

        $ticket = $this->ticket_model->get_ticket_by_id($ticket_id, $role, $user_id);
        if (empty($ticket)) {
            show_404();
            return;
        }

        $reply = trim((string) $this->input->post('reply_text', true));
        if ($reply === '') {
            $this->session->set_flashdata('error', 'Reply tidak boleh kosong.');
            redirect('helpdesk/detail/' . $ticket_id);
            return;
        }

        $is_internal = (int) $this->input->post('is_internal', true) === 1 ? 1 : 0;
        if ($role === 'teknisi') {
            $is_internal = 0;
        }

        $ok = $this->ticket_model->add_reply($ticket_id, $reply, $user_id, $is_internal);
        if (!$ok) {
            $this->session->set_flashdata('error', 'Gagal menambahkan reply.');
            redirect('helpdesk/detail/' . $ticket_id);
            return;
        }

        $this->session->set_flashdata('success', 'Reply berhasil ditambahkan.');
        redirect('helpdesk/detail/' . $ticket_id);
    }

    public function upload_attachment($ticket_id = 0)
    {
        $ticket_id = (int) $ticket_id;
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $role = (string) $this->session->userdata('role');
        $user_id = (int) $this->session->userdata('user_id');

        $ticket = $this->ticket_model->get_ticket_by_id($ticket_id, $role, $user_id);
        if (empty($ticket)) {
            show_404();
            return;
        }

        if (empty($_FILES['attachment_file']['name'])) {
            $this->session->set_flashdata('error', 'Pilih file dokumentasi terlebih dahulu.');
            redirect('helpdesk/detail/' . $ticket_id);
            return;
        }

        $relative_dir = 'uploads/helpdesk/' . date('Y/m/') ;
        $upload_path = FCPATH . $relative_dir;
        if (!is_dir($upload_path)) {
            @mkdir($upload_path, 0755, true);
        }

        $config = array(
            'upload_path' => $upload_path,
            'allowed_types' => 'jpg|jpeg|png|pdf|doc|docx|txt',
            'max_size' => 8192,
            'encrypt_name' => true,
        );

        $this->upload->initialize($config);
        if (!$this->upload->do_upload('attachment_file')) {
            $this->session->set_flashdata('error', strip_tags($this->upload->display_errors('', '')));
            redirect('helpdesk/detail/' . $ticket_id);
            return;
        }

        $upload_data = $this->upload->data();
        $upload_data['file_path'] = $relative_dir . $upload_data['file_name'];

        $ok = $this->ticket_model->add_attachment($ticket_id, $upload_data, $user_id);
        if (!$ok) {
            $this->session->set_flashdata('error', 'File terupload, tapi gagal dicatat ke database.');
            redirect('helpdesk/detail/' . $ticket_id);
            return;
        }

        $this->ticket_model->add_reply(
            $ticket_id,
            'Dokumentasi diunggah: ' . (string) ($upload_data['orig_name'] ?? $upload_data['file_name']),
            $user_id,
            0
        );

        $this->session->set_flashdata('success', 'Dokumentasi berhasil diupload.');
        redirect('helpdesk/detail/' . $ticket_id);
    }

    public function delete($ticket_id = 0)
    {
        $this->require_role(array('superadmin'));

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            $this->session->set_flashdata('error', 'Method tidak diizinkan. Hapus tiket harus melalui tombol form.');
            redirect('helpdesk/detail/' . (int) $ticket_id);
            return;
        }

        $ticket_id = (int) $ticket_id;
        if ($ticket_id <= 0) {
            $this->session->set_flashdata('error', 'Ticket ID tidak valid.');
            redirect('helpdesk');
            return;
        }

        $ok = $this->ticket_model->delete_ticket($ticket_id);
        if (!$ok) {
            $this->session->set_flashdata('error', 'Gagal menghapus tiket.');
            redirect('helpdesk/detail/' . $ticket_id);
            return;
        }

        $this->session->set_flashdata('success', 'Tiket berhasil dihapus.');
        redirect('helpdesk');
    }

    private function map_issue_type_label($issue_type)
    {
        $issue_type = strtolower(trim((string) $issue_type));
        if ($issue_type === 'fo_cut') {
            return 'FO Cut';
        }
        if ($issue_type === 'router_replace') {
            return 'Ganti Router';
        }
        if ($issue_type === 'adapter_replace') {
            return 'Ganti Adaptor';
        }
        return 'Gangguan';
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

    private function json_response(array $payload, $status = 200)
    {
        $payload['csrf_token_name'] = $this->security->get_csrf_token_name();
        $payload['csrf_hash'] = $this->security->get_csrf_hash();

        return $this->output
            ->set_status_header((int) $status)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
    }
}
