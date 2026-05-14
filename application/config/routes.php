<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
|	example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|	https://codeigniter.com/userguide3/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are three reserved routes:
|
|	$route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|	$route['404_override'] = 'errors/page_missing';
|
| This route will tell the Router which controller/method to use if those
| provided in the URL cannot be matched to a valid route.
|
|	$route['translate_uri_dashes'] = FALSE;
|
| This is not exactly a route, but allows you to automatically route
| controller and method names that contain dashes. '-' isn't a valid
| class or method name character, so it requires translation.
| When you set this option to TRUE, it will replace ALL dashes in the
| controller and method URI segments.
|
| Examples:	my-controller/index	-> my_controller/index
|		my-controller/my-method	-> my_controller/my_method
*/
$route['default_controller'] = 'dashboard';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;

// Authentication routes
$route['auth'] = 'auth/login';
$route['auth/login'] = 'auth/login';
$route['auth/process_login'] = 'auth/process_login';
$route['auth/logout'] = 'auth/logout';

// Realtime notification routes
$route['notification/latest'] = 'notification/latest';
$route['notification/unread_count'] = 'notification/unread_count';
$route['notification/mark_read/(:num)'] = 'notification/mark_read/$1';
$route['notification/mark_all_read'] = 'notification/mark_all_read';
$route['notification/auth'] = 'notification/auth';

// Cron notification routes
$route['cron-notification/run-all'] = 'cronnotification/run_all';
$route['cron-notification/check-overdue-invoice'] = 'cronnotification/check_overdue_invoice';
$route['cron-notification/check-router-status'] = 'cronnotification/check_router_status';
$route['cron-notification/check-ticket-pending'] = 'cronnotification/check_ticket_pending';
$route['cron-notification/check-inventory-minimum'] = 'cronnotification/check_inventory_minimum';

// TR-069 API routes (machine-to-machine)
$route['api/ont/set-wifi'] = 'api/ont/set_wifi';
$route['api/ont/set_wifi'] = 'api/ont/set_wifi';
$route['api/ont/reboot'] = 'api/ont/reboot';
$route['api/ont/connected-devices'] = 'api/ont/connected_devices';
$route['api/ont/connected_devices'] = 'api/ont/connected_devices';

// UI routes
$route['dashboard'] = 'dashboard/index';
$route['dashboard/index'] = 'dashboard/index';
$route['dashboard/switch-router'] = 'dashboard/switch_router';
$route['dashboard/switch_router'] = 'dashboard/switch_router';
$route['dashboard/switch-router/(:any)'] = 'dashboard/switch_router/$1';
$route['dashboard/switch_router/(:any)'] = 'dashboard/switch_router/$1';
$route['system-monitoring'] = 'monitoring/index';
$route['system_monitoring'] = 'monitoring/index';
$route['monitoring'] = 'monitoring/index';
$route['monitoring/index'] = 'monitoring/index';
$route['monitoring/snapshot_json'] = 'monitoring/snapshot_json';
$route['monitoring/snapshot-json'] = 'monitoring/snapshot_json';
$route['monitoring/check_now'] = 'monitoring/check_now';
$route['monitoring/check-now'] = 'monitoring/check_now';
$route['monitoring/save-interface-config'] = 'monitoring/save_interface_config';
$route['monitoring/save_interface_config'] = 'monitoring/save_interface_config';

$route['customer'] = 'customers/index';
$route['customers'] = 'customers/index';
$route['customers/list'] = 'customers/index';
$route['customers/generate_credential'] = 'customers/generate_credential';
$route['customers/preview-remote-ip'] = 'customers/preview_remote_ip';
$route['customers/preview_remote_ip'] = 'customers/preview_remote_ip';
$route['customers/suggest-remote-ip'] = 'customers/suggest_remote_ip';
$route['customers/suggest_remote_ip'] = 'customers/suggest_remote_ip';
$route['customers/bulk_delete'] = 'customers/bulk_delete';
$route['customers/bulk-disable'] = 'customers/bulk_disable';
$route['customers/bulk_disable'] = 'customers/bulk_disable';
$route['customers/bulk-generate-invoice'] = 'customers/bulk_generate_invoice';
$route['customers/bulk_generate_invoice'] = 'customers/bulk_generate_invoice';
$route['customers/upgrade'] = 'CustomerUpgrade/index';
$route['customers/upgrade/index'] = 'CustomerUpgrade/index';
$route['customers/upgrade/show-form/(:num)'] = 'CustomerUpgrade/show_form/$1';
$route['customers/upgrade/show_form/(:num)'] = 'CustomerUpgrade/show_form/$1';
$route['customers/upgrade/calculate-prorate'] = 'CustomerUpgrade/calculate_prorate';
$route['customers/upgrade/calculate_prorate'] = 'CustomerUpgrade/calculate_prorate';
$route['customers/upgrade/process'] = 'CustomerUpgrade/process_upgrade';
$route['customers/upgrade/process_upgrade'] = 'CustomerUpgrade/process_upgrade';

$route['ppp-profiles'] = 'ppp_profiles/index';
$route['ppp_profiles'] = 'ppp_profiles/index';
$route['ppp-profiles/create'] = 'ppp_profiles/create';
$route['ppp_profiles/create'] = 'ppp_profiles/create';
$route['ppp-profiles/store'] = 'ppp_profiles/store';
$route['ppp_profiles/store'] = 'ppp_profiles/store';
$route['ppp-profiles/sync-from-router'] = 'ppp_profiles/sync_from_router';
$route['ppp_profiles/sync_from_router'] = 'ppp_profiles/sync_from_router';
$route['ppp-profiles/edit/(:num)'] = 'ppp_profiles/edit/$1';
$route['ppp_profiles/edit/(:num)'] = 'ppp_profiles/edit/$1';
$route['ppp-profiles/update/(:num)'] = 'ppp_profiles/update/$1';
$route['ppp_profiles/update/(:num)'] = 'ppp_profiles/update/$1';
$route['ppp-profiles/delete/(:num)'] = 'ppp_profiles/delete/$1';
$route['ppp_profiles/delete/(:num)'] = 'ppp_profiles/delete/$1';

$route['ip-pools'] = 'ip_pools/index';
$route['ip_pools'] = 'ip_pools/index';
$route['ip-pools/create'] = 'ip_pools/create';
$route['ip_pools/create'] = 'ip_pools/create';
$route['ip-pools/store'] = 'ip_pools/store';
$route['ip_pools/store'] = 'ip_pools/store';
$route['ip-pools/sync-from-router'] = 'ip_pools/sync_from_router';
$route['ip_pools/sync_from_router'] = 'ip_pools/sync_from_router';
$route['ip-pools/refresh-usage'] = 'ip_pools/refresh_usage';
$route['ip_pools/refresh_usage'] = 'ip_pools/refresh_usage';
$route['ip-pools/edit/(:num)'] = 'ip_pools/edit/$1';
$route['ip_pools/edit/(:num)'] = 'ip_pools/edit/$1';
$route['ip-pools/update/(:num)'] = 'ip_pools/update/$1';
$route['ip_pools/update/(:num)'] = 'ip_pools/update/$1';
$route['ip-pools/delete/(:num)'] = 'ip_pools/delete/$1';
$route['ip_pools/delete/(:num)'] = 'ip_pools/delete/$1';

$route['routers'] = 'routers/index';
$route['routers/list'] = 'routers/index';
$route['routers/create'] = 'routers/create';
$route['routers/store'] = 'routers/store';
$route['routers/edit/(:num)'] = 'routers/edit/$1';
$route['routers/update/(:num)'] = 'routers/update/$1';
$route['routers/delete/(:num)'] = 'routers/delete/$1';
$route['routers/test-connection/(:num)'] = 'routers/test_connection/$1';
$route['routers/test_connection/(:num)'] = 'routers/test_connection/$1';
$route['settings/routers'] = 'routers/index';
$route['settings/routers/create'] = 'routers/create';
$route['settings/routers/store'] = 'routers/store';
$route['settings/routers/edit/(:num)'] = 'routers/edit/$1';
$route['settings/routers/update/(:num)'] = 'routers/update/$1';
$route['settings/routers/delete/(:num)'] = 'routers/delete/$1';
$route['settings/routers/test-connection/(:num)'] = 'routers/test_connection/$1';
$route['settings/routers/test_connection/(:num)'] = 'routers/test_connection/$1';

$route['router-acs'] = 'routeracs/index';
$route['router_acs'] = 'routeracs/index';
$route['router-acs/edit/(:num)'] = 'routeracs/edit/$1';
$route['router_acs/edit/(:num)'] = 'routeracs/edit/$1';
$route['router-acs/update/(:num)'] = 'routeracs/update/$1';
$route['router_acs/update/(:num)'] = 'routeracs/update/$1';
$route['router-acs/test-connection/(:num)'] = 'routeracs/test_connection/$1';
$route['router_acs/test_connection/(:num)'] = 'routeracs/test_connection/$1';
$route['settings/router-acs'] = 'routeracs/index';
$route['settings/router_acs'] = 'routeracs/index';
$route['settings/router-acs/edit/(:num)'] = 'routeracs/edit/$1';
$route['settings/router_acs/edit/(:num)'] = 'routeracs/edit/$1';
$route['settings/router-acs/update/(:num)'] = 'routeracs/update/$1';
$route['settings/router_acs/update/(:num)'] = 'routeracs/update/$1';
$route['settings/router-acs/test-connection/(:num)'] = 'routeracs/test_connection/$1';
$route['settings/router_acs/test_connection/(:num)'] = 'routeracs/test_connection/$1';

$route['master-references'] = 'master_references/index';
$route['master_references'] = 'master_references/index';
$route['master-references/locations'] = 'master_references/locations';
$route['master_references/locations'] = 'master_references/locations';
$route['master-references/store-location'] = 'master_references/store_location';
$route['master_references/store_location'] = 'master_references/store_location';
$route['master-references/update-location/(:num)'] = 'master_references/update_location/$1';
$route['master_references/update_location/(:num)'] = 'master_references/update_location/$1';
$route['master-references/delete-location/(:num)'] = 'master_references/delete_location/$1';
$route['master_references/delete_location/(:num)'] = 'master_references/delete_location/$1';
$route['master-references/bulk-update-locations'] = 'master_references/bulk_update_locations';
$route['master_references/bulk_update_locations'] = 'master_references/bulk_update_locations';
$route['master-references/bulk-delete-locations'] = 'master_references/bulk_delete_locations';
$route['master_references/bulk_delete_locations'] = 'master_references/bulk_delete_locations';
$route['master-references/olts'] = 'master_references/olts';
$route['master_references/olts'] = 'master_references/olts';
$route['master-references/store-olt'] = 'master_references/store_olt';
$route['master_references/store_olt'] = 'master_references/store_olt';
$route['master-references/update-olt/(:num)'] = 'master_references/update_olt/$1';
$route['master_references/update_olt/(:num)'] = 'master_references/update_olt/$1';
$route['master-references/delete-olt/(:num)'] = 'master_references/delete_olt/$1';
$route['master_references/delete_olt/(:num)'] = 'master_references/delete_olt/$1';
$route['master-references/bulk-update-olts'] = 'master_references/bulk_update_olts';
$route['master_references/bulk_update_olts'] = 'master_references/bulk_update_olts';
$route['master-references/bulk-delete-olts'] = 'master_references/bulk_delete_olts';
$route['master_references/bulk_delete_olts'] = 'master_references/bulk_delete_olts';

$route['billing'] = 'billing_ui/index';
$route['billing/list'] = 'billing_ui/index';
$route['billing/generate-monthly'] = 'billing/generate_monthly_invoices';
$route['billing/auto-suspend'] = 'billing/auto_suspend';
$route['billing/record-payment'] = 'billing/record_payment';
$route['billing/manual-generate'] = 'billing/manual_generate_invoice';
$route['billing/manual_generate'] = 'billing/manual_generate_invoice';
$route['billing/manual-isolir'] = 'billing/manual_isolir';
$route['billing/manual_isolir'] = 'billing/manual_isolir';
$route['billing/view/(:num)'] = 'billing/view_invoice/$1';
$route['billing/edit/(:num)'] = 'billing/edit_invoice/$1';
$route['billing/update/(:num)'] = 'billing/update_invoice/$1';
$route['billing/mark-paid/(:num)'] = 'billing/mark_paid/$1';
$route['billing/mark_paid/(:num)'] = 'billing/mark_paid/$1';
$route['billing/mark-overdue/(:num)'] = 'billing/mark_overdue/$1';
$route['billing/mark_overdue/(:num)'] = 'billing/mark_overdue/$1';
$route['billing/delete/(:num)'] = 'billing/delete_invoice/$1';
$route['billing/delete_invoice/(:num)'] = 'billing/delete_invoice/$1';
$route['billing/bulk-action'] = 'billing/bulk_action';
$route['billing/bulk_action'] = 'billing/bulk_action';
$route['invoice'] = 'billing_ui/index';
$route['invoice/list'] = 'billing_ui/index';

$route['admin-whatsapp'] = 'admin_whatsapp/index';
$route['admin_whatsapp'] = 'admin_whatsapp/index';
$route['admin-whatsapp/detail/(:num)'] = 'admin_whatsapp/detail/$1';
$route['admin_whatsapp/detail/(:num)'] = 'admin_whatsapp/detail/$1';
$route['admin-whatsapp/resend/(:num)'] = 'admin_whatsapp/resend/$1';
$route['admin_whatsapp/resend/(:num)'] = 'admin_whatsapp/resend/$1';

$route['manual-isolir'] = 'manual_isolir/index';
$route['manual_isolir'] = 'manual_isolir/index';
$route['manual-isolir/popup'] = 'manual_isolir/popup';
$route['manual_isolir/popup'] = 'manual_isolir/popup';
$route['manual-isolir/suggest-user'] = 'manual_isolir/suggest_user';
$route['manual_isolir/suggest_user'] = 'manual_isolir/suggest_user';
$route['manual-isolir/isolate'] = 'manual_isolir/isolate_user';
$route['manual_isolir/isolate_user'] = 'manual_isolir/isolate_user';
$route['manual-isolir/release'] = 'manual_isolir/release_user';
$route['manual_isolir/release_user'] = 'manual_isolir/release_user';

$route['network/fiber-network-map'] = 'NetworkMap/index';
$route['network/fiber_network_map'] = 'NetworkMap/index';
$route['network-map'] = 'NetworkMap/index';
$route['network_map'] = 'NetworkMap/index';
$route['network/nodes'] = 'NetworkMap/nodes';
$route['network/node-management'] = 'NetworkMap/nodes';
$route['network/node_management'] = 'NetworkMap/nodes';

$route['api/network/map'] = 'NetworkMap/get_map_data';
$route['api/network/routers'] = 'NetworkMap/get_router_list';
$route['api/network/router/create'] = 'NetworkMap/create_router';
$route['api/network/router/update'] = 'NetworkMap/update_router';
$route['api/network/router/delete'] = 'NetworkMap/delete_router';
$route['api/network/olt/create'] = 'NetworkMap/create_olt';
$route['api/network/olt/update'] = 'NetworkMap/update_olt';
$route['api/network/olt/delete'] = 'NetworkMap/delete_olt';
$route['api/network/odc/create'] = 'NetworkMap/create_odc';
$route['api/network/odc/update'] = 'NetworkMap/update_odc';
$route['api/network/odc/delete'] = 'NetworkMap/delete_odc';
$route['api/network/odp/create'] = 'NetworkMap/create_odp';
$route['api/network/odp/update'] = 'NetworkMap/update_odp';
$route['api/network/odp/delete'] = 'NetworkMap/delete_odp';

$route['static-ip-sync'] = 'static_ip_sync/index';
$route['static_ip_sync'] = 'static_ip_sync/index';
$route['router-sync'] = 'static_ip_sync/index';
$route['router_sync'] = 'static_ip_sync/index';
$route['static-ip-sync/run-sync'] = 'static_ip_sync/run_sync';
$route['static_ip_sync/run_sync'] = 'static_ip_sync/run_sync';
$route['static-ip-sync/run-check-isolir'] = 'static_ip_sync/run_check_isolir';
$route['static_ip_sync/run_check_isolir'] = 'static_ip_sync/run_check_isolir';

$route['ont-remote'] = 'ont_remote/index';
$route['ont_remote'] = 'ont_remote/index';
$route['ont-remote/detail'] = 'ont_remote/detail';
$route['ont_remote/detail'] = 'ont_remote/detail';
$route['ont-remote/set-wifi'] = 'ont_remote/set_wifi';
$route['ont_remote/set_wifi'] = 'ont_remote/set_wifi';
$route['ont-remote/reboot'] = 'ont_remote/reboot';
$route['ont_remote/reboot'] = 'ont_remote/reboot';
$route['ont-remote/connected-devices'] = 'ont_remote/connected_devices';
$route['ont_remote/connected_devices'] = 'ont_remote/connected_devices';
$route['ont-remote/summary'] = 'ont_remote/summary';
$route['ont_remote/summary'] = 'ont_remote/summary';

// GenieACS ONT monitoring routes
$route['ont'] = 'ont/index';
$route['ont/index'] = 'ont/index';
$route['ont/online'] = 'ont/online';
$route['ont/offline'] = 'ont/offline';
$route['ont/index/(:num)'] = 'ont/index/$1';
$route['ont/online/(:num)'] = 'ont/online/$1';
$route['ont/offline/(:num)'] = 'ont/offline/$1';
$route['ont/detail/(:any)'] = 'ont/detail/$1';
$route['ont/reboot/(:any)'] = 'ont/reboot/$1';
$route['ont/set-wifi'] = 'ont/set_wifi';
$route['ont/set_wifi'] = 'ont/set_wifi';
$route['ont/sync'] = 'ont/sync';
$route['ont/sync/(:num)'] = 'ont/sync/$1';

$route['cashflow'] = 'cashflow_ui/index';
$route['cashflow/list'] = 'cashflow_ui/index';
$route['cashflow/add-expense'] = 'cashflow_ui/add_expense';
$route['cashflow/add_expense'] = 'cashflow_ui/add_expense';
$route['cashflow/add-income'] = 'cashflow_ui/add_income';
$route['cashflow/add_income'] = 'cashflow_ui/add_income';
$route['cashflow/bulk-action'] = 'cashflow_ui/bulk_action';
$route['cashflow/bulk_action'] = 'cashflow_ui/bulk_action';
$route['cashflow/update/(:num)'] = 'cashflow_ui/update/$1';
$route['cashflow/delete/(:num)'] = 'cashflow_ui/delete/$1';
$route['cashflow/review-request/(:num)'] = 'cashflow_ui/review_request/$1';
$route['cashflow/review_request/(:num)'] = 'cashflow_ui/review_request/$1';

$route['workorder'] = 'workorders/index';
$route['work_order'] = 'workorders/index';
$route['workorders'] = 'workorders/index';
$route['workorders/list'] = 'workorders/index';
$route['workorders/store'] = 'workorders/store';
$route['workorders/delete/(:num)'] = 'workorders/delete/$1';
$route['workorders/delete-wo/(:num)'] = 'workorders/delete/$1';
$route['workorders/mark_done/(:num)'] = 'workorders/mark_done/$1';
$route['workorders/mark-done/(:num)'] = 'workorders/mark_done/$1';

$route['helpdesk'] = 'helpdesk/index';
$route['helpdesk/index'] = 'helpdesk/index';
$route['helpdesk/dashboard'] = 'helpdesk_dashboard/index';
$route['helpdesk-dashboard'] = 'helpdesk_dashboard/index';
$route['helpdesk_dashboard'] = 'helpdesk_dashboard/index';
$route['helpdesk-dashboard/export-pdf'] = 'helpdesk_dashboard/export_pdf';
$route['helpdesk_dashboard/export_pdf'] = 'helpdesk_dashboard/export_pdf';
$route['helpdesk-dashboard/export-excel'] = 'helpdesk_dashboard/export_excel';
$route['helpdesk_dashboard/export_excel'] = 'helpdesk_dashboard/export_excel';
$route['teknisi-dashboard'] = 'teknisi_dashboard/index';
$route['teknisi_dashboard'] = 'teknisi_dashboard/index';
$route['teknisi/dashboard'] = 'teknisi_dashboard/index';
$route['teknisi-dashboard/export-pdf'] = 'teknisi_dashboard/export_pdf';
$route['teknisi_dashboard/export_pdf'] = 'teknisi_dashboard/export_pdf';
$route['helpdesk/create'] = 'helpdesk/create';
$route['helpdesk/store'] = 'helpdesk/store';
$route['helpdesk/detail/(:num)'] = 'helpdesk/detail/$1';
$route['helpdesk/customer-ppp/(:num)'] = 'helpdesk/customer_ppp_detail/$1';
$route['helpdesk/customer_ppp/(:num)'] = 'helpdesk/customer_ppp_detail/$1';
$route['helpdesk/customer_ppp_detail/(:num)'] = 'helpdesk/customer_ppp_detail/$1';
$route['helpdesk/update-status'] = 'helpdesk/update_status';
$route['helpdesk/update_status'] = 'helpdesk/update_status';
$route['helpdesk/mark-done/(:num)'] = 'helpdesk/mark_done/$1';
$route['helpdesk/mark_done/(:num)'] = 'helpdesk/mark_done/$1';
$route['helpdesk/add-reply/(:num)'] = 'helpdesk/add_reply/$1';
$route['helpdesk/add_reply/(:num)'] = 'helpdesk/add_reply/$1';
$route['helpdesk/upload-attachment/(:num)'] = 'helpdesk/upload_attachment/$1';
$route['helpdesk/upload_attachment/(:num)'] = 'helpdesk/upload_attachment/$1';
$route['helpdesk/delete/(:num)'] = 'helpdesk/delete/$1';

$route['helpdesk-report'] = 'helpdesk_report/index';
$route['helpdesk_report'] = 'helpdesk_report/index';
$route['helpdesk-report/export-pdf'] = 'helpdesk_report/export_pdf';
$route['helpdesk_report/export_pdf'] = 'helpdesk_report/export_pdf';

$route['helpdesk-cron/check-sla'] = 'helpdesk_cron/check_sla';
$route['helpdesk_cron/check_sla'] = 'helpdesk_cron/check_sla';

$route['tickets'] = 'tickets/index';
$route['tickets/list'] = 'tickets/index';
$route['tickets/store'] = 'tickets/store';
$route['tickets/mark_done/(:num)'] = 'tickets/mark_done/$1';
$route['tickets/mark-done/(:num)'] = 'tickets/mark_done/$1';
$route['helpdesk/list'] = 'helpdesk/index';

$route['user-logs'] = 'user_logs/index';
$route['user_logs'] = 'user_logs/index';

$route['pppoe-sync'] = 'settings/pppoe_sync';
$route['pppoe_sync'] = 'settings/pppoe_sync';
$route['pppoe-sync/save'] = 'settings/save_pppoe_sync';
$route['pppoe_sync/save'] = 'settings/save_pppoe_sync';
$route['pppoe-sync/sync-now'] = 'settings/sync_pppoe_now';
$route['pppoe-sync/sync-now/(:num)'] = 'settings/sync_pppoe_now/$1';
$route['pppoe_sync/sync_now'] = 'settings/sync_pppoe_now';
$route['pppoe_sync/sync_now/(:num)'] = 'settings/sync_pppoe_now/$1';
$route['pppoe-sync/migrate-customers'] = 'settings/migrate_ppp_secret';
$route['pppoe_sync/migrate_customers'] = 'settings/migrate_ppp_secret';

$route['static-packages'] = 'ppp_profiles/index';
$route['static_packages'] = 'ppp_profiles/index';
$route['static-packages/edit/(:num)'] = 'ppp_profiles/edit/$1';
$route['static_packages/edit/(:num)'] = 'ppp_profiles/edit/$1';
$route['static-packages/update/(:num)'] = 'ppp_profiles/update/$1';
$route['static_packages/update/(:num)'] = 'ppp_profiles/update/$1';

$route['settings'] = 'settings/index';
$route['settings/mikrotik'] = 'settings/mikrotik';
$route['settings/save_mikrotik'] = 'settings/save_mikrotik';
$route['settings/test_mikrotik'] = 'settings/test_mikrotik';
$route['settings/database'] = 'settings/database';
$route['settings/save_database'] = 'settings/save_database';
$route['settings/test_database'] = 'settings/test_database';
$route['settings/telegram'] = 'settings/telegram';
$route['settings/save_telegram'] = 'settings/save_telegram';
$route['settings/test_telegram'] = 'settings/test_telegram';
$route['settings/pppoe_sync'] = 'settings/pppoe_sync';
$route['settings/save_pppoe_sync'] = 'settings/save_pppoe_sync';
$route['settings/sync_pppoe'] = 'settings/sync_pppoe';
$route['settings/sync_pppoe_now'] = 'settings/sync_pppoe_now';
$route['settings/migrate_ppp_secret'] = 'settings/migrate_ppp_secret';
$route['settings/migrate-ppp-secret'] = 'settings/migrate_ppp_secret';

$route['users'] = 'users/index';
$route['users/create'] = 'users/create';
$route['users/store'] = 'users/store';
$route['users/edit/(:num)'] = 'users/edit/$1';
$route['users/update/(:num)'] = 'users/update/$1';
$route['users/delete/(:num)'] = 'users/delete/$1';

// Full auto provisioning
$route['provisioning/store'] = 'provisioning/store';
$route['telegram/webhook'] = 'telegram_webhook/index';

// Billing automation cron endpoint
$route['cron/generate-invoice'] = 'cron/billing_cron/generate_invoice';
$route['cron/auto-suspend'] = 'cron/billing_cron/auto_suspend';
$route['cron/sync-static-ip-arp'] = 'cron/static_ip_cron/sync_static_ip_arp';
$route['cron/sync_static_ip_arp'] = 'cron/static_ip_cron/sync_static_ip_arp';
$route['cron/check-static-isolir'] = 'cron/static_ip_cron/check_static_isolir';
$route['cron/check_static_isolir'] = 'cron/static_ip_cron/check_static_isolir';
$route['cron/monitoring-health'] = 'cron/monitoring_cron/check_health';
$route['cron/monitoring_health'] = 'cron/monitoring_cron/check_health';
