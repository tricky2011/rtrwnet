<?php
$page_title = 'Work Orders - ' . app_name();
$page_heading = 'Work Order List';
$page_subheading = 'Monitoring progres WO instalasi dan perbaikan.';
$active_menu = 'workorders';
$rows = isset($rows) && is_array($rows) ? $rows : array();
$search = isset($search) ? (string) $search : '';
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($rows);
$filter_month = isset($filter_month) ? (int) $filter_month : (int) date('m');
$filter_year = isset($filter_year) ? (int) $filter_year : (int) date('Y');
$months = isset($months) && is_array($months) ? $months : array();
$years = isset($years) && is_array($years) ? $years : array((int) date('Y'));
$selected_period_label = isset($selected_period_label) ? (string) $selected_period_label : date('F Y');
$can_create = isset($can_create) ? (bool) $can_create : in_array((string) $this->session->userdata('role'), array('superadmin', 'admin'), true);
$current_role = (string) $this->session->userdata('role');
$can_delete = in_array($current_role, array('superadmin', 'admin'), true);
$customer_options = isset($customer_options) && is_array($customer_options) ? $customer_options : array();
$teknisi_options = isset($teknisi_options) && is_array($teknisi_options) ? $teknisi_options : array();

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">WO List</div>
    <div class="card-body p-0">
        <?php if ($this->session->flashdata('success')): ?>
            <div class="alert alert-success rounded-0 mb-0 border-0 border-bottom">
                <?php echo html_escape((string) $this->session->flashdata('success')); ?>
            </div>
        <?php endif; ?>
        <?php if ($this->session->flashdata('error')): ?>
            <div class="alert alert-danger rounded-0 mb-0 border-0 border-bottom">
                <?php echo html_escape((string) $this->session->flashdata('error')); ?>
            </div>
        <?php endif; ?>

        <div class="p-3 border-bottom">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-2">
                <div class="small text-muted">
                    Kelola WO existing/manual untuk pemasangan sebelum aplikasi aktif.
                    <span class="mx-1">|</span>
                    Periode: <strong><?php echo html_escape($selected_period_label); ?></strong>
                </div>
                <?php if ($can_create): ?>
                <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#createWoModal"
                >
                    + Input Work Order
                </button>
                <?php endif; ?>
            </div>

            <?php echo form_open('workorders', array('method' => 'get', 'class' => 'row g-2 align-items-end')); ?>
                <div class="col-lg-4">
                    <label class="form-label form-label-sm mb-1">Search</label>
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        name="search"
                        placeholder="Cari WO number / customer"
                        value="<?php echo html_escape($search); ?>"
                    >
                </div>
                <div class="col-lg-2 col-md-3">
                    <label class="form-label form-label-sm mb-1">Bulan</label>
                    <select name="month" class="form-select form-select-sm">
                        <?php foreach ($months as $month_no => $month_label): ?>
                        <option value="<?php echo (int) $month_no; ?>" <?php echo $filter_month === (int) $month_no ? 'selected' : ''; ?>>
                            <?php echo html_escape((string) $month_label); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3">
                    <label class="form-label form-label-sm mb-1">Tahun</label>
                    <select name="year" class="form-select form-select-sm">
                        <?php foreach ($years as $year): ?>
                        <option value="<?php echo (int) $year; ?>" <?php echo $filter_year === (int) $year ? 'selected' : ''; ?>>
                            <?php echo (int) $year; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3 d-grid">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
                </div>
                <div class="col-lg-2 col-md-3 d-grid">
                    <a href="<?php echo site_url('workorders'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
                <div class="col-12 text-muted small text-md-end">
                    Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> data
                </div>
            <?php echo form_close(); ?>
        </div>

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">WO Number</th>
                        <th>Customer</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Schedule</th>
                        <th class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr>
                        <td class="ps-3 text-muted" colspan="6">Tidak ada data work order.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                        <?php
                        $status = strtolower((string) ($r['status'] ?? ''));
                        $label = strtoupper($status);
                        if ($label === 'IN_PROGRESS') {
                            $label = 'PROCESS';
                        } elseif ($label === 'COMPLETED') {
                            $label = 'DONE';
                        }
                        $type = (string) ($r['wo_type'] ?? $r['type'] ?? '-');
                        $can_mark_done = (int) ($r['id'] ?? 0) > 0
                            && in_array($status, array('open', 'process', 'in_progress', 'pending', 'new', 'assigned'), true);
                        $can_delete_row = $can_delete && (int) ($r['id'] ?? 0) > 0;
                        $schedule = (string) ($r['scheduled_start_at'] ?? $r['requested_date'] ?? '-');
                        $delete_confirm = $current_role === 'superadmin'
                            ? 'Hapus WO ini secara permanen?'
                            : 'Hapus WO ini? (Role admin: soft delete)';
                        ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?php echo html_escape((string) ($r['wo_number'] ?? '-')); ?></td>
                            <td><?php echo html_escape((string) ($r['customer_name'] ?? '-')); ?></td>
                            <td><?php echo html_escape(strtoupper($type)); ?></td>
                            <td>
                                <?php if ($label === 'OPEN' || $label === 'ASSIGNED'): ?>
                                <span class="badge text-bg-secondary"><?php echo html_escape($label); ?></span>
                                <?php elseif ($label === 'PROCESS' || $label === 'IN_PROGRESS'): ?>
                                <span class="badge text-bg-primary">PROCESS</span>
                                <?php else: ?>
                                <span class="badge text-bg-success"><?php echo html_escape($label !== '' ? $label : 'DONE'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo html_escape($schedule); ?></td>
                            <td class="text-end pe-3">
                                <?php if ($can_mark_done): ?>
                                    <?php echo form_open('workorders/mark_done/' . (int) ($r['id'] ?? 0), array('class' => 'd-inline')); ?>
                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-success"
                                            onclick="return confirm('Ubah status WO ke DONE?');"
                                        >DONE</button>
                                    <?php echo form_close(); ?>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled>DONE</button>
                                <?php endif; ?>

                                <?php if ($can_delete_row): ?>
                                    <?php echo form_open('workorders/delete/' . (int) ($r['id'] ?? 0), array('class' => 'd-inline')); ?>
                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('<?php echo html_escape($delete_confirm); ?>');"
                                        >Hapus</button>
                                    <?php echo form_close(); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pagination !== ''): ?>
            <div class="p-3 border-top d-flex justify-content-end">
                <?php echo $pagination; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($can_create): ?>
<div class="modal fade" id="createWoModal" tabindex="-1" aria-labelledby="createWoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createWoModalLabel">Input Work Order Manual</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <?php echo form_open('workorders/store'); ?>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" id="wo_customer_id" class="form-select js-searchable-select" required>
                            <option value="">- Pilih Customer -</option>
                            <?php foreach ($customer_options as $cust): ?>
                            <option value="<?php echo (int) ($cust['id'] ?? 0); ?>">
                                <?php echo html_escape((string) ($cust['customer_name'] ?? ('Customer #' . (int) ($cust['id'] ?? 0)))); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tipe WO</label>
                        <select name="wo_type" class="form-select" required>
                            <option value="installation">Installation</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="relocation">Relocation</option>
                            <option value="termination">Termination</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Prioritas</label>
                        <select name="priority" class="form-select" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Judul WO</label>
                        <input
                            type="text"
                            name="title"
                            class="form-control"
                            placeholder="Contoh: Pemasangan Baru Existing - Customer Lama"
                            maxlength="200"
                            required
                        >
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Deskripsi</label>
                        <textarea
                            name="description"
                            class="form-control"
                            rows="3"
                            placeholder="Detail pekerjaan, catatan teknis, kebutuhan kunjungan, dll."
                        ></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tanggal Request</label>
                        <input type="date" name="requested_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Jadwal Start</label>
                        <input type="datetime-local" name="scheduled_start_at" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Assign Teknisi</label>
                        <select name="assigned_to" class="form-select">
                            <option value="0">- Belum di-assign -</option>
                            <?php foreach ($teknisi_options as $tech): ?>
                            <option value="<?php echo (int) ($tech['id'] ?? 0); ?>">
                                <?php echo html_escape((string) ($tech['name'] ?? ('Teknisi #' . (int) ($tech['id'] ?? 0)))); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Work Order</button>
            </div>
            <?php echo form_close(); ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.initSearchableSelect === 'function') {
        window.initSearchableSelect('#wo_customer_id', {
            searchPlaceholderValue: 'Cari customer / area...'
        });
    }
});
</script>
<?php
$page_scripts = (isset($page_scripts) ? $page_scripts : '') . ob_get_clean();
include APPPATH . 'views/layout/master.php';
