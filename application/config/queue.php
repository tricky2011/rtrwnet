<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Queue Configuration
|--------------------------------------------------------------------------
| Driver:
| - redis   : gunakan Redis (phpredis / predis) sebagai distributed queue
| - database: fallback polling dari tabel background_jobs
|
| Installer final mengendalikan nilai ini via .env runtime:
| - QUEUE_DRIVER
| - QUEUE_ENABLE_ASYNC
| - REDIS_HOST / REDIS_PORT / REDIS_DB / REDIS_TIMEOUT / REDIS_PASSWORD / REDIS_PREFIX
*/

$queue_driver = strtolower(trim((string) (getenv('QUEUE_DRIVER') ?: 'redis')));
if ($queue_driver !== 'redis' && $queue_driver !== 'database') {
    $queue_driver = 'database';
}

$queue_enable_async_env = getenv('QUEUE_ENABLE_ASYNC');
$queue_enable_async = true;
if ($queue_enable_async_env !== false && $queue_enable_async_env !== null && trim((string) $queue_enable_async_env) !== '') {
    $parsed_async = filter_var($queue_enable_async_env, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $queue_enable_async = ($parsed_async === null) ? true : (bool) $parsed_async;
}

$queue_name = trim((string) (getenv('QUEUE_NAME') ?: 'default'));
if ($queue_name === '') {
    $queue_name = 'default';
}

$redis_host = trim((string) (getenv('REDIS_HOST') ?: '127.0.0.1'));
if ($redis_host === '') {
    $redis_host = '127.0.0.1';
}

$redis_port = (int) (getenv('REDIS_PORT') ?: 6379);
if ($redis_port <= 0) {
    $redis_port = 6379;
}

$redis_db = (int) (getenv('REDIS_DB') ?: 0);
if ($redis_db < 0) {
    $redis_db = 0;
}

$redis_timeout = (float) (getenv('REDIS_TIMEOUT') ?: 2.0);
if ($redis_timeout <= 0) {
    $redis_timeout = 2.0;
}

$redis_password = getenv('REDIS_PASSWORD');
if ($redis_password === false) {
    $redis_password = null;
}

$redis_prefix = trim((string) (getenv('REDIS_PREFIX') ?: 'rtrwnet:'));
if ($redis_prefix === '') {
    $redis_prefix = 'rtrwnet:';
}

$config['queue_driver'] = $queue_driver;
$config['queue_name'] = $queue_name;
$config['queue_poll_sleep_seconds'] = max(1, (int) (getenv('QUEUE_POLL_SLEEP_SECONDS') ?: 1));
$config['queue_pop_timeout_seconds'] = max(1, (int) (getenv('QUEUE_POP_TIMEOUT_SECONDS') ?: 2));
$config['queue_worker_memory_limit_mb'] = max(64, (int) (getenv('QUEUE_WORKER_MEMORY_LIMIT_MB') ?: 256));
$config['queue_default_max_attempts'] = max(1, (int) (getenv('QUEUE_DEFAULT_MAX_ATTEMPTS') ?: 5));
$config['queue_backoff_base_seconds'] = max(1, (int) (getenv('QUEUE_BACKOFF_BASE_SECONDS') ?: 5));
$config['queue_backoff_max_seconds'] = max($config['queue_backoff_base_seconds'], (int) (getenv('QUEUE_BACKOFF_MAX_SECONDS') ?: 300));
$config['queue_enable_async'] = $queue_enable_async;

$config['redis_host'] = $redis_host;
$config['redis_port'] = $redis_port;
$config['redis_db'] = $redis_db;
$config['redis_timeout'] = $redis_timeout;
$config['redis_password'] = $redis_password;
$config['redis_prefix'] = $redis_prefix;
