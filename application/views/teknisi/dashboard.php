<?php
$page_title = 'Dashboard Teknisi - ' . app_name();
$page_heading = 'Dashboard Performance Teknisi';
$page_subheading = 'Work Order instalasi, ticket gangguan, KPI capaian, dan poin dasar penggajian.';
$active_menu = 'teknisi_dashboard';

$filters = isset($filters) && is_array($filters) ? $filters : array();
$selected_period_label = isset($selected_period_label) ? (string) $selected_period_label : date('F Y');
$months = isset($months) && is_array($months) ? $months : array();
$years = isset($years) && is_array($years) ? $years : array((int) date('Y'));
$filter_query = isset($filter_query) && is_array($filter_query) ? $filter_query : array();
$teknisi_options = isset($teknisi_options) && is_array($teknisi_options) ? $teknisi_options : array();
$can_filter_teknisi = !empty($can_filter_teknisi);
$can_export = !empty($can_export);

$kpi = isset($kpi) && is_array($kpi) ? $kpi : array();
$targets = isset($targets) && is_array($targets) ? $targets : array();
$work_order_rows = isset($work_order_rows) && is_array($work_order_rows) ? $work_order_rows : array();
$ticket_rows = isset($ticket_rows) && is_array($ticket_rows) ? $ticket_rows : array();
$ranking_rows = isset($ranking_rows) && is_array($ranking_rows) ? $ranking_rows : array();
$top_rank = isset($top_rank) && is_array($top_rank) ? $top_rank : array();
$selected_technician_name = isset($selected_technician_name) ? (string) $selected_technician_name : 'Semua Teknisi';
$points_rule = isset($points_rule) && is_array($points_rule) ? $points_rule : array(
    'wo_done' => 10,
    'ticket_done' => 5,
    'ticket_pending' => -2,
);
$chart_data_json = isset($chart_data_json) ? (string) $chart_data_json : '{}';

if (!function_exists('teknisi_badge_status_class')) {
    function teknisi_badge_status_class($status_key)
    {
        $status_key = strtolower(trim((string) $status_key));
        if (in_array($status_key, array('done', 'completed', 'resolved', 'closed', 'activated', 'selesai'), true)) {
            return 'text-bg-success';
        }
        if (in_array($status_key, array('pending', 'open', 'assigned', 'process', 'in_progress', 'progress'), true)) {
            return 'text-bg-warning';
        }
        if (in_array($status_key, array('cancel', 'cancelled'), true)) {
            return 'text-bg-dark';
        }
        return 'text-bg-secondary';
    }
}

if (!function_exists('teknisi_progress_class')) {
    function teknisi_progress_class($percent)
    {
        $percent = (float) $percent;
        if ($percent >= 100) {
            return 'bg-success';
        }
        if ($percent >= 70) {
            return 'bg-primary';
        }
        if ($percent >= 40) {
            return 'bg-warning';
        }
        return 'bg-danger';
    }
}

ob_start();
?>
<?php if ($this->session->flashdata('success')): ?>
<div class="alert alert-success"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
<div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>

<div class="card stat-card mb-3">
    <div class="card-body">
        <?php echo form_open('teknisi-dashboard', array('method' => 'get', 'class' => 'row g-2 align-items-end', 'id' => 'teknisiDashboardFilterForm')); ?>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Bulan</label>
                <select name="month" class="form-select form-select-sm">
                    <?php foreach ($months as $m => $label): ?>
                    <option value="<?php echo (int) $m; ?>" <?php echo (int) ($filters['month'] ?? 0) === (int) $m ? 'selected' : ''; ?>>
                        <?php echo html_escape((string) $label); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Tahun</label>
                <select name="year" class="form-select form-select-sm">
                    <?php foreach ($years as $year): ?>
                    <option value="<?php echo (int) $year; ?>" <?php echo (int) ($filters['year'] ?? 0) === (int) $year ? 'selected' : ''; ?>>
                        <?php echo (int) $year; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($can_filter_teknisi): ?>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Filter Teknisi</label>
                <select name="technician_id" class="form-select form-select-sm">
                    <option value="0">Semua Teknisi</option>
                    <?php foreach ($teknisi_options as $opt): ?>
                    <?php $opt_id = (int) ($opt['id'] ?? 0); ?>
                    <option value="<?php echo $opt_id; ?>" <?php echo (int) ($filters['technician_id'] ?? 0) === $opt_id ? 'selected' : ''; ?>>
                        <?php echo html_escape((string) ($opt['name'] ?? ('Teknisi #' . $opt_id))); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Target Installasi</label>
                <input type="number" min="1" name="target_installation" class="form-control form-control-sm" value="<?php echo (int) ($filters['target_installation'] ?? 30); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Target Ticket</label>
                <input type="number" min="1" name="target_ticket" class="form-control form-control-sm" value="<?php echo (int) ($filters['target_ticket'] ?? 50); ?>">
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-sm btn-primary">Terapkan</button>
            </div>
            <div class="col-md-2 d-grid">
                <a href="<?php echo site_url('teknisi-dashboard'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
            <?php if ($can_export): ?>
            <div class="col-md-2 d-grid">
                <a href="<?php echo site_url('teknisi-dashboard/export-pdf?' . http_build_query($filter_query)); ?>" target="_blank" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                </a>
            </div>
            <?php endif; ?>
            <div class="col-12 text-muted small">
                Periode laporan: <strong><?php echo html_escape($selected_period_label); ?></strong>
                <span class="mx-1">|</span>
                Teknisi aktif: <strong><?php echo html_escape($selected_technician_name); ?></strong>
            </div>
        <?php echo form_close(); ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl">
        <div class="card stat-card h-100 border-primary">
            <div class="card-body">
                <div class="small text-muted">Total WO Bulan Terpilih</div>
                <div class="stat-value text-primary"><?php echo number_format((int) ($kpi['total_wo'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl">
        <div class="card stat-card h-100 border-success">
            <div class="card-body">
                <div class="small text-muted">WO Selesai</div>
                <div class="stat-value text-success"><?php echo number_format((int) ($kpi['wo_done'] ?? 0), 0, ',', '.'); ?></div>
                <div class="small text-muted">
                    <?php echo number_format((int) ($kpi['wo_done'] ?? 0), 0, ',', '.'); ?>
                    dari
                    <?php echo number_format((int) ($kpi['total_wo'] ?? 0), 0, ',', '.'); ?>
                    (<?php echo number_format((float) ($kpi['wo_done_percent'] ?? 0), 2, ',', '.'); ?>%)
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl">
        <div class="card stat-card h-100 border-info">
            <div class="card-body">
                <div class="small text-muted">Ticket Gangguan Ditangani</div>
                <div class="stat-value text-info"><?php echo number_format((int) ($kpi['ticket_done'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl">
        <div class="card stat-card h-100 border-warning">
            <div class="card-body">
                <div class="small text-muted">Ticket Pending</div>
                <div class="stat-value text-warning"><?php echo number_format((int) ($kpi['ticket_pending'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl">
        <div class="card stat-card h-100 border-dark">
            <div class="card-body">
                <div class="small text-muted">Total Poin / Score Teknisi</div>
                <div class="stat-value" id="totalPointsValue"><?php echo number_format((int) ($kpi['total_points'] ?? 0), 0, ',', '.'); ?></div>
                <div class="small text-muted">
                    Rumus: WO selesai x <?php echo (int) ($points_rule['wo_done'] ?? 10); ?>,
                    Ticket selesai x <?php echo (int) ($points_rule['ticket_done'] ?? 5); ?>,
                    Ticket pending x <?php echo (int) ($points_rule['ticket_pending'] ?? -2); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Grafik Work Order Instalasi per Minggu</div>
            <div class="card-body">
                <canvas id="woWeeklyChart" height="120"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Grafik Ticket Gangguan Harian (<?php echo html_escape($selected_period_label); ?>)</div>
            <div class="card-body">
                <canvas id="ticketTrendChart" height="120"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Capaian Target Instalasi</div>
            <div class="card-body">
                <div class="d-flex justify-content-between small mb-1">
                    <span>Target Instalasi: <?php echo number_format((int) ($targets['target_installation'] ?? 0), 0, ',', '.'); ?> / bulan</span>
                    <span>Realisasi: <?php echo number_format((int) ($targets['real_installation'] ?? 0), 0, ',', '.'); ?></span>
                </div>
                <?php $installation_percent = (float) ($targets['installation_percent'] ?? 0); ?>
                <div class="progress" role="progressbar" aria-valuenow="<?php echo $installation_percent; ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar <?php echo teknisi_progress_class($installation_percent); ?>" style="width: <?php echo min(100, max(0, $installation_percent)); ?>%;">
                        <?php echo number_format($installation_percent, 2, ',', '.'); ?>%
                    </div>
                </div>
                <?php if ($installation_percent > 100): ?>
                <div class="small text-success mt-2">Capaian melebihi target (<?php echo number_format($installation_percent, 2, ',', '.'); ?>%).</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Capaian Target Ticket Handling</div>
            <div class="card-body">
                <div class="d-flex justify-content-between small mb-1">
                    <span>Target Ticket: <?php echo number_format((int) ($targets['target_ticket'] ?? 0), 0, ',', '.'); ?></span>
                    <span>Realisasi: <?php echo number_format((int) ($targets['real_ticket'] ?? 0), 0, ',', '.'); ?></span>
                </div>
                <?php $ticket_percent = (float) ($targets['ticket_percent'] ?? 0); ?>
                <div class="progress" role="progressbar" aria-valuenow="<?php echo $ticket_percent; ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar <?php echo teknisi_progress_class($ticket_percent); ?>" style="width: <?php echo min(100, max(0, $ticket_percent)); ?>%;">
                        <?php echo number_format($ticket_percent, 2, ',', '.'); ?>%
                    </div>
                </div>
                <?php if ($ticket_percent > 100): ?>
                <div class="small text-success mt-2">Capaian melebihi target (<?php echo number_format($ticket_percent, 2, ',', '.'); ?>%).</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Detail Work Order Instalasi</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tableWoDetail">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Nama Customer</th>
                            <th>Jenis Paket</th>
                            <th>Status</th>
                            <th>Waktu Pengerjaan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($work_order_rows)): ?>
                        <tr>
                            <td class="text-muted">Tidak ada data WO pada periode ini.</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($work_order_rows as $row): ?>
                            <tr>
                                <td><?php echo html_escape((string) ($row['work_date'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['customer_name'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['package_name'] ?? '-')); ?></td>
                                <td>
                                    <span class="badge <?php echo teknisi_badge_status_class((string) ($row['status_key'] ?? '')); ?>">
                                        <?php echo html_escape((string) ($row['status'] ?? '-')); ?>
                                    </span>
                                </td>
                                <td><?php echo html_escape((string) ($row['work_duration'] ?? '-')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Ranking Performa Teknisi</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="tableRanking">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Teknisi</th>
                            <th>WO</th>
                            <th>Ticket</th>
                            <th>Pending</th>
                            <th>Poin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ranking_rows)): ?>
                        <tr>
                            <td class="text-muted">Belum ada data ranking.</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($ranking_rows as $idx => $rank): ?>
                            <tr>
                                <td><?php echo (int) $idx + 1; ?></td>
                                <td>
                                    <?php echo html_escape((string) ($rank['technician_name'] ?? '-')); ?>
                                    <?php if ((int) $idx === 0): ?><span class="badge text-bg-success ms-1">Top</span><?php endif; ?>
                                </td>
                                <td><?php echo number_format((int) ($rank['wo_done'] ?? 0), 0, ',', '.'); ?></td>
                                <td><?php echo number_format((int) ($rank['ticket_done'] ?? 0), 0, ',', '.'); ?></td>
                                <td><?php echo number_format((int) ($rank['ticket_pending'] ?? 0), 0, ',', '.'); ?></td>
                                <td class="fw-semibold"><?php echo number_format((int) ($rank['total_points'] ?? 0), 0, ',', '.'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($top_rank)): ?>
            <div class="card-body border-top small">
                Teknisi terbaik periode ini:
                <strong><?php echo html_escape((string) ($top_rank['technician_name'] ?? '-')); ?></strong>
                (<?php echo number_format((int) ($top_rank['total_points'] ?? 0), 0, ',', '.'); ?> poin)
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">Detail Ticket Gangguan</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="tableTicketDetail">
            <thead class="table-light">
                <tr>
                    <th>No Ticket</th>
                    <th>Customer</th>
                    <th>Jenis Gangguan</th>
                    <th>SLA</th>
                    <th>Status</th>
                    <th>Durasi Penyelesaian</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ticket_rows)): ?>
                <tr>
                    <td class="text-muted">Tidak ada data ticket pada periode ini.</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
                <?php else: ?>
                    <?php foreach ($ticket_rows as $row): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo html_escape((string) ($row['ticket_number'] ?? '-')); ?></td>
                        <td><?php echo html_escape((string) ($row['customer_name'] ?? '-')); ?></td>
                        <td><?php echo html_escape((string) ($row['issue_type'] ?? '-')); ?></td>
                        <td><?php echo html_escape((string) ($row['sla_deadline'] ?? '-')); ?></td>
                        <td>
                            <span class="badge <?php echo teknisi_badge_status_class((string) ($row['status_key'] ?? '')); ?>">
                                <?php echo html_escape((string) ($row['status'] ?? '-')); ?>
                            </span>
                        </td>
                        <td><?php echo html_escape((string) ($row['duration'] ?? '-')); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
(function () {
    function calculatePoints(woDone, ticketDone, ticketPending) {
        var woPoint = <?php echo (int) ($points_rule['wo_done'] ?? 10); ?>;
        var ticketPoint = <?php echo (int) ($points_rule['ticket_done'] ?? 5); ?>;
        var pendingPoint = <?php echo (int) ($points_rule['ticket_pending'] ?? -2); ?>;
        return (woDone * woPoint) + (ticketDone * ticketPoint) + (ticketPending * pendingPoint);
    }

    var woDone = <?php echo (int) ($kpi['wo_done'] ?? 0); ?>;
    var ticketDone = <?php echo (int) ($kpi['ticket_done'] ?? 0); ?>;
    var ticketPending = <?php echo (int) ($kpi['ticket_pending'] ?? 0); ?>;
    var computedPoints = calculatePoints(woDone, ticketDone, ticketPending);
    var totalPointsNode = document.getElementById('totalPointsValue');
    if (totalPointsNode && !isNaN(computedPoints)) {
        totalPointsNode.setAttribute('data-computed-points', String(computedPoints));
    }

    var chartData = <?php echo $chart_data_json !== '' ? $chart_data_json : '{}'; ?>;
    var workOrderData = chartData && chartData.work_order ? chartData.work_order : {labels: [], values: []};
    var ticketData = chartData && chartData.ticket ? chartData.ticket : {labels: [], incoming: [], resolved: [], pending: []};

    if (typeof Chart !== 'undefined') {
        var woCanvas = document.getElementById('woWeeklyChart');
        if (woCanvas) {
            new Chart(woCanvas, {
                type: 'bar',
                data: {
                    labels: workOrderData.labels || [],
                    datasets: [{
                        label: 'Instalasi Selesai',
                        data: workOrderData.values || [],
                        borderRadius: 8,
                        backgroundColor: 'rgba(13, 110, 253, 0.70)',
                        borderColor: 'rgba(13, 110, 253, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        }

        var ticketCanvas = document.getElementById('ticketTrendChart');
        if (ticketCanvas) {
            new Chart(ticketCanvas, {
                type: 'line',
                data: {
                    labels: ticketData.labels || [],
                    datasets: [
                        {
                            label: 'Ticket Masuk',
                            data: ticketData.incoming || [],
                            borderColor: '#0d6efd',
                            backgroundColor: 'rgba(13, 110, 253, 0.20)',
                            borderWidth: 2,
                            tension: 0.35,
                            fill: true
                        },
                        {
                            label: 'Ticket Selesai',
                            data: ticketData.resolved || [],
                            borderColor: '#198754',
                            backgroundColor: 'rgba(25, 135, 84, 0.15)',
                            borderWidth: 2,
                            tension: 0.35,
                            fill: true
                        },
                        {
                            label: 'Ticket Pending',
                            data: ticketData.pending || [],
                            borderColor: '#ffc107',
                            backgroundColor: 'rgba(255, 193, 7, 0.15)',
                            borderWidth: 2,
                            tension: 0.35,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        }
    }

    if (window.jQuery) {
        var $ = window.jQuery;
        $('#tableWoDetail').DataTable({
            paging: true,
            pageLength: 10,
            lengthChange: false,
            ordering: false,
            searching: true,
            info: false
        });
        $('#tableTicketDetail').DataTable({
            paging: true,
            pageLength: 10,
            lengthChange: false,
            ordering: false,
            searching: true,
            info: false
        });
        $('#tableRanking').DataTable({
            paging: false,
            ordering: false,
            searching: false,
            info: false
        });
    }
})();
</script>
<?php
$page_scripts = ob_get_clean();
include APPPATH . 'views/layout/master.php';
