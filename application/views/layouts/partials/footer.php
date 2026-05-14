<?php
$brand_name = app_name();
$brand_tagline = app_tagline();
$brand_company = app_company();
?>
<footer class="footer-ui mt-4 py-3 border-top">
    <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
        <div>&copy; <span data-now-year></span> <?php echo html_escape($brand_name . ' ' . $brand_tagline); ?></div>
        <div>Powered by <?php echo html_escape($brand_company); ?></div>
    </div>
</footer>
