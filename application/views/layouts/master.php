<!doctype html>
<html lang="en">
<head>
    <?php include APPPATH . 'views/layouts/partials/head.php'; ?>
</head>
<body class="app-shell">
    <?php include APPPATH . 'views/layouts/partials/header.php'; ?>

    <?php $sidebar_mode = 'offcanvas'; include APPPATH . 'views/layouts/partials/sidebar.php'; ?>

    <div class="app-wrapper d-flex">
        <?php $sidebar_mode = 'desktop'; include APPPATH . 'views/layouts/partials/sidebar.php'; ?>

        <main class="app-content">
            <?php if (!empty($page_heading) || !empty($page_subheading)): ?>
            <div class="page-head reveal">
                <?php if (!empty($page_heading)): ?>
                <h1 class="page-title"><?php echo html_escape($page_heading); ?></h1>
                <?php endif; ?>
                <?php if (!empty($page_subheading)): ?>
                <p class="page-subtitle"><?php echo html_escape($page_subheading); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php echo isset($content) ? $content : ''; ?>

            <?php include APPPATH . 'views/layouts/partials/footer.php'; ?>
        </main>
    </div>

    <?php include APPPATH . 'views/layouts/partials/scripts.php'; ?>
</body>
</html>
