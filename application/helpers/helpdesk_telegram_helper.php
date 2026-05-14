<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('helpdesk_telegram_send')) {
    function helpdesk_telegram_send($message, $parse_mode = 'HTML', $chat_id = '', $type = 'admin', $router_id = null)
    {
        $CI =& get_instance();
        $message = trim((string) $message);
        $type = trim((string) $type);
        $target_chat = trim((string) $chat_id);
        $router_id = $router_id === null ? null : (int) $router_id;

        if ($message === '') {
            return array('success' => false, 'message' => 'Message Telegram kosong.');
        }

        if ($target_chat === '' && $type !== '') {
            $multi = helpdesk_telegram_send_by_type($type, $message, $router_id);
            if (!empty($multi['success'])) {
                return $multi;
            }
        }

        return helpdesk_telegram_send_legacy($message, $parse_mode, $target_chat);
    }
}

if (!function_exists('helpdesk_telegram_send_by_type')) {
    function helpdesk_telegram_send_by_type($type, $message, $router_id = null)
    {
        $CI =& get_instance();
        $CI->load->helper('tenant');
        $router_id = $router_id === null ? null : (int) $router_id;

        if ($router_id !== null && $router_id > 0 && function_exists('sendTelegramByRouter')) {
            $res = sendTelegramByRouter((int) $router_id, (string) $type, (string) $message, false);
            $effective_sent = (int) ($res['sent'] ?? 0) + (int) ($res['deduped'] ?? 0);
            return array(
                'success' => !empty($res['success']) && $effective_sent > 0,
                'message' => (string) ($res['message'] ?? 'Telegram multi-chat by router diproses.'),
                'sent' => (int) ($res['sent'] ?? 0),
                'failed' => (int) ($res['failed'] ?? 0),
                'deduped' => (int) ($res['deduped'] ?? 0),
            );
        }

        if (function_exists('sendTelegramByType')) {
            $res = sendTelegramByType((string) $type, (string) $message);
            $effective_sent = (int) ($res['sent'] ?? 0) + (int) ($res['deduped'] ?? 0);
            if (!empty($res['success'])) {
                return array(
                    'success' => $effective_sent > 0,
                    'message' => (string) ($res['message'] ?? 'Telegram multi-chat terkirim.'),
                    'sent' => (int) ($res['sent'] ?? 0),
                    'failed' => (int) ($res['failed'] ?? 0),
                    'deduped' => (int) ($res['deduped'] ?? 0),
                );
            }

            return array(
                'success' => false,
                'message' => (string) ($res['message'] ?? 'Telegram multi-chat gagal.'),
                'sent' => (int) ($res['sent'] ?? 0),
                'failed' => (int) ($res['failed'] ?? 0),
                'deduped' => (int) ($res['deduped'] ?? 0),
            );
        }

        return array(
            'success' => false,
            'message' => 'Helper multi-chat Telegram belum tersedia.',
            'sent' => 0,
            'failed' => 0,
            'deduped' => 0,
        );
    }
}

if (!function_exists('helpdesk_telegram_send_legacy')) {
    function helpdesk_telegram_send_legacy($message, $parse_mode = 'HTML', $chat_id = '')
    {
        $CI =& get_instance();
        $CI->load->model('Settings_model', 'settings_model');
        $CI->load->library('telegram_service');

        $settings = $CI->settings_model->get_telegram_settings();
        $bot_token = trim((string) ($settings['bot_token'] ?? ''));
        $default_chat = trim((string) ($settings['chat_id_admin'] ?? ''));
        $enabled = (int) ($settings['enable_notification'] ?? 0) === 1;

        if (!$enabled) {
            return array('success' => false, 'message' => 'Telegram notification nonaktif.');
        }
        if ($bot_token === '') {
            return array('success' => false, 'message' => 'Bot token Telegram belum diatur.');
        }

        $target_chat = trim((string) $chat_id);
        if ($target_chat === '') {
            $target_chat = $default_chat;
        }
        if ($target_chat === '') {
            return array('success' => false, 'message' => 'Chat ID Telegram belum diatur.');
        }

        return $CI->telegram_service->send_message($bot_token, $target_chat, (string) $message, (string) $parse_mode);
    }
}

if (!function_exists('helpdesk_telegram_broadcast')) {
    function helpdesk_telegram_broadcast($message, array $types, $router_id = null)
    {
        $types = array_values(array_unique(array_filter(array_map(static function ($t) {
            return trim((string) $t);
        }, $types))));
        if (empty($types)) {
            $types = array('admin');
        }

        $total_sent = 0;
        $total_failed = 0;
        $total_deduped = 0;
        $multi_attempted = false;
        $messages = array();

        foreach ($types as $type) {
            $res = helpdesk_telegram_send_by_type($type, $message, $router_id);
            if (((int) ($res['sent'] ?? 0) + (int) ($res['failed'] ?? 0) + (int) ($res['deduped'] ?? 0)) > 0) {
                $multi_attempted = true;
            }
            if (!empty($res['success'])) {
                $total_sent += (int) ($res['sent'] ?? 0);
                $total_failed += (int) ($res['failed'] ?? 0);
                $total_deduped += (int) ($res['deduped'] ?? 0);
            } else {
                $total_failed++;
                $messages[] = '[' . $type . '] ' . (string) ($res['message'] ?? 'gagal kirim');
            }
        }

        $allow_legacy_fallback = !($router_id !== null && (int) $router_id > 0);
        if ($total_sent === 0 && $total_deduped === 0 && !$multi_attempted && $allow_legacy_fallback) {
            $legacy = helpdesk_telegram_send_legacy($message, 'HTML', '');
            if (!empty($legacy['success'])) {
                $total_sent = 1;
                $messages = array('Fallback legacy dipakai.');
            } else {
                $messages[] = '[legacy] ' . (string) ($legacy['message'] ?? 'gagal kirim');
            }
        }

        return array(
            'success' => ($total_sent > 0 || $total_deduped > 0),
            'message' => !empty($messages)
                ? implode(' | ', $messages)
                : ('Telegram broadcast terkirim. sent=' . $total_sent . ', deduped=' . $total_deduped . ', failed=' . $total_failed),
            'sent' => $total_sent,
            'failed' => $total_failed,
            'deduped' => $total_deduped,
        );
    }
}

if (!function_exists('helpdesk_telegram_ticket_created')) {
    function helpdesk_telegram_ticket_created(array $ticket)
    {
        $ticket_code = html_escape((string) ($ticket['ticket_code'] ?? '-'));
        $subject = html_escape((string) ($ticket['subject'] ?? '-'));
        $customer = html_escape((string) ($ticket['customer_name'] ?? '-'));
        $area = html_escape((string) ($ticket['customer_area'] ?? '-'));
        $issue_type = strtolower(trim((string) ($ticket['issue_type'] ?? '')));
        $issue_type_label = html_escape((string) ($ticket['issue_type_label'] ?? 'Gangguan'));
        $priority = strtoupper((string) ($ticket['priority'] ?? 'MEDIUM'));
        $status = strtoupper((string) ($ticket['status'] ?? 'OPEN'));
        $assigned = html_escape((string) ($ticket['assigned_name'] ?? '-'));
        $ppp_username = html_escape((string) ($ticket['ppp_username'] ?? '-'));
        $ppp_password = html_escape((string) ($ticket['ppp_password'] ?? '-'));
        $deadline = (string) ($ticket['sla_deadline'] ?? '');
        $deadline_label = ($deadline !== '' && strtotime($deadline) !== false)
            ? date('d-m-Y H:i', strtotime($deadline))
            : '-';

        $message = "🛠 <b>TIKET HELP DESK BARU</b>\n\n"
            . "No Tiket: <b>{$ticket_code}</b>\n"
            . "Customer: <b>{$customer}</b>\n"
            . "Area: <b>{$area}</b>\n"
            . "Jenis: <b>{$issue_type_label}</b>\n"
            . "Issue: {$subject}\n"
            . "Prioritas: <b>{$priority}</b>\n"
            . "Status: <b>{$status}</b>\n"
            . "Assigned: <b>{$assigned}</b>\n"
            . "SLA Deadline: <b>{$deadline_label}</b>\n"
            . "Waktu: " . date('d-m-Y H:i');

        if ($issue_type === 'router_replace') {
            $message .= "\n\n<b>DATA PPP (GANTI ROUTER)</b>\n"
                . "Username PPP: <code>{$ppp_username}</code>\n"
                . "Password PPP: <code>{$ppp_password}</code>";
        }

        $types = array('admin', 'teknisi');
        if ($priority === 'URGENT') {
            $types[] = 'alert';
        }

        $router_id = helpdesk_telegram_resolve_router_id($ticket);
        return helpdesk_telegram_broadcast($message, $types, $router_id);
    }
}

if (!function_exists('helpdesk_telegram_sla_breached')) {
    function helpdesk_telegram_sla_breached(array $tickets)
    {
        if (empty($tickets)) {
            return array('success' => false, 'message' => 'Tidak ada tiket SLA breached.');
        }

        $lines = array();
        $lines[] = '⚠️ <b>ALERT SLA BREACHED</b>';
        $lines[] = '';

        $max = min(count($tickets), 10);
        for ($i = 0; $i < $max; $i++) {
            $t = (array) $tickets[$i];
            $code = html_escape((string) ($t['ticket_code'] ?? ('#' . (int) ($t['id'] ?? 0))));
            $subject = html_escape((string) ($t['subject'] ?? '-'));
            $priority = strtoupper((string) ($t['priority'] ?? 'MEDIUM'));
            $deadline = (string) ($t['sla_deadline'] ?? '');
            $deadline_label = ($deadline !== '' && strtotime($deadline) !== false)
                ? date('d-m-Y H:i', strtotime($deadline))
                : '-';

            $lines[] = ($i + 1) . '. <b>' . $code . '</b> | ' . $priority;
            $lines[] = '   ' . $subject;
            $lines[] = '   Deadline: ' . $deadline_label;
        }

        if (count($tickets) > $max) {
            $lines[] = '';
            $lines[] = 'Dan ' . (count($tickets) - $max) . ' tiket lainnya.';
        }

        return helpdesk_telegram_broadcast(implode("\n", $lines), array('alert', 'admin'));
    }
}

if (!function_exists('helpdesk_telegram_ticket_status_updated')) {
    function helpdesk_telegram_ticket_status_updated(array $ticket, $old_status = '', $new_status = '', $note = '')
    {
        $ticket_code = html_escape((string) ($ticket['ticket_code'] ?? $ticket['ticket_number'] ?? ('#' . (int) ($ticket['id'] ?? 0))));
        $subject = html_escape((string) ($ticket['subject'] ?? '-'));
        $customer = html_escape((string) ($ticket['customer_name'] ?? '-'));
        $area = html_escape((string) ($ticket['customer_area'] ?? '-'));
        $assigned = html_escape((string) ($ticket['assigned_name'] ?? '-'));

        $normalize = static function ($status) {
            $s = strtoupper(trim((string) $status));
            if ($s === 'IN_PROGRESS') {
                return 'PROGRESS';
            }
            if ($s === 'RESOLVED') {
                return 'DONE';
            }
            if ($s === 'CANCELED') {
                return 'CANCELLED';
            }
            return $s !== '' ? $s : '-';
        };

        $old_label = $normalize($old_status);
        if ($old_label === '-') {
            $old_label = $normalize((string) ($ticket['status'] ?? ''));
        }
        $new_label = $normalize($new_status);
        if ($new_label === '-') {
            $new_label = $normalize((string) ($ticket['status'] ?? ''));
        }

        $note = trim((string) $note);
        $note_line = $note !== '' ? "\nCatatan: " . html_escape($note) : '';

        $message = "✅ <b>UPDATE STATUS TIKET</b>\n\n"
            . "No Tiket: <b>{$ticket_code}</b>\n"
            . "Customer: <b>{$customer}</b>\n"
            . "Area: <b>{$area}</b>\n"
            . "Issue: {$subject}\n"
            . "Assigned: <b>{$assigned}</b>\n"
            . "Status: <b>{$old_label}</b> → <b>{$new_label}</b>"
            . $note_line
            . "\nWaktu: " . date('d-m-Y H:i');

        $router_id = helpdesk_telegram_resolve_router_id($ticket);
        return helpdesk_telegram_broadcast($message, array('admin', 'teknisi'), $router_id);
    }
}

if (!function_exists('helpdesk_telegram_resolve_router_id')) {
    function helpdesk_telegram_resolve_router_id(array $ticket)
    {
        foreach (array('router_id', 'service_router_id', 'customer_service_router_id') as $key) {
            if (!empty($ticket[$key]) && (int) $ticket[$key] > 0) {
                return (int) $ticket[$key];
            }
        }

        $customer_id = (int) ($ticket['customer_id'] ?? 0);
        if ($customer_id <= 0) {
            return 0;
        }

        $CI =& get_instance();
        $CI->load->database();

        if ($CI->db->table_exists('customers')) {
            $customer_fields = $CI->db->list_fields('customers');
            if (in_array('router_id', $customer_fields, true)) {
                $row = (array) $CI->db
                    ->select('router_id')
                    ->from('customers')
                    ->where('id', $customer_id)
                    ->where('router_id >', 0)
                    ->limit(1)
                    ->get()
                    ->row_array();
                if (!empty($row['router_id'])) {
                    return (int) $row['router_id'];
                }
            }
        }

        if (!$CI->db->table_exists('customer_services')) {
            return 0;
        }

        $fields = $CI->db->list_fields('customer_services');
        if (!in_array('customer_id', $fields, true) || !in_array('router_id', $fields, true)) {
            return 0;
        }

        $qb = $CI->db
            ->select('router_id')
            ->from('customer_services')
            ->where('customer_id', $customer_id)
            ->where('router_id >', 0);

        if (in_array('status', $fields, true)) {
            $qb->where_in('LOWER(status)', array('active', 'suspended', 'isolated', 'isolir', 'pending'));
        }
        if (in_array('id', $fields, true)) {
            $qb->order_by('id', 'DESC');
        }

        $row = (array) $qb->limit(1)->get()->row_array();
        return (int) ($row['router_id'] ?? 0);
    }
}
