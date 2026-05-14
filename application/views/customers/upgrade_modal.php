<?php
$customer = isset($customer) ? $customer : array();
$upgrade_context = isset($upgrade_context) ? $upgrade_context : array();
$plans = isset($plans) && is_array($plans) ? $plans : array();
$process_url = isset($process_url) ? $process_url : site_url('customers/upgrade/process');
$calculate_url = isset($calculate_url) ? $calculate_url : site_url('customers/upgrade/calculate-prorate');
$csrf_name = isset($csrf_name) ? $csrf_name : $this->security->get_csrf_token_name();
$csrf_hash = isset($csrf_hash) ? $csrf_hash : $this->security->get_csrf_hash();

$customer_id = (int) ($customer['id'] ?? 0);
$customer_name = (string) ($customer['full_name'] ?? ($customer['nama'] ?? '-'));
$old_plan_id = (int) ($upgrade_context['old_plan_id'] ?? 0);
$old_plan_name = (string) ($upgrade_context['old_plan_name'] ?? '-');
$old_price = (float) ($upgrade_context['old_price'] ?? 0);
$default_upgrade_date = date('Y-m-d');
?>
<div class="modal fade" id="customerUpgradeModal" tabindex="-1" aria-hidden="true" data-calculate-url="<?php echo html_escape($calculate_url); ?>">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">Upgrade Paket - <?php echo html_escape($customer_name); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="<?php echo html_escape($process_url); ?>" id="customerUpgradeForm">
                <input type="hidden" name="<?php echo html_escape($csrf_name); ?>" value="<?php echo html_escape($csrf_hash); ?>">
                <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Paket Saat Ini</label>
                            <input type="text" class="form-control" value="<?php echo html_escape($old_plan_name); ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Paket Baru</label>
                            <select class="form-select" name="new_plan_id" id="upgradeNewPlan" required>
                                <option value="">Pilih Paket Baru</option>
                                <?php foreach ($plans as $plan): ?>
                                    <?php
                                    $plan_id = (int) ($plan['id'] ?? 0);
                                    $plan_name = (string) ($plan['name'] ?? '-');
                                    if ($plan_id <= 0) {
                                        continue;
                                    }
                                    ?>
                                    <option
                                        value="<?php echo $plan_id; ?>"
                                        data-price="<?php echo html_escape((string) ((float) ($plan['price'] ?? 0))); ?>"
                                        <?php echo $plan_id === $old_plan_id ? 'disabled' : ''; ?>
                                    >
                                        <?php echo html_escape($plan_name . ' - Rp ' . number_format((float) ($plan['price'] ?? 0), 0, ',', '.')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Harga Lama</label>
                            <input type="text" class="form-control" id="upgradeOldPrice" value="Rp <?php echo number_format($old_price, 0, ',', '.'); ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Harga Baru</label>
                            <input type="text" class="form-control" id="upgradeNewPrice" value="Rp 0" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Selisih Harga</label>
                            <input type="text" class="form-control" id="upgradePriceDiff" value="Rp 0" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tanggal Upgrade</label>
                            <input type="date" class="form-control" name="upgrade_date" id="upgradeDate" value="<?php echo html_escape($default_upgrade_date); ?>" required>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" name="apply_prorate" id="applyProrate" checked>
                                <label class="form-check-label" for="applyProrate">
                                    Hitung prorate tagihan
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tipe Perubahan</label>
                            <input type="text" class="form-control" id="upgradeType" value="-" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Prorate Tagihan</label>
                            <input type="text" class="form-control" id="upgradeProrateAmount" value="Rp 0" readonly>
                        </div>
                    </div>

                    <div class="alert alert-light border mt-3 mb-0 small" id="upgradeCalcMessage">
                        Pilih paket baru untuk melihat simulasi upgrade.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning" id="btnConfirmUpgrade">Konfirmasi Upgrade</button>
                </div>
            </form>
        </div>
    </div>
</div>
