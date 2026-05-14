<?php
$page_title = 'Settings Telegram - ' . app_name();
$page_heading = 'Settings: Telegram';
$page_subheading = 'Konfigurasi multi bot dan multi chat ID untuk notifikasi sistem.';
$active_menu = 'settings';
$data_form = isset($data_form) && is_array($data_form) ? $data_form : array();
$telegram_bots = isset($telegram_bots) && is_array($telegram_bots) ? $telegram_bots : array();
$telegram_groups = isset($telegram_groups) && is_array($telegram_groups) ? $telegram_groups : array();
$telegram_type_options = isset($telegram_type_options) && is_array($telegram_type_options)
    ? $telegram_type_options
    : array('teknisi' => 'Teknisi', 'admin' => 'Admin', 'owner' => 'Owner', 'alert' => 'Alert');
$router_options = isset($router_options) && is_array($router_options) ? $router_options : array();
$current_role = function_exists('normalizeRole')
    ? normalizeRole((string) $this->session->userdata('role'))
    : strtolower(trim((string) $this->session->userdata('role')));
$is_superadmin_user = isset($is_superadmin_user) ? (bool) $is_superadmin_user : ($current_role === 'superadmin');
$scoped_router_id = isset($scoped_router_id) ? (int) $scoped_router_id : (int) $this->session->userdata('router_scope_id');
$scoped_router_name = isset($scoped_router_name) ? trim((string) $scoped_router_name) : '';
$can_submit_group = $is_superadmin_user ? !empty($router_options) : ($scoped_router_id > 0);

ob_start();
?>
<?php include APPPATH . 'views/settings/_menu.php'; ?>

<?php if ($this->session->flashdata('success')): ?>
<div class="alert alert-success"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
<div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>
<?php if (empty($telegram_groups)): ?>
    <div class="alert alert-warning">
        Belum ada Telegram Group aktif. Tambahkan minimal 1 group/chat agar notifikasi tidak hilang.
</div>
<?php endif; ?>
<?php if (!$is_superadmin_user && $scoped_router_id <= 0): ?>
<div class="alert alert-danger">
    Router scope admin belum diatur. Hubungi superadmin agar `users.router_scope_id` diisi.
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12">
        <div class="card stat-card">
            <div class="card-header bg-white fw-semibold">Quick Test Multi-Chat</div>
            <div class="card-body">
                <?php echo form_open('settings/test_telegram_dispatch', array('class' => 'row g-2')); ?>
                    <div class="col-md-3">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" required>
                            <?php foreach ($telegram_type_options as $value => $label): ?>
                            <option value="<?php echo html_escape((string) $value); ?>"><?php echo html_escape((string) $label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Router</label>
                        <?php if ($is_superadmin_user): ?>
                        <select name="router_id" class="form-select">
                            <option value="0">Semua Router</option>
                            <?php foreach ($router_options as $router): ?>
                            <option value="<?php echo (int) ($router['id'] ?? 0); ?>">
                                <?php echo html_escape((string) ($router['name'] ?? ('Router #' . (int) ($router['id'] ?? 0)))); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="hidden" name="router_id" value="<?php echo (int) $scoped_router_id; ?>">
                        <input type="text" class="form-control" readonly value="<?php echo html_escape($scoped_router_name !== '' ? $scoped_router_name : ('Router #' . (int) $scoped_router_id)); ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Message</label>
                        <input type="text" name="message" class="form-control" placeholder="Kosongkan untuk test message default">
                    </div>
                    <div class="col-md-2 d-grid">
                        <label class="form-label d-none d-md-block">&nbsp;</label>
                        <button type="submit" class="btn btn-outline-primary">Test Send</button>
                    </div>
                <?php echo form_close(); ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Telegram Bot</div>
            <div class="card-body">
                <?php echo form_open('settings/save_telegram_bot', array('class' => 'row g-2 mb-3')); ?>
                    <input type="hidden" name="id" value="0">
                    <div class="col-md-6">
                        <label class="form-label">Nama Bot</label>
                        <input type="text" name="bot_name" class="form-control" required placeholder="Contoh: Main Bot">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Bot Token</label>
                        <input type="text" name="bot_token" class="form-control" required placeholder="123456:ABC...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Router</label>
                        <?php if ($is_superadmin_user): ?>
                        <select name="router_id" class="form-select" required>
                            <option value="">- Pilih Router -</option>
                            <?php foreach ($router_options as $router): ?>
                            <option value="<?php echo (int) ($router['id'] ?? 0); ?>">
                                <?php echo html_escape((string) ($router['name'] ?? ('Router #' . (int) ($router['id'] ?? 0)))); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="hidden" name="router_id" value="<?php echo (int) $scoped_router_id; ?>">
                        <input type="text" class="form-control" readonly value="<?php echo html_escape($scoped_router_name !== '' ? $scoped_router_name : ('Router #' . (int) $scoped_router_id)); ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="is_active" class="form-select">
                            <option value="1">Aktif</option>
                            <option value="0">Nonaktif</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-grid">
                        <label class="form-label d-none d-md-block">&nbsp;</label>
                        <button type="submit" class="btn btn-primary">Simpan Bot</button>
                    </div>
                <?php echo form_close(); ?>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Bot</th>
                                <th>Router</th>
                                <th>Token</th>
                                <th>Status</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($telegram_bots)): ?>
                            <tr><td colspan="6" class="text-muted">Belum ada bot.</td></tr>
                            <?php else: ?>
                                <?php foreach ($telegram_bots as $bot): ?>
                                <tr>
                                    <td><?php echo (int) ($bot['id'] ?? 0); ?></td>
                                    <td><?php echo html_escape((string) ($bot['bot_name'] ?? '-')); ?></td>
                                    <td>
                                        <?php
                                        $bot_router_id = (int) ($bot['router_id'] ?? 0);
                                        $bot_router_label = '-';
                                        foreach ($router_options as $router_opt) {
                                            if ((int) ($router_opt['id'] ?? 0) === $bot_router_id) {
                                                $bot_router_label = (string) ($router_opt['name'] ?? ('Router #' . $bot_router_id));
                                                break;
                                            }
                                        }
                                        if ($bot_router_label === '-' && $bot_router_id > 0) {
                                            $bot_router_label = 'Router #' . $bot_router_id;
                                        }
                                        ?>
                                        <?php echo html_escape($bot_router_label); ?>
                                    </td>
                                    <td><code><?php echo html_escape((string) ($bot['token_preview'] ?? '***')); ?></code></td>
                                    <td>
                                        <span class="badge <?php echo (int) ($bot['is_active'] ?? 0) === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                                            <?php echo (int) ($bot['is_active'] ?? 0) === 1 ? 'Aktif' : 'Nonaktif'; ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php echo form_open('settings/delete_telegram_bot/' . (int) ($bot['id'] ?? 0), array('class' => 'd-inline', 'onsubmit' => "return confirm('Hapus bot ini?')")); ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
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
    </div>

    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Telegram Group / Chat ID</div>
            <div class="card-body">
                <?php echo form_open('settings/save_telegram_group', array('class' => 'row g-2 mb-3')); ?>
                    <input type="hidden" name="id" value="0">
                    <div class="col-md-4">
                        <label class="form-label">Bot</label>
                        <select name="bot_id" class="form-select" required>
                            <option value="0">- Pilih Bot -</option>
                            <?php foreach ($telegram_bots as $bot): ?>
                            <option value="<?php echo (int) ($bot['id'] ?? 0); ?>">
                                <?php echo html_escape((string) ($bot['bot_name'] ?? '-')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Router</label>
                        <?php if ($is_superadmin_user): ?>
                        <select name="router_id" class="form-select" required>
                            <option value="">- Pilih Router -</option>
                            <?php foreach ($router_options as $router): ?>
                            <option value="<?php echo (int) ($router['id'] ?? 0); ?>">
                                <?php echo html_escape((string) ($router['name'] ?? ('Router #' . (int) ($router['id'] ?? 0)))); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="hidden" name="router_id" value="<?php echo (int) $scoped_router_id; ?>">
                        <input type="text" class="form-control" readonly value="<?php echo html_escape($scoped_router_name !== '' ? $scoped_router_name : ('Router #' . (int) $scoped_router_id)); ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Chat ID</label>
                        <input type="text" name="chat_id" class="form-control" required placeholder="-100xxxxxxxxxx">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" required>
                            <?php foreach ($telegram_type_options as $value => $label): ?>
                            <option value="<?php echo html_escape((string) $value); ?>"><?php echo html_escape((string) $label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="is_active" class="form-select">
                            <option value="1">Aktif</option>
                            <option value="0">Nonaktif</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama Group</label>
                        <input type="text" name="group_name" class="form-control" placeholder="Opsional. Jika kosong akan diisi otomatis.">
                    </div>
                    <div class="col-12 d-grid">
                        <button type="submit" class="btn btn-primary" <?php echo $can_submit_group ? '' : 'disabled'; ?>>Simpan Group</button>
                    </div>
                <?php echo form_close(); ?>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Group</th>
                                <th>Type</th>
                                <th>Chat ID</th>
                                <th>Router</th>
                                <th>Bot</th>
                                <th>Status</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($telegram_groups)): ?>
                            <tr><td colspan="7" class="text-muted">Belum ada chat id/group.</td></tr>
                            <?php else: ?>
                                <?php foreach ($telegram_groups as $group): ?>
                                <tr>
                                    <?php
                                    $row_router_name = trim((string) ($group['router_name'] ?? ''));
                                    $row_router_id = (int) ($group['router_id'] ?? 0);
                                    $row_router_label = $row_router_name !== ''
                                        ? $row_router_name
                                        : ($row_router_id > 0 ? ('Router #' . $row_router_id) : '-');
                                    ?>
                                    <td><?php echo html_escape((string) ($group['group_name'] ?? '-')); ?></td>
                                    <td><span class="badge text-bg-info"><?php echo html_escape(strtoupper((string) ($group['type'] ?? '-'))); ?></span></td>
                                    <td><code><?php echo html_escape((string) ($group['chat_id'] ?? '-')); ?></code></td>
                                    <td><?php echo html_escape($row_router_label); ?></td>
                                    <td><?php echo html_escape((string) ($group['bot_name'] ?? '-')); ?></td>
                                    <td>
                                        <span class="badge <?php echo (int) ($group['is_active'] ?? 0) === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                                            <?php echo (int) ($group['is_active'] ?? 0) === 1 ? 'Aktif' : 'Nonaktif'; ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php echo form_open('settings/delete_telegram_group/' . (int) ($group['id'] ?? 0), array('class' => 'd-inline', 'onsubmit' => "return confirm('Hapus group ini?')")); ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
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
    </div>

</div>

<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
