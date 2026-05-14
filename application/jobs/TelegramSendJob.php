<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class TelegramSendJob
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->library('encryption');
        $this->CI->load->model('Settings_model', 'settings_model');
    }

    public function handle(array $payload, array $job = array())
    {
        $message = trim((string) ($payload['message'] ?? ''));
        $parse_mode = trim((string) ($payload['parse_mode'] ?? 'HTML'));
        $group_type = trim((string) ($payload['group_type'] ?? 'ops'));
        $router_id = (int) ($payload['router_id'] ?? 0);
        $tenant_id = (int) ($payload['tenant_id'] ?? $job['tenant_id'] ?? 0);
        $inline_keyboard = isset($payload['inline_keyboard']) && is_array($payload['inline_keyboard'])
            ? $payload['inline_keyboard']
            : array();

        if ($message === '') {
            return array(
                'success' => false,
                'message' => 'Payload telegram kosong.',
                'retryable' => false,
            );
        }

        $chat_ids = isset($payload['chat_ids']) && is_array($payload['chat_ids']) ? $payload['chat_ids'] : array();
        $bot_token = trim((string) ($payload['bot_token'] ?? ''));

        try {
            $this->CI->load->library('telegram_service');

            if (!empty($chat_ids) && $bot_token !== '') {
                $success = 0;
                $failed = 0;
                foreach ($chat_ids as $chat_id_raw) {
                    $chat_id = trim((string) $chat_id_raw);
                    if ($chat_id === '') {
                        continue;
                    }
                    $res = $this->send_to_chat($bot_token, $chat_id, $message, $parse_mode, $inline_keyboard);
                    if (!empty($res['success'])) {
                        $success++;
                    } else {
                        $failed++;
                    }
                }

                return array(
                    'success' => $failed === 0,
                    'message' => 'Telegram send done. success=' . $success . ', failed=' . $failed,
                    'retryable' => $failed > 0,
                );
            }

            // Fallback ke group tenant SaaS.
            if ($tenant_id > 0 && $this->CI->db->table_exists('telegram_groups')) {
                $sent = $this->send_to_tenant_groups(
                    $tenant_id,
                    $group_type !== '' ? $group_type : 'ops',
                    $message,
                    $parse_mode,
                    $inline_keyboard
                );
                if ($sent['success']) {
                    return array(
                        'success' => true,
                        'message' => $sent['message'],
                        'retryable' => false,
                    );
                }
            }

            // Single install mode (non-tenant): kirim ke group global.
            if ($this->CI->db->table_exists('telegram_groups')) {
                $sent_global = $this->send_to_global_groups(
                    $group_type !== '' ? $group_type : 'alert',
                    $message,
                    $parse_mode,
                    $inline_keyboard,
                    $router_id
                );
                if ($sent_global['success']) {
                    return array(
                        'success' => true,
                        'message' => $sent_global['message'],
                        'retryable' => false,
                    );
                }
            }

            // Fallback terakhir: settings_telegram legacy (single tenant).
            $legacy = $this->send_via_legacy_settings($message, $parse_mode, $inline_keyboard);
            if ($legacy['success']) {
                return array(
                    'success' => true,
                    'message' => $legacy['message'],
                    'retryable' => false,
                );
            }

            return array(
                'success' => false,
                'message' => $legacy['message'],
                'retryable' => true,
            );
        } catch (Throwable $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'retryable' => true,
            );
        }
    }

    private function send_to_tenant_groups($tenant_id, $group_type, $message, $parse_mode, array $inline_keyboard = array())
    {
        $groups = $this->CI->db
            ->from('telegram_groups')
            ->where('tenant_id', (int) $tenant_id)
            ->where('group_type', trim((string) $group_type))
            ->where('is_active', 1)
            ->get()
            ->result_array();

        if (empty($groups)) {
            return array(
                'success' => false,
                'message' => 'Group telegram tenant tidak ditemukan.',
            );
        }

        $this->CI->load->library('telegram_service');
        $sent = 0;
        $failed = 0;
        foreach ($groups as $group) {
            $chat_id = trim((string) ($group['chat_id'] ?? ''));
            $token_enc = trim((string) ($group['bot_token_enc'] ?? ''));
            if ($chat_id === '' || $token_enc === '') {
                $failed++;
                continue;
            }

            $token = $this->decode_token($token_enc);
            if ($token === '') {
                $failed++;
                continue;
            }

            $res = $this->send_to_chat($token, $chat_id, $message, $parse_mode, $inline_keyboard);
            if (!empty($res['success'])) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return array(
            'success' => $sent > 0 && $failed === 0,
            'message' => 'Telegram group send done. success=' . $sent . ', failed=' . $failed,
        );
    }

    private function send_to_global_groups($group_type, $message, $parse_mode, array $inline_keyboard = array(), $router_id = 0)
    {
        $fields = $this->CI->db->list_fields('telegram_groups');
        $type_col = in_array('type', $fields, true) ? 'type' : (in_array('group_type', $fields, true) ? 'group_type' : '');
        if ($type_col === '') {
            return array('success' => false, 'message' => 'Kolom type/group_type tidak tersedia.');
        }
        $router_col = in_array('router_id', $fields, true)
            ? 'router_id'
            : (in_array('router_scope_id', $fields, true) ? 'router_scope_id' : '');

        $qb = $this->CI->db
            ->from('telegram_groups')
            ->where($type_col, trim((string) $group_type));

        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        }
        if (in_array('tenant_id', $fields, true)) {
            $qb->where('(tenant_id IS NULL OR tenant_id = 0)', null, false);
        }
        if ($router_id > 0 && $router_col !== '') {
            $qb->where($router_col, (int) $router_id);
        }

        $groups = $qb->get()->result_array();
        if (empty($groups)) {
            if ($router_id > 0 && $router_col !== '') {
                return array('success' => false, 'message' => 'Group Telegram router tidak ditemukan (router_id=' . (int) $router_id . ').');
            }
            return array('success' => false, 'message' => 'Global group tidak ditemukan.');
        }

        $bots = array();
        if ($this->CI->db->table_exists('telegram_bots')) {
            $bots = $this->CI->db->get('telegram_bots')->result_array();
        }
        $bot_map = array();
        foreach ($bots as $bot) {
            $bot_map[(int) ($bot['id'] ?? 0)] = $bot;
        }

        $sent = 0;
        $failed = 0;
        foreach ($groups as $group) {
            $chat_id = trim((string) ($group['chat_id'] ?? ''));
            if ($chat_id === '') {
                $failed++;
                continue;
            }

            $token = '';
            $bot_id = (int) ($group['bot_id'] ?? 0);
            if ($bot_id > 0 && isset($bot_map[$bot_id])) {
                $bot = $bot_map[$bot_id];
                if (isset($bot['is_active']) && (int) $bot['is_active'] !== 1) {
                    $failed++;
                    continue;
                }

                $token_raw = trim((string) ($bot['bot_token'] ?? ''));
                if ($token_raw !== '') {
                    $dec = $this->decode_token($token_raw, true);
                    if (is_string($dec) && trim($dec) !== '') {
                        $token = trim($dec);
                    } elseif (strpos($token_raw, 'osl:') !== 0 && strpos($token_raw, 'ci3:') !== 0) {
                        $token = $token_raw;
                    }
                }
            }

            if ($token === '' && !empty($group['bot_token_enc'])) {
                $dec = $this->decode_token((string) $group['bot_token_enc']);
                if (is_string($dec) && trim($dec) !== '') {
                    $token = trim($dec);
                }
            }

            if ($token === '') {
                $failed++;
                continue;
            }

            $res = $this->send_to_chat($token, $chat_id, $message, $parse_mode, $inline_keyboard);
            if (!empty($res['success'])) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return array(
            'success' => $sent > 0 && $failed === 0,
            'message' => 'Telegram global send done. success=' . $sent . ', failed=' . $failed,
        );
    }

    private function send_via_legacy_settings($message, $parse_mode, array $inline_keyboard = array())
    {
        if (!file_exists(APPPATH . 'models/Settings_model.php')) {
            return array(
                'success' => false,
                'message' => 'Settings telegram belum tersedia.',
            );
        }

        $this->CI->load->model('Settings_model', 'settings_model');
        $cfg = $this->CI->settings_model->get_telegram_settings();
        $token = trim((string) ($cfg['bot_token'] ?? ''));
        $chat_id = trim((string) ($cfg['chat_id_admin'] ?? ''));
        $enabled = (int) ($cfg['enable_notification'] ?? 0) === 1;
        if (!$enabled || $token === '' || $chat_id === '') {
            return array(
                'success' => false,
                'message' => 'Telegram legacy config belum aktif.',
            );
        }

        $this->CI->load->library('telegram_service');
        $res = $this->send_to_chat($token, $chat_id, $message, $parse_mode, $inline_keyboard);

        return array(
            'success' => !empty($res['success']),
            'message' => !empty($res['success']) ? 'Telegram terkirim via settings_telegram.' : (string) ($res['message'] ?? 'Telegram gagal'),
        );
    }

    private function send_to_chat($token, $chat_id, $message, $parse_mode, array $inline_keyboard = array())
    {
        $token = trim((string) $token);
        $chat_id = trim((string) $chat_id);
        if ($token === '' || $chat_id === '') {
            return array('success' => false, 'message' => 'token/chat_id invalid');
        }

        $this->CI->load->library('telegram_service');
        if (!empty($inline_keyboard)) {
            return $this->CI->telegram_service->send_message_with_inline_keyboard(
                $token,
                $chat_id,
                $message,
                $inline_keyboard,
                $parse_mode
            );
        }

        return $this->CI->telegram_service->send_message($token, $chat_id, $message, $parse_mode);
    }

    private function decode_token($cipher, $allow_raw = false)
    {
        $cipher = trim((string) $cipher);
        if ($cipher === '') {
            return '';
        }

        $decoded = $this->CI->settings_model->decrypt_secret($cipher);
        if (is_string($decoded) && trim($decoded) !== '') {
            return trim($decoded);
        }

        $legacy = $this->CI->encryption->decrypt($cipher);
        if (is_string($legacy) && trim($legacy) !== '') {
            return trim($legacy);
        }

        if ($allow_raw && strpos($cipher, 'osl:') !== 0 && strpos($cipher, 'ci3:') !== 0) {
            return $cipher;
        }

        return '';
    }
}
