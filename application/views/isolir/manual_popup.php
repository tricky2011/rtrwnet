<?php
$user_options = isset($user_options) && is_array($user_options) ? $user_options : array();
$csrf_name = isset($csrf_name) ? (string) $csrf_name : $this->security->get_csrf_token_name();
$csrf_hash = isset($csrf_hash) ? (string) $csrf_hash : $this->security->get_csrf_hash();
$router_scope_required = !empty($router_scope_required);
?>

<div class="manual-isolir-popup-content">
    <div class="js-manual-isolir-popup-config"
         data-isolate-action="<?php echo html_escape(site_url('manual-isolir/isolate')); ?>"
         data-release-action="<?php echo html_escape(site_url('manual-isolir/release')); ?>"
         data-csrf-name="<?php echo html_escape($csrf_name); ?>"
         data-csrf-hash="<?php echo html_escape($csrf_hash); ?>"
         data-router-scope-required="<?php echo $router_scope_required ? '1' : '0'; ?>"></div>

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
                    <form class="js-manual-isolir-form" method="post" action="<?php echo site_url('manual-isolir/isolate'); ?>">
                        <input type="hidden" class="js-csrf-isolir" name="<?php echo html_escape($csrf_name); ?>" value="<?php echo html_escape($csrf_hash); ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Target (Username/Queue):</label>
                            <select class="form-select js-manual-isolir-popup-select js-manual-isolir-target" name="pppoe_username" required <?php echo $router_scope_required ? 'disabled' : ''; ?>>
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
                    <form class="js-manual-release-form" method="post" action="<?php echo site_url('manual-isolir/release'); ?>">
                        <input type="hidden" class="js-csrf-release" name="<?php echo html_escape($csrf_name); ?>" value="<?php echo html_escape($csrf_hash); ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Target (Username/Queue):</label>
                            <select class="form-select js-manual-isolir-popup-select js-manual-release-target" name="pppoe_username" required <?php echo $router_scope_required ? 'disabled' : ''; ?>>
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
            <div class="js-manual-isolir-result text-muted">No operations performed yet.</div>
        </div>
    </div>
</div>
