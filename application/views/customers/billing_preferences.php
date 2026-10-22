<?php
$billing_customer = isset($billing_customer) ? (array) $billing_customer : array();
$interval = (string) set_value('billing_interval_months', $billing_customer['billing_interval_months'] ?? '1');
$anchor = (string) set_value('billing_cycle_start', substr((string) ($billing_customer['billing_cycle_start'] ?? ''), 0, 7));
$collection = (string) set_value('payment_collection_method', $billing_customer['payment_collection_method'] ?? 'unspecified');
?>
<div class="col-md-4">
    <label class="form-label">Siklus Tagihan</label>
    <select class="form-select" name="billing_interval_months" required>
        <option value="1" <?php echo $interval === '1' ? 'selected' : ''; ?>>Setiap 1 bulan</option>
        <option value="2" <?php echo $interval === '2' ? 'selected' : ''; ?>>Setiap 2 bulan (2× tarif bulanan)</option>
    </select>
</div>
<div class="col-md-4">
    <label class="form-label">Bulan Awal Siklus</label>
    <input type="month" class="form-control" name="billing_cycle_start" value="<?php echo html_escape($anchor); ?>">
    <div class="form-text">Wajib untuk siklus 2 bulan. Contoh: November → Nov–Des, Jan–Feb. Pilih bulan yang belum ditagih.</div>
</div>
<div class="col-md-4">
    <label class="form-label">Cara Penagihan</label>
    <select class="form-select" name="payment_collection_method" required>
        <option value="unspecified" <?php echo $collection === 'unspecified' ? 'selected' : ''; ?>>Belum ditentukan</option>
        <option value="transfer" <?php echo $collection === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
        <option value="cash_visit" <?php echo $collection === 'cash_visit' ? 'selected' : ''; ?>>Cash — ditagih ke rumah</option>
    </select>
    <div class="form-text">Tagihan belum lunas pelanggan kunjungan rumah dikirim ke grup penagihan setiap tanggal 10 pukul 08.00 WIB.</div>
</div>
