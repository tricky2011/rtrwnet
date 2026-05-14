<?php
$page_title = 'Customers - ' . app_name();
$page_heading = 'Customers';
$page_subheading = 'Daftar pelanggan dan ringkasan status billing.';
$active_menu = 'customers';
$customers = isset($customers) ? $customers : array();
$credential = $this->session->flashdata('credential');
$role = (string) $this->session->userdata('role');
$can_delete = in_array($role, array('superadmin', 'admin'), true);
$can_manage_bulk = $can_delete;
$search = isset($search) ? (string) $search : '';
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($customers);
$per_page = isset($per_page) ? (int) $per_page : 20;
$per_page_options = isset($per_page_options) && is_array($per_page_options) ? $per_page_options : array(20, 50, 100, 500);
$csrf_name = $this->security->get_csrf_token_name();
$csrf_hash = $this->security->get_csrf_hash();

$format_profile_label = function ($name, $fallback_id = 0) {
    $name = trim((string) $name);
    $fallback_id = (int) $fallback_id;
    if ($name === '') {
        return $fallback_id > 0 ? ('Profile #' . $fallback_id) : '-';
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

$parse_install_date = function ($password) {
    $password = trim((string) $password);
    if ($password === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $password)) {
        $parts = explode('-', $password);
        if (checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return $password;
        }
        return '';
    }

    if (preg_match('/^\d{8}$/', $password)) {
        $dd = (int) substr($password, 0, 2);
        $mm = (int) substr($password, 2, 2);
        $yyyy = (int) substr($password, 4, 4);
        if (checkdate($mm, $dd, $yyyy)) {
            return sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd);
        }
    }

    return '';
};

$status_meta = function ($status_raw) {
    $status_raw = strtolower(trim((string) $status_raw));
    if (in_array($status_raw, array('active', 'actived'), true)) {
        return array('label' => 'ACTIVED', 'class' => 'text-bg-success');
    }
    if (in_array($status_raw, array('suspended', 'isolir', 'isolated'), true)) {
        return array('label' => 'ISOLIR', 'class' => 'text-bg-warning');
    }
    if ($status_raw === 'terminated') {
        return array('label' => 'TERMINATED', 'class' => 'text-bg-danger');
    }
    return array('label' => strtoupper($status_raw !== '' ? $status_raw : '-'), 'class' => 'text-bg-secondary');
};

ob_start();
?>
<style>
@media (max-width: 767.98px) {
    .customer-top-actions {
        width: 100%;
    }
    .customer-top-actions .dropdown,
    .customer-top-actions .btn {
        width: 100%;
    }
    .customer-top-actions .dropdown .btn {
        width: 100%;
    }
    .customer-row-actions {
        width: 100%;
    }
    .customer-row-actions .btn {
        width: 100%;
    }
}
</style>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-2">
        <span>Customers Table</span>
        <div class="d-flex flex-wrap gap-2 align-items-center w-100 w-lg-auto customer-top-actions">
            <?php if ($can_manage_bulk): ?>
                <span id="selected_count" class="badge text-bg-secondary">0 selected</span>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Bulk Action
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li>
                            <button type="button" class="dropdown-item js-customer-bulk-action" data-action="disable">
                                Disable Selected
                            </button>
                        </li>
                        <li>
                            <button type="button" class="dropdown-item js-customer-bulk-action" data-action="invoice">
                                Generate Invoice
                            </button>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <button type="button" class="dropdown-item text-danger js-customer-bulk-action" data-action="delete">
                                Hapus Selected
                            </button>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
            <a href="<?php echo site_url('customers/create'); ?>" class="btn btn-sm btn-primary ms-lg-auto">Tambah Pelanggan</a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="p-3 border-bottom">
            <?php echo form_open('customers', array('method' => 'get', 'class' => 'row g-2 align-items-center', 'id' => 'customersFilterForm')); ?>
                <div class="col-md-5">
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        name="search"
                        placeholder="Cari nama / paket / username PPP / lokasi"
                        value="<?php echo html_escape($search); ?>"
                    >
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
                </div>
                <div class="col-auto">
                    <a href="<?php echo site_url('customers'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
                <div class="col text-muted small text-md-end">
                    Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> data
                </div>
            <?php echo form_close(); ?>
        </div>

        <?php if (!empty($credential) && is_array($credential)): ?>
        <div class="alert alert-info rounded-0 border-0 mb-0">
            Username PPP: <strong><?php echo html_escape((string) ($credential['username'] ?? '-')); ?></strong> |
            Password PPP: <strong><?php echo html_escape((string) ($credential['password'] ?? '-')); ?></strong>
        </div>
        <?php endif; ?>

        <?php if ($this->session->flashdata('success')): ?>
        <div class="alert alert-success rounded-0 border-0 mb-0"><?php echo html_escape($this->session->flashdata('success')); ?></div>
        <?php endif; ?>

        <?php if ($this->session->flashdata('error')): ?>
        <div class="alert alert-danger rounded-0 border-0 mb-0"><?php echo html_escape($this->session->flashdata('error')); ?></div>
        <?php endif; ?>

        <div class="table-responsive" data-mobile-table-mode="stack">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <?php if ($can_manage_bulk): ?>
                            <th class="ps-3" style="width:42px;">
                                <input type="checkbox" id="select_all" class="form-check-input">
                            </th>
                        <?php endif; ?>
                        <th class="ps-3">Nama</th>
                        <th>Paket</th>
                        <th>Teknisi</th>
                        <th>Status</th>
                        <th>Tanggal Pasang</th>
                        <th>Harga Paket</th>
                        <th class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                    <tr>
                        <td class="ps-3 text-muted" colspan="<?php echo $can_manage_bulk ? '8' : '7'; ?>">Belum ada data pelanggan.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $row): ?>
                        <?php
                        $customer_id = (int) (isset($row->id) ? $row->id : 0);
                        $status_raw = isset($row->status) ? strtolower((string) $row->status) : '-';
                        $status = $status_meta($status_raw);
                        $profile_name = isset($row->profile_name) ? (string) $row->profile_name : '';
                        $fallback_profile_id = 0;
                        if (isset($row->effective_profile_id) && (int) $row->effective_profile_id > 0) {
                            $fallback_profile_id = (int) $row->effective_profile_id;
                        } elseif (isset($row->ppp_profile_id) && (int) $row->ppp_profile_id > 0) {
                            $fallback_profile_id = (int) $row->ppp_profile_id;
                        } elseif (isset($row->profile_id) && (int) $row->profile_id > 0) {
                            $fallback_profile_id = (int) $row->profile_id;
                        }
                        $profile_label = $format_profile_label($profile_name, $fallback_profile_id);
                        $package_price = 0.0;
                        if (isset($row->service_price) && (float) $row->service_price > 0) {
                            $package_price = (float) $row->service_price;
                        } elseif (isset($row->profile_price) && (float) $row->profile_price > 0) {
                            $package_price = (float) $row->profile_price;
                        } elseif (isset($row->package_price) && (float) $row->package_price > 0) {
                            $package_price = (float) $row->package_price;
                        }

                        $install_date = '';
                        $secret_password = isset($row->secret_ppp_password) ? (string) $row->secret_ppp_password : '';
                        if ($secret_password !== '') {
                            $install_date = $parse_install_date($secret_password);
                        }
                        if ($install_date === '' && !empty($row->install_date)) {
                            $install_date = $parse_install_date((string) $row->install_date);
                        }
                        if ($install_date === '' && !empty($row->join_date)) {
                            $install_date = $parse_install_date((string) $row->join_date);
                        }
                        $install_date_label = $install_date !== '' ? date('d-m-Y', strtotime($install_date)) : '-';
                        $display_name = !empty($row->full_name)
                            ? (string) $row->full_name
                            : (!empty($row->nama) ? (string) $row->nama : '-');
                        $technician_name = trim((string) ($row->technician_name ?? ''));
                        if ($technician_name === '' && !empty($row->technician_id)) {
                            $technician_name = 'ID ' . (int) $row->technician_id;
                        }
                        if ($technician_name === '') {
                            $technician_name = '-';
                        }
                        ?>
                        <tr>
                            <?php if ($can_manage_bulk): ?>
                                <td class="ps-3">
                                    <input type="checkbox" name="customer_ids[]" value="<?php echo $customer_id; ?>" class="form-check-input customer_checkbox">
                                </td>
                            <?php endif; ?>
                            <td class="ps-3"><?php echo html_escape($display_name); ?></td>
                            <td><?php echo html_escape($profile_label); ?></td>
                            <td><?php echo html_escape($technician_name); ?></td>
                            <td>
                                <span class="badge <?php echo html_escape($status['class']); ?>"><?php echo html_escape($status['label']); ?></span>
                            </td>
                            <td><?php echo html_escape($install_date_label); ?></td>
                            <td>Rp <?php echo number_format($package_price, 0, ',', '.'); ?></td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex flex-column flex-sm-row gap-1 customer-row-actions">
                                    <a href="<?php echo site_url('customers/edit/' . $customer_id); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <?php if ($can_delete): ?>
                                        <?php echo form_open('customers/delete/' . $customer_id, array('class' => 'm-0')); ?>
                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Yakin hapus pelanggan ini?')"
                                        >
                                            Hapus
                                        </button>
                                        <?php echo form_close(); ?>
                                    <?php endif; ?>
                                </div>
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
                <div class="d-flex flex-wrap gap-1" role="group" aria-label="customers-page-view-buttons">
                    <?php foreach ($per_page_options as $option): ?>
                        <?php $option = (int) $option; ?>
                        <?php $input_id = 'customers_per_page_' . $option; ?>
                        <input
                            class="btn-check"
                            type="radio"
                            name="per_page"
                            id="<?php echo $input_id; ?>"
                            form="customersFilterForm"
                            value="<?php echo $option; ?>"
                            autocomplete="off"
                            onchange="document.getElementById('customersFilterForm').submit();"
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
<?php
$content = ob_get_clean();

if ($can_manage_bulk) {
    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    (function() {
        const selectAll = document.getElementById('select_all');
        const selectedCount = document.getElementById('selected_count');
        const csrfName = <?php echo json_encode($csrf_name); ?>;
        let csrfHash = <?php echo json_encode($csrf_hash); ?>;

        function getCheckboxes() {
            return Array.from(document.querySelectorAll('.customer_checkbox'));
        }

        function getSelectedIds() {
            return getCheckboxes()
                .filter(cb => cb.checked)
                .map(cb => cb.value)
                .filter(v => v !== '');
        }

        function updateSelectedCount() {
            const checked = getSelectedIds().length;
            if (selectedCount) {
                selectedCount.textContent = checked + ' selected';
            }
        }

        function syncSelectAllState() {
            const checkboxes = getCheckboxes();
            if (!selectAll || checkboxes.length === 0) {
                return;
            }

            const checkedCount = checkboxes.filter(cb => cb.checked).length;
            selectAll.checked = checkedCount === checkboxes.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        }

        function buildRequestBody(ids) {
            const params = new URLSearchParams();
            params.append(csrfName, csrfHash);
            ids.forEach(id => params.append('ids[]', id));
            return params.toString();
        }

        async function postBulk(url, ids) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: buildRequestBody(ids)
            });

            if (response.status === 403) {
                throw new Error('Token keamanan kadaluarsa. Silakan reload halaman lalu coba lagi.');
            }

            const text = await response.text();
            let data = null;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error('Response server tidak valid. Reload halaman lalu coba lagi.');
            }

            if (data && typeof data.csrf_token === 'string' && data.csrf_token !== '') {
                csrfHash = data.csrf_token;
            }

            if (!response.ok || !data || !data.status) {
                throw new Error((data && data.message) ? data.message : 'Request gagal.');
            }

            return data;
        }

        async function handleBulkAction(options) {
            const ids = getSelectedIds();
            if (ids.length === 0) {
                await Swal.fire('Warning', 'Pilih minimal 1 customer.', 'warning');
                return;
            }

            const confirm = await Swal.fire({
                title: options.confirmTitle,
                text: options.confirmText,
                icon: options.confirmIcon || 'question',
                showCancelButton: true,
                confirmButtonText: options.confirmButtonText || 'Lanjut',
                cancelButtonText: 'Batal'
            });

            if (!confirm.isConfirmed) {
                return;
            }

            try {
                const result = await postBulk(options.url, ids);
                await Swal.fire({
                    icon: result.status === 'error' ? 'error' : (result.status === 'partial' ? 'warning' : 'success'),
                    title: result.status === 'error' ? 'Gagal' : 'Selesai',
                    text: result.message || 'Proses selesai.'
                });
                window.location.reload();
            } catch (error) {
                await Swal.fire('Error', error.message || 'Terjadi kesalahan.', 'error');
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function() {
                const checked = this.checked;
                getCheckboxes().forEach(cb => {
                    cb.checked = checked;
                });
                updateSelectedCount();
                syncSelectAllState();
            });
        }

        getCheckboxes().forEach(cb => {
            cb.addEventListener('change', function() {
                updateSelectedCount();
                syncSelectAllState();
            });
        });

        const bulkActionMap = {
            'delete': {
                url: <?php echo json_encode(site_url('customers/bulk_delete')); ?>,
                confirmTitle: 'Hapus customer terpilih?',
                confirmText: 'Data customer terpilih akan dihapus.',
                confirmIcon: 'warning',
                confirmButtonText: 'Ya, hapus'
            },
            'disable': {
                url: <?php echo json_encode(site_url('customers/bulk_disable')); ?>,
                confirmTitle: 'Disable customer terpilih?',
                confirmText: 'Status customer akan di-suspend dan PPP Secret di MikroTik di-disable.',
                confirmIcon: 'warning',
                confirmButtonText: 'Ya, disable'
            },
            'invoice': {
                url: <?php echo json_encode(site_url('customers/bulk_generate_invoice')); ?>,
                confirmTitle: 'Generate invoice customer terpilih?',
                confirmText: 'Invoice periode bulan berjalan akan dibuat untuk customer yang belum punya invoice.',
                confirmIcon: 'question',
                confirmButtonText: 'Ya, generate'
            }
        };

        document.querySelectorAll('.js-customer-bulk-action').forEach(btn => {
            btn.addEventListener('click', function() {
                const action = this.getAttribute('data-action');
                if (!action || !bulkActionMap[action]) {
                    return;
                }
                handleBulkAction(bulkActionMap[action]);
            });
        });

        updateSelectedCount();
        syncSelectAllState();
    })();
    </script>
    <?php
    $page_scripts = ob_get_clean();
}

include APPPATH . 'views/layout/master.php';
