<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Helpdesk Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        .head { margin-bottom: 12px; }
        .title { font-size: 18px; font-weight: bold; }
        .meta { color: #555; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; vertical-align: top; }
        th { background: #f1f3f5; text-align: left; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <?php
    $filters = isset($filters) && is_array($filters) ? $filters : array();
    $filter_month = (int) ($filters['month'] ?? 0);
    $filter_year = (int) ($filters['year'] ?? 0);
    $period_label = 'Semua Periode';
    if ($filter_month >= 1 && $filter_month <= 12 && $filter_year >= 2000) {
        $period_label = date('F Y', strtotime($filter_year . '-' . str_pad((string) $filter_month, 2, '0', STR_PAD_LEFT) . '-01'));
    }
    ?>
    <div class="head">
        <div class="title">RTRWNet - Helpdesk Report</div>
        <div class="meta">Generated at: <?php echo html_escape((string) ($generated_at ?? date('Y-m-d H:i:s'))); ?></div>
        <div class="meta">Periode: <?php echo html_escape($period_label); ?></div>
        <div class="meta">Filter: <?php echo html_escape(http_build_query((array) ($filters ?? array()))); ?></div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 13%;">Ticket</th>
                <th style="width: 15%;">Customer</th>
                <th style="width: 9%;">Area</th>
                <th style="width: 12%;">OLT</th>
                <th style="width: 24%;">Subject</th>
                <th style="width: 8%;">Priority</th>
                <th style="width: 9%;">Status</th>
                <th style="width: 10%;">SLA Deadline</th>
            </tr>
        </thead>
        <tbody>
            <?php $rows = isset($rows) && is_array($rows) ? $rows : array(); ?>
            <?php if (empty($rows)): ?>
            <tr>
                <td colspan="8" class="muted">Tidak ada data.</td>
            </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo html_escape((string) ($row['ticket_code'] ?? '-')); ?></td>
                    <td><?php echo html_escape((string) ($row['customer_name'] ?? '-')); ?></td>
                    <td><?php echo html_escape((string) ($row['customer_area'] ?? '-')); ?></td>
                    <td><?php echo html_escape((string) ($row['olt_name'] ?? '-')); ?></td>
                    <td><?php echo html_escape((string) ($row['subject'] ?? '-')); ?></td>
                    <td><?php echo html_escape((string) ($row['priority'] ?? '-')); ?></td>
                    <td><?php echo html_escape((string) ($row['status'] ?? '-')); ?></td>
                    <td><?php echo !empty($row['sla_deadline']) ? html_escape(date('d-m-Y H:i', strtotime((string) $row['sla_deadline']))) : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
