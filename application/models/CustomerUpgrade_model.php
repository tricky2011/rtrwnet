<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class CustomerUpgrade_model extends CI_Model
{
    private $customers_fields = array();
    private $service_fields = array();
    private $ppp_profile_fields = array();
    private $invoice_fields = array();
    private $invoice_item_fields = array();
    private $history_fields = array();
    private $log_fields = array();

    public function __construct()
    {
        parent::__construct();
        $this->load->model('billing_automation_model');

        if ($this->db->table_exists('customers')) {
            $this->customers_fields = $this->db->list_fields('customers');
        }
        if ($this->db->table_exists('customer_services')) {
            $this->service_fields = $this->db->list_fields('customer_services');
        }
        if ($this->db->table_exists('ppp_profiles')) {
            $this->ppp_profile_fields = $this->db->list_fields('ppp_profiles');
        }
        if ($this->db->table_exists('invoices')) {
            $this->invoice_fields = $this->db->list_fields('invoices');
        }
        if ($this->db->table_exists('invoice_items')) {
            $this->invoice_item_fields = $this->db->list_fields('invoice_items');
        }
        if ($this->db->table_exists('customer_service_history')) {
            $this->history_fields = $this->db->list_fields('customer_service_history');
        }
        if ($this->db->table_exists('user_activity_logs')) {
            $this->log_fields = $this->db->list_fields('user_activity_logs');
        }
    }

    public function get_customer($customer_id, $router_scope_id = null)
    {
        if (!$this->db->table_exists('customers')) {
            return null;
        }

        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return null;
        }

        $qb = $this->db
            ->from('customers')
            ->where('id', $customer_id)
            ->limit(1);

        $router_scope_id = (int) $router_scope_id;
        if ($router_scope_id > 0 && in_array('router_id', $this->customers_fields, true)) {
            $qb->where('router_id', $router_scope_id);
        }

        return $qb->get()->row_array();
    }

    public function get_service_plan($plan_id, $router_scope_id = null)
    {
        if (!$this->db->table_exists('ppp_profiles')) {
            return null;
        }

        $plan_id = (int) $plan_id;
        if ($plan_id <= 0) {
            return null;
        }

        $qb = $this->db
            ->select('id, name, price')
            ->from('ppp_profiles')
            ->where('id', $plan_id)
            ->limit(1);

        foreach (array('remote_address_pool', 'ip_pool_name', 'ip_pool_range') as $column) {
            if (in_array($column, $this->ppp_profile_fields, true)) {
                $qb->select($column);
            }
        }

        if (in_array('router_id', $this->ppp_profile_fields, true)) {
            $qb->select('router_id');
            $router_scope_id = (int) $router_scope_id;
            if ($router_scope_id > 0) {
                $qb->where('router_id', $router_scope_id);
            }
        }

        return $qb->get()->row_array();
    }

    public function get_service_plan_options($router_scope_id = null)
    {
        if (!$this->db->table_exists('ppp_profiles')) {
            return array();
        }

        $qb = $this->db
            ->select('id, name, price')
            ->from('ppp_profiles');

        if (in_array('router_id', $this->ppp_profile_fields, true)) {
            $qb->select('router_id');
            $router_scope_id = (int) $router_scope_id;
            if ($router_scope_id > 0) {
                $qb->where('router_id', $router_scope_id);
            }
        }

        return $qb
            ->order_by('name', 'ASC')
            ->get()
            ->result_array();
    }

    public function get_upgrade_context($customer_id, $router_scope_id = null)
    {
        $customer = $this->get_customer($customer_id, $router_scope_id);
        if (empty($customer)) {
            return array();
        }

        $service = $this->resolve_latest_customer_service((int) $customer_id);
        $old_plan_id = $this->resolve_old_plan_id($customer, $service);
        $old_plan = $old_plan_id > 0 ? $this->get_service_plan($old_plan_id) : null;

        return array(
            'customer' => $customer,
            'service' => $service,
            'customer_service_id' => (int) ($service['id'] ?? 0),
            'old_plan_id' => $old_plan_id,
            'old_plan_name' => (string) ($old_plan['name'] ?? '-'),
            'old_price' => $this->resolve_old_price($customer, $service, $old_plan),
            'pppoe_username' => $this->resolve_pppoe_username($customer, $service),
            'router_id' => $this->resolve_router_id($customer, $service),
        );
    }

    public function update_customer_service($customer_service_id, array $payload)
    {
        if (!$this->db->table_exists('customer_services')) {
            return false;
        }

        $customer_service_id = (int) $customer_service_id;
        if ($customer_service_id <= 0) {
            return false;
        }

        $update = $this->filter_payload_by_fields($payload, $this->service_fields);
        if (empty($update)) {
            return false;
        }

        return $this->db
            ->where('id', $customer_service_id)
            ->update('customer_services', $update);
    }

    public function update_customer_plan($customer_id, array $payload)
    {
        if (!$this->db->table_exists('customers')) {
            return false;
        }

        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return false;
        }

        $update = $this->filter_payload_by_fields($payload, $this->customers_fields);
        if (empty($update)) {
            return false;
        }

        return $this->db
            ->where('id', $customer_id)
            ->update('customers', $update);
    }

    public function save_history(array $data)
    {
        if (!$this->ensure_history_table_ready()) {
            return false;
        }

        $payload = $this->filter_payload_by_fields($data, $this->history_fields);
        if (empty($payload)) {
            return false;
        }

        return $this->db->insert('customer_service_history', $payload);
    }

    public function create_prorate_invoice($customer_id, $customer_service_id, $prorate_amount, $upgrade_date, $old_plan_name, $new_plan_name, $router_id = 0)
    {
        if (!$this->db->table_exists('invoices')) {
            return array('success' => false, 'message' => 'Tabel invoices tidak tersedia.');
        }

        $customer_id = (int) $customer_id;
        $customer_service_id = (int) $customer_service_id;
        $router_id = (int) $router_id;
        $amount = (float) $prorate_amount;
        if ($customer_id <= 0 || $amount <= 0) {
            return array('success' => false, 'message' => 'Data invoice prorate tidak valid.');
        }

        $upgrade_date = $this->normalize_date($upgrade_date);
        if ($upgrade_date === '') {
            $upgrade_date = date('Y-m-d');
        }

        $period_ym = date('Y-m', strtotime($upgrade_date));
        $period_start = $period_ym . '-01';
        $period_end = date('Y-m-t', strtotime($upgrade_date));

        $payload = array(
            'invoice_number' => $this->billing_automation_model->next_invoice_number($period_ym),
            'customer_id' => $customer_id,
            'customer_service_id' => $customer_service_id > 0 ? $customer_service_id : null,
            'billing_period_start' => $period_start,
            'billing_period_end' => $period_end,
            'issue_date' => $upgrade_date,
            'due_date' => $upgrade_date,
            'subtotal' => $amount,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $amount,
            'paid_amount' => 0,
            'balance_amount' => $amount,
            'status' => 'issued',
            'notes' => 'Prorate upgrade paket dari ' . $old_plan_name . ' ke ' . $new_plan_name,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        if (in_array('invoice_type', $this->invoice_fields, true)) {
            $payload['invoice_type'] = 'upgrade';
        }
        if ($router_id > 0 && in_array('router_id', $this->invoice_fields, true)) {
            $payload['router_id'] = $router_id;
        }

        $payload = $this->filter_payload_by_fields($payload, $this->invoice_fields);

        $ok = $this->db->insert('invoices', $payload);
        if (!$ok) {
            $db_error = (array) $this->db->error();
            return array('success' => false, 'message' => 'Gagal insert invoice prorate: ' . (string) ($db_error['message'] ?? 'unknown'));
        }

        $invoice_id = (int) $this->db->insert_id();
        $this->insert_prorate_invoice_item($invoice_id, $amount, $old_plan_name, $new_plan_name);

        return array('success' => true, 'invoice_id' => $invoice_id);
    }

    public function insert_user_activity_log(array $payload)
    {
        if (!$this->db->table_exists('user_activity_logs')) {
            return false;
        }

        $insert = $this->filter_payload_by_fields($payload, $this->log_fields);
        if (empty($insert)) {
            return false;
        }

        return $this->db->insert('user_activity_logs', $insert);
    }

    public function ensure_customer_service($customer_id, $ppp_profile_id, $price, $pppoe_username = '', $router_id = 0, $install_date = '')
    {
        if (!$this->db->table_exists('customer_services')) {
            return array('success' => false, 'message' => 'Tabel customer_services tidak tersedia.');
        }
        if (!in_array('customer_id', $this->service_fields, true) || !in_array('ppp_profile_id', $this->service_fields, true)) {
            return array('success' => false, 'message' => 'Skema customer_services belum kompatibel untuk upgrade.');
        }

        $customer_id = (int) $customer_id;
        $ppp_profile_id = (int) $ppp_profile_id;
        $router_id = (int) $router_id;
        $price = (float) $price;
        $pppoe_username = trim((string) $pppoe_username);
        if ($customer_id <= 0 || $ppp_profile_id <= 0) {
            return array('success' => false, 'message' => 'Data customer/service plan tidak valid.');
        }

        $existing = $this->resolve_latest_customer_service($customer_id);
        if (!empty($existing) && !empty($existing['id'])) {
            return array(
                'success' => true,
                'customer_service_id' => (int) $existing['id'],
            );
        }

        $profile_ref = $this->resolve_customer_services_profile_reference($ppp_profile_id);
        if (empty($profile_ref['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($profile_ref['message'] ?? 'Gagal mapping profile ke customer_services.'),
            );
        }
        $service_profile_id = (int) ($profile_ref['profile_id'] ?? 0);
        if ($service_profile_id <= 0) {
            return array('success' => false, 'message' => 'Profile reference customer_services tidak valid.');
        }

        $customer = $this->get_customer($customer_id);
        $legacy_ip = is_array($customer) ? trim((string) ($customer['ip_address'] ?? '')) : '';

        $install_date = $this->normalize_date($install_date);
        if ($install_date === '') {
            $install_date = date('Y-m-d');
        }

        $now = date('Y-m-d H:i:s');
        $next_billing_date = date('Y-m-d', strtotime($install_date . ' +1 month'));
        $payload = array(
            'customer_id' => $customer_id,
            'ppp_profile_id' => $service_profile_id,
            'price' => $price,
            'service_number' => 'SVC-' . date('YmdHis') . '-' . str_pad((string) $customer_id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'pppoe_username' => $pppoe_username,
            'router_id' => $router_id > 0 ? $router_id : null,
            'ip_address' => $legacy_ip,
            'install_date' => $install_date,
            'next_billing_date' => $next_billing_date,
            'created_at' => $now,
            'updated_at' => $now,
        );

        $payload = $this->filter_payload_by_fields($payload, $this->service_fields);
        if (empty($payload)) {
            return array('success' => false, 'message' => 'Payload service kosong setelah filter field.');
        }

        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('customer_services', $payload);
        $db_error = (array) $this->db->error();
        $this->db->db_debug = $old_db_debug;

        if (!$ok || (int) ($db_error['code'] ?? 0) !== 0) {
            return array(
                'success' => false,
                'message' => 'Gagal membuat service customer: ' . (string) ($db_error['message'] ?? 'unknown'),
            );
        }

        return array(
            'success' => true,
            'customer_service_id' => (int) $this->db->insert_id(),
        );
    }

    private function resolve_latest_customer_service($customer_id)
    {
        if (!$this->db->table_exists('customer_services')) {
            return array();
        }
        if (!in_array('customer_id', $this->service_fields, true)) {
            return array();
        }

        return (array) $this->db
            ->from('customer_services')
            ->where('customer_id', (int) $customer_id)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();
    }

    private function resolve_old_plan_id(array $customer, array $service)
    {
        if (!empty($service['ppp_profile_id'])) {
            return (int) $service['ppp_profile_id'];
        }

        foreach (array('ppp_profile_id', 'profile_id', 'service_plan_id') as $column) {
            if (!empty($customer[$column])) {
                return (int) $customer[$column];
            }
        }

        return 0;
    }

    private function resolve_old_price(array $customer, array $service, $old_plan)
    {
        if (isset($service['price']) && (float) $service['price'] > 0) {
            return round((float) $service['price'], 2);
        }

        if (is_array($old_plan) && isset($old_plan['price']) && (float) $old_plan['price'] > 0) {
            return round((float) $old_plan['price'], 2);
        }

        foreach (array('price', 'package_price') as $column) {
            if (isset($customer[$column]) && (float) $customer[$column] > 0) {
                return round((float) $customer[$column], 2);
            }
        }

        return 0.00;
    }

    private function resolve_pppoe_username(array $customer, array $service)
    {
        if (!empty($service['pppoe_username'])) {
            return trim((string) $service['pppoe_username']);
        }

        foreach (array('pppoe_username', 'username') as $column) {
            if (!empty($customer[$column])) {
                return trim((string) $customer[$column]);
            }
        }

        return '';
    }

    private function resolve_router_id(array $customer, array $service)
    {
        if (!empty($service['router_id'])) {
            return (int) $service['router_id'];
        }

        if (!empty($customer['router_id'])) {
            return (int) $customer['router_id'];
        }

        return 0;
    }

    private function insert_prorate_invoice_item($invoice_id, $amount, $old_plan_name, $new_plan_name)
    {
        if (!$this->db->table_exists('invoice_items') || empty($this->invoice_item_fields)) {
            return;
        }

        $payload = array(
            'invoice_id' => (int) $invoice_id,
            'item_type' => 'other',
            'description' => 'Prorate upgrade paket dari ' . $old_plan_name . ' ke ' . $new_plan_name,
            'quantity' => 1,
            'unit_price' => (float) $amount,
            'line_total' => (float) $amount,
            'sort_order' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        );

        $payload = $this->filter_payload_by_fields($payload, $this->invoice_item_fields);
        if (empty($payload)) {
            return;
        }

        $this->db->insert('invoice_items', $payload);
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
                'message' => 'FK masih ke service_plans, tetapi tabel service_plans tidak ditemukan.',
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

        return $this->ensure_service_plan_compat_row($ppp_profile_id, $profile, $service_plan_fields);
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

        if (empty($payload)) {
            return array(
                'success' => false,
                'message' => 'Tabel service_plans tidak punya kolom yang kompatibel untuk auto-create row.',
            );
        }

        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('service_plans', $payload);
        $db_error = (array) $this->db->error();
        $this->db->db_debug = $old_db_debug;

        if (!$ok || (int) ($db_error['code'] ?? 0) !== 0) {
            return array(
                'success' => false,
                'message' => 'Gagal auto-create service_plans compatibility row: ' . (string) ($db_error['message'] ?? 'unknown'),
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

    private function ensure_history_table_ready()
    {
        if (!$this->db->table_exists('customer_service_history')) {
            $old_db_debug = $this->db->db_debug;
            $this->db->db_debug = false;
            $this->db->query("
                CREATE TABLE IF NOT EXISTS `customer_service_history` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `customer_id` BIGINT(20) UNSIGNED NOT NULL,
                    `old_plan_id` BIGINT(20) UNSIGNED NOT NULL,
                    `new_plan_id` BIGINT(20) UNSIGNED NOT NULL,
                    `upgrade_type` ENUM('upgrade','downgrade') NOT NULL DEFAULT 'upgrade',
                    `old_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    `new_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    `prorate_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    `upgrade_date` DATE NOT NULL,
                    `created_by` BIGINT(20) UNSIGNED DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_csh_customer` (`customer_id`),
                    KEY `idx_csh_upgrade_date` (`upgrade_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $db_error = (array) $this->db->error();
            $this->db->db_debug = $old_db_debug;
            if ((int) ($db_error['code'] ?? 0) !== 0) {
                log_message('error', '[CustomerUpgrade_model] create customer_service_history gagal: ' . json_encode($db_error));
                return false;
            }
        }

        if (!$this->db->table_exists('customer_service_history')) {
            return false;
        }

        $this->history_fields = $this->db->list_fields('customer_service_history');
        return !empty($this->history_fields);
    }
}
