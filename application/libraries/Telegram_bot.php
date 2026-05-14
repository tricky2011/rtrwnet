<?php
/**
 * application/libraries/Telegram_bot.php
 *
 * Low-level Telegram Bot API wrapper.
 * Hanya kirim pesan. Tidak handle webhook/callback.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Telegram_bot
{
    private $CI;
    private $token;
    private $api_url;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model('setting_model');

        $this->token   = $this->CI->setting_model->get_value('telegram_bot_token');
        $this->api_url = "https://api.telegram.org/bot{$this->token}";
    }

    /**
     * Kirim pesan teks (Markdown parse mode)
     *
     * @param  string $chat_id
     * @param  string $text     Markdown formatted
     * @return array  ['ok' => bool, 'description' => string]
     */
    public function send_message($chat_id, $text)
    {
        $payload = [
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ];

        $result = $this->api_request('sendMessage', $payload);

        custom_log('api_telegram.log',
            "SEND chat={$chat_id} " .
            "ok=" . ($result['ok'] ? 'YES' : 'NO') .
            " len=" . strlen($text));

        return $result;
    }

    /**
     * Kirim foto dengan caption
     */
    public function send_photo($chat_id, $photo_path, $caption = '')
    {
        if (!file_exists($photo_path)) {
            return ['ok' => false, 'description' => 'File not found'];
        }

        $payload = [
            'chat_id' => $chat_id,
            'caption' => $caption,
            'parse_mode' => 'Markdown',
            'photo'   => new CURLFile($photo_path),
        ];

        return $this->api_request('sendPhoto', $payload, true);
    }

    /**
     * HTTP request ke Telegram API
     */
    private function api_request($method, $payload, $multipart = false)
    {
        $url = "{$this->api_url}/{$method}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $multipart ? $payload : json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        if (!$multipart) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);
        }

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            custom_log('api_telegram.log', "CURL ERROR: {$error}");
            return ['ok' => false, 'description' => "cURL: {$error}"];
        }

        $data = json_decode($response, true);

        if (!$data) {
            return ['ok' => false, 'description' => 'Invalid JSON response'];
        }

        return $data;
    }
}