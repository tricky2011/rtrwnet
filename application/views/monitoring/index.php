<?php
$page_title = isset($page_title) ? $page_title : 'System Monitoring - ' . app_name();
$page_heading = isset($page_heading) ? $page_heading : 'System Monitoring';
$page_subheading = isset($page_subheading) ? $page_subheading : 'Realtime ISP Monitoring';
$active_menu = isset($active_menu) ? $active_menu : 'monitoring';
$refresh_seconds = isset($refresh_seconds) ? (int) $refresh_seconds : 10;
$refresh_seconds = $refresh_seconds > 0 ? $refresh_seconds : 10;
$snapshot = isset($snapshot) && is_array($snapshot) ? $snapshot : array();
$snapshot_json = isset($snapshot_json) && $snapshot_json !== '' ? $snapshot_json : '{}';
$interface_settings = isset($interface_settings) && is_array($interface_settings) ? $interface_settings : array();
$iface_can_edit = !empty($interface_settings['can_edit']);
$iface_router_id = (int) ($interface_settings['router_id'] ?? 0);
$iface_router_name = trim((string) ($interface_settings['router_name'] ?? ''));
$iface_selected = isset($interface_settings['interfaces']) && is_array($interface_settings['interfaces'])
    ? array_values(array_unique(array_map('strtolower', array_map('trim', $interface_settings['interfaces']))))
    : array();
$iface_watchlist = isset($interface_settings['down_watchlist']) && is_array($interface_settings['down_watchlist'])
    ? array_values(array_unique(array_map('strtolower', array_map('trim', $interface_settings['down_watchlist']))))
    : array();
$iface_available = isset($interface_settings['available_interfaces']) && is_array($interface_settings['available_interfaces'])
    ? $interface_settings['available_interfaces']
    : array();
$iface_catalog_error = trim((string) ($interface_settings['catalog_error'] ?? ''));

ob_start();
?>
<style>
    .monitor-badge { font-size: .73rem; font-weight: 700; letter-spacing: .02em; }
    .monitor-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }
    .monitor-stat-value {
        font-size: 1.4rem;
        font-weight: 700;
        line-height: 1.2;
    }
    .table-compact td,
    .table-compact th {
        padding: .45rem .55rem;
        vertical-align: middle;
    }
    .resource-value { font-size: 1.1rem; font-weight: 700; }
    .chart-container { min-height: 290px; }
    .table-monitor-scroll { max-height: 350px; overflow: auto; }
    .router-summary-wrap { max-height: 230px; overflow: auto; }
    @media (max-width: 767.98px) {
        .chart-container { min-height: 240px; }
    }
</style>
<?php if ($this->session->flashdata('success')): ?>
<div class="alert alert-success"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
<div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>

<div class="card stat-card mb-3">
    <div class="card-body py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="small text-muted" id="scopeSummaryText">Scope monitoring: memuat...</div>
        <span class="badge bg-secondary monitor-badge" id="scopeModeBadge">SCOPE</span>
    </div>
    <div class="router-summary-wrap d-none" id="routerSummaryWrap">
        <table class="table table-sm table-striped mb-0 table-compact">
            <thead class="table-light">
                <tr>
                    <th>Router</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">CPU %</th>
                    <th class="text-end">Memory %</th>
                    <th class="text-end">PPP Active</th>
                    <th class="text-end">RX Mbps</th>
                    <th class="text-end">TX Mbps</th>
                </tr>
            </thead>
            <tbody id="routerSummaryRows">
                <tr><td colspan="7" class="text-center text-muted">Memuat ringkasan router...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card stat-card mb-3">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0"><i class="bi bi-sliders me-2"></i>Interface Scope Per Router</h2>
    </div>
    <div class="card-body">
        <?php if (!$iface_can_edit): ?>
            <div class="alert alert-info mb-0">
                Pilih router spesifik (bukan <strong>Semua Router</strong>) di header atas untuk mengatur interface monitoring per router.
            </div>
        <?php else: ?>
            <?php echo form_open('monitoring/save_interface_config', array('class' => 'row g-3')); ?>
                <input type="hidden" name="router_id" value="<?php echo (int) $iface_router_id; ?>">
                <div class="col-12">
                    <div class="small text-muted">
                        Router: <strong><?php echo html_escape($iface_router_name !== '' ? $iface_router_name : ('Router #' . $iface_router_id)); ?></strong>
                    </div>
                </div>

                <?php if ($iface_catalog_error !== ''): ?>
                    <div class="col-12">
                        <div class="alert alert-warning py-2 mb-0"><?php echo html_escape($iface_catalog_error); ?></div>
                    </div>
                <?php endif; ?>

                <div class="col-lg-6">
                    <label class="form-label">Monitored Interfaces</label>
                    <select class="form-select js-monitor-interface-select" name="monitor_interfaces[]" multiple>
                        <?php foreach ($iface_available as $row): ?>
                            <?php
                            $name = strtolower(trim((string) ($row['normalized_name'] ?? $row['name'] ?? '')));
                            if ($name === '') { continue; }
                            $label = trim((string) ($row['name'] ?? $name));
                            $type = trim((string) ($row['type'] ?? '-'));
                            $selected = in_array($name, $iface_selected, true) ? 'selected' : '';
                            ?>
                            <option value="<?php echo html_escape($name); ?>" <?php echo $selected; ?>>
                                <?php echo html_escape($label . ' (' . $type . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Tipe yang ditampilkan hanya: <strong>Bridge, Ethernet, Vlan</strong>. Kosong = fallback ke konfigurasi default global.</div>
                    <label class="form-label mt-2 mb-1">Tambahan manual (opsional)</label>
                    <textarea class="form-control" rows="2" name="monitor_interfaces_custom" placeholder="Pisahkan dengan koma / baris baru"></textarea>
                </div>

                <div class="col-lg-6">
                    <label class="form-label">Interface Down Watchlist</label>
                    <select class="form-select js-monitor-watchlist-select" name="monitor_down_watchlist[]" multiple>
                        <?php foreach ($iface_available as $row): ?>
                            <?php
                            $name = strtolower(trim((string) ($row['normalized_name'] ?? $row['name'] ?? '')));
                            if ($name === '') { continue; }
                            $label = trim((string) ($row['name'] ?? $name));
                            $type = trim((string) ($row['type'] ?? '-'));
                            $selected = in_array($name, $iface_watchlist, true) ? 'selected' : '';
                            ?>
                            <option value="<?php echo html_escape($name); ?>" <?php echo $selected; ?>>
                                <?php echo html_escape($label . ' (' . $type . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Tipe yang ditampilkan hanya: <strong>Bridge, Ethernet, Vlan</strong>. Kosong = fallback ke watchlist default global.</div>
                    <label class="form-label mt-2 mb-1">Tambahan manual (opsional)</label>
                    <textarea class="form-control" rows="2" name="monitor_down_watchlist_custom" placeholder="Pisahkan dengan koma / baris baru"></textarea>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i>Simpan Interface Router
                    </button>
                </div>
            <?php echo form_close(); ?>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4 col-xl">
        <div class="card stat-card h-100 border-primary-subtle">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Total PPP Online</div>
                        <div class="monitor-stat-value" id="sumPppOnline">0</div>
                    </div>
                    <span class="monitor-stat-icon bg-primary-subtle text-primary"><i class="bi bi-plug"></i></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card stat-card h-100 border-warning-subtle">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">CPU Load</div>
                        <div class="monitor-stat-value" id="sumCpuLoad">0%</div>
                    </div>
                    <span class="monitor-stat-icon bg-warning-subtle text-warning"><i class="bi bi-cpu"></i></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card stat-card h-100 border-success-subtle">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Revenue Hari Ini</div>
                        <div class="monitor-stat-value fs-5" id="sumRevenueToday">Rp 0</div>
                    </div>
                    <span class="monitor-stat-icon bg-success-subtle text-success"><i class="bi bi-cash-coin"></i></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl">
        <div class="card stat-card h-100 border-danger-subtle">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Total Unpaid Invoice</div>
                        <div class="monitor-stat-value" id="sumUnpaid">0</div>
                    </div>
                    <span class="monitor-stat-icon bg-danger-subtle text-danger"><i class="bi bi-receipt-cutoff"></i></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl">
        <div class="card stat-card h-100 border-secondary-subtle">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Customer Isolir</div>
                        <div class="monitor-stat-value" id="sumIsolir">0</div>
                    </div>
                    <span class="monitor-stat-icon bg-secondary-subtle text-secondary"><i class="bi bi-person-dash"></i></span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-4">
        <div class="card stat-card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0"><i class="bi bi-server me-2"></i>Router Resource</h2>
                <span id="routerOnlineBadge" class="badge text-bg-secondary monitor-badge">CHECKING</span>
            </div>
            <div class="card-body">
                <div class="mb-2 d-flex justify-content-between">
                    <span>CPU</span>
                    <span class="resource-value" id="routerCpu">0%</span>
                </div>
                <div class="progress mb-3">
                    <div id="routerCpuBar" class="progress-bar bg-warning" style="width: 0%"></div>
                </div>

                <div class="mb-2 d-flex justify-content-between">
                    <span>Memory</span>
                    <span class="resource-value" id="routerMemory">0%</span>
                </div>
                <div class="progress mb-3">
                    <div id="routerMemoryBar" class="progress-bar bg-info" style="width: 0%"></div>
                </div>

                <div class="mb-2 d-flex justify-content-between">
                    <span>Disk</span>
                    <span class="resource-value" id="routerDisk">0%</span>
                </div>
                <div class="progress mb-3">
                    <div id="routerDiskBar" class="progress-bar bg-success" style="width: 0%"></div>
                </div>

                <div class="small text-muted">
                    <div>Uptime: <span id="routerUptime">-</span></div>
                    <div>Board: <span id="routerBoard">-</span></div>
                    <div>Version: <span id="routerVersion">-</span></div>
                    <div>Temperature: <span id="routerTemp">-</span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card stat-card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Realtime Traffic</h2>
                <span class="badge text-bg-info monitor-badge">AUTO REFRESH <?php echo (int) $refresh_seconds; ?>s</span>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="trafficRealtimeChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-7">
        <div class="card stat-card">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="bi bi-hdd-network me-2"></i>Interface Traffic Monitoring</h2>
            </div>
            <div class="card-body p-0 table-monitor-scroll">
                <table class="table table-striped table-hover table-compact mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Interface</th>
                            <th>Status</th>
                            <th class="text-end">RX (Mbps)</th>
                            <th class="text-end">TX (Mbps)</th>
                            <th class="text-end">Drop</th>
                            <th class="text-end">Error</th>
                        </tr>
                    </thead>
                    <tbody id="interfaceRows">
                        <tr><td colspan="6" class="text-center text-muted">Memuat data...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card stat-card">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="bi bi-activity me-2"></i>Resource Trend</h2>
            </div>
            <div class="card-body">
                <div class="chart-container" style="min-height: 260px;">
                    <canvas id="resourceTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-6">
        <div class="card stat-card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0"><i class="bi bi-person-check me-2"></i>PPP Active Monitoring</h2>
                <span class="badge text-bg-success monitor-badge">
                    Active: <span id="pppActiveCount">0</span> | Online Today: <span id="pppTodayCount">0</span>
                </span>
            </div>
            <div class="card-body p-0 table-monitor-scroll">
                <table class="table table-hover table-compact mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Username</th>
                            <th>IP Address</th>
                            <th>Login Time</th>
                            <th>Session</th>
                        </tr>
                    </thead>
                    <tbody id="pppRows">
                        <tr><td colspan="4" class="text-center text-muted">Memuat data...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="bi bi-receipt me-2"></i>Customer Billing Status</h2>
            </div>
            <div class="card-body">
                <div class="row g-2 text-center">
                    <div class="col-6 col-md-3">
                        <span class="badge text-bg-success w-100 py-2">Lunas: <span id="billingLunas">0</span></span>
                    </div>
                    <div class="col-6 col-md-3">
                        <span class="badge text-bg-warning w-100 py-2">Jatuh Tempo: <span id="billingJatuhTempo">0</span></span>
                    </div>
                    <div class="col-6 col-md-3">
                        <span class="badge text-bg-danger w-100 py-2">Belum Bayar: <span id="billingBelumBayar">0</span></span>
                    </div>
                    <div class="col-6 col-md-3">
                        <span class="badge text-bg-secondary w-100 py-2">Isolir: <span id="billingIsolir">0</span></span>
                    </div>
                </div>
                <div class="mt-3 small text-muted">
                    Auto-isolir sync aktif via cron monitoring. PPP secret customer isolir akan di-`disabled=yes`.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-5">
        <div class="card stat-card h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="bi bi-heart-pulse me-2"></i>Network Health Check</h2>
            </div>
            <div class="card-body">
                <table class="table table-compact mb-3">
                    <thead class="table-light">
                        <tr>
                            <th>Target</th>
                            <th>Status</th>
                            <th>Latency</th>
                            <th>Loss</th>
                        </tr>
                    </thead>
                    <tbody id="networkRows">
                        <tr><td colspan="4" class="text-center text-muted">Memuat data...</td></tr>
                    </tbody>
                </table>
                <div class="small text-muted">
                    <div>Gateway RTO threshold: <span id="gatewayRtoThreshold">300</span> detik</div>
                    <div>Last update: <span id="lastUpdateTime">-</span></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card stat-card h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="bi bi-exclamation-triangle me-2"></i>System Log Monitoring</h2>
            </div>
            <div class="card-body p-0 table-monitor-scroll">
                <table class="table table-hover table-compact mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Time</th>
                            <th>Topics</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody id="logRows">
                        <tr><td colspan="3" class="text-center text-muted">Memuat data...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (in_array((string) $this->session->userdata('role'), array('superadmin', 'admin'), true)): ?>
<div class="card stat-card">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <button type="button" class="btn btn-primary btn-sm" id="btnManualHealthCheck">
            <i class="bi bi-play-fill me-1"></i>Run Health Check Now
        </button>
        <span class="small text-muted" id="manualCheckStatus">Belum ada eksekusi manual.</span>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();

$endpoint_js = json_encode(site_url('monitoring/snapshot_json'));
$manual_check_endpoint_js = json_encode(site_url('monitoring/check_now'));
$refresh_ms = (int) $refresh_seconds * 1000;
$initial_snapshot_js = $snapshot_json;

$page_scripts = <<<SCRIPT
<script>
(function () {
    var endpoint = {$endpoint_js};
    var manualCheckEndpoint = {$manual_check_endpoint_js};
    var refreshMs = {$refresh_ms};
    var initialSnapshot = {$initial_snapshot_js} || {};
    var maxPoints = 30;

    var trafficLabels = [];
    var trafficRx = [];
    var trafficTx = [];
    var resourceCpu = [];
    var resourceMem = [];

    var trafficChart = null;
    var resourceChart = null;

    function toRupiah(value) {
        var n = Number(value || 0);
        return 'Rp ' + n.toLocaleString('id-ID');
    }

    function statusBadge(status) {
        var s = (status || '').toLowerCase();
        if (s === 'running' || s === 'up') return '<span class="badge bg-success monitor-badge">RUNNING</span>';
        if (s === 'down') return '<span class="badge bg-danger monitor-badge">DOWN</span>';
        return '<span class="badge bg-secondary monitor-badge">' + String(status || '-').toUpperCase() + '</span>';
    }

    function ensureCharts() {
        if (typeof Chart === 'undefined') {
            return;
        }

        var trafficCtx = document.getElementById('trafficRealtimeChart');
        if (trafficCtx && !trafficChart) {
            trafficChart = new Chart(trafficCtx, {
                type: 'line',
                data: {
                    labels: trafficLabels,
                    datasets: [
                        { label: 'RX Mbps', data: trafficRx, borderColor: '#0ea5e9', backgroundColor: 'rgba(14,165,233,.2)', tension: .3, fill: true },
                        { label: 'TX Mbps', data: trafficTx, borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.2)', tension: .3, fill: true }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Mbps' } }
                    }
                }
            });
        }

        var resCtx = document.getElementById('resourceTrendChart');
        if (resCtx && !resourceChart) {
            resourceChart = new Chart(resCtx, {
                type: 'line',
                data: {
                    labels: trafficLabels,
                    datasets: [
                        { label: 'CPU %', data: resourceCpu, borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.15)', tension: .25, fill: true },
                        { label: 'Memory %', data: resourceMem, borderColor: '#8b5cf6', backgroundColor: 'rgba(139,92,246,.15)', tension: .25, fill: true }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    scales: {
                        y: { beginAtZero: true, suggestedMax: 100, title: { display: true, text: 'Percent (%)' } }
                    }
                }
            });
        }
    }

    function pushPoint(label, rx, tx, cpu, mem) {
        trafficLabels.push(label);
        trafficRx.push(Number(rx || 0));
        trafficTx.push(Number(tx || 0));
        resourceCpu.push(Number(cpu || 0));
        resourceMem.push(Number(mem || 0));

        while (trafficLabels.length > maxPoints) {
            trafficLabels.shift();
            trafficRx.shift();
            trafficTx.shift();
            resourceCpu.shift();
            resourceMem.shift();
        }
    }

    function updateSummary(summary) {
        summary = summary || {};
        document.getElementById('sumPppOnline').textContent = Number(summary.total_ppp_online || 0).toLocaleString('id-ID');
        document.getElementById('sumCpuLoad').textContent = Number(summary.cpu_load_percent || 0).toFixed(0) + '%';
        document.getElementById('sumRevenueToday').textContent = toRupiah(summary.revenue_today || 0);
        document.getElementById('sumUnpaid').textContent = Number(summary.total_unpaid_invoice || 0).toLocaleString('id-ID');
        document.getElementById('sumIsolir').textContent = Number(summary.total_customer_isolir || 0).toLocaleString('id-ID');
    }

    function updateScope(scope, routerSummary) {
        scope = scope || {};
        routerSummary = routerSummary || {};
        var isAll = !!scope.is_all;
        var totalRouter = Number(scope.total_router || 0);
        var onlineRouter = Number(scope.online_router || 0);
        var selectedName = String(scope.selected_router_name || '-');

        var scopeTextEl = document.getElementById('scopeSummaryText');
        var scopeBadgeEl = document.getElementById('scopeModeBadge');
        var summaryWrapEl = document.getElementById('routerSummaryWrap');
        var summaryRowsEl = document.getElementById('routerSummaryRows');

        if (scopeTextEl) {
            if (isAll) {
                scopeTextEl.textContent = 'Scope monitoring: Semua Router (' + onlineRouter + '/' + totalRouter + ' online)';
            } else {
                scopeTextEl.textContent = 'Scope monitoring: ' + selectedName;
            }
        }

        if (scopeBadgeEl) {
            if (isAll) {
                scopeBadgeEl.className = 'badge bg-primary monitor-badge';
                scopeBadgeEl.textContent = 'SEMUA ROUTER';
            } else {
                scopeBadgeEl.className = 'badge bg-info monitor-badge';
                scopeBadgeEl.textContent = selectedName.toUpperCase();
            }
        }

        if (!summaryWrapEl || !summaryRowsEl) {
            return;
        }

        var rows = Array.isArray(routerSummary.rows) ? routerSummary.rows : [];
        if (!isAll || !rows.length) {
            summaryWrapEl.classList.add('d-none');
            summaryRowsEl.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Ringkasan router tidak tersedia.</td></tr>';
            return;
        }

        summaryWrapEl.classList.remove('d-none');
        summaryRowsEl.innerHTML = rows.map(function (row) {
            var online = !!row.online;
            var statusBadge = online
                ? '<span class="badge bg-success monitor-badge">ONLINE</span>'
                : '<span class="badge bg-danger monitor-badge">OFFLINE</span>';

            var name = row.router_name || ('Router #' + Number(row.router_id || 0));
            var ip = row.ip_address ? ' <span class="text-muted">(' + row.ip_address + ')</span>' : '';
            return '<tr>' +
                '<td>' + name + ip + '</td>' +
                '<td class="text-center">' + statusBadge + '</td>' +
                '<td class="text-end">' + Number(row.cpu_load_percent || 0).toFixed(0) + '</td>' +
                '<td class="text-end">' + Number(row.memory_usage_percent || 0).toFixed(1) + '</td>' +
                '<td class="text-end">' + Number(row.ppp_active || 0).toLocaleString('id-ID') + '</td>' +
                '<td class="text-end">' + Number(row.rx_mbps || 0).toFixed(3) + '</td>' +
                '<td class="text-end">' + Number(row.tx_mbps || 0).toFixed(3) + '</td>' +
            '</tr>';
        }).join('');
    }

    function updateRouter(router) {
        router = router || {};
        var cpu = Number(router.cpu_load_percent || 0);
        var mem = Number(router.memory_usage_percent || 0);
        var disk = Number(router.disk_usage_percent || 0);

        document.getElementById('routerCpu').textContent = cpu.toFixed(0) + '%';
        document.getElementById('routerCpuBar').style.width = Math.min(100, cpu) + '%';

        document.getElementById('routerMemory').textContent = mem.toFixed(1) + '%';
        document.getElementById('routerMemoryBar').style.width = Math.min(100, mem) + '%';

        document.getElementById('routerDisk').textContent = disk.toFixed(1) + '%';
        document.getElementById('routerDiskBar').style.width = Math.min(100, disk) + '%';

        document.getElementById('routerUptime').textContent = router.uptime || '-';
        document.getElementById('routerBoard').textContent = router.board_name || '-';
        document.getElementById('routerVersion').textContent = router.version || '-';
        document.getElementById('routerTemp').textContent = (router.temperature_celsius !== null && router.temperature_celsius !== undefined)
            ? Number(router.temperature_celsius).toFixed(1) + ' °C'
            : 'N/A';

        var badge = document.getElementById('routerOnlineBadge');
        if (router.online) {
            badge.className = 'badge bg-success monitor-badge';
            badge.textContent = 'ONLINE';
        } else {
            badge.className = 'badge bg-danger monitor-badge';
            badge.textContent = 'OFFLINE';
        }
    }

    function updateInterfaces(interfaces) {
        interfaces = interfaces || {};
        var rows = interfaces.rows || [];
        var tbody = document.getElementById('interfaceRows');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Tidak ada data interface.</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map(function (r) {
            return '<tr>' +
                '<td>' + (r.name || '-') + '</td>' +
                '<td>' + statusBadge(r.status) + '</td>' +
                '<td class="text-end">' + Number(r.rx_mbps || 0).toFixed(3) + '</td>' +
                '<td class="text-end">' + Number(r.tx_mbps || 0).toFixed(3) + '</td>' +
                '<td class="text-end">' + Number(r.packet_drop || 0).toFixed(0) + '</td>' +
                '<td class="text-end">' + Number(r.packet_error || 0).toFixed(0) + '</td>' +
            '</tr>';
        }).join('');
    }

    function updatePpp(ppp) {
        ppp = ppp || {};
        var rows = ppp.rows || [];
        document.getElementById('pppActiveCount').textContent = Number(ppp.total_active || 0).toLocaleString('id-ID');
        document.getElementById('pppTodayCount').textContent = Number(ppp.online_today || 0).toLocaleString('id-ID');

        var tbody = document.getElementById('pppRows');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">PPP active kosong.</td></tr>';
            return;
        }

        tbody.innerHTML = rows.slice(0, 50).map(function (r) {
            return '<tr>' +
                '<td>' + (r.username || '-') + '</td>' +
                '<td>' + (r.ip_address || '-') + '</td>' +
                '<td>' + (r.login_time || '-') + '</td>' +
                '<td>' + (r.session_duration || '-') + '</td>' +
            '</tr>';
        }).join('');
    }

    function updateBilling(billing) {
        billing = billing || {};
        document.getElementById('billingLunas').textContent = Number(billing.lunas || 0).toLocaleString('id-ID');
        document.getElementById('billingJatuhTempo').textContent = Number(billing.jatuh_tempo || 0).toLocaleString('id-ID');
        document.getElementById('billingBelumBayar').textContent = Number(billing.belum_bayar || 0).toLocaleString('id-ID');
        document.getElementById('billingIsolir').textContent = Number(billing.isolir || 0).toLocaleString('id-ID');
    }

    function updateNetwork(network, thresholds, generatedAt) {
        network = network || {};
        thresholds = thresholds || {};
        var rows = [];
        var gateway = network.gateway || {};
        var publicDns = network.public_dns || {};
        rows.push(gateway);
        rows.push(publicDns);

        var tbody = document.getElementById('networkRows');
        tbody.innerHTML = rows.map(function (r) {
            var stat = (r.status || 'down').toLowerCase();
            var statusHtml = statusBadge(stat === 'up' ? 'running' : 'down');
            var lat = (r.latency_ms === null || r.latency_ms === undefined) ? '-' : Number(r.latency_ms).toFixed(2) + ' ms';
            var loss = (r.packet_loss_percent === null || r.packet_loss_percent === undefined) ? '-' : Number(r.packet_loss_percent).toFixed(2) + '%';
            return '<tr>' +
                '<td>' + (r.target || '-') + '</td>' +
                '<td>' + statusHtml + '</td>' +
                '<td>' + lat + '</td>' +
                '<td>' + loss + '</td>' +
            '</tr>';
        }).join('');

        document.getElementById('gatewayRtoThreshold').textContent = Number(thresholds.gateway_rto_seconds || 300).toLocaleString('id-ID');
        document.getElementById('lastUpdateTime').textContent = generatedAt || '-';
    }

    function updateLogs(logs) {
        logs = logs || {};
        var rows = logs.rows || [];
        var tbody = document.getElementById('logRows');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Tidak ada log penting terbaru.</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map(function (r) {
            var sev = String(r.severity || 'secondary').toLowerCase();
            var badge = '<span class="badge bg-' + (sev === 'danger' ? 'danger' : (sev === 'warning' ? 'warning text-dark' : 'secondary')) + '">' + sev.toUpperCase() + '</span>';
            return '<tr>' +
                '<td>' + (r.time || '-') + '</td>' +
                '<td>' + (r.topics || '-') + '</td>' +
                '<td>' + badge + ' ' + (r.message || '-') + '</td>' +
            '</tr>';
        }).join('');
    }

    function applySnapshot(snapshot) {
        snapshot = snapshot || {};
        updateSummary(snapshot.summary || {});
        updateScope(snapshot.router_scope || {}, snapshot.router_summary || {});
        updateRouter(snapshot.router || {});
        updateInterfaces(snapshot.interfaces || {});
        updatePpp(snapshot.ppp_active || {});
        updateBilling(snapshot.billing || {});
        updateNetwork(snapshot.network || {}, snapshot.thresholds || {}, snapshot.generated_at || '-');
        updateLogs(snapshot.system_logs || {});

        var nowLabel = new Date().toLocaleTimeString('id-ID');
        var totals = (snapshot.interfaces && snapshot.interfaces.totals) ? snapshot.interfaces.totals : {};
        var cpu = snapshot.router ? snapshot.router.cpu_load_percent : 0;
        var mem = snapshot.router ? snapshot.router.memory_usage_percent : 0;
        pushPoint(nowLabel, totals.rx_mbps || 0, totals.tx_mbps || 0, cpu || 0, mem || 0);

        if (trafficChart) {
            trafficChart.update();
        }
        if (resourceChart) {
            resourceChart.update();
        }
    }

    function pullSnapshot() {
        fetch(endpoint, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success) {
                    setFetchError('Response monitoring tidak valid.');
                    return;
                }
                applySnapshot(json.data || {});
            })
            .catch(function () {
                setFetchError('Gagal mengambil data monitoring.');
            });
    }

    function setFetchError(message) {
        var badge = document.getElementById('routerOnlineBadge');
        if (badge) {
            badge.className = 'badge text-bg-danger monitor-badge';
            badge.textContent = 'ERROR';
        }

        var text = String(message || 'Data monitoring tidak tersedia.');
        var ifRows = document.getElementById('interfaceRows');
        if (ifRows) {
            ifRows.innerHTML = '<tr><td colspan="6" class="text-center text-danger">' + text + '</td></tr>';
        }
        var pppRows = document.getElementById('pppRows');
        if (pppRows) {
            pppRows.innerHTML = '<tr><td colspan="4" class="text-center text-danger">' + text + '</td></tr>';
        }
        var netRows = document.getElementById('networkRows');
        if (netRows) {
            netRows.innerHTML = '<tr><td colspan="4" class="text-center text-danger">' + text + '</td></tr>';
        }
        var logRows = document.getElementById('logRows');
        if (logRows) {
            logRows.innerHTML = '<tr><td colspan="3" class="text-center text-danger">' + text + '</td></tr>';
        }
    }

    function bindManualCheck() {
        var btn = document.getElementById('btnManualHealthCheck');
        if (!btn) return;

        var statusEl = document.getElementById('manualCheckStatus');
        btn.addEventListener('click', function () {
            btn.disabled = true;
            statusEl.textContent = 'Menjalankan health check...';
            fetch(manualCheckEndpoint, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success) {
                    var msg = json.message || 'Health check selesai';
                    if (json.mode === 'all') {
                        msg = msg + ' (router: ' + Number(json.total_router || 0) + ')';
                    }
                    statusEl.textContent = msg + ' @ ' + (json.generated_at || new Date().toLocaleString('id-ID'));
                } else {
                    statusEl.textContent = (json && json.message) ? json.message : 'Health check gagal.';
                }
            })
            .catch(function () {
                statusEl.textContent = 'Koneksi server gagal saat health check.';
            })
            .finally(function () {
                btn.disabled = false;
            });
        });
    }

    function initInterfaceDropdowns() {
        if (typeof window.initSearchableSelect !== 'function') {
            return;
        }

        window.initSearchableSelect('.js-monitor-interface-select', {
            removeItemButton: true,
            closeDropdownOnSelect: false,
            placeholder: true,
            placeholderValue: 'Pilih interface monitoring',
            searchPlaceholderValue: 'Cari interface...',
            maxItemCount: -1
        });

        window.initSearchableSelect('.js-monitor-watchlist-select', {
            removeItemButton: true,
            closeDropdownOnSelect: false,
            placeholder: true,
            placeholderValue: 'Pilih interface watchlist down',
            searchPlaceholderValue: 'Cari interface watchlist...',
            maxItemCount: -1
        });
    }

    initInterfaceDropdowns();
    ensureCharts();
    applySnapshot(initialSnapshot);
    bindManualCheck();
    pullSnapshot();
    setInterval(pullSnapshot, refreshMs);
})();
</script>
SCRIPT;

include APPPATH . 'views/layout/master.php';
