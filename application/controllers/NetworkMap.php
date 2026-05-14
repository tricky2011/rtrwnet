<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class NetworkMap extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'), 'Akses ditolak. Fiber Network Map hanya untuk admin.');
        $this->load->database();
        $this->load->helper(array('url', 'form'));
        $this->load->model('NetworkMap_model', 'networkmap_model');
        $this->load->model('Router_model', 'router_model');
        $this->networkmap_model->set_router_scope($this->getEffectiveRouterId());
    }

    public function index()
    {
        $routers = $this->networkmap_model->get_all_routers();

        $this->load->view('network/fiber_network_map', array(
            'routers' => $routers,
            'api_map_url' => site_url('api/network/map'),
            'network_nodes_url' => site_url('network/nodes'),
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
        ));
    }

    public function nodes()
    {
        $routers = $this->networkmap_model->get_all_routers();

        $this->load->view('network/network_nodes', array(
            'routers' => $routers,
            'api_map_url' => site_url('api/network/map'),
            'api_create_odc_url' => site_url('api/network/odc/create'),
            'api_update_odc_url' => site_url('api/network/odc/update'),
            'api_delete_odc_url' => site_url('api/network/odc/delete'),
            'api_create_odp_url' => site_url('api/network/odp/create'),
            'api_update_odp_url' => site_url('api/network/odp/update'),
            'api_delete_odp_url' => site_url('api/network/odp/delete'),
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
        ));
    }

    public function get_map_data()
    {
        if (strtoupper((string) $this->input->method()) !== 'GET') {
            return $this->json_response(405, array('message' => 'Method Not Allowed'));
        }

        $requested_router_id = (int) $this->input->get('router_id', true);
        $effective_router_id = (int) $this->getEffectiveRouterId();
        if ($effective_router_id > 0) {
            $requested_router_id = $effective_router_id;
        }

        $routers = $this->networkmap_model->get_all_routers($requested_router_id);
        $olts = $this->networkmap_model->get_olts_by_router($requested_router_id);
        $odcs = $this->networkmap_model->get_odc_by_router($requested_router_id);
        $odps = $this->networkmap_model->get_odp_by_router($requested_router_id);
        $customers = $this->networkmap_model->get_customers_by_router($requested_router_id);

        $routers = $this->enrich_router_metadata($routers, $olts, $odcs, $customers, $odps);

        return $this->json_response(200, array(
            'routers' => $routers,
            'olts' => $olts,
            'odcs' => $odcs,
            'odps' => $odps,
            'customers' => $customers,
            'filter_router_id' => $requested_router_id > 0 ? $requested_router_id : null,
            'generated_at' => date('c'),
        ));
    }

    public function get_router_list()
    {
        if (strtoupper((string) $this->input->method()) !== 'GET') {
            return $this->json_response(405, array('message' => 'Method Not Allowed'));
        }

        $requested_router_id = (int) $this->input->get('router_id', true);
        $effective_router_id = (int) $this->getEffectiveRouterId();
        if ($effective_router_id > 0) {
            $requested_router_id = $effective_router_id;
        }

        $routers = $this->networkmap_model->get_all_routers($requested_router_id);

        return $this->json_response(200, array(
            'routers' => $routers,
        ));
    }

    public function create_router()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }

        if (!$this->router_model->table_exists()) {
            return $this->respond_with_csrf(422, false, 'Tabel routers belum tersedia. Jalankan migration router terlebih dahulu.');
        }

        $payload = $this->read_json_or_post();

        $name = trim((string) ($payload['name'] ?? ''));
        $ip_address = trim((string) ($payload['ip_address'] ?? ''));
        $username = trim((string) ($payload['username'] ?? ''));
        $password = trim((string) ($payload['password'] ?? ''));
        $api_port = (int) ($payload['api_port'] ?? 8728);

        if ($name === '') {
            return $this->respond_with_csrf(422, false, 'Nama Router wajib diisi.');
        }
        if ($ip_address === '') {
            return $this->respond_with_csrf(422, false, 'IP/Host Router wajib diisi.');
        }
        if ($username === '') {
            return $this->respond_with_csrf(422, false, 'Username API Router wajib diisi.');
        }
        if ($password === '') {
            return $this->respond_with_csrf(422, false, 'Password API Router wajib diisi.');
        }
        if ($api_port <= 0) {
            $api_port = 8728;
        }

        if ($this->router_model->exists_name($name)) {
            return $this->respond_with_csrf(422, false, 'Nama Router sudah digunakan.');
        }

        $insert_payload = array(
            'name' => $name,
            'ip_address' => $ip_address,
            'api_port' => $api_port,
            'username' => $username,
            'password' => $password,
            'description' => trim((string) ($payload['description'] ?? '')),
            'is_active' => 1,
            'use_ssl' => (int) (!empty($payload['use_ssl']) ? 1 : 0),
            'timeout_seconds' => max(1, (int) ($payload['timeout_seconds'] ?? 5)),
        );

        $insert_id = (int) $this->router_model->insert($insert_payload);
        if ($insert_id <= 0) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][create_router] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal menambahkan Router: ' . $db_message) : 'Gagal menambahkan Router.'
            );
        }

        $geo_ok = $this->networkmap_model->update_router_geo($insert_id, $payload);
        if (!$geo_ok) {
            log_message('error', '[NETWORK_MAP][create_router] gagal update koordinat router id=' . $insert_id);
        }

        return $this->respond_with_csrf(200, true, 'Router berhasil ditambahkan.', array(
            'data' => $this->networkmap_model->get_router_row($insert_id),
        ));
    }

    public function update_router()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }

        if (!$this->router_model->table_exists()) {
            return $this->respond_with_csrf(422, false, 'Tabel routers belum tersedia.');
        }

        $payload = $this->read_json_or_post();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return $this->respond_with_csrf(422, false, 'ID Router tidak valid.');
        }

        $effective_router_id = (int) $this->getEffectiveRouterId();
        if ($effective_router_id > 0 && $id !== $effective_router_id) {
            return $this->respond_with_csrf(403, false, 'Anda hanya bisa mengubah router yang menjadi scope Anda.');
        }

        $existing = $this->router_model->get_by_id($id);
        if (empty($existing)) {
            return $this->respond_with_csrf(404, false, 'Router tidak ditemukan.');
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($existing['name'] ?? ''));
        }

        $ip_address = trim((string) ($payload['ip_address'] ?? ''));
        if ($ip_address === '') {
            $ip_address = trim((string) ($existing['ip_address'] ?? ''));
        }

        $username = trim((string) ($payload['username'] ?? ''));
        if ($username === '') {
            $username = trim((string) ($existing['username'] ?? ''));
        }
        $password = trim((string) ($payload['password'] ?? ''));
        $api_port = (int) ($payload['api_port'] ?? ($existing['api_port'] ?? 8728));

        if ($name === '') {
            return $this->respond_with_csrf(422, false, 'Nama Router wajib diisi.');
        }
        if ($ip_address === '') {
            return $this->respond_with_csrf(422, false, 'IP/Host Router wajib diisi.');
        }
        if ($username === '') {
            return $this->respond_with_csrf(422, false, 'Username API Router wajib diisi.');
        }
        if ($api_port <= 0) {
            $api_port = 8728;
        }
        if ($this->router_model->exists_name($name, $id)) {
            return $this->respond_with_csrf(422, false, 'Nama Router sudah digunakan.');
        }

        $update_payload = array(
            'name' => $name,
            'ip_address' => $ip_address,
            'api_port' => $api_port,
            'username' => $username,
            'password' => $password,
            'description' => array_key_exists('description', $payload)
                ? trim((string) $payload['description'])
                : (string) ($existing['description'] ?? ''),
            'is_active' => (int) ($existing['is_active'] ?? 1),
            'use_ssl' => (int) ($existing['use_ssl'] ?? 0),
            'timeout_seconds' => max(1, (int) ($existing['timeout_seconds'] ?? 5)),
        );

        if (array_key_exists('use_ssl', $payload)) {
            $update_payload['use_ssl'] = (int) (!empty($payload['use_ssl']) ? 1 : 0);
        }
        if (array_key_exists('timeout_seconds', $payload)) {
            $update_payload['timeout_seconds'] = max(1, (int) $payload['timeout_seconds']);
        }

        $ok = $this->router_model->update($id, $update_payload);
        if (!$ok) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][update_router] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal memperbarui Router: ' . $db_message) : 'Gagal memperbarui Router.'
            );
        }

        $geo_ok = $this->networkmap_model->update_router_geo($id, $payload);
        if (!$geo_ok) {
            log_message('error', '[NETWORK_MAP][update_router] gagal update koordinat router id=' . $id);
        }

        return $this->respond_with_csrf(200, true, 'Router berhasil diperbarui.', array(
            'data' => $this->networkmap_model->get_router_row($id),
        ));
    }

    public function delete_router()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }

        if (!$this->router_model->table_exists()) {
            return $this->respond_with_csrf(422, false, 'Tabel routers belum tersedia.');
        }

        $payload = $this->read_json_or_post();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return $this->respond_with_csrf(422, false, 'ID Router tidak valid.');
        }

        $effective_router_id = (int) $this->getEffectiveRouterId();
        if ($effective_router_id > 0 && $id !== $effective_router_id) {
            return $this->respond_with_csrf(403, false, 'Anda hanya bisa menghapus router yang menjadi scope Anda.');
        }

        $role = (string) $this->session->userdata('role');
        if (function_exists('normalizeRole')) {
            $role = normalizeRole($role);
        } else {
            $role = strtolower(trim($role));
        }

        $ok = $this->router_model->delete_by_role($id, $role);
        if (!$ok) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][delete_router] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal menghapus Router: ' . $db_message) : 'Gagal menghapus Router.'
            );
        }

        return $this->respond_with_csrf(200, true, 'Router berhasil dihapus.');
    }

    public function create_olt()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }

        $payload = $this->read_json_or_post();
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->respond_with_csrf(422, false, 'Nama OLT wajib diisi.');
        }

        $effective_router_id = (int) $this->getEffectiveRouterId();
        $router_id = $effective_router_id > 0 ? $effective_router_id : (int) ($payload['router_id'] ?? 0);
        if ($router_id <= 0) {
            return $this->respond_with_csrf(422, false, 'Router wajib dipilih untuk OLT.');
        }

        $payload['router_id'] = $router_id;
        $insert_id = $this->networkmap_model->create_olt($payload);
        if ((int) $insert_id <= 0) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][create_olt] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal menambahkan OLT: ' . $db_message) : 'Gagal menambahkan OLT.'
            );
        }

        return $this->respond_with_csrf(200, true, 'OLT berhasil ditambahkan.', array(
            'data' => $this->networkmap_model->get_olt_row($insert_id),
        ));
    }

    public function update_olt()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }

        $payload = $this->read_json_or_post();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return $this->respond_with_csrf(422, false, 'ID OLT tidak valid.');
        }
        if (array_key_exists('name', $payload) && trim((string) $payload['name']) === '') {
            return $this->respond_with_csrf(422, false, 'Nama OLT wajib diisi.');
        }

        $effective_router_id = (int) $this->getEffectiveRouterId();
        if ($effective_router_id > 0) {
            $payload['router_id'] = $effective_router_id;
        }

        $ok = $this->networkmap_model->update_olt($id, $payload);
        if (!$ok) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][update_olt] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal memperbarui OLT: ' . $db_message) : 'Gagal memperbarui OLT.'
            );
        }

        return $this->respond_with_csrf(200, true, 'OLT berhasil diperbarui.', array(
            'data' => $this->networkmap_model->get_olt_row($id),
        ));
    }

    public function delete_olt()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }

        $payload = $this->read_json_or_post();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return $this->respond_with_csrf(422, false, 'ID OLT tidak valid.');
        }

        $ok = $this->networkmap_model->delete_olt($id);
        if (!$ok) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][delete_olt] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal menghapus OLT: ' . $db_message) : 'Gagal menghapus OLT.'
            );
        }

        return $this->respond_with_csrf(200, true, 'OLT berhasil dihapus.');
    }

    public function create_odc()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }
        if (!$this->db->table_exists('fiber_odc')) {
            return $this->respond_with_csrf(422, false, 'Tabel fiber_odc belum tersedia. Jalankan migration Fiber Network Map ODC terlebih dahulu.');
        }

        $payload = $this->read_json_or_post();
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->respond_with_csrf(422, false, 'Nama ODC wajib diisi.');
        }

        $effective_router_id = (int) $this->getEffectiveRouterId();
        $router_id = $effective_router_id > 0 ? $effective_router_id : (int) ($payload['router_id'] ?? 0);
        if ($router_id <= 0) {
            return $this->respond_with_csrf(422, false, 'Router wajib dipilih untuk ODC.');
        }

        $payload['router_id'] = $router_id;
        $insert_id = $this->networkmap_model->create_odc($payload);
        if ((int) $insert_id <= 0) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][create_odc] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal menambahkan ODC: ' . $db_message) : 'Gagal menambahkan ODC.'
            );
        }

        return $this->respond_with_csrf(200, true, 'ODC berhasil ditambahkan.', array(
            'data' => $this->networkmap_model->get_odc_row($insert_id),
        ));
    }

    public function update_odc()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }
        if (!$this->db->table_exists('fiber_odc')) {
            return $this->respond_with_csrf(422, false, 'Tabel fiber_odc belum tersedia.');
        }

        $payload = $this->read_json_or_post();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return $this->respond_with_csrf(422, false, 'ID ODC tidak valid.');
        }
        if (array_key_exists('name', $payload) && trim((string) $payload['name']) === '') {
            return $this->respond_with_csrf(422, false, 'Nama ODC wajib diisi.');
        }

        $effective_router_id = (int) $this->getEffectiveRouterId();
        if ($effective_router_id > 0) {
            $payload['router_id'] = $effective_router_id;
        }

        $ok = $this->networkmap_model->update_odc($id, $payload);
        if (!$ok) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][update_odc] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal memperbarui ODC: ' . $db_message) : 'Gagal memperbarui ODC.'
            );
        }

        return $this->respond_with_csrf(200, true, 'ODC berhasil diperbarui.', array(
            'data' => $this->networkmap_model->get_odc_row($id),
        ));
    }

    public function delete_odc()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }
        if (!$this->db->table_exists('fiber_odc')) {
            return $this->respond_with_csrf(422, false, 'Tabel fiber_odc belum tersedia.');
        }

        $payload = $this->read_json_or_post();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return $this->respond_with_csrf(422, false, 'ID ODC tidak valid.');
        }

        $ok = $this->networkmap_model->delete_odc($id);
        if (!$ok) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][delete_odc] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal menghapus ODC: ' . $db_message) : 'Gagal menghapus ODC.'
            );
        }

        return $this->respond_with_csrf(200, true, 'ODC berhasil dihapus.');
    }

    public function create_odp()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }

        $payload = $this->read_json_or_post();
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->respond_with_csrf(422, false, 'Nama ODP wajib diisi.');
        }

        $effective_router_id = (int) $this->getEffectiveRouterId();
        $router_id = $effective_router_id > 0 ? $effective_router_id : (int) ($payload['router_id'] ?? 0);
        if ($router_id <= 0) {
            return $this->respond_with_csrf(422, false, 'Router wajib dipilih untuk ODP.');
        }

        $payload['router_id'] = $router_id;
        $insert_id = $this->networkmap_model->create_odp($payload);
        if ((int) $insert_id <= 0) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][create_odp] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal menambahkan ODP: ' . $db_message) : 'Gagal menambahkan ODP.'
            );
        }

        return $this->respond_with_csrf(200, true, 'ODP berhasil ditambahkan.', array(
            'data' => $this->networkmap_model->get_odp_row($insert_id),
        ));
    }

    public function update_odp()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }

        $payload = $this->read_json_or_post();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return $this->respond_with_csrf(422, false, 'ID ODP tidak valid.');
        }

        $ok = $this->networkmap_model->update_odp($id, $payload);
        if (!$ok) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][update_odp] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal memperbarui ODP: ' . $db_message) : 'Gagal memperbarui ODP.'
            );
        }

        return $this->respond_with_csrf(200, true, 'ODP berhasil diperbarui.', array(
            'data' => $this->networkmap_model->get_odp_row($id),
        ));
    }

    public function delete_odp()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->respond_with_csrf(405, false, 'Method Not Allowed');
        }

        $payload = $this->read_json_or_post();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return $this->respond_with_csrf(422, false, 'ID ODP tidak valid.');
        }

        $ok = $this->networkmap_model->delete_odp($id);
        if (!$ok) {
            $db_error = $this->db->error();
            $db_message = trim((string) ($db_error['message'] ?? ''));
            if ($db_message !== '') {
                log_message('error', '[NETWORK_MAP][delete_odp] DB error: ' . $db_message);
            }
            return $this->respond_with_csrf(
                422,
                false,
                $db_message !== '' ? ('Gagal menghapus ODP: ' . $db_message) : 'Gagal menghapus ODP.'
            );
        }

        return $this->respond_with_csrf(200, true, 'ODP berhasil dihapus.');
    }

    private function enrich_router_metadata(array $routers, array $olts, array $odcs, array $customers, array $odps)
    {
        if (empty($routers)) {
            return $routers;
        }

        $router_map = array();
        $coord_points = array();
        foreach ($routers as $idx => $router) {
            $router_id = (int) ($router['id'] ?? 0);
            if ($router_id <= 0) {
                continue;
            }
            $router_map[$router_id] = $idx;
            $routers[$idx]['metadata'] = is_array($router['metadata'] ?? null) ? $router['metadata'] : array();
            $routers[$idx]['metadata']['total_olt'] = 0;
            $routers[$idx]['metadata']['total_odc'] = 0;
            $routers[$idx]['metadata']['total_odp'] = 0;
            $routers[$idx]['metadata']['total_customers'] = 0;
            $coord_points[$router_id] = array();
        }

        foreach ($olts as $olt) {
            $router_id = (int) ($olt['router_id'] ?? 0);
            if (!isset($router_map[$router_id])) {
                continue;
            }
            $routers[$router_map[$router_id]]['metadata']['total_olt'] += 1;
            $lat = $olt['latitude'] ?? null;
            $lng = $olt['longitude'] ?? null;
            if ($lat !== null && $lng !== null) {
                $coord_points[$router_id][] = array((float) $lat, (float) $lng);
            }
        }

        foreach ($odcs as $odc) {
            $router_id = (int) ($odc['router_id'] ?? 0);
            if (!isset($router_map[$router_id])) {
                continue;
            }
            $routers[$router_map[$router_id]]['metadata']['total_odc'] += 1;
            $lat = $odc['latitude'] ?? null;
            $lng = $odc['longitude'] ?? null;
            if ($lat !== null && $lng !== null) {
                $coord_points[$router_id][] = array((float) $lat, (float) $lng);
            }
        }

        foreach ($odps as $odp) {
            $router_id = (int) ($odp['router_id'] ?? 0);
            if (!isset($coord_points[$router_id])) {
                continue;
            }
            $routers[$router_map[$router_id]]['metadata']['total_odp'] += 1;
            $lat = $odp['latitude'] ?? null;
            $lng = $odp['longitude'] ?? null;
            if ($lat !== null && $lng !== null) {
                $coord_points[$router_id][] = array((float) $lat, (float) $lng);
            }
        }

        foreach ($customers as $customer) {
            $router_id = (int) ($customer['router_id'] ?? 0);
            if (!isset($router_map[$router_id])) {
                continue;
            }
            $routers[$router_map[$router_id]]['metadata']['total_customers'] += 1;
            $lat = $customer['latitude'] ?? null;
            $lng = $customer['longitude'] ?? null;
            if ($lat !== null && $lng !== null) {
                $coord_points[$router_id][] = array((float) $lat, (float) $lng);
            }
        }

        foreach ($router_map as $router_id => $idx) {
            $lat = $routers[$idx]['latitude'] ?? null;
            $lng = $routers[$idx]['longitude'] ?? null;
            if ($lat !== null && $lng !== null) {
                continue;
            }

            $points = $coord_points[$router_id] ?? array();
            if (empty($points)) {
                continue;
            }

            $sum_lat = 0.0;
            $sum_lng = 0.0;
            $count = 0;
            foreach ($points as $point) {
                $sum_lat += (float) $point[0];
                $sum_lng += (float) $point[1];
                $count++;
            }
            if ($count > 0) {
                $routers[$idx]['latitude'] = round($sum_lat / $count, 7);
                $routers[$idx]['longitude'] = round($sum_lng / $count, 7);
            }
        }

        return $routers;
    }

    private function read_json_or_post()
    {
        $raw = trim((string) $this->input->raw_input_stream);
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        $post = $this->input->post(null, true);
        return is_array($post) ? $post : array();
    }

    private function respond_with_csrf($http_code, $success, $message, array $extra = array())
    {
        $payload = array_merge(array(
            'success' => (bool) $success,
            'message' => (string) $message,
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
        ), $extra);

        return $this->json_response($http_code, $payload);
    }

    private function json_response($http_code, array $payload)
    {
        $this->output
            ->set_status_header((int) $http_code)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
        return;
    }
}
