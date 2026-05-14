<?php
$mode = isset($mode) ? $mode : 'create';
$is_edit = ($mode === 'edit');
$customer = isset($customer) ? $customer : null;
$fields = isset($fields) ? $fields : array();
$form_action = isset($form_action) ? $form_action : 'customers/store';
$ppp_profiles = isset($ppp_profiles) && is_array($ppp_profiles) ? $ppp_profiles : array();
$location_options = isset($location_options) && is_array($location_options) ? $location_options : array();
$olt_options = isset($olt_options) && is_array($olt_options) ? $olt_options : array();
$odp_options = isset($odp_options) && is_array($odp_options) ? $odp_options : array();
$teknisi_options = isset($teknisi_options) && is_array($teknisi_options) ? $teknisi_options : array();
$router_options = isset($router_options) && is_array($router_options) ? $router_options : array();
$selected_profile_id = isset($selected_profile_id) ? $selected_profile_id : null;
$selected_service_mode = isset($selected_service_mode) ? strtolower(trim((string) $selected_service_mode)) : '';
$selected_technician_id = isset($selected_technician_id) ? (int) $selected_technician_id : 0;
$selected_router_id = isset($selected_router_id) ? (int) $selected_router_id : 0;
$customer_id = (is_object($customer) && isset($customer->id)) ? (int) $customer->id : 0;
$csrf_name = $this->security->get_csrf_token_name();

$page_title = $is_edit ? 'Edit Customer - ' . app_name() : 'Tambah Customer - ' . app_name();
$page_heading = $is_edit ? 'Edit Pelanggan' : 'Tambah Pelanggan';
$page_subheading = 'Form pelanggan (create/edit) untuk mode PPPoE dan Static.';
$active_menu = 'customers';

$has_field = function ($field) use ($fields) {
    return in_array($field, $fields, true);
};

$format_ppp_profile_label = function ($name) {
    $name = trim((string) $name);
    if ($name === '') {
        return '-';
    }

    $normalized = strtoupper(preg_replace('/\s+/', '', $name));
    $map = array(
        '10M' => '10 M (10 Mbps)',
        '20M' => '20 M (20 Mbps)',
        '30M' => '30 M (30 Mbps)',
        '50M' => '50 M (50 Mbps)',
    );

    return isset($map[$normalized]) ? $map[$normalized] : $name;
};

$field_value = function ($field, $default = '') use ($customer) {
    $existing = '';
    if (is_object($customer) && isset($customer->{$field})) {
        $existing = $customer->{$field};
    }
    return set_value($field, $existing !== '' ? $existing : $default);
};

$selected_ppp_profile_id = (string) set_value('ppp_profile_id', $selected_profile_id !== null ? $selected_profile_id : (isset($customer->ppp_profile_id) ? $customer->ppp_profile_id : (isset($customer->profile_id) ? $customer->profile_id : '')));
$selected_tech_id = (int) set_value('technician_id', $selected_technician_id);
$selected_router_id = (int) set_value('router_id', $selected_router_id);
$selected_odp_id = (int) set_value('odp_id', isset($customer->odp_id) ? $customer->odp_id : 0);
$username_value = $field_value('pppoe_username', $field_value('username'));
$password_value = $field_value('pppoe_password', $field_value('ppp_password'));

if ($selected_service_mode === '') {
    $selected_service_mode = (trim((string) $username_value) !== '' || (int) $selected_ppp_profile_id > 0) ? 'pppoe' : 'static';
}
$selected_service_mode = strtolower((string) set_value('service_mode', $selected_service_mode));
if (!in_array($selected_service_mode, array('pppoe', 'static'), true)) {
    $selected_service_mode = 'pppoe';
}

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><?php echo $is_edit ? 'Edit Data Pelanggan' : 'Tambah Pelanggan Baru'; ?></span>
        <?php if ($is_edit && $customer_id > 0): ?>
        <button
            type="button"
            class="btn btn-warning btn-sm"
            id="btnOpenUpgradeModal"
            data-show-form-url="<?php echo html_escape(site_url('customers/upgrade/show-form/' . $customer_id)); ?>"
        >
            Upgrade Paket
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($this->session->flashdata('success')): ?>
        <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('success')); ?></div>
        <?php endif; ?>

        <?php if ($this->session->flashdata('error')): ?>
        <div class="alert alert-danger"><?php echo html_escape($this->session->flashdata('error')); ?></div>
        <?php endif; ?>

        <?php if (validation_errors()): ?>
        <div class="alert alert-danger"><?php echo validation_errors(); ?></div>
        <?php endif; ?>

        <?php echo form_open($form_action); ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Nama Pelanggan</label>
                <input type="text" class="form-control" name="full_name" id="full_name" value="<?php echo html_escape($field_value('full_name')); ?>" required>
            </div>

            <?php if ($has_field('phone')): ?>
            <div class="col-md-6">
                <label class="form-label">No. HP</label>
                <div class="input-group">
                    <input
                        type="tel"
                        class="form-control"
                        name="phone"
                        id="phone"
                        inputmode="tel"
                        autocomplete="tel"
                        value="<?php echo html_escape($field_value('phone')); ?>"
                    >
                    <button type="button" class="btn btn-outline-secondary" id="phone_contact_picker_btn">Kontak</button>
                </div>
                <div class="form-text" id="phone_contact_picker_hint">Ambil nomor langsung dari kontak HP.</div>
            </div>
            <?php endif; ?>

            <?php if ($has_field('lokasi')): ?>
            <div class="col-md-6">
                <label class="form-label">Lokasi</label>
                <select class="form-select text-uppercase" name="lokasi" required>
                    <option value="">Pilih Lokasi</option>
                    <?php foreach ($location_options as $location): ?>
                    <option value="<?php echo html_escape($location); ?>" <?php echo (string) $field_value('lokasi') === (string) $location ? 'selected' : ''; ?>>
                        <?php echo html_escape($location); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($has_field('olt')): ?>
            <div class="col-md-6 olt-field">
                <label class="form-label">OLT</label>
                <select class="form-select text-uppercase" name="olt" id="olt">
                    <option value="">Pilih OLT</option>
                    <?php foreach ($olt_options as $olt): ?>
                    <option value="<?php echo html_escape($olt); ?>" <?php echo (string) $field_value('olt') === (string) $olt ? 'selected' : ''; ?>>
                        <?php echo html_escape($olt); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($has_field('odp_id')): ?>
            <div class="col-md-6">
                <label class="form-label">ODP</label>
                <select class="form-select" name="odp_id" id="odp_id" data-searchable="1">
                    <option value="">Pilih ODP</option>
                    <?php foreach ($odp_options as $odp): ?>
                    <?php
                        $oid = (int) ($odp['id'] ?? 0);
                        if ($oid <= 0) { continue; }
                        $oname = (string) ($odp['name'] ?? ('ODP #' . $oid));
                        $orouter_id = (int) ($odp['router_id'] ?? 0);
                        $orouter_name = trim((string) ($odp['router_name'] ?? ''));
                    ?>
                    <option
                        value="<?php echo $oid; ?>"
                        data-router-id="<?php echo $orouter_id; ?>"
                        <?php echo $selected_odp_id === $oid ? 'selected' : ''; ?>
                    >
                        <?php echo html_escape($oname . ($orouter_name !== '' ? ' - ' . $orouter_name : '')); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Pilih ODP pelanggan untuk sinkronisasi topologi network map.</div>
            </div>
            <?php endif; ?>

            <?php if ($has_field('email')): ?>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" value="<?php echo html_escape($field_value('email')); ?>">
            </div>
            <?php endif; ?>

            <div class="col-md-6">
                <label class="form-label">Mode Layanan</label>
                <select class="form-select" name="service_mode" id="service_mode">
                    <option value="pppoe" <?php echo $selected_service_mode === 'pppoe' ? 'selected' : ''; ?>>PPPoE</option>
                    <option value="static" <?php echo $selected_service_mode === 'static' ? 'selected' : ''; ?>>Static</option>
                </select>
                <div class="form-text">Mode `Static` tidak akan memproses PPP secret ke MikroTik.</div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Paket / Profile</label>
                <select class="form-select" name="ppp_profile_id" id="ppp_profile_id" <?php echo $selected_service_mode === 'pppoe' ? 'required' : ''; ?>>
                    <option value="">Pilih Paket / Profile</option>
                    <?php foreach ($ppp_profiles as $profile): ?>
                    <?php $pid = (string) (int) ($profile['id'] ?? 0); ?>
                    <option value="<?php echo html_escape($pid); ?>" <?php echo $selected_ppp_profile_id === $pid ? 'selected' : ''; ?>>
                        <?php echo html_escape($format_ppp_profile_label((string) ($profile['name'] ?? '-'))); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Bisa dipakai untuk menyimpan paket customer PPPoE maupun Static.</div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Assign Teknisi</label>
                <select class="form-select" name="technician_id">
                    <option value="">Pilih Teknisi</option>
                    <?php foreach ($teknisi_options as $tech): ?>
                    <?php
                        $tid = (int) ($tech['id'] ?? 0);
                        $tname = (string) ($tech['name'] ?? ('Teknisi #' . $tid));
                    ?>
                    <option value="<?php echo $tid; ?>" <?php echo $selected_tech_id === $tid ? 'selected' : ''; ?>>
                        <?php echo html_escape($tname); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Teknisi terpilih akan jadi PIC WO pemasangan untuk perhitungan KPI.</div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Router</label>
                <select class="form-select" name="router_id" id="router_id" required>
                    <option value="">Pilih Router</option>
                    <?php foreach ($router_options as $router): ?>
                    <?php
                        $rid = (int) ($router['id'] ?? 0);
                        $rname = (string) ($router['name'] ?? ('Router #' . $rid));
                        $rip = trim((string) ($router['ip_address'] ?? ''));
                    ?>
                    <option value="<?php echo $rid; ?>" <?php echo $selected_router_id === $rid ? 'selected' : ''; ?>>
                        <?php echo html_escape($rname . ($rip !== '' ? ' (' . $rip . ')' : '')); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 pppoe-field">
                <label class="form-label">Username PPP</label>
                <input type="text" class="form-control" name="pppoe_username" id="pppoe_username" value="<?php echo html_escape($username_value); ?>" <?php echo $selected_service_mode === 'pppoe' ? 'required' : ''; ?>>
            </div>

            <div class="col-md-6 pppoe-field">
                <label class="form-label">Password PPP</label>
                <input type="text" class="form-control" name="pppoe_password" id="pppoe_password" value="<?php echo html_escape($password_value); ?>" <?php echo ($is_edit || $selected_service_mode !== 'pppoe') ? '' : 'required'; ?>>
            </div>

            <div class="col-md-6">
                <label class="form-label">IP Remote</label>
                <input type="text" class="form-control" name="ip_address" value="<?php echo html_escape($field_value('ip_address')); ?>" placeholder="Auto assign jika kosong">
            </div>

            <?php if ($has_field('status')): ?>
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <?php $selected_status = strtolower((string) $field_value('status', 'pending')); ?>
                <select class="form-select" name="status" required>
                    <option value="pending" <?php echo $selected_status === 'pending' ? 'selected' : ''; ?>>PENDING</option>
                    <option value="active" <?php echo $selected_status === 'active' ? 'selected' : ''; ?>>ACTIVE</option>
                    <option value="suspended" <?php echo $selected_status === 'suspended' ? 'selected' : ''; ?>>SUSPENDED</option>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($has_field('latitude') || $has_field('longitude')): ?>
            <div class="col-12">
                <label class="form-label fw-semibold">Titik Koordinat (Maps)</label>
            </div>

            <?php if ($has_field('latitude')): ?>
            <div class="col-md-6">
                <label class="form-label">Latitude</label>
                <input
                    type="text"
                    class="form-control"
                    name="latitude"
                    id="latitude"
                    value="<?php echo html_escape($field_value('latitude')); ?>"
                    placeholder="-6.9123"
                >
                <div class="form-text">Contoh: -6.9123</div>
            </div>
            <?php endif; ?>

            <?php if ($has_field('longitude')): ?>
            <div class="col-md-6">
                <label class="form-label">Longitude</label>
                <input
                    type="text"
                    class="form-control"
                    name="longitude"
                    id="longitude"
                    value="<?php echo html_escape($field_value('longitude')); ?>"
                    placeholder="107.6098"
                >
                <div class="form-text">Contoh: 107.6098</div>
            </div>
            <?php endif; ?>

            <?php if ($has_field('latitude') && $has_field('longitude')): ?>
            <div class="col-12">
                <label class="form-label">Link Google Maps</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="google_maps_link" readonly placeholder="https://maps.google.com/?q=lat,long">
                    <button type="button" class="btn btn-outline-primary" id="btnOpenGoogleMaps" disabled>Buka Maps</button>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($has_field('address')): ?>
            <div class="col-12">
                <label class="form-label">Alamat</label>
                <textarea class="form-control" name="address" rows="3"><?php echo html_escape($field_value('address')); ?></textarea>
            </div>
            <?php endif; ?>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?php echo $is_edit ? 'Update' : 'Simpan'; ?></button>
            <a href="<?php echo site_url('customers'); ?>" class="btn btn-outline-secondary">Kembali</a>
        </div>
        <?php echo form_close(); ?>
    </div>
</div>
<script>
(function () {
    const serviceModeEl = document.getElementById('service_mode');
    const pppProfileEl = document.getElementById('ppp_profile_id');
    const pppUserEl = document.getElementById('pppoe_username');
    const pppPassEl = document.getElementById('pppoe_password');
    const pppoeFields = document.querySelectorAll('.pppoe-field');
    const oltFieldWraps = document.querySelectorAll('.olt-field');
    const oltEl = document.getElementById('olt');
    const routerEl = document.getElementById('router_id');
    const odpEl = document.getElementById('odp_id');

    function applyServiceMode() {
        if (!serviceModeEl) return;
        const mode = String(serviceModeEl.value || 'pppoe').toLowerCase();
        const isPppoe = (mode !== 'static');

        pppoeFields.forEach(function (el) {
            if (!el) return;
            el.style.display = isPppoe ? '' : 'none';
        });

        if (pppProfileEl) pppProfileEl.required = isPppoe;
        if (pppUserEl) pppUserEl.required = isPppoe;
        if (pppPassEl) pppPassEl.required = false;

        oltFieldWraps.forEach(function (el) {
            if (!el) return;
            el.style.display = isPppoe ? '' : 'none';
        });
        if (oltEl) {
            if (!isPppoe) {
                oltEl.value = '';
            }
            oltEl.required = isPppoe;
            oltEl.disabled = !isPppoe;
        }
    }

    if (serviceModeEl) {
        serviceModeEl.addEventListener('change', applyServiceMode);
        applyServiceMode();
    }

    function filterOdpByRouter() {
        if (!routerEl || !odpEl) {
            return;
        }

        const routerId = String(routerEl.value || '');
        let selectedStillVisible = false;
        const selectedValue = String(odpEl.value || '');

        Array.prototype.forEach.call(odpEl.options, function (option, index) {
            if (!option || index === 0) {
                return;
            }

            const optionRouterId = String(option.getAttribute('data-router-id') || '');
            const visible = routerId === '' || routerId === '0' || optionRouterId === '' || optionRouterId === routerId;
            option.hidden = !visible;
            option.disabled = !visible;

            if (visible && selectedValue !== '' && option.value === selectedValue) {
                selectedStillVisible = true;
            }
        });

        if (selectedValue !== '' && !selectedStillVisible) {
            odpEl.value = '';
        }
    }

    if (routerEl && odpEl) {
        routerEl.addEventListener('change', filterOdpByRouter);
        filterOdpByRouter();
    }

    const latEl = document.getElementById('latitude');
    const lngEl = document.getElementById('longitude');
    const mapsEl = document.getElementById('google_maps_link');
    const openBtn = document.getElementById('btnOpenGoogleMaps');

    if (!latEl || !lngEl || !mapsEl) {
        return;
    }

    function buildMapUrl() {
        const lat = (latEl.value || '').trim();
        const lng = (lngEl.value || '').trim();
        if (lat === '' || lng === '') {
            return '';
        }

        return 'https://maps.google.com/?q=' + encodeURIComponent(lat + ',' + lng);
    }

    function updateMapLink() {
        const url = buildMapUrl();
        mapsEl.value = url;
        if (openBtn) {
            openBtn.disabled = (url === '');
        }
    }

    latEl.addEventListener('input', updateMapLink);
    lngEl.addEventListener('input', updateMapLink);

    if (openBtn) {
        openBtn.addEventListener('click', function () {
            const url = buildMapUrl();
            if (url !== '') {
                window.open(url, '_blank', 'noopener');
            }
        });
    }

    updateMapLink();
})();
</script>
<?php if ($is_edit && $customer_id > 0): ?>
<div id="customerUpgradeModalContainer"></div>
<script>
(function () {
    const btnOpen = document.getElementById('btnOpenUpgradeModal');
    const modalContainer = document.getElementById('customerUpgradeModalContainer');
    const csrfName = <?php echo json_encode($csrf_name); ?>;

    if (!btnOpen || !modalContainer || typeof bootstrap === 'undefined') {
        return;
    }

    const idrFormatter = new Intl.NumberFormat('id-ID');
    let modalInstance = null;
    let isLoadingModal = false;

    function normalizeAmount(value) {
        const num = Number(value);
        return Number.isFinite(num) ? num : 0;
    }

    function formatCurrency(value) {
        const amount = normalizeAmount(value);
        const abs = Math.abs(amount);
        const sign = amount < 0 ? '- ' : '';
        return sign + 'Rp ' + idrFormatter.format(abs);
    }

    function updateCsrfToken(token) {
        if (!token) {
            return;
        }

        document.querySelectorAll('input[name="' + csrfName + '"]').forEach(function (el) {
            el.value = token;
        });
    }

    function parseJson(text) {
        try {
            return JSON.parse(text);
        } catch (err) {
            return null;
        }
    }

    function applySummary(formEl, data) {
        const upgradeTypeEl = formEl.querySelector('#upgradeType');
        const newPriceEl = formEl.querySelector('#upgradeNewPrice');
        const priceDiffEl = formEl.querySelector('#upgradePriceDiff');
        const prorateEl = formEl.querySelector('#upgradeProrateAmount');
        const messageEl = formEl.querySelector('#upgradeCalcMessage');

        if (newPriceEl) {
            newPriceEl.value = formatCurrency(data.new_price);
        }
        if (priceDiffEl) {
            priceDiffEl.value = formatCurrency(data.price_diff);
        }
        if (prorateEl) {
            prorateEl.value = formatCurrency(data.prorate_amount);
        }
        if (upgradeTypeEl) {
            const label = String(data.upgrade_type || '-').toLowerCase();
            upgradeTypeEl.value = label === 'downgrade' ? 'DOWNGRADE' : 'UPGRADE';
        }
        if (messageEl) {
            messageEl.className = 'alert alert-light border mt-3 mb-0 small';
            messageEl.textContent = 'Simulasi: ' + (data.old_plan_name || '-') + ' -> ' + (data.new_plan_name || '-') + '.';
        }
    }

    function resetSummary(formEl) {
        const newPriceEl = formEl.querySelector('#upgradeNewPrice');
        const priceDiffEl = formEl.querySelector('#upgradePriceDiff');
        const prorateEl = formEl.querySelector('#upgradeProrateAmount');
        const upgradeTypeEl = formEl.querySelector('#upgradeType');
        const messageEl = formEl.querySelector('#upgradeCalcMessage');

        if (newPriceEl) {
            newPriceEl.value = 'Rp 0';
        }
        if (priceDiffEl) {
            priceDiffEl.value = 'Rp 0';
        }
        if (prorateEl) {
            prorateEl.value = 'Rp 0';
        }
        if (upgradeTypeEl) {
            upgradeTypeEl.value = '-';
        }
        if (messageEl) {
            messageEl.className = 'alert alert-light border mt-3 mb-0 small';
            messageEl.textContent = 'Pilih paket baru untuk melihat simulasi upgrade.';
        }
    }

    function showCalcError(formEl, message) {
        const messageEl = formEl.querySelector('#upgradeCalcMessage');
        if (!messageEl) {
            return;
        }

        messageEl.className = 'alert alert-danger mt-3 mb-0 small';
        messageEl.textContent = message || 'Gagal menghitung prorate.';
    }

    async function loadModalContent() {
        if (isLoadingModal) {
            return;
        }

        isLoadingModal = true;
        btnOpen.disabled = true;
        btnOpen.textContent = 'Memuat...';

        try {
            const response = await fetch(btnOpen.getAttribute('data-show-form-url'), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const text = await response.text();
            const json = parseJson(text);

            if (!json) {
                throw new Error('Response form upgrade tidak valid. Silakan refresh halaman.');
            }
            if (json.csrf_token) {
                updateCsrfToken(json.csrf_token);
            }
            if (!response.ok || !json.success || !json.html) {
                throw new Error(json.message || 'Gagal memuat form upgrade.');
            }

            modalContainer.innerHTML = json.html;
            wireModalEvents();
            const modalEl = document.getElementById('customerUpgradeModal');
            if (!modalEl) {
                throw new Error('Komponen modal upgrade tidak ditemukan.');
            }
            modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
            modalInstance.show();
        } catch (error) {
            window.alert(error.message || 'Terjadi kesalahan saat membuka form upgrade.');
        } finally {
            isLoadingModal = false;
            btnOpen.disabled = false;
            btnOpen.textContent = 'Upgrade Paket';
        }
    }

    function wireModalEvents() {
        const modalEl = document.getElementById('customerUpgradeModal');
        const formEl = document.getElementById('customerUpgradeForm');
        if (!modalEl || !formEl) {
            return;
        }

        const calculateUrl = modalEl.getAttribute('data-calculate-url') || '';
        const newPlanEl = formEl.querySelector('#upgradeNewPlan');
        const upgradeDateEl = formEl.querySelector('#upgradeDate');
        const applyProrateEl = formEl.querySelector('#applyProrate');
        const customerIdEl = formEl.querySelector('input[name="customer_id"]');
        const submitBtn = formEl.querySelector('#btnConfirmUpgrade');

        resetSummary(formEl);
        if (submitBtn) {
            submitBtn.disabled = true;
        }

        async function recalculate() {
            if (!newPlanEl || !newPlanEl.value) {
                resetSummary(formEl);
                if (submitBtn) {
                    submitBtn.disabled = true;
                }
                return;
            }
            if (calculateUrl === '') {
                showCalcError(formEl, 'Endpoint perhitungan prorate tidak tersedia.');
                if (submitBtn) {
                    submitBtn.disabled = true;
                }
                return;
            }

            const postData = new URLSearchParams();
            const csrfEl = formEl.querySelector('input[name="' + csrfName + '"]');
            postData.append('customer_id', customerIdEl ? customerIdEl.value : '');
            postData.append('new_plan_id', newPlanEl.value);
            postData.append('upgrade_date', upgradeDateEl ? upgradeDateEl.value : '');
            postData.append('apply_prorate', applyProrateEl && applyProrateEl.checked ? '1' : '0');
            if (csrfEl) {
                postData.append(csrfEl.name, csrfEl.value);
            }

            try {
                const response = await fetch(calculateUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: postData.toString()
                });
                const text = await response.text();
                const json = parseJson(text);

                if (!json) {
                    throw new Error('Response perhitungan tidak valid.');
                }
                if (json.csrf_token) {
                    updateCsrfToken(json.csrf_token);
                }
                if (!response.ok || !json.success || !json.data) {
                    throw new Error(json.message || 'Perhitungan prorate gagal.');
                }

                applySummary(formEl, json.data);
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
            } catch (error) {
                showCalcError(formEl, error.message || 'Perhitungan prorate gagal.');
                if (submitBtn) {
                    submitBtn.disabled = true;
                }
            }
        }

        if (newPlanEl) {
            newPlanEl.addEventListener('change', recalculate);
        }
        if (upgradeDateEl) {
            upgradeDateEl.addEventListener('change', recalculate);
        }
        if (applyProrateEl) {
            applyProrateEl.addEventListener('change', recalculate);
        }

        formEl.addEventListener('submit', function (event) {
            if (!newPlanEl || !newPlanEl.value) {
                event.preventDefault();
                window.alert('Pilih paket baru terlebih dahulu.');
                return;
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Memproses...';
            }
        });
    }

    btnOpen.addEventListener('click', loadModalContent);
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
