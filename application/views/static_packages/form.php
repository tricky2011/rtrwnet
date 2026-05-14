<?php
$profile = isset($profile) && is_array($profile) ? $profile : array();
$name = trim((string) ($profile['name'] ?? '-'));
$normalized = strtoupper(preg_replace('/\s+/', '', $name));
$label_map = array(
    '10M' => '10 M (10 Mbps)',
    '20M' => '20 M (20 Mbps)',
    '30M' => '30 M (30 Mbps)',
    '50M' => '50 M (50 Mbps)',
    '7M' => '7 M (7 Mbps)',
    '5M' => '5 M (5 Mbps)',
);
$display_name = isset($label_map[$normalized]) ? $label_map[$normalized] : $name;

$page_title = 'Edit Harga Paket Static - ' . app_name();
$page_heading = 'Edit Harga Paket Static';
$page_subheading = 'Harga ini akan dipakai saat sync customer STATIC.';
$active_menu = 'static_packages';
$price_value = set_value('price', (string) ((int) round((float) ($profile['price'] ?? 0))));

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">Edit Harga Paket Static</div>
    <div class="card-body">
        <?php if (validation_errors()): ?>
        <div class="alert alert-danger"><?php echo validation_errors(); ?></div>
        <?php endif; ?>

        <?php echo form_open('static-packages/update/' . (int) ($profile['id'] ?? 0), array('method' => 'post', 'class' => 'row g-3')); ?>
        <div class="col-md-6">
            <label class="form-label">Nama Paket</label>
            <input type="text" class="form-control" value="<?php echo html_escape($display_name); ?>" readonly>
        </div>
        <div class="col-md-6">
            <label class="form-label">Rate Limit</label>
            <input type="text" class="form-control" value="<?php echo html_escape((string) ($profile['rate_limit'] ?? '-')); ?>" readonly>
        </div>
        <div class="col-md-6">
            <label class="form-label">Harga Paket</label>
            <input type="number" class="form-control" name="price" min="0" step="1" value="<?php echo html_escape($price_value); ?>" required>
        </div>

        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Simpan Harga</button>
            <a href="<?php echo site_url('static-packages'); ?>" class="btn btn-outline-secondary">Kembali</a>
        </div>
        <?php echo form_close(); ?>
    </div>
</div>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';

