<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ticket_model extends CI_Model
{
    const TABLE_TICKETS = 'tickets';
    const TABLE_REPLIES = 'ticket_replies';
    const TABLE_ATTACHMENTS = 'ticket_attachments';

    private $ticket_fields = array();
    private $customer_fields = array();
    private $user_fields = array();
    private $ticket_status_enum = array();
    private $viewer_role = '';
    private $effective_router_id = null;

    public function __construct()
    {
        parent::__construct();
        $this->load->helper(array('rbac', 'router_scope'));
        $this->resolve_router_scope_context();

        if ($this->db->table_exists(self::TABLE_TICKETS)) {
            $this->ticket_fields = $this->db->list_fields(self::TABLE_TICKETS);
            $this->ticket_status_enum = $this->load_ticket_status_enum();
        }
        if ($this->db->table_exists('customers')) {
            $this->customer_fields = $this->db->list_fields('customers');
        }
        if ($this->db->table_exists('users')) {
            $this->user_fields = $this->db->list_fields('users');
        }
    }

    private function resolve_router_scope_context()
    {
        $this->viewer_role = 'guest';
        $this->effective_router_id = null;

        $CI =& get_instance();
        if (!isset($CI->session)) {
            $CI->load->library('session');
        }

        $this->viewer_role = normalizeRole((string) $CI->session->userdata('role'));
        if ($this->viewer_role === 'superadmin') {
            $active_router_id = (int) $CI->session->userdata('active_router_id');
            if ($active_router_id <= 0) {
                $active_router_id = (int) $CI->session->userdata('dashboard_router_id');
            }
            if ($active_router_id <= 0) {
                $active_router_id = (int) $CI->session->userdata('router_scope_id');
            }
            if ($active_router_id > 0) {
                $this->effective_router_id = $active_router_id;
            }
            return;
        }

        $scope_router_id = (int) $CI->session->userdata('router_scope_id');
        if ($scope_router_id <= 0 && $this->db->table_exists('users')) {
            $user_fields = $this->db->list_fields('users');
            if (in_array('router_scope_id', $user_fields, true)) {
                $user_id = (int) $CI->session->userdata('user_id');
                if ($user_id > 0) {
                    $row = (array) $this->db
                        ->select('router_scope_id')
                        ->from('users')
                        ->where('id', $user_id)
                        ->limit(1)
                        ->get()
                        ->row_array();
                    $scope_router_id = (int) ($row['router_scope_id'] ?? 0);
                    if ($scope_router_id > 0) {
                        $CI->session->set_userdata('router_scope_id', $scope_router_id);
                    }
                }
            }
        }

        if ($scope_router_id > 0) {
            $this->effective_router_id = $scope_router_id;
        }
    }

    private function apply_router_scope_filter(CI_DB_query_builder $qb, $column = 'router_id', $alias = '')
    {
        $prefix = trim((string) $alias) !== '' ? (trim((string) $alias) . '.') : '';

        if ($this->effective_router_id !== null && (int) $this->effective_router_id > 0) {
            $qb->where($prefix . $column, (int) $this->effective_router_id);
            return;
        }

        if ($this->viewer_role !== 'superadmin') {
            $qb->where('1 = 0', null, false);
        }
    }

    public function table_ready()
    {
        return $this->db->table_exists(self::TABLE_TICKETS);
    }

    public function list_statuses()
    {
        return array('OPEN', 'ASSIGNED', 'PROGRESS', 'RESOLVED', 'CLOSED');
    }

    public function list_priorities()
    {
        return array('LOW', 'MEDIUM', 'HIGH', 'URGENT');
    }

    public function get_technicians()
    {
        if (!$this->db->table_exists('users') || !$this->has_user_field('id') || !$this->has_user_field('role')) {
            return array();
        }

        $name_col = $this->resolve_user_name_column();
        if ($name_col === '') {
            return array();
        }

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS name', false)
            ->from('users')
            ->where('role', 'teknisi');

        if ($this->has_user_field('router_scope_id')) {
            if ($this->effective_router_id !== null && (int) $this->effective_router_id > 0) {
                $qb->where('router_scope_id', (int) $this->effective_router_id);
            } elseif ($this->viewer_role !== 'superadmin') {
                $qb->where('1 = 0', null, false);
            }
        }

        if ($this->has_user_field('status')) {
            $qb->where('status', 'active');
        }

        return $qb->order_by($name_col, 'ASC')->get()->result_array();
    }

    public function get_olt_options()
    {
        if ($this->db->table_exists('master_olts')) {
            $fields = $this->db->list_fields('master_olts');
            if (in_array('id', $fields, true) && in_array('name', $fields, true)) {
                $qb = $this->db->select('id, name')->from('master_olts');
                if (in_array('router_id', $fields, true)) {
                    $this->apply_router_scope_filter($qb, 'router_id', 'master_olts');
                }
                if (in_array('is_active', $fields, true)) {
                    $qb->where('is_active', 1);
                }
                return $qb->order_by('name', 'ASC')->get()->result_array();
            }
        }

        return array();
    }

    public function get_customer_options($limit = 2000)
    {
        if (!$this->db->table_exists('customers') || empty($this->customer_fields)) {
            return array();
        }

        $name_col = $this->resolve_customer_name_column();
        if ($name_col === '') {
            return array();
        }
        $area_col = $this->resolve_customer_area_column();
        $ppp_col = $this->resolve_customer_ppp_username_column();

        $qb = $this->db
            ->from('customers c')
            ->select('c.id')
            ->select('c.' . $name_col . ' AS customer_name', false);

        if ($area_col !== '') {
            $qb->select('c.' . $area_col . ' AS area_name', false);
        } else {
            $qb->select("'' AS area_name", false);
        }

        if ($ppp_col !== '') {
            $qb->select('c.' . $ppp_col . ' AS ppp_username', false);
        } else {
            $qb->select("'' AS ppp_username", false);
        }
        if ($this->has_customer_field('olt_id')) {
            $qb->select('c.olt_id');
        }
        if ($this->has_customer_field('olt')) {
            $qb->select('c.olt');
        }

        if ($this->has_customer_field('status')) {
            $qb->where_in('c.status', array('active', 'pending', 'isolated', 'suspended'));
        }
        if ($this->has_customer_field('deleted_at')) {
            $qb->where('c.deleted_at IS NULL', null, false);
        } elseif ($this->has_customer_field('is_deleted')) {
            $qb->where('c.is_deleted', 0);
        }
        if ($this->has_customer_field('router_id')) {
            $this->apply_router_scope_filter($qb, 'router_id', 'c');
        }

        $rows = $qb->order_by('c.' . $name_col, 'ASC')->limit((int) $limit)->get()->result_array();
        return $this->enrich_customer_olt_data($rows);
    }

    public function get_customer_context($customer_id)
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
        $ppp_col = $this->resolve_customer_ppp_username_column();
        $ppp_pass_col = $this->resolve_customer_ppp_password_column();

        $qb = $this->db
            ->from('customers c')
            ->select('c.id')
            ->select('c.' . $name_col . ' AS customer_name', false)
            ->where('c.id', $customer_id)
            ->limit(1);

        if ($area_col !== '') {
            $qb->select('c.' . $area_col . ' AS area_name', false);
        } else {
            $qb->select("'' AS area_name", false);
        }

        if ($ppp_col !== '') {
            $qb->select('c.' . $ppp_col . ' AS ppp_username', false);
        } else {
            $qb->select("'' AS ppp_username", false);
        }

        if ($ppp_pass_col !== '') {
            $qb->select('c.' . $ppp_pass_col . ' AS ppp_password', false);
        } else {
            $qb->select("'' AS ppp_password", false);
        }

        if ($this->has_customer_field('status')) {
            $qb->select('c.status');
        }
        if ($this->has_customer_field('olt')) {
            $qb->select('c.olt');
        }
        if ($this->has_customer_field('olt_id')) {
            $qb->select('c.olt_id');
        }
        if ($this->has_customer_field('router_id')) {
            $qb->select('c.router_id');
            $this->apply_router_scope_filter($qb, 'router_id', 'c');
        }

        $row = (array) $qb->get()->row_array();
        if (empty($row)) {
            return array();
        }

        $row['olt_id'] = $this->resolve_customer_olt_id($row);
        $row['olt_name'] = $this->resolve_customer_olt_name($row);

        return $row;
    }

    public function count_tickets(array $filters = array(), $viewer_role = '', $viewer_user_id = 0)
    {
        if (!$this->table_ready()) {
            return 0;
        }

        $qb = $this->build_ticket_query($filters, $viewer_role, (int) $viewer_user_id, true);
        return (int) $qb->count_all_results();
    }

    public function get_tickets(array $filters = array(), $limit = 20, $offset = 0, $viewer_role = '', $viewer_user_id = 0)
    {
        if (!$this->table_ready()) {
            return array();
        }

        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);

        $qb = $this->build_ticket_query($filters, $viewer_role, (int) $viewer_user_id, false);
        return $qb->limit($limit, $offset)->get()->result_array();
    }

    public function get_ticket_by_id($ticket_id, $viewer_role = '', $viewer_user_id = 0)
    {
        $ticket_id = (int) $ticket_id;
        if ($ticket_id <= 0 || !$this->table_ready()) {
            return array();
        }

        $qb = $this->build_ticket_query(array('ticket_id' => $ticket_id), $viewer_role, (int) $viewer_user_id, false);
        $qb->limit(1);
        return (array) $qb->get()->row_array();
    }

    public function get_ticket_replies($ticket_id)
    {
        $ticket_id = (int) $ticket_id;
        if ($ticket_id <= 0 || !$this->db->table_exists(self::TABLE_REPLIES)) {
            return array();
        }

        $name_col = $this->resolve_user_name_column();
        $name_select = $name_col !== '' ? 'u.' . $name_col : "'-'";

        $qb = $this->db
            ->from(self::TABLE_REPLIES . ' r')
            ->select('r.*')
            ->select($name_select . ' AS created_by_name', false)
            ->where('r.ticket_id', $ticket_id)
            ->order_by('r.id', 'ASC');

        if ($this->db->table_exists('users') && $this->has_user_field('id')) {
            $qb->join('users u', 'u.id = r.created_by', 'left');
        }

        return $qb->get()->result_array();
    }

    public function get_ticket_attachments($ticket_id)
    {
        $ticket_id = (int) $ticket_id;
        if ($ticket_id <= 0 || !$this->db->table_exists(self::TABLE_ATTACHMENTS)) {
            return array();
        }

        $name_col = $this->resolve_user_name_column();
        $name_select = $name_col !== '' ? 'u.' . $name_col : "'-'";

        $qb = $this->db
            ->from(self::TABLE_ATTACHMENTS . ' a')
            ->select('a.*')
            ->select($name_select . ' AS uploaded_by_name', false)
            ->where('a.ticket_id', $ticket_id)
            ->order_by('a.id', 'DESC');

        if ($this->db->table_exists('users') && $this->has_user_field('id')) {
            $qb->join('users u', 'u.id = a.uploaded_by', 'left');
        }

        return $qb->get()->result_array();
    }

    public function create_ticket(array $input, $created_by)
    {
        if (!$this->table_ready()) {
            return array('success' => false, 'message' => 'Tabel tickets belum tersedia.');
        }

        $created_by = (int) $created_by;
        $customer_id = (int) ($input['customer_id'] ?? 0);
        $customer_context = array();
        if ($customer_id > 0) {
            $customer_context = $this->get_customer_context($customer_id);
            if (empty($customer_context)) {
                return array('success' => false, 'message' => 'Customer tidak ditemukan atau tidak sesuai distribusi aktif.');
            }
        }
        $olt_id = (int) ($input['olt_id'] ?? 0);
        $subject = trim((string) ($input['subject'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $priority = strtoupper(trim((string) ($input['priority'] ?? 'MEDIUM')));
        $assigned_to = (int) ($input['assigned_to'] ?? 0);
        $issue_type = strtolower(trim((string) ($input['issue_type'] ?? '')));
        $router_id = (int) ($input['router_id'] ?? 0);
        if ($router_id <= 0) {
            $router_id = (int) ($customer_context['router_id'] ?? 0);
        }
        if ($router_id <= 0 && $this->effective_router_id !== null) {
            $router_id = (int) $this->effective_router_id;
        }

        if ($subject === '') {
            return array('success' => false, 'message' => 'Subject wajib diisi.');
        }

        if (!in_array($priority, $this->list_priorities(), true)) {
            $priority = 'MEDIUM';
        }

        $status = $assigned_to > 0 ? 'ASSIGNED' : 'OPEN';
        $category = $this->map_issue_type_to_category($issue_type);
        $now = date('Y-m-d H:i:s');
        $sla_deadline = $this->calculate_sla_deadline($priority, $now);

        $payload = array();
        if ($this->has_ticket_field('ticket_code')) {
            $payload['ticket_code'] = $this->generate_ticket_code($now);
        }
        if ($this->has_ticket_field('ticket_number')) {
            if (!empty($payload['ticket_code'])) {
                $payload['ticket_number'] = (string) $payload['ticket_code'];
            } else {
                $payload['ticket_number'] = $this->generate_ticket_number($now);
            }
        }
        if ($this->has_ticket_field('customer_id')) {
            $payload['customer_id'] = $customer_id > 0 ? $customer_id : null;
        }
        if ($this->has_ticket_field('olt_id')) {
            $payload['olt_id'] = $olt_id > 0 ? $olt_id : null;
        }
        if ($this->has_ticket_field('router_id')) {
            if ($router_id <= 0) {
                return array('success' => false, 'message' => 'Router ticket tidak valid.');
            }
            $payload['router_id'] = $router_id;
        }
        if ($this->has_ticket_field('subject')) {
            $payload['subject'] = $subject;
        }
        if ($this->has_ticket_field('description')) {
            $payload['description'] = $description;
        }
        if ($this->has_ticket_field('priority')) {
            $payload['priority'] = $priority;
        }
        if ($this->has_ticket_field('category')) {
            $payload['category'] = $this->category_value_for_db($category);
        }
        if ($this->has_ticket_field('channel')) {
            $payload['channel'] = 'web';
        }
        if ($this->has_ticket_field('status')) {
            $payload['status'] = $this->status_value_for_db($status);
        }
        if ($this->has_ticket_field('assigned_to')) {
            $payload['assigned_to'] = $assigned_to > 0 ? $assigned_to : null;
        }
        if ($this->has_ticket_field('sla_deadline')) {
            $payload['sla_deadline'] = $sla_deadline;
        }
        if ($this->has_ticket_field('created_by')) {
            $payload['created_by'] = $created_by;
        }
        if ($this->has_ticket_field('created_at')) {
            $payload['created_at'] = $now;
        }
        if ($this->has_ticket_field('updated_at')) {
            $payload['updated_at'] = $now;
        }

        if (empty($payload)) {
            return array('success' => false, 'message' => 'Struktur kolom tickets tidak kompatibel.');
        }

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;

        $this->db->trans_start();
        $this->db->insert(self::TABLE_TICKETS, $payload);
        $db_error = $this->db->error();
        $ticket_id = (int) $this->db->insert_id();

        if ($ticket_id > 0 && $description !== '' && $this->db->table_exists(self::TABLE_REPLIES)) {
            $this->db->insert(self::TABLE_REPLIES, array(
                'ticket_id' => $ticket_id,
                'reply_text' => $description,
                'is_internal' => 0,
                'created_by' => $created_by,
                'created_at' => $now,
            ));
        }

        $this->db->trans_complete();
        $ok = $this->db->trans_status() && empty($db_error['code']);
        $this->db->db_debug = $old_debug;

        if (!$ok) {
            log_message('error', '[HELPDESK][CREATE] DB error: ' . json_encode($db_error) . ' payload=' . json_encode($payload));
            return array(
                'success' => false,
                'message' => 'Gagal menyimpan tiket: ' . (string) ($db_error['message'] ?? 'unknown'),
            );
        }

        return array(
            'success' => true,
            'message' => 'Tiket berhasil dibuat.',
            'ticket_id' => $ticket_id,
            'ticket_code' => (string) ($payload['ticket_code'] ?? $payload['ticket_number'] ?? ('#' . $ticket_id)),
            'status' => $this->normalize_status_key($status),
            'sla_deadline' => $sla_deadline,
        );
    }

    public function assign_ticket($ticket_id, $assigned_to, $updated_by)
    {
        $ticket_id = (int) $ticket_id;
        $assigned_to = (int) $assigned_to;
        $updated_by = (int) $updated_by;

        if ($ticket_id <= 0 || $assigned_to <= 0 || !$this->table_ready()) {
            return array('success' => false, 'message' => 'Parameter assign tidak valid.');
        }

        $now = date('Y-m-d H:i:s');
        $payload = array();

        if ($this->has_ticket_field('assigned_to')) {
            $payload['assigned_to'] = $assigned_to;
        }
        if ($this->has_ticket_field('status')) {
            $payload['status'] = $this->status_value_for_db('ASSIGNED');
        }
        if ($this->has_ticket_field('updated_at')) {
            $payload['updated_at'] = $now;
        }

        if (empty($payload)) {
            return array('success' => false, 'message' => 'Kolom assign/status tidak tersedia.');
        }

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->where('id', $ticket_id)->update(self::TABLE_TICKETS, $payload);
        $error = $this->db->error();
        $this->db->db_debug = $old_debug;

        if (!$ok) {
            return array('success' => false, 'message' => 'Gagal assign tiket: ' . (string) ($error['message'] ?? 'unknown'));
        }

        if ($this->db->table_exists(self::TABLE_REPLIES)) {
            $this->db->insert(self::TABLE_REPLIES, array(
                'ticket_id' => $ticket_id,
                'reply_text' => 'Tiket di-assign ke teknisi ID ' . $assigned_to,
                'is_internal' => 1,
                'created_by' => $updated_by,
                'created_at' => $now,
            ));
        }

        return array('success' => true, 'message' => 'Tiket berhasil di-assign.');
    }

    public function update_ticket_status($ticket_id, $new_status, $note, $user_id, $role)
    {
        $ticket_id = (int) $ticket_id;
        $new_status = $this->normalize_status_key($new_status);
        $user_id = (int) $user_id;
        $role = strtolower(trim((string) $role));
        $note = trim((string) $note);

        if ($ticket_id <= 0 || !$this->table_ready()) {
            return array('success' => false, 'message' => 'Ticket tidak valid.');
        }
        if (!in_array($new_status, $this->list_statuses(), true)) {
            return array('success' => false, 'message' => 'Status tidak valid.');
        }

        $ticket = $this->get_ticket_raw($ticket_id);
        if (empty($ticket)) {
            return array('success' => false, 'message' => 'Ticket tidak ditemukan.');
        }

        if (!$this->can_user_access_ticket($ticket, $role, $user_id)) {
            return array('success' => false, 'message' => 'Anda tidak memiliki akses tiket ini.');
        }

        $current_status = $this->normalize_status_key((string) ($ticket['status'] ?? 'OPEN'));
        if ($current_status === '') {
            $current_status = (int) ($ticket['assigned_to'] ?? 0) > 0 ? 'ASSIGNED' : 'OPEN';
        }
        if ($current_status === $new_status) {
            return array('success' => true, 'message' => 'Status sudah sama.', 'status' => $current_status);
        }

        $permission = $this->can_transition_status($current_status, $new_status, $role);
        if (!$permission['allowed']) {
            return array('success' => false, 'message' => $permission['message']);
        }

        $now = date('Y-m-d H:i:s');
        $payload = array('status' => $this->status_value_for_db($new_status));
        if ($this->has_ticket_field('updated_at')) {
            $payload['updated_at'] = $now;
        }

        if (in_array($new_status, array('PROGRESS', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'), true)
            && $this->has_ticket_field('first_response_at')
            && empty($ticket['first_response_at'])
        ) {
            $payload['first_response_at'] = $now;
        }

        if (in_array($new_status, array('RESOLVED', 'CLOSED'), true)) {
            if ($this->has_ticket_field('resolved_at') && empty($ticket['resolved_at'])) {
                $payload['resolved_at'] = $now;
            }

            if ($new_status === 'CLOSED' && $this->has_ticket_field('closed_at')) {
                $payload['closed_at'] = $now;
            }
        }

        $old_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->where('id', $ticket_id)->update(self::TABLE_TICKETS, $payload);
        $error = $this->db->error();
        $this->db->db_debug = $old_debug;

        if (!$ok) {
            return array('success' => false, 'message' => 'Gagal update status: ' . (string) ($error['message'] ?? 'unknown'));
        }

        $log_note = $note !== ''
            ? '[STATUS] ' . $current_status . ' → ' . $new_status . "\n" . $note
            : '[STATUS] ' . $current_status . ' → ' . $new_status;

        $this->add_reply($ticket_id, $log_note, $user_id, 1);

        return array(
            'success' => true,
            'message' => 'Status tiket berhasil diperbarui.',
            'status' => $new_status,
        );
    }

    public function add_reply($ticket_id, $reply_text, $created_by, $is_internal = 0)
    {
        $ticket_id = (int) $ticket_id;
        $created_by = (int) $created_by;
        $reply_text = trim((string) $reply_text);

        if ($ticket_id <= 0 || $reply_text === '' || !$this->db->table_exists(self::TABLE_REPLIES)) {
            return false;
        }

        $payload = array(
            'ticket_id' => $ticket_id,
            'reply_text' => $reply_text,
            'is_internal' => (int) $is_internal === 1 ? 1 : 0,
            'created_by' => $created_by,
            'created_at' => date('Y-m-d H:i:s'),
        );

        $ok = $this->db->insert(self::TABLE_REPLIES, $payload);
        if ($ok && $this->has_ticket_field('updated_at')) {
            $this->db->where('id', $ticket_id)->update(self::TABLE_TICKETS, array('updated_at' => date('Y-m-d H:i:s')));
        }

        return $ok;
    }

    public function add_attachment($ticket_id, array $file_data, $uploaded_by)
    {
        $ticket_id = (int) $ticket_id;
        $uploaded_by = (int) $uploaded_by;

        if ($ticket_id <= 0 || !$this->db->table_exists(self::TABLE_ATTACHMENTS)) {
            return false;
        }

        $payload = array(
            'ticket_id' => $ticket_id,
            'file_name' => (string) ($file_data['file_name'] ?? ''),
            'file_path' => (string) ($file_data['file_path'] ?? ''),
            'mime_type' => (string) ($file_data['file_type'] ?? ''),
            'file_size' => isset($file_data['file_size']) ? (int) $file_data['file_size'] : 0,
            'uploaded_by' => $uploaded_by,
            'created_at' => date('Y-m-d H:i:s'),
        );

        return $this->db->insert(self::TABLE_ATTACHMENTS, $payload);
    }

    public function delete_ticket($ticket_id)
    {
        $ticket_id = (int) $ticket_id;
        if ($ticket_id <= 0 || !$this->table_ready()) {
            return false;
        }

        $this->db->trans_start();

        if ($this->db->table_exists(self::TABLE_ATTACHMENTS)) {
            $attachments = $this->get_ticket_attachments($ticket_id);
            foreach ($attachments as $attachment) {
                $path = trim((string) ($attachment['file_path'] ?? ''));
                if ($path !== '') {
                    $full = FCPATH . ltrim($path, '/');
                    if (is_file($full)) {
                        @unlink($full);
                    }
                }
            }
            $this->db->where('ticket_id', $ticket_id)->delete(self::TABLE_ATTACHMENTS);
        }

        if ($this->db->table_exists(self::TABLE_REPLIES)) {
            $this->db->where('ticket_id', $ticket_id)->delete(self::TABLE_REPLIES);
        }

        $this->db->where('id', $ticket_id)->delete(self::TABLE_TICKETS);
        $this->db->trans_complete();

        return (bool) $this->db->trans_status();
    }

    public function get_sla_breached_tickets($limit = 200)
    {
        if (!$this->table_ready()) {
            return array();
        }

        $ticket_ref_select = "CONCAT('TICKET#', t.id) AS ticket_code";
        if ($this->has_ticket_field('ticket_code')) {
            $ticket_ref_select = 't.ticket_code';
        } elseif ($this->has_ticket_field('ticket_number')) {
            $ticket_ref_select = 't.ticket_number AS ticket_code';
        }

        $qb = $this->db
            ->from(self::TABLE_TICKETS . ' t')
            ->select('t.id, ' . $ticket_ref_select . ', t.subject, t.priority, t.status, t.sla_deadline, t.customer_id', false)
            ->where('t.sla_deadline <', date('Y-m-d H:i:s'))
            ->where_not_in('t.status', $this->closed_status_values())
            ->order_by('t.sla_deadline', 'ASC')
            ->limit((int) $limit);

        if ($this->has_ticket_field('router_id')) {
            $this->apply_router_scope_filter($qb, 'router_id', 't');
        }

        if ($this->db->table_exists('customers')) {
            $name_col = $this->resolve_customer_name_column();
            if ($name_col !== '') {
                $qb->join('customers c', 'c.id = t.customer_id', 'left');
                $qb->select('c.' . $name_col . ' AS customer_name', false);
                if (!$this->has_ticket_field('router_id') && $this->has_customer_field('router_id')) {
                    $this->apply_router_scope_filter($qb, 'router_id', 'c');
                }
            } else {
                $qb->select("'' AS customer_name", false);
            }
        } else {
            $qb->select("'' AS customer_name", false);
        }

        return $qb->get()->result_array();
    }

    public function count_sla_breached()
    {
        if (!$this->table_ready() || !$this->has_ticket_field('sla_deadline') || !$this->has_ticket_field('status')) {
            return 0;
        }

        $qb = $this->db
            ->from(self::TABLE_TICKETS)
            ->where('sla_deadline <', date('Y-m-d H:i:s'))
            ->where_not_in('status', $this->closed_status_values());

        if ($this->has_ticket_field('router_id')) {
            $this->apply_router_scope_filter($qb, 'router_id');
        }

        return (int) $qb->count_all_results();
    }

    public function calculate_sla_deadline($priority, $base_time = '')
    {
        $priority = strtoupper(trim((string) $priority));
        $base = $base_time !== '' ? strtotime($base_time) : time();
        if ($base === false) {
            $base = time();
        }

        $hours = 12;
        switch ($priority) {
            case 'LOW':
                $hours = 24;
                break;
            case 'MEDIUM':
                $hours = 12;
                break;
            case 'HIGH':
                $hours = 6;
                break;
            case 'URGENT':
                $hours = 2;
                break;
        }

        return date('Y-m-d H:i:s', strtotime('+' . $hours . ' hours', $base));
    }

    public function generate_ticket_code($date_time = '')
    {
        $timestamp = $date_time !== '' ? strtotime($date_time) : time();
        if ($timestamp === false) {
            $timestamp = time();
        }

        $prefix = 'BJN-' . date('Ymd', $timestamp) . '-';
        $sequence = $this->next_ticket_sequence($prefix, array('ticket_code', 'ticket_number'));

        return $prefix . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }

    public function generate_ticket_number($date_time = '')
    {
        $timestamp = $date_time !== '' ? strtotime($date_time) : time();
        if ($timestamp === false) {
            $timestamp = time();
        }

        $prefix = 'BJN-' . date('Ymd', $timestamp) . '-';
        $sequence = $this->next_ticket_sequence($prefix, array('ticket_number', 'ticket_code'));

        return $prefix . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }

    public function can_user_access_ticket(array $ticket, $role, $user_id)
    {
        $role = strtolower(trim((string) $role));
        $user_id = (int) $user_id;

        if ($role === 'superadmin' || $role === 'admin') {
            return true;
        }

        if ($role === 'teknisi') {
            return (int) ($ticket['assigned_to'] ?? 0) === $user_id;
        }

        return false;
    }

    private function can_transition_status($current_status, $new_status, $role)
    {
        $flow = array(
            'OPEN' => 1,
            'ASSIGNED' => 2,
            'PROGRESS' => 3,
            'RESOLVED' => 4,
            'CLOSED' => 5,
        );

        $current = $this->normalize_status_key($current_status);
        $target = $this->normalize_status_key($new_status);
        $role = strtolower((string) $role);

        if (!isset($flow[$current]) || !isset($flow[$target])) {
            return array('allowed' => false, 'message' => 'Status flow tidak valid.');
        }

        if ($flow[$target] < $flow[$current]) {
            return array('allowed' => false, 'message' => 'Tidak boleh rollback status.');
        }

        if ($role === 'teknisi') {
            if (!in_array($target, array('PROGRESS', 'RESOLVED'), true)) {
                return array('allowed' => false, 'message' => 'Teknisi hanya boleh update ke PROGRESS atau RESOLVED.');
            }
            if ($target === 'PROGRESS' && !in_array($current, array('OPEN', 'ASSIGNED', 'PROGRESS'), true)) {
                return array('allowed' => false, 'message' => 'Status saat ini tidak valid untuk PROGRESS.');
            }
            if ($target === 'RESOLVED' && !in_array($current, array('ASSIGNED', 'PROGRESS', 'RESOLVED'), true)) {
                return array('allowed' => false, 'message' => 'Status saat ini tidak valid untuk RESOLVED.');
            }
            return array('allowed' => true, 'message' => 'OK');
        }

        if (in_array($role, array('superadmin', 'admin'), true)) {
            return array('allowed' => true, 'message' => 'OK');
        }

        return array('allowed' => false, 'message' => 'Role tidak diizinkan update status.');
    }

    private function build_ticket_query(array $filters, $viewer_role, $viewer_user_id, $count_only = false)
    {
        $viewer_role = strtolower(trim((string) $viewer_role));
        $viewer_user_id = (int) $viewer_user_id;

        $qb = $this->db->from(self::TABLE_TICKETS . ' t');

        $join_customer = $this->db->table_exists('customers') && $this->has_ticket_field('customer_id');
        $join_olt = $this->db->table_exists('master_olts') && $this->has_ticket_field('olt_id');
        $join_user = $this->db->table_exists('users') && $this->has_ticket_field('assigned_to');

        if (!$count_only) {
            $select = array('t.id');
            if ($this->has_ticket_field('ticket_code')) {
                $select[] = 't.ticket_code';
            } elseif ($this->has_ticket_field('ticket_number')) {
                $select[] = 't.ticket_number AS ticket_code';
            } else {
                $select[] = "CONCAT('BJN-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(t.id,3,'0')) AS ticket_code";
            }
            $select[] = $this->has_ticket_field('subject') ? 't.subject' : "'-' AS subject";
            $select[] = $this->has_ticket_field('description') ? 't.description' : "'' AS description";
            $select[] = $this->has_ticket_field('priority') ? 't.priority' : "'MEDIUM' AS priority";
            $select[] = $this->has_ticket_field('status') ? 't.status' : "'OPEN' AS status";
            $select[] = $this->has_ticket_field('sla_deadline') ? 't.sla_deadline' : 'NULL AS sla_deadline';
            $select[] = $this->has_ticket_field('created_at') ? 't.created_at' : 'NULL AS created_at';
            $select[] = $this->has_ticket_field('updated_at') ? 't.updated_at' : 'NULL AS updated_at';
            $select[] = $this->has_ticket_field('assigned_to') ? 't.assigned_to' : 'NULL AS assigned_to';
            $select[] = $this->has_ticket_field('created_by') ? 't.created_by' : 'NULL AS created_by';
            $select[] = $this->has_ticket_field('customer_id') ? 't.customer_id' : 'NULL AS customer_id';
            $select[] = $this->has_ticket_field('olt_id') ? 't.olt_id' : 'NULL AS olt_id';
            $select[] = $this->has_ticket_field('router_id') ? 't.router_id' : 'NULL AS router_id';

            $customer_name_col = $join_customer ? $this->resolve_customer_name_column() : '';
            $customer_area_col = $join_customer ? $this->resolve_customer_area_column() : '';
            $customer_ppp_col = $join_customer ? $this->resolve_customer_ppp_username_column() : '';
            $assigned_name_col = $join_user ? $this->resolve_user_name_column() : '';
            $creator_name_col = $this->db->table_exists('users') && $this->has_ticket_field('created_by') ? $this->resolve_user_name_column() : '';

            $select[] = $customer_name_col !== '' ? ('c.' . $customer_name_col . ' AS customer_name') : "'-' AS customer_name";
            $select[] = $customer_area_col !== '' ? ('c.' . $customer_area_col . ' AS customer_area') : "'-' AS customer_area";
            $select[] = $customer_ppp_col !== '' ? ('c.' . $customer_ppp_col . ' AS ppp_username') : "'' AS ppp_username";
            $select[] = $join_olt ? 'o.name AS olt_name' : "'-' AS olt_name";
            $select[] = $assigned_name_col !== '' ? ('u.' . $assigned_name_col . ' AS assigned_name') : "'-' AS assigned_name";
            $select[] = $creator_name_col !== '' ? ('cu.' . $creator_name_col . ' AS created_by_name') : "'-' AS created_by_name";

            $qb->select(implode(', ', $select), false);
        }

        if ($join_customer) {
            $qb->join('customers c', 'c.id = t.customer_id', 'left');
        }
        if ($join_olt) {
            $qb->join('master_olts o', 'o.id = t.olt_id', 'left');
        }
        if ($join_user) {
            $qb->join('users u', 'u.id = t.assigned_to', 'left');
        }
        if ($this->db->table_exists('users') && $this->has_ticket_field('created_by')) {
            $qb->join('users cu', 'cu.id = t.created_by', 'left');
        }

        if ($this->has_ticket_field('router_id')) {
            $this->apply_router_scope_filter($qb, 'router_id', 't');
        } elseif ($join_customer && $this->has_customer_field('router_id')) {
            $this->apply_router_scope_filter($qb, 'router_id', 'c');
        }

        if ($viewer_role === 'teknisi' && $this->has_ticket_field('assigned_to')) {
            $qb->where('t.assigned_to', $viewer_user_id);
        }

        $status = strtoupper(trim((string) ($filters['status'] ?? '')));
        if ($status !== '' && $this->has_ticket_field('status')) {
            $qb->where('t.status', $this->status_value_for_db($status));
        }

        $priority = strtoupper(trim((string) ($filters['priority'] ?? '')));
        if ($priority !== '' && $this->has_ticket_field('priority')) {
            $qb->where('t.priority', $priority);
        }

        $olt_id = (int) ($filters['olt_id'] ?? 0);
        if ($olt_id > 0 && $this->has_ticket_field('olt_id')) {
            $qb->where('t.olt_id', $olt_id);
        }

        $teknisi_id = (int) ($filters['assigned_to'] ?? 0);
        if ($teknisi_id > 0 && $this->has_ticket_field('assigned_to')) {
            $qb->where('t.assigned_to', $teknisi_id);
        }

        $ticket_id = (int) ($filters['ticket_id'] ?? 0);
        if ($ticket_id > 0) {
            $qb->where('t.id', $ticket_id);
        }

        $has_month_filter = array_key_exists('month', $filters) || array_key_exists('year', $filters);
        $filter_month = isset($filters['month']) ? (int) $filters['month'] : 0;
        $filter_year = isset($filters['year']) ? (int) $filters['year'] : 0;
        $filter_date_col = $this->resolve_ticket_filter_date_column();
        if ($has_month_filter && $filter_date_col !== '') {
            $this->apply_month_year_filter($qb, 't', $filter_date_col, $filter_month, $filter_year);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $search_code_column = 't.id';
            if ($this->has_ticket_field('ticket_code')) {
                $search_code_column = 't.ticket_code';
            } elseif ($this->has_ticket_field('ticket_number')) {
                $search_code_column = 't.ticket_number';
            }

            $qb->group_start()
                ->like($search_code_column, $search);
            if ($this->has_ticket_field('subject')) {
                $qb->or_like('t.subject', $search);
            }
            if ($this->has_ticket_field('description')) {
                $qb->or_like('t.description', $search);
            }
            if ($join_customer) {
                $customer_name_col = $this->resolve_customer_name_column();
                if ($customer_name_col !== '') {
                    $qb->or_like('c.' . $customer_name_col, $search);
                }
                $customer_area_col = $this->resolve_customer_area_column();
                if ($customer_area_col !== '') {
                    $qb->or_like('c.' . $customer_area_col, $search);
                }
            }
            if ($join_olt) {
                $qb->or_like('o.name', $search);
            }
            if ($join_user) {
                $assigned_name_col = $this->resolve_user_name_column();
                if ($assigned_name_col !== '') {
                    $qb->or_like('u.' . $assigned_name_col, $search);
                }
            }
            $qb->group_end();
        }

        if (!$count_only) {
            $qb->order_by('t.id', 'DESC');
        }

        return $qb;
    }

    private function get_ticket_raw($ticket_id)
    {
        $qb = $this->db
            ->from(self::TABLE_TICKETS)
            ->where('id', (int) $ticket_id)
            ->limit(1);

        if ($this->has_ticket_field('router_id')) {
            $this->apply_router_scope_filter($qb, 'router_id');
        }

        return (array) $qb->get()->row_array();
    }

    private function enrich_customer_olt_data(array $rows)
    {
        if (empty($rows)) {
            return array();
        }

        foreach ($rows as &$row) {
            $row['olt_id'] = $this->resolve_customer_olt_id($row);
            $row['olt_name'] = $this->resolve_customer_olt_name($row);
        }

        return $rows;
    }

    private function resolve_customer_olt_id(array $customer)
    {
        if (isset($customer['olt_id']) && (int) $customer['olt_id'] > 0) {
            return (int) $customer['olt_id'];
        }

        $olt_text = trim((string) ($customer['olt'] ?? ''));
        if ($olt_text === '' || !$this->db->table_exists('master_olts')) {
            return 0;
        }

        $fields = $this->db->list_fields('master_olts');
        if (!in_array('name', $fields, true) || !in_array('id', $fields, true)) {
            return 0;
        }

        $row = $this->db
            ->select('id')
            ->from('master_olts')
            ->where('name', $olt_text)
            ->limit(1);
        if (in_array('router_id', $fields, true)) {
            $this->apply_router_scope_filter($row, 'router_id', 'master_olts');
        }
        $row = $row->get()->row_array();

        return (int) ($row['id'] ?? 0);
    }

    private function resolve_customer_olt_name(array $customer)
    {
        if (!empty($customer['olt_name'])) {
            return (string) $customer['olt_name'];
        }
        if (!empty($customer['olt'])) {
            return (string) $customer['olt'];
        }

        $olt_id = (int) ($customer['olt_id'] ?? 0);
        if ($olt_id <= 0 || !$this->db->table_exists('master_olts')) {
            return '';
        }

        $fields = $this->db->list_fields('master_olts');
        $row_qb = $this->db
            ->select('name')
            ->from('master_olts')
            ->where('id', $olt_id)
            ->limit(1);
        if (in_array('router_id', $fields, true)) {
            $this->apply_router_scope_filter($row_qb, 'router_id', 'master_olts');
        }
        $row = $row_qb->get()->row_array();

        return (string) ($row['name'] ?? '');
    }

    private function resolve_customer_name_column()
    {
        foreach (array('full_name', 'nama', 'customer_name', 'name') as $column) {
            if ($this->has_customer_field($column)) {
                return $column;
            }
        }
        return '';
    }

    private function resolve_customer_area_column()
    {
        foreach (array('area', 'lokasi') as $column) {
            if ($this->has_customer_field($column)) {
                return $column;
            }
        }
        return '';
    }

    private function resolve_customer_ppp_username_column()
    {
        foreach (array('pppoe_username', 'username') as $column) {
            if ($this->has_customer_field($column)) {
                return $column;
            }
        }
        return '';
    }

    private function resolve_customer_ppp_password_column()
    {
        foreach (array('pppoe_password', 'password') as $column) {
            if ($this->has_customer_field($column)) {
                return $column;
            }
        }
        return '';
    }

    private function map_issue_type_to_category($issue_type)
    {
        $issue_type = strtolower(trim((string) $issue_type));
        if ($issue_type === 'fo_cut') {
            return 'internet_down';
        }
        if (in_array($issue_type, array('router_replace', 'adapter_replace'), true)) {
            return 'device';
        }
        return 'other';
    }

    private function category_value_for_db($category)
    {
        $category = trim((string) $category);
        if ($category === '' || !$this->has_ticket_field('category')) {
            return 'other';
        }

        $row = $this->db->query("SHOW COLUMNS FROM `" . self::TABLE_TICKETS . "` LIKE 'category'")->row_array();
        $type = isset($row['Type']) ? (string) $row['Type'] : '';
        if ($type === '' || !preg_match('/^enum\\((.*)\\)$/i', $type, $match)) {
            return $category;
        }

        $values = str_getcsv($match[1], ',', "'");
        if (empty($values)) {
            return $category;
        }

        foreach ($values as $enum_value) {
            if (strcasecmp(trim((string) $enum_value), $category) === 0) {
                return (string) $enum_value;
            }
        }

        foreach ($values as $enum_value) {
            if (strcasecmp(trim((string) $enum_value), 'other') === 0) {
                return (string) $enum_value;
            }
        }

        return (string) $values[0];
    }

    private function resolve_user_name_column()
    {
        foreach (array('name', 'username') as $column) {
            if ($this->has_user_field($column)) {
                return $column;
            }
        }
        return '';
    }

    private function has_ticket_field($field)
    {
        return in_array((string) $field, $this->ticket_fields, true);
    }

    private function has_customer_field($field)
    {
        return in_array((string) $field, $this->customer_fields, true);
    }

    private function has_user_field($field)
    {
        return in_array((string) $field, $this->user_fields, true);
    }

    private function load_ticket_status_enum()
    {
        if (!$this->table_ready() || !$this->has_ticket_field('status')) {
            return array();
        }

        $row = $this->db->query("SHOW COLUMNS FROM `" . self::TABLE_TICKETS . "` LIKE 'status'")->row_array();
        $type = isset($row['Type']) ? (string) $row['Type'] : '';
        if ($type === '') {
            return array();
        }

        if (!preg_match('/^enum\\((.*)\\)$/i', $type, $match)) {
            return array();
        }

        $values = str_getcsv($match[1], ',', "'");
        $result = array();
        foreach ((array) $values as $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $result[] = $v;
            }
        }

        return array_values(array_unique($result));
    }

    private function normalize_status_key($status)
    {
        $s = strtoupper(trim((string) $status));
        $s = str_replace('-', '_', $s);

        if ($s === 'NEW') {
            return 'OPEN';
        }
        if ($s === 'IN_PROGRESS') {
            return 'PROGRESS';
        }
        if ($s === 'DONE') {
            return 'RESOLVED';
        }
        if ($s === 'CANCELED') {
            return 'CANCELLED';
        }

        return $s;
    }

    private function status_value_for_db($status)
    {
        $normalized = $this->normalize_status_key($status);

        if (empty($this->ticket_status_enum)) {
            return $normalized;
        }

        $candidates = array(
            $normalized,
        );

        if ($normalized === 'OPEN') {
            $candidates = array('OPEN', 'open', 'NEW', 'new');
        } elseif ($normalized === 'ASSIGNED') {
            $candidates = array('ASSIGNED', 'assigned', 'IN_PROGRESS', 'in_progress', 'PROGRESS', 'progress', 'OPEN', 'open');
        } elseif ($normalized === 'PROGRESS') {
            $candidates = array('PROGRESS', 'progress', 'IN_PROGRESS', 'in_progress', 'ASSIGNED', 'assigned');
        } elseif ($normalized === 'RESOLVED') {
            $candidates = array('RESOLVED', 'resolved', 'DONE', 'done');
        } elseif ($normalized === 'CLOSED') {
            $candidates = array('CLOSED', 'closed');
        } elseif ($normalized === 'CANCELLED') {
            $candidates = array('CANCELLED', 'cancelled', 'CANCELED', 'canceled');
        }

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $this->ticket_status_enum, true)) {
                return $candidate;
            }
        }

        foreach ($this->ticket_status_enum as $enum_value) {
            foreach ($candidates as $candidate) {
                if (strtolower($enum_value) === strtolower((string) $candidate)) {
                    return $enum_value;
                }
            }
        }

        return (string) $this->ticket_status_enum[0];
    }

    private function resolve_ticket_filter_date_column()
    {
        foreach (array('opened_at', 'created_at', 'updated_at') as $column) {
            if ($this->has_ticket_field($column)) {
                return $column;
            }
        }

        return '';
    }

    private function apply_month_year_filter(CI_DB_query_builder $qb, $alias, $column, $month, $year)
    {
        $column = trim((string) $column);
        if ($column === '') {
            return;
        }

        $month = (int) $month;
        $year = (int) $year;
        if ($month < 1 || $month > 12) {
            $month = (int) date('m');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $start_date = $year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-01';
        $end_date = date('Y-m-t', strtotime($start_date));
        $expr = trim((string) $alias) !== '' ? trim((string) $alias) . '.' . $column : $column;

        $qb->where($expr . ' >= ' . $this->db->escape($start_date . ' 00:00:00'), null, false);
        $qb->where($expr . ' <= ' . $this->db->escape($end_date . ' 23:59:59'), null, false);
    }

    private function closed_status_values()
    {
        $values = array(
            $this->status_value_for_db('RESOLVED'),
            $this->status_value_for_db('CLOSED'),
        );
        return array_values(array_unique(array_filter($values, static function ($v) {
            return trim((string) $v) !== '';
        })));
    }

    private function next_ticket_sequence($prefix, array $candidate_columns)
    {
        if (!$this->table_ready()) {
            return 1;
        }

        $max_tail = 0;
        foreach ($candidate_columns as $column) {
            if (!$this->has_ticket_field($column)) {
                continue;
            }

            $last = $this->db
                ->select($column)
                ->from(self::TABLE_TICKETS)
                ->like($column, $prefix, 'after')
                ->order_by('id', 'DESC')
                ->limit(1)
                ->get()
                ->row_array();

            $value = trim((string) ($last[$column] ?? ''));
            if ($value === '') {
                continue;
            }

            $parts = explode('-', $value);
            $tail = end($parts);
            if (ctype_digit((string) $tail)) {
                $tail_int = (int) $tail;
                if ($tail_int > $max_tail) {
                    $max_tail = $tail_int;
                }
            }
        }

        return $max_tail + 1;
    }
}
