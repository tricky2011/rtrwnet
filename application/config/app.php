<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Application Branding
|--------------------------------------------------------------------------
| Keep branding values centralized so views can avoid hardcoded product text.
*/
if (!function_exists('rtrwnet_brand_env')) {
    function rtrwnet_brand_env($key, $default = '')
    {
        $value = getenv($key);
        if ($value === FALSE) {
            return $default;
        }

        $value = trim((string) $value);
        return $value !== '' ? $value : $default;
    }
}

$app_name = rtrwnet_brand_env('APP_BRAND_NAME', 'RTRWNet');
$app_tagline = rtrwnet_brand_env('APP_BRAND_TAGLINE', 'ISP Operations Platform');
$app_company = rtrwnet_brand_env('APP_BRAND_COMPANY', 'RTRWNet Operator');

$config['app_name'] = $app_name;
$config['app_tagline'] = $app_tagline;
$config['app_company'] = $app_company;
$config['company_name'] = rtrwnet_brand_env('COMPANY_NAME', $app_company);
$config['company_tagline'] = rtrwnet_brand_env('COMPANY_TAGLINE', $app_tagline);
$config['company_address'] = rtrwnet_brand_env('COMPANY_ADDRESS', '');
$config['company_phone'] = rtrwnet_brand_env('COMPANY_PHONE', '');
$config['company_email'] = rtrwnet_brand_env('COMPANY_EMAIL', 'billing@example.invalid');
$config['company_website'] = rtrwnet_brand_env('COMPANY_WEBSITE', '');
$config['app_logo'] = '/assets/branding/nawacore-logo.png';
$config['app_logo_dark'] = '/assets/branding/nawacore-logo-dark.png';
$config['app_icon'] = '/assets/branding/nawacore-icon.png';
