<?php
$page_title = 'WhatsApp Logs - ' . app_name();
$page_heading = 'WhatsApp Gateway Logs';
$page_subheading = 'Monitoring antrian, status pengiriman, response gateway, dan kirim ulang pesan billing.';
$active_menu = 'whatsapp';
$rows = isset($rows) && is_array($rows) ? $rows : array();
$filters = isset($filters) && is_array($filters) ? $filters : array();
$stats = isset($stats) && is_array($stats) ? $stats : array();
$pagination = isset($pagination) ? (string) $pagination : '';
$total_rows = isset($total_rows) ? (int) $total_rows : count($rows);
$per_page = isset($per_page) ? (int) $per_page : 20;
$per_page_options = isset($per_page_options) && is_array($per_page_options) ? $per_page_options : array(20, 50, 100, 500);
$table_ready = isset($table_ready) ? (bool) $table_ready : true;

if (!function_exists('wa_log_status_badge')) {
    function wa_log_status_badge($status)
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

ob_start();
?>
<?php if ($this->session->flashdata('success')): ?>
<div class="alert alert-success"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
<div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>

<?php if (!$table_ready): ?>
<div class="alert alert-danger">
    Tabel <code>wa_message_logs</code> belum tersedia. Jalankan SQL migrasi WhatsApp Gateway terlebih dahulu.
</div>
<?php endif; ?>

<div class="row g-2 mb-3">
    <?php foreach (array('pending' => 'warning', 'processing' => 'info', 'sent' => 'success', 'failed' => 'danger') as $status_key => $accent): ?>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="small text-muted"><?php echo strtoupper($status_key); ?></div>
                    <div class="h4 mb-0 text-<?php echo html_escape($accent); ?>"><?php echo number_format((int) ($stats[$status_key] ?? 0), 0, ',', '.'); ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card stat-card">
    <div class="card-header bg-white">
        <?php echo form_open('admin-whatsapp', array('method' => 'get', 'class' => 'row g-2 align-items-end', 'id' => 'waLogFilterForm')); ?>
            <div class="col-md-3">
                <label class="form-label form-label-sm mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Nama, invoice, nomor, error" value="<?php echo html_escape((string) ($filters['search'] ?? '')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <?php foreach (array('pending', 'processing', 'sent', 'failed') as $status): ?>
                    <option value="<?php echo html_escape($status); ?>" <?php echo (string) ($filters['status'] ?? '') === $status ? 'selected' : ''; ?>>
                        <?php echo strtoupper($status); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Tanggal Dari</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo html_escape((string) ($filters['date_from'] ?? '')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm mb-1">Tanggal Sampai</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo html_escape((string) ($filters['date_to'] ?? '')); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label form-label-sm mb-1">Customer ID</label>
                <input type="number" min="0" name="customer_id" class="form-control form-control-sm" value="<?php echo (int) ($filters['customer_id'] ?? 0); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label form-label-sm mb-1">Invoice ID</label>
                <input type="number" min="0" name="invoice_id" class="form-control form-control-sm" value="<?php echo (int) ($filters['invoice_id'] ?? 0); ?>">
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            </div>
            <div class="col-12 d-flex justify-content-between align-items-center">
                <small class="text-muted">Total: <?php echo number_format($total_rows, 0, ',', '.'); ?> log</small>
                <a href="<?php echo site_url('admin-whatsapp'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        <?php echo form_close(); ?>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Waktu</th>
                        <th>Pelanggan</th>
                        <th>Invoice</th>
                        <th>Nomor</th>
                        <th>Pesan</th>
                        <th>Retry</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="ps-3 text-muted">Belum ada log WhatsApp.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            list($label, $badge) = wa_log_status_badge($row['status'] ?? 'pending');
                            $message = trim((string) ($row['message'] ?? ''));
                            $preview = strlen($message) > 90 ? substr($message, 0, 90) . '...' : $message;
                            $detail_url = site_url('admin-whatsapp/detail/' . (int) ($row['id'] ?? 0));
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <div><?php echo html_escape((string) ($row['created_at'] ?? '-')); ?></div>
                                    <?php if (!empty($row['sent_at'])): ?>
                                    <div class="small text-muted">Sent: <?php echo html_escape((string) $row['sent_at']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-medium"><?php echo html_escape((string) ($row['customer_name'] ?? ('Customer #' . (int) ($row['customer_id'] ?? 0)))); ?></div>
                                    <div class="small text-muted">ID: <?php echo (int) ($row['customer_id'] ?? 0); ?></div>
                                </td>
                                <td>
                                    <div><?php echo html_escape((string) ($row['invoice_number'] ?? ('#' . (int) ($row['invoice_id'] ?? 0)))); ?></div>
                                    <div class="small text-muted">ID: <?php echo (int) ($row['invoice_id'] ?? 0); ?></div>
                                </td>
                                <td>
                                    <div><?php echo html_escape((string) ($row['normalized_phone'] ?? '-')); ?></div>
                                    <div class="small text-muted"><?php echo html_escape((string) ($row['phone'] ?? '')); ?></div>
                                </td>
                                <td style="max-width:340px;">
                                    <div class="small text-muted"><?php echo nl2br(html_escape($preview)); ?></div>
                                    <?php if (!empty($row['error_message'])): ?>
                                    <div class="small text-danger mt-1"><?php echo html_escape((string) $row['error_message']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) ($row['retry_count'] ?? 0); ?></td>
                                <td><span class="badge text-bg-<?php echo html_escape($badge); ?>"><?php echo html_escape($label); ?></span></td>
                                <td class="text-end pe-3">
                                    <a href="<?php echo html_escape($detail_url); ?>" class="btn btn-sm btn-outline-primary">Detail</a>
                                    <?php if (in_array(strtolower((string) ($row['status'] ?? '')), array('failed', 'sent'), true)): ?>
                                        <?php echo form_open('admin-whatsapp/resend/' . (int) ($row['id'] ?? 0), array('class' => 'd-inline')); ?>
                                            <input type="hidden" name="return_to" value="<?php echo html_escape(current_url() . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Kirim ulang pesan ini lewat antrian?');">
                                                <?php echo strtolower((string) ($row['status'] ?? '')) === 'failed' ? 'Resend' : 'Kirim Ulang'; ?>
                                            </button>
                                        <?php echo form_close(); ?>
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
                <div class="d-flex flex-wrap gap-1" role="group">
                    <?php foreach ($per_page_options as $opt): ?>
                        <?php $opt = (int) $opt; ?>
                        <input class="btn-check" type="radio" name="per_page" id="wa_per_page_<?php echo $opt; ?>" form="waLogFilterForm" value="<?php echo $opt; ?>" onchange="document.getElementById('waLogFilterForm').submit();" <?php echo $per_page === $opt ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-primary btn-sm px-2 py-1" for="wa_per_page_<?php echo $opt; ?>"><?php echo $opt; ?></label>
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
include APPPATH . 'views/layouts/master.php';
?>
