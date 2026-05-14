<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('create_notification')) {
    /**
     * Buat notifikasi + push realtime via Pusher.
     *
     * Payload minimal:
     * - title
     * - message
     * Optional:
     * - user_id
     * - target_user_ids (array)
     * - router_id
     * - brand_id
     * - type
     * - category
     * - reference_id
     * - reference_type
     *
     * @param array $data
     * @return array
     */
    function create_notification(array $data)
    {
        $CI =& get_instance();
        $CI->load->model('Notification_model');
        $CI->load->library('pusher_lib');

        $title = trim((string) ($data['title'] ?? 'Notifikasi'));
        $message = trim((string) ($data['message'] ?? ''));
        if ($title === '' || $message === '') {
            return array(
                'success' => false,
                'message' => 'title/message notifikasi wajib diisi.',
                'inserted_ids' => array(),
            );
        }

        $base = array(
            'brand_id' => isset($data['brand_id']) ? $data['brand_id'] : null,
            'router_id' => isset($data['router_id']) ? $data['router_id'] : null,
            'type' => isset($data['type']) ? $data['type'] : 'info',
            'category' => isset($data['category']) ? $data['category'] : 'general',
            'title' => $title,
            'message' => $message,
            'reference_id' => isset($data['reference_id']) ? $data['reference_id'] : null,
            'reference_type' => isset($data['reference_type']) ? $data['reference_type'] : null,
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        );

        $target_user_ids = array();
        if (isset($data['target_user_ids']) && is_array($data['target_user_ids'])) {
            foreach ($data['target_user_ids'] as $uid) {
                $uid = (int) $uid;
                if ($uid > 0) {
                    $target_user_ids[$uid] = $uid;
                }
            }
        } elseif (isset($data['user_id']) && (int) $data['user_id'] > 0) {
            $uid = (int) $data['user_id'];
            $target_user_ids[$uid] = $uid;
        }

        $inserted_ids = array();
        $pushed = 0;

        if (!empty($target_user_ids)) {
            foreach ($target_user_ids as $uid) {
                $row = $base;
                $row['user_id'] = $uid;
                $notif_id = (int) $CI->Notification_model->insert($row);
                if ($notif_id <= 0) {
                    continue;
                }

                $inserted_ids[] = $notif_id;
                $payload = array(
                    'id' => $notif_id,
                    'user_id' => $uid,
                    'title' => $row['title'],
                    'message' => $row['message'],
                    'category' => $row['category'],
                    'type' => $row['type'],
                    'router_id' => $row['router_id'],
                    'reference_id' => $row['reference_id'],
                    'reference_type' => $row['reference_type'],
                    'created_at' => $row['created_at'],
                    'is_read' => 0,
                );

                if ($CI->pusher_lib->trigger_user($uid, $CI->pusher_lib->get_event_name(), $payload)) {
                    $pushed++;
                }
            }
        } else {
            $row = $base;
            $row['user_id'] = null;
            $notif_id = (int) $CI->Notification_model->insert($row);
            if ($notif_id > 0) {
                $inserted_ids[] = $notif_id;
                $payload = array(
                    'id' => $notif_id,
                    'user_id' => null,
                    'title' => $row['title'],
                    'message' => $row['message'],
                    'category' => $row['category'],
                    'type' => $row['type'],
                    'router_id' => $row['router_id'],
                    'reference_id' => $row['reference_id'],
                    'reference_type' => $row['reference_type'],
                    'created_at' => $row['created_at'],
                    'is_read' => 0,
                );

                if ($CI->pusher_lib->trigger($CI->pusher_lib->get_public_channel(), $CI->pusher_lib->get_event_name(), $payload)) {
                    $pushed++;
                }
            }
        }

        return array(
            'success' => !empty($inserted_ids),
            'message' => !empty($inserted_ids)
                ? 'Notifikasi berhasil dibuat.'
                : 'Gagal menyimpan notifikasi ke database.',
            'inserted_ids' => $inserted_ids,
            'pushed' => $pushed,
        );
    }
}

if (!function_exists('create_notification_for_roles')) {
    /**
     * Helper distribusi notifikasi ke role tertentu (router-aware).
     *
     * @param array $roles
     * @param array $data
     * @param int|null $router_id
     * @return array
     */
    function create_notification_for_roles(array $roles, array $data, $router_id = null)
    {
        $CI =& get_instance();
        $CI->load->model('Notification_model');

        $router_id = $router_id !== null ? (int) $router_id : 0;
        $user_ids = $CI->Notification_model->get_target_user_ids_by_roles($roles, $router_id > 0 ? $router_id : null);
        if (empty($user_ids)) {
            return array(
                'success' => false,
                'message' => 'Target user notifikasi tidak ditemukan.',
                'inserted_ids' => array(),
                'pushed' => 0,
            );
        }

        $data['target_user_ids'] = $user_ids;
        if (!isset($data['router_id']) && $router_id > 0) {
            $data['router_id'] = $router_id;
        }

        return create_notification($data);
    }
}

