<?php
$page_title = isset($page_title) ? $page_title : 'System Monitoring';
$page_heading = isset($page_heading) ? $page_heading : 'System Monitoring';
$page_subheading = isset($page_subheading) ? $page_subheading : 'Monitoring status sistem';
$active_menu = isset($active_menu) ? $active_menu : 'system_monitoring';

$m = isset($monitoring) ? $monitoring : [];
$cron = isset($m['cron']) ? $m['cron'] : ['source' => '-', 'rows' => []];
$latestCron = isset($m['latest_cron']) ? $m['latest_cron'] : null;
$mikrotik = isset($m['mikrotik']) ? $m['mikrotik'] : ['label' => 'Unknown', 'status' => 'unknown', 'message' => '-'];
$telegram = isset($m['telegram']) ? $m['telegram'] : ['label' => 'Unknown', 'status' => 'unknown', 'message' => '-'];
$errors = isset($m['errors']) ? $m['errors'] : ['source' => '-', 'rows' => []];
$metrics = isset($m['metrics']) ? $m['metrics'] : [];
$chart = isset($m['chart']) ? $m['chart'] : ['labels' => [], 'durations' => []];

ob_start();
?>
<div class="row g-3 mb-3">
    <div class="col-md-6 col-xl-3 reveal">
        <div class="card card-ui kpi-card">
            <div class="card-body">
                <div class="kpi-label">Last Cron Run</div>
                <div class="kpi-value" style="font-size:1.05rem;">
                    <?php echo $latestCron ? html_escape($latestCron['job_name']) : '-'; ?>
                </div>
                <small class="text-muted">
                    <?php echo $latestCron ? html_escape((string) ($latestCron['started_at'] ?: '-')) : 'Belum ada data'; ?>
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3 reveal delay-1">
        <div class="card card-ui kpi-card">
            <div class="card-body">
                <div class="kpi-label">MikroTik API</div>
                <div class="kpi-value"><?php echo html_escape($mikrotik['label']); ?></div>
                <small class="<?php echo $mikrotik['status'] === 'online' ? 'text-success' : 'text-danger'; ?>">
                    <?php echo html_escape($mikrotik['message']); ?>
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3 reveal delay-2">
        <div class="card card-ui kpi-card">
            <div class="card-body">
                <div class="kpi-label">Telegram Bot</div>
                <div class="kpi-value"><?php echo html_escape($telegram['label']); ?></div>
                <small class="<?php echo $telegram['status'] === 'online' ? 'text-success' : 'text-danger'; ?>">
                    <?php echo html_escape($telegram['message']); ?>
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3 reveal delay-3">
        <div class="card card-ui kpi-card">
            <div class="card-body">
                <div class="kpi-label">Last Errors</div>
                <div class="kpi-value"><?php echo count($errors['rows']); ?></div>
                <small class="text-danger"><?php echo html_escape($errors['source']); ?></small>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8 reveal">
        <div class="card card-ui">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Cron Duration Trend (ms)</span>
                <span class="badge rounded-pill badge-soft"><?php echo html_escape($cron['source']); ?></span>
            </div>
            <div class="card-body chart-wrap">
                <canvas id="cronDurationChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4 reveal delay-1">
        <div class="card card-ui">
            <div class="card-header">Quick Metrics</div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span>Open WO</span><strong><?php echo (int) ($metrics['open_wo'] ?? 0); ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span>Overdue Invoice</span><strong><?php echo (int) ($metrics['overdue_invoices'] ?? 0); ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span>Antrian Telegram</span><strong><?php echo (int) ($metrics['pending_telegram_queue'] ?? 0); ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span>Telegram Gagal</span><strong><?php echo (int) ($metrics['failed_telegram_queue'] ?? 0); ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span>Generated At</span><strong><?php echo html_escape($m['generated_at'] ?? '-'); ?></strong>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-6 reveal">
        <div class="card card-ui">
            <div class="card-header">Last Cron Runs</div>
            <div class="card-body table-scroll p-0">
                <table class="table table-ui mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Proses</th>
                            <th>Status</th>
                            <th>Started</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($cron['rows'])): ?>
                            <?php foreach ($cron['rows'] as $row): ?>
                            <tr>
                                <td class="ps-3"><?php echo html_escape((string) ($row['job_name'] ?? '-')); ?></td>
                                <td>
                                    <?php $s = strtolower((string) ($row['status'] ?? 'info')); ?>
                                    <?php if ($s === 'success'): ?>
                                        <span class="badge text-bg-success">SUCCESS</span>
                                    <?php elseif ($s === 'error' || $s === 'failed'): ?>
                                        <span class="badge text-bg-danger">ERROR</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">INFO</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo html_escape((string) ($row['started_at'] ?? '-')); ?></td>
                                <td><?php echo isset($row['duration_ms']) && $row['duration_ms'] !== null ? (int) $row['duration_ms'] . ' ms' : '-'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td class="ps-3" colspan="4">Belum ada data cron.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6 reveal delay-1">
        <div class="card card-ui">
            <div class="card-header">Logs Last Errors</div>
            <div class="card-body table-scroll p-0">
                <table class="table table-ui mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Time</th>
                            <th>Module</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($errors['rows'])): ?>
                            <?php foreach ($errors['rows'] as $row): ?>
                            <tr>
                                <td class="ps-3"><?php echo html_escape((string) ($row['log_time'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['module'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['message'] ?? '-')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td class="ps-3" colspan="3">Tidak ada error terbaru.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

$labels = json_encode($chart['labels']);
$durations = json_encode($chart['durations']);
$page_scripts = <<<SCRIPT
<script>
(function () {
    const labels = {$labels} || [];
    const durations = {$durations} || [];
    const ctx = document.getElementById('cronDurationChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Duration (ms)',
                data: durations,
                backgroundColor: 'rgba(29, 78, 216, 0.42)',
                borderColor: '#1d4ed8',
                borderWidth: 1
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { maxRotation: 45, minRotation: 20 } },
                y: { beginAtZero: true }
            }
        }
    });
})();
</script>
SCRIPT;

include APPPATH . 'views/layouts/master.php';
