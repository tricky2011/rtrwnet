<?php
$page_title = 'Static IP Sync - ' . app_name();
$page_heading = 'Static IP Sync';
$page_subheading = 'Sinkronisasi customer STATIC dari MikroTik dan kontrol auto isolir.';
$active_menu = 'static_ip_sync';

$recent_runs = isset($recent_runs) && is_array($recent_runs) ? $recent_runs : array();
$last_result = isset($last_result) && is_array($last_result) ? $last_result : array();

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
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h5 class="mb-1">Eksekusi Manual</h5>
                <div class="text-muted small">Tidak mengubah alur PPPoE existing. Fokus untuk customer STATIC.</div>
            </div>
            <div class="d-flex gap-2">
                <?php echo form_open('static-ip-sync/run-sync', array('class' => 'm-0')); ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-arrow-repeat me-1"></i>Sync Static IP
                    </button>
                <?php echo form_close(); ?>

                <?php echo form_open('static-ip-sync/run-check-isolir', array('class' => 'm-0')); ?>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-shield-lock me-1"></i>Check Static Isolir
                    </button>
                <?php echo form_close(); ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($last_result)): ?>
<div class="card stat-card mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Hasil Terakhir</strong>
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
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Belum ada riwayat run static IP.</td>
                    </tr>
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
include APPPATH . 'views/layout/master.php';
