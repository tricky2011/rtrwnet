<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin system monitoring dashboard controller.
 */
class System_monitoring extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->model('system_monitoring_model');
    }

    public function index()
    {
        $cron = $this->system_monitoring_model->get_last_cron_runs(12);
        $mikrotik = $this->system_monitoring_model->get_mikrotik_status();
        $telegram = $this->system_monitoring_model->get_telegram_status();
        $errors = $this->system_monitoring_model->get_last_errors(20);
        $metrics = $this->system_monitoring_model->get_system_metrics();

        $latestCron = !empty($cron['rows']) ? $cron['rows'][0] : null;
        $cronLabels = [];
        $cronDurations = [];
        foreach (array_reverse($cron['rows']) as $row) {
            $cronLabels[] = !empty($row['job_name']) ? $row['job_name'] : 'cron';
            $cronDurations[] = isset($row['duration_ms']) && $row['duration_ms'] !== null
                ? (int) $row['duration_ms']
                : 0;
        }

        $this->load->view('ui/system_monitoring', [
            'page_title' => 'System Monitoring - RTRWNet',
            'page_heading' => 'System Monitoring',
            'page_subheading' => 'Pantau status cron, API, dan error sistem terbaru.',
            'active_menu' => 'monitoring',
            'monitoring' => [
                'cron' => $cron,
                'latest_cron' => $latestCron,
                'mikrotik' => $mikrotik,
                'telegram' => $telegram,
                'errors' => $errors,
                'metrics' => $metrics,
                'chart' => [
                    'labels' => $cronLabels,
                    'durations' => $cronDurations,
                ],
                'generated_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    public function status_json()
    {
        $payload = [
            'success' => true,
            'generated_at' => date('Y-m-d H:i:s'),
            'cron' => $this->system_monitoring_model->get_last_cron_runs(8),
            'mikrotik' => $this->system_monitoring_model->get_mikrotik_status(),
            'telegram' => $this->system_monitoring_model->get_telegram_status(),
            'errors' => $this->system_monitoring_model->get_last_errors(10),
            'metrics' => $this->system_monitoring_model->get_system_metrics(),
        ];

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
    }
}
