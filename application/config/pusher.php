<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Pusher Realtime Notification
|--------------------------------------------------------------------------
|
| Simpan kredensial Pusher untuk websocket realtime notification.
| Direkomendasikan menggunakan ENV di server production.
|
*/

$pusher_app_id = trim((string) getenv('PUSHER_APP_ID'));
$pusher_key = trim((string) getenv('PUSHER_KEY'));
$pusher_secret = trim((string) getenv('PUSHER_SECRET'));
$pusher_cluster = trim((string) getenv('PUSHER_CLUSTER'));
$pusher_channel_public = trim((string) getenv('PUSHER_CHANNEL_PUBLIC'));
$pusher_channel_private_prefix = trim((string) getenv('PUSHER_CHANNEL_PRIVATE_PREFIX'));
$pusher_event_new_notification = trim((string) getenv('PUSHER_EVENT_NEW_NOTIFICATION'));

$pusher_use_tls_env = getenv('PUSHER_USE_TLS');
$pusher_use_tls = true;
if ($pusher_use_tls_env !== false) {
    $pusher_use_tls_env = trim((string) $pusher_use_tls_env);
    if ($pusher_use_tls_env !== '') {
        $parsed_use_tls = filter_var($pusher_use_tls_env, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $pusher_use_tls = $parsed_use_tls === null ? true : (bool) $parsed_use_tls;
    }
}

$config['pusher_app_id'] = $pusher_app_id;
$config['pusher_key'] = $pusher_key;
$config['pusher_secret'] = $pusher_secret;
$config['pusher_cluster'] = $pusher_cluster !== '' ? $pusher_cluster : 'ap1';
$config['pusher_use_tls'] = $pusher_use_tls;
$config['pusher_channel_public'] = $pusher_channel_public !== '' ? $pusher_channel_public : 'superapps-channel';
$config['pusher_channel_private_prefix'] = $pusher_channel_private_prefix !== '' ? $pusher_channel_private_prefix : 'private-user-';
$config['pusher_event_new_notification'] = $pusher_event_new_notification !== '' ? $pusher_event_new_notification : 'new-notification';
$config['pusher_enabled'] = ($pusher_app_id !== '' && $pusher_key !== '' && $pusher_secret !== '');
