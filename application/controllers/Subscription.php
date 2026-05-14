<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Subscription extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->library('session');
        $this->load->helper(array('url', 'tenant', 'subscription'));
    }

    public function expired()
    {
        $role = strtolower((string) $this->session->userdata('role'));
        if (in_array($role, array('platform_owner', 'superadmin'), true)) {
            redirect('dashboard');
            return;
        }

        $tenant_id = (int) (getTenantId() ?: 0);
        $latest_subscription = $tenant_id > 0 ? getTenantLatestSubscription($tenant_id) : null;
        $latest_invoice = $tenant_id > 0 ? getTenantLastSaasInvoice($tenant_id) : null;

        $this->load->view('tenant/subscription_expired', array(
            'latest_subscription' => is_array($latest_subscription) ? $latest_subscription : array(),
            'latest_invoice' => is_array($latest_invoice) ? $latest_invoice : array(),
            'tenant_suspended' => $tenant_id > 0 ? isTenantSuspended($tenant_id) : true,
        ));
    }

    public function pay()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            redirect('subscription/expired');
            return;
        }

        $this->session->set_flashdata(
            'success',
            'Permintaan pembayaran subscription sudah diterima. Silakan lanjutkan proses pembayaran SaaS Anda.'
        );
        redirect('subscription/expired');
    }
}

