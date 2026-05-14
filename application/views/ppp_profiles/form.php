<?php
$mode = isset($mode) ? $mode : 'create';
$is_edit = $mode === 'edit';
$profile = isset($profile) && is_array($profile) ? $profile : array();
$ip_pools = isset($ip_pools) && is_array($ip_pools) ? $ip_pools : array();

$page_title = $is_edit ? 'Edit PPP Profile - ' . app_name() : 'Tambah PPP Profile - ' . app_name();
$page_heading = $is_edit ? 'Edit PPP Profile' : 'Tambah PPP Profile';
$page_subheading = 'Konfigurasi profile PPP yang terhubung ke MikroTik.';
$active_menu = 'ppp_profiles';

$field_value = function ($key, $default = '') use ($profile) {
    return set_value($key, isset($profile[$key]) ? (string) $profile[$key] : $default);
};

$form_action = $is_edit
    ? 'ppp_profiles/update/' . (int) ($profile['id'] ?? 0)
    : 'ppp_profiles/store';
$price_form_value = set_value('price', (string) ((int) round((float) ($profile['price'] ?? 0))));

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold"><?php echo $is_edit ? 'Edit PPP Profile' : 'Tambah PPP Profile'; ?></div>
    <div class="card-body">
        <?php if (validation_errors()): ?>
        <div class="alert alert-danger"><?php echo validation_errors(); ?></div>
        <?php endif; ?>

        <?php echo form_open($form_action, array('class' => 'row g-3', 'method' => 'post')); ?>
        <div class="col-md-6">
            <label class="form-label">Name</label>
            <input type="text" class="form-control" name="name" value="<?php echo html_escape($field_value('name')); ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Rate Limit</label>
            <input type="text" class="form-control" name="rate_limit" placeholder="10M/10M" value="<?php echo html_escape($field_value('rate_limit')); ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Local Address</label>
            <input type="text" class="form-control" name="local_address" placeholder="192.168.10.1" value="<?php echo html_escape($field_value('local_address')); ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Remote Address Pool</label>
            <select class="form-select" name="remote_address_pool" required>
                <option value="">Pilih IP Pool</option>
                <?php foreach ($ip_pools as $pool): ?>
                <?php $selected = ((string) $field_value('remote_address_pool') === (string) ($pool['pool_name'] ?? '')) ? 'selected' : ''; ?>
                <option value="<?php echo html_escape((string) ($pool['pool_name'] ?? '')); ?>" <?php echo $selected; ?>>
                    <?php echo html_escape((string) ($pool['pool_name'] ?? '-')); ?> (<?php echo html_escape((string) ($pool['range_start'] ?? '-')); ?> - <?php echo html_escape((string) ($pool['range_end'] ?? '-')); ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Price</label>
            <input type="number" class="form-control" name="price" min="0" step="1" placeholder="235000" value="<?php echo html_escape($price_form_value); ?>" required>
        </div>
        <div class="col-12">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="3"><?php echo html_escape($field_value('description')); ?></textarea>
        </div>

        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?php echo $is_edit ? 'Update' : 'Simpan'; ?></button>
            <a href="<?php echo site_url('ppp-profiles'); ?>" class="btn btn-outline-secondary">Kembali</a>
        </div>
        <?php echo form_close(); ?>
    </div>
</div>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
