<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ont_remote extends MY_Controller
{
    private $customer_fields = array();

    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->database();
        $this->load->helper(array('url', 'form'));
        $this->load->model('tr069_acs_model');

        if ($this->db->table_exists('customers')) {
            $this->customer_fields = $this->db->list_fields('customers');
        }
    }

    public function index()
    {
        $this->load->view('ont_remote/index', array(
            'customer_options' => $this->get_customer_options('', 1000),
            'has_ont_columns' => $this->has_required_ont_columns(),
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
        ));
    }

    public function detail()
    {
        $customer_id = (int) $this->input->get('customer_id', true);
        if ($customer_id <= 0) {
            return $this->json_response(array(
                'success' => false,
                'message' => 'customer_id wajib dipilih.',
            ), 422);
        }

        try {
            $result = $this->tr069_acs_model->get_customer_ont($customer_id);
            if (empty($result['success'])) {
                return $this->json_response(array(
                    'success' => false,
                    'message' => (string) ($result['message'] ?? 'Gagal mengambil detail ONT.'),
                ), 422);
            }

            return $this->json_response(array(
                'success' => true,
                'message' => 'Detail ONT berhasil dimuat.',
                'data' => $result['data'],
            ));
        } catch (Throwable $e) {
            log_message('error', '[ONT_REMOTE] detail failed: ' . $e->getMessage());
            return $this->json_response(array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ), 500);
        }
    }

    public function set_wifi()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Method Not Allowed',
            ), 405);
        }

        $customer_id = (int) $this->input->post('customer_id', true);
        $ssid = trim((string) $this->input->post('ssid', true));
        $password = trim((string) $this->input->post('password', true));

        if ($customer_id <= 0 || $ssid === '' || $password === '') {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Field customer, SSID, dan password wajib diisi.',
            ), 422);
        }

        if (strlen($password) < 8) {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Password WiFi minimal 8 karakter.',
            ), 422);
        }

        try {
            $result = $this->tr069_acs_model->set_wifi($customer_id, $ssid, $password);
            if (empty($result['success'])) {
                return $this->json_response(array(
                    'success' => false,
                    'message' => (string) ($result['message'] ?? 'Set WiFi gagal.'),
                    'errors' => isset($result['errors']) ? $result['errors'] : array(),
                ), 422);
            }

            return $this->json_response(array(
                'success' => true,
                'message' => (string) ($result['message'] ?? 'WiFi ONT berhasil diupdate.'),
                'data' => isset($result['data']) ? $result['data'] : array(),
            ));
        } catch (Throwable $e) {
            log_message('error', '[ONT_REMOTE] set_wifi failed: ' . $e->getMessage());
            return $this->json_response(array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ), 500);
        }
    }

    public function reboot()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Method Not Allowed',
            ), 405);
        }

        $customer_id = (int) $this->input->post('customer_id', true);
        if ($customer_id <= 0) {
            return $this->json_response(array(
                'success' => false,
                'message' => 'customer_id wajib dipilih.',
            ), 422);
        }

        try {
            $result = $this->tr069_acs_model->reboot_ont($customer_id);
            if (empty($result['success'])) {
                return $this->json_response(array(
                    'success' => false,
                    'message' => (string) ($result['message'] ?? 'Reboot ONT gagal.'),
                ), 422);
            }

            return $this->json_response(array(
                'success' => true,
                'message' => (string) ($result['message'] ?? 'Task reboot berhasil dikirim.'),
                'data' => isset($result['data']) ? $result['data'] : array(),
            ));
        } catch (Throwable $e) {
            log_message('error', '[ONT_REMOTE] reboot failed: ' . $e->getMessage());
            return $this->json_response(array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ), 500);
        }
    }

    public function connected_devices()
    {
        $customer_id = (int) $this->input->get('customer_id', true);
        if ($customer_id <= 0) {
            return $this->json_response(array(
                'success' => false,
                'message' => 'customer_id wajib dipilih.',
            ), 422);
        }

        try {
            $result = $this->tr069_acs_model->get_connected_devices($customer_id);
            if (empty($result['success'])) {
                return $this->json_response(array(
                    'success' => false,
                    'message' => (string) ($result['message'] ?? 'Gagal membaca connected devices.'),
                ), 422);
            }

            $hosts = array();
            if (!empty($result['data']['hosts']) && is_array($result['data']['hosts'])) {
                $hosts = array_values($result['data']['hosts']);
            }

            return $this->json_response(array(
                'success' => true,
                'message' => 'Connected devices berhasil dimuat.',
                'data' => array(
                    'hosts' => $hosts,
                ),
            ));
        } catch (Throwable $e) {
            log_message('error', '[ONT_REMOTE] connected_devices failed: ' . $e->getMessage());
            return $this->json_response(array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ), 500);
        }
    }

    public function summary()
    {
        try {
            $data = $this->build_summary_data();
            return $this->json_response(array(
                'success' => true,
                'message' => 'Summary ONT berhasil dimuat.',
                'data' => $data,
            ));
        } catch (Throwable $e) {
            log_message('error', '[ONT_REMOTE] summary failed: ' . $e->getMessage());
            return $this->json_response(array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ), 500);
        }
    }

    private function get_customer_options($keyword = '', $limit = 500)
    {
        if (!$this->db->table_exists('customers')) {
            return array();
        }

        if (!$this->has_customer_field('ont_device_id')) {
            return array();
        }

        $keyword = trim((string) $keyword);
        $limit = max(1, min(2000, (int) $limit));

        $qb = $this->db
            ->select('*')
            ->from('customers')
            ->where('ont_device_id !=', '')
            ->order_by('id', 'DESC')
            ->limit($limit);

        $this->apply_customer_soft_delete_scope($qb);

        if ($keyword !== '') {
            $search_cols = array('full_name', 'nama', 'customer_code', 'username', 'pppoe_username', 'ont_device_id', 'ont_serial');
            $has_search = false;
            foreach ($search_cols as $column) {
                if (!$this->has_customer_field($column)) {
                    continue;
                }

                if (!$has_search) {
                    $qb->group_start()->like($column, $keyword);
                    $has_search = true;
                } else {
                    $qb->or_like($column, $keyword);
                }
            }
            if ($has_search) {
                $qb->group_end();
            }
        }

        $rows = $qb->get()->result_array();
        $options = array();
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $device_id = trim((string) ($row['ont_device_id'] ?? ''));
            if ($id <= 0 || $device_id === '') {
                continue;
            }

            $name = $this->resolve_customer_name($row);
            $serial = trim((string) ($row['ont_serial'] ?? ''));
            $model = trim((string) ($row['ont_model'] ?? ''));

            $suffix = array();
            if ($serial !== '') {
                $suffix[] = 'SN: ' . $serial;
            }
            if ($model !== '') {
                $suffix[] = $model;
            }

            $label = '#' . $id . ' - ' . $name . ' (' . $device_id . ')';
            if (!empty($suffix)) {
                $label .= ' [' . implode(' | ', $suffix) . ']';
            }

            $options[] = array(
                'id' => $id,
                'label' => $label,
                'name' => $name,
                'device_id' => $device_id,
                'serial' => $serial,
                'model' => $model,
            );
        }

        return $options;
    }

    private function resolve_customer_name(array $row)
    {
        foreach (array('full_name', 'nama', 'username', 'pppoe_username', 'customer_code') as $column) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $id = (int) ($row['id'] ?? 0);
        return $id > 0 ? 'Customer #' . $id : 'Customer';
    }

    private function has_required_ont_columns()
    {
        return $this->has_customer_field('ont_device_id') && $this->has_customer_field('tr069_profile');
    }

    private function has_customer_field($column)
    {
        return in_array((string) $column, $this->customer_fields, true);
    }

    private function apply_customer_soft_delete_scope(CI_DB_query_builder $qb)
    {
        if ($this->has_customer_field('deleted_at')) {
            $qb->where('deleted_at IS NULL', null, false);
        }
        if ($this->has_customer_field('is_deleted')) {
            $qb->where('is_deleted', 0);
        }
        if ($this->has_customer_field('deleted')) {
            $qb->where('deleted', 0);
        }
    }

    private function build_summary_data()
    {
        if (!$this->db->table_exists('customers')) {
            return array(
                'total_customers' => 0,
                'total_ont_registered' => 0,
                'total_ready_remote' => 0,
                'coverage_percent' => 0.00,
                'ready_percent' => 0.00,
                'profile_breakdown' => array(),
                'model_breakdown' => array(),
            );
        }

        $total_customers = $this->count_customers_total();
        $total_ont_registered = $this->count_customers_with_ont();
        $total_ready_remote = $this->count_customers_ready_remote();

        $coverage_percent = $total_customers > 0
            ? round(($total_ont_registered / $total_customers) * 100, 2)
            : 0.00;
        $ready_percent = $total_ont_registered > 0
            ? round(($total_ready_remote / $total_ont_registered) * 100, 2)
            : 0.00;

        return array(
            'total_customers' => $total_customers,
            'total_ont_registered' => $total_ont_registered,
            'total_ready_remote' => $total_ready_remote,
            'coverage_percent' => $coverage_percent,
            'ready_percent' => $ready_percent,
            'profile_breakdown' => $this->get_profile_breakdown(),
            'model_breakdown' => $this->get_model_breakdown(),
        );
    }

    private function count_customers_total()
    {
        $qb = $this->db->from('customers');
        $this->apply_customer_soft_delete_scope($qb);
        return (int) $qb->count_all_results();
    }

    private function count_customers_with_ont()
    {
        if (!$this->has_customer_field('ont_device_id')) {
            return 0;
        }

        $qb = $this->db
            ->from('customers')
            ->where('ont_device_id !=', '');
        $this->apply_customer_soft_delete_scope($qb);
        return (int) $qb->count_all_results();
    }

    private function count_customers_ready_remote()
    {
        if (!$this->has_customer_field('ont_device_id')) {
            return 0;
        }

        $qb = $this->db
            ->from('customers')
            ->where('ont_device_id !=', '');

        if ($this->has_customer_field('tr069_profile')) {
            $qb->where('tr069_profile !=', '');
        }

        $this->apply_customer_soft_delete_scope($qb);
        return (int) $qb->count_all_results();
    }

    private function get_profile_breakdown()
    {
        if (!$this->has_customer_field('tr069_profile')) {
            return array();
        }

        $qb = $this->db
            ->select("LOWER(COALESCE(NULLIF(tr069_profile,''), 'unset')) AS profile_key, COUNT(*) AS total", false)
            ->from('customers');

        if ($this->has_customer_field('ont_device_id')) {
            $qb->where('ont_device_id !=', '');
        }

        $this->apply_customer_soft_delete_scope($qb);

        $rows = $qb
            ->group_by('profile_key')
            ->order_by('total', 'DESC')
            ->get()
            ->result_array();

        $result = array();
        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row['profile_key'] ?? 'unset')));
            if ($key === '') {
                $key = 'unset';
            }
            $result[] = array(
                'key' => $key,
                'label' => strtoupper($key),
                'total' => (int) ($row['total'] ?? 0),
            );
        }

        return $result;
    }

    private function get_model_breakdown()
    {
        if (!$this->has_customer_field('ont_model')) {
            return array();
        }

        $qb = $this->db
            ->select("COALESCE(NULLIF(ont_model,''), 'Unknown') AS model_name, COUNT(*) AS total", false)
            ->from('customers');

        if ($this->has_customer_field('ont_device_id')) {
            $qb->where('ont_device_id !=', '');
        }

        $this->apply_customer_soft_delete_scope($qb);

        $rows = $qb
            ->group_by('model_name')
            ->order_by('total', 'DESC')
            ->limit(7)
            ->get()
            ->result_array();

        $result = array();
        foreach ($rows as $row) {
            $result[] = array(
                'model' => trim((string) ($row['model_name'] ?? 'Unknown')),
                'total' => (int) ($row['total'] ?? 0),
            );
        }

        return $result;
    }

    private function json_response(array $payload, $status_code = 200)
    {
        $payload['csrf_name'] = $this->security->get_csrf_token_name();
        $payload['csrf_hash'] = $this->security->get_csrf_hash();

        return $this->output
            ->set_status_header((int) $status_code)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
    }
}
