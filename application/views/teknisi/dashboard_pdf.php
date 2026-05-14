<?php
$filters = isset($filters) && is_array($filters) ? $filters : array();
$selected_period_label = isset($selected_period_label) ? (string) $selected_period_label : '';
$kpi = isset($kpi) && is_array($kpi) ? $kpi : array();
$targets = isset($targets) && is_array($targets) ? $targets : array();
$work_order_rows = isset($work_order_rows) && is_array($work_order_rows) ? $work_order_rows : array();
$ticket_rows = isset($ticket_rows) && is_array($ticket_rows) ? $ticket_rows : array();
$ranking_rows = isset($ranking_rows) && is_array($ranking_rows) ? $ranking_rows : array();
$selected_technician_name = isset($selected_technician_name) ? (string) $selected_technician_name : 'Semua Teknisi';
$points_rule = isset($points_rule) && is_array($points_rule) ? $points_rule : array();

if ($selected_period_label === '') {
    $filter_month = (int) ($filters['month'] ?? date('m'));
    $filter_year = (int) ($filters['year'] ?? date('Y'));
    $selected_period_label = date('F Y', mktime(0, 0, 0, $filter_month, 1, $filter_year));
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Dashboard Teknisi</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1f2937; }
        h1, h2, h3 { margin: 0; }
        .meta { margin-top: 8px; margin-bottom: 14px; }
        .grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .grid th, .grid td { border: 1px solid #d1d5db; padding: 6px 8px; vertical-align: top; }
        .grid th { background: #f3f4f6; text-align: left; }
        .kpi td { width: 20%; }
        .small { color: #6b7280; font-size: 10px; }
    </style>
</head>
<body>
    <h2>RTRWNet</h2>
    <h1 style="margin-top:4px;">Laporan Dashboard Teknisi</h1>
    <div class="meta">
        <div>Periode: <?php echo html_escape($selected_period_label); ?></div>
        <div>Teknisi: <?php echo html_escape($selected_technician_name); ?></div>
        <div class="small">
            Rumus poin: WO selesai x <?php echo (int) ($points_rule['wo_done'] ?? 10); ?>,
            Ticket selesai x <?php echo (int) ($points_rule['ticket_done'] ?? 5); ?>,
            Ticket pending x <?php echo (int) ($points_rule['ticket_pending'] ?? -2); ?>
        </div>
    </div>

    <table class="grid kpi">
        <tr>
            <th>Total WO</th>
            <th>WO Selesai</th>
            <th>Ticket Ditangani</th>
            <th>Ticket Pending</th>
            <th>Total Poin</th>
        </tr>
        <tr>
            <td><?php echo number_format((int) ($kpi['total_wo'] ?? 0), 0, ',', '.'); ?></td>
            <td><?php echo number_format((int) ($kpi['wo_done'] ?? 0), 0, ',', '.'); ?></td>
            <td><?php echo number_format((int) ($kpi['ticket_done'] ?? 0), 0, ',', '.'); ?></td>
            <td><?php echo number_format((int) ($kpi['ticket_pending'] ?? 0), 0, ',', '.'); ?></td>
            <td><strong><?php echo number_format((int) ($kpi['total_points'] ?? 0), 0, ',', '.'); ?></strong></td>
        </tr>
    </table>

    <table class="grid">
        <tr>
            <th colspan="4">Target Progress</th>
        </tr>
        <tr>
            <th>Target Instalasi</th>
            <th>Realisasi Instalasi</th>
            <th>Target Ticket</th>
            <th>Realisasi Ticket</th>
        </tr>
        <tr>
            <td><?php echo number_format((int) ($targets['target_installation'] ?? 0), 0, ',', '.'); ?></td>
            <td><?php echo number_format((int) ($targets['real_installation'] ?? 0), 0, ',', '.'); ?> (<?php echo number_format((float) ($targets['installation_percent'] ?? 0), 2, ',', '.'); ?>%)</td>
            <td><?php echo number_format((int) ($targets['target_ticket'] ?? 0), 0, ',', '.'); ?></td>
            <td><?php echo number_format((int) ($targets['real_ticket'] ?? 0), 0, ',', '.'); ?> (<?php echo number_format((float) ($targets['ticket_percent'] ?? 0), 2, ',', '.'); ?>%)</td>
        </tr>
    </table>

    <table class="grid">
        <tr><th colspan="5">Detail Work Order</th></tr>
        <tr>
            <th>Tanggal</th>
            <th>Customer</th>
            <th>Paket</th>
            <th>Status</th>
            <th>Durasi</th>
        </tr>
        <?php if (empty($work_order_rows)): ?>
        <tr><td colspan="5">Tidak ada data.</td></tr>
        <?php else: ?>
            <?php foreach (array_slice($work_order_rows, 0, 30) as $row): ?>
            <tr>
                <td><?php echo html_escape((string) ($row['work_date'] ?? '-')); ?></td>
                <td><?php echo html_escape((string) ($row['customer_name'] ?? '-')); ?></td>
                <td><?php echo html_escape((string) ($row['package_name'] ?? '-')); ?></td>
                <td><?php echo html_escape((string) ($row['status'] ?? '-')); ?></td>
                <td><?php echo html_escape((string) ($row['work_duration'] ?? '-')); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <table class="grid">
        <tr><th colspan="6">Detail Ticket</th></tr>
        <tr>
            <th>No Ticket</th>
            <th>Customer</th>
            <th>Jenis</th>
            <th>SLA</th>
            <th>Status</th>
            <th>Durasi</th>
        </tr>
        <?php if (empty($ticket_rows)): ?>
        <tr><td colspan="6">Tidak ada data.</td></tr>
        <?php else: ?>
            <?php foreach (array_slice($ticket_rows, 0, 30) as $row): ?>
            <tr>
                <td><?php echo html_escape((string) ($row['ticket_number'] ?? '-')); ?></td>
                <td><?php echo html_escape((string) ($row['customer_name'] ?? '-')); ?></td>
                <td><?php echo html_escape((string) ($row['issue_type'] ?? '-')); ?></td>
                <td><?php echo html_escape((string) ($row['sla_deadline'] ?? '-')); ?></td>
                <td><?php echo html_escape((string) ($row['status'] ?? '-')); ?></td>
                <td><?php echo html_escape((string) ($row['duration'] ?? '-')); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <table class="grid">
        <tr><th colspan="6">Ranking Teknisi</th></tr>
        <tr>
            <th>#</th>
            <th>Teknisi</th>
            <th>WO Selesai</th>
            <th>Ticket Selesai</th>
            <th>Ticket Pending</th>
            <th>Total Poin</th>
        </tr>
        <?php if (empty($ranking_rows)): ?>
        <tr><td colspan="6">Tidak ada data ranking.</td></tr>
        <?php else: ?>
            <?php foreach ($ranking_rows as $idx => $row): ?>
            <tr>
                <td><?php echo (int) $idx + 1; ?></td>
                <td><?php echo html_escape((string) ($row['technician_name'] ?? '-')); ?></td>
                <td><?php echo number_format((int) ($row['wo_done'] ?? 0), 0, ',', '.'); ?></td>
                <td><?php echo number_format((int) ($row['ticket_done'] ?? 0), 0, ',', '.'); ?></td>
                <td><?php echo number_format((int) ($row['ticket_pending'] ?? 0), 0, ',', '.'); ?></td>
                <td><?php echo number_format((int) ($row['total_points'] ?? 0), 0, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
</body>
</html>
