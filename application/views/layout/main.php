<?php
$brand_name = app_name();
$brand_tagline = app_tagline();
$brand_company = app_company();
$page_title = isset($page_title) ? $page_title : ($brand_name . ' - ' . $brand_tagline);
$page_heading = isset($page_heading) ? $page_heading : '';
$page_subheading = isset($page_subheading) ? $page_subheading : '';
$content = isset($content) ? $content : '';
$page_scripts = isset($page_scripts) ? $page_scripts : '';
$pwa_base = rtrim(base_url(), '/') . '/';
$pwa_manifest = $pwa_base . 'manifest.json';
$pwa_icon_192 = $pwa_base . 'pwa/icon-192.png';
$pwa_icon_512 = $pwa_base . 'pwa/icon-512.png';
$brand_logo = base_url(ltrim(app_logo_url(false), '/'));
$brand_logo_dark = base_url(ltrim(app_logo_url(true), '/'));
$brand_icon = base_url(ltrim(app_icon_url(), '/'));
$custom_css_file = FCPATH . 'assets/css/custom.css';
$custom_js_file = FCPATH . 'assets/js/custom.js';
$header_notif_js_file = FCPATH . 'assets/js/header-notification.js';
$app_ui_js_file = FCPATH . 'assets/js/app-ui.js';
$custom_css_ver = file_exists($custom_css_file) ? (string) filemtime($custom_css_file) : (string) time();
$custom_js_ver = file_exists($custom_js_file) ? (string) filemtime($custom_js_file) : (string) time();
$header_notif_js_ver = file_exists($header_notif_js_file) ? (string) filemtime($header_notif_js_file) : (string) time();
$app_ui_js_ver = file_exists($app_ui_js_file) ? (string) filemtime($app_ui_js_file) : (string) time();
$flash_success = (string) $this->session->flashdata('success');
$flash_error = (string) $this->session->flashdata('error');
$is_demo_mode = !empty($is_demo_mode) || (bool) $this->session->userdata('is_demo');
$demo_mode_banner_text = isset($demo_mode_banner_text) && trim((string) $demo_mode_banner_text) !== ''
    ? (string) $demo_mode_banner_text
    : 'ANDA SEDANG DALAM MODE DEMO - AKSES HANYA READ ONLY';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?php echo html_escape($brand_name); ?>">
    <link rel="manifest" href="<?php echo html_escape($pwa_manifest); ?>">
    <link rel="icon" type="image/png" href="<?php echo html_escape($brand_icon); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo html_escape($brand_icon); ?>">
    <link rel="apple-touch-icon" href="<?php echo html_escape($brand_icon); ?>">
    <title><?php echo html_escape($page_title); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons@1.119.0/iconfont/tabler-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/custom.css?v=' . rawurlencode($custom_css_ver)); ?>" rel="stylesheet">
</head>
<body data-flash-success="<?php echo html_escape($flash_success); ?>" data-flash-error="<?php echo html_escape($flash_error); ?>">
    <div id="appLoader">
        <div class="loader-spinner"></div>
        <div class="small text-muted">Memuat <?php echo html_escape($brand_name); ?>...</div>
    </div>

    <?php include APPPATH . 'views/layout/header.php'; ?>

    <?php if ($is_demo_mode): ?>
    <div class="demo-mode-banner">
        <?php echo html_escape($demo_mode_banner_text); ?>
    </div>
    <?php endif; ?>

    <div class="app-shell">
        <?php include APPPATH . 'views/layout/sidebar.php'; ?>

        <main class="app-main p-3 p-lg-4">
            <?php if ($page_heading): ?>
                <div class="page-heading">
                    <h1><?php echo html_escape($page_heading); ?></h1>
                    <?php if ($page_subheading): ?>
                        <p><?php echo html_escape($page_subheading); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php echo $content; ?>

            <?php include APPPATH . 'views/layout/footer.php'; ?>
        </main>
    </div>

    <div class="modal fade" id="manualIsolirGlobalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg manual-isolir-modal">
                <div class="modal-header manual-isolir-head border-0">
                    <h5 class="mb-0 text-white fw-semibold">
                        <i class="bi bi-person-gear me-2"></i>Manual Isolir/Release User
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3 p-md-4 bg-light" id="manualIsolirGlobalModalBody">
                    <div class="manual-isolir-loading text-muted">Memuat data...</div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
      window.__APP_SW__ = {
        path: <?php echo json_encode($pwa_base . 'pwa-sw.js'); ?>,
        scope: <?php echo json_encode($pwa_base); ?>
      };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://js.pusher.com/7.2/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
    <script src="<?php echo base_url('assets/js/app-ui.js?v=' . rawurlencode($app_ui_js_ver)); ?>"></script>
    <script src="<?php echo base_url('assets/js/custom.js?v=' . rawurlencode($custom_js_ver)); ?>"></script>
    <script src="<?php echo base_url('assets/js/header-notification.js?v=' . rawurlencode($header_notif_js_ver)); ?>"></script>

    <?php echo $page_scripts; ?>
</body>
</html>
