<?php
$page_title = 'Router Sync - ' . app_name();
$page_heading = 'Router Sync';
$page_subheading = 'Sinkronisasi data PPPoE dan Static IP dari MikroTik dalam satu halaman.';
$active_menu = 'router_sync';

$pppoe_data_form = isset($pppoe_data_form) && is_array($pppoe_data_form) ? $pppoe_data_form : array();
$pppoe_sync_logs = isset($pppoe_sync_logs) && is_array($pppoe_sync_logs) ? $pppoe_sync_logs : array();
$router_options = isset($router_options) && is_array($router_options) ? $router_options : array();
$selected_router_id = isset($selected_router_id) ? (int) $selected_router_id : 0;
$is_superadmin_user = !empty($is_superadmin_user);
$recent_runs = isset($recent_runs) && is_array($recent_runs) ? $recent_runs : array();
$last_result = isset($last_result) && is_array($last_result) ? $last_result : array();
$show_debug_raw = defined('ENVIRONMENT') && ENVIRONMENT !== 'production';

ob_start();
?>

<?php if ($this->session->flashdata('success')): ?>
<div class="alert alert-success"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
<div class="alert alert-danger"><?php echo $this->session->flashdata('error'); ?></div>
<?php endif; ?>
<?php if ($show_debug_raw && $this->session->flashdata('debug_raw')): ?>
<div class="alert alert-info">
    <div class="fw-semibold mb-1">Detail Teknis</div>
    <pre class="mb-0 small"><?php echo html_escape((string) $this->session->flashdata('debug_raw')); ?></pre>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">PPPoE Sync</div>
            <div class="card-body">
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-md-8">
                        <label class="form-label">Router Sumber Sync</label>
                        <?php if ($is_superadmin_user): ?>
                            <select class="form-select" id="router_sync_router_id" name="router_id">
                                <option value="">- Pilih Router -</option>
                                <?php foreach ($router_options as $router): ?>
                                    <?php $rid = (int) ($router['id'] ?? 0); ?>
                                    <option value="<?php echo $rid; ?>" <?php echo $selected_router_id === $rid ? 'selected' : ''; ?>>
                                        <?php echo html_escape((string) ($router['name'] ?? ('Router #' . $rid))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <?php if (!empty($router_options)): ?>
                                <input type="text" class="form-control" value="<?php echo html_escape((string) ($router_options[0]['name'] ?? '-')); ?>" readonly>
                                <input type="hidden" id="router_sync_router_id" value="<?php echo (int) ($router_options[0]['id'] ?? 0); ?>">
                            <?php else: ?>
                                <input type="text" class="form-control" value="Router scope belum diset" readonly>
                                <input type="hidden" id="router_sync_router_id" value="0">
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">PPPoE sync hanya untuk superadmin.</div>
                    </div>
                </div>

                <?php if ($is_superadmin_user): ?>
                    <?php echo form_open('pppoe-sync/save', array('class' => 'row g-3 mb-3')); ?>
                        <div class="col-12">
                            <?php $auto_sync = (string) set_value('auto_sync', (string) ($pppoe_data_form['auto_sync'] ?? 0)); ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="auto_sync" name="auto_sync" <?php echo $auto_sync === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_sync">Auto Sync (cron ready)</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Interval (minutes)</label>
                            <input type="number" class="form-control" min="5" name="interval_minutes" value="<?php echo html_escape(set_value('interval_minutes', $pppoe_data_form['interval_minutes'] ?? 60)); ?>">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-outline-primary">Simpan Config</button>
                        </div>
                    <?php echo form_close(); ?>

                    <div class="d-flex flex-wrap gap-2">
                        <?php echo form_open('pppoe-sync/sync-now', array('id' => 'form_sync_pppoe_now')); ?>
                            <input type="hidden" name="router_id" id="sync_now_router_id" value="<?php echo $selected_router_id > 0 ? $selected_router_id : ''; ?>">
                            <button type="submit" class="btn btn-primary">Sync PPPoE</button>
                        <?php echo form_close(); ?>
                        <?php echo form_open('pppoe-sync/migrate-customers', array('id' => 'form_migrate_ppp_customers')); ?>
                            <input type="hidden" name="router_id" id="migrate_router_id" value="<?php echo $selected_router_id > 0 ? $selected_router_id : ''; ?>">
                            <button type="submit" class="btn btn-outline-secondary" onclick="return confirm('Jalankan migrasi customer dari /ppp/secret sekarang?');">Migrate Customers</button>
                        <?php echo form_close(); ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">Aksi PPPoE Sync dibatasi untuk superadmin.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">PPPoE Sync Logs</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Time</th>
                                <th>Status</th>
                                <th>Message</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pppoe_sync_logs)): ?>
                                <tr><td class="ps-3 text-muted" colspan="4">Belum ada log sync.</td></tr>
                            <?php else: ?>
                                <?php foreach ($pppoe_sync_logs as $row): ?>
                                    <?php $status = strtolower((string) ($row->status ?? 'info')); ?>
                                    <tr>
                                        <td class="ps-3"><?php echo html_escape((string) ($row->created_at ?? '-')); ?></td>
                                        <td>
                                            <?php if ($status === 'success'): ?>
                                                <span class="badge text-bg-success">SUCCESS</span>
                                            <?php elseif ($status === 'failed'): ?>
                                                <span class="badge text-bg-danger">FAILED</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-warning"><?php echo html_escape(strtoupper($status)); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo html_escape((string) ($row->message ?? '-')); ?></td>
                                        <td><?php echo (int) ($row->synced_count ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card stat-card mb-3">
    <div class="card-header bg-white fw-semibold">Static IP Sync</div>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h6 class="mb-1">Eksekusi Manual</h6>
                <div class="text-muted small">Sinkronisasi customer STATIC dari MikroTik dan pengecekan auto isolir.</div>
            </div>
            <div class="d-flex gap-2">
                <?php echo form_open('static-ip-sync/run-sync', array('class' => 'm-0')); ?>
                    <input type="hidden" name="router_id" id="static_sync_router_id" value="<?php echo $selected_router_id > 0 ? $selected_router_id : ''; ?>">
                    <button type="submit" class="btn btn-primary">Sync Static IP</button>
                <?php echo form_close(); ?>
                <?php echo form_open('static-ip-sync/run-check-isolir', array('class' => 'm-0')); ?>
                    <input type="hidden" name="router_id" id="static_isolir_router_id" value="<?php echo $selected_router_id > 0 ? $selected_router_id : ''; ?>">
                    <button type="submit" class="btn btn-warning">Check Static Isolir</button>
                <?php echo form_close(); ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($last_result)): ?>
<div class="card stat-card mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Hasil Static Sync Terakhir</strong>
        <span class="badge <?php echo !empty($last_result['success']) ? 'text-bg-success' : 'text-bg-danger'; ?>">
            <?php echo !empty($last_result['success']) ? 'SUCCESS' : 'FAILED'; ?>
        </span>
    </div>
    <div class="card-body">
        <div class="mb-2">
            <span class="text-muted">Action:</span>
            <strong><?php echo html_escape((string) ($last_result['action'] ?? '-')); ?></strong>
            <span class="ms-2 text-muted">Run at:</span>
            <strong><?php echo html_escape((string) ($last_result['run_at'] ?? '-')); ?></strong>
        </div>
        <div class="mb-2"><?php echo html_escape((string) ($last_result['message'] ?? '-')); ?></div>

        <?php $stats = isset($last_result['stats']) && is_array($last_result['stats']) ? $last_result['stats'] : array(); ?>
        <?php if (!empty($stats)): ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <?php foreach ($stats as $key => $value): ?>
                        <?php if (is_array($value)): continue; endif; ?>
                        <tr>
                            <th style="width: 240px;"><?php echo html_escape((string) $key); ?></th>
                            <td><?php echo html_escape((string) $value); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card stat-card">
    <div class="card-header bg-white">
        <strong>Riwayat Run (Static IP)</strong>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 70px;">#</th>
                        <th>Proses</th>
                        <th style="width: 120px;">Status</th>
                        <th style="width: 180px;">Started</th>
                        <th style="width: 180px;">Finished</th>
                        <th style="width: 120px;">Duration</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_runs)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Belum ada riwayat run static IP.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_runs as $i => $row): ?>
                            <?php
                                $status = strtolower((string) ($row['status'] ?? 'unknown'));
                                $badge = 'text-bg-secondary';
                                if ($status === 'success' || $status === 'ok') {
                                    $badge = 'text-bg-success';
                                } elseif ($status === 'error' || $status === 'failed') {
                                    $badge = 'text-bg-danger';
                                }
                                $duration_display = '-';
                                if (isset($row['duration_ms']) && $row['duration_ms'] !== null && $row['duration_ms'] !== '') {
                                    $duration_display = (string) $row['duration_ms'] . ' ms';
                                }
                            ?>
                            <tr>
                                <td><?php echo (int) $i + 1; ?></td>
                                <td><code><?php echo html_escape((string) ($row['job_name'] ?? '-')); ?></code></td>
                                <td><span class="badge <?php echo $badge; ?>"><?php echo html_escape((string) strtoupper($status)); ?></span></td>
                                <td><?php echo html_escape((string) ($row['started_at'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['finished_at'] ?? '-')); ?></td>
                                <td><?php echo html_escape($duration_display); ?></td>
                                <td><?php echo html_escape((string) ($row['message'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$page_scripts = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var routerSelect = document.getElementById('router_sync_router_id');
    var targets = [
        document.getElementById('sync_now_router_id'),
        document.getElementById('migrate_router_id'),
        document.getElementById('static_sync_router_id'),
        document.getElementById('static_isolir_router_id')
    ];

    var applyRouter = function () {
        if (!routerSelect) return;
        var value = routerSelect.value || '';
        targets.forEach(function (el) {
            if (el) el.value = value;
        });
    };

    if (routerSelect) {
        routerSelect.addEventListener('change', applyRouter);
        applyRouter();
    }
});
</script>
HTML;
include APPPATH . 'views/layout/master.php';
