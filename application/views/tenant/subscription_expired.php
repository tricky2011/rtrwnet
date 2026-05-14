<?php
$page_title = 'Subscription Expired - ' . app_name();
$page_heading = 'Subscription Tenant Tidak Aktif';
$page_subheading = 'Hubungi platform owner untuk perpanjangan paket.';
$active_menu = 'tenant_dashboard';

$latest_subscription = isset($latest_subscription) && is_array($latest_subscription) ? $latest_subscription : array();
$latest_invoice = isset($latest_invoice) && is_array($latest_invoice) ? $latest_invoice : array();
$tenant_suspended = !empty($tenant_suspended);
$package_name = (string) ($latest_subscription['package_name'] ?? $latest_subscription['package_name_legacy'] ?? '-');
$status = strtoupper((string) ($latest_subscription['status'] ?? '-'));
$end_date = (string) ($latest_subscription['end_date'] ?? '-');
$sub_start_date = (string) ($latest_subscription['start_date'] ?? '-');

$invoice_number = (string) ($latest_invoice['invoice_number'] ?? '-');
$invoice_status = strtoupper((string) ($latest_invoice['status'] ?? '-'));
$invoice_due_date = (string) ($latest_invoice['due_date'] ?? '-');
$invoice_amount = 0;
if (isset($latest_invoice['amount'])) {
    $invoice_amount = (float) $latest_invoice['amount'];
} elseif (isset($latest_invoice['total_amount'])) {
    $invoice_amount = (float) $latest_invoice['total_amount'];
} elseif (isset($latest_invoice['subtotal'])) {
    $invoice_amount = (float) $latest_invoice['subtotal'];
}

ob_start();
?>
<div class="card stat-card border-danger">
    <div class="card-body">
        <div class="alert alert-danger mb-3">
            Akses tenant dibatasi karena subscription sudah expired / suspended. Silakan lunasi invoice SaaS untuk mengaktifkan kembali sistem.
        </div>

        <?php if ($this->session->flashdata('success')): ?>
            <div class="alert alert-success mb-3"><?php echo $this->session->flashdata('success'); ?></div>
        <?php endif; ?>

        <?php if ($this->session->flashdata('error')): ?>
            <div class="alert alert-warning mb-3"><?php echo $this->session->flashdata('error'); ?></div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-4">
                <div class="small text-muted">Paket Terakhir</div>
                <div class="fw-semibold"><?php echo html_escape($package_name); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Status</div>
                <div>
                    <span class="badge <?php echo $tenant_suspended ? 'text-bg-danger' : 'text-bg-warning'; ?>">
                        <?php echo html_escape($status); ?>
                    </span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Expired Date</div>
                <div class="fw-semibold"><?php echo html_escape($end_date); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Mulai Subscription</div>
                <div class="fw-semibold"><?php echo html_escape($sub_start_date); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Invoice Terakhir</div>
                <div class="fw-semibold"><?php echo html_escape($invoice_number); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Status Invoice</div>
                <div>
                    <span class="badge <?php echo in_array(strtolower($invoice_status), array('paid', 'lunas'), true) ? 'text-bg-success' : 'text-bg-danger'; ?>">
                        <?php echo html_escape($invoice_status); ?>
                    </span>
                </div>
            </div>
        </div>

        <hr>

        <div class="row g-3 align-items-end">
            <div class="col-md-6">
                <div class="small text-muted">Jatuh Tempo Invoice</div>
                <div class="fw-semibold"><?php echo html_escape($invoice_due_date); ?></div>
            </div>
            <div class="col-md-6 text-md-end">
                <div class="small text-muted">Jumlah Tagihan SaaS</div>
                <div class="h5 fw-bold mb-0">Rp <?php echo number_format($invoice_amount, 0, ',', '.'); ?></div>
            </div>
        </div>

        <div class="mt-3 d-flex flex-wrap gap-2">
            <form action="<?php echo site_url('subscription/pay'); ?>" method="post" class="d-inline">
                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                <button type="submit" class="btn btn-primary">Bayar Sekarang</button>
            </form>
            <a href="<?php echo site_url('dashboard'); ?>" class="btn btn-outline-primary">Kembali ke Dashboard</a>
            <a href="<?php echo site_url('auth/logout'); ?>" class="btn btn-outline-secondary">Logout</a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
