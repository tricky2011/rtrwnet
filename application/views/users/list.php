<?php
$page_title = 'User Management - ' . app_name();
$page_heading = 'User Management';
$page_subheading = 'Kelola akun dan role akses sistem.';
$active_menu = 'users';
$users = isset($users) ? $users : array();
$is_superadmin = isset($is_superadmin) ? (bool) $is_superadmin : false;
ob_start();
?>

<?php if ($this->session->flashdata('success')): ?>
    <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
    <div class="alert alert-danger"><?php echo $this->session->flashdata('error'); ?></div>
<?php endif; ?>
<?php if (!$is_superadmin): ?>
    <div class="alert alert-info">
        Mode admin: hapus user hanya soft delete (status menjadi <strong>inactive</strong>), hard delete hanya superadmin.
    </div>
<?php endif; ?>

<div class="card stat-card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Daftar User</h5>
            <a href="<?php echo site_url('users/create'); ?>" class="btn btn-primary btn-sm">Tambah User</a>
        </div>

        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Router Scope</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">Belum ada data user.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $row): ?>
                            <?php
                            $scope_id = isset($row->router_scope_id) ? (int) $row->router_scope_id : 0;
                            $scope_name = isset($row->router_scope_name) ? trim((string) $row->router_scope_name) : '';
                            $access_names = isset($row->router_access_names) ? trim((string) $row->router_access_names) : '';
                            $scope_label = 'Belum diatur';
                            $scope_badge = 'text-bg-warning';

                            if ($access_names !== '') {
                                $scope_label = $access_names;
                                $scope_badge = 'text-bg-primary';
                            } elseif ($scope_id > 0) {
                                $scope_label = $scope_name !== '' ? $scope_name : ('Router #' . $scope_id);
                                $scope_badge = 'text-bg-primary';
                            } elseif (strtolower((string) $row->role) === 'superadmin') {
                                $scope_label = 'Semua Router';
                                $scope_badge = 'text-bg-dark';
                            }
                            ?>
                            <tr>
                                <td><?php echo (int) $row->id; ?></td>
                                <td><?php echo html_escape((string) $row->name); ?></td>
                                <td><?php echo html_escape((string) $row->username); ?></td>
                                <td><span class="badge text-bg-info"><?php echo html_escape((string) $row->role); ?></span></td>
                                <td><span class="badge <?php echo $scope_badge; ?>"><?php echo html_escape($scope_label); ?></span></td>
                                <td>
                                    <?php if ((string) $row->status === 'active'): ?>
                                        <span class="badge text-bg-success">active</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?php echo site_url('users/edit/' . (int) $row->id); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <?php echo form_open('users/delete/' . (int) $row->id, array('class' => 'd-inline')); ?>
                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Hapus user ini?');"
                                        >
                                            Hapus
                                        </button>
                                    <?php echo form_close(); ?>
                                </td>
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
