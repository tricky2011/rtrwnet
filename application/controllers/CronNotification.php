<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class CronNotification extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->model('Notification_model', 'notification_model');
        $this->load->helper(array('notification', 'tenant'));
    }

    public function run_all()
    {
        $this->assert_cron_access();

        $summary = array(
            'overdue_invoice' => $this->do_check_overdue_invoice(),
            'router_status' => $this->do_check_router_status(),
            'ticket_pending' => $this->do_check_ticket_pending(),
            'inventory_minimum' => $this->do_check_inventory_minimum(),
        );

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(array(
                'success' => true,
                'summary' => $summary,
            )));
    }

    public function check_overdue_invoice()
    {
        $this->assert_cron_access();
        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($this->do_check_overdue_invoice()));
    }

    public function check_router_status()
    {
        $this->assert_cron_access();
        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($this->do_check_router_status()));
    }

    public function check_ticket_pending()
    {
        $this->assert_cron_access();
        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($this->do_check_ticket_pending()));
    }

    public function check_inventory_minimum()
    {
        $this->assert_cron_access();
        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($this->do_check_inventory_minimum()));
    }

    protected function do_check_overdue_invoice()
    {
        if (!$this->db->table_exists('invoices')) {
            return array('success' => false, 'message' => 'Tabel invoices tidak ditemukan.', 'triggered' => 0);
        }

        $fields = $this->db->list_fields('invoices');
        if (!in_array('id', $fields, true) || !in_array('due_date', $fields, true) || !in_array('status', $fields, true)) {
            return array('success' => false, 'message' => 'Struktur invoices belum lengkap.', 'triggered' => 0);
        }

        $select = array('id', 'invoice_number', 'due_date', 'status');
        if (in_array('customer_id', $fields, true)) {
            $select[] = 'customer_id';
        }
        if (in_array('router_id', $fields, true)) {
            $select[] = 'router_id';
        }

        $rows = $this->db
            ->select(implode(',', $select))
            ->from('invoices')
            ->where('due_date <', date('Y-m-d'))
            ->where_in('status', array('issued', 'overdue', 'partially_paid'))
            ->limit(300)
            ->get()
            ->result_array();

        $triggered = 0;
        foreach ($rows as $row) {
            $invoice_id = (int) ($row['id'] ?? 0);
            if ($invoice_id <= 0) {
                continue;
            }

            $router_id = (int) ($row['router_id'] ?? 0);
            $target_users = $this->notification_model->get_target_user_ids_by_roles(array('superadmin', 'admin'), $router_id > 0 ? $router_id : null);
            if (empty($target_users)) {
                continue;
            }

            foreach ($target_users as $uid) {
                if ($this->notification_model->exists_reference_recent($uid, 'invoice', $invoice_id, 24, 'warning')) {
                    continue;
                }

                create_notification(array(
                    'user_id' => $uid,
                    'router_id' => $router_id > 0 ? $router_id : null,
                    'type' => 'warning',
                    'category' => 'billing',
                    'title' => 'Invoice overdue',
                    'message' => 'Invoice ' . (string) ($row['invoice_number'] ?? ('#' . $invoice_id)) . ' melewati jatuh tempo.',
                    'reference_id' => $invoice_id,
                    'reference_type' => 'invoice',
                ));
                $triggered++;
            }
        }

        return array(
            'success' => true,
            'message' => 'Check overdue invoice selesai.',
            'checked_rows' => count($rows),
            'triggered' => $triggered,
        );
    }

    protected function do_check_router_status()
    {
        if (!$this->db->table_exists('routers')) {
            return array('success' => false, 'message' => 'Tabel routers tidak ditemukan.', 'triggered' => 0);
        }

        $router_fields = $this->db->list_fields('routers');
        $name_col = in_array('name', $router_fields, true) ? 'name' : 'id';

        $qb = $this->db->select('id,' . $name_col . ' AS name', false)->from('routers');
        if (in_array('is_active', $router_fields, true)) {
            $qb->where('is_active', 1);
        }
        $routers = $qb->order_by('id', 'ASC')->get()->result_array();

        $triggered = 0;
        $checked = 0;
        foreach ($routers as $router) {
            $router_id = (int) ($router['id'] ?? 0);
            if ($router_id <= 0) {
                continue;
            }
            $checked++;

            $connect = connectRouter($router_id);
            if (empty($connect['success'])) {
                $message = 'Router ' . (string) ($router['name'] ?? ('#' . $router_id)) . ' unreachable: ' . (string) ($connect['message'] ?? 'koneksi gagal');
                $triggered += $this->notify_router_roles_once($router_id, array('superadmin', 'admin'), 'critical', 'router', 'Router unreachable', $message, $router_id, 'router_status');
                continue;
            }

            $api = $connect['api'];
            $resource = (array) $api->comm('/system/resource/print');
            if (!empty($resource[0]['cpu-load'])) {
                $cpu = (int) $resource[0]['cpu-load'];
                if ($cpu >= 85) {
                    $message = 'CPU router ' . (string) ($router['name'] ?? ('#' . $router_id)) . ' tinggi: ' . $cpu . '%.';
                    $triggered += $this->notify_router_roles_once($router_id, array('superadmin', 'admin'), 'warning', 'router', 'CPU router tinggi', $message, $router_id, 'router_cpu');
                }
            }

            $interfaces = (array) $api->comm('/interface/print');
            $down_count = 0;
            foreach ($interfaces as $iface) {
                $disabled = strtolower((string) ($iface['disabled'] ?? 'false')) === 'true';
                $running = strtolower((string) ($iface['running'] ?? 'false')) === 'true';
                if (!$disabled && !$running) {
                    $down_count++;
                }
            }
            if ($down_count > 0) {
                $message = 'Router ' . (string) ($router['name'] ?? ('#' . $router_id)) . ' memiliki interface down: ' . $down_count . ' interface.';
                $triggered += $this->notify_router_roles_once($router_id, array('superadmin', 'admin'), 'critical', 'router', 'Interface down terdeteksi', $message, $router_id, 'router_interface_down');
            }

            $ping_rows = (array) $api->comm('/ping', array('address' => '8.8.8.8', 'count' => '3', 'interval' => '300ms'));
            $ping_success = false;
            foreach ($ping_rows as $ping_row) {
                if (isset($ping_row['time']) && trim((string) $ping_row['time']) !== '') {
                    $ping_success = true;
                    break;
                }
            }
            if (!$ping_success) {
                $message = 'Router ' . (string) ($router['name'] ?? ('#' . $router_id)) . ' RTO saat ping 8.8.8.8 (3x percobaan).';
                $triggered += $this->notify_router_roles_once($router_id, array('superadmin', 'admin'), 'critical', 'router', 'Ping gateway internet RTO', $message, $router_id, 'router_ping_rto');
            }

            $ppp_active = 0;
            $ppp_rows = (array) $api->comm('/ppp/active/print');
            if (!empty($ppp_rows)) {
                $ppp_active = count($ppp_rows);
            }
            $prev_snapshot = $this->read_router_snapshot($router_id);
            if (!empty($prev_snapshot) && !empty($prev_snapshot['ppp_active'])) {
                $prev_active = (int) $prev_snapshot['ppp_active'];
                if ($prev_active >= 10 && $ppp_active >= 0) {
                    $drop_percent = (($prev_active - $ppp_active) / max(1, $prev_active)) * 100;
                    if ($drop_percent >= 30) {
                        $message = 'PPP Active drop pada router ' . (string) ($router['name'] ?? ('#' . $router_id)) . ': ' . $prev_active . ' -> ' . $ppp_active . ' (' . number_format($drop_percent, 1) . '%).';
                        $triggered += $this->notify_router_roles_once($router_id, array('superadmin', 'admin'), 'warning', 'router', 'PPP drop drastis', $message, $router_id, 'router_ppp_drop');
                    }
                }
            }
            $this->write_router_snapshot($router_id, array('ppp_active' => $ppp_active, 'checked_at' => date('Y-m-d H:i:s')));

            if (is_object($api) && method_exists($api, 'disconnect')) {
                $api->disconnect();
            }
        }

        return array(
            'success' => true,
            'message' => 'Check router status selesai.',
            'checked_routers' => $checked,
            'triggered' => $triggered,
        );
    }

    protected function do_check_ticket_pending()
    {
        if (!$this->db->table_exists('tickets')) {
            return array('success' => false, 'message' => 'Tabel tickets tidak ditemukan.', 'triggered' => 0);
        }

        $fields = $this->db->list_fields('tickets');
        if (!in_array('id', $fields, true) || !in_array('status', $fields, true)) {
            return array('success' => false, 'message' => 'Struktur tickets belum lengkap.', 'triggered' => 0);
        }

        $timestamp_col = in_array('opened_at', $fields, true)
            ? 'opened_at'
            : (in_array('created_at', $fields, true) ? 'created_at' : '');
        if ($timestamp_col === '') {
            return array('success' => false, 'message' => 'Kolom waktu tiket tidak ditemukan.', 'triggered' => 0);
        }

        $select = array('id', 'status', 'subject', $timestamp_col . ' AS opened_at');
        if (in_array('ticket_number', $fields, true)) {
            $select[] = 'ticket_number';
        }
        if (in_array('router_id', $fields, true)) {
            $select[] = 'router_id';
        }

        $rows = $this->db
            ->select(implode(',', $select), false)
            ->from('tickets')
            ->where_in('LOWER(status)', array('open', 'in_progress', 'pending'))
            ->where($timestamp_col . ' <=', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->limit(300)
            ->get()
            ->result_array();

        $triggered = 0;
        foreach ($rows as $row) {
            $ticket_id = (int) ($row['id'] ?? 0);
            if ($ticket_id <= 0) {
                continue;
            }

            $router_id = (int) ($row['router_id'] ?? 0);
            $title = 'Ticket pending > 1 jam';
            $message = 'Tiket ' . (string) ($row['ticket_number'] ?? ('#' . $ticket_id))
                . ' belum selesai lebih dari 1 jam. Subject: '
                . trim((string) ($row['subject'] ?? '-'));

            $triggered += $this->notify_router_roles_once(
                $router_id,
                array('superadmin', 'admin', 'teknisi'),
                'warning',
                'ticket',
                $title,
                $message,
                $ticket_id,
                'ticket_pending'
            );
        }

        return array(
            'success' => true,
            'message' => 'Check ticket pending selesai.',
            'checked_rows' => count($rows),
            'triggered' => $triggered,
        );
    }

    protected function do_check_inventory_minimum()
    {
        $table = '';
        if ($this->db->table_exists('inventory_items')) {
            $table = 'inventory_items';
        } elseif ($this->db->table_exists('inventory')) {
            $table = 'inventory';
        }

        if ($table === '') {
            return array(
                'success' => true,
                'message' => 'Tabel inventory tidak ditemukan (skip).',
                'checked_rows' => 0,
                'triggered' => 0,
            );
        }

        $fields = $this->db->list_fields($table);
        $stock_col = in_array('current_stock', $fields, true) ? 'current_stock' : (in_array('stock', $fields, true) ? 'stock' : '');
        $min_col = in_array('minimum_stock', $fields, true) ? 'minimum_stock' : (in_array('min_stock', $fields, true) ? 'min_stock' : '');
        if ($stock_col === '' || $min_col === '') {
            return array(
                'success' => true,
                'message' => 'Kolom stock/minimum_stock tidak ditemukan (skip).',
                'checked_rows' => 0,
                'triggered' => 0,
            );
        }

        $name_col = in_array('item_name', $fields, true) ? 'item_name' : (in_array('name', $fields, true) ? 'name' : 'id');
        $router_col = in_array('router_id', $fields, true) ? 'router_id' : '';

        $select = 'id,' . $name_col . ' AS item_name,' . $stock_col . ' AS stock_now,' . $min_col . ' AS min_stock';
        if ($router_col !== '') {
            $select .= ',' . $router_col . ' AS router_id';
        }

        $qb = $this->db->select($select, false)->from($table)->where($stock_col . ' <=', $min_col, false);
        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        } elseif (in_array('status', $fields, true)) {
            $qb->where_in('LOWER(status)', array('active', 'aktif'));
        }

        $rows = $qb->limit(300)->get()->result_array();
        $triggered = 0;
        foreach ($rows as $row) {
            $item_id = (int) ($row['id'] ?? 0);
            if ($item_id <= 0) {
                continue;
            }

            $router_id = (int) ($row['router_id'] ?? 0);
            $item_name = trim((string) ($row['item_name'] ?? ('Item #' . $item_id)));
            $stock_now = (float) ($row['stock_now'] ?? 0);
            $min_stock = (float) ($row['min_stock'] ?? 0);

            $triggered += $this->notify_router_roles_once(
                $router_id,
                array('superadmin', 'admin'),
                'warning',
                'inventory',
                'Stock minimum tercapai',
                'Stok item `' . $item_name . '` minimum (stok: ' . rtrim(rtrim(number_format($stock_now, 2, '.', ''), '0'), '.') . ', minimum: ' . rtrim(rtrim(number_format($min_stock, 2, '.', ''), '0'), '.') . ').',
                $item_id,
                'inventory_minimum'
            );
        }

        return array(
            'success' => true,
            'message' => 'Check inventory minimum selesai.',
            'checked_rows' => count($rows),
            'triggered' => $triggered,
        );
    }

    protected function notify_router_roles_once($router_id, array $roles, $type, $category, $title, $message, $reference_id, $reference_type)
    {
        $router_id = (int) $router_id;
        $target_users = $this->notification_model->get_target_user_ids_by_roles($roles, $router_id > 0 ? $router_id : null);
        if (empty($target_users)) {
            return 0;
        }

        $count = 0;
        foreach ($target_users as $uid) {
            if ($this->notification_model->exists_reference_recent((int) $uid, $reference_type, (int) $reference_id, 6, $type)) {
                continue;
            }

            $res = create_notification(array(
                'user_id' => (int) $uid,
                'router_id' => $router_id > 0 ? $router_id : null,
                'type' => $type,
                'category' => $category,
                'title' => $title,
                'message' => $message,
                'reference_id' => (int) $reference_id,
                'reference_type' => $reference_type,
            ));

            if (!empty($res['success'])) {
                $count++;
            }
        }

        return $count;
    }

    protected function read_router_snapshot($router_id)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0) {
            return array();
        }

        $path = sys_get_temp_dir() . '/cron_notification_router_' . $router_id . '.json';
        if (!is_file($path)) {
            return array();
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    protected function write_router_snapshot($router_id, array $payload)
    {
        $router_id = (int) $router_id;
        if ($router_id <= 0 || empty($payload)) {
            return;
        }
        $path = sys_get_temp_dir() . '/cron_notification_router_' . $router_id . '.json';
        @file_put_contents($path, json_encode($payload));
    }

    protected function assert_cron_access()
    {
        if (is_cli()) {
            return;
        }

        $key_from_get = trim((string) $this->input->get('key', true));
        $key_from_env = trim((string) getenv('CRON_NOTIFICATION_KEY'));
        if ($key_from_env !== '' && hash_equals($key_from_env, $key_from_get)) {
            return;
        }

        show_error('Akses cron ditolak.', 403);
        exit;
    }
}
