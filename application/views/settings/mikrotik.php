<?php
$page_title = 'Settings Mikrotik - ' . app_name();
$page_heading = 'Settings: Mikrotik';
$page_subheading = 'Konfigurasi koneksi RouterOS API.';
$active_menu = 'settings';
$data_form = isset($data_form) ? $data_form : array();
ob_start();
?>

<?php include APPPATH . 'views/settings/_menu.php'; ?>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">Mikrotik Connection</div>
    <div class="card-body">
        <?php if ($this->session->flashdata('success')): ?>
        <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('success')); ?></div>
        <?php endif; ?>
        <?php if ($this->session->flashdata('error')): ?>
        <div class="alert alert-danger"><?php echo $this->session->flashdata('error'); ?></div>
        <?php endif; ?>

        <?php echo form_open('settings/save_mikrotik', array('class' => 'row g-3')); ?>
            <div class="col-md-6">
                <label class="form-label">Host / IP</label>
                <input type="text" name="host" class="form-control" required value="<?php echo html_escape(set_value('host', $data_form['host'] ?? '')); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required value="<?php echo html_escape(set_value('username', $data_form['username'] ?? '')); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" value="<?php echo html_escape(set_value('password', '')); ?>">
                <small class="text-muted">Kosongkan jika tidak ingin mengubah password. Saat test koneksi, isi password terlebih dahulu.</small>
            </div>
            <div class="col-md-3">
                <label class="form-label">Port API</label>
                <input type="number" name="api_port" class="form-control" required value="<?php echo html_escape(set_value('api_port', $data_form['api_port'] ?? 8728)); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Use SSL</label>
                <?php $ssl = (string) set_value('use_ssl', (string) ($data_form['use_ssl'] ?? 0)); ?>
                <select name="use_ssl" class="form-select">
                    <option value="0" <?php echo $ssl === '0' ? 'selected' : ''; ?>>No</option>
                    <option value="1" <?php echo $ssl === '1' ? 'selected' : ''; ?>>Yes</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Simpan</button>
                <button type="submit" formaction="<?php echo site_url('settings/test_mikrotik'); ?>" class="btn btn-outline-secondary">Test Connection</button>
            </div>
        <?php echo form_close(); ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
