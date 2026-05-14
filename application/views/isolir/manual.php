<?php
$page_title = 'Manual Isolir/Release - ' . app_name();
$page_heading = 'Manual Isolir/Release User';
$page_subheading = 'Fitur isolir/release manual untuk user PPPoE dan queue STATIC.';
$active_menu = 'manual_isolir';

$user_options = isset($user_options) && is_array($user_options) ? $user_options : array();
$router_scope_required = !empty($router_scope_required);
$csrf_name = isset($csrf_name) ? (string) $csrf_name : $this->security->get_csrf_token_name();
$csrf_hash = isset($csrf_hash) ? (string) $csrf_hash : $this->security->get_csrf_hash();

ob_start();
?>
<div class="card border-0 shadow-sm manual-isolir-card">
    <div class="card-header manual-isolir-head border-0">
        <h5 class="mb-0 text-white fw-semibold">
            <i class="bi bi-person-gear me-2"></i>Manual Isolir/Release User
        </h5>
    </div>
    <div class="card-body p-3 p-md-4 bg-light">
        <?php if ($router_scope_required): ?>
            <div class="alert alert-warning mb-3">
                Pilih router aktif terlebih dahulu sebelum menjalankan Manual Isolir/Release.
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card border-danger-subtle h-100">
                    <div class="card-header bg-white fw-semibold">Manual Isolir User</div>
                    <div class="card-body">
                        <form id="formManualIsolir" method="post" action="<?php echo site_url('manual-isolir/isolate'); ?>">
                            <input type="hidden" name="<?php echo html_escape($csrf_name); ?>" id="csrfTokenIsolir" value="<?php echo html_escape($csrf_hash); ?>">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Target (Username/Queue):</label>
                                <select class="form-select js-searchable-select" name="pppoe_username" id="isolirTarget" required <?php echo $router_scope_required ? 'disabled' : ''; ?>>
                                    <option value="">- Pilih Target PPP/STATIC -</option>
                                    <?php foreach ($user_options as $username): ?>
                                        <?php $target = trim((string) $username); ?>
                                        <?php if ($target === '') { continue; } ?>
                                        <option value="<?php echo html_escape($target); ?>"><?php echo html_escape($target); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Cari lalu pilih target. Mendukung username PPP dan nama queue STATIC.</div>
                            </div>
                            <button type="submit" class="btn btn-danger w-100" <?php echo $router_scope_required ? 'disabled' : ''; ?>>
                                <i class="bi bi-person-slash me-1"></i>Isolir User
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-success-subtle h-100">
                    <div class="card-header bg-white fw-semibold">Manual Release User</div>
                    <div class="card-body">
                        <form id="formManualRelease" method="post" action="<?php echo site_url('manual-isolir/release'); ?>">
                            <input type="hidden" name="<?php echo html_escape($csrf_name); ?>" id="csrfTokenRelease" value="<?php echo html_escape($csrf_hash); ?>">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Target (Username/Queue):</label>
                                <select class="form-select js-searchable-select" name="pppoe_username" id="releaseTarget" required <?php echo $router_scope_required ? 'disabled' : ''; ?>>
                                    <option value="">- Pilih Target PPP/STATIC -</option>
                                    <?php foreach ($user_options as $username): ?>
                                        <?php $target = trim((string) $username); ?>
                                        <?php if ($target === '') { continue; } ?>
                                        <option value="<?php echo html_escape($target); ?>"><?php echo html_escape($target); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Cari lalu pilih target untuk release dari ISOLIR.</div>
                            </div>
                            <button type="submit" class="btn btn-success w-100" <?php echo $router_scope_required ? 'disabled' : ''; ?>>
                                <i class="bi bi-person-check me-1"></i>Release User
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-info-subtle mt-3">
            <div class="card-header bg-white fw-semibold">Operation Result</div>
            <div class="card-body">
                <div id="operationResult" class="text-muted">No operations performed yet.</div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

$page_scripts = <<<'SCRIPT'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var formIsolir = document.getElementById('formManualIsolir');
    var formRelease = document.getElementById('formManualRelease');
    var inputIsolir = document.getElementById('isolirTarget');
    var inputRelease = document.getElementById('releaseTarget');
    var csrfTokenIsolir = document.getElementById('csrfTokenIsolir');
    var csrfTokenRelease = document.getElementById('csrfTokenRelease');
    var resultBox = document.getElementById('operationResult');

    if (typeof window.initSearchableSelect === 'function') {
        window.initSearchableSelect('#isolirTarget', {
            searchPlaceholderValue: 'Cari target PPP/STATIC...'
        });
        window.initSearchableSelect('#releaseTarget', {
            searchPlaceholderValue: 'Cari target PPP/STATIC...'
        });
    }

    function updateCsrf(name, hash) {
        if (!name || !hash) return;
        if (csrfTokenIsolir) {
            csrfTokenIsolir.name = name;
            csrfTokenIsolir.value = hash;
        }
        if (csrfTokenRelease) {
            csrfTokenRelease.name = name;
            csrfTokenRelease.value = hash;
        }
    }

    function setResult(success, text) {
        if (!resultBox) return;
        resultBox.className = success ? 'text-success' : 'text-danger';
        resultBox.textContent = text || (success ? 'Operation sukses.' : 'Operation gagal.');
    }

    function showAlert(icon, title, text) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: icon, title: title, text: text });
            return;
        }
        window.alert(text || title);
    }

    function requestAction(formEl, usernameEl, actionLabel) {
        if (!formEl || !usernameEl) return;

        var username = (usernameEl.value || '').trim();
        if (!username) {
            showAlert('warning', 'Target kosong', 'Silakan pilih target PPP/STATIC terlebih dahulu.');
            return;
        }

        var executeRequest = function () {
            fetch(formEl.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: new URLSearchParams(new FormData(formEl))
            })
            .then(function (res) {
                return res.json();
            })
            .then(function (json) {
                updateCsrf(json.csrf_name, json.csrf_hash);
                if (json.success) {
                    setResult(true, json.message || 'Operation sukses.');
                    showAlert('success', 'Berhasil', json.message || 'Operation sukses.');
                    return;
                }
                setResult(false, json.message || 'Operation gagal.');
                showAlert('error', 'Gagal', json.message || 'Operation gagal.');
            })
            .catch(function (err) {
                var msg = err && err.message ? err.message : 'Network error';
                setResult(false, msg);
                showAlert('error', 'Error', msg);
            });
        };

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'question',
                title: actionLabel + ' user?',
                text: 'Username: ' + username,
                showCancelButton: true,
                confirmButtonText: 'Ya, lanjut',
                cancelButtonText: 'Batal'
            }).then(function (result) {
                if (!result.isConfirmed) return;
                executeRequest();
            });
            return;
        }

        if (window.confirm(actionLabel + ' user ' + username + '?')) {
            executeRequest();
        }
    }

    if (formIsolir) {
        formIsolir.addEventListener('submit', function (e) {
            e.preventDefault();
            requestAction(formIsolir, inputIsolir, 'Isolir');
        });
    }

    if (formRelease) {
        formRelease.addEventListener('submit', function (e) {
            e.preventDefault();
            requestAction(formRelease, inputRelease, 'Release');
        });
    }
});
</script>
<style>
.manual-isolir-head {
    background: linear-gradient(90deg, #f4b400, #ffc107);
}
.manual-isolir-card {
    border-radius: 14px;
}
</style>
SCRIPT;

include APPPATH . 'views/layout/master.php';
