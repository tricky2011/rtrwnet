<?php
$page_title = 'Detail Ticket - ' . app_name();
$page_heading = 'Detail Ticket';
$page_subheading = 'Update status, reply, dan dokumentasi tiket.';
$active_menu = 'helpdesk';

$ticket = isset($ticket) && is_array($ticket) ? $ticket : array();
$replies = isset($replies) && is_array($replies) ? $replies : array();
$attachments = isset($attachments) && is_array($attachments) ? $attachments : array();
$status_options = isset($status_options) && is_array($status_options) ? $status_options : array('OPEN', 'ASSIGNED', 'PROGRESS', 'RESOLVED', 'CLOSED');
$teknisi_options = isset($teknisi_options) && is_array($teknisi_options) ? $teknisi_options : array();
$role = isset($role) ? (string) $role : (string) $this->session->userdata('role');
$is_superadmin = !empty($is_superadmin);

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
        if ($status === 'PROGRESS' || $status === 'IN_PROGRESS') {
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

$current_status = strtoupper((string) ($ticket['status'] ?? 'OPEN'));
$current_status_label = helpdesk_status_label($current_status, $role);
$allowed_update_status = array();
if (in_array($role, array('superadmin', 'admin'), true)) {
    $allowed_update_status = array('ASSIGNED', 'PROGRESS', 'RESOLVED', 'CLOSED');
} elseif ($role === 'teknisi') {
    $allowed_update_status = array('PROGRESS', 'DONE');
}

ob_start();
?>
<?php if ($this->session->flashdata('success')): ?>
<div class="alert alert-success"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
<div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card stat-card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div class="fw-semibold">Ticket <?php echo html_escape((string) ($ticket['ticket_code'] ?? '#')); ?></div>
                <span id="ticketStatusBadge" class="badge <?php echo helpdesk_status_badge_class($current_status_label); ?>"><?php echo html_escape($current_status_label); ?></span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="small text-muted">Customer</div>
                        <div class="fw-semibold"><?php echo html_escape((string) ($ticket['customer_name'] ?? '-')); ?></div>
                        <div class="small text-muted"><?php echo html_escape((string) ($ticket['customer_area'] ?? '-')); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">PPP Username</div>
                        <div class="fw-semibold"><?php echo html_escape((string) ($ticket['ppp_username'] ?? '-')); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">OLT</div>
                        <div class="fw-semibold"><?php echo html_escape((string) ($ticket['olt_name'] ?? '-')); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">Assigned Teknisi</div>
                        <div class="fw-semibold"><?php echo html_escape((string) ($ticket['assigned_name'] ?? '-')); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">Priority</div>
                        <div class="fw-semibold"><?php echo html_escape((string) ($ticket['priority'] ?? '-')); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">SLA Deadline</div>
                        <div class="fw-semibold"><?php echo !empty($ticket['sla_deadline']) ? html_escape(date('d-m-Y H:i', strtotime((string) $ticket['sla_deadline']))) : '-'; ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">Created At</div>
                        <div class="fw-semibold"><?php echo !empty($ticket['created_at']) ? html_escape(date('d-m-Y H:i', strtotime((string) $ticket['created_at']))) : '-'; ?></div>
                    </div>
                    <div class="col-12">
                        <div class="small text-muted">Subject</div>
                        <div class="fw-semibold"><?php echo html_escape((string) ($ticket['subject'] ?? '-')); ?></div>
                    </div>
                    <div class="col-12">
                        <div class="small text-muted">Description</div>
                        <div><?php echo nl2br(html_escape((string) ($ticket['description'] ?? '-'))); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card stat-card mb-3">
            <div class="card-header bg-white fw-semibold">Update Status (AJAX)</div>
            <div class="card-body">
                <?php if (empty($allowed_update_status)): ?>
                <div class="text-muted">Role Anda tidak diizinkan update status.</div>
                <?php else: ?>
                <div class="mb-2">
                    <label class="form-label form-label-sm">Status Baru</label>
                    <select id="statusUpdateSelect" class="form-select form-select-sm">
                        <?php foreach ($allowed_update_status as $s): ?>
                        <option value="<?php echo html_escape($s); ?>" <?php echo $current_status_label === $s ? 'selected' : ''; ?>><?php echo html_escape($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (in_array($role, array('superadmin', 'admin'), true)): ?>
                <div class="mb-2" id="assignTechWrap" style="display:none;">
                    <label class="form-label form-label-sm">Assign Teknisi</label>
                    <select id="assignToSelect" class="form-select form-select-sm">
                        <option value="0">- Pilih Teknisi -</option>
                        <?php foreach ($teknisi_options as $tech): ?>
                        <option value="<?php echo (int) ($tech['id'] ?? 0); ?>"><?php echo html_escape((string) ($tech['name'] ?? '-')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="mb-2">
                    <label class="form-label form-label-sm">Catatan</label>
                    <textarea id="statusUpdateNote" class="form-control form-control-sm" rows="3" placeholder="Catatan update status"></textarea>
                </div>
                <button type="button" class="btn btn-sm btn-primary w-100" id="btnUpdateStatus">Update Status</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_superadmin): ?>
        <div class="card stat-card mb-3">
            <div class="card-body d-grid">
                <?php echo form_open('helpdesk/delete/' . (int) ($ticket['id'] ?? 0), array('onsubmit' => "return confirm('Hapus tiket ini?');")); ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger w-100">Delete Ticket</button>
                <?php echo form_close(); ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card stat-card">
            <div class="card-body d-grid gap-2">
                <a href="<?php echo site_url('helpdesk'); ?>" class="btn btn-sm btn-outline-secondary">Kembali ke List</a>
                <a href="<?php echo site_url('helpdesk/dashboard'); ?>" class="btn btn-sm btn-outline-secondary">Dashboard</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Timeline Reply</div>
            <div class="card-body">
                <?php if (empty($replies)): ?>
                <div class="text-muted">Belum ada reply.</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($replies as $reply): ?>
                    <?php
                        $reply_text = (string) ($reply['reply_text'] ?? '-');
                        if ($role === 'teknisi' && strpos($reply_text, '[STATUS]') === 0) {
                            $reply_text = str_replace('RESOLVED', 'DONE', $reply_text);
                        }
                    ?>
                    <div class="list-group-item px-0">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <div class="fw-semibold"><?php echo html_escape((string) ($reply['created_by_name'] ?? '-')); ?></div>
                            <div class="small text-muted"><?php echo !empty($reply['created_at']) ? html_escape(date('d-m-Y H:i', strtotime((string) $reply['created_at']))) : '-'; ?></div>
                        </div>
                        <?php if ((int) ($reply['is_internal'] ?? 0) === 1): ?><span class="badge text-bg-dark mb-1">Internal Note</span><?php endif; ?>
                        <div><?php echo nl2br(html_escape($reply_text)); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-white">
                <?php echo form_open('helpdesk/add-reply/' . (int) ($ticket['id'] ?? 0), array('class' => 'row g-2')); ?>
                    <div class="col-12">
                        <textarea name="reply_text" class="form-control" rows="3" placeholder="Tambahkan update / hasil pekerjaan" required></textarea>
                    </div>
                    <?php if (in_array($role, array('superadmin', 'admin'), true)): ?>
                    <div class="col-md-6">
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" value="1" id="isInternal" name="is_internal">
                            <label class="form-check-label" for="isInternal">Internal note</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-6 text-md-end">
                        <button type="submit" class="btn btn-sm btn-primary">Kirim Reply</button>
                    </div>
                <?php echo form_close(); ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card stat-card h-100">
            <div class="card-header bg-white fw-semibold">Dokumentasi</div>
            <div class="card-body">
                <?php if (empty($attachments)): ?>
                <div class="text-muted mb-3">Belum ada lampiran.</div>
                <?php else: ?>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($attachments as $file): ?>
                    <?php $url = base_url((string) ($file['file_path'] ?? '')); ?>
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold"><?php echo html_escape((string) ($file['file_name'] ?? '-')); ?></div>
                            <div class="small text-muted"><?php echo html_escape((string) ($file['uploaded_by_name'] ?? '-')); ?> | <?php echo !empty($file['created_at']) ? html_escape(date('d-m-Y H:i', strtotime((string) $file['created_at']))) : '-'; ?></div>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo $url; ?>" target="_blank">Open</a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php echo form_open_multipart('helpdesk/upload-attachment/' . (int) ($ticket['id'] ?? 0), array('class' => 'row g-2')); ?>
                    <div class="col-12">
                        <label class="form-label form-label-sm">Upload Dokumentasi</label>
                        <input type="file" name="attachment_file" class="form-control form-control-sm" required>
                        <div class="small text-muted mt-1">Tipe file: jpg, png, pdf, doc, docx, txt (max 8MB)</div>
                    </div>
                    <div class="col-12 d-grid">
                        <button type="submit" class="btn btn-sm btn-success">Upload</button>
                    </div>
                <?php echo form_close(); ?>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 2000;">
    <div id="statusToast" class="toast align-items-center text-bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="statusToastBody">Update status selesai.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var statusSelect = document.getElementById('statusUpdateSelect');
    var assignWrap = document.getElementById('assignTechWrap');
    var assignSelect = document.getElementById('assignToSelect');
    var btnUpdate = document.getElementById('btnUpdateStatus');
    var noteEl = document.getElementById('statusUpdateNote');
    var toastEl = document.getElementById('statusToast');
    var toastBody = document.getElementById('statusToastBody');
    var csrfName = '<?php echo $this->security->get_csrf_token_name(); ?>';
    var csrfHash = '<?php echo $this->security->get_csrf_hash(); ?>';
    var technicianMode = <?php echo $role === 'teknisi' ? 'true' : 'false'; ?>;

    function getCookieValue(name) {
        var escaped = name.replace(/([.*+?^${}()|[\]\\])/g, '\\$1');
        var match = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function getCsrfHash() {
        var cookieHash = csrfName ? getCookieValue(csrfName) : '';
        return cookieHash || csrfHash || '';
    }

    function statusBadgeClass(status) {
        status = (status || '').toUpperCase();
        if (status === 'OPEN') return 'text-bg-secondary';
        if (status === 'ASSIGNED') return 'text-bg-info';
        if (status === 'PROGRESS' || status === 'IN_PROGRESS') return 'text-bg-warning';
        if (status === 'RESOLVED' || status === 'DONE') return 'text-bg-success';
        if (status === 'CLOSED') return 'text-bg-dark';
        return 'text-bg-light';
    }

    function displayStatus(status) {
        status = (status || '').toUpperCase();
        if (technicianMode && status === 'RESOLVED') {
            return 'DONE';
        }
        return status;
    }

    function showToast(message, success) {
        if (!toastEl || !toastBody) {
            alert(message);
            return;
        }
        toastEl.className = 'toast align-items-center border-0 ' + (success ? 'text-bg-success' : 'text-bg-danger');
        toastBody.textContent = message;
        var toast = bootstrap.Toast.getOrCreateInstance(toastEl);
        toast.show();
    }

    function toggleAssign() {
        if (!assignWrap || !statusSelect) {
            return;
        }
        assignWrap.style.display = statusSelect.value === 'ASSIGNED' ? '' : 'none';
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', toggleAssign);
        toggleAssign();
    }

    if (btnUpdate) {
        btnUpdate.addEventListener('click', function () {
            var statusValue = statusSelect ? statusSelect.value : '';
            if (!statusValue) {
                showToast('Pilih status terlebih dahulu.', false);
                return;
            }

            var formData = new FormData();
            formData.append('ticket_id', '<?php echo (int) ($ticket['id'] ?? 0); ?>');
            formData.append('status', statusValue);
            formData.append('note', noteEl ? noteEl.value : '');
            if (assignSelect) {
                formData.append('assigned_to', assignSelect.value || '0');
            }
            var activeCsrfHash = getCsrfHash();
            if (csrfName && activeCsrfHash) {
                formData.append(csrfName, activeCsrfHash);
            }

            fetch('<?php echo site_url('helpdesk/update-status'); ?>', {
                method: 'POST',
                body: formData,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(function (res) {
                return res.text().then(function (text) {
                    var json = null;
                    try {
                        json = text ? JSON.parse(text) : {};
                    } catch (e) {
                        throw new Error('Response server tidak valid. Refresh halaman lalu coba lagi.');
                    }
                    return {res: res, json: json};
                });
            })
            .then(function (pack) {
                var res = pack.res;
                var json = pack.json || {};

                if (json.csrf_hash) {
                    csrfHash = json.csrf_hash;
                }

                if (!res.ok || !json.success) {
                    var failMessage = (json && json.message) ? json.message : 'Update status gagal.';
                    if (res.status === 403) {
                        failMessage = 'Session/CSRF kadaluarsa. Refresh halaman lalu login ulang jika diminta.';
                    }
                    showToast(failMessage, false);
                    return;
                }

                var badge = document.getElementById('ticketStatusBadge');
                var badgeStatus = displayStatus(json.status || statusValue);
                if (badge) {
                    badge.className = 'badge ' + statusBadgeClass(badgeStatus);
                    badge.textContent = badgeStatus;
                }
                showToast(json.message || 'Status berhasil diperbarui.', true);
            })
            .catch(function (err) {
                showToast((err && err.message) ? err.message : 'Koneksi server gagal.', false);
            });
        });
    }
});
</script>
<?php
$page_scripts = ob_get_clean();

include APPPATH . 'views/layout/master.php';
