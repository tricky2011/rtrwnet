<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Helpdesk_cron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->helper(array('url', 'helpdesk_telegram'));
        $this->load->model('Ticket_model', 'ticket_model');
        $this->load->model('System_monitoring_model', 'system_monitoring_model');
    }

    public function check_sla()
    {
        $start = microtime(true);
        if (!$this->authorize_cron()) {
            return $this->output
                ->set_status_header(403)
                ->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Unauthorized')));
        }

        if (!$this->ticket_model->table_ready()) {
            return $this->output
                ->set_status_header(422)
                ->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'message' => 'Tabel tickets belum tersedia.')));
        }

        try {
            $breached = $this->ticket_model->get_sla_breached_tickets(200);
            $alert_result = array('success' => false, 'message' => 'Tidak ada tiket breached.');
            if (!empty($breached)) {
                $alert_result = helpdesk_telegram_sla_breached($breached);
            }

            $duration = (int) round((microtime(true) - $start) * 1000);
            if (method_exists($this->system_monitoring_model, 'record_cron_run')) {
                $this->system_monitoring_model->record_cron_run(
                    'helpdesk_cron.check_sla',
                    !empty($alert_result['success']) || empty($breached),
                    empty($breached) ? 'No SLA breach' : (string) ($alert_result['message'] ?? 'Alert sent'),
                    $duration
                );
            }

            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(array(
                    'success' => true,
                    'total_breached' => count($breached),
                    'telegram' => $alert_result,
                    'duration_ms' => $duration,
                    'run_at' => date('Y-m-d H:i:s'),
                )));
        } catch (Throwable $e) {
            log_message('error', '[HELPDESK_CRON][SLA] ' . $e->getMessage());
            $duration = (int) round((microtime(true) - $start) * 1000);
            if (method_exists($this->system_monitoring_model, 'record_cron_run')) {
                $this->system_monitoring_model->record_cron_run(
                    'helpdesk_cron.check_sla',
                    false,
                    $e->getMessage(),
                    $duration
                );
            }

            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(array(
                    'success' => false,
                    'message' => $e->getMessage(),
                    'duration_ms' => $duration,
                )));
        }
    }

    private function authorize_cron()
    {
        if (is_cli()) {
            return true;
        }

        $token = trim((string) $this->input->get('token', true));
        $expected = trim((string) $this->config->item('cron_token'));
        return ($expected !== '' && hash_equals($expected, $token));
    }
}
