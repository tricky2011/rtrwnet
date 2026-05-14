<?php
$page_title = 'Cashflow - ' . app_name();
$page_heading = 'Cashflow';
$page_subheading = 'Monitor pendapatan, pengeluaran, dan profit bersih secara cash basis.';
$active_menu = 'cashflow';

$rows = isset($rows) && is_array($rows) ? $rows : array();
$search = isset($search) ? (string) $search : '';
$type_filter = isset($type_filter) ? (string) $type_filter : '';
$type_options = isset($type_options) && is_array($type_options) ? $type_options : array('' => 'Semua Tipe', 'income' => 'Income', 'expense' => 'Expense');
$period_filter = isset($period_filter) ? (string) $period_filter : date('Y-m');
$period_range = isset($period_range) && is_array($period_range) ? $period_range : array('label' => date('F Y'));
$summary = isset($summary) && is_array($summary) ? $summary : array('total_income' => 0, 'total_expense' => 0, 'net_profit' => 0);
$category_breakdown = isset($category_breakdown) && is_array($category_breakdown) ? $category_breakdown : array('income' => array(), 'expense' => array());
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($rows);
$per_page = isset($per_page) ? (int) $per_page : 20;
$per_page_options = isset($per_page_options) && is_array($per_page_options) ? $per_page_options : array(20, 50, 100, 500);
$chart_data = isset($chart_data) && is_array($chart_data) ? $chart_data : array(
    'labels' => array(),
    'income' => array(),
    'expense' => array(),
    'net' => array(),
);
$income_categories = isset($income_categories) && is_array($income_categories) ? $income_categories : array();
$expense_categories = isset($expense_categories) && is_array($expense_categories) ? $expense_categories : array();
$role = isset($role) ? (string) $role : (string) $this->session->userdata('role');
$is_superadmin = $role === 'superadmin';
$pending_request_map = isset($pending_request_map) && is_array($pending_request_map) ? $pending_request_map : array();
$pending_requests = isset($pending_requests) && is_array($pending_requests) ? $pending_requests : array();

$total_income = (float) ($summary['total_income'] ?? 0);
$total_internet_income = (float) ($summary['total_internet_income'] ?? 0);
$total_installation_income = (float) ($summary['total_installation_income'] ?? 0);
$total_expense = (float) ($summary['total_expense'] ?? 0);
$net_profit = (float) ($summary['net_profit'] ?? 0);
$net_class = $net_profit >= 0 ? 'text-success' : 'text-danger';
$active_modal = (string) $this->session->flashdata('cashflow_form_modal');

ob_start();
?>
<div class="row g-3 mb-3">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card stat-card border-success-subtle">
            <div class="card-body">
                <div class="text-muted small">Total Pendapatan (<?php echo html_escape((string) ($period_range['label'] ?? '-')); ?>)</div>
                <div class="h4 mb-0 text-success">Rp <?php echo number_format($total_income, 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card stat-card border-info-subtle">
            <div class="card-body">
                <div class="text-muted small">Pendapatan Internet</div>
                <div class="h4 mb-0 text-info">Rp <?php echo number_format($total_internet_income, 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card stat-card border-primary-subtle">
            <div class="card-body">
                <div class="text-muted small">Pendapatan Instalasi</div>
                <div class="h4 mb-0 text-primary">Rp <?php echo number_format($total_installation_income, 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card stat-card border-danger-subtle">
            <div class="card-body">
                <div class="text-muted small">Total Pengeluaran (<?php echo html_escape((string) ($period_range['label'] ?? '-')); ?>)</div>
                <div class="h4 mb-0 text-danger">Rp <?php echo number_format($total_expense, 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-6">
        <div class="card stat-card border-primary-subtle">
            <div class="card-body">
                <div class="text-muted small">Profit Bersih (Pendapatan - Pengeluaran)</div>
                <div class="h4 mb-0 <?php echo $net_class; ?>">Rp <?php echo number_format($net_profit, 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end mb-3 gap-2">
    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#incomeModal">
        <i class="bi bi-plus-circle me-1"></i>Input Pemasukan
    </button>
    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#expenseModal">
        <i class="bi bi-plus-circle me-1"></i>Input Pengeluaran
    </button>
</div>

<?php if ($this->session->flashdata('success')): ?>
    <div class="alert alert-success"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
    <div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card stat-card">
            <div class="card-header bg-white fw-semibold">Grafik Internet vs Instalasi vs Expense vs Net (6 Bulan)</div>
            <div class="card-body" style="height:340px;"><canvas id="cashflowChart"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Breakdown Kategori</div>
            <div class="card-body p-0">
                <div class="p-3 border-bottom">
                    <div class="small fw-semibold text-success mb-2">Income</div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>Kategori</th><th class="text-end">Amount</th></tr></thead>
                            <tbody>
                            <?php if (empty($category_breakdown['income'])): ?>
                                <tr><td colspan="2" class="text-muted">Belum ada income.</td></tr>
                            <?php else: ?>
                                <?php foreach ($category_breakdown['income'] as $row): ?>
                                    <tr>
                                        <td><?php echo html_escape((string) ($row['category_name'] ?? '-')); ?></td>
                                        <td class="text-end">Rp <?php echo number_format((float) ($row['total_amount'] ?? 0), 0, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="p-3">
                    <div class="small fw-semibold text-danger mb-2">Expense</div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>Kategori</th><th class="text-end">Amount</th></tr></thead>
                            <tbody>
                            <?php if (empty($category_breakdown['expense'])): ?>
                                <tr><td colspan="2" class="text-muted">Belum ada expense.</td></tr>
                            <?php else: ?>
                                <?php foreach ($category_breakdown['expense'] as $row): ?>
                                    <tr>
                                        <td><?php echo html_escape((string) ($row['category_name'] ?? '-')); ?></td>
                                        <td class="text-end">Rp <?php echo number_format((float) ($row['total_amount'] ?? 0), 0, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">Tabel Transaksi Cashflow</div>
    <div class="card-body p-0">
        <div class="p-3 border-bottom">
            <?php echo form_open('cashflow', array('method' => 'get', 'class' => 'row g-2 align-items-end', 'id' => 'cashflowFilterForm')); ?>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-1">Periode</label>
                    <input type="month" class="form-control form-control-sm" name="period" value="<?php echo html_escape($period_filter); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm mb-1">Tipe</label>
                    <select name="type" class="form-select form-select-sm">
                        <?php foreach ($type_options as $key => $label): ?>
                            <option value="<?php echo html_escape((string) $key); ?>" <?php echo (string) $type_filter === (string) $key ? 'selected' : ''; ?>>
                                <?php echo html_escape((string) $label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-1">Search</label>
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        name="search"
                        placeholder="Cari deskripsi / nomor txn / invoice / customer"
                        value="<?php echo html_escape($search); ?>"
                    >
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">Filter</button>
                    <a href="<?php echo site_url('cashflow'); ?>" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
                </div>
                <div class="col-12 text-muted small">Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> transaksi</div>
            <?php echo form_close(); ?>
        </div>

        <div class="p-3 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="small text-muted">
                <span id="cashflowSelectedCount">0</span> transaksi terpilih.
            </div>
            <?php echo form_open('cashflow/bulk-action', array('method' => 'post', 'id' => 'cashflowBulkForm', 'class' => 'd-flex flex-wrap align-items-center gap-2 mb-0')); ?>
                <select name="bulk_action" id="cashflowBulkAction" class="form-select form-select-sm" style="min-width:230px;">
                    <option value="">Pilih Bulk Action</option>
                    <option value="set_type_income"><?php echo $is_superadmin ? 'Ubah Jenis -> INCOME' : 'Request Ubah Jenis -> INCOME'; ?></option>
                    <option value="set_type_expense"><?php echo $is_superadmin ? 'Ubah Jenis -> EXPENSE' : 'Request Ubah Jenis -> EXPENSE'; ?></option>
                    <option value="delete"><?php echo $is_superadmin ? 'Hapus Permanen Selected' : 'Request Hapus Selected'; ?></option>
                </select>
                <button type="submit" class="btn btn-sm btn-primary" id="cashflowBulkSubmit">Eksekusi Bulk</button>
            <?php echo form_close(); ?>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width:44px;">
                            <input type="checkbox" class="form-check-input" id="cashflowSelectAll">
                        </th>
                        <th class="ps-3">Tanggal</th>
                        <th>No. Transaksi</th>
                        <th>Tipe</th>
                        <th>Kategori</th>
                        <th>Deskripsi</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th class="text-end pe-3">Amount</th>
                        <th class="text-center pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr>
                        <td class="ps-3 text-muted" colspan="10">Tidak ada data cashflow pada filter ini.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                        <?php
                        $txn_date_raw = (string) ($r['txn_date'] ?? '');
                        $txn_date = '-';
                        if ($txn_date_raw !== '' && strtotime($txn_date_raw) !== false) {
                            $txn_date = date('d-m-Y H:i', strtotime($txn_date_raw));
                        }
                        $type = strtolower((string) ($r['type'] ?? ''));
                        $badge_class = $type === 'income' ? 'text-bg-success' : 'text-bg-danger';
                        $type_label = strtoupper($type !== '' ? $type : '-');
                        $txn_number = (string) ($r['txn_number'] ?? '-');
                        $category_name = (string) ($r['category_name'] ?? '-');
                        $category_id = (string) ($r['category_id'] ?? '');
                        $category_text = (string) ($r['category_text'] ?? '');
                        $description = (string) ($r['description'] ?? '-');
                        $invoice_number = (string) ($r['invoice_number'] ?? '-');
                        $customer_name = (string) ($r['customer_name'] ?? '-');
                        $amount = (float) ($r['amount'] ?? 0);
                        $txn_id = (int) ($r['id'] ?? 0);
                        $txn_date_input = $txn_date_raw !== '' && strtotime($txn_date_raw) !== false
                            ? date('Y-m-d', strtotime($txn_date_raw))
                            : date('Y-m-d');
                        $pending_action = isset($pending_request_map[$txn_id]) ? strtolower((string) $pending_request_map[$txn_id]) : '';
                        $is_pending_request = $pending_action !== '';
                        ?>
                        <tr>
                            <td class="ps-3">
                                <?php if ($txn_id > 0): ?>
                                    <input
                                        type="checkbox"
                                        class="form-check-input js-cashflow-checkbox"
                                        name="txn_ids[]"
                                        form="cashflowBulkForm"
                                        value="<?php echo (int) $txn_id; ?>"
                                        <?php echo $is_pending_request ? 'disabled' : ''; ?>
                                    >
                                <?php endif; ?>
                            </td>
                            <td class="ps-3"><?php echo html_escape($txn_date); ?></td>
                            <td><span class="badge text-bg-light border"><?php echo html_escape($txn_number !== '' ? $txn_number : '-'); ?></span></td>
                            <td><span class="badge <?php echo html_escape($badge_class); ?>"><?php echo html_escape($type_label); ?></span></td>
                            <td><?php echo html_escape($category_name !== '' ? $category_name : '-'); ?></td>
                            <td><?php echo html_escape($description !== '' ? $description : '-'); ?></td>
                            <td><?php echo html_escape($invoice_number !== '' ? $invoice_number : '-'); ?></td>
                            <td><?php echo html_escape($customer_name !== '' ? $customer_name : '-'); ?></td>
                            <td class="text-end pe-3">Rp <?php echo number_format($amount, 0, ',', '.'); ?></td>
                            <td class="text-center pe-3">
                                <?php if ($txn_id <= 0): ?>
                                    <span class="text-muted small">-</span>
                                <?php elseif ($is_pending_request): ?>
                                    <span class="badge text-bg-warning text-uppercase">Pending <?php echo html_escape($pending_action); ?></span>
                                <?php else: ?>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary js-edit-txn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editTxnModal"
                                        data-id="<?php echo (int) $txn_id; ?>"
                                        data-date="<?php echo html_escape($txn_date_input); ?>"
                                        data-type="<?php echo html_escape($type); ?>"
                                        data-category-id="<?php echo html_escape($category_id); ?>"
                                        data-category-text="<?php echo html_escape($category_text); ?>"
                                        data-description="<?php echo html_escape($description); ?>"
                                        data-amount="<?php echo (int) round($amount); ?>"
                                    >
                                        Edit
                                    </button>
                                    <?php echo form_open('cashflow/delete/' . $txn_id, array('method' => 'post', 'class' => 'd-inline js-delete-txn-form')); ?>
                                        <button
                                            type="submit"
                                            class="btn btn-sm <?php echo $is_superadmin ? 'btn-outline-danger' : 'btn-outline-warning'; ?>"
                                            data-confirm="<?php echo $is_superadmin ? 'Hapus permanen transaksi ini?' : 'Kirim request hapus transaksi ke superadmin?'; ?>"
                                        >
                                            Hapus
                                        </button>
                                    <?php echo form_close(); ?>
                                    <?php if (!$is_superadmin): ?>
                                        <div class="small text-muted mt-1">Butuh ACC superadmin</div>
                                    <?php endif; ?>
                                <?php endif; ?>
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
                <div class="d-flex flex-wrap gap-1" role="group" aria-label="cashflow-page-view-buttons">
                    <?php foreach ($per_page_options as $opt): ?>
                        <?php $opt = (int) $opt; ?>
                        <?php $input_id = 'cashflow_per_page_' . $opt; ?>
                        <input
                            class="btn-check"
                            type="radio"
                            name="per_page"
                            id="<?php echo $input_id; ?>"
                            form="cashflowFilterForm"
                            value="<?php echo $opt; ?>"
                            autocomplete="off"
                            onchange="document.getElementById('cashflowFilterForm').submit();"
                            <?php echo $per_page === $opt ? 'checked' : ''; ?>
                        >
                        <label class="btn btn-outline-primary btn-sm px-2 py-1" for="<?php echo $input_id; ?>"><?php echo $opt; ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($pagination !== ''): ?>
                <div class="ms-md-auto"><?php echo $pagination; ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($is_superadmin): ?>
<div class="card stat-card mt-3">
    <div class="card-header bg-white fw-semibold">Approval Edit/Hapus Transaksi (Admin)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Waktu Request</th>
                        <th>No. Transaksi</th>
                        <th>Pengaju</th>
                        <th>Aksi</th>
                        <th>Detail Perubahan</th>
                        <th class="pe-3 text-center">Review</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pending_requests)): ?>
                        <tr>
                            <td class="ps-3 text-muted" colspan="6">Tidak ada request approval.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pending_requests as $request): ?>
                            <?php
                            $request_action = strtolower((string) ($request['action_name'] ?? ''));
                            $request_time_raw = (string) ($request['created_at'] ?? '');
                            $request_time = $request_time_raw !== '' && strtotime($request_time_raw) !== false
                                ? date('d-m-Y H:i', strtotime($request_time_raw))
                                : '-';
                            $new_data = isset($request['new_data']) && is_array($request['new_data']) ? $request['new_data'] : array();
                            $reason = trim((string) ($request['reason'] ?? ''));
                            ?>
                            <tr>
                                <td class="ps-3"><?php echo html_escape($request_time); ?></td>
                                <td>
                                    <span class="badge text-bg-light border"><?php echo html_escape((string) ($request['txn_number'] ?? '-')); ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo html_escape((string) ($request['requester_name'] ?? '-')); ?></div>
                                    <div class="small text-muted text-uppercase"><?php echo html_escape((string) ($request['requested_role'] ?? 'admin')); ?></div>
                                </td>
                                <td>
                                    <?php if ($request_action === 'edit'): ?>
                                        <span class="badge text-bg-primary">EDIT</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-danger">DELETE</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($request_action === 'edit'): ?>
                                        <div class="small">
                                            <div><strong>Deskripsi:</strong> <?php echo html_escape((string) ($new_data['description'] ?? '-')); ?></div>
                                            <div><strong>Amount:</strong> Rp <?php echo number_format((float) ($new_data['amount'] ?? 0), 0, ',', '.'); ?></div>
                                        </div>
                                    <?php else: ?>
                                        <div class="small">Permintaan hapus transaksi ini.</div>
                                    <?php endif; ?>
                                    <?php if ($reason !== ''): ?>
                                        <div class="small text-muted mt-1"><strong>Alasan:</strong> <?php echo html_escape($reason); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-3 text-center">
                                    <?php echo form_open('cashflow/review-request/' . (int) ($request['id'] ?? 0), array('method' => 'post', 'class' => 'd-inline')); ?>
                                        <input type="hidden" name="decision" value="approve">
                                        <button type="submit" class="btn btn-sm btn-success">ACC</button>
                                    <?php echo form_close(); ?>
                                    <?php echo form_open('cashflow/review-request/' . (int) ($request['id'] ?? 0), array('method' => 'post', 'class' => 'd-inline ms-1')); ?>
                                        <input type="hidden" name="decision" value="reject">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Tolak</button>
                                    <?php echo form_close(); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="editTxnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Transaksi Cashflow</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <?php echo form_open('cashflow/update/0', array('method' => 'post', 'id' => 'editTxnForm')); ?>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Tanggal</label>
                        <input type="date" class="form-control" name="txn_date" id="editTxnDate" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tipe</label>
                        <input type="text" class="form-control" id="editTxnType" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Kategori</label>
                        <select class="form-select" name="category" id="editTxnCategory" required>
                            <?php foreach ($income_categories as $cat): ?>
                                <?php
                                $cat_id = (string) ($cat['id'] ?? '');
                                $cat_label = (string) ($cat['label'] ?? '');
                                if ($cat_label === '') {
                                    continue;
                                }
                                $cat_value = $cat_id !== '' ? $cat_id : $cat_label;
                                ?>
                                <option data-type="income" value="<?php echo html_escape($cat_value); ?>"><?php echo html_escape($cat_label); ?></option>
                            <?php endforeach; ?>
                            <?php foreach ($expense_categories as $cat): ?>
                                <?php
                                $cat_id = (string) ($cat['id'] ?? '');
                                $cat_label = (string) ($cat['label'] ?? '');
                                if ($cat_label === '') {
                                    continue;
                                }
                                $cat_value = $cat_id !== '' ? $cat_id : $cat_label;
                                ?>
                                <option data-type="expense" value="<?php echo html_escape($cat_value); ?>"><?php echo html_escape($cat_label); ?></option>
                            <?php endforeach; ?>
                            <?php if (empty($income_categories) && empty($expense_categories)): ?>
                                <option data-type="income" value="Subscription">Subscription</option>
                                <option data-type="income" value="Installation">Installation</option>
                                <option data-type="expense" value="Operational">Operational</option>
                                <option data-type="expense" value="Maintenance">Maintenance</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Deskripsi</label>
                        <textarea class="form-control" name="description" id="editTxnDescription" rows="3" required></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nominal (Rp)</label>
                        <input type="text" class="form-control js-idr-thousands" name="amount" id="editTxnAmount" inputmode="numeric" autocomplete="off" placeholder="Contoh: 1.000.000" required>
                    </div>
                    <?php if (!$is_superadmin): ?>
                        <div class="col-md-12">
                            <label class="form-label">Alasan Request (opsional)</label>
                            <textarea class="form-control" name="reason" rows="2" placeholder="Contoh: koreksi nominal transaksi"></textarea>
                            <div class="form-text">Perubahan akan masuk approval superadmin terlebih dahulu.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary"><?php echo $is_superadmin ? 'Simpan Perubahan' : 'Kirim Request Edit'; ?></button>
            </div>
            <?php echo form_close(); ?>
        </div>
    </div>
</div>

<div class="modal fade" id="incomeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Input Pemasukan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <?php echo form_open('cashflow/add-income', array('method' => 'post', 'class' => 'needs-validation', 'novalidate' => 'novalidate')); ?>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Tanggal</label>
                        <input type="date" class="form-control" name="txn_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Kategori Pemasukan</label>
                        <select class="form-select" name="category" required>
                            <?php foreach ($income_categories as $cat): ?>
                                <?php
                                $cat_id = (string) ($cat['id'] ?? '');
                                $cat_label = (string) ($cat['label'] ?? '');
                                if ($cat_label === '') {
                                    continue;
                                }
                                $cat_value = $cat_id !== '' ? $cat_id : $cat_label;
                                ?>
                                <option value="<?php echo html_escape($cat_value); ?>"><?php echo html_escape($cat_label); ?></option>
                            <?php endforeach; ?>
                            <?php if (empty($income_categories)): ?>
                                <option value="Subscription">Subscription</option>
                                <option value="Installation">Installation</option>
                                <option value="Other Income">Other Income</option>
                            <?php endif; ?>
                        </select>
                        <div class="form-text">Pilih <strong>Installation</strong> untuk pemasukan biaya pasang baru.</div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Deskripsi</label>
                        <textarea class="form-control" name="description" rows="3" placeholder="Contoh: Biaya instalasi pelanggan baru #CUST-001" required></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nominal (Rp)</label>
                        <input type="text" class="form-control js-idr-thousands" name="amount" inputmode="numeric" autocomplete="off" placeholder="Contoh: 350.000" required>
                        <div class="form-text">Ketik angka, format titik ribuan akan muncul otomatis.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success">Simpan Pemasukan</button>
            </div>
            <?php echo form_close(); ?>
        </div>
    </div>
</div>

<div class="modal fade" id="expenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-cash-stack me-2"></i>Input Pengeluaran</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <?php echo form_open('cashflow/add-expense', array('method' => 'post', 'class' => 'needs-validation', 'novalidate' => 'novalidate')); ?>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Tanggal</label>
                        <input type="date" class="form-control" name="txn_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Kategori</label>
                        <select class="form-select" name="category" required>
                            <?php foreach ($expense_categories as $cat): ?>
                                <?php
                                $cat_id = (string) ($cat['id'] ?? '');
                                $cat_label = (string) ($cat['label'] ?? '');
                                if ($cat_label === '') {
                                    continue;
                                }
                                $cat_value = $cat_id !== '' ? $cat_id : $cat_label;
                                ?>
                                <option value="<?php echo html_escape($cat_value); ?>"><?php echo html_escape($cat_label); ?></option>
                            <?php endforeach; ?>
                            <?php if (empty($expense_categories)): ?>
                                <option value="Operational">Operational</option>
                                <option value="Gaji">Gaji</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Pembelian Infrastruktur">Pembelian Infrastruktur</option>
                                <option value="Other Expense">Other Expense</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Deskripsi</label>
                        <textarea class="form-control" name="description" rows="3" placeholder="Contoh: Bayar listrik POP Bulan Februari" required></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nominal (Rp)</label>
                        <input type="text" class="form-control js-idr-thousands" name="amount" inputmode="numeric" autocomplete="off" placeholder="Contoh: 250.000" required>
                        <div class="form-text">Ketik angka, format titik ribuan akan muncul otomatis.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger">Simpan Pengeluaran</button>
            </div>
            <?php echo form_close(); ?>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

$labels_json = json_encode(array_values($chart_data['labels'] ?? array()));
$internet_income_json = json_encode(array_values($chart_data['internet_income'] ?? array()));
$installation_income_json = json_encode(array_values($chart_data['installation_income'] ?? array()));
$expense_json = json_encode(array_values($chart_data['expense'] ?? array()));
$net_json = json_encode(array_values($chart_data['net'] ?? array()));

$page_scripts = <<<'SCRIPT'
<script>
(function () {
    var canvas = document.getElementById('cashflowChart');
    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    new Chart(canvas, {
        data: {
            labels: LABELS_JSON,
            datasets: [
                {
                    type: 'bar',
                    label: 'Internet',
                    data: INTERNET_INCOME_JSON,
                    backgroundColor: 'rgba(2,132,199,0.60)',
                    borderColor: 'rgba(2,132,199,1)',
                    borderWidth: 1,
                    borderRadius: 6
                },
                {
                    type: 'bar',
                    label: 'Instalasi',
                    data: INSTALLATION_INCOME_JSON,
                    backgroundColor: 'rgba(16,185,129,0.60)',
                    borderColor: 'rgba(16,185,129,1)',
                    borderWidth: 1,
                    borderRadius: 6
                },
                {
                    type: 'bar',
                    label: 'Expense',
                    data: EXPENSE_JSON,
                    backgroundColor: 'rgba(239,68,68,0.55)',
                    borderColor: 'rgba(239,68,68,1)',
                    borderWidth: 1,
                    borderRadius: 6
                },
                {
                    type: 'line',
                    label: 'Net Profit',
                    data: NET_JSON,
                    borderColor: 'rgba(37,99,235,1)',
                    backgroundColor: 'rgba(37,99,235,0.12)',
                    tension: 0.25,
                    fill: false,
                    pointRadius: 3,
                    pointHoverRadius: 4
                }
            ]
        },
        options: {
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function (value) {
                            return 'Rp ' + Number(value).toLocaleString('id-ID');
                        }
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ctx.dataset.label + ': Rp ' + Number(ctx.raw || 0).toLocaleString('id-ID');
                        }
                    }
                }
            }
        }
    });
})();

document.addEventListener('DOMContentLoaded', function () {
    var incomeModal = document.getElementById('incomeModal');
    var expenseModal = document.getElementById('expenseModal');
    var hasBootstrapModal = !!(window.bootstrap && typeof window.bootstrap.Modal === 'function');
    var activeModal = 'ACTIVE_MODAL';
    if (hasBootstrapModal) {
        if (activeModal === 'income' && incomeModal) {
            var incomeModalInstance = new window.bootstrap.Modal(incomeModal);
            incomeModalInstance.show();
        } else if (activeModal === 'expense' && expenseModal) {
            var modalInstance = new window.bootstrap.Modal(expenseModal);
            modalInstance.show();
        }
    }

    document.querySelectorAll('.js-delete-txn-form button[type="submit"]').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            var message = btn.getAttribute('data-confirm') || 'Yakin proses transaksi ini?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    function formatThousandsId(value) {
        var digits = String(value || '').replace(/[^\d]/g, '');
        if (digits === '') {
            return '';
        }
        return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    document.querySelectorAll('.js-idr-thousands').forEach(function (input) {
        input.addEventListener('input', function () {
            input.value = formatThousandsId(input.value);
        });
        input.addEventListener('blur', function () {
            input.value = formatThousandsId(input.value);
        });
        if (input.value) {
            input.value = formatThousandsId(input.value);
        }
    });

    var selectAll = document.getElementById('cashflowSelectAll');
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.js-cashflow-checkbox'));
    var selectedCountEl = document.getElementById('cashflowSelectedCount');
    var bulkForm = document.getElementById('cashflowBulkForm');
    var bulkAction = document.getElementById('cashflowBulkAction');
    var bulkSubmit = document.getElementById('cashflowBulkSubmit');

    function updateSelectedCount() {
        var checked = checkboxes.filter(function (cb) { return cb.checked; }).length;
        if (selectedCountEl) {
            selectedCountEl.textContent = String(checked);
        }
        if (bulkSubmit) {
            bulkSubmit.disabled = checked === 0;
        }
        if (selectAll) {
            var enabled = checkboxes.filter(function (cb) { return !cb.disabled; });
            var enabledChecked = enabled.filter(function (cb) { return cb.checked; });
            selectAll.checked = enabled.length > 0 && enabled.length === enabledChecked.length;
            selectAll.indeterminate = enabledChecked.length > 0 && enabledChecked.length < enabled.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (cb) {
                if (!cb.disabled) {
                    cb.checked = selectAll.checked;
                }
            });
            updateSelectedCount();
        });
    }
    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', updateSelectedCount);
    });
    updateSelectedCount();

    if (bulkForm) {
        bulkForm.addEventListener('submit', function (event) {
            var checked = checkboxes.filter(function (cb) { return cb.checked; }).length;
            if (checked === 0) {
                event.preventDefault();
                window.alert('Pilih minimal 1 transaksi.');
                return;
            }
            if (!bulkAction || !bulkAction.value) {
                event.preventDefault();
                window.alert('Pilih bulk action terlebih dahulu.');
                return;
            }

            var confirmText = 'Yakin eksekusi bulk action untuk ' + checked + ' transaksi?';
            if (bulkAction.value === 'delete') {
                confirmText = 'Yakin proses bulk HAPUS untuk ' + checked + ' transaksi?';
            }
            if (!window.confirm(confirmText)) {
                event.preventDefault();
            }
        });
    }

    var editForm = document.getElementById('editTxnForm');
    var editType = document.getElementById('editTxnType');
    var editDate = document.getElementById('editTxnDate');
    var editCategory = document.getElementById('editTxnCategory');
    var editDescription = document.getElementById('editTxnDescription');
    var editAmount = document.getElementById('editTxnAmount');
    if (!editForm || !editType || !editDate || !editCategory || !editDescription || !editAmount) {
        return;
    }

    var updateUrlBase = 'UPDATE_URL_BASE';
    var originalCategoryOptions = Array.prototype.slice.call(editCategory.options).map(function (option) {
        return {
            value: option.value,
            text: option.text,
            type: option.getAttribute('data-type') || ''
        };
    });

    function rebuildCategoryOptions(typeFilter, preferredValue, preferredText) {
        editCategory.innerHTML = '';
        var total = 0;

        originalCategoryOptions.forEach(function (opt) {
            if (typeFilter && opt.type && opt.type !== typeFilter) {
                return;
            }
            var option = document.createElement('option');
            option.value = opt.value;
            option.text = opt.text;
            option.setAttribute('data-type', opt.type);
            if (preferredValue && opt.value === preferredValue) {
                option.selected = true;
            } else if (!preferredValue && preferredText && opt.text.toLowerCase() === preferredText.toLowerCase()) {
                option.selected = true;
            }
            editCategory.appendChild(option);
            total++;
        });

        if (total === 0 && preferredText) {
            var fallback = document.createElement('option');
            fallback.value = preferredText;
            fallback.text = preferredText;
            fallback.selected = true;
            editCategory.appendChild(fallback);
        }
    }

    document.querySelectorAll('.js-edit-txn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var txnId = btn.getAttribute('data-id') || '0';
            var txnType = (btn.getAttribute('data-type') || '').toLowerCase();
            var categoryId = btn.getAttribute('data-category-id') || '';
            var categoryText = btn.getAttribute('data-category-text') || '';

            editForm.setAttribute('action', updateUrlBase + txnId);
            editType.value = txnType !== '' ? txnType.toUpperCase() : '-';
            editDate.value = btn.getAttribute('data-date') || '';
            editDescription.value = btn.getAttribute('data-description') || '';
            editAmount.value = formatThousandsId(btn.getAttribute('data-amount') || '');
            rebuildCategoryOptions(txnType, categoryId, categoryText);
        });
    });
});
</script>
SCRIPT;

$page_scripts = str_replace(
    array('LABELS_JSON', 'INTERNET_INCOME_JSON', 'INSTALLATION_INCOME_JSON', 'EXPENSE_JSON', 'NET_JSON', 'ACTIVE_MODAL', 'UPDATE_URL_BASE'),
    array($labels_json, $internet_income_json, $installation_income_json, $expense_json, $net_json, $active_modal, site_url('cashflow/update/')),
    $page_scripts
);

include APPPATH . 'views/layout/master.php';
