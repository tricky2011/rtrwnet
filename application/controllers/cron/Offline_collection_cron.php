<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Offline_collection_cron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Forbidden', 403);
            exit;
        }
        $this->load->library('offline_collection_service');
        $this->load->model('system_monitoring_model');
    }

    public function run()
    {
        $started = date('Y-m-d H:i:s');
        try {
            $result = $this->offline_collection_service->run();
        } catch (Throwable $e) {
            log_message('error', '[OFFLINE_COLLECTION] ' . $e->getMessage());
            $result = array('success' => false, 'message' => 'Proses laporan penagihan gagal. Periksa log aplikasi.');
        }
        if (empty($result['skipped'])) {
            $this->system_monitoring_model->record_cron_run('offline_collection_cron.run', !empty($result['success']) ? 'success' : 'error', $started, date('Y-m-d H:i:s'), (string) ($result['message'] ?? 'Laporan penagihan rumah selesai.'), $result);
        }
        echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    }

    public function preview($date = null)
    {
        // Preview never sends Telegram messages or stores delivery snapshots.
        echo json_encode($this->offline_collection_service->run(true, $date), JSON_PRETTY_PRINT) . PHP_EOL;
    }
}
