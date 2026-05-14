<?php
$page_title = 'Work Order List - ' . app_name();
$page_heading = 'Work Order List';
$page_subheading = 'Monitoring workflow pekerjaan teknisi: OPEN -> PROCESS -> DONE -> ACTIVATED.';
$active_menu = 'work_orders';

$rows = [
    ['WO-202602-0012', 'Budi Santoso', 'installation', 'OPEN', '2026-02-21', 'Andi'],
    ['WO-202602-0011', 'Nina Saputri', 'installation', 'PROCESS', '2026-02-20', 'Rama'],
    ['WO-202602-0010', 'Aldi Maulana', 'repair', 'DONE', '2026-02-20', 'Andi'],
    ['WO-202602-0009', 'Sari Wulandari', 'installation', 'ACTIVATED', '2026-02-19', 'Rama'],
    ['WO-202602-0008', 'Reza Kurnia', 'repair', 'OPEN', '2026-02-22', 'Dian'],
];

ob_start();
?>
<div class="card card-ui reveal">
    <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
        <span>WO Lifecycle Monitoring</span>
        <div class="d-flex gap-2">
            <span class="badge text-bg-secondary">OPEN</span>
            <span class="badge text-bg-primary">PROCESS</span>
            <span class="badge text-bg-success">DONE</span>
            <span class="badge" style="background:#0f766e;color:#fff;">ACTIVATED</span>
        </div>
    </div>
    <div class="card-body table-scroll p-0">
        <table class="table table-ui mb-0">
            <thead>
                <tr>
                    <th class="ps-3">WO Number</th>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Schedule</th>
                    <th>Technician</th>
                    <th class="text-end pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?php echo $r[0]; ?></td>
                    <td><?php echo $r[1]; ?></td>
                    <td><?php echo strtoupper($r[2]); ?></td>
                    <td>
                        <?php if ($r[3] === 'OPEN'): ?>
                        <span class="badge text-bg-secondary">OPEN</span>
                        <?php elseif ($r[3] === 'PROCESS'): ?>
                        <span class="badge text-bg-primary">PROCESS</span>
                        <?php elseif ($r[3] === 'DONE'): ?>
                        <span class="badge text-bg-success">DONE</span>
                        <?php else: ?>
                        <span class="badge" style="background:#0f766e;color:#fff;">ACTIVATED</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $r[4]; ?></td>
                    <td><?php echo $r[5]; ?></td>
                    <td class="text-end pe-3">
                        <button class="btn btn-sm btn-outline-primary">Update</button>
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
