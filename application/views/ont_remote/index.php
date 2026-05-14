<?php
$page_title = 'Remote ONT - ' . app_name();
$page_heading = 'Remote ONT (TR-069)';
$page_subheading = 'Kelola WiFi ONT, reboot perangkat, dan lihat connected devices.';
$active_menu = 'ont_remote';

$customer_options = isset($customer_options) && is_array($customer_options) ? $customer_options : array();
$has_ont_columns = isset($has_ont_columns) ? (bool) $has_ont_columns : false;
$csrf_name = isset($csrf_name) ? (string) $csrf_name : $this->security->get_csrf_token_name();
$csrf_hash = isset($csrf_hash) ? (string) $csrf_hash : $this->security->get_csrf_hash();

ob_start();
?>
<div id="ontRemoteConfig"
     data-detail-url="<?php echo html_escape(site_url('ont-remote/detail')); ?>"
     data-set-wifi-url="<?php echo html_escape(site_url('ont-remote/set-wifi')); ?>"
     data-reboot-url="<?php echo html_escape(site_url('ont-remote/reboot')); ?>"
     data-devices-url="<?php echo html_escape(site_url('ont-remote/connected-devices')); ?>"
     data-summary-url="<?php echo html_escape(site_url('ont-remote/summary')); ?>"></div>

<input type="hidden" id="ontCsrfName" value="<?php echo html_escape($csrf_name); ?>">
<input type="hidden" id="ontCsrfHash" value="<?php echo html_escape($csrf_hash); ?>">

<?php if (!$has_ont_columns): ?>
<div class="alert alert-warning border-warning-subtle">
    Kolom TR-069 pada tabel <code>customers</code> belum lengkap. Pastikan kolom
    <code>ont_device_id</code> dan <code>tr069_profile</code> sudah ada.
</div>
<?php endif; ?>

<style>
.ont-chart-wrap {
    position: relative;
    height: 260px;
}
@media (max-width: 991.98px) {
    .ont-chart-wrap {
        height: 220px;
    }
}
</style>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold">Graph ONT Summary</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="btnRefreshOntGraph">
            <i class="bi bi-arrow-repeat me-1"></i>Refresh Graph
        </button>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small">Total Customer</div>
                    <div class="h4 mb-1" id="ontStatTotalCustomers">0</div>
                    <div class="small text-muted">Semua customer aktif data.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small">ONT Terdaftar</div>
                    <div class="h4 mb-1 text-primary" id="ontStatRegistered">0</div>
                    <div class="small text-muted">Coverage: <span id="ontStatCoverage">0.00%</span></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="text-muted small">Ready Remote</div>
                    <div class="h4 mb-1 text-success" id="ontStatReady">0</div>
                    <div class="small text-muted">Readiness: <span id="ontStatReadyPercent">0.00%</span></div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-4">
                <div class="border rounded p-2 h-100">
                    <div class="small text-muted px-2 py-1">TR-069 Profile</div>
                    <div class="ont-chart-wrap">
                        <canvas id="chartOntProfile"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-xl-8">
                <div class="border rounded p-2 h-100">
                    <div class="small text-muted px-2 py-1">Top Model ONT</div>
                    <div class="ont-chart-wrap">
                        <canvas id="chartOntModel"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Pilih Customer ONT</div>
            <div class="card-body">
                <label for="customerId" class="form-label fw-semibold">Customer</label>
                <select id="customerId" class="form-select">
                    <option value="">-- Pilih customer --</option>
                    <?php foreach ($customer_options as $item): ?>
                        <option value="<?php echo (int) ($item['id'] ?? 0); ?>">
                            <?php echo html_escape((string) ($item['label'] ?? '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">
                    Menampilkan customer yang sudah memiliki <code>ont_device_id</code>.
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="button" class="btn btn-primary" id="btnLoadDetail">
                        <i class="bi bi-search me-1"></i>Muat Detail
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="btnLoadDevices">
                        <i class="bi bi-hdd-network me-1"></i>Connected Devices
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Detail ONT</div>
            <div class="card-body">
                <div class="row g-3 small">
                    <div class="col-md-6">
                        <div class="text-muted">Customer</div>
                        <div class="fw-semibold" id="ontCustomerName">-</div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted">Customer ID</div>
                        <div class="fw-semibold" id="ontCustomerId">-</div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted">Device ID</div>
                        <div class="fw-semibold text-break" id="ontDeviceId">-</div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted">Serial</div>
                        <div class="fw-semibold" id="ontSerial">-</div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted">Model</div>
                        <div class="fw-semibold" id="ontModel">-</div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted">TR069 Profile</div>
                        <div class="fw-semibold text-uppercase" id="ontProfile">-</div>
                    </div>
                </div>
                <hr>
                <div id="ontResult" class="text-muted">Belum ada aksi dijalankan.</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Set WiFi ONT</div>
            <div class="card-body">
                <form id="formSetWifi">
                    <div class="mb-3">
                        <label for="wifiSsid" class="form-label">SSID</label>
                        <input type="text" id="wifiSsid" class="form-control" placeholder="Contoh: RTRWNet-Home" required>
                    </div>
                    <div class="mb-3">
                        <label for="wifiPassword" class="form-label">Password WiFi</label>
                        <input type="text" id="wifiPassword" class="form-control" placeholder="Minimal 8 karakter" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-wifi me-1"></i>Update WiFi + Reboot
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Aksi Cepat ONT</div>
            <div class="card-body">
                <button type="button" class="btn btn-outline-danger mb-3" id="btnRebootOnt">
                    <i class="bi bi-arrow-repeat me-1"></i>Reboot ONT
                </button>

                <div class="table-responsive border rounded">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Hostname</th>
                                <th>MAC</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody id="connectedDevicesBody">
                            <tr>
                                <td colspan="3" class="text-muted text-center py-3">Belum ada data connected devices.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

$page_scripts = <<<'SCRIPT'
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var configEl = document.getElementById('ontRemoteConfig');
    if (!configEl) {
        return;
    }

    var urls = {
        detail: configEl.getAttribute('data-detail-url') || '',
        setWifi: configEl.getAttribute('data-set-wifi-url') || '',
        reboot: configEl.getAttribute('data-reboot-url') || '',
        devices: configEl.getAttribute('data-devices-url') || '',
        summary: configEl.getAttribute('data-summary-url') || ''
    };

    var customerSelect = document.getElementById('customerId');
    var btnLoadDetail = document.getElementById('btnLoadDetail');
    var btnLoadDevices = document.getElementById('btnLoadDevices');
    var btnRebootOnt = document.getElementById('btnRebootOnt');
    var formSetWifi = document.getElementById('formSetWifi');
    var wifiSsid = document.getElementById('wifiSsid');
    var wifiPassword = document.getElementById('wifiPassword');
    var resultBox = document.getElementById('ontResult');
    var devicesBody = document.getElementById('connectedDevicesBody');
    var csrfNameEl = document.getElementById('ontCsrfName');
    var csrfHashEl = document.getElementById('ontCsrfHash');
    var btnRefreshOntGraph = document.getElementById('btnRefreshOntGraph');

    var statTotalCustomers = document.getElementById('ontStatTotalCustomers');
    var statRegistered = document.getElementById('ontStatRegistered');
    var statReady = document.getElementById('ontStatReady');
    var statCoverage = document.getElementById('ontStatCoverage');
    var statReadyPercent = document.getElementById('ontStatReadyPercent');

    var chartProfileEl = document.getElementById('chartOntProfile');
    var chartModelEl = document.getElementById('chartOntModel');
    var chartOntProfile = null;
    var chartOntModel = null;

    var detailFields = {
        customerName: document.getElementById('ontCustomerName'),
        customerId: document.getElementById('ontCustomerId'),
        deviceId: document.getElementById('ontDeviceId'),
        serial: document.getElementById('ontSerial'),
        model: document.getElementById('ontModel'),
        profile: document.getElementById('ontProfile')
    };

    function getCustomerId() {
        var id = parseInt(customerSelect ? customerSelect.value : '0', 10);
        return Number.isFinite(id) && id > 0 ? id : 0;
    }

    function getCsrfPair() {
        return {
            name: csrfNameEl ? csrfNameEl.value : '',
            hash: csrfHashEl ? csrfHashEl.value : ''
        };
    }

    function updateCsrfFromResponse(json) {
        if (!json || !json.csrf_name || !json.csrf_hash) {
            return;
        }
        if (csrfNameEl) {
            csrfNameEl.value = json.csrf_name;
        }
        if (csrfHashEl) {
            csrfHashEl.value = json.csrf_hash;
        }
    }

    function setResult(success, message) {
        if (!resultBox) {
            return;
        }
        resultBox.className = success ? 'text-success' : 'text-danger';
        resultBox.textContent = message || (success ? 'Berhasil.' : 'Gagal.');
    }

    function setDetail(data) {
        data = data || {};
        if (detailFields.customerName) detailFields.customerName.textContent = data.customer_name || '-';
        if (detailFields.customerId) detailFields.customerId.textContent = data.id || '-';
        if (detailFields.deviceId) detailFields.deviceId.textContent = data.ont_device_id || '-';
        if (detailFields.serial) detailFields.serial.textContent = data.ont_serial || '-';
        if (detailFields.model) detailFields.model.textContent = data.ont_model || '-';
        if (detailFields.profile) detailFields.profile.textContent = data.tr069_profile || '-';
    }

    function setDevicesRows(hosts) {
        if (!devicesBody) {
            return;
        }
        if (!Array.isArray(hosts) || hosts.length < 1) {
            devicesBody.innerHTML = '<tr><td colspan="3" class="text-muted text-center py-3">Tidak ada perangkat terhubung.</td></tr>';
            return;
        }

        var rows = hosts.map(function (host) {
            host = host || {};
            var hostname = host.hostname || '-';
            var mac = host.mac || '-';
            var ip = host.ip || '-';
            return '<tr><td>' + escapeHtml(hostname) + '</td><td>' + escapeHtml(mac) + '</td><td>' + escapeHtml(ip) + '</td></tr>';
        });
        devicesBody.innerHTML = rows.join('');
    }

    function escapeHtml(str) {
        var p = document.createElement('p');
        p.textContent = str == null ? '' : String(str);
        return p.innerHTML;
    }

    function showError(message) {
        Swal.fire({
            icon: 'error',
            title: 'Gagal',
            text: message || 'Terjadi kesalahan.'
        });
    }

    function setSummaryCardValue(el, value) {
        if (!el) {
            return;
        }
        el.textContent = value == null ? '0' : String(value);
    }

    function renderProfileChart(rows) {
        if (!chartProfileEl || typeof Chart === 'undefined') {
            return;
        }

        var labels = [];
        var values = [];
        var totalValue = 0;
        (rows || []).forEach(function (row) {
            if (!row) {
                return;
            }
            var val = parseInt(row.total || 0, 10) || 0;
            labels.push(row.label || row.key || 'UNSET');
            values.push(val);
            totalValue += val;
        });

        if (labels.length === 0 || totalValue <= 0) {
            labels = ['NO DATA'];
            values = [1];
        }

        if (chartOntProfile) {
            chartOntProfile.destroy();
        }

        chartOntProfile = new Chart(chartProfileEl.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: labels[0] === 'NO DATA'
                        ? ['#cbd5e1']
                        : ['#2563eb', '#16a34a', '#f59e0b', '#ef4444', '#7c3aed', '#0ea5e9', '#64748b'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    function renderModelChart(rows) {
        if (!chartModelEl || typeof Chart === 'undefined') {
            return;
        }

        var labels = [];
        var values = [];
        var totalValue = 0;
        (rows || []).forEach(function (row) {
            if (!row) {
                return;
            }
            var val = parseInt(row.total || 0, 10) || 0;
            labels.push(row.model || 'Unknown');
            values.push(val);
            totalValue += val;
        });

        if (labels.length === 0 || totalValue <= 0) {
            labels = ['NO DATA'];
            values = [1];
        }

        if (chartOntModel) {
            chartOntModel.destroy();
        }

        chartOntModel = new Chart(chartModelEl.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Jumlah ONT',
                    data: values,
                    backgroundColor: labels[0] === 'NO DATA' ? '#cbd5e1' : '#2563eb'
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    function loadSummary(silentMode) {
        if (!urls.summary) {
            return;
        }

        fetch(urls.summary, { method: 'GET' })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                updateCsrfFromResponse(json);
                if (!json || !json.success) {
                    throw new Error((json && json.message) ? json.message : 'Summary ONT gagal dimuat.');
                }

                var data = json.data || {};
                setSummaryCardValue(statTotalCustomers, data.total_customers || 0);
                setSummaryCardValue(statRegistered, data.total_ont_registered || 0);
                setSummaryCardValue(statReady, data.total_ready_remote || 0);
                var coverage = Number(data.coverage_percent || 0);
                var readiness = Number(data.ready_percent || 0);
                setSummaryCardValue(statCoverage, (isNaN(coverage) ? 0 : coverage).toFixed(2) + '%');
                setSummaryCardValue(statReadyPercent, (isNaN(readiness) ? 0 : readiness).toFixed(2) + '%');

                renderProfileChart(Array.isArray(data.profile_breakdown) ? data.profile_breakdown : []);
                renderModelChart(Array.isArray(data.model_breakdown) ? data.model_breakdown : []);
                if (Number(data.total_ont_registered || 0) <= 0) {
                    setResult(true, 'Belum ada ONT terdaftar. Isi kolom ont_device_id customer agar grafik menampilkan data real.');
                } else if (!silentMode) {
                    setResult(true, json.message || 'Summary ONT berhasil dimuat.');
                }
            })
            .catch(function (err) {
                var msg = (err && err.message) ? err.message : 'Summary ONT gagal dimuat.';
                setResult(false, msg);
                if (!silentMode) {
                    showError(msg);
                }
            });
    }

    function requestDetail() {
        var customerId = getCustomerId();
        if (customerId <= 0) {
            showError('Pilih customer ONT terlebih dahulu.');
            return;
        }

        fetch(urls.detail + '?customer_id=' + encodeURIComponent(customerId), {
            method: 'GET'
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            updateCsrfFromResponse(json);
            if (!json || !json.success) {
                throw new Error((json && json.message) ? json.message : 'Detail ONT gagal dimuat.');
            }
            setDetail(json.data || {});
            setResult(true, json.message || 'Detail ONT berhasil dimuat.');
        })
        .catch(function (err) {
            var msg = (err && err.message) ? err.message : 'Detail ONT gagal dimuat.';
            setResult(false, msg);
            showError(msg);
        });
    }

    function requestConnectedDevices() {
        var customerId = getCustomerId();
        if (customerId <= 0) {
            showError('Pilih customer ONT terlebih dahulu.');
            return;
        }

        fetch(urls.devices + '?customer_id=' + encodeURIComponent(customerId), {
            method: 'GET'
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            updateCsrfFromResponse(json);
            if (!json || !json.success) {
                throw new Error((json && json.message) ? json.message : 'Connected devices gagal dimuat.');
            }
            var hosts = (json.data && Array.isArray(json.data.hosts)) ? json.data.hosts : [];
            setDevicesRows(hosts);
            setResult(true, json.message || 'Connected devices berhasil dimuat.');
        })
        .catch(function (err) {
            var msg = (err && err.message) ? err.message : 'Connected devices gagal dimuat.';
            setResult(false, msg);
            showError(msg);
        });
    }

    function requestPost(url, extraData) {
        var customerId = getCustomerId();
        if (customerId <= 0) {
            showError('Pilih customer ONT terlebih dahulu.');
            return Promise.reject(new Error('customer_id kosong'));
        }

        var csrf = getCsrfPair();
        var body = new URLSearchParams();
        body.append('customer_id', customerId);
        if (csrf.name && csrf.hash) {
            body.append(csrf.name, csrf.hash);
        }
        Object.keys(extraData || {}).forEach(function (key) {
            body.append(key, extraData[key]);
        });

        return fetch(url, {
            method: 'POST',
            body: body
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            updateCsrfFromResponse(json);
            if (!json || !json.success) {
                throw new Error((json && json.message) ? json.message : 'Request gagal.');
            }
            return json;
        });
    }

    if (btnLoadDetail) {
        btnLoadDetail.addEventListener('click', requestDetail);
    }

    if (btnLoadDevices) {
        btnLoadDevices.addEventListener('click', requestConnectedDevices);
    }

    if (btnRebootOnt) {
        btnRebootOnt.addEventListener('click', function () {
            requestPost(urls.reboot, {})
                .then(function (json) {
                    setResult(true, json.message || 'Task reboot berhasil dikirim.');
                    Swal.fire({icon: 'success', title: 'Berhasil', text: json.message || 'Task reboot berhasil dikirim.'});
                })
                .catch(function (err) {
                    var msg = (err && err.message) ? err.message : 'Reboot ONT gagal.';
                    setResult(false, msg);
                    showError(msg);
                });
        });
    }

    if (formSetWifi) {
        formSetWifi.addEventListener('submit', function (event) {
            event.preventDefault();
            var ssid = (wifiSsid && wifiSsid.value) ? wifiSsid.value.trim() : '';
            var pass = (wifiPassword && wifiPassword.value) ? wifiPassword.value.trim() : '';

            if (!ssid || !pass) {
                showError('SSID dan password WiFi wajib diisi.');
                return;
            }
            if (pass.length < 8) {
                showError('Password WiFi minimal 8 karakter.');
                return;
            }

            requestPost(urls.setWifi, { ssid: ssid, password: pass })
                .then(function (json) {
                    setResult(true, json.message || 'WiFi ONT berhasil diupdate.');
                    Swal.fire({icon: 'success', title: 'Berhasil', text: json.message || 'WiFi ONT berhasil diupdate.'});
                })
                .catch(function (err) {
                    var msg = (err && err.message) ? err.message : 'Update WiFi gagal.';
                    setResult(false, msg);
                    showError(msg);
                });
        });
    }

    if (btnRefreshOntGraph) {
        btnRefreshOntGraph.addEventListener('click', function () {
            loadSummary(false);
        });
    }

    loadSummary(true);
});
</script>
SCRIPT;

include APPPATH . 'views/layout/master.php';
