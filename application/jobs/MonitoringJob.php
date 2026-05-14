<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class MonitoringJob
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model('Monitoring_model', 'monitoring_model');
    }

    public function handle(array $payload, array $job = array())
    {
        try {
            $result = $this->CI->monitoring_model->run_health_checks_and_alerts();
            return array(
                'success' => true,
                'message' => 'Monitoring health check selesai.',
                'result' => $result,
                'retryable' => false,
            );
        } catch (Throwable $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'retryable' => true,
            );
        }
    }
}

