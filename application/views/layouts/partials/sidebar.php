<?php
$brand_name = app_name();
$brand_logo = base_url(ltrim(app_logo_url(false), '/'));
$brand_logo_dark = base_url(ltrim(app_logo_url(true), '/'));
$active_menu = isset($active_menu) ? $active_menu : 'dashboard';
$index_page = isset($_SERVER['SCRIPT_NAME']) ? rtrim($_SERVER['SCRIPT_NAME'], '/') : 'index.php';
$mode = isset($sidebar_mode) ? $sidebar_mode : 'desktop';

$main_menu_items = [
    ['key' => 'dashboard', 'icon' => 'bi-grid-1x2', 'label' => 'Dashboard Statistik', 'href' => $index_page . '/dashboard'],
    ['key' => 'monitoring', 'icon' => 'bi-cpu', 'label' => 'System Monitoring', 'href' => $index_page . '/monitoring'],
    ['key' => 'billing', 'icon' => 'bi-receipt', 'label' => 'Billing List', 'href' => $index_page . '/billing'],
    ['key' => 'whatsapp', 'icon' => 'bi-whatsapp', 'label' => 'WhatsApp Logs', 'href' => $index_page . '/admin-whatsapp'],
    ['key' => 'customers', 'icon' => 'bi-people', 'label' => 'Customers', 'href' => $index_page . '/customers'],
    ['key' => 'cashflow', 'icon' => 'bi-cash-stack', 'label' => 'Cashflow', 'href' => $index_page . '/cashflow'],
    ['key' => 'workorders', 'icon' => 'bi-tools', 'label' => 'Work Orders', 'href' => $index_page . '/workorders'],
    ['key' => 'tickets', 'icon' => 'bi-life-preserver', 'label' => 'Helpdesk', 'href' => $index_page . '/tickets'],
    ['key' => 'settings', 'icon' => 'bi-gear', 'label' => 'Settings', 'href' => $index_page . '/settings'],
];

$network_menu_items = [
    ['key' => 'fiber_network_map', 'icon' => 'bi-map', 'label' => 'Fiber Network Map', 'href' => $index_page . '/network/fiber-network-map'],
    ['key' => 'network_nodes', 'icon' => 'bi-diagram-3', 'label' => 'Manajemen ODP/ODC', 'href' => $index_page . '/network/nodes'],
    ['key' => 'master_locations', 'icon' => 'bi-geo-alt', 'label' => 'Master Lokasi', 'href' => $index_page . '/master-references/locations'],
    ['key' => 'master_olts', 'icon' => 'bi-hdd-network', 'label' => 'Master OLT', 'href' => $index_page . '/master-references/olts'],
];
?>

<?php if ($mode === 'offcanvas'): ?>
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title app-brand d-flex align-items-center gap-2" id="mobileSidebarLabel">
            <img
                src="<?php echo html_escape($brand_logo); ?>"
                alt="<?php echo html_escape($brand_name); ?> Logo"
                class="app-brand-logo app-brand-logo-sm"
                data-logo-light="<?php echo html_escape($brand_logo); ?>"
                data-logo-dark="<?php echo html_escape($brand_logo_dark); ?>"
            >
            <span class="app-brand-text"><?php echo html_escape($brand_name); ?></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-3">
        <a class="sidebar-brand-link mb-2" href="<?php echo html_escape($index_page . '/dashboard'); ?>">
            <img
                src="<?php echo html_escape($brand_logo); ?>"
                alt="<?php echo html_escape($brand_name); ?> Logo"
                class="app-brand-logo"
                data-logo-light="<?php echo html_escape($brand_logo); ?>"
                data-logo-dark="<?php echo html_escape($brand_logo_dark); ?>"
            >
            <span class="app-brand-text"><?php echo html_escape($brand_name); ?></span>
        </a>
        <div class="sidebar-group mt-0">Main Menu</div>
        <?php foreach ($main_menu_items as $item): ?>
        <a class="sidebar-link <?php echo $active_menu === $item['key'] ? 'active' : ''; ?>" href="<?php echo html_escape($item['href']); ?>">
            <i class="bi <?php echo $item['icon']; ?>"></i>
            <span><?php echo $item['label']; ?></span>
        </a>
        <?php endforeach; ?>

        <div class="sidebar-group">Network</div>
        <?php foreach ($network_menu_items as $item): ?>
        <a class="sidebar-link <?php echo $active_menu === $item['key'] ? 'active' : ''; ?>" href="<?php echo html_escape($item['href']); ?>">
            <i class="bi <?php echo $item['icon']; ?>"></i>
            <span><?php echo $item['label']; ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<aside class="app-sidebar desktop-only">
    <div class="sidebar-inner">
        <a class="sidebar-brand-link mb-2" href="<?php echo html_escape($index_page . '/dashboard'); ?>">
            <img
                src="<?php echo html_escape($brand_logo); ?>"
                alt="<?php echo html_escape($brand_name); ?> Logo"
                class="app-brand-logo"
                data-logo-light="<?php echo html_escape($brand_logo); ?>"
                data-logo-dark="<?php echo html_escape($brand_logo_dark); ?>"
            >
            <span class="app-brand-text"><?php echo html_escape($brand_name); ?></span>
        </a>
        <div class="sidebar-group mt-0">Main Menu</div>
        <?php foreach ($main_menu_items as $item): ?>
        <a class="sidebar-link <?php echo $active_menu === $item['key'] ? 'active' : ''; ?>" href="<?php echo html_escape($item['href']); ?>">
            <i class="bi <?php echo $item['icon']; ?>"></i>
            <span><?php echo $item['label']; ?></span>
        </a>
        <?php endforeach; ?>

        <div class="sidebar-group">Network</div>
        <?php foreach ($network_menu_items as $item): ?>
        <a class="sidebar-link <?php echo $active_menu === $item['key'] ? 'active' : ''; ?>" href="<?php echo html_escape($item['href']); ?>">
            <i class="bi <?php echo $item['icon']; ?>"></i>
            <span><?php echo $item['label']; ?></span>
        </a>
        <?php endforeach; ?>

        <div class="sidebar-group">System</div>
        <a class="sidebar-link" href="#settings"><i class="bi bi-sliders2"></i><span>Settings</span></a>
        <a class="sidebar-link" href="#logs"><i class="bi bi-journal-text"></i><span>Logs</span></a>
    </div>
</aside>
<?php endif; ?>
