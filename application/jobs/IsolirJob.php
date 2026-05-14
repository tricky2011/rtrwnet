<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class IsolirJob
{
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->library('billing_automation_service');
    }

    public function handle(array $payload, array $job = array())
    {
        $date = trim((string) ($payload['date'] ?? ''));
        $grace_days = (int) ($payload['grace_days'] ?? 5);
        if ($grace_days < 0) {
            $grace_days = 5;
        }

        try {
            $result = $this->CI->billing_automation_service->auto_suspend($date !== '' ? $date : null, $grace_days);
            if (!empty($result['success'])) {
                return array(
                    'success' => true,
                    'message' => (string) ($result['message'] ?? 'Isolir check success'),
                    'retryable' => false,
                );
            }

            return array(
                'success' => false,
                'message' => (string) ($result['message'] ?? 'Isolir check failed'),
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

