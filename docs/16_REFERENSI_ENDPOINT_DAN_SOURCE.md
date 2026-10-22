# Referensi endpoint dan inventaris source RTRWNet

Lampiran untuk [dokumentasi lengkap](13_DOKUMENTASI_LENGKAP_BILLING_RTRWNET.md). Dibangkitkan dari source oleh `python3 scripts/docs/generate_inventory.py`.

Revisi source dasar: `aadebf9b4cc34b13969989a765b542d3a278759c`. Inventaris membaca working tree saat generator berjalan. Tidak membaca `.env`, database, log runtime, atau kredensial.

## 1. Batas pembacaan

Daftar route menunjukkan pemetaan URI yang dideklarasikan, bukan jaminan endpoint berhasil dijalankan. HTTP method, autentikasi, role, validasi, dan efek samping ditentukan controller. Public method juga dapat berupa callback validasi/helper; keberadaannya bukan kontrak API publik. Route konvensional CI3 tetap berlaku di luar alias eksplisit.

## 2. Ukuran source

| Folder application | Jumlah file PHP |
| --- | ---: |
| `controllers/` | 51 |
| `models/` | 27 |
| `libraries/` | 28 |
| `helpers/` | 9 |
| `jobs/` | 5 |
| `migrations/` | 18 |
| `views/` | 90 |

## 3. Route eksplisit

`default_controller` dan `404_override` adalah konfigurasi khusus. `translate_uri_dashes = FALSE` di source; alias dengan tanda hubung yang ada di tabel dipetakan secara eksplisit.

| URI / konfigurasi | Target | Sumber |
| --- | --- | --- |
| `default_controller` | `dashboard` | [application/config/routes.php:52](../application/config/routes.php#L52) |
| `404_override` | `` | [application/config/routes.php:53](../application/config/routes.php#L53) |
| `auth` | `auth/login` | [application/config/routes.php:57](../application/config/routes.php#L57) |
| `auth/login` | `auth/login` | [application/config/routes.php:58](../application/config/routes.php#L58) |
| `auth/process_login` | `auth/process_login` | [application/config/routes.php:59](../application/config/routes.php#L59) |
| `auth/logout` | `auth/logout` | [application/config/routes.php:60](../application/config/routes.php#L60) |
| `notification/latest` | `notification/latest` | [application/config/routes.php:63](../application/config/routes.php#L63) |
| `notification/unread_count` | `notification/unread_count` | [application/config/routes.php:64](../application/config/routes.php#L64) |
| `notification/mark_read/(:num)` | `notification/mark_read/$1` | [application/config/routes.php:65](../application/config/routes.php#L65) |
| `notification/mark_all_read` | `notification/mark_all_read` | [application/config/routes.php:66](../application/config/routes.php#L66) |
| `notification/auth` | `notification/auth` | [application/config/routes.php:67](../application/config/routes.php#L67) |
| `cron-notification/run-all` | `cronnotification/run_all` | [application/config/routes.php:70](../application/config/routes.php#L70) |
| `cron-notification/check-overdue-invoice` | `cronnotification/check_overdue_invoice` | [application/config/routes.php:71](../application/config/routes.php#L71) |
| `cron-notification/check-router-status` | `cronnotification/check_router_status` | [application/config/routes.php:72](../application/config/routes.php#L72) |
| `cron-notification/check-ticket-pending` | `cronnotification/check_ticket_pending` | [application/config/routes.php:73](../application/config/routes.php#L73) |
| `cron-notification/check-inventory-minimum` | `cronnotification/check_inventory_minimum` | [application/config/routes.php:74](../application/config/routes.php#L74) |
| `api/ont/set-wifi` | `api/ont/set_wifi` | [application/config/routes.php:77](../application/config/routes.php#L77) |
| `api/ont/set_wifi` | `api/ont/set_wifi` | [application/config/routes.php:78](../application/config/routes.php#L78) |
| `api/ont/reboot` | `api/ont/reboot` | [application/config/routes.php:79](../application/config/routes.php#L79) |
| `api/ont/connected-devices` | `api/ont/connected_devices` | [application/config/routes.php:80](../application/config/routes.php#L80) |
| `api/ont/connected_devices` | `api/ont/connected_devices` | [application/config/routes.php:81](../application/config/routes.php#L81) |
| `dashboard` | `dashboard/index` | [application/config/routes.php:84](../application/config/routes.php#L84) |
| `dashboard/index` | `dashboard/index` | [application/config/routes.php:85](../application/config/routes.php#L85) |
| `dashboard/switch-router` | `dashboard/switch_router` | [application/config/routes.php:86](../application/config/routes.php#L86) |
| `dashboard/switch_router` | `dashboard/switch_router` | [application/config/routes.php:87](../application/config/routes.php#L87) |
| `dashboard/switch-router/(:any)` | `dashboard/switch_router/$1` | [application/config/routes.php:88](../application/config/routes.php#L88) |
| `dashboard/switch_router/(:any)` | `dashboard/switch_router/$1` | [application/config/routes.php:89](../application/config/routes.php#L89) |
| `system-monitoring` | `monitoring/index` | [application/config/routes.php:90](../application/config/routes.php#L90) |
| `system_monitoring` | `monitoring/index` | [application/config/routes.php:91](../application/config/routes.php#L91) |
| `monitoring` | `monitoring/index` | [application/config/routes.php:92](../application/config/routes.php#L92) |
| `monitoring/index` | `monitoring/index` | [application/config/routes.php:93](../application/config/routes.php#L93) |
| `monitoring/snapshot_json` | `monitoring/snapshot_json` | [application/config/routes.php:94](../application/config/routes.php#L94) |
| `monitoring/snapshot-json` | `monitoring/snapshot_json` | [application/config/routes.php:95](../application/config/routes.php#L95) |
| `monitoring/check_now` | `monitoring/check_now` | [application/config/routes.php:96](../application/config/routes.php#L96) |
| `monitoring/check-now` | `monitoring/check_now` | [application/config/routes.php:97](../application/config/routes.php#L97) |
| `monitoring/save-interface-config` | `monitoring/save_interface_config` | [application/config/routes.php:98](../application/config/routes.php#L98) |
| `monitoring/save_interface_config` | `monitoring/save_interface_config` | [application/config/routes.php:99](../application/config/routes.php#L99) |
| `customer` | `customers/index` | [application/config/routes.php:100](../application/config/routes.php#L100) |
| `customers` | `customers/index` | [application/config/routes.php:102](../application/config/routes.php#L102) |
| `customers/list` | `customers/index` | [application/config/routes.php:103](../application/config/routes.php#L103) |
| `customers/generate_credential` | `customers/generate_credential` | [application/config/routes.php:104](../application/config/routes.php#L104) |
| `customers/preview-remote-ip` | `customers/preview_remote_ip` | [application/config/routes.php:105](../application/config/routes.php#L105) |
| `customers/preview_remote_ip` | `customers/preview_remote_ip` | [application/config/routes.php:106](../application/config/routes.php#L106) |
| `customers/suggest-remote-ip` | `customers/suggest_remote_ip` | [application/config/routes.php:107](../application/config/routes.php#L107) |
| `customers/suggest_remote_ip` | `customers/suggest_remote_ip` | [application/config/routes.php:108](../application/config/routes.php#L108) |
| `customers/bulk_delete` | `customers/bulk_delete` | [application/config/routes.php:109](../application/config/routes.php#L109) |
| `customers/bulk-disable` | `customers/bulk_disable` | [application/config/routes.php:110](../application/config/routes.php#L110) |
| `customers/bulk_disable` | `customers/bulk_disable` | [application/config/routes.php:111](../application/config/routes.php#L111) |
| `customers/bulk-generate-invoice` | `customers/bulk_generate_invoice` | [application/config/routes.php:112](../application/config/routes.php#L112) |
| `customers/bulk_generate_invoice` | `customers/bulk_generate_invoice` | [application/config/routes.php:113](../application/config/routes.php#L113) |
| `customers/bulk-update-collection-method` | `customers/bulk_update_collection_method` | [application/config/routes.php:114](../application/config/routes.php#L114) |
| `customers/bulk_update_collection_method` | `customers/bulk_update_collection_method` | [application/config/routes.php:115](../application/config/routes.php#L115) |
| `customers/upgrade` | `CustomerUpgrade/index` | [application/config/routes.php:116](../application/config/routes.php#L116) |
| `customers/upgrade/index` | `CustomerUpgrade/index` | [application/config/routes.php:117](../application/config/routes.php#L117) |
| `customers/upgrade/show-form/(:num)` | `CustomerUpgrade/show_form/$1` | [application/config/routes.php:118](../application/config/routes.php#L118) |
| `customers/upgrade/show_form/(:num)` | `CustomerUpgrade/show_form/$1` | [application/config/routes.php:119](../application/config/routes.php#L119) |
| `customers/upgrade/calculate-prorate` | `CustomerUpgrade/calculate_prorate` | [application/config/routes.php:120](../application/config/routes.php#L120) |
| `customers/upgrade/calculate_prorate` | `CustomerUpgrade/calculate_prorate` | [application/config/routes.php:121](../application/config/routes.php#L121) |
| `customers/upgrade/process` | `CustomerUpgrade/process_upgrade` | [application/config/routes.php:122](../application/config/routes.php#L122) |
| `customers/upgrade/process_upgrade` | `CustomerUpgrade/process_upgrade` | [application/config/routes.php:123](../application/config/routes.php#L123) |
| `ppp-profiles` | `ppp_profiles/index` | [application/config/routes.php:124](../application/config/routes.php#L124) |
| `ppp_profiles` | `ppp_profiles/index` | [application/config/routes.php:126](../application/config/routes.php#L126) |
| `ppp-profiles/create` | `ppp_profiles/create` | [application/config/routes.php:127](../application/config/routes.php#L127) |
| `ppp_profiles/create` | `ppp_profiles/create` | [application/config/routes.php:128](../application/config/routes.php#L128) |
| `ppp-profiles/store` | `ppp_profiles/store` | [application/config/routes.php:129](../application/config/routes.php#L129) |
| `ppp_profiles/store` | `ppp_profiles/store` | [application/config/routes.php:130](../application/config/routes.php#L130) |
| `ppp-profiles/sync-from-router` | `ppp_profiles/sync_from_router` | [application/config/routes.php:131](../application/config/routes.php#L131) |
| `ppp_profiles/sync_from_router` | `ppp_profiles/sync_from_router` | [application/config/routes.php:132](../application/config/routes.php#L132) |
| `ppp-profiles/edit/(:num)` | `ppp_profiles/edit/$1` | [application/config/routes.php:133](../application/config/routes.php#L133) |
| `ppp_profiles/edit/(:num)` | `ppp_profiles/edit/$1` | [application/config/routes.php:134](../application/config/routes.php#L134) |
| `ppp-profiles/update/(:num)` | `ppp_profiles/update/$1` | [application/config/routes.php:135](../application/config/routes.php#L135) |
| `ppp_profiles/update/(:num)` | `ppp_profiles/update/$1` | [application/config/routes.php:136](../application/config/routes.php#L136) |
| `ppp-profiles/delete/(:num)` | `ppp_profiles/delete/$1` | [application/config/routes.php:137](../application/config/routes.php#L137) |
| `ppp_profiles/delete/(:num)` | `ppp_profiles/delete/$1` | [application/config/routes.php:138](../application/config/routes.php#L138) |
| `ip-pools` | `ip_pools/index` | [application/config/routes.php:139](../application/config/routes.php#L139) |
| `ip_pools` | `ip_pools/index` | [application/config/routes.php:141](../application/config/routes.php#L141) |
| `ip-pools/create` | `ip_pools/create` | [application/config/routes.php:142](../application/config/routes.php#L142) |
| `ip_pools/create` | `ip_pools/create` | [application/config/routes.php:143](../application/config/routes.php#L143) |
| `ip-pools/store` | `ip_pools/store` | [application/config/routes.php:144](../application/config/routes.php#L144) |
| `ip_pools/store` | `ip_pools/store` | [application/config/routes.php:145](../application/config/routes.php#L145) |
| `ip-pools/sync-from-router` | `ip_pools/sync_from_router` | [application/config/routes.php:146](../application/config/routes.php#L146) |
| `ip_pools/sync_from_router` | `ip_pools/sync_from_router` | [application/config/routes.php:147](../application/config/routes.php#L147) |
| `ip-pools/refresh-usage` | `ip_pools/refresh_usage` | [application/config/routes.php:148](../application/config/routes.php#L148) |
| `ip_pools/refresh_usage` | `ip_pools/refresh_usage` | [application/config/routes.php:149](../application/config/routes.php#L149) |
| `ip-pools/edit/(:num)` | `ip_pools/edit/$1` | [application/config/routes.php:150](../application/config/routes.php#L150) |
| `ip_pools/edit/(:num)` | `ip_pools/edit/$1` | [application/config/routes.php:151](../application/config/routes.php#L151) |
| `ip-pools/update/(:num)` | `ip_pools/update/$1` | [application/config/routes.php:152](../application/config/routes.php#L152) |
| `ip_pools/update/(:num)` | `ip_pools/update/$1` | [application/config/routes.php:153](../application/config/routes.php#L153) |
| `ip-pools/delete/(:num)` | `ip_pools/delete/$1` | [application/config/routes.php:154](../application/config/routes.php#L154) |
| `ip_pools/delete/(:num)` | `ip_pools/delete/$1` | [application/config/routes.php:155](../application/config/routes.php#L155) |
| `routers` | `routers/index` | [application/config/routes.php:156](../application/config/routes.php#L156) |
| `routers/list` | `routers/index` | [application/config/routes.php:158](../application/config/routes.php#L158) |
| `routers/create` | `routers/create` | [application/config/routes.php:159](../application/config/routes.php#L159) |
| `routers/store` | `routers/store` | [application/config/routes.php:160](../application/config/routes.php#L160) |
| `routers/edit/(:num)` | `routers/edit/$1` | [application/config/routes.php:161](../application/config/routes.php#L161) |
| `routers/update/(:num)` | `routers/update/$1` | [application/config/routes.php:162](../application/config/routes.php#L162) |
| `routers/delete/(:num)` | `routers/delete/$1` | [application/config/routes.php:163](../application/config/routes.php#L163) |
| `routers/test-connection/(:num)` | `routers/test_connection/$1` | [application/config/routes.php:164](../application/config/routes.php#L164) |
| `routers/test_connection/(:num)` | `routers/test_connection/$1` | [application/config/routes.php:165](../application/config/routes.php#L165) |
| `settings/routers` | `routers/index` | [application/config/routes.php:166](../application/config/routes.php#L166) |
| `settings/routers/create` | `routers/create` | [application/config/routes.php:167](../application/config/routes.php#L167) |
| `settings/routers/store` | `routers/store` | [application/config/routes.php:168](../application/config/routes.php#L168) |
| `settings/routers/edit/(:num)` | `routers/edit/$1` | [application/config/routes.php:169](../application/config/routes.php#L169) |
| `settings/routers/update/(:num)` | `routers/update/$1` | [application/config/routes.php:170](../application/config/routes.php#L170) |
| `settings/routers/delete/(:num)` | `routers/delete/$1` | [application/config/routes.php:171](../application/config/routes.php#L171) |
| `settings/routers/test-connection/(:num)` | `routers/test_connection/$1` | [application/config/routes.php:172](../application/config/routes.php#L172) |
| `settings/routers/test_connection/(:num)` | `routers/test_connection/$1` | [application/config/routes.php:173](../application/config/routes.php#L173) |
| `router-acs` | `routeracs/index` | [application/config/routes.php:174](../application/config/routes.php#L174) |
| `router_acs` | `routeracs/index` | [application/config/routes.php:176](../application/config/routes.php#L176) |
| `router-acs/edit/(:num)` | `routeracs/edit/$1` | [application/config/routes.php:177](../application/config/routes.php#L177) |
| `router_acs/edit/(:num)` | `routeracs/edit/$1` | [application/config/routes.php:178](../application/config/routes.php#L178) |
| `router-acs/update/(:num)` | `routeracs/update/$1` | [application/config/routes.php:179](../application/config/routes.php#L179) |
| `router_acs/update/(:num)` | `routeracs/update/$1` | [application/config/routes.php:180](../application/config/routes.php#L180) |
| `router-acs/test-connection/(:num)` | `routeracs/test_connection/$1` | [application/config/routes.php:181](../application/config/routes.php#L181) |
| `router_acs/test_connection/(:num)` | `routeracs/test_connection/$1` | [application/config/routes.php:182](../application/config/routes.php#L182) |
| `settings/router-acs` | `routeracs/index` | [application/config/routes.php:183](../application/config/routes.php#L183) |
| `settings/router_acs` | `routeracs/index` | [application/config/routes.php:184](../application/config/routes.php#L184) |
| `settings/router-acs/edit/(:num)` | `routeracs/edit/$1` | [application/config/routes.php:185](../application/config/routes.php#L185) |
| `settings/router_acs/edit/(:num)` | `routeracs/edit/$1` | [application/config/routes.php:186](../application/config/routes.php#L186) |
| `settings/router-acs/update/(:num)` | `routeracs/update/$1` | [application/config/routes.php:187](../application/config/routes.php#L187) |
| `settings/router_acs/update/(:num)` | `routeracs/update/$1` | [application/config/routes.php:188](../application/config/routes.php#L188) |
| `settings/router-acs/test-connection/(:num)` | `routeracs/test_connection/$1` | [application/config/routes.php:189](../application/config/routes.php#L189) |
| `settings/router_acs/test_connection/(:num)` | `routeracs/test_connection/$1` | [application/config/routes.php:190](../application/config/routes.php#L190) |
| `master-references` | `master_references/index` | [application/config/routes.php:191](../application/config/routes.php#L191) |
| `master_references` | `master_references/index` | [application/config/routes.php:193](../application/config/routes.php#L193) |
| `master-references/locations` | `master_references/locations` | [application/config/routes.php:194](../application/config/routes.php#L194) |
| `master_references/locations` | `master_references/locations` | [application/config/routes.php:195](../application/config/routes.php#L195) |
| `master-references/store-location` | `master_references/store_location` | [application/config/routes.php:196](../application/config/routes.php#L196) |
| `master_references/store_location` | `master_references/store_location` | [application/config/routes.php:197](../application/config/routes.php#L197) |
| `master-references/update-location/(:num)` | `master_references/update_location/$1` | [application/config/routes.php:198](../application/config/routes.php#L198) |
| `master_references/update_location/(:num)` | `master_references/update_location/$1` | [application/config/routes.php:199](../application/config/routes.php#L199) |
| `master-references/delete-location/(:num)` | `master_references/delete_location/$1` | [application/config/routes.php:200](../application/config/routes.php#L200) |
| `master_references/delete_location/(:num)` | `master_references/delete_location/$1` | [application/config/routes.php:201](../application/config/routes.php#L201) |
| `master-references/bulk-update-locations` | `master_references/bulk_update_locations` | [application/config/routes.php:202](../application/config/routes.php#L202) |
| `master_references/bulk_update_locations` | `master_references/bulk_update_locations` | [application/config/routes.php:203](../application/config/routes.php#L203) |
| `master-references/bulk-delete-locations` | `master_references/bulk_delete_locations` | [application/config/routes.php:204](../application/config/routes.php#L204) |
| `master_references/bulk_delete_locations` | `master_references/bulk_delete_locations` | [application/config/routes.php:205](../application/config/routes.php#L205) |
| `master-references/olts` | `master_references/olts` | [application/config/routes.php:206](../application/config/routes.php#L206) |
| `master_references/olts` | `master_references/olts` | [application/config/routes.php:207](../application/config/routes.php#L207) |
| `master-references/store-olt` | `master_references/store_olt` | [application/config/routes.php:208](../application/config/routes.php#L208) |
| `master_references/store_olt` | `master_references/store_olt` | [application/config/routes.php:209](../application/config/routes.php#L209) |
| `master-references/update-olt/(:num)` | `master_references/update_olt/$1` | [application/config/routes.php:210](../application/config/routes.php#L210) |
| `master_references/update_olt/(:num)` | `master_references/update_olt/$1` | [application/config/routes.php:211](../application/config/routes.php#L211) |
| `master-references/delete-olt/(:num)` | `master_references/delete_olt/$1` | [application/config/routes.php:212](../application/config/routes.php#L212) |
| `master_references/delete_olt/(:num)` | `master_references/delete_olt/$1` | [application/config/routes.php:213](../application/config/routes.php#L213) |
| `master-references/bulk-update-olts` | `master_references/bulk_update_olts` | [application/config/routes.php:214](../application/config/routes.php#L214) |
| `master_references/bulk_update_olts` | `master_references/bulk_update_olts` | [application/config/routes.php:215](../application/config/routes.php#L215) |
| `master-references/bulk-delete-olts` | `master_references/bulk_delete_olts` | [application/config/routes.php:216](../application/config/routes.php#L216) |
| `master_references/bulk_delete_olts` | `master_references/bulk_delete_olts` | [application/config/routes.php:217](../application/config/routes.php#L217) |
| `billing` | `billing_ui/index` | [application/config/routes.php:218](../application/config/routes.php#L218) |
| `billing/list` | `billing_ui/index` | [application/config/routes.php:220](../application/config/routes.php#L220) |
| `billing/generate-monthly` | `billing/generate_monthly_invoices` | [application/config/routes.php:221](../application/config/routes.php#L221) |
| `billing/auto-suspend` | `billing/auto_suspend` | [application/config/routes.php:222](../application/config/routes.php#L222) |
| `billing/record-payment` | `billing/record_payment` | [application/config/routes.php:223](../application/config/routes.php#L223) |
| `billing/manual-generate` | `billing/manual_generate_invoice` | [application/config/routes.php:224](../application/config/routes.php#L224) |
| `billing/manual_generate` | `billing/manual_generate_invoice` | [application/config/routes.php:225](../application/config/routes.php#L225) |
| `billing/manual-isolir` | `billing/manual_isolir` | [application/config/routes.php:226](../application/config/routes.php#L226) |
| `billing/manual_isolir` | `billing/manual_isolir` | [application/config/routes.php:227](../application/config/routes.php#L227) |
| `billing/acs-gap` | `billing/acs_gap` | [application/config/routes.php:228](../application/config/routes.php#L228) |
| `billing/acs_gap` | `billing/acs_gap` | [application/config/routes.php:229](../application/config/routes.php#L229) |
| `billing/view/(:num)` | `billing/view_invoice/$1` | [application/config/routes.php:230](../application/config/routes.php#L230) |
| `billing/edit/(:num)` | `billing/edit_invoice/$1` | [application/config/routes.php:231](../application/config/routes.php#L231) |
| `billing/update/(:num)` | `billing/update_invoice/$1` | [application/config/routes.php:232](../application/config/routes.php#L232) |
| `billing/mark-paid/(:num)` | `billing/mark_paid/$1` | [application/config/routes.php:233](../application/config/routes.php#L233) |
| `billing/mark_paid/(:num)` | `billing/mark_paid/$1` | [application/config/routes.php:234](../application/config/routes.php#L234) |
| `billing/mark-overdue/(:num)` | `billing/mark_overdue/$1` | [application/config/routes.php:235](../application/config/routes.php#L235) |
| `billing/mark_overdue/(:num)` | `billing/mark_overdue/$1` | [application/config/routes.php:236](../application/config/routes.php#L236) |
| `billing/delete-ont/(:num)` | `billing/delete_ont/$1` | [application/config/routes.php:237](../application/config/routes.php#L237) |
| `billing/delete_ont/(:num)` | `billing/delete_ont/$1` | [application/config/routes.php:238](../application/config/routes.php#L238) |
| `billing/delete/(:num)` | `billing/delete_invoice/$1` | [application/config/routes.php:239](../application/config/routes.php#L239) |
| `billing/delete_invoice/(:num)` | `billing/delete_invoice/$1` | [application/config/routes.php:240](../application/config/routes.php#L240) |
| `billing/bulk-action` | `billing/bulk_action` | [application/config/routes.php:241](../application/config/routes.php#L241) |
| `billing/bulk_action` | `billing/bulk_action` | [application/config/routes.php:242](../application/config/routes.php#L242) |
| `invoice` | `billing_ui/index` | [application/config/routes.php:243](../application/config/routes.php#L243) |
| `invoice/list` | `billing_ui/index` | [application/config/routes.php:244](../application/config/routes.php#L244) |
| `admin-whatsapp` | `admin_whatsapp/index` | [application/config/routes.php:245](../application/config/routes.php#L245) |
| `admin_whatsapp` | `admin_whatsapp/index` | [application/config/routes.php:247](../application/config/routes.php#L247) |
| `admin-whatsapp/detail/(:num)` | `admin_whatsapp/detail/$1` | [application/config/routes.php:248](../application/config/routes.php#L248) |
| `admin_whatsapp/detail/(:num)` | `admin_whatsapp/detail/$1` | [application/config/routes.php:249](../application/config/routes.php#L249) |
| `admin-whatsapp/resend/(:num)` | `admin_whatsapp/resend/$1` | [application/config/routes.php:250](../application/config/routes.php#L250) |
| `admin_whatsapp/resend/(:num)` | `admin_whatsapp/resend/$1` | [application/config/routes.php:251](../application/config/routes.php#L251) |
| `manual-isolir` | `manual_isolir/index` | [application/config/routes.php:252](../application/config/routes.php#L252) |
| `manual_isolir` | `manual_isolir/index` | [application/config/routes.php:254](../application/config/routes.php#L254) |
| `manual-isolir/popup` | `manual_isolir/popup` | [application/config/routes.php:255](../application/config/routes.php#L255) |
| `manual_isolir/popup` | `manual_isolir/popup` | [application/config/routes.php:256](../application/config/routes.php#L256) |
| `manual-isolir/suggest-user` | `manual_isolir/suggest_user` | [application/config/routes.php:257](../application/config/routes.php#L257) |
| `manual_isolir/suggest_user` | `manual_isolir/suggest_user` | [application/config/routes.php:258](../application/config/routes.php#L258) |
| `manual-isolir/isolate` | `manual_isolir/isolate_user` | [application/config/routes.php:259](../application/config/routes.php#L259) |
| `manual_isolir/isolate_user` | `manual_isolir/isolate_user` | [application/config/routes.php:260](../application/config/routes.php#L260) |
| `manual-isolir/release` | `manual_isolir/release_user` | [application/config/routes.php:261](../application/config/routes.php#L261) |
| `manual_isolir/release_user` | `manual_isolir/release_user` | [application/config/routes.php:262](../application/config/routes.php#L262) |
| `network/fiber-network-map` | `NetworkMap/index` | [application/config/routes.php:263](../application/config/routes.php#L263) |
| `network/fiber_network_map` | `NetworkMap/index` | [application/config/routes.php:265](../application/config/routes.php#L265) |
| `network-map` | `NetworkMap/index` | [application/config/routes.php:266](../application/config/routes.php#L266) |
| `network_map` | `NetworkMap/index` | [application/config/routes.php:267](../application/config/routes.php#L267) |
| `network/nodes` | `NetworkMap/nodes` | [application/config/routes.php:268](../application/config/routes.php#L268) |
| `network/node-management` | `NetworkMap/nodes` | [application/config/routes.php:269](../application/config/routes.php#L269) |
| `network/node_management` | `NetworkMap/nodes` | [application/config/routes.php:270](../application/config/routes.php#L270) |
| `api/network/map` | `NetworkMap/get_map_data` | [application/config/routes.php:271](../application/config/routes.php#L271) |
| `api/network/routers` | `NetworkMap/get_router_list` | [application/config/routes.php:273](../application/config/routes.php#L273) |
| `api/network/router/create` | `NetworkMap/create_router` | [application/config/routes.php:274](../application/config/routes.php#L274) |
| `api/network/router/update` | `NetworkMap/update_router` | [application/config/routes.php:275](../application/config/routes.php#L275) |
| `api/network/router/delete` | `NetworkMap/delete_router` | [application/config/routes.php:276](../application/config/routes.php#L276) |
| `api/network/olt/create` | `NetworkMap/create_olt` | [application/config/routes.php:277](../application/config/routes.php#L277) |
| `api/network/olt/update` | `NetworkMap/update_olt` | [application/config/routes.php:278](../application/config/routes.php#L278) |
| `api/network/olt/delete` | `NetworkMap/delete_olt` | [application/config/routes.php:279](../application/config/routes.php#L279) |
| `api/network/odc/create` | `NetworkMap/create_odc` | [application/config/routes.php:280](../application/config/routes.php#L280) |
| `api/network/odc/update` | `NetworkMap/update_odc` | [application/config/routes.php:281](../application/config/routes.php#L281) |
| `api/network/odc/delete` | `NetworkMap/delete_odc` | [application/config/routes.php:282](../application/config/routes.php#L282) |
| `api/network/odp/create` | `NetworkMap/create_odp` | [application/config/routes.php:283](../application/config/routes.php#L283) |
| `api/network/odp/update` | `NetworkMap/update_odp` | [application/config/routes.php:284](../application/config/routes.php#L284) |
| `api/network/odp/delete` | `NetworkMap/delete_odp` | [application/config/routes.php:285](../application/config/routes.php#L285) |
| `static-ip-sync` | `static_ip_sync/index` | [application/config/routes.php:286](../application/config/routes.php#L286) |
| `static_ip_sync` | `static_ip_sync/index` | [application/config/routes.php:288](../application/config/routes.php#L288) |
| `router-sync` | `static_ip_sync/index` | [application/config/routes.php:289](../application/config/routes.php#L289) |
| `router_sync` | `static_ip_sync/index` | [application/config/routes.php:290](../application/config/routes.php#L290) |
| `static-ip-sync/run-sync` | `static_ip_sync/run_sync` | [application/config/routes.php:291](../application/config/routes.php#L291) |
| `static_ip_sync/run_sync` | `static_ip_sync/run_sync` | [application/config/routes.php:292](../application/config/routes.php#L292) |
| `static-ip-sync/run-check-isolir` | `static_ip_sync/run_check_isolir` | [application/config/routes.php:293](../application/config/routes.php#L293) |
| `static_ip_sync/run_check_isolir` | `static_ip_sync/run_check_isolir` | [application/config/routes.php:294](../application/config/routes.php#L294) |
| `hotspot` | `hotspot/index` | [application/config/routes.php:295](../application/config/routes.php#L295) |
| `hotspot/index` | `hotspot/index` | [application/config/routes.php:297](../application/config/routes.php#L297) |
| `hotspot/add-user` | `hotspot/add_user` | [application/config/routes.php:298](../application/config/routes.php#L298) |
| `hotspot/add_user` | `hotspot/add_user` | [application/config/routes.php:299](../application/config/routes.php#L299) |
| `hotspot/generate-users` | `hotspot/generate_users` | [application/config/routes.php:300](../application/config/routes.php#L300) |
| `hotspot/generate_users` | `hotspot/generate_users` | [application/config/routes.php:301](../application/config/routes.php#L301) |
| `hotspot/add-profile` | `hotspot/add_profile` | [application/config/routes.php:302](../application/config/routes.php#L302) |
| `hotspot/add_profile` | `hotspot/add_profile` | [application/config/routes.php:303](../application/config/routes.php#L303) |
| `ont-remote` | `ont_remote/index` | [application/config/routes.php:304](../application/config/routes.php#L304) |
| `ont_remote` | `ont_remote/index` | [application/config/routes.php:306](../application/config/routes.php#L306) |
| `ont-remote/detail` | `ont_remote/detail` | [application/config/routes.php:307](../application/config/routes.php#L307) |
| `ont_remote/detail` | `ont_remote/detail` | [application/config/routes.php:308](../application/config/routes.php#L308) |
| `ont-remote/set-wifi` | `ont_remote/set_wifi` | [application/config/routes.php:309](../application/config/routes.php#L309) |
| `ont_remote/set_wifi` | `ont_remote/set_wifi` | [application/config/routes.php:310](../application/config/routes.php#L310) |
| `ont-remote/reboot` | `ont_remote/reboot` | [application/config/routes.php:311](../application/config/routes.php#L311) |
| `ont_remote/reboot` | `ont_remote/reboot` | [application/config/routes.php:312](../application/config/routes.php#L312) |
| `ont-remote/connected-devices` | `ont_remote/connected_devices` | [application/config/routes.php:313](../application/config/routes.php#L313) |
| `ont_remote/connected_devices` | `ont_remote/connected_devices` | [application/config/routes.php:314](../application/config/routes.php#L314) |
| `ont-remote/summary` | `ont_remote/summary` | [application/config/routes.php:315](../application/config/routes.php#L315) |
| `ont_remote/summary` | `ont_remote/summary` | [application/config/routes.php:316](../application/config/routes.php#L316) |
| `ont` | `ont/index` | [application/config/routes.php:319](../application/config/routes.php#L319) |
| `ont/index` | `ont/index` | [application/config/routes.php:320](../application/config/routes.php#L320) |
| `ont/online` | `ont/online` | [application/config/routes.php:321](../application/config/routes.php#L321) |
| `ont/offline` | `ont/offline` | [application/config/routes.php:322](../application/config/routes.php#L322) |
| `ont/index/(:num)` | `ont/index/$1` | [application/config/routes.php:323](../application/config/routes.php#L323) |
| `ont/online/(:num)` | `ont/online/$1` | [application/config/routes.php:324](../application/config/routes.php#L324) |
| `ont/offline/(:num)` | `ont/offline/$1` | [application/config/routes.php:325](../application/config/routes.php#L325) |
| `ont/detail/(:any)` | `ont/detail/$1` | [application/config/routes.php:326](../application/config/routes.php#L326) |
| `ont/reboot/(:any)` | `ont/reboot/$1` | [application/config/routes.php:327](../application/config/routes.php#L327) |
| `ont/set-wifi` | `ont/set_wifi` | [application/config/routes.php:328](../application/config/routes.php#L328) |
| `ont/set_wifi` | `ont/set_wifi` | [application/config/routes.php:329](../application/config/routes.php#L329) |
| `ont/sync` | `ont/sync` | [application/config/routes.php:330](../application/config/routes.php#L330) |
| `ont/sync/(:num)` | `ont/sync/$1` | [application/config/routes.php:331](../application/config/routes.php#L331) |
| `cashflow` | `cashflow_ui/index` | [application/config/routes.php:332](../application/config/routes.php#L332) |
| `cashflow/list` | `cashflow_ui/index` | [application/config/routes.php:334](../application/config/routes.php#L334) |
| `cashflow/add-expense` | `cashflow_ui/add_expense` | [application/config/routes.php:335](../application/config/routes.php#L335) |
| `cashflow/add_expense` | `cashflow_ui/add_expense` | [application/config/routes.php:336](../application/config/routes.php#L336) |
| `cashflow/add-income` | `cashflow_ui/add_income` | [application/config/routes.php:337](../application/config/routes.php#L337) |
| `cashflow/add_income` | `cashflow_ui/add_income` | [application/config/routes.php:338](../application/config/routes.php#L338) |
| `cashflow/bulk-action` | `cashflow_ui/bulk_action` | [application/config/routes.php:339](../application/config/routes.php#L339) |
| `cashflow/bulk_action` | `cashflow_ui/bulk_action` | [application/config/routes.php:340](../application/config/routes.php#L340) |
| `cashflow/update/(:num)` | `cashflow_ui/update/$1` | [application/config/routes.php:341](../application/config/routes.php#L341) |
| `cashflow/delete/(:num)` | `cashflow_ui/delete/$1` | [application/config/routes.php:342](../application/config/routes.php#L342) |
| `cashflow/review-request/(:num)` | `cashflow_ui/review_request/$1` | [application/config/routes.php:343](../application/config/routes.php#L343) |
| `cashflow/review_request/(:num)` | `cashflow_ui/review_request/$1` | [application/config/routes.php:344](../application/config/routes.php#L344) |
| `workorder` | `workorders/index` | [application/config/routes.php:345](../application/config/routes.php#L345) |
| `work_order` | `workorders/index` | [application/config/routes.php:347](../application/config/routes.php#L347) |
| `workorders` | `workorders/index` | [application/config/routes.php:348](../application/config/routes.php#L348) |
| `workorders/list` | `workorders/index` | [application/config/routes.php:349](../application/config/routes.php#L349) |
| `workorders/store` | `workorders/store` | [application/config/routes.php:350](../application/config/routes.php#L350) |
| `workorders/delete/(:num)` | `workorders/delete/$1` | [application/config/routes.php:351](../application/config/routes.php#L351) |
| `workorders/delete-wo/(:num)` | `workorders/delete/$1` | [application/config/routes.php:352](../application/config/routes.php#L352) |
| `workorders/mark_done/(:num)` | `workorders/mark_done/$1` | [application/config/routes.php:353](../application/config/routes.php#L353) |
| `workorders/mark-done/(:num)` | `workorders/mark_done/$1` | [application/config/routes.php:354](../application/config/routes.php#L354) |
| `helpdesk` | `helpdesk/index` | [application/config/routes.php:355](../application/config/routes.php#L355) |
| `helpdesk/index` | `helpdesk/index` | [application/config/routes.php:357](../application/config/routes.php#L357) |
| `helpdesk/dashboard` | `helpdesk_dashboard/index` | [application/config/routes.php:358](../application/config/routes.php#L358) |
| `helpdesk-dashboard` | `helpdesk_dashboard/index` | [application/config/routes.php:359](../application/config/routes.php#L359) |
| `helpdesk_dashboard` | `helpdesk_dashboard/index` | [application/config/routes.php:360](../application/config/routes.php#L360) |
| `helpdesk-dashboard/export-pdf` | `helpdesk_dashboard/export_pdf` | [application/config/routes.php:361](../application/config/routes.php#L361) |
| `helpdesk_dashboard/export_pdf` | `helpdesk_dashboard/export_pdf` | [application/config/routes.php:362](../application/config/routes.php#L362) |
| `helpdesk-dashboard/export-excel` | `helpdesk_dashboard/export_excel` | [application/config/routes.php:363](../application/config/routes.php#L363) |
| `helpdesk_dashboard/export_excel` | `helpdesk_dashboard/export_excel` | [application/config/routes.php:364](../application/config/routes.php#L364) |
| `teknisi-dashboard` | `teknisi_dashboard/index` | [application/config/routes.php:365](../application/config/routes.php#L365) |
| `teknisi_dashboard` | `teknisi_dashboard/index` | [application/config/routes.php:366](../application/config/routes.php#L366) |
| `teknisi/dashboard` | `teknisi_dashboard/index` | [application/config/routes.php:367](../application/config/routes.php#L367) |
| `teknisi-dashboard/export-pdf` | `teknisi_dashboard/export_pdf` | [application/config/routes.php:368](../application/config/routes.php#L368) |
| `teknisi_dashboard/export_pdf` | `teknisi_dashboard/export_pdf` | [application/config/routes.php:369](../application/config/routes.php#L369) |
| `helpdesk/create` | `helpdesk/create` | [application/config/routes.php:370](../application/config/routes.php#L370) |
| `helpdesk/store` | `helpdesk/store` | [application/config/routes.php:371](../application/config/routes.php#L371) |
| `helpdesk/detail/(:num)` | `helpdesk/detail/$1` | [application/config/routes.php:372](../application/config/routes.php#L372) |
| `helpdesk/customer-ppp/(:num)` | `helpdesk/customer_ppp_detail/$1` | [application/config/routes.php:373](../application/config/routes.php#L373) |
| `helpdesk/customer_ppp/(:num)` | `helpdesk/customer_ppp_detail/$1` | [application/config/routes.php:374](../application/config/routes.php#L374) |
| `helpdesk/customer_ppp_detail/(:num)` | `helpdesk/customer_ppp_detail/$1` | [application/config/routes.php:375](../application/config/routes.php#L375) |
| `helpdesk/update-status` | `helpdesk/update_status` | [application/config/routes.php:376](../application/config/routes.php#L376) |
| `helpdesk/update_status` | `helpdesk/update_status` | [application/config/routes.php:377](../application/config/routes.php#L377) |
| `helpdesk/mark-done/(:num)` | `helpdesk/mark_done/$1` | [application/config/routes.php:378](../application/config/routes.php#L378) |
| `helpdesk/mark_done/(:num)` | `helpdesk/mark_done/$1` | [application/config/routes.php:379](../application/config/routes.php#L379) |
| `helpdesk/add-reply/(:num)` | `helpdesk/add_reply/$1` | [application/config/routes.php:380](../application/config/routes.php#L380) |
| `helpdesk/add_reply/(:num)` | `helpdesk/add_reply/$1` | [application/config/routes.php:381](../application/config/routes.php#L381) |
| `helpdesk/upload-attachment/(:num)` | `helpdesk/upload_attachment/$1` | [application/config/routes.php:382](../application/config/routes.php#L382) |
| `helpdesk/upload_attachment/(:num)` | `helpdesk/upload_attachment/$1` | [application/config/routes.php:383](../application/config/routes.php#L383) |
| `helpdesk/delete/(:num)` | `helpdesk/delete/$1` | [application/config/routes.php:384](../application/config/routes.php#L384) |
| `helpdesk-report` | `helpdesk_report/index` | [application/config/routes.php:385](../application/config/routes.php#L385) |
| `helpdesk_report` | `helpdesk_report/index` | [application/config/routes.php:387](../application/config/routes.php#L387) |
| `helpdesk-report/export-pdf` | `helpdesk_report/export_pdf` | [application/config/routes.php:388](../application/config/routes.php#L388) |
| `helpdesk_report/export_pdf` | `helpdesk_report/export_pdf` | [application/config/routes.php:389](../application/config/routes.php#L389) |
| `helpdesk-cron/check-sla` | `helpdesk_cron/check_sla` | [application/config/routes.php:390](../application/config/routes.php#L390) |
| `helpdesk_cron/check_sla` | `helpdesk_cron/check_sla` | [application/config/routes.php:392](../application/config/routes.php#L392) |
| `tickets` | `tickets/index` | [application/config/routes.php:393](../application/config/routes.php#L393) |
| `tickets/list` | `tickets/index` | [application/config/routes.php:395](../application/config/routes.php#L395) |
| `tickets/store` | `tickets/store` | [application/config/routes.php:396](../application/config/routes.php#L396) |
| `tickets/mark_done/(:num)` | `tickets/mark_done/$1` | [application/config/routes.php:397](../application/config/routes.php#L397) |
| `tickets/mark-done/(:num)` | `tickets/mark_done/$1` | [application/config/routes.php:398](../application/config/routes.php#L398) |
| `helpdesk/list` | `helpdesk/index` | [application/config/routes.php:399](../application/config/routes.php#L399) |
| `user-logs` | `user_logs/index` | [application/config/routes.php:400](../application/config/routes.php#L400) |
| `user_logs` | `user_logs/index` | [application/config/routes.php:402](../application/config/routes.php#L402) |
| `pppoe-sync` | `settings/pppoe_sync` | [application/config/routes.php:403](../application/config/routes.php#L403) |
| `pppoe_sync` | `settings/pppoe_sync` | [application/config/routes.php:405](../application/config/routes.php#L405) |
| `pppoe-sync/save` | `settings/save_pppoe_sync` | [application/config/routes.php:406](../application/config/routes.php#L406) |
| `pppoe_sync/save` | `settings/save_pppoe_sync` | [application/config/routes.php:407](../application/config/routes.php#L407) |
| `pppoe-sync/sync-now` | `settings/sync_pppoe_now` | [application/config/routes.php:408](../application/config/routes.php#L408) |
| `pppoe-sync/sync-now/(:num)` | `settings/sync_pppoe_now/$1` | [application/config/routes.php:409](../application/config/routes.php#L409) |
| `pppoe_sync/sync_now` | `settings/sync_pppoe_now` | [application/config/routes.php:410](../application/config/routes.php#L410) |
| `pppoe_sync/sync_now/(:num)` | `settings/sync_pppoe_now/$1` | [application/config/routes.php:411](../application/config/routes.php#L411) |
| `pppoe-sync/migrate-customers` | `settings/migrate_ppp_secret` | [application/config/routes.php:412](../application/config/routes.php#L412) |
| `pppoe_sync/migrate_customers` | `settings/migrate_ppp_secret` | [application/config/routes.php:413](../application/config/routes.php#L413) |
| `static-packages` | `ppp_profiles/index` | [application/config/routes.php:414](../application/config/routes.php#L414) |
| `static_packages` | `ppp_profiles/index` | [application/config/routes.php:416](../application/config/routes.php#L416) |
| `static-packages/edit/(:num)` | `ppp_profiles/edit/$1` | [application/config/routes.php:417](../application/config/routes.php#L417) |
| `static_packages/edit/(:num)` | `ppp_profiles/edit/$1` | [application/config/routes.php:418](../application/config/routes.php#L418) |
| `static-packages/update/(:num)` | `ppp_profiles/update/$1` | [application/config/routes.php:419](../application/config/routes.php#L419) |
| `static_packages/update/(:num)` | `ppp_profiles/update/$1` | [application/config/routes.php:420](../application/config/routes.php#L420) |
| `settings` | `settings/index` | [application/config/routes.php:421](../application/config/routes.php#L421) |
| `settings/mikrotik` | `settings/mikrotik` | [application/config/routes.php:423](../application/config/routes.php#L423) |
| `settings/save_mikrotik` | `settings/save_mikrotik` | [application/config/routes.php:424](../application/config/routes.php#L424) |
| `settings/test_mikrotik` | `settings/test_mikrotik` | [application/config/routes.php:425](../application/config/routes.php#L425) |
| `settings/database` | `settings/database` | [application/config/routes.php:426](../application/config/routes.php#L426) |
| `settings/save_database` | `settings/save_database` | [application/config/routes.php:427](../application/config/routes.php#L427) |
| `settings/test_database` | `settings/test_database` | [application/config/routes.php:428](../application/config/routes.php#L428) |
| `settings/telegram` | `settings/telegram` | [application/config/routes.php:429](../application/config/routes.php#L429) |
| `settings/save_telegram` | `settings/save_telegram` | [application/config/routes.php:430](../application/config/routes.php#L430) |
| `settings/test_telegram` | `settings/test_telegram` | [application/config/routes.php:431](../application/config/routes.php#L431) |
| `settings/pppoe_sync` | `settings/pppoe_sync` | [application/config/routes.php:432](../application/config/routes.php#L432) |
| `settings/save_pppoe_sync` | `settings/save_pppoe_sync` | [application/config/routes.php:433](../application/config/routes.php#L433) |
| `settings/sync_pppoe` | `settings/sync_pppoe` | [application/config/routes.php:434](../application/config/routes.php#L434) |
| `settings/sync_pppoe_now` | `settings/sync_pppoe_now` | [application/config/routes.php:435](../application/config/routes.php#L435) |
| `settings/migrate_ppp_secret` | `settings/migrate_ppp_secret` | [application/config/routes.php:436](../application/config/routes.php#L436) |
| `settings/migrate-ppp-secret` | `settings/migrate_ppp_secret` | [application/config/routes.php:437](../application/config/routes.php#L437) |
| `users` | `users/index` | [application/config/routes.php:438](../application/config/routes.php#L438) |
| `users/create` | `users/create` | [application/config/routes.php:440](../application/config/routes.php#L440) |
| `users/store` | `users/store` | [application/config/routes.php:441](../application/config/routes.php#L441) |
| `users/edit/(:num)` | `users/edit/$1` | [application/config/routes.php:442](../application/config/routes.php#L442) |
| `users/update/(:num)` | `users/update/$1` | [application/config/routes.php:443](../application/config/routes.php#L443) |
| `users/delete/(:num)` | `users/delete/$1` | [application/config/routes.php:444](../application/config/routes.php#L444) |
| `provisioning/store` | `provisioning/store` | [application/config/routes.php:447](../application/config/routes.php#L447) |
| `telegram/webhook` | `telegram_webhook/index` | [application/config/routes.php:448](../application/config/routes.php#L448) |
| `cron/generate-invoice` | `cron/billing_cron/generate_invoice` | [application/config/routes.php:451](../application/config/routes.php#L451) |
| `cron/auto-suspend` | `cron/billing_cron/auto_suspend` | [application/config/routes.php:452](../application/config/routes.php#L452) |
| `cron/sync-static-ip-arp` | `cron/static_ip_cron/sync_static_ip_arp` | [application/config/routes.php:453](../application/config/routes.php#L453) |
| `cron/sync_static_ip_arp` | `cron/static_ip_cron/sync_static_ip_arp` | [application/config/routes.php:454](../application/config/routes.php#L454) |
| `cron/check-static-isolir` | `cron/static_ip_cron/check_static_isolir` | [application/config/routes.php:455](../application/config/routes.php#L455) |
| `cron/check_static_isolir` | `cron/static_ip_cron/check_static_isolir` | [application/config/routes.php:456](../application/config/routes.php#L456) |
| `cron/monitoring-health` | `cron/monitoring_cron/check_health` | [application/config/routes.php:457](../application/config/routes.php#L457) |
| `cron/monitoring_health` | `cron/monitoring_cron/check_health` | [application/config/routes.php:458](../application/config/routes.php#L458) |

## 4. Controller dan public method

### application/controllers/Admin_whatsapp.php

Class `Admin_whatsapp` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Admin_whatsapp.php:16](../application/controllers/Admin_whatsapp.php#L16) |
| `detail($id)` | [application/controllers/Admin_whatsapp.php:35](../application/controllers/Admin_whatsapp.php#L35) |
| `resend($id)` | [application/controllers/Admin_whatsapp.php:48](../application/controllers/Admin_whatsapp.php#L48) |

### application/controllers/Analytics.php

Class `Analytics` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `revenue()` | [application/controllers/Analytics.php:23](../application/controllers/Analytics.php#L23) |

### application/controllers/Auth.php

Class `Auth` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Auth.php:16](../application/controllers/Auth.php#L16) |
| `login()` | [application/controllers/Auth.php:21](../application/controllers/Auth.php#L21) |
| `process_login()` | [application/controllers/Auth.php:38](../application/controllers/Auth.php#L38) |
| `logout()` | [application/controllers/Auth.php:192](../application/controllers/Auth.php#L192) |

### application/controllers/Billing.php

Class `Billing` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Billing.php:17](../application/controllers/Billing.php#L17) |
| `generate_monthly_invoices()` | [application/controllers/Billing.php:22](../application/controllers/Billing.php#L22) |
| `manual_generate_invoice()` | [application/controllers/Billing.php:61](../application/controllers/Billing.php#L61) |
| `manual_isolir()` | [application/controllers/Billing.php:300](../application/controllers/Billing.php#L300) |
| `auto_suspend()` | [application/controllers/Billing.php:389](../application/controllers/Billing.php#L389) |
| `record_payment()` | [application/controllers/Billing.php:423](../application/controllers/Billing.php#L423) |
| `view_invoice($id)` | [application/controllers/Billing.php:458](../application/controllers/Billing.php#L458) |
| `edit_invoice($id)` | [application/controllers/Billing.php:491](../application/controllers/Billing.php#L491) |
| `update_invoice($id)` | [application/controllers/Billing.php:504](../application/controllers/Billing.php#L504) |
| `mark_paid($id)` | [application/controllers/Billing.php:788](../application/controllers/Billing.php#L788) |
| `mark_overdue($id)` | [application/controllers/Billing.php:850](../application/controllers/Billing.php#L850) |
| `delete_invoice($id)` | [application/controllers/Billing.php:909](../application/controllers/Billing.php#L909) |
| `delete_ont($id)` | [application/controllers/Billing.php:927](../application/controllers/Billing.php#L927) |
| `acs_gap()` | [application/controllers/Billing.php:978](../application/controllers/Billing.php#L978) |
| `bulk_action()` | [application/controllers/Billing.php:992](../application/controllers/Billing.php#L992) |

### application/controllers/Billing_ui.php

Class `Billing_ui` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Billing_ui.php:14](../application/controllers/Billing_ui.php#L14) |

### application/controllers/Cashflow.php

Class `Cashflow` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Cashflow.php:20](../application/controllers/Cashflow.php#L20) |
| `add_expense()` | [application/controllers/Cashflow.php:34](../application/controllers/Cashflow.php#L34) |
| `monthly_report()` | [application/controllers/Cashflow.php:86](../application/controllers/Cashflow.php#L86) |
| `export_excel()` | [application/controllers/Cashflow.php:114](../application/controllers/Cashflow.php#L114) |

### application/controllers/Cashflow_ui.php

Class `Cashflow_ui` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Cashflow_ui.php:14](../application/controllers/Cashflow_ui.php#L14) |
| `add_income()` | [application/controllers/Cashflow_ui.php:99](../application/controllers/Cashflow_ui.php#L99) |
| `add_expense()` | [application/controllers/Cashflow_ui.php:223](../application/controllers/Cashflow_ui.php#L223) |
| `update($id)` | [application/controllers/Cashflow_ui.php:348](../application/controllers/Cashflow_ui.php#L348) |
| `delete($id)` | [application/controllers/Cashflow_ui.php:478](../application/controllers/Cashflow_ui.php#L478) |
| `review_request($request_id)` | [application/controllers/Cashflow_ui.php:543](../application/controllers/Cashflow_ui.php#L543) |
| `bulk_action()` | [application/controllers/Cashflow_ui.php:676](../application/controllers/Cashflow_ui.php#L676) |

### application/controllers/CronNotification.php

Class `CronNotification` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `run_all()` | [application/controllers/CronNotification.php:14](../application/controllers/CronNotification.php#L14) |
| `check_overdue_invoice()` | [application/controllers/CronNotification.php:33](../application/controllers/CronNotification.php#L33) |
| `check_router_status()` | [application/controllers/CronNotification.php:41](../application/controllers/CronNotification.php#L41) |
| `check_ticket_pending()` | [application/controllers/CronNotification.php:49](../application/controllers/CronNotification.php#L49) |
| `check_inventory_minimum()` | [application/controllers/CronNotification.php:57](../application/controllers/CronNotification.php#L57) |

### application/controllers/Cron_whatsapp.php

Class `Cron_whatsapp` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `process_queue($limit = null)` | [application/controllers/Cron_whatsapp.php:17](../application/controllers/Cron_whatsapp.php#L17) |
| `reminder_due()` | [application/controllers/Cron_whatsapp.php:29](../application/controllers/Cron_whatsapp.php#L29) |
| `reminder_overdue()` | [application/controllers/Cron_whatsapp.php:100](../application/controllers/Cron_whatsapp.php#L100) |

### application/controllers/CustomerUpgrade.php

Class `CustomerUpgrade` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/CustomerUpgrade.php:20](../application/controllers/CustomerUpgrade.php#L20) |
| `show_form($customer_id = 0)` | [application/controllers/CustomerUpgrade.php:74](../application/controllers/CustomerUpgrade.php#L74) |
| `calculate_prorate()` | [application/controllers/CustomerUpgrade.php:141](../application/controllers/CustomerUpgrade.php#L141) |
| `process_upgrade()` | [application/controllers/CustomerUpgrade.php:236](../application/controllers/CustomerUpgrade.php#L236) |

### application/controllers/Customers.php

Class `Customers` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Customers.php:40](../application/controllers/Customers.php#L40) |
| `create()` | [application/controllers/Customers.php:66](../application/controllers/Customers.php#L66) |
| `store()` | [application/controllers/Customers.php:71](../application/controllers/Customers.php#L71) |
| `generate_credential()` | [application/controllers/Customers.php:346](../application/controllers/Customers.php#L346) |
| `preview_remote_ip()` | [application/controllers/Customers.php:382](../application/controllers/Customers.php#L382) |
| `suggest_remote_ip()` | [application/controllers/Customers.php:420](../application/controllers/Customers.php#L420) |
| `edit($id = null)` | [application/controllers/Customers.php:425](../application/controllers/Customers.php#L425) |
| `update($id = null)` | [application/controllers/Customers.php:438](../application/controllers/Customers.php#L438) |
| `delete($id = null)` | [application/controllers/Customers.php:640](../application/controllers/Customers.php#L640) |
| `bulk_update_collection_method()` | [application/controllers/Customers.php:691](../application/controllers/Customers.php#L691) |
| `bulk_delete()` | [application/controllers/Customers.php:735](../application/controllers/Customers.php#L735) |
| `bulk_disable()` | [application/controllers/Customers.php:846](../application/controllers/Customers.php#L846) |
| `bulk_generate_invoice()` | [application/controllers/Customers.php:961](../application/controllers/Customers.php#L961) |
| `unique_pppoe_username($username, $ignore_id = 0)` | [application/controllers/Customers.php:1045](../application/controllers/Customers.php#L1045) |
| `valid_billing_cycle_start($value)` | [application/controllers/Customers.php:1254](../application/controllers/Customers.php#L1254) |

### application/controllers/Dashboard.php

Class `Dashboard` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Dashboard.php:18](../application/controllers/Dashboard.php#L18) |
| `switch_router($router_id = null)` | [application/controllers/Dashboard.php:84](../application/controllers/Dashboard.php#L84) |
| `ppp_active_json()` | [application/controllers/Dashboard.php:488](../application/controllers/Dashboard.php#L488) |

### application/controllers/Helpdesk.php

Class `Helpdesk` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Helpdesk.php:19](../application/controllers/Helpdesk.php#L19) |
| `dashboard()` | [application/controllers/Helpdesk.php:59](../application/controllers/Helpdesk.php#L59) |
| `create()` | [application/controllers/Helpdesk.php:78](../application/controllers/Helpdesk.php#L78) |
| `store()` | [application/controllers/Helpdesk.php:90](../application/controllers/Helpdesk.php#L90) |
| `detail($ticket_id = 0)` | [application/controllers/Helpdesk.php:170](../application/controllers/Helpdesk.php#L170) |
| `customer_ppp_detail($customer_id = 0)` | [application/controllers/Helpdesk.php:193](../application/controllers/Helpdesk.php#L193) |
| `update_status()` | [application/controllers/Helpdesk.php:281](../application/controllers/Helpdesk.php#L281) |
| `mark_done($ticket_id = 0)` | [application/controllers/Helpdesk.php:354](../application/controllers/Helpdesk.php#L354) |
| `add_reply($ticket_id = 0)` | [application/controllers/Helpdesk.php:421](../application/controllers/Helpdesk.php#L421) |
| `upload_attachment($ticket_id = 0)` | [application/controllers/Helpdesk.php:461](../application/controllers/Helpdesk.php#L461) |
| `delete($ticket_id = 0)` | [application/controllers/Helpdesk.php:525](../application/controllers/Helpdesk.php#L525) |

### application/controllers/Helpdesk_cron.php

Class `Helpdesk_cron` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `check_sla()` | [application/controllers/Helpdesk_cron.php:15](../application/controllers/Helpdesk_cron.php#L15) |

### application/controllers/Helpdesk_dashboard.php

Class `Helpdesk_dashboard` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Helpdesk_dashboard.php:15](../application/controllers/Helpdesk_dashboard.php#L15) |
| `export_pdf()` | [application/controllers/Helpdesk_dashboard.php:23](../application/controllers/Helpdesk_dashboard.php#L23) |
| `export_excel()` | [application/controllers/Helpdesk_dashboard.php:53](../application/controllers/Helpdesk_dashboard.php#L53) |

### application/controllers/Helpdesk_report.php

Class `Helpdesk_report` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Helpdesk_report.php:15](../application/controllers/Helpdesk_report.php#L15) |
| `export_pdf()` | [application/controllers/Helpdesk_report.php:62](../application/controllers/Helpdesk_report.php#L62) |

### application/controllers/Hotspot.php

Class `Hotspot` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Hotspot.php:18](../application/controllers/Hotspot.php#L18) |
| `add_user()` | [application/controllers/Hotspot.php:125](../application/controllers/Hotspot.php#L125) |
| `generate_users()` | [application/controllers/Hotspot.php:190](../application/controllers/Hotspot.php#L190) |
| `add_profile()` | [application/controllers/Hotspot.php:294](../application/controllers/Hotspot.php#L294) |

### application/controllers/Ip_pools.php

Class `Ip_pools` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Ip_pools.php:17](../application/controllers/Ip_pools.php#L17) |
| `create()` | [application/controllers/Ip_pools.php:35](../application/controllers/Ip_pools.php#L35) |
| `sync_from_router()` | [application/controllers/Ip_pools.php:40](../application/controllers/Ip_pools.php#L40) |
| `refresh_usage()` | [application/controllers/Ip_pools.php:198](../application/controllers/Ip_pools.php#L198) |
| `store()` | [application/controllers/Ip_pools.php:300](../application/controllers/Ip_pools.php#L300) |
| `edit($id = null)` | [application/controllers/Ip_pools.php:345](../application/controllers/Ip_pools.php#L345) |
| `update($id = null)` | [application/controllers/Ip_pools.php:357](../application/controllers/Ip_pools.php#L357) |
| `delete($id = null)` | [application/controllers/Ip_pools.php:418](../application/controllers/Ip_pools.php#L418) |

### application/controllers/Manual_isolir.php

Class `Manual_isolir` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Manual_isolir.php:17](../application/controllers/Manual_isolir.php#L17) |
| `popup()` | [application/controllers/Manual_isolir.php:31](../application/controllers/Manual_isolir.php#L31) |
| `suggest_user()` | [application/controllers/Manual_isolir.php:45](../application/controllers/Manual_isolir.php#L45) |
| `isolate_user()` | [application/controllers/Manual_isolir.php:64](../application/controllers/Manual_isolir.php#L64) |
| `release_user()` | [application/controllers/Manual_isolir.php:195](../application/controllers/Manual_isolir.php#L195) |

### application/controllers/Master_references.php

Class `Master_references` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Master_references.php:20](../application/controllers/Master_references.php#L20) |
| `locations()` | [application/controllers/Master_references.php:25](../application/controllers/Master_references.php#L25) |
| `store_location()` | [application/controllers/Master_references.php:46](../application/controllers/Master_references.php#L46) |
| `update_location($id = 0)` | [application/controllers/Master_references.php:82](../application/controllers/Master_references.php#L82) |
| `delete_location($id = 0)` | [application/controllers/Master_references.php:114](../application/controllers/Master_references.php#L114) |
| `bulk_update_locations()` | [application/controllers/Master_references.php:139](../application/controllers/Master_references.php#L139) |
| `bulk_delete_locations()` | [application/controllers/Master_references.php:169](../application/controllers/Master_references.php#L169) |
| `olts()` | [application/controllers/Master_references.php:196](../application/controllers/Master_references.php#L196) |
| `store_olt()` | [application/controllers/Master_references.php:217](../application/controllers/Master_references.php#L217) |
| `update_olt($id = 0)` | [application/controllers/Master_references.php:253](../application/controllers/Master_references.php#L253) |
| `delete_olt($id = 0)` | [application/controllers/Master_references.php:285](../application/controllers/Master_references.php#L285) |
| `bulk_update_olts()` | [application/controllers/Master_references.php:310](../application/controllers/Master_references.php#L310) |
| `bulk_delete_olts()` | [application/controllers/Master_references.php:340](../application/controllers/Master_references.php#L340) |

### application/controllers/Migrate.php

Class `Migrate` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `latest()` | [application/controllers/Migrate.php:24](../application/controllers/Migrate.php#L24) |

### application/controllers/Monitoring.php

Class `Monitoring` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Monitoring.php:18](../application/controllers/Monitoring.php#L18) |
| `snapshot_json()` | [application/controllers/Monitoring.php:70](../application/controllers/Monitoring.php#L70) |
| `check_now()` | [application/controllers/Monitoring.php:94](../application/controllers/Monitoring.php#L94) |
| `save_interface_config()` | [application/controllers/Monitoring.php:108](../application/controllers/Monitoring.php#L108) |

### application/controllers/NetworkMap.php

Class `NetworkMap` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/NetworkMap.php:17](../application/controllers/NetworkMap.php#L17) |
| `nodes()` | [application/controllers/NetworkMap.php:30](../application/controllers/NetworkMap.php#L30) |
| `get_map_data()` | [application/controllers/NetworkMap.php:48](../application/controllers/NetworkMap.php#L48) |
| `get_router_list()` | [application/controllers/NetworkMap.php:79](../application/controllers/NetworkMap.php#L79) |
| `create_router()` | [application/controllers/NetworkMap.php:98](../application/controllers/NetworkMap.php#L98) |
| `update_router()` | [application/controllers/NetworkMap.php:172](../application/controllers/NetworkMap.php#L172) |
| `delete_router()` | [application/controllers/NetworkMap.php:276](../application/controllers/NetworkMap.php#L276) |
| `create_olt()` | [application/controllers/NetworkMap.php:321](../application/controllers/NetworkMap.php#L321) |
| `update_olt()` | [application/controllers/NetworkMap.php:359](../application/controllers/NetworkMap.php#L359) |
| `delete_olt()` | [application/controllers/NetworkMap.php:398](../application/controllers/NetworkMap.php#L398) |
| `create_odc()` | [application/controllers/NetworkMap.php:427](../application/controllers/NetworkMap.php#L427) |
| `update_odc()` | [application/controllers/NetworkMap.php:468](../application/controllers/NetworkMap.php#L468) |
| `delete_odc()` | [application/controllers/NetworkMap.php:510](../application/controllers/NetworkMap.php#L510) |
| `create_odp()` | [application/controllers/NetworkMap.php:542](../application/controllers/NetworkMap.php#L542) |
| `update_odp()` | [application/controllers/NetworkMap.php:580](../application/controllers/NetworkMap.php#L580) |
| `delete_odp()` | [application/controllers/NetworkMap.php:611](../application/controllers/NetworkMap.php#L611) |

### application/controllers/Notification.php

Class `Notification` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `latest()` | [application/controllers/Notification.php:15](../application/controllers/Notification.php#L15) |
| `unread_count()` | [application/controllers/Notification.php:57](../application/controllers/Notification.php#L57) |
| `mark_read($id = 0)` | [application/controllers/Notification.php:73](../application/controllers/Notification.php#L73) |
| `mark_all_read()` | [application/controllers/Notification.php:102](../application/controllers/Notification.php#L102) |
| `auth()` | [application/controllers/Notification.php:126](../application/controllers/Notification.php#L126) |

### application/controllers/Ont.php

Class `Ont` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index($router_id = null)` | [application/controllers/Ont.php:22](../application/controllers/Ont.php#L22) |
| `online($router_id = null)` | [application/controllers/Ont.php:59](../application/controllers/Ont.php#L59) |
| `offline($router_id = null)` | [application/controllers/Ont.php:65](../application/controllers/Ont.php#L65) |
| `detail($serial = '')` | [application/controllers/Ont.php:71](../application/controllers/Ont.php#L71) |
| `reboot($serial = '')` | [application/controllers/Ont.php:116](../application/controllers/Ont.php#L116) |
| `set_wifi()` | [application/controllers/Ont.php:157](../application/controllers/Ont.php#L157) |
| `sync($router_id = null)` | [application/controllers/Ont.php:204](../application/controllers/Ont.php#L204) |

### application/controllers/Ont_remote.php

Class `Ont_remote` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Ont_remote.php:21](../application/controllers/Ont_remote.php#L21) |
| `detail()` | [application/controllers/Ont_remote.php:31](../application/controllers/Ont_remote.php#L31) |
| `set_wifi()` | [application/controllers/Ont_remote.php:64](../application/controllers/Ont_remote.php#L64) |
| `reboot()` | [application/controllers/Ont_remote.php:115](../application/controllers/Ont_remote.php#L115) |
| `connected_devices()` | [application/controllers/Ont_remote.php:155](../application/controllers/Ont_remote.php#L155) |
| `summary()` | [application/controllers/Ont_remote.php:195](../application/controllers/Ont_remote.php#L195) |

### application/controllers/Ppp_profiles.php

Class `Ppp_profiles` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Ppp_profiles.php:25](../application/controllers/Ppp_profiles.php#L25) |
| `create()` | [application/controllers/Ppp_profiles.php:43](../application/controllers/Ppp_profiles.php#L43) |
| `sync_from_router()` | [application/controllers/Ppp_profiles.php:49](../application/controllers/Ppp_profiles.php#L49) |
| `store()` | [application/controllers/Ppp_profiles.php:262](../application/controllers/Ppp_profiles.php#L262) |
| `edit($id = null)` | [application/controllers/Ppp_profiles.php:308](../application/controllers/Ppp_profiles.php#L308) |
| `update($id = null)` | [application/controllers/Ppp_profiles.php:322](../application/controllers/Ppp_profiles.php#L322) |
| `delete($id = null)` | [application/controllers/Ppp_profiles.php:397](../application/controllers/Ppp_profiles.php#L397) |

### application/controllers/Provisioning.php

Class `Provisioning` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `store()` | [application/controllers/Provisioning.php:26](../application/controllers/Provisioning.php#L26) |
| `generate_username($full_name)` | [application/controllers/Provisioning.php:263](../application/controllers/Provisioning.php#L263) |
| `generate_password($length = 8)` | [application/controllers/Provisioning.php:282](../application/controllers/Provisioning.php#L282) |
| `assign_technician()` | [application/controllers/Provisioning.php:296](../application/controllers/Provisioning.php#L296) |
| `send_telegram_with_button(array $technician, array $customer)` | [application/controllers/Provisioning.php:308](../application/controllers/Provisioning.php#L308) |

### application/controllers/Routeracs.php

Class `Routeracs` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Routeracs.php:16](../application/controllers/Routeracs.php#L16) |
| `edit($id = null)` | [application/controllers/Routeracs.php:38](../application/controllers/Routeracs.php#L38) |
| `update($id = null)` | [application/controllers/Routeracs.php:53](../application/controllers/Routeracs.php#L53) |
| `test_connection($id = null)` | [application/controllers/Routeracs.php:91](../application/controllers/Routeracs.php#L91) |

### application/controllers/Routers.php

Class `Routers` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Routers.php:16](../application/controllers/Routers.php#L16) |
| `create()` | [application/controllers/Routers.php:45](../application/controllers/Routers.php#L45) |
| `store()` | [application/controllers/Routers.php:51](../application/controllers/Routers.php#L51) |
| `edit($id = null)` | [application/controllers/Routers.php:94](../application/controllers/Routers.php#L94) |
| `update($id = null)` | [application/controllers/Routers.php:107](../application/controllers/Routers.php#L107) |
| `delete($id = null)` | [application/controllers/Routers.php:152](../application/controllers/Routers.php#L152) |
| `test_connection($id = null)` | [application/controllers/Routers.php:185](../application/controllers/Routers.php#L185) |

### application/controllers/SaasCron.php

Class `SaasCron` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `checkSubscriptionStatus()` | [application/controllers/SaasCron.php:17](../application/controllers/SaasCron.php#L17) |

### application/controllers/Settings.php

Class `Settings` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Settings.php:36](../application/controllers/Settings.php#L36) |
| `mikrotik()` | [application/controllers/Settings.php:41](../application/controllers/Settings.php#L41) |
| `save_mikrotik()` | [application/controllers/Settings.php:49](../application/controllers/Settings.php#L49) |
| `test_mikrotik()` | [application/controllers/Settings.php:81](../application/controllers/Settings.php#L81) |
| `database()` | [application/controllers/Settings.php:113](../application/controllers/Settings.php#L113) |
| `save_database()` | [application/controllers/Settings.php:121](../application/controllers/Settings.php#L121) |
| `test_database()` | [application/controllers/Settings.php:152](../application/controllers/Settings.php#L152) |
| `telegram()` | [application/controllers/Settings.php:178](../application/controllers/Settings.php#L178) |
| `save_telegram()` | [application/controllers/Settings.php:213](../application/controllers/Settings.php#L213) |
| `save_telegram_bot()` | [application/controllers/Settings.php:244](../application/controllers/Settings.php#L244) |
| `delete_telegram_bot($id = 0)` | [application/controllers/Settings.php:272](../application/controllers/Settings.php#L272) |
| `save_telegram_group()` | [application/controllers/Settings.php:283](../application/controllers/Settings.php#L283) |
| `delete_telegram_group($id = 0)` | [application/controllers/Settings.php:313](../application/controllers/Settings.php#L313) |
| `test_telegram_dispatch()` | [application/controllers/Settings.php:329](../application/controllers/Settings.php#L329) |
| `test_telegram()` | [application/controllers/Settings.php:359](../application/controllers/Settings.php#L359) |
| `pppoe_sync()` | [application/controllers/Settings.php:384](../application/controllers/Settings.php#L384) |
| `save_pppoe_sync()` | [application/controllers/Settings.php:394](../application/controllers/Settings.php#L394) |
| `sync_pppoe_now($router_id = null)` | [application/controllers/Settings.php:420](../application/controllers/Settings.php#L420) |
| `migrate_ppp_secret()` | [application/controllers/Settings.php:425](../application/controllers/Settings.php#L425) |
| `sync_pppoe($router_id = null)` | [application/controllers/Settings.php:715](../application/controllers/Settings.php#L715) |

### application/controllers/Static_ip_sync.php

Class `Static_ip_sync` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Static_ip_sync.php:18](../application/controllers/Static_ip_sync.php#L18) |
| `run_sync()` | [application/controllers/Static_ip_sync.php:50](../application/controllers/Static_ip_sync.php#L50) |
| `run_check_isolir()` | [application/controllers/Static_ip_sync.php:82](../application/controllers/Static_ip_sync.php#L82) |

### application/controllers/Static_packages.php

Class `Static_packages` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Static_packages.php:13](../application/controllers/Static_packages.php#L13) |
| `edit($id = null)` | [application/controllers/Static_packages.php:18](../application/controllers/Static_packages.php#L18) |
| `update($id = null)` | [application/controllers/Static_packages.php:23](../application/controllers/Static_packages.php#L23) |

### application/controllers/Subscription.php

Class `Subscription` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `expired()` | [application/controllers/Subscription.php:14](../application/controllers/Subscription.php#L14) |
| `pay()` | [application/controllers/Subscription.php:33](../application/controllers/Subscription.php#L33) |

### application/controllers/System_monitoring.php

Class `System_monitoring` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/System_monitoring.php:16](../application/controllers/System_monitoring.php#L16) |
| `status_json()` | [application/controllers/System_monitoring.php:55](../application/controllers/System_monitoring.php#L55) |

### application/controllers/Teknisi_dashboard.php

Class `Teknisi_dashboard` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Teknisi_dashboard.php:15](../application/controllers/Teknisi_dashboard.php#L15) |
| `export_pdf()` | [application/controllers/Teknisi_dashboard.php:72](../application/controllers/Teknisi_dashboard.php#L72) |

### application/controllers/Telegram_webhook.php

Class `Telegram_webhook` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Telegram_webhook.php:28](../application/controllers/Telegram_webhook.php#L28) |

### application/controllers/Tenant_dashboard.php

Class `Tenant_dashboard` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Tenant_dashboard.php:14](../application/controllers/Tenant_dashboard.php#L14) |
| `expired()` | [application/controllers/Tenant_dashboard.php:57](../application/controllers/Tenant_dashboard.php#L57) |

### application/controllers/Tickets.php

Class `Tickets` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Tickets.php:31](../application/controllers/Tickets.php#L31) |
| `store()` | [application/controllers/Tickets.php:105](../application/controllers/Tickets.php#L105) |
| `mark_done($id = 0)` | [application/controllers/Tickets.php:333](../application/controllers/Tickets.php#L333) |

### application/controllers/User_logs.php

Class `User_logs` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/User_logs.php:16](../application/controllers/User_logs.php#L16) |

### application/controllers/Users.php

Class `Users` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Users.php:17](../application/controllers/Users.php#L17) |
| `create()` | [application/controllers/Users.php:37](../application/controllers/Users.php#L37) |
| `store()` | [application/controllers/Users.php:42](../application/controllers/Users.php#L42) |
| `edit($id = null)` | [application/controllers/Users.php:87](../application/controllers/Users.php#L87) |
| `update($id = null)` | [application/controllers/Users.php:104](../application/controllers/Users.php#L104) |
| `delete($id = null)` | [application/controllers/Users.php:163](../application/controllers/Users.php#L163) |

### application/controllers/Work_order.php

Class `Work_order` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `start()` | [application/controllers/Work_order.php:22](../application/controllers/Work_order.php#L22) |
| `done()` | [application/controllers/Work_order.php:44](../application/controllers/Work_order.php#L44) |
| `activate()` | [application/controllers/Work_order.php:78](../application/controllers/Work_order.php#L78) |
| `cancel()` | [application/controllers/Work_order.php:107](../application/controllers/Work_order.php#L107) |
| `detail($id = null)` | [application/controllers/Work_order.php:129](../application/controllers/Work_order.php#L129) |

### application/controllers/Worker.php

Class `Worker` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `run($mode = 'daemon', $max_jobs = 0)` | [application/controllers/Worker.php:38](../application/controllers/Worker.php#L38) |

### application/controllers/Workorders.php

Class `Workorders` mewarisi `MY_Controller`.

| Method | Sumber |
| --- | --- |
| `index()` | [application/controllers/Workorders.php:33](../application/controllers/Workorders.php#L33) |
| `store()` | [application/controllers/Workorders.php:91](../application/controllers/Workorders.php#L91) |
| `mark_done($id = 0)` | [application/controllers/Workorders.php:266](../application/controllers/Workorders.php#L266) |
| `delete($id = 0)` | [application/controllers/Workorders.php:353](../application/controllers/Workorders.php#L353) |

### application/controllers/api/Ont.php

Class `Ont` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `set_wifi()` | [application/controllers/api/Ont.php:17](../application/controllers/api/Ont.php#L17) |
| `reboot()` | [application/controllers/api/Ont.php:54](../application/controllers/api/Ont.php#L54) |
| `connected_devices()` | [application/controllers/api/Ont.php:82](../application/controllers/api/Ont.php#L82) |

### application/controllers/cron/Billing_cron.php

Class `Billing_cron` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `generate_invoice()` | [application/controllers/cron/Billing_cron.php:15](../application/controllers/cron/Billing_cron.php#L15) |
| `auto_suspend()` | [application/controllers/cron/Billing_cron.php:87](../application/controllers/cron/Billing_cron.php#L87) |

### application/controllers/cron/Invoice_cron.php

Class `Invoice_cron` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `generate()` | [application/controllers/cron/Invoice_cron.php:32](../application/controllers/cron/Invoice_cron.php#L32) |
| `check_overdue()` | [application/controllers/cron/Invoice_cron.php:82](../application/controllers/cron/Invoice_cron.php#L82) |

### application/controllers/cron/Monitoring_cron.php

Class `Monitoring_cron` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `check_health()` | [application/controllers/cron/Monitoring_cron.php:18](../application/controllers/cron/Monitoring_cron.php#L18) |

### application/controllers/cron/Offline_collection_cron.php

Class `Offline_collection_cron` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `run()` | [application/controllers/cron/Offline_collection_cron.php:17](../application/controllers/cron/Offline_collection_cron.php#L17) |
| `preview($date = null)` | [application/controllers/cron/Offline_collection_cron.php:32](../application/controllers/cron/Offline_collection_cron.php#L32) |

### application/controllers/cron/Static_ip_cron.php

Class `Static_ip_cron` mewarisi `CI_Controller`.

| Method | Sumber |
| --- | --- |
| `sync_static_ip_arp($router_id = null)` | [application/controllers/cron/Static_ip_cron.php:15](../application/controllers/cron/Static_ip_cron.php#L15) |
| `check_static_isolir($router_id = null)` | [application/controllers/cron/Static_ip_cron.php:72](../application/controllers/cron/Static_ip_cron.php#L72) |

## 5. Migrasi PHP dalam urutan nama

Urutan ini bukan bukti seluruh migrasi telah terpasang. Status aktual tersimpan di tabel `migrations`. Sejumlah migrasi awal memerlukan tabel dasar yang berasal dari schema terpisah.

- [application/migrations/20260223091500_multitenant_foundation.php:1](../application/migrations/20260223091500_multitenant_foundation.php#L1)
- [application/migrations/20260223100500_saas_subscription_engine.php:1](../application/migrations/20260223100500_saas_subscription_engine.php#L1)
- [application/migrations/20260223113500_saas_enforcement_lock.php:1](../application/migrations/20260223113500_saas_enforcement_lock.php#L1)
- [application/migrations/20260223122000_async_job_queue.php:1](../application/migrations/20260223122000_async_job_queue.php#L1)
- [application/migrations/20260224123000_multirouter_multitelegram_single_install.php:1](../application/migrations/20260224123000_multirouter_multitelegram_single_install.php#L1)
- [application/migrations/20260224150000_multiclient_lite_foundation.php:1](../application/migrations/20260224150000_multiclient_lite_foundation.php#L1)
- [application/migrations/20260227170000_realtime_notifications_pusher.php:1](../application/migrations/20260227170000_realtime_notifications_pusher.php#L1)
- [application/migrations/20260301155000_genieacs_ont_devices.php:1](../application/migrations/20260301155000_genieacs_ont_devices.php#L1)
- [application/migrations/20260301190000_router_acs_status.php:1](../application/migrations/20260301190000_router_acs_status.php#L1)
- [application/migrations/20260302003000_ont_devices_extra_fields.php:1](../application/migrations/20260302003000_ont_devices_extra_fields.php#L1)
- [application/migrations/20260308110000_customer_upgrade_feature.php:1](../application/migrations/20260308110000_customer_upgrade_feature.php#L1)
- [application/migrations/20260308194000_fiber_network_map_module.php:1](../application/migrations/20260308194000_fiber_network_map_module.php#L1)
- [application/migrations/20260309113000_fiber_network_map_odc_router_geo.php:1](../application/migrations/20260309113000_fiber_network_map_odc_router_geo.php#L1)
- [application/migrations/20260511120000_whatsapp_message_logs.php:1](../application/migrations/20260511120000_whatsapp_message_logs.php#L1)
- [application/migrations/20260511133000_static_ip_sync_customer_columns.php:1](../application/migrations/20260511133000_static_ip_sync_customer_columns.php#L1)
- [application/migrations/20260531130000_user_multi_router_access.php:1](../application/migrations/20260531130000_user_multi_router_access.php#L1)
- [application/migrations/20260607120000_customer_due_date_day.php:1](../application/migrations/20260607120000_customer_due_date_day.php#L1)
- [application/migrations/20261022120000_customer_billing_cycles_offline_collection.php:1](../application/migrations/20261022120000_customer_billing_cycles_offline_collection.php#L1)

## 6. Dependency produksi Composer yang dikunci

| Package | Versi lock | Constraint PHP package |
| --- | --- | --- |
| `dompdf/dompdf` | `v3.1.4` | `^7.1 \|\| ^8.0` |
| `dompdf/php-font-lib` | `1.0.2` | `^7.1 \|\| ^8.0` |
| `dompdf/php-svg-lib` | `1.0.2` | `^7.1 \|\| ^8.0` |
| `guzzlehttp/guzzle` | `7.10.0` | `^7.2.5 \|\| ^8.0` |
| `guzzlehttp/promises` | `2.3.0` | `^7.2.5 \|\| ^8.0` |
| `guzzlehttp/psr7` | `2.8.0` | `^7.2.5 \|\| ^8.0` |
| `masterminds/html5` | `2.10.0` | `>=5.3.0` |
| `paragonie/random_compat` | `v9.99.100` | `>= 7` |
| `paragonie/sodium_compat` | `v1.24.0` | `^5.2.4\|^5.3\|^5.4\|^5.5\|^5.6\|^7\|^8` |
| `predis/predis` | `v2.4.1` | `^7.2 \|\| ^8.0` |
| `psr/http-client` | `1.0.3` | `^7.0 \|\| ^8.0` |
| `psr/http-factory` | `1.1.0` | `>=7.1` |
| `psr/http-message` | `2.0` | `^7.2 \|\| ^8.0` |
| `psr/log` | `1.1.4` | `>=5.3.0` |
| `pusher/pusher-php-server` | `7.2.7` | `^7.3\|^8.0` |
| `ralouphie/getallheaders` | `3.0.3` | `>=5.6` |
| `sabberworm/php-css-parser` | `v9.2.0` | `^7.2.0 \|\| ~8.0.0 \|\| ~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0` |
| `symfony/deprecation-contracts` | `v2.5.4` | `>=7.1` |
| `thecodingmachine/safe` | `v1.3.3` | `>=7.2` |

## 7. Referensi variabel environment

Hanya nama dan lokasi pembacaan. Variabel yang diteruskan lewat wrapper dinamis bisa tidak tertangkap pemindai; `.env.example` ikut dijadikan sumber nama. Defaults dan arti utama dijelaskan di [spesifikasi teknis](14_SPESIFIKASI_TEKNIS_DAN_DATA.md).

| Nama | Lokasi referensi |
| --- | --- |
| `APP_BRAND_COMPANY` | [.env.example:1](../.env.example#L1) |
| `APP_BRAND_NAME` | [.env.example:1](../.env.example#L1), [application/config/config.php:1](../application/config/config.php#L1), [application/config/telegram_automation.php:1](../application/config/telegram_automation.php#L1) |
| `APP_BRAND_TAGLINE` | [.env.example:1](../.env.example#L1) |
| `APP_FORCE_HTTPS` | [.env.example:1](../.env.example#L1), [application/config/config.php:1](../application/config/config.php#L1) |
| `APP_KEY` | [application/config/config.php:1](../application/config/config.php#L1), [application/models/Settings_model.php:1](../application/models/Settings_model.php#L1) |
| `APP_LOG_THRESHOLD` | [.env.example:1](../.env.example#L1), [application/config/config.php:1](../application/config/config.php#L1) |
| `APP_SECRET_KEY` | [application/models/Settings_model.php:1](../application/models/Settings_model.php#L1) |
| `APP_TRUSTED_PROXIES` | [.env.example:1](../.env.example#L1), [application/config/config.php:1](../application/config/config.php#L1) |
| `AUTH_LOGIN_LOCK_SECONDS` | [.env.example:1](../.env.example#L1), [application/config/config.php:1](../application/config/config.php#L1) |
| `AUTH_LOGIN_MAX_ATTEMPTS` | [.env.example:1](../.env.example#L1), [application/config/config.php:1](../application/config/config.php#L1) |
| `AUTH_LOGIN_WINDOW_SECONDS` | [.env.example:1](../.env.example#L1), [application/config/config.php:1](../application/config/config.php#L1) |
| `AUTH_SESSION_IDLE_TIMEOUT` | [.env.example:1](../.env.example#L1), [application/config/config.php:1](../application/config/config.php#L1) |
| `BILLING_APPLY_MIKROTIK_ON_MARK_PAID` | [.env.example:1](../.env.example#L1), [application/libraries/Billing_automation_service.php:1](../application/libraries/Billing_automation_service.php#L1) |
| `CI_ENCRYPTION_KEY` | [.env.example:1](../.env.example#L1), [application/config/config.php:1](../application/config/config.php#L1), [application/models/Settings_model.php:1](../application/models/Settings_model.php#L1) |
| `CI_ENV` | [.env.example:1](../.env.example#L1) |
| `COMPANY_ADDRESS` | [.env.example:1](../.env.example#L1) |
| `COMPANY_EMAIL` | [.env.example:1](../.env.example#L1) |
| `COMPANY_NAME` | [.env.example:1](../.env.example#L1) |
| `COMPANY_PHONE` | [.env.example:1](../.env.example#L1) |
| `COMPANY_TAGLINE` | [.env.example:1](../.env.example#L1) |
| `COMPANY_WEBSITE` | [.env.example:1](../.env.example#L1) |
| `CRON_NOTIFICATION_KEY` | [.env.example:1](../.env.example#L1), [application/controllers/CronNotification.php:1](../application/controllers/CronNotification.php#L1) |
| `CRON_TOKEN` | [.env.example:1](../.env.example#L1), [application/config/cron.php:1](../application/config/cron.php#L1), [application/controllers/SaasCron.php:1](../application/controllers/SaasCron.php#L1), [application/controllers/cron/Billing_cron.php:1](../application/controllers/cron/Billing_cron.php#L1), [application/controllers/cron/Static_ip_cron.php:1](../application/controllers/cron/Static_ip_cron.php#L1) |
| `DB_HOST` | [.env.example:1](../.env.example#L1) |
| `DB_NAME` | [.env.example:1](../.env.example#L1) |
| `DB_PASS` | [.env.example:1](../.env.example#L1) |
| `DB_PORT` | [.env.example:1](../.env.example#L1) |
| `DB_USER` | [.env.example:1](../.env.example#L1) |
| `FREEACS_HTTP_PASS` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `FREEACS_HTTP_USER` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `FREEACS_MODE` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `FREEACS_REST_BASE_URL` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `FREEACS_REST_TOKEN` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `FREEACS_SOAP_LOGIN_PASS` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `FREEACS_SOAP_LOGIN_USER` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `FREEACS_SOAP_URL` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `FREEACS_TIMEOUT` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `FREEACS_VERIFY_SSL` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `GENIEACS_CONNECTION_REQUEST_ONLINE_CHECK` | [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `GENIEACS_SYNC_REFRESH_VIRTUAL_PARAMETERS` | [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `GENIEACS_TASK_CONNECTION_REQUEST` | [.env.example:1](../.env.example#L1), [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `GENIEACS_TIMEOUT` | [.env.example:1](../.env.example#L1), [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `GENIEACS_VERIFY_SSL` | [.env.example:1](../.env.example#L1), [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `GENIEACS_VPARAM_BASE_DIR` | [.env.example:1](../.env.example#L1), [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `GENIEACS_VPARAM_JSON_ARRAY_PATH` | [.env.example:1](../.env.example#L1), [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `GENIEACS_VPARAM_MANIFEST_PATH` | [.env.example:1](../.env.example#L1), [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `GENIEACS_VPARAM_MONGO_DB` | [.env.example:1](../.env.example#L1), [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `GENIEACS_VPARAM_NDJSON_PATH` | [.env.example:1](../.env.example#L1), [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `GENIEACS_VPARAM_SOURCE` | [.env.example:1](../.env.example#L1), [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `GENIEACS_VPARAM_SYNC_ENABLED` | [.env.example:1](../.env.example#L1), [application/config/genieacs.php:1](../application/config/genieacs.php#L1) |
| `MIKROTIK_HOST` | [.env.example:1](../.env.example#L1) |
| `MIKROTIK_ISOLIR_LIST` | [.env.example:1](../.env.example#L1), [application/libraries/Billing_automation_service.php:1](../application/libraries/Billing_automation_service.php#L1), [application/libraries/MikrotikManager.php:1](../application/libraries/MikrotikManager.php#L1) |
| `MIKROTIK_PASS` | [.env.example:1](../.env.example#L1) |
| `MIKROTIK_PORT` | [.env.example:1](../.env.example#L1) |
| `MIKROTIK_RETRY_DELAY` | [.env.example:1](../.env.example#L1) |
| `MIKROTIK_RETRY_MAX` | [.env.example:1](../.env.example#L1) |
| `MIKROTIK_SSL` | [.env.example:1](../.env.example#L1) |
| `MIKROTIK_TIMEOUT` | [.env.example:1](../.env.example#L1) |
| `MIKROTIK_USER` | [.env.example:1](../.env.example#L1) |
| `MONITORING_CRON_TOKEN` | [.env.example:1](../.env.example#L1), [application/config/monitoring.php:1](../application/config/monitoring.php#L1) |
| `PROVISIONING_CALLBACK_SECRET` | [.env.example:1](../.env.example#L1), [application/controllers/Customers.php:1](../application/controllers/Customers.php#L1), [application/controllers/Provisioning.php:1](../application/controllers/Provisioning.php#L1), [application/controllers/Telegram_webhook.php:1](../application/controllers/Telegram_webhook.php#L1) |
| `PUSHER_APP_ID` | [.env.example:1](../.env.example#L1), [application/config/pusher.php:1](../application/config/pusher.php#L1) |
| `PUSHER_CHANNEL_PRIVATE_PREFIX` | [.env.example:1](../.env.example#L1), [application/config/pusher.php:1](../application/config/pusher.php#L1) |
| `PUSHER_CHANNEL_PUBLIC` | [.env.example:1](../.env.example#L1), [application/config/pusher.php:1](../application/config/pusher.php#L1) |
| `PUSHER_CLUSTER` | [.env.example:1](../.env.example#L1), [application/config/pusher.php:1](../application/config/pusher.php#L1) |
| `PUSHER_EVENT_NEW_NOTIFICATION` | [.env.example:1](../.env.example#L1), [application/config/pusher.php:1](../application/config/pusher.php#L1) |
| `PUSHER_KEY` | [.env.example:1](../.env.example#L1), [application/config/pusher.php:1](../application/config/pusher.php#L1) |
| `PUSHER_SECRET` | [.env.example:1](../.env.example#L1), [application/config/pusher.php:1](../application/config/pusher.php#L1) |
| `PUSHER_USE_TLS` | [.env.example:1](../.env.example#L1), [application/config/pusher.php:1](../application/config/pusher.php#L1) |
| `QUEUE_BACKOFF_BASE_SECONDS` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `QUEUE_BACKOFF_MAX_SECONDS` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `QUEUE_DEFAULT_MAX_ATTEMPTS` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `QUEUE_DRIVER` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `QUEUE_ENABLE_ASYNC` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `QUEUE_NAME` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `QUEUE_POLL_SLEEP_SECONDS` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `QUEUE_POP_TIMEOUT_SECONDS` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `QUEUE_WORKER_MEMORY_LIMIT_MB` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `REDIS_DB` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `REDIS_HOST` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `REDIS_PASSWORD` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `REDIS_PORT` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `REDIS_PREFIX` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `REDIS_TIMEOUT` | [.env.example:1](../.env.example#L1), [application/config/queue.php:1](../application/config/queue.php#L1) |
| `STATIC_IP_SYNC_CIDR` | [application/models/Static_ip_sync_model.php:1](../application/models/Static_ip_sync_model.php#L1) |
| `TELEGRAM_BOT_TOKEN` | [.env.example:1](../.env.example#L1), [application/config/telegram_automation.php:1](../application/config/telegram_automation.php#L1) |
| `TELEGRAM_WEBHOOK_SECRET` | [.env.example:1](../.env.example#L1), [application/config/telegram_automation.php:1](../application/config/telegram_automation.php#L1), [application/controllers/Telegram_webhook.php:1](../application/controllers/Telegram_webhook.php#L1) |
| `TR069_API_BASIC_ENABLED` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `TR069_API_BASIC_PASS` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `TR069_API_BASIC_USER` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `TR069_API_TOKEN` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `TR069_TASK_RATE_LIMIT_SECONDS` | [.env.example:1](../.env.example#L1), [application/config/tr069.php:1](../application/config/tr069.php#L1) |
| `WA_API_TOKEN` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_API_URL` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_CONNECT_TIMEOUT_SECONDS` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_CRON_SECRET` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_DELAY_SECONDS` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_MAX_RETRY` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_PAYMENT_INFO` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_PROCESSING_TIMEOUT_MINUTES` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_PROVIDER` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_QUEUE_LIMIT` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_REMINDER_LIMIT` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_SENDER` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_TIMEOUT_SECONDS` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_TOKEN_HEADER` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
| `WA_TOKEN_PREFIX` | [application/config/whatsapp.php:1](../application/config/whatsapp.php#L1) |
