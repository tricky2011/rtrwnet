<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| TR-069 / FreeACS Integration Config
|--------------------------------------------------------------------------
| Simpan credential sensitif di ENV agar tidak hardcode di repository.
| Contoh:
|   export TR069_API_TOKEN="super-secret-token"
|   export FREEACS_SOAP_URL="http://127.0.0.1:8088/webservice/ws/acs"
|   export FREEACS_SOAP_LOGIN_USER="apiuser"
|   export FREEACS_SOAP_LOGIN_PASS="apipass"
*/

$config['tr069_api_token'] = getenv('TR069_API_TOKEN') ?: '';
$config['tr069_api_basic_auth_enabled'] = getenv('TR069_API_BASIC_ENABLED') === '1';
$config['tr069_api_basic_username'] = getenv('TR069_API_BASIC_USER') ?: '';
$config['tr069_api_basic_password'] = getenv('TR069_API_BASIC_PASS') ?: '';

// Mode integrasi: 'soap' (default FreeACS webservice) atau 'rest' (bridge API internal).
$config['tr069_freeacs_mode'] = strtolower((string) (getenv('FREEACS_MODE') ?: 'soap'));

// FreeACS SOAP endpoint harus diisi eksplisit lewat env ketika integrasi dipakai.
$config['tr069_freeacs_soap_url'] = trim((string) (getenv('FREEACS_SOAP_URL') ?: ''));
$config['tr069_freeacs_soap_login_user'] = trim((string) (getenv('FREEACS_SOAP_LOGIN_USER') ?: ''));
$config['tr069_freeacs_soap_login_pass'] = trim((string) (getenv('FREEACS_SOAP_LOGIN_PASS') ?: ''));

// HTTP auth untuk endpoint FreeACS jika di-reverse proxy Basic Auth.
$config['tr069_freeacs_http_username'] = getenv('FREEACS_HTTP_USER') ?: '';
$config['tr069_freeacs_http_password'] = getenv('FREEACS_HTTP_PASS') ?: '';

// Jika memakai bridge REST internal (opsional).
$config['tr069_freeacs_rest_base_url'] = rtrim((string) (getenv('FREEACS_REST_BASE_URL') ?: ''), '/');
$config['tr069_freeacs_rest_token'] = getenv('FREEACS_REST_TOKEN') ?: '';

$config['tr069_freeacs_timeout'] = (int) (getenv('FREEACS_TIMEOUT') ?: 20);
$config['tr069_freeacs_verify_ssl'] = getenv('FREEACS_VERIFY_SSL') === '1';

$config['tr069_parameter_map'] = array(
    'tr181' => array(
        'ssid' => 'Device.WiFi.SSID.1.SSID',
        'password' => 'Device.WiFi.AccessPoint.1.Security.KeyPassphrase',
        'hosts_root' => 'Device.Hosts.Host.',
    ),
    'tr098' => array(
        'ssid' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        'password' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
        'hosts_root' => 'InternetGatewayDevice.LANDevice.1.Hosts.Host.',
    ),
);

// Limit proteksi agar task tidak membanjiri ACS pada server low-spec.
$config['tr069_task_rate_limit_seconds'] = (int) (getenv('TR069_TASK_RATE_LIMIT_SECONDS') ?: 2);
