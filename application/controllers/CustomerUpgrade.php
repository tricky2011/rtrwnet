<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class CustomerUpgrade extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_module_access('customers', 'Akses ditolak. Modul Customer hanya untuk superadmin/admin.');
        $this->require_role(array('superadmin', 'admin'), 'Akses ditolak. Hanya superadmin/admin yang bisa upgrade paket.');

        $this->load->database();
        $this->load->model('customer_model');
        $this->load->model('CustomerUpgrade_model', 'customerupgrade_model');
        $this->load->model('billing_automation_model');
        $this->load->library('MikrotikManager');
        $this->load->helper(array('url', 'form'));
    }

    public function index()
    {
        $scope_router_id = $this->getEffectiveRouterId();
        $customers = $this->customer_model->get_all();
        $plans = $this->customerupgrade_model->get_service_plan_options($scope_router_id);

        $customer_options = array();
        foreach ((array) $customers as $row) {
            if (!is_object($row) || !isset($row->id)) {
                continue;
            }

            $customer_id = (int) $row->id;
            if ($customer_id <= 0) {
                continue;
            }

            $display_name = trim((string) ($row->full_name ?? ($row->nama ?? '')));
            if ($display_name === '') {
                $display_name = 'Customer #' . $customer_id;
            }

            $pppoe_username = trim((string) ($row->pppoe_username ?? ($row->username ?? '')));
            $context = $this->customerupgrade_model->get_upgrade_context($customer_id, $scope_router_id);
            if (empty($context)) {
                continue;
            }

            $customer_options[] = array(
                'id' => $customer_id,
                'name' => $display_name,
                'pppoe_username' => $pppoe_username,
                'old_plan_id' => (int) ($context['old_plan_id'] ?? 0),
                'old_plan_name' => (string) ($context['old_plan_name'] ?? '-'),
                'old_price' => (float) ($context['old_price'] ?? 0),
            );
        }

        usort($customer_options, function ($a, $b) {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $this->load->view('customers/upgrade_page', array(
            'customer_options' => $customer_options,
            'plans' => $plans,
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
            'calculate_url' => site_url('customers/upgrade/calculate-prorate'),
            'context_url_base' => rtrim(site_url('customers/upgrade/show-form'), '/'),
            'process_url' => site_url('customers/upgrade/process'),
            'return_url' => 'customers/upgrade',
        ));
    }

    public function show_form($customer_id = 0)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'Customer tidak valid.',
                'csrf_token' => $this->security->get_csrf_hash(),
            ));
        }

        $scope_router_id = $this->getEffectiveRouterId();
        $customer = $this->customer_model->get_by_id($customer_id);
        if (!$customer) {
            return $this->json_response(404, array(
                'success' => false,
                'message' => 'Customer tidak ditemukan.',
                'csrf_token' => $this->security->get_csrf_hash(),
            ));
        }

        $context = $this->customerupgrade_model->get_upgrade_context($customer_id, $scope_router_id);
        if (empty($context)) {
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'Data service customer belum tersedia.',
                'csrf_token' => $this->security->get_csrf_hash(),
            ));
        }

        $target_router_id = (int) ($context['router_id'] ?? 0);
        if ($target_router_id <= 0) {
            $target_router_id = (int) $scope_router_id;
        }

        $plans = $this->customerupgrade_model->get_service_plan_options($target_router_id);
        $customer_payload = is_array($context['customer'] ?? null)
            ? $context['customer']
            : (is_object($customer) ? (array) $customer : array());

        $html = $this->load->view('customers/upgrade_modal', array(
            'customer' => $customer_payload,
            'upgrade_context' => $context,
            'plans' => $plans,
            'process_url' => site_url('customers/upgrade/process'),
            'calculate_url' => site_url('customers/upgrade/calculate-prorate'),
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
        ), true);

        return $this->json_response(200, array(
            'success' => true,
            'message' => 'Form upgrade siap.',
            'html' => $html,
            'data' => array(
                'customer_id' => $customer_id,
                'old_plan_id' => (int) ($context['old_plan_id'] ?? 0),
                'old_plan_name' => (string) ($context['old_plan_name'] ?? '-'),
                'old_price' => (float) ($context['old_price'] ?? 0),
                'pppoe_username' => (string) ($context['pppoe_username'] ?? ''),
                'router_id' => $target_router_id,
                'router_name' => $this->get_router_label($target_router_id),
            ),
            'csrf_token' => $this->security->get_csrf_hash(),
        ));
    }

    public function calculate_prorate()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(405, array(
                'success' => false,
                'message' => 'Method Not Allowed',
            ));
        }

        $customer_id = (int) $this->input->post('customer_id', true);
        $new_plan_id = (int) $this->input->post('new_plan_id', true);
        $upgrade_date = trim((string) $this->input->post('upgrade_date', true));
        $apply_prorate = (int) $this->input->post('apply_prorate', true) === 1;

        if ($customer_id <= 0 || $new_plan_id <= 0) {
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'Customer atau paket baru tidak valid.',
                'csrf_token' => $this->security->get_csrf_hash(),
            ));
        }

        $upgrade_date = $this->normalize_date($upgrade_date);
        if ($upgrade_date === '') {
            $upgrade_date = date('Y-m-d');
        }

        $scope_router_id = $this->getEffectiveRouterId();
        $context = $this->customerupgrade_model->get_upgrade_context($customer_id, $scope_router_id);
        if (empty($context)) {
            return $this->json_response(404, array(
                'success' => false,
                'message' => 'Data customer/service tidak ditemukan.',
                'csrf_token' => $this->security->get_csrf_hash(),
            ));
        }

        $target_router_id = (int) ($context['router_id'] ?? 0);
        if ($target_router_id <= 0) {
            $target_router_id = (int) $scope_router_id;
        }

        $new_plan = $this->customerupgrade_model->get_service_plan($new_plan_id, $target_router_id);
        if (empty($new_plan)) {
            return $this->json_response(404, array(
                'success' => false,
                'message' => 'Paket baru tidak ditemukan untuk router customer.',
                'csrf_token' => $this->security->get_csrf_hash(),
            ));
        }

        $old_price = (float) ($context['old_price'] ?? 0);
        $new_price = (float) ($new_plan['price'] ?? 0);
        $price_diff = round($new_price - $old_price, 2);
        $upgrade_type = $price_diff < 0 ? 'downgrade' : 'upgrade';

        $prorate_amount = 0.00;
        if ($apply_prorate) {
            $day_of_month = (int) date('j', strtotime($upgrade_date));
            $remaining_days = max(0, 30 - $day_of_month);
            $prorate_amount = round(($price_diff / 30) * $remaining_days, 2);
        }

        $pppoe_username = trim((string) ($context['pppoe_username'] ?? ''));
        $network_preview = $this->resolve_upgrade_network_assignment($target_router_id, $pppoe_username, (array) $new_plan);
        if (empty($network_preview['success'])) {
            return $this->json_response(422, array(
                'success' => false,
                'message' => (string) ($network_preview['message'] ?? 'Gagal siapkan migrasi IP pool.'),
                'csrf_token' => $this->security->get_csrf_hash(),
            ));
        }

        return $this->json_response(200, array(
            'success' => true,
            'message' => 'Perhitungan prorate berhasil.',
            'data' => array(
                'old_plan_id' => (int) ($context['old_plan_id'] ?? 0),
                'old_plan_name' => (string) ($context['old_plan_name'] ?? '-'),
                'new_plan_id' => (int) ($new_plan['id'] ?? 0),
                'new_plan_name' => (string) ($new_plan['name'] ?? '-'),
                'old_price' => $old_price,
                'new_price' => $new_price,
                'price_diff' => $price_diff,
                'upgrade_type' => $upgrade_type,
                'prorate_amount' => $prorate_amount,
                'upgrade_date' => $upgrade_date,
                'target_pool_name' => (string) ($network_preview['pool_name'] ?? ''),
                'target_remote_ip' => (string) ($network_preview['target_remote_ip'] ?? ''),
                'network_message' => (string) ($network_preview['message'] ?? ''),
            ),
            'csrf_token' => $this->security->get_csrf_hash(),
        ));
    }

    public function process_upgrade()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $customer_id = (int) $this->input->post('customer_id', true);
        $new_plan_id = (int) $this->input->post('new_plan_id', true);
        $upgrade_date = $this->normalize_date((string) $this->input->post('upgrade_date', true));
        $apply_prorate = (int) $this->input->post('apply_prorate', true) === 1;
        $redirect_target = $this->resolve_redirect_target(
            $customer_id,
            (string) $this->input->post('return_url', true)
        );

        if ($customer_id <= 0 || $new_plan_id <= 0) {
            $this->session->set_flashdata('error', 'Data upgrade paket tidak valid.');
            redirect($redirect_target);
            return;
        }

        if ($upgrade_date === '') {
            $upgrade_date = date('Y-m-d');
        }

        $scope_router_id = $this->getEffectiveRouterId();
        $context = $this->customerupgrade_model->get_upgrade_context($customer_id, $scope_router_id);
        if (empty($context)) {
            $this->session->set_flashdata('error', 'Data customer/service tidak ditemukan.');
            redirect($redirect_target);
            return;
        }

        $target_router_id = (int) ($context['router_id'] ?? 0);
        if ($target_router_id <= 0) {
            $target_router_id = (int) $scope_router_id;
        }

        $new_plan = $this->customerupgrade_model->get_service_plan($new_plan_id, $target_router_id);
        if (empty($new_plan)) {
            $this->session->set_flashdata('error', 'Paket baru tidak ditemukan untuk router customer.');
            redirect($redirect_target);
            return;
        }

        $old_plan_id = (int) ($context['old_plan_id'] ?? 0);
        if ($old_plan_id > 0 && $old_plan_id === $new_plan_id) {
            $this->session->set_flashdata('error', 'Paket baru harus berbeda dengan paket saat ini.');
            redirect($redirect_target);
            return;
        }

        $old_plan_name = (string) ($context['old_plan_name'] ?? '-');
        $new_plan_name = (string) ($new_plan['name'] ?? '-');
        if ($new_plan_name === '') {
            $this->session->set_flashdata('error', 'Nama paket baru tidak valid.');
            redirect($redirect_target);
            return;
        }
        $old_price = (float) ($context['old_price'] ?? 0);
        $new_price = (float) ($new_plan['price'] ?? 0);
        $price_diff = round($new_price - $old_price, 2);
        $upgrade_type = $price_diff < 0 ? 'downgrade' : 'upgrade';

        $prorate_amount = 0.00;
        if ($apply_prorate) {
            $day_of_month = (int) date('j', strtotime($upgrade_date));
            $remaining_days = max(0, 30 - $day_of_month);
            $prorate_amount = round(($price_diff / 30) * $remaining_days, 2);
        }

        $pppoe_username = trim((string) ($context['pppoe_username'] ?? ''));
        if ($pppoe_username === '') {
            $this->session->set_flashdata('error', 'PPPoE username customer tidak ditemukan.');
            redirect($redirect_target);
            return;
        }

        $router_id = (int) ($context['router_id'] ?? 0);
        if ($router_id <= 0) {
            $router_id = (int) $this->billing_automation_model->get_customer_router_id($customer_id, $pppoe_username);
        }
        if ($router_id <= 0) {
            $this->session->set_flashdata('error', 'Router customer tidak ditemukan.');
            redirect($redirect_target);
            return;
        }

        $secret_resolution = $this->resolve_secret_router_and_username($pppoe_username, $router_id, $context);
        $secret_auto_created = false;
        $customer_name = trim((string) (($context['customer']['full_name'] ?? ($context['customer']['nama'] ?? 'Customer #' . $customer_id))));
        $router_label = $this->get_router_label($router_id);
        if (empty($secret_resolution['success'])) {
            if ((int) ($context['customer_service_id'] ?? 0) <= 0) {
                $create_secret = $this->create_missing_secret_for_upgrade($router_id, $pppoe_username, $new_plan_name, $context);
                if (!empty($create_secret['success'])) {
                    $secret_resolution = array(
                        'success' => true,
                        'router_id' => $router_id,
                        'username' => $pppoe_username,
                        'auto_created' => true,
                    );
                    $secret_auto_created = true;
                }
            }
            if (empty($secret_resolution['success'])) {
                $this->session->set_flashdata(
                    'error',
                    'Gagal update profile MikroTik untuk customer ' . $customer_name .
                    ' (username: ' . $pppoe_username . ', router: ' . $router_label . '): ' .
                    (string) ($secret_resolution['message'] ?? 'PPP secret tidak ditemukan.')
                );
                redirect($redirect_target);
                return;
            }
        }

        $router_id = (int) ($secret_resolution['router_id'] ?? $router_id);
        $pppoe_username = (string) ($secret_resolution['username'] ?? $pppoe_username);
        $secret_auto_created = $secret_auto_created || !empty($secret_resolution['auto_created']);

        $validated_plan = $this->customerupgrade_model->get_service_plan($new_plan_id, $router_id);
        if (empty($validated_plan)) {
            $this->session->set_flashdata('error', 'Paket baru tidak tersedia pada router tempat PPP secret customer berada.');
            redirect($redirect_target);
            return;
        }
        $new_plan = $validated_plan;
        $new_plan_name = (string) ($new_plan['name'] ?? $new_plan_name);
        $new_price = (float) ($new_plan['price'] ?? $new_price);
        $price_diff = round($new_price - $old_price, 2);
        $upgrade_type = $price_diff < 0 ? 'downgrade' : 'upgrade';
        if ($apply_prorate) {
            $day_of_month = (int) date('j', strtotime($upgrade_date));
            $remaining_days = max(0, 30 - $day_of_month);
            $prorate_amount = round(($price_diff / 30) * $remaining_days, 2);
        } else {
            $prorate_amount = 0.00;
        }

        $customer_service_id = (int) ($context['customer_service_id'] ?? 0);
        $network_assignment = $this->resolve_upgrade_network_assignment($router_id, $pppoe_username, $new_plan);
        if (empty($network_assignment['success'])) {
            $this->session->set_flashdata('error', 'Gagal siapkan migrasi IP pool: ' . (string) ($network_assignment['message'] ?? 'unknown'));
            redirect($redirect_target);
            return;
        }
        $target_remote_ip = (string) ($network_assignment['target_remote_ip'] ?? '');
        $target_pool_name = (string) ($network_assignment['pool_name'] ?? '');

        $now = date('Y-m-d H:i:s');
        $user_id = (int) $this->session->userdata('user_id');

        $this->db->trans_begin();

        if ($customer_service_id <= 0) {
            $seed_plan_id = $old_plan_id > 0 ? $old_plan_id : $new_plan_id;
            $seed_price = $old_price > 0 ? $old_price : $new_price;
            $seed_service = $this->customerupgrade_model->ensure_customer_service(
                $customer_id,
                $seed_plan_id,
                $seed_price,
                $pppoe_username,
                $router_id,
                $upgrade_date
            );
            if (empty($seed_service['success'])) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', (string) ($seed_service['message'] ?? 'Gagal membuat service customer.'));
                redirect($redirect_target);
                return;
            }
            $customer_service_id = (int) ($seed_service['customer_service_id'] ?? 0);
            if ($customer_service_id <= 0) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'Gagal inisialisasi service customer.');
                redirect($redirect_target);
                return;
            }
        }

        $service_update = array(
            'ppp_profile_id' => $new_plan_id,
            'price' => $new_price,
            'router_id' => $router_id,
            'pppoe_username' => $pppoe_username,
            'updated_at' => $now,
        );
        if ($target_remote_ip !== '') {
            $service_update['ip_address'] = $target_remote_ip;
        }
        $ok_service = $this->customerupgrade_model->update_customer_service($customer_service_id, $service_update);
        if (!$ok_service) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Gagal update service customer.');
            redirect($redirect_target);
            return;
        }

        $customer_update = array(
            'service_plan_id' => $new_plan_id,
            'price' => $new_price,
            'profile_id' => $new_plan_id,
            'ppp_profile_id' => $new_plan_id,
            'package_price' => $new_price,
            'router_id' => $router_id,
            'pppoe_username' => $pppoe_username,
            'username' => $pppoe_username,
            'updated_at' => $now,
        );
        if ($target_remote_ip !== '') {
            $customer_update['ip_address'] = $target_remote_ip;
        }
        $ok_customer = $this->customerupgrade_model->update_customer_plan($customer_id, $customer_update);
        if (!$ok_customer) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Gagal update data customer.');
            redirect($redirect_target);
            return;
        }

        $history_payload = array(
            'customer_id' => $customer_id,
            'old_plan_id' => $old_plan_id > 0 ? $old_plan_id : $new_plan_id,
            'new_plan_id' => $new_plan_id,
            'upgrade_type' => $upgrade_type,
            'old_price' => $old_price,
            'new_price' => $new_price,
            'prorate_amount' => $prorate_amount,
            'upgrade_date' => $upgrade_date,
            'created_by' => $user_id > 0 ? $user_id : null,
            'created_at' => $now,
        );
        $ok_history = $this->customerupgrade_model->save_history($history_payload);
        if (!$ok_history) {
            $db_error = (array) $this->db->error();
            $error_message = 'Gagal menyimpan history upgrade paket.';
            if ((int) ($db_error['code'] ?? 0) !== 0 && !empty($db_error['message'])) {
                $error_message .= ' DB: ' . (string) $db_error['message'];
            }
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', $error_message);
            redirect($redirect_target);
            return;
        }

        $invoice_id = 0;
        if ($prorate_amount > 0) {
            $invoice_result = $this->customerupgrade_model->create_prorate_invoice(
                $customer_id,
                $customer_service_id,
                $prorate_amount,
                $upgrade_date,
                $old_plan_name,
                $new_plan_name,
                $router_id
            );
            if (empty($invoice_result['success'])) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', (string) ($invoice_result['message'] ?? 'Gagal membuat invoice prorate.'));
                redirect($redirect_target);
                return;
            }
            $invoice_id = (int) ($invoice_result['invoice_id'] ?? 0);
        }

        $mikrotik_result = $this->set_mikrotik_profile($router_id, $pppoe_username, $new_plan_name, $target_remote_ip);
        if (empty($mikrotik_result['success'])) {
            $this->db->trans_rollback();
            $this->session->set_flashdata(
                'error',
                'Gagal update profile MikroTik untuk customer ' . $customer_name .
                ' (username: ' . $pppoe_username . ', router: ' . $this->get_router_label($router_id) . '): ' .
                (string) ($mikrotik_result['message'] ?? 'unknown')
            );
            redirect($redirect_target);
            return;
        }
        $disconnect_result = $this->mikrotikmanager->disconnectPppActiveByUsername($router_id, $pppoe_username);
        if (empty($disconnect_result['success'])) {
            $this->db->trans_rollback();
            $old_profile = (string) ($mikrotik_result['old_profile'] ?? '');
            if ($old_profile !== '') {
                $this->set_mikrotik_profile(
                    $router_id,
                    $pppoe_username,
                    $old_profile,
                    (string) ($mikrotik_result['old_remote_ip'] ?? '')
                );
            }
            $this->session->set_flashdata(
                'error',
                'Profile berhasil diubah, tetapi gagal memutus PPP active untuk apply instan: ' .
                (string) ($disconnect_result['message'] ?? 'unknown')
            );
            redirect($redirect_target);
            return;
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            $old_profile = (string) ($mikrotik_result['old_profile'] ?? '');
            if ($old_profile !== '') {
                $this->set_mikrotik_profile(
                    $router_id,
                    $pppoe_username,
                    $old_profile,
                    (string) ($mikrotik_result['old_remote_ip'] ?? '')
                );
            }
            $this->session->set_flashdata('error', 'Transaction DB gagal setelah update MikroTik.');
            redirect($redirect_target);
            return;
        }

        $this->db->trans_commit();

        $activity_message = 'Admin melakukan upgrade paket pelanggan dari ' . $old_plan_name . ' ke ' . $new_plan_name;
        $this->customerupgrade_model->insert_user_activity_log(array(
            'user_id' => $user_id > 0 ? $user_id : null,
            'user_name' => (string) $this->session->userdata('name'),
            'user_role' => (string) $this->session->userdata('role'),
            'http_method' => 'POST',
            'action' => $activity_message,
            'controller' => 'customerupgrade',
            'method' => 'process_upgrade',
            'request_uri' => substr((string) ($_SERVER['REQUEST_URI'] ?? uri_string()), 0, 255),
            'query_string' => null,
            'payload_json' => json_encode(array(
                'customer_id' => $customer_id,
                'old_plan_id' => $old_plan_id,
                'new_plan_id' => $new_plan_id,
                'prorate_amount' => $prorate_amount,
                'invoice_id' => $invoice_id,
                'target_pool_name' => $target_pool_name,
                'target_remote_ip' => $target_remote_ip,
            )),
            'ip_address' => substr((string) $this->input->ip_address(), 0, 45),
            'user_agent' => substr((string) $this->input->user_agent(), 0, 255),
            'created_at' => $now,
        ));

        $success_message = 'Upgrade paket berhasil dari ' . $old_plan_name . ' ke ' . $new_plan_name . '.';
        if ($invoice_id > 0) {
            $success_message .= ' Invoice prorate berhasil dibuat.';
        }
        if ($target_pool_name !== '') {
            $success_message .= ' IP dipindah ke pool `' . $target_pool_name . '`';
            if ($target_remote_ip !== '') {
                $success_message .= ' dengan IP `' . $target_remote_ip . '`';
            }
            $success_message .= '.';
        } elseif ($target_remote_ip !== '') {
            $success_message .= ' IP remote diset ke `' . $target_remote_ip . '`.';
        }
        $removed_sessions = (int) (($disconnect_result['data']['removed'] ?? 0));
        $success_message .= $removed_sessions > 0
            ? ' Sesi PPP aktif diputus untuk apply instan.'
            : ' Tidak ada sesi PPP aktif yang perlu diputus.';
        if ($secret_auto_created) {
            $success_message .= ' Akses PPP pelanggan diperbarui otomatis di MikroTik.';
        }
        $this->session->set_flashdata('success', $success_message);
        redirect($redirect_target);
    }

    private function resolve_redirect_target($customer_id, $return_url = '')
    {
        $customer_id = (int) $customer_id;
        $return_url = trim((string) $return_url);

        if ($return_url !== '') {
            $base = rtrim((string) site_url(), '/');
            if (strpos($return_url, $base . '/') === 0) {
                $return_url = substr($return_url, strlen($base) + 1);
            }
            $return_url = trim($return_url, '/');
            if ($return_url !== '' && preg_match('#^[a-zA-Z0-9/_-]+$#', $return_url)) {
                return $return_url;
            }
        }

        if ($customer_id > 0) {
            return 'customers/edit/' . $customer_id;
        }

        return 'customers/upgrade';
    }

    private function set_mikrotik_profile($router_id, $username, $new_profile, $target_remote_ip = '')
    {
        $router_id = (int) $router_id;
        $username = trim((string) $username);
        $new_profile = trim((string) $new_profile);
        $target_remote_ip = $this->normalize_ipv4((string) $target_remote_ip);

        if ($router_id <= 0 || $username === '' || $new_profile === '') {
            return array('success' => false, 'message' => 'router_id/username/profile tidak valid.');
        }

        $find = $this->mikrotikmanager->findPppSecretByRouterId($router_id, $username);
        if (empty($find['success'])) {
            return array('success' => false, 'message' => (string) ($find['message'] ?? 'PPP secret tidak ditemukan.'));
        }
        $secret = (array) (($find['data']['secret'] ?? array()));
        if (empty($secret)) {
            return array('success' => false, 'message' => 'PPP secret `' . $username . '` tidak ditemukan di router #' . $router_id . '.');
        }

        $connect = $this->mikrotikmanager->connectByRouterId($router_id);
        if (empty($connect['success'])) {
            return array('success' => false, 'message' => (string) ($connect['message'] ?? 'Gagal konek router.'));
        }

        try {
            $old_profile = trim((string) $this->read_mikrotik_field($secret, 'profile'));
            $old_remote_ip = $this->normalize_ipv4((string) $this->read_mikrotik_field($secret, 'remote-address'));
            $params = array('profile' => $new_profile);
            if ($target_remote_ip !== '') {
                $params['remote-address'] = $target_remote_ip;
            }

            $secret_id = trim((string) $this->read_mikrotik_field($secret, '.id'));
            if ($secret_id !== '') {
                $params['.id'] = $secret_id;
            } else {
                $params['numbers'] = $username;
            }

            $set = $this->mikrotikmanager->command('/ppp/secret/set', $params);
            if (empty($set['success']) && isset($params['.id'])) {
                $fallback_params = array(
                    'numbers' => $username,
                    'profile' => $new_profile,
                );
                if ($target_remote_ip !== '') {
                    $fallback_params['remote-address'] = $target_remote_ip;
                }
                $set = $this->mikrotikmanager->command('/ppp/secret/set', $fallback_params);
            }

            if (empty($set['success'])) {
                return array('success' => false, 'message' => 'Gagal set profile PPP secret `' . $username . '` di router #' . $router_id . ': ' . (string) ($set['message'] ?? 'unknown'));
            }

            return array(
                'success' => true,
                'old_profile' => $old_profile,
                'old_remote_ip' => $old_remote_ip,
                'new_profile' => $new_profile,
                'assigned_remote_ip' => $target_remote_ip,
            );
        } finally {
            $this->mikrotikmanager->disconnect();
        }
    }

    private function resolve_secret_router_and_username($primary_username, $preferred_router_id, array $context = array())
    {
        $preferred_router_id = (int) $preferred_router_id;
        $usernames = array();

        $push_username = function ($value) use (&$usernames) {
            $value = trim((string) $value);
            if ($value === '') {
                return;
            }
            if (!in_array($value, $usernames, true)) {
                $usernames[] = $value;
            }
        };

        $push_username($primary_username);
        $customer = (array) ($context['customer'] ?? array());
        $service = (array) ($context['service'] ?? array());
        $push_username($customer['pppoe_username'] ?? '');
        $push_username($customer['username'] ?? '');
        $push_username($service['pppoe_username'] ?? '');

        if (empty($usernames)) {
            return array('success' => false, 'message' => 'Username PPP customer kosong.');
        }

        $router_ids = $this->get_candidate_router_ids($preferred_router_id);
        if (empty($router_ids)) {
            return array('success' => false, 'message' => 'Tidak ada router kandidat untuk cek PPP secret.');
        }

        $checked = array();
        foreach ($usernames as $username) {
            foreach ($router_ids as $router_id) {
                $result = $this->mikrotikmanager->findPppSecretByRouterId((int) $router_id, $username);
                $checked[] = (int) $router_id . ':' . $username;
                if (!empty($result['success'])) {
                    return array(
                        'success' => true,
                        'router_id' => (int) $router_id,
                        'username' => $username,
                    );
                }
            }
        }

        return array(
            'success' => false,
            'message' => 'PPP secret tidak ditemukan. Dicek pada ' . implode(', ', $checked),
        );
    }

    private function get_candidate_router_ids($preferred_router_id = 0)
    {
        $preferred_router_id = (int) $preferred_router_id;
        $candidates = array();

        $push = function ($id) use (&$candidates) {
            $id = (int) $id;
            if ($id <= 0) {
                return;
            }
            if (!in_array($id, $candidates, true)) {
                $candidates[] = $id;
            }
        };

        $push($preferred_router_id);
        $push((int) $this->getEffectiveRouterId());
        $push((int) $this->session->userdata('active_router_id'));
        $push((int) $this->session->userdata('router_scope_id'));

        $role = strtolower(trim((string) $this->session->userdata('role')));
        if ($role === 'superadmin' && $this->db->table_exists('routers')) {
            $qb = $this->db->select('id')->from('routers');
            $router_fields = $this->db->list_fields('routers');
            if (in_array('is_active', $router_fields, true)) {
                $qb->where('is_active', 1);
            } elseif (in_array('status', $router_fields, true)) {
                $qb->where('status', 'active');
            }

            $rows = $qb->order_by('id', 'ASC')->get()->result_array();
            foreach ($rows as $row) {
                $push((int) ($row['id'] ?? 0));
            }
        }

        return $candidates;
    }

    private function create_missing_secret_for_upgrade($router_id, $username, $profile_name, array $context = array())
    {
        $router_id = (int) $router_id;
        $username = trim((string) $username);
        $profile_name = trim((string) $profile_name);
        if ($router_id <= 0 || $username === '' || $profile_name === '') {
            return array('success' => false, 'message' => 'Data create PPP secret tidak lengkap.');
        }

        $customer = (array) ($context['customer'] ?? array());
        $service = (array) ($context['service'] ?? array());

        $password_candidates = array(
            (string) ($customer['pppoe_password'] ?? ''),
            (string) ($customer['ppp_password'] ?? ''),
            (string) ($service['pppoe_password'] ?? ''),
        );

        $password = '';
        foreach ($password_candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                $password = $candidate;
                break;
            }
        }

        if ($password === '') {
            return array('success' => false, 'message' => 'PPP secret tidak ditemukan dan password PPP customer kosong.');
        }

        $remote_ip = trim((string) ($service['ip_address'] ?? ''));
        if ($remote_ip === '') {
            $remote_ip = trim((string) ($customer['ip_address'] ?? ''));
        }

        $create = $this->mikrotikmanager->createPppSecret(
            $router_id,
            $username,
            $password,
            $profile_name,
            $remote_ip,
            'Auto create from customer upgrade'
        );

        if (empty($create['success'])) {
            return array(
                'success' => false,
                'message' => 'PPP secret tidak ditemukan dan gagal auto-create: ' . (string) ($create['message'] ?? 'unknown'),
            );
        }

        return array('success' => true);
    }

    private function resolve_upgrade_network_assignment($router_id, $pppoe_username, array $new_plan)
    {
        $router_id = (int) $router_id;
        $pppoe_username = trim((string) $pppoe_username);
        $plan_name = trim((string) ($new_plan['name'] ?? ''));
        if ($router_id <= 0 || $plan_name === '') {
            return array('success' => false, 'message' => 'Router atau nama paket tidak valid untuk migrasi IP pool.');
        }

        $pool_candidate = '';
        foreach (array('remote_address_pool', 'ip_pool_name') as $column) {
            $value = trim((string) ($new_plan[$column] ?? ''));
            if ($value !== '') {
                $pool_candidate = $value;
                break;
            }
        }

        if ($pool_candidate === '') {
            $profile_remote = $this->mikrotikmanager->resolveProfileRemoteAddress($router_id, $plan_name);
            if (!empty($profile_remote['success'])) {
                $pool_candidate = trim((string) ($profile_remote['data']['remote_address'] ?? ''));
            }
        }

        if ($pool_candidate === '') {
            return array(
                'success' => true,
                'pool_name' => '',
                'target_remote_ip' => '',
                'message' => 'Profile paket tidak menggunakan remote-address pool.',
            );
        }

        $as_ip = $this->normalize_ipv4($pool_candidate);
        if ($as_ip !== '') {
            return array(
                'success' => true,
                'pool_name' => '',
                'target_remote_ip' => $as_ip,
                'message' => 'Profile paket menggunakan static remote-address.',
            );
        }

        $free_ip = $this->mikrotikmanager->findFreeIpInPool($router_id, $pool_candidate, $pppoe_username);
        if (empty($free_ip['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($free_ip['message'] ?? ('Tidak bisa cari IP kosong dari pool `' . $pool_candidate . '`.')),
            );
        }

        $picked_ip = $this->normalize_ipv4((string) ($free_ip['data']['ip_address'] ?? ''));
        if ($picked_ip === '') {
            return array(
                'success' => false,
                'message' => 'Gagal menentukan IP kosong yang valid dari pool `' . $pool_candidate . '`.',
            );
        }

        return array(
            'success' => true,
            'pool_name' => $pool_candidate,
            'target_remote_ip' => $picked_ip,
            'message' => 'IP kosong ditemukan pada pool target.',
        );
    }

    private function read_mikrotik_field(array $row, $field, $default = '')
    {
        $field = trim((string) $field);
        if ($field === '') {
            return $default;
        }

        foreach (array($field, '=' . $field) as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return $default;
    }

    private function normalize_ipv4($ip)
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '';
        }

        $ip = explode('/', $ip, 2)[0];
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
    }

    private function get_router_label($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || !$this->db->table_exists('routers')) {
            return $router_id > 0 ? ('Router #' . $router_id) : '-';
        }

        $router_fields = $this->db->list_fields('routers');
        $name_column = null;
        foreach (array('name', 'router_name', 'api_host', 'ip_address') as $candidate) {
            if (in_array($candidate, $router_fields, true)) {
                $name_column = $candidate;
                break;
            }
        }

        $qb = $this->db->select('id')->from('routers')->where('id', $router_id)->limit(1);
        if ($name_column !== null) {
            $qb->select($name_column . ' AS router_label', false);
        }

        $row = (array) $qb->get()->row_array();
        if (empty($row)) {
            return 'Router #' . $router_id;
        }

        $label = trim((string) ($row['router_label'] ?? ''));
        if ($label === '') {
            return 'Router #' . $router_id;
        }

        return $label . ' (#' . $router_id . ')';
    }

    private function normalize_date($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return '';
        }

        return date('Y-m-d', $ts);
    }

    private function json_response($http_code, array $payload)
    {
        $this->output
            ->set_status_header((int) $http_code)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($payload));
        return;
    }
}
