<?php
$page_title = 'Dashboard Statistik - ' . app_name();
$page_heading = 'Dashboard Statistik';
$page_subheading = 'Ringkasan performa operasional ISP dalam satu layar.';
$active_menu = 'dashboard';

ob_start();
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3 reveal">
        <div class="card card-ui kpi-card">
            <div class="card-body">
                <div class="kpi-label">Total Customers</div>
                <div class="kpi-value">324</div>
                <small class="text-success">+12 bulan ini</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3 reveal delay-1">
        <div class="card card-ui kpi-card">
            <div class="card-body">
                <div class="kpi-label">MRR</div>
                <div class="kpi-value">Rp 96,8 Jt</div>
                <small class="text-success">+4,8%</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3 reveal delay-2">
        <div class="card card-ui kpi-card">
            <div class="card-body">
                <div class="kpi-label">Open WO</div>
                <div class="kpi-value">19</div>
                <small class="text-warning">5 overdue</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3 reveal delay-3">
        <div class="card card-ui kpi-card">
            <div class="card-body">
                <div class="kpi-label">Overdue Invoice</div>
                <div class="kpi-value">27</div>
                <small class="text-danger">Rp 8,9 Jt</small>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8 reveal">
        <div class="card card-ui">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Revenue Trend (12 bulan)</span>
                <span class="badge rounded-pill badge-soft">Cash Basis</span>
            </div>
            <div class="card-body chart-wrap">
                <canvas id="dashboardRevenueChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4 reveal delay-1">
        <div class="card card-ui">
            <div class="card-header">Customer Composition</div>
            <div class="card-body chart-wrap">
                <canvas id="dashboardCustomerChart"></canvas>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

$page_scripts = <<<'SCRIPT'
<script>
(function () {
    const lineCtx = document.getElementById('dashboardRevenueChart');
    if (lineCtx) {
        new Chart(lineCtx, {
            type: 'line',
            data: {
                labels: ['Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des', 'Jan', 'Feb'],
                datasets: [{
                    label: 'Revenue',
                    data: [72, 75, 74, 79, 81, 83, 85, 88, 90, 92, 94, 97],
                    borderColor: '#1d4ed8',
                    backgroundColor: 'rgba(29,78,216,0.16)',
                    fill: true,
                    tension: 0.32,
                    borderWidth: 2.5
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        ticks: {
                            callback: (v) => 'Rp ' + v + ' Jt'
                        }
                    }
                }
            }
        });
    }

    const donutCtx = document.getElementById('dashboardCustomerChart');
    if (donutCtx) {
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Isolated', 'Waiting Install'],
                datasets: [{
                    data: [288, 21, 15],
                    backgroundColor: ['#0f766e', '#f97316', '#1d4ed8'],
                    borderWidth: 0
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                cutout: '62%'
            }
        });
    }
})();
</script>
SCRIPT;

include APPPATH . 'views/layouts/master.php';
