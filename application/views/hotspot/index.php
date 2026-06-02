<?php
$page_title = 'Hotspot - ' . app_name();
$page_heading = 'Hotspot';
$page_subheading = 'Manajemen user dan profile hotspot MikroTik via API.';
$active_menu = 'hotspot';

$router = isset($router) && is_array($router) ? $router : array();
$profiles = isset($profiles) && is_array($profiles) ? $profiles : array();
$servers = isset($servers) && is_array($servers) ? $servers : array();
$users = isset($users) && is_array($users) ? $users : array();
$active = isset($active) && is_array($active) ? $active : array();
$generated_users = isset($generated_users) && is_array($generated_users) ? $generated_users : array();
$cache_info = isset($cache_info) && is_array($cache_info) ? $cache_info : array();
$error = isset($error) ? trim((string) $error) : '';

$format_bytes = static function ($value) {
    $value = (float) $value;
    if ($value <= 0) {
        return '-';
    }
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $idx = 0;
    while ($value >= 1024 && $idx < count($units) - 1) {
        $value /= 1024;
        $idx++;
    }
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$idx];
};

$safe = static function ($value, $fallback = '-') {
    $value = trim((string) $value);
    return $value !== '' ? $value : $fallback;
};

$format_money = static function ($value) {
    $value = preg_replace('/[^0-9]/', '', (string) $value);
    if ($value === '') {
        return '-';
    }
    $amount = (float) $value;
    return $amount > 0 ? 'Rp ' . number_format($amount, 0, ',', '.') : '-';
};

$parse_profile_meta = static function ($comment) {
    $comment = trim((string) $comment);
    if ($comment === '' || strpos($comment, 'RTRWNET-HOTSPOT') !== 0) {
        return array();
    }
    $meta = array();
    foreach (explode(';', $comment) as $part) {
        $pos = strpos($part, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($part, 0, $pos));
        $value = trim(substr($part, $pos + 1));
        if ($key !== '') {
            $meta[$key] = $value;
        }
    }
    return $meta;
};

$profile_meta_map = array();
foreach ($profiles as $profile_row) {
    $profile_name = trim((string) ($profile_row['name'] ?? ''));
    if ($profile_name !== '') {
        $profile_meta_map[$profile_name] = $parse_profile_meta($profile_row['comment'] ?? '');
    }
}

ob_start();
?>

<?php if ($this->session->flashdata('success')): ?>
    <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
    <div class="alert alert-danger"><?php echo html_escape($this->session->flashdata('error')); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-warning"><?php echo html_escape($error); ?></div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="text-muted small">
        <?php if (!empty($cache_info['loaded'])): ?>
            Data terakhir: <?php echo html_escape((string) ($cache_info['fetched_at'] ?? '-')); ?>
            <?php if (!empty($cache_info['from_cache'])): ?>
                (cache <?php echo (int) ($cache_info['age_seconds'] ?? 0); ?> detik)
            <?php endif; ?>
        <?php else: ?>
            Data router belum dimuat.
        <?php endif; ?>
    </div>
    <a href="<?php echo site_url('hotspot') . '?refresh=1'; ?>" class="btn btn-sm btn-outline-primary">
        Refresh dari Router
    </a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Router Aktif</div>
                <div class="h5 mb-0"><?php echo html_escape((string) ($router['router_name'] ?? '-')); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Hotspot Users</div>
                <div class="h5 mb-0"><?php echo number_format(count($users), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Active Sessions</div>
                <div class="h5 mb-0"><?php echo number_format(count($active), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Profiles</div>
                <div class="h5 mb-0"><?php echo number_format(count($profiles), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
</div>

<datalist id="hotspotProfileOptions">
    <?php foreach ($profiles as $profile): ?>
        <?php $profile_name = (string) ($profile['name'] ?? ''); ?>
        <?php if ($profile_name === '') { continue; } ?>
        <option value="<?php echo html_escape($profile_name); ?>"></option>
    <?php endforeach; ?>
</datalist>
<datalist id="hotspotServerOptions">
    <?php foreach ($servers as $server): ?>
        <?php $server_name = (string) ($server['name'] ?? ''); ?>
        <?php if ($server_name === '') { continue; } ?>
        <option value="<?php echo html_escape($server_name); ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php if (!empty($generated_users)): ?>
    <div class="card stat-card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Voucher Terakhir</h5>
                <span class="badge text-bg-primary"><?php echo count($generated_users); ?> user</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Password</th>
                            <th>Profile</th>
                            <th>Time Limit</th>
                            <th>Data Limit</th>
                            <th>Harga Voucher</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($generated_users as $row): ?>
                            <?php
                            $generated_profile = (string) ($row['profile'] ?? '');
                            $generated_meta = $profile_meta_map[$generated_profile] ?? array();
                            $selling_price = $row['selling_price'] ?? ($generated_meta['selling_price'] ?? '');
                            ?>
                            <tr>
                                <td><code><?php echo html_escape((string) ($row['username'] ?? '')); ?></code></td>
                                <td><code><?php echo html_escape((string) ($row['password'] ?? '')); ?></code></td>
                                <td><?php echo html_escape($safe($row['profile'] ?? '')); ?></td>
                                <td><?php echo html_escape($safe($row['time_limit'] ?? '')); ?></td>
                                <td><?php echo html_escape($format_bytes($row['data_limit'] ?? 0)); ?></td>
                                <td><?php echo html_escape($format_money($selling_price)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <h5 class="mb-3">Add Hotspot User</h5>
                <?php echo form_open('hotspot/add-user', array('class' => 'row g-2')); ?>
                    <div class="col-12">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" maxlength="64" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Password</label>
                        <input type="text" name="password" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Profile</label>
                        <input type="text" name="profile" class="form-control" list="hotspotProfileOptions" placeholder="default">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Server</label>
                        <input type="text" name="server" class="form-control" list="hotspotServerOptions" placeholder="all">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Time Limit</label>
                        <input type="text" name="time_limit" class="form-control" placeholder="1d / 6h">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Data Limit</label>
                        <div class="input-group">
                            <input type="text" name="data_limit_value" class="form-control" placeholder="2">
                            <select name="data_limit_unit" class="form-select" style="max-width: 90px;">
                                <option value="MB">MB</option>
                                <option value="GB">GB</option>
                                <option value="KB">KB</option>
                                <option value="B">B</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Comment</label>
                        <input type="text" name="comment" class="form-control" maxlength="255">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100">Tambah User</button>
                    </div>
                <?php echo form_close(); ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <h5 class="mb-3">Generate Hotspot Users</h5>
                <?php echo form_open('hotspot/generate-users', array('class' => 'row g-2')); ?>
                    <div class="col-6">
                        <label class="form-label">Jumlah</label>
                        <input type="number" name="count" class="form-control" min="1" max="500" value="10" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Panjang</label>
                        <input type="number" name="username_length" class="form-control" min="1" max="8" value="6" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Mode</label>
                        <select name="mode" class="form-select">
                            <option value="username_password">Username & Password</option>
                            <option value="username_equals_password">Username = Password</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Prefix</label>
                        <input type="text" name="prefix" class="form-control" maxlength="8">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Profile</label>
                        <input type="text" name="profile" class="form-control" list="hotspotProfileOptions" placeholder="default">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Server</label>
                        <input type="text" name="server" class="form-control" list="hotspotServerOptions" placeholder="all">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Time Limit</label>
                        <input type="text" name="time_limit" class="form-control" placeholder="1d">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Data Limit</label>
                        <div class="input-group">
                            <input type="text" name="data_limit_value" class="form-control">
                            <select name="data_limit_unit" class="form-select" style="max-width: 90px;">
                                <option value="MB">MB</option>
                                <option value="GB">GB</option>
                                <option value="KB">KB</option>
                                <option value="B">B</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Comment</label>
                        <input type="text" name="comment" class="form-control" maxlength="255">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100">Generate</button>
                    </div>
                <?php echo form_close(); ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <h5 class="mb-3">User Profile</h5>
                <?php echo form_open('hotspot/add-profile', array('class' => 'row g-2')); ?>
                    <div class="col-12">
                        <label class="form-label">Nama Profile</label>
                        <input type="text" name="name" class="form-control" maxlength="64" placeholder="voucher-1d" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Validity</label>
                        <input type="text" name="validity" class="form-control" placeholder="1d">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Expired</label>
                        <select name="expire_mode" class="form-select">
                            <option value="remove">Remove</option>
                            <option value="remove_record">Remove & Record</option>
                            <option value="notice">Notice</option>
                            <option value="notice_record">Notice & Record</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Price</label>
                        <input type="text" name="price" class="form-control" value="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Selling Price</label>
                        <input type="text" name="selling_price" class="form-control" value="0">
                    </div>
                    <div class="col-12 d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="record" value="1" id="profileRecord">
                            <label class="form-check-label" for="profileRecord">Record</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="user_lock" value="1" id="profileLock">
                            <label class="form-check-label" for="profileLock">User Lock</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100">Buat Profile</button>
                    </div>
                <?php echo form_close(); ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-5">
        <div class="card stat-card h-100">
            <div class="card-body">
                <h5 class="mb-3">Profiles</h5>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Validity</th>
                                <th>Expired</th>
                                <th>Price</th>
                                <th>Selling</th>
                                <th>Lock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($profiles)): ?>
                                <tr><td colspan="6" class="text-center text-muted">Tidak ada profile.</td></tr>
                            <?php else: ?>
                                <?php foreach ($profiles as $profile): ?>
                                    <?php
                                    $profile_name = (string) ($profile['name'] ?? '');
                                    $meta = $profile_meta_map[$profile_name] ?? array();
                                    $user_lock = (string) ($meta['user_lock'] ?? '') === '1';
                                    ?>
                                    <tr>
                                        <td><?php echo html_escape($safe($profile['name'] ?? '')); ?></td>
                                        <td><?php echo html_escape($safe($meta['validity'] ?? '')); ?></td>
                                        <td><?php echo html_escape($safe($meta['expire_mode'] ?? '')); ?></td>
                                        <td><?php echo html_escape($format_money($meta['price'] ?? '')); ?></td>
                                        <td><?php echo html_escape($format_money($meta['selling_price'] ?? '')); ?></td>
                                        <td>
                                            <?php if ($user_lock): ?>
                                                <span class="badge text-bg-primary">lock</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">open</span>
                                            <?php endif; ?>
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

    <div class="col-lg-7">
        <div class="card stat-card h-100">
            <div class="card-body">
                <h5 class="mb-3">Hotspot Users</h5>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Profile</th>
                                <th>Uptime</th>
                                <th>Limit Time</th>
                                <th>Limit Data</th>
                                <th>Terpakai</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr><td colspan="7" class="text-center text-muted">Tidak ada hotspot user.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($users, 0, 150) as $user): ?>
                                    <?php
                                    $bytes_total = (float) ($user['bytes-in'] ?? 0) + (float) ($user['bytes-out'] ?? 0);
                                    $disabled = strtolower((string) ($user['disabled'] ?? 'false'));
                                    ?>
                                    <tr>
                                        <td><code><?php echo html_escape($safe($user['name'] ?? '')); ?></code></td>
                                        <td><?php echo html_escape($safe($user['profile'] ?? '')); ?></td>
                                        <td><?php echo html_escape($safe($user['uptime'] ?? '')); ?></td>
                                        <td><?php echo html_escape($safe($user['limit-uptime'] ?? '')); ?></td>
                                        <td><?php echo html_escape($format_bytes($user['limit-bytes-total'] ?? 0)); ?></td>
                                        <td><?php echo html_escape($format_bytes($bytes_total)); ?></td>
                                        <td>
                                            <?php if ($disabled === 'true' || $disabled === 'yes'): ?>
                                                <span class="badge text-bg-secondary">disabled</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-success">enabled</span>
                                            <?php endif; ?>
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
