<?php
$page_title = 'Detail ONT - ' . app_name();
$page_heading = 'Detail ONT';
$page_subheading = 'Informasi device dari GenieACS NBI.';
$active_menu = 'ont_monitoring';

$serial = isset($serial) ? (string) $serial : '';
$device = isset($device) && is_array($device) ? $device : array();
$db_row = isset($db_row) && is_array($db_row) ? $db_row : array();
$scope_router_id = isset($scope_router_id) ? (int) $scope_router_id : 0;
$customer_label = trim((string) ($db_row['customer_name'] ?? ''));
if ($customer_label === '' || $customer_label === '-') {
    $customer_label = trim((string) ($db_row['ont_username'] ?? ''));
}
if ($customer_label === '') {
    $customer_label = '-';
}

function ont_value($device, $path)
{
    return isset($device[$path]['_value']) ? (string) $device[$path]['_value'] : '';
}

ob_start();
?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <div class="fw-semibold">Serial: <?php echo html_escape($serial); ?></div>
        <a href="<?php echo site_url('ont') . ($scope_router_id > 0 ? '?router_id=' . $scope_router_id : ''); ?>" class="btn btn-sm btn-outline-secondary">Kembali</a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="small text-muted">Device ID (_id)</div>
                <div class="fw-semibold text-break"><?php echo html_escape((string) ($device['_id'] ?? '-')); ?></div>
            </div>
            <div class="col-md-6">
                <div class="small text-muted">Last Inform</div>
                <div class="fw-semibold"><?php echo html_escape((string) ($device['_lastInform'] ?? ($db_row['last_inform'] ?? '-'))); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Manufacturer</div>
                <div class="fw-semibold"><?php echo html_escape((string) ($db_row['manufacturer'] ?? '-')); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Product Class</div>
                <div class="fw-semibold"><?php echo html_escape((string) ($db_row['product_class'] ?? '-')); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">WAN IP</div>
                <div class="fw-semibold"><?php echo html_escape((string) ($db_row['wan_ip'] ?? '-')); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Customer</div>
                <div class="fw-semibold"><?php echo html_escape($customer_label); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">SSID</div>
                <div class="fw-semibold"><?php echo html_escape((string) ($db_row['ssid'] ?? '-')); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Password WiFi</div>
                <div class="fw-semibold"><?php echo html_escape((string) ($db_row['wifi_password'] ?? '-')); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Username PPP</div>
                <div class="fw-semibold"><?php echo html_escape((string) ($db_row['ont_username'] ?? '-')); ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Redaman (Rx)</div>
                <div class="fw-semibold"><?php echo html_escape((string) ($db_row['optical_rx_dbm'] ?? '-')); ?></div>
            </div>
        </div>
        <hr>
        <h6 class="mb-2">Raw JSON (GenieACS)</h6>
        <pre class="small bg-light border rounded p-3 mb-0" style="max-height:520px; overflow:auto;"><?php echo html_escape(json_encode($device, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
    </div>
</div>
<?php
$content = ob_get_clean();
$this->load->view('layout/master', compact('page_title', 'page_heading', 'page_subheading', 'active_menu', 'content'));
?>
