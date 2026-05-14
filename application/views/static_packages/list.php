<?php
$page_title = 'Harga Paket Static - ' . app_name();
$page_heading = 'Harga Paket Static';
$page_subheading = 'Manajemen harga paket STATIC (sumber dari profile PPP). Dipakai saat sync static IP.';
$active_menu = 'static_packages';

$packages = isset($packages) && is_array($packages) ? $packages : array();
$search = isset($search) ? (string) $search : '';
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($packages);
$per_page = isset($per_page) ? (int) $per_page : 20;
$per_page_options = isset($per_page_options) && is_array($per_page_options) ? $per_page_options : array(20, 50, 100, 500);

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Daftar Harga Paket Static</span>
        <a href="<?php echo site_url('ppp-profiles'); ?>" class="btn btn-sm btn-outline-secondary">Lihat PPP Profiles</a>
    </div>
    <div class="card-body p-0">
        <div class="p-3 border-bottom">
            <?php echo form_open('static-packages', array('method' => 'get', 'class' => 'row g-2 align-items-center', 'id' => 'staticPackageFilterForm')); ?>
                <div class="col-md-5">
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        name="search"
                        placeholder="Cari paket / rate limit"
                        value="<?php echo html_escape($search); ?>"
                    >
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
                </div>
                <div class="col-auto">
                    <a href="<?php echo site_url('static-packages'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
                <div class="col text-muted small text-md-end">
                    Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> data
                </div>
            <?php echo form_close(); ?>
        </div>

        <?php if ($this->session->flashdata('success')): ?>
        <div class="alert alert-success rounded-0 border-0 mb-0"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
        <?php endif; ?>
        <?php if ($this->session->flashdata('error')): ?>
        <div class="alert alert-danger rounded-0 border-0 mb-0"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Paket</th>
                        <th>Rate Limit</th>
                        <th>Harga</th>
                        <th>Updated</th>
                        <th class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($packages)): ?>
                        <tr>
                            <td class="ps-3 text-muted" colspan="5">Belum ada paket static.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($packages as $row): ?>
                            <?php
                            $name = trim((string) ($row['name'] ?? '-'));
                            $normalized = strtoupper(preg_replace('/\s+/', '', $name));
                            $label_map = array(
                                '10M' => '10 M (10 Mbps)',
                                '20M' => '20 M (20 Mbps)',
                                '30M' => '30 M (30 Mbps)',
                                '50M' => '50 M (50 Mbps)',
                                '7M' => '7 M (7 Mbps)',
                                '5M' => '5 M (5 Mbps)',
                            );
                            $display_name = isset($label_map[$normalized]) ? $label_map[$normalized] : $name;
                            ?>
                            <tr>
                                <td class="ps-3 fw-semibold"><?php echo html_escape($display_name); ?></td>
                                <td><?php echo html_escape((string) ($row['rate_limit'] ?? '-')); ?></td>
                                <td><?php echo function_exists('rupiah') ? rupiah($row['price'] ?? 0) : ('Rp ' . number_format((float) ($row['price'] ?? 0), 0, ',', '.')); ?></td>
                                <td><?php echo html_escape((string) ($row['updated_at'] ?? '-')); ?></td>
                                <td class="text-end pe-3">
                                    <a href="<?php echo site_url('static-packages/edit/' . (int) ($row['id'] ?? 0)); ?>" class="btn btn-sm btn-outline-primary">Edit Harga</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="p-3 border-top d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div>
                <div class="small text-muted mb-1">Page View</div>
                <div class="d-flex flex-wrap gap-1" role="group" aria-label="static-package-page-view-buttons">
                    <?php foreach ($per_page_options as $option): ?>
                        <?php $option = (int) $option; ?>
                        <?php $input_id = 'static_package_per_page_' . $option; ?>
                        <input
                            class="btn-check"
                            type="radio"
                            name="per_page"
                            id="<?php echo $input_id; ?>"
                            form="staticPackageFilterForm"
                            value="<?php echo $option; ?>"
                            autocomplete="off"
                            onchange="document.getElementById('staticPackageFilterForm').submit();"
                            <?php echo $per_page === $option ? 'checked' : ''; ?>
                        >
                        <label class="btn btn-outline-primary btn-sm px-2 py-1" for="<?php echo $input_id; ?>">
                            <?php echo $option; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($pagination !== ''): ?>
                <div class="ms-md-auto"><?php echo $pagination; ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';

