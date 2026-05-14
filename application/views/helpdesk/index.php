<?php
$page_title = 'Helpdesk Ticket List - ' . app_name();
$page_heading = 'Helpdesk Tickets';
$page_subheading = 'Flow status: OPEN → ASSIGNED → PROGRESS → RESOLVED → CLOSED';
$active_menu = 'helpdesk';

$rows = isset($rows) && is_array($rows) ? $rows : array();
$filters = isset($filters) && is_array($filters) ? $filters : array();
$status_options = isset($status_options) && is_array($status_options) ? $status_options : array('OPEN', 'ASSIGNED', 'PROGRESS', 'RESOLVED', 'CLOSED');
$priority_options = isset($priority_options) && is_array($priority_options) ? $priority_options : array('LOW', 'MEDIUM', 'HIGH', 'URGENT');
$olt_options = isset($olt_options) && is_array($olt_options) ? $olt_options : array();
$teknisi_options = isset($teknisi_options) && is_array($teknisi_options) ? $teknisi_options : array();
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($rows);
$per_page = isset($per_page) ? (int) $per_page : 20;
$per_page_options = isset($per_page_options) && is_array($per_page_options) ? $per_page_options : array(20, 50, 100, 500);
$months = isset($months) && is_array($months) ? $months : array();
$years = isset($years) && is_array($years) ? $years : array((int) date('Y'));
$selected_period_label = isset($selected_period_label) ? (string) $selected_period_label : date('F Y');
$role = isset($role) ? (string) $role : (string) $this->session->userdata('role');
$is_superadmin = !empty($is_superadmin);

if (strtolower($role) === 'teknisi') {
    $page_subheading = 'Flow status: OPEN → ASSIGNED → PROGRESS → DONE';
}

if (!function_exists('helpdesk_status_badge_class')) {
    function helpdesk_status_badge_class($status)
    {
        $status = strtoupper((string) $status);
        if ($status === 'OPEN') {
            return 'text-bg-secondary';
        }
        if ($status === 'ASSIGNED') {
            return 'text-bg-info';
        }
        if ($status === 'PROGRESS') {
            return 'text-bg-warning';
        }
        if ($status === 'RESOLVED' || $status === 'DONE') {
            return 'text-bg-success';
        }
        if ($status === 'CLOSED') {
            return 'text-bg-dark';
        }

        return 'text-bg-light border';
    }
}

if (!function_exists('helpdesk_status_label')) {
    function helpdesk_status_label($status, $role)
    {
        $status = strtoupper((string) $status);
        $role = strtolower((string) $role);

        if ($role === 'teknisi' && $status === 'RESOLVED') {
            return 'DONE';
        }

        return $status;
    }
}

ob_start();
?>
<?php if ($this->session->flashdata('success')): ?>
<div class="alert alert-success"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
<div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>

<div class="card stat-card mb-3">
    <div class="card-body p-3">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
            <div class="small text-muted">
                Periode: <strong><?php echo html_escape($selected_period_label); ?></strong>
                <span class="mx-1">|</span>
                Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> tiket
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo site_url('helpdesk/dashboard'); ?>" class="btn btn-sm btn-outline-secondary">Dashboard</a>
                <?php if (in_array($role, array('superadmin', 'admin'), true)): ?>
                <a href="<?php echo site_url('helpdesk/create'); ?>" class="btn btn-sm btn-primary">+ New Ticket</a>
                <?php endif; ?>
                <?php if (in_array($role, array('superadmin', 'admin'), true)): ?>
                <a href="<?php echo site_url('helpdesk-report/export-pdf') . '?' . http_build_query($filters); ?>" class="btn btn-sm btn-outline-danger" target="_blank">Export PDF</a>
                <?php endif; ?>
            </div>
        </div>

        <?php echo form_open('helpdesk', array('method' => 'get', 'id' => 'helpdeskFilterForm', 'class' => 'row g-2 align-items-end')); ?>
            <div class="col-lg-3">
                <label class="form-label form-label-sm mb-1">Search</label>
                <input type="text" class="form-control form-control-sm" name="search" value="<?php echo html_escape((string) ($filters['search'] ?? '')); ?>" placeholder="ticket / subject / customer">
            </div>
            <div class="col-lg-2 col-md-3">
                <label class="form-label form-label-sm mb-1">Bulan</label>
                <select name="month" class="form-select form-select-sm">
                    <?php foreach ($months as $month_no => $month_label): ?>
                    <option value="<?php echo (int) $month_no; ?>" <?php echo (int) ($filters['month'] ?? date('m')) === (int) $month_no ? 'selected' : ''; ?>>
                        <?php echo html_escape((string) $month_label); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-3">
                <label class="form-label form-label-sm mb-1">Tahun</label>
                <select name="year" class="form-select form-select-sm">
                    <?php foreach ($years as $year): ?>
                    <option value="<?php echo (int) $year; ?>" <?php echo (int) ($filters['year'] ?? date('Y')) === (int) $year ? 'selected' : ''; ?>>
                        <?php echo (int) $year; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-3">
                <label class="form-label form-label-sm mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <?php foreach ($status_options as $status): ?>
                    <?php $status = strtoupper((string) $status); ?>
                    <option value="<?php echo html_escape($status); ?>" <?php echo strtoupper((string) ($filters['status'] ?? '')) === $status ? 'selected' : ''; ?>><?php echo html_escape(helpdesk_status_label($status, $role)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-3">
                <label class="form-label form-label-sm mb-1">Priority</label>
                <select name="priority" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <?php foreach ($priority_options as $priority): ?>
                    <?php $priority = strtoupper((string) $priority); ?>
                    <option value="<?php echo html_escape($priority); ?>" <?php echo strtoupper((string) ($filters['priority'] ?? '')) === $priority ? 'selected' : ''; ?>><?php echo html_escape($priority); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label form-label-sm mb-1">OLT</label>
                <select name="olt_id" class="form-select form-select-sm">
                    <option value="0">Semua OLT</option>
                    <?php foreach ($olt_options as $olt): ?>
                    <?php $oid = (int) ($olt['id'] ?? 0); ?>
                    <option value="<?php echo $oid; ?>" <?php echo (int) ($filters['olt_id'] ?? 0) === $oid ? 'selected' : ''; ?>><?php echo html_escape((string) ($olt['name'] ?? '-')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label form-label-sm mb-1">Teknisi</label>
                <select name="assigned_to" class="form-select form-select-sm">
                    <option value="0">Semua</option>
                    <?php foreach ($teknisi_options as $tech): ?>
                    <?php $tid = (int) ($tech['id'] ?? 0); ?>
                    <option value="<?php echo $tid; ?>" <?php echo (int) ($filters['assigned_to'] ?? 0) === $tid ? 'selected' : ''; ?>><?php echo html_escape((string) ($tech['name'] ?? '-')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4 d-grid">
                <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
            </div>

            <div class="col-12 d-flex justify-content-between align-items-center mt-2">
                <div class="d-flex align-items-center gap-1">
                    <span class="small text-muted me-1">Page View</span>
                    <?php foreach ($per_page_options as $opt): ?>
                    <?php $opt = (int) $opt; $id = 'helpdeskPerPage' . $opt; ?>
                    <input type="radio" class="btn-check" name="per_page" id="<?php echo $id; ?>" value="<?php echo $opt; ?>" <?php echo $per_page === $opt ? 'checked' : ''; ?> onchange="document.getElementById('helpdeskFilterForm').submit();">
                    <label for="<?php echo $id; ?>" class="btn btn-outline-primary btn-sm px-2 py-1"><?php echo $opt; ?></label>
                    <?php endforeach; ?>
                </div>
                <a href="<?php echo site_url('helpdesk'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        <?php echo form_close(); ?>
    </div>
</div>

<div class="card stat-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="helpdeskTable">
            <thead class="table-light">
                <tr>
                    <th>Ticket</th>
                    <th>Customer</th>
                    <th>OLT</th>
                    <th>Subject</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Assigned</th>
                    <th>SLA</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="9" class="text-muted">Tidak ada tiket.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                    <?php
                        $id = (int) ($row['id'] ?? 0);
                        $status = strtoupper((string) ($row['status'] ?? 'OPEN'));
                        $status_label = helpdesk_status_label($status, $role);
                        $deadline = (string) ($row['sla_deadline'] ?? '');
                        $is_breached = ($deadline !== '' && strtotime($deadline) !== false && strtotime($deadline) < time() && !in_array($status, array('RESOLVED', 'CLOSED'), true));
                        $can_mark_done = $role === 'teknisi'
                            && $id > 0
                            && in_array($status, array('ASSIGNED', 'PROGRESS', 'IN_PROGRESS'), true);
                    ?>
                    <tr id="ticketRow<?php echo $id; ?>">
                        <td class="fw-semibold"><?php echo html_escape((string) ($row['ticket_code'] ?? '-')); ?></td>
                        <td>
                            <div><?php echo html_escape((string) ($row['customer_name'] ?? '-')); ?></div>
                            <div class="small text-muted"><?php echo html_escape((string) ($row['customer_area'] ?? '-')); ?></div>
                        </td>
                        <td><?php echo html_escape((string) ($row['olt_name'] ?? '-')); ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo html_escape((string) ($row['subject'] ?? '-')); ?></div>
                            <div class="small text-muted"><?php echo html_escape((string) ($row['description'] ?? '')); ?></div>
                        </td>
                        <td><span class="badge text-bg-light border"><?php echo html_escape((string) ($row['priority'] ?? 'MEDIUM')); ?></span></td>
                        <td>
                            <span class="badge <?php echo helpdesk_status_badge_class($status); ?> js-status-badge" data-ticket-id="<?php echo $id; ?>">
                                <?php echo html_escape($status_label); ?>
                            </span>
                        </td>
                        <td><?php echo html_escape((string) ($row['assigned_name'] ?? '-')); ?></td>
                        <td>
                            <?php if ($deadline !== '' && strtotime($deadline) !== false): ?>
                                <div><?php echo html_escape(date('d-m-Y H:i', strtotime($deadline))); ?></div>
                                <?php if ($is_breached): ?><span class="badge text-bg-danger mt-1">SLA BREACHED</span><?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <?php if ($role === 'teknisi'): ?>
                                    <?php if ($can_mark_done): ?>
                                        <?php echo form_open('helpdesk/mark_done/' . $id, array('class' => 'd-inline')); ?>
                                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Ubah status tiket ke DONE?');">DONE</button>
                                        <?php echo form_close(); ?>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled>DONE</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <a href="<?php echo site_url('helpdesk/detail/' . $id); ?>" class="btn btn-outline-primary">Detail</a>
                                <?php if ($is_superadmin): ?>
                                <?php echo form_open('helpdesk/delete/' . $id, array('onsubmit' => "return confirm('Hapus tiket ini?')")); ?>
                                    <button type="submit" class="btn btn-outline-danger">Delete</button>
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
    <div class="card-body border-top d-flex justify-content-end">
        <?php if ($pagination !== ''): ?><?php echo $pagination; ?><?php endif; ?>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
(function () {
    if (typeof window.jQuery === 'undefined') {
        return;
    }
    var $ = window.jQuery;
    var $table = $('#helpdeskTable');
    if ($table.length === 0) {
        return;
    }

    // Hindari warning DataTables "Incorrect column count" saat tbody hanya berisi row placeholder colspan.
    if ($table.find('tbody td[colspan]').length > 0) {
        return;
    }
    $table.DataTable({
        paging: false,
        info: false,
        searching: false,
        order: []
    });
})();
</script>
<?php
$page_scripts = ob_get_clean();
include APPPATH . 'views/layout/master.php';
