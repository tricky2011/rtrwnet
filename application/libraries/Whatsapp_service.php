<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Whatsapp_service
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->config('whatsapp');
        $this->CI->load->model('Whatsapp_log_model', 'whatsapp_log_model');
    }

    /**
     * Queue pesan WhatsApp. Pengiriman aktual dilakukan oleh send_from_queue().
     */
    public function send_message($phone, $message, $invoice_id = null, $customer_id = null)
    {
        $phone = trim((string) $phone);
        $message = trim((string) $message);
        $normalized = $this->normalize_phone($phone);

        if ($message === '') {
            return array('success' => false, 'queued' => false, 'message' => 'Pesan WhatsApp kosong.');
        }

        if ($normalized === '') {
            $log_id = $this->CI->whatsapp_log_model->insert_log(array(
                'customer_id' => $customer_id,
                'invoice_id' => $invoice_id,
                'phone' => $phone,
                'normalized_phone' => '',
                'message' => $message,
                'status' => 'failed',
                'error_message' => 'Nomor WhatsApp tidak valid.',
                'retry_count' => (int) $this->CI->config->item('wa_max_retry'),
            ));

            return array(
                'success' => false,
                'queued' => false,
                'id' => $log_id,
                'message' => 'Nomor WhatsApp tidak valid.',
            );
        }

        $log_id = $this->CI->whatsapp_log_model->insert_log(array(
            'customer_id' => $customer_id,
            'invoice_id' => $invoice_id,
            'phone' => $phone,
            'normalized_phone' => $normalized,
            'message' => $message,
            'status' => 'pending',
        ));

        if (!$log_id) {
            return array(
                'success' => false,
                'queued' => false,
                'message' => 'Gagal membuat log WhatsApp. Pastikan tabel wa_message_logs sudah dibuat.',
            );
        }

        return array(
            'success' => true,
            'queued' => true,
            'id' => (int) $log_id,
            'message' => 'Pesan masuk queue WhatsApp.',
        );
    }

    public function normalize_phone($phone)
    {
        $phone = preg_replace('/\D+/', '', (string) $phone);
        if ($phone === '') {
            return '';
        }

        if (strpos($phone, '00') === 0) {
            $phone = substr($phone, 2);
        }
        if (strpos($phone, '620') === 0) {
            $phone = '62' . substr($phone, 3);
        } elseif (strpos($phone, '0') === 0) {
            $phone = '62' . substr($phone, 1);
        } elseif (strpos($phone, '8') === 0) {
            $phone = '62' . $phone;
        }

        return preg_match('/^628\d{7,12}$/', $phone) ? $phone : '';
    }

    public function send_from_queue($limit = 10)
    {
        $limit = max(1, min((int) $limit, (int) $this->CI->config->item('wa_queue_limit')));
        $max_retry = max(1, (int) $this->CI->config->item('wa_max_retry'));
        $delay = max(1, (int) $this->CI->config->item('wa_delay_seconds'));

        if (!$this->CI->whatsapp_log_model->table_ready()) {
            return array(
                'success' => false,
                'message' => 'Tabel wa_message_logs belum tersedia.',
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
            );
        }

        if (!$this->is_enabled()) {
            return array(
                'success' => false,
                'message' => 'WhatsApp Gateway sedang nonaktif.',
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
            );
        }

        $released = $this->CI->whatsapp_log_model->release_stale_processing(
            (int) $this->CI->config->item('wa_processing_timeout_minutes')
        );
        $rows = $this->CI->whatsapp_log_model->get_pending_messages($limit, $max_retry);
        $stats = array(
            'success' => true,
            'message' => 'Process queue selesai.',
            'checked' => count($rows),
            'released_stale_processing' => $released,
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => array(),
        );

        foreach ($rows as $idx => $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || !$this->CI->whatsapp_log_model->mark_processing($id)) {
                $stats['skipped']++;
                continue;
            }

            $stats['processed']++;
            $result = $this->deliver_message($row);

            if (!empty($result['success'])) {
                $this->CI->whatsapp_log_model->mark_sent($id, $result['raw_response'] ?? '');
                $stats['sent']++;
            } else {
                $this->CI->whatsapp_log_model->mark_failed(
                    $id,
                    $result['raw_response'] ?? '',
                    (string) ($result['message'] ?? 'Gateway error')
                );
                $stats['failed']++;
                $stats['errors'][] = array(
                    'id' => $id,
                    'message' => (string) ($result['message'] ?? 'Gateway error'),
                );
            }

            if ($idx < count($rows) - 1) {
                sleep($delay);
            }
        }

        return $stats;
    }

    public function resend_message($log_id)
    {
        return $this->CI->whatsapp_log_model->retry_message(
            (int) $log_id,
            max(1, (int) $this->CI->config->item('wa_max_retry'))
        );
    }

    private function is_enabled()
    {
        return (bool) $this->CI->config->item('wa_enabled');
    }

    private function deliver_message(array $log)
    {
        $api_url = trim((string) $this->CI->config->item('wa_api_url'));
        if ($api_url === '') {
            return array('success' => false, 'message' => 'WA_API_URL belum dikonfigurasi.');
        }

        $phone = $this->normalize_phone((string) ($log['normalized_phone'] ?? $log['phone'] ?? ''));
        if ($phone === '') {
            return array('success' => false, 'message' => 'Nomor WhatsApp tidak valid.');
        }

        $payload = $this->build_payload($phone, (string) ($log['message'] ?? ''));
        $headers = array('Content-Type: application/json');
        $token = trim((string) $this->CI->config->item('wa_api_token'));
        if ($token !== '') {
            $header_name = trim((string) $this->CI->config->item('wa_token_header'));
            $prefix = trim((string) $this->CI->config->item('wa_token_prefix'));
            if ($header_name !== '') {
                $headers[] = $header_name . ': ' . ($prefix !== '' ? $prefix . ' ' : '') . $token;
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $api_url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(5, (int) $this->CI->config->item('wa_timeout_seconds')),
            CURLOPT_CONNECTTIMEOUT => max(2, (int) $this->CI->config->item('wa_connect_timeout_seconds')),
        ));

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            return array(
                'success' => false,
                'message' => 'cURL error: ' . $curl_error,
                'raw_response' => (string) $response,
                'http_code' => $http_code,
            );
        }

        return $this->parse_gateway_response((string) $response, $http_code);
    }

    private function build_payload($phone, $message)
    {
        $template = $this->CI->config->item('wa_payload_template');
        if (!is_array($template) || empty($template)) {
            $template = array(
                'sender' => '{sender}',
                'number' => '{phone}',
                'message' => '{message}',
            );
        }

        $replacements = array(
            '{sender}' => trim((string) $this->CI->config->item('wa_sender')),
            '{phone}' => (string) $phone,
            '{message}' => (string) $message,
            '{token}' => trim((string) $this->CI->config->item('wa_api_token')),
        );

        return $this->replace_payload_placeholders($template, $replacements);
    }

    private function replace_payload_placeholders($value, array $replacements)
    {
        if (is_array($value)) {
            $result = array();
            foreach ($value as $key => $item) {
                $result[$key] = $this->replace_payload_placeholders($item, $replacements);
            }
            return $result;
        }

        if (is_string($value)) {
            return strtr($value, $replacements);
        }

        return $value;
    }

    private function parse_gateway_response($response, $http_code)
    {
        $decoded = json_decode($response, true);
        $has_json = is_array($decoded);

        if ($http_code < 200 || $http_code >= 300) {
            return array(
                'success' => false,
                'message' => $this->extract_error_message($decoded, 'HTTP ' . $http_code),
                'raw_response' => $response,
                'http_code' => $http_code,
            );
        }

        if (!$has_json) {
            return array(
                'success' => trim($response) !== '',
                'message' => trim($response) !== '' ? 'Gateway response non-JSON diterima.' : 'Response gateway kosong.',
                'raw_response' => $response,
                'http_code' => $http_code,
            );
        }

        if (isset($decoded['success'])) {
            $success = filter_var($decoded['success'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($success === false) {
                return array(
                    'success' => false,
                    'message' => $this->extract_error_message($decoded, 'Gateway mengembalikan success=false.'),
                    'raw_response' => $response,
                    'http_code' => $http_code,
                );
            }
        }

        if (isset($decoded['ok']) && !$decoded['ok']) {
            return array(
                'success' => false,
                'message' => $this->extract_error_message($decoded, 'Gateway mengembalikan ok=false.'),
                'raw_response' => $response,
                'http_code' => $http_code,
            );
        }

        if (isset($decoded['status'])) {
            $status = strtolower(trim((string) $decoded['status']));
            if (in_array($status, array('error', 'failed', 'fail', 'logout', 'unauthorized'), true)) {
                return array(
                    'success' => false,
                    'message' => $this->extract_error_message($decoded, 'Gateway status: ' . $status),
                    'raw_response' => $response,
                    'http_code' => $http_code,
                );
            }
        }

        if (isset($decoded['error']) && !empty($decoded['error'])) {
            return array(
                'success' => false,
                'message' => $this->extract_error_message($decoded, 'Gateway error.'),
                'raw_response' => $response,
                'http_code' => $http_code,
            );
        }

        return array(
            'success' => true,
            'message' => 'Pesan terkirim.',
            'raw_response' => $response,
            'http_code' => $http_code,
        );
    }

    private function extract_error_message($decoded, $fallback)
    {
        if (is_array($decoded)) {
            foreach (array('message', 'error_message', 'description', 'error') as $key) {
                if (!isset($decoded[$key])) {
                    continue;
                }
                if (is_scalar($decoded[$key]) && trim((string) $decoded[$key]) !== '') {
                    return (string) $decoded[$key];
                }
                if (is_array($decoded[$key])) {
                    return json_encode($decoded[$key]);
                }
            }
        }

        return (string) $fallback;
    }
}
