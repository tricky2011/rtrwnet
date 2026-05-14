<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Telegram Automation Config
|--------------------------------------------------------------------------
| Chat ID bisa diisi sesuai kebutuhan:
| - group teknisi/ops
| - group finance
| - group manajemen (ROI)
*/

$config['telegram_app_name'] = getenv('APP_BRAND_NAME') ?: 'RTRWNet';
$config['telegram_bot_token'] = getenv('TELEGRAM_BOT_TOKEN') ?: '';

// Fallback chat id jika method tidak menerima chat id explicit
$config['telegram_default_chat_id'] = '';

// Chat ID khusus use-case
$config['telegram_ops_chat_id'] = '';
$config['telegram_finance_chat_id'] = '';
$config['telegram_management_chat_id'] = '';

// Security token untuk header webhook Telegram:
// X-Telegram-Bot-Api-Secret-Token
$config['telegram_webhook_secret'] = getenv('TELEGRAM_WEBHOOK_SECRET') ?: '';
