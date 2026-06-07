<?php
$page_title = 'Tambah Pelanggan - ' . app_name();
$page_heading = 'Tambah Pelanggan';
$page_subheading = 'Tambah customer baru untuk mode PPPoE atau Static.';
$active_menu = 'customers';
$ppp_profiles = isset($ppp_profiles) && is_array($ppp_profiles) ? $ppp_profiles : array();
$location_options = isset($location_options) && is_array($location_options) ? $location_options : array();
$olt_options = isset($olt_options) && is_array($olt_options) ? $olt_options : array();
$teknisi_options = isset($teknisi_options) && is_array($teknisi_options) ? $teknisi_options : array();
$router_options = isset($router_options) && is_array($router_options) ? $router_options : array();
$selected_technician_id = isset($selected_technician_id) ? (int) $selected_technician_id : 0;
$selected_router_id = isset($selected_router_id) ? (int) $selected_router_id : 0;
$fields = isset($fields) && is_array($fields) ? $fields : array();

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

$csrf_name = $this->security->get_csrf_token_name();
$csrf_hash = $this->security->get_csrf_hash();
$install_field = $has_field('installation_date') ? 'installation_date' : 'install_date';
$selected_service_mode = strtolower((string) set_value('service_mode', 'pppoe'));
if (!in_array($selected_service_mode, array('pppoe', 'static'), true)) {
    $selected_service_mode = 'pppoe';
}

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Form Tambah Pelanggan</span>
        <span class="badge text-bg-warning">PROVISIONING AUTO</span>
    </div>
    <div class="card-body">
        <?php if ($this->session->flashdata('error')): ?>
        <div class="alert alert-danger"><?php echo html_escape($this->session->flashdata('error')); ?></div>
        <?php endif; ?>

        <?php if ($this->session->flashdata('success')): ?>
        <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('success')); ?></div>
        <?php endif; ?>

        <?php if (validation_errors()): ?>
        <div class="alert alert-danger"><?php echo validation_errors(); ?></div>
        <?php endif; ?>

        <?php if (empty($location_options) || empty($olt_options) || empty($router_options)): ?>
        <div class="alert alert-warning">
            Data dropdown belum lengkap.
            Silakan isi <a href="<?php echo site_url('master-references/locations'); ?>">Master Lokasi</a>
            , <a href="<?php echo site_url('master-references/olts'); ?>">Master OLT</a>,
            dan data <strong>Router</strong> terlebih dahulu.
            OLT hanya wajib untuk customer mode <strong>PPPoE</strong>.
        </div>
        <?php endif; ?>

        <?php echo form_open('customers/store', array('id' => 'customerCreateForm', 'class' => 'row g-4')); ?>
        <input type="hidden" name="<?php echo html_escape($csrf_name); ?>" id="csrf_token_field" value="<?php echo html_escape($csrf_hash); ?>">

        <div class="col-12">
            <div class="border rounded p-3">
                <div class="fw-semibold mb-3">1. Data Pelanggan</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nama Pelanggan</label>
                        <input type="text" class="form-control" name="full_name" id="full_name" value="<?php echo html_escape(set_value('full_name')); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Lokasi</label>
                        <select class="form-select text-uppercase" name="lokasi" id="lokasi" required>
                            <option value="">Pilih Lokasi</option>
                            <?php foreach ($location_options as $location): ?>
                            <option value="<?php echo html_escape($location); ?>" <?php echo set_select('lokasi', $location); ?>>
                                <?php echo html_escape($location); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Kelola data di menu <a href="<?php echo site_url('master-references/locations'); ?>">Master Lokasi</a>.
                        </div>
                    </div>
                    <div class="col-md-3 olt-field">
                        <label class="form-label">OLT</label>
                        <select class="form-select text-uppercase" name="olt" id="olt" required>
                            <option value="">Pilih OLT</option>
                            <?php foreach ($olt_options as $olt): ?>
                            <option value="<?php echo html_escape($olt); ?>" <?php echo set_select('olt', $olt); ?>>
                                <?php echo html_escape($olt); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Kelola data di menu <a href="<?php echo site_url('master-references/olts'); ?>">Master OLT</a>.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Assign Teknisi</label>
                        <select class="form-select" name="technician_id" id="technician_id">
                            <option value="">Pilih Teknisi</option>
                            <?php foreach ($teknisi_options as $tech): ?>
                            <?php
                                $tech_id = (int) ($tech['id'] ?? 0);
                                $tech_name = (string) ($tech['name'] ?? ('Teknisi #' . $tech_id));
                            ?>
                            <option value="<?php echo $tech_id; ?>" <?php echo (int) set_value('technician_id', $selected_technician_id) === $tech_id ? 'selected' : ''; ?>>
                                <?php echo html_escape($tech_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Teknisi ini akan otomatis jadi PIC Work Order pemasangan untuk KPI.</div>
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
                            <option value="<?php echo $rid; ?>" <?php echo (int) set_value('router_id', $selected_router_id) === $rid ? 'selected' : ''; ?>>
                                <?php echo html_escape($rname . ($rip !== '' ? ' (' . $rip . ')' : '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Digunakan untuk scope customer, dan provisioning PPP jika mode layanan PPPoE.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">No HP</label>
                        <div class="input-group">
                            <input
                                type="tel"
                                class="form-control"
                                name="phone"
                                id="phone"
                                inputmode="tel"
                                autocomplete="tel"
                                value="<?php echo html_escape(set_value('phone')); ?>"
                            >
                            <button type="button" class="btn btn-outline-secondary" id="phone_contact_picker_btn">Kontak</button>
                        </div>
                        <div class="form-text" id="phone_contact_picker_hint">Ambil nomor langsung dari kontak HP.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="email" value="<?php echo html_escape(set_value('email')); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Alamat</label>
                        <textarea class="form-control" name="address" id="address" rows="2"><?php echo html_escape(set_value('address')); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="border rounded p-3">
                <div class="fw-semibold mb-3">2. Data Instalasi</div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Instalasi</label>
                        <input type="date" class="form-control" name="<?php echo html_escape($install_field); ?>" value="<?php echo html_escape(set_value($install_field, date('Y-m-d'))); ?>" required>
                    </div>
                    <?php if ($has_field('due_date_day')): ?>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Jatuh Tempo</label>
                        <input
                            type="number"
                            class="form-control"
                            name="due_date_day"
                            min="1"
                            max="31"
                            value="<?php echo html_escape(set_value('due_date_day')); ?>"
                        >
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <label class="form-label">Latitude</label>
                        <input type="text" class="form-control" name="latitude" id="latitude" value="<?php echo html_escape(set_value('latitude')); ?>" placeholder="-6.9123">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Longitude</label>
                        <input type="text" class="form-control" name="longitude" id="longitude" value="<?php echo html_escape(set_value('longitude')); ?>" placeholder="107.6098">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Link Google Maps</label>
                        <input type="text" class="form-control" id="google_maps_link" readonly placeholder="https://maps.google.com/?q=lat,long">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="border rounded p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="fw-semibold">3. Mode Layanan &amp; Koneksi</div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnGenerateCredential">
                        Generate Username &amp; Password
                    </button>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Mode Layanan</label>
                        <select class="form-select" name="service_mode" id="service_mode">
                            <option value="pppoe" <?php echo $selected_service_mode === 'pppoe' ? 'selected' : ''; ?>>PPPoE</option>
                            <option value="static" <?php echo $selected_service_mode === 'static' ? 'selected' : ''; ?>>Static</option>
                        </select>
                        <div class="form-text">Mode `Static` tidak akan membuat PPP secret ke MikroTik.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Paket / Profile</label>
                        <select class="form-select" name="ppp_profile_id" id="ppp_profile_id" <?php echo $selected_service_mode === 'pppoe' ? 'required' : ''; ?>>
                            <option value="">Pilih Paket / Profile</option>
                            <?php foreach ($ppp_profiles as $profile): ?>
                            <?php $profile_id = (int) ($profile['id'] ?? 0); ?>
                            <option value="<?php echo $profile_id; ?>"
                                    data-price="<?php echo html_escape((string) ($profile['price'] ?? 0)); ?>"
                                    <?php echo set_select('ppp_profile_id', (string) $profile_id); ?>>
                                <?php echo html_escape($format_ppp_profile_label((string) ($profile['name'] ?? '-'))); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Untuk mode `Static`, paket/profile boleh dipilih agar harga paket ikut tersimpan.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Harga Profile</label>
                        <input type="text" class="form-control" id="profile_price_display" readonly placeholder="Rp 0">
                    </div>
                    <div class="col-md-6 pppoe-field">
                        <label class="form-label">Username PPP</label>
                        <input type="text" class="form-control" name="pppoe_username" id="pppoe_username" value="<?php echo html_escape(set_value('pppoe_username')); ?>">
                    </div>
                    <div class="col-md-6 pppoe-field">
                        <label class="form-label">Password PPP</label>
                        <input type="text" class="form-control" name="pppoe_password" id="pppoe_password" value="<?php echo html_escape(set_value('pppoe_password')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">IP Address</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="ip_address" id="ip_address" value="<?php echo html_escape(set_value('ip_address')); ?>" placeholder="Kosongkan untuk auto assign saat PPPoE">
                            <button type="button" class="btn btn-outline-primary" id="btnSuggestIp">Suggest IP</button>
                        </div>
                        <div class="form-text" id="ip_suggest_info">Klik tombol Suggest IP untuk cek IP free berikutnya dari pool profile.</div>
                    </div>
                    <div class="col-md-6 pppoe-field">
                        <label class="form-label">VLAN ID (Auto)</label>
                        <input type="text" class="form-control" id="vlan_id_display" readonly placeholder="Pilih profile terlebih dahulu">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <div class="form-control bg-light d-flex align-items-center justify-content-between">
                            <span>PENDING</span>
                            <span class="badge text-bg-warning">pending</span>
                        </div>
                        <input type="hidden" name="status" value="pending">
                        <?php if ($has_field('installation_status')): ?>
                        <input type="hidden" name="installation_status" value="waiting">
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Simpan Pelanggan</button>
            <a href="<?php echo site_url('customers'); ?>" class="btn btn-outline-secondary">Kembali</a>
        </div>
        <?php echo form_close(); ?>
    </div>
</div>

<script>
(function () {
    const latEl = document.getElementById('latitude');
    const lngEl = document.getElementById('longitude');
    const mapsEl = document.getElementById('google_maps_link');
    const nameEl = document.getElementById('full_name');
    const lokasiEl = document.getElementById('lokasi');
    const oltEl = document.getElementById('olt');
    const serviceModeEl = document.getElementById('service_mode');
    const userEl = document.getElementById('pppoe_username');
    const passEl = document.getElementById('pppoe_password');
    const ipAddressEl = document.getElementById('ip_address');
    const profileEl = document.getElementById('ppp_profile_id');
    const priceDisplayEl = document.getElementById('profile_price_display');
    const vlanDisplayEl = document.getElementById('vlan_id_display');
    const suggestInfoEl = document.getElementById('ip_suggest_info');
    const btnSuggestIp = document.getElementById('btnSuggestIp');
    const csrfField = document.getElementById('csrf_token_field');
    const btnGenerate = document.getElementById('btnGenerateCredential');
    const installDateEl = document.querySelector('input[name="installation_date"], input[name="install_date"]');
    const pppoeFields = document.querySelectorAll('.pppoe-field');
    const oltFieldWraps = document.querySelectorAll('.olt-field');

    function isPppoeMode() {
        return !serviceModeEl || String(serviceModeEl.value || 'pppoe').toLowerCase() !== 'static';
    }

    function updateMapLink() {
        if (!latEl || !lngEl || !mapsEl) {
            return;
        }
        const lat = (latEl.value || '').trim();
        const lng = (lngEl.value || '').trim();
        mapsEl.value = (lat !== '' && lng !== '')
            ? ('https://maps.google.com/?q=' + encodeURIComponent(lat + ',' + lng))
            : '';
    }

    function updateProfilePrice() {
        const selected = profileEl.options[profileEl.selectedIndex];
        const rawPrice = selected ? parseFloat(selected.getAttribute('data-price') || '0') : 0;
        const formatted = new Intl.NumberFormat('id-ID').format(isNaN(rawPrice) ? 0 : rawPrice);
        priceDisplayEl.value = 'Rp ' + formatted;
    }

    function detectVlanFromProfile() {
        const selected = profileEl.options[profileEl.selectedIndex];
        const label = selected ? (selected.textContent || '').toUpperCase() : '';
        let vlan = '';

        if (label.includes('10M') || label.includes('10 M')) {
            vlan = 'VID 1111';
        } else if (label.includes('20M') || label.includes('20 M')) {
            vlan = 'VID 200';
        } else if (label.includes('30M') || label.includes('30 M')) {
            vlan = 'VID 300';
        } else if (label.includes('50M') || label.includes('50 M')) {
            vlan = 'VID 500';
        }

        if (vlanDisplayEl) {
            vlanDisplayEl.value = vlan;
        }
    }

    function setSuggestInfo(message, level) {
        if (!suggestInfoEl) {
            return;
        }

        suggestInfoEl.classList.remove('text-muted', 'text-success', 'text-danger', 'text-warning');
        if (level === 'success') {
            suggestInfoEl.classList.add('text-success');
        } else if (level === 'error') {
            suggestInfoEl.classList.add('text-danger');
        } else if (level === 'warning') {
            suggestInfoEl.classList.add('text-warning');
        } else {
            suggestInfoEl.classList.add('text-muted');
        }

        suggestInfoEl.textContent = message || '';
    }

    function previewRemoteIp(forceFill) {
        if (!isPppoeMode()) {
            setSuggestInfo('Mode Static tidak memakai auto suggest IP PPP.', 'warning');
            return;
        }

        const profileId = (profileEl.value || '').trim();
        if (!profileId) {
            setSuggestInfo('Pilih PPP Profile terlebih dahulu.', 'warning');
            return;
        }

        const formData = new URLSearchParams();
        formData.append('ppp_profile_id', profileId);
        formData.append('<?php echo html_escape($csrf_name); ?>', csrfField.value);

        fetch('<?php echo site_url('customers/preview-remote-ip'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData.toString()
        })
        .then(response => response.json())
        .then(json => {
            if (json.csrf_token) {
                csrfField.value = json.csrf_token;
            }

            if (!json.success || !json.data) {
                setSuggestInfo(json.message || 'Belum ada IP free pada pool profile ini.', 'warning');
                return;
            }

            if (ipAddressEl && (forceFill || !ipAddressEl.value || ipAddressEl.value.trim() === '')) {
                ipAddressEl.value = json.data.ip_address || '';
            }

            const poolName = (json.data.pool_name || '').trim();
            const info = poolName !== ''
                ? ('Saran IP: ' + (json.data.ip_address || '-') + ' (Pool: ' + poolName + ')')
                : ('Saran IP: ' + (json.data.ip_address || '-'));
            setSuggestInfo(info, 'success');
        })
        .catch(() => {
            setSuggestInfo('Gagal request suggest IP ke server.', 'error');
        });
    }

    function applyServiceMode() {
        const isPppoe = isPppoeMode();

        pppoeFields.forEach(function (el) {
            if (!el) {
                return;
            }
            el.style.display = isPppoe ? '' : 'none';
        });

        oltFieldWraps.forEach(function (el) {
            if (!el) {
                return;
            }
            el.style.display = isPppoe ? '' : 'none';
        });

        if (profileEl) {
            profileEl.required = isPppoe;
        }
        if (userEl) {
            userEl.required = isPppoe;
        }
        if (passEl) {
            passEl.required = false;
        }
        if (btnGenerate) {
            btnGenerate.style.display = isPppoe ? '' : 'none';
        }
        if (btnSuggestIp) {
            btnSuggestIp.disabled = !isPppoe;
        }
        if (oltEl) {
            oltEl.required = isPppoe;
            oltEl.disabled = !isPppoe;
            if (!isPppoe) {
                oltEl.value = '';
            }
        }
        if (!isPppoe && vlanDisplayEl) {
            vlanDisplayEl.value = '';
        }
        if (!isPppoe) {
            setSuggestInfo('Mode Static tidak memakai auto suggest IP PPP.', 'warning');
        } else if (!profileEl.value) {
            setSuggestInfo('Klik tombol Suggest IP untuk cek IP free berikutnya dari pool profile.', 'muted');
        } else {
            previewRemoteIp(false);
        }
    }

    if (latEl && lngEl) {
        latEl.addEventListener('input', updateMapLink);
        lngEl.addEventListener('input', updateMapLink);
        updateMapLink();
    }

    if (serviceModeEl) {
        serviceModeEl.addEventListener('change', applyServiceMode);
    }

    profileEl.addEventListener('change', updateProfilePrice);
    profileEl.addEventListener('change', detectVlanFromProfile);
    profileEl.addEventListener('change', function () {
        if (isPppoeMode()) {
            previewRemoteIp(false);
        }
    });
    updateProfilePrice();
    detectVlanFromProfile();
    applyServiceMode();

    if (btnSuggestIp) {
        btnSuggestIp.addEventListener('click', function () {
            previewRemoteIp(true);
        });
    }

    btnGenerate.addEventListener('click', function () {
        if (!isPppoeMode()) {
            return;
        }

        const fullName = (nameEl.value || '').trim();
        const lokasi = (lokasiEl.value || '').trim();
        const olt = (oltEl.value || '').trim();
        const installDate = installDateEl ? (installDateEl.value || '').trim() : '';
        const profileId = (profileEl.value || '').trim();

        if (!fullName || !lokasi || !olt) {
            alert('Isi Nama Pelanggan, Lokasi, dan OLT terlebih dahulu.');
            return;
        }

        if (!installDate) {
            alert('Isi Tanggal Instalasi terlebih dahulu.');
            return;
        }

        const formData = new URLSearchParams();
        formData.append('full_name', fullName);
        formData.append('lokasi', lokasi);
        formData.append('olt', olt);
        formData.append('install_date', installDate);
        formData.append('ppp_profile_id', profileId);
        formData.append('<?php echo html_escape($csrf_name); ?>', csrfField.value);

        fetch('<?php echo site_url('customers/generate_credential'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData.toString()
        })
        .then(response => response.json())
        .then(json => {
            if (json.csrf_token) {
                csrfField.value = json.csrf_token;
            }
            if (!json.success) {
                alert(json.message || 'Gagal generate credential.');
                return;
            }
            userEl.value = json.data.username || '';
            passEl.value = json.data.password || '';
            if (vlanDisplayEl) {
                vlanDisplayEl.value = (json.data.vlan_id ? ('VID ' + json.data.vlan_id) : '');
            }
        })
        .catch(() => {
            alert('Terjadi error saat generate credential.');
        });
    });
})();
</script>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
