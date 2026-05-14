<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Telegram_webhook extends CI_Controller
{
    private $customer_fields = array();
    private $pppoe_fields = array();
    private $work_order_fields = array();

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->model(array('settings_model'));
        $this->load->library('telegram_service');

        if ($this->db->table_exists('customers')) {
            $this->customer_fields = $this->db->list_fields('customers');
        }
        if ($this->db->table_exists('pppoe_secrets')) {
            $this->pppoe_fields = $this->db->list_fields('pppoe_secrets');
        }
        if ($this->db->table_exists('work_orders')) {
            $this->work_order_fields = $this->db->list_fields('work_orders');
        }
    }

    public function index()
    {
        $expected_secret = $this->get_webhook_secret();
        $header_secret = trim((string) $this->input->server('HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'));

        if ($expected_secret === '') {
            log_message('error', '[TELEGRAM_WEBHOOK] webhook secret not configured.');
            return $this->json_response(503, array(
                'success' => false,
                'message' => 'Telegram webhook secret belum dikonfigurasi.',
            ));
        }

        if (!hash_equals($expected_secret, $header_secret)) {
            log_message('error', '[TELEGRAM_WEBHOOK] invalid secret token.');
            return $this->json_response(403, array(
                'success' => false,
                'message' => 'Forbidden',
            ));
        }

        $raw = file_get_contents('php://input');
        $update = json_decode((string) $raw, true);

        if (!is_array($update)) {
            log_message('error', '[TELEGRAM_WEBHOOK] invalid payload: ' . (string) $raw);
            return $this->json_response(400, array(
                'success' => false,
                'message' => 'Invalid payload',
            ));
        }

        log_message('debug', '[TELEGRAM_WEBHOOK] incoming update: ' . json_encode($update));

        if (!empty($update['callback_query']) && is_array($update['callback_query'])) {
            return $this->handle_callback_query($update['callback_query']);
        }

        if (!empty($update['message']) && is_array($update['message'])) {
            return $this->handle_message_update($update['message']);
        }

        return $this->json_response(200, array(
            'success' => true,
            'message' => 'No actionable update.',
        ));
    }

    private function handle_callback_query(array $callback_query)
    {
        $bot_token = $this->settings_model->get_telegram_settings()['bot_token'] ?? '';
        $callback_query_id = (string) ($callback_query['id'] ?? '');
        $data = (string) ($callback_query['data'] ?? '');
        $chat_id = (string) ($callback_query['message']['chat']['id'] ?? '');
        $actor_id = (string) ($callback_query['from']['id'] ?? '');

        if ($callback_query_id === '' || $data === '') {
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'Callback data tidak lengkap.',
            ));
        }

        $callback_secret = $this->get_provisioning_callback_secret();
        if ($callback_secret === '') {
            $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'Fitur callback belum dikonfigurasi.', true);
            log_message('error', '[TELEGRAM_WEBHOOK] provisioning callback secret missing.');
            return $this->json_response(200, array(
                'success' => false,
                'message' => 'Provisioning callback secret belum dikonfigurasi.',
            ));
        }

        $parsed = $this->parse_callback_data($data);
        if (empty($parsed)) {
            $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'Data tombol tidak valid.', true);
            log_message('error', '[TELEGRAM_WEBHOOK] invalid callback format: ' . $data);
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'Data tombol tidak valid.',
            ));
        }

        $action = (string) ($parsed['action'] ?? '');
        $customer_id = (int) ($parsed['customer_id'] ?? 0);
        $signature = (string) ($parsed['signature'] ?? '');
        $expected_signature = $this->build_callback_signature($customer_id, $action, $callback_secret);
        $legacy_signature = $this->build_callback_signature($customer_id, '', $callback_secret);
        if (!hash_equals($expected_signature, $signature) && !hash_equals($legacy_signature, $signature)) {
            $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'Token callback tidak valid.', true);
            log_message('error', '[TELEGRAM_WEBHOOK] invalid callback signature customer_id=' . $customer_id);
            return $this->json_response(403, array(
                'success' => false,
                'message' => 'Invalid callback signature.',
            ));
        }

        $customer = $this->db
            ->where('id', $customer_id)
            ->limit(1)
            ->get('customers')
            ->row_array();
        if (empty($customer)) {
            $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'Customer tidak ditemukan.', true);
            return $this->json_response(404, array(
                'success' => false,
                'message' => 'Customer tidak ditemukan.',
            ));
        }

        if (in_array('technician_id', $this->customer_fields, true)
            && !empty($customer['technician_id'])
            && $this->db->table_exists('technicians')
        ) {
            $tech = $this->db
                ->where('id', (int) $customer['technician_id'])
                ->limit(1)
                ->get('technicians')
                ->row_array();
            $allowed_id = trim((string) ($tech['telegram_chat_id'] ?? ''));
            if (!empty($tech) && $allowed_id !== '' && $allowed_id !== $chat_id && $allowed_id !== $actor_id) {
                $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'Anda tidak berhak untuk WO ini.', true);
                return $this->json_response(403, array(
                    'success' => false,
                    'message' => 'Unauthorized chat ID.',
                ));
            }
        }

        if ($action === 'USR') {
            $username = $this->resolve_pppoe_username($customer);
            if ($username === '') {
                $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'Username PPP tidak ditemukan.', true);
                return $this->json_response(404, array(
                    'success' => false,
                    'message' => 'Username PPP tidak ditemukan.',
                ));
            }

            $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'Username PPP dikirim.', false);
            if ($chat_id !== '') {
                $this->telegram_service->send_message(
                    $bot_token,
                    $chat_id,
                    "<code>" . html_escape($username) . "</code>",
                    'HTML'
                );
            }

            return $this->json_response(200, array(
                'success' => true,
                'message' => 'Username PPP dikirim.',
                'customer_id' => $customer_id,
            ));
        }

        if ($action === 'PWD') {
            $password = $this->resolve_pppoe_password($customer);
            if ($password === '') {
                $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'Password PPP tidak ditemukan.', true);
                return $this->json_response(404, array(
                    'success' => false,
                    'message' => 'Password PPP tidak ditemukan.',
                ));
            }

            $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'Password PPP dikirim.', false);
            if ($chat_id !== '') {
                $this->telegram_service->send_message(
                    $bot_token,
                    $chat_id,
                    "<code>" . html_escape($password) . "</code>",
                    'HTML'
                );
            }

            return $this->json_response(200, array(
                'success' => true,
                'message' => 'Password PPP dikirim.',
                'customer_id' => $customer_id,
            ));
        }

        if ($action === 'VLAN') {
            $vlan = $this->resolve_customer_vlan($customer);
            if ($vlan === '') {
                $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'VLAN ID tidak ditemukan.', true);
                return $this->json_response(404, array(
                    'success' => false,
                    'message' => 'VLAN ID tidak ditemukan.',
                ));
            }

            $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'VLAN ID dikirim.', false);
            if ($chat_id !== '') {
                $this->telegram_service->send_message(
                    $bot_token,
                    $chat_id,
                    "<code>" . html_escape($vlan) . "</code>",
                    'HTML'
                );
            }

            return $this->json_response(200, array(
                'success' => true,
                'message' => 'VLAN ID dikirim.',
                'customer_id' => $customer_id,
            ));
        }

        if ($action !== 'ACT') {
            $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'Aksi tidak dikenali.', true);
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'Aksi callback tidak dikenali.',
            ));
        }

        $wo_result = $this->find_latest_installation_work_order($customer_id);
        if (empty($wo_result['success'])) {
            $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, (string) $wo_result['message'], true);
            return $this->json_response(404, array(
                'success' => false,
                'message' => (string) $wo_result['message'],
                'customer_id' => $customer_id,
            ));
        }

        $actor_scope = $actor_id !== '' ? $actor_id : $chat_id;
        $speedtest_token = $this->build_speedtest_token($customer_id, (int) $wo_result['wo_id'], $actor_scope);
        $instruction = "Lampiran speedtest wajib sebelum WO selesai.\n"
            . "WO: <code>" . html_escape((string) ($wo_result['wo_number'] ?? '-')) . "</code>\n"
            . "Customer: <b>" . html_escape((string) ($customer['full_name'] ?? $customer_id)) . "</b>\n\n"
            . "Silakan balas (reply) pesan ini dengan screenshot/file speedtest.\n"
            . "Token: <code>" . html_escape($speedtest_token) . "</code>";

        $this->telegram_service->answer_callback_query($bot_token, $callback_query_id, 'Silakan upload lampiran speedtest.', false);

        $sent = array('success' => false);
        if ($chat_id !== '') {
            $sent = $this->telegram_service->send_message_with_reply_markup(
                $bot_token,
                $chat_id,
                $instruction,
                array(
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' => 'Upload lampiran speedtest...',
                ),
                'HTML'
            );
            if (empty($sent['success'])) {
                $sent = $this->telegram_service->send_message($bot_token, $chat_id, $instruction, 'HTML');
            }
        }

        if (empty($sent['success'])) {
            log_message('error', '[TELEGRAM_WEBHOOK] send speedtest instruction failed: ' . json_encode($sent));
            return $this->json_response(500, array(
                'success' => false,
                'message' => 'Gagal kirim instruksi upload speedtest.',
            ));
        }

        return $this->json_response(200, array(
            'success' => true,
            'message' => 'Instruksi upload speedtest sudah dikirim.',
            'customer_id' => $customer_id,
            'work_order' => $wo_result,
        ));
    }

    private function handle_message_update(array $message)
    {
        $bot_token = $this->settings_model->get_telegram_settings()['bot_token'] ?? '';
        $chat_id = (string) ($message['chat']['id'] ?? '');
        $actor_id = (string) ($message['from']['id'] ?? '');
        $token = $this->extract_speedtest_token_from_message($message);

        if ($token === '') {
            return $this->json_response(200, array(
                'success' => true,
                'message' => 'No speedtest token found.',
            ));
        }

        $callback_secret = $this->get_provisioning_callback_secret();
        if ($callback_secret === '') {
            if ($chat_id !== '') {
                $this->telegram_service->send_message(
                    $bot_token,
                    $chat_id,
                    'Token speedtest belum bisa diproses karena secret callback belum dikonfigurasi.',
                    'HTML'
                );
            }
            log_message('error', '[TELEGRAM_WEBHOOK] provisioning callback secret missing for speedtest token.');
            return $this->json_response(200, array(
                'success' => false,
                'message' => 'Provisioning callback secret belum dikonfigurasi.',
            ));
        }

        $parsed = $this->parse_speedtest_token($token);
        if (empty($parsed)) {
            if ($chat_id !== '') {
                $this->telegram_service->send_message(
                    $bot_token,
                    $chat_id,
                    'Token speedtest tidak valid. Klik ulang tombol <b>SUDAH TERPASANG</b>.',
                    'HTML'
                );
            }
            return $this->json_response(200, array(
                'success' => false,
                'message' => 'Invalid speedtest token.',
            ));
        }

        $scope_current = $actor_id !== '' ? $actor_id : $chat_id;
        $expected_signature = $this->build_speedtest_signature((int) $parsed['customer_id'], (int) $parsed['wo_id'], $scope_current, $callback_secret);
        $legacy_signature = $this->build_speedtest_signature((int) $parsed['customer_id'], (int) $parsed['wo_id'], $chat_id, $callback_secret);
        if (!hash_equals($expected_signature, (string) $parsed['signature'])
            && !hash_equals($legacy_signature, (string) $parsed['signature'])
        ) {
            if ($chat_id !== '') {
                $this->telegram_service->send_message(
                    $bot_token,
                    $chat_id,
                    'Token speedtest tidak cocok. Klik ulang tombol <b>SUDAH TERPASANG</b>.',
                    'HTML'
                );
            }
            return $this->json_response(200, array(
                'success' => false,
                'message' => 'Speedtest token signature invalid.',
            ));
        }

        $attachment = $this->extract_speedtest_attachment($message);
        if (empty($attachment['ok'])) {
            if ($chat_id !== '') {
                $this->telegram_service->send_message(
                    $bot_token,
                    $chat_id,
                    'Lampiran speedtest belum terdeteksi. Balas token dengan <b>foto</b> atau <b>dokumen</b> speedtest.',
                    'HTML'
                );
            }
            return $this->json_response(200, array(
                'success' => false,
                'message' => 'Speedtest attachment missing.',
            ));
        }

        $complete = $this->complete_work_order_after_speedtest(
            (int) $parsed['customer_id'],
            (int) $parsed['wo_id'],
            $attachment,
            $scope_current
        );

        if (empty($complete['success'])) {
            if ($chat_id !== '') {
                $this->telegram_service->send_message(
                    $bot_token,
                    $chat_id,
                    'Gagal menutup WO: ' . html_escape((string) ($complete['message'] ?? 'unknown')),
                    'HTML'
                );
            }
            return $this->json_response(200, array(
                'success' => false,
                'message' => (string) ($complete['message'] ?? 'Gagal menutup work order.'),
            ));
        }

        if ($chat_id !== '') {
            $this->telegram_service->send_message(
                $bot_token,
                $chat_id,
                "Speedtest diterima.\nWO: <code>" . html_escape((string) ($complete['wo_number'] ?? '-')) . "</code> sudah <b>DONE</b>.",
                'HTML'
            );
        }

        return $this->json_response(200, array(
            'success' => true,
            'message' => 'Speedtest received. Work order updated.',
            'data' => $complete,
        ));
    }

    private function extract_speedtest_token_from_message(array $message)
    {
        $sources = array(
            (string) ($message['text'] ?? ''),
            (string) ($message['caption'] ?? ''),
            (string) ($message['reply_to_message']['text'] ?? ''),
            (string) ($message['reply_to_message']['caption'] ?? ''),
        );

        foreach ($sources as $text) {
            if ($text === '') {
                continue;
            }

            if (preg_match('/SPD\|[0-9]+\|[0-9]+\|[a-f0-9]{16}/i', $text, $m)) {
                return (string) $m[0];
            }
        }

        return '';
    }

    private function parse_speedtest_token($token)
    {
        $token = trim((string) $token);
        if (!preg_match('/^SPD\|([0-9]+)\|([0-9]+)\|([a-f0-9]{16})$/i', $token, $m)) {
            return array();
        }

        return array(
            'customer_id' => (int) $m[1],
            'wo_id' => (int) $m[2],
            'signature' => strtolower((string) $m[3]),
        );
    }

    private function extract_speedtest_attachment(array $message)
    {
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $last = end($message['photo']);
            if (!empty($last['file_id'])) {
                return array(
                    'ok' => true,
                    'type' => 'photo',
                    'file_id' => (string) $last['file_id'],
                    'caption' => (string) ($message['caption'] ?? ''),
                );
            }
        }

        if (!empty($message['document']['file_id'])) {
            return array(
                'ok' => true,
                'type' => 'document',
                'file_id' => (string) $message['document']['file_id'],
                'file_name' => (string) ($message['document']['file_name'] ?? ''),
                'caption' => (string) ($message['caption'] ?? ''),
            );
        }

        if (!empty($message['video']['file_id'])) {
            return array(
                'ok' => true,
                'type' => 'video',
                'file_id' => (string) $message['video']['file_id'],
                'caption' => (string) ($message['caption'] ?? ''),
            );
        }

        return array('ok' => false);
    }

    private function find_latest_installation_work_order($customer_id)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return array('success' => false, 'message' => 'customer_id tidak valid.');
        }

        if (!$this->db->table_exists('work_orders')) {
            return array('success' => false, 'message' => 'Tabel work_orders tidak ditemukan.');
        }

        $fields = !empty($this->work_order_fields) ? $this->work_order_fields : $this->db->list_fields('work_orders');
        if (empty($fields)) {
            return array('success' => false, 'message' => 'Struktur work_orders tidak terbaca.');
        }

        $open_candidates = array('open', 'OPEN', 'process', 'PROCESS', 'pending', 'PENDING', 'new', 'NEW', 'in_progress', 'IN_PROGRESS');
        $qb = $this->db
            ->select('id, wo_number, status')
            ->from('work_orders')
            ->where('customer_id', $customer_id)
            ->where_in('status', $open_candidates);

        if (in_array('wo_type', $fields, true)) {
            $qb->where('wo_type', $this->resolve_table_enum_value('work_orders', 'wo_type', array('installation', 'INSTALLATION'), 'installation'));
        } elseif (in_array('type', $fields, true)) {
            $qb->where('type', $this->resolve_table_enum_value('work_orders', 'type', array('installation', 'INSTALLATION'), 'installation'));
        }

        $wo = $qb->order_by('id', 'DESC')->limit(1)->get()->row_array();
        if (empty($wo)) {
            return array(
                'success' => false,
                'message' => 'WO installation OPEN/PROCESS tidak ditemukan.',
            );
        }

        return array(
            'success' => true,
            'wo_id' => (int) $wo['id'],
            'wo_number' => (string) ($wo['wo_number'] ?? ''),
            'status' => (string) ($wo['status'] ?? ''),
        );
    }

    private function complete_work_order_after_speedtest($customer_id, $wo_id, array $attachment, $actor_scope = '')
    {
        $customer_id = (int) $customer_id;
        $wo_id = (int) $wo_id;

        $customer = $this->db
            ->where('id', $customer_id)
            ->limit(1)
            ->get('customers')
            ->row_array();
        if (empty($customer)) {
            return array('success' => false, 'message' => 'Customer tidak ditemukan.');
        }

        $wo = $this->db
            ->where('id', $wo_id)
            ->where('customer_id', $customer_id)
            ->limit(1)
            ->get('work_orders')
            ->row_array();
        if (empty($wo)) {
            return array('success' => false, 'message' => 'Work order tidak ditemukan.');
        }

        $status_now = strtolower((string) ($wo['status'] ?? ''));
        if (in_array($status_now, array('done', 'activated', 'cancel', 'completed'), true)) {
            return array(
                'success' => true,
                'updated' => false,
                'wo_id' => $wo_id,
                'wo_number' => (string) ($wo['wo_number'] ?? ''),
                'message' => 'WO sudah selesai sebelumnya.',
            );
        }

        $now = date('Y-m-d H:i:s');
        $this->db->trans_begin();

        $update_customer = array();
        if (in_array('status', $this->customer_fields, true)) {
            $update_customer['status'] = 'active';
        }
        if (in_array('installation_status', $this->customer_fields, true)) {
            $update_customer['installation_status'] = 'completed';
        }
        if (in_array('updated_at', $this->customer_fields, true)) {
            $update_customer['updated_at'] = $now;
        }
        if (!empty($update_customer)) {
            $ok = $this->db->where('id', $customer_id)->update('customers', $update_customer);
            if (!$ok) {
                $error = $this->db->error();
                $this->db->trans_rollback();
                return array('success' => false, 'message' => 'Gagal update customer: ' . (string) ($error['message'] ?? 'unknown'));
            }
        }

        $pppoe_username = (string) ($customer['pppoe_username'] ?? '');
        if ($pppoe_username !== '' && $this->db->table_exists('pppoe_secrets') && !empty($this->pppoe_fields)) {
            $pppoe_update = array();
            if (in_array('disabled', $this->pppoe_fields, true)) {
                $pppoe_update['disabled'] = 0;
            }
            if (in_array('updated_at', $this->pppoe_fields, true)) {
                $pppoe_update['updated_at'] = $now;
            }
            if (!empty($pppoe_update)) {
                $this->db->where('username', $pppoe_username)->update('pppoe_secrets', $pppoe_update);
            }
        }

        $fields = !empty($this->work_order_fields) ? $this->work_order_fields : $this->db->list_fields('work_orders');
        $status_done = $this->resolve_table_enum_value('work_orders', 'status', array('done', 'DONE', 'completed', 'COMPLETED'), 'done');
        $note = 'Speedtest via Telegram'
            . ' | type=' . (string) ($attachment['type'] ?? '-')
            . ' | file_id=' . (string) ($attachment['file_id'] ?? '-')
            . ($actor_scope !== '' ? (' | by=' . $actor_scope) : '');

        $wo_update = array(
            'status' => $status_done,
        );
        if (in_array('done_at', $fields, true)) {
            $wo_update['done_at'] = $now;
        }
        if (in_array('updated_at', $fields, true)) {
            $wo_update['updated_at'] = $now;
        }
        if (in_array('completion_notes', $fields, true)) {
            $wo_update['completion_notes'] = $note;
        }
        if (in_array('photo_after', $fields, true)) {
            $wo_update['photo_after'] = (string) ($attachment['file_id'] ?? '');
        }

        $ok_wo = $this->db
            ->where('id', $wo_id)
            ->where('customer_id', $customer_id)
            ->update('work_orders', $wo_update);
        if (!$ok_wo) {
            $error = $this->db->error();
            $this->db->trans_rollback();
            return array('success' => false, 'message' => 'Gagal update WO: ' . (string) ($error['message'] ?? 'unknown'));
        }

        if ($this->db->trans_status() === false) {
            $error = $this->db->error();
            $this->db->trans_rollback();
            return array('success' => false, 'message' => 'Transaksi gagal: ' . (string) ($error['message'] ?? 'unknown'));
        }

        $this->db->trans_commit();

        return array(
            'success' => true,
            'updated' => true,
            'wo_id' => $wo_id,
            'wo_number' => (string) ($wo['wo_number'] ?? ''),
            'message' => 'WO updated to DONE.',
        );
    }

    private function build_speedtest_token($customer_id, $wo_id, $actor_scope = '')
    {
        $signature = $this->build_speedtest_signature((int) $customer_id, (int) $wo_id, (string) $actor_scope);
        return 'SPD|' . (int) $customer_id . '|' . (int) $wo_id . '|' . $signature;
    }

    private function build_speedtest_signature($customer_id, $wo_id, $actor_scope = '', $secret = null)
    {
        $customer_id = (int) $customer_id;
        $wo_id = (int) $wo_id;
        $actor_scope = trim((string) $actor_scope);
        $secret = is_string($secret) ? trim($secret) : $this->get_provisioning_callback_secret();
        if ($secret === '') {
            return '';
        }

        $payload = 'SPD|' . $customer_id . '|' . $wo_id . '|' . $actor_scope;
        return substr(hash_hmac('sha256', $payload, $secret), 0, 16);
    }

    private function parse_callback_data($data)
    {
        $data = trim((string) $data);
        if ($data === '') {
            return array();
        }

        // Backward compatibility: ACT|{customer_id}|{signature}
        if (preg_match('/^ACT\|([0-9]+)\|([a-f0-9]{16})$/i', $data, $m)) {
            return array(
                'action' => 'ACT',
                'customer_id' => (int) $m[1],
                'signature' => strtolower((string) $m[2]),
            );
        }

        // New format: WO|{ACTION}|{customer_id}|{signature}
        if (preg_match('/^WO\|(ACT|USR|PWD|VLAN)\|([0-9]+)\|([a-f0-9]{16})$/i', $data, $m)) {
            return array(
                'action' => strtoupper((string) $m[1]),
                'customer_id' => (int) $m[2],
                'signature' => strtolower((string) $m[3]),
            );
        }

        return array();
    }

    private function resolve_pppoe_username(array $customer)
    {
        foreach (array('pppoe_username', 'username') as $column) {
            if (in_array($column, $this->customer_fields, true)) {
                $value = trim((string) ($customer[$column] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function resolve_pppoe_password(array $customer)
    {
        foreach (array('pppoe_password', 'ppp_password') as $column) {
            if (in_array($column, $this->customer_fields, true)) {
                $value = trim((string) ($customer[$column] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $username = $this->resolve_pppoe_username($customer);
        if ($username === '' || !$this->db->table_exists('pppoe_secrets')) {
            return '';
        }

        $secret = $this->db
            ->where('username', $username)
            ->limit(1)
            ->get('pppoe_secrets')
            ->row_array();
        if (!is_array($secret)) {
            return '';
        }

        foreach (array('ppp_password', 'password') as $column) {
            if (in_array($column, $this->pppoe_fields, true)) {
                $value = trim((string) ($secret[$column] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function resolve_customer_vlan(array $customer)
    {
        foreach (array('vlan_id', 'vlan_info') as $column) {
            if (!in_array($column, $this->customer_fields, true)) {
                continue;
            }

            $value = trim((string) ($customer[$column] ?? ''));
            if ($value === '') {
                continue;
            }

            if ($column === 'vlan_info') {
                $value = preg_replace('/^VID\s*/i', '', $value);
                $value = trim((string) $value);
            }

            if ($value !== '') {
                return $value;
            }
        }

        if ($this->db->table_exists('work_orders') && !empty($this->work_order_fields)) {
            $wo = $this->db
                ->select('vlan_info')
                ->from('work_orders')
                ->where('customer_id', (int) ($customer['id'] ?? 0))
                ->where('vlan_info IS NOT NULL', null, false)
                ->where('vlan_info <>', '')
                ->order_by('id', 'DESC')
                ->limit(1)
                ->get()
                ->row_array();
            if (!empty($wo['vlan_info'])) {
                $value = preg_replace('/^VID\s*/i', '', (string) $wo['vlan_info']);
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function mark_latest_work_order_done($customer_id)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return array('success' => false, 'message' => 'customer_id tidak valid.');
        }

        if (!$this->db->table_exists('work_orders')) {
            return array('success' => true, 'updated' => false, 'message' => 'Tabel work_orders tidak ditemukan.');
        }

        $fields = !empty($this->work_order_fields) ? $this->work_order_fields : $this->db->list_fields('work_orders');
        if (empty($fields) || !in_array('id', $fields, true) || !in_array('customer_id', $fields, true) || !in_array('status', $fields, true)) {
            return array('success' => true, 'updated' => false, 'message' => 'Kolom utama work_orders tidak lengkap.');
        }

        $status_done = $this->resolve_table_enum_value('work_orders', 'status', array('done', 'DONE', 'completed', 'COMPLETED'), 'done');
        $open_candidates = array('open', 'OPEN', 'process', 'PROCESS', 'pending', 'PENDING', 'new', 'NEW', 'in_progress', 'IN_PROGRESS');

        $qb = $this->db
            ->select('id, wo_number, status')
            ->from('work_orders')
            ->where('customer_id', $customer_id)
            ->where_in('status', $open_candidates);

        if (in_array('wo_type', $fields, true)) {
            $qb->where('wo_type', $this->resolve_table_enum_value('work_orders', 'wo_type', array('installation', 'INSTALLATION'), 'installation'));
        } elseif (in_array('type', $fields, true)) {
            $qb->where('type', $this->resolve_table_enum_value('work_orders', 'type', array('installation', 'INSTALLATION'), 'installation'));
        }

        $wo = $qb->order_by('id', 'DESC')->limit(1)->get()->row_array();
        if (empty($wo)) {
            return array(
                'success' => true,
                'updated' => false,
                'message' => 'Tidak ada WO OPEN/PROCESS untuk customer ini.',
            );
        }

        $now = date('Y-m-d H:i:s');
        $update = array(
            'status' => $status_done,
        );
        if (in_array('done_at', $fields, true)) {
            $update['done_at'] = $now;
        }
        if (in_array('updated_at', $fields, true)) {
            $update['updated_at'] = $now;
        }
        if (in_array('completion_notes', $fields, true)) {
            $update['completion_notes'] = 'Selesai via tombol Telegram: SUDAH TERPASANG';
        }

        $ok = $this->db
            ->where('id', (int) $wo['id'])
            ->update('work_orders', $update);
        if (!$ok) {
            $error = $this->db->error();
            return array(
                'success' => false,
                'message' => 'DB error update work_orders: ' . (string) ($error['message'] ?? 'unknown'),
            );
        }

        return array(
            'success' => true,
            'updated' => true,
            'wo_id' => (int) $wo['id'],
            'wo_number' => (string) ($wo['wo_number'] ?? ''),
            'message' => 'Work order ditandai DONE.',
        );
    }

    private function resolve_table_enum_value($table, $column, array $candidates, $fallback = '')
    {
        $table = trim((string) $table);
        $column = trim((string) $column);
        if ($table === '' || $column === '' || empty($candidates)) {
            return (string) $fallback;
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return (string) $fallback;
        }
        if (!$this->db->table_exists($table)) {
            return (string) $fallback;
        }

        $query = $this->db->query("SHOW COLUMNS FROM `" . $this->db->escape_str($table) . "` LIKE " . $this->db->escape($column));
        $row = $query ? $query->row_array() : null;
        if (empty($row['Type']) || stripos((string) $row['Type'], 'enum(') !== 0) {
            return (string) $fallback;
        }

        $type = (string) $row['Type'];
        if (!preg_match_all("/'((?:\\\\'|[^'])*)'/", $type, $m) || empty($m[1])) {
            return (string) $fallback;
        }

        $values = array_map(static function ($value) {
            return str_replace("\\'", "'", (string) $value);
        }, $m[1]);

        foreach ($candidates as $candidate) {
            foreach ($values as $enum_value) {
                if (strcasecmp((string) $enum_value, (string) $candidate) === 0) {
                    return (string) $enum_value;
                }
            }
        }

        return in_array($fallback, $values, true) ? (string) $fallback : (string) ($values[0] ?? $fallback);
    }

    private function get_webhook_secret()
    {
        $from_env = trim((string) getenv('TELEGRAM_WEBHOOK_SECRET'));
        if ($from_env !== '') {
            return $from_env;
        }

        $this->config->load('telegram_automation', true);
        $cfg = $this->config->item('telegram_automation');
        $from_config = is_array($cfg) ? trim((string) ($cfg['telegram_webhook_secret'] ?? '')) : '';
        if ($from_config !== '') {
            return $from_config;
        }

        return '';
    }

    private function get_provisioning_callback_secret()
    {
        $from_env = trim((string) getenv('PROVISIONING_CALLBACK_SECRET'));
        if ($from_env !== '') {
            return $from_env;
        }

        $from_config = trim((string) config_item('provisioning_callback_secret'));
        if ($from_config !== '') {
            return $from_config;
        }

        return '';
    }

    private function build_callback_signature($customer_id, $action = '', $secret = null)
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

    private function json_response($http_code, array $payload)
    {
        return $this->output
            ->set_status_header((int) $http_code)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
    }
}
