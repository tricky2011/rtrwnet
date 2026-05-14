<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('getTenantGracePeriodDays')) {
    /**
     * Ambil grace period SaaS (hari), fallback 3 hari.
     *
     * @param int $fallback
     * @return int
     */
    function getTenantGracePeriodDays($fallback = 3)
    {
        $grace = (int) config_item('saas_grace_period_days');
        if ($grace <= 0) {
            $grace = (int) $fallback;
        }

        return max(1, $grace);
    }
}

if (!function_exists('getTenantLatestSubscription')) {
    /**
     * Ambil subscription terakhir tenant (tanpa filter status).
     *
     * @param int|null $tenant_id
     * @return array|null
     */
    function getTenantLatestSubscription($tenant_id = null)
    {
        $CI =& get_instance();
        $CI->load->database();
        $CI->load->helper('tenant');

        if ($tenant_id === null) {
            $tenant_id = getTenantId();
        }

        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0) {
            return null;
        }

        if (!$CI->db->table_exists('tenant_subscriptions')) {
            return null;
        }

        return $CI->db
            ->from('tenant_subscriptions')
            ->where('tenant_id', $tenant_id)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();
    }
}

if (!function_exists('getActiveTenantSubscription')) {
    /**
     * Ambil subscription tenant yang aktif dan belum expired.
     *
     * @param int|null $tenant_id
     * @return array|null
     */
    function getActiveTenantSubscription($tenant_id = null)
    {
        $CI =& get_instance();
        $CI->load->database();
        $CI->load->helper('tenant');

        if ($tenant_id === null && !is_cli() && !isPlatformOwner()) {
            if ($tenant_id === null) {
                $tenant_id = getTenantId();
            }
        }

        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0) {
            return null;
        }

        if (!$CI->db->table_exists('tenant_subscriptions')) {
            return null;
        }

        $today = date('Y-m-d');
        $sub_fields = $CI->db->list_fields('tenant_subscriptions');
        $has_packages = $CI->db->table_exists('packages');
        $package_fields = $has_packages ? $CI->db->list_fields('packages') : array();

        $qb = $CI->db
            ->select('ts.*')
            ->from('tenant_subscriptions ts')
            ->where('ts.tenant_id', $tenant_id)
            ->where_in('ts.status', array('active', 'trial', 'grace'));

        if (in_array('start_date', $sub_fields, true)) {
            $qb->where('ts.start_date <=', $today);
        }
        if (in_array('end_date', $sub_fields, true)) {
            $qb
                ->group_start()
                ->where('ts.end_date IS NULL', null, false)
                ->or_where('ts.end_date >=', $today)
                ->group_end();
        }

        if ($has_packages) {
            $qb->join('packages p', 'p.id = ts.package_id', 'left');
        }

        $mapped_package_fields = array(
            'name' => array('name', 'package_name'),
            'price_monthly' => array('price_monthly', 'price'),
            'price_yearly' => array('price_yearly'),
            'max_router' => array('max_router', 'max_routers'),
            'max_customer' => array('max_customer', 'max_customers'),
            'max_user' => array('max_user'),
            'max_telegram_group' => array('max_telegram_group'),
            'max_technician' => array('max_technician'),
            'features_json' => array('features_json'),
        );

        if ($has_packages) {
            foreach ($mapped_package_fields as $alias => $candidates) {
                foreach ($candidates as $candidate) {
                    if (in_array($candidate, $package_fields, true)) {
                        $qb->select('p.' . $candidate . ' AS ' . $alias);
                        break;
                    }
                }
            }
        }

        return $qb
            ->order_by('ts.id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();
    }
}

if (!function_exists('getTenantLastSaasInvoice')) {
    /**
     * Ambil invoice SaaS terakhir tenant.
     *
     * @param int|null $tenant_id
     * @return array|null
     */
    function getTenantLastSaasInvoice($tenant_id = null)
    {
        $CI =& get_instance();
        $CI->load->database();
        $CI->load->helper('tenant');

        if ($tenant_id === null) {
            $tenant_id = getTenantId();
        }

        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0) {
            return null;
        }

        if (!$CI->db->table_exists('tenant_invoices')) {
            return null;
        }

        return $CI->db
            ->from('tenant_invoices')
            ->where('tenant_id', $tenant_id)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();
    }
}

if (!function_exists('tenantHasOverdueSaasInvoice')) {
    /**
     * Cek apakah tenant memiliki invoice SaaS overdue melewati grace period.
     *
     * @param int|null $tenant_id
     * @param int|null $grace_days
     * @return bool
     */
    function tenantHasOverdueSaasInvoice($tenant_id = null, $grace_days = null)
    {
        $CI =& get_instance();
        $CI->load->database();
        $CI->load->helper('tenant');

        if ($tenant_id === null) {
            $tenant_id = getTenantId();
        }

        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0 || !$CI->db->table_exists('tenant_invoices')) {
            return false;
        }

        $grace = (int) ($grace_days !== null ? $grace_days : getTenantGracePeriodDays());
        $grace = max(1, $grace);
        $threshold = date('Y-m-d', strtotime('-' . $grace . ' days'));

        $invoice_fields = $CI->db->list_fields('tenant_invoices');
        if (!in_array('due_date', $invoice_fields, true)) {
            return false;
        }

        $qb = $CI->db
            ->from('tenant_invoices')
            ->where('tenant_id', $tenant_id)
            ->where('due_date <', $threshold);

        if (in_array('status', $invoice_fields, true)) {
            $qb->where_in('status', array('pending', 'overdue', 'issued', 'partially_paid', 'unpaid'));
        }

        if (in_array('balance_amount', $invoice_fields, true)) {
            $qb->where('balance_amount >', 0);
        }

        return $qb->count_all_results() > 0;
    }
}

if (!function_exists('isTenantSuspended')) {
    /**
     * Cek apakah tenant dalam kondisi suspended/locked.
     *
     * @param int|null $tenant_id
     * @param int|null $grace_days
     * @return bool
     */
    function isTenantSuspended($tenant_id = null, $grace_days = null)
    {
        $CI =& get_instance();
        $CI->load->database();
        $CI->load->helper('tenant');

        if ($tenant_id === null) {
            if (!is_cli() && isPlatformOwner()) {
                return false;
            }
            $tenant_id = getTenantId();
        }

        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0) {
            return false;
        }

        if ($CI->db->table_exists('tenants')) {
            $tenant_fields = $CI->db->list_fields('tenants');
            $select_columns = array('status');
            if (in_array('expired_at', $tenant_fields, true)) {
                $select_columns[] = 'expired_at';
            }

            $tenant = $CI->db->select(implode(',', $select_columns), false)
                ->from('tenants')
                ->where('id', $tenant_id)
                ->limit(1)
                ->get()
                ->row_array();

            $tenant_status = strtolower((string) ($tenant['status'] ?? ''));
            if (in_array($tenant_status, array('suspended', 'terminated'), true)) {
                return true;
            }

            $expired_at = trim((string) ($tenant['expired_at'] ?? ''));
            if ($expired_at !== '' && $expired_at < date('Y-m-d')) {
                return true;
            }
        }

        $has_subscriptions = false;
        if ($CI->db->table_exists('tenant_subscriptions')) {
            $has_subscriptions = $CI->db
                ->from('tenant_subscriptions')
                ->where('tenant_id', $tenant_id)
                ->limit(1)
                ->count_all_results() > 0;
        }

        if (!$has_subscriptions) {
            return false;
        }

        $latest_subscription = getTenantLatestSubscription($tenant_id);
        if (!empty($latest_subscription)) {
            $sub_status = strtolower((string) ($latest_subscription['status'] ?? ''));
            if (in_array($sub_status, array('suspended', 'expired', 'cancelled'), true)) {
                return true;
            }

            $end_date = (string) ($latest_subscription['end_date'] ?? '');
            if ($end_date !== '' && $end_date < date('Y-m-d')) {
                return true;
            }
        }

        if (tenantHasOverdueSaasInvoice($tenant_id, $grace_days)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('isTenantActive')) {
    /**
     * Cek apakah subscription tenant aktif.
     *
     * @param bool $redirect_if_inactive
     * @param int|null $tenant_id_override
     * @return bool
     */
    function isTenantActive($redirect_if_inactive = true, $tenant_id_override = null)
    {
        $CI =& get_instance();
        $CI->load->helper(array('url', 'tenant'));
        if (!isset($CI->db)) {
            $CI->load->database();
        }

        if (!isset($CI->session) && !is_cli()) {
            $CI->load->library('session');
        }

        if (!is_cli() && !$CI->session->userdata('logged_in')) {
            return false;
        }

        if (!is_cli() && $tenant_id_override === null && isPlatformOwner()) {
            return true;
        }

        $tenant_id = $tenant_id_override !== null ? (int) $tenant_id_override : getTenantId();
        if ($tenant_id === null) {
            if ($redirect_if_inactive && !is_cli()) {
                $CI->session->set_flashdata('error', 'Tenant context tidak valid.');
                redirect('auth/login');
                exit;
            }
            return false;
        }

        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0) {
            return false;
        }

        if ($CI->db->table_exists('tenants')) {
            $tenant_fields = $CI->db->list_fields('tenants');
            $select_columns = array('status');
            if (in_array('expired_at', $tenant_fields, true)) {
                $select_columns[] = 'expired_at';
            }

            $tenant_row = $CI->db
                ->select(implode(',', $select_columns), false)
                ->from('tenants')
                ->where('id', $tenant_id)
                ->limit(1)
                ->get()
                ->row_array();

            $tenant_status = strtolower((string) ($tenant_row['status'] ?? ''));
            if (!in_array($tenant_status, array('active', 'trial'), true)) {
                if ($redirect_if_inactive && !is_cli()) {
                    $CI->session->set_flashdata('error', 'Akun tenant Anda disuspend / tidak aktif.');
                    redirect('subscription/expired');
                    exit;
                }
                return false;
            }

            $expired_at = trim((string) ($tenant_row['expired_at'] ?? ''));
            if ($expired_at !== '' && $expired_at < date('Y-m-d')) {
                if ($redirect_if_inactive && !is_cli()) {
                    $CI->session->set_flashdata('error', 'Subscription tenant Anda sudah expired.');
                    redirect('subscription/expired');
                    exit;
                }
                return false;
            }
        }

        if (!$CI->db->table_exists('tenant_subscriptions')) {
            // Mode multi-client lite: hanya tenants.status + tenants.expired_at.
            return true;
        }

        $has_subscription_rows = $CI->db
            ->from('tenant_subscriptions')
            ->where('tenant_id', $tenant_id)
            ->limit(1)
            ->count_all_results() > 0;

        if (!$has_subscription_rows) {
            // Mode multi-client lite: tidak wajib tenant_subscriptions.
            return true;
        }

        $subscription = getActiveTenantSubscription($tenant_id);
        if (!empty($subscription)) {
            if (!tenantHasOverdueSaasInvoice($tenant_id, getTenantGracePeriodDays())) {
                return true;
            }
        }

        if ($redirect_if_inactive && !is_cli()) {
            $CI->session->set_flashdata('error', 'Subscription tenant Anda tidak aktif / sudah expired.');
            redirect('subscription/expired');
            exit;
        }

        return false;
    }
}

if (!function_exists('canRunTenantBackgroundJobs')) {
    /**
     * Validasi global agar cron/worker tenant hanya jalan jika tenant aktif.
     *
     * @param int $tenant_id
     * @return bool
     */
    function canRunTenantBackgroundJobs($tenant_id)
    {
        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0) {
            return false;
        }

        if (!isTenantActive(false, $tenant_id)) {
            return false;
        }

        return !isTenantSuspended($tenant_id);
    }
}

if (!function_exists('resumeTenantAfterInvoicePaid')) {
    /**
     * Auto resume tenant ketika invoice SaaS dibayar.
     *
     * @param int $tenant_invoice_id
     * @return array
     */
    function resumeTenantAfterInvoicePaid($tenant_invoice_id)
    {
        $CI =& get_instance();
        $CI->load->database();

        if (!$CI->db->table_exists('tenant_invoices')
            || !$CI->db->table_exists('tenant_subscriptions')
            || !$CI->db->table_exists('tenants')) {
            return array('success' => false, 'message' => 'Tabel SaaS belum lengkap.');
        }

        $tenant_invoice_id = (int) $tenant_invoice_id;
        if ($tenant_invoice_id <= 0) {
            return array('success' => false, 'message' => 'ID invoice tidak valid.');
        }

        $invoice = $CI->db
            ->from('tenant_invoices')
            ->where('id', $tenant_invoice_id)
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($invoice)) {
            return array('success' => false, 'message' => 'Invoice tenant tidak ditemukan.');
        }

        if (strtolower((string) ($invoice['status'] ?? '')) !== 'paid') {
            return array('success' => false, 'message' => 'Invoice tenant belum berstatus paid.');
        }

        $tenant_id = (int) ($invoice['tenant_id'] ?? 0);
        if ($tenant_id <= 0) {
            return array('success' => false, 'message' => 'tenant_id tidak valid di invoice.');
        }

        $today = date('Y-m-d');
        $updated_sub = $CI->db
            ->where('tenant_id', $tenant_id)
            ->where('end_date >=', $today)
            ->where_in('status', array('expired', 'suspended', 'grace', 'trial', 'active'))
            ->update('tenant_subscriptions', array(
                'status' => 'active',
                'updated_at' => date('Y-m-d H:i:s'),
            ));

        if (!$updated_sub) {
            return array('success' => false, 'message' => 'Gagal update subscription tenant.');
        }

        $tenant_payload = array(
            'status' => 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        );

        $tenant_fields = $CI->db->list_fields('tenants');
        if (in_array('suspended_at', $tenant_fields, true)) {
            $tenant_payload['suspended_at'] = null;
        }
        if (in_array('suspend_reason', $tenant_fields, true)) {
            $tenant_payload['suspend_reason'] = null;
        }
        if (in_array('resumed_at', $tenant_fields, true)) {
            $tenant_payload['resumed_at'] = date('Y-m-d H:i:s');
        }

        $CI->db->where('id', $tenant_id)->update('tenants', $tenant_payload);

        return array(
            'success' => true,
            'message' => 'Tenant berhasil diaktifkan kembali.',
            'tenant_id' => $tenant_id,
        );
    }
}
