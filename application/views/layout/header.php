<?php
$brand_name = app_name();
$brand_logo = base_url(ltrim(app_logo_url(false), '/'));
$brand_logo_dark = base_url(ltrim(app_logo_url(true), '/'));
$name = (string) $this->session->userdata('name');
$role = strtolower(trim((string) $this->session->userdata('role')));
$is_demo_mode = (bool) $this->session->userdata('is_demo');
$role_label = $is_demo_mode ? 'DEMO (READ ONLY)' : strtoupper($role !== '' ? $role : 'user');
$dashboard_url = site_url('dashboard');
if ($role === 'teknisi') {
    $dashboard_url = site_url('teknisi-dashboard');
}

$show_router_switcher = false;
$active_router_id = (int) $this->session->userdata('active_router_id');
if ($active_router_id <= 0) {
    $active_router_id = (int) $this->session->userdata('dashboard_router_id');
}
if ($active_router_id <= 0) {
    $active_router_id = (int) $this->session->userdata('router_scope_id');
}
$active_router_options = array();
if ($role === 'superadmin' && isset($this->db) && is_object($this->db) && $this->db->table_exists('routers')) {
    $router_fields = $this->db->list_fields('routers');
    if (in_array('id', $router_fields, true)) {
        $router_name_col = in_array('name', $router_fields, true)
            ? 'name'
            : (in_array('router_name', $router_fields, true) ? 'router_name' : 'id');
        $qb = $this->db
            ->select('id, ' . $router_name_col . ' AS name', false)
            ->from('routers');
        if (in_array('is_active', $router_fields, true)) {
            $qb->where('is_active', 1);
        }
        $active_router_options = $qb->order_by($router_name_col, 'ASC')->get()->result_array();
        $show_router_switcher = !empty($active_router_options);
    }
}

$query_string = isset($_SERVER['QUERY_STRING']) && trim((string) $_SERVER['QUERY_STRING']) !== ''
    ? '?' . trim((string) $_SERVER['QUERY_STRING'])
    : '';
$return_url = current_url() . $query_string;
$active_router_context = isset($active_router_context) && is_array($active_router_context)
    ? $active_router_context
    : (function_exists('getActiveRouter') ? getActiveRouter() : array());
$active_router_badge_text = trim((string) ($active_router_badge_text ?? ($active_router_context['label'] ?? '')));
if ($active_router_badge_text === '') {
    $active_router_badge_text = 'Distribusi: -';
}
if ($role === 'superadmin' && $active_router_id <= 0 && !empty($active_router_context['router_id'])) {
    $active_router_id = (int) $active_router_context['router_id'];
}
?>
<header class="app-header navbar navbar-expand-lg">
    <div class="container-fluid px-3 px-lg-4">
        <div class="d-flex align-items-center gap-2">
            <button id="sidebarToggleMobile" class="header-action d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Toggle sidebar">
                <i class="ti ti-menu-2"></i>
            </button>
            <button id="sidebarToggleDesktop" class="header-action d-none d-lg-inline-flex" type="button" aria-label="Collapse sidebar" title="Collapse sidebar">
                <i class="ti ti-layout-sidebar-left-collapse"></i>
            </button>
            <a class="navbar-brand app-brand d-flex align-items-center gap-2 mb-0" href="<?php echo $dashboard_url; ?>">
                <img
                    src="<?php echo html_escape($brand_logo); ?>"
                    alt="<?php echo html_escape($brand_name); ?> Logo"
                    class="app-brand-logo"
                    data-logo-light="<?php echo html_escape($brand_logo); ?>"
                    data-logo-dark="<?php echo html_escape($brand_logo_dark); ?>"
                >
                <span class="app-brand-text"><?php echo html_escape($brand_name); ?></span>
            </a>
        </div>

        <div class="ms-auto d-flex align-items-center gap-2">
            <?php if ($show_router_switcher): ?>
                <form method="post" action="<?php echo site_url('dashboard/switch_router'); ?>" class="d-none d-md-flex align-items-center gap-2 me-1">
                    <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo html_escape($return_url); ?>">
                    <select name="router_id" class="form-select form-select-sm" style="min-width: 200px;" onchange="this.form.submit()">
                        <option value="0"<?php echo $active_router_id <= 0 ? ' selected' : ''; ?>>Semua Distribusi</option>
                        <?php foreach ($active_router_options as $router_option): ?>
                            <?php $router_option_id = (int) ($router_option['id'] ?? 0); ?>
                            <option value="<?php echo $router_option_id; ?>"<?php echo $router_option_id === $active_router_id ? ' selected' : ''; ?>>
                                <?php echo html_escape((string) ($router_option['name'] ?? ('Router #' . $router_option_id))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>

            <span class="badge text-bg-light border d-none d-md-inline" style="border-radius: 10px;">
                <?php echo html_escape($active_router_badge_text); ?>
            </span>

            <button id="themeToggle" type="button" class="header-action" title="Toggle dark mode" aria-label="Toggle dark mode">
                <i id="themeToggleIcon" class="ti ti-moon-stars"></i>
            </button>

            <?php include APPPATH . 'views/layout/header_notification.php'; ?>

            <div class="dropdown">
                <button class="header-action header-profile-action d-inline-flex align-items-center gap-1 px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="ti ti-user-circle"></i>
                    <span class="d-none d-md-inline small fw-semibold"><?php echo html_escape($name !== '' ? $name : 'User'); ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="border-radius: 12px; min-width: 220px;">
                    <li class="px-3 py-2 border-bottom">
                        <div class="fw-semibold"><?php echo html_escape($name !== '' ? $name : 'User'); ?></div>
                        <div class="small text-muted"><?php echo html_escape($role_label); ?></div>
                    </li>
                    <li>
                        <a class="dropdown-item py-2" href="<?php echo $dashboard_url; ?>"><i class="ti ti-dashboard me-2"></i>Dashboard</a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item text-danger py-2" href="<?php echo site_url('auth/logout'); ?>"><i class="ti ti-logout me-2"></i>Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <?php if ($show_router_switcher): ?>
        <div class="app-header-mobile-router d-md-none px-3 pb-2">
            <form method="post" action="<?php echo site_url('dashboard/switch_router'); ?>" class="d-flex align-items-center gap-2">
                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo html_escape($return_url); ?>">
                <label class="small text-muted mb-0 flex-shrink-0" for="routerSelectMobile">Router</label>
                <select id="routerSelectMobile" name="router_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0"<?php echo $active_router_id <= 0 ? ' selected' : ''; ?>>Semua Distribusi</option>
                    <?php foreach ($active_router_options as $router_option): ?>
                        <?php $router_option_id = (int) ($router_option['id'] ?? 0); ?>
                        <option value="<?php echo $router_option_id; ?>"<?php echo $router_option_id === $active_router_id ? ' selected' : ''; ?>>
                            <?php echo html_escape((string) ($router_option['name'] ?? ('Router #' . $router_option_id))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    <?php endif; ?>
</header>
