<?php
$page_title = 'Helpdesk Tickets - ' . app_name();
$page_heading = 'Tiket Gangguan / Maintenance';
$page_subheading = 'Input tiket prioritas dan submit teknisi hingga DONE.';
$active_menu = 'tickets';

$rows = isset($rows) && is_array($rows) ? $rows : array();
$search = isset($search) ? (string) $search : '';
$status_filter = isset($status_filter) ? strtolower((string) $status_filter) : '';
$priority_filter = isset($priority_filter) ? strtolower((string) $priority_filter) : '';
$status_options = isset($status_options) && is_array($status_options) ? $status_options : array(
    '' => 'Semua Status',
    'open' => 'OPEN',
    'in_progress' => 'PROCESS',
    'resolved' => 'DONE/RESOLVED',
    'closed' => 'CLOSED',
);
$priority_options = isset($priority_options) && is_array($priority_options) ? $priority_options : array(
    '' => 'Semua Prioritas',
    'low' => 'LOW',
    'medium' => 'MEDIUM',
    'high' => 'HIGH',
    'critical' => 'CRITICAL',
);
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($rows);
$per_page = isset($per_page) ? (int) $per_page : 20;
$per_page_options = isset($per_page_options) && is_array($per_page_options) ? $per_page_options : array(20, 50, 100, 500);
$role = isset($role) ? (string) $role : (string) $this->session->userdata('role');
$can_create = isset($can_create) ? (bool) $can_create : in_array($role, array('superadmin', 'admin'), true);
$teknisi_options = isset($teknisi_options) && is_array($teknisi_options) ? $teknisi_options : array();
$customer_options = isset($customer_options) && is_array($customer_options) ? $customer_options : array();

if (!function_exists('ticket_status_badge_meta')) {
    function ticket_status_badge_meta($status_raw)
    {
        $status_raw = strtolower(trim((string) $status_raw));
        if (in_array($status_raw, array('open', 'new', 'assigned'), true)) {
            return array('OPEN', 'text-bg-secondary');
        }
        if (in_array($status_raw, array('in_progress', 'process', 'working'), true)) {
            return array('PROCESS', 'text-bg-primary');
        }
        if (in_array($status_raw, array('resolved', 'done', 'completed'), true)) {
            return array('DONE', 'text-bg-success');
        }
        if (in_array($status_raw, array('closed', 'cancel', 'cancelled'), true)) {
            return array('CLOSED', 'text-bg-dark');
        }

        return array(strtoupper($status_raw !== '' ? $status_raw : '-'), 'text-bg-light border');
    }
}

if (!function_exists('ticket_priority_badge_meta')) {
    function ticket_priority_badge_meta($priority_raw)
    {
        $priority_raw = strtolower(trim((string) $priority_raw));
        if ($priority_raw === 'critical') {
            return array('CRITICAL', 'text-bg-danger');
        }
        if ($priority_raw === 'high') {
            return array('HIGH', 'text-bg-warning');
        }
        if ($priority_raw === 'medium') {
            return array('MEDIUM', 'text-bg-info');
        }

        return array('LOW', 'text-bg-light border');
    }
}

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">Helpdesk Ticket List</div>
    <div class="card-body p-0">
        <?php if ($this->session->flashdata('success')): ?>
        <div class="alert alert-success rounded-0 border-0 mb-0"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
        <?php endif; ?>
        <?php if ($this->session->flashdata('error')): ?>
        <div class="alert alert-danger rounded-0 border-0 mb-0"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
        <?php endif; ?>

        <?php if ($can_create): ?>
        <div class="p-3 border-bottom bg-light-subtle d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
            <div>
                <div class="fw-semibold">Input Tiket Baru (Gangguan / Maintenance)</div>
                <div class="small text-muted">Popup input tiket dengan nama user dan area pelanggan.</div>
            </div>
            <button
                type="button"
                class="btn btn-sm btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#createTicketModal"
            >
                <i class="bi bi-plus-circle me-1"></i>Input Ticket Baru
            </button>
        </div>
        <?php endif; ?>

        <div class="p-3 border-bottom">
            <?php echo form_open('tickets', array('method' => 'get', 'class' => 'row g-2 align-items-end', 'id' => 'ticketsFilterForm')); ?>
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-1">Search</label>
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        name="search"
                        placeholder="Cari ticket / issue / teknisi"
                        value="<?php echo html_escape($search); ?>"
                    >
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <?php foreach ($status_options as $key => $label): ?>
                            <option value="<?php echo html_escape((string) $key); ?>" <?php echo $status_filter === (string) $key ? 'selected' : ''; ?>>
                                <?php echo html_escape((string) $label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-1">Prioritas</label>
                    <select name="priority" class="form-select form-select-sm">
                        <?php foreach ($priority_options as $key => $label): ?>
                            <option value="<?php echo html_escape((string) $key); ?>" <?php echo $priority_filter === (string) $key ? 'selected' : ''; ?>>
                                <?php echo html_escape((string) $label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">Filter</button>
                    <a href="<?php echo site_url('tickets'); ?>" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
                </div>
                <div class="col-12 text-muted small">
                    Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> tiket
                </div>
            <?php echo form_close(); ?>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Ticket ID</th>
                        <th>Jenis</th>
                        <th>Issue</th>
                        <th>Prioritas</th>
                        <th>Status</th>
                        <th>Assigned Teknisi</th>
                        <th>Update</th>
                        <th class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr>
                        <td class="ps-3 text-muted" colspan="8">Tidak ada data tiket.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                        <?php
                        $ticket_id = (int) ($r['id'] ?? 0);
                        $ticket_number = (string) ($r['ticket_number'] ?? '-');
                        $subject = (string) ($r['subject'] ?? '-');
                        $description = trim((string) ($r['description'] ?? ''));
                        $customer_name = trim((string) ($r['customer_name'] ?? ''));
                        $area_name = trim((string) ($r['area'] ?? ''));
                        $ticket_type = strtolower(trim((string) ($r['ticket_type'] ?? 'gangguan')));
                        $ticket_type_label = $ticket_type === 'maintenance' ? 'MAINTENANCE' : 'GANGGUAN';
                        list($priority_label, $priority_class) = ticket_priority_badge_meta((string) ($r['priority'] ?? 'low'));
                        list($status_label, $status_class) = ticket_status_badge_meta((string) ($r['status'] ?? 'open'));
                        $assigned_name = (string) ($r['assigned_name'] ?? '-');
                        $updated_raw = (string) ($r['updated_at'] ?? '');
                        $updated_label = ($updated_raw !== '' && strtotime($updated_raw) !== false)
                            ? date('d-m-Y H:i', strtotime($updated_raw))
                            : '-';
                        $status_key = strtolower((string) ($r['status'] ?? ''));
                        $can_mark_done = $ticket_id > 0 && in_array($status_key, array('open', 'new', 'assigned', 'in_progress', 'process', 'working'), true);
                        ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?php echo html_escape($ticket_number); ?></td>
                            <td>
                                <span class="badge <?php echo $ticket_type === 'maintenance' ? 'text-bg-warning' : 'text-bg-secondary'; ?>">
                                    <?php echo html_escape($ticket_type_label); ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold"><?php echo html_escape($subject); ?></div>
                                <?php if ($customer_name !== '' || $area_name !== ''): ?>
                                <div class="small text-muted">
                                    User: <?php echo html_escape($customer_name !== '' ? $customer_name : '-'); ?>
                                    | Area: <?php echo html_escape($area_name !== '' ? $area_name : '-'); ?>
                                </div>
                                <?php endif; ?>
                                <div class="small text-muted"><?php echo html_escape($description !== '' ? $description : '-'); ?></div>
                            </td>
                            <td><span class="badge <?php echo html_escape($priority_class); ?>"><?php echo html_escape($priority_label); ?></span></td>
                            <td><span class="badge <?php echo html_escape($status_class); ?>"><?php echo html_escape($status_label); ?></span></td>
                            <td><?php echo html_escape($assigned_name); ?></td>
                            <td><?php echo html_escape($updated_label); ?></td>
                            <td class="text-end pe-3">
                                <?php if ($can_mark_done): ?>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-success"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#doneTicket<?php echo $ticket_id; ?>"
                                    >
                                        DONE
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled>DONE</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($can_mark_done): ?>
                        <tr class="collapse" id="doneTicket<?php echo $ticket_id; ?>">
                            <td colspan="8" class="bg-light">
                                <?php echo form_open('tickets/mark_done/' . $ticket_id, array('class' => 'row g-2 align-items-end')); ?>
                                    <div class="col-md-10">
                                        <label class="form-label form-label-sm mb-1">Catatan Teknisi</label>
                                        <textarea
                                            name="done_note"
                                            class="form-control form-control-sm"
                                            rows="2"
                                            placeholder="Contoh: Link normal kembali, ONT restart, speedtest sesuai paket."
                                        ></textarea>
                                    </div>
                                    <div class="col-md-2 d-grid">
                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-success"
                                            onclick="return confirm('Set tiket ini ke DONE?');"
                                        >
                                            Submit DONE
                                        </button>
                                    </div>
                                <?php echo form_close(); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="p-3 border-top d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div>
                <div class="small text-muted mb-1">Page View</div>
                <div class="d-flex flex-wrap gap-1" role="group" aria-label="tickets-page-view-buttons">
                    <?php foreach ($per_page_options as $opt): ?>
                        <?php $opt = (int) $opt; ?>
                        <?php $input_id = 'tickets_per_page_' . $opt; ?>
                        <input
                            class="btn-check"
                            type="radio"
                            name="per_page"
                            id="<?php echo $input_id; ?>"
                            form="ticketsFilterForm"
                            value="<?php echo $opt; ?>"
                            autocomplete="off"
                            onchange="document.getElementById('ticketsFilterForm').submit();"
                            <?php echo $per_page === $opt ? 'checked' : ''; ?>
                        >
                        <label class="btn btn-outline-primary btn-sm px-2 py-1" for="<?php echo $input_id; ?>">
                            <?php echo $opt; ?>
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
<?php if ($can_create): ?>
<div class="modal fade" id="createTicketModal" tabindex="-1" aria-labelledby="createTicketModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createTicketModalLabel">Input Tiket Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <?php echo form_open('tickets/store', array('id' => 'createTicketForm')); ?>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nama User/Customer</label>
                        <?php if (!empty($customer_options)): ?>
                        <select name="customer_id" id="ticket_customer_id" class="form-select js-searchable-select" required>
                            <option value="">- Pilih Customer -</option>
                            <?php foreach ($customer_options as $customer): ?>
                                <?php
                                $customer_id = (int) ($customer['id'] ?? 0);
                                $customer_name = (string) ($customer['name'] ?? '-');
                                $customer_area = (string) ($customer['area'] ?? '');
                                ?>
                                <option
                                    value="<?php echo $customer_id; ?>"
                                    data-customer-name="<?php echo html_escape($customer_name); ?>"
                                    data-customer-area="<?php echo html_escape($customer_area); ?>"
                                >
                                    <?php echo html_escape($customer_name . ($customer_area !== '' ? ' - ' . $customer_area : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="customer_name" id="ticket_customer_name">
                        <?php else: ?>
                        <input type="text" name="customer_name" class="form-control" placeholder="Nama user / pelanggan" required>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Area</label>
                        <input type="text" name="area" id="ticket_area" class="form-control" placeholder="Area pelanggan" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Subject Gangguan</label>
                        <input type="text" name="subject" class="form-control" placeholder="Contoh: Internet down area X" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Jenis</label>
                        <select name="ticket_type" class="form-select" required>
                            <option value="gangguan">Gangguan</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Prioritas</label>
                        <select name="priority" class="form-select" required>
                            <option value="low">LOW</option>
                            <option value="medium" selected>MEDIUM</option>
                            <option value="high">HIGH</option>
                            <option value="critical">CRITICAL</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Assigned Teknisi</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">- Pilih Teknisi -</option>
                            <?php foreach ($teknisi_options as $teknisi): ?>
                                <option value="<?php echo (int) ($teknisi['id'] ?? 0); ?>">
                                    <?php echo html_escape((string) ($teknisi['name'] ?? '-')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Deskripsi</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Detail gangguan / kebutuhan maintenance"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-send me-1"></i>Submit Tiket
                </button>
            </div>
            <?php echo form_close(); ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
if ($can_create):
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var customerSelect = document.getElementById('ticket_customer_id');
    var customerName = document.getElementById('ticket_customer_name');
    var customerArea = document.getElementById('ticket_area');
    if (!customerSelect) {
        return;
    }

    var syncCustomerContext = function () {
        var selected = customerSelect.options[customerSelect.selectedIndex];
        if (!selected) {
            return;
        }
        var name = selected.getAttribute('data-customer-name') || '';
        var area = selected.getAttribute('data-customer-area') || '';
        if (customerName) {
            customerName.value = name;
        }
        if (customerArea) {
            customerArea.value = area;
        }
    };

    if (typeof window.initSearchableSelect === 'function') {
        window.initSearchableSelect('#ticket_customer_id', {
            searchPlaceholderValue: 'Cari customer / area...'
        });
    }
    customerSelect.addEventListener('change', syncCustomerContext);
    syncCustomerContext();
});
</script>
<?php
$page_scripts = (isset($page_scripts) ? $page_scripts : '') . ob_get_clean();
endif;
?>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
