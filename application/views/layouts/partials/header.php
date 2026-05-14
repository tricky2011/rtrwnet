<?php
$brand_name = app_name();
$brand_logo = base_url(ltrim(app_logo_url(false), '/'));
$brand_logo_dark = base_url(ltrim(app_logo_url(true), '/'));
?>
<header class="app-topbar d-flex align-items-center px-3 px-lg-4 sticky-top">
    <button class="btn btn-sm btn-outline-secondary d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar">
        <i class="bi bi-list"></i>
    </button>

    <div class="app-brand d-flex align-items-center gap-2 text-dark">
        <img
            src="<?php echo html_escape($brand_logo); ?>"
            alt="<?php echo html_escape($brand_name); ?> Logo"
            class="app-brand-logo app-brand-logo-sm"
            data-logo-light="<?php echo html_escape($brand_logo); ?>"
            data-logo-dark="<?php echo html_escape($brand_logo_dark); ?>"
        >
        <span class="app-brand-text"><?php echo html_escape($brand_name); ?></span>
    </div>

    <div class="ms-auto d-flex align-items-center gap-2">
        <span class="badge rounded-pill badge-soft d-none d-md-inline">Updated <span data-now-time></span></span>
        <button class="btn btn-sm btn-light border"><i class="bi bi-bell"></i></button>
        <button class="btn btn-sm btn-light border"><i class="bi bi-person-circle"></i></button>
    </div>
</header>
