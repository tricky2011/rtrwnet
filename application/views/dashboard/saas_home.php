<?php
$page_title = 'Platform Dashboard - ' . app_name();
$page_heading = 'SaaS Platform Dashboard';
$page_subheading = 'Ringkasan tenant, subscription, dan monetisasi platform.';
$active_menu = 'dashboard';

$saas_ready = !empty($saas_ready);
$kpi = isset($kpi) && is_array($kpi) ? $kpi : array();
$status_distribution = isset($status_distribution) && is_array($status_distribution) ? $status_distribution : array('labels' => array(), 'values' => array());
$package_distribution = isset($package_distribution) && is_array($package_distribution) ? $package_distribution : array('labels' => array(), 'values' => array());
$recent_invoices = isset($recent_invoices) && is_array($recent_invoices) ? $recent_invoices : array();
$expiring_tenants = isset($expiring_tenants) && is_array($expiring_tenants) ? $expiring_tenants : array();

$kpi_total_tenants = (int) ($kpi['total_tenants'] ?? 0);
$kpi_active_tenants = (int) ($kpi['active_tenants'] ?? 0);
$kpi_active_subscriptions = (int) ($kpi['active_subscriptions'] ?? 0);
$kpi_expiring_7_days = (int) ($kpi['expiring_7_days'] ?? 0);
$kpi_mrr = (float) ($kpi['mrr'] ?? 0);
$kpi_arr = (float) ($kpi['arr'] ?? 0);
$kpi_revenue_month = (float) ($kpi['revenue_month'] ?? 0);
$kpi_overdue_invoices = (int) ($kpi['overdue_invoices'] ?? 0);
$kpi_pending_receivable = (float) ($kpi['pending_receivable'] ?? 0);

ob_start();
?>

<?php if (!$saas_ready): ?>
    <div class="alert alert-warning mb-3">
        Tabel SaaS (`tenants`, `tenant_subscriptions`, `tenant_invoices`) belum lengkap. Jalankan migration SaaS foundation terlebih dahulu.
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100 border-primary-subtle">
            <div class="card-body">
                <div class="text-muted small">Total Tenant</div>
                <div class="stat-value"><?php echo number_format($kpi_total_tenants); ?></div>
                <div class="small text-muted">Active: <?php echo number_format($kpi_active_tenants); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100 border-success-subtle">
            <div class="card-body">
                <div class="text-muted small">Subscription Aktif</div>
                <div class="stat-value text-success"><?php echo number_format($kpi_active_subscriptions); ?></div>
                <div class="small <?php echo $kpi_expiring_7_days > 0 ? 'text-danger' : 'text-muted'; ?>">
                    Expiring 7 hari: <?php echo number_format($kpi_expiring_7_days); ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100 border-warning-subtle">
            <div class="card-body">
                <div class="text-muted small">MRR (Estimasi)</div>
                <div class="stat-value text-warning">Rp <?php echo number_format($kpi_mrr, 0, ',', '.'); ?></div>
                <div class="small text-muted">ARR: Rp <?php echo number_format($kpi_arr, 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100 border-danger-subtle">
            <div class="card-body">
                <div class="text-muted small">Overdue Invoice</div>
                <div class="stat-value text-danger"><?php echo number_format($kpi_overdue_invoices); ?></div>
                <div class="small text-muted">Piutang: Rp <?php echo number_format($kpi_pending_receivable, 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Tenant by Status</div>
            <div class="card-body" style="height: 320px;">
                <canvas id="tenantStatusChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Active Subscription by Package</div>
            <div class="card-body" style="height: 320px;">
                <canvas id="packageDistributionChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Tenant Akan Expired (14 hari)</span>
                <span class="badge text-bg-warning"><?php echo number_format(count($expiring_tenants)); ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tenant</th>
                            <th>Paket</th>
                            <th>Expired</th>
                            <th class="text-end">Sisa Hari</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($expiring_tenants)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">Tidak ada tenant yang akan expired.</td></tr>
                    <?php else: ?>
                        <?php foreach ($expiring_tenants as $row): ?>
                            <?php $days_left = (int) ($row['days_left'] ?? 0); ?>
                            <tr>
                                <td><?php echo html_escape((string) ($row['tenant_name'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['package_name'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['end_date'] ?? '-')); ?></td>
                                <td class="text-end <?php echo $days_left <= 3 ? 'text-danger fw-semibold' : ''; ?>">
                                    <?php echo number_format($days_left); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Invoice Tenant Terbaru</span>
                <span class="text-muted small">Revenue bulan ini: <strong>Rp <?php echo number_format($kpi_revenue_month, 0, ',', '.'); ?></strong></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice</th>
                            <th>Tenant</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recent_invoices)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">Belum ada data invoice tenant.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_invoices as $row): ?>
                            <?php
                            $status = strtolower((string) ($row['status'] ?? 'pending'));
                            $badge = 'secondary';
                            if ($status === 'paid') {
                                $badge = 'success';
                            } elseif ($status === 'overdue') {
                                $badge = 'danger';
                            } elseif ($status === 'pending' || $status === 'issued') {
                                $badge = 'warning';
                            }
                            ?>
                            <tr>
                                <td><?php echo html_escape((string) ($row['invoice_number'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['tenant_name'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['due_date'] ?? '-')); ?></td>
                                <td><span class="badge text-bg-<?php echo $badge; ?>"><?php echo strtoupper($status); ?></span></td>
                                <td class="text-end">Rp <?php echo number_format((float) ($row['amount'] ?? 0), 0, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

$status_labels = $status_distribution['labels'] ?? array();
$status_values = $status_distribution['values'] ?? array();
$package_labels = $package_distribution['labels'] ?? array();
$package_values = $package_distribution['values'] ?? array();

$page_scripts = '
<script>
(function () {
    var statusCtx = document.getElementById("tenantStatusChart");
    if (statusCtx) {
        var statusLabels = ' . json_encode(array_values($status_labels)) . ';
        var statusValues = ' . json_encode(array_values($status_values)) . ';
        new Chart(statusCtx, {
            type: "bar",
            data: {
                labels: statusLabels,
                datasets: [{
                    label: "Tenant",
                    data: statusValues,
                    backgroundColor: ["#2563eb", "#16a34a", "#f59e0b", "#dc2626", "#6b7280"]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    var packageCtx = document.getElementById("packageDistributionChart");
    if (packageCtx) {
        var packageLabels = ' . json_encode(array_values($package_labels)) . ';
        var packageValues = ' . json_encode(array_values($package_values)) . ';
        new Chart(packageCtx, {
            type: "doughnut",
            data: {
                labels: packageLabels.length ? packageLabels : ["No Data"],
                datasets: [{
                    data: packageValues.length ? packageValues : [1],
                    backgroundColor: ["#0ea5e9", "#6366f1", "#10b981", "#f59e0b", "#ef4444", "#8b5cf6", "#14b8a6"]
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }
})();
</script>';

include APPPATH . 'views/layout/master.php';

