<?php
$page_title = 'Cashflow - ' . app_name();
$page_heading = 'Cashflow';
$page_subheading = 'Income vs expense bulanan dan net profit berbasis cash movement.';
$active_menu = 'cashflow';

$txRows = [
    ['2026-02-01', 'income', 'subscription', 'Pembayaran INV-2026-0201', 350000],
    ['2026-02-02', 'expense', 'operational', 'Beli kabel fiber', 800000],
    ['2026-02-03', 'income', 'subscription', 'Pembayaran INV-2026-0202', 280000],
    ['2026-02-05', 'expense', 'maintenance', 'Service OLT rack', 1200000],
    ['2026-02-07', 'income', 'installation', 'Biaya instalasi customer baru', 500000],
];

ob_start();
?>
<div class="row g-3 mb-3">
    <div class="col-md-4 reveal">
        <div class="card card-ui kpi-card"><div class="card-body"><div class="kpi-label">Total Income</div><div class="kpi-value">Rp 128,4 Jt</div></div></div>
    </div>
    <div class="col-md-4 reveal delay-1">
        <div class="card card-ui kpi-card"><div class="card-body"><div class="kpi-label">Total Expense</div><div class="kpi-value">Rp 54,1 Jt</div></div></div>
    </div>
    <div class="col-md-4 reveal delay-2">
        <div class="card card-ui kpi-card"><div class="card-body"><div class="kpi-label">Net Profit</div><div class="kpi-value">Rp 74,3 Jt</div></div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-7 reveal">
        <div class="card card-ui">
            <div class="card-header">Monthly Cashflow</div>
            <div class="card-body chart-wrap"><canvas id="cashflowMonthlyChart"></canvas></div>
        </div>
    </div>
    <div class="col-xl-5 reveal delay-1">
        <div class="card card-ui">
            <div class="card-header">Expense by Category</div>
            <div class="card-body chart-wrap"><canvas id="cashflowExpenseChart"></canvas></div>
        </div>
    </div>
    <div class="col-12 reveal delay-2">
        <div class="card card-ui">
            <div class="card-header">Latest Transactions</div>
            <div class="card-body table-scroll p-0">
                <table class="table table-ui mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Date</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th class="pe-3">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($txRows as $r): ?>
                        <tr>
                            <td class="ps-3"><?php echo $r[0]; ?></td>
                            <td>
                                <?php if ($r[1] === 'income'): ?>
                                <span class="badge text-bg-success">INCOME</span>
                                <?php else: ?>
                                <span class="badge text-bg-danger">EXPENSE</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo strtoupper($r[2]); ?></td>
                            <td><?php echo $r[3]; ?></td>
                            <td class="pe-3">Rp <?php echo number_format((float) $r[4], 0, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

$page_scripts = <<<'SCRIPT'
<script>
(function () {
    new Chart(document.getElementById('cashflowMonthlyChart'), {
        data: {
            labels: ['Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des', 'Jan', 'Feb'],
            datasets: [{
                type: 'bar',
                label: 'Income',
                data: [88, 91, 93, 96, 99, 103, 105, 108, 112, 117, 122, 128],
                backgroundColor: 'rgba(15,118,110,0.55)'
            }, {
                type: 'bar',
                label: 'Expense',
                data: [36, 35, 37, 40, 42, 43, 44, 46, 47, 49, 51, 54],
                backgroundColor: 'rgba(249,115,22,0.58)'
            }, {
                type: 'line',
                label: 'Net Profit',
                data: [52, 56, 56, 56, 57, 60, 61, 62, 65, 68, 71, 74],
                borderColor: '#1d4ed8',
                backgroundColor: '#1d4ed8',
                tension: 0.3,
                yAxisID: 'y'
            }]
        },
        options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false }
        }
    });

    new Chart(document.getElementById('cashflowExpenseChart'), {
        type: 'doughnut',
        data: {
            labels: ['Operational', 'Maintenance', 'Salary', 'Other'],
            datasets: [{
                data: [39, 28, 25, 8],
                backgroundColor: ['#f97316', '#ef4444', '#0f766e', '#64748b'],
                borderWidth: 0
            }]
        },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
})();
</script>
SCRIPT;

include APPPATH . 'views/layouts/master.php';
