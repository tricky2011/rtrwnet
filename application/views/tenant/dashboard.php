<?php
$page_title = 'Tenant Dashboard - ' . app_name();
$page_heading = 'SaaS Subscription Dashboard';
$page_subheading = 'Ringkasan paket aktif dan penggunaan resource tenant.';
$active_menu = 'tenant_dashboard';

$subscription = isset($subscription) && is_array($subscription) ? $subscription : array();
$usage = isset($usage) && is_array($usage) ? $usage : array();
$limits = isset($limits) && is_array($limits) ? $limits : array();
$days_left = isset($days_left) ? $days_left : null;

$package_name = (string) ($subscription['name'] ?? $subscription['package_name'] ?? $subscription['package_name_legacy'] ?? '-');
$end_date = (string) ($subscription['end_date'] ?? '-');
$status = strtoupper((string) ($subscription['status'] ?? '-'));

$items = array(
    array('label' => 'Router', 'key' => 'router', 'icon' => 'bi bi-router', 'color' => 'primary'),
    array('label' => 'Customer', 'key' => 'customer', 'icon' => 'bi bi-people', 'color' => 'success'),
    array('label' => 'User', 'key' => 'user', 'icon' => 'bi bi-person-badge', 'color' => 'warning'),
    array('label' => 'Telegram Group', 'key' => 'telegram_group', 'icon' => 'bi bi-telegram', 'color' => 'info'),
);

ob_start();
?>

<?php if ($this->session->flashdata('error')): ?>
    <div class="alert alert-danger"><?php echo $this->session->flashdata('error'); ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Paket Aktif</div>
                <div class="h4 fw-bold mb-1"><?php echo html_escape($package_name !== '' ? $package_name : '-'); ?></div>
                <span class="badge <?php echo $status === 'ACTIVE' ? 'text-bg-success' : 'text-bg-danger'; ?>">
                    <?php echo html_escape($status !== '' ? $status : '-'); ?>
                </span>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Tanggal Expired</div>
                <div class="h4 fw-bold mb-1"><?php echo html_escape($end_date); ?></div>
                <div class="text-muted small">Perpanjang sebelum masa aktif berakhir.</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Sisa Hari</div>
                <div class="h4 fw-bold mb-1 <?php echo ($days_left !== null && $days_left <= 7) ? 'text-danger' : 'text-primary'; ?>">
                    <?php echo $days_left !== null ? (int) $days_left . ' hari' : '-'; ?>
                </div>
                <div class="text-muted small">Auto suspend tenant jika subscription expired.</div>
            </div>
        </div>
    </div>
</div>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">Resource Usage</div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($items as $item): ?>
                <?php
                $key = $item['key'];
                $current = (int) ($usage[$key] ?? 0);
                $max = (int) ($limits[$key] ?? 0);
                $percent = ($max > 0) ? min(100, round(($current / $max) * 100, 2)) : 0;
                $bar_class = $percent >= 90 ? 'bg-danger' : ($percent >= 70 ? 'bg-warning' : 'bg-success');
                ?>
                <div class="col-md-6">
                    <div class="border rounded p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="fw-semibold"><i class="<?php echo $item['icon']; ?> me-1 text-<?php echo $item['color']; ?>"></i><?php echo $item['label']; ?></div>
                            <div class="small text-muted"><?php echo number_format($current); ?> / <?php echo number_format($max); ?></div>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar <?php echo $bar_class; ?>" style="width: <?php echo $percent; ?>%;"></div>
                        </div>
                        <div class="small text-muted mt-2"><?php echo $percent; ?>% terpakai</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
