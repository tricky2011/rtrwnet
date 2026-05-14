<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class BillingGenerateJob
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->library('billing_automation_service');
    }

    public function handle(array $payload, array $job = array())
    {
        $mode = strtolower(trim((string) ($payload['mode'] ?? 'daily')));
        $period = trim((string) ($payload['period'] ?? ''));
        $run_date = trim((string) ($payload['date'] ?? ''));
        $router_id = isset($payload['router_id']) ? (int) $payload['router_id'] : null;
        if ($router_id !== null && $router_id <= 0) {
            $router_id = null;
        }

        try {
            if ($mode === 'monthly' && $period !== '') {
                $result = $this->CI->billing_automation_service->generate_monthly_invoices($period, $router_id);
            } else {
                $result = $this->CI->billing_automation_service->generate_daily_rolling_invoices(
                    $run_date !== '' ? $run_date : null,
                    $router_id
                );
            }

            if (!empty($result['success'])) {
                return array(
                    'success' => true,
                    'message' => (string) ($result['message'] ?? 'Billing generate success'),
                    'retryable' => false,
                );
            }

            return array(
                'success' => false,
                'message' => (string) ($result['message'] ?? 'Billing generate failed'),
                'retryable' => true,
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
