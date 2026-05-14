<?php
$routers = isset($routers) && is_array($routers) ? $routers : array();
$api_map_url = isset($api_map_url) ? (string) $api_map_url : site_url('api/network/map');
$network_nodes_url = isset($network_nodes_url) ? (string) $network_nodes_url : site_url('network/nodes');

$page_title = 'Fiber Network Map - ' . app_name();
$page_heading = 'Fiber Network Map';
$page_subheading = 'Visualisasi topologi Router -> OLT -> ODC -> ODP -> Customer.';
$active_menu = 'fiber_network_map';

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Fiber Network Map</span>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-sm btn-outline-success" id="btnStartManualLine">
                <i class="ti ti-pencil me-1"></i>Tarik Garis Manual
            </button>
            <button type="button" class="btn btn-sm btn-success" id="btnSaveManualLine" disabled>
                <i class="ti ti-device-floppy me-1"></i>Simpan Garis
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelManualLine" disabled>
                Batal
            </button>
            <a href="<?php echo html_escape($network_nodes_url); ?>" class="btn btn-sm btn-outline-primary">
                <i class="ti ti-sitemap me-1"></i>Manajemen ODP/ODC
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-lg-4 col-md-6">
                <label class="form-label">Select Router</label>
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
            <div class="col-lg-8 col-md-6 d-flex align-items-end justify-content-md-end">
                <div class="small text-muted d-flex flex-wrap gap-3">
                    <span><img src="<?php echo base_url('assets/img/network_map/router_icon.png'); ?>" width="18" height="18" alt=""> Router</span>
                    <span><img src="<?php echo base_url('assets/img/network_map/olt_icon.png'); ?>" width="18" height="18" alt=""> OLT</span>
                    <span><span class="network-legend-chip" style="--ring:#06b6d4"><img src="<?php echo base_url('assets/img/network_map/odc_icon.png'); ?>" width="12" height="12" alt=""></span> ODC</span>
                    <span><span class="network-legend-chip" style="--ring:#6366f1"><img src="<?php echo base_url('assets/img/network_map/odp_box_icon.svg'); ?>" width="12" height="12" alt=""></span> ODP Normal</span>
                    <span><span class="network-legend-chip" style="--ring:#f59e0b"><img src="<?php echo base_url('assets/img/network_map/odp_box_icon.svg'); ?>" width="12" height="12" alt=""></span> ODP >80%</span>
                    <span><span class="network-legend-chip" style="--ring:#ef4444"><img src="<?php echo base_url('assets/img/network_map/odp_box_icon.svg'); ?>" width="12" height="12" alt=""></span> ODP Full</span>
                    <span><span class="network-legend-user text-primary"><i class="ti ti-user"></i></span> PPPoE</span>
                    <span><span class="network-legend-user text-warning"><i class="ti ti-user"></i></span> Static IP</span>
                </div>
            </div>
        </div>

        <div id="mapLoadingState" class="alert alert-light border mb-3">Memuat data peta...</div>
        <div id="fiberNetworkMap" class="fiber-network-map"></div>
    </div>
</div>
<?php
$content = ob_get_clean();

$page_scripts = <<<'SCRIPT'
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<style>
.fiber-network-map {
    width: 100%;
    height: 72vh;
    min-height: 420px;
    border-radius: 12px;
    border: 1px solid #dbe4f2;
    overflow: hidden;
}
.leaflet-popup-content {
    min-width: 230px;
}
.network-popup-title {
    font-weight: 700;
    margin-bottom: .35rem;
}
.network-popup-grid {
    display: grid;
    grid-template-columns: 110px 1fr;
    gap: .2rem .5rem;
    font-size: .83rem;
}
.network-node-icon {
    background: transparent !important;
    border: none !important;
}
.network-node-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.95);
    border: 3px solid #cbd5e1;
    box-sizing: border-box;
    overflow: hidden;
}
.network-node-wrap img {
    display: block;
    object-fit: contain;
}
.network-legend-chip {
    width: 20px;
    height: 20px;
    border-radius: 999px;
    border: 2px solid var(--ring, #94a3b8);
    background: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: .3rem;
    vertical-align: middle;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.2);
}
.network-legend-user {
    width: 20px;
    height: 20px;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: .3rem;
    vertical-align: middle;
}
.network-legend-user i {
    font-size: 14px;
    line-height: 1;
}
@media (max-width: 767.98px) {
    .fiber-network-map {
        height: 68vh;
        min-height: 360px;
    }
}
</style>
<script>
(function () {
    const mapEl = document.getElementById('fiberNetworkMap');
    if (!mapEl || typeof L === 'undefined') {
        return;
    }

    const filterEl = document.getElementById('routerFilter');
    const loadingEl = document.getElementById('mapLoadingState');
    const btnStartManualLine = document.getElementById('btnStartManualLine');
    const btnSaveManualLine = document.getElementById('btnSaveManualLine');
    const btnCancelManualLine = document.getElementById('btnCancelManualLine');

    const config = {
        apiMapUrl: %API_MAP_URL%,
        icons: {
            router: %ICON_ROUTER%,
            olt: %ICON_OLT%,
            odc: %ICON_ODC%,
            odp: %ICON_ODP%
        }
    };

    const state = {
        data: { routers: [], olts: [], odcs: [], odps: [], customers: [] },
        routerId: 0,
        manual: {
            drawing: false,
            points: [],
            tempLine: null
        }
    };
    const MANUAL_LINE_STORAGE_KEY = 'fiber_map_manual_lines_v1';

    const map = L.map(mapEl).setView([-6.2000000, 106.8166660], 10);
    map.createPane('topologyPane');
    map.getPane('topologyPane').style.zIndex = 430;
    const googleRoad = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
        maxZoom: 20,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
        attribution: '&copy; Google'
    });
    const googleSatellite = L.tileLayer('https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', {
        maxZoom: 20,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
        attribution: '&copy; Google'
    });
    const googleHybrid = L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
        maxZoom: 20,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
        attribution: '&copy; Google'
    });
    googleRoad.addTo(map);

    L.control.layers(
        {
            'Google Roadmap': googleRoad,
            'Google Satellite': googleSatellite,
            'Google Hybrid': googleHybrid
        },
        null,
        { position: 'topright' }
    ).addTo(map);

    const routerLayer = L.layerGroup().addTo(map);
    const oltLayer = L.layerGroup().addTo(map);
    const odcLayer = L.layerGroup().addTo(map);
    const odpLayer = L.layerGroup().addTo(map);
    const customerLayer = L.layerGroup().addTo(map);
    const lineLayer = L.layerGroup().addTo(map);
    const manualLineLayer = L.layerGroup().addTo(map);
    let customerClusterLayer = null;

    function buildNodeIcon(iconUrl, size, borderColor, shadowColor) {
        const outer = Math.max(22, Number(size || 0));
        const inner = Math.round(outer * 0.7);
        const anchorX = Math.round(outer / 2);
        const anchorY = outer;

        return L.divIcon({
            className: 'network-node-icon',
            html:
                '<span class="network-node-wrap" style="' +
                'width:' + outer + 'px;' +
                'height:' + outer + 'px;' +
                'border-color:' + borderColor + ';' +
                'box-shadow:0 4px 14px ' + shadowColor + ';">' +
                '<img src="' + iconUrl + '" alt="" style="width:' + inner + 'px;height:' + inner + 'px;">' +
                '</span>',
            iconSize: [outer, outer],
            iconAnchor: [anchorX, anchorY],
            popupAnchor: [0, -Math.round(outer * 0.85)]
        });
    }

    function buildPersonIcon(size, fillColor, borderColor, shadowColor) {
        const outer = Math.max(22, Number(size || 0));
        const anchorX = Math.round(outer / 2);
        const anchorY = outer;
        const iconSize = Math.round(outer * 0.72);

        const svg =
            '<svg xmlns="http://www.w3.org/2000/svg" width="' + iconSize + '" height="' + iconSize + '" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' +
            '<circle cx="12" cy="8" r="4"></circle>' +
            '<path d="M4 20c0-3.8 3.6-6 8-6s8 2.2 8 6"></path>' +
            '</svg>';

        return L.divIcon({
            className: 'network-node-icon',
            html:
                '<span class="network-node-wrap" style="' +
                'width:' + outer + 'px;' +
                'height:' + outer + 'px;' +
                'background:' + fillColor + ';' +
                'border-color:' + borderColor + ';' +
                'box-shadow:0 4px 14px ' + shadowColor + ';">' +
                svg +
                '</span>',
            iconSize: [outer, outer],
            iconAnchor: [anchorX, anchorY],
            popupAnchor: [0, -Math.round(outer * 0.85)]
        });
    }

    const icons = {
        router: buildNodeIcon(config.icons.router, 52, '#1d4ed8', 'rgba(37,99,235,0.45)'),
        olt: buildNodeIcon(config.icons.olt, 46, '#10b981', 'rgba(16,185,129,0.35)'),
        odc: buildNodeIcon(config.icons.odc, 44, '#0ea5e9', 'rgba(14,165,233,0.35)'),
        // ODP menggunakan ikon ODP box dengan warna ring berbeda.
        odp: buildNodeIcon(config.icons.odp, 40, '#6366f1', 'rgba(99,102,241,0.32)'),
        odpOrange: buildNodeIcon(config.icons.odp, 40, '#f59e0b', 'rgba(245,158,11,0.34)'),
        odpRed: buildNodeIcon(config.icons.odp, 40, '#ef4444', 'rgba(239,68,68,0.32)'),
        // Customer: ikon orang, beda warna PPPoE vs Static.
        customerPppoe: buildPersonIcon(36, '#2563eb', '#1d4ed8', 'rgba(37,99,235,0.35)'),
        customerStatic: buildPersonIcon(36, '#f97316', '#ea580c', 'rgba(249,115,22,0.34)')
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
        if (!Number.isFinite(lt) || !Number.isFinite(ln)) {
            return false;
        }
        if (Math.abs(lt) > 90 || Math.abs(ln) > 180) {
            return false;
        }
        // Hindari titik default/null island yang sering muncul karena data kosong.
        if (Math.abs(lt) < 0.000001 && Math.abs(ln) < 0.000001) {
            return false;
        }
        return true;
    }

    function setLoading(text, type) {
        if (!loadingEl) {
            return;
        }

        loadingEl.className = 'alert mb-3';
        if (type === 'error') {
            loadingEl.classList.add('alert-danger');
        } else if (type === 'success') {
            loadingEl.classList.add('alert-success');
        } else {
            loadingEl.classList.add('alert-light', 'border');
        }
        loadingEl.textContent = text;
    }

    function popupGrid(rows) {
        return '<div class="network-popup-grid">' + rows.map(function (row) {
            return '<div class="text-muted">' + esc(row[0]) + '</div><div>' + esc(row[1]) + '</div>';
        }).join('') + '</div>';
    }

    function clearLayers() {
        routerLayer.clearLayers();
        oltLayer.clearLayers();
        odcLayer.clearLayers();
        odpLayer.clearLayers();
        customerLayer.clearLayers();
        lineLayer.clearLayers();
        manualLineLayer.clearLayers();

        if (customerClusterLayer) {
            customerClusterLayer.clearLayers();
            map.removeLayer(customerClusterLayer);
            customerClusterLayer = null;
        }
    }

    function pointsAlmostEqual(a, b) {
        if (!a || !b) {
            return false;
        }
        return Math.abs(Number(a[0]) - Number(b[0])) < 0.00003 && Math.abs(Number(a[1]) - Number(b[1])) < 0.00003;
    }

    function drawTopologyLine(fromPos, toPos, options) {
        if (!fromPos || !toPos) {
            return;
        }
        if (pointsAlmostEqual(fromPos, toPos)) {
            return;
        }
        const lineOptions = Object.assign({ pane: 'topologyPane' }, options || {});
        L.polyline([fromPos, toPos], lineOptions).addTo(lineLayer);
    }

    function readManualLineStore() {
        try {
            const raw = window.localStorage.getItem(MANUAL_LINE_STORAGE_KEY);
            if (!raw) {
                return {};
            }
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function writeManualLineStore(store) {
        try {
            window.localStorage.setItem(MANUAL_LINE_STORAGE_KEY, JSON.stringify(store || {}));
        } catch (e) {
            // ignore localStorage errors
        }
    }

    function getManualLinesForRouter(routerId) {
        const rid = Number(routerId || 0);
        if (rid <= 0) {
            return [];
        }
        const store = readManualLineStore();
        const lines = store[String(rid)] || [];
        return Array.isArray(lines) ? lines : [];
    }

    function setManualLinesForRouter(routerId, lines) {
        const rid = Number(routerId || 0);
        if (rid <= 0) {
            return;
        }
        const store = readManualLineStore();
        store[String(rid)] = Array.isArray(lines) ? lines : [];
        writeManualLineStore(store);
    }

    function clearManualDraft() {
        state.manual.points = [];
        if (state.manual.tempLine) {
            map.removeLayer(state.manual.tempLine);
            state.manual.tempLine = null;
        }
        if (btnSaveManualLine) {
            btnSaveManualLine.disabled = true;
        }
        if (btnCancelManualLine) {
            btnCancelManualLine.disabled = !state.manual.drawing;
        }
    }

    function drawManualDraft() {
        if (state.manual.tempLine) {
            map.removeLayer(state.manual.tempLine);
            state.manual.tempLine = null;
        }
        if (state.manual.points.length < 2) {
            if (btnSaveManualLine) btnSaveManualLine.disabled = true;
            return;
        }
        state.manual.tempLine = L.polyline(state.manual.points, {
            color: '#ef4444',
            weight: 3.4,
            opacity: 0.95,
            dashArray: '8,4',
            pane: 'topologyPane'
        }).addTo(map);
        if (btnSaveManualLine) btnSaveManualLine.disabled = false;
    }

    function renderManualLines() {
        manualLineLayer.clearLayers();
        const lines = getManualLinesForRouter(state.routerId);
        lines.forEach(function (line, idx) {
            if (!line || !Array.isArray(line.points) || line.points.length < 2) {
                return;
            }
            const polyline = L.polyline(line.points, {
                color: String(line.color || '#f97316'),
                weight: Number(line.weight || 3),
                opacity: 0.96,
                pane: 'topologyPane'
            });
            polyline.bindPopup(
                '<div class="network-popup-title">' + esc(line.name || ('Garis Manual #' + (idx + 1))) + '</div>' +
                '<div class="small text-muted mb-2">Router #' + esc(state.routerId) + '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-danger js-delete-manual-line" data-line-id="' + esc(line.id || '') + '">Hapus Garis</button>'
            );
            polyline.addTo(manualLineLayer);
        });
    }

    function odpIcon(meta) {
        const warning = String((meta && meta.warning_level) || '').toLowerCase();
        if (warning === 'full') {
            return icons.odpRed;
        }
        if (warning === 'high') {
            return icons.odpOrange;
        }
        return icons.odp;
    }

    function renderMap() {
        clearLayers();

        const bounds = [];
        const routerCoords = {};
        const oltCoords = {};
        const odcCoords = {};
        const odpCoords = {};

        state.data.routers.forEach(function (router) {
            if (!hasLatLng(router.latitude, router.longitude)) {
                return;
            }

            const marker = L.marker([Number(router.latitude), Number(router.longitude)], { icon: icons.router });
            marker.bindPopup(
                '<div class="network-popup-title">' + esc(router.name) + '</div>' +
                popupGrid([
                    ['Router', router.name],
                    ['Total OLT', (router.metadata && router.metadata.total_olt) || 0],
                    ['Total ODC', (router.metadata && router.metadata.total_odc) || 0],
                    ['Total ODP', (router.metadata && router.metadata.total_odp) || 0],
                    ['Total Customers', (router.metadata && router.metadata.total_customers) || 0]
                ])
            );
            marker.addTo(routerLayer);
            routerCoords[String(router.id)] = [Number(router.latitude), Number(router.longitude)];
            bounds.push([Number(router.latitude), Number(router.longitude)]);
        });

        state.data.olts.forEach(function (olt) {
            if (!hasLatLng(olt.latitude, olt.longitude)) {
                return;
            }

            oltCoords[String(olt.id)] = [Number(olt.latitude), Number(olt.longitude)];
            const marker = L.marker([Number(olt.latitude), Number(olt.longitude)], { icon: icons.olt });
            marker.bindPopup(
                '<div class="network-popup-title">' + esc(olt.name) + '</div>' +
                popupGrid([
                    ['Router', (olt.metadata && olt.metadata.router_name) || ('Router #' + (olt.router_id || 0))],
                    ['Total ODC', (olt.metadata && olt.metadata.total_odc) || 0],
                    ['Total ODP', (olt.metadata && olt.metadata.total_odp) || 0],
                    ['Total ONU', (olt.metadata && olt.metadata.total_onu) || 0]
                ])
            );
            marker.addTo(oltLayer);
            bounds.push([Number(olt.latitude), Number(olt.longitude)]);
        });

        state.data.odcs.forEach(function (odc) {
            if (!hasLatLng(odc.latitude, odc.longitude)) {
                return;
            }

            odcCoords[String(odc.id)] = [Number(odc.latitude), Number(odc.longitude)];
            const meta = odc.metadata || {};
            const marker = L.marker([Number(odc.latitude), Number(odc.longitude)], { icon: icons.odc });
            marker.bindPopup(
                '<div class="network-popup-title">' + esc(odc.name) + '</div>' +
                popupGrid([
                    ['OLT', meta.olt_name || '-'],
                    ['Capacity', meta.capacity || 0],
                    ['Used Ports', meta.used_ports || 0],
                    ['Total ODP', meta.total_odp || 0]
                ])
            );
            marker.addTo(odcLayer);
            bounds.push([Number(odc.latitude), Number(odc.longitude)]);
        });

        state.data.odps.forEach(function (odp) {
            if (!hasLatLng(odp.latitude, odp.longitude)) {
                return;
            }

            odpCoords[String(odp.id)] = [Number(odp.latitude), Number(odp.longitude)];
            const meta = odp.metadata || {};
            const marker = L.marker([Number(odp.latitude), Number(odp.longitude)], { icon: odpIcon(meta) });
            marker.bindPopup(
                '<div class="network-popup-title">' + esc(odp.name) + '</div>' +
                popupGrid([
                    ['OLT', meta.olt_name || '-'],
                    ['ODC', meta.odc_name || '-'],
                    ['PON Port', meta.pon_port || '-'],
                    ['Capacity', meta.capacity || 0],
                    ['Used Ports', meta.used_ports || 0]
                ])
            );
            marker.addTo(odpLayer);
            bounds.push([Number(odp.latitude), Number(odp.longitude)]);
        });

        const customers = state.data.customers || [];
        let customerTargetLayer = customerLayer;
        if (customers.length > 1000 && typeof L.markerClusterGroup === 'function') {
            customerClusterLayer = L.markerClusterGroup();
            customerClusterLayer.addTo(map);
            customerTargetLayer = customerClusterLayer;
        }

        customers.forEach(function (customer) {
            if (!hasLatLng(customer.latitude, customer.longitude)) {
                return;
            }

            const meta = customer.metadata || {};
            const serviceMode = String(meta.service_mode || '').toLowerCase();
            const isStatic = serviceMode === 'static';
            const marker = L.marker(
                [Number(customer.latitude), Number(customer.longitude)],
                { icon: isStatic ? icons.customerStatic : icons.customerPppoe }
            );
            marker.bindPopup(
                '<div class="network-popup-title">' + esc(customer.name) + '</div>' +
                popupGrid([
                    ['Mode', isStatic ? 'Static IP' : 'PPPoE'],
                    ['Service Plan', meta.service_plan || '-'],
                    ['Status', customer.status || '-'],
                    ['IP Address', meta.ip_address || '-'],
                    ['ODP', meta.odp_name || '-']
                ])
            );
            marker.addTo(customerTargetLayer);
            bounds.push([Number(customer.latitude), Number(customer.longitude)]);
        });

        state.data.olts.forEach(function (olt) {
            const oltPos = oltCoords[String(olt.id)];
            const routerPos = routerCoords[String(olt.router_id || 0)];
            if (!oltPos || !routerPos) {
                return;
            }
            drawTopologyLine(routerPos, oltPos, { color: '#0ea5e9', weight: 3.2, opacity: 0.9 });
        });

        state.data.odcs.forEach(function (odc) {
            const meta = odc.metadata || {};
            const odcPos = odcCoords[String(odc.id)];
            const oltPos = oltCoords[String(meta.olt_id || 0)];
            if (!odcPos || !oltPos) {
                return;
            }
            drawTopologyLine(oltPos, odcPos, { color: '#7c3aed', weight: 3, opacity: 0.86, dashArray: '8,4' });
        });

        state.data.odps.forEach(function (odp) {
            const meta = odp.metadata || {};
            const odpPos = odpCoords[String(odp.id)];
            const odcPos = odcCoords[String(meta.odc_id || 0)];
            if (!odpPos || !odcPos) {
                return;
            }
            drawTopologyLine(odcPos, odpPos, { color: '#1d4ed8', weight: 2.8, opacity: 0.86, dashArray: '6,3' });
        });

        state.data.odps.forEach(function (odp) {
            const meta = odp.metadata || {};
            if (Number(meta.odc_id || 0) > 0) {
                return;
            }
            const odpPos = odpCoords[String(odp.id)];
            const oltPos = oltCoords[String(meta.olt_id || 0)];
            if (!odpPos || !oltPos) {
                return;
            }
            drawTopologyLine(oltPos, odpPos, { color: '#1d4ed8', weight: 2.8, opacity: 0.8, dashArray: '6,3' });
        });

        customers.forEach(function (customer) {
            if (!hasLatLng(customer.latitude, customer.longitude)) {
                return;
            }
            const meta = customer.metadata || {};
            const odpPos = odpCoords[String(meta.odp_id || 0)];
            if (!odpPos) {
                return;
            }
            const customerPos = [Number(customer.latitude), Number(customer.longitude)];
            drawTopologyLine(odpPos, customerPos, { color: '#16a34a', weight: 2.2, opacity: 0.72 });
        });

        renderManualLines();

        // Fokus utama: selalu ke router yang dipilih agar tidak melebar ke titik anomali.
        const selectedRouterId = Number(state.routerId || 0);
        const selectedRouter = selectedRouterId > 0
            ? (state.data.routers || []).find(function (router) { return Number(router.id || 0) === selectedRouterId; })
            : null;

        if (selectedRouter && hasLatLng(selectedRouter.latitude, selectedRouter.longitude)) {
            const focusPoints = [];
            const routerPos = [Number(selectedRouter.latitude), Number(selectedRouter.longitude)];
            focusPoints.push(routerPos);

            (state.data.olts || []).forEach(function (olt) {
                if (Number(olt.router_id || 0) === selectedRouterId && hasLatLng(olt.latitude, olt.longitude)) {
                    focusPoints.push([Number(olt.latitude), Number(olt.longitude)]);
                }
            });
            (state.data.odcs || []).forEach(function (odc) {
                if (Number(odc.router_id || 0) === selectedRouterId && hasLatLng(odc.latitude, odc.longitude)) {
                    focusPoints.push([Number(odc.latitude), Number(odc.longitude)]);
                }
            });
            (state.data.odps || []).forEach(function (odp) {
                if (Number(odp.router_id || 0) === selectedRouterId && hasLatLng(odp.latitude, odp.longitude)) {
                    focusPoints.push([Number(odp.latitude), Number(odp.longitude)]);
                }
            });
            (state.data.customers || []).forEach(function (customer) {
                if (Number(customer.router_id || 0) === selectedRouterId && hasLatLng(customer.latitude, customer.longitude)) {
                    focusPoints.push([Number(customer.latitude), Number(customer.longitude)]);
                }
            });

            if (focusPoints.length > 1) {
                let minLat = focusPoints[0][0];
                let maxLat = focusPoints[0][0];
                let minLng = focusPoints[0][1];
                let maxLng = focusPoints[0][1];
                focusPoints.forEach(function (pt) {
                    minLat = Math.min(minLat, pt[0]);
                    maxLat = Math.max(maxLat, pt[0]);
                    minLng = Math.min(minLng, pt[1]);
                    maxLng = Math.max(maxLng, pt[1]);
                });
                const span = Math.max(maxLat - minLat, maxLng - minLng);

                if (span < 0.0012) {
                    map.setView(routerPos, 17);
                } else {
                    map.fitBounds(focusPoints, { padding: [24, 24], maxZoom: 17 });
                }
            } else {
                map.setView(routerPos, 17);
            }
            return;
        }

        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [22, 22], maxZoom: 16 });
        }
    }

    function buildApiUrl() {
        const routerId = Number(state.routerId || 0);
        if (routerId <= 0) {
            return config.apiMapUrl;
        }
        return config.apiMapUrl + (config.apiMapUrl.indexOf('?') === -1 ? '?' : '&') + 'router_id=' + encodeURIComponent(String(routerId));
    }

    function fetchMapData() {
        setLoading('Memuat data peta...', 'info');

        fetch(buildApiUrl(), {
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
            state.data = {
                routers: Array.isArray(json.routers) ? json.routers : [],
                olts: Array.isArray(json.olts) ? json.olts : [],
                odcs: Array.isArray(json.odcs) ? json.odcs : [],
                odps: Array.isArray(json.odps) ? json.odps : [],
                customers: Array.isArray(json.customers) ? json.customers : []
            };

            setLoading(
                'Router: ' + state.data.routers.length +
                ', OLT: ' + state.data.olts.length +
                ', ODC: ' + state.data.odcs.length +
                ', ODP: ' + state.data.odps.length +
                ', Customer: ' + state.data.customers.length,
                'success'
            );

            renderMap();
        })
        .catch(function (error) {
            setLoading('Gagal memuat map data: ' + (error && error.message ? error.message : ''), 'error');
        });
    }

    if (filterEl) {
        filterEl.addEventListener('change', function () {
            state.manual.drawing = false;
            clearManualDraft();
            if (btnStartManualLine) {
                btnStartManualLine.classList.remove('btn-danger');
                btnStartManualLine.classList.add('btn-outline-success');
                btnStartManualLine.innerHTML = '<i class="ti ti-pencil me-1"></i>Tarik Garis Manual';
            }
            state.routerId = Number(filterEl.value || 0);
            fetchMapData();
        });
    }

    if (btnStartManualLine) {
        btnStartManualLine.addEventListener('click', function () {
            if (Number(state.routerId || 0) <= 0) {
                setLoading('Pilih router dulu sebelum tarik garis manual.', 'error');
                return;
            }
            state.manual.drawing = !state.manual.drawing;
            clearManualDraft();
            if (state.manual.drawing) {
                btnStartManualLine.classList.remove('btn-outline-success');
                btnStartManualLine.classList.add('btn-danger');
                btnStartManualLine.innerHTML = '<i class="ti ti-circle-x me-1"></i>Stop Tarik Garis';
                if (btnCancelManualLine) btnCancelManualLine.disabled = false;
                setLoading('Mode tarik garis aktif. Klik titik-titik di peta lalu klik Simpan Garis.', 'info');
            } else {
                btnStartManualLine.classList.remove('btn-danger');
                btnStartManualLine.classList.add('btn-outline-success');
                btnStartManualLine.innerHTML = '<i class="ti ti-pencil me-1"></i>Tarik Garis Manual';
                if (btnCancelManualLine) btnCancelManualLine.disabled = true;
                setLoading('Mode tarik garis dimatikan.', 'success');
            }
        });
    }

    if (btnCancelManualLine) {
        btnCancelManualLine.addEventListener('click', function () {
            clearManualDraft();
            setLoading('Draft garis manual dibatalkan.', 'info');
        });
    }

    if (btnSaveManualLine) {
        btnSaveManualLine.addEventListener('click', function () {
            if (Number(state.routerId || 0) <= 0) {
                setLoading('Pilih router dulu sebelum simpan garis.', 'error');
                return;
            }
            if (!Array.isArray(state.manual.points) || state.manual.points.length < 2) {
                setLoading('Minimal butuh 2 titik untuk simpan garis.', 'error');
                return;
            }

            let lineName = 'Manual Line ' + new Date().toLocaleString('id-ID');
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Nama Garis',
                    input: 'text',
                    inputValue: lineName,
                    showCancelButton: true,
                    confirmButtonText: 'Simpan',
                    cancelButtonText: 'Batal'
                }).then(function (res) {
                    if (!res.isConfirmed) return;
                    lineName = String(res.value || lineName).trim();
                    const lines = getManualLinesForRouter(state.routerId);
                    lines.push({
                        id: 'ml-' + Date.now() + '-' + Math.floor(Math.random() * 1000),
                        name: lineName !== '' ? lineName : 'Manual Line',
                        points: state.manual.points.slice(0),
                        color: '#f97316',
                        weight: 3
                    });
                    setManualLinesForRouter(state.routerId, lines);
                    clearManualDraft();
                    renderManualLines();
                    setLoading('Garis manual berhasil disimpan (browser ini).', 'success');
                });
                return;
            }

            const entered = window.prompt('Nama garis:', lineName);
            if (entered === null) return;
            lineName = String(entered || lineName).trim();
            const lines = getManualLinesForRouter(state.routerId);
            lines.push({
                id: 'ml-' + Date.now() + '-' + Math.floor(Math.random() * 1000),
                name: lineName !== '' ? lineName : 'Manual Line',
                points: state.manual.points.slice(0),
                color: '#f97316',
                weight: 3
            });
            setManualLinesForRouter(state.routerId, lines);
            clearManualDraft();
            renderManualLines();
            setLoading('Garis manual berhasil disimpan (browser ini).', 'success');
        });
    }

    map.on('click', function (event) {
        if (!state.manual.drawing) {
            return;
        }
        const lat = Number(event.latlng && event.latlng.lat);
        const lng = Number(event.latlng && event.latlng.lng);
        if (!hasLatLng(lat, lng)) {
            return;
        }
        state.manual.points.push([lat, lng]);
        drawManualDraft();
    });

    document.addEventListener('click', function (event) {
        const deleteBtn = event.target.closest('.js-delete-manual-line');
        if (!deleteBtn) {
            return;
        }

        const lineId = String(deleteBtn.getAttribute('data-line-id') || '');
        if (lineId === '' || Number(state.routerId || 0) <= 0) {
            return;
        }

        const runDelete = function () {
            const lines = getManualLinesForRouter(state.routerId).filter(function (line) {
                return String(line.id || '') !== lineId;
            });
            setManualLinesForRouter(state.routerId, lines);
            renderManualLines();
            setLoading('Garis manual berhasil dihapus.', 'success');
        };

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Hapus garis manual?',
                showCancelButton: true,
                confirmButtonText: 'Hapus',
                cancelButtonText: 'Batal'
            }).then(function (res) {
                if (res.isConfirmed) runDelete();
            });
            return;
        }

        if (window.confirm('Hapus garis manual ini?')) {
            runDelete();
        }
    });

    if (filterEl && Number(filterEl.value || 0) <= 0 && filterEl.options && filterEl.options.length > 1) {
        filterEl.value = String(filterEl.options[1].value || '0');
    }
    state.routerId = Number(filterEl ? filterEl.value : 0);
    fetchMapData();
})();
</script>
SCRIPT;

$page_scripts = str_replace('%API_MAP_URL%', json_encode($api_map_url), $page_scripts);
$page_scripts = str_replace('%ICON_ROUTER%', json_encode(base_url('assets/img/network_map/router_icon.png')), $page_scripts);
$page_scripts = str_replace('%ICON_OLT%', json_encode(base_url('assets/img/network_map/olt_icon.png')), $page_scripts);
$page_scripts = str_replace('%ICON_ODC%', json_encode(base_url('assets/img/network_map/odc_icon.png')), $page_scripts);
$page_scripts = str_replace('%ICON_ODP%', json_encode(base_url('assets/img/network_map/odp_box_icon.svg')), $page_scripts);

include APPPATH . 'views/layout/master.php';
