<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class SaasCron extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->config->load('cron', true, true);
        $this->load->database();
        $this->load->helper(array('subscription', 'tenant'));
        if (file_exists(APPPATH . 'models/System_monitoring_model.php')) {
            $this->load->model('System_monitoring_model', 'system_monitoring_model');
        }
    }

    public function checkSubscriptionStatus()
    {
        $started_at = date('Y-m-d H:i:s');
        $start = microtime(true);

        if (!$this->authorize_cron()) {
            return;
        }

        if (!$this->db->table_exists('tenants')
            || !$this->db->table_exists('tenant_subscriptions')
            || !$this->db->table_exists('tenant_invoices')) {
            return $this->json_response(422, array(
                'success' => false,
                'message' => 'Tabel SaaS belum lengkap (tenants, tenant_subscriptions, tenant_invoices).',
            ));
        }

        $grace_days = (int) $this->input->get('grace_days', true);
        if ($grace_days <= 0) {
            $grace_days = getTenantGracePeriodDays(3);
        }

        $today = date('Y-m-d');
        $overdue_threshold = date('Y-m-d', strtotime('-' . $grace_days . ' days'));

        $stats = array(
            'grace_days' => $grace_days,
            'expired_subscriptions' => 0,
            'suspended_by_overdue' => 0,
            'resumed_tenants' => 0,
            'skipped_no_action' => 0,
            'cancelled_background_jobs' => 0,
            'tenant_suspended_ids' => array(),
            'tenant_resumed_ids' => array(),
        );

        $sub_fields = $this->db->list_fields('tenant_subscriptions');
        $tenant_fields = $this->db->list_fields('tenants');
        $invoice_fields = $this->db->list_fields('tenant_invoices');

        $sub_status_values = $this->get_enum_values('tenant_subscriptions', 'status');
        $tenant_status_values = $this->get_enum_values('tenants', 'status');
        $invoice_status_values = $this->get_enum_values('tenant_invoices', 'status');

        $sub_status_expired = $this->pick_existing_status($sub_status_values, array('expired', 'suspended', 'cancelled'));
        $sub_status_suspended = $this->pick_existing_status($sub_status_values, array('suspended', 'expired', 'cancelled'));
        $sub_status_active = $this->pick_existing_status($sub_status_values, array('active', 'trial', 'grace'));
        $tenant_status_suspended = $this->pick_existing_status($tenant_status_values, array('suspended', 'terminated'));
        $tenant_status_active = $this->pick_existing_status($tenant_status_values, array('active', 'trial'));

        $this->db->trans_begin();

        // STEP 1: Expire subscription aktif yang sudah lewat end_date.
        $active_status_candidates = array_filter(array('active', 'trial', 'grace'), function ($status) use ($sub_status_values) {
            return empty($sub_status_values) || in_array($status, $sub_status_values, true);
        });
        if (empty($active_status_candidates) && $sub_status_active !== null) {
            $active_status_candidates = array($sub_status_active);
        }

        if (in_array('end_date', $sub_fields, true)) {
            $expired_rows = $this->db
                ->select('id, tenant_id, status, end_date')
                ->from('tenant_subscriptions')
                ->where('end_date IS NOT NULL', null, false)
                ->where('end_date <', $today)
                ->where_in('status', $active_status_candidates)
                ->get()
                ->result_array();

            foreach ($expired_rows as $row) {
                $tenant_id = (int) ($row['tenant_id'] ?? 0);
                if ($tenant_id <= 0) {
                    $stats['skipped_no_action']++;
                    continue;
                }

                $payload = array();
                if ($sub_status_expired !== null) {
                    $payload['status'] = $sub_status_expired;
                }
                if (in_array('updated_at', $sub_fields, true)) {
                    $payload['updated_at'] = date('Y-m-d H:i:s');
                }
                if (in_array('suspended_at', $sub_fields, true)) {
                    $payload['suspended_at'] = date('Y-m-d H:i:s');
                }
                if (in_array('suspend_reason', $sub_fields, true)) {
                    $payload['suspend_reason'] = 'subscription_expired';
                }

                if (!empty($payload)) {
                    $this->db->where('id', (int) $row['id'])->update('tenant_subscriptions', $payload);
                }

                $this->suspend_tenant_row($tenant_id, 'subscription_expired', $tenant_fields, $tenant_status_suspended);
                $stats['expired_subscriptions']++;
                $stats['tenant_suspended_ids'][$tenant_id] = $tenant_id;
            }
        }

        // STEP 2: Suspend tenant jika invoice SaaS overdue > grace.
        $unpaid_statuses = $this->resolve_unpaid_invoice_statuses($invoice_status_values);
        $overdue_qb = $this->db
            ->select('tenant_id, COUNT(*) AS overdue_count')
            ->from('tenant_invoices')
            ->where('due_date <', $overdue_threshold);
        if (!empty($unpaid_statuses) && in_array('status', $invoice_fields, true)) {
            $overdue_qb->where_in('status', $unpaid_statuses);
        }
        if (in_array('balance_amount', $invoice_fields, true)) {
            $overdue_qb->where('balance_amount >', 0);
        }
        $overdue_qb->group_by('tenant_id');
        $overdue_tenants = $overdue_qb->get()->result_array();

        foreach ($overdue_tenants as $row) {
            $tenant_id = (int) ($row['tenant_id'] ?? 0);
            if ($tenant_id <= 0) {
                continue;
            }

            $sub_update = array();
            if ($sub_status_suspended !== null) {
                $sub_update['status'] = $sub_status_suspended;
            }
            if (in_array('updated_at', $sub_fields, true)) {
                $sub_update['updated_at'] = date('Y-m-d H:i:s');
            }
            if (in_array('suspended_at', $sub_fields, true)) {
                $sub_update['suspended_at'] = date('Y-m-d H:i:s');
            }
            if (in_array('suspend_reason', $sub_fields, true)) {
                $sub_update['suspend_reason'] = 'invoice_overdue_grace';
            }

            if (!empty($sub_update)) {
                $this->db
                    ->where('tenant_id', $tenant_id)
                    ->where_in('status', $active_status_candidates)
                    ->update('tenant_subscriptions', $sub_update);
            }

            $this->suspend_tenant_row($tenant_id, 'invoice_overdue_grace', $tenant_fields, $tenant_status_suspended);

            $stats['suspended_by_overdue']++;
            $stats['tenant_suspended_ids'][$tenant_id] = $tenant_id;
        }

        // STEP 3: Auto resume tenant jika invoice sudah paid + tidak ada overdue grace.
        $suspended_tenant_statuses = array_filter(array($tenant_status_suspended, 'suspended', 'terminated'));
        $resume_candidates_qb = $this->db
            ->select('id')
            ->from('tenants');
        if (!empty($suspended_tenant_statuses) && in_array('status', $tenant_fields, true)) {
            $resume_candidates_qb->where_in('status', array_values(array_unique($suspended_tenant_statuses)));
        }
        $resume_candidates = $resume_candidates_qb->get()->result_array();

        foreach ($resume_candidates as $tenant_row) {
            $tenant_id = (int) ($tenant_row['id'] ?? 0);
            if ($tenant_id <= 0) {
                continue;
            }

            if (tenantHasOverdueSaasInvoice($tenant_id, $grace_days)) {
                continue;
            }

            $paid_qb = $this->db
                ->from('tenant_invoices')
                ->where('tenant_id', $tenant_id);
            if (in_array('status', $invoice_fields, true)) {
                $paid_qb->where('status', 'paid');
            }
            $has_paid_invoice = ((int) $paid_qb->count_all_results()) > 0;
            if (!$has_paid_invoice) {
                continue;
            }

            $valid_sub_qb = $this->db
                ->from('tenant_subscriptions')
                ->where('tenant_id', $tenant_id);
            if (in_array('end_date', $sub_fields, true)) {
                $valid_sub_qb
                    ->group_start()
                    ->where('end_date IS NULL', null, false)
                    ->or_where('end_date >=', $today)
                    ->group_end();
            }
            $has_valid_subscription = ((int) $valid_sub_qb->count_all_results()) > 0;
            if (!$has_valid_subscription) {
                continue;
            }

            $resume_sub_payload = array();
            if ($sub_status_active !== null) {
                $resume_sub_payload['status'] = $sub_status_active;
            }
            if (in_array('updated_at', $sub_fields, true)) {
                $resume_sub_payload['updated_at'] = date('Y-m-d H:i:s');
            }
            if (in_array('suspended_at', $sub_fields, true)) {
                $resume_sub_payload['suspended_at'] = null;
            }
            if (in_array('suspend_reason', $sub_fields, true)) {
                $resume_sub_payload['suspend_reason'] = null;
            }

            if (!empty($resume_sub_payload)) {
                $this->db
                    ->where('tenant_id', $tenant_id)
                    ->update('tenant_subscriptions', $resume_sub_payload);
            }

            $tenant_resume_payload = array();
            if ($tenant_status_active !== null) {
                $tenant_resume_payload['status'] = $tenant_status_active;
            }
            if (in_array('updated_at', $tenant_fields, true)) {
                $tenant_resume_payload['updated_at'] = date('Y-m-d H:i:s');
            }
            if (in_array('suspended_at', $tenant_fields, true)) {
                $tenant_resume_payload['suspended_at'] = null;
            }
            if (in_array('suspend_reason', $tenant_fields, true)) {
                $tenant_resume_payload['suspend_reason'] = null;
            }
            if (in_array('resumed_at', $tenant_fields, true)) {
                $tenant_resume_payload['resumed_at'] = date('Y-m-d H:i:s');
            }

            if (!empty($tenant_resume_payload)) {
                $this->db->where('id', $tenant_id)->update('tenants', $tenant_resume_payload);
            }

            $stats['resumed_tenants']++;
            $stats['tenant_resumed_ids'][$tenant_id] = $tenant_id;
        }

        // STEP 4: Disable background jobs untuk tenant suspended.
        if (!empty($stats['tenant_suspended_ids']) && $this->db->table_exists('background_jobs')) {
            $job_fields = $this->db->list_fields('background_jobs');
            if (in_array('tenant_id', $job_fields, true) && in_array('status', $job_fields, true)) {
                $job_payload = array('status' => 'cancelled');
                if (in_array('updated_at', $job_fields, true)) {
                    $job_payload['updated_at'] = date('Y-m-d H:i:s');
                }
                if (in_array('finished_at', $job_fields, true)) {
                    $job_payload['finished_at'] = date('Y-m-d H:i:s');
                }
                if (in_array('last_error', $job_fields, true)) {
                    $job_payload['last_error'] = 'Cancelled automatically: tenant suspended by SaaS enforcement.';
                }

                $this->db
                    ->where_in('tenant_id', array_values($stats['tenant_suspended_ids']))
                    ->where_in('status', array('pending', 'queued', 'processing'))
                    ->update('background_jobs', $job_payload);

                $stats['cancelled_background_jobs'] = (int) $this->db->affected_rows();
            }
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();
            $result = array(
                'success' => false,
                'message' => 'Gagal update status subscription tenant.',
                'stats' => $stats,
            );
            $this->record_cron_run('error', $started_at, $result, $start);
            return $this->json_response(500, $result);
        }

        $this->db->trans_commit();

        $stats['tenant_suspended_ids'] = array_values($stats['tenant_suspended_ids']);
        $stats['tenant_resumed_ids'] = array_values($stats['tenant_resumed_ids']);

        $result = array(
            'success' => true,
            'message' => 'SaaS subscription status check selesai.',
            'stats' => $stats,
            'period' => array(
                'today' => $today,
                'overdue_threshold' => $overdue_threshold,
            ),
        );
        $this->record_cron_run('success', $started_at, $result, $start);
        return $this->json_response(200, $result);
    }

    private function suspend_tenant_row($tenant_id, $reason, array $tenant_fields, $tenant_status_suspended = null)
    {
        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0) {
            return;
        }

        $payload = array();
        if ($tenant_status_suspended !== null) {
            $payload['status'] = $tenant_status_suspended;
        }
        if (in_array('updated_at', $tenant_fields, true)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('suspended_at', $tenant_fields, true)) {
            $payload['suspended_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('suspend_reason', $tenant_fields, true)) {
            $payload['suspend_reason'] = (string) $reason;
        }

        if (empty($payload)) {
            return;
        }

        $this->db->where('id', $tenant_id)->update('tenants', $payload);
    }

    private function resolve_unpaid_invoice_statuses(array $enum_values)
    {
        $candidates = array('pending', 'overdue', 'issued', 'partially_paid', 'unpaid', 'draft');
        if (empty($enum_values)) {
            return $candidates;
        }

        $result = array();
        foreach ($candidates as $status) {
            if (in_array($status, $enum_values, true)) {
                $result[] = $status;
            }
        }

        return $result;
    }

    private function pick_existing_status(array $enum_values, array $priority)
    {
        if (empty($priority)) {
            return null;
        }

        if (empty($enum_values)) {
            return $priority[0];
        }

        foreach ($priority as $status) {
            if (in_array($status, $enum_values, true)) {
                return $status;
            }
        }

        return null;
    }

    private function get_enum_values($table, $column)
    {
        $query = $this->db->query(
            "SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            array($this->db->database, (string) $table, (string) $column)
        );
        $row = $query->row_array();
        $column_type = (string) ($row['COLUMN_TYPE'] ?? '');
        if (strpos($column_type, 'enum(') !== 0) {
            return array();
        }

        $raw = substr($column_type, 5, -1);
        if ($raw === '') {
            return array();
        }

        $parts = str_getcsv($raw, ',', "'");
        $values = array();
        foreach ($parts as $part) {
            $v = trim((string) $part);
            if ($v !== '') {
                $values[] = $v;
            }
        }

        return array_values(array_unique($values));
    }

    private function authorize_cron()
    {
        if ($this->input->is_cli_request()) {
            return true;
        }

        $expected = $this->resolve_cron_token();
        if ($expected === '') {
            log_message('error', '[SAAS_CRON] cron token not configured.');
            show_error('Forbidden', 403);
            return false;
        }

        $actual = trim((string) $this->input->get('token', true));
        if ($expected !== '' && hash_equals($expected, $actual)) {
            return true;
        }

        show_error('Forbidden', 403);
        return false;
    }

    private function resolve_cron_token()
    {
        $from_env = trim((string) getenv('CRON_TOKEN'));
        if ($from_env !== '') {
            return $from_env;
        }

        $from_config = trim((string) $this->config->item('cron_token', 'cron'));
        if ($from_config !== '') {
            return $from_config;
        }

        return trim((string) config_item('cron_token'));
    }

    private function record_cron_run($status, $started_at, array $result, $start_timer)
    {
        if (!isset($this->system_monitoring_model) || !method_exists($this->system_monitoring_model, 'record_cron_run')) {
            return;
        }

        $this->system_monitoring_model->record_cron_run(
            'saas_cron.check_subscription_status',
            (string) $status,
            (string) $started_at,
            date('Y-m-d H:i:s'),
            (string) ($result['message'] ?? '-'),
            array(
                'duration_ms' => (int) round((microtime(true) - $start_timer) * 1000),
                'result' => $result,
            )
        );
    }

    private function json_response($status, array $payload)
    {
        return $this->output
            ->set_status_header((int) $status)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
    }
}
