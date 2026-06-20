<?php
$page_title = 'ACS Gap - ' . app_name();
$page_heading = 'ACS Gap Billing';
$page_subheading = 'Customer dengan WAN IP yang belum ditemukan di ONT GenieACS.';
$active_menu = 'billing';

$summary = isset($summary) && is_array($summary) ? $summary : array();
$missing_rows = isset($missing_rows) && is_array($missing_rows) ? $missing_rows : array();
$search = isset($search) ? trim((string) $search) : '';
$error_message = isset($error_message) ? trim((string) $error_message) : '';
$acs_gap_return_url = uri_string();
$acs_gap_query_string = (string) $this->input->server('QUERY_STRING');
if ($acs_gap_query_string !== '') {
    $acs_gap_return_url .= '?' . $acs_gap_query_string;
}

if (!function_exists('billing_acs_gap_value')) {
    function billing_acs_gap_value($value, $fallback = '-')
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : $fallback;
    }
}

ob_start();
?>
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo site_url('billing'); ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Kembali Billing
        </a>
        <?php echo form_open('ont/sync', array('class' => 'd-inline', 'onsubmit' => "return confirm('Jalankan summon all ONT dari GenieACS?');")); ?>
            <input type="hidden" name="return_url" value="<?php echo html_escape($acs_gap_return_url); ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-arrow-repeat me-1"></i>Summon All
            </button>
        <?php echo form_close(); ?>
    </div>
    <form class="d-flex gap-2" method="get" action="<?php echo site_url('billing/acs-gap'); ?>">
        <input type="search" name="search" class="form-control form-control-sm" placeholder="Cari customer/IP/PPP" value="<?php echo html_escape($search); ?>">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="small text-muted">ONT di ACS Mirror</div>
                <div class="h4 mb-0"><?php echo number_format((int) ($summary['total_acs_ont'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="small text-muted">Customer Dengan IP</div>
                <div class="h4 mb-0"><?php echo number_format((int) ($summary['customer_with_ip'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="small text-muted">Sudah Cocok ACS</div>
                <div class="h4 mb-0 text-success"><?php echo number_format((int) ($summary['registered_in_acs'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="small text-muted">Belum Terdaftar ACS</div>
                <div class="h4 mb-0 text-danger"><?php echo number_format((int) ($summary['missing_in_acs'] ?? 0), 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($error_message !== ''): ?>
    <div class="alert alert-warning"><?php echo html_escape($error_message); ?></div>
<?php endif; ?>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex flex-column flex-md-row justify-content-between gap-2">
        <span>Customer WAN IP Belum Ada di ACS</span>
        <span class="small text-muted"><?php echo number_format(count($missing_rows), 0, ',', '.'); ?> baris</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Router</th>
                    <th>Customer</th>
                    <th>User PPP</th>
                    <th>WAN IP Customer</th>
                    <th>Tipe</th>
                    <th>ONT di Customer</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($missing_rows)): ?>
                    <tr>
                        <td colspan="7" class="ps-3 text-muted">Tidak ada gap berdasarkan WAN IP customer.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($missing_rows as $row): ?>
                        <?php
                        $customer_name = billing_acs_gap_value($row['full_name'] ?? '');
                        $customer_code = billing_acs_gap_value($row['customer_code'] ?? '');
                        $pppoe_label = billing_acs_gap_value($row['pppoe_label'] ?? '');
                        $router_name = billing_acs_gap_value($row['router_name'] ?? ('#' . (string) ($row['router_id'] ?? '')));
                        $ont_device_id = billing_acs_gap_value($row['ont_device_id'] ?? '');
                        $ont_serial = billing_acs_gap_value($row['ont_serial'] ?? '');
                        $ont_customer_value = $ont_device_id !== '-' ? $ont_device_id : $ont_serial;
                        ?>
                        <tr>
                            <td class="ps-3"><?php echo html_escape($router_name); ?></td>
                            <td>
                                <div class="fw-semibold"><?php echo html_escape($customer_name); ?></div>
                                <div class="small text-muted"><?php echo html_escape($customer_code); ?></div>
                            </td>
                            <td><span class="badge text-bg-light border"><?php echo html_escape($pppoe_label); ?></span></td>
                            <td class="fw-semibold"><?php echo html_escape((string) ($row['ip_address'] ?? '-')); ?></td>
                            <td><?php echo html_escape(billing_acs_gap_value($row['connection_type'] ?? '')); ?></td>
                            <td><?php echo html_escape($ont_customer_value); ?></td>
                            <td>
                                <span class="badge text-bg-danger">BELUM REGISTER ACS</span>
                                <div class="small text-muted mt-1"><?php echo html_escape(billing_acs_gap_value($row['customer_status'] ?? '')); ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layouts/master.php';
