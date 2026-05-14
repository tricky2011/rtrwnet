<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('branding_value')) {
    function branding_value($key, $fallback = '')
    {
        static $loaded = false;

        $CI =& get_instance();
        if (!is_object($CI) || !isset($CI->config)) {
            return $fallback;
        }

        if (!$loaded) {
            $CI->config->load('app', false, true);
            $loaded = true;
        }

        $value = $CI->config->item($key);
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return $fallback;
    }
}

if (!function_exists('app_name')) {
    function app_name()
    {
        return branding_value('app_name', 'RTRWNet');
    }
}

if (!function_exists('app_tagline')) {
    function app_tagline()
    {
        return branding_value('app_tagline', 'ISP Operations Platform');
    }
}

if (!function_exists('app_company')) {
    function app_company()
    {
        return branding_value('app_company', 'RTRWNet Operator');
    }
}

if (!function_exists('app_logo_url')) {
    function app_logo_url($dark = false)
    {
        return $dark
            ? branding_value('app_logo_dark', '/assets/branding/nawacore-logo-dark.png')
            : branding_value('app_logo', '/assets/branding/nawacore-logo.png');
    }
}

if (!function_exists('app_icon_url')) {
    function app_icon_url()
    {
        return branding_value('app_icon', '/assets/branding/nawacore-icon.png');
    }
}
