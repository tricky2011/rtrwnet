<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Customers extends MY_Controller
{
    private $customer_fields = array();
    private $user_fields = array();
    private $customer_status_values = null;
    private $mikrotik_used_ip_cache = array();

    public function __construct()
    {
        parent::__construct();
        $this->require_module_access('customers', 'Akses ditolak. Modul Customer hanya untuk superadmin/admin.');
        $this->load->database();
        $this->load->model('customer_model');
        $this->load->model('ppp_profile_model');
        $this->load->model('billing_automation_model');
        $this->load->model('master_reference_model');
        // Gunakan class utama agar konsisten di Linux (case-sensitive FS).
        // Property instance tetap bisa diakses via $this->mikrotikmanager.
        $this->load->library(array('form_validation', 'session', 'jobdispatcher', 'MikrotikManager'));
        $this->load->helper(array('url', 'form'));

        if (method_exists($this->customer_model, 'set_router_scope')) {
            $this->customer_model->set_router_scope($this->getEffectiveRouterId());
        }
        if (method_exists($this->master_reference_model, 'set_router_scope')) {
            $this->master_reference_model->set_router_scope($this->getEffectiveRouterId());
        }

        if ($this->db->table_exists('customers')) {
            $this->customer_fields = $this->db->list_fields('customers');
        }
        if ($this->db->table_exists('users')) {
            $this->user_fields = $this->db->list_fields('users');
        }
    }

    public function index()
    {
        try {
            $keyword = trim((string) $this->input->get('search', true));
            $pager = $this->init_pagination(
                'customers',
                $this->customer_model->count_filtered($keyword),
                20,
                3
            );

            $this->load->view('customers/list', array(
                'customers' => $this->customer_model->get_paginated($pager['per_page'], $pager['offset'], $keyword),
                'pagination' => $pager['links'],
                'search' => $keyword,
                'total_rows' => $pager['total_rows'],
                'per_page' => (int) $pager['per_page'],
                'per_page_options' => $this->get_per_page_options(),
            ));
        } catch (Throwable $e) {
            log_message('error', '[CUSTOMERS][INDEX] ' . $e->getMessage());
            $this->session->set_flashdata('error', 'Terjadi kesalahan saat memuat data customer. Silakan cek log aplikasi.');
            redirect('dashboard');
        }
    }

    public function create()
    {
        return $this->render_create_form();
    }

    public function store()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $service_mode = $this->resolve_customer_service_mode(null, 0);
        $this->set_validation_rules(0, true, $service_mode);
        if ($this->form_validation->run() === false) {
            return $this->render_create_form();
        }
        $router_id = $this->resolve_router_id_from_request();
        if ($router_id <= 0) {
            $this->session->set_flashdata('error', 'Router belum dipilih atau tidak aktif. Silakan pilih router.');
            return redirect('customers/create');
        }

        $assigned_technician_id = (int) $this->input->post('technician_id', true);
        if ($assigned_technician_id > 0 && !$this->teknisi_exists($assigned_technician_id, $router_id)) {
            $this->session->set_flashdata('error', 'Teknisi yang dipilih tidak valid atau tidak aktif.');
            return redirect('customers/create');
        }

        $install_date = $this->resolve_installation_date_from_post();
        $payload = $this->collect_form_payload();
        $this->apply_service_mode_to_customer_payload($payload, $service_mode);

        if ($service_mode === 'static') {
            $ppp_profile_id = (int) $this->input->post('ppp_profile_id', true);
            $profile = null;
            $profile_price = 0.0;
            if ($ppp_profile_id > 0) {
                $profile = $this->ppp_profile_model->get_by_id($ppp_profile_id);
                if (!$profile) {
                    $this->session->set_flashdata('error', 'Paket/Profile tidak ditemukan.');
                    return redirect('customers/create');
                }

                $profile_price = (float) ($profile['price'] ?? 0);
                $this->attach_profile_to_customer_payload($payload, $ppp_profile_id, $profile_price);
            }

            $this->strip_pppoe_payload_for_static_mode($payload);
            if ($install_date !== '') {
                if ($this->has_field('install_date')) {
                    $payload['install_date'] = $install_date;
                }
                if ($this->has_field('installation_date')) {
                    $payload['installation_date'] = $install_date;
                }
            }
            if ($this->has_field('nama') && empty($payload['nama']) && !empty($payload['full_name'])) {
                $payload['nama'] = (string) $payload['full_name'];
            }
            if ($this->has_field('router_id')) {
                $payload['router_id'] = (int) $router_id;
            }

            $this->db->trans_begin();

            $customer_id = $this->customer_model->insert($payload);
            if (!$customer_id) {
                $db_error = (array) $this->db->error();
                log_message('error', '[CUSTOMERS][STORE][STATIC] insert customers gagal. payload=' . json_encode($payload) . ' db_error=' . json_encode($db_error));
                $this->db->trans_rollback();
                $message = 'Gagal menambahkan pelanggan static ke database.';
                if (!empty($db_error['message'])) {
                    $message .= ' (' . (string) $db_error['message'] . ')';
                }
                $this->session->set_flashdata('error', $message);
                return redirect('customers/create');
            }

            $invoice_created = false;
            if ($profile_price > 0) {
                $invoice_result = $this->create_initial_invoice(
                    (int) $customer_id,
                    0,
                    $profile_price,
                    (int) $router_id
                );
                if (empty($invoice_result['success'])) {
                    $this->db->trans_rollback();
                    $this->session->set_flashdata('error', (string) $invoice_result['message']);
                    return redirect('customers/create');
                }
                $invoice_created = true;
            }

            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'Transaction gagal saat create customer static.');
                return redirect('customers/create');
            }

            $this->db->trans_commit();

            $success_message = 'Pelanggan static berhasil ditambahkan tanpa provisioning PPPoE.';
            if ($invoice_created) {
                $success_message .= ' Invoice awal juga berhasil dibuat.';
            }
            $this->session->set_flashdata('success', $success_message);
            return redirect('customers');
        }

        $ppp_profile_id = (int) $this->input->post('ppp_profile_id', true);
        $profile = $this->ppp_profile_model->get_by_id($ppp_profile_id);
        if (!$profile) {
            $this->session->set_flashdata('error', 'PPP Profile tidak ditemukan.');
            return redirect('customers/create');
        }

        $credential = $this->build_auto_ppp_credential_from_post(0, $install_date);
        if (empty($credential['success'])) {
            $this->session->set_flashdata('error', (string) ($credential['message'] ?? 'Gagal generate credential PPP.'));
            return redirect('customers/create');
        }

        $username = (string) ($credential['username'] ?? '');
        $password = (string) ($credential['password'] ?? '');
        $vlan_id = (string) $this->resolve_vlan_id_by_profile($profile);

        $this->attach_profile_and_credential_to_customer_payload(
            $payload,
            $ppp_profile_id,
            $username,
            $password,
            (float) ($profile['price'] ?? 0),
            $vlan_id
        );
        if ($password === '') {
            unset($payload['pppoe_password'], $payload['ppp_password']);
        }
        if ($install_date !== '') {
            if ($this->has_field('install_date')) {
                $payload['install_date'] = $install_date;
            }
            if ($this->has_field('installation_date')) {
                $payload['installation_date'] = $install_date;
            }
        }
        if ($this->has_field('nama') && empty($payload['nama']) && !empty($payload['full_name'])) {
            $payload['nama'] = (string) $payload['full_name'];
        }
        if ($this->has_field('router_id')) {
            $payload['router_id'] = (int) $router_id;
        }

        $this->db->trans_begin();

        $customer_id = $this->customer_model->insert($payload);
        if (!$customer_id) {
            $db_error = (array) $this->db->error();
            log_message('error', '[CUSTOMERS][STORE] insert customers gagal. payload=' . json_encode($payload) . ' db_error=' . json_encode($db_error));
            $this->db->trans_rollback();
            $message = 'Gagal menambahkan pelanggan ke database.';
            if (!empty($db_error['message'])) {
                $message .= ' (' . (string) $db_error['message'] . ')';
            }
            $this->session->set_flashdata('error', $message);
            return redirect('customers/create');
        }

        $service_result = $this->upsert_customer_service(
            $customer_id,
            $ppp_profile_id,
            (float) ($profile['price'] ?? 0),
            $username,
            $router_id
        );
        if (empty($service_result['success'])) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', (string) $service_result['message']);
            return redirect('customers/create');
        }

        $resolved_service_router_id = $this->resolve_positive_router_id(
            (int) ($service_result['router_id'] ?? 0),
            (int) $router_id,
            (int) $customer_id
        );

        $invoice_result = $this->create_initial_invoice(
            $customer_id,
            (int) $service_result['customer_service_id'],
            (float) ($profile['price'] ?? 0),
            (int) $resolved_service_router_id
        );
        if (empty($invoice_result['success'])) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', (string) $invoice_result['message']);
            return redirect('customers/create');
        }

        $mikrotik_result = $this->provision_ppp_secret_to_mikrotik(
            (string) $username,
            (string) $password,
            (string) ($profile['name'] ?? ''),
            (string) ($service_result['ip_address'] ?? ''),
            (string) ($payload['full_name'] ?? ''),
            (int) $resolved_service_router_id
        );
        if (empty($mikrotik_result['success'])) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Gagal provisioning PPP ke MikroTik: ' . (string) ($mikrotik_result['message'] ?? 'unknown'));
            return redirect('customers/create');
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Transaction gagal saat create customer.');
            return redirect('customers/create');
        }

        $this->db->trans_commit();

        $work_order_result = array('success' => false, 'message' => 'WO belum diproses.');
        try {
            $work_order_result = $this->create_installation_work_order(array(
                'customer_id' => (int) $customer_id,
                'customer_name' => (string) ($payload['full_name'] ?? ''),
                'address' => (string) ($payload['address'] ?? ''),
                'pppoe_username' => $username,
                'pppoe_password' => $password,
                'profile_name' => (string) ($profile['name'] ?? ''),
                'vlan_id' => $vlan_id,
                'install_date' => $install_date,
                'technician_id' => $assigned_technician_id,
                'router_id' => (int) $resolved_service_router_id,
            ));
        } catch (Throwable $e) {
            $work_order_result = array('success' => false, 'message' => $e->getMessage());
            log_message('error', '[CUSTOMERS][WO_CREATE] exception: ' . $e->getMessage());
        }

        $telegram_result = array('success' => false, 'message' => 'Telegram belum diproses.');
        try {
            $telegram_result = $this->send_new_installation_telegram(array(
                'customer_id' => (int) $customer_id,
                'wo_number' => (string) ($work_order_result['wo_number'] ?? ''),
                'customer_name' => (string) ($payload['full_name'] ?? ''),
                'address' => (string) ($payload['address'] ?? ''),
                'pppoe_username' => $username,
                'pppoe_password' => $password,
                'profile_name' => (string) ($profile['name'] ?? ''),
                'vlan_id' => $vlan_id,
                'ip_address' => (string) ($service_result['ip_address'] ?? ''),
                'router_id' => (int) $resolved_service_router_id,
            ));
        } catch (Throwable $e) {
            $telegram_result = array('success' => false, 'message' => $e->getMessage());
            log_message('error', '[CUSTOMERS][TELEGRAM_WO] exception: ' . $e->getMessage());
        }

        if ($username !== '' && $password !== '') {
            $this->session->set_flashdata('credential', array(
                'username' => $username,
                'password' => $password,
            ));
        }

        $success_message = 'Pelanggan berhasil ditambahkan. Service dan invoice awal sudah dibuat.';
        $success_message .= ' ' . $this->build_customer_mikrotik_status_message(is_array($mikrotik_result) ? $mikrotik_result : array());
        if (!empty($work_order_result['success']) && !empty($work_order_result['wo_number'])) {
            $success_message .= ' WO ' . $work_order_result['wo_number'] . ' masuk antrian pemasangan baru.';
        } else {
            $success_message .= ' WO belum tersimpan: ' . (string) ($work_order_result['message'] ?? 'unknown');
        }
        $success_message .= ' ' . $this->build_customer_telegram_status_message(is_array($telegram_result) ? $telegram_result : array());

        $this->session->set_flashdata('success', $success_message);
        return redirect('customers');
    }

    public function generate_credential()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(405, array(
                'success' => false,
                'message' => 'Method Not Allowed',
            ));
        }

        $install_date = $this->resolve_installation_date_from_post();
        $credential = $this->build_auto_ppp_credential_from_post(0, $install_date);
        if (empty($credential['success'])) {
            return $this->json_response(422, array(
                'success' => false,
                'message' => (string) ($credential['message'] ?? 'Data generate credential belum lengkap.'),
                'csrf_token' => $this->security->get_csrf_hash(),
            ));
        }

        $ppp_profile_id = (int) $this->input->post('ppp_profile_id', true);
        $profile = $this->ppp_profile_model->get_by_id($ppp_profile_id);
        $vlan_id = $this->resolve_vlan_id_by_profile(is_array($profile) ? $profile : array());

        return $this->json_response(200, array(
            'success' => true,
            'message' => 'Credential berhasil dibuat.',
            'data' => array(
                'username' => (string) $credential['username'],
                'password' => (string) $credential['password'],
                'install_date' => $install_date,
                'vlan_id' => $vlan_id,
            ),
            'csrf_token' => $this->security->get_csrf_hash(),
        ));
    }

    public function preview_remote_ip()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(405, array(
                'success' => false,
                'message' => 'Method Not Allowed',
            ));
        }

        $ppp_profile_id = (int) $this->input->post('ppp_profile_id', true);
        if ($ppp_profile_id <= 0) {
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'PPP Profile wajib dipilih.',
                'csrf_token' => $this->security->get_csrf_hash(),
            ));
        }

        $allocated = $this->allocate_ip_from_profile($ppp_profile_id, 0);
        if (empty($allocated['success'])) {
            return $this->json_response(200, array(
                'success' => false,
                'message' => (string) ($allocated['message'] ?? 'Belum ada IP tersedia untuk profile ini.'),
                'csrf_token' => $this->security->get_csrf_hash(),
            ));
        }

        return $this->json_response(200, array(
            'success' => true,
            'message' => 'Preview IP tersedia.',
            'data' => array(
                'ip_address' => (string) ($allocated['ip_address'] ?? ''),
                'pool_name' => (string) ($allocated['pool_name'] ?? ''),
            ),
            'csrf_token' => $this->security->get_csrf_hash(),
        ));
    }

    public function suggest_remote_ip()
    {
        return $this->preview_remote_ip();
    }

    public function edit($id = null)
    {
        $id = (int) $id;
        $customer = $this->customer_model->get_by_id($id);
        if (!$customer) {
            $this->session->set_flashdata('error', 'Data pelanggan tidak ditemukan.');
            return redirect('customers');
        }

        $selected_profile_id = $this->resolve_customer_profile_id($customer, $id);
        return $this->render_form('edit', $customer, $id, $selected_profile_id);
    }

    public function update($id = null)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $customer = $this->customer_model->get_by_id($id);
        if (!$customer) {
            $this->session->set_flashdata('error', 'Data pelanggan tidak ditemukan.');
            return redirect('customers');
        }

        $service_mode = $this->resolve_customer_service_mode($customer, $id);
        $this->set_validation_rules($id, false, $service_mode);
        if ($this->form_validation->run() === false) {
            $selected_profile_id = $this->resolve_customer_profile_id($customer, $id);
            return $this->render_form('edit', $customer, $id, $selected_profile_id);
        }

        $router_id = $this->resolve_router_id_from_request();
        if ($router_id <= 0) {
            $this->session->set_flashdata('error', 'Router belum dipilih atau tidak aktif.');
            return redirect('customers/edit/' . $id);
        }

        $assigned_technician_id = (int) $this->input->post('technician_id', true);
        if ($assigned_technician_id > 0 && !$this->teknisi_exists($assigned_technician_id, $router_id)) {
            $this->session->set_flashdata('error', 'Teknisi yang dipilih tidak valid atau tidak aktif.');
            return redirect('customers/edit/' . $id);
        }

        $payload = $this->collect_form_payload();
        $this->apply_service_mode_to_customer_payload($payload, $service_mode);
        if ($service_mode === 'static') {
            $static_profile_id = (int) $this->input->post('ppp_profile_id', true);
            if ($static_profile_id > 0) {
                $static_profile = $this->ppp_profile_model->get_by_id($static_profile_id);
                if (!$static_profile) {
                    $this->session->set_flashdata('error', 'Paket/Profile tidak ditemukan.');
                    return redirect('customers/edit/' . $id);
                }

                $this->attach_profile_to_customer_payload(
                    $payload,
                    $static_profile_id,
                    (float) ($static_profile['price'] ?? 0)
                );
            }
            $this->strip_pppoe_payload_for_static_mode($payload);
            if ($this->has_field('router_id')) {
                $payload['router_id'] = (int) $router_id;
            }

            $this->db->trans_begin();

            $ok = $this->customer_model->update($id, $payload);
            if (!$ok) {
                $db_error = (array) $this->db->error();
                log_message('error', '[CUSTOMERS][UPDATE][STATIC] update customers gagal. customer_id=' . (int) $id . ' payload=' . json_encode($payload) . ' db_error=' . json_encode($db_error));
                $this->db->trans_rollback();
                $message = 'Gagal memperbarui pelanggan static.';
                if (!empty($db_error['message'])) {
                    $message .= ' (' . (string) $db_error['message'] . ')';
                }
                $this->session->set_flashdata('error', $message);
                return redirect('customers/edit/' . $id);
            }

            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'Transaction gagal saat update customer static.');
                return redirect('customers/edit/' . $id);
            }

            $this->db->trans_commit();
            $this->session->set_flashdata('success', 'Data pelanggan static berhasil diperbarui tanpa proses PPPoE.');
            return redirect('customers');
        }

        $ppp_profile_id = (int) $this->input->post('ppp_profile_id', true);
        $profile = $this->ppp_profile_model->get_by_id($ppp_profile_id);
        if (!$profile) {
            $this->session->set_flashdata('error', 'PPP Profile tidak ditemukan.');
            return redirect('customers/edit/' . $id);
        }
        $username = trim((string) $this->input->post('pppoe_username', true));
        $password = trim((string) $this->input->post('pppoe_password', true));
        $latest_service = $this->get_latest_customer_service_record($id);
        $should_sync_pppoe = $this->should_sync_pppoe_update(
            $customer,
            $latest_service,
            $ppp_profile_id,
            $router_id,
            $username,
            $password
        );

        if ($should_sync_pppoe) {
            $this->attach_profile_and_credential_to_customer_payload(
                $payload,
                $ppp_profile_id,
                $username,
                $password,
                (float) ($profile['price'] ?? 0),
                (string) $this->resolve_vlan_id_by_profile($profile)
            );
            if ($password === '') {
                unset($payload['pppoe_password'], $payload['ppp_password']);
            }
        } else {
            $this->strip_pppoe_payload_for_standard_update($payload);
        }
        if ($this->has_field('router_id')) {
            $payload['router_id'] = (int) $router_id;
        }

        $this->db->trans_begin();

        $ok = $this->customer_model->update($id, $payload);
        if (!$ok) {
            $db_error = (array) $this->db->error();
            log_message('error', '[CUSTOMERS][UPDATE] update customers gagal. customer_id=' . (int) $id . ' payload=' . json_encode($payload) . ' db_error=' . json_encode($db_error));
            $this->db->trans_rollback();
            $message = 'Gagal memperbarui pelanggan.';
            if (!empty($db_error['message'])) {
                $message .= ' (' . (string) $db_error['message'] . ')';
            }
            $this->session->set_flashdata('error', $message);
            return redirect('customers/edit/' . $id);
        }

        $service_result = array(
            'success' => true,
            'customer_service_id' => (int) ($latest_service['id'] ?? 0),
            'ip_address' => (string) ($latest_service['ip_address'] ?? ''),
            'router_id' => (int) ($latest_service['router_id'] ?? 0),
        );
        if ($service_result['router_id'] <= 0 && isset($customer->router_id)) {
            $service_result['router_id'] = (int) $customer->router_id;
        }

        if ($should_sync_pppoe) {
            $service_result = $this->upsert_customer_service(
                $id,
                $ppp_profile_id,
                (float) ($profile['price'] ?? 0),
                $username,
                $router_id
            );
            if (empty($service_result['success'])) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', (string) $service_result['message']);
                return redirect('customers/edit/' . $id);
            }
        }

        $resolved_service_router_id = $this->resolve_positive_router_id(
            (int) ($service_result['router_id'] ?? 0),
            (int) $router_id,
            (int) $id
        );

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Transaction gagal saat update customer.');
            return redirect('customers/edit/' . $id);
        }

        $this->db->trans_commit();

        $wo_sync_result = array('success' => true, 'updated' => false, 'created' => false, 'message' => '');
        if ($assigned_technician_id > 0) {
            $wo_sync_result = $this->sync_customer_technician_work_order((int) $id, (int) $assigned_technician_id, array(
                'customer_id' => (int) $id,
                'customer_name' => trim((string) ($payload['full_name'] ?? ($customer->full_name ?? ($customer->nama ?? '')))),
                'address' => trim((string) ($payload['address'] ?? ($customer->address ?? ''))),
                'pppoe_username' => $username !== '' ? $username : trim((string) ($customer->pppoe_username ?? ($customer->username ?? ''))),
                'pppoe_password' => $password !== '' ? $password : trim((string) ($customer->pppoe_password ?? ($customer->ppp_password ?? ''))),
                'profile_name' => trim((string) ($profile['name'] ?? '')),
                'vlan_id' => (string) $this->resolve_vlan_id_by_profile($profile),
                'install_date' => date('Y-m-d'),
                'router_id' => (int) $resolved_service_router_id,
            ));
        }

        $success_message = 'Data pelanggan berhasil diperbarui.';
        if ($assigned_technician_id > 0) {
            if (!empty($wo_sync_result['success']) && !empty($wo_sync_result['created'])) {
                $success_message .= ' WO pemasangan baru otomatis dibuat dan di-assign ke teknisi.';
            } elseif (!empty($wo_sync_result['success']) && !empty($wo_sync_result['updated'])) {
                $success_message .= ' WO aktif pelanggan sudah di-assign ke teknisi.';
            } elseif (!empty($wo_sync_result['message'])) {
                $success_message .= ' Catatan WO: ' . (string) $wo_sync_result['message'];
            }
        }

        $this->session->set_flashdata('success', $success_message);
        return redirect('customers');
    }

    public function delete($id = null)
    {
        $this->require_role(array('superadmin', 'admin'), 'Akses ditolak. Teknisi tidak diperbolehkan menghapus data.');

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $customer = $this->customer_model->get_by_id($id);
        if (!$customer) {
            $this->session->set_flashdata('error', 'Data pelanggan tidak ditemukan.');
            return redirect('customers');
        }

        $role = (string) $this->session->userdata('role');
        if ($role === 'superadmin') {
            if ($this->is_static_customer_record($customer)) {
                $ok = $this->customer_model->soft_delete($id);
                if (!$ok) {
                    $this->session->set_flashdata('error', 'Gagal menghapus pelanggan static.');
                    return redirect('customers');
                }
                $this->mark_customer_services_terminated(array($id));

                $this->session->set_flashdata('success', 'Pelanggan static berhasil dihapus.');
                return redirect('customers');
            }

            $result = $this->hard_delete_customer($id);
            if (empty($result['success'])) {
                $this->session->set_flashdata('error', (string) ($result['message'] ?? 'Gagal hapus pelanggan.'));
                return redirect('customers');
            }

            $this->session->set_flashdata('success', 'Pelanggan berhasil dihapus.');
            return redirect('customers');
        }

        $ok = $this->customer_model->soft_delete($id);
        if (!$ok) {
            $this->session->set_flashdata('error', 'Gagal menghapus pelanggan.');
            return redirect('customers');
        }
        $this->mark_customer_services_terminated(array($id));

        $this->session->set_flashdata('success', 'Pelanggan berhasil dihapus.');
        return redirect('customers');
    }

    public function bulk_delete()
    {
        $this->require_role(array('superadmin', 'admin'), 'Akses ditolak. Teknisi tidak diperbolehkan menghapus data.');

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(405, $this->bulk_response('error', 'Method Not Allowed'));
        }

        $ids = $this->parse_bulk_customer_ids();
        if (empty($ids)) {
            return $this->json_response(422, $this->bulk_response('error', 'Pilih minimal 1 customer.'));
        }

        $role = (string) $this->session->userdata('role');
        if ($role === 'superadmin') {
            $success = 0;
            $failed = 0;
            $failed_rows = array();

            foreach ($ids as $customer_id) {
                $customer = $this->customer_model->get_by_id((int) $customer_id);
                if ($customer && $this->is_static_customer_record($customer)) {
                    $ok = $this->customer_model->soft_delete((int) $customer_id);
                    if ($ok) {
                        $this->mark_customer_services_terminated(array((int) $customer_id));
                        $result = array('success' => true, 'message' => 'Pelanggan static berhasil dihapus.');
                    } else {
                        $result = array('success' => false, 'message' => 'Gagal menghapus pelanggan static.');
                    }
                } else {
                    $result = $this->hard_delete_customer((int) $customer_id);
                }
                if (!empty($result['success'])) {
                    $success++;
                } else {
                    $failed++;
                    $failed_rows[] = 'ID ' . (int) $customer_id . ': ' . (string) ($result['message'] ?? 'Gagal hapus.');
                }
            }

            if (!empty($failed_rows)) {
                log_message('error', '[CUSTOMERS_BULK_DELETE_HARD] ' . implode(' | ', $failed_rows));
            }

            $message = 'Bulk hapus selesai. Success=' . $success . ', Failed=' . $failed . '.';
            return $this->json_response(200, $this->bulk_response($failed > 0 ? 'partial' : 'success', $message, array(
                'processed' => count($ids),
                'success_count' => $success,
                'failed_count' => $failed,
                'failed_details' => $failed_rows,
            )));
        }

        $payload = $this->build_soft_delete_payload();
        if (empty($payload)) {
            return $this->json_response(500, $this->bulk_response('error', 'Skema tabel customers tidak mendukung soft delete.'));
        }

        $this->db->trans_begin();

        $this->db->where_in('id', $ids)->update('customers', $payload);
        $db_error = $this->db->error();
        if ((int) ($db_error['code'] ?? 0) !== 0) {
            $this->db->trans_rollback();
            log_message('error', '[CUSTOMERS_BULK_DELETE] DB error: ' . json_encode($db_error));
            return $this->json_response(500, $this->bulk_response('error', 'Gagal bulk delete: ' . ($db_error['message'] ?? 'unknown')));
        }

        $service_result = $this->mark_customer_services_terminated($ids);
        if (!$service_result['success']) {
            $this->db->trans_rollback();
            log_message('error', '[CUSTOMERS_BULK_DELETE] Service DB error: ' . $service_result['message']);
            return $this->json_response(500, $this->bulk_response('error', 'Gagal update status service: ' . $service_result['message']));
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            return $this->json_response(500, $this->bulk_response('error', 'Transaction bulk delete gagal.'));
        }

        $this->db->trans_commit();

        return $this->json_response(200, $this->bulk_response('success', 'Bulk hapus berhasil diproses.', array(
            'processed' => count($ids),
        )));
    }

    private function is_static_customer_record($customer)
    {
        if (is_object($customer)) {
            $customer = (array) $customer;
        }
        if (!is_array($customer)) {
            return false;
        }

        $connection_type = strtoupper(trim((string) ($customer['connection_type'] ?? '')));
        if ($connection_type === 'STATIC') {
            return true;
        }

        $service_mode = strtolower(trim((string) ($customer['service_mode'] ?? '')));
        if ($service_mode === 'static') {
            return true;
        }

        $queue_name = trim((string) ($customer['queue_name'] ?? ''));
        $pppoe_username = trim((string) ($customer['pppoe_username'] ?? ''));
        return $queue_name !== '' && $pppoe_username === '';
    }

    public function bulk_disable()
    {
        $this->require_role(array('superadmin', 'admin'), 'Akses ditolak. Teknisi tidak diperbolehkan menonaktifkan pelanggan.');

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(405, $this->bulk_response('error', 'Method Not Allowed'));
        }

        $ids = $this->parse_bulk_customer_ids();
        if (empty($ids)) {
            return $this->json_response(422, $this->bulk_response('error', 'Pilih minimal 1 customer.'));
        }

        $status_value = $this->resolve_customer_status_value(array('suspended', 'disabled', 'inactive'));
        if ($status_value === null) {
            return $this->json_response(500, $this->bulk_response('error', 'Kolom status customers tidak kompatibel untuk disable.'));
        }

        $username_map = $this->get_bulk_pppoe_username_map($ids);
        if (empty($username_map)) {
            return $this->json_response(422, $this->bulk_response('error', 'Username PPPoE tidak ditemukan pada customer terpilih.'));
        }
        $router_map = $this->get_bulk_customer_router_map($ids);

        $success = 0;
        $failed = 0;
        $failed_rows = array();
        $now = date('Y-m-d H:i:s');

        foreach ($ids as $customer_id) {
            if (!isset($username_map[$customer_id])) {
                $failed++;
                $failed_rows[] = 'ID ' . $customer_id . ': username PPP kosong.';
                continue;
            }

            $pppoe_username = trim((string) $username_map[$customer_id]);
            if ($pppoe_username === '') {
                $failed++;
                $failed_rows[] = 'ID ' . $customer_id . ': username PPP kosong.';
                continue;
            }

            $router_id = (int) ($router_map[$customer_id] ?? 0);
            if ($router_id <= 0) {
                $failed++;
                $failed_rows[] = 'ID ' . $customer_id . ': router_id customer belum terpasang.';
                continue;
            }

            $router_result = $this->disable_ppp_secret_by_username($pppoe_username, $router_id);
            if (empty($router_result['success'])) {
                $failed++;
                $failed_rows[] = 'ID ' . $customer_id . ': ' . (string) ($router_result['message'] ?? 'Gagal disable PPP di MikroTik');
                continue;
            }

            $customer_payload = array('status' => $status_value);
            if ($this->has_field('updated_at')) {
                $customer_payload['updated_at'] = $now;
            }

            $this->db->trans_begin();
            $this->db->where('id', (int) $customer_id)->update('customers', $customer_payload);
            $customer_error = $this->db->error();
            if ((int) ($customer_error['code'] ?? 0) !== 0) {
                $this->db->trans_rollback();
                $failed++;
                $failed_rows[] = 'ID ' . $customer_id . ': DB customers error - ' . (string) ($customer_error['message'] ?? 'unknown');
                continue;
            }

            if ($this->db->table_exists('customer_services')) {
                $service_fields = $this->db->list_fields('customer_services');
                if (in_array('status', $service_fields, true)) {
                    $service_payload = array('status' => 'suspended');
                    if (in_array('updated_at', $service_fields, true)) {
                        $service_payload['updated_at'] = $now;
                    }

                    $this->db->where('customer_id', (int) $customer_id)->update('customer_services', $service_payload);
                    $service_error = $this->db->error();
                    if ((int) ($service_error['code'] ?? 0) !== 0) {
                        $this->db->trans_rollback();
                        $failed++;
                        $failed_rows[] = 'ID ' . $customer_id . ': DB customer_services error - ' . (string) ($service_error['message'] ?? 'unknown');
                        continue;
                    }
                }
            }

            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();
                $failed++;
                $failed_rows[] = 'ID ' . $customer_id . ': transaction gagal.';
                continue;
            }

            $this->db->trans_commit();
            $success++;
        }

        $message = 'Bulk disable selesai. Success=' . $success . ', Failed=' . $failed . '.';
        if (!empty($failed_rows)) {
            log_message('error', '[CUSTOMERS_BULK_DISABLE] ' . implode(' | ', $failed_rows));
        }

        return $this->json_response(200, $this->bulk_response($failed > 0 ? 'partial' : 'success', $message, array(
            'processed' => count($ids),
            'success_count' => $success,
            'failed_count' => $failed,
            'failed_details' => $failed_rows,
        )));
    }

    public function bulk_generate_invoice()
    {
        $this->require_role(array('superadmin', 'admin'), 'Akses ditolak. Teknisi tidak diperbolehkan generate invoice.');

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(405, $this->bulk_response('error', 'Method Not Allowed'));
        }

        $ids = $this->parse_bulk_customer_ids();
        if (empty($ids)) {
            return $this->json_response(422, $this->bulk_response('error', 'Pilih minimal 1 customer.'));
        }

        if (!$this->db->table_exists('invoices')) {
            return $this->json_response(500, $this->bulk_response('error', 'Tabel invoices tidak ditemukan.'));
        }

        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;

        $period_ym = date('Y-m');
        $inserted = 0;
        $skipped = 0;
        $failed = 0;
        $failed_rows = array();

        try {
            foreach ($ids as $customer_id) {
                $customer = $this->customer_model->get_by_id($customer_id);
                if (!$customer) {
                    $failed++;
                    $failed_rows[] = 'ID ' . $customer_id . ': customer tidak ditemukan.';
                    continue;
                }

                if ($this->billing_automation_model->invoice_exists_for_period($customer_id, $period_ym)) {
                    $skipped++;
                    continue;
                }

                $service_context = $this->get_customer_service_context($customer_id, $customer);
                $price = (float) ($service_context['price'] ?? 0);
                $customer_service_id = (int) ($service_context['customer_service_id'] ?? 0);

                if ($price <= 0) {
                    $failed++;
                    $failed_rows[] = 'ID ' . $customer_id . ': harga paket tidak valid.';
                    continue;
                }

                $result = $this->create_manual_invoice($customer_id, $customer_service_id, $price, 'Bulk generate invoice dari Customers list');
                if (empty($result['success'])) {
                    $failed++;
                    $failed_rows[] = 'ID ' . $customer_id . ': ' . (string) ($result['message'] ?? 'Gagal generate invoice.');
                    continue;
                }

                $inserted++;
            }

            if (!empty($failed_rows)) {
                log_message('error', '[CUSTOMERS_BULK_INVOICE] ' . implode(' | ', $failed_rows));
            }

            $message = 'Bulk generate invoice selesai. Inserted=' . $inserted . ', Skipped=' . $skipped . ', Failed=' . $failed . '.';
            return $this->json_response(200, $this->bulk_response($failed > 0 ? 'partial' : 'success', $message, array(
                'processed' => count($ids),
                'inserted' => $inserted,
                'skipped' => $skipped,
                'failed' => $failed,
                'failed_details' => $failed_rows,
            )));
        } catch (Throwable $e) {
            log_message('error', '[CUSTOMERS_BULK_INVOICE] ' . $e->getMessage());
            return $this->json_response(500, $this->bulk_response('error', 'Generate invoice gagal: ' . $e->getMessage()));
        } finally {
            $this->db->db_debug = $old_db_debug;
        }
    }

    public function unique_pppoe_username($username, $ignore_id = 0)
    {
        $username = trim((string) $username);
        $ignore_id = (int) $ignore_id;

        if ($username === '') {
            return true;
        }

        if ($ignore_id > 0 && $this->is_same_customer_pppoe_username($ignore_id, $username)) {
            return true;
        }

        if ($this->customer_model->exists_username_any($username, $ignore_id)) {
            $this->form_validation->set_message('unique_pppoe_username', 'Username PPP sudah digunakan.');
            return false;
        }

        return true;
    }

    private function is_same_customer_pppoe_username($customer_id, $username)
    {
        $customer_id = (int) $customer_id;
        $username = trim((string) $username);

        if ($customer_id <= 0 || $username === '') {
            return false;
        }

        $current_username = trim((string) $this->billing_automation_model->get_customer_current_pppoe_username($customer_id));
        if ($current_username !== '' && strcasecmp($current_username, $username) === 0) {
            return true;
        }

        return false;
    }

    private function render_form($mode, $customer = null, $id = 0, $selected_profile_id = null)
    {
        $is_edit = ($mode === 'edit');
        $action = $is_edit ? ('customers/update/' . (int) $id) : 'customers/store';
        $selected_technician_id = $this->resolve_selected_technician_id($customer);
        $selected_router_id = $this->resolve_selected_router_id($customer);
        $selected_service_mode = $this->resolve_customer_service_mode($customer, (int) $id);

        $this->load->view('customers/form', array(
            'mode' => $mode,
            'form_action' => $action,
            'customer' => $customer,
            'fields' => $this->customer_fields,
            'ppp_profiles' => $this->ppp_profile_model->dropdown_options(),
            'location_options' => $this->master_reference_model->dropdown_locations(),
            'olt_options' => $this->master_reference_model->dropdown_olts(),
            'odp_options' => $this->get_odp_options($selected_router_id),
            'selected_profile_id' => $selected_profile_id,
            'teknisi_options' => $this->get_teknisi_options($selected_router_id),
            'selected_technician_id' => $selected_technician_id,
            'router_options' => $this->get_router_options(),
            'selected_router_id' => $selected_router_id,
            'selected_service_mode' => $selected_service_mode,
        ));
    }

    private function render_create_form()
    {
        $selected_router_id = (int) set_value('router_id', 0);
        if ($selected_router_id <= 0) {
            $effective_router_id = $this->getEffectiveRouterId();
            if ($effective_router_id !== null) {
                $selected_router_id = (int) $effective_router_id;
            }
        }
        if ($selected_router_id <= 0) {
            $routers = $this->get_router_options();
            if (count($routers) === 1) {
                $selected_router_id = (int) ($routers[0]['id'] ?? 0);
            }
        }
        $selected_technician_id = (int) set_value('technician_id', 0);

        $this->load->view('customers/create', array(
            'ppp_profiles' => $this->ppp_profile_model->dropdown_options(),
            'location_options' => $this->master_reference_model->dropdown_locations(),
            'olt_options' => $this->master_reference_model->dropdown_olts(),
            'fields' => $this->customer_fields,
            'teknisi_options' => $this->get_teknisi_options($selected_router_id),
            'selected_technician_id' => $selected_technician_id,
            'router_options' => $this->get_router_options(),
            'selected_router_id' => $selected_router_id,
        ));
    }

    private function resolve_customer_profile_id($customer, $customer_id = 0)
    {
        $from_post = trim((string) $this->input->post('ppp_profile_id', true));
        if ($from_post !== '' && ctype_digit($from_post) && (int) $from_post > 0) {
            return (int) $from_post;
        }

        if (is_object($customer)) {
            if (isset($customer->ppp_profile_id) && (int) $customer->ppp_profile_id > 0) {
                return (int) $customer->ppp_profile_id;
            }
            if (isset($customer->profile_id) && (int) $customer->profile_id > 0) {
                return (int) $customer->profile_id;
            }
            if ($customer_id <= 0 && isset($customer->id)) {
                $customer_id = (int) $customer->id;
            }
        }

        $customer_id = (int) $customer_id;
        if ($customer_id > 0 && $this->db->table_exists('customer_services')) {
            $service_fields = $this->db->list_fields('customer_services');
            if (!in_array('ppp_profile_id', $service_fields, true)) {
                return null;
            }

            $row = $this->db
                ->select('ppp_profile_id')
                ->from('customer_services')
                ->where('customer_id', $customer_id)
                ->order_by('id', 'DESC')
                ->limit(1)
                ->get()
                ->row_array();

            if (!empty($row['ppp_profile_id'])) {
                return (int) $row['ppp_profile_id'];
            }
        }

        return null;
    }

    private function set_validation_rules($ignore_id = 0, $is_create = true, $service_mode = 'pppoe')
    {
        $this->form_validation->set_message('regex_match', 'Format {field} tidak valid.');

        $this->form_validation->set_rules('full_name', 'Nama pelanggan', 'trim|required|min_length[3]|max_length[150]');
        $this->form_validation->set_rules('service_mode', 'Mode layanan', 'trim|required|in_list[pppoe,static]');

        $service_mode = strtolower(trim((string) $service_mode));
        if (!in_array($service_mode, array('pppoe', 'static'), true)) {
            $service_mode = 'pppoe';
        }
        $is_static = ($service_mode === 'static');

        if (!$is_static) {
            if ($is_create) {
                $this->form_validation->set_rules('pppoe_username', 'Username PPP', 'trim|min_length[3]|max_length[100]|regex_match[/^[a-zA-Z0-9._-]+$/]|callback_unique_pppoe_username[' . (int) $ignore_id . ']');
                $this->form_validation->set_rules('pppoe_password', 'Password PPP', 'trim|min_length[6]|max_length[100]');
            } else {
                $this->form_validation->set_rules('pppoe_username', 'Username PPP', 'trim|required|min_length[3]|max_length[100]|regex_match[/^[a-zA-Z0-9._-]+$/]|callback_unique_pppoe_username[' . (int) $ignore_id . ']');
                $this->form_validation->set_rules('pppoe_password', 'Password PPP', 'trim|min_length[6]|max_length[100]');
            }
            $this->form_validation->set_rules('ppp_profile_id', 'PPP Profile', 'trim|required|integer|greater_than[0]');
        }
        if ($this->db->table_exists('routers')) {
            $this->form_validation->set_rules('router_id', 'Router', 'trim|required|integer|greater_than[0]');
        }
        if ($is_create) {
            $this->form_validation->set_rules('lokasi', 'Lokasi', 'trim|required|min_length[2]|max_length[40]|regex_match[/^[a-zA-Z0-9\\-\\s]+$/]');
            if (!$is_static) {
                $this->form_validation->set_rules('olt', 'OLT', 'trim|required|min_length[2]|max_length[40]|regex_match[/^[a-zA-Z0-9\\-\\s]+$/]');
            }

            if ($this->has_field('installation_date')) {
                $this->form_validation->set_rules('installation_date', 'Tanggal instalasi', 'trim|required|regex_match[/^\\d{4}-\\d{2}-\\d{2}$/]');
            } else {
                $this->form_validation->set_rules('install_date', 'Tanggal instalasi', 'trim|required|regex_match[/^\\d{4}-\\d{2}-\\d{2}$/]');
            }
        }

        if ($this->has_field('phone')) {
            $this->form_validation->set_rules('phone', 'Nomor HP', 'trim|min_length[8]|max_length[20]');
        }
        if ($this->has_field('address')) {
            $this->form_validation->set_rules('address', 'Alamat', 'trim|required');
        }
        if ($this->has_field('email')) {
            $this->form_validation->set_rules('email', 'Email', 'trim|valid_email');
        }
        if ($this->has_field('status')) {
            $this->form_validation->set_rules('status', 'Status', 'trim|required');
        }
        if ($this->has_field('latitude')) {
            $this->form_validation->set_rules('latitude', 'Latitude', 'trim|decimal');
        }
        if ($this->has_field('longitude')) {
            $this->form_validation->set_rules('longitude', 'Longitude', 'trim|decimal');
        }
        if ($this->has_field('odp_id')) {
            $this->form_validation->set_rules('odp_id', 'ODP', 'trim|integer');
        }
        if ($this->has_field('technician_id')) {
            $this->form_validation->set_rules('technician_id', 'Assign teknisi', 'trim|integer');
        }
    }

    private function resolve_customer_service_mode($customer = null, $customer_id = 0)
    {
        $posted_mode = strtolower(trim((string) $this->input->post('service_mode', true)));
        if (in_array($posted_mode, array('pppoe', 'static'), true)) {
            return $posted_mode;
        }

        if (is_object($customer)) {
            $customer_service_mode = strtolower(trim((string) ($customer->service_mode ?? '')));
            if (in_array($customer_service_mode, array('pppoe', 'static'), true)) {
                return $customer_service_mode;
            }

            $customer_connection_type = strtoupper(trim((string) ($customer->connection_type ?? '')));
            if ($customer_connection_type === 'STATIC') {
                return 'static';
            }
            if ($customer_connection_type === 'PPPOE') {
                return 'pppoe';
            }

            $customer_queue_name = trim((string) ($customer->queue_name ?? ''));
            if ($customer_queue_name !== '') {
                return 'static';
            }

            $customer_notes = strtoupper(trim((string) ($customer->notes ?? '')));
            if ($customer_notes !== '' && strpos($customer_notes, 'STATIC') !== false) {
                return 'static';
            }
        }

        $posted_profile_id = (int) $this->input->post('ppp_profile_id', true);
        $posted_pppoe_username = trim((string) $this->input->post('pppoe_username', true));
        if ($posted_profile_id > 0 || $posted_pppoe_username !== '') {
            return 'pppoe';
        }

        if (is_object($customer)) {
            $customer_profile = 0;
            if (isset($customer->ppp_profile_id)) {
                $customer_profile = (int) $customer->ppp_profile_id;
            }
            if ($customer_profile <= 0 && isset($customer->profile_id)) {
                $customer_profile = (int) $customer->profile_id;
            }

            $customer_username = trim((string) ($customer->pppoe_username ?? ($customer->username ?? '')));
            if ($customer_profile > 0 && $customer_username === '') {
                return 'static';
            }
            if ($customer_profile > 0 || $customer_username !== '') {
                return 'pppoe';
            }

            if ($customer_id <= 0 && isset($customer->id)) {
                $customer_id = (int) $customer->id;
            }
        }

        $customer_id = (int) $customer_id;
        if ($customer_id > 0 && $this->db->table_exists('customer_services')) {
            $service_fields = $this->db->list_fields('customer_services');
            $select = array();
            if (in_array('ppp_profile_id', $service_fields, true)) {
                $select[] = 'ppp_profile_id';
            }
            if (in_array('pppoe_username', $service_fields, true)) {
                $select[] = 'pppoe_username';
            }

            if (!empty($select)) {
                $row = $this->db
                    ->select(implode(', ', $select), false)
                    ->from('customer_services')
                    ->where('customer_id', $customer_id)
                    ->order_by('id', 'DESC')
                    ->limit(1)
                    ->get()
                    ->row_array();

                if (!empty($row)) {
                    $profile_id = (int) ($row['ppp_profile_id'] ?? 0);
                    $username = trim((string) ($row['pppoe_username'] ?? ''));
                    if ($profile_id > 0 || $username !== '') {
                        return 'pppoe';
                    }
                }
            }
        }

        return 'static';
    }

    private function strip_pppoe_payload_for_static_mode(array &$payload)
    {
        $fields = array(
            'pppoe_username',
            'pppoe_password',
            'ppp_password',
            'vlan_id',
            'vlan_info',
        );

        foreach ($fields as $field) {
            if (array_key_exists($field, $payload)) {
                unset($payload[$field]);
            }
        }

        // Static customer tidak memakai OLT/PON mapping.
        if ($this->has_field('olt')) {
            $payload['olt'] = null;
        }
    }

    private function strip_pppoe_payload_for_standard_update(array &$payload)
    {
        $fields = array(
            'username',
            'pppoe_username',
            'pppoe_password',
            'ppp_password',
            'profile_id',
            'ppp_profile_id',
            'package_price',
            'vlan_id',
            'vlan_info',
        );

        foreach ($fields as $field) {
            if (array_key_exists($field, $payload)) {
                unset($payload[$field]);
            }
        }
    }

    private function should_sync_pppoe_update($customer, array $service, $ppp_profile_id, $router_id, $username, $password)
    {
        $current_profile_id = 0;
        if (is_object($customer)) {
            if (isset($customer->ppp_profile_id) && (int) $customer->ppp_profile_id > 0) {
                $current_profile_id = (int) $customer->ppp_profile_id;
            } elseif (isset($customer->profile_id) && (int) $customer->profile_id > 0) {
                $current_profile_id = (int) $customer->profile_id;
            }
        }
        if ($current_profile_id <= 0) {
            $current_profile_id = (int) ($service['ppp_profile_id'] ?? 0);
        }

        $current_username = '';
        if (is_object($customer)) {
            foreach (array('pppoe_username', 'username') as $field) {
                if (isset($customer->{$field})) {
                    $current_username = trim((string) $customer->{$field});
                    if ($current_username !== '') {
                        break;
                    }
                }
            }
        }
        if ($current_username === '') {
            $current_username = trim((string) ($service['pppoe_username'] ?? ''));
        }

        $current_password = '';
        if (is_object($customer)) {
            foreach (array('pppoe_password', 'ppp_password') as $field) {
                if (isset($customer->{$field})) {
                    $current_password = trim((string) $customer->{$field});
                    if ($current_password !== '') {
                        break;
                    }
                }
            }
        }

        $current_router_id = 0;
        if (is_object($customer) && isset($customer->router_id)) {
            $current_router_id = (int) $customer->router_id;
        }
        if ($current_router_id <= 0) {
            $current_router_id = (int) ($service['router_id'] ?? 0);
        }

        $current_ip = '';
        if (is_object($customer) && isset($customer->ip_address)) {
            $current_ip = $this->normalize_ipv4((string) $customer->ip_address);
        }
        if ($current_ip === '') {
            $current_ip = $this->normalize_ipv4((string) ($service['ip_address'] ?? ''));
        }

        $requested_username = trim((string) $username);
        $requested_password = trim((string) $password);
        $requested_router_id = (int) $router_id;
        $requested_profile_id = (int) $ppp_profile_id;
        $requested_ip = $this->normalize_ipv4((string) $this->input->post('ip_address', true));
        $effective_requested_ip = $requested_ip !== '' ? $requested_ip : $current_ip;

        if ($requested_profile_id !== $current_profile_id) {
            return true;
        }
        if ($requested_router_id !== $current_router_id) {
            return true;
        }
        if ($requested_username !== $current_username) {
            return true;
        }
        if ($requested_password !== '' && $requested_password !== $current_password) {
            return true;
        }
        if ($effective_requested_ip !== $current_ip) {
            return true;
        }

        return false;
    }

    private function collect_form_payload()
    {
        $payload = array();
        $candidate_fields = array(
            'customer_code',
            'full_name',
            'nama',
            'phone',
            'email',
            'address',
            'lokasi',
            'olt',
            'odp_id',
            'latitude',
            'longitude',
            'username',
            'pppoe_username',
            'pppoe_password',
            'ppp_password',
            'vlan_id',
            'vlan_info',
            'installation_status',
            'install_date',
            'installation_date',
            'join_date',
            'status',
            'technician_id',
            'router_id',
            'service_mode',
            'profile_id',
            'ppp_profile_id',
            'package_price',
        );

        foreach ($candidate_fields as $field) {
            if (!$this->has_field($field)) {
                continue;
            }

            $value = $this->input->post($field, true);
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($field === 'profile_id' || $field === 'ppp_profile_id' || $field === 'technician_id' || $field === 'router_id' || $field === 'odp_id') {
                $value = ($value === '') ? null : (int) $value;
            }

            $payload[$field] = $value;
        }

        if ($this->has_field('status')) {
            $allowed = $this->get_customer_status_values();
            $requested_status = strtolower(trim((string) ($payload['status'] ?? '')));

            if (!empty($allowed)) {
                if ($requested_status === '' || !in_array($requested_status, $allowed, true)) {
                    if (in_array('pending', $allowed, true)) {
                        $payload['status'] = 'pending';
                    } elseif (in_array('active', $allowed, true)) {
                        $payload['status'] = 'active';
                    } else {
                        $payload['status'] = (string) reset($allowed);
                    }
                } else {
                    $payload['status'] = $requested_status;
                }
            } elseif ($requested_status === '') {
                $payload['status'] = 'pending';
            }
        }
        if ($this->has_field('nama') && empty($payload['nama']) && !empty($payload['full_name'])) {
            $payload['nama'] = (string) $payload['full_name'];
        }
        if ($this->has_field('customer_code') && trim((string) ($payload['customer_code'] ?? '')) === '') {
            $payload['customer_code'] = $this->generate_customer_code();
        }
        if ($this->has_field('join_date') && trim((string) ($payload['join_date'] ?? '')) === '') {
            $join_date = $this->resolve_installation_date_from_post();
            if ($join_date === '') {
                $join_date = date('Y-m-d');
            }
            $payload['join_date'] = $join_date;
        }

        return $payload;
    }

    private function resolve_selected_technician_id($customer = null)
    {
        $selected = (int) set_value('technician_id', 0);
        if ($selected > 0) {
            return $selected;
        }

        if (is_object($customer) && isset($customer->technician_id) && (int) $customer->technician_id > 0) {
            return (int) $customer->technician_id;
        }

        $customer_id = is_object($customer) && isset($customer->id) ? (int) $customer->id : 0;
        if ($customer_id <= 0 || !$this->db->table_exists('work_orders')) {
            return 0;
        }

        $wo_fields = $this->db->list_fields('work_orders');
        if (!in_array('customer_id', $wo_fields, true) || !in_array('assigned_to', $wo_fields, true)) {
            return 0;
        }

        $qb = $this->db
            ->select('assigned_to')
            ->from('work_orders')
            ->where('customer_id', $customer_id)
            ->where('assigned_to IS NOT NULL', null, false)
            ->where('assigned_to >', 0);

        if (in_array('status', $wo_fields, true)) {
            $qb->where("LOWER(status) <> 'cancelled'", null, false);
        }
        if (in_array('deleted_at', $wo_fields, true)) {
            $qb->where('deleted_at IS NULL', null, false);
        }
        if (in_array('is_deleted', $wo_fields, true)) {
            $qb->where('is_deleted', 0);
        }

        $row = $qb->order_by('id', 'DESC')->limit(1)->get()->row_array();
        return !empty($row['assigned_to']) ? (int) $row['assigned_to'] : 0;
    }

    private function attach_profile_and_credential_to_customer_payload(array &$payload, $ppp_profile_id, $username, $password, $profile_price = null, $vlan_id = '')
    {
        $this->attach_profile_to_customer_payload($payload, $ppp_profile_id, $profile_price);
        if ($vlan_id !== '') {
            if ($this->has_field('vlan_id')) {
                $payload['vlan_id'] = $vlan_id;
            }
            if ($this->has_field('vlan_info')) {
                $payload['vlan_info'] = $vlan_id;
            }
        }

        if ($this->has_field('username')) {
            $payload['username'] = $username;
        }
        if ($this->has_field('pppoe_username')) {
            $payload['pppoe_username'] = $username;
        }

        if ($password !== '') {
            if ($this->has_field('pppoe_password')) {
                $payload['pppoe_password'] = $password;
            }
            if ($this->has_field('ppp_password')) {
                $payload['ppp_password'] = $password;
            }
        }
    }

    private function attach_profile_to_customer_payload(array &$payload, $ppp_profile_id, $profile_price = null)
    {
        if ($this->has_field('profile_id')) {
            $payload['profile_id'] = (int) $ppp_profile_id;
        }
        if ($this->has_field('ppp_profile_id')) {
            $payload['ppp_profile_id'] = (int) $ppp_profile_id;
        }
        if ($this->has_field('package_price') && $profile_price !== null) {
            $payload['package_price'] = round((float) $profile_price, 2);
        }
    }

    private function apply_service_mode_to_customer_payload(array &$payload, $service_mode)
    {
        $service_mode = strtolower(trim((string) $service_mode));
        if (!in_array($service_mode, array('pppoe', 'static'), true)) {
            $service_mode = 'pppoe';
        }

        if ($this->has_field('service_mode')) {
            $payload['service_mode'] = $service_mode;
        }
        if ($this->has_field('connection_type')) {
            $payload['connection_type'] = $service_mode === 'static' ? 'STATIC' : 'PPPOE';
        }
    }

    private function upsert_customer_service($customer_id, $ppp_profile_id, $price, $pppoe_username = '', $router_id = 0)
    {
        if (!$this->db->table_exists('customer_services')) {
            return array(
                'success' => false,
                'message' => 'Tabel customer_services belum tersedia. Jalankan migration terlebih dahulu.',
            );
        }

        $fields = $this->db->list_fields('customer_services');
        if (!in_array('ppp_profile_id', $fields, true)) {
            return array(
                'success' => false,
                'message' => 'Kolom customer_services.ppp_profile_id belum ada. Jalankan migrasi customer_services ke skema terbaru.',
            );
        }

        $profile_ref = $this->resolve_customer_services_profile_reference((int) $ppp_profile_id);
        if (empty($profile_ref['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($profile_ref['message'] ?? 'Mapping profile customer_services gagal.'),
            );
        }

        $service_profile_id = (int) ($profile_ref['profile_id'] ?? 0);
        if ($service_profile_id <= 0) {
            return array(
                'success' => false,
                'message' => 'Profile ID untuk customer_services tidak valid.',
            );
        }

        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $next_billing = date('Y-m-d', strtotime('+1 month'));

        $existing = $this->db
            ->from('customer_services')
            ->where('customer_id', (int) $customer_id)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();

        $payload = array(
            'customer_id' => (int) $customer_id,
            'ppp_profile_id' => $service_profile_id,
            'price' => (float) $price,
            'status' => 'active',
            'updated_at' => $now,
        );
        $router_id = (int) $router_id;
        if (in_array('router_id', $fields, true) && $router_id > 0) {
            $payload['router_id'] = $router_id;
        }
        $selected_router = $router_id > 0 ? $this->get_router_by_id($router_id) : null;
        if (in_array('pppoe_username', $fields, true) && $pppoe_username !== '') {
            $payload['pppoe_username'] = $pppoe_username;
        }
        if (in_array('router_name', $fields, true)) {
            $router_name = '';
            if (!empty($selected_router['name'])) {
                $router_name = trim((string) $selected_router['name']);
            }
            if ($router_name === '') {
                $router_name = trim((string) $this->input->post('router_name', true));
            }
            if ($router_name !== '') {
                $payload['router_name'] = $router_name;
            }
        }
        if (in_array('ip_address', $fields, true)) {
            $ip_address = trim((string) $this->input->post('ip_address', true));
            $existing_ip = trim((string) ($existing['ip_address'] ?? ''));

            if ($ip_address !== '') {
                $normalized_ip = $this->normalize_ipv4($ip_address);
                if ($normalized_ip === '') {
                    return array('success' => false, 'message' => 'Format IP address tidak valid.');
                }
                $normalized_existing_ip = $this->normalize_ipv4($existing_ip);
                $existing_profile_id = (int) ($existing['ppp_profile_id'] ?? 0);

                if ($normalized_existing_ip !== ''
                    && $normalized_ip === $normalized_existing_ip
                    && $existing_profile_id === $service_profile_id
                ) {
                    $payload['ip_address'] = $normalized_existing_ip;
                } else {
                    $ip_validation = $this->validate_ip_for_profile(
                        (int) $ppp_profile_id,
                        $normalized_ip,
                        (int) ($existing['id'] ?? 0)
                    );
                    if (empty($ip_validation['success'])) {
                        return array('success' => false, 'message' => (string) ($ip_validation['message'] ?? 'IP remote tidak valid.'));
                    }
                    $payload['ip_address'] = $normalized_ip;
                }
            } elseif ($existing_ip !== '') {
                $normalized_existing_ip = $this->normalize_ipv4($existing_ip);
                if ($normalized_existing_ip !== '') {
                    $payload['ip_address'] = $normalized_existing_ip;
                }
            } else {
                $allocated = $this->allocate_ip_from_profile((int) $ppp_profile_id, (int) ($existing['id'] ?? 0));
                if (empty($allocated['success'])) {
                    return array('success' => false, 'message' => (string) ($allocated['message'] ?? 'Gagal auto assign IP dari pool.'));
                }
                $payload['ip_address'] = (string) $allocated['ip_address'];
            }
        }

        $payload = $this->filter_payload_by_fields($payload, $fields);

        if ($existing) {
            $old_db_debug = $this->db->db_debug;
            $this->db->db_debug = false;
            $ok = $this->db
                ->where('id', (int) $existing['id'])
                ->update('customer_services', $payload);
            $db_error = $this->db->error();
            $this->db->db_debug = $old_db_debug;

            if (!$ok || (int) ($db_error['code'] ?? 0) !== 0) {
                return array('success' => false, 'message' => 'Gagal update customer_services: ' . json_encode($db_error));
            }

            return array(
                'success' => true,
                'customer_service_id' => (int) $existing['id'],
                'ip_address' => (string) ($payload['ip_address'] ?? $existing['ip_address'] ?? ''),
                'router_id' => (int) ($payload['router_id'] ?? $existing['router_id'] ?? 0),
            );
        }

        $insert_payload = $payload;
        if (in_array('created_at', $fields, true)) {
            $insert_payload['created_at'] = $now;
        }
        if (in_array('install_date', $fields, true)) {
            $insert_payload['install_date'] = $today;
        }
        if (in_array('next_billing_date', $fields, true)) {
            $insert_payload['next_billing_date'] = $next_billing;
        }
        if (in_array('service_number', $fields, true)) {
            $insert_payload['service_number'] = 'SVC-' . date('Ymd') . '-' . str_pad((string) $customer_id, 6, '0', STR_PAD_LEFT);
        }

        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('customer_services', $insert_payload);
        $db_error = $this->db->error();
        $this->db->db_debug = $old_db_debug;
        if (!$ok || (int) ($db_error['code'] ?? 0) !== 0) {
            return array('success' => false, 'message' => 'Gagal insert customer_services: ' . json_encode($db_error));
        }

        return array(
            'success' => true,
            'customer_service_id' => (int) $this->db->insert_id(),
            'ip_address' => (string) ($insert_payload['ip_address'] ?? ''),
            'router_id' => (int) ($insert_payload['router_id'] ?? 0),
        );
    }

    private function resolve_customer_services_profile_reference($ppp_profile_id)
    {
        $ppp_profile_id = (int) $ppp_profile_id;
        if ($ppp_profile_id <= 0) {
            return array('success' => false, 'message' => 'PPP Profile tidak valid.');
        }

        $fk_table = $this->get_fk_referenced_table('customer_services', 'ppp_profile_id');
        $fk_table_lc = strtolower(trim((string) $fk_table));
        $ppp_profiles_table = strtolower($this->db->dbprefix('ppp_profiles'));

        if ($fk_table_lc === '' || $fk_table_lc === 'ppp_profiles' || $fk_table_lc === $ppp_profiles_table) {
            return array('success' => true, 'profile_id' => $ppp_profile_id);
        }

        if ($fk_table_lc !== 'service_plans' && $fk_table_lc !== strtolower($this->db->dbprefix('service_plans'))) {
            return array(
                'success' => false,
                'message' => 'FK customer_services.ppp_profile_id mengarah ke tabel `' . $fk_table . '` yang tidak dikenali.',
            );
        }

        if (!$this->db->table_exists('service_plans')) {
            return array(
                'success' => false,
                'message' => 'FK masih mengarah ke service_plans, tapi tabel service_plans tidak ditemukan.',
            );
        }

        if (!$this->db->table_exists('ppp_profiles')) {
            return array(
                'success' => false,
                'message' => 'Tabel ppp_profiles tidak ditemukan untuk mapping ke service_plans.',
            );
        }

        $service_plan_fields = $this->db->list_fields('service_plans');
        $service_plan_has_router_id = in_array('router_id', $service_plan_fields, true);

        $ppp_profile_fields = $this->db->list_fields('ppp_profiles');
        $profile_select = array('id', 'name', 'price');
        if (in_array('router_id', $ppp_profile_fields, true)) {
            $profile_select[] = 'router_id';
        }

        $profile = $this->db
            ->select(implode(', ', $profile_select))
            ->from('ppp_profiles')
            ->where('id', $ppp_profile_id)
            ->limit(1)
            ->get()
            ->row_array();
        if (empty($profile)) {
            return array(
                'success' => false,
                'message' => 'PPP Profile id `' . $ppp_profile_id . '` tidak ditemukan.',
            );
        }

        $profile_name = trim((string) ($profile['name'] ?? ''));
        if ($profile_name === '') {
            return array(
                'success' => false,
                'message' => 'Nama PPP Profile kosong, tidak bisa mapping ke service_plans.',
            );
        }

        $profile_router_id = (int) ($profile['router_id'] ?? 0);
        $direct_query = $this->db
            ->select('id')
            ->from('service_plans')
            ->where('id', $ppp_profile_id);
        if ($service_plan_has_router_id && $profile_router_id > 0) {
            $direct_query->where('router_id', $profile_router_id);
        }

        $direct = $direct_query
            ->limit(1)
            ->get()
            ->row_array();
        if (!empty($direct['id'])) {
            return array('success' => true, 'profile_id' => (int) $direct['id']);
        }

        $candidate_columns = array('mikrotik_profile', 'profile_name', 'plan_name', 'name', 'package_name', 'title');

        foreach ($candidate_columns as $column) {
            if (!in_array($column, $service_plan_fields, true)) {
                continue;
            }

            $row_query = $this->db
                ->select('id')
                ->from('service_plans')
                ->where($column, $profile_name);
            if ($service_plan_has_router_id && $profile_router_id > 0) {
                $row_query->where('router_id', $profile_router_id);
            }

            $row = $row_query
                ->limit(1)
                ->get()
                ->row_array();

            if (!empty($row['id'])) {
                return array('success' => true, 'profile_id' => (int) $row['id']);
            }
        }

        $compat = $this->ensure_service_plan_compat_row($ppp_profile_id, $profile, $service_plan_fields);
        if (!empty($compat['success'])) {
            return array('success' => true, 'profile_id' => (int) $compat['profile_id']);
        }

        return array(
            'success' => false,
            'message' => (string) ($compat['message'] ?? ('FK customer_services masih ke service_plans, tetapi profile `' . $profile_name . '` belum ada di service_plans. Gunakan schema final `docs/02_SKEMA_SQL_FINAL_SUPERAPSS.sql` lalu sinkronkan ulang profile.')),
        );
    }

    private function ensure_service_plan_compat_row($ppp_profile_id, array $profile, array $service_plan_fields)
    {
        if (!$this->db->table_exists('service_plans')) {
            return array(
                'success' => false,
                'message' => 'Tabel service_plans tidak ditemukan untuk mode kompatibilitas FK.',
            );
        }

        $profile_name = trim((string) ($profile['name'] ?? ''));
        if ($profile_name === '') {
            return array(
                'success' => false,
                'message' => 'Nama PPP profile kosong, tidak bisa membuat service_plan kompatibilitas.',
            );
        }

        $payload = array();
        $profile_router_id = (int) ($profile['router_id'] ?? 0);
        if (in_array('router_id', $service_plan_fields, true)) {
            if ($profile_router_id <= 0) {
                return array(
                    'success' => false,
                    'message' => 'PPP profile tidak punya router_id yang valid, tidak bisa membuat service_plan kompatibilitas.',
                );
            }

            $payload['router_id'] = $profile_router_id;
        }
        if (in_array('id', $service_plan_fields, true)) {
            $payload['id'] = (int) $ppp_profile_id;
        }
        if (in_array('plan_code', $service_plan_fields, true)) {
            $payload['plan_code'] = 'AUTO-PPP-' . (int) $ppp_profile_id;
        }
        if (in_array('plan_name', $service_plan_fields, true)) {
            $payload['plan_name'] = $profile_name;
        }
        if (in_array('speed_profile', $service_plan_fields, true)) {
            $payload['speed_profile'] = $profile_name;
        }
        if (in_array('monthly_price', $service_plan_fields, true)) {
            $payload['monthly_price'] = (float) ($profile['price'] ?? 0);
        }
        if (in_array('installation_fee', $service_plan_fields, true)) {
            $payload['installation_fee'] = 0;
        }
        if (in_array('is_active', $service_plan_fields, true)) {
            $payload['is_active'] = 1;
        }
        if (in_array('description', $service_plan_fields, true)) {
            $payload['description'] = 'Auto compatibility record from ppp_profiles(id=' . (int) $ppp_profile_id . ')';
        }
        if (in_array('created_at', $service_plan_fields, true)) {
            $payload['created_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $service_plan_fields, true)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('service_plans', $payload);
        $db_error = $this->db->error();
        $this->db->db_debug = $old_db_debug;

        if (!$ok || (int) ($db_error['code'] ?? 0) !== 0) {
            return array(
                'success' => false,
                'message' => 'Gagal auto-create service_plans compatibility row: ' . json_encode($db_error),
            );
        }

        return array(
            'success' => true,
            'profile_id' => (int) $ppp_profile_id,
        );
    }

    private function get_fk_referenced_table($table, $column)
    {
        $table = trim((string) $table);
        $column = trim((string) $column);
        if ($table === '' || $column === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return '';
        }

        $table_name = $this->db->dbprefix($table);
        $sql = "SELECT REFERENCED_TABLE_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
                  AND REFERENCED_TABLE_NAME IS NOT NULL
                LIMIT 1";

        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $query = $this->db->query($sql, array($table_name, $column));
        $this->db->db_debug = $old_db_debug;
        if (!$query) {
            return '';
        }

        $row = $query->row_array();
        return isset($row['REFERENCED_TABLE_NAME']) ? (string) $row['REFERENCED_TABLE_NAME'] : '';
    }

    private function allocate_ip_from_profile($ppp_profile_id, $exclude_service_id = 0)
    {
        $pool_info = $this->get_profile_pool_info((int) $ppp_profile_id);
        if (empty($pool_info['success'])) {
            return $pool_info;
        }

        $pool_name = (string) $pool_info['pool_name'];
        $start_long = (int) $pool_info['start_long'];
        $end_long = (int) $pool_info['end_long'];

        $used_map = $this->get_used_ip_map((int) $exclude_service_id, $pool_name, $start_long, $end_long);
        for ($current = $start_long; $current <= $end_long; $current++) {
            $candidate = long2ip($current);
            if ($candidate === false) {
                continue;
            }
            if (!isset($used_map[$candidate])) {
                return array(
                    'success' => true,
                    'ip_address' => $candidate,
                    'pool_name' => $pool_name,
                );
            }
        }

        return array('success' => false, 'message' => 'IP Pool penuh, tidak dapat assign IP');
    }

    private function get_profile_pool_info($ppp_profile_id)
    {
        if (!$this->db->table_exists('ppp_profiles')) {
            return array('success' => false, 'message' => 'Tabel ppp_profiles tidak ditemukan.');
        }

        $profile = $this->db
            ->select('id, remote_address_pool')
            ->from('ppp_profiles')
            ->where('id', (int) $ppp_profile_id)
            ->limit(1)
            ->get()
            ->row_array();
        if (!$profile) {
            return array('success' => false, 'message' => 'PPP Profile tidak ditemukan untuk auto assign IP.');
        }

        $pool_name = trim((string) ($profile['remote_address_pool'] ?? ''));
        if ($pool_name === '') {
            return array('success' => false, 'message' => 'PPP Profile belum terhubung ke IP Pool.');
        }

        // Prioritas ambil range langsung dari MikroTik agar selalu up-to-date.
        $router_pool = $this->fetch_pool_range_from_mikrotik($pool_name);
        if (!empty($router_pool['success'])) {
            $range_start = (string) $router_pool['range_start'];
            $range_end = (string) $router_pool['range_end'];
        } else {
            if (!$this->db->table_exists('ip_pools')) {
                return array('success' => false, 'message' => 'IP Pool `' . $pool_name . '` tidak ditemukan di router maupun tabel ip_pools.');
            }

            $pool = $this->db
                ->select('pool_name, range_start, range_end')
                ->from('ip_pools')
                ->where('pool_name', $pool_name)
                ->limit(1)
                ->get()
                ->row_array();
            if (!$pool) {
                return array('success' => false, 'message' => 'IP Pool `' . $pool_name . '` tidak ditemukan.');
            }

            $range_start = (string) ($pool['range_start'] ?? '');
            $range_end = (string) ($pool['range_end'] ?? '');
        }

        $range_start = $this->normalize_ipv4($range_start);
        $range_end = $this->normalize_ipv4($range_end);
        if ($range_start === '' || $range_end === '') {
            return array('success' => false, 'message' => 'Range IP Pool tidak valid.');
        }

        $start_long = ip2long($range_start);
        $end_long = ip2long($range_end);
        if ($start_long === false || $end_long === false || $end_long < $start_long) {
            return array('success' => false, 'message' => 'Range IP Pool tidak valid.');
        }

        return array(
            'success' => true,
            'pool_name' => $pool_name,
            'range_start' => $range_start,
            'range_end' => $range_end,
            'start_long' => (int) $start_long,
            'end_long' => (int) $end_long,
        );
    }

    private function fetch_pool_range_from_mikrotik($pool_name)
    {
        $pool_name = trim((string) $pool_name);
        if ($pool_name === '') {
            return array('success' => false, 'message' => 'pool_name kosong');
        }

        try {
            $this->load->library('mikrotik_api');
            $result = $this->mikrotik_api->command_safe('/ip/pool/print', array(
                '?name' => $pool_name,
                '.proplist' => 'name,ranges',
            ));
            if (empty($result['success']) || empty($result['data']) || !is_array($result['data'])) {
                return array(
                    'success' => false,
                    'message' => (string) ($result['error'] ?? 'Pool tidak ditemukan di MikroTik.'),
                );
            }

            $row = isset($result['data'][0]) && is_array($result['data'][0]) ? $result['data'][0] : array();
            $ranges = trim((string) ($row['ranges'] ?? ''));
            if ($ranges === '') {
                return array('success' => false, 'message' => 'Field ranges kosong di MikroTik.');
            }

            $first = trim((string) explode(',', $ranges)[0]);
            $pair = explode('-', $first, 2);
            if (count($pair) !== 2) {
                return array('success' => false, 'message' => 'Format ranges pool tidak valid.');
            }

            $range_start = $this->normalize_ipv4((string) $pair[0]);
            $range_end = $this->normalize_ipv4((string) $pair[1]);
            if ($range_start === '' || $range_end === '') {
                return array('success' => false, 'message' => 'Range pool MikroTik tidak valid.');
            }

            return array(
                'success' => true,
                'range_start' => $range_start,
                'range_end' => $range_end,
            );
        } catch (Throwable $e) {
            return array('success' => false, 'message' => $e->getMessage());
        } finally {
            if (isset($this->mikrotik_api)) {
                $this->mikrotik_api->disconnect();
            }
        }
    }

    private function validate_ip_for_profile($ppp_profile_id, $ip_address, $exclude_service_id = 0)
    {
        $ip_address = $this->normalize_ipv4($ip_address);
        if ($ip_address === '') {
            return array('success' => false, 'message' => 'Format IP remote tidak valid.');
        }

        $pool_info = $this->get_profile_pool_info((int) $ppp_profile_id);
        if (empty($pool_info['success'])) {
            return $pool_info;
        }

        $ip_long = ip2long($ip_address);
        if ($ip_long === false) {
            return array('success' => false, 'message' => 'Format IP remote tidak valid.');
        }

        if ($ip_long < (int) $pool_info['start_long'] || $ip_long > (int) $pool_info['end_long']) {
            return array(
                'success' => false,
                'message' => 'IP remote harus berada di range pool `' . (string) $pool_info['pool_name'] . '`.',
            );
        }

        $used_map = $this->get_used_ip_map(
            (int) $exclude_service_id,
            (string) $pool_info['pool_name'],
            (int) $pool_info['start_long'],
            (int) $pool_info['end_long']
        );
        if (isset($used_map[$ip_address])) {
            return array('success' => false, 'message' => 'IP remote `' . $ip_address . '` sudah terpakai.');
        }

        return array('success' => true, 'message' => 'OK');
    }

    private function get_used_ip_map($exclude_service_id = 0, $pool_name = '', $start_long = null, $end_long = null)
    {
        $used = array();
        if (!$this->db->table_exists('customer_services')) {
            $used = array();
        } else {
            $service_fields = $this->db->list_fields('customer_services');
            if (in_array('ip_address', $service_fields, true)) {
                $qb = $this->db
                    ->select('ip_address')
                    ->from('customer_services')
                    ->where('ip_address IS NOT NULL', null, false)
                    ->where("TRIM(ip_address) != ''", null, false);

                if ((int) $exclude_service_id > 0 && in_array('id', $service_fields, true)) {
                    $qb->where('id !=', (int) $exclude_service_id);
                }

                $rows = $qb->get()->result_array();
                foreach ($rows as $row) {
                    $normalized = $this->normalize_ipv4((string) ($row['ip_address'] ?? ''));
                    if ($normalized !== '') {
                        if ($start_long !== null && $end_long !== null) {
                            $ip_long = ip2long($normalized);
                            if ($ip_long === false || $ip_long < (int) $start_long || $ip_long > (int) $end_long) {
                                continue;
                            }
                        }
                        $used[$normalized] = true;
                    }
                }
            }
        }

        $mikrotik_used = $this->get_mikrotik_used_ip_map((string) $pool_name, $start_long, $end_long);
        foreach ($mikrotik_used as $ip => $flag) {
            $used[$ip] = true;
        }

        return $used;
    }

    private function get_mikrotik_used_ip_map($pool_name = '', $start_long = null, $end_long = null)
    {
        $cache_key = md5((string) $pool_name . '|' . (string) $start_long . '|' . (string) $end_long);
        if (isset($this->mikrotik_used_ip_cache[$cache_key])) {
            return $this->mikrotik_used_ip_cache[$cache_key];
        }

        $used = array();
        try {
            $this->load->library('mikrotik_api');

            $secret_result = $this->mikrotik_api->command_safe('/ppp/secret/print', array(
                '.proplist' => 'name,remote-address',
            ));
            if (!empty($secret_result['success']) && !empty($secret_result['data']) && is_array($secret_result['data'])) {
                foreach ($secret_result['data'] as $secret_row) {
                    if (!is_array($secret_row)) {
                        continue;
                    }

                    $remote = trim((string) ($secret_row['remote-address'] ?? ''));
                    $normalized = $this->normalize_ipv4($remote);
                    if ($normalized === '') {
                        continue;
                    }

                    if ($start_long !== null && $end_long !== null) {
                        $ip_long = ip2long($normalized);
                        if ($ip_long === false || $ip_long < (int) $start_long || $ip_long > (int) $end_long) {
                            continue;
                        }
                    }
                    $used[$normalized] = true;
                }
            }
        } catch (Throwable $e) {
            log_message('error', '[CUSTOMERS][IP_ALLOC] Gagal baca used IP dari MikroTik: ' . $e->getMessage());
        } finally {
            if (isset($this->mikrotik_api)) {
                $this->mikrotik_api->disconnect();
            }
        }

        $this->mikrotik_used_ip_cache[$cache_key] = $used;
        return $used;
    }

    private function normalize_ipv4($ip)
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '';
        }

        $ip = explode('/', $ip)[0];
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : '';
    }

    private function create_initial_invoice($customer_id, $customer_service_id, $price, $router_id = 0)
    {
        if (!$this->db->table_exists('invoices')) {
            return array(
                'success' => false,
                'message' => 'Tabel invoices belum tersedia.',
            );
        }

        $fields = $this->db->list_fields('invoices');
        $period_ym = date('Y-m');
        $period_start = $period_ym . '-01';
        $period_end = date('Y-m-t', strtotime($period_start));
        $issue_date = date('Y-m-d');
        $install_date = $this->resolve_customer_install_date((int) $customer_id);
        $due_date = $this->calculate_due_date_from_install($install_date, $period_start);
        $valid_service_id = $this->resolve_valid_customer_service_id((int) $customer_id, (int) $customer_service_id);

        $subtotal = (float) $price;
        $tax_amount = 0;
        $discount_amount = 0;
        $total_amount = round($subtotal + $tax_amount - $discount_amount, 2);
        $router_id = (int) $router_id;
        if ($router_id <= 0 && $this->db->table_exists('customers') && $this->has_field('router_id')) {
            $customer_router = $this->db
                ->select('router_id')
                ->from('customers')
                ->where('id', (int) $customer_id)
                ->limit(1)
                ->get()
                ->row_array();
            $router_id = (int) ($customer_router['router_id'] ?? 0);
        }

        $payload = array(
            'invoice_number' => $this->billing_automation_model->next_invoice_number($period_ym),
            'customer_id' => (int) $customer_id,
            'customer_service_id' => $valid_service_id,
            'billing_period_start' => $period_start,
            'billing_period_end' => $period_end,
            'issue_date' => $issue_date,
            'due_date' => $due_date,
            'subtotal' => $subtotal,
            'tax_amount' => $tax_amount,
            'discount_amount' => $discount_amount,
            'total_amount' => $total_amount,
            'paid_amount' => 0,
            'balance_amount' => $total_amount,
            'status' => 'issued',
            'notes' => 'Auto invoice saat customer dibuat (due=install+5).',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        if ($router_id > 0) {
            $payload['router_id'] = $router_id;
        }

        $payload = $this->filter_payload_by_fields($payload, $fields);
        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('invoices', $payload);
        $db_error = $this->db->error();
        $this->db->db_debug = $old_db_debug;
        if (!$ok) {
            return array('success' => false, 'message' => 'Gagal insert invoice: ' . json_encode($db_error));
        }

        return array('success' => true, 'invoice_id' => (int) $this->db->insert_id());
    }

    private function filter_payload_by_fields(array $payload, array $fields)
    {
        $filtered = array();
        foreach ($payload as $key => $value) {
            if (in_array($key, $fields, true)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    private function has_field($field)
    {
        return in_array($field, $this->customer_fields, true);
    }

    private function has_user_field($field)
    {
        return in_array($field, $this->user_fields, true);
    }

    private function get_teknisi_options($router_id = null)
    {
        if (!$this->db->table_exists('users') || empty($this->user_fields)) {
            return array();
        }
        if (!$this->has_user_field('id') || !$this->has_user_field('role')) {
            return array();
        }

        $name_col = $this->has_user_field('name')
            ? 'name'
            : ($this->has_user_field('username') ? 'username' : '');
        if ($name_col === '') {
            return array();
        }

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS name', false)
            ->from('users')
            ->where('role', 'teknisi');

        if ($this->has_user_field('status')) {
            $qb->where('status', 'active');
        }

        $target_router_id = is_numeric($router_id) ? (int) $router_id : 0;
        if ($target_router_id <= 0) {
            $effective_router_id = $this->getEffectiveRouterId();
            if ($effective_router_id !== null) {
                $target_router_id = (int) $effective_router_id;
            }
        }
        if ($target_router_id > 0 && $this->has_user_field('router_scope_id')) {
            $qb->where('router_scope_id', $target_router_id);
        }

        return $qb->order_by($name_col, 'ASC')->get()->result_array();
    }

    private function get_router_options()
    {
        if (!$this->db->table_exists('routers')) {
            return array();
        }

        $fields = $this->db->list_fields('routers');
        if (!in_array('id', $fields, true)) {
            return array();
        }

        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : 'id');

        $ip_col = in_array('ip_address', $fields, true)
            ? 'ip_address'
            : (in_array('api_host', $fields, true) ? 'api_host' : '');

        $qb = $this->db
            ->select('id, ' . $name_col . ' AS name' . ($ip_col !== '' ? ', ' . $ip_col . ' AS ip_address' : ''), false)
            ->from('routers');

        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        if (in_array('tenant_id', $fields, true) && method_exists($this, 'get_current_tenant_id')) {
            $tenant_id = (int) $this->get_current_tenant_id();
            if ($tenant_id > 0) {
                $qb->where('tenant_id', $tenant_id);
            }
        }

        $scope_router_id = $this->getEffectiveRouterId();
        if ($scope_router_id !== null) {
            $qb->where('id', (int) $scope_router_id);
        }

        return $qb->order_by($name_col, 'ASC')->get()->result_array();
    }

    private function get_odp_options($router_id = null)
    {
        if (!$this->db->table_exists('fiber_odp')) {
            return array();
        }

        $fields = $this->db->list_fields('fiber_odp');
        if (!in_array('id', $fields, true) || !in_array('name', $fields, true)) {
            return array();
        }

        $target_router_id = is_numeric($router_id) ? (int) $router_id : 0;
        if ($target_router_id <= 0) {
            $effective_router_id = $this->getEffectiveRouterId();
            if ($effective_router_id !== null) {
                $target_router_id = (int) $effective_router_id;
            }
        }

        $qb = $this->db
            ->select('fo.id, fo.name', false)
            ->from('fiber_odp fo');

        if (in_array('router_id', $fields, true)) {
            $qb->select('fo.router_id', false);
            if ($target_router_id > 0) {
                $qb->where('fo.router_id', $target_router_id);
            }
        } else {
            $qb->select('0 AS router_id', false);
        }

        if (in_array('is_active', $fields, true)) {
            $qb->where('fo.is_active', 1);
        }

        $router_name_expr = "''";
        if ($this->db->table_exists('routers') && in_array('router_id', $fields, true)) {
            $router_fields = $this->db->list_fields('routers');
            if (in_array('id', $router_fields, true)) {
                if (in_array('name', $router_fields, true) && in_array('router_name', $router_fields, true)) {
                    $router_name_expr = "COALESCE(NULLIF(r.name, ''), NULLIF(r.router_name, ''), CONCAT('Router #', fo.router_id))";
                } elseif (in_array('name', $router_fields, true)) {
                    $router_name_expr = "COALESCE(NULLIF(r.name, ''), CONCAT('Router #', fo.router_id))";
                } elseif (in_array('router_name', $router_fields, true)) {
                    $router_name_expr = "COALESCE(NULLIF(r.router_name, ''), CONCAT('Router #', fo.router_id))";
                } else {
                    $router_name_expr = "CONCAT('Router #', fo.router_id)";
                }

                $qb->join('routers r', 'r.id = fo.router_id', 'left');
            }
        }

        $qb->select($router_name_expr . ' AS router_name', false);
        $qb->order_by('fo.name', 'ASC');
        $rows = $qb->get()->result_array();

        $result = array();
        foreach ($rows as $row) {
            $odp_id = (int) ($row['id'] ?? 0);
            if ($odp_id <= 0) {
                continue;
            }

            $result[] = array(
                'id' => $odp_id,
                'name' => trim((string) ($row['name'] ?? ('ODP #' . $odp_id))),
                'router_id' => (int) ($row['router_id'] ?? 0),
                'router_name' => trim((string) ($row['router_name'] ?? '')),
            );
        }

        return $result;
    }

    private function get_router_by_id($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return null;
        }

        $fields = $this->db->list_fields('routers');
        if (!in_array('id', $fields, true)) {
            return null;
        }

        $qb = $this->db->from('routers')->where('id', $router_id);

        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        if (in_array('tenant_id', $fields, true) && method_exists($this, 'get_current_tenant_id')) {
            $tenant_id = (int) $this->get_current_tenant_id();
            if ($tenant_id > 0) {
                $qb->where('tenant_id', $tenant_id);
            }
        }

        $scope_router_id = $this->getEffectiveRouterId();
        if ($scope_router_id !== null) {
            $qb->where('id', (int) $scope_router_id);
        }

        $row = $qb->limit(1)->get()->row_array();
        return !empty($row) ? $row : null;
    }

    private function resolve_router_id_from_request()
    {
        $posted = (int) $this->input->post('router_id', true);
        if ($posted > 0 && $this->get_router_by_id($posted) !== null) {
            return $posted;
        }

        $routers = $this->get_router_options();
        if (count($routers) === 1) {
            return (int) ($routers[0]['id'] ?? 0);
        }

        return 0;
    }

    private function resolve_selected_router_id($customer = null)
    {
        $posted = (int) set_value('router_id', 0);
        if ($posted > 0) {
            return $posted;
        }

        if (is_object($customer)) {
            if (isset($customer->router_id) && (int) $customer->router_id > 0) {
                return (int) $customer->router_id;
            }

            $customer_id = isset($customer->id) ? (int) $customer->id : 0;
            if ($customer_id > 0 && $this->db->table_exists('customer_services')) {
                $service_fields = $this->db->list_fields('customer_services');
                if (in_array('router_id', $service_fields, true)) {
                    $row = $this->db
                        ->select('router_id')
                        ->from('customer_services')
                        ->where('customer_id', $customer_id)
                        ->order_by('id', 'DESC')
                        ->limit(1)
                        ->get()
                        ->row_array();

                    if (!empty($row['router_id'])) {
                        return (int) $row['router_id'];
                    }
                }
            }
        }

        $routers = $this->get_router_options();
        if (count($routers) === 1) {
            return (int) ($routers[0]['id'] ?? 0);
        }

        return 0;
    }

    private function teknisi_exists($user_id, $router_id = null)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !$this->db->table_exists('users') || !$this->has_user_field('id')) {
            return false;
        }

        $qb = $this->db
            ->select('id')
            ->from('users')
            ->where('id', $user_id)
            ->where('role', 'teknisi')
            ->limit(1);
        if ($this->has_user_field('status')) {
            $qb->where('status', 'active');
        }
        $target_router_id = is_numeric($router_id) ? (int) $router_id : 0;
        if ($target_router_id <= 0) {
            $effective_router_id = $this->getEffectiveRouterId();
            if ($effective_router_id !== null) {
                $target_router_id = (int) $effective_router_id;
            }
        }
        if ($target_router_id > 0 && $this->has_user_field('router_scope_id')) {
            $qb->where('router_scope_id', $target_router_id);
        }

        return !empty($qb->get()->row_array());
    }

    private function parse_bulk_customer_ids()
    {
        $ids = $this->input->post('ids');
        if (!is_array($ids)) {
            $ids = $this->input->post('customer_ids');
        }

        if (!is_array($ids)) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && trim($raw) !== '') {
                $json = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                    if (isset($json['ids']) && is_array($json['ids'])) {
                        $ids = $json['ids'];
                    } elseif (isset($json['customer_ids']) && is_array($json['customer_ids'])) {
                        $ids = $json['customer_ids'];
                    }
                }
            }
        }

        if (!is_array($ids)) {
            return array();
        }

        $clean = array();
        foreach ($ids as $id) {
            if (!is_scalar($id)) {
                continue;
            }
            $value = (int) $id;
            if ($value > 0) {
                $clean[$value] = $value;
            }
        }

        return array_values($clean);
    }

    private function build_soft_delete_payload()
    {
        $payload = array();
        $now = date('Y-m-d H:i:s');

        if ($this->has_field('deleted_at')) {
            $payload['deleted_at'] = $now;
        }
        if ($this->has_field('is_deleted')) {
            $payload['is_deleted'] = 1;
        }
        if ($this->has_field('deleted')) {
            $payload['deleted'] = 1;
        }
        if ($this->has_field('status')) {
            $status = $this->resolve_customer_status_value(array('terminated', 'inactive', 'disabled'));
            if ($status !== null) {
                $payload['status'] = $status;
            }
        }
        if ($this->has_field('updated_at')) {
            $payload['updated_at'] = $now;
        }

        return $payload;
    }

    private function mark_customer_services_terminated(array $customer_ids)
    {
        $customer_ids = array_values(array_filter(array_map('intval', $customer_ids), static function ($id) {
            return $id > 0;
        }));
        if (empty($customer_ids)) {
            return array('success' => true, 'message' => 'No customer ids.');
        }
        if (!$this->db->table_exists('customer_services')) {
            return array('success' => true, 'message' => 'Tabel customer_services tidak ada.');
        }

        $service_fields = $this->db->list_fields('customer_services');
        if (!in_array('status', $service_fields, true)) {
            return array('success' => true, 'message' => 'Kolom status tidak ada pada customer_services.');
        }

        $payload = array('status' => 'terminated');
        if (in_array('updated_at', $service_fields, true)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        $this->db->where_in('customer_id', $customer_ids)->update('customer_services', $payload);
        $error = $this->db->error();
        if ((int) ($error['code'] ?? 0) !== 0) {
            return array('success' => false, 'message' => (string) ($error['message'] ?? 'unknown'));
        }

        return array('success' => true, 'message' => 'OK');
    }

    private function hard_delete_customer($customer_id)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return array('success' => false, 'message' => 'ID customer tidak valid.');
        }

        $select = array('id');
        if ($this->has_field('username')) {
            $select[] = 'username';
        }
        if ($this->has_field('pppoe_username')) {
            $select[] = 'pppoe_username';
        }

        $customer = $this->db
            ->select(implode(', ', $select), false)
            ->from('customers')
            ->where('id', $customer_id)
            ->limit(1)
            ->get()
            ->row_array();
        if (!$customer) {
            return array('success' => false, 'message' => 'Customer tidak ditemukan.');
        }

        $this->db->trans_begin();

        $dependencies = array(
            array('table' => 'payments', 'column' => 'customer_id'),
            array('table' => 'cashflow_transactions', 'column' => 'customer_id'),
            array('table' => 'tickets', 'column' => 'customer_id'),
            array('table' => 'work_orders', 'column' => 'customer_id'),
            array('table' => 'invoices', 'column' => 'customer_id'),
            array('table' => 'customer_services', 'column' => 'customer_id'),
        );

        foreach ($dependencies as $dep) {
            if (!$this->table_has_column($dep['table'], $dep['column'])) {
                continue;
            }

            $this->db->where($dep['column'], $customer_id)->delete($dep['table']);
            $error = $this->db->error();
            if ((int) ($error['code'] ?? 0) !== 0) {
                $this->db->trans_rollback();
                return array(
                    'success' => false,
                    'message' => 'Gagal hapus relasi `' . $dep['table'] . '` : ' . (string) ($error['message'] ?? 'unknown'),
                );
            }
        }

        if ($this->db->table_exists('pppoe_secrets') && $this->table_has_column('pppoe_secrets', 'username')) {
            $candidate_usernames = array();
            foreach (array('pppoe_username', 'username') as $column) {
                $value = trim((string) ($customer[$column] ?? ''));
                if ($value !== '') {
                    $candidate_usernames[$value] = $value;
                }
            }

            foreach (array_values($candidate_usernames) as $username) {
                $this->db->where('username', $username)->delete('pppoe_secrets');
                $pppoe_error = $this->db->error();
                if ((int) ($pppoe_error['code'] ?? 0) !== 0) {
                    $this->db->trans_rollback();
                    return array(
                        'success' => false,
                        'message' => 'Gagal hapus pppoe_secrets: ' . (string) ($pppoe_error['message'] ?? 'unknown'),
                    );
                }
            }
        }

        $this->db->where('id', $customer_id)->delete('customers');
        $customer_error = $this->db->error();
        if ((int) ($customer_error['code'] ?? 0) !== 0) {
            $this->db->trans_rollback();
            return array(
                'success' => false,
                'message' => 'Gagal hard delete customer: ' . (string) ($customer_error['message'] ?? 'unknown'),
            );
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            return array('success' => false, 'message' => 'Transaction hard delete gagal.');
        }

        $this->db->trans_commit();
        return array('success' => true, 'message' => 'OK');
    }

    private function table_has_column($table, $column)
    {
        $table = trim((string) $table);
        $column = trim((string) $column);
        if ($table === '' || $column === '') {
            return false;
        }
        if (!$this->db->table_exists($table)) {
            return false;
        }

        $fields = $this->db->list_fields($table);
        if (in_array($column, $fields, true)) {
            return true;
        }

        // Fallback metadata query agar tidak false-negative saat list_fields stale.
        $row = $this->db->query(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            array($this->db->database, $this->db->dbprefix($table), $column)
        )->row_array();

        return (int) ($row['c'] ?? 0) > 0;
    }

    private function resolve_fallback_active_router_id()
    {
        if (!$this->db->table_exists('routers')) {
            return 0;
        }

        $fields = $this->db->list_fields('routers');
        $qb = $this->db->select('id')->from('routers');
        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        $row = $qb->order_by('id', 'ASC')->limit(1)->get()->row_array();
        return (int) ($row['id'] ?? 0);
    }

    private function router_id_exists_any_scope($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return false;
        }

        $fields = $this->db->list_fields('routers');
        $qb = $this->db->select('id')->from('routers')->where('id', $router_id);
        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where('status', 'active');
        }

        $row = $qb->limit(1)->get()->row_array();
        return !empty($row);
    }

    private function resolve_customer_status_value(array $candidates)
    {
        if (!$this->has_field('status')) {
            return null;
        }

        $allowed = $this->get_customer_status_values();
        if (!empty($allowed)) {
            foreach ($candidates as $candidate) {
                $candidate = strtolower(trim((string) $candidate));
                if ($candidate !== '' && in_array($candidate, $allowed, true)) {
                    return $candidate;
                }
            }
            return null;
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function get_customer_status_values()
    {
        if ($this->customer_status_values !== null) {
            return $this->customer_status_values;
        }

        $this->customer_status_values = array();
        if (!$this->has_field('status')) {
            return $this->customer_status_values;
        }

        $table = (string) $this->db->dbprefix('customers');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return $this->customer_status_values;
        }

        $row = $this->db
            ->query("SHOW COLUMNS FROM `" . $this->db->escape_str($table) . "` LIKE " . $this->db->escape('status'))
            ->row_array();
        if (empty($row['Type']) || !preg_match('/^enum\((.*)\)$/i', (string) $row['Type'], $matches)) {
            return $this->customer_status_values;
        }

        $values = str_getcsv($matches[1], ',', "'");
        $normalized = array();
        foreach ($values as $value) {
            $value = strtolower(trim((string) $value));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        $this->customer_status_values = $normalized;
        return $this->customer_status_values;
    }

    private function get_bulk_pppoe_username_map(array $ids)
    {
        $map = array();
        if (empty($ids)) {
            return $map;
        }

        $select = array('id');
        $customer_username_column = null;
        if ($this->has_field('pppoe_username')) {
            $customer_username_column = 'pppoe_username';
        } elseif ($this->has_field('username')) {
            $customer_username_column = 'username';
        }
        if ($customer_username_column !== null) {
            $select[] = $customer_username_column;
        }

        $rows = $this->db
            ->select(implode(', ', $select), false)
            ->from('customers')
            ->where_in('id', $ids)
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            $customer_id = (int) ($row['id'] ?? 0);
            if ($customer_id <= 0) {
                continue;
            }

            $username = '';
            if ($customer_username_column !== null) {
                $username = trim((string) ($row[$customer_username_column] ?? ''));
            }
            if ($username !== '') {
                $map[$customer_id] = $username;
            }
        }

        if (count($map) === count($ids)) {
            return $map;
        }

        if (!$this->db->table_exists('customer_services')) {
            return $map;
        }

        $service_fields = $this->db->list_fields('customer_services');
        if (!in_array('pppoe_username', $service_fields, true)) {
            return $map;
        }

        $table = $this->db->dbprefix('customer_services');
        $sub = "(
            SELECT cs1.customer_id, cs1.pppoe_username
            FROM {$table} cs1
            INNER JOIN (
                SELECT customer_id, MAX(id) AS max_id
                FROM {$table}
                GROUP BY customer_id
            ) cs2 ON cs2.max_id = cs1.id
        ) s";

        $service_rows = $this->db
            ->select('s.customer_id, s.pppoe_username')
            ->from($sub, false)
            ->where_in('s.customer_id', $ids)
            ->get()
            ->result_array();

        foreach ($service_rows as $row) {
            $customer_id = (int) ($row['customer_id'] ?? 0);
            if ($customer_id <= 0 || isset($map[$customer_id])) {
                continue;
            }

            $username = trim((string) ($row['pppoe_username'] ?? ''));
            if ($username !== '') {
                $map[$customer_id] = $username;
            }
        }

        return $map;
    }

    private function get_bulk_customer_router_map(array $ids)
    {
        $map = array();
        if (empty($ids)) {
            return $map;
        }

        if ($this->db->table_exists('customer_services')) {
            $service_fields = $this->db->list_fields('customer_services');
            if (in_array('router_id', $service_fields, true)) {
                $table = $this->db->dbprefix('customer_services');
                $sub = "(
                    SELECT cs1.customer_id, cs1.router_id
                    FROM {$table} cs1
                    INNER JOIN (
                        SELECT customer_id, MAX(id) AS max_id
                        FROM {$table}
                        GROUP BY customer_id
                    ) cs2 ON cs2.max_id = cs1.id
                ) s";

                $rows = $this->db
                    ->select('s.customer_id, s.router_id')
                    ->from($sub, false)
                    ->where_in('s.customer_id', $ids)
                    ->get()
                    ->result_array();

                foreach ($rows as $row) {
                    $customer_id = (int) ($row['customer_id'] ?? 0);
                    $router_id = (int) ($row['router_id'] ?? 0);
                    if ($customer_id > 0 && $router_id > 0) {
                        $map[$customer_id] = $router_id;
                    }
                }
            }
        }

        if (count($map) === count($ids)) {
            return $map;
        }

        if ($this->db->table_exists('customers') && $this->has_field('router_id')) {
            $rows = $this->db
                ->select('id, router_id')
                ->from('customers')
                ->where_in('id', $ids)
                ->get()
                ->result_array();

            foreach ($rows as $row) {
                $customer_id = (int) ($row['id'] ?? 0);
                $router_id = (int) ($row['router_id'] ?? 0);
                if ($customer_id > 0 && $router_id > 0 && !isset($map[$customer_id])) {
                    $map[$customer_id] = $router_id;
                }
            }
        }

        if (count($map) === count($ids)) {
            return $map;
        }

        $routers = $this->get_router_options();
        if (count($routers) === 1) {
            $default_router_id = (int) ($routers[0]['id'] ?? 0);
            if ($default_router_id > 0) {
                foreach ($ids as $customer_id) {
                    $customer_id = (int) $customer_id;
                    if ($customer_id > 0 && !isset($map[$customer_id])) {
                        $map[$customer_id] = $default_router_id;
                    }
                }
            }
        }

        return $map;
    }

    private function resolve_router_id_by_pppoe_username($username)
    {
        $username = trim((string) $username);
        if ($username === '') {
            return 0;
        }

        if ($this->db->table_exists('customer_services')) {
            $service_fields = $this->db->list_fields('customer_services');
            if (in_array('router_id', $service_fields, true) && in_array('pppoe_username', $service_fields, true)) {
                $row = $this->db
                    ->select('router_id')
                    ->from('customer_services')
                    ->where('pppoe_username', $username)
                    ->order_by('id', 'DESC')
                    ->limit(1)
                    ->get()
                    ->row_array();
                $router_id = (int) ($row['router_id'] ?? 0);
                if ($router_id > 0) {
                    return $router_id;
                }
            }
        }

        if ($this->db->table_exists('customers') && $this->has_field('router_id')) {
            $qb = $this->db
                ->select('router_id')
                ->from('customers');
            if ($this->has_field('pppoe_username') && $this->has_field('username')) {
                $qb->group_start()
                    ->where('pppoe_username', $username)
                    ->or_where('username', $username)
                    ->group_end();
            } elseif ($this->has_field('pppoe_username')) {
                $qb->where('pppoe_username', $username);
            } elseif ($this->has_field('username')) {
                $qb->where('username', $username);
            }
            $row = $qb->order_by('id', 'DESC')->limit(1)->get()->row_array();
            $router_id = (int) ($row['router_id'] ?? 0);
            if ($router_id > 0) {
                return $router_id;
            }
        }

        $routers = $this->get_router_options();
        if (count($routers) === 1) {
            return (int) ($routers[0]['id'] ?? 0);
        }

        return 0;
    }

    private function resolve_router_id_by_customer_id($customer_id)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return 0;
        }

        if ($this->db->table_exists('customer_services')) {
            $service_fields = $this->db->list_fields('customer_services');
            if (in_array('router_id', $service_fields, true)) {
                $row = $this->db
                    ->select('router_id')
                    ->from('customer_services')
                    ->where('customer_id', $customer_id)
                    ->where('router_id >', 0)
                    ->order_by('id', 'DESC')
                    ->limit(1)
                    ->get()
                    ->row_array();
                $router_id = (int) ($row['router_id'] ?? 0);
                if ($router_id > 0) {
                    return $router_id;
                }
            }
        }

        if ($this->db->table_exists('customers') && $this->has_field('router_id')) {
            $row = $this->db
                ->select('router_id')
                ->from('customers')
                ->where('id', $customer_id)
                ->limit(1)
                ->get()
                ->row_array();
            $router_id = (int) ($row['router_id'] ?? 0);
            if ($router_id > 0) {
                return $router_id;
            }
        }

        $effective_router_id = $this->getEffectiveRouterId();
        return $effective_router_id !== null ? (int) $effective_router_id : 0;
    }

    private function disable_ppp_secret_by_username($username, $router_id = 0)
    {
        $username = trim((string) $username);
        if ($username === '') {
            return array('success' => false, 'message' => 'Username PPP kosong.');
        }

        $router_id = (int) $router_id;
        if ($router_id <= 0) {
            $router_id = $this->resolve_router_id_by_pppoe_username($username);
        }
        if ($router_id <= 0) {
            return array(
                'success' => false,
                'message' => 'Router customer untuk `' . $username . '` tidak ditemukan.',
            );
        }

        $disable = $this->mikrotikmanager->disablePppSecret($router_id, $username);
        if (empty($disable['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($disable['message'] ?? 'Gagal disable secret di MikroTik.'),
            );
        }

        return array(
            'success' => true,
            'message' => 'Akses PPP `' . $username . '` berhasil dinonaktifkan.',
        );
    }

    private function extract_secret_id(array $secret)
    {
        foreach (array('.id', '=.id', 'id') as $key) {
            if (!isset($secret[$key])) {
                continue;
            }

            $value = trim((string) $secret[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function pick_secret_by_name($rows, $username)
    {
        $username = trim((string) $username);
        if ($username === '' || !is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '' && strcmp($name, $username) === 0) {
                return $row;
            }
        }

        return null;
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

    private function build_customer_mikrotik_status_message(array $result)
    {
        $delivery = strtolower(trim((string) ($result['delivery'] ?? '')));

        if (!empty($result['success']) && $delivery === 'queued') {
            $message = 'Pembuatan akses PPP masuk antrian';

            return $message . '.';
        }

        if (!empty($result['success'])) {
            return 'PPP Secret MikroTik sudah dibuat.';
        }

        return 'PPP Secret MikroTik belum dibuat: ' . (string) ($result['message'] ?? 'unknown');
    }

    private function build_customer_telegram_status_message(array $result)
    {
        $delivery = strtolower(trim((string) ($result['delivery'] ?? '')));

        if (!empty($result['skipped']) || $delivery === 'skipped') {
            return 'Notifikasi teknisi belum dikirim: ' . (string) ($result['message'] ?? 'konfigurasi Telegram belum lengkap');
        }

        if (!empty($result['success']) && $delivery === 'queued') {
            $message = 'Notifikasi teknisi masuk antrian';

            return $message . '.';
        }

        if (!empty($result['success'])) {
            return 'Notifikasi Telegram teknisi berhasil dikirim.';
        }

        return 'Telegram belum terkirim: ' . (string) ($result['message'] ?? 'unknown');
    }

    private function provision_ppp_secret_to_mikrotik($username, $password, $profile_name, $remote_ip = '', $customer_name = '', $router_id = 0)
    {
        $username = trim((string) $username);
        $password = trim((string) $password);
        $profile_name = trim((string) $profile_name);
        $remote_ip = $this->normalize_ipv4($remote_ip);
        $customer_name = trim((string) $customer_name);

        if ($username === '' || $password === '') {
            return array(
                'success' => false,
                'message' => 'Username/password PPP belum valid untuk provisioning MikroTik.',
            );
        }
        if ($profile_name === '') {
            return array(
                'success' => false,
                'message' => 'Nama PPP Profile kosong, tidak bisa provisioning ke MikroTik.',
            );
        }

        if ($this->is_async_queue_enabled()) {
            $dispatch = $this->jobdispatcher->dispatch(
                null,
                'mikrotik_create_secret',
                array(
                    'router_id' => (int) $router_id,
                    'username' => $username,
                    'password' => $password,
                    'profile' => $profile_name,
                    'remote_ip' => $remote_ip,
                    'service' => 'pppoe',
                    'comment' => $customer_name !== '' ? 'AUTO-' . $customer_name : 'AUTO-' . date('YmdHis'),
                ),
                0
            );

            if (empty($dispatch['success'])) {
                return array(
                    'success' => false,
                    'message' => 'Gagal menambahkan pembuatan akses PPP ke antrian: ' . (string) ($dispatch['message'] ?? 'unknown'),
                );
            }

            return array(
                'success' => true,
                'message' => 'Pembuatan akses PPP masuk antrian.',
                'job_id' => (int) ($dispatch['job_id'] ?? 0),
                'delivery' => 'queued',
            );
        }

        $service = 'pppoe';
        $this->config->load('mikrotik', true);
        $mk_cfg = (array) $this->config->item('mikrotik');
        if (!empty($mk_cfg['pppoe_service'])) {
            $service = trim((string) $mk_cfg['pppoe_service']);
        }
        if ($service === '') {
            $service = 'pppoe';
        }

        $comment = 'AUTO-' . date('YmdHis');
        if ($customer_name !== '') {
            $comment = 'AUTO-' . $customer_name;
        }

        try {
            $router_id = (int) $router_id;
            if ($router_id <= 0) {
                return array(
                    'success' => false,
                    'message' => 'router_id customer belum tersedia untuk provisioning PPP.',
                );
            }

            log_message('debug', '[CUSTOMERS][PPP_PROVISION] start username=' . $username . ' profile=' . $profile_name . ' remote_ip=' . $remote_ip . ' router_id=' . $router_id);
            $upsert = $this->mikrotikmanager->upsertPppSecret(
                $router_id,
                $username,
                $password,
                $profile_name,
                $remote_ip,
                $comment
            );
            if (empty($upsert['success'])) {
                return array(
                    'success' => false,
                    'message' => 'Gagal provisioning PPP secret `' . $username . '` di MikroTik: ' . (string) ($upsert['message'] ?? 'unknown'),
                );
            }

            return array(
                'success' => true,
                'message' => 'PPP secret `' . $username . '` berhasil diproses di MikroTik (' . (string) (($upsert['data']['action'] ?? 'upsert')) . ').',
                'delivery' => 'sent',
            );
        } catch (Throwable $e) {
            log_message('error', '[CUSTOMERS][PPP_PROVISION] exception: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        }
    }

    private function get_customer_service_context($customer_id, $customer = null)
    {
        $customer_id = (int) $customer_id;
        $result = array(
            'customer_service_id' => 0,
            'ppp_profile_id' => 0,
            'price' => 0,
        );

        if ($customer_id <= 0) {
            return $result;
        }

        if ($this->db->table_exists('customer_services')) {
            $service_fields = $this->db->list_fields('customer_services');
            $select = array('id as customer_service_id');
            if (in_array('ppp_profile_id', $service_fields, true)) {
                $select[] = 'ppp_profile_id';
            }
            if (in_array('price', $service_fields, true)) {
                $select[] = 'price';
            }

            $service = $this->db
                ->select(implode(', ', $select), false)
                ->from('customer_services')
                ->where('customer_id', $customer_id)
                ->order_by('id', 'DESC')
                ->limit(1)
                ->get()
                ->row_array();

            if (!empty($service)) {
                $result['customer_service_id'] = (int) ($service['customer_service_id'] ?? 0);
                $result['ppp_profile_id'] = (int) ($service['ppp_profile_id'] ?? 0);
                $result['price'] = (float) ($service['price'] ?? 0);
            }
        }

        if ($result['ppp_profile_id'] <= 0 && is_object($customer)) {
            if (isset($customer->profile_id)) {
                $result['ppp_profile_id'] = (int) $customer->profile_id;
            } elseif (isset($customer->ppp_profile_id)) {
                $result['ppp_profile_id'] = (int) $customer->ppp_profile_id;
            }
        }

        if ($result['price'] <= 0 && $result['ppp_profile_id'] > 0 && $this->db->table_exists('ppp_profiles')) {
            $profile = $this->db
                ->select('price')
                ->from('ppp_profiles')
                ->where('id', (int) $result['ppp_profile_id'])
                ->limit(1)
                ->get()
                ->row_array();

            if (!empty($profile)) {
                $result['price'] = (float) ($profile['price'] ?? 0);
            }
        }

        return $result;
    }

    private function get_latest_customer_service_record($customer_id)
    {
        $customer_id = (int) $customer_id;
        $result = array(
            'id' => 0,
            'ppp_profile_id' => 0,
            'pppoe_username' => '',
            'ip_address' => '',
            'router_id' => 0,
        );

        if ($customer_id <= 0 || !$this->db->table_exists('customer_services')) {
            return $result;
        }

        $service_fields = $this->db->list_fields('customer_services');
        if (!in_array('id', $service_fields, true) || !in_array('customer_id', $service_fields, true)) {
            return $result;
        }

        $select = array('id');
        foreach (array('ppp_profile_id', 'pppoe_username', 'ip_address', 'router_id') as $field) {
            if (in_array($field, $service_fields, true)) {
                $select[] = $field;
            }
        }

        $row = $this->db
            ->select(implode(', ', $select), false)
            ->from('customer_services')
            ->where('customer_id', $customer_id)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($row)) {
            return $result;
        }

        $result['id'] = (int) ($row['id'] ?? 0);
        $result['ppp_profile_id'] = (int) ($row['ppp_profile_id'] ?? 0);
        $result['pppoe_username'] = trim((string) ($row['pppoe_username'] ?? ''));
        $result['ip_address'] = trim((string) ($row['ip_address'] ?? ''));
        $result['router_id'] = (int) ($row['router_id'] ?? 0);

        return $result;
    }

    private function create_manual_invoice($customer_id, $customer_service_id, $price, $note = '')
    {
        $fields = $this->db->list_fields('invoices');
        $period_ym = date('Y-m');
        $period_start = $period_ym . '-01';
        $period_end = date('Y-m-t', strtotime($period_start));
        $issue_date = date('Y-m-d');
        $install_date = $this->resolve_customer_install_date((int) $customer_id);
        $due_date = $this->calculate_due_date_from_install($install_date, $period_start);
        $valid_service_id = $this->resolve_valid_customer_service_id((int) $customer_id, (int) $customer_service_id);

        $subtotal = (float) $price;
        $tax_amount = 0;
        $discount_amount = 0;
        $total_amount = round($subtotal + $tax_amount - $discount_amount, 2);

        $payload = array(
            'invoice_number' => $this->billing_automation_model->next_invoice_number($period_ym),
            'customer_id' => (int) $customer_id,
            'customer_service_id' => $valid_service_id,
            'billing_period_start' => $period_start,
            'billing_period_end' => $period_end,
            'issue_date' => $issue_date,
            'due_date' => $due_date,
            'subtotal' => $subtotal,
            'tax_amount' => $tax_amount,
            'discount_amount' => $discount_amount,
            'total_amount' => $total_amount,
            'paid_amount' => 0,
            'balance_amount' => $total_amount,
            'status' => 'issued',
            'notes' => $note !== '' ? ($note . ' (due=install+5)') : 'Bulk generate invoice (due=install+5)',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );

        $payload = $this->filter_payload_by_fields($payload, $fields);
        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('invoices', $payload);
        $db_error = $this->db->error();
        $this->db->db_debug = $old_db_debug;
        if (!$ok) {
            return array('success' => false, 'message' => 'DB error: ' . json_encode($db_error));
        }

        return array('success' => true, 'invoice_id' => (int) $this->db->insert_id());
    }

    private function resolve_valid_customer_service_id($customer_id, $customer_service_id)
    {
        $customer_id = (int) $customer_id;
        $customer_service_id = (int) $customer_service_id;
        if ($customer_id <= 0 || $customer_service_id <= 0) {
            return null;
        }

        if (!$this->db->table_exists('customer_services')) {
            return null;
        }
        if (!$this->table_has_column('customer_services', 'id') || !$this->table_has_column('customer_services', 'customer_id')) {
            return null;
        }

        $exists = $this->db
            ->from('customer_services')
            ->where('id', $customer_service_id)
            ->where('customer_id', $customer_id)
            ->count_all_results() > 0;

        return $exists ? $customer_service_id : null;
    }

    private function resolve_installation_date_from_post()
    {
        foreach (array('install_date', 'installation_date') as $field) {
            $value = trim((string) $this->input->post($field, true));
            if ($this->is_valid_ymd_date($value)) {
                return $value;
            }
        }

        return '';
    }

    private function build_auto_ppp_credential_from_post($ignore_customer_id = 0, $install_date = '')
    {
        $full_name = trim((string) $this->input->post('full_name', true));
        $lokasi = trim((string) $this->input->post('lokasi', true));
        $olt = trim((string) $this->input->post('olt', true));

        if ($full_name === '' || $lokasi === '' || $olt === '') {
            return array(
                'success' => false,
                'message' => 'Isi Nama Pelanggan, Lokasi, dan OLT terlebih dahulu.',
            );
        }

        if ($install_date === '') {
            $install_date = $this->resolve_installation_date_from_post();
        }
        if (!$this->is_valid_ymd_date($install_date)) {
            return array(
                'success' => false,
                'message' => 'Tanggal instalasi wajib diisi dengan format YYYY-MM-DD.',
            );
        }

        $name_segment = $this->normalize_ppp_segment($full_name, true);
        $lokasi_segment = $this->normalize_ppp_segment($lokasi, false);
        $olt_segment = $this->normalize_ppp_segment($olt, false);

        if ($name_segment === '' || $lokasi_segment === '' || $olt_segment === '') {
            return array(
                'success' => false,
                'message' => 'Format nama/lokasi/OLT tidak valid untuk username PPP.',
            );
        }

        $base_username = strtoupper($name_segment . '-' . $lokasi_segment . '-' . $olt_segment);
        $username = $this->build_unique_pppoe_username($base_username, (int) $ignore_customer_id);
        if ($username === '') {
            return array(
                'success' => false,
                'message' => 'Gagal membuat username PPP yang unik.',
            );
        }

        return array(
            'success' => true,
            'username' => $username,
            'password' => $this->format_install_password($install_date),
            'install_date' => $install_date,
        );
    }

    private function normalize_ppp_segment($value, $first_token_only = false)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if ($first_token_only) {
            $parts = preg_split('/\s+/', $value);
            $value = isset($parts[0]) ? (string) $parts[0] : $value;
        }

        $value = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));
        if ($value === '') {
            return '';
        }

        return substr($value, 0, 30);
    }

    private function build_unique_pppoe_username($base_username, $ignore_id = 0)
    {
        $base_username = trim((string) $base_username);
        if ($base_username === '') {
            return '';
        }

        $base_username = substr($base_username, 0, 90);
        if (!$this->customer_model->exists_username_any($base_username, (int) $ignore_id)) {
            return $base_username;
        }

        for ($i = 2; $i <= 999; $i++) {
            $candidate = $base_username . '-' . $i;
            if (strlen($candidate) > 100) {
                $candidate = substr($candidate, 0, 100);
            }

            if (!$this->customer_model->exists_username_any($candidate, (int) $ignore_id)) {
                return $candidate;
            }
        }

        return '';
    }

    private function generate_customer_code()
    {
        if (!$this->db->table_exists('customers') || !$this->has_field('customer_code')) {
            return '';
        }

        $prefix = 'CUST-' . date('Ymd') . '-';
        $max_id_row = $this->db
            ->select_max('id', 'max_id')
            ->from('customers')
            ->get()
            ->row_array();

        $next = (int) ($max_id_row['max_id'] ?? 0) + 1;
        for ($i = 0; $i < 100; $i++) {
            $code = $prefix . str_pad((string) ($next + $i), 5, '0', STR_PAD_LEFT);
            $exists = $this->db
                ->from('customers')
                ->where('customer_code', $code)
                ->count_all_results() > 0;
            if (!$exists) {
                return $code;
            }
        }

        return $prefix . strtoupper(substr(md5(uniqid('', true)), 0, 6));
    }

    private function format_install_password($install_date)
    {
        if (!$this->is_valid_ymd_date($install_date)) {
            return '';
        }

        return date('dmY', strtotime($install_date));
    }

    private function resolve_vlan_id_by_profile(array $profile)
    {
        $speed = 0;
        foreach (array('name', 'rate_limit') as $key) {
            $value = strtoupper(trim((string) ($profile[$key] ?? '')));
            if ($value === '') {
                continue;
            }
            if (preg_match('/\b(10|20|30|50)\s*M\b/i', $value, $m)) {
                $speed = (int) $m[1];
                break;
            }
            if (preg_match('/\b(10|20|30|50)\b/', $value, $m)) {
                $speed = (int) $m[1];
                break;
            }
        }

        switch ($speed) {
            case 10:
                return '1111';
            case 20:
                return '200';
            case 30:
                return '300';
            case 50:
                return '500';
            default:
                return '';
        }
    }

    private function create_installation_work_order(array $context)
    {
        if (!$this->db->table_exists('work_orders')) {
            return array('success' => false, 'message' => 'Tabel work_orders tidak ditemukan.');
        }

        $fields = $this->db->list_fields('work_orders');
        if (empty($fields)) {
            return array('success' => false, 'message' => 'Struktur tabel work_orders tidak terbaca.');
        }

        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $install_date = $this->is_valid_ymd_date((string) ($context['install_date'] ?? ''))
            ? (string) $context['install_date']
            : $today;
        $customer_name = trim((string) ($context['customer_name'] ?? ''));
        $profile_name = trim((string) ($context['profile_name'] ?? ''));
        $vlan_id = trim((string) ($context['vlan_id'] ?? ''));
        $status_open = $this->resolve_table_enum_value('work_orders', 'status', array('open', 'OPEN', 'pending', 'new'), 'open');
        $priority_value = $this->resolve_table_enum_value('work_orders', 'priority', array('high', 'urgent', 'medium'), 'high');
        $type_value = $this->resolve_table_enum_value('work_orders', 'type', array('installation', 'INSTALLATION'), 'installation');
        $wo_type_value = $this->resolve_table_enum_value('work_orders', 'wo_type', array('installation', 'INSTALLATION'), 'installation');
        $wo_number = 'WO-' . date('YmdHis') . '-' . str_pad((string) random_int(1, 99), 2, '0', STR_PAD_LEFT);
        $user_id = (int) $this->session->userdata('user_id');
        $router_id = (int) ($context['router_id'] ?? 0);
        if ($router_id <= 0 && !empty($context['customer_id'])) {
            $router_id = $this->resolve_router_id_by_customer_id((int) $context['customer_id']);
        }
        if ($router_id <= 0) {
            $effective_router_id = $this->getEffectiveRouterId();
            if ($effective_router_id !== null) {
                $router_id = (int) $effective_router_id;
            }
        }
        if ($router_id <= 0) {
            $router_id = $this->resolve_fallback_active_router_id();
        }

        $description_lines = array(
            'Pemasangan baru pelanggan',
            'Nama: ' . ($customer_name !== '' ? $customer_name : '-'),
            'Alamat: ' . trim((string) ($context['address'] ?? '-')),
            'PPPoE: ' . trim((string) ($context['pppoe_username'] ?? '-')),
            'Profile: ' . ($profile_name !== '' ? $profile_name : '-'),
            'VLAN: ' . ($vlan_id !== '' ? $vlan_id : '-'),
        );

        $payload = array();
        if (in_array('wo_number', $fields, true)) {
            $payload['wo_number'] = $wo_number;
        }
        if (in_array('customer_id', $fields, true)) {
            $payload['customer_id'] = (int) ($context['customer_id'] ?? 0);
        }
        if (in_array('router_id', $fields, true)) {
            if ($router_id <= 0) {
                return array('success' => false, 'message' => 'router_id WO tidak ditemukan.');
            }
            if (!$this->router_id_exists_any_scope($router_id)) {
                return array('success' => false, 'message' => 'router_id WO tidak valid.');
            }
            // Selalu set router_id agar insert WO tidak jatuh ke default 0 lalu kena FK.
            $payload['router_id'] = $router_id;
        }
        if (in_array('created_by', $fields, true)) {
            $payload['created_by'] = $user_id > 0 ? $user_id : 1;
        }
        if (in_array('assigned_to', $fields, true) && !empty($context['technician_id'])) {
            $payload['assigned_to'] = (int) $context['technician_id'];
        }
        if (in_array('status', $fields, true)) {
            $payload['status'] = $status_open;
        }
        if (in_array('priority', $fields, true)) {
            $payload['priority'] = $priority_value;
        }
        if (in_array('type', $fields, true)) {
            $payload['type'] = $type_value;
        }
        if (in_array('wo_type', $fields, true)) {
            $payload['wo_type'] = $wo_type_value;
        }
        if (in_array('open_at', $fields, true)) {
            $payload['open_at'] = $now;
        }
        if (in_array('requested_date', $fields, true)) {
            $payload['requested_date'] = $today;
        }
        if (in_array('scheduled_date', $fields, true)) {
            $payload['scheduled_date'] = $install_date;
        }
        if (in_array('scheduled_start_at', $fields, true)) {
            $payload['scheduled_start_at'] = $install_date . ' 09:00:00';
        }
        if (in_array('scheduled_time', $fields, true)) {
            $payload['scheduled_time'] = '09:00:00';
        }
        if (in_array('title', $fields, true)) {
            $payload['title'] = 'Pemasangan baru - ' . ($customer_name !== '' ? $customer_name : 'Customer');
        }
        if (in_array('description', $fields, true)) {
            $payload['description'] = implode("\n", $description_lines);
        }
        if (in_array('pppoe_username', $fields, true)) {
            $payload['pppoe_username'] = trim((string) ($context['pppoe_username'] ?? ''));
        }
        if (in_array('package_name', $fields, true)) {
            $payload['package_name'] = $profile_name;
        }
        if (in_array('bandwidth_mbps', $fields, true) && preg_match('/(\d{1,3})/i', $profile_name, $m)) {
            $payload['bandwidth_mbps'] = (int) $m[1];
        }
        if (in_array('vlan_info', $fields, true)) {
            $payload['vlan_info'] = $vlan_id;
        }
        if (in_array('created_at', $fields, true)) {
            $payload['created_at'] = $now;
        }
        if (in_array('updated_at', $fields, true)) {
            $payload['updated_at'] = $now;
        }

        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('work_orders', $payload);
        $db_error = $this->db->error();
        $this->db->db_debug = $old_db_debug;

        if (!$ok) {
            log_message('error', '[CUSTOMERS][WO_CREATE] DB error: ' . json_encode($db_error) . ' payload=' . json_encode($payload) . ' context=' . json_encode($context));
            return array(
                'success' => false,
                'message' => (string) ($db_error['message'] ?? 'gagal insert work_orders'),
            );
        }

        return array(
            'success' => true,
            'wo_id' => (int) $this->db->insert_id(),
            'wo_number' => $wo_number,
        );
    }

    private function sync_customer_technician_work_order($customer_id, $technician_id, array $context = array())
    {
        $customer_id = (int) $customer_id;
        $technician_id = (int) $technician_id;
        if ($customer_id <= 0 || $technician_id <= 0) {
            return array(
                'success' => false,
                'updated' => false,
                'created' => false,
                'message' => 'customer_id/technician_id tidak valid.',
            );
        }
        if (!$this->db->table_exists('work_orders')) {
            return array(
                'success' => false,
                'updated' => false,
                'created' => false,
                'message' => 'Tabel work_orders tidak ditemukan.',
            );
        }

        $fields = $this->db->list_fields('work_orders');
        if (empty($fields) || !in_array('customer_id', $fields, true)) {
            return array(
                'success' => false,
                'updated' => false,
                'created' => false,
                'message' => 'Kolom customer_id tidak tersedia di work_orders.',
            );
        }

        $qb = $this->db
            ->from('work_orders')
            ->where('customer_id', $customer_id);

        if (in_array('wo_type', $fields, true)) {
            $qb->where("LOWER(wo_type) IN ('installation','instalasi')", null, false);
        } elseif (in_array('type', $fields, true)) {
            $qb->where("LOWER(type) IN ('installation','instalasi')", null, false);
        }

        if (in_array('status', $fields, true)) {
            $excluded_statuses = array('done', 'activated', 'completed', 'closed', 'cancel', 'cancelled');
            $escaped = array();
            foreach ($excluded_statuses as $status) {
                $escaped[] = $this->db->escape($status);
            }
            $qb->where('LOWER(status) NOT IN (' . implode(', ', $escaped) . ')', null, false);
        }

        if (in_array('deleted_at', $fields, true)) {
            $qb->where('deleted_at IS NULL', null, false);
        }
        if (in_array('is_deleted', $fields, true)) {
            $qb->where('is_deleted', 0);
        }

        $existing = $qb
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();

        if (!empty($existing['id'])) {
            $update = array();
            if (in_array('assigned_to', $fields, true)) {
                $update['assigned_to'] = $technician_id;
            } elseif (in_array('technician_id', $fields, true)) {
                $update['technician_id'] = $technician_id;
            }

            $current_month_start = strtotime(date('Y-m-01 00:00:00'));
            if (in_array('scheduled_start_at', $fields, true)) {
                $scheduled_start_at = trim((string) ($existing['scheduled_start_at'] ?? ''));
                if ($scheduled_start_at === '' || strtotime($scheduled_start_at) === false || strtotime($scheduled_start_at) < $current_month_start) {
                    $update['scheduled_start_at'] = date('Y-m-d H:i:s');
                }
            }
            if (in_array('requested_date', $fields, true)) {
                $requested_date = trim((string) ($existing['requested_date'] ?? ''));
                if ($requested_date === '' || strtotime($requested_date) === false || strtotime($requested_date) < $current_month_start) {
                    $update['requested_date'] = date('Y-m-d');
                }
            }

            if (in_array('updated_at', $fields, true)) {
                $update['updated_at'] = date('Y-m-d H:i:s');
            }

            if (empty($update)) {
                return array(
                    'success' => false,
                    'updated' => false,
                    'created' => false,
                    'message' => 'Kolom assignment teknisi tidak ditemukan di work_orders.',
                );
            }

            $ok = $this->db->where('id', (int) $existing['id'])->update('work_orders', $update);
            if (!$ok) {
                $db_error = $this->db->error();
                log_message('error', '[CUSTOMERS][WO_SYNC_ASSIGN] update failed: ' . json_encode($db_error));
                return array(
                    'success' => false,
                    'updated' => false,
                    'created' => false,
                    'message' => (string) ($db_error['message'] ?? 'gagal update assignment WO'),
                );
            }

            return array(
                'success' => true,
                'updated' => true,
                'created' => false,
                'wo_number' => (string) ($existing['wo_number'] ?? ''),
                'message' => 'WO existing berhasil di-assign ulang.',
            );
        }

        $create_ctx = $context;
        $create_ctx['customer_id'] = $customer_id;
        $create_ctx['technician_id'] = $technician_id;
        $create_ctx['install_date'] = date('Y-m-d');
        if (empty($create_ctx['router_id'])) {
            $create_ctx['router_id'] = $this->resolve_router_id_by_customer_id($customer_id);
        }

        $created = $this->create_installation_work_order($create_ctx);
        if (!empty($created['success'])) {
            return array(
                'success' => true,
                'updated' => false,
                'created' => true,
                'wo_number' => (string) ($created['wo_number'] ?? ''),
                'message' => 'WO baru berhasil dibuat.',
            );
        }

        return array(
            'success' => false,
            'updated' => false,
            'created' => false,
            'message' => (string) ($created['message'] ?? 'gagal membuat WO assignment'),
        );
    }

    private function send_new_installation_telegram(array $context)
    {
        $customer_id = (int) ($context['customer_id'] ?? 0);
        $router_id = 0;
        $router_candidates = array((int) ($context['router_id'] ?? 0));
        if ($customer_id > 0) {
            $router_candidates[] = (int) $this->resolve_router_id_by_customer_id($customer_id);
        }
        foreach ($router_candidates as $router_candidate) {
            if ($router_candidate > 0 && $this->router_id_exists_any_scope($router_candidate)) {
                $router_id = $router_candidate;
                break;
            }
        }
        $wo_number = trim((string) ($context['wo_number'] ?? '-'));
        $customer_name = trim((string) ($context['customer_name'] ?? '-'));
        $address = trim((string) ($context['address'] ?? '-'));
        $pppoe_username = trim((string) ($context['pppoe_username'] ?? '-'));
        $pppoe_password = trim((string) ($context['pppoe_password'] ?? '-'));
        $profile_name = trim((string) ($context['profile_name'] ?? '-'));
        $vlan_text = trim((string) ($context['vlan_id'] ?? ''));
        if ($vlan_text === '') {
            $vlan_text = '-';
        }

        $message = "<b>WORK ORDER PEMASANGAN BARU</b>\n"
            . "────────────────────\n"
            . "<b>No WO</b>\n"
            . "<code>" . html_escape($wo_number !== '' ? $wo_number : '-') . "</code>\n\n"
            . "<b>DATA PELANGGAN</b>\n"
            . "Nama   : <b>" . html_escape($customer_name !== '' ? $customer_name : '-') . "</b>\n"
            . "Alamat : " . html_escape($address !== '' ? $address : '-') . "\n\n"
            . "<b>DATA PPP</b>\n"
            . "User PPP : <code>" . html_escape($pppoe_username !== '' ? $pppoe_username : '-') . "</code>\n"
            . "Pass PPP : <code>" . html_escape($pppoe_password !== '' ? $pppoe_password : '-') . "</code>\n"
            . "Profile  : <b>" . html_escape($profile_name !== '' ? $profile_name : '-') . "</b>\n"
            . "VLAN ID  : <b>" . html_escape($vlan_text) . "</b>";

        $ip_address = trim((string) ($context['ip_address'] ?? ''));
        if ($ip_address !== '') {
            $message .= "\nIP Remote: <code>" . html_escape($ip_address) . "</code>";
        }

        $copy_keyboard = array();
        if ($customer_id > 0) {
            $copy_keyboard = array(
                array(
                    array(
                        'text' => 'Copy User PPP',
                        'copy_text' => array(
                            'text' => $pppoe_username !== '' ? $pppoe_username : '-',
                        ),
                    ),
                    array(
                        'text' => 'Copy Pass PPP',
                        'copy_text' => array(
                            'text' => $pppoe_password !== '' ? $pppoe_password : '-',
                        ),
                    ),
                ),
                array(
                    array(
                        'text' => 'Copy VLAN',
                        'copy_text' => array(
                            'text' => $vlan_text !== '' ? $vlan_text : '-',
                        ),
                    ),
                ),
            );
        }

        if ($router_id <= 0) {
            return array(
                'success' => false,
                'message' => 'Router pelanggan belum valid. Notifikasi teknisi belum dikirim.',
                'delivery' => 'skipped',
                'skipped' => true,
            );
        }

        $this->load->helper('tenant');
        $target = telegram_get_groups_by_type('teknisi', $router_id, false);
        if (empty($target['success'])) {
            return array(
                'success' => false,
                'message' => 'Grup Telegram teknisi untuk router ini belum tersedia.',
                'delivery' => 'skipped',
                'skipped' => true,
                'router_id' => $router_id,
            );
        }

        if ($this->is_async_queue_enabled()) {
            $dispatch = $this->jobdispatcher->dispatch(
                null,
                'telegram_send',
                array(
                    'group_type' => 'teknisi',
                    'router_id' => $router_id > 0 ? $router_id : null,
                    'allow_router_fallback' => false,
                    'allow_legacy_fallback' => false,
                    'message' => $message,
                    'parse_mode' => 'HTML',
                    'inline_keyboard' => $copy_keyboard,
                ),
                0
            );

            if (!empty($dispatch['success'])) {
                return array(
                    'success' => true,
                    'message' => 'Notifikasi teknisi masuk antrian.',
                    'job_id' => (int) ($dispatch['job_id'] ?? 0),
                    'delivery' => 'queued',
                );
            }

            return array(
                'success' => false,
                'message' => 'Gagal menambahkan notifikasi ke antrian: ' . (string) ($dispatch['message'] ?? 'unknown'),
            );
        }

        $options = array('parse_mode' => 'HTML');
        if (!empty($copy_keyboard)) {
            $options['reply_markup'] = array('inline_keyboard' => $copy_keyboard);
        }
        $result = telegram_dispatch_to_groups((array) ($target['groups'] ?? array()), $message, $options);

        if (empty($result['success'])) {
            log_message('error', '[CUSTOMERS][TELEGRAM_WO] send failed: ' . json_encode($result));
        }

        $result['delivery'] = !empty($result['success']) ? 'sent' : 'failed';

        return $result;
    }

    private function resolve_positive_router_id($primary_router_id = 0, $fallback_router_id = 0, $customer_id = 0)
    {
        $candidates = array(
            (int) $primary_router_id,
            (int) $fallback_router_id,
        );

        $customer_id = (int) $customer_id;
        if ($customer_id > 0) {
            $candidates[] = (int) $this->resolve_router_id_by_customer_id($customer_id);
        }

        $effective_router_id = $this->getEffectiveRouterId();
        if ($effective_router_id !== null) {
            $candidates[] = (int) $effective_router_id;
        }

        $candidates[] = (int) $this->resolve_fallback_active_router_id();

        foreach ($candidates as $candidate) {
            if ($candidate > 0 && $this->router_id_exists_any_scope($candidate)) {
                return $candidate;
            }
        }

        return 0;
    }

    private function build_telegram_callback_signature($customer_id, $action = '', $secret = null)
    {
        $customer_id = (int) $customer_id;
        $action = strtoupper(trim((string) $action));
        $secret = is_string($secret) ? trim($secret) : $this->get_provisioning_callback_secret();
        if ($secret === '') {
            return '';
        }

        $payload = (string) $customer_id;
        if ($action !== '') {
            $payload .= '|' . $action;
        }

        return substr(hash_hmac('sha256', $payload, $secret), 0, 16);
    }

    private function get_provisioning_callback_secret()
    {
        $secret = trim((string) getenv('PROVISIONING_CALLBACK_SECRET'));
        if ($secret !== '') {
            return $secret;
        }

        $secret = trim((string) config_item('provisioning_callback_secret'));
        if ($secret !== '') {
            return $secret;
        }

        return '';
    }

    private function resolve_table_enum_value($table, $column, array $candidates, $fallback = '')
    {
        if (!$this->table_has_column($table, $column)) {
            return $fallback;
        }

        $table_name = (string) $this->db->dbprefix($table);
        $column = trim((string) $column);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table_name) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return $fallback;
        }

        $row = $this->db
            ->query("SHOW COLUMNS FROM `" . $this->db->escape_str($table_name) . "` LIKE " . $this->db->escape($column))
            ->row_array();
        if (empty($row['Type']) || !preg_match('/^enum\((.*)\)$/i', (string) $row['Type'], $m)) {
            return $fallback;
        }

        $allowed_raw = str_getcsv($m[1], ',', "'");
        $allowed_map = array();
        foreach ($allowed_raw as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $allowed_map[strtolower($item)] = $item;
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            $key = strtolower($candidate);
            if (isset($allowed_map[$key])) {
                return $allowed_map[$key];
            }
        }

        return $fallback !== '' ? $fallback : (string) reset($allowed_map);
    }

    private function resolve_customer_install_date($customer_id)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0 || !$this->db->table_exists('customers')) {
            return '';
        }

        $customer_fields = $this->db->list_fields('customers');
        $select = array('id');
        foreach (array('install_date', 'join_date', 'pppoe_password', 'ppp_password', 'pppoe_username', 'username') as $column) {
            if (in_array($column, $customer_fields, true)) {
                $select[] = $column;
            }
        }

        $customer = $this->db
            ->select(implode(', ', $select), false)
            ->from('customers')
            ->where('id', $customer_id)
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($customer)) {
            return '';
        }

        foreach (array('install_date', 'join_date') as $column) {
            $candidate = trim((string) ($customer[$column] ?? ''));
            if ($this->is_valid_ymd_date($candidate)) {
                return $candidate;
            }
        }

        foreach (array('pppoe_password', 'ppp_password') as $column) {
            $parsed = $this->parse_install_date_from_password((string) ($customer[$column] ?? ''));
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $username_candidates = array();
        foreach (array('pppoe_username', 'username') as $column) {
            $value = trim((string) ($customer[$column] ?? ''));
            if ($value !== '') {
                $username_candidates[$value] = $value;
            }
        }

        if (!$this->db->table_exists('pppoe_secrets') || empty($username_candidates)) {
            return '';
        }
        if (!$this->table_has_column('pppoe_secrets', 'username')) {
            return '';
        }

        $secret_fields = $this->db->list_fields('pppoe_secrets');
        $select_secret = array('username');
        if (in_array('ppp_password', $secret_fields, true)) {
            $select_secret[] = 'ppp_password';
        }
        if (in_array('password', $secret_fields, true)) {
            $select_secret[] = 'password';
        }

        $secret = $this->db
            ->select(implode(', ', $select_secret), false)
            ->from('pppoe_secrets')
            ->where_in('username', array_values($username_candidates))
            ->limit(1)
            ->get()
            ->row_array();

        if (!empty($secret)) {
            foreach (array('ppp_password', 'password') as $column) {
                $parsed = $this->parse_install_date_from_password((string) ($secret[$column] ?? ''));
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        return '';
    }

    private function calculate_due_date_from_install($install_date, $period_start)
    {
        $period_start = trim((string) $period_start);
        if (!$this->is_valid_ymd_date($period_start)) {
            $period_start = date('Y-m-01');
        }

        $period_ts = strtotime($period_start);
        $year = (int) date('Y', $period_ts);
        $month = (int) date('m', $period_ts);
        $last_day = (int) date('t', $period_ts);

        $install_day = 5;
        if ($this->is_valid_ymd_date($install_date)) {
            $install_day = (int) date('d', strtotime($install_date));
        }

        $due_day = $install_day + 5;
        if ($due_day > $last_day) {
            $due_day = $last_day;
        }
        if ($due_day < 1) {
            $due_day = 1;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $due_day);
    }

    private function parse_install_date_from_password($password)
    {
        $password = trim((string) $password);
        if ($password === '') {
            return null;
        }

        if ($this->is_valid_ymd_date($password)) {
            return $password;
        }

        if (preg_match('/^\d{8}$/', $password)) {
            $dd = (int) substr($password, 0, 2);
            $mm = (int) substr($password, 2, 2);
            $yyyy = (int) substr($password, 4, 4);
            if (checkdate($mm, $dd, $yyyy)) {
                return sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd);
            }

            $yyyy = (int) substr($password, 0, 4);
            $mm = (int) substr($password, 4, 2);
            $dd = (int) substr($password, 6, 2);
            if (checkdate($mm, $dd, $yyyy)) {
                return sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd);
            }
        }

        return null;
    }

    private function is_valid_ymd_date($date)
    {
        $date = trim((string) $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $parts = explode('-', $date);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    private function bulk_response($status, $message, array $extra = array())
    {
        return array_merge(array(
            'status' => (string) $status,
            'message' => (string) $message,
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_token' => $this->security->get_csrf_hash(),
        ), $extra);
    }

    private function generate_random_password($length = 8)
    {
        $length = max(8, (int) $length);
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max_index = strlen($chars) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max_index)];
        }
        return $password;
    }

    private function json_response($http_code, array $payload)
    {
        return $this->output
            ->set_status_header((int) $http_code)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
    }
}
