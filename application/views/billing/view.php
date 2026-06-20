<?php
$page_title = 'Invoice - ' . app_name();
$page_heading = '';
$page_subheading = '';
$active_menu = 'billing';
$invoice = isset($invoice) && is_array($invoice) ? $invoice : array();
$router = isset($router) && is_array($router) ? $router : array();
$wa_url = isset($wa_url) ? (string) $wa_url : '';
$embed_only = !empty($embed_only);
$auto_print = !empty($auto_print);
$return_url = isset($return_url) ? trim((string) $return_url) : 'billing';
if ($return_url === '' || preg_match('/^(https?:)?\/\//i', $return_url)) {
    $return_url = 'billing';
}
$back_url = site_url($return_url);

$company_name = trim((string) ($router['brand_name'] ?? ''));
if ($company_name === '') {
    $company_name = trim((string) config_item('company_name'));
}
$company_tagline = trim((string) config_item('company_tagline'));
$company_address = trim((string) ($router['brand_address'] ?? ''));
if ($company_address === '') {
    $company_address = trim((string) config_item('company_address'));
}
$company_phone = trim((string) ($router['brand_phone'] ?? ''));
if ($company_phone === '') {
    $company_phone = trim((string) config_item('company_phone'));
}
$company_email = trim((string) ($router['brand_email'] ?? ''));
if ($company_email === '') {
    $company_email = trim((string) config_item('company_email'));
}
$company_website = trim((string) ($router['brand_website'] ?? ''));
$brand_bank_name = trim((string) ($router['brand_bank_name'] ?? ''));
$brand_bank_account = trim((string) ($router['brand_bank_account'] ?? ''));
$brand_bank_holder = trim((string) ($router['brand_bank_holder'] ?? ''));
$invoice_footer = trim((string) ($router['invoice_footer'] ?? ''));
$brand_logo_raw = trim((string) ($router['brand_logo'] ?? ''));
$brand_logo_url = '';
if ($brand_logo_raw !== '') {
    $brand_logo_url = preg_match('~^https?://~i', $brand_logo_raw)
        ? $brand_logo_raw
        : base_url(ltrim($brand_logo_raw, '/'));
}

if ($company_name === '') {
    $company_name = app_company();
}
if ($company_tagline === '') {
    $company_tagline = app_tagline();
}
if ($company_address === '') {
    $company_address = 'Alamat perusahaan belum diatur';
}
if ($company_phone === '') {
    $company_phone = '-';
}
if ($company_email === '') {
    $company_email = 'billing@example.invalid';
}
if ($invoice_footer === '') {
    $invoice_footer = 'Terima kasih telah menggunakan layanan kami.';
}

$invoice_id = (int) ($invoice['id'] ?? 0);
$invoice_number = (string) ($invoice['invoice_number'] ?? '-');
$customer_name = (string) ($invoice['customer_name'] ?? '-');
$customer_phone = (string) ($invoice['customer_phone'] ?? '-');
$customer_address = (string) ($invoice['customer_address'] ?? '-');
$profile_name = (string) ($invoice['profile_name'] ?? '-');
$remote_ip = (string) ($invoice['remote_ip'] ?? '-');
$customer_ont_device_id = trim((string) ($invoice['customer_ont_device_id'] ?? ''));
$customer_ont_serial = trim((string) ($invoice['customer_ont_serial'] ?? ''));
$customer_ip_address = trim((string) ($invoice['customer_ip_address'] ?? ''));
$can_delete_ont = $customer_ont_device_id !== ''
    || $customer_ont_serial !== ''
    || filter_var($customer_ip_address, FILTER_VALIDATE_IP)
    || filter_var($remote_ip, FILTER_VALIDATE_IP);

$period_start = (string) ($invoice['billing_period_start'] ?? '');
$period_end = (string) ($invoice['billing_period_end'] ?? '');
$issue_date = (string) ($invoice['issue_date'] ?? '');
$due_date = (string) ($invoice['due_date'] ?? '');
$status = strtolower((string) ($invoice['status'] ?? 'issued'));
$notes = (string) ($invoice['notes'] ?? '');

$subtotal = (float) ($invoice['subtotal'] ?? 0);
$tax_amount = (float) ($invoice['tax_amount'] ?? 0);
$discount_amount = (float) ($invoice['discount_amount'] ?? 0);
$total_amount = (float) ($invoice['total_amount'] ?? 0);
$paid_amount = (float) ($invoice['paid_amount'] ?? 0);
$balance_amount = (float) ($invoice['balance_amount'] ?? max(0, $total_amount - $paid_amount));

$last_payment_date = (string) ($invoice['last_payment_date'] ?? '');
$last_payment_method = strtoupper((string) ($invoice['last_payment_method'] ?? ''));

$period_label = '-';
if ($period_start !== '') {
    $period_label = date('F Y', strtotime($period_start));
}

$status_stamp = 'BELUM LUNAS';
$status_stamp_class = 'warning';
if ($status === 'paid') {
    $status_stamp = 'LUNAS';
    $status_stamp_class = 'success';
} elseif ($status === 'overdue') {
    $status_stamp = 'OVERDUE';
    $status_stamp_class = 'danger';
} elseif ($status === 'void') {
    $status_stamp = 'CANCEL';
    $status_stamp_class = 'secondary';
} elseif ($status === 'partially_paid') {
    $status_stamp = 'PARSIAL';
    $status_stamp_class = 'info';
}

$can_mark_paid = !in_array($status, array('paid', 'void'), true) && $balance_amount > 0;
$can_mark_overdue = !in_array($status, array('paid', 'void'), true);

$wa_default_phone = preg_replace('/\D+/', '', (string) $customer_phone);
if (strpos($wa_default_phone, '0') === 0) {
    $wa_default_phone = '62' . substr($wa_default_phone, 1);
}
if ($wa_default_phone !== '' && strpos($wa_default_phone, '62') !== 0) {
    $wa_default_phone = '';
}

$wa_message_lines = array();
$wa_message_lines[] = '*' . strtoupper($company_name) . '*';
$wa_message_lines[] = '*INVOICE TAGIHAN INTERNET*';
$wa_message_lines[] = '';
$wa_message_lines[] = '*Detail Invoice:*';
$wa_message_lines[] = '- Invoice: ' . $invoice_number;
$wa_message_lines[] = '- Nama Customer: ' . $customer_name;
$wa_message_lines[] = '- Paket: ' . ($profile_name !== '' ? $profile_name : '-');
$wa_message_lines[] = '- IP Address: ' . ($remote_ip !== '' ? $remote_ip : '-');
$wa_message_lines[] = '- Periode: ' . $period_label;
$wa_message_lines[] = '- Jatuh Tempo: ' . ($due_date !== '' ? date('d-m-Y', strtotime($due_date)) : '-');
$wa_message_lines[] = '- Total Tagihan: Rp ' . number_format($total_amount, 0, ',', '.');
$wa_message_lines[] = '';
$wa_message_lines[] = 'Silakan lakukan pembayaran sebelum jatuh tempo.';
$wa_message_lines[] = 'Terima kasih.';
$wa_message_template = implode("\n", $wa_message_lines);

ob_start();
?>
<?php if (!$embed_only): ?>
    <div class="invoice-toolbar d-flex flex-wrap gap-2 mb-3">
        <a href="<?php echo html_escape($back_url); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <button type="button" class="btn btn-success btn-sm" id="btnOpenWaModal"><i class="bi bi-whatsapp"></i> Kirim WhatsApp</button>
        <a href="<?php echo site_url('billing/edit/' . $invoice_id) . '?return_url=' . rawurlencode($return_url); ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil-square"></i> Edit Invoice</a>

        <?php if ($can_delete_ont): ?>
            <?php echo form_open('billing/delete-ont/' . $invoice_id, array('class' => 'd-inline', 'onsubmit' => "return confirm('Hapus ONT customer ini dari GenieACS?');")); ?>
                <input type="hidden" name="return_url" value="<?php echo html_escape($return_url); ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-router"></i> Hapus ONT ACS</button>
            <?php echo form_close(); ?>
        <?php endif; ?>

        <?php if ($can_mark_paid): ?>
            <?php echo form_open('billing/mark-paid/' . $invoice_id, array('class' => 'd-inline')); ?>
                <input type="hidden" name="return_url" value="<?php echo html_escape($return_url); ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2-circle"></i> Lunas</button>
            <?php echo form_close(); ?>
        <?php endif; ?>

        <?php if ($can_mark_overdue): ?>
            <?php echo form_open('billing/mark-overdue/' . $invoice_id, array('class' => 'd-inline')); ?>
                <input type="hidden" name="return_url" value="<?php echo html_escape($return_url); ?>">
                <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-exclamation-triangle"></i> Overdue</button>
            <?php echo form_close(); ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div id="invoice-print-area" class="invoice-sheet bg-white border rounded-4 shadow-sm p-3 p-md-4">
    <div class="invoice-head d-flex flex-column flex-lg-row justify-content-between gap-3 pb-3 border-bottom">
        <div class="d-flex gap-3">
            <?php if ($brand_logo_url !== ''): ?>
                <div class="brand-mark rounded-3 d-flex align-items-center justify-content-center fw-bold text-white bg-white border">
                    <img src="<?php echo html_escape($brand_logo_url); ?>" alt="Brand Logo" style="max-width:100%; max-height:54px;">
                </div>
            <?php else: ?>
                <div class="brand-mark rounded-3 d-flex align-items-center justify-content-center fw-bold text-white">
                    BN
                </div>
            <?php endif; ?>
            <div>
                <h4 class="mb-1 text-primary fw-bold"><?php echo html_escape($company_name); ?></h4>
                <div class="text-muted small"><?php echo html_escape($company_tagline); ?></div>
                <div class="small text-muted"><i class="bi bi-geo-alt"></i> <?php echo html_escape($company_address); ?></div>
                <div class="small text-muted"><i class="bi bi-telephone"></i> <?php echo html_escape($company_phone); ?></div>
                <div class="small text-muted"><i class="bi bi-envelope"></i> <?php echo html_escape($company_email); ?></div>
                <?php if ($company_website !== ''): ?>
                    <div class="small text-muted"><i class="bi bi-globe"></i> <?php echo html_escape($company_website); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="invoice-meta text-lg-end">
            <div class="invoice-title fw-bold mb-2">INVOICE</div>
            <div class="small"><strong>Invoice #:</strong> <?php echo html_escape($invoice_number); ?></div>
            <div class="small"><strong>Tanggal:</strong> <?php echo html_escape($issue_date !== '' ? date('d F Y', strtotime($issue_date)) : '-'); ?></div>
            <div class="small"><strong>Jatuh Tempo:</strong> <?php echo html_escape($due_date !== '' ? date('d F Y', strtotime($due_date)) : '-'); ?></div>
            <span class="status-stamp status-<?php echo html_escape($status_stamp_class); ?> mt-2"><?php echo html_escape($status_stamp); ?></span>
        </div>
    </div>

    <div class="row g-3 mt-2">
        <div class="col-md-5">
            <div class="invoice-box h-100">
                <div class="invoice-box-title">Tagihan Untuk</div>
                <div class="fw-bold"><?php echo html_escape($customer_name); ?></div>
                <div class="small text-muted"><?php echo html_escape($customer_phone !== '' ? $customer_phone : '-'); ?></div>
                <div class="small text-muted mb-2"><?php echo html_escape($customer_address !== '' ? $customer_address : '-'); ?></div>
                <div class="small"><strong>Paket:</strong> <span class="badge text-bg-primary"><?php echo html_escape($profile_name !== '' ? $profile_name : '-'); ?></span></div>
                <div class="small"><strong>IP Address:</strong> <?php echo html_escape($remote_ip !== '' ? $remote_ip : '-'); ?></div>
                <div class="small"><strong>Periode:</strong> <?php echo html_escape($period_label); ?></div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="invoice-box h-100">
                <div class="invoice-box-title">Detail Tagihan</div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0 invoice-table">
                        <thead>
                            <tr>
                                <th>Deskripsi</th>
                                <th style="width:120px;">Periode</th>
                                <th class="text-end" style="width:160px;">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div class="fw-semibold">Layanan Internet</div>
                                    <div class="small text-muted">Paket <?php echo html_escape($profile_name !== '' ? $profile_name : '-'); ?></div>
                                    <?php if ($period_start !== '' || $period_end !== ''): ?>
                                        <div class="small text-muted"><?php echo html_escape($period_start !== '' ? date('d-m-Y', strtotime($period_start)) : '-'); ?> s/d <?php echo html_escape($period_end !== '' ? date('d-m-Y', strtotime($period_end)) : '-'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo html_escape($period_label); ?></td>
                                <td class="text-end fw-bold">Rp <?php echo number_format($subtotal, 0, ',', '.'); ?></td>
                            </tr>
                            <?php if ($tax_amount > 0): ?>
                                <tr>
                                    <td colspan="2">Pajak</td>
                                    <td class="text-end">Rp <?php echo number_format($tax_amount, 0, ',', '.'); ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($discount_amount > 0): ?>
                                <tr>
                                    <td colspan="2">Diskon</td>
                                    <td class="text-end text-danger">- Rp <?php echo number_format($discount_amount, 0, ',', '.'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="total-box mt-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div>
            <div class="total-title">Total Tagihan</div>
            <div class="small opacity-75">Silakan lakukan pembayaran sebelum tanggal jatuh tempo.</div>
        </div>
        <div class="total-amount">Rp <?php echo number_format($total_amount, 0, ',', '.'); ?></div>
    </div>

    <?php if ($paid_amount > 0 || $status === 'paid'): ?>
        <div class="payment-state state-paid mt-3">
            <div class="fw-semibold mb-1"><i class="bi bi-check-circle-fill"></i> Invoice Telah Dibayar</div>
            <div><strong>Nominal:</strong> Rp <?php echo number_format($paid_amount, 0, ',', '.'); ?></div>
            <div><strong>Tanggal Bayar:</strong> <?php echo html_escape($last_payment_date !== '' ? date('d F Y H:i', strtotime($last_payment_date)) : '-'); ?></div>
            <div><strong>Metode Pembayaran:</strong> <?php echo html_escape($last_payment_method !== '' ? $last_payment_method : '-'); ?></div>
        </div>
    <?php else: ?>
        <div class="payment-state state-unpaid mt-3">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill"></i> Invoice Belum Dibayar</div>
            <div><strong>Sisa Tagihan:</strong> Rp <?php echo number_format($balance_amount, 0, ',', '.'); ?></div>
            <div><strong>Jatuh Tempo:</strong> <?php echo html_escape($due_date !== '' ? date('d F Y', strtotime($due_date)) : '-'); ?></div>
            <div class="small text-muted">Status overdue lebih dari 5 hari akan masuk isolir otomatis.</div>
        </div>
    <?php endif; ?>

    <?php if ($notes !== ''): ?>
        <div class="mt-3 border-top pt-3 small text-muted">
            <strong>Catatan:</strong><br>
            <?php echo nl2br(html_escape($notes)); ?>
        </div>
    <?php endif; ?>

    <?php if ($brand_bank_name !== '' || $brand_bank_account !== '' || $brand_bank_holder !== '' || $invoice_footer !== ''): ?>
        <div class="mt-3 border-top pt-3 small text-muted">
            <?php if ($brand_bank_name !== '' || $brand_bank_account !== '' || $brand_bank_holder !== ''): ?>
                <div><strong>Pembayaran:</strong>
                    <?php echo html_escape(trim($brand_bank_name . ' ' . $brand_bank_account)); ?>
                    <?php if ($brand_bank_holder !== ''): ?>
                        (a/n <?php echo html_escape($brand_bank_holder); ?>)
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($invoice_footer !== ''): ?>
                <div class="mt-1"><?php echo nl2br(html_escape($invoice_footer)); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="invoice-fab d-none d-lg-flex flex-column gap-2">
    <button type="button" class="fab-btn fab-wa" title="WhatsApp" id="btnOpenWaModalFab"><i class="bi bi-whatsapp"></i></button>
    <button type="button" class="fab-btn fab-print" onclick="window.print()" title="Print"><i class="bi bi-printer"></i></button>
    <?php if (!$embed_only): ?>
        <a href="<?php echo site_url('billing'); ?>" class="fab-btn fab-back" title="Kembali"><i class="bi bi-arrow-left"></i></a>
    <?php endif; ?>
</div>

<div class="modal fade" id="waInvoiceModal" tabindex="-1" aria-labelledby="waInvoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="waInvoiceModalLabel"><i class="bi bi-whatsapp"></i> Kirim Invoice ke WhatsApp</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nomor WhatsApp</label>
                    <input type="text" class="form-control" id="waPhoneInput" placeholder="6281234567890" value="<?php echo html_escape($wa_default_phone); ?>">
                    <div class="form-text">Format: 62 diikuti nomor HP tanpa 0.</div>
                </div>
                <div>
                    <label class="form-label">Pesan</label>
                    <textarea class="form-control" id="waMessageInput" rows="12"><?php echo html_escape($wa_message_template); ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-success" id="btnSendWaNow"><i class="bi bi-whatsapp"></i> Kirim WhatsApp</button>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

$page_scripts = <<<'SCRIPT'
<style>
.invoice-sheet {
    max-width: 920px;
    margin: 0 auto;
    border: 1px solid #dbe3ee;
}
.brand-mark {
    width: 62px;
    height: 62px;
    font-size: 1.1rem;
    background: linear-gradient(140deg, #0d6efd, #00a3a3);
}
.invoice-title {
    letter-spacing: 0.08em;
    font-size: 1.65rem;
}
.status-stamp {
    display: inline-block;
    transform: rotate(-8deg);
    border: 2px solid;
    border-radius: 10px;
    padding: 0.2rem 0.65rem;
    font-weight: 700;
    font-size: 0.86rem;
    letter-spacing: 0.07em;
}
.status-success { color: #198754; border-color: #198754; }
.status-danger { color: #dc3545; border-color: #dc3545; }
.status-warning { color: #f59f00; border-color: #f59f00; }
.status-secondary { color: #6c757d; border-color: #6c757d; }
.status-info { color: #0dcaf0; border-color: #0dcaf0; }
.invoice-box {
    border: 1px solid #dbe3ee;
    border-radius: 0.8rem;
    padding: 1rem;
    background: #fff;
}
.invoice-box-title {
    font-weight: 700;
    color: #0d6efd;
    margin-bottom: 0.65rem;
}
.invoice-table thead th {
    background: #d9e7fb;
}
.total-box {
    border-radius: 0.8rem;
    color: #fff;
    padding: 1.1rem 1.3rem;
    background: linear-gradient(120deg, #4a7dff, #6f42c1);
}
.total-title {
    font-weight: 700;
    font-size: 1.25rem;
}
.total-amount {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
}
.payment-state {
    border-radius: 0.7rem;
    padding: 0.95rem 1rem;
    border: 1px solid transparent;
}
.state-paid {
    background: #e8f7ef;
    border-color: #b9e6cd;
    color: #1f5135;
}
.state-unpaid {
    background: #fff6e6;
    border-color: #ffe0a6;
    color: #7a4f01;
}
.invoice-fab {
    position: fixed;
    right: 20px;
    bottom: 22px;
    z-index: 1030;
}
.fab-btn {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    text-decoration: none;
    box-shadow: 0 8px 20px rgba(0,0,0,.2);
}
.fab-wa { background: #25D366; }
.fab-print { background: #0d6efd; }
.fab-back { background: #6c757d; }
@media (max-width: 768px) {
    .total-amount {
        font-size: 1.6rem;
    }
    .invoice-sheet {
        border-radius: 0.6rem !important;
    }
}
@media print {
    body { background: #fff !important; }
    .app-header,
    .app-sidebar,
    .invoice-toolbar,
    .invoice-fab,
    footer,
    .app-main > .mb-3 {
        display: none !important;
    }
    .app-main {
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    #invoice-print-area {
        margin: 0 !important;
        max-width: 100% !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('waInvoiceModal');
    var btnOpen = document.getElementById('btnOpenWaModal');
    var btnOpenFab = document.getElementById('btnOpenWaModalFab');
    var btnSend = document.getElementById('btnSendWaNow');
    var phoneInput = document.getElementById('waPhoneInput');
    var messageInput = document.getElementById('waMessageInput');
    var bsModal = modalEl ? new bootstrap.Modal(modalEl) : null;

    function normalizePhone(raw) {
        var phone = String(raw || '').replace(/\D+/g, '');
        if (phone.indexOf('0') === 0) {
            phone = '62' + phone.substring(1);
        }
        return phone;
    }

    function isValidPhone(phone) {
        return /^62\d{8,14}$/.test(phone);
    }

    function openModal() {
        if (!bsModal) {
            return;
        }
        bsModal.show();
        if (phoneInput) {
            phoneInput.focus();
            phoneInput.select();
        }
    }

    if (btnOpen) {
        btnOpen.addEventListener('click', openModal);
    }
    if (btnOpenFab) {
        btnOpenFab.addEventListener('click', openModal);
    }

    if (btnSend) {
        btnSend.addEventListener('click', function () {
            var phone = normalizePhone(phoneInput ? phoneInput.value : '');
            var message = messageInput ? String(messageInput.value || '').trim() : '';

            if (!isValidPhone(phone)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Nomor tidak valid',
                    text: 'Gunakan format 62xxxxxxxxxx tanpa spasi/tanda baca.'
                });
                if (phoneInput) {
                    phoneInput.focus();
                }
                return;
            }

            if (message === '') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Pesan kosong',
                    text: 'Isi pesan WhatsApp terlebih dahulu.'
                });
                if (messageInput) {
                    messageInput.focus();
                }
                return;
            }

            var url = 'https://api.whatsapp.com/send?phone=' + encodeURIComponent(phone) + '&text=' + encodeURIComponent(message);
            window.open(url, '_blank');
        });
    }
});
</script>
SCRIPT;
if ($auto_print) {
    $page_scripts .= '<script>window.addEventListener("load", function(){ setTimeout(function(){ window.print(); }, 250); });</script>';
}
if ($embed_only): ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo html_escape($invoice_number); ?> - Invoice</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light p-2 p-md-3">
    <?php echo $content; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php echo $page_scripts; ?>
</body>
</html>
<?php else:
    include APPPATH . 'views/layout/master.php';
endif;
