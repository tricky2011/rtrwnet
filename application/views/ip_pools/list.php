<?php
$page_title = 'IP Pools - ' . app_name();
$page_heading = 'IP Pools';
$page_subheading = 'Master Data pool IP untuk PPP profile.';
$active_menu = 'ip_pools';
$pools = isset($pools) && is_array($pools) ? $pools : array();
$search = isset($search) ? (string) $search : '';
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($pools);

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>IP Pools</span>
        <div class="d-flex gap-2">
            <?php echo form_open('ip-pools/sync-from-router', array('class' => 'd-inline')); ?>
            <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Sync IP Pool dari MikroTik sekarang?');">
                Sync from MikroTik
            </button>
            <?php echo form_close(); ?>
            <?php echo form_open('ip-pools/refresh-usage', array('class' => 'd-inline')); ?>
            <button type="submit" class="btn btn-sm btn-outline-warning">
                Refresh Usage
            </button>
            <?php echo form_close(); ?>
            <a href="<?php echo site_url('ip-pools/create'); ?>" class="btn btn-sm btn-primary">Tambah IP Pool</a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="p-3 border-bottom">
            <?php echo form_open('ip_pools', array('method' => 'get', 'class' => 'row g-2 align-items-center')); ?>
                <div class="col-md-5">
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        name="search"
                        placeholder="Cari pool / range / router"
                        value="<?php echo html_escape($search); ?>"
                    >
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
                </div>
                <div class="col-auto">
                    <a href="<?php echo site_url('ip_pools'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
                <div class="col text-muted small text-md-end">
                    Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> data
                </div>
            <?php echo form_close(); ?>
        </div>

        <?php if ($this->session->flashdata('success')): ?>
        <div class="alert alert-success rounded-0 border-0 mb-0"><?php echo html_escape($this->session->flashdata('success')); ?></div>
        <?php endif; ?>
        <?php if ($this->session->flashdata('error')): ?>
        <div class="alert alert-danger rounded-0 border-0 mb-0"><?php echo html_escape($this->session->flashdata('error')); ?></div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Pool Name</th>
                        <th>Range Start</th>
                        <th>Range End</th>
                        <th>Total IP</th>
                        <th>Used IP</th>
                        <th>Usage %</th>
                        <th>Router</th>
                        <th class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pools)): ?>
                    <tr>
                        <td class="ps-3 text-muted" colspan="8">Belum ada IP Pool.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($pools as $row): ?>
                        <?php
                        $total_ips = (int) ($row['total_ips'] ?? 0);
                        $used_ips = (int) ($row['used_ips'] ?? 0);
                        $usage_percent = isset($row['usage_percent']) ? (float) $row['usage_percent'] : 0;
                        if ($usage_percent < 0) {
                            $usage_percent = 0;
                        }
                        if ($usage_percent > 100) {
                            $usage_percent = 100;
                        }
                        $bar_class = 'bg-success';
                        if ($usage_percent > 80) {
                            $bar_class = 'bg-danger';
                        } elseif ($usage_percent >= 50) {
                            $bar_class = 'bg-warning';
                        }
                        ?>
                        <tr>
                            <td class="ps-3"><?php echo html_escape((string) ($row['pool_name'] ?? '-')); ?></td>
                            <td><?php echo html_escape((string) ($row['range_start'] ?? '-')); ?></td>
                            <td><?php echo html_escape((string) ($row['range_end'] ?? '-')); ?></td>
                            <td><?php echo number_format($total_ips, 0, ',', '.'); ?></td>
                            <td><?php echo number_format($used_ips, 0, ',', '.'); ?></td>
                            <td style="min-width: 180px;">
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar <?php echo $bar_class; ?>" role="progressbar" style="width: <?php echo (float) $usage_percent; ?>%;" aria-valuenow="<?php echo (float) $usage_percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <small class="text-muted"><?php echo number_format($usage_percent, 2); ?>%</small>
                            </td>
                            <td><?php echo html_escape((string) (($row['router_name'] ?? ($row['router_id'] ?? '')) !== '' ? ($row['router_name'] ?? $row['router_id']) : '-')); ?></td>
                            <td class="text-end pe-3">
                                <a href="<?php echo site_url('ip-pools/edit/' . (int) ($row['id'] ?? 0)); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                <?php echo form_open('ip-pools/delete/' . (int) ($row['id'] ?? 0), array('class' => 'd-inline')); ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus IP Pool ini?')">Hapus</button>
                                <?php echo form_close(); ?>
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
