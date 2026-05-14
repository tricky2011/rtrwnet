<?php
$page_title = 'Customers Table - ' . app_name();
$page_heading = 'Customers Table';
$page_subheading = 'Data pelanggan aktif, paket, billing date, dan status layanan.';
$active_menu = 'customers';

$rows = [
    ['CUST-0001', 'Budi Santoso', '20 Mbps', '15', 'active', '081234567890'],
    ['CUST-0002', 'Sari Wulandari', '10 Mbps', '9', 'active', '081299887766'],
    ['CUST-0003', 'Aldi Maulana', '50 Mbps', '25', 'isolated', '081355551234'],
    ['CUST-0004', 'Citra Novia', '30 Mbps', '12', 'waiting_install', '082122221111'],
    ['CUST-0005', 'Reza Kurnia', '20 Mbps', '6', 'active', '085700001122'],
];

ob_start();
?>
<div class="card card-ui reveal">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Customer Master Data</span>
        <div class="d-flex gap-2">
            <input class="form-control form-control-sm" style="width: 210px;" placeholder="Search customer...">
            <button class="btn btn-sm btn-primary">Filter</button>
        </div>
    </div>
    <div class="card-body table-scroll p-0">
        <table class="table table-ui mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Code</th>
                    <th>Name</th>
                    <th>Package</th>
                    <th>Billing Date</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th class="text-end pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?php echo $r[0]; ?></td>
                    <td><?php echo $r[1]; ?></td>
                    <td><?php echo $r[2]; ?></td>
                    <td>Tgl <?php echo $r[3]; ?></td>
                    <td><?php echo $r[5]; ?></td>
                    <td>
                        <?php if ($r[4] === 'active'): ?>
                        <span class="badge text-bg-success">ACTIVE</span>
                        <?php elseif ($r[4] === 'isolated'): ?>
                        <span class="badge text-bg-danger">ISOLATED</span>
                        <?php else: ?>
                        <span class="badge text-bg-info">WAITING INSTALL</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                        <button class="btn btn-sm btn-outline-secondary">Edit</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layouts/master.php';
