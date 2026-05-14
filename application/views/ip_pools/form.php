<?php
$mode = isset($mode) ? $mode : 'create';
$is_edit = $mode === 'edit';
$pool = isset($pool) && is_array($pool) ? $pool : array();

$page_title = $is_edit ? 'Edit IP Pool - ' . app_name() : 'Tambah IP Pool - ' . app_name();
$page_heading = $is_edit ? 'Edit IP Pool' : 'Tambah IP Pool';
$page_subheading = 'Konfigurasi IP pool dan sinkronisasi ke MikroTik.';
$active_menu = 'ip_pools';

$field_value = function ($key, $default = '') use ($pool) {
    return set_value($key, isset($pool[$key]) ? (string) $pool[$key] : $default);
};

$form_action = $is_edit
    ? 'ip-pools/update/' . (int) ($pool['id'] ?? 0)
    : 'ip-pools/store';

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold"><?php echo $is_edit ? 'Edit IP Pool' : 'Tambah IP Pool'; ?></div>
    <div class="card-body">
        <?php if (validation_errors()): ?>
        <div class="alert alert-danger"><?php echo validation_errors(); ?></div>
        <?php endif; ?>

        <?php echo form_open($form_action, array('class' => 'row g-3')); ?>
        <div class="col-md-6">
            <label class="form-label">Pool Name</label>
            <input type="text" class="form-control" name="pool_name" value="<?php echo html_escape($field_value('pool_name')); ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Range Start</label>
            <input type="text" class="form-control" name="range_start" placeholder="192.168.10.2" value="<?php echo html_escape($field_value('range_start')); ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Range End</label>
            <input type="text" class="form-control" name="range_end" placeholder="192.168.10.254" value="<?php echo html_escape($field_value('range_end')); ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Router Name (Opsional)</label>
            <input type="text" class="form-control" name="router_name" value="<?php echo html_escape($field_value('router_name', $field_value('router_id'))); ?>">
        </div>

        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?php echo $is_edit ? 'Update' : 'Simpan'; ?></button>
            <a href="<?php echo site_url('ip-pools'); ?>" class="btn btn-outline-secondary">Kembali</a>
        </div>
        <?php echo form_close(); ?>
    </div>
</div>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
