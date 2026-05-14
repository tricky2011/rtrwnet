<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!class_exists('TenantLimiter', false) && !class_exists('Tenantlimiter', false)) {
    require_once APPPATH . 'libraries/TenantLimiter.php';
}
