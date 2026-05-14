<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Telegram_service
{
    public function send_message($bot_token, $chat_id, $message, $parse_mode = 'HTML')
    {
        $bot_token = trim((string) $bot_token);
        $chat_id = trim((string) $chat_id);
        $message = (string) $message;

        if ($bot_token === '' || $chat_id === '' || $message === '') {
            return array(
                'success' => false,
                'message' => 'Bot token, chat ID, dan message wajib diisi.',
            );
        }

        $url = 'https://api.telegram.org/bot' . $bot_token . '/sendMessage';
        $payload = array(
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => $parse_mode,
            'disable_web_page_preview' => true,
        );

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ));

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            log_message('error', '[Telegram_service] cURL error: ' . $curl_error);
            return array('success' => false, 'message' => 'cURL error: ' . $curl_error);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            log_message('error', '[Telegram_service] Invalid JSON response: ' . $response);
            return array('success' => false, 'message' => 'Response Telegram tidak valid.');
        }

        if ($http_code >= 200 && $http_code < 300 && !empty($decoded['ok'])) {
            return array('success' => true, 'message' => 'Pesan test Telegram berhasil dikirim.');
        }

        $description = isset($decoded['description']) ? $decoded['description'] : 'Unknown Telegram error';
        log_message('error', '[Telegram_service] API error: ' . $description);
        return array('success' => false, 'message' => $description);
    }

    public function send_message_with_inline_keyboard($bot_token, $chat_id, $message, array $inline_keyboard, $parse_mode = 'HTML')
    {
        $bot_token = trim((string) $bot_token);
        $chat_id = trim((string) $chat_id);
        $message = (string) $message;

        if ($bot_token === '' || $chat_id === '' || $message === '' || empty($inline_keyboard)) {
            return array(
                'success' => false,
                'message' => 'Bot token, chat ID, message, dan inline keyboard wajib diisi.',
            );
        }

        $payload = array(
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => $parse_mode,
            'disable_web_page_preview' => true,
            'reply_markup' => array(
                'inline_keyboard' => $inline_keyboard,
            ),
        );

        return $this->request($bot_token, 'sendMessage', $payload);
    }

    public function send_message_with_reply_markup($bot_token, $chat_id, $message, array $reply_markup, $parse_mode = 'HTML')
    {
        $bot_token = trim((string) $bot_token);
        $chat_id = trim((string) $chat_id);
        $message = (string) $message;

        if ($bot_token === '' || $chat_id === '' || $message === '' || empty($reply_markup)) {
            return array(
                'success' => false,
                'message' => 'Bot token, chat ID, message, dan reply markup wajib diisi.',
            );
        }

        $payload = array(
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => $parse_mode,
            'disable_web_page_preview' => true,
            'reply_markup' => $reply_markup,
        );

        return $this->request($bot_token, 'sendMessage', $payload);
    }

    public function answer_callback_query($bot_token, $callback_query_id, $text, $show_alert = false)
    {
        $bot_token = trim((string) $bot_token);
        $callback_query_id = trim((string) $callback_query_id);
        $text = (string) $text;

        if ($bot_token === '' || $callback_query_id === '') {
            return array(
                'success' => false,
                'message' => 'Bot token dan callback_query_id wajib diisi.',
            );
        }

        $payload = array(
            'callback_query_id' => $callback_query_id,
            'text' => $text,
            'show_alert' => !empty($show_alert),
        );

        return $this->request($bot_token, 'answerCallbackQuery', $payload);
    }

    private function request($bot_token, $method, array $payload)
    {
        $url = 'https://api.telegram.org/bot' . $bot_token . '/' . $method;

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ));

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            log_message('error', '[Telegram_service] cURL error: ' . $curl_error);
            return array('success' => false, 'message' => 'cURL error: ' . $curl_error);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            log_message('error', '[Telegram_service] Invalid JSON response: ' . $response);
            return array('success' => false, 'message' => 'Response Telegram tidak valid.');
        }

        if ($http_code >= 200 && $http_code < 300 && !empty($decoded['ok'])) {
            return array(
                'success' => true,
                'message' => 'Telegram request berhasil.',
                'data' => $decoded,
            );
        }

        $description = isset($decoded['description']) ? $decoded['description'] : 'Unknown Telegram error';
        log_message('error', '[Telegram_service] API error: ' . $description);
        return array('success' => false, 'message' => $description, 'data' => $decoded);
    }
}
