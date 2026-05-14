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

        // Load migration config then force-enable for CLI runner.
        $this->config->load('migration', true);
        $this->config->set_item('migration_enabled', true);
        $this->config->set_item('migration_type', 'timestamp');
        $this->config->set_item('migration_auto_latest', false);
        $this->config->set_item('migration_version', 0);
        if (!$this->config->item('migration_path')) {
            $this->config->set_item('migration_path', APPPATH . 'migrations/');
        }
        if (!$this->config->item('migration_table')) {
            $this->config->set_item('migration_table', 'migrations');
        }

        $this->load->library('migration');
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
