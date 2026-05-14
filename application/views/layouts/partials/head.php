<?php
$brand_name = app_name();
$brand_tagline = app_tagline();
$page_title = isset($page_title) ? $page_title : ($brand_name . ' - ' . $brand_tagline);
$asset_base = isset($asset_base) ? rtrim($asset_base, '/') : '/application/views/assets';
$pwa_base = rtrim(base_url(), '/') . '/';
$pwa_manifest = $pwa_base . 'manifest.json';
$pwa_icon_192 = $pwa_base . 'pwa/icon-192.png';
$pwa_icon_512 = $pwa_base . 'pwa/icon-512.png';
$brand_icon = base_url(ltrim(app_icon_url(), '/'));
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1d4ed8">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?php echo html_escape($brand_name); ?>">
<title><?php echo html_escape($page_title); ?></title>
<link rel="manifest" href="<?php echo html_escape($pwa_manifest); ?>">
<link rel="icon" type="image/png" href="<?php echo html_escape($brand_icon); ?>">
<link rel="shortcut icon" type="image/png" href="<?php echo html_escape($brand_icon); ?>">
<link rel="apple-touch-icon" href="<?php echo html_escape($brand_icon); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Sora:wght@500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?php echo $asset_base; ?>/css/app-ui.css" rel="stylesheet">
<?php if (!empty($extra_head)): ?>
<?php echo $extra_head; ?>
<?php endif; ?>
