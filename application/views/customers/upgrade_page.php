<?php
$customer_options = isset($customer_options) && is_array($customer_options) ? $customer_options : array();
$plans = isset($plans) && is_array($plans) ? $plans : array();
$csrf_name = isset($csrf_name) ? (string) $csrf_name : $this->security->get_csrf_token_name();
$csrf_hash = isset($csrf_hash) ? (string) $csrf_hash : $this->security->get_csrf_hash();
$calculate_url = isset($calculate_url) ? (string) $calculate_url : site_url('customers/upgrade/calculate-prorate');
$context_url_base = isset($context_url_base) ? (string) $context_url_base : rtrim(site_url('customers/upgrade/show-form'), '/');
$process_url = isset($process_url) ? (string) $process_url : site_url('customers/upgrade/process');
$return_url = isset($return_url) ? (string) $return_url : 'customers/upgrade';

$page_title = 'Upgrade Paket Customer - ' . app_name();
$page_heading = 'Upgrade Paket Customer';
$page_subheading = 'Menu khusus untuk upgrade/downgrade paket pelanggan.';
$active_menu = 'customer_upgrade';

$default_customer_id = (int) set_value('customer_id', 0);
$default_new_plan_id = (int) set_value('new_plan_id', 0);
$default_upgrade_date = (string) set_value('upgrade_date', date('Y-m-d'));
$default_apply_prorate = (int) set_value('apply_prorate', 1) === 1;
$has_default_customer = $default_customer_id > 0;
$has_default_plan = $default_new_plan_id > 0;

ob_start();
?>
<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">
        Form Upgrade Paket
    </div>
    <div class="card-body">
        <?php if ($this->session->flashdata('success')): ?>
        <div class="alert alert-success"><?php echo html_escape($this->session->flashdata('success')); ?></div>
        <?php endif; ?>

        <?php if ($this->session->flashdata('error')): ?>
        <div class="alert alert-danger"><?php echo html_escape($this->session->flashdata('error')); ?></div>
        <?php endif; ?>

        <?php if (empty($customer_options)): ?>
        <div class="alert alert-warning mb-0">Belum ada customer aktif yang bisa diproses upgrade.</div>
        <?php elseif (empty($plans)): ?>
        <div class="alert alert-warning mb-0">Data service plan (`ppp_profiles`) belum tersedia.</div>
        <?php else: ?>
        <form method="post" action="<?php echo html_escape($process_url); ?>" id="customerUpgradePageForm">
            <input type="hidden" name="<?php echo html_escape($csrf_name); ?>" id="upgradeCsrfToken" value="<?php echo html_escape($csrf_hash); ?>">
            <input type="hidden" name="return_url" value="<?php echo html_escape($return_url); ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Customer</label>
                    <select class="form-select" name="customer_id" id="upgradeCustomer" data-searchable="1" required>
                        <option value="" data-placeholder="true" <?php echo $has_default_customer ? '' : 'selected'; ?>>Pilih Customer</option>
                        <?php foreach ($customer_options as $customer): ?>
                        <?php
                        $cid = (int) ($customer['id'] ?? 0);
                        $name = (string) ($customer['name'] ?? ('Customer #' . $cid));
                        $pppoe = trim((string) ($customer['pppoe_username'] ?? ''));
                        if ($cid <= 0) {
                            continue;
                        }
                        ?>
                        <option value="<?php echo $cid; ?>" <?php echo $default_customer_id === $cid ? 'selected' : ''; ?>>
                            <?php echo html_escape($name . ($pppoe !== '' ? ' (' . $pppoe . ')' : '')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Paket Baru</label>
                    <select class="form-select" name="new_plan_id" id="upgradeNewPlan" data-searchable="1" required>
                        <option value="" data-placeholder="true" <?php echo $has_default_plan ? '' : 'selected'; ?>>Pilih Paket Baru</option>
                        <?php foreach ($plans as $plan): ?>
                        <?php
                        $plan_id = (int) ($plan['id'] ?? 0);
                        $plan_name = trim((string) ($plan['name'] ?? ''));
                        $plan_price = (float) ($plan['price'] ?? 0);
                        if ($plan_id <= 0) {
                            continue;
                        }
                        ?>
                        <option value="<?php echo $plan_id; ?>" data-price="<?php echo html_escape((string) $plan_price); ?>" <?php echo $default_new_plan_id === $plan_id ? 'selected' : ''; ?>>
                            <?php echo html_escape(($plan_name !== '' ? $plan_name : ('Plan #' . $plan_id)) . ' - Rp ' . number_format($plan_price, 0, ',', '.')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Username PPP Target</label>
                    <input type="text" class="form-control" id="upgradeTargetUsername" value="-" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Router Target</label>
                    <input type="text" class="form-control" id="upgradeTargetRouter" value="-" readonly>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Paket Saat Ini</label>
                    <input type="text" class="form-control" id="upgradeOldPlanName" value="-" readonly>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tipe Perubahan</label>
                    <input type="text" class="form-control" id="upgradeType" value="-" readonly>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Harga Lama</label>
                    <input type="text" class="form-control" id="upgradeOldPrice" value="Rp 0" readonly>
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
                        <input class="form-check-input" type="checkbox" value="1" name="apply_prorate" id="applyProrate" <?php echo $default_apply_prorate ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="applyProrate">Hitung prorate tagihan</label>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Prorate Tagihan</label>
                    <input type="text" class="form-control" id="upgradeProrateAmount" value="Rp 0" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Pool Target</label>
                    <input type="text" class="form-control" id="upgradeTargetPool" value="-" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">IP Baru</label>
                    <input type="text" class="form-control" id="upgradeTargetIp" value="-" readonly>
                </div>
            </div>

            <div class="alert alert-light border mt-3 mb-0 small" id="upgradeCalcMessage">
                Pilih customer dan paket baru untuk melihat simulasi upgrade.
            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-warning" id="btnSubmitUpgrade" disabled>Konfirmasi Upgrade</button>
                <a href="<?php echo site_url('customers'); ?>" class="btn btn-outline-secondary">Kembali ke Customers</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($customer_options) && !empty($plans)): ?>
<script>
(function () {
    const formEl = document.getElementById('customerUpgradePageForm');
    if (!formEl) {
        return;
    }

    const customerEl = document.getElementById('upgradeCustomer');
    const newPlanEl = document.getElementById('upgradeNewPlan');
    const upgradeDateEl = document.getElementById('upgradeDate');
    const applyProrateEl = document.getElementById('applyProrate');
    const csrfEl = document.getElementById('upgradeCsrfToken');
    const submitBtn = document.getElementById('btnSubmitUpgrade');
    const oldPlanEl = document.getElementById('upgradeOldPlanName');
    const targetUsernameEl = document.getElementById('upgradeTargetUsername');
    const targetRouterEl = document.getElementById('upgradeTargetRouter');
    const oldPriceEl = document.getElementById('upgradeOldPrice');
    const newPriceEl = document.getElementById('upgradeNewPrice');
    const diffEl = document.getElementById('upgradePriceDiff');
    const typeEl = document.getElementById('upgradeType');
    const prorateEl = document.getElementById('upgradeProrateAmount');
    const targetPoolEl = document.getElementById('upgradeTargetPool');
    const targetIpEl = document.getElementById('upgradeTargetIp');
    const messageEl = document.getElementById('upgradeCalcMessage');

    const csrfName = <?php echo json_encode($csrf_name); ?>;
    const calculateUrl = <?php echo json_encode($calculate_url); ?>;
    const contextUrlBase = <?php echo json_encode($context_url_base); ?>;
    const keepCustomerSelection = <?php echo $has_default_customer ? 'true' : 'false'; ?>;
    const keepPlanSelection = <?php echo $has_default_plan ? 'true' : 'false'; ?>;
    const idr = new Intl.NumberFormat('id-ID');
    let currentOldPlanId = 0;

    function normalizeAmount(value) {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
    }

    function formatCurrency(value) {
        const amount = normalizeAmount(value);
        const abs = Math.abs(amount);
        const sign = amount < 0 ? '- ' : '';
        return sign + 'Rp ' + idr.format(abs);
    }

    function setMessage(text, level) {
        if (!messageEl) {
            return;
        }

        messageEl.className = 'alert mt-3 mb-0 small';
        if (level === 'error') {
            messageEl.classList.add('alert-danger');
        } else if (level === 'success') {
            messageEl.classList.add('alert-success');
        } else if (level === 'warning') {
            messageEl.classList.add('alert-warning');
        } else {
            messageEl.classList.add('alert-light', 'border');
        }
        messageEl.textContent = text;
    }

    function updateCsrfToken(token) {
        if (!token) {
            return;
        }
        document.querySelectorAll('input[name="' + csrfName + '"]').forEach(function (el) {
            el.value = token;
        });
    }

    function applyPlanOptionState(oldPlanId) {
        if (!newPlanEl) {
            return;
        }

        const oldId = Number(oldPlanId || 0);
        Array.prototype.forEach.call(newPlanEl.options, function (option) {
            const value = Number(option.value || 0);
            if (value <= 0) {
                option.disabled = false;
                return;
            }
            option.disabled = oldId > 0 && value === oldId;
        });

        const selected = Number(newPlanEl.value || 0);
        if (selected > 0 && oldId > 0 && selected === oldId) {
            newPlanEl.value = '';
        }
    }

    function resetCalculatedFields() {
        newPriceEl.value = 'Rp 0';
        diffEl.value = 'Rp 0';
        typeEl.value = '-';
        prorateEl.value = 'Rp 0';
        if (targetPoolEl) {
            targetPoolEl.value = '-';
        }
        if (targetIpEl) {
            targetIpEl.value = '-';
        }
        if (submitBtn) {
            submitBtn.disabled = true;
        }
    }

    function ensureEmptySelectionForFreshPage() {
        if (!keepCustomerSelection && customerEl && customerEl.value) {
            customerEl.value = '';
        }
        if (!keepPlanSelection && newPlanEl && newPlanEl.value) {
            newPlanEl.value = '';
        }
    }

    async function fetchCustomerContext() {
        const customerId = Number(customerEl.value || 0);
        currentOldPlanId = 0;
        oldPlanEl.value = '-';
        if (targetUsernameEl) {
            targetUsernameEl.value = '-';
        }
        if (targetRouterEl) {
            targetRouterEl.value = '-';
        }
        oldPriceEl.value = 'Rp 0';
        resetCalculatedFields();

        if (customerId <= 0) {
            applyPlanOptionState(0);
            setMessage('Pilih customer dan paket baru untuk melihat simulasi upgrade.', '');
            return;
        }

        try {
            const response = await fetch(contextUrlBase + '/' + customerId, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const text = await response.text();
            const json = JSON.parse(text);

            if (json && json.csrf_token) {
                updateCsrfToken(json.csrf_token);
            }
            if (!response.ok || !json || !json.success || !json.data) {
                throw new Error((json && json.message) ? json.message : 'Gagal ambil data paket customer.');
            }

            currentOldPlanId = Number(json.data.old_plan_id || 0);
            oldPlanEl.value = json.data.old_plan_name || '-';
            if (targetUsernameEl) {
                targetUsernameEl.value = (json.data.pppoe_username || '-');
            }
            if (targetRouterEl) {
                const rid = Number(json.data.router_id || 0);
                const rname = (json.data.router_name || '').trim();
                targetRouterEl.value = rname !== '' ? rname : (rid > 0 ? ('Router #' + rid) : '-');
            }
            oldPriceEl.value = formatCurrency(json.data.old_price || 0);
            applyPlanOptionState(currentOldPlanId);
            setMessage('Data paket saat ini berhasil dimuat. Pilih paket baru untuk simulasi.', 'success');
        } catch (err) {
            applyPlanOptionState(0);
            setMessage(err.message || 'Gagal ambil data paket customer.', 'error');
        }
    }

    async function recalculate() {
        const customerId = Number(customerEl.value || 0);
        const newPlanId = Number(newPlanEl.value || 0);
        const upgradeDate = (upgradeDateEl.value || '').trim();
        const applyProrate = !!(applyProrateEl && applyProrateEl.checked);

        if (customerId <= 0 || newPlanId <= 0 || upgradeDate === '') {
            resetCalculatedFields();
            if (customerId <= 0) {
                setMessage('Pilih customer terlebih dahulu.', 'warning');
            } else if (newPlanId <= 0) {
                setMessage('Pilih paket baru terlebih dahulu.', 'warning');
            } else {
                setMessage('Tanggal upgrade wajib diisi.', 'warning');
            }
            return;
        }

        const params = new URLSearchParams();
        params.append('customer_id', String(customerId));
        params.append('new_plan_id', String(newPlanId));
        params.append('upgrade_date', upgradeDate);
        params.append('apply_prorate', applyProrate ? '1' : '0');
        params.append(csrfName, csrfEl ? csrfEl.value : '');

        try {
            const response = await fetch(calculateUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: params.toString()
            });
            const text = await response.text();
            const json = JSON.parse(text);

            if (json && json.csrf_token) {
                updateCsrfToken(json.csrf_token);
            }
            if (!response.ok || !json || !json.success || !json.data) {
                throw new Error((json && json.message) ? json.message : 'Perhitungan prorate gagal.');
            }

            const data = json.data;
            oldPlanEl.value = data.old_plan_name || oldPlanEl.value || '-';
            oldPriceEl.value = formatCurrency(data.old_price || 0);
            newPriceEl.value = formatCurrency(data.new_price || 0);
            diffEl.value = formatCurrency(data.price_diff || 0);
            typeEl.value = String(data.upgrade_type || 'upgrade').toLowerCase() === 'downgrade' ? 'DOWNGRADE' : 'UPGRADE';
            prorateEl.value = formatCurrency(data.prorate_amount || 0);
            if (targetPoolEl) {
                targetPoolEl.value = (data.target_pool_name || '-');
            }
            if (targetIpEl) {
                targetIpEl.value = (data.target_remote_ip || '-');
            }

            if (submitBtn) {
                submitBtn.disabled = false;
            }

            let calcText = 'Simulasi: ' + (data.old_plan_name || '-') + ' -> ' + (data.new_plan_name || '-') + '.';
            if (data.target_pool_name) {
                calcText += ' Pool: ' + data.target_pool_name + '.';
            }
            if (data.target_remote_ip) {
                calcText += ' IP Baru: ' + data.target_remote_ip + '.';
            }
            if (data.network_message) {
                calcText += ' ' + data.network_message;
            }
            setMessage(calcText, 'success');
        } catch (err) {
            resetCalculatedFields();
            setMessage(err.message || 'Perhitungan prorate gagal.', 'error');
        }
    }

    customerEl.addEventListener('change', function () {
        fetchCustomerContext().then(recalculate);
    });
    newPlanEl.addEventListener('change', recalculate);
    upgradeDateEl.addEventListener('change', recalculate);
    if (applyProrateEl) {
        applyProrateEl.addEventListener('change', recalculate);
    }

    formEl.addEventListener('submit', function (event) {
        if (!customerEl.value || !newPlanEl.value) {
            event.preventDefault();
            setMessage('Pilih customer dan paket baru sebelum konfirmasi upgrade.', 'warning');
            return;
        }
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Memproses...';
        }
    });

    ensureEmptySelectionForFreshPage();
    fetchCustomerContext().then(recalculate);

    window.addEventListener('load', function () {
        ensureEmptySelectionForFreshPage();
        fetchCustomerContext().then(recalculate);
    });
})();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
