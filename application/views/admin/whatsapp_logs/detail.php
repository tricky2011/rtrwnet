<?php
$page_title = 'Detail WhatsApp Log - ' . app_name();
$page_heading = 'Detail WhatsApp Log';
$page_subheading = 'Payload pesan, status, response gateway, dan error.';
$active_menu = 'whatsapp';
$log = isset($log) && is_array($log) ? $log : array();

if (!function_exists('wa_detail_status_badge')) {
    function wa_detail_status_badge($status)
    {
        $status = strtolower((string) $status);
        if ($status === 'sent') {
            return array('SENT', 'success');
        }
        if ($status === 'failed') {
            return array('FAILED', 'danger');
        }
        if ($status === 'processing') {
            return array('PROCESSING', 'info');
        }
        return array('PENDING', 'warning');
    }
}

list($status_label, $status_badge) = wa_detail_status_badge($log['status'] ?? 'pending');
ob_start();
?>
<div class="mb-3 d-flex gap-2">
    <a href="<?php echo site_url('admin-whatsapp'); ?>" class="btn btn-sm btn-outline-secondary">Kembali</a>
    <?php if (in_array(strtolower((string) ($log['status'] ?? '')), array('failed', 'sent'), true)): ?>
        <?php echo form_open('admin-whatsapp/resend/' . (int) ($log['id'] ?? 0), array('class' => 'd-inline')); ?>
            <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Kirim ulang pesan ini lewat queue?');">Kirim Ulang</button>
        <?php echo form_close(); ?>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Informasi Log</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">ID</dt><dd class="col-sm-8"><?php echo (int) ($log['id'] ?? 0); ?></dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><span class="badge text-bg-<?php echo html_escape($status_badge); ?>"><?php echo html_escape($status_label); ?></span></dd>
                    <dt class="col-sm-4">Customer ID</dt><dd class="col-sm-8"><?php echo (int) ($log['customer_id'] ?? 0); ?></dd>
                    <dt class="col-sm-4">Invoice ID</dt><dd class="col-sm-8"><?php echo (int) ($log['invoice_id'] ?? 0); ?></dd>
                    <dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?php echo html_escape((string) ($log['phone'] ?? '-')); ?></dd>
                    <dt class="col-sm-4">Normalized</dt><dd class="col-sm-8"><?php echo html_escape((string) ($log['normalized_phone'] ?? '-')); ?></dd>
                    <dt class="col-sm-4">Retry</dt><dd class="col-sm-8"><?php echo (int) ($log['retry_count'] ?? 0); ?></dd>
                    <dt class="col-sm-4">Created</dt><dd class="col-sm-8"><?php echo html_escape((string) ($log['created_at'] ?? '-')); ?></dd>
                    <dt class="col-sm-4">Updated</dt><dd class="col-sm-8"><?php echo html_escape((string) ($log['updated_at'] ?? '-')); ?></dd>
                    <dt class="col-sm-4">Sent At</dt><dd class="col-sm-8"><?php echo html_escape((string) ($log['sent_at'] ?? '-')); ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Pesan</div>
            <div class="card-body">
                <pre class="mb-0 bg-light border rounded p-3" style="white-space:pre-wrap;"><?php echo html_escape((string) ($log['message'] ?? '')); ?></pre>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Error Message</div>
            <div class="card-body">
                <pre class="mb-0 bg-light border rounded p-3" style="white-space:pre-wrap;"><?php echo html_escape((string) ($log['error_message'] ?? '')); ?></pre>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Provider Response</div>
            <div class="card-body">
                <pre class="mb-0 bg-light border rounded p-3" style="white-space:pre-wrap;"><?php echo html_escape((string) ($log['provider_response'] ?? '')); ?></pre>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include APPPATH . 'views/layouts/master.php';
?>
