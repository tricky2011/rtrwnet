<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Compatibility wrapper for CI3 loader on case-sensitive FS.
 *
 * Existing code loads library as "mikrotikmanager", while main class file
 * currently uses MikrotikManager.php + class MikrotikManager.
 */
require_once APPPATH . 'libraries/MikrotikManager.php';

if (!class_exists('Mikrotikmanager', false)) {
    class Mikrotikmanager extends MikrotikManager
    {
    }
}

