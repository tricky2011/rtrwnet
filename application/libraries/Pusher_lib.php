<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use Pusher\Pusher;

class Pusher_lib
{
    protected $CI;
    protected $client = null;
    protected $config = array();
    protected $booted = false;
    protected $ready = false;

    public function __construct($params = array())
    {
        $this->CI =& get_instance();

        $this->CI->config->load('pusher', true);
        $cfg = (array) $this->CI->config->item('pusher');

        $this->config = array(
            'app_id' => trim((string) ($cfg['pusher_app_id'] ?? '')),
            'key' => trim((string) ($cfg['pusher_key'] ?? '')),
            'secret' => trim((string) ($cfg['pusher_secret'] ?? '')),
            'cluster' => trim((string) ($cfg['pusher_cluster'] ?? 'ap1')),
            'use_tls' => array_key_exists('pusher_use_tls', $cfg) ? (bool) $cfg['pusher_use_tls'] : true,
            'channel_public' => trim((string) ($cfg['pusher_channel_public'] ?? 'superapps-channel')),
            'channel_private_prefix' => trim((string) ($cfg['pusher_channel_private_prefix'] ?? 'private-user-')),
            'event_new_notification' => trim((string) ($cfg['pusher_event_new_notification'] ?? 'new-notification')),
        );

        if (!empty($params) && is_array($params)) {
            $this->config = array_merge($this->config, $params);
        }
    }

    public function is_configured()
    {
        return $this->config['app_id'] !== ''
            && $this->config['key'] !== ''
            && $this->config['secret'] !== '';
    }

    public function isConfigured()
    {
        return $this->is_configured();
    }

    public function is_ready()
    {
        $this->bootstrap();
        return $this->ready;
    }

    public function get_public_channel()
    {
        return (string) $this->config['channel_public'];
    }

    public function get_private_prefix()
    {
        return (string) $this->config['channel_private_prefix'];
    }

    public function get_user_channel_name($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return '';
        }

        return $this->get_private_prefix() . $user_id;
    }

    public function extract_user_id_from_private_channel($channel_name)
    {
        $channel_name = trim((string) $channel_name);
        if ($channel_name === '') {
            return 0;
        }

        $prefix = $this->get_private_prefix();
        if ($prefix === '') {
            return 0;
        }

        $pattern = '/^' . preg_quote($prefix, '/') . '(\d+)$/';
        if (!preg_match($pattern, $channel_name, $matches)) {
            return 0;
        }

        return (int) ($matches[1] ?? 0);
    }

    public function get_event_name()
    {
        return (string) $this->config['event_new_notification'];
    }

    public function trigger($channel, $event, array $data = array())
    {
        $this->bootstrap();
        if (!$this->ready) {
            return false;
        }

        $channel = trim((string) $channel);
        $event = trim((string) $event);
        if ($channel === '' || $event === '') {
            return false;
        }

        try {
            $this->client->trigger($channel, $event, $data);
            return true;
        } catch (Throwable $e) {
            log_message('error', '[PUSHER] trigger failed: ' . $e->getMessage());
            return false;
        }
    }

    public function trigger_user($user_id, $event, array $data = array())
    {
        $channel = $this->get_user_channel_name($user_id);
        if ($channel === '') {
            return false;
        }

        return $this->trigger($channel, $event, $data);
    }

    public function authenticate_private_channel($socket_id, $channel_name, $custom_data = null)
    {
        $this->bootstrap();
        if (!$this->ready) {
            return false;
        }

        $socket_id = trim((string) $socket_id);
        $channel_name = trim((string) $channel_name);
        if ($socket_id === '' || $channel_name === '') {
            return false;
        }

        $normalized_custom_data = null;
        if (!$this->normalize_custom_data($custom_data, $normalized_custom_data)) {
            log_message('error', '[PUSHER] auth failed: invalid custom data payload.');
            return false;
        }

        try {
            if (method_exists($this->client, 'authorizeChannel')) {
                return $this->client->authorizeChannel($channel_name, $socket_id, $normalized_custom_data);
            }

            if (method_exists($this->client, 'socket_auth')) {
                return $this->client->socket_auth($channel_name, $socket_id, $normalized_custom_data);
            }
        } catch (Throwable $e) {
            log_message('error', '[PUSHER] auth failed: ' . $e->getMessage());
        }

        return false;
    }

    protected function normalize_custom_data($custom_data, &$normalized_custom_data)
    {
        $normalized_custom_data = null;

        if ($custom_data === null) {
            return true;
        }

        if (is_array($custom_data) || is_object($custom_data)) {
            if (empty((array) $custom_data)) {
                return true;
            }

            try {
                $normalized_custom_data = json_encode($custom_data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                return true;
            } catch (Throwable $e) {
                log_message('error', '[PUSHER] custom data encoding failed: ' . $e->getMessage());
                return false;
            }
        }

        $normalized_custom_data = trim((string) $custom_data);
        if ($normalized_custom_data === '') {
            $normalized_custom_data = null;
        }

        return true;
    }

    protected function bootstrap()
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $this->client = null;
        $this->ready = false;

        if (!$this->is_configured()) {
            log_message('debug', '[PUSHER] config incomplete, realtime disabled.');
            return;
        }

        if (!class_exists('Pusher\\Pusher')) {
            $autoload = FCPATH . 'vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }

        if (!class_exists('Pusher\\Pusher')) {
            log_message('error', '[PUSHER] SDK class not found.');
            return;
        }

        try {
            $options = array(
                'cluster' => $this->config['cluster'] !== '' ? $this->config['cluster'] : 'ap1',
                'useTLS' => (bool) $this->config['use_tls'],
            );

            $this->client = new Pusher(
                (string) $this->config['key'],
                (string) $this->config['secret'],
                (string) $this->config['app_id'],
                $options
            );
            $this->ready = true;
        } catch (Throwable $e) {
            $this->client = null;
            $this->ready = false;
            log_message('error', '[PUSHER] bootstrap failed: ' . $e->getMessage());
        }
    }
}
