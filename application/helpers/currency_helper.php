<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('rupiah')) {
    function rupiah($angka)
    {
        return 'Rp ' . number_format((float) $angka, 0, ',', '.');
    }
}
