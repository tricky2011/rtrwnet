<?php
$page_title = 'User Logs - ' . app_name();
$page_heading = 'User Activity Logs';
$page_subheading = 'Riwayat aksi user pada seluruh fitur aplikasi.';
$active_menu = 'user_logs';

$rows = isset($rows) && is_array($rows) ? $rows : array();
$filters = isset($filters) && is_array($filters) ? $filters : array();
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($rows);
$user_options = isset($user_options) && is_array($user_options) ? $user_options : array();
$per_page = isset($per_page) ? (int) $per_page : 20;
$per_page_options = isset($per_page_options) && is_array($per_page_options) ? $per_page_options : array(20, 50, 100, 500);
$allowed_roles = isset($allowed_roles) && is_array($allowed_roles) ? $allowed_roles : array();

$search = (string) ($filters['search'] ?? '');
$user_id = (int) ($filters['user_id'] ?? 0);
$controller = (string) ($filters['controller'] ?? '');
$method = (string) ($filters['method'] ?? '');
$date_from = (string) ($filters['date_from'] ?? '');
$date_to = (string) ($filters['date_to'] ?? '');

$query_base = $this->input->get();
if (isset($query_base['page'])) {
    unset($query_base['page']);
}

ob_start();
?>

<?php if ($this->session->flashdata('success')): ?>
    <div class="alert alert-success"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
    <div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>
<?php if (!empty($allowed_roles)): ?>
    <div class="alert alert-info">
        Scope log untuk role admin: hanya menampilkan aktivitas role <strong><?php echo html_escape(strtoupper(implode(', ', $allowed_roles))); ?></strong>.
    </div>
<?php endif; ?>

<div class="card stat-card mb-3">
    <div class="card-body">
        <?php echo form_open('user-logs', array('method' => 'get', 'class' => 'row g-2 align-items-end')); ?>
            <div class="col-lg-3 col-md-6">
                <label class="form-label mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo html_escape($search); ?>" placeholder="Aksi / URL / IP / payload">
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label mb-1">User</label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value="0">Semua User</option>
                    <?php foreach ($user_options as $u): ?>
                        <?php $uid = (int) ($u['id'] ?? 0); ?>
                        <option value="<?php echo $uid; ?>" <?php echo $uid === $user_id ? 'selected' : ''; ?>>
                            <?php echo html_escape((string) ($u['label'] ?? ('User #' . $uid))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label mb-1">Controller</label>
                <input type="text" name="controller" class="form-control form-control-sm" value="<?php echo html_escape($controller); ?>" placeholder="contoh: billing">
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label mb-1">Method</label>
                <input type="text" name="method" class="form-control form-control-sm" value="<?php echo html_escape($method); ?>" placeholder="contoh: store">
            </div>
            <div class="col-lg-1 col-md-6">
                <label class="form-label mb-1">Dari</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo html_escape($date_from); ?>">
            </div>
            <div class="col-lg-1 col-md-6">
                <label class="form-label mb-1">Sampai</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo html_escape($date_to); ?>">
            </div>
            <div class="col-12 d-flex flex-wrap gap-2 pt-1">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <a href="<?php echo site_url('user-logs'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                <span class="ms-auto text-muted small align-self-center">Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> log</span>
            </div>
        <?php echo form_close(); ?>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-2">
    <span class="small text-muted align-self-center">Page View:</span>
    <?php foreach ($per_page_options as $opt): ?>
        <?php
        $opt = (int) $opt;
        $query = $query_base;
        $query['per_page'] = $opt;
        $url = site_url('user-logs') . '?' . http_build_query($query);
        ?>
        <a href="<?php echo html_escape($url); ?>" class="btn btn-sm <?php echo $per_page === $opt ? 'btn-primary' : 'btn-outline-primary'; ?>">
            <?php echo $opt; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card stat-card">
    <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:160px;">Waktu</th>
                    <th style="width:160px;">User</th>
                    <th style="width:90px;">Role</th>
                    <th style="width:180px;">Aksi</th>
                    <th style="width:150px;">Controller/Method</th>
                    <th>Request URI</th>
                    <th style="width:130px;">IP</th>
                    <th style="width:260px;">Payload</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">Belum ada user activity log.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $role_label = strtoupper((string) ($r['user_role'] ?? '-'));
                    $payload = (string) ($r['payload_json'] ?? '');
                    $payload_preview = $payload !== '' ? (strlen($payload) > 140 ? substr($payload, 0, 140) . '...' : $payload) : '-';
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?php echo html_escape((string) ($r['created_at'] ?? '-')); ?></div>
                            <div class="small text-muted"><?php echo html_escape((string) ($r['http_method'] ?? '')); ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?php echo html_escape((string) ($r['actor_name'] ?? $r['user_name'] ?? '-')); ?></div>
                            <div class="small text-muted">ID: <?php echo (int) ($r['user_id'] ?? 0); ?></div>
                        </td>
                        <td>
                            <span class="badge <?php echo $role_label === 'SUPERADMIN' ? 'text-bg-danger' : ($role_label === 'ADMIN' ? 'text-bg-primary' : 'text-bg-secondary'); ?>">
                                <?php echo html_escape($role_label); ?>
                            </span>
                        </td>
                        <td><?php echo html_escape((string) ($r['action'] ?? '-')); ?></td>
                        <td>
                            <code><?php echo html_escape((string) ($r['controller'] ?? '-')); ?></code>
                            /
                            <code><?php echo html_escape((string) ($r['method'] ?? '-')); ?></code>
                        </td>
                        <td>
                            <div class="small"><?php echo html_escape((string) ($r['request_uri'] ?? '-')); ?></div>
                            <?php if (!empty($r['query_string'])): ?>
                                <div class="small text-muted">?<?php echo html_escape((string) $r['query_string']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo html_escape((string) ($r['ip_address'] ?? '-')); ?></td>
                        <td>
                            <div class="small text-break"><?php echo html_escape($payload_preview); ?></div>
                            <?php if ($payload !== '' && strlen($payload) > 140): ?>
                                <details class="mt-1">
                                    <summary class="small text-primary" role="button">Detail</summary>
                                    <pre class="small mb-0 mt-1"><?php echo html_escape($payload); ?></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pagination !== ''): ?>
        <div class="card-footer bg-white d-flex justify-content-end">
            <?php echo $pagination; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
