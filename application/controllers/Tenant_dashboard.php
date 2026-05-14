<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Tenant_dashboard extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->library(array('session', 'tenantlimiter'));
        $this->load->helper(array('url', 'tenant', 'subscription'));
    }

    public function index()
    {
        $role = strtolower((string) $this->session->userdata('role'));
        if (in_array($role, array('platform_owner', 'superadmin'), true)) {
            redirect('dashboard');
            return;
        }

        $this->require_role(array('tenant_owner', 'admin'));

        $tenant_id = (int) (getTenantId() ?: 0);
        if ($tenant_id <= 0) {
            show_error('Tenant context tidak valid.', 403);
            return;
        }

        if (!isTenantActive(false)) {
            redirect('tenant/subscription-expired');
            return;
        }

        $usage_summary = $this->tenantlimiter->getUsageSummary($tenant_id);
        $subscription = isset($usage_summary['subscription']) ? (array) $usage_summary['subscription'] : array();
        $usage = isset($usage_summary['usage']) ? (array) $usage_summary['usage'] : array();
        $limits = isset($usage_summary['limits']) ? (array) $usage_summary['limits'] : array();

        $days_left = null;
        $end_date = isset($subscription['end_date']) ? (string) $subscription['end_date'] : '';
        if ($end_date !== '') {
            $end = strtotime($end_date . ' 23:59:59');
            if ($end !== false) {
                $days_left = (int) floor(($end - time()) / 86400);
            }
        }

        $this->load->view('tenant/dashboard', array(
            'subscription' => $subscription,
            'usage' => $usage,
            'limits' => $limits,
            'days_left' => $days_left,
        ));
    }

    public function expired()
    {
        redirect('subscription/expired');
    }
}
