<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| WhatsApp Gateway Configuration
|--------------------------------------------------------------------------
| Nilai sensitif seperti token/API key sebaiknya diisi lewat environment.
| Controller tidak boleh hardcode token.
|
| Contoh .env/server env:
| WA_API_URL=https://gateway.example.com/api/send
| WA_API_TOKEN=xxxxx
| WA_SENDER=62812xxxxxxx
| WA_ENABLED=true
*/

if (!function_exists('wa_config_bool')) {
    function wa_config_bool($key, $default = false)
    {
        $value = getenv($key);
        if ($value === false || trim((string) $value) === '') {
            return (bool) $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed === null ? (bool) $default : (bool) $parsed;
    }
}

$config['wa_provider'] = trim((string) (getenv('WA_PROVIDER') ?: 'gateway'));
$config['wa_api_url'] = trim((string) getenv('WA_API_URL'));
$config['wa_api_token'] = trim((string) getenv('WA_API_TOKEN'));
$config['wa_sender'] = trim((string) getenv('WA_SENDER'));
$config['wa_delay_seconds'] = max(1, (int) (getenv('WA_DELAY_SECONDS') ?: 15));
$config['wa_enabled'] = wa_config_bool('WA_ENABLED', true);

// Queue/anti-spam.
$config['wa_queue_limit'] = max(1, (int) (getenv('WA_QUEUE_LIMIT') ?: 10));
$config['wa_max_retry'] = max(1, (int) (getenv('WA_MAX_RETRY') ?: 3));
$config['wa_timeout_seconds'] = max(5, (int) (getenv('WA_TIMEOUT_SECONDS') ?: 20));
$config['wa_connect_timeout_seconds'] = max(2, (int) (getenv('WA_CONNECT_TIMEOUT_SECONDS') ?: 8));
$config['wa_processing_timeout_minutes'] = max(1, (int) (getenv('WA_PROCESSING_TIMEOUT_MINUTES') ?: 15));
$config['wa_due_reminder_days'] = array(3, 1, 0);
$config['wa_reminder_limit'] = max(1, (int) (getenv('WA_REMINDER_LIMIT') ?: 100));
$config['wa_cron_secret'] = trim((string) getenv('WA_CRON_SECRET'));

// Informasi pembayaran yang akan masuk ke template pesan.
$config['wa_payment_info'] = trim((string) (getenv('WA_PAYMENT_INFO') ?: 'Silakan lakukan pembayaran sesuai instruksi pada invoice atau hubungi admin billing.'));

/*
| Payload default fleksibel dan mudah diganti sesuai vendor.
| Placeholder yang tersedia: {sender}, {phone}, {message}.
|
| Contoh vendor lain:
| $config['wa_payload_template'] = array(
|     'api_key' => '{token}',
|     'device' => '{sender}',
|     'target' => '{phone}',
|     'text' => '{message}',
| );
*/
$config['wa_payload_template'] = array(
    'sender' => '{sender}',
    'number' => '{phone}',
    'message' => '{message}',
);

// Default: Authorization: Bearer {token}. Kosongkan prefix jika vendor memakai token polos.
$config['wa_token_header'] = trim((string) (getenv('WA_TOKEN_HEADER') ?: 'Authorization'));
$config['wa_token_prefix'] = trim((string) (getenv('WA_TOKEN_PREFIX') ?: 'Bearer'));
