<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Revenue Analytics</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #1f2937; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; background: #fff; }
        .card h3 { margin: 0 0 10px; font-size: 16px; }
        .kpi { font-size: 24px; font-weight: 700; }
        canvas { width: 100% !important; height: 320px !important; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { border-bottom: 1px solid #e5e7eb; text-align: left; padding: 8px; font-size: 13px; }
        @media (max-width: 900px) { .row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <h2>Revenue Analytics (Cash Basis by paid_date)</h2>
    <p>Range: <?php echo html_escape($start_date); ?> s/d <?php echo html_escape($end_date); ?></p>

    <div class="row">
        <div class="card">
            <h3>Total Revenue</h3>
            <div class="kpi">Rp <?php echo number_format((float) $summary['total_revenue'], 0, ',', '.'); ?></div>
        </div>
        <div class="card">
            <h3>Average ARPU</h3>
            <div class="kpi">Rp <?php echo number_format((float) $summary['average_arpu'], 0, ',', '.'); ?></div>
        </div>
    </div>

    <div class="row">
        <div class="card">
            <h3>Revenue per Month (Line)</h3>
            <canvas id="revenueLineChart"></canvas>
        </div>
        <div class="card">
            <h3>Revenue per Month (Bar)</h3>
            <canvas id="revenueBarChart"></canvas>
        </div>
    </div>

    <div class="row">
        <div class="card">
            <h3>ARPU per Month</h3>
            <canvas id="arpuLineChart"></canvas>
        </div>
        <div class="card">
            <h3>Revenue per Package</h3>
            <canvas id="packageBarChart"></canvas>
        </div>
    </div>

    <div class="card">
        <h3>Raw Data - Monthly Revenue</h3>
        <table>
            <thead>
            <tr>
                <th>Month</th>
                <th>Revenue</th>
                <th>Paying Customers</th>
                <th>ARPU</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($monthly_arpu as $i => $row): ?>
                <tr>
                    <td><?php echo html_escape($row['label']); ?></td>
                    <td>Rp <?php echo number_format((float) $monthly_revenue[$i]['revenue'], 0, ',', '.'); ?></td>
                    <td><?php echo (int) $row['paying_customers']; ?></td>
                    <td>Rp <?php echo number_format((float) $row['arpu'], 0, ',', '.'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const chartData = <?php echo json_encode($chart_data, JSON_UNESCAPED_UNICODE); ?>;

        const formatRupiah = (value) => 'Rp ' + Number(value || 0).toLocaleString('id-ID');

        new Chart(document.getElementById('revenueLineChart'), {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Revenue',
                    data: chartData.revenue,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,0.15)',
                    tension: 0.25,
                    fill: true
                }]
            },
            options: {
                plugins: { tooltip: { callbacks: { label: (ctx) => formatRupiah(ctx.raw) } } },
                scales: { y: { ticks: { callback: (v) => formatRupiah(v) } } }
            }
        });

        new Chart(document.getElementById('revenueBarChart'), {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Revenue',
                    data: chartData.revenue,
                    backgroundColor: '#16a34a'
                }]
            },
            options: {
                plugins: { tooltip: { callbacks: { label: (ctx) => formatRupiah(ctx.raw) } } },
                scales: { y: { ticks: { callback: (v) => formatRupiah(v) } } }
            }
        });

        new Chart(document.getElementById('arpuLineChart'), {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'ARPU',
                    data: chartData.arpu,
                    borderColor: '#9333ea',
                    backgroundColor: 'rgba(147,51,234,0.14)',
                    tension: 0.25,
                    fill: true
                }]
            },
            options: {
                plugins: { tooltip: { callbacks: { label: (ctx) => formatRupiah(ctx.raw) } } },
                scales: { y: { ticks: { callback: (v) => formatRupiah(v) } } }
            }
        });

        new Chart(document.getElementById('packageBarChart'), {
            type: 'bar',
            data: {
                labels: chartData.package_labels,
                datasets: [{
                    label: 'Revenue per Package',
                    data: chartData.package_revenue,
                    backgroundColor: '#f59e0b'
                }]
            },
            options: {
                indexAxis: 'y',
                plugins: { tooltip: { callbacks: { label: (ctx) => formatRupiah(ctx.raw) } } },
                scales: { x: { ticks: { callback: (v) => formatRupiah(v) } } }
            }
        });
    </script>
</body>
</html>
