<?php
$brand_name = app_name();
$brand_tagline = app_tagline();
$brand_company = app_company();
$is_demo_mode = !empty($is_demo_mode) || (bool) $this->session->userdata('is_demo');
$demo_mode_watermark = isset($demo_mode_watermark) && trim((string) $demo_mode_watermark) !== ''
    ? (string) $demo_mode_watermark
    : ($brand_name . ' ' . $brand_tagline . ' Demo Version');
?>
<footer class="border-top mt-4 py-3 text-muted small">
    <div class="d-flex flex-column flex-md-row justify-content-between">
        <span>&copy; <?php echo date('Y'); ?> <?php echo html_escape($brand_name . ' ' . $brand_tagline); ?></span>
        <?php if ($is_demo_mode): ?>
            <span><?php echo html_escape($demo_mode_watermark); ?></span>
        <?php else: ?>
            <span>Powered by <?php echo html_escape($brand_company); ?></span>
        <?php endif; ?>
    </div>
</footer>
