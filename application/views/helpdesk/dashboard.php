<?php
$page_title = 'Helpdesk Dashboard Bulanan - ' . app_name();
$page_heading = 'Helpdesk Dashboard Bulanan';
$page_subheading = 'Analitik tiket bulanan, SLA, performa teknisi, dan channel gangguan.';
$active_menu = 'helpdesk';

$report_mode = !empty($report_mode);
$report_rows = isset($report_rows) && is_array($report_rows) ? $report_rows : array();
$report_summary = isset($report_summary) && is_array($report_summary) ? $report_summary : array();
$report_filters = isset($report_filters) && is_array($report_filters) ? $report_filters : array();

$filter_month = isset($filter_month) ? (int) $filter_month : (int) date('m');
$filter_year = isset($filter_year) ? (int) $filter_year : (int) date('Y');
$months = isset($months) && is_array($months) ? $months : array();
$years = isset($years) && is_array($years) ? $years : array((int) date('Y'));

$summary = isset($summary) && is_array($summary) ? $summary : array(
    'total_ticket' => 0,
    'open' => 0,
    'in_progress' => 0,
    'resolved' => 0,
    'closed' => 0,
    'cancelled' => 0,
    'critical' => 0,
    'channel_counts' => array(
        'phone' => 0,
        'whatsapp' => 0,
        'telegram' => 0,
        'web' => 0,
        'other' => 0,
    ),
);

$avg_response_minutes = isset($avg_response_minutes) ? (float) $avg_response_minutes : 0;
$avg_resolve_minutes = isset($avg_resolve_minutes) ? (float) $avg_resolve_minutes : 0;
$resolution_rate = isset($resolution_rate) ? (float) $resolution_rate : 0;
$is_low_resolution_rate = !empty($is_low_resolution_rate);

$ticket_per_month = isset($ticket_per_month) && is_array($ticket_per_month) ? $ticket_per_month : array();
$ticket_by_status = isset($ticket_by_status) && is_array($ticket_by_status) ? $ticket_by_status : array();
$ticket_by_category = isset($ticket_by_category) && is_array($ticket_by_category) ? $ticket_by_category : array();
$ticket_by_channel = isset($ticket_by_channel) && is_array($ticket_by_channel) ? $ticket_by_channel : array();
$technician_performance = isset($technician_performance) && is_array($technician_performance) ? $technician_performance : array();
$top_customers = isset($top_customers) && is_array($top_customers) ? $top_customers : array();
$top_technician = isset($top_technician) && is_array($top_technician) ? $top_technician : array();

$chart_data_json = isset($chart_data_json) ? (string) $chart_data_json : '{}';

if (!function_exists('helpdesk_minutes_to_human')) {
    function helpdesk_minutes_to_human($minutes)
    {
        $minutes = (float) $minutes;
        if ($minutes <= 0) {
            return '-';
        }
        if ($minutes < 60) {
            return number_format($minutes, 1, ',', '.') . ' menit';
        }
        $hours = floor($minutes / 60);
        $rem = $minutes - ($hours * 60);
        if ($rem <= 0.01) {
            return number_format($hours, 0, ',', '.') . ' jam';
        }
        return number_format($hours, 0, ',', '.') . ' jam ' . number_format($rem, 0, ',', '.') . ' menit';
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

<?php if ($report_mode): ?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Laporan Helpdesk</span>
        <a href="<?php echo site_url('helpdesk-report/export-pdf') . '?' . http_build_query($report_filters); ?>" target="_blank" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
        </a>
    </div>
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-md-2"><div class="small text-muted">Total</div><div class="fw-semibold"><?php echo number_format((int) ($report_summary['total'] ?? 0), 0, ',', '.'); ?></div></div>
            <div class="col-md-2"><div class="small text-muted">Open</div><div class="fw-semibold"><?php echo number_format((int) ($report_summary['open'] ?? 0), 0, ',', '.'); ?></div></div>
            <div class="col-md-2"><div class="small text-muted">Assigned</div><div class="fw-semibold"><?php echo number_format((int) ($report_summary['assigned'] ?? 0), 0, ',', '.'); ?></div></div>
            <div class="col-md-2"><div class="small text-muted">Progress</div><div class="fw-semibold"><?php echo number_format((int) ($report_summary['progress'] ?? 0), 0, ',', '.'); ?></div></div>
            <div class="col-md-2"><div class="small text-muted">Resolved</div><div class="fw-semibold"><?php echo number_format((int) ($report_summary['resolved'] ?? 0), 0, ',', '.'); ?></div></div>
            <div class="col-md-2"><div class="small text-muted">Closed</div><div class="fw-semibold"><?php echo number_format((int) ($report_summary['closed'] ?? 0), 0, ',', '.'); ?></div></div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Ticket</th>
                        <th>Subject</th>
                        <th>Customer</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>SLA Deadline</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report_rows)): ?>
                    <tr><td colspan="6" class="text-muted">Tidak ada data report.</td></tr>
                    <?php else: ?>
                        <?php foreach ($report_rows as $row): ?>
                        <tr>
                            <td><?php echo html_escape((string) ($row['ticket_code'] ?? $row['ticket_number'] ?? '-')); ?></td>
                            <td><?php echo html_escape((string) ($row['subject'] ?? '-')); ?></td>
                            <td><?php echo html_escape((string) ($row['customer_name'] ?? '-')); ?></td>
                            <td><?php echo html_escape(strtoupper((string) ($row['priority'] ?? '-'))); ?></td>
                            <td><?php echo html_escape(strtoupper((string) ($row['status'] ?? '-'))); ?></td>
                            <td><?php echo !empty($row['sla_deadline']) ? html_escape(date('d-m-Y H:i', strtotime((string) $row['sla_deadline']))) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>

<div class="card stat-card mb-3">
    <div class="card-body">
        <?php echo form_open('helpdesk-dashboard', array('method' => 'get', 'class' => 'row g-2 align-items-end')); ?>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-1">Bulan</label>
                <select name="month" class="form-select form-select-sm">
                    <?php foreach ($months as $m => $label): ?>
                    <option value="<?php echo (int) $m; ?>" <?php echo ((int) $m === $filter_month) ? 'selected' : ''; ?>>
                        <?php echo html_escape((string) $label); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Tahun</label>
                <select name="year" class="form-select form-select-sm">
                    <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int) $y; ?>" <?php echo ((int) $y === $filter_year) ? 'selected' : ''; ?>>
                        <?php echo (int) $y; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-sm btn-primary">Terapkan Filter</button>
            </div>
            <div class="col-md-5 d-flex justify-content-md-end gap-2">
                <a href="<?php echo site_url('helpdesk-dashboard/export-pdf?month=' . $filter_month . '&year=' . $filter_year); ?>" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                </a>
                <a href="<?php echo site_url('helpdesk-dashboard/export-excel?month=' . $filter_month . '&year=' . $filter_year); ?>" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export Excel
                </a>
                <a href="<?php echo site_url('helpdesk'); ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-list-ul me-1"></i>Ticket List
                </a>
            </div>
        <?php echo form_close(); ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-muted">Total Ticket</div>
                <div class="stat-value"><?php echo number_format((int) ($summary['total_ticket'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-muted">Open</div>
                <div class="stat-value text-secondary"><?php echo number_format((int) ($summary['open'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-muted">In Progress</div>
                <div class="stat-value text-warning"><?php echo number_format((int) ($summary['in_progress'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-muted">Resolved</div>
                <div class="stat-value text-success"><?php echo number_format((int) ($summary['resolved'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-muted">Closed</div>
                <div class="stat-value"><?php echo number_format((int) ($summary['closed'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-muted">Cancelled</div>
                <div class="stat-value text-dark"><?php echo number_format((int) ($summary['cancelled'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card stat-card h-100 border-danger">
            <div class="card-body">
                <div class="small text-muted">Critical Ticket</div>
                <div class="stat-value text-danger">
                    <?php echo number_format((int) ($summary['critical'] ?? 0), 0, ',', '.'); ?>
                    <span class="badge text-bg-danger align-top ms-1">CRITICAL</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-muted">Ticket per Channel</div>
                <div class="small mt-2 d-grid gap-1">
                    <?php $channel_counts = isset($summary['channel_counts']) && is_array($summary['channel_counts']) ? $summary['channel_counts'] : array(); ?>
                    <div>Phone: <strong><?php echo number_format((int) ($channel_counts['phone'] ?? 0), 0, ',', '.'); ?></strong></div>
                    <div>WhatsApp: <strong><?php echo number_format((int) ($channel_counts['whatsapp'] ?? 0), 0, ',', '.'); ?></strong></div>
                    <div>Telegram: <strong><?php echo number_format((int) ($channel_counts['telegram'] ?? 0), 0, ',', '.'); ?></strong></div>
                    <div>Web: <strong><?php echo number_format((int) ($channel_counts['web'] ?? 0), 0, ',', '.'); ?></strong></div>
                    <div>Other: <strong><?php echo number_format((int) ($channel_counts['other'] ?? 0), 0, ',', '.'); ?></strong></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-muted">Average First Response Time</div>
                <div class="h4 mb-0"><?php echo html_escape(helpdesk_minutes_to_human($avg_response_minutes)); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="small text-muted">Average Resolve Time</div>
                <div class="h4 mb-0"><?php echo html_escape(helpdesk_minutes_to_human($avg_resolve_minutes)); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card h-100 <?php echo $is_low_resolution_rate ? 'border-danger' : 'border-success'; ?>">
            <div class="card-body">
                <div class="small text-muted">Resolution Rate</div>
                <div class="h4 mb-0 <?php echo $is_low_resolution_rate ? 'text-danger' : 'text-success'; ?>">
                    <?php echo number_format($resolution_rate, 2, ',', '.'); ?>%
                </div>
                <?php if ($is_low_resolution_rate): ?>
                <div class="small text-danger mt-1">Di bawah target 70%.</div>
                <?php else: ?>
                <div class="small text-success mt-1">Target SLA tercapai.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="card stat-card">
            <div class="card-header bg-white fw-semibold">Line Chart - Ticket per Bulan (<?php echo (int) $filter_year; ?>)</div>
            <div class="card-body">
                <canvas id="chartLineMonthly" height="90"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Pie Chart - Ticket by Status</div>
            <div class="card-body">
                <canvas id="chartPieStatus" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Bar Chart - Ticket by Category</div>
            <div class="card-body">
                <canvas id="chartBarCategory" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Bar Chart - Ticket by Channel</div>
            <div class="card-body">
                <canvas id="chartBarChannel" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Horizontal Bar - Performa Teknisi (Resolved)</div>
            <div class="card-body">
                <canvas id="chartBarTechnician" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Technician Performance</span>
                <?php if (!empty($top_technician)): ?>
                <span class="badge text-bg-success">
                    Best: <?php echo html_escape((string) ($top_technician['technician_name'] ?? '-')); ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Teknisi</th>
                            <th>Resolved Ticket</th>
                            <th>Avg Resolve Time</th>
                            <th>Ranking</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($technician_performance)): ?>
                        <tr><td colspan="4" class="text-muted">Belum ada data performa teknisi.</td></tr>
                        <?php else: ?>
                            <?php foreach ($technician_performance as $idx => $tech): ?>
                            <tr>
                                <td><?php echo html_escape((string) ($tech['technician_name'] ?? '-')); ?></td>
                                <td><?php echo number_format((int) ($tech['resolved_total'] ?? 0), 0, ',', '.'); ?></td>
                                <td><?php echo html_escape(helpdesk_minutes_to_human((float) ($tech['avg_resolve_minutes'] ?? 0))); ?></td>
                                <td>
                                    <?php if ($idx === 0): ?>
                                    <span class="badge text-bg-warning">#1</span>
                                    <?php else: ?>
                                    <span class="badge text-bg-light border">#<?php echo $idx + 1; ?></span>
                                    <?php endif; ?>
                                </td>
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
            <div class="card-header bg-white fw-semibold">Top 5 Customer Paling Sering Buat Ticket</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Customer</th>
                            <th>Total Ticket</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_customers)): ?>
                        <tr><td colspan="2" class="text-muted">Tidak ada data customer.</td></tr>
                        <?php else: ?>
                            <?php foreach ($top_customers as $customer): ?>
                            <tr>
                                <td><?php echo html_escape((string) ($customer['customer_name'] ?? '-')); ?></td>
                                <td><span class="badge text-bg-primary"><?php echo number_format((int) ($customer['total_ticket'] ?? 0), 0, ',', '.'); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') {
        return;
    }

    var chartData = <?php echo $chart_data_json !== '' ? $chart_data_json : '{}'; ?> || {};

    function drawChart(elId, config) {
        var el = document.getElementById(elId);
        if (!el) {
            return;
        }
        new Chart(el, config);
    }

    var line = chartData.line_ticket_per_month || {labels: [], values: []};
    drawChart('chartLineMonthly', {
        type: 'line',
        data: {
            labels: line.labels || [],
            datasets: [{
                label: 'Total Ticket',
                data: line.values || [],
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13,110,253,0.2)',
                tension: 0.35,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    var pie = chartData.pie_status || {labels: [], values: []};
    drawChart('chartPieStatus', {
        type: 'pie',
        data: {
            labels: pie.labels || [],
            datasets: [{
                data: pie.values || [],
                backgroundColor: ['#6c757d', '#ffc107', '#198754', '#212529', '#dc3545']
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    var category = chartData.bar_category || {labels: [], values: []};
    drawChart('chartBarCategory', {
        type: 'bar',
        data: {
            labels: category.labels || [],
            datasets: [{
                label: 'Ticket',
                data: category.values || [],
                backgroundColor: '#0dcaf0'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    var channel = chartData.bar_channel || {labels: [], values: []};
    drawChart('chartBarChannel', {
        type: 'bar',
        data: {
            labels: channel.labels || [],
            datasets: [{
                label: 'Ticket',
                data: channel.values || [],
                backgroundColor: '#20c997'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    var tech = chartData.bar_technician || {labels: [], values: []};
    drawChart('chartBarTechnician', {
        type: 'bar',
        data: {
            labels: tech.labels || [],
            datasets: [{
                label: 'Resolved',
                data: tech.values || [],
                backgroundColor: '#fd7e14'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
});
</script>
<?php
$page_scripts = ob_get_clean();

include APPPATH . 'views/layout/master.php';
