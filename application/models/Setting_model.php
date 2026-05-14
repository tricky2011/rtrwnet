<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Compatibility adapter for existing libraries that call `setting_model`.
 */
class Setting_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('settings_model');
    }

    public function get_value($key, $default = null)
    {
        $key = (string) $key;

        switch ($key) {
            case 'mikrotik_ip':
            case 'mikrotik_host':
                return $this->settings_model->get_mikrotik_settings()['host'] ?: $default;

            case 'mikrotik_user':
                return $this->settings_model->get_mikrotik_settings()['username'] ?: $default;

            case 'mikrotik_pass':
                return $this->settings_model->get_mikrotik_settings()['password'] ?: $default;

            case 'mikrotik_port':
                return $this->settings_model->get_mikrotik_settings()['api_port'] ?: $default;

            case 'mikrotik_ssl':
                return $this->settings_model->get_mikrotik_settings()['use_ssl'];

            case 'telegram_bot_token':
                return $this->settings_model->get_telegram_settings()['bot_token'] ?: $default;

            case 'telegram_group_teknisi':
                return $this->settings_model->get_telegram_settings()['chat_id_admin'] ?: $default;

            default:
                return $default;
        }
    }
}
