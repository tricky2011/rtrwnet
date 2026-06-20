<?php
$page_title = 'Billing - ' . app_name();
$page_heading = 'Billing / Invoice List';
$page_subheading = 'Kelola invoice, pembayaran, overdue, dan aksi bulk.';
$active_menu = 'billing';
$rows = isset($rows) && is_array($rows) ? $rows : array();
$search = isset($search) ? (string) $search : '';
$status_filter = isset($status_filter) ? strtolower((string) $status_filter) : '';
$period_filter = isset($period_filter) ? (string) $period_filter : date('Y-m');
$period_all = !empty($all_periods) || $period_filter === 'all';
$current_period = date('Y-m');
$previous_period = date('Y-m', strtotime('first day of last month'));
$bw_filter = isset($bw_filter) ? strtoupper((string) $bw_filter) : '';
$pagination = isset($pagination) ? (string) $pagination : '';
$billing_return_url = uri_string();
$billing_query_string = (string) $this->input->server('QUERY_STRING');
if ($billing_query_string !== '') {
    $billing_return_url .= '?' . $billing_query_string;
}
$total_rows = isset($total_rows) ? (int) $total_rows : count($rows);
$per_page = isset($per_page) ? (int) $per_page : 20;
$per_page_options = isset($per_page_options) && is_array($per_page_options) ? $per_page_options : array(20, 50, 100, 500);
$status_summary = isset($status_summary) && is_array($status_summary) ? $status_summary : array(
    'all' => $total_rows,
    'pending' => 0,
    'lunas' => 0,
    'overdue' => 0,
);
$status_options = isset($status_options) && is_array($status_options) ? $status_options : array(
    '' => 'Semua Status',
    'pending' => 'Belum Lunas',
    'lunas' => 'Lunas',
    'overdue' => 'Overdue',
    'cancel' => 'Cancel',
);
$bw_options = isset($bw_options) && is_array($bw_options) ? $bw_options : array(
    '' => 'Semua BW',
    '7M' => '7 M',
    '10M' => '10 M',
    '20M' => '20 M',
    '30M' => '30 M',
);
$bw_summary = isset($bw_summary) && is_array($bw_summary) ? $bw_summary : array(
    '7M' => array('bw' => '7M', 'customer_total' => 0, 'total_amount' => 0),
    '10M' => array('bw' => '10M', 'customer_total' => 0, 'total_amount' => 0),
    '20M' => array('bw' => '20M', 'customer_total' => 0, 'total_amount' => 0),
    '30M' => array('bw' => '30M', 'customer_total' => 0, 'total_amount' => 0),
);
$quick_filter_query = array();
if ($search !== '') {
    $quick_filter_query['search'] = $search;
}
if ($period_all) {
    $quick_filter_query['all_periods'] = '1';
} elseif ($period_filter !== '') {
    $quick_filter_query['period'] = $period_filter;
}
if ($bw_filter !== '') {
    $quick_filter_query['bw'] = $bw_filter;
}
if ($per_page > 0) {
    $quick_filter_query['per_page'] = $per_page;
}
$period_shortcut_query = array();
if ($search !== '') {
    $period_shortcut_query['search'] = $search;
}
if ($status_filter !== '') {
    $period_shortcut_query['status'] = $status_filter;
}
if ($bw_filter !== '') {
    $period_shortcut_query['bw'] = $bw_filter;
}
if ($per_page > 0) {
    $period_shortcut_query['per_page'] = $per_page;
}
$bw_card_base_query = $quick_filter_query;
if ($status_filter !== '') {
    $bw_card_base_query['status'] = $status_filter;
}
$quick_status_cards = array(
    array(
        'key' => '',
        'label' => 'Semua Invoice',
        'count' => (int) ($status_summary['all'] ?? $total_rows),
        'accent' => 'primary',
        'helper' => 'Tampilkan seluruh invoice',
    ),
    array(
        'key' => 'pending',
        'label' => 'Belum Lunas',
        'count' => (int) ($status_summary['pending'] ?? 0),
        'accent' => 'warning',
        'helper' => 'Issued, draft, parsial',
    ),
    array(
        'key' => 'lunas',
        'label' => 'Lunas',
        'count' => (int) ($status_summary['lunas'] ?? 0),
        'accent' => 'success',
        'helper' => 'Invoice sudah dibayar',
    ),
    array(
        'key' => 'overdue',
        'label' => 'Overdue',
        'count' => (int) ($status_summary['overdue'] ?? 0),
        'accent' => 'danger',
        'helper' => 'Sudah lewat jatuh tempo',
    ),
);
$bw_card_meta = array(
    '7M' => array('label' => 'BW 7 M', 'accent' => 'info', 'helper' => 'Paket 7 Mbps'),
    '10M' => array('label' => 'BW 10 M', 'accent' => 'primary', 'helper' => 'Paket 10 Mbps'),
    '20M' => array('label' => 'BW 20 M', 'accent' => 'success', 'helper' => 'Paket 20 Mbps'),
    '30M' => array('label' => 'BW 30 M', 'accent' => 'warning', 'helper' => 'Paket 30 Mbps'),
);
$bw_summary_cards = array();
foreach ($bw_card_meta as $bw_key => $meta) {
    $item = isset($bw_summary[$bw_key]) && is_array($bw_summary[$bw_key]) ? $bw_summary[$bw_key] : array();
    $bw_summary_cards[] = array(
        'key' => $bw_key,
        'label' => (string) $meta['label'],
        'accent' => (string) $meta['accent'],
        'helper' => (string) $meta['helper'],
        'customer_total' => (int) ($item['customer_total'] ?? 0),
        'total_amount' => (float) ($item['total_amount'] ?? 0),
    );
}

if (!function_exists('billing_status_badge')) {
    function billing_status_badge($status_raw)
    {
        $status_raw = strtolower((string) $status_raw);
        if ($status_raw === 'paid') {
            return array('LUNAS', 'success');
        }
        if ($status_raw === 'overdue') {
            return array('OVERDUE', 'danger');
        }
        if ($status_raw === 'void') {
            return array('CANCEL', 'secondary');
        }
        if ($status_raw === 'partially_paid') {
            return array('PARSIAL', 'info');
        }
        if ($status_raw === 'draft' || $status_raw === 'issued') {
            return array('BELUM LUNAS', 'warning');
        }

        return array(strtoupper($status_raw !== '' ? $status_raw : 'BELUM LUNAS'), 'warning');
    }
}

if (!function_exists('billing_bw_badge')) {
    function billing_bw_badge($bw_raw)
    {
        $bw_raw = strtoupper(trim((string) $bw_raw));
        if ($bw_raw === '7M') {
            return array('7M', 'info');
        }
        if ($bw_raw === '10M') {
            return array('10M', 'primary');
        }
        if ($bw_raw === '20M') {
            return array('20M', 'success');
        }
        if ($bw_raw === '30M') {
            return array('30M', 'warning');
        }

        return array('-', 'secondary');
    }
}

ob_start();
?>
<style>
.billing-status-card {
    display: block;
    border: 1px solid rgba(15, 23, 42, 0.08);
    border-radius: 1rem;
    padding: .9rem 1rem;
    text-decoration: none;
    color: inherit;
    background: #fff;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.billing-status-card:hover {
    color: inherit;
    transform: translateY(-2px);
    box-shadow: 0 .65rem 1.5rem rgba(15, 23, 42, 0.08);
}
.billing-status-card.active {
    box-shadow: 0 0 0 .16rem rgba(13, 110, 253, 0.14);
}
.billing-status-card.accent-primary {
    border-top: 4px solid var(--bs-primary);
}
.billing-status-card.accent-warning {
    border-top: 4px solid var(--bs-warning);
}
.billing-status-card.accent-success {
    border-top: 4px solid var(--bs-success);
}
.billing-status-card.accent-danger {
    border-top: 4px solid var(--bs-danger);
}
.billing-status-card.accent-info {
    border-top: 4px solid var(--bs-info);
}
.billing-status-card.active.accent-primary {
    border-color: rgba(13, 110, 253, 0.28);
    background: rgba(13, 110, 253, 0.04);
}
.billing-status-card.active.accent-warning {
    border-color: rgba(255, 193, 7, 0.35);
    background: rgba(255, 193, 7, 0.08);
}
.billing-status-card.active.accent-success {
    border-color: rgba(25, 135, 84, 0.28);
    background: rgba(25, 135, 84, 0.05);
}
.billing-status-card.active.accent-danger {
    border-color: rgba(220, 53, 69, 0.28);
    background: rgba(220, 53, 69, 0.05);
}
.billing-status-card.active.accent-info {
    border-color: rgba(13, 202, 240, 0.28);
    background: rgba(13, 202, 240, 0.07);
}
.billing-bw-amount {
    font-size: .92rem;
}
@media (max-width: 767.98px) {
    .billing-status-card {
        padding: .8rem .9rem;
        border-radius: .85rem;
    }
}
@media (max-width: 767.98px) {
    .table-responsive.mobile-table-stack tbody td.mobile-action-cell .btn-group {
        flex-wrap: wrap;
        gap: .3rem;
    }
    .table-responsive.mobile-table-stack tbody td.mobile-action-cell .btn-group .btn.action-icon-btn {
        min-width: 42px;
        padding: .24rem .38rem;
    }
    .table-responsive.mobile-table-stack tbody td.mobile-action-cell .btn-group .btn.action-icon-btn .bi {
        font-size: .95rem;
    }
}
</style>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold d-flex flex-column gap-2">
        <div class="d-flex flex-column flex-lg-row gap-2 justify-content-between align-items-lg-center">
            <span>Invoice List</span>
            <div class="small text-muted">Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> data</div>
        </div>

        <div class="row g-2">
            <?php foreach ($quick_status_cards as $card): ?>
                <?php
                $card_query = $quick_filter_query;
                if ((string) $card['key'] !== '') {
                    $card_query['status'] = (string) $card['key'];
                } else {
                    unset($card_query['status']);
                }
                $card_url = site_url('billing');
                $card_query_string = http_build_query($card_query);
                if ($card_query_string !== '') {
                    $card_url .= '?' . $card_query_string;
                }
                $is_card_active = ((string) $card['key'] === '' && $status_filter === '')
                    || $status_filter === (string) $card['key'];
                ?>
                <div class="col-6 col-xl-3">
                    <a
                        href="<?php echo html_escape($card_url); ?>"
                        class="billing-status-card accent-<?php echo html_escape((string) $card['accent']); ?> <?php echo $is_card_active ? 'active' : ''; ?>"
                    >
                        <div class="small text-muted"><?php echo html_escape((string) $card['helper']); ?></div>
                        <div class="fw-semibold mt-1"><?php echo html_escape((string) $card['label']); ?></div>
                        <div class="h4 mb-0 mt-1"><?php echo number_format((int) $card['count'], 0, ',', '.'); ?></div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <?php echo form_open('billing', array('method' => 'get', 'class' => 'row g-2 align-items-end', 'id' => 'billingFilterForm')); ?>
            <div class="col-md-4 col-lg-4">
                <label class="form-label form-label-sm mb-1">Search Invoice</label>
                <input
                    type="text"
                    name="search"
                    class="form-control form-control-sm"
                    placeholder="Cari no invoice / nama / no HP / user PPP"
                    value="<?php echo html_escape($search); ?>"
                >
            </div>
            <div class="col-md-2 col-lg-2">
                <label class="form-label form-label-sm mb-1">Filter Status</label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach ($status_options as $key => $label): ?>
                        <option value="<?php echo html_escape((string) $key); ?>" <?php echo $status_filter === (string) $key ? 'selected' : ''; ?>>
                            <?php echo html_escape((string) $label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-lg-2">
                <label class="form-label form-label-sm mb-1">Jenis BW</label>
                <select name="bw" class="form-select form-select-sm">
                    <?php foreach ($bw_options as $key => $label): ?>
                        <option value="<?php echo html_escape((string) $key); ?>" <?php echo $bw_filter === strtoupper((string) $key) ? 'selected' : ''; ?>>
                            <?php echo html_escape((string) $label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-lg-2">
                <label class="form-label form-label-sm mb-1">Periode Invoice</label>
                <input
                    type="month"
                    name="period"
                    id="billing_period_input"
                    class="form-control form-control-sm"
                    data-default-period="<?php echo html_escape($current_period); ?>"
                    value="<?php echo $period_all ? '' : html_escape($period_filter); ?>"
                >
                <div class="form-check mt-1">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="all_periods"
                        value="1"
                        id="billing_all_periods"
                        <?php echo $period_all ? 'checked' : ''; ?>
                    >
                    <label class="form-check-label small text-muted" for="billing_all_periods">
                        Semua periode
                    </label>
                </div>
            </div>
            <div class="col-md-2 col-lg-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary w-100" type="submit">Filter</button>
                <a href="<?php echo site_url('billing'); ?>" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
            </div>
            <div class="col-12">
                <?php
                $current_period_url = site_url('billing') . '?' . http_build_query(array_merge($period_shortcut_query, array('period' => $current_period)));
                $previous_period_url = site_url('billing') . '?' . http_build_query(array_merge($period_shortcut_query, array('period' => $previous_period)));
                $all_period_url = site_url('billing') . '?' . http_build_query(array_merge($period_shortcut_query, array('all_periods' => '1')));
                ?>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <small class="text-muted me-1">
                        Default bulan berjalan. Gunakan Bulan Lalu/Semua Periode untuk update invoice lama.
                    </small>
                    <a class="btn btn-sm btn-outline-secondary py-0 px-2 <?php echo (!$period_all && $period_filter === $current_period) ? 'active' : ''; ?>" href="<?php echo html_escape($current_period_url); ?>">Bulan Ini</a>
                    <a class="btn btn-sm btn-outline-secondary py-0 px-2 <?php echo (!$period_all && $period_filter === $previous_period) ? 'active' : ''; ?>" href="<?php echo html_escape($previous_period_url); ?>">Bulan Lalu</a>
                    <a class="btn btn-sm btn-outline-secondary py-0 px-2 <?php echo $period_all ? 'active' : ''; ?>" href="<?php echo html_escape($all_period_url); ?>">Semua Periode</a>
                </div>
            </div>
        <?php echo form_close(); ?>

        <div class="pt-2 border-top">
            <div class="small text-muted mb-2">Statistik client per BW mengikuti filter pencarian, status, dan periode.</div>
            <div class="row g-2">
                <?php foreach ($bw_summary_cards as $card): ?>
                    <?php
                    $card_query = $bw_card_base_query;
                    $card_query['bw'] = (string) $card['key'];
                    $card_url = site_url('billing');
                    $card_query_string = http_build_query($card_query);
                    if ($card_query_string !== '') {
                        $card_url .= '?' . $card_query_string;
                    }
                    $is_card_active = $bw_filter === (string) $card['key'];
                    ?>
                    <div class="col-6 col-xl-3">
                        <a
                            href="<?php echo html_escape($card_url); ?>"
                            class="billing-status-card accent-<?php echo html_escape((string) $card['accent']); ?> <?php echo $is_card_active ? 'active' : ''; ?>"
                        >
                            <div class="small text-muted"><?php echo html_escape((string) $card['helper']); ?></div>
                            <div class="fw-semibold mt-1"><?php echo html_escape((string) $card['label']); ?></div>
                            <div class="mt-1"><?php echo number_format((int) $card['customer_total'], 0, ',', '.'); ?> customer</div>
                            <div class="billing-bw-amount text-muted mt-1">Rp <?php echo number_format((float) $card['total_amount'], 0, ',', '.'); ?></div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php echo form_open('billing/manual-generate', array('method' => 'post', 'class' => 'row g-2 align-items-end pt-2 border-top')); ?>
            <div class="col-md-3 col-lg-2">
                <label class="form-label form-label-sm mb-1">Generate Mode</label>
                <select name="mode" id="manual_generate_mode" class="form-select form-select-sm">
                    <option value="rolling">Rolling Harian</option>
                    <option value="period">Periode Bulanan</option>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label form-label-sm mb-1">Periode (YYYY-MM)</label>
                <input type="month" name="period" id="manual_generate_period" class="form-control form-control-sm" value="<?php echo date('Y-m'); ?>">
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label form-label-sm mb-1">Run Date</label>
                <input type="date" name="run_date" id="manual_generate_date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-3 col-lg-2 d-grid">
                <button type="submit" class="btn btn-sm btn-success">
                    <i class="bi bi-play-circle me-1"></i>Generate Manual
                </button>
            </div>
            <div class="col-12">
                <small class="text-muted">
                    Jika cron tidak berjalan, gunakan tombol ini untuk generate invoice manual.
                </small>
            </div>
        <?php echo form_close(); ?>

    </div>

    <div class="card-body p-0">
        <div class="px-3 py-2 border-bottom d-flex flex-wrap align-items-center gap-2">
            <div class="btn-group btn-group-sm">
                <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    Bulk Action
                </button>
                <ul class="dropdown-menu shadow-sm">
                    <li>
                        <button type="button" class="dropdown-item js-bulk-action" data-action="mark_paid" data-title="Bulk Mark Lunas" data-text="Invoice terpilih akan dilunasi otomatis.">
                            <i class="bi bi-check2-circle text-success me-2"></i>Mark Lunas
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item js-bulk-action" data-action="mark_overdue" data-title="Bulk Mark Overdue" data-text="Invoice terpilih akan menjadi overdue dan auto isolir setelah 5 hari jika belum lunas.">
                            <i class="bi bi-exclamation-triangle text-warning me-2"></i>Mark Overdue
                        </button>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <button type="button" class="dropdown-item js-bulk-action text-danger" data-action="delete" data-title="Bulk Hapus Invoice" data-text="Invoice terpilih akan dihapus sesuai role pengguna.">
                            <i class="bi bi-trash me-2"></i>Hapus
                        </button>
                    </li>
                </ul>
            </div>
            <a href="<?php echo site_url('billing/acs-gap'); ?>" class="btn btn-sm btn-outline-info" title="Cek customer WAN IP yang belum terdaftar di ACS">
                <i class="bi bi-hdd-network me-1"></i>ACS Gap
            </a>
            <?php echo form_open('ont/sync', array('class' => 'd-inline', 'onsubmit' => "return confirm('Jalankan summon all ONT dari GenieACS?');")); ?>
                <input type="hidden" name="return_url" value="<?php echo html_escape($billing_return_url); ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-arrow-repeat me-1"></i>Summon All
                </button>
            <?php echo form_close(); ?>
            <span class="small text-muted ms-auto" id="selected_count">0 selected</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:42px;">
                                <input type="checkbox" id="select_all">
                            </th>
                            <th>Invoice</th>
                            <th>Pelanggan</th>
                            <th>User PPP</th>
                            <th>BW</th>
                            <th>Periode</th>
                            <th>Jatuh Tempo</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Sisa</th>
                            <th>Status</th>
                            <th class="text-end pe-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td class="ps-3 text-muted" colspan="11">Tidak ada data invoice.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $invoice_id = (int) ($r['id'] ?? 0);
                                $status_raw = strtolower((string) ($r['status'] ?? 'issued'));
                                list($status_label, $badge_class) = billing_status_badge($status_raw);
                                $period = !empty($r['billing_period_start']) ? date('Y-m', strtotime((string) $r['billing_period_start'])) : '-';
                                $due_date = !empty($r['due_date']) ? date('d-m-Y', strtotime((string) $r['due_date'])) : '-';
                                $total_amount = (float) ($r['total_amount'] ?? 0);
                                $balance_amount = (float) ($r['balance_amount'] ?? max(0, $total_amount - (float) ($r['paid_amount'] ?? 0)));
                                $return_url_param = rawurlencode($billing_return_url);
                                $print_link = site_url('billing/view/' . $invoice_id) . '?print=1&return_url=' . $return_url_param;
                                $preview_link = site_url('billing/view/' . $invoice_id) . '?embed=1&return_url=' . $return_url_param;
                                $edit_link = site_url('billing/edit/' . $invoice_id) . '?return_url=' . rawurlencode($billing_return_url);
                                $pppoe_username = trim((string) ($r['service_pppoe_username'] ?? ''));
                                if ($pppoe_username === '') {
                                    $pppoe_username = trim((string) ($r['customer_pppoe_username'] ?? ''));
                                }
                                if ($pppoe_username === '') {
                                    $pppoe_username = trim((string) ($r['customer_username'] ?? ''));
                                }
                                if ($pppoe_username === '') {
                                    $pppoe_username = '-';
                                }
                                $profile_name = trim((string) ($r['profile_name'] ?? ''));
                                $bw_label = strtoupper(trim((string) ($r['bw_label'] ?? '')));
                                list($bw_badge_label, $bw_badge_class) = billing_bw_badge($bw_label);

                                $wa_phone = preg_replace('/\D+/', '', (string) ($r['customer_phone'] ?? ''));
                                if (strpos($wa_phone, '0') === 0) {
                                    $wa_phone = '62' . substr($wa_phone, 1);
                                }
                                if ($wa_phone !== '' && strpos($wa_phone, '62') !== 0) {
                                    $wa_phone = '';
                                }

                                $wa_message_lines = array(
                                    '*BUJANAYA NETWORKS*',
                                    '*INVOICE TAGIHAN INTERNET*',
                                    '',
                                    '- Invoice: ' . (string) ($r['invoice_number'] ?? '-'),
                                    '- Nama Customer: ' . (string) ($r['customer_name'] ?? '-'),
                                    '- User PPP: ' . $pppoe_username,
                                    '- BW: ' . $bw_badge_label,
                                    '- Periode: ' . $period,
                                    '- Jatuh Tempo: ' . $due_date,
                                    '- Total: Rp ' . number_format($total_amount, 0, ',', '.'),
                                );
                                $wa_message = implode("\n", $wa_message_lines);
                                $wa_message_encoded = rawurlencode($wa_message);

                                $can_mark_paid = !in_array($status_raw, array('paid', 'void'), true) && $balance_amount > 0;
                                $can_mark_overdue = !in_array($status_raw, array('paid', 'void'), true);
                                ?>
                                <tr>
                                    <td class="ps-3">
                                        <input type="checkbox" class="invoice_checkbox" value="<?php echo $invoice_id; ?>">
                                    </td>
                                    <td class="fw-semibold"><?php echo html_escape((string) ($r['invoice_number'] ?? '-')); ?></td>
                                    <td>
                                        <div class="fw-medium"><?php echo html_escape((string) ($r['customer_name'] ?? '-')); ?></div>
                                        <div class="small text-muted"><?php echo html_escape((string) ($r['customer_phone'] ?? '')); ?></div>
                                    </td>
                                    <td><span class="badge text-bg-light border"><?php echo html_escape($pppoe_username); ?></span></td>
                                    <td>
                                        <span class="badge text-bg-<?php echo html_escape($bw_badge_class); ?>"><?php echo html_escape($bw_badge_label); ?></span>
                                        <?php if ($profile_name !== '' && strtoupper($profile_name) !== $bw_badge_label): ?>
                                            <div class="small text-muted mt-1"><?php echo html_escape($profile_name); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo html_escape($period); ?></td>
                                    <td><?php echo html_escape($due_date); ?></td>
                                    <td class="text-end">Rp <?php echo number_format($total_amount, 0, ',', '.'); ?></td>
                                    <td class="text-end">Rp <?php echo number_format($balance_amount, 0, ',', '.'); ?></td>
                                    <td>
                                        <span class="badge text-bg-<?php echo html_escape($badge_class); ?>"><?php echo html_escape($status_label); ?></span>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="btn-group btn-group-sm" role="group" aria-label="invoice-actions">
                                            <button
                                                type="button"
                                                class="btn btn-outline-primary js-preview-invoice"
                                                data-invoice-url="<?php echo html_escape($preview_link); ?>"
                                                data-invoice-number="<?php echo html_escape((string) ($r['invoice_number'] ?? '-')); ?>"
                                            >
                                                <i class="bi bi-eye"></i><span class="d-none d-md-inline ms-1">Lihat</span>
                                            </button>
                                            <a href="<?php echo html_escape($print_link); ?>" target="_blank" rel="noopener" class="btn btn-outline-dark action-icon-btn" title="Print Invoice">
                                                <i class="bi bi-printer"></i><span class="d-none d-lg-inline ms-1">Print</span>
                                            </a>
                                            <button
                                                type="button"
                                                class="btn btn-outline-success action-icon-btn js-row-wa"
                                                title="Blast WhatsApp"
                                                data-wa-phone="<?php echo html_escape($wa_phone); ?>"
                                                data-wa-message="<?php echo html_escape($wa_message_encoded); ?>"
                                            >
                                                <i class="bi bi-whatsapp"></i><span class="d-none d-lg-inline ms-1">WA</span>
                                            </button>
                                            <a href="<?php echo html_escape($edit_link); ?>" class="btn btn-outline-secondary">
                                                <i class="bi bi-pencil-square"></i><span class="d-none d-md-inline ms-1">Edit</span>
                                            </a>
                                        </div>
                                        <div class="d-flex justify-content-end gap-1 mt-1">
                                            <?php echo form_open('billing/mark-paid/' . $invoice_id, array('class' => 'd-inline')); ?>
                                                <input type="hidden" name="return_url" value="<?php echo html_escape($billing_return_url); ?>">
                                                <button type="submit" class="btn btn-sm btn-success" <?php echo $can_mark_paid ? '' : 'disabled'; ?>>Lunas</button>
                                            <?php echo form_close(); ?>

                                            <?php echo form_open('billing/mark-overdue/' . $invoice_id, array('class' => 'd-inline')); ?>
                                                <input type="hidden" name="return_url" value="<?php echo html_escape($billing_return_url); ?>">
                                                <button type="submit" class="btn btn-sm btn-warning" <?php echo $can_mark_overdue ? '' : 'disabled'; ?>>Overdue</button>
                                            <?php echo form_close(); ?>

                                            <?php echo form_open('billing/delete-ont/' . $invoice_id, array('class' => 'd-inline', 'onsubmit' => "return confirm('Hapus ONT customer ini dari GenieACS?');")); ?>
                                                <input type="hidden" name="return_url" value="<?php echo html_escape($billing_return_url); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus ONT ACS">
                                                    <i class="bi bi-router"></i><span class="d-none d-lg-inline ms-1">ONT</span>
                                                </button>
                                            <?php echo form_close(); ?>

                                            <?php echo form_open('billing/delete/' . $invoice_id, array('class' => 'd-inline', 'onsubmit' => "return confirm('Yakin hapus invoice ini?');")); ?>
                                                <input type="hidden" name="return_url" value="<?php echo html_escape($billing_return_url); ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                            <?php echo form_close(); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
        </div>

        <?php echo form_open('billing/bulk_action', array('id' => 'billingBulkForm', 'class' => 'd-none')); ?>
            <input type="hidden" name="bulk_action" id="billing_bulk_action" value="">
            <input type="hidden" name="invoice_ids_csv" id="billing_invoice_ids_csv" value="">
            <input type="hidden" name="return_url" value="<?php echo html_escape($billing_return_url); ?>">
        <?php echo form_close(); ?>

        <div class="p-3 border-top d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div>
                <div class="small text-muted mb-1">Page View</div>
                <div class="d-flex flex-wrap gap-1" role="group" aria-label="billing-page-view-buttons">
                    <?php foreach ($per_page_options as $opt): ?>
                        <?php $opt = (int) $opt; ?>
                        <?php $input_id = 'billing_per_page_' . $opt; ?>
                        <input
                            class="btn-check"
                            type="radio"
                            name="per_page"
                            id="<?php echo $input_id; ?>"
                            form="billingFilterForm"
                            value="<?php echo $opt; ?>"
                            autocomplete="off"
                            onchange="document.getElementById('billingFilterForm').submit();"
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

<div class="modal fade" id="invoicePreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="invoicePreviewTitle">Preview Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" style="min-height:70vh;">
                <iframe
                    id="invoicePreviewFrame"
                    src="about:blank"
                    class="w-100 border-0"
                    style="min-height:70vh;"
                    loading="lazy"
                    title="Preview Invoice"
                ></iframe>
            </div>
            <div class="modal-footer">
                <a href="#" id="invoicePreviewOpenNewTab" class="btn btn-outline-primary" target="_blank" rel="noopener">Open in New Tab</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

$swal_success = $this->session->flashdata('success');
$swal_error = $this->session->flashdata('error');

$page_scripts = <<<'SCRIPT'
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectAll = document.getElementById('select_all');
    var selectedCount = document.getElementById('selected_count');
    var bulkForm = document.getElementById('billingBulkForm');
    var bulkActionInput = document.getElementById('billing_bulk_action');
    var invoiceIdsInput = document.getElementById('billing_invoice_ids_csv');
    var billingPeriodInput = document.getElementById('billing_period_input');
    var billingAllPeriods = document.getElementById('billing_all_periods');
    var manualGenerateMode = document.getElementById('manual_generate_mode');
    var manualGeneratePeriod = document.getElementById('manual_generate_period');
    var manualGenerateDate = document.getElementById('manual_generate_date');
    var invoicePreviewModalEl = document.getElementById('invoicePreviewModal');
    var invoicePreviewFrame = document.getElementById('invoicePreviewFrame');
    var invoicePreviewTitle = document.getElementById('invoicePreviewTitle');
    var invoicePreviewOpenNewTab = document.getElementById('invoicePreviewOpenNewTab');
    var invoicePreviewModal = null;

    if (invoicePreviewModalEl && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
        invoicePreviewModal = new window.bootstrap.Modal(invoicePreviewModalEl);
    }

    function eachNode(nodeList, callback) {
        Array.prototype.forEach.call(nodeList, callback);
    }

    function getCheckboxes() {
        return document.querySelectorAll('.invoice_checkbox');
    }

    function countSelected() {
        var boxes = getCheckboxes();
        var checked = 0;
        eachNode(boxes, function (cb) {
            if (cb.checked) {
                checked++;
            }
        });

        if (selectedCount) {
            selectedCount.textContent = checked + ' selected';
        }

        if (selectAll) {
            selectAll.checked = boxes.length > 0 && checked === boxes.length;
            selectAll.indeterminate = checked > 0 && checked < boxes.length;
        }

        return checked;
    }

    function collectSelectedIds() {
        var ids = [];
        eachNode(getCheckboxes(), function (cb) {
            if (cb.checked) {
                ids.push(cb.value);
            }
        });
        return ids;
    }

    function confirmDialog(options, onConfirm) {
        if (window.Swal) {
            Swal.fire(options).then(function (result) {
                if (result.isConfirmed) {
                    onConfirm();
                }
            });
            return;
        }

        if (window.confirm(options.text || 'Lanjutkan aksi ini?')) {
            onConfirm();
        }
    }

    function submitBulk(action, title, text) {
        var ids = collectSelectedIds();
        if (ids.length === 0) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Pilih invoice',
                    text: 'Pilih minimal 1 invoice.'
                });
            } else {
                alert('Pilih minimal 1 invoice.');
            }
            return;
        }

        confirmDialog({
            icon: 'question',
            title: title,
            text: text,
            showCancelButton: true,
            confirmButtonText: 'Ya, lanjut',
            cancelButtonText: 'Batal'
        }, function () {
            if (bulkActionInput) {
                bulkActionInput.value = action;
            }
            if (invoiceIdsInput) {
                invoiceIdsInput.value = ids.join(',');
            }
            if (bulkForm) {
                bulkForm.submit();
            }
        });
    }

    function normalizeWaPhone(raw) {
        var phone = String(raw || '').replace(/\D+/g, '');
        if (phone.indexOf('0') === 0) {
            phone = '62' + phone.substring(1);
        }
        return phone;
    }

    function isValidWaPhone(phone) {
        return /^62\d{8,14}$/.test(String(phone || ''));
    }

    function openWa(phone, message) {
        var url = 'https://api.whatsapp.com/send?phone=' + encodeURIComponent(phone) + '&text=' + encodeURIComponent(String(message || ''));
        window.open(url, '_blank', 'noopener');
    }

    function syncManualGenerateFields() {
        if (!manualGenerateMode) {
            return;
        }
        var mode = manualGenerateMode.value;
        if (manualGeneratePeriod) {
            manualGeneratePeriod.disabled = (mode !== 'period');
        }
        if (manualGenerateDate) {
            manualGenerateDate.disabled = (mode === 'period');
        }
    }

    function syncBillingPeriodFilter() {
        if (!billingPeriodInput || !billingAllPeriods) {
            return;
        }

        billingPeriodInput.disabled = billingAllPeriods.checked;
        if (billingAllPeriods.checked) {
            billingPeriodInput.value = '';
        } else if (billingPeriodInput.value === '') {
            billingPeriodInput.value = billingPeriodInput.getAttribute('data-default-period') || '';
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var boxes = getCheckboxes();
            eachNode(boxes, function (cb) {
                cb.checked = selectAll.checked;
            });
            countSelected();
        });
    }

    document.addEventListener('change', function (event) {
        if (event.target && event.target.classList && event.target.classList.contains('invoice_checkbox')) {
            countSelected();
        }
    });

    document.addEventListener('click', function (event) {
        var previewTrigger = event.target.closest('.js-preview-invoice');
        if (previewTrigger) {
            var invoiceUrl = previewTrigger.getAttribute('data-invoice-url') || '';
            var invoiceNumber = previewTrigger.getAttribute('data-invoice-number') || '';
            if (!invoiceUrl) {
                return;
            }

            if (invoicePreviewTitle) {
                invoicePreviewTitle.textContent = 'Preview Invoice ' + invoiceNumber;
            }
            if (invoicePreviewFrame) {
                invoicePreviewFrame.setAttribute('src', invoiceUrl);
            }
            if (invoicePreviewOpenNewTab) {
                invoicePreviewOpenNewTab.setAttribute('href', invoiceUrl);
            }

            if (invoicePreviewModal) {
                invoicePreviewModal.show();
            } else {
                window.open(invoiceUrl, '_blank', 'noopener');
            }
            return;
        }

        var waTrigger = event.target.closest('.js-row-wa');
        if (waTrigger) {
            event.preventDefault();
            var phone = normalizeWaPhone(waTrigger.getAttribute('data-wa-phone') || '');
            var message = '';
            try {
                message = decodeURIComponent(waTrigger.getAttribute('data-wa-message') || '');
            } catch (e) {
                message = '';
            }

            if (isValidWaPhone(phone)) {
                openWa(phone, message);
                return;
            }

            if (window.Swal) {
                Swal.fire({
                    title: 'Nomor WhatsApp',
                    text: 'Nomor customer belum tersedia. Masukkan nomor tujuan (format 62xxxxxxxxxx).',
                    input: 'text',
                    inputValue: '62',
                    showCancelButton: true,
                    confirmButtonText: 'Kirim',
                    cancelButtonText: 'Batal',
                    preConfirm: function (value) {
                        var normalized = normalizeWaPhone(value);
                        if (!isValidWaPhone(normalized)) {
                            Swal.showValidationMessage('Format nomor tidak valid');
                            return false;
                        }
                        return normalized;
                    }
                }).then(function (result) {
                    if (result.isConfirmed && result.value) {
                        openWa(result.value, message);
                    }
                });
            } else {
                var input = window.prompt('Masukkan nomor WhatsApp (62xxxxxxxxxx):', '62');
                var normalized = normalizeWaPhone(input);
                if (!isValidWaPhone(normalized)) {
                    alert('Format nomor tidak valid.');
                    return;
                }
                openWa(normalized, message);
            }
            return;
        }

        var trigger = event.target.closest('.js-bulk-action');
        if (!trigger) {
            return;
        }

        var action = trigger.getAttribute('data-action') || '';
        var title = trigger.getAttribute('data-title') || 'Bulk Action';
        var text = trigger.getAttribute('data-text') || 'Lanjutkan aksi ini?';
        submitBulk(action, title, text);
    });

    if (manualGenerateMode) {
        manualGenerateMode.addEventListener('change', syncManualGenerateFields);
        syncManualGenerateFields();
    }

    if (billingAllPeriods) {
        billingAllPeriods.addEventListener('change', syncBillingPeriodFilter);
        syncBillingPeriodFilter();
    }

    if (invoicePreviewModalEl) {
        invoicePreviewModalEl.addEventListener('hidden.bs.modal', function () {
            if (invoicePreviewFrame) {
                invoicePreviewFrame.setAttribute('src', 'about:blank');
            }
        });
    }

    countSelected();
});
</script>
SCRIPT;

if ($swal_success) {
    $page_scripts .= '<script>document.addEventListener("DOMContentLoaded",function(){Swal.fire({icon:"success",title:"Berhasil",text:' . json_encode((string) $swal_success) . '});});</script>';
}
if ($swal_error) {
    $page_scripts .= '<script>document.addEventListener("DOMContentLoaded",function(){Swal.fire({icon:"error",title:"Error",text:' . json_encode((string) $swal_error) . '});});</script>';
}

include APPPATH . 'views/layout/master.php';
