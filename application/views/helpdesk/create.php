<?php
$page_title = 'Create Helpdesk Ticket - ' . app_name();
$page_heading = 'Create Helpdesk Ticket';
$page_subheading = 'Admin/Superadmin membuat tiket gangguan atau maintenance dengan SLA otomatis.';
$active_menu = 'helpdesk';

$customers = isset($customers) && is_array($customers) ? $customers : array();
$priority_options = isset($priority_options) && is_array($priority_options) ? $priority_options : array('LOW', 'MEDIUM', 'HIGH', 'URGENT');
$olt_options = isset($olt_options) && is_array($olt_options) ? $olt_options : array();
$teknisi_options = isset($teknisi_options) && is_array($teknisi_options) ? $teknisi_options : array();

ob_start();
?>
<?php if ($this->session->flashdata('error')): ?>
<div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">Form Ticket</div>
    <div class="card-body">
        <?php echo form_open('helpdesk/store', array('id' => 'helpdeskCreateForm', 'class' => 'row g-3')); ?>
            <div class="col-md-6">
                <label class="form-label">Customer</label>
                <select name="customer_id" id="customer_id" class="form-select js-searchable-select" required>
                    <option value="">- Pilih Customer -</option>
                    <?php foreach ($customers as $c): ?>
                    <?php
                        $cid = (int) ($c['id'] ?? 0);
                        $cname = (string) ($c['customer_name'] ?? $c['name'] ?? '-');
                        $carea = (string) ($c['area_name'] ?? $c['area'] ?? '');
                        $colt = (string) ($c['olt_name'] ?? '');
                    ?>
                    <option value="<?php echo $cid; ?>" data-area="<?php echo html_escape($carea); ?>" data-olt="<?php echo html_escape($colt); ?>">
                        <?php echo html_escape($cname . ($carea !== '' ? ' - ' . $carea : '')); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select" required>
                    <?php foreach ($priority_options as $priority): ?>
                    <?php $priority = strtoupper((string) $priority); ?>
                    <option value="<?php echo html_escape($priority); ?>" <?php echo $priority === 'MEDIUM' ? 'selected' : ''; ?>><?php echo html_escape($priority); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Assign Teknisi</label>
                <select name="assigned_to" class="form-select">
                    <option value="0">- Belum diassign -</option>
                    <?php foreach ($teknisi_options as $tech): ?>
                    <option value="<?php echo (int) ($tech['id'] ?? 0); ?>"><?php echo html_escape((string) ($tech['name'] ?? '-')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">OLT</label>
                <select name="olt_id" id="olt_id" class="form-select">
                    <option value="0">- Auto / Tidak spesifik -</option>
                    <?php foreach ($olt_options as $olt): ?>
                    <option value="<?php echo (int) ($olt['id'] ?? 0); ?>"><?php echo html_escape((string) ($olt['name'] ?? '-')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Jenis Gangguan</label>
                <select name="issue_type" id="issue_type" class="form-select" required>
                    <option value="fo_cut">FO Cut</option>
                    <option value="router_replace">Ganti Router</option>
                    <option value="adapter_replace">Ganti Adaptor</option>
                </select>
                <small id="issueHint" class="text-muted d-block mt-1">Pilih jenis gangguan utama.</small>
            </div>
            <div class="col-md-4">
                <label class="form-label">Subject</label>
                <input type="text" name="subject" id="subject" class="form-control" maxlength="200" placeholder="Contoh: FO Cut">
            </div>

            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea name="description" rows="4" class="form-control" required placeholder="Jelaskan detail gangguan / maintenance"></textarea>
            </div>

            <div class="col-12">
                <div id="pppAlertWrap"></div>
                <div class="card border mt-2" id="pppInfoCard" style="display:none;">
                    <div class="card-header bg-light fw-semibold">PPP Detail (MikroTik)</div>
                    <div class="card-body row g-2">
                        <div class="col-md-3"><div class="small text-muted">PPP Username</div><div id="pppUsername" class="fw-semibold">-</div></div>
                        <div class="col-md-2"><div class="small text-muted">Status</div><div id="pppStatus" class="fw-semibold">-</div></div>
                        <div class="col-md-3"><div class="small text-muted">IP Address</div><div id="pppIp" class="fw-semibold">-</div></div>
                        <div class="col-md-2"><div class="small text-muted">Profile</div><div id="pppProfile" class="fw-semibold">-</div></div>
                        <div class="col-md-2"><div class="small text-muted">Service</div><div id="pppService" class="fw-semibold">-</div></div>
                    </div>
                </div>
            </div>

            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Submit Ticket</button>
                <a href="<?php echo site_url('helpdesk'); ?>" class="btn btn-outline-secondary">Batal</a>
            </div>
        <?php echo form_close(); ?>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var customerSelect = document.getElementById('customer_id');
    var oltSelect = document.getElementById('olt_id');
    var issueSelect = document.getElementById('issue_type');
    var subjectInput = document.getElementById('subject');
    var issueHint = document.getElementById('issueHint');
    var pppCard = document.getElementById('pppInfoCard');
    var pppAlertWrap = document.getElementById('pppAlertWrap');
    var issueMap = {
        fo_cut: 'FO Cut',
        router_replace: 'Ganti Router',
        adapter_replace: 'Ganti Adaptor'
    };

    function renderPpp(data) {
        document.getElementById('pppUsername').textContent = data.ppp_username || '-';
        document.getElementById('pppStatus').textContent = data.ppp_status || '-';
        document.getElementById('pppIp').textContent = data.ip_address || '-';
        document.getElementById('pppProfile').textContent = data.profile || '-';
        document.getElementById('pppService').textContent = data.service || '-';
        pppCard.style.display = 'block';

        if (data.alert_html) {
            pppAlertWrap.innerHTML = data.alert_html;
        } else {
            pppAlertWrap.innerHTML = '';
        }
    }

    function clearPpp() {
        pppCard.style.display = 'none';
        pppAlertWrap.innerHTML = '';
    }

    function applyIssueTemplate() {
        var key = issueSelect.value || 'fo_cut';
        var label = issueMap[key] || 'Gangguan';
        if (subjectInput.value.trim() === '') {
            subjectInput.value = label;
        }
        if (key === 'router_replace') {
            issueHint.textContent = 'Untuk Ganti Router, notif Telegram akan menyertakan Username & Password PPP customer.';
        } else {
            issueHint.textContent = 'Pilih jenis gangguan utama.';
        }
    }

    if (typeof window.initSearchableSelect === 'function') {
        window.initSearchableSelect('#customer_id', {
            searchPlaceholderValue: 'Cari customer / area...'
        });
    }

    customerSelect.addEventListener('change', function () {
        var id = this.value;
        if (!id) {
            clearPpp();
            return;
        }

        fetch('<?php echo site_url('helpdesk/customer-ppp'); ?>/' + encodeURIComponent(id), {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (!json || !json.success) {
                clearPpp();
                pppAlertWrap.innerHTML = '<div class="alert alert-warning mb-0">Gagal mengambil PPP detail.</div>';
                return;
            }
            renderPpp(json);
        })
        .catch(function () {
            clearPpp();
            pppAlertWrap.innerHTML = '<div class="alert alert-warning mb-0">Koneksi ke server gagal saat load PPP detail.</div>';
        });
    });

    issueSelect.addEventListener('change', function () {
        applyIssueTemplate();
    });

    applyIssueTemplate();
});
</script>
<?php
$page_scripts = ob_get_clean();

include APPPATH . 'views/layout/master.php';
