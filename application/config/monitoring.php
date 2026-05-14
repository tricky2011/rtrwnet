<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Monitoring Configuration
|--------------------------------------------------------------------------
| Konfigurasi dashboard monitoring real-time dan cron alert.
*/

$config['monitoring'] = array(
    // Dashboard auto refresh interval (detik)
    'refresh_seconds' => 10,

    // Cache snapshot monitoring (detik). 0 = nonaktif.
    'snapshot_cache_ttl' => 45,

    // Interface yang dimonitor (kosong = semua interface)
    'monitored_interfaces' => array(
        'ether10',
        'br-dist',
        '50_5Mbps-PPPoE',
        '70_7Mbps-PPoE',
        '1111_10Mbps-PPPoE',
        '200_20Mbps-PPPoE',
        '300_30Mbps-PPPoE',
        '500_50Mbps-PPPoE',
    ),

    // Interface yang dipakai untuk alert down (kosong = semua monitored_interfaces)
    'interface_down_watchlist' => array('ether10'),

    // Jumlah interface yang ditampilkan/diukur realtime
    'interface_limit' => 8,
    'ppp_active_display_limit' => 120,

    // Network health targets
    'gateway_target' => '192.168.88.1',
    'public_target' => '8.8.8.8',
    'ping_count' => 3,

    // Alert thresholds
    'alert_cpu_threshold' => 80,
    'alert_gateway_rto_seconds' => 300,
    'alert_cooldown_seconds' => 300,

    // PPP isolir sync
    'isolir_sync_limit' => 200,

    // Router log monitoring
    'system_log_limit' => 30,

    // Token untuk HTTP cron (CLI tidak perlu token)
    'cron_token' => trim((string) getenv('MONITORING_CRON_TOKEN')),
);
