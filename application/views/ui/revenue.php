<?php
$page_title = 'Revenue Chart - ' . app_name();
$page_heading = 'Revenue Chart';
$page_subheading = 'Performa revenue bulanan, ARPU, dan kontribusi paket layanan.';
$active_menu = 'revenue';

ob_start();
?>
<div class="row g-3 mb-3">
    <div class="col-md-4 reveal">
        <div class="card card-ui kpi-card"><div class="card-body"><div class="kpi-label">Total Revenue</div><div class="kpi-value">Rp 1,09 M</div></div></div>
    </div>
    <div class="col-md-4 reveal delay-1">
        <div class="card card-ui kpi-card"><div class="card-body"><div class="kpi-label">ARPU Rata-rata</div><div class="kpi-value">Rp 299 Rb</div></div></div>
    </div>
    <div class="col-md-4 reveal delay-2">
        <div class="card card-ui kpi-card"><div class="card-body"><div class="kpi-label">Growth YoY</div><div class="kpi-value">+14.2%</div></div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8 reveal">
        <div class="card card-ui">
            <div class="card-header">Revenue by Month (Line)</div>
            <div class="card-body chart-wrap"><canvas id="revenueLineChart"></canvas></div>
        </div>
    </div>
    <div class="col-xl-4 reveal delay-1">
        <div class="card card-ui">
            <div class="card-header">Revenue per Package (Bar)</div>
            <div class="card-body chart-wrap"><canvas id="revenuePackageChart"></canvas></div>
        </div>
    </div>
    <div class="col-12 reveal delay-2">
        <div class="card card-ui">
            <div class="card-header">ARPU vs Paying Customers</div>
            <div class="card-body chart-wrap"><canvas id="revenueArpuChart"></canvas></div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

$page_scripts = <<<'SCRIPT'
<script>
(function () {
    const labels = ['Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des', 'Jan', 'Feb'];

    new Chart(document.getElementById('revenueLineChart'), {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Revenue (Juta Rupiah)',
                data: [72, 75, 74, 79, 81, 83, 85, 88, 90, 92, 94, 97],
                borderColor: '#0f766e',
                backgroundColor: 'rgba(15,118,110,0.16)',
                fill: true,
                tension: 0.28
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        }
    });

    new Chart(document.getElementById('revenuePackageChart'), {
        type: 'bar',
        data: {
            labels: ['10 Mbps', '20 Mbps', '30 Mbps', '50 Mbps'],
            datasets: [{
                label: 'Revenue',
                data: [188, 346, 214, 141],
                backgroundColor: ['#1d4ed8', '#0f766e', '#f97316', '#334155']
            }]
        },
        options: {
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { legend: { display: false } }
        }
    });

    new Chart(document.getElementById('revenueArpuChart'), {
        data: {
            labels,
            datasets: [{
                type: 'line',
                label: 'ARPU (Ribu)',
                data: [262, 266, 261, 273, 278, 281, 286, 290, 292, 294, 297, 301],
                borderColor: '#f97316',
                backgroundColor: '#f97316',
                yAxisID: 'y',
                tension: 0.3
            }, {
                type: 'bar',
                label: 'Paying Customers',
                data: [275, 282, 281, 289, 292, 296, 299, 304, 307, 312, 317, 321],
                backgroundColor: 'rgba(29,78,216,0.35)',
                yAxisID: 'y1'
            }]
        },
        options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { position: 'left' },
                y1: { position: 'right', grid: { drawOnChartArea: false } }
            }
        }
    });
})();
</script>
SCRIPT;

include APPPATH . 'views/layouts/master.php';
