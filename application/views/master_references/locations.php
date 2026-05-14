<?php
$page_title = 'Master Lokasi - ' . app_name();
$page_heading = 'Master Lokasi';
$page_subheading = 'Kelola daftar lokasi untuk dropdown form pelanggan.';
$active_menu = 'master_locations';
$rows = isset($rows) && is_array($rows) ? $rows : array();
$search = isset($search) ? (string) $search : '';
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($rows);
$per_page = isset($per_page) ? (int) $per_page : 20;
$per_page_options = isset($per_page_options) && is_array($per_page_options) ? $per_page_options : array(20, 50, 100, 500);
$build_maps_url = function ($lat, $lng) {
    $lat = trim((string) $lat);
    $lng = trim((string) $lng);
    if ($lat === '' || $lng === '') {
        return '';
    }

    return 'https://maps.google.com/?q=' . rawurlencode($lat . ',' . $lng);
};

ob_start();
?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card stat-card">
            <div class="card-header bg-white fw-semibold">Tambah Lokasi</div>
            <div class="card-body">
                <?php echo form_open('master-references/store-location', array('class' => 'js-map-coordinate-form')); ?>
                    <div class="mb-2">
                        <label class="form-label">Nama Lokasi</label>
                        <input type="text" class="form-control" name="name" required maxlength="120" placeholder="Contoh: KLS">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Latitude</label>
                            <input type="text" class="form-control js-latitude" name="latitude" placeholder="-6.9123">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Longitude</label>
                            <input type="text" class="form-control js-longitude" name="longitude" placeholder="107.6098">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Link Google Maps</label>
                        <div class="input-group">
                            <input type="text" class="form-control js-map-link" readonly placeholder="https://maps.google.com/?q=lat,long">
                            <button type="button" class="btn btn-outline-primary js-open-map-btn" disabled>Buka</button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Deskripsi</label>
                        <textarea class="form-control" name="description" rows="2" placeholder="Opsional"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Simpan Lokasi</button>
                <?php echo form_close(); ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card stat-card">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Daftar Lokasi</span>
                <span id="selected_locations_count" class="badge text-bg-secondary">0 selected</span>
            </div>
            <div class="card-body p-0">
                <div class="p-3 border-bottom">
                    <?php echo form_open('master-references/locations', array('method' => 'get', 'class' => 'row g-2 align-items-center', 'id' => 'locationsFilterForm')); ?>
                        <div class="col-md-5">
                            <input
                                type="text"
                                class="form-control form-control-sm"
                                name="search"
                                placeholder="Cari nama/deskripsi lokasi"
                                value="<?php echo html_escape($search); ?>"
                            >
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
                        </div>
                        <div class="col-auto">
                            <a href="<?php echo site_url('master-references/locations'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                        </div>
                        <div class="col text-muted small text-md-end">
                            Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> data
                        </div>
                    <?php echo form_close(); ?>
                </div>

                <div class="p-3 border-bottom d-flex flex-wrap gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-success" id="bulk_location_activate">Bulk Aktifkan</button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="bulk_location_deactivate">Bulk Nonaktifkan</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="bulk_location_delete">Bulk Hapus</button>
                </div>

                <?php if ($this->session->flashdata('success')): ?>
                <div class="alert alert-success rounded-0 border-0 mb-0"><?php echo html_escape($this->session->flashdata('success')); ?></div>
                <?php endif; ?>
                <?php if ($this->session->flashdata('error')): ?>
                <div class="alert alert-danger rounded-0 border-0 mb-0"><?php echo $this->session->flashdata('error'); ?></div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="width: 42px;">
                                    <input type="checkbox" id="select_all_locations" class="form-check-input">
                                </th>
                                <th>Nama</th>
                                <th>Deskripsi</th>
                                <th>Koordinat</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="6" class="ps-3 text-muted">Belum ada data lokasi.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $id = (int) ($row['id'] ?? 0);
                                $name = (string) ($row['name'] ?? '');
                                $description = (string) ($row['description'] ?? '');
                                $latitude = trim((string) ($row['latitude'] ?? ''));
                                $longitude = trim((string) ($row['longitude'] ?? ''));
                                $maps_url = $build_maps_url($latitude, $longitude);
                                $is_active = (int) ($row['is_active'] ?? 1) === 1;
                                ?>
                                <tr>
                                    <td class="ps-3">
                                        <input type="checkbox" class="form-check-input location-checkbox" value="<?php echo $id; ?>">
                                    </td>
                                    <td class="fw-semibold"><?php echo html_escape($name); ?></td>
                                    <td><?php echo html_escape($description !== '' ? $description : '-'); ?></td>
                                    <td>
                                        <?php if ($maps_url !== ''): ?>
                                            <div class="small"><?php echo html_escape($latitude . ', ' . $longitude); ?></div>
                                            <a href="<?php echo html_escape($maps_url); ?>" target="_blank" rel="noopener" class="small">Buka Maps</a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $is_active ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                                            <?php echo $is_active ? 'AKTIF' : 'NONAKTIF'; ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-3">
                                        <button
                                            class="btn btn-sm btn-outline-primary"
                                            type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#editLocation<?php echo $id; ?>"
                                        >
                                            Edit
                                        </button>
                                        <?php echo form_open('master-references/delete-location/' . $id, array('class' => 'd-inline')); ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus lokasi ini?');">Hapus</button>
                                        <?php echo form_close(); ?>
                                    </td>
                                </tr>
                                <tr class="collapse" id="editLocation<?php echo $id; ?>">
                                    <td colspan="6" class="bg-light">
                                        <?php echo form_open('master-references/update-location/' . $id, array('class' => 'row g-2 align-items-end js-map-coordinate-form')); ?>
                                            <div class="col-md-3">
                                                <label class="form-label mb-1">Nama</label>
                                                <input type="text" class="form-control form-control-sm" name="name" value="<?php echo html_escape($name); ?>" required>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label mb-1">Deskripsi</label>
                                                <input type="text" class="form-control form-control-sm" name="description" value="<?php echo html_escape($description); ?>">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label mb-1">Latitude</label>
                                                <input type="text" class="form-control form-control-sm js-latitude" name="latitude" value="<?php echo html_escape($latitude); ?>" placeholder="-6.9123">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label mb-1">Longitude</label>
                                                <input type="text" class="form-control form-control-sm js-longitude" name="longitude" value="<?php echo html_escape($longitude); ?>" placeholder="107.6098">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label mb-1">Status</label>
                                                <select class="form-select form-select-sm" name="is_active">
                                                    <option value="1" <?php echo $is_active ? 'selected' : ''; ?>>Aktif</option>
                                                    <option value="0" <?php echo !$is_active ? 'selected' : ''; ?>>Nonaktif</option>
                                                </select>
                                            </div>
                                            <div class="col-md-8">
                                                <label class="form-label mb-1">Link Google Maps</label>
                                                <div class="input-group input-group-sm">
                                                    <input type="text" class="form-control js-map-link" readonly placeholder="https://maps.google.com/?q=lat,long">
                                                    <button type="button" class="btn btn-outline-primary js-open-map-btn" disabled>Buka</button>
                                                </div>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                            </div>
                                        <?php echo form_close(); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-3 border-top d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <div class="small text-muted mb-1">Page View</div>
                        <div class="d-flex flex-wrap gap-1" role="group" aria-label="locations-page-view-buttons">
                            <?php foreach ($per_page_options as $option): ?>
                                <?php $option = (int) $option; ?>
                                <?php $input_id = 'locations_per_page_' . $option; ?>
                                <input
                                    class="btn-check"
                                    type="radio"
                                    name="per_page"
                                    id="<?php echo $input_id; ?>"
                                    form="locationsFilterForm"
                                    value="<?php echo $option; ?>"
                                    autocomplete="off"
                                    onchange="document.getElementById('locationsFilterForm').submit();"
                                    <?php echo $per_page === $option ? 'checked' : ''; ?>
                                >
                                <label class="btn btn-outline-primary btn-sm px-2 py-1" for="<?php echo $input_id; ?>">
                                    <?php echo $option; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if ($pagination !== ''): ?>
                        <div class="ms-md-auto"><?php echo $pagination; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php echo form_open('master-references/bulk-update-locations', array('id' => 'bulkLocationUpdateForm', 'class' => 'd-none')); ?>
    <input type="hidden" name="ids" id="bulkLocationUpdateIds" value="">
    <input type="hidden" name="is_active" id="bulkLocationIsActive" value="1">
<?php echo form_close(); ?>

<?php echo form_open('master-references/bulk-delete-locations', array('id' => 'bulkLocationDeleteForm', 'class' => 'd-none')); ?>
    <input type="hidden" name="ids" id="bulkLocationDeleteIds" value="">
<?php echo form_close(); ?>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
(function() {
    const selectAll = document.getElementById('select_all_locations');
    const checkboxes = Array.from(document.querySelectorAll('.location-checkbox'));
    const selectedCount = document.getElementById('selected_locations_count');
    const coordinateForms = Array.from(document.querySelectorAll('.js-map-coordinate-form'));

    function selectedIds() {
        return checkboxes.filter(cb => cb.checked).map(cb => cb.value).filter(v => v !== '');
    }

    function updateCounter() {
        const count = selectedIds().length;
        if (selectedCount) {
            selectedCount.textContent = count + ' selected';
        }

        if (!selectAll || checkboxes.length === 0) {
            return;
        }

        selectAll.checked = count === checkboxes.length;
        selectAll.indeterminate = count > 0 && count < checkboxes.length;
    }

    function buildMapUrl(lat, lng) {
        const latValue = String(lat || '').trim();
        const lngValue = String(lng || '').trim();
        if (latValue === '' || lngValue === '') {
            return '';
        }

        return 'https://maps.google.com/?q=' + encodeURIComponent(latValue + ',' + lngValue);
    }

    function bindCoordinateForm(formEl) {
        const latEl = formEl.querySelector('.js-latitude');
        const lngEl = formEl.querySelector('.js-longitude');
        const mapEl = formEl.querySelector('.js-map-link');
        const openBtn = formEl.querySelector('.js-open-map-btn');

        if (!latEl || !lngEl || !mapEl) {
            return;
        }

        function updateMapLink() {
            const url = buildMapUrl(latEl.value, lngEl.value);
            mapEl.value = url;
            if (openBtn) {
                openBtn.disabled = (url === '');
            }
        }

        latEl.addEventListener('input', updateMapLink);
        lngEl.addEventListener('input', updateMapLink);
        if (openBtn) {
            openBtn.addEventListener('click', function () {
                const url = buildMapUrl(latEl.value, lngEl.value);
                if (url !== '') {
                    window.open(url, '_blank', 'noopener');
                }
            });
        }

        updateMapLink();
    }

    function submitBulkUpdate(statusValue) {
        const ids = selectedIds();
        if (ids.length === 0) {
            alert('Pilih minimal 1 lokasi.');
            return;
        }

        document.getElementById('bulkLocationUpdateIds').value = ids.join(',');
        document.getElementById('bulkLocationIsActive').value = statusValue;
        document.getElementById('bulkLocationUpdateForm').submit();
    }

    function submitBulkDelete() {
        const ids = selectedIds();
        if (ids.length === 0) {
            alert('Pilih minimal 1 lokasi.');
            return;
        }

        if (!window.confirm('Hapus semua lokasi terpilih?')) {
            return;
        }

        document.getElementById('bulkLocationDeleteIds').value = ids.join(',');
        document.getElementById('bulkLocationDeleteForm').submit();
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
            });
            updateCounter();
        });
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateCounter);
    });

    const bulkActivateBtn = document.getElementById('bulk_location_activate');
    if (bulkActivateBtn) {
        bulkActivateBtn.addEventListener('click', function() {
            submitBulkUpdate('1');
        });
    }

    const bulkDeactivateBtn = document.getElementById('bulk_location_deactivate');
    if (bulkDeactivateBtn) {
        bulkDeactivateBtn.addEventListener('click', function() {
            submitBulkUpdate('0');
        });
    }

    const bulkDeleteBtn = document.getElementById('bulk_location_delete');
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', submitBulkDelete);
    }

    coordinateForms.forEach(bindCoordinateForm);
    updateCounter();
})();
</script>
<?php
$page_scripts = ob_get_clean();

include APPPATH . 'views/layout/master.php';
