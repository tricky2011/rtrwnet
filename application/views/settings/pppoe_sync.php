<?php
$page_title = 'Settings PPPoE Sync - ' . app_name();
$page_heading = 'PPPoE Sync';
$page_subheading = 'Sinkronisasi data PPP secret dari MikroTik ke tabel customers.';
$active_menu = 'pppoe_sync';
$data_form = isset($data_form) ? $data_form : array();
$sync_logs = isset($sync_logs) ? $sync_logs : array();
$router_options = isset($router_options) && is_array($router_options) ? $router_options : array();
$selected_router_id = isset($selected_router_id) ? (int) $selected_router_id : 0;
$is_superadmin_user = !empty($is_superadmin_user);
$show_debug_raw = defined('ENVIRONMENT') && ENVIRONMENT !== 'production';
ob_start();
?>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">PPPoE Sync Config</div>
            <div class="card-body">
                <?php if ($this->session->flashdata('success')): ?>
                <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('success')); ?></div>
                <?php endif; ?>
                <?php if ($this->session->flashdata('error')): ?>
                <div class="alert alert-danger"><?php echo $this->session->flashdata('error'); ?></div>
                <?php endif; ?>
                <?php if ($show_debug_raw && $this->session->flashdata('debug_raw')): ?>
                <div class="alert alert-info">
                    <div class="fw-semibold mb-1">Detail Teknis</div>
                    <pre class="mb-0 small"><?php echo html_escape($this->session->flashdata('debug_raw')); ?></pre>
                </div>
                <?php endif; ?>

                <?php echo form_open('pppoe-sync/save', array('class' => 'row g-3')); ?>
                    <div class="col-12">
                        <?php $auto_sync = (string) set_value('auto_sync', (string) ($data_form['auto_sync'] ?? 0)); ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="auto_sync" name="auto_sync" <?php echo $auto_sync === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="auto_sync">
                                Auto Sync (cron ready)
                            </label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Interval (minutes)</label>
                        <input type="number" class="form-control" min="5" name="interval_minutes" value="<?php echo html_escape(set_value('interval_minutes', $data_form['interval_minutes'] ?? 60)); ?>">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Simpan</button>
                    </div>
                <?php echo form_close(); ?>

                <hr>
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-md-8">
                        <label class="form-label">Router Sumber Sync</label>
                        <?php if ($is_superadmin_user): ?>
                            <select class="form-select" id="pppoe_sync_router_id" name="router_id">
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
                                <input type="hidden" id="pppoe_sync_router_id" value="<?php echo (int) ($router_options[0]['id'] ?? 0); ?>">
                            <?php else: ?>
                                <input type="text" class="form-control" value="Router scope belum diset" readonly>
                                <input type="hidden" id="pppoe_sync_router_id" value="0">
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">Sync akan membaca data /ppp/secret/print sesuai router terpilih.</div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small text-muted">Last Sync</div>
                        <strong><?php echo !empty($data_form['last_sync_at']) ? html_escape($data_form['last_sync_at']) : '-'; ?></strong>
                    </div>
                    <div class="d-flex gap-2">
                        <?php echo form_open('pppoe-sync/sync-now', array('id' => 'form_sync_pppoe_now')); ?>
                            <input type="hidden" name="router_id" id="sync_now_router_id" value="<?php echo $selected_router_id > 0 ? $selected_router_id : ''; ?>">
                            <button type="submit" class="btn btn-outline-secondary">Sync PPPoE</button>
                        <?php echo form_close(); ?>
                        <?php echo form_open('pppoe-sync/migrate-customers', array('id' => 'form_migrate_ppp_customers')); ?>
                            <input type="hidden" name="router_id" id="migrate_router_id" value="<?php echo $selected_router_id > 0 ? $selected_router_id : ''; ?>">
                            <button
                                type="submit"
                                class="btn btn-primary"
                                onclick="return confirm('Jalankan migrasi customer dari /ppp/secret sekarang?');"
                            >
                                Migrate Customers
                            </button>
                        <?php echo form_close(); ?>
                    </div>
                </div>
                <div class="small text-muted mt-2">Akses migrasi dibatasi untuk role superadmin.</div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Sync Logs</div>
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
                            <?php if (empty($sync_logs)): ?>
                            <tr><td class="ps-3 text-muted" colspan="4">Belum ada log sync.</td></tr>
                            <?php else: ?>
                                <?php foreach ($sync_logs as $row): ?>
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

<?php
$content = ob_get_clean();
$page_scripts = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var routerSelect = document.getElementById('pppoe_sync_router_id');
    var syncInput = document.getElementById('sync_now_router_id');
    var migrateInput = document.getElementById('migrate_router_id');
    if (!routerSelect) {
        return;
    }
    var applyRouter = function () {
        var value = routerSelect.value || '';
        if (syncInput) syncInput.value = value;
        if (migrateInput) migrateInput.value = value;
    };
    routerSelect.addEventListener('change', applyRouter);
    applyRouter();
});
</script>
HTML;
include APPPATH . 'views/layout/master.php';
