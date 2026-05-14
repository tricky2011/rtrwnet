<?php
$page_title = 'Settings Router - ' . app_name();
$page_heading = 'Settings: Router';
$page_subheading = 'Konfigurasi multi router MikroTik untuk provisioning, isolir, dan monitoring.';
$active_menu = 'routers';

$rows = isset($rows) && is_array($rows) ? $rows : array();
$search = isset($search) ? (string) $search : '';
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($rows);
$per_page = isset($per_page) ? (int) $per_page : 20;
$per_page_options = isset($per_page_options) && is_array($per_page_options) ? $per_page_options : array(20, 50, 100, 500);

$role = strtolower(trim((string) $this->session->userdata('role')));
$can_manage = in_array($role, array('superadmin', 'admin'), true);

$query_base = $this->input->get();
unset($query_base['page']);

ob_start();
?>

<?php
$setting_menu = 'router';
include APPPATH . 'views/settings/_menu.php';
?>

<?php if ($this->session->flashdata('success')): ?>
    <div class="alert alert-success"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
    <div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Router List</span>
        <?php if ($can_manage): ?>
            <a href="<?php echo site_url('routers/create'); ?>" class="btn btn-sm btn-primary">Tambah Router</a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="p-3 border-bottom">
            <?php echo form_open('routers', array('method' => 'get', 'class' => 'row g-2 align-items-center')); ?>
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control form-control-sm" value="<?php echo html_escape($search); ?>" placeholder="Cari nama router / host / username">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
                </div>
                <div class="col-auto">
                    <a href="<?php echo site_url('routers'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
                <div class="col text-muted small text-md-end">
                    Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> data
                </div>
            <?php echo form_close(); ?>
        </div>

        <div class="d-flex flex-wrap gap-2 px-3 py-2 border-bottom">
            <span class="small text-muted align-self-center">Page View:</span>
            <?php foreach ($per_page_options as $opt): ?>
                <?php
                $opt = (int) $opt;
                $query = $query_base;
                $query['per_page'] = $opt;
                $url = site_url('routers') . '?' . http_build_query($query);
                ?>
                <a href="<?php echo html_escape($url); ?>" class="btn btn-sm <?php echo $per_page === $opt ? 'btn-primary' : 'btn-outline-primary'; ?>">
                    <?php echo $opt; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Nama Router</th>
                        <th>Host / IP</th>
                        <th>Port</th>
                        <th>Username API</th>
                        <th>SSL</th>
                        <th>Status</th>
                        <th>Deskripsi</th>
                        <th class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">Belum ada data router.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?php echo html_escape((string) ($r['name'] ?? '-')); ?></td>
                            <td><code><?php echo html_escape((string) ($r['ip_address'] ?? '-')); ?></code></td>
                            <td><?php echo (int) ($r['api_port'] ?? 8728); ?></td>
                            <td><?php echo html_escape((string) ($r['username'] ?? '-')); ?></td>
                            <td>
                                <?php if (!empty($r['use_ssl'])): ?>
                                    <span class="badge text-bg-success">ON</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">OFF</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) ($r['is_active'] ?? 0) === 1): ?>
                                    <span class="badge text-bg-success">ACTIVE</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">INACTIVE</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo html_escape((string) (($r['description'] ?? '') !== '' ? $r['description'] : '-')); ?></td>
                            <td class="text-end pe-3">
                                <?php if ($can_manage): ?>
                                    <?php echo form_open('routers/test-connection/' . (int) ($r['id'] ?? 0), array('class' => 'd-inline')); ?>
                                    <button type="submit" class="btn btn-sm btn-outline-success">Test</button>
                                    <?php echo form_close(); ?>

                                    <a href="<?php echo site_url('routers/edit/' . (int) ($r['id'] ?? 0)); ?>" class="btn btn-sm btn-outline-primary">Edit</a>

                                    <?php echo form_open('routers/delete/' . (int) ($r['id'] ?? 0), array('class' => 'd-inline')); ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus router ini?');">Hapus</button>
                                    <?php echo form_close(); ?>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Read Only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pagination !== ''): ?>
            <div class="p-3 border-top d-flex justify-content-end">
                <?php echo $pagination; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
