<?php
/** Integration checks use connection-local TEMPORARY tables; no real messages. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
chdir(dirname(__DIR__, 2));
$_SERVER['argv'] = array('index.php', 'cron/Offline_collection_cron/preview', '2026-11-10');
ob_start();
require 'index.php';
ob_end_clean();
$ci =& get_instance();
$db = $ci->db;
$db->db_debug = false;
$checks = 0;
function check($condition, $label)
{
    global $checks;
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $label . PHP_EOL);
        throw new RuntimeException($label);
    }
    $checks++;
}
function insert_fixture($table, array $data)
{
    global $db;
    if (!$db->insert($table, $data)) {
        throw new RuntimeException('Fixture ' . $table . ': ' . ($db->error()['message'] ?? 'unknown'));
    }
    return (int) $db->insert_id();
}
$router_template = $db->limit(1)->get('routers')->row_array();
check(!empty($router_template), 'An installed router is required');
$temporary_tables = array('routers', 'customers', 'invoices', 'customer_services', 'ppp_profiles', 'pppoe_secrets', 'telegram_groups', 'telegram_bots', 'offline_collection_deliveries');
foreach ($temporary_tables as $table) {
    $definition = $db->query("SHOW CREATE TABLE `{$table}`")->row_array()['Create Table'];
    $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
    $definition = preg_replace('/^\s*CONSTRAINT[^\n]*FOREIGN KEY[^\n]*\n/m', '', $definition);
    $definition = preg_replace('/,\n\)/', "\n)", $definition);
    $created = $db->query($definition);
    check((bool) $created, 'Temporary table ' . $table . ($created ? '' : ': ' . ($db->error()['message'] ?? 'unknown')));
}
try {
    require_once BASEPATH . 'libraries/Migration.php';
    require_once APPPATH . 'migrations/20261023090000_customer_monthly_price_override.php';
    $price_migration = new Migration_Customer_monthly_price_override();
    $price_migration->up();
    $price_migration->up();
    check($db->field_exists('monthly_price_override', 'customers'), 'Customer pricing migration is idempotent on temporary table');
    foreach (array(901, 902) as $id) {
        $router = $router_template;
        $router['id'] = $id;
        $router['name'] = 'Test Router ' . $id;
        $router['is_active'] = 1;
        insert_fixture('routers', $router);
    }
    $ci->load->library(array('billing_cycle', 'billing_automation_service'));
    $cycle = $ci->billing_cycle;
    check($cycle->resolve(array(), '2026-02')['end'] === '2026-02-28', 'Monthly compatibility');
    $pref = array('billing_interval_months' => 2, 'billing_cycle_start' => '2026-11-01');
    check($cycle->resolve($pref, '2026-11')['end'] === '2026-12-31', 'Two-month coverage');
    check($cycle->resolve($pref, '2026-12') === null, 'Skip intervening month');
    check($cycle->resolve($pref, '2026-10') === null, 'Skip before anchor');
    check($cycle->resolve($pref, '2027-01')['end'] === '2027-02-28', 'Year transition');
    check($cycle->resolve(array('billing_interval_months' => 2, 'billing_cycle_start' => '2028-01-01'), '2028-01')['end'] === '2028-02-29', 'Leap year');
    foreach (array(array('billing_interval_months' => 2), array('billing_interval_months' => 3)) as $invalid) {
        $rejected = false;
        try { $cycle->resolve($invalid, '2026-11'); } catch (InvalidArgumentException $e) { $rejected = true; }
        check($rejected, 'Reject invalid cycle settings');
    }
    $invalid = false;
    try { $cycle->resolve(array(), '2026-13'); } catch (InvalidArgumentException $e) { $invalid = true; }
    check($invalid, 'Reject invalid calendar month');

    class Fixture_billing_model extends Billing_automation_model
    {
        public $fixtures = array();
        public function get_active_customers_with_profile($router_id = null) { return $this->fixtures; }
    }
    $model = new Fixture_billing_model();
    $ci->billing_automation_model = $model;
    // Prevent generation tests from touching WhatsApp logs or network services.
    $ci->whatsapp_log_model = new class { public function table_ready() { return false; } };
    $prefs = array('cash_visit', 'transfer', 'cash_visit', 'cash_visit', 'cash_visit', 'unspecified');
    foreach ($prefs as $index => $method) {
        $id = 9001 + $index;
        insert_fixture('customers', array('id' => $id, 'customer_code' => 'TEST-' . $id, 'full_name' => 'Customer ' . $id,
            'phone' => '0800000000', 'address' => 'Test address', 'username' => 'test-' . $id,
            'router_id' => $id === 9004 ? 902 : 901, 'status' => $id === 9005 ? 'terminated' : 'active',
            'payment_collection_method' => $method, 'billing_interval_months' => $id === 9001 ? 2 : 1,
            'billing_cycle_start' => $id === 9001 ? '2026-11-01' : null));
    }
    $base = array('service_router_id' => 901, 'customer_router_id' => 901, 'service_price' => 150000,
        'install_date' => '2026-11-01', 'due_date_day' => 20);
    $model->fixtures = array(array_merge($base, $pref, array('customer_id' => 9001)), array_merge($base, array('customer_id' => 9002)));
    $generated = $ci->billing_automation_service->generate_monthly_invoices('2026-11', 901);
    check($generated['stats']['total_created'] === 2 && $generated['stats']['total_failed'] === 0, 'Generate monthly and bimonthly invoices');
    $invoice = $db->where('customer_id', 9001)->get('invoices')->row_array();
    check((float) $invoice['total_amount'] === 300000.0 && $invoice['billing_period_end'] === '2026-12-31' && $invoice['due_date'] === '2026-11-20', 'Bimonthly amount, coverage, due date');
    $rerun = $ci->billing_automation_service->generate_monthly_invoices('2026-11', 901);
    check($rerun['stats']['total_created'] === 0 && $rerun['stats']['total_skipped_duplicate'] === 2, 'Generation is idempotent');
    $next_month = $ci->billing_automation_service->generate_monthly_invoices('2026-12', 901);
    check($next_month['stats']['total_created'] === 1 && $next_month['stats']['total_skipped_not_cycle'] === 1, 'Monthly customer continues while bimonthly skips');
    check($model->invoice_exists_for_period(9001, '2026-12'), 'Covered month blocks duplicate bulk invoice');
    // Switch back to monthly: an already covered month still cannot be invoiced again.
    $model->fixtures = array(array_merge($base, array('customer_id' => 9001)));
    $changed_cycle = $ci->billing_automation_service->generate_monthly_invoices('2026-12', 901);
    check($changed_cycle['stats']['total_skipped_duplicate'] === 1, 'Changing cycle preserves covered months');
    $model->fixtures = array(array_merge($base, $pref, array('customer_id' => 9001)));
    $january = $ci->billing_automation_service->generate_monthly_invoices('2027-01', 901);
    check($january['stats']['total_created'] === 1, 'Next two-month cycle is generated');
    foreach (array(9003 => 'paid', 9004 => 'issued', 9005 => 'issued', 9006 => 'issued') as $customer_id => $status) {
        $copy = $invoice;
        unset($copy['id']);
        $copy['customer_id'] = $customer_id;
        $copy['invoice_number'] = 'TEST-INV-' . $customer_id;
        $copy['router_id'] = $customer_id === 9004 ? 902 : 901;
        $copy['status'] = $status;
        $copy['balance_amount'] = $status === 'paid' ? 0 : 300000;
        $copy['paid_amount'] = $status === 'paid' ? 300000 : 0;
        insert_fixture('invoices', $copy);
    }
    $rows = $ci->offline_collection_service->get_outstanding(901, '2026-11-10');
    check(count($rows) === 1 && $rows[0]['name'] === 'Customer 9001' && $rows[0]['amount'] === 300000.0, 'Report excludes transfer, paid, other router, terminated, unspecified and future cycles');
    // Partial payment leaves only the remaining amount in the report.
    $db->where('id', $invoice['id'])->update('invoices', array('status' => 'partially_paid', 'paid_amount' => 100000, 'balance_amount' => 200000));
    $rows = $ci->offline_collection_service->get_outstanding(901, '2026-11-10');
    check($rows[0]['amount'] === 200000.0, 'Report uses outstanding balance after partial payment');

    $long = $rows[0];
    $long['name'] = str_repeat('<&"', 200);
    $long['address'] = str_repeat('<&"', 500);
    $pages = $ci->offline_collection_service->build_messages(str_repeat('&', 200), '2026-11-10', array_fill(0, 40, $long));
    check(count($pages) > 1, 'Long report splits into pages');
    foreach ($pages as $page) {
        check(mb_strlen($page) < 4096 && strpos($page, '&lt;') !== false, 'Telegram size and HTML escaping');
    }

    $encrypted_token = $ci->settings_model->encrypt_secret('TEST_NO_NETWORK');
    check($encrypted_token !== '', 'Encrypted bot token fixture');
    insert_fixture('telegram_bots', array('id' => 901, 'router_id' => 901, 'bot_name' => 'Test bot', 'bot_token' => $encrypted_token, 'is_active' => 1));
    foreach (array(901 => 'collection', 902 => 'admin') as $id => $type) {
        insert_fixture('telegram_groups', array('id' => $id, 'bot_id' => 901, 'router_id' => 901, 'group_name' => 'Test group', 'chat_id' => '-TEST' . $id,
            'type' => $type, 'group_type' => $type === 'collection' ? 'collection' : 'billing', 'is_active' => 1));
    }
    class Frozen_collection_service extends Offline_collection_service
    {
        public $clock = '2026-11-10 08:00:00';
        protected function current_time() { return new DateTimeImmutable($this->clock, new DateTimeZone('Asia/Jakarta')); }
    }
    $service = new Frozen_collection_service();
    $sender = new class {
        public $calls = array();
        public $tokens = array();
        public $fail = true;
        public function send_message($token, $chat_id, $message) {
            $this->calls[] = $chat_id;
            $this->tokens[] = $token;
            return array('success' => !$this->fail);
        }
    };
    $ci->telegram_service = $sender;
    $preview = $service->run(true, '2026-11-10');
    check($preview['preview'] && empty($sender->calls) && $db->count_all('offline_collection_deliveries') === 0, 'Preview has no send or snapshot side effects');
    $service->clock = '2026-11-09 08:00:00';
    check(!empty($service->run()['skipped']), 'Skip outside day 10');
    $service->clock = '2026-11-10 07:59:59';
    check(!empty($service->run()['skipped']), 'Skip before 08:00 WIB');
    $service->clock = '2026-11-10 08:00:00';
    $failed = $service->run();
    check(!$failed['success'] && $failed['sent'] === 0, 'Failed Telegram remains pending');
    $sender->fail = false;
    $retry = $service->run();
    check($retry['sent'] === 1, 'Retry sends pending report');
    $again = $service->run();
    check($again['sent'] === 0 && $again['already_sent'] === 1, 'Already sent report is not repeated');
    check(array_unique($sender->calls) === array('-TEST901'), 'Only collection group receives report');
    check(array_unique($sender->tokens) === array('TEST_NO_NETWORK'), 'Encrypted bot token is decrypted before sending');
    $db->where('id', 901)->update('telegram_groups', array('is_active' => 0));
    $disabled = $service->run(true, '2026-11-10');
    check(!$disabled['success'] && $disabled['routers'][0]['targets'] === 0, 'No fallback to admin when collection group is disabled');
    // Bulk collection edits affect only selected customers visible on this router.
    $ci->load->helper('rbac');
    $ci->load->model('customer_model');
    $customers = $ci->customer_model->set_router_scope(901);
    $zero_amounts = array('total_amount' => 0.0, 'cash_visit_amount' => 0.0, 'transfer_amount' => 0.0, 'unspecified_amount' => 0.0);
    check($customers->get_collection_summary() === array('total' => 4, 'cash_visit' => 2, 'transfer' => 1, 'unspecified' => 1) + $zero_amounts,
        'Collection summary counts all visible customers and excludes terminated and other-router customers');
    $customers->get_paginated(1, 0, 'Customer 9001');
    check($customers->get_collection_summary() === array('total' => 4, 'cash_visit' => 2, 'transfer' => 1, 'unspecified' => 1) + $zero_amounts,
        'Collection summary is independent of table search and pagination');
    foreach (array('cash_visit' => array(9003, 9001), 'transfer' => array(9002), 'unspecified' => array(9006)) as $method => $expected_ids) {
        $rows = $customers->get_paginated(20, 0, '', $method);
        check(array_map('intval', array_column($rows, 'id')) === $expected_ids,
            'Collection filter returns only visible customers for ' . $method);
        check($customers->count_filtered('', $method) === count($expected_ids),
            'Filtered pagination total matches list for ' . $method);
    }
    check(array_map('intval', array_column($customers->get_paginated(1, 1, '', 'cash_visit'), 'id')) === array(9001),
        'Collection filter is applied before pagination');
    check($customers->count_filtered('Customer 9001', 'cash_visit') === 1
        && $customers->count_filtered('Customer 9001', 'transfer') === 0,
        'Customer search combines with collection filter');
    check(empty($customers->get_paginated(20, 0, 'Customer 9001', 'transfer')),
        'List is empty when search does not match the selected collection method');
    check($customers->count_filtered() === 4, 'Clearing collection filter restores all visible customers');
    foreach (array('', 'legacy') as $legacy_method) {
        $db->where('id', 9006)->update('customers', array('payment_collection_method' => $legacy_method));
        check(array_map('intval', array_column($customers->get_paginated(20, 0, '', 'unspecified'), 'id')) === array(9006),
            'Unspecified collection filter includes legacy value: ' . $legacy_method);
    }
    $db->where('id', 9006)->update('customers', array('payment_collection_method' => 'unspecified'));
    $before = $db->order_by('id')->get('customers')->result_array();
    check($customers->bulk_update_payment_collection_method(array(9001, 9002, 9002, 9004, 9005, 9999), 'transfer') === 2,
        'Bulk transfer deduplicates and skips missing, deleted and other-router customers');
    $after = $db->order_by('id')->get('customers')->result_array();
    foreach ($before as $index => $row) {
        $expected = $row;
        if (in_array((int) $row['id'], array(9001, 9002), true)) {
            $expected['payment_collection_method'] = 'transfer';
            $expected['updated_at'] = $after[$index]['updated_at'];
        }
        check($after[$index] === $expected, 'Bulk edit preserves other customer fields and unselected rows: ' . $row['id']);
    }
    check($customers->bulk_update_payment_collection_method(array(9001, 9002), 'cash_visit') === 2, 'Bulk switch to cash visits');
    check($db->where_in('id', array(9001, 9002))->where('payment_collection_method', 'cash_visit')->count_all_results('customers') === 2,
        'Both selected customers now use cash visits');
    check($customers->bulk_update_payment_collection_method(array(9001, 9002), 'cash_visit') === 2, 'Repeated bulk choice still succeeds');
    check($customers->get_collection_summary() === array('total' => 4, 'cash_visit' => 3, 'transfer' => 0, 'unspecified' => 1) + $zero_amounts,
        'Collection summary reflects updated collection methods');
    check($customers->count_filtered('', 'transfer') === 0 && $customers->count_filtered('', 'cash_visit') === 3,
        'Edited collection methods move customers between filtered lists');
    $snapshot = $db->order_by('id')->get('customers')->result_array();
    check($customers->bulk_update_payment_collection_method(array(9001), 'cash') === false, 'Reject unsupported collection method');
    check($customers->bulk_update_payment_collection_method(array(), 'transfer') === 0, 'Empty bulk selection changes nothing');
    check($customers->bulk_update_payment_collection_method(array(9004, 9005, 9999), 'transfer') === 0, 'Inaccessible bulk selection changes nothing');
    check($db->order_by('id')->get('customers')->result_array() === $snapshot, 'Rejected bulk requests preserve all rows');
    $customers->set_router_scope(902);
    check($customers->bulk_update_payment_collection_method(array(9001, 9004), 'transfer') === 1, 'Switching routers limits bulk edit to newly selected router');
    check($db->where('id', 9001)->get('customers')->row()->payment_collection_method === 'cash_visit', 'Previous router customer stays unchanged');
    check($customers->get_collection_summary() === array('total' => 1, 'cash_visit' => 0, 'transfer' => 1, 'unspecified' => 0) + $zero_amounts,
        'Collection summary follows the selected router');
    check(array_map('intval', array_column($customers->get_paginated(20, 0, '', 'transfer'), 'id')) === array(9004),
        'Collection filter follows selected router and excludes previous router');
    $customers->set_router_scope(999999);
    check($customers->get_collection_summary() === array('total' => 0, 'cash_visit' => 0, 'transfer' => 0, 'unspecified' => 0) + $zero_amounts,
        'Collection summary returns zero for an empty router');

    // Amounts use monthly package prices, independent of invoice balances/cycles.
    foreach (array(9001 => 90000, 9002 => 80000, 9003 => 75000, 9004 => 400000, 9005 => 500000, 9006 => 125000) as $id => $price) {
        $db->where('id', $id)->update('customers', array('package_price' => $price));
    }
    $db->where('id', 9002)->update('customers', array('payment_collection_method' => 'transfer', 'profile_id' => 901));
    $db->where('id', 9003)->update('customers', array('profile_id' => 902));
    insert_fixture('ppp_profiles', array('id' => 901, 'name' => 'Test 180K', 'price' => 180000, 'router_id' => 901));
    insert_fixture('ppp_profiles', array('id' => 902, 'name' => 'Test 150K', 'price' => 150000, 'router_id' => 901));
    foreach (array(array(9001, 250000, 902), array(9001, 150000, 902), array(9002, 0, 901)) as $index => $service) {
        insert_fixture('customer_services', array('customer_id' => $service[0], 'price' => $service[1], 'ppp_profile_id' => $service[2],
            'service_number' => 'TEST-AMOUNT-' . $index, 'install_date' => '2026-11-01', 'next_billing_date' => '2026-12-01', 'router_id' => 901));
    }
    // Duplicate secret matches must not inflate counts or money.
    foreach (array(901, 902) as $router_id) {
        insert_fixture('pppoe_secrets', array('username' => 'test-9001', 'router_id' => $router_id));
    }
    $customers->set_router_scope(901);
    $expected_money_summary = array('total' => 4, 'cash_visit' => 2, 'transfer' => 1, 'unspecified' => 1,
        'total_amount' => 605000.0, 'cash_visit_amount' => 300000.0, 'transfer_amount' => 180000.0, 'unspecified_amount' => 125000.0);
    check($customers->get_collection_summary() === $expected_money_summary,
        'Amounts use latest service, then positive profile, then customer price, counting equal prices and duplicate joins correctly');
    $customers->get_paginated(1, 0, 'Customer 9002', 'transfer');
    check($customers->get_collection_summary() === $expected_money_summary, 'Amount summary ignores table search, filter and pagination');
    $db->where('id', 9006)->update('customers', array('payment_collection_method' => 'legacy'));
    check($customers->get_collection_summary() === $expected_money_summary, 'Unspecified amount includes legacy collection values');
    $db->where('id', 9006)->update('customers', array('package_price' => 0));
    $without_price = $customers->get_collection_summary();
    check($without_price['unspecified_amount'] === 0.0 && $without_price['total_amount'] === 480000.0,
        'Customers without a positive package price contribute zero');
    $customers->set_router_scope(902);
    $other_router = $customers->get_collection_summary();
    check($other_router['total_amount'] === 400000.0 && $other_router['transfer_amount'] === 400000.0,
        'Package amounts follow selected router');

    // Exercise form payload, persistence and actual invoice generation together.
    require_once APPPATH . 'controllers/Customers.php';
    $ci->load->library('form_validation');
    $controller_reflection = new ReflectionClass('Customers');
    $customer_controller = $controller_reflection->newInstanceWithoutConstructor();
    $customer_controller->db = $db;
    $customer_controller->input = $ci->input;
    $customer_controller->form_validation = $ci->form_validation;
    $customer_fields = $controller_reflection->getProperty('customer_fields');
    $customer_fields->setAccessible(true);
    $customer_fields->setValue($customer_controller, array('monthly_price_override', 'profile_id', 'package_price'));
    foreach (array('', '0', '125000', '125000.50', '999999999999.99') as $valid_price) {
        check($customer_controller->valid_monthly_price($valid_price), 'Accept valid customer price: ' . $valid_price);
    }
    foreach (array('-1', '1e5', '125.000,50', '12.345', '1000000000000', array('125000')) as $invalid_price) {
        check(!$customer_controller->valid_monthly_price($invalid_price), 'Reject invalid customer price');
    }
    $payload_method = $controller_reflection->getMethod('collect_form_payload');
    $payload_method->setAccessible(true);
    $strip_method = $controller_reflection->getMethod('strip_pppoe_payload_for_standard_update');
    $strip_method->setAccessible(true);
    $saved_post = $_POST;
    $_POST = array('monthly_price_override' => '125000.50');
    $price_payload = $payload_method->invoke($customer_controller);
    $strip_method->invokeArgs($customer_controller, array(&$price_payload));
    check($price_payload === array('monthly_price_override' => '125000.50'), 'Ordinary customer edit retains custom monthly price');
    $customers->set_router_scope(901);
    $service_snapshot = $db->order_by('id')->get('customer_services')->result_array();
    $profile_snapshot = $db->order_by('id')->get('ppp_profiles')->result_array();
    $invoice_snapshot = $db->order_by('id')->get('invoices')->result_array();
    check($customers->update(9001, $price_payload), 'Save customer-specific monthly price');
    check($customers->update(9004, $price_payload) === false, 'Price edit respects router access');
    $priced_rows = $customers->get_paginated(20, 0, 'Customer 9001');
    check((float) $priced_rows[0]->effective_monthly_price === 125000.50, 'Customer list uses custom price above service price');
    $custom_summary = $customers->get_collection_summary();
    check($custom_summary['cash_visit_amount'] === 275000.50 && $custom_summary['total_amount'] === 455000.50,
        'Collection summary uses custom monthly price once despite duplicate joins');
    $context_method = $controller_reflection->getMethod('get_customer_service_context');
    $context_method->setAccessible(true);
    $custom_context = $context_method->invoke($customer_controller, 9001, $customers->get_by_id(9001));
    check($custom_context['price'] === 125000.50, 'Manual invoice context uses customer-specific price');
    $sync_method = $controller_reflection->getMethod('should_sync_pppoe_update');
    $sync_method->setAccessible(true);
    check($sync_method->invoke($customer_controller, $customers->get_by_id(9001), array('ppp_profile_id' => 902),
        902, 901, 'test-9001', '') === false, 'Price-only edit does not request PPP provisioning');
    $real_billing_model = new Billing_automation_model();
    $ci->billing_automation_model = $real_billing_model;
    $priced_customers = $real_billing_model->get_active_customers_with_profile(901);
    $model->fixtures = array_values(array_filter($priced_customers, static function ($row) { return (int) $row['customer_id'] === 9001; }));
    $ci->billing_automation_model = $model;
    $new_invoices = $ci->billing_automation_service->generate_monthly_invoices('2027-03', 901);
    check($new_invoices['stats']['total_created'] === 1 && $new_invoices['stats']['total_failed'] === 0,
        'Generate next invoice from real customer custom-price data');
    $custom_invoice = $db->where('customer_id', 9001)->where('billing_period_start', '2027-03-01')->get('invoices')->row_array();
    check((float) $custom_invoice['total_amount'] === 250001.0 && $custom_invoice['billing_period_end'] === '2027-04-30',
        'Two-month invoice multiplies agreed customer price correctly');
    check($db->where('id <=', $invoice_snapshot[count($invoice_snapshot) - 1]['id'])->order_by('id')->get('invoices')->result_array() === $invoice_snapshot,
        'Changing customer price preserves previously issued invoices');
    check($db->order_by('id')->get('customer_services')->result_array() === $service_snapshot
        && $db->order_by('id')->get('ppp_profiles')->result_array() === $profile_snapshot,
        'Customer price edits preserve service bandwidth and shared package prices');
    $_POST = array('monthly_price_override' => '');
    $reset_payload = $payload_method->invoke($customer_controller);
    check($reset_payload === array('monthly_price_override' => null) && $customers->update(9001, $reset_payload),
        'Blank price clears the customer override');
    $reset_rows = $customers->get_paginated(20, 0, 'Customer 9001');
    check((float) $reset_rows[0]->effective_monthly_price === 150000.0, 'Cleared override restores service price');
    $_POST = array('monthly_price_override' => '0');
    check($customers->update(9001, $payload_method->invoke($customer_controller)), 'Save an explicit zero-price override');
    $zero_rows = $customers->get_paginated(20, 0, 'Customer 9001');
    check((float) $zero_rows[0]->effective_monthly_price === 0.0
        && $context_method->invoke($customer_controller, 9001, $customers->get_by_id(9001))['price'] === 0.0,
        'Explicit zero price stays zero in list and manual billing context');
    $_POST = array('monthly_price_override' => array('125000'));
    check(!$customer_controller->valid_monthly_price('125000'), 'Reject array price even when form validation passes individual elements');
    $_POST = $saved_post;

    echo "PASS: {$checks} checks; only temporary tables and fake messaging used.\n";
} finally {
    foreach (array_reverse($temporary_tables) as $table) {
        $db->query("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
    }
}
