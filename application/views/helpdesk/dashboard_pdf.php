<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Helpdesk Dashboard Bulanan</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1, h2, h3 { margin: 0; }
        .header { margin-bottom: 12px; }
        .subtitle { color: #6b7280; font-size: 10px; margin-top: 4px; }
        .grid { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .grid th, .grid td { border: 1px solid #d1d5db; padding: 6px 8px; vertical-align: top; }
        .grid th { background: #f3f4f6; text-align: left; }
        .kpi { margin-top: 12px; }
        .kpi td { width: 25%; }
        .badge-critical { color: #b91c1c; font-weight: bold; }
    </style>
</head>
<body>
    <?php
    $summary = isset($summary) && is_array($summary) ? $summary : array();
    $technician_performance = isset($technician_performance) && is_array($technician_performance) ? $technician_performance : array();
    $top_customers = isset($top_customers) && is_array($top_customers) ? $top_customers : array();
    $filter_month = isset($filter_month) ? (int) $filter_month : (int) date('m');
    $filter_year = isset($filter_year) ? (int) $filter_year : (int) date('Y');
    $resolution_rate = isset($resolution_rate) ? (float) $resolution_rate : 0;
    ?>
    <div class="header">
        <h1>RTRWNet - Helpdesk Dashboard</h1>
        <div class="subtitle">Periode: <?php echo date('F', mktime(0, 0, 0, $filter_month, 1)); ?> <?php echo $filter_year; ?> | Generated: <?php echo date('d-m-Y H:i:s'); ?></div>
    </div>

    <table class="grid kpi">
        <tr>
            <th>Total Ticket</th>
            <th>Open</th>
            <th>In Progress</th>
            <th>Resolved</th>
        </tr>
        <tr>
            <td><?php echo number_format((int) ($summary['total_ticket'] ?? 0), 0, ',', '.'); ?></td>
            <td><?php echo number_format((int) ($summary['open'] ?? 0), 0, ',', '.'); ?></td>
            <td><?php echo number_format((int) ($summary['in_progress'] ?? 0), 0, ',', '.'); ?></td>
            <td><?php echo number_format((int) ($summary['resolved'] ?? 0), 0, ',', '.'); ?></td>
        </tr>
        <tr>
            <th>Closed</th>
            <th>Cancelled</th>
            <th>Critical</th>
            <th>Resolution Rate</th>
        </tr>
        <tr>
            <td><?php echo number_format((int) ($summary['closed'] ?? 0), 0, ',', '.'); ?></td>
            <td><?php echo number_format((int) ($summary['cancelled'] ?? 0), 0, ',', '.'); ?></td>
            <td class="badge-critical"><?php echo number_format((int) ($summary['critical'] ?? 0), 0, ',', '.'); ?></td>
            <td><?php echo number_format($resolution_rate, 2, ',', '.'); ?>%</td>
        </tr>
    </table>

    <h3 style="margin-top:16px;">Technician Performance</h3>
    <table class="grid">
        <tr>
            <th>Teknisi</th>
            <th>Resolved Ticket</th>
            <th>Avg Resolve (minute)</th>
        </tr>
        <?php if (empty($technician_performance)): ?>
        <tr><td colspan="3">Tidak ada data.</td></tr>
        <?php else: ?>
            <?php foreach ($technician_performance as $tech): ?>
            <tr>
                <td><?php echo html_escape((string) ($tech['technician_name'] ?? '-')); ?></td>
                <td><?php echo number_format((int) ($tech['resolved_total'] ?? 0), 0, ',', '.'); ?></td>
                <td><?php echo number_format((float) ($tech['avg_resolve_minutes'] ?? 0), 2, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <h3 style="margin-top:16px;">Top 5 Customer</h3>
    <table class="grid">
        <tr>
            <th>Customer</th>
            <th>Total Ticket</th>
        </tr>
        <?php if (empty($top_customers)): ?>
        <tr><td colspan="2">Tidak ada data.</td></tr>
        <?php else: ?>
            <?php foreach ($top_customers as $customer): ?>
            <tr>
                <td><?php echo html_escape((string) ($customer['customer_name'] ?? '-')); ?></td>
                <td><?php echo number_format((int) ($customer['total_ticket'] ?? 0), 0, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
</body>
</html>
