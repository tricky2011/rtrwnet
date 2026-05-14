<?php
$page_title = 'Billing List - ' . app_name();
$page_heading = 'Billing List';
$page_subheading = 'Daftar invoice pelanggan dengan status penagihan terbaru.';
$active_menu = 'billing';

$rows = [
    ['INV-2026-0201', 'CUST-0012', 'Andi Wijaya', '2026-02', '2026-02-12', 350000, 'UNPAID'],
    ['INV-2026-0202', 'CUST-0044', 'Nina Saputri', '2026-02', '2026-02-10', 280000, 'OVERDUE'],
    ['INV-2026-0203', 'CUST-0007', 'Rizal Pratama', '2026-02', '2026-02-08', 500000, 'PAID'],
    ['INV-2026-0204', 'CUST-0061', 'Suci Lestari', '2026-02', '2026-02-18', 420000, 'UNPAID'],
    ['INV-2026-0205', 'CUST-0023', 'Budi Rahman', '2026-02', '2026-02-09', 300000, 'OVERDUE'],
];

ob_start();
?>
<div class="card card-ui reveal">
    <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
        <span>Invoice Overview</span>
        <div class="d-flex gap-2">
            <span class="badge rounded-pill text-bg-success">PAID</span>
            <span class="badge rounded-pill text-bg-warning">UNPAID</span>
            <span class="badge rounded-pill text-bg-danger">OVERDUE</span>
        </div>
    </div>
    <div class="card-body table-scroll p-0">
        <table class="table table-ui mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Invoice</th>
                    <th>Customer</th>
                    <th>Period</th>
                    <th>Due Date</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th class="text-end pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?php echo $r[0]; ?></td>
                    <td>
                        <div><?php echo $r[2]; ?></div>
                        <small class="text-muted"><?php echo $r[1]; ?></small>
                    </td>
                    <td><?php echo $r[3]; ?></td>
                    <td><?php echo $r[4]; ?></td>
                    <td>Rp <?php echo number_format((float) $r[5], 0, ',', '.'); ?></td>
                    <td>
                        <?php if ($r[6] === 'PAID'): ?>
                        <span class="badge text-bg-success">PAID</span>
                        <?php elseif ($r[6] === 'OVERDUE'): ?>
                        <span class="badge text-bg-danger">OVERDUE</span>
                        <?php else: ?>
                        <span class="badge text-bg-warning">UNPAID</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-3"><button class="btn btn-sm btn-outline-primary">Detail</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layouts/master.php';
