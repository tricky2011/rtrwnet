<?php
$page_title = 'Monitoring ONT - ' . app_name();
$page_heading = 'Monitoring ONT';
$page_subheading = 'Monitoring status ONT GenieACS + remote reboot/set WiFi.';
$active_menu = 'ont_monitoring';

$rows = isset($rows) && is_array($rows) ? $rows : array();
$summary = isset($summary) && is_array($summary) ? $summary : array('online' => 0, 'offline' => 0, 'total' => 0);
$search = isset($search) ? (string) $search : '';
$status_filter = isset($status_filter) ? (string) $status_filter : '';
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($rows);
$scope_router_id = isset($scope_router_id) ? (int) $scope_router_id : 0;
$ont_return_url = uri_string();
$ont_query_string = (string) $this->input->server('QUERY_STRING');
if ($ont_query_string !== '') {
    $ont_return_url .= '?' . $ont_query_string;
}
$ont_sync_path = $scope_router_id > 0 ? 'ont/sync/' . $scope_router_id : 'ont/sync';

$build_ont_url = function ($path, array $query = array()) use ($scope_router_id) {
    $url = site_url($path);
    if ($scope_router_id > 0) {
        $query['router_id'] = $scope_router_id;
    }
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
};

$customer_fallback_label = function (array $row) {
    $pppoe = trim((string) ($row['ont_username'] ?? ''));
    if ($pppoe !== '') {
        $prefix = preg_replace('/[-_].*$/', '', $pppoe);
        $prefix = trim((string) $prefix);
        return $prefix !== '' ? $prefix : $pppoe;
    }

    $ssid = trim((string) ($row['ssid'] ?? ''));
    if ($ssid !== '') {
        return $ssid;
    }

    $wan_ip = trim((string) ($row['wan_ip'] ?? ''));
    return $wan_ip !== '' ? $wan_ip : '-';
};

ob_start();
?>
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted">Total ONT</div>
                <div class="h3 mb-0"><?php echo (int) ($summary['total'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted">Online</div>
                <div class="h3 mb-0 text-success"><?php echo (int) ($summary['online'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-muted">Offline</div>
                <div class="h3 mb-0 text-danger"><?php echo (int) ($summary['offline'] ?? 0); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="fw-semibold">List ONT</div>
        <div class="d-flex gap-2">
            <a href="<?php echo html_escape($build_ont_url('ont', array('status' => 'online'))); ?>" class="btn btn-sm btn-outline-success">Online</a>
            <a href="<?php echo html_escape($build_ont_url('ont', array('status' => 'offline'))); ?>" class="btn btn-sm btn-outline-danger">Offline</a>
            <a href="<?php echo html_escape($build_ont_url('ont')); ?>" class="btn btn-sm btn-outline-secondary">Semua</a>
            <?php if (hasRole(array('superadmin', 'admin'))): ?>
                <?php echo form_open($ont_sync_path, array('class' => 'd-inline', 'onsubmit' => "return confirm('Jalankan summon all ONT dari GenieACS?');")); ?>
                    <input type="hidden" name="return_url" value="<?php echo html_escape($ont_return_url); ?>">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-arrow-repeat me-1"></i>Summon All
                    </button>
                <?php echo form_close(); ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <form method="get" class="row g-2 mb-3">
            <div class="col-md-5">
                <input type="text" class="form-control" name="search" placeholder="Cari serial/manufacturer/model/customer/ip..." value="<?php echo html_escape($search); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">Semua Status</option>
                    <option value="online"<?php echo $status_filter === 'online' ? ' selected' : ''; ?>>Online</option>
                    <option value="offline"<?php echo $status_filter === 'offline' ? ' selected' : ''; ?>>Offline</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
            <div class="col-md-2">
                <a href="<?php echo html_escape($build_ont_url('ont')); ?>" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </form>

        <div class="small text-muted mb-2">Total: <?php echo (int) $total_rows; ?> data</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Serial Number</th>
                        <th>Customer</th>
                        <th>WAN IP</th>
                        <th>Manufacturer</th>
                        <th>Product Class</th>
                        <th>SSID</th>
                        <th>Password WiFi</th>
                        <th>Redaman</th>
                        <th>Last Inform</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">Belum ada data ONT.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $serial = (string) ($row['serial_number'] ?? '');
                            $is_online = strtolower((string) ($row['status'] ?? '')) === 'online';
                            $badge = $is_online ? 'bg-success' : 'bg-danger';
                            $customerLabel = trim((string) ($row['customer_name'] ?? ''));
                            if ($customerLabel === '' || $customerLabel === '-') {
                                $customerLabel = $customer_fallback_label($row);
                            }
                            if ($customerLabel === '') {
                                $customerLabel = '-';
                            }
                            ?>
                            <tr>
                                <td><span class="badge <?php echo $badge; ?>"><?php echo $is_online ? 'ONLINE' : 'OFFLINE'; ?></span></td>
                                <td class="fw-semibold"><?php echo html_escape($serial); ?></td>
                                <td><?php echo html_escape($customerLabel); ?></td>
                                <td><?php echo html_escape((string) ($row['wan_ip'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['manufacturer'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['product_class'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['ssid'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['wifi_password'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['optical_rx_dbm'] ?? '-')); ?></td>
                                <td><?php echo html_escape((string) ($row['last_inform'] ?? '-')); ?></td>
                                <td class="text-end">
                                    <?php $row_router_id = (int) ($row['router_id'] ?? $scope_router_id); ?>
                                    <a href="<?php echo site_url('ont/detail/' . rawurlencode($serial)) . ($row_router_id > 0 ? '?router_id=' . $row_router_id : ''); ?>" class="btn btn-sm btn-outline-primary">Detail</a>
                                    <?php if (hasRole(array('superadmin', 'admin'))): ?>
                                        <form method="post" action="<?php echo site_url('ont/reboot/' . rawurlencode($serial)); ?>" class="d-inline">
                                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                            <input type="hidden" name="router_id" value="<?php echo $row_router_id > 0 ? $row_router_id : ''; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning">Reboot</button>
                                        </form>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-success btnSetWifi"
                                                data-serial="<?php echo html_escape($serial); ?>"
                                                data-router-id="<?php echo (int) $row_router_id; ?>"
                                                data-ssid="<?php echo html_escape((string) ($row['ssid'] ?? '')); ?>">
                                            Set WiFi
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pagination): ?>
            <div class="mt-3"><?php echo $pagination; ?></div>
        <?php endif; ?>
    </div>
</div>

<?php if (hasRole(array('superadmin', 'admin'))): ?>
<div class="modal fade" id="setWifiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?php echo site_url('ont/set_wifi'); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Set WiFi ONT</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                    <input type="hidden" id="wifiRouterId" name="router_id" value="<?php echo $scope_router_id > 0 ? $scope_router_id : ''; ?>">
                    <div class="mb-3">
                        <label class="form-label">Serial Number</label>
                        <input type="text" class="form-control" id="wifiSerial" name="serial" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">SSID</label>
                        <input type="text" class="form-control" id="wifiSsid" name="ssid" required maxlength="100">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Password</label>
                        <input type="text" class="form-control" name="password" required minlength="8" maxlength="100" placeholder="Minimal 8 karakter">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">Simpan + Reboot</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();

$page_scripts = <<<'SCRIPT'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('setWifiModal');
    if (!modalEl) return;

    var modal = new bootstrap.Modal(modalEl);
    var serialInput = document.getElementById('wifiSerial');
    var ssidInput = document.getElementById('wifiSsid');
    var routerInput = document.getElementById('wifiRouterId');

    document.querySelectorAll('.btnSetWifi').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var serial = btn.getAttribute('data-serial') || '';
            var ssid = btn.getAttribute('data-ssid') || '';
            var routerId = btn.getAttribute('data-router-id') || '';
            if (serialInput) serialInput.value = serial;
            if (ssidInput) ssidInput.value = ssid;
            if (routerInput) routerInput.value = routerId;
            modal.show();
        });
    });
});
</script>
SCRIPT;

$this->load->view('layout/master', compact('page_title', 'page_heading', 'page_subheading', 'active_menu', 'content', 'page_scripts'));
?>
