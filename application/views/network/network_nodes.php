<?php
$routers = isset($routers) && is_array($routers) ? $routers : array();
$csrf_name = isset($csrf_name) ? (string) $csrf_name : $this->security->get_csrf_token_name();
$csrf_hash = isset($csrf_hash) ? (string) $csrf_hash : $this->security->get_csrf_hash();
$api_map_url = isset($api_map_url) ? (string) $api_map_url : site_url('api/network/map');
$api_create_odc_url = isset($api_create_odc_url) ? (string) $api_create_odc_url : site_url('api/network/odc/create');
$api_update_odc_url = isset($api_update_odc_url) ? (string) $api_update_odc_url : site_url('api/network/odc/update');
$api_delete_odc_url = isset($api_delete_odc_url) ? (string) $api_delete_odc_url : site_url('api/network/odc/delete');
$api_create_odp_url = isset($api_create_odp_url) ? (string) $api_create_odp_url : site_url('api/network/odp/create');
$api_update_odp_url = isset($api_update_odp_url) ? (string) $api_update_odp_url : site_url('api/network/odp/update');
$api_delete_odp_url = isset($api_delete_odp_url) ? (string) $api_delete_odp_url : site_url('api/network/odp/delete');

$page_title = 'Manajemen ODP/ODC - ' . app_name();
$page_heading = 'Manajemen ODP / ODC';
$page_subheading = 'Kelola data ODP dan ODC di menu Network. Halaman map hanya untuk visualisasi.';
$active_menu = 'network_nodes';

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Manajemen ODP / ODC</span>
        <a href="<?php echo site_url('network/fiber-network-map'); ?>" class="btn btn-sm btn-outline-primary">
            <i class="ti ti-map-2 me-1"></i>Buka Fiber Network Map
        </a>
    </div>
    <div class="card-body">
        <input type="hidden" id="networkNodesCsrfName" value="<?php echo html_escape($csrf_name); ?>">
        <input type="hidden" id="networkNodesCsrfHash" value="<?php echo html_escape($csrf_hash); ?>">

        <div class="row g-3 mb-3">
            <div class="col-lg-4 col-md-6">
                <label class="form-label">Filter Router</label>
                <select id="routerFilter" class="form-select" data-searchable="1">
                    <option value="0">Semua Router</option>
                    <?php foreach ($routers as $router): ?>
                    <?php $router_id = (int) ($router['id'] ?? 0); ?>
                    <?php if ($router_id <= 0) { continue; } ?>
                    <option value="<?php echo $router_id; ?>">
                        <?php echo html_escape((string) ($router['name'] ?? ('Router #' . $router_id))); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-8 col-md-6 d-flex align-items-end">
                <div id="networkNodesSummary" class="alert alert-light border mb-0 w-100">
                    Memuat data ODP/ODC...
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-6">
                <div class="card border h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Data ODC</span>
                        <button type="button" class="btn btn-sm btn-outline-info" id="btnAddOdc" data-bs-toggle="modal" data-bs-target="#odcModal">
                            <i class="ti ti-plus me-1"></i>Tambah ODC
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0" id="odcTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Nama</th>
                                        <th>Router</th>
                                        <th>OLT</th>
                                        <th>Port</th>
                                        <th>Koordinat</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="card border h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Data ODP</span>
                        <button type="button" class="btn btn-sm btn-primary" id="btnAddOdp" data-bs-toggle="modal" data-bs-target="#odpModal">
                            <i class="ti ti-plus me-1"></i>Tambah ODP
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0" id="odpTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Nama</th>
                                        <th>Router</th>
                                        <th>OLT/ODC</th>
                                        <th>PON</th>
                                        <th>Port</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="odcModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form id="odcForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="odcModalTitle">Tambah ODC</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="odcFormId" name="id" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Router</label>
                            <select class="form-select" name="router_id" id="odcRouterId" required></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">OLT</label>
                            <select class="form-select" name="olt_id" id="odcOltId">
                                <option value="">Pilih OLT</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nama ODC</label>
                            <input type="text" class="form-control" name="name" id="odcName" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Capacity</label>
                            <input type="number" class="form-control" name="capacity" id="odcCapacity" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Used Ports</label>
                            <input type="number" class="form-control" name="used_ports" id="odcUsedPorts" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Latitude</label>
                            <input type="text" class="form-control" name="latitude" id="odcLatitude" placeholder="-6.1234567">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Longitude</label>
                            <input type="text" class="form-control" name="longitude" id="odcLongitude" placeholder="106.1234567">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="odcDescription" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="odcSubmitBtn">Simpan ODC</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="odpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form id="odpForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="odpModalTitle">Tambah ODP</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="odpFormId" name="id" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Router</label>
                            <select class="form-select" name="router_id" id="odpRouterId" required></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">OLT</label>
                            <select class="form-select" name="olt_id" id="odpOltId">
                                <option value="">Pilih OLT</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ODC</label>
                            <select class="form-select" name="odc_id" id="odpOdcId">
                                <option value="">Pilih ODC</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nama ODP</label>
                            <input type="text" class="form-control" name="name" id="odpName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">PON Port</label>
                            <input type="text" class="form-control" name="pon_port" id="odpPonPort" placeholder="Contoh: PON1/1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Capacity</label>
                            <input type="number" class="form-control" name="capacity" id="odpCapacity" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Used Ports</label>
                            <input type="number" class="form-control" name="used_ports" id="odpUsedPorts" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Latitude</label>
                            <input type="text" class="form-control" name="latitude" id="odpLatitude" placeholder="-6.1234567">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Longitude</label>
                            <input type="text" class="form-control" name="longitude" id="odpLongitude" placeholder="106.1234567">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="odpDescription" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="odpSubmitBtn">Simpan ODP</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

$page_scripts = <<<'SCRIPT'
<style>
#odcTable tbody td,
#odpTable tbody td {
    vertical-align: middle;
}
.table-actions {
    white-space: nowrap;
}
.table-actions .btn {
    margin-left: .25rem;
}
</style>
<script>
(function () {
    const summaryEl = document.getElementById('networkNodesSummary');
    const routerFilterEl = document.getElementById('routerFilter');
    const csrfNameEl = document.getElementById('networkNodesCsrfName');
    const csrfHashEl = document.getElementById('networkNodesCsrfHash');

    const odcTableBody = document.querySelector('#odcTable tbody');
    const odpTableBody = document.querySelector('#odpTable tbody');

    const odcModalEl = document.getElementById('odcModal');
    const odcModalTitleEl = document.getElementById('odcModalTitle');
    const odcSubmitBtnEl = document.getElementById('odcSubmitBtn');
    const odcFormEl = document.getElementById('odcForm');
    const odcIdEl = document.getElementById('odcFormId');
    const odcRouterEl = document.getElementById('odcRouterId');
    const odcOltEl = document.getElementById('odcOltId');
    const odcNameEl = document.getElementById('odcName');
    const odcCapacityEl = document.getElementById('odcCapacity');
    const odcUsedEl = document.getElementById('odcUsedPorts');
    const odcLatEl = document.getElementById('odcLatitude');
    const odcLngEl = document.getElementById('odcLongitude');
    const odcDescEl = document.getElementById('odcDescription');

    const odpModalEl = document.getElementById('odpModal');
    const odpModalTitleEl = document.getElementById('odpModalTitle');
    const odpSubmitBtnEl = document.getElementById('odpSubmitBtn');
    const odpFormEl = document.getElementById('odpForm');
    const odpIdEl = document.getElementById('odpFormId');
    const odpRouterEl = document.getElementById('odpRouterId');
    const odpOltEl = document.getElementById('odpOltId');
    const odpOdcEl = document.getElementById('odpOdcId');
    const odpNameEl = document.getElementById('odpName');
    const odpPonEl = document.getElementById('odpPonPort');
    const odpCapacityEl = document.getElementById('odpCapacity');
    const odpUsedEl = document.getElementById('odpUsedPorts');
    const odpLatEl = document.getElementById('odpLatitude');
    const odpLngEl = document.getElementById('odpLongitude');
    const odpDescEl = document.getElementById('odpDescription');

    const odcModal = odcModalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(odcModalEl) : null;
    const odpModal = odpModalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(odpModalEl) : null;

    const config = {
        apiMapUrl: %API_MAP_URL%,
        apiCreateOdcUrl: %API_CREATE_ODC_URL%,
        apiUpdateOdcUrl: %API_UPDATE_ODC_URL%,
        apiDeleteOdcUrl: %API_DELETE_ODC_URL%,
        apiCreateOdpUrl: %API_CREATE_ODP_URL%,
        apiUpdateOdpUrl: %API_UPDATE_ODP_URL%,
        apiDeleteOdpUrl: %API_DELETE_ODP_URL%
    };

    const state = {
        routerId: 0,
        routers: [],
        olts: [],
        odcs: [],
        odps: [],
        routerMap: {},
        oltMap: {},
        odcMap: {},
        odpMap: {}
    };

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function hasLatLng(lat, lng) {
        const lt = Number(lat);
        const ln = Number(lng);
        return Number.isFinite(lt) && Number.isFinite(ln) && Math.abs(lt) <= 90 && Math.abs(ln) <= 180;
    }

    function setSummary(text, type) {
        if (!summaryEl) {
            return;
        }

        summaryEl.className = 'alert mb-0';
        if (type === 'error') {
            summaryEl.classList.add('alert-danger');
        } else if (type === 'success') {
            summaryEl.classList.add('alert-success');
        } else {
            summaryEl.classList.add('alert-light', 'border');
        }
        summaryEl.textContent = text;
    }

    function notifyError(message) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'error', title: 'Gagal', text: message || 'Terjadi kesalahan.' });
            return;
        }
        window.alert(message || 'Terjadi kesalahan.');
    }

    function notifySuccess(message) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'success', title: 'Berhasil', text: message || 'Data berhasil disimpan.' });
            return;
        }
        window.alert(message || 'Data berhasil disimpan.');
    }

    function getCsrf() {
        return {
            name: csrfNameEl ? csrfNameEl.value : '',
            hash: csrfHashEl ? csrfHashEl.value : ''
        };
    }

    function setCsrf(name, hash) {
        if (csrfNameEl && name) {
            csrfNameEl.value = name;
        }
        if (csrfHashEl && hash) {
            csrfHashEl.value = hash;
        }
    }

    function fillRouterOptions(selectEl, withPlaceholder, placeholderText) {
        if (!selectEl) {
            return;
        }

        const current = String(selectEl.value || '');
        let html = '';
        if (withPlaceholder) {
            html += '<option value="">' + esc(placeholderText || 'Pilih Router') + '</option>';
        }

        (state.routers || []).forEach(function (router) {
            html += '<option value="' + esc(router.id) + '">' + esc(router.name) + '</option>';
        });

        selectEl.innerHTML = html;

        if (current && selectEl.querySelector('option[value="' + current + '"]')) {
            selectEl.value = current;
        } else if (Number(state.routerId || 0) > 0 && selectEl.querySelector('option[value="' + String(state.routerId) + '"]')) {
            selectEl.value = String(state.routerId);
        }
    }

    function fillOltOptions(selectEl, routerId, withPlaceholder) {
        if (!selectEl) {
            return;
        }

        const current = String(selectEl.value || '');
        let html = '';
        if (withPlaceholder !== false) {
            html += '<option value="">Pilih OLT</option>';
        }

        (state.olts || []).forEach(function (olt) {
            if (Number(routerId || 0) > 0 && Number(olt.router_id || 0) !== Number(routerId || 0)) {
                return;
            }
            html += '<option value="' + esc(olt.id) + '">' + esc(olt.name) + '</option>';
        });

        selectEl.innerHTML = html;
        if (current && selectEl.querySelector('option[value="' + current + '"]')) {
            selectEl.value = current;
        }
    }

    function fillOdcOptions(routerId, oltId) {
        if (!odpOdcEl) {
            return;
        }

        const current = String(odpOdcEl.value || '');
        let html = '<option value="">Pilih ODC</option>';

        (state.odcs || []).forEach(function (odc) {
            const meta = odc.metadata || {};
            if (Number(routerId || 0) > 0 && Number(odc.router_id || 0) !== Number(routerId || 0)) {
                return;
            }
            if (Number(oltId || 0) > 0 && Number(meta.olt_id || 0) !== Number(oltId || 0)) {
                return;
            }
            html += '<option value="' + esc(odc.id) + '">' + esc(odc.name) + '</option>';
        });

        odpOdcEl.innerHTML = html;
        if (current && odpOdcEl.querySelector('option[value="' + current + '"]')) {
            odpOdcEl.value = current;
        }
    }

    function renderTables() {
        state.routerMap = {};
        state.oltMap = {};
        state.odcMap = {};
        state.odpMap = {};

        (state.routers || []).forEach(function (router) {
            state.routerMap[String(router.id)] = router;
        });
        (state.olts || []).forEach(function (olt) {
            state.oltMap[String(olt.id)] = olt;
        });
        (state.odcs || []).forEach(function (odc) {
            state.odcMap[String(odc.id)] = odc;
        });
        (state.odps || []).forEach(function (odp) {
            state.odpMap[String(odp.id)] = odp;
        });

        if (odcTableBody) {
            if (!state.odcs.length) {
                odcTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Belum ada data ODC.</td></tr>';
            } else {
                odcTableBody.innerHTML = state.odcs.map(function (odc) {
                    const meta = odc.metadata || {};
                    const routerName = (state.routerMap[String(odc.router_id)] || {}).name || ('Router #' + (odc.router_id || 0));
                    const coord = hasLatLng(odc.latitude, odc.longitude)
                        ? (String(odc.latitude) + ', ' + String(odc.longitude))
                        : '-';

                    return '<tr>' +
                        '<td>' + esc(odc.name || '-') + '</td>' +
                        '<td>' + esc(routerName) + '</td>' +
                        '<td>' + esc(meta.olt_name || '-') + '</td>' +
                        '<td>' + esc(String(meta.used_ports || 0) + '/' + String(meta.capacity || 0)) + '</td>' +
                        '<td class="small text-muted">' + esc(coord) + '</td>' +
                        '<td class="text-end table-actions">' +
                            '<button type="button" class="btn btn-sm btn-outline-primary js-edit-odc" data-id="' + esc(odc.id) + '">Edit</button>' +
                            '<button type="button" class="btn btn-sm btn-outline-danger js-delete-odc" data-id="' + esc(odc.id) + '" data-name="' + esc(odc.name || '') + '">Hapus</button>' +
                        '</td>' +
                    '</tr>';
                }).join('');
            }
        }

        if (odpTableBody) {
            if (!state.odps.length) {
                odpTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Belum ada data ODP.</td></tr>';
            } else {
                odpTableBody.innerHTML = state.odps.map(function (odp) {
                    const meta = odp.metadata || {};
                    const routerName = (state.routerMap[String(odp.router_id)] || {}).name || ('Router #' + (odp.router_id || 0));
                    return '<tr>' +
                        '<td>' + esc(odp.name || '-') + '</td>' +
                        '<td>' + esc(routerName) + '</td>' +
                        '<td>' + esc((meta.olt_name || '-') + ' / ' + (meta.odc_name || '-')) + '</td>' +
                        '<td>' + esc(meta.pon_port || '-') + '</td>' +
                        '<td>' + esc(String(meta.used_ports || 0) + '/' + String(meta.capacity || 0)) + '</td>' +
                        '<td class="text-end table-actions">' +
                            '<button type="button" class="btn btn-sm btn-outline-primary js-edit-odp" data-id="' + esc(odp.id) + '">Edit</button>' +
                            '<button type="button" class="btn btn-sm btn-outline-danger js-delete-odp" data-id="' + esc(odp.id) + '">Hapus</button>' +
                        '</td>' +
                    '</tr>';
                }).join('');
            }
        }

        setSummary(
            'Router: ' + state.routers.length +
            ', OLT: ' + state.olts.length +
            ', ODC: ' + state.odcs.length +
            ', ODP: ' + state.odps.length,
            'success'
        );
    }

    function buildMapUrl() {
        const routerId = Number(state.routerId || 0);
        if (routerId <= 0) {
            return config.apiMapUrl;
        }

        return config.apiMapUrl + (config.apiMapUrl.indexOf('?') === -1 ? '?' : '&') + 'router_id=' + encodeURIComponent(String(routerId));
    }

    function fetchData() {
        setSummary('Memuat data ODP/ODC...', 'info');

        return fetch(buildMapUrl(), {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function (json) {
            state.routers = Array.isArray(json.routers) ? json.routers : [];
            state.olts = Array.isArray(json.olts) ? json.olts : [];
            state.odcs = Array.isArray(json.odcs) ? json.odcs : [];
            state.odps = Array.isArray(json.odps) ? json.odps : [];

            fillRouterOptions(odcRouterEl, true, 'Pilih Router');
            fillRouterOptions(odpRouterEl, true, 'Pilih Router');
            fillOltOptions(odcOltEl, Number(odcRouterEl ? odcRouterEl.value : 0), true);
            fillOltOptions(odpOltEl, Number(odpRouterEl ? odpRouterEl.value : 0), true);
            fillOdcOptions(Number(odpRouterEl ? odpRouterEl.value : 0), Number(odpOltEl ? odpOltEl.value : 0));

            renderTables();
        })
        .catch(function (error) {
            setSummary('Gagal memuat data: ' + (error && error.message ? error.message : ''), 'error');
        });
    }

    function postForm(url, payload) {
        const csrf = getCsrf();
        const params = new URLSearchParams();

        Object.keys(payload || {}).forEach(function (key) {
            if (payload[key] !== null && payload[key] !== undefined) {
                params.append(key, String(payload[key]));
            }
        });

        if (csrf.name && csrf.hash) {
            params.append(csrf.name, csrf.hash);
        }

        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: params
        }).then(function (response) {
            return response.json().then(function (json) {
                return { ok: response.ok, json: json || {} };
            });
        });
    }

    function resetOdcForm() {
        if (!odcFormEl) {
            return;
        }

        odcFormEl.reset();
        if (odcIdEl) odcIdEl.value = '';
        if (odcModalTitleEl) odcModalTitleEl.textContent = 'Tambah ODC';
        if (odcSubmitBtnEl) odcSubmitBtnEl.textContent = 'Simpan ODC';
        if (odcCapacityEl) odcCapacityEl.value = '0';
        if (odcUsedEl) odcUsedEl.value = '0';

        fillRouterOptions(odcRouterEl, true, 'Pilih Router');
        fillOltOptions(odcOltEl, Number(odcRouterEl ? odcRouterEl.value : 0), true);
    }

    function resetOdpForm() {
        if (!odpFormEl) {
            return;
        }

        odpFormEl.reset();
        if (odpIdEl) odpIdEl.value = '';
        if (odpModalTitleEl) odpModalTitleEl.textContent = 'Tambah ODP';
        if (odpSubmitBtnEl) odpSubmitBtnEl.textContent = 'Simpan ODP';
        if (odpCapacityEl) odpCapacityEl.value = '0';
        if (odpUsedEl) odpUsedEl.value = '0';

        fillRouterOptions(odpRouterEl, true, 'Pilih Router');
        fillOltOptions(odpOltEl, Number(odpRouterEl ? odpRouterEl.value : 0), true);
        fillOdcOptions(Number(odpRouterEl ? odpRouterEl.value : 0), Number(odpOltEl ? odpOltEl.value : 0));
    }

    function openEditOdc(id) {
        const odc = state.odcMap[String(id)];
        if (!odc || !odcModal) {
            return;
        }

        resetOdcForm();

        const meta = odc.metadata || {};
        if (odcModalTitleEl) odcModalTitleEl.textContent = 'Edit ODC';
        if (odcSubmitBtnEl) odcSubmitBtnEl.textContent = 'Update ODC';
        if (odcIdEl) odcIdEl.value = String(odc.id || '');
        if (odcRouterEl) odcRouterEl.value = String(odc.router_id || '');
        fillOltOptions(odcOltEl, Number(odc.router_id || 0), true);
        if (odcOltEl) odcOltEl.value = String(meta.olt_id || '');
        if (odcNameEl) odcNameEl.value = String(odc.name || '');
        if (odcCapacityEl) odcCapacityEl.value = String(meta.capacity || 0);
        if (odcUsedEl) odcUsedEl.value = String(meta.used_ports || 0);
        if (odcLatEl) odcLatEl.value = odc.latitude === null || odc.latitude === undefined ? '' : String(odc.latitude);
        if (odcLngEl) odcLngEl.value = odc.longitude === null || odc.longitude === undefined ? '' : String(odc.longitude);
        if (odcDescEl) odcDescEl.value = String(meta.description || '');

        odcModal.show();
    }

    function openEditOdp(id) {
        const odp = state.odpMap[String(id)];
        if (!odp || !odpModal) {
            return;
        }

        resetOdpForm();

        const meta = odp.metadata || {};
        if (odpModalTitleEl) odpModalTitleEl.textContent = 'Edit ODP';
        if (odpSubmitBtnEl) odpSubmitBtnEl.textContent = 'Update ODP';
        if (odpIdEl) odpIdEl.value = String(odp.id || '');
        if (odpRouterEl) odpRouterEl.value = String(odp.router_id || '');
        fillOltOptions(odpOltEl, Number(odp.router_id || 0), true);
        if (odpOltEl) odpOltEl.value = String(meta.olt_id || '');
        fillOdcOptions(Number(odp.router_id || 0), Number(meta.olt_id || 0));
        if (odpOdcEl) odpOdcEl.value = String(meta.odc_id || '');
        if (odpNameEl) odpNameEl.value = String(odp.name || '');
        if (odpPonEl) odpPonEl.value = String(meta.pon_port || '');
        if (odpCapacityEl) odpCapacityEl.value = String(meta.capacity || 0);
        if (odpUsedEl) odpUsedEl.value = String(meta.used_ports || 0);
        if (odpLatEl) odpLatEl.value = odp.latitude === null || odp.latitude === undefined ? '' : String(odp.latitude);
        if (odpLngEl) odpLngEl.value = odp.longitude === null || odp.longitude === undefined ? '' : String(odp.longitude);
        if (odpDescEl) odpDescEl.value = String(meta.description || '');

        odpModal.show();
    }

    function confirmDelete(title, text, onConfirm) {
        if (typeof onConfirm !== 'function') {
            return;
        }

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: title || 'Hapus data?',
                text: text || 'Data akan dihapus.',
                showCancelButton: true,
                confirmButtonText: 'Ya, hapus',
                cancelButtonText: 'Batal'
            }).then(function (result) {
                if (result.isConfirmed) {
                    onConfirm();
                }
            });
            return;
        }

        if (window.confirm(text || 'Data akan dihapus. Lanjutkan?')) {
            onConfirm();
        }
    }

    function runDelete(url, id, okMessage, errorMessage) {
        postForm(url, { id: id }).then(function (res) {
            const json = res.json || {};
            if (json.csrf_name && json.csrf_hash) {
                setCsrf(json.csrf_name, json.csrf_hash);
            }

            if (!json.success) {
                notifyError(json.message || errorMessage || 'Gagal menghapus data.');
                return;
            }

            notifySuccess(json.message || okMessage || 'Data berhasil dihapus.');
            fetchData();
        }).catch(function (error) {
            notifyError(error && error.message ? error.message : 'Network error');
        });
    }

    if (routerFilterEl) {
        routerFilterEl.addEventListener('change', function () {
            state.routerId = Number(routerFilterEl.value || 0);
            fetchData();
        });
    }

    if (odcRouterEl) {
        odcRouterEl.addEventListener('change', function () {
            fillOltOptions(odcOltEl, Number(odcRouterEl.value || 0), true);
        });
    }

    if (odpRouterEl) {
        odpRouterEl.addEventListener('change', function () {
            const routerId = Number(odpRouterEl.value || 0);
            fillOltOptions(odpOltEl, routerId, true);
            fillOdcOptions(routerId, Number(odpOltEl ? odpOltEl.value : 0));
        });
    }

    if (odpOltEl) {
        odpOltEl.addEventListener('change', function () {
            fillOdcOptions(Number(odpRouterEl ? odpRouterEl.value : 0), Number(odpOltEl.value || 0));
        });
    }

    const addOdcBtn = document.getElementById('btnAddOdc');
    if (addOdcBtn) {
        addOdcBtn.addEventListener('click', function () {
            resetOdcForm();
        });
    }

    const addOdpBtn = document.getElementById('btnAddOdp');
    if (addOdpBtn) {
        addOdpBtn.addEventListener('click', function () {
            resetOdpForm();
        });
    }

    if (odcFormEl) {
        odcFormEl.addEventListener('submit', function (event) {
            event.preventDefault();

            const id = Number(odcIdEl && odcIdEl.value ? odcIdEl.value : 0);
            const payload = {
                id: id > 0 ? id : '',
                router_id: Number(odcRouterEl && odcRouterEl.value ? odcRouterEl.value : 0),
                olt_id: Number(odcOltEl && odcOltEl.value ? odcOltEl.value : 0),
                name: odcNameEl ? odcNameEl.value : '',
                capacity: Number(odcCapacityEl && odcCapacityEl.value ? odcCapacityEl.value : 0),
                used_ports: Number(odcUsedEl && odcUsedEl.value ? odcUsedEl.value : 0),
                latitude: odcLatEl ? odcLatEl.value : '',
                longitude: odcLngEl ? odcLngEl.value : '',
                description: odcDescEl ? odcDescEl.value : ''
            };

            if (payload.router_id <= 0) {
                notifyError('Router wajib dipilih.');
                return;
            }
            if (!String(payload.name || '').trim()) {
                notifyError('Nama ODC wajib diisi.');
                return;
            }

            const endpoint = id > 0 ? config.apiUpdateOdcUrl : config.apiCreateOdcUrl;
            postForm(endpoint, payload).then(function (res) {
                const json = res.json || {};
                if (json.csrf_name && json.csrf_hash) {
                    setCsrf(json.csrf_name, json.csrf_hash);
                }

                if (!json.success) {
                    notifyError(json.message || (id > 0 ? 'Gagal update ODC.' : 'Gagal menambah ODC.'));
                    return;
                }

                notifySuccess(json.message || (id > 0 ? 'ODC berhasil diperbarui.' : 'ODC berhasil ditambahkan.'));
                if (odcModal) {
                    odcModal.hide();
                }
                fetchData();
            }).catch(function (error) {
                notifyError(error && error.message ? error.message : 'Network error');
            });
        });
    }

    if (odpFormEl) {
        odpFormEl.addEventListener('submit', function (event) {
            event.preventDefault();

            const id = Number(odpIdEl && odpIdEl.value ? odpIdEl.value : 0);
            const payload = {
                id: id > 0 ? id : '',
                router_id: Number(odpRouterEl && odpRouterEl.value ? odpRouterEl.value : 0),
                olt_id: Number(odpOltEl && odpOltEl.value ? odpOltEl.value : 0),
                odc_id: Number(odpOdcEl && odpOdcEl.value ? odpOdcEl.value : 0),
                name: odpNameEl ? odpNameEl.value : '',
                pon_port: odpPonEl ? odpPonEl.value : '',
                capacity: Number(odpCapacityEl && odpCapacityEl.value ? odpCapacityEl.value : 0),
                used_ports: Number(odpUsedEl && odpUsedEl.value ? odpUsedEl.value : 0),
                latitude: odpLatEl ? odpLatEl.value : '',
                longitude: odpLngEl ? odpLngEl.value : '',
                description: odpDescEl ? odpDescEl.value : ''
            };

            if (payload.router_id <= 0) {
                notifyError('Router wajib dipilih.');
                return;
            }
            if (!String(payload.name || '').trim()) {
                notifyError('Nama ODP wajib diisi.');
                return;
            }

            const endpoint = id > 0 ? config.apiUpdateOdpUrl : config.apiCreateOdpUrl;
            postForm(endpoint, payload).then(function (res) {
                const json = res.json || {};
                if (json.csrf_name && json.csrf_hash) {
                    setCsrf(json.csrf_name, json.csrf_hash);
                }

                if (!json.success) {
                    notifyError(json.message || (id > 0 ? 'Gagal update ODP.' : 'Gagal menambah ODP.'));
                    return;
                }

                notifySuccess(json.message || (id > 0 ? 'ODP berhasil diperbarui.' : 'ODP berhasil ditambahkan.'));
                if (odpModal) {
                    odpModal.hide();
                }
                fetchData();
            }).catch(function (error) {
                notifyError(error && error.message ? error.message : 'Network error');
            });
        });
    }

    document.addEventListener('click', function (event) {
        const editOdcBtn = event.target.closest('.js-edit-odc');
        if (editOdcBtn) {
            event.preventDefault();
            openEditOdc(Number(editOdcBtn.getAttribute('data-id') || 0));
            return;
        }

        const editOdpBtn = event.target.closest('.js-edit-odp');
        if (editOdpBtn) {
            event.preventDefault();
            openEditOdp(Number(editOdpBtn.getAttribute('data-id') || 0));
            return;
        }

        const deleteOdcBtn = event.target.closest('.js-delete-odc');
        if (deleteOdcBtn) {
            event.preventDefault();
            const id = Number(deleteOdcBtn.getAttribute('data-id') || 0);
            const name = String(deleteOdcBtn.getAttribute('data-name') || '');
            if (id <= 0) {
                return;
            }
            confirmDelete(
                'Hapus ODC?',
                'ODC ' + (name ? ('"' + name + '" ') : '') + 'akan dinonaktifkan. Lanjutkan?',
                function () {
                    runDelete(config.apiDeleteOdcUrl, id, 'ODC berhasil dihapus.', 'Gagal menghapus ODC.');
                }
            );
            return;
        }

        const deleteOdpBtn = event.target.closest('.js-delete-odp');
        if (deleteOdpBtn) {
            event.preventDefault();
            const id = Number(deleteOdpBtn.getAttribute('data-id') || 0);
            if (id <= 0) {
                return;
            }
            confirmDelete(
                'Hapus ODP?',
                'Data ODP akan dihapus permanen. Lanjutkan?',
                function () {
                    runDelete(config.apiDeleteOdpUrl, id, 'ODP berhasil dihapus.', 'Gagal menghapus ODP.');
                }
            );
        }
    });

    state.routerId = Number(routerFilterEl ? routerFilterEl.value : 0);
    fetchData();
})();
</script>
SCRIPT;

$page_scripts = str_replace('%API_MAP_URL%', json_encode($api_map_url), $page_scripts);
$page_scripts = str_replace('%API_CREATE_ODC_URL%', json_encode($api_create_odc_url), $page_scripts);
$page_scripts = str_replace('%API_UPDATE_ODC_URL%', json_encode($api_update_odc_url), $page_scripts);
$page_scripts = str_replace('%API_DELETE_ODC_URL%', json_encode($api_delete_odc_url), $page_scripts);
$page_scripts = str_replace('%API_CREATE_ODP_URL%', json_encode($api_create_odp_url), $page_scripts);
$page_scripts = str_replace('%API_UPDATE_ODP_URL%', json_encode($api_update_odp_url), $page_scripts);
$page_scripts = str_replace('%API_DELETE_ODP_URL%', json_encode($api_delete_odp_url), $page_scripts);

include APPPATH . 'views/layout/master.php';
