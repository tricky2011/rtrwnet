<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| GenieACS Integration Config
|--------------------------------------------------------------------------
|
| IMPORTANT:
| - URL endpoint ACS/NBI tidak lagi dibaca dari config global.
| - URL endpoint diambil per-router dari tabel `routers`:
|   - routers.acs_url
|   - routers.acs_nbi_url
|
| Config ini hanya menyimpan parameter umum client HTTP.
*/

$config['genieacs_timeout'] = (int) (getenv('GENIEACS_TIMEOUT') ?: 20);
$config['genieacs_verify_ssl'] = getenv('GENIEACS_VERIFY_SSL') === '1';
$config['genieacs_sync_refresh_virtual_parameters'] = getenv('GENIEACS_SYNC_REFRESH_VIRTUAL_PARAMETERS') === '1';
$config['genieacs_connection_request_online_check'] = getenv('GENIEACS_CONNECTION_REQUEST_ONLINE_CHECK') !== '0';
$config['genieacs_task_connection_request'] = getenv('GENIEACS_TASK_CONNECTION_REQUEST') === '1';

/*
|--------------------------------------------------------------------------
| Virtual Parameters Sync
|--------------------------------------------------------------------------
| Konfigurasi sumber virtual parameter GenieACS agar sinkronisasi konsisten.
| Nilai default diarahkan ke paket lokal:
| docs/genieacs_virtual_parameters/alijayanet
*/

$config['genieacs_vparam_sync_enabled'] = getenv('GENIEACS_VPARAM_SYNC_ENABLED') !== '0';
$config['genieacs_vparam_source'] = trim((string) (getenv('GENIEACS_VPARAM_SOURCE') ?: 'alijayanet'));
$config['genieacs_vparam_base_dir'] = trim((string) (getenv('GENIEACS_VPARAM_BASE_DIR') ?: 'docs/genieacs_virtual_parameters'));
$config['genieacs_vparam_ndjson_path'] = trim((string) (getenv('GENIEACS_VPARAM_NDJSON_PATH') ?: ''));
$config['genieacs_vparam_json_array_path'] = trim((string) (getenv('GENIEACS_VPARAM_JSON_ARRAY_PATH') ?: ''));
$config['genieacs_vparam_manifest_path'] = trim((string) (getenv('GENIEACS_VPARAM_MANIFEST_PATH') ?: ''));
$config['genieacs_vparam_mongo_db'] = trim((string) (getenv('GENIEACS_VPARAM_MONGO_DB') ?: 'genieacs'));
