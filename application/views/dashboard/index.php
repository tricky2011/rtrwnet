<?php
$brand_name = app_name();
$page_title = 'Dashboard - ' . $brand_name;
$page_heading = 'RTRWNet Control Center';
$page_subheading = 'Your centralized ISP automation and billing platform.';
$active_menu = 'dashboard';

$metrics = isset($metrics) && is_array($metrics) ? $metrics : array();
$monthly_summary = isset($monthly_summary) && is_array($monthly_summary) ? $monthly_summary : array();
$chart_series = isset($chart_series) && is_array($chart_series) ? $chart_series : array();
$show_monthly_summary = !empty($show_monthly_summary);
$teknisi_achievement_rows = isset($teknisi_achievement_rows) && is_array($teknisi_achievement_rows) ? $teknisi_achievement_rows : array();
$router_context = isset($router_context) && is_array($router_context) ? $router_context : array();

$total_customer = (int) ($metrics['total_customer'] ?? 0);
$active_customer = (int) ($metrics['active_customer'] ?? 0);
$suspended_customer = (int) ($metrics['suspended_customer'] ?? 0);
$static_customer = (int) ($metrics['static_customer'] ?? 0);
$total_unpaid = (float) ($metrics['total_unpaid'] ?? 0);
$income_month = (float) ($metrics['income_month'] ?? 0);

$instalasi_baru = (int) ($monthly_summary['instalasi_baru'] ?? 0);
$ticket_bulan_ini = (int) ($monthly_summary['ticket_bulan_ini'] ?? 0);
$total_pendapatan_bulan_ini = (float) ($monthly_summary['total_pendapatan'] ?? 0);
$total_pengeluaran_bulan_ini = (float) ($monthly_summary['total_pengeluaran'] ?? 0);
$error_log_bulan_ini = (int) ($monthly_summary['error_log'] ?? 0);
$ppp_active_bulan_ini = (int) ($monthly_summary['ppp_active'] ?? 0);
$total_customer_router = (int) ($monthly_summary['total_customer_router'] ?? 0);

$selected_router_name = trim((string) ($router_context['selected_router_name'] ?? 'Semua Router'));
$selected_router_id = isset($router_context['selected_router_id']) && $router_context['selected_router_id'] !== null
    ? (int) $router_context['selected_router_id']
    : 0;
$router_options = isset($router_context['router_options']) && is_array($router_context['router_options'])
    ? $router_context['router_options']
    : array();

$selected_router_ip = '-';
if ($selected_router_id > 0) {
    foreach ($router_options as $router_item) {
        if ((int) ($router_item['id'] ?? 0) === $selected_router_id) {
            $selected_router_ip = trim((string) ($router_item['ip_address'] ?? '-'));
            break;
        }
    }
} else {
    $selected_router_ip = 'Semua Router';
}

$chart_labels = isset($chart_series['labels']) && is_array($chart_series['labels']) ? $chart_series['labels'] : array();
$chart_revenue = isset($chart_series['revenue']) && is_array($chart_series['revenue']) ? $chart_series['revenue'] : array();
$chart_ppp = isset($chart_series['ppp_active']) && is_array($chart_series['ppp_active']) ? $chart_series['ppp_active'] : array();

$active_ratio = $total_customer > 0 ? min(100, max(0, round(($active_customer / $total_customer) * 100))) : 0;
$ppp_ratio = $total_customer > 0 ? min(100, max(0, round(($ppp_active_bulan_ini / $total_customer) * 100))) : 0;
$static_ratio = $total_customer > 0 ? min(100, max(0, round(($static_customer / $total_customer) * 100))) : 0;
$outstanding_ratio = $total_unpaid > 0 ? 100 : 0;

ob_start();
?>
<div class="alert alert-primary border-0 shadow-sm mb-3">
    <div class="fw-semibold">Welcome to <?php echo html_escape($brand_name); ?></div>
    <div class="small mb-0">Your centralized ISP automation and billing platform.</div>
</div>

<div class="mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div>
        <div class="small text-muted">Identity Router</div>
        <div class="fw-semibold"><?php echo html_escape($selected_router_name); ?> <span class="text-muted">(<?php echo html_escape($selected_router_ip); ?>)</span></div>
    </div>
    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2" style="border-radius: 999px;">
        Scope: <?php echo $selected_router_id > 0 ? 'Single Router' : 'All Router'; ?>
    </span>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="premium-stat h-100">
            <div class="icon-wrap icon-gradient-primary"><i class="ti ti-users"></i></div>
            <div class="label">Total Customers</div>
            <div class="value js-countup skeleton" data-countup="<?php echo (int) $total_customer; ?>" data-format="number"><?php echo number_format($total_customer); ?></div>
            <div class="mini-progress"><span style="width: <?php echo (int) $active_ratio; ?>%; background: linear-gradient(90deg,#2563eb,#60a5fa);"></span></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="premium-stat h-100">
            <div class="icon-wrap icon-gradient-success"><i class="ti ti-wifi"></i></div>
            <div class="label">PPP Active</div>
            <div class="value js-countup skeleton" data-countup="<?php echo (int) $ppp_active_bulan_ini; ?>" data-format="number"><?php echo number_format($ppp_active_bulan_ini); ?></div>
            <div class="mini-progress"><span style="width: <?php echo (int) $ppp_ratio; ?>%; background: linear-gradient(90deg,#16a34a,#4ade80);"></span></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="premium-stat h-100">
            <div class="icon-wrap icon-gradient-danger"><i class="ti ti-alert-circle"></i></div>
            <div class="label">Unpaid Invoice</div>
            <div class="value js-countup skeleton" data-countup="<?php echo (float) $total_unpaid; ?>" data-format="currency">Rp <?php echo number_format($total_unpaid, 0, ',', '.'); ?></div>
            <div class="mini-progress"><span style="width: <?php echo (int) $outstanding_ratio; ?>%; background: linear-gradient(90deg,#dc2626,#fb7185);"></span></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="premium-stat h-100">
            <div class="icon-wrap icon-gradient-info"><i class="ti ti-router"></i></div>
            <div class="label">Static Customers</div>
            <div class="value js-countup skeleton" data-countup="<?php echo (int) $static_customer; ?>" data-format="number"><?php echo number_format($static_customer); ?></div>
            <div class="mini-progress"><span style="width: <?php echo (int) $static_ratio; ?>%; background: linear-gradient(90deg,#0891b2,#22d3ee);"></span></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-xl-8">
        <div class="chart-card h-100">
            <div class="chart-title">Revenue Bulanan</div>
            <canvas id="revenueChart" height="110"></canvas>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="chart-card h-100">
            <div class="chart-title">PPP Active Trend</div>
            <canvas id="pppTrendChart" height="110"></canvas>
        </div>
    </div>
</div>

<?php if ($show_monthly_summary): ?>
<div class="card stat-card mb-3">
    <div class="card-header bg-white fw-semibold">Summary Bulan Ini</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Instalasi Baru</div><div class="h4 mb-0"><?php echo number_format($instalasi_baru); ?></div></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Ticket</div><div class="h4 mb-0"><?php echo number_format($ticket_bulan_ini); ?></div></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Total Pendapatan</div><div class="h4 mb-0 text-success">Rp <?php echo number_format($total_pendapatan_bulan_ini, 0, ',', '.'); ?></div></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Total Pengeluaran</div><div class="h4 mb-0 text-danger">Rp <?php echo number_format($total_pengeluaran_bulan_ini, 0, ',', '.'); ?></div></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Suspended</div><div class="h4 mb-0 text-danger"><?php echo number_format($suspended_customer); ?></div></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Total Outstanding</div><div class="h4 mb-0 text-warning">Rp <?php echo number_format($total_unpaid, 0, ',', '.'); ?></div></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Penambahan Customer / Router</div><div class="h4 mb-0"><?php echo number_format($total_customer_router); ?></div></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Error Log</div><div class="h4 mb-0 <?php echo $error_log_bulan_ini > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo number_format($error_log_bulan_ini); ?></div></div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">Pencapaian Teknisi Bulan Ini</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:60px;">#</th>
                    <th>Teknisi</th>
                    <th class="text-center">Instalasi Selesai</th>
                    <th class="text-center">Ticket Selesai</th>
                    <th class="text-center">Ticket Pending</th>
                    <th class="text-center">Poin</th>
                    <th class="text-center">Avg Resolve</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($teknisi_achievement_rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada data pencapaian teknisi bulan ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($teknisi_achievement_rows as $idx => $row): ?>
                        <?php $avg = isset($row['avg_resolve_minutes']) && $row['avg_resolve_minutes'] !== null ? round((float) $row['avg_resolve_minutes']) . ' menit' : '-'; ?>
                        <tr>
                            <td><?php echo (int) $idx + 1; ?></td>
                            <td><?php echo html_escape((string) ($row['technician_name'] ?? '-')); ?></td>
                            <td class="text-center"><?php echo (int) ($row['wo_done'] ?? 0); ?></td>
                            <td class="text-center"><?php echo (int) ($row['ticket_done'] ?? 0); ?></td>
                            <td class="text-center"><?php echo (int) ($row['ticket_pending'] ?? 0); ?></td>
                            <td class="text-center fw-semibold"><?php echo (int) ($row['total_points'] ?? 0); ?></td>
                            <td class="text-center"><?php echo $avg; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();

$page_scripts = <<<'SCRIPT'
<script>
(function () {
    const revenueCtx = document.getElementById('revenueChart');
    const pppCtx = document.getElementById('pppTrendChart');
    const labels = %s;
    const revenueData = %s;
    const pppData = %s;

    if (revenueCtx && typeof Chart !== 'undefined') {
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Revenue',
                    data: revenueData,
                    tension: 0.35,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,.15)',
                    fill: true,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }

    if (pppCtx && typeof Chart !== 'undefined') {
        new Chart(pppCtx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'PPP Active',
                    data: pppData,
                    backgroundColor: 'rgba(22,163,74,.75)',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }
})();
</script>
SCRIPT;

$page_scripts = sprintf(
    $page_scripts,
    json_encode(array_values($chart_labels)),
    json_encode(array_map('floatval', $chart_revenue)),
    json_encode(array_map('intval', $chart_ppp))
);

include APPPATH . 'views/layout/master.php';
