<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ppp_profiles extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->database();
        $this->load->model('ppp_profile_model');
        $this->load->model('ip_pool_model');
        $this->load->model('settings_model');
        $this->load->library(array('form_validation', 'session', 'mikrotik_api'));
        $this->load->helper(array('url', 'form', 'currency'));

        if (method_exists($this->ppp_profile_model, 'set_router_scope')) {
            $this->ppp_profile_model->set_router_scope($this->getEffectiveRouterId());
        }
        if (method_exists($this->ip_pool_model, 'set_router_scope')) {
            $this->ip_pool_model->set_router_scope($this->getEffectiveRouterId());
        }
    }

    public function index()
    {
        $keyword = trim((string) $this->input->get('search', true));
        $pager = $this->init_pagination(
            'ppp_profiles',
            $this->ppp_profile_model->count_filtered($keyword),
            20,
            3
        );

        $this->load->view('ppp_profiles/list', array(
            'profiles' => $this->ppp_profile_model->get_paginated($pager['per_page'], $pager['offset'], $keyword),
            'pagination' => $pager['links'],
            'search' => $keyword,
            'total_rows' => $pager['total_rows'],
        ));
    }

    public function create()
    {
        $this->require_role(array('superadmin', 'admin'));
        return $this->render_form('create');
    }

    public function sync_from_router()
    {
        $this->require_role(array('superadmin', 'admin'));

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->db->table_exists('ppp_profiles')) {
            $this->session->set_flashdata('error', 'Tabel ppp_profiles tidak ditemukan.');
            return redirect('ppp-profiles');
        }

        $router_context = $this->resolve_target_router_context();
        if (empty($router_context['success'])) {
            $this->session->set_flashdata('error', (string) ($router_context['message'] ?? 'Router tidak valid.'));
            return redirect('ppp-profiles');
        }
        $router_id = (int) ($router_context['router_id'] ?? 0);
        $router_name = (string) ($router_context['router_name'] ?? ('Router #' . $router_id));

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $api = null;

        try {
            $this->load->helper('tenant');
            $connect = connectRouter($router_id);
            if (empty($connect['success']) || empty($connect['api'])) {
                $this->session->set_flashdata('error', 'Gagal konek router `' . $router_name . '`: ' . (string) ($connect['message'] ?? 'unknown'));
                return redirect('ppp-profiles');
            }
            $api = $connect['api'];

            // Test koneksi dasar API.
            $test_identity = $api->comm('/system/identity/print');
            if (!is_array($test_identity) || empty($test_identity)) {
                $this->session->set_flashdata('error', 'API terhubung tetapi /system/identity/print kosong.');
                return redirect('ppp-profiles');
            }

            // Command utama sync profile (wajib dengan slash path).
            $profiles = $api->comm('/ppp/profile/print');
            if (!is_array($profiles) || empty($profiles)) {
                // Fallback command format array seperti request.
                $profiles = $api->comm('/ppp/profile/print', array());
            }

            if (!is_array($profiles) || empty($profiles)) {
                $this->session->set_flashdata('error', 'PPP Profile tidak terbaca dari router. Kemungkinan permission user API untuk menu PPP belum cukup.');
                return redirect('ppp-profiles');
            }

            foreach ($profiles as $row) {
                if (!is_array($row)) {
                    $skipped++;
                    continue;
                }

                $name = trim((string) $this->get_mikrotik_value($row, 'name'));
                if ($name === '') {
                    $skipped++;
                    continue;
                }

                $lower_name = strtolower($name);
                if (in_array($lower_name, array('default', 'default-encryption'), true)) {
                    $skipped++;
                    continue;
                }

                $data = array(
                    'rate_limit' => trim((string) $this->get_mikrotik_value($row, 'rate-limit')),
                    'local_address' => trim((string) $this->get_mikrotik_value($row, 'local-address')),
                    'remote_address_pool' => trim((string) $this->get_mikrotik_value($row, 'remote-address')),
                    'updated_at' => date('Y-m-d H:i:s'),
                );
                if ($this->table_has_column('ppp_profiles', 'router_id')) {
                    $data['router_id'] = $router_id;
                }

                $qb = $this->db
                    ->from('ppp_profiles')
                    ->where('name', $name);
                if ($this->table_has_column('ppp_profiles', 'router_id')) {
                    $qb->where('router_id', $router_id);
                }
                $existing = $qb->limit(1)->get()->row_array();

                if ($existing) {
                    $ok = $this->db
                        ->where('id', (int) $existing['id'])
                        ->update('ppp_profiles', $data);

                    if ($ok) {
                        $updated++;
                    } else {
                        $failed++;
                        log_message('error', '[PPP_SYNC] Update gagal untuk `' . $name . '`: ' . json_encode($this->db->error()));
                    }
                    continue;
                }

                $insert_data = $data;
                $insert_data['name'] = $name;
                $insert_data['price'] = 0.00;
                $insert_data['description'] = null;
                $insert_data['created_at'] = date('Y-m-d H:i:s');

                $ok = $this->db->insert('ppp_profiles', $insert_data);
                if ($ok) {
                    $inserted++;
                } else {
                    $failed++;
                    log_message('error', '[PPP_SYNC] Insert gagal untuk `' . $name . '`: ' . json_encode($this->db->error()));
                }
            }

            $this->session->set_flashdata(
                'success',
                'PPP Profiles synced successfully [' . $router_name . ']. Inserted: ' . $inserted . ', Updated: ' . $updated . ', Skipped: ' . $skipped . ', Failed: ' . $failed
            );
        } catch (Throwable $e) {
            log_message('error', '[PPP_SYNC] Gagal sync profile dari MikroTik: ' . $e->getMessage());
            $this->session->set_flashdata('error', 'Gagal sync profile dari MikroTik: ' . $e->getMessage());
        } finally {
            if (is_object($api) && method_exists($api, 'disconnect')) {
                $api->disconnect();
            }
        }

        return redirect('ppp-profiles');
    }

    private function resolve_target_router_context()
    {
        $effective = $this->getEffectiveRouterId();
        $effective = $effective !== null ? (int) $effective : 0;
        if ($effective > 0) {
            $name = (string) $this->settings_model->get_router_name_by_id($effective);
            return array(
                'success' => true,
                'router_id' => $effective,
                'router_name' => $name !== '' ? $name : ('Router #' . $effective),
            );
        }

        $role = function_exists('normalizeRole')
            ? normalizeRole((string) $this->session->userdata('role'))
            : strtolower(trim((string) $this->session->userdata('role')));
        if ($role !== 'superadmin') {
            return array('success' => false, 'message' => 'Akun Anda belum memiliki router scope.');
        }

        $routers = $this->settings_model->get_active_routers();
        if (count($routers) === 1) {
            $rid = (int) ($routers[0]['id'] ?? 0);
            if ($rid > 0) {
                return array(
                    'success' => true,
                    'router_id' => $rid,
                    'router_name' => (string) ($routers[0]['name'] ?? ('Router #' . $rid)),
                );
            }
        }

        return array('success' => false, 'message' => 'Pilih distribusi/router aktif terlebih dahulu sebelum sync.');
    }

    private function connect_mikweb_api(array $mk)
    {
        require_once APPPATH . 'third_party/RouterOS/routeros_api.class.php';

        $api = new RouterosAPI();
        $api->port = !empty($mk['api_port']) ? (int) $mk['api_port'] : 8728;
        $api->ssl = !empty($mk['use_ssl']);
        $api->timeout = 6;
        $api->attempts = 1;
        $api->delay = 0;
        $api->debug = false;

        $connected = $api->connect(
            (string) $mk['host'],
            (string) $mk['username'],
            (string) $mk['password']
        );
        if (!$connected) {
            $error_no = isset($api->error_no) ? $api->error_no : '';
            $error_str = isset($api->error_str) ? $api->error_str : 'unknown error';
            throw new RuntimeException('Koneksi MikroTik gagal. [' . $error_no . '] ' . $error_str);
        }

        return $api;
    }

    private function get_mikrotik_value(array $row, $key, $default = '')
    {
        $key = (string) $key;
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }

        $alt_key = '=' . $key;
        if (array_key_exists($alt_key, $row)) {
            return $row[$alt_key];
        }

        return $default;
    }

    public function store()
    {
        $this->require_role(array('superadmin', 'admin'));

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $this->set_validation_rules();
        if ($this->form_validation->run() === false) {
            return $this->render_form('create');
        }

        $payload = $this->collect_payload();
        if ($this->ppp_profile_model->exists_name($payload['name'])) {
            $this->session->set_flashdata('error', 'Nama PPP Profile sudah digunakan.');
            return redirect('ppp-profiles/create');
        }

        $this->db->trans_begin();
        $id = $this->ppp_profile_model->insert($payload);
        if (!$id) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Gagal menyimpan PPP Profile ke database.');
            return redirect('ppp-profiles/create');
        }

        $sync = $this->sync_create_to_mikrotik($payload);
        if (empty($sync['success'])) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', $sync['message']);
            return redirect('ppp-profiles/create');
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Transaction database gagal saat create PPP Profile.');
            return redirect('ppp-profiles/create');
        }

        $this->db->trans_commit();
        $this->session->set_flashdata('success', 'PPP Profile berhasil dibuat dan tersinkron ke MikroTik.');
        return redirect('ppp-profiles');
    }

    public function edit($id = null)
    {
        $this->require_role(array('superadmin', 'admin'));

        $id = (int) $id;
        $profile = $this->ppp_profile_model->get_by_id($id);
        if (!$profile) {
            $this->session->set_flashdata('error', 'PPP Profile tidak ditemukan.');
            return redirect('ppp-profiles');
        }

        return $this->render_form('edit', $profile);
    }

    public function update($id = null)
    {
        $this->require_role(array('superadmin', 'admin'));

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            show_error('Invalid request method', 405);
            return;
        }
        $id = (int) $id;
        $existing = $this->ppp_profile_model->get_by_id($id);
        if (!$existing) {
            $this->session->set_flashdata('error', 'PPP Profile tidak ditemukan.');
            return redirect('ppp-profiles');
        }

        $post = $this->input->post(null, true);
        if (!$post) {
            show_error('No POST data received');
            return;
        }
        if (!isset($post['price'])) {
            show_error('Price field missing from POST');
            return;
        }

        $raw_price = trim((string) $post['price']);
        if (!is_numeric($raw_price)) {
            show_error('Price must be numeric');
            return;
        }

        $data = array(
            'name' => trim((string) ($post['name'] ?? '')),
            'rate_limit' => trim((string) ($post['rate_limit'] ?? '')),
            'local_address' => trim((string) ($post['local_address'] ?? '')),
            'remote_address_pool' => trim((string) ($post['remote_address_pool'] ?? '')),
            'price' => (float) $raw_price,
            'description' => trim((string) ($post['description'] ?? '')),
            'updated_at' => date('Y-m-d H:i:s'),
        );

        if ($this->ppp_profile_model->exists_name((string) $data['name'], $id)) {
            $this->session->set_flashdata('error', 'Nama PPP Profile sudah digunakan.');
            return redirect('ppp_profiles/edit/' . $id);
        }

        $this->db->where('id', $id);
        $ok = $this->db->update('ppp_profiles', $data);
        if (!$ok) {
            show_error('Update gagal.');
            return;
        }

        $sync_payload = array(
            'name' => $data['name'],
            'rate_limit' => $data['rate_limit'],
            'local_address' => $data['local_address'],
            'remote_address_pool' => $data['remote_address_pool'],
        );
        $sync = $this->sync_update_to_mikrotik((string) $existing['name'], $sync_payload);
        if (empty($sync['success'])) {
            log_message('error', '[PPP_UPDATE] DB updated but MikroTik sync failed for id=' . $id . ': ' . (string) ($sync['message'] ?? 'unknown'));
            $this->session->set_flashdata('error', 'PPP Profile tersimpan di database, tetapi sinkron MikroTik gagal: ' . (string) ($sync['message'] ?? 'unknown'));
            return redirect('ppp_profiles/edit/' . $id);
        }

        if ($this->db->affected_rows() < 0) {
            show_error('Update gagal.');
            return;
        }

        $this->session->set_flashdata('swal_success', 'PPP Profile berhasil diupdate.');
        return redirect('ppp_profiles');
    }

    public function delete($id = null)
    {
        $this->require_role(array('superadmin', 'admin'));

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $id = (int) $id;
        $existing = $this->ppp_profile_model->get_by_id($id);
        if (!$existing) {
            $this->session->set_flashdata('error', 'PPP Profile tidak ditemukan.');
            return redirect('ppp-profiles');
        }

        if ($this->db->table_exists('customer_services')) {
            $service_fields = $this->db->list_fields('customer_services');
            if (!in_array('ppp_profile_id', $service_fields, true)) {
                $this->session->set_flashdata('error', 'Kolom customer_services.ppp_profile_id belum ada. Jalankan migrasi database terlebih dahulu.');
                return redirect('ppp-profiles');
            }

            $used = (int) $this->db
                ->from('customer_services')
                ->where('ppp_profile_id', $id)
                ->count_all_results();
            if ($used > 0) {
                $this->session->set_flashdata('error', 'PPP Profile masih dipakai customer_services dan tidak bisa dihapus.');
                return redirect('ppp-profiles');
            }
        }

        $role = (string) $this->session->userdata('role');
        if ($role !== 'superadmin') {
            $ok = $this->ppp_profile_model->soft_delete($id, (int) $this->session->userdata('user_id'));
            if (!$ok) {
                $this->session->set_flashdata('error', 'Gagal hapus PPP Profile.');
                return redirect('ppp-profiles');
            }

            $this->session->set_flashdata('success', 'PPP Profile berhasil dihapus.');
            return redirect('ppp-profiles');
        }

        $this->db->trans_begin();

        $sync = $this->sync_delete_from_mikrotik((string) $existing['name']);
        if (empty($sync['success'])) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', $sync['message']);
            return redirect('ppp-profiles');
        }

        $ok = $this->ppp_profile_model->delete($id);
        if (!$ok) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Gagal menghapus PPP Profile dari database.');
            return redirect('ppp-profiles');
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'Transaction database gagal saat hapus PPP Profile.');
            return redirect('ppp-profiles');
        }

        $this->db->trans_commit();
        $this->session->set_flashdata('success', 'PPP Profile berhasil dihapus.');
        return redirect('ppp-profiles');
    }

    private function set_validation_rules()
    {
        $this->form_validation->set_rules('name', 'Nama Profile', 'trim|required|max_length[100]');
        $this->form_validation->set_rules('rate_limit', 'Rate Limit', 'trim|required|max_length[100]');
        $this->form_validation->set_rules('local_address', 'Local Address', 'trim|required|max_length[100]');
        $this->form_validation->set_rules('remote_address_pool', 'Remote Address Pool', 'trim|required|max_length[100]');
        $this->form_validation->set_rules('price', 'Price', 'trim|required|numeric');
    }

    private function collect_payload()
    {
        $rawPrice = $this->input->post('price');
        $price = $this->normalize_price_input($rawPrice);

        return array(
            'name' => trim((string) $this->input->post('name', true)),
            'rate_limit' => trim((string) $this->input->post('rate_limit', true)),
            'local_address' => trim((string) $this->input->post('local_address', true)),
            'remote_address_pool' => trim((string) $this->input->post('remote_address_pool', true)),
            'price' => $price === null ? null : (float) $price,
            'description' => trim((string) $this->input->post('description', true)),
        );
    }

    private function normalize_price_input($raw_price)
    {
        if ($raw_price !== null && $raw_price !== '') {
            $clean_price = str_replace(array('.', ','), '', trim((string) $raw_price));
            if (is_numeric($clean_price)) {
                return (float) $clean_price;
            }

            return 0;
        }

        return null;
    }

    private function render_form($mode, $profile = null)
    {
        $this->load->view('ppp_profiles/form', array(
            'mode' => $mode,
            'profile' => $profile,
            'ip_pools' => $this->ip_pool_model->get_all(),
        ));
    }

    private function sync_create_to_mikrotik(array $payload)
    {
        $mk = $this->settings_model->get_mikrotik_settings();
        if (empty($mk['host']) || empty($mk['username']) || empty($mk['password'])) {
            return array('success' => false, 'message' => 'Konfigurasi MikroTik belum lengkap. Isi di menu Settings terlebih dahulu.');
        }

        $this->mikrotik_api->configure($mk);
        return $this->mikrotik_api->add_ppp_profile($payload);
    }

    private function sync_update_to_mikrotik($old_name, array $payload)
    {
        $mk = $this->settings_model->get_mikrotik_settings();
        if (empty($mk['host']) || empty($mk['username']) || empty($mk['password'])) {
            return array('success' => false, 'message' => 'Konfigurasi MikroTik belum lengkap. Isi di menu Settings terlebih dahulu.');
        }

        $this->mikrotik_api->configure($mk);
        return $this->mikrotik_api->update_ppp_profile($old_name, $payload);
    }

    private function sync_delete_from_mikrotik($name)
    {
        $mk = $this->settings_model->get_mikrotik_settings();
        if (empty($mk['host']) || empty($mk['username']) || empty($mk['password'])) {
            return array('success' => false, 'message' => 'Konfigurasi MikroTik belum lengkap. Isi di menu Settings terlebih dahulu.');
        }

        $this->mikrotik_api->configure($mk);
        return $this->mikrotik_api->remove_ppp_profile($name);
    }

    private function table_has_column($table, $column)
    {
        if (!$this->db->table_exists((string) $table)) {
            return false;
        }

        return in_array((string) $column, $this->db->list_fields((string) $table), true);
    }
}
