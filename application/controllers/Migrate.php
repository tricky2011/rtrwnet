<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migrate extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();

        if (!is_cli()) {
            show_error('Akses hanya melalui CLI.', 403);
        }

        $this->load->library('migration', array(
            'migration_enabled' => true,
            'migration_type' => 'timestamp',
            'migration_auto_latest' => false,
            'migration_version' => 0,
            'migration_path' => APPPATH . 'migrations/',
            'migration_table' => 'migrations',
        ));
    }

    public function latest()
    {
        if ($this->migration->latest() === false) {
            $error = $this->migration->error_string();
            echo "MIGRATION FAILED: " . $error . PHP_EOL;
            exit(1);
        }

        echo "MIGRATION SUCCESS" . PHP_EOL;
    }
}
