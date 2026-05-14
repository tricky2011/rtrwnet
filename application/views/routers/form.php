<?php
$mode = isset($mode) ? (string) $mode : 'create';
$row = isset($row) && is_array($row) ? $row : array();

$is_edit = $mode === 'edit';
$title = $is_edit ? 'Edit Router' : 'Tambah Router';

$page_title = 'Settings Router - ' . app_name();
$page_heading = 'Settings: Router';
$page_subheading = $is_edit ? 'Edit konfigurasi koneksi router MikroTik.' : 'Tambah konfigurasi koneksi router MikroTik.';
$active_menu = 'routers';

$id = (int) ($row['id'] ?? 0);
$form_action = $is_edit ? 'routers/update/' . $id : 'routers/store';
ob_start();
?>

<?php
$setting_menu = 'router';
include APPPATH . 'views/settings/_menu.php';
?>

<?php if ($this->session->flashdata('error')): ?>
    <div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>

<?php if (validation_errors()): ?>
    <div class="alert alert-danger">
        <?php echo validation_errors(); ?>
    </div>
<?php endif; ?>

<?php
$brand_logo_value = (string) set_value('brand_logo', $row['brand_logo'] ?? '');
$brand_logo_preview = trim($brand_logo_value);
if ($brand_logo_preview !== '' && !preg_match('~^https?://~i', $brand_logo_preview)) {
    $brand_logo_preview = base_url(ltrim($brand_logo_preview, '/'));
}
?>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold"><?php echo $is_edit ? 'Edit Data Router' : 'Input Router Baru'; ?></div>
    <div class="card-body">
        <?php echo form_open_multipart($form_action, array('autocomplete' => 'off', 'class' => 'row g-3')); ?>
            <div class="col-12">
                <ul class="nav nav-tabs" id="routerFormTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="router-connection-tab" data-bs-toggle="tab" data-bs-target="#router-connection" type="button" role="tab" aria-controls="router-connection" aria-selected="true">Koneksi Router</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="router-branding-tab" data-bs-toggle="tab" data-bs-target="#router-branding" type="button" role="tab" aria-controls="router-branding" aria-selected="false">Branding Invoice</button>
                    </li>
                </ul>
                <div class="tab-content border border-top-0 rounded-bottom p-3">
                    <div class="tab-pane fade show active" id="router-connection" role="tabpanel" aria-labelledby="router-connection-tab">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nama Router</label>
                                <input type="text" class="form-control" name="name" required maxlength="120"
                                       value="<?php echo html_escape((string) set_value('name', $row['name'] ?? '')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Host / IP</label>
                                <input type="text" class="form-control" name="ip_address" required maxlength="120"
                                       placeholder="contoh: 172.16.0.1"
                                       value="<?php echo html_escape((string) set_value('ip_address', $row['ip_address'] ?? '')); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">API Port</label>
                                <input type="number" class="form-control" name="api_port" required min="1"
                                       value="<?php echo html_escape((string) set_value('api_port', $row['api_port'] ?? 8728)); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Timeout (detik)</label>
                                <input type="number" class="form-control" name="timeout_seconds" required min="1"
                                       value="<?php echo html_escape((string) set_value('timeout_seconds', $row['timeout_seconds'] ?? 5)); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Use SSL</label>
                                <?php $ssl_value = (int) set_value('use_ssl', $row['use_ssl'] ?? 0); ?>
                                <select class="form-select" name="use_ssl">
                                    <option value="0" <?php echo $ssl_value === 0 ? 'selected' : ''; ?>>No</option>
                                    <option value="1" <?php echo $ssl_value === 1 ? 'selected' : ''; ?>>Yes</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <?php $active_value = (int) set_value('is_active', $row['is_active'] ?? 1); ?>
                                <select class="form-select" name="is_active">
                                    <option value="1" <?php echo $active_value === 1 ? 'selected' : ''; ?>>Active</option>
                                    <option value="0" <?php echo $active_value === 0 ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Username API</label>
                                <input type="text" class="form-control" name="username" required maxlength="100"
                                       value="<?php echo html_escape((string) set_value('username', $row['username'] ?? '')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password API <?php echo $is_edit ? '<span class="text-muted small">(kosongkan jika tidak diubah)</span>' : ''; ?></label>
                                <input type="password" class="form-control" name="password" <?php echo $is_edit ? '' : 'required'; ?>>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">ACS Inform URL</label>
                                <input type="text" class="form-control" name="acs_url" maxlength="255"
                                       placeholder="contoh: http://10.20.20.2:7547"
                                       value="<?php echo html_escape((string) set_value('acs_url', $row['acs_url'] ?? '')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">ACS NBI URL</label>
                                <input type="text" class="form-control" name="acs_nbi_url" maxlength="255"
                                       placeholder="contoh: http://10.20.20.2:7557"
                                       value="<?php echo html_escape((string) set_value('acs_nbi_url', $row['acs_nbi_url'] ?? '')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">ACS Username</label>
                                <input type="text" class="form-control" name="acs_username" maxlength="100"
                                       value="<?php echo html_escape((string) set_value('acs_username', $row['acs_username'] ?? '')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">ACS Password <span class="text-muted small">(kosongkan jika tidak diubah)</span></label>
                                <input type="password" class="form-control" name="acs_password" maxlength="100">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Deskripsi</label>
                                <textarea class="form-control" name="description" rows="3"><?php echo html_escape((string) set_value('description', $row['description'] ?? '')); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="router-branding" role="tabpanel" aria-labelledby="router-branding-tab">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nama Brand</label>
                                <input type="text" class="form-control" name="brand_name" maxlength="150"
                                       value="<?php echo html_escape((string) set_value('brand_name', $row['brand_name'] ?? '')); ?>"
                                       placeholder="Contoh: RTRWNet Tembalang">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Website Brand</label>
                                <input type="text" class="form-control" name="brand_website" maxlength="150"
                                       value="<?php echo html_escape((string) set_value('brand_website', $row['brand_website'] ?? '')); ?>"
                                       placeholder="https://...">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Upload Logo (jpg/png/webp)</label>
                                <input type="file" class="form-control" name="brand_logo_file" accept=".jpg,.jpeg,.png,.webp">
                                <?php if ($brand_logo_preview !== ''): ?>
                                    <div class="mt-2">
                                        <img src="<?php echo html_escape($brand_logo_preview); ?>" alt="Brand Logo" style="max-height:56px;">
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Path/URL Logo (opsional)</label>
                                <input type="text" class="form-control" name="brand_logo" maxlength="255"
                                       value="<?php echo html_escape($brand_logo_value); ?>"
                                       placeholder="uploads/router-branding/logo.png atau https://...">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">No HP Brand</label>
                                <input type="text" class="form-control" name="brand_phone" maxlength="50"
                                       value="<?php echo html_escape((string) set_value('brand_phone', $row['brand_phone'] ?? '')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Brand</label>
                                <input type="email" class="form-control" name="brand_email" maxlength="100"
                                       value="<?php echo html_escape((string) set_value('brand_email', $row['brand_email'] ?? '')); ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Alamat Brand</label>
                                <textarea class="form-control" name="brand_address" rows="2"><?php echo html_escape((string) set_value('brand_address', $row['brand_address'] ?? '')); ?></textarea>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Nama Bank</label>
                                <input type="text" class="form-control" name="brand_bank_name" maxlength="150"
                                       value="<?php echo html_escape((string) set_value('brand_bank_name', $row['brand_bank_name'] ?? '')); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">No. Rekening</label>
                                <input type="text" class="form-control" name="brand_bank_account" maxlength="100"
                                       value="<?php echo html_escape((string) set_value('brand_bank_account', $row['brand_bank_account'] ?? '')); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Pemilik Rekening</label>
                                <input type="text" class="form-control" name="brand_bank_holder" maxlength="150"
                                       value="<?php echo html_escape((string) set_value('brand_bank_holder', $row['brand_bank_holder'] ?? '')); ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Footer Invoice</label>
                                <textarea class="form-control" name="invoice_footer" rows="2"><?php echo html_escape((string) set_value('invoice_footer', $row['invoice_footer'] ?? '')); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?php echo $is_edit ? 'Update' : 'Simpan'; ?></button>
                <a href="<?php echo site_url('routers'); ?>" class="btn btn-outline-secondary">Batal</a>
            </div>
        <?php echo form_close(); ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
