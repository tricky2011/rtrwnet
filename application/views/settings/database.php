<?php
$page_title = 'Settings Database - ' . app_name();
$page_heading = 'Settings: Database';
$page_subheading = 'Dynamic database connection (tanpa overwrite config default).';
$active_menu = 'settings';
$data_form = isset($data_form) ? $data_form : array();
ob_start();
?>

<?php include APPPATH . 'views/settings/_menu.php'; ?>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">Database Connection (Custom)</div>
    <div class="card-body">
        <?php if ($this->session->flashdata('success')): ?>
        <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('success')); ?></div>
        <?php endif; ?>
        <?php if ($this->session->flashdata('error')): ?>
        <div class="alert alert-danger"><?php echo $this->session->flashdata('error'); ?></div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-8">
                <?php echo form_open('settings/save_database', array('class' => 'row g-3')); ?>
                    <div class="col-md-6">
                        <label class="form-label">Host</label>
                        <input type="text" name="db_host" class="form-control" required value="<?php echo html_escape(set_value('db_host', $data_form['db_host'] ?? '')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Database Name</label>
                        <input type="text" name="db_name" class="form-control" required value="<?php echo html_escape(set_value('db_name', $data_form['db_name'] ?? '')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Username</label>
                        <input type="text" name="db_username" class="form-control" required value="<?php echo html_escape(set_value('db_username', $data_form['db_username'] ?? '')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password</label>
                        <input type="password" name="db_password" class="form-control" value="<?php echo html_escape(set_value('db_password', '')); ?>">
                        <small class="text-muted">Kosongkan jika tidak ingin mengubah password.</small>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Simpan</button>
                        <button type="submit" formaction="<?php echo site_url('settings/test_database'); ?>" class="btn btn-outline-secondary">Test Database Connection</button>
                    </div>
                <?php echo form_close(); ?>
            </div>
            <div class="col-lg-4">
                <div class="border rounded p-3 bg-light h-100">
                    <h6 class="mb-2">Info Database</h6>
                    <ul class="small text-muted mb-0 ps-3">
                        <li>Config ini dipakai untuk koneksi dinamis, tidak overwrite `application/config/database.php`.</li>
                        <li>Gunakan user database dengan privilege minimum yang dibutuhkan.</li>
                        <li>Setelah update, lakukan test koneksi sebelum eksekusi fitur berat.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
