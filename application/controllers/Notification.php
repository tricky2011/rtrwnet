<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Notification extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->model('Notification_model', 'notification_model');
        $this->load->library('pusher_lib');
        $this->load->helper(array('url', 'notification'));
    }

    public function latest()
    {
        $limit = (int) $this->input->get('limit', true);
        if ($limit <= 0) {
            $limit = 10;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $user_id = (int) $this->session->userdata('user_id');
        $role = (string) $this->session->userdata('role');
        $effective_router_id = $this->getEffectiveRouterId();
        $router_id = $effective_router_id !== null ? (int) $effective_router_id : 0;

        $items = $this->notification_model->get_recent_for_user($user_id, array(
            'role' => $role,
            'limit' => $limit,
            'router_id' => $router_id > 0 ? $router_id : null,
        ));
        $unread = $this->notification_model->get_unread_count_for_user($user_id, array(
            'router_id' => $router_id > 0 ? $router_id : null,
        ));

        foreach ($items as &$item) {
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['is_read'] = (int) ($item['is_read'] ?? 0);
            $item['title'] = (string) ($item['title'] ?? '');
            $item['message'] = (string) ($item['message'] ?? '');
            $item['type'] = (string) ($item['type'] ?? 'info');
            $item['category'] = (string) ($item['category'] ?? 'general');
            $item['created_at'] = (string) ($item['created_at'] ?? '');
        }
        unset($item);

        $this->json_response(array(
            'success' => true,
            'items' => $items,
            'unread_count' => (int) $unread,
        ));
    }

    public function unread_count()
    {
        $user_id = (int) $this->session->userdata('user_id');
        $effective_router_id = $this->getEffectiveRouterId();
        $router_id = $effective_router_id !== null ? (int) $effective_router_id : 0;

        $unread = $this->notification_model->get_unread_count_for_user($user_id, array(
            'router_id' => $router_id > 0 ? $router_id : null,
        ));

        $this->json_response(array(
            'success' => true,
            'unread_count' => (int) $unread,
        ));
    }

    public function mark_read($id = 0)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Method Not Allowed.',
            ), 405);
        }

        $id = (int) $id;
        $user_id = (int) $this->session->userdata('user_id');
        $effective_router_id = $this->getEffectiveRouterId();
        $router_id = $effective_router_id !== null ? (int) $effective_router_id : 0;

        $ok = $this->notification_model->mark_read_for_user($id, $user_id, array(
            'router_id' => $router_id > 0 ? $router_id : null,
        ));

        $unread = $this->notification_model->get_unread_count_for_user($user_id, array(
            'router_id' => $router_id > 0 ? $router_id : null,
        ));

        $this->json_response(array(
            'success' => (bool) $ok,
            'message' => $ok ? 'Notifikasi ditandai dibaca.' : 'Notifikasi tidak ditemukan.',
            'unread_count' => (int) $unread,
        ), $ok ? 200 : 404);
    }

    public function mark_all_read()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Method Not Allowed.',
            ), 405);
        }

        $user_id = (int) $this->session->userdata('user_id');
        $effective_router_id = $this->getEffectiveRouterId();
        $router_id = $effective_router_id !== null ? (int) $effective_router_id : 0;

        $ok = $this->notification_model->mark_all_read_for_user($user_id, array(
            'router_id' => $router_id > 0 ? $router_id : null,
        ));

        $this->json_response(array(
            'success' => (bool) $ok,
            'message' => $ok ? 'Semua notifikasi ditandai dibaca.' : 'Tidak ada notifikasi untuk diupdate.',
            'unread_count' => 0,
        ));
    }

    public function auth()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Method Not Allowed.',
            ), 405);
        }

        if (!$this->session->userdata('logged_in')) {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Unauthorized.',
            ), 401);
        }

        $socket_id = trim((string) $this->input->post('socket_id', true));
        $channel_name = trim((string) $this->input->post('channel_name', true));
        if ($socket_id === '' || $channel_name === '') {
            return $this->json_response(array(
                'success' => false,
                'message' => 'socket_id / channel_name wajib.',
            ), 422);
        }

        $channel_user_id = $this->pusher_lib->extract_user_id_from_private_channel($channel_name);
        if ($channel_user_id <= 0) {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Channel tidak valid.',
            ), 403);
        }

        $session_user_id = (int) $this->session->userdata('user_id');
        if ($channel_user_id <= 0 || $session_user_id <= 0 || $channel_user_id !== $session_user_id) {
            return $this->json_response(array(
                'success' => false,
                'message' => 'Unauthorized channel subscription.',
            ), 403);
        }

        if (!$this->pusher_lib->is_ready()) {
            log_message('debug', '[PUSHER] auth skipped because realtime client is not ready for channel: ' . $channel_name);
            return $this->json_response(array(
                'success' => false,
                'message' => 'Realtime notification sementara tidak tersedia.',
            ), 503);
        }

        $auth_response = $this->pusher_lib->authenticate_private_channel(
            $socket_id,
            $channel_name
        );

        if ($auth_response === false) {
            log_message('error', '[PUSHER] private auth failed for user_id=' . $session_user_id . ' channel=' . $channel_name);
            return $this->json_response(array(
                'success' => false,
                'message' => 'Gagal otorisasi channel.',
            ), 503);
        }

        // Pusher expects raw JSON body: {"auth":"..."}
        if (is_array($auth_response)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode($auth_response));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output((string) $auth_response);
    }

    protected function json_response(array $data, $status_code = 200)
    {
        if (!isset($data['csrf'])) {
            $data['csrf'] = array(
                'name' => $this->security->get_csrf_token_name(),
                'hash' => $this->security->get_csrf_hash(),
            );
        }

        return $this->output
            ->set_status_header((int) $status_code)
            ->set_content_type('application/json')
            ->set_output(json_encode($data));
    }
}
