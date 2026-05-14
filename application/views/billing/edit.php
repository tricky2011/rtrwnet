<?php
$page_title = 'Edit Invoice - ' . app_name();
$page_heading = 'Edit Invoice';
$page_subheading = 'Ubah data invoice tanpa mengganggu struktur billing inti.';
$active_menu = 'billing';
$invoice = isset($invoice) && is_array($invoice) ? $invoice : array();

$id = (int) ($invoice['id'] ?? 0);
$invoice_number = (string) ($invoice['invoice_number'] ?? '-');
$customer_name = (string) ($invoice['customer_name'] ?? '-');
$status = (string) ($invoice['status'] ?? 'issued');
$status_form = strtolower($status);
if (!in_array($status_form, array('paid', 'overdue', 'void'), true)) {
    $status_form = 'issued';
}
$status_options = array(
    'paid' => 'Paid / Lunas',
    'issued' => 'Belum Lunas',
    'overdue' => 'Overdue',
    'void' => 'Cancel / Void',
);

$flash_error = (string) $this->session->flashdata('error');
$flash_success = (string) $this->session->flashdata('success');
$return_url = trim((string) $this->input->get('return_url', true));
if ($return_url === '' || preg_match('/^(https?:)?\/\//i', $return_url)) {
    $return_url = 'billing';
}
$back_url = site_url($return_url);

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">Edit <?php echo html_escape($invoice_number); ?></div>
    <div class="card-body">
        <?php if ($flash_error !== ''): ?>
            <div class="alert alert-danger"><?php echo html_escape($flash_error); ?></div>
        <?php endif; ?>
        <?php if ($flash_success !== ''): ?>
            <div class="alert alert-success"><?php echo html_escape($flash_success); ?></div>
        <?php endif; ?>

        <div class="mb-3 small text-muted">
            Pelanggan: <strong><?php echo html_escape($customer_name); ?></strong>
        </div>

        <div class="alert alert-light border small">
            Pilihan status edit:
            <strong>Paid</strong>, <strong>Belum Lunas</strong>, atau <strong>Overdue</strong>.
            Jika invoice yang sebelumnya lunas diubah ke belum lunas/overdue, histori payment confirmed invoice ini akan di-reset agar status dan laporan tetap sinkron.
        </div>

        <?php echo form_open('billing/update/' . $id, array('id' => 'invoiceEditForm', 'class' => 'row g-3')); ?>
            <input type="hidden" name="return_url" value="<?php echo html_escape($return_url); ?>">
            <div class="col-md-3">
                <label class="form-label">Issue Date</label>
                <input type="date" name="issue_date" class="form-control" value="<?php echo html_escape((string) ($invoice['issue_date'] ?? '')); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Due Date</label>
                <input type="date" name="due_date" class="form-control" value="<?php echo html_escape((string) ($invoice['due_date'] ?? '')); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" required>
                    <?php foreach ($status_options as $st => $label): ?>
                        <option value="<?php echo html_escape($st); ?>" <?php echo $status_form === $st ? 'selected' : ''; ?>>
                            <?php echo html_escape($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Jika pembayaran invoice belum penuh lalu ingin dilunasi, gunakan tombol <strong>Lunas</strong> di list/detail.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label">Subtotal</label>
                <input type="number" step="0.01" min="0" name="subtotal" id="subtotal" class="form-control" value="<?php echo html_escape((string) ($invoice['subtotal'] ?? '0')); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Tax Amount</label>
                <input type="number" step="0.01" min="0" name="tax_amount" id="tax_amount" class="form-control" value="<?php echo html_escape((string) ($invoice['tax_amount'] ?? '0')); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Discount Amount</label>
                <input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount" class="form-control" value="<?php echo html_escape((string) ($invoice['discount_amount'] ?? '0')); ?>" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Total (auto)</label>
                <input type="text" id="total_preview" class="form-control" value="Rp <?php echo number_format((float) ($invoice['total_amount'] ?? 0), 0, ',', '.'); ?>" readonly>
            </div>
            <div class="col-md-4">
                <label class="form-label">Paid Amount (readonly)</label>
                <input type="text" class="form-control" value="Rp <?php echo number_format((float) ($invoice['paid_amount'] ?? 0), 0, ',', '.'); ?>" readonly>
            </div>
            <div class="col-md-4">
                <label class="form-label">Balance Amount (readonly)</label>
                <input type="text" class="form-control" value="Rp <?php echo number_format((float) ($invoice['balance_amount'] ?? 0), 0, ',', '.'); ?>" readonly>
            </div>

            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" rows="4" class="form-control"><?php echo html_escape((string) ($invoice['notes'] ?? '')); ?></textarea>
            </div>

            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                <a href="<?php echo html_escape($back_url); ?>" class="btn btn-outline-secondary">Kembali</a>
            </div>
        <?php echo form_close(); ?>
    </div>
</div>
<?php
$content = ob_get_clean();

$page_scripts = <<<'SCRIPT'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const subtotal = document.getElementById('subtotal');
    const tax = document.getElementById('tax_amount');
    const discount = document.getElementById('discount_amount');
    const totalPreview = document.getElementById('total_preview');

    function formatRupiah(num) {
        const n = Math.max(0, Number(num) || 0);
        return 'Rp ' + n.toLocaleString('id-ID');
    }

    function recalc() {
        const st = Number(subtotal.value || 0);
        const tx = Number(tax.value || 0);
        const dc = Number(discount.value || 0);
        const total = Math.max(0, st + tx - dc);
        totalPreview.value = formatRupiah(total);
    }

    [subtotal, tax, discount].forEach(function (el) {
        el.addEventListener('input', recalc);
    });
});
</script>
SCRIPT;

include APPPATH . 'views/layout/master.php';
