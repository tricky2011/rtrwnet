<?php
$pricing_customer = isset($pricing_customer) ? (array) $pricing_customer : array();
?>
<div class="col-md-6" id="customer-price">
    <label class="form-label" for="monthly_price_override">Harga Khusus Customer / Bulan</label>
    <div class="input-group">
        <span class="input-group-text">Rp</span>
        <input type="number" class="form-control" id="monthly_price_override" name="monthly_price_override"
            min="0" max="999999999999.99" step="0.01" inputmode="decimal"
            value="<?php echo html_escape(set_value('monthly_price_override', $pricing_customer['monthly_price_override'] ?? '')); ?>"
            placeholder="Kosong = harga layanan/paket">
    </div>
    <div class="form-text">Isi nominal tanpa pemisah ribuan, misalnya 125000. Kosongkan untuk memakai harga layanan/paket. Berlaku untuk invoice baru; bandwidth mengikuti paket yang dipilih.</div>
</div>
