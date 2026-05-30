<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Billing_automation_model extends CI_Model
{
    private function table_has_column($table, $column)
    {
        if (!$this->db->table_exists($table)) {
            return false;
        }

        return in_array((string) $column, $this->db->list_fields($table), true);
    }

    private function customer_username_expr($alias = 'c')
    {
        $alias = trim((string) $alias);
        if ($alias === '') {
            $alias = 'c';
        }

        if ($this->table_has_column('customers', 'pppoe_username')) {
            return "COALESCE(NULLIF({$alias}.pppoe_username,''), NULLIF({$alias}.username,''))";
        }

        if ($this->table_has_column('customers', 'username')) {
            return $alias . '.username';
        }

        return "''";
    }

    private function customer_services_has_column($column)
    {
        return $this->table_has_column('customer_services', $column);
    }

    private function invoices_has_column($column)
    {
        return $this->table_has_column('invoices', $column);
    }

    private function payments_has_column($column)
    {
        return $this->table_has_column('payments', $column);
    }

    private function count_static_customers($router_id = null)
    {
        if (!$this->db->table_exists('customers')) {
            return 0;
        }

        $fields = $this->db->list_fields('customers');
        if (empty($fields)) {
            return 0;
        }

        $router_id = $router_id === null ? null : (int) $router_id;
        if ($router_id !== null && $router_id <= 0) {
            $router_id = null;
        }

        $qb = $this->db->from('customers');
        if ($router_id !== null && in_array('router_id', $fields, true)) {
            $qb->where('router_id', $router_id);
        }

        if (in_array('connection_type', $fields, true)) {
            $qb->where("UPPER(COALESCE(connection_type, '')) = 'STATIC'", null, false);
            return (int) $qb->count_all_results();
        }

        if (in_array('service_mode', $fields, true)) {
            $qb->where("LOWER(COALESCE(service_mode, '')) = 'static'", null, false);
            return (int) $qb->count_all_results();
        }

        if (in_array('queue_name', $fields, true)) {
            $qb->where("COALESCE(queue_name, '') <> ''", null, false);
            return (int) $qb->count_all_results();
        }

        if (in_array('notes', $fields, true)) {
            $qb->like('notes', 'Auto sync STATIC', 'after');
            return (int) $qb->count_all_results();
        }

        return 0;
    }

    private function debug_database_context_before_sum()
    {
        $debug_flag = (string) $this->input->get('debug_db', true);
        if ($debug_flag !== '1') {
            return;
        }

        $prefix = (string) $this->db->dbprefix;
        $table_plain = 'invoices';
        $table_prefixed = $this->db->dbprefix('invoices');

        echo '<pre>';
        echo "=== BILLING DB DEBUG ===\n";
        echo 'Active DB: ' . $this->db->database . "\n";
        echo 'DB Prefix: ' . ($prefix === '' ? '(empty)' : $prefix) . "\n";
        echo 'Model Table (plain): ' . $table_plain . "\n";
        echo 'Model Table (dbprefix): ' . $table_prefixed . "\n";
        echo 'table_exists(invoices): ' . ($this->db->table_exists('invoices') ? 'YES' : 'NO') . "\n";
        echo 'table_exists(' . $table_prefixed . '): ' . ($this->db->table_exists($table_prefixed) ? 'YES' : 'NO') . "\n\n";

        $original_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;

        echo "-- SHOW TABLES --\n";
        $tables_query = $this->db->query('SHOW TABLES');
        if ($tables_query === false) {
            print_r($this->db->error());
        } else {
            print_r($tables_query->result_array());
        }

        echo "\n-- SHOW COLUMNS FROM invoices --\n";
        $columns_plain_query = $this->db->query('SHOW COLUMNS FROM invoices');
        if ($columns_plain_query === false) {
            print_r($this->db->error());
        } else {
            print_r($columns_plain_query->result_array());
        }

        if ($table_prefixed !== 'invoices') {
            echo "\n-- SHOW COLUMNS FROM " . $table_prefixed . " --\n";
            $columns_prefixed_query = $this->db->query('SHOW COLUMNS FROM `' . $this->db->escape_str($table_prefixed) . '`');
            if ($columns_prefixed_query === false) {
                print_r($this->db->error());
            } else {
                print_r($columns_prefixed_query->result_array());
            }
        }

        $this->db->db_debug = $original_db_debug;

        echo "\n-- Debug done before SUM queries --\n";
        echo '</pre>';
        exit;
    }

    public function validate_required_schema()
    {
        $required = array(
            'customers' => array('id', 'status'),
            'invoices' => array(
                'id', 'invoice_number', 'customer_id',
                'billing_period_start', 'billing_period_end', 'issue_date', 'due_date',
                'subtotal', 'tax_amount', 'discount_amount', 'total_amount',
                'paid_amount', 'balance_amount', 'status', 'created_at', 'updated_at',
            ),
            'payments' => array('id', 'invoice_id', 'amount', 'payment_date'),
        );

        $missing = array();
        foreach ($required as $table => $columns) {
            if (!$this->db->table_exists($table)) {
                $missing[$table] = array('__table_not_exists__');
                continue;
            }

            $exists_columns = $this->db->list_fields($table);
            $diff = array_values(array_diff($columns, $exists_columns));
            if (!empty($diff)) {
                $missing[$table] = $diff;
            }
        }

        return array(
            'ok' => empty($missing),
            'missing' => $missing,
        );
    }

    public function get_active_customers_with_profile($router_id = null)
    {
        $router_id = $router_id === null ? null : (int) $router_id;
        $billable_statuses = array('active', 'suspended', 'isolated', 'isolir');

        $qb = $this->db
            ->select('c.id as customer_id, c.*')
            ->from('customers c')
            ->where_in('LOWER(c.status)', $billable_statuses);

        $customer_fields = $this->db->table_exists('customers')
            ? $this->db->list_fields('customers')
            : array();
        $customer_has_router_id = in_array('router_id', $customer_fields, true);
        if ($customer_has_router_id) {
            $qb->select('c.router_id as customer_router_id');
        } else {
            $qb->select('0 as customer_router_id', false);
        }

        $service_has_router_id = false;
        $customer_has_profile_id = in_array('profile_id', $customer_fields, true);

        if ($this->db->table_exists('customer_services') && $this->customer_services_has_column('ppp_profile_id')) {
            $cs_table = $this->db->dbprefix('customer_services');
            $status_filter = '';
            if ($this->customer_services_has_column('status')) {
                $status_filter = "WHERE LOWER(status) IN ('active','suspended','isolated','isolir')";
            }

            $sub_latest_service = "(
                SELECT cs1.*
                FROM {$cs_table} cs1
                INNER JOIN (
                    SELECT customer_id, MAX(id) AS max_id
                    FROM {$cs_table}
                    {$status_filter}
                    GROUP BY customer_id
                ) cs2 ON cs2.max_id = cs1.id
            ) cs";

            $qb->join($sub_latest_service, 'cs.customer_id = c.id', 'left', false)
               ->select('cs.id as customer_service_id, cs.ppp_profile_id');

            if ($this->customer_services_has_column('price')) {
                $qb->select('cs.price as service_price');
            }
            if ($this->customer_services_has_column('ip_address')) {
                $qb->select('cs.ip_address as service_ip_address');
            }
            if ($this->customer_services_has_column('router_id')) {
                $service_has_router_id = true;
                $qb->select('cs.router_id as service_router_id');
            } else {
                $qb->select('0 as service_router_id', false);
            }
            if ($this->customer_services_has_column('install_date')) {
                $qb->select('cs.install_date as service_install_date');
            }
            if ($this->customer_services_has_column('pppoe_username')) {
                $qb->select('cs.pppoe_username as service_pppoe_username');
            }

            if ($this->db->table_exists('ppp_profiles')) {
                if ($customer_has_profile_id) {
                    // Fallback ke customers.profile_id untuk data legacy yang belum punya customer_services.
                    $qb->join('ppp_profiles p', 'p.id = COALESCE(cs.ppp_profile_id, c.profile_id)', 'left', false);
                } else {
                    $qb->join('ppp_profiles p', 'p.id = cs.ppp_profile_id', 'left');
                }
                $qb->select('p.name as profile_name, p.price as profile_price, p.name as isolation_profile');
            }
        } elseif (in_array('profile_id', $customer_fields, true) && $this->db->table_exists('ppp_profiles')) {
            $qb->join('ppp_profiles p', 'p.id = c.profile_id', 'left')
               ->select('p.name as profile_name, p.price as profile_price, p.name as isolation_profile')
               ->select('c.profile_id as ppp_profile_id');
        } else {
            $qb->select("'' as profile_name, 0 as profile_price, '' as isolation_profile", false);
            $qb->select('0 as service_router_id', false);
        }

        if ($router_id !== null && $router_id > 0) {
            if ($service_has_router_id && $customer_has_router_id) {
                $qb->group_start()
                    ->where('cs.router_id', $router_id)
                    ->or_group_start()
                        ->group_start()
                            ->where('cs.router_id', 0)
                            ->or_where('cs.router_id IS NULL', null, false)
                        ->group_end()
                        ->where('c.router_id', $router_id)
                    ->group_end()
                ->group_end();
            } elseif ($service_has_router_id) {
                $qb->where('cs.router_id', $router_id);
            } elseif ($customer_has_router_id) {
                $qb->where('c.router_id', $router_id);
            }
        }

        return $qb->get()->result_array();
    }

    public function invoice_exists_for_period($customer_id, $period_ym)
    {
        $period_ym = trim((string) $period_ym);
        if (!preg_match('/^\d{4}-\d{2}$/', $period_ym)) {
            return false;
        }

        $period_start = $period_ym . '-01';
        $period_end = date('Y-m-t', strtotime($period_start));
        return $this->invoice_exists_for_exact_period($customer_id, $period_start, $period_end);
    }

    public function invoice_exists_for_exact_period($customer_id, $period_start, $period_end)
    {
        return $this->db
            ->from('invoices')
            ->where('customer_id', (int) $customer_id)
            ->where('billing_period_start', (string) $period_start)
            ->where('billing_period_end', (string) $period_end)
            ->where('status !=', 'void')
            ->count_all_results() > 0;
    }

    public function next_invoice_number($period_ym)
    {
        $prefix = 'INV-' . str_replace('-', '', $period_ym) . '-';
        $row = $this->db
            ->select('invoice_number')
            ->from('invoices')
            ->like('invoice_number', $prefix, 'after')
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();

        $next = 1;
        if (!empty($row['invoice_number'])) {
            $last_number = (string) $row['invoice_number'];
            $parts = explode('-', $last_number);
            $tail = end($parts);
            if (ctype_digit((string) $tail)) {
                $next = (int) $tail + 1;
            }
        }

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function insert_invoice(array $data)
    {
        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('invoices', $data);
        $error = $this->db->error();
        $this->db->db_debug = $old_db_debug;

        if (!$ok) {
            return array(
                'success' => false,
                'id' => 0,
                'error' => $error,
            );
        }

        return array(
            'success' => true,
            'id' => (int) $this->db->insert_id(),
            'error' => null,
        );
    }

    public function get_overdue_unpaid_customers($today, $grace_days = 5)
    {
        $grace_days = max(0, (int) $grace_days);
        $threshold = date('Y-m-d', strtotime($today . ' -' . $grace_days . ' day'));
        $manual_overdue_threshold = date('Y-m-d', strtotime($today . ' -5 day'));

        $username_expr = $this->customer_username_expr('c');
        $has_customer_ip = $this->table_has_column('customers', 'ip_address');
        $customer_ip_select = $has_customer_ip ? 'c.ip_address as customer_ip_address' : "'' as customer_ip_address";

        $qb = $this->db
            ->select('c.id as customer_id, ' . $username_expr . ' as username, c.status, ' . $customer_ip_select . ', MIN(i.due_date) as oldest_due_date, SUM(i.balance_amount) as outstanding_amount', false)
            ->from('invoices i')
            ->join('customers c', 'c.id = i.customer_id', 'inner')
            ->where('i.balance_amount >', 0)
            ->where_in('LOWER(c.status)', array('active'));

        $qb->group_start()
            ->group_start()
                ->where_in('i.status', array('issued', 'partially_paid'))
                ->where('i.due_date <=', $threshold)
            ->group_end()
            ->or_group_start()
                ->where('i.status', 'overdue')
                ->like('COALESCE(i.notes, \'\')', '[MANUAL_OVERDUE]', 'both', false)
                ->where('DATE(i.updated_at) <=', $manual_overdue_threshold)
            ->group_end()
        ->group_end();

        if ($this->db->table_exists('customer_services') && $this->customer_services_has_column('ppp_profile_id')) {
            $cs_table = $this->db->dbprefix('customer_services');
            $status_filter = '';
            if ($this->customer_services_has_column('status')) {
                $status_filter = "WHERE LOWER(status) = 'active'";
            }

            $sub_latest_service = "(
                SELECT cs1.*
                FROM {$cs_table} cs1
                INNER JOIN (
                    SELECT customer_id, MAX(id) AS max_id
                    FROM {$cs_table}
                    {$status_filter}
                    GROUP BY customer_id
                ) cs2 ON cs2.max_id = cs1.id
            ) cs";

            $qb->join($sub_latest_service, 'cs.customer_id = c.id', 'left', false)
               ->select('cs.ppp_profile_id, cs.ip_address as service_ip_address');
            if ($this->customer_services_has_column('router_id')) {
                $qb->select('cs.router_id as service_router_id');
            }

            if ($this->db->table_exists('ppp_profiles')) {
                $qb->join('ppp_profiles p', 'p.id = cs.ppp_profile_id', 'left')
                   ->select('p.name as profile_name, p.name as isolation_profile');
            }
        }

        $qb->group_by('c.id, c.status, c.ip_address');

        if ($this->db->table_exists('customer_services') && $this->customer_services_has_column('ppp_profile_id')) {
            $qb->group_by('cs.ppp_profile_id, cs.ip_address');
            if ($this->customer_services_has_column('router_id')) {
                $qb->group_by('cs.router_id');
            }
            if ($this->db->table_exists('ppp_profiles')) {
                $qb->group_by('p.name');
            }
        }

        return $qb->get()->result_array();
    }

    public function mark_customer_invoices_overdue($customer_id, $today, $grace_days = 5)
    {
        $grace_days = max(0, (int) $grace_days);
        $threshold = date('Y-m-d', strtotime($today . ' -' . $grace_days . ' day'));

        $this->db
            ->set('status', 'overdue')
            ->set('updated_at', date('Y-m-d H:i:s'))
            ->where('customer_id', (int) $customer_id)
            ->where_in('status', array('issued', 'partially_paid'))
            ->where('due_date <=', $threshold)
            ->where('balance_amount >', 0)
            ->update('invoices');

        return (int) $this->db->affected_rows();
    }

    public function update_customer_status($customer_id, $status)
    {
        $payload = array('status' => (string) $status);
        if ($this->table_has_column('customers', 'updated_at')) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        $ok = $this->db
            ->where('id', (int) $customer_id)
            ->update('customers', $payload);

        return array(
            'success' => (bool) $ok,
            'error' => $ok ? null : $this->db->error(),
        );
    }

    public function get_customer_primary_ip($customer_id)
    {
        $customer_id = (int) $customer_id;

        if ($customer_id <= 0) {
            return '';
        }

        if ($this->db->table_exists('customer_services') && $this->customer_services_has_column('ip_address')) {
            $row = $this->db
                ->select('ip_address')
                ->from('customer_services')
                ->where('customer_id', $customer_id)
                ->order_by('id', 'DESC')
                ->limit(1)
                ->get()
                ->row_array();

            $service_ip = trim((string) ($row['ip_address'] ?? ''));
            if ($service_ip !== '') {
                return $service_ip;
            }
        }

        if ($this->table_has_column('customers', 'ip_address')) {
            $row = $this->db
                ->select('ip_address')
                ->from('customers')
                ->where('id', $customer_id)
                ->limit(1)
                ->get()
                ->row_array();

            return trim((string) ($row['ip_address'] ?? ''));
        }

        return '';
    }

    public function get_customer_current_pppoe_username($customer_id)
    {
        $customer_id = (int) $customer_id;

        if ($customer_id <= 0) {
            return '';
        }

        if ($this->db->table_exists('customer_services') && $this->customer_services_has_column('pppoe_username')) {
            $row = $this->db
                ->select('pppoe_username')
                ->from('customer_services')
                ->where('customer_id', $customer_id)
                ->order_by('id', 'DESC')
                ->limit(1)
                ->get()
                ->row_array();

            $service_username = trim((string) ($row['pppoe_username'] ?? ''));
            if ($service_username !== '') {
                return $service_username;
            }
        }

        $selects = array();
        if ($this->table_has_column('customers', 'pppoe_username')) {
            $selects[] = 'pppoe_username';
        }
        if ($this->table_has_column('customers', 'username')) {
            $selects[] = 'username';
        }

        if (empty($selects)) {
            return '';
        }

        $row = $this->db
            ->select(implode(',', $selects))
            ->from('customers')
            ->where('id', $customer_id)
            ->limit(1)
            ->get()
            ->row_array();

        $pppoe_username = trim((string) ($row['pppoe_username'] ?? ''));
        if ($pppoe_username !== '') {
            return $pppoe_username;
        }

        return trim((string) ($row['username'] ?? ''));
    }

    public function get_invoice_with_customer_profile($invoice_id)
    {
        $invoice_id = (int) $invoice_id;
        if ($invoice_id <= 0) {
            return array();
        }

        $username_expr = $this->customer_username_expr('c');
        $customer_profile_select = $this->table_has_column('customers', 'profile_id')
            ? 'c.profile_id as customer_profile_id'
            : 'NULL as customer_profile_id';
        $customer_code_select = $this->table_has_column('customers', 'customer_code')
            ? 'c.customer_code as customer_code'
            : "'' as customer_code";
        $customer_pppoe_select = $this->table_has_column('customers', 'pppoe_username')
            ? 'c.pppoe_username as customer_pppoe_username'
            : "'' as customer_pppoe_username";
        $customer_ip_select = $this->table_has_column('customers', 'ip_address')
            ? 'c.ip_address as customer_ip_address'
            : "'' as customer_ip_address";
        $customer_connection_type_select = $this->table_has_column('customers', 'connection_type')
            ? 'c.connection_type as customer_connection_type'
            : "'' as customer_connection_type";
        $customer_full_name_select = $this->table_has_column('customers', 'full_name')
            ? 'c.full_name as customer_full_name'
            : "'' as customer_full_name";
        $customer_notes_select = $this->table_has_column('customers', 'notes')
            ? 'c.notes as customer_notes'
            : "'' as customer_notes";
        $invoice = $this->db
            ->select('i.*, c.id as customer_id, c.status as customer_status, ' . $customer_profile_select . ', ' . $customer_code_select . ', ' . $username_expr . ' as username, ' . $customer_pppoe_select . ', ' . $customer_ip_select . ', ' . $customer_connection_type_select . ', ' . $customer_full_name_select . ', ' . $customer_notes_select, false)
            ->from('invoices i')
            ->join('customers c', 'c.id = i.customer_id', 'inner')
            ->where('i.id', $invoice_id)
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($invoice)) {
            return array();
        }

        $service = array();
        if ($this->db->table_exists('customer_services')) {
            if (!empty($invoice['customer_service_id'])) {
                $service = $this->db
                    ->from('customer_services')
                    ->where('id', (int) $invoice['customer_service_id'])
                    ->limit(1)
                    ->get()
                    ->row_array();
            }

            if (empty($service)) {
                $service = $this->db
                    ->from('customer_services')
                    ->where('customer_id', (int) $invoice['customer_id'])
                    ->order_by('id', 'DESC')
                    ->limit(1)
                    ->get()
                    ->row_array();
            }
        }

        $profile_id = 0;
        if (!empty($service['ppp_profile_id'])) {
            $profile_id = (int) $service['ppp_profile_id'];
        } elseif (!empty($invoice['customer_profile_id'])) {
            $profile_id = (int) $invoice['customer_profile_id'];
        }

        $profile_name = '';
        if ($profile_id > 0 && $this->db->table_exists('ppp_profiles')) {
            $profile = $this->db
                ->select('name')
                ->from('ppp_profiles')
                ->where('id', $profile_id)
                ->limit(1)
                ->get()
                ->row_array();

            $profile_name = trim((string) ($profile['name'] ?? ''));
        }

        $invoice['profile_id'] = $profile_id > 0 ? $profile_id : null;
        $invoice['profile_name'] = $profile_name;
        $invoice['isolation_profile'] = $profile_name;

        if (!empty($service['pppoe_username']) && trim((string) $service['pppoe_username']) !== '') {
            $invoice['username'] = trim((string) $service['pppoe_username']);
        } elseif (!empty($invoice['customer_pppoe_username'])) {
            $invoice['username'] = trim((string) $invoice['customer_pppoe_username']);
        }

        $invoice['service_router_id'] = 0;
        if (!empty($service['router_id'])) {
            $invoice['service_router_id'] = (int) $service['router_id'];
        } elseif ($this->invoices_has_column('router_id')) {
            $invoice['service_router_id'] = (int) ($invoice['router_id'] ?? 0);
        }

        $invoice['service_ip_address'] = '';
        if (!empty($service['ip_address'])) {
            $invoice['service_ip_address'] = trim((string) $service['ip_address']);
        }

        return $invoice;
    }

    public function get_customer_router_id($customer_id = 0, $username = '')
    {
        $customer_id = (int) $customer_id;
        $username = trim((string) $username);

        if ($this->db->table_exists('customer_services') && $this->customer_services_has_column('router_id')) {
            if ($customer_id > 0 && $this->customer_services_has_column('customer_id')) {
                $row = $this->db
                    ->select('router_id')
                    ->from('customer_services')
                    ->where('customer_id', $customer_id)
                    ->order_by('id', 'DESC')
                    ->limit(1)
                    ->get()
                    ->row_array();
                $router_id = (int) ($row['router_id'] ?? 0);
                if ($router_id > 0) {
                    return $router_id;
                }
            }

            if ($username !== '' && $this->customer_services_has_column('pppoe_username')) {
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

        if ($customer_id > 0 && $this->table_has_column('customers', 'router_id')) {
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

        if ($this->db->table_exists('routers')) {
            $qb = $this->db->select('id')->from('routers');
            $router_fields = $this->db->list_fields('routers');
            if (in_array('is_active', $router_fields, true)) {
                $qb->where('is_active', 1);
            } elseif (in_array('status', $router_fields, true)) {
                $qb->where('status', 'active');
            }
            $rows = $qb->order_by('id', 'ASC')->get()->result_array();
            if (count($rows) === 1) {
                return (int) ($rows[0]['id'] ?? 0);
            }
        }

        return 0;
    }

    public function update_invoice_payment($invoice_id, $paid_amount, $balance_amount, $status, $paid_date = null)
    {
        $payload = array(
            'paid_amount' => (float) $paid_amount,
            'balance_amount' => (float) $balance_amount,
            'status' => (string) $status,
            'updated_at' => date('Y-m-d H:i:s'),
        );

        if ($paid_date !== null && $paid_date !== '' && $this->invoices_has_column('paid_date')) {
            $payload['paid_date'] = (string) $paid_date;
        }

        $ok = $this->db
            ->where('id', (int) $invoice_id)
            ->update('invoices', $payload);

        return array(
            'success' => (bool) $ok,
            'error' => $ok ? null : $this->db->error(),
        );
    }

    public function customer_has_unpaid_invoice_balance($customer_id)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0 || !$this->db->table_exists('invoices')) {
            return false;
        }

        $invoice_fields = $this->db->list_fields('invoices');
        $qb = $this->db
            ->from('invoices')
            ->where('customer_id', $customer_id);

        if (in_array('status', $invoice_fields, true)) {
            $qb->where("LOWER(COALESCE(status, '')) NOT IN ('paid','void','cancelled','canceled')", null, false);
        }

        if (in_array('balance_amount', $invoice_fields, true)) {
            $qb->where('balance_amount >', 0);
        } elseif (in_array('total_amount', $invoice_fields, true) && in_array('paid_amount', $invoice_fields, true)) {
            $qb->where('total_amount > paid_amount', null, false);
        } elseif (in_array('amount', $invoice_fields, true) && in_array('paid_amount', $invoice_fields, true)) {
            $qb->where('amount > paid_amount', null, false);
        } elseif (in_array('total_amount', $invoice_fields, true)) {
            $qb->where('total_amount >', 0);
        } elseif (in_array('amount', $invoice_fields, true)) {
            $qb->where('amount >', 0);
        }

        return $qb->count_all_results() > 0;
    }

    public function customer_has_isolir_blocking_invoice($customer_id, $today = null, $grace_days = 5)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0 || !$this->db->table_exists('invoices')) {
            return false;
        }

        $today = $today ? (string) $today : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            $today = date('Y-m-d');
        }
        $grace_days = max(0, (int) $grace_days);
        $cutoff = date('Y-m-d', strtotime($today . ' -' . $grace_days . ' day'));

        $invoice_fields = $this->db->list_fields('invoices');
        $has_status = in_array('status', $invoice_fields, true);
        $has_due_date = in_array('due_date', $invoice_fields, true);

        $qb = $this->db
            ->from('invoices')
            ->where('customer_id', $customer_id);

        if (in_array('balance_amount', $invoice_fields, true)) {
            $qb->where('balance_amount >', 0);
        } elseif (in_array('total_amount', $invoice_fields, true) && in_array('paid_amount', $invoice_fields, true)) {
            $qb->where('total_amount > paid_amount', null, false);
        } elseif (in_array('amount', $invoice_fields, true) && in_array('paid_amount', $invoice_fields, true)) {
            $qb->where('amount > paid_amount', null, false);
        }

        if ($has_status && $has_due_date) {
            $qb->group_start()
                ->where_in('LOWER(status)', array('overdue'))
                ->or_group_start()
                    ->where_in('LOWER(status)', array('issued', 'partially_paid', 'unpaid', 'pending'))
                    ->where('due_date <=', $cutoff)
                ->group_end()
            ->group_end();
        } elseif ($has_status) {
            $qb->where_in('LOWER(status)', array('overdue'));
        } elseif ($has_due_date) {
            $qb->where('due_date <=', $cutoff);
        }

        return $qb->count_all_results() > 0;
    }

    private function sanitize_payment_method($method)
    {
        $method = strtolower(trim((string) $method));
        $allowed = array('cash', 'bank_transfer', 'ewallet', 'gateway', 'other');

        if ($method === 'transfer') {
            $method = 'bank_transfer';
        }

        if (!in_array($method, $allowed, true)) {
            $method = 'other';
        }

        return $method;
    }

    public function next_payment_number($date_ymd = null)
    {
        $date_ymd = $date_ymd ? date('Ymd', strtotime($date_ymd)) : date('Ymd');
        $prefix = 'PAY-' . $date_ymd . '-';

        $row = $this->db
            ->select('payment_number')
            ->from('payments')
            ->like('payment_number', $prefix, 'after')
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();

        $next = 1;
        if (!empty($row['payment_number'])) {
            $parts = explode('-', (string) $row['payment_number']);
            $tail = end($parts);
            if (ctype_digit((string) $tail)) {
                $next = (int) $tail + 1;
            }
        }

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function insert_payment(array $data)
    {
        $payment_date = (string) ($data['payment_date'] ?? date('Y-m-d H:i:s'));
        $invoice_id = (int) ($data['invoice_id'] ?? 0);
        $customer_id = (int) ($data['customer_id'] ?? 0);
        $router_id = (int) ($data['router_id'] ?? 0);

        if ($customer_id <= 0 && $invoice_id > 0) {
            $invoice = $this->db
                ->select('customer_id')
                ->from('invoices')
                ->where('id', $invoice_id)
                ->limit(1)
                ->get()
                ->row_array();
            $customer_id = (int) ($invoice['customer_id'] ?? 0);
        }

        if ($router_id <= 0 && $invoice_id > 0 && $this->invoices_has_column('router_id')) {
            $invoice_router = (array) $this->db
                ->select('router_id')
                ->from('invoices')
                ->where('id', $invoice_id)
                ->limit(1)
                ->get()
                ->row_array();
            $router_id = (int) ($invoice_router['router_id'] ?? 0);
        }

        if ($router_id <= 0) {
            $router_id = (int) $this->get_customer_router_id($customer_id, '');
        }

        $payload = array(
            'invoice_id' => $invoice_id,
            'customer_id' => $customer_id,
            'payment_date' => $payment_date,
            'amount' => (float) ($data['amount'] ?? 0),
            'method' => $this->sanitize_payment_method((string) ($data['method'] ?? 'other')),
        );

        if ($this->payments_has_column('payment_number')) {
            $payload['payment_number'] = (string) ($data['payment_number'] ?? $this->next_payment_number($payment_date));
        }
        if ($this->payments_has_column('status')) {
            $payload['status'] = (string) ($data['status'] ?? 'confirmed');
        }
        if ($this->payments_has_column('reference_no') && array_key_exists('reference_no', $data)) {
            $payload['reference_no'] = $data['reference_no'];
        }
        if ($this->payments_has_column('received_by') && array_key_exists('received_by', $data)) {
            $payload['received_by'] = $data['received_by'] !== null ? (int) $data['received_by'] : null;
        }
        if ($this->payments_has_column('notes') && array_key_exists('notes', $data)) {
            $payload['notes'] = $data['notes'];
        }
        if ($this->payments_has_column('router_id')) {
            if ($router_id <= 0) {
                return array(
                    'success' => false,
                    'id' => 0,
                    'error' => array('code' => 0, 'message' => 'router_id payment tidak valid'),
                    'payload' => $payload,
                );
            }
            $payload['router_id'] = $router_id;
        }
        if ($this->payments_has_column('created_at')) {
            $payload['created_at'] = (string) ($data['created_at'] ?? date('Y-m-d H:i:s'));
        }
        if ($this->payments_has_column('updated_at')) {
            $payload['updated_at'] = (string) ($data['updated_at'] ?? date('Y-m-d H:i:s'));
        }

        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('payments', $payload);
        $error = $this->db->error();
        $this->db->db_debug = $old_db_debug;

        return array(
            'success' => (bool) $ok,
            'id' => $ok ? (int) $this->db->insert_id() : 0,
            'error' => $ok ? null : $error,
            'payload' => $payload,
        );
    }

    private function resolve_cashflow_actor_user_id($created_by = null)
    {
        if ($created_by !== null && (int) $created_by > 0) {
            return (int) $created_by;
        }

        $ci =& get_instance();
        if (isset($ci->session) && is_object($ci->session) && method_exists($ci->session, 'userdata')) {
            $session_user_id = (int) $ci->session->userdata('user_id');
            if ($session_user_id > 0) {
                return $session_user_id;
            }
        }

        if ($this->db->table_exists('users')) {
            $user = $this->db
                ->select('id')
                ->from('users')
                ->where('status', 'active')
                ->order_by('id', 'ASC')
                ->limit(1)
                ->get()
                ->row_array();

            if (!empty($user['id'])) {
                return (int) $user['id'];
            }
        }

        return 0;
    }

    private function ensure_cashflow_income_category_id()
    {
        if (!$this->db->table_exists('cashflow_categories')) {
            return 0;
        }

        $existing = $this->db
            ->select('id')
            ->from('cashflow_categories')
            ->where('type', 'income')
            ->group_start()
                ->where('category_code', 'subscription')
                ->or_where('category_name', 'Subscription')
            ->group_end()
            ->limit(1)
            ->get()
            ->row_array();

        if (!empty($existing['id'])) {
            return (int) $existing['id'];
        }

        $payload = array(
            'category_code' => 'subscription',
            'category_name' => 'Subscription',
            'type' => 'income',
            'is_active' => 1,
        );

        if ($this->table_has_column('cashflow_categories', 'created_at')) {
            $payload['created_at'] = date('Y-m-d H:i:s');
        }
        if ($this->table_has_column('cashflow_categories', 'updated_at')) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        $ok = $this->db->insert('cashflow_categories', $payload);
        if (!$ok) {
            log_message('error', '[Billing_automation_model] gagal create cashflow category subscription: ' . json_encode($this->db->error()));
            return 0;
        }

        return (int) $this->db->insert_id();
    }

    private function next_cashflow_txn_number($txn_date)
    {
        $prefix = 'CF-' . date('Ymd', strtotime($txn_date)) . '-';
        $row = $this->db
            ->select('txn_number')
            ->from('cashflow_transactions')
            ->like('txn_number', $prefix, 'after')
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();

        $next = 1;
        if (!empty($row['txn_number'])) {
            $parts = explode('-', (string) $row['txn_number']);
            $tail = end($parts);
            if (ctype_digit((string) $tail)) {
                $next = (int) $tail + 1;
            }
        }

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function insert_cashflow_income_once($invoice_id, $customer_id, $payment_id, $amount, $payment_date, $description = '', $created_by = null, $router_id = 0)
    {
        if (!$this->db->table_exists('cashflow_transactions')) {
            return array('success' => true, 'skipped' => true, 'message' => 'cashflow_transactions tidak tersedia');
        }

        $invoice_id = (int) $invoice_id;
        $customer_id = (int) $customer_id;
        $payment_id = (int) $payment_id;
        $amount = (float) $amount;
        $router_id = (int) $router_id;

        if ($amount <= 0) {
            return array('success' => false, 'skipped' => true, 'message' => 'amount tidak valid');
        }

        if ($payment_id > 0) {
            $exists_by_payment = $this->db
                ->from('cashflow_transactions')
                ->where('payment_id', $payment_id)
                ->count_all_results();
            if ($exists_by_payment > 0) {
                return array('success' => true, 'skipped' => true, 'message' => 'cashflow payment sudah ada');
            }
        }

        if ($invoice_id > 0) {
            $exists_by_invoice = $this->db
                ->from('cashflow_transactions')
                ->where('invoice_id', $invoice_id)
                ->where('type', 'income')
                ->count_all_results();
            if ($exists_by_invoice > 0) {
                return array('success' => true, 'skipped' => true, 'message' => 'cashflow invoice sudah ada');
            }
        }

        $category_id = $this->ensure_cashflow_income_category_id();
        if ($category_id <= 0) {
            return array('success' => false, 'skipped' => false, 'message' => 'category income tidak tersedia');
        }

        $actor_user_id = $this->resolve_cashflow_actor_user_id($created_by);
        if ($actor_user_id <= 0) {
            return array('success' => false, 'skipped' => false, 'message' => 'user actor cashflow tidak tersedia');
        }

        $txn_date = date('Y-m-d H:i:s', strtotime($payment_date));
        $payload = array(
            'txn_number' => $this->next_cashflow_txn_number($txn_date),
            'txn_date' => $txn_date,
            'type' => 'income',
            'category_id' => $category_id,
            'amount' => $amount,
            'description' => $description !== '' ? $description : ('Pembayaran invoice #' . $invoice_id),
            'customer_id' => $customer_id > 0 ? $customer_id : null,
            'invoice_id' => $invoice_id > 0 ? $invoice_id : null,
            'payment_id' => $payment_id > 0 ? $payment_id : null,
            'created_by' => $actor_user_id,
        );

        if ($this->table_has_column('cashflow_transactions', 'router_id')) {
            $resolved_router_id = $router_id;
            if ($resolved_router_id <= 0 && $invoice_id > 0 && $this->invoices_has_column('router_id')) {
                $invoice_row = $this->db
                    ->select('router_id')
                    ->from('invoices')
                    ->where('id', $invoice_id)
                    ->limit(1)
                    ->get()
                    ->row_array();
                $resolved_router_id = (int) ($invoice_row['router_id'] ?? 0);
            }

            if ($resolved_router_id <= 0) {
                $resolved_router_id = (int) $this->get_customer_router_id($customer_id, '');
            }
            if ($resolved_router_id <= 0 && $payment_id > 0 && $this->payments_has_column('router_id')) {
                $payment_row = $this->db
                    ->select('router_id')
                    ->from('payments')
                    ->where('id', $payment_id)
                    ->limit(1)
                    ->get()
                    ->row_array();
                $resolved_router_id = (int) ($payment_row['router_id'] ?? 0);
            }

            if ($resolved_router_id <= 0) {
                return array(
                    'success' => false,
                    'skipped' => false,
                    'message' => 'router_id cashflow tidak valid',
                    'error' => array('code' => 0, 'message' => 'router_id tidak ditemukan untuk transaksi income'),
                );
            }

            $payload['router_id'] = $resolved_router_id;
        }

        if ($this->table_has_column('cashflow_transactions', 'created_at')) {
            $payload['created_at'] = date('Y-m-d H:i:s');
        }
        if ($this->table_has_column('cashflow_transactions', 'updated_at')) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }

        $old_db_debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $ok = $this->db->insert('cashflow_transactions', $payload);
        $error = $this->db->error();
        $this->db->db_debug = $old_db_debug;

        if (!$ok) {
            return array(
                'success' => false,
                'skipped' => false,
                'message' => 'Insert cashflow gagal',
                'error' => $error,
                'payload' => $payload,
            );
        }

        return array(
            'success' => true,
            'skipped' => false,
            'id' => (int) $this->db->insert_id(),
            'message' => 'Cashflow income tercatat',
        );
    }

    public function get_dashboard_metrics($router_id = null)
    {
        $this->debug_database_context_before_sum();

        $router_id = (int) $router_id;
        if ($router_id <= 0) {
            $router_id = null;
        }

        $total_customer = 0;
        $active_customer = 0;
        $suspended_customer = 0;
        $static_customer = $this->count_static_customers($router_id);

        $has_customers = $this->db->table_exists('customers');
        $customers_has_router_id = $has_customers && $this->table_has_column('customers', 'router_id');
        $customers_has_status = $has_customers && $this->table_has_column('customers', 'status');

        // Prioritas hitung dari customers.router_id agar konsisten dengan menu Customers.
        if ($router_id !== null && $customers_has_router_id) {
            $total_customer = (int) $this->db
                ->from('customers')
                ->where('router_id', $router_id)
                ->count_all_results();

            if ($customers_has_status) {
                $active_customer = (int) $this->db
                    ->from('customers')
                    ->where('router_id', $router_id)
                    ->where_in('LOWER(status)', array('active', 'actived'))
                    ->count_all_results();

                $suspended_customer = (int) $this->db
                    ->from('customers')
                    ->where('router_id', $router_id)
                    ->where_in('LOWER(status)', array('suspended', 'isolated', 'isolir'))
                    ->count_all_results();
            }
        } elseif ($has_customers) {
            $total_customer = (int) $this->db->count_all('customers');
            if ($customers_has_status) {
                $active_customer = (int) $this->db
                    ->from('customers')
                    ->where_in('LOWER(status)', array('active', 'actived'))
                    ->count_all_results();
                $suspended_customer = (int) $this->db
                    ->from('customers')
                    ->where_in('LOWER(status)', array('suspended', 'isolated', 'isolir'))
                    ->count_all_results();
            }
        } else {
            // Fallback legacy jika tabel customers tidak tersedia.
            $can_scope_customer_by_router = $router_id !== null
                && $this->db->table_exists('customer_services')
                && $this->customer_services_has_column('customer_id')
                && $this->customer_services_has_column('router_id');
            if ($can_scope_customer_by_router) {
                $total_customer_row = $this->db
                    ->select('COUNT(DISTINCT cs.customer_id) AS total_customer', false)
                    ->from('customer_services cs')
                    ->where('cs.router_id', $router_id)
                    ->get()
                    ->row_array();
                $total_customer = (int) ($total_customer_row['total_customer'] ?? 0);
            } elseif ($this->db->table_exists('customer_services')) {
                $total_customer = (int) $this->db
                    ->from('customer_services')
                    ->count_all_results();
            }
        }

        $can_scope_invoice_by_router = $router_id !== null && $this->invoices_has_column('router_id');

        $qb_outstanding = $this->db
            ->select_sum('balance_amount', 'total_outstanding')
            ->from('invoices')
            ->where_in('status', array('issued', 'overdue', 'partially_paid'))
            ->where('balance_amount >', 0);
        if ($can_scope_invoice_by_router) {
            $qb_outstanding->where('router_id', $router_id);
        }
        $row_outstanding = $qb_outstanding->get()->row_array();

        $qb_total_revenue = $this->db
            ->select_sum('total_amount', 'total_revenue')
            ->from('invoices');
        if ($can_scope_invoice_by_router) {
            $qb_total_revenue->where('router_id', $router_id);
        }
        $row_total_revenue = $qb_total_revenue->get()->row_array();

        $qb_total_paid = $this->db
            ->select_sum('paid_amount', 'total_paid')
            ->from('invoices');
        if ($can_scope_invoice_by_router) {
            $qb_total_paid->where('router_id', $router_id);
        }
        $row_total_paid = $qb_total_paid->get()->row_array();

        $month_start = date('Y-m-01 00:00:00');
        $next_month_start = date('Y-m-01 00:00:00', strtotime('+1 month', strtotime($month_start)));

        $income_month = 0.0;

        if ($this->db->table_exists('payments')) {
            $sub = $this->db
                ->select('1', false)
                ->from('payments p')
                ->where('p.invoice_id = i.id', null, false)
                ->where('p.payment_date >=', $month_start)
                ->where('p.payment_date <', $next_month_start);

            if ($this->payments_has_column('status')) {
                $sub->where('p.status', 'confirmed');
            }

            $compiled_exists = $sub->get_compiled_select();

            $row_paid_month = $this->db
                ->select_sum('i.total_amount', 'total_paid_month')
                ->from('invoices i')
                ->where('i.status', 'paid')
                ->where('EXISTS (' . $compiled_exists . ')', null, false);
            if ($can_scope_invoice_by_router) {
                $row_paid_month = $row_paid_month->where('i.router_id', $router_id);
            }
            $row_paid_month = $row_paid_month->get()->row_array();

            $income_month = (float) ($row_paid_month['total_paid_month'] ?? 0);
        } else {
            $row_paid_month = $this->db
                ->select_sum('total_amount', 'total_paid_month')
                ->from('invoices')
                ->where('status', 'paid')
                ->where('updated_at >=', $month_start)
                ->where('updated_at <', $next_month_start);
            if ($can_scope_invoice_by_router) {
                $row_paid_month = $row_paid_month->where('router_id', $router_id);
            }
            $row_paid_month = $row_paid_month->get()->row_array();

            $income_month = (float) ($row_paid_month['total_paid_month'] ?? 0);
        }

        $profit_month = $income_month;
        $expense_month = 0.0;

        if ($this->db->table_exists('cashflow_transactions')) {
            $qb_cashflow_month = $this->db
                ->select("COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END), 0) AS total_income, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS total_expense", false)
                ->from('cashflow_transactions')
                ->where('txn_date >=', $month_start)
                ->where('txn_date <', $next_month_start);
            if ($router_id !== null && $this->table_has_column('cashflow_transactions', 'router_id')) {
                $qb_cashflow_month->where('router_id', $router_id);
            }
            $row_cashflow_month = $qb_cashflow_month->get()->row_array();

            $income_month = (float) ($row_cashflow_month['total_income'] ?? 0);
            $expense_month = (float) ($row_cashflow_month['total_expense'] ?? 0);
            $profit_month = $income_month - $expense_month;
        }

        return array(
            'total_customer' => $total_customer,
            'active_customer' => $active_customer,
            'suspended_customer' => $suspended_customer,
            'static_customer' => $static_customer,
            'total_isolir' => $suspended_customer,
            'total_unpaid' => (float) ($row_outstanding['total_outstanding'] ?? 0),
            'total_outstanding' => (float) ($row_outstanding['total_outstanding'] ?? 0),
            'income_month' => round($income_month, 2),
            'expense_month' => round($expense_month, 2),
            'profit_month' => round($profit_month, 2),
            'total_revenue' => (float) ($row_total_revenue['total_revenue'] ?? 0),
            'total_paid' => (float) ($row_total_paid['total_paid'] ?? 0),
        );
    }

    public function get_revenue_monthly_series($start_date, $end_date)
    {
        if (!$this->db->table_exists('payments')) {
            return array();
        }

        $sub = $this->db
            ->select('invoice_id, MAX(payment_date) AS paid_at', false)
            ->from('payments');

        if ($this->payments_has_column('status')) {
            $sub->where('status', 'confirmed');
        }

        $sub->group_by('invoice_id');
        $paid_sub = $sub->get_compiled_select();

        return $this->db
            ->select("DATE_FORMAT(pd.paid_at, '%Y-%m') AS month_key, COALESCE(SUM(i.total_amount), 0) AS revenue", false)
            ->from('invoices i')
            ->join('(' . $paid_sub . ') pd', 'pd.invoice_id = i.id', 'inner', false)
            ->where('i.status', 'paid')
            ->where('DATE(pd.paid_at) >=', $start_date)
            ->where('DATE(pd.paid_at) <=', $end_date)
            ->group_by("DATE_FORMAT(pd.paid_at, '%Y-%m')", false)
            ->order_by('month_key', 'ASC')
            ->get()
            ->result_array();
    }

    public function get_income_expense_monthly_series($start_date, $end_date)
    {
        if (!$this->db->table_exists('cashflow_transactions')) {
            return array();
        }

        return $this->db
            ->select("DATE_FORMAT(txn_date, '%Y-%m') AS month_key, COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END), 0) AS total_income, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS total_expense", false)
            ->from('cashflow_transactions')
            ->where('DATE(txn_date) >=', $start_date)
            ->where('DATE(txn_date) <=', $end_date)
            ->group_by("DATE_FORMAT(txn_date, '%Y-%m')", false)
            ->order_by('month_key', 'ASC')
            ->get()
            ->result_array();
    }
}
