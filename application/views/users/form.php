<?php
$mode = isset($mode) ? $mode : 'create';
$is_edit = $mode === 'edit';
$user = isset($user) ? $user : null;
$action = isset($action) ? $action : 'users/store';
$allowed_roles = isset($allowed_roles) && is_array($allowed_roles) ? $allowed_roles : array('admin', 'teknisi');
$router_form = isset($router_form) && is_array($router_form) ? $router_form : array();
$router_scope_enabled = !empty($router_form['enabled']);
$router_options = isset($router_form['router_options']) && is_array($router_form['router_options']) ? $router_form['router_options'] : array();
$router_count = (int) ($router_form['router_count'] ?? count($router_options));
$single_router_auto_id = isset($router_form['single_router_auto_id']) ? (int) $router_form['single_router_auto_id'] : null;
$selected_router_scope_id = isset($router_form['selected_router_scope_id']) ? $router_form['selected_router_scope_id'] : null;
$actor_role = isset($router_form['actor_role']) ? (string) $router_form['actor_role'] : '';
$can_select_all_router = !empty($router_form['can_select_all']);

$page_title = ($is_edit ? 'Edit User' : 'Tambah User') . ' - ' . app_name();
$page_heading = $is_edit ? 'Edit User' : 'Tambah User';
$page_subheading = 'Form manajemen user dan role akses.';
$active_menu = 'users';
ob_start();
?>

<?php if ($this->session->flashdata('error')): ?>
    <div class="alert alert-danger"><?php echo $this->session->flashdata('error'); ?></div>
<?php endif; ?>

<div class="card stat-card">
    <div class="card-body">
        <?php echo form_open($action, array('class' => 'row g-3')); ?>
            <div class="col-md-6">
                <label class="form-label">Nama</label>
                <input
                    type="text"
                    class="form-control"
                    name="name"
                    value="<?php echo html_escape(set_value('name', $is_edit && $user ? $user->name : '')); ?>"
                    required
                >
            </div>

            <div class="col-md-6">
                <label class="form-label">Username</label>
                <input
                    type="text"
                    class="form-control"
                    name="username"
                    value="<?php echo html_escape(set_value('username', $is_edit && $user ? $user->username : '')); ?>"
                    required
                >
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Password
                    <?php if ($is_edit): ?>
                        <span class="text-muted small">(kosongkan jika tidak diubah)</span>
                    <?php endif; ?>
                </label>
                <input type="password" class="form-control" name="password" <?php echo $is_edit ? '' : 'required'; ?>>
            </div>

            <div class="col-md-3">
                <label class="form-label">Role</label>
                <?php $selected_role = set_value('role', $is_edit && $user ? $user->role : 'teknisi'); ?>
                <select name="role" class="form-select" required>
                    <?php foreach ($allowed_roles as $role_option): ?>
                        <option value="<?php echo html_escape($role_option); ?>" <?php echo $selected_role === $role_option ? 'selected' : ''; ?>>
                            <?php echo html_escape($role_option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Status</label>
                <?php $selected_status = set_value('status', $is_edit && $user ? $user->status : 'active'); ?>
                <select name="status" class="form-select" required>
                    <option value="active" <?php echo $selected_status === 'active' ? 'selected' : ''; ?>>active</option>
                    <option value="inactive" <?php echo $selected_status === 'inactive' ? 'selected' : ''; ?>>inactive</option>
                </select>
            </div>

            <?php if ($router_scope_enabled): ?>
                <div class="col-md-6" id="routerScopeContainer">
                    <label class="form-label">Router Scope</label>

                    <?php if ($router_count <= 0): ?>
                        <div class="alert alert-warning mb-0">
                            Tidak ada router aktif. Tambahkan router aktif terlebih dahulu.
                        </div>
                    <?php elseif ($router_count === 1): ?>
                        <?php
                        $single_router_name = '';
                        foreach ($router_options as $opt) {
                            if ((int) ($opt['id'] ?? 0) === $single_router_auto_id) {
                                $single_router_name = (string) ($opt['name'] ?? '');
                                break;
                            }
                        }
                        ?>
                        <input type="text" class="form-control" value="<?php echo html_escape($single_router_name); ?>" readonly>
                        <input
                            type="hidden"
                            name="router_scope_id"
                            id="routerScopeHidden"
                            value="<?php echo html_escape((string) ($selected_router_scope_id ?: $single_router_auto_id)); ?>"
                            data-auto-id="<?php echo (int) $single_router_auto_id; ?>"
                        >
                        <small class="text-muted" id="routerScopeSingleHint">
                            Router otomatis dipilih karena hanya ada 1 router aktif.
                        </small>
                    <?php else: ?>
                        <?php $selected_router_scope = (string) set_value('router_scope_id', $selected_router_scope_id); ?>
                        <select name="router_scope_id" id="routerScopeSelect" class="form-select">
                            <?php if ($can_select_all_router): ?>
                                <option value="">Semua Router (hanya untuk role superadmin)</option>
                            <?php endif; ?>
                            <?php foreach ($router_options as $opt): ?>
                                <?php
                                $opt_id = (int) ($opt['id'] ?? 0);
                                $opt_name = (string) ($opt['name'] ?? ('Router #' . $opt_id));
                                ?>
                                <option value="<?php echo $opt_id; ?>" <?php echo $selected_router_scope === (string) $opt_id ? 'selected' : ''; ?>>
                                    <?php echo html_escape($opt_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            Pilih router untuk membatasi akses user sesuai distribusi.
                        </small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?php echo $is_edit ? 'Update' : 'Simpan'; ?></button>
                <a href="<?php echo site_url('users'); ?>" class="btn btn-outline-secondary">Kembali</a>
            </div>
        <?php echo form_close(); ?>
    </div>
</div>

<?php
$content = ob_get_clean();

$page_scripts = '';
if ($router_scope_enabled && $can_select_all_router && $router_count === 1) {
    $page_scripts = '<script>
    (function () {
        var roleSelect = document.querySelector(\'select[name="role"]\');
        var hiddenScope = document.getElementById(\'routerScopeHidden\');
        var hint = document.getElementById(\'routerScopeSingleHint\');
        if (!roleSelect || !hiddenScope) {
            return;
        }

        var applyScopeByRole = function () {
            var role = (roleSelect.value || \'\').toLowerCase();
            var autoId = hiddenScope.getAttribute(\'data-auto-id\') || \'\';
            if (role === \'superadmin\') {
                hiddenScope.value = \'\';
                if (hint) {
                    hint.textContent = \'Role superadmin otomatis akses semua router (router scope kosong).\';
                }
            } else {
                hiddenScope.value = autoId;
                if (hint) {
                    hint.textContent = \'Router otomatis dipilih karena hanya ada 1 router aktif.\';
                }
            }
        };

        roleSelect.addEventListener(\'change\', applyScopeByRole);
        applyScopeByRole();
    })();
    </script>';
}

include APPPATH . 'views/layout/master.php';
