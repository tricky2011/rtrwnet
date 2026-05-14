<?php
$page_title = 'PPP Profiles - ' . app_name();
$page_heading = 'PPP Profiles';
$page_subheading = 'Master Data profile PPP sekaligus harga paket (PPPoE & Static) untuk provisioning dan billing.';
$active_menu = 'ppp_profiles';
$profiles = isset($profiles) && is_array($profiles) ? $profiles : array();
$role = (string) $this->session->userdata('role');
$can_manage = in_array($role, array('superadmin', 'admin'), true);
$search = isset($search) ? (string) $search : '';
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($profiles);
$swal_success = $this->session->flashdata('swal_success');
if ($swal_success === null || $swal_success === '') {
    $swal_success = $this->session->flashdata('success');
}
$page_scripts = '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
if ($swal_success !== null && $swal_success !== '') {
    $page_scripts .= '<script>document.addEventListener("DOMContentLoaded",function(){Swal.fire({icon:"success",title:"Berhasil!",text:'
        . json_encode((string) $swal_success)
        . ',confirmButtonColor:"#3085d6",confirmButtonText:"OK"});});</script>';
}

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>PPP Profiles</span>
        <?php if ($can_manage): ?>
            <div class="d-flex gap-2">
                <?php echo form_open('ppp-profiles/sync-from-router', array('class' => 'd-inline')); ?>
                <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Sync PPP Profile dari MikroTik sekarang?');">
                    Sync from MikroTik
                </button>
                <?php echo form_close(); ?>
                <a href="<?php echo site_url('ppp-profiles/create'); ?>" class="btn btn-sm btn-primary">Tambah PPP Profile</a>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="p-3 border-bottom">
            <?php echo form_open('ppp_profiles', array('method' => 'get', 'class' => 'row g-2 align-items-center')); ?>
                <div class="col-md-5">
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        name="search"
                        placeholder="Cari nama profile / rate limit / pool"
                        value="<?php echo html_escape($search); ?>"
                    >
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
                </div>
                <div class="col-auto">
                    <a href="<?php echo site_url('ppp_profiles'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
                <div class="col text-muted small text-md-end">
                    Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> data
                </div>
            <?php echo form_close(); ?>
        </div>

        <?php if ($this->session->flashdata('error')): ?>
        <div class="alert alert-danger rounded-0 border-0 mb-0"><?php echo html_escape($this->session->flashdata('error')); ?></div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Name</th>
                        <th>Rate Limit</th>
                        <th>Local Address</th>
                        <th>Remote Pool</th>
                        <th>IP Range</th>
                        <th>Total IP</th>
                        <th>Used IP</th>
                        <th>Usage %</th>
                        <th>Price</th>
                        <th>Description</th>
                        <th class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($profiles)): ?>
                    <tr>
                        <td class="ps-3 text-muted" colspan="11">Belum ada PPP Profile.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($profiles as $row): ?>
                        <tr>
                            <td class="ps-3"><?php echo html_escape((string) ($row['name'] ?? '-')); ?></td>
                            <td><?php echo html_escape((string) ($row['rate_limit'] ?? '-')); ?></td>
                            <td><?php echo html_escape((string) ($row['local_address'] ?? '-')); ?></td>
                            <td><?php echo html_escape((string) ($row['remote_address_pool'] ?? '-')); ?></td>
                            <td><?php echo html_escape((string) ($row['ip_pool_range'] ?? '-')); ?></td>
                            <td><?php echo number_format((int) ($row['ip_total'] ?? 0), 0, ',', '.'); ?></td>
                            <td><?php echo number_format((int) ($row['ip_used'] ?? 0), 0, ',', '.'); ?></td>
                            <td><?php echo number_format((float) ($row['ip_usage_percent'] ?? 0), 2); ?>%</td>
                            <td><?php echo function_exists('rupiah') ? rupiah($row['price'] ?? 0) : ('Rp ' . number_format((float) ($row['price'] ?? 0), 0, ',', '.')); ?></td>
                            <td><?php echo html_escape((string) (($row['description'] ?? '') !== '' ? $row['description'] : '-')); ?></td>
                            <td class="text-end pe-3">
                                <?php if ($can_manage): ?>
                                    <a href="<?php echo site_url('ppp-profiles/edit/' . (int) ($row['id'] ?? 0)); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <?php echo form_open('ppp-profiles/delete/' . (int) ($row['id'] ?? 0), array('class' => 'd-inline')); ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus PPP Profile ini?')">Hapus</button>
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
