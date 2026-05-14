<?php
$brand_name = app_name();
$brand_logo = base_url(ltrim(app_logo_url(false), '/'));
$brand_logo_dark = base_url(ltrim(app_logo_url(true), '/'));
$active_menu = isset($active_menu) ? $active_menu : 'dashboard';
$role = strtolower(trim((string) $this->session->userdata('role')));
$is_superadmin = $role === 'superadmin';
$is_admin = $role === 'admin';
$is_teknisi = $role === 'teknisi';
$is_demo = $role === 'demo' || !empty($is_demo_mode);
$current_uri = strtolower(trim((string) uri_string(), '/'));

$can_customer = hasRole(array('superadmin', 'admin', 'demo'), $role);
$can_billing = hasRole(array('superadmin', 'admin', 'demo'), $role);
$can_workorders = hasRole(array('superadmin', 'admin', 'teknisi'), $role);
$can_helpdesk = hasRole(array('superadmin', 'admin', 'teknisi'), $role);
$can_cashflow = hasRole(array('superadmin', 'admin'), $role);
$can_router_management = hasRole(array('superadmin', 'admin'), $role);
$can_network = hasRole(array('superadmin', 'admin'), $role);
$can_settings = hasRole(array('superadmin'), $role);
$can_monitoring = hasRole(array('superadmin', 'admin', 'demo'), $role);

$dashboard_url = site_url('dashboard');
$dashboard_label = 'Dashboard';
if ($is_teknisi) {
    $dashboard_url = site_url('teknisi-dashboard');
    $dashboard_label = 'Dashboard Teknisi';
}
$dashboard_active = $active_menu === 'dashboard' || ($is_teknisi && $active_menu === 'teknisi_dashboard');

$is_settings_router_page = $active_menu === 'routers' || strpos($current_uri, 'settings/routers') === 0 || strpos($current_uri, 'routers') === 0;
$is_settings_router_acs_page = $active_menu === 'router_acs' || strpos($current_uri, 'settings/router-acs') === 0 || strpos($current_uri, 'settings/router_acs') === 0 || strpos($current_uri, 'router-acs') === 0 || strpos($current_uri, 'router_acs') === 0;
$is_settings_telegram_page = strpos($current_uri, 'settings/telegram') === 0;
$is_settings_database_page = strpos($current_uri, 'settings/database') === 0;
$settings_menu_active = $is_settings_router_page || $is_settings_router_acs_page || $is_settings_telegram_page || $is_settings_database_page;

$access_active = $can_customer && in_array($active_menu, array('customers', 'customer_upgrade', 'ppp_profiles', 'ip_pools'), true);
$operations_active = ($can_billing || $can_cashflow) && in_array($active_menu, array('billing', 'manual_isolir', 'pppoe_sync', 'static_ip_sync', 'router_sync', 'ont_remote', 'cashflow'), true);
$network_active = $can_network && in_array($active_menu, array('fiber_network_map', 'network_nodes', 'master_locations', 'master_olts'), true);
$support_active = ($can_workorders || $can_helpdesk || $can_monitoring) && in_array($active_menu, array('workorders', 'teknisi_dashboard', 'helpdesk', 'tickets', 'monitoring'), true);
$router_management_active = $can_router_management && ($is_settings_router_page || $is_settings_router_acs_page);
$system_active = in_array($active_menu, array('users', 'user_logs'), true);
$settings_active = $can_settings && $settings_menu_active;
$is_ont_scope_page = ($active_menu === 'ont_monitoring') || strpos($current_uri, 'ont') === 0;
$is_ont_online_page = strpos($current_uri, 'ont/online') === 0;
$is_ont_offline_page = strpos($current_uri, 'ont/offline') === 0;
$is_ont_list_page = $is_ont_scope_page && !$is_ont_online_page && !$is_ont_offline_page;
$ont_monitoring_active = $can_monitoring && $is_ont_scope_page;
$about_active = $active_menu === 'about_superapps' || strpos($current_uri, 'tentang-superapps') === 0 || strpos($current_uri, 'about-superapps') === 0 || strpos($current_uri, 'about') === 0;

$invoice_badge = 0;
$unpaid_badge = 0;
if (($can_billing || $can_cashflow) && isset($this->db) && is_object($this->db) && $this->db->table_exists('invoices')) {
    $invoice_fields = $this->db->list_fields('invoices');
    if (in_array('status', $invoice_fields, true)) {
        $router_id = 0;
        if (function_exists('active_router_id')) {
            $router_id = (int) active_router_id(0);
        }
        if ($router_id <= 0) {
            $router_id = (int) $this->session->userdata('active_router_id');
        }
        if ($router_id <= 0) {
            $router_id = (int) $this->session->userdata('dashboard_router_id');
        }
        if ($router_id <= 0) {
            $router_id = (int) $this->session->userdata('router_scope_id');
        }

        $period_month = date('Y-m');
        $period_date_col = '';
        foreach (array('period_month', 'billing_period_start', 'issue_date', 'due_date', 'created_at') as $candidate_col) {
            if (in_array($candidate_col, $invoice_fields, true)) {
                $period_date_col = $candidate_col;
                break;
            }
        }
        $qb_invoice = $this->db->from('invoices');
        if (in_array('router_id', $invoice_fields, true) && $router_id > 0) {
            $qb_invoice->where('router_id', $router_id);
        }
        if ($period_date_col === 'period_month') {
            $qb_invoice->where('period_month', $period_month);
        } elseif ($period_date_col !== '') {
            $qb_invoice->where("DATE_FORMAT({$period_date_col}, '%Y-%m') = " . $this->db->escape($period_month), null, false);
        }
        $invoice_badge = (int) $qb_invoice->count_all_results();

        $qb_unpaid = $this->db->from('invoices');
        $qb_unpaid->where("LOWER(status) IN ('issued','overdue','partially_paid','unpaid')", null, false);
        if (in_array('router_id', $invoice_fields, true) && $router_id > 0) {
            $qb_unpaid->where('router_id', $router_id);
        }
        $unpaid_badge = (int) $qb_unpaid->count_all_results();
    }
}
?>
<div class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">
    <div class="offcanvas-header border-bottom d-lg-none">
        <h5 class="offcanvas-title app-brand d-flex align-items-center gap-2" id="appSidebarLabel">
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
        <nav class="sidebar-nav">
            <a href="<?php echo $dashboard_url; ?>" class="sidebar-brand-link">
                <img
                    src="<?php echo html_escape($brand_logo); ?>"
                    alt="<?php echo html_escape($brand_name); ?> Logo"
                    class="app-brand-logo"
                    data-logo-light="<?php echo html_escape($brand_logo); ?>"
                    data-logo-dark="<?php echo html_escape($brand_logo_dark); ?>"
                >
                <span class="app-brand-text"><?php echo html_escape($brand_name); ?></span>
            </a>
            <div class="sidebar-group">
                <a href="<?php echo $dashboard_url; ?>" class="sidebar-link <?php echo $dashboard_active ? 'active' : ''; ?>">
                    <i class="ti ti-dashboard"></i>
                    <span><?php echo html_escape($dashboard_label); ?></span>
                </a>
            </div>

            <?php if ($can_customer): ?>
            <div class="sidebar-group">
                <button class="sidebar-section-toggle <?php echo $access_active ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarSectionAccess" aria-expanded="<?php echo $access_active ? 'true' : 'false'; ?>">
                    <span class="sidebar-section-title"><i class="ti ti-shield"></i><span>Access</span></span>
                    <i class="ti ti-chevron-down"></i>
                </button>
                <div id="sidebarSectionAccess" class="collapse <?php echo $access_active ? 'show' : ''; ?>">
                    <div class="sidebar-submenu">
                        <a href="<?php echo site_url('customers'); ?>" class="sidebar-link <?php echo $active_menu === 'customers' ? 'active' : ''; ?>"><i class="ti ti-users"></i><span>Customers</span></a>
                        <a href="<?php echo site_url('customers/upgrade'); ?>" class="sidebar-link <?php echo $active_menu === 'customer_upgrade' ? 'active' : ''; ?>"><i class="ti ti-arrow-up-circle"></i><span>Upgrade Paket</span></a>
                        <a href="<?php echo site_url('ppp-profiles'); ?>" class="sidebar-link <?php echo $active_menu === 'ppp_profiles' ? 'active' : ''; ?>"><i class="ti ti-package"></i><span>Service Plan</span></a>
                        <a href="<?php echo site_url('ip-pools'); ?>" class="sidebar-link <?php echo $active_menu === 'ip_pools' ? 'active' : ''; ?>"><i class="ti ti-network"></i><span>IP Pools</span></a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($can_billing || $can_cashflow): ?>
            <div class="sidebar-group">
                <button class="sidebar-section-toggle <?php echo $operations_active ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarSectionOperations" aria-expanded="<?php echo $operations_active ? 'true' : 'false'; ?>">
                    <span class="sidebar-section-title"><i class="ti ti-bolt"></i><span>Operations</span></span>
                    <i class="ti ti-chevron-down"></i>
                </button>
                <div id="sidebarSectionOperations" class="collapse <?php echo $operations_active ? 'show' : ''; ?>">
                    <div class="sidebar-submenu">
                        <?php if ($can_billing): ?>
                        <a href="<?php echo site_url('billing'); ?>" class="sidebar-link <?php echo $active_menu === 'billing' ? 'active' : ''; ?>">
                            <i class="ti ti-file-invoice"></i><span>Invoice</span>
                            <?php if ($invoice_badge > 0): ?><span class="badge bg-danger ms-auto"><?php echo (int) $invoice_badge; ?></span><?php endif; ?>
                        </a>
                        <a href="<?php echo site_url('manual-isolir'); ?>" class="sidebar-link <?php echo $active_menu === 'manual_isolir' ? 'active' : ''; ?>">
                            <i class="ti ti-shield-lock"></i><span>System Isolir Manual</span>
                            <?php if ($unpaid_badge > 0): ?><span class="badge bg-warning text-dark ms-auto"><?php echo (int) $unpaid_badge; ?></span><?php endif; ?>
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo site_url('router-sync'); ?>" class="sidebar-link <?php echo in_array($active_menu, array('router_sync', 'pppoe_sync', 'static_ip_sync'), true) ? 'active' : ''; ?>"><i class="ti ti-router"></i><span>Router Sync</span></a>
                        <?php if ($can_cashflow): ?>
                        <a href="<?php echo site_url('cashflow'); ?>" class="sidebar-link <?php echo $active_menu === 'cashflow' ? 'active' : ''; ?>"><i class="ti ti-credit-card"></i><span>Cashflow</span></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($can_network): ?>
            <div class="sidebar-group">
                <button class="sidebar-section-toggle <?php echo $network_active ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarSectionNetwork" aria-expanded="<?php echo $network_active ? 'true' : 'false'; ?>">
                    <span class="sidebar-section-title"><i class="ti ti-map-2"></i><span>Network</span></span>
                    <i class="ti ti-chevron-down"></i>
                </button>
                <div id="sidebarSectionNetwork" class="collapse <?php echo $network_active ? 'show' : ''; ?>">
                    <div class="sidebar-submenu">
                        <a href="<?php echo site_url('network/fiber-network-map'); ?>" class="sidebar-link <?php echo $active_menu === 'fiber_network_map' ? 'active' : ''; ?>">
                            <i class="ti ti-map-pin-2"></i><span>Fiber Network Map</span>
                        </a>
                        <a href="<?php echo site_url('network/nodes'); ?>" class="sidebar-link <?php echo $active_menu === 'network_nodes' ? 'active' : ''; ?>">
                            <i class="ti ti-sitemap"></i><span>Manajemen ODP/ODC</span>
                        </a>
                        <a href="<?php echo site_url('master-references/locations'); ?>" class="sidebar-link <?php echo $active_menu === 'master_locations' ? 'active' : ''; ?>">
                            <i class="ti ti-map-pin"></i><span>Master Lokasi</span>
                        </a>
                        <a href="<?php echo site_url('master-references/olts'); ?>" class="sidebar-link <?php echo $active_menu === 'master_olts' ? 'active' : ''; ?>">
                            <i class="ti ti-binary-tree-2"></i><span>Master OLT</span>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($can_workorders || $can_helpdesk || $can_monitoring): ?>
            <div class="sidebar-group">
                <button class="sidebar-section-toggle <?php echo $support_active ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarSectionSupport" aria-expanded="<?php echo $support_active ? 'true' : 'false'; ?>">
                    <span class="sidebar-section-title"><i class="ti ti-lifebuoy"></i><span>Support</span></span>
                    <i class="ti ti-chevron-down"></i>
                </button>
                <div id="sidebarSectionSupport" class="collapse <?php echo $support_active ? 'show' : ''; ?>">
                    <div class="sidebar-submenu">
                        <?php if ($can_workorders): ?>
                        <a href="<?php echo site_url('workorders'); ?>" class="sidebar-link <?php echo $active_menu === 'workorders' ? 'active' : ''; ?>"><i class="ti ti-tool"></i><span>Work Orders</span></a>
                        <?php endif; ?>
                        <?php if ($is_superadmin || $is_admin): ?>
                        <a href="<?php echo site_url('teknisi-dashboard'); ?>" class="sidebar-link <?php echo $active_menu === 'teknisi_dashboard' ? 'active' : ''; ?>"><i class="ti ti-chart-bar"></i><span>Dashboard Teknisi</span></a>
                        <?php endif; ?>
                        <?php if ($can_helpdesk): ?>
                        <a href="<?php echo site_url('helpdesk'); ?>" class="sidebar-link <?php echo in_array($active_menu, array('helpdesk', 'tickets'), true) ? 'active' : ''; ?>"><i class="ti ti-help-circle"></i><span>Helpdesk Tickets</span></a>
                        <?php endif; ?>
                        <?php if ($can_monitoring): ?>
                        <a href="<?php echo site_url('monitoring'); ?>" class="sidebar-link <?php echo $active_menu === 'monitoring' ? 'active' : ''; ?>"><i class="ti ti-device-desktop-analytics"></i><span>Monitoring</span></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($can_monitoring): ?>
            <div class="sidebar-group">
                <button class="sidebar-section-toggle <?php echo $ont_monitoring_active ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarSectionOntMonitoring" aria-expanded="<?php echo $ont_monitoring_active ? 'true' : 'false'; ?>">
                    <span class="sidebar-section-title"><i class="ti ti-router"></i><span>ONT Monitoring</span></span>
                    <i class="ti ti-chevron-down"></i>
                </button>
                <div id="sidebarSectionOntMonitoring" class="collapse <?php echo $ont_monitoring_active ? 'show' : ''; ?>">
                    <div class="sidebar-submenu">
                        <a href="<?php echo site_url('ont'); ?>" class="sidebar-link <?php echo $is_ont_list_page ? 'active' : ''; ?>"><i class="ti ti-list"></i><span>ONT List</span></a>
                        <a href="<?php echo site_url('ont/online'); ?>" class="sidebar-link <?php echo $is_ont_online_page ? 'active' : ''; ?>"><i class="ti ti-wifi"></i><span>ONT Online</span></a>
                        <a href="<?php echo site_url('ont/offline'); ?>" class="sidebar-link <?php echo $is_ont_offline_page ? 'active' : ''; ?>"><i class="ti ti-wifi-off"></i><span>ONT Offline</span></a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($can_router_management): ?>
            <div class="sidebar-group">
                <button class="sidebar-section-toggle <?php echo $router_management_active ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarSectionRouterManagement" aria-expanded="<?php echo $router_management_active ? 'true' : 'false'; ?>">
                    <span class="sidebar-section-title"><i class="ti ti-router"></i><span>Router Management</span></span>
                    <i class="ti ti-chevron-down"></i>
                </button>
                <div id="sidebarSectionRouterManagement" class="collapse <?php echo $router_management_active ? 'show' : ''; ?>">
                    <div class="sidebar-submenu">
                        <a href="<?php echo site_url('settings/routers'); ?>" class="sidebar-link <?php echo $is_settings_router_page ? 'active' : ''; ?>"><i class="ti ti-list-details"></i><span>Router List</span></a>
                        <a href="<?php echo site_url('settings/router-acs'); ?>" class="sidebar-link <?php echo $is_settings_router_acs_page ? 'active' : ''; ?>"><i class="ti ti-link"></i><span>Config ACS</span></a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($is_superadmin || $is_admin): ?>
            <div class="sidebar-group">
                <button class="sidebar-section-toggle <?php echo $system_active ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarSectionSystem" aria-expanded="<?php echo $system_active ? 'true' : 'false'; ?>">
                    <span class="sidebar-section-title"><i class="ti ti-settings-2"></i><span>System</span></span>
                    <i class="ti ti-chevron-down"></i>
                </button>
                <div id="sidebarSectionSystem" class="collapse <?php echo $system_active ? 'show' : ''; ?>">
                    <div class="sidebar-submenu">
                        <a href="<?php echo site_url('users'); ?>" class="sidebar-link <?php echo $active_menu === 'users' ? 'active' : ''; ?>"><i class="ti ti-user-cog"></i><span>User Management</span></a>
                        <a href="<?php echo site_url('user-logs'); ?>" class="sidebar-link <?php echo $active_menu === 'user_logs' ? 'active' : ''; ?>"><i class="ti ti-history"></i><span>User Logs</span></a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($can_settings): ?>
            <div class="sidebar-group">
                <button class="sidebar-section-toggle <?php echo $settings_active ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarSectionSettings" aria-expanded="<?php echo $settings_active ? 'true' : 'false'; ?>">
                    <span class="sidebar-section-title"><i class="ti ti-settings"></i><span>Settings</span></span>
                    <i class="ti ti-chevron-down"></i>
                </button>
                <div id="sidebarSectionSettings" class="collapse <?php echo $settings_active ? 'show' : ''; ?>">
                    <div class="sidebar-submenu">
                        <a href="<?php echo site_url('settings/telegram'); ?>" class="sidebar-link <?php echo $is_settings_telegram_page ? 'active' : ''; ?>"><i class="ti ti-brand-telegram"></i><span>Telegram</span></a>
                        <a href="<?php echo site_url('settings/database'); ?>" class="sidebar-link <?php echo $is_settings_database_page ? 'active' : ''; ?>"><i class="ti ti-database"></i><span>Database</span></a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="sidebar-group">
                <a href="<?php echo site_url('tentang-superapps'); ?>" class="sidebar-link <?php echo $about_active ? 'active' : ''; ?>">
                    <i class="ti ti-info-circle"></i>
                    <span>Tentang RTRWNet</span>
                </a>
            </div>
        </nav>
    </div>
</div>
