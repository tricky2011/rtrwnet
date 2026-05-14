<?php
$pwa_base = rtrim(base_url(), '/') . '/';
$app_ui_js_file = FCPATH . 'assets/js/app-ui.js';
$app_ui_js_ver = file_exists($app_ui_js_file) ? (string) filemtime($app_ui_js_file) : (string) time();
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?php echo base_url('assets/js/app-ui.js?v=' . rawurlencode($app_ui_js_ver)); ?>"></script>
<script>
(function () {
    if (!('serviceWorker' in navigator)) {
        return;
    }
    window.addEventListener('load', function () {
        navigator.serviceWorker
            .register(<?php echo json_encode($pwa_base . 'pwa-sw.js'); ?>, { scope: <?php echo json_encode($pwa_base); ?> })
            .catch(function (error) {
                console.warn('[PWA] Service worker registration failed:', error);
            });
    });
})();
</script>
<?php if (!empty($page_scripts)): ?>
<?php echo $page_scripts; ?>
<?php endif; ?>
