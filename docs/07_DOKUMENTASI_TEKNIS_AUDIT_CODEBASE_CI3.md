# Dokumentasi Teknis Audit Codebase CI3

## Dokumen Ini Dibuat Dari Apa

- Basis analisis: source code repository pada path `/var/www/rtrwnet`
- Tanggal audit: 2026-04-07
- Framework inti: CodeIgniter 3
- Nama aplikasi yang terdeteksi: `Nawacore`
- Konteks produk: platform operasional ISP / RTRWNet berbasis single-install, multi-router, multi-bot Telegram, dengan modul billing, support, monitoring, dan otomasi jaringan

Dokumen ini tidak ditulis dari template CI3 generik. Seluruh isi disusun dari pembacaan `routes.php`, controller, model, view, config, migration, SQL dump, asset frontend, serta script deployment yang ada di repository.

Catatan packaging 2026-04-17:

- baseline source saat ini sudah dibersihkan dari dump produksi dan backup historis
- nama/branding lama yang masih muncul di sebagian catatan di bawah harus dibaca sebagai konteks audit historis, bukan baseline installer yang aktif

## Asumsi dan Batasan Analisis

- Repository ini masih menyimpan jejak modul lama hasil eksperimen SaaS / multitenant, tetapi mode aktif saat ini adalah **single-install**. Hal ini terlihat dari komentar di `MY_Controller`, dokumentasi `docs/README.md`, dan route aktif yang berfokus pada modul operasional tunggal.
- Saat audit awal masih ada dump historis di repo dan isinya tidak sepenuhnya sinkron dengan code terbaru. Untuk baseline packaging sekarang, dump tersebut sudah dikeluarkan dari source tree.
- Di beberapa area terdapat pasangan modul lama dan modul baru, misalnya `Cashflow.php` versus `Cashflow_ui.php`, `Tickets.php` versus `Helpdesk.php`, `System_monitoring.php` versus `Monitoring.php`. Dalam dokumentasi ini saya menandai mana yang tampak **aktif/canonical** dan mana yang tampak **legacy/compatibility**.
- Bagian deployment untuk Nginx benar-benar didukung oleh repo melalui installer Ubuntu. Bagian Apache ditulis sebagai rekomendasi teknis yang masuk akal karena repo menyediakan `.htaccess`, tetapi tidak menyediakan installer Apache khusus.

---

## 1. Overview Project

### Nama Project

**Nawacore RTRWNet**

Nama ini diturunkan dari kombinasi:

- branding aplikasi di `application/config/app.php` dan `application/config/config.php`: `Nawacore`
- nama repository dan konteks domain operasional: `rtrwnet`

### Deskripsi Aplikasi

Project ini adalah aplikasi **CI3 fullstack untuk operasional ISP/RTRWNet** yang menggabungkan:

- manajemen pelanggan internet
- provisioning PPPoE / static IP ke MikroTik
- billing dan pembayaran invoice
- cashflow
- manual isolir / auto suspend
- work order teknisi
- helpdesk ticketing
- monitoring router dan interface
- monitoring ONT / integrasi ACS
- notifikasi realtime dan Telegram
- dukungan PWA dan wrapper Android berbasis Capacitor

Secara arsitektural, ini bukan sekadar CRUD pelanggan. Codebase menunjukkan pola produk operasional yang mencoba menghubungkan siklus bisnis lengkap:

`pelanggan -> layanan -> router -> invoice -> pembayaran -> cashflow -> support -> monitoring -> notifikasi`

### Fitur Utama yang Terdeteksi

- Login, session management, role normalization, dan router scope
- Dashboard admin dan dashboard teknisi
- Customer management dengan mode layanan `pppoe` dan `static`
- Service plan / PPP profile management
- IP pool management
- Upgrade paket pelanggan dengan prorate dan sinkronisasi MikroTik
- Invoice generation, payment recording, overdue handling, dan auto suspend
- Cashflow pemasukan / pengeluaran
- Manual isolir / release user
- Router sync dan static IP sync
- Router management dan per-router ACS configuration
- Fiber network map, ODP/ODC management, master lokasi, dan master OLT
- Helpdesk ticketing, lampiran, reply, dashboard, export
- Work order teknisi dan dashboard performa teknisi
- Monitoring router realtime
- ONT monitoring via GenieACS dan remote action TR-069
- Realtime notification via Pusher
- Telegram webhook dan automation
- Async job queue dan worker
- Ubuntu deployment installer

---

## 2. Analisis Arsitektur

### Cara CI3 Digunakan di Project Ini

Project ini memakai pola CodeIgniter 3 klasik, tetapi dengan beberapa lapisan tambahan agar cocok untuk operasi ISP:

- `index.php` sebagai front controller
- `application/config/routes.php` sebagai pintu masuk URI yang cukup ekstensif
- `application/core/MY_Controller.php` sebagai middleware sentral untuk auth, session timeout, validasi user aktif, router scope, dan activity log
- controller sebagai orchestration layer
- model sebagai akses database dan logika query
- library sebagai integration/service layer untuk MikroTik, billing automation, queue, Telegram, Pusher, dan GenieACS
- view sebagai server-rendered UI berbasis Bootstrap 5 dengan AJAX/fetch di banyak halaman

### Flow Arsitektur Utama

Flow standar yang dipakai di codebase ini:

1. User membuka route.
2. `routes.php` memetakan URI ke controller tertentu.
3. Controller mewarisi `MY_Controller` untuk memaksa login, validasi role, session idle timeout, dan router scoping.
4. Controller memanggil model untuk query database.
5. Jika perlu, controller juga memanggil library eksternal seperti `MikrotikManager`, `Billing_automation_service`, `Genieacs`, `Telegram_service`, atau `Pusher_lib`.
6. Controller mengembalikan:
   - HTML view untuk halaman biasa
   - JSON response untuk AJAX/API
   - file export untuk PDF/Excel

Representasi sederhananya:

`user -> route -> controller -> model/library -> database/external service -> view/json`

### Middleware dan Cross-Cutting Concern

Komponen yang paling menentukan perilaku aplikasi justru bukan di satu controller tertentu, melainkan di lapisan umum berikut:

- `application/core/MY_Controller.php`
  - enforce login
  - enforce session idle timeout
  - normalize dan validasi role
  - enforce active user status
  - apply router scope
  - expose active router badge ke view
  - log aktivitas user
- `application/helpers/rbac_helper.php`
  - menormalkan role legacy SaaS ke role aktif `superadmin`, `admin`, `teknisi`
  - menyediakan matrix akses modul
- `application/helpers/router_scope_helper.php`
  - menentukan router aktif dan label distribusi di UI

### Mode Produk yang Sedang Aktif

Project ini tampak sudah diposisikan ulang dari eksperimen SaaS ke model **single-install**. Indikasinya:

- `docs/README.md` menyebut versi final setelah rollback SaaS ke single-install
- `MY_Controller` menandai tenant middleware sebagai compatibility only
- route aktif fokus ke modul operasional tunggal, bukan tenant lifecycle

### AJAX dan API yang Terdeteksi

Project ini bukan aplikasi full SPA, tetapi cukup banyak memakai AJAX/fetch untuk operasi cepat.

#### AJAX/JSON endpoint internal

- `customers/generate_credential`
- `customers/preview_remote_ip`
- `customers/bulk_delete`
- `customers/bulk_disable`
- `customers/bulk_generate_invoice`
- `customers/upgrade/show_form/{id}`
- `customers/upgrade/calculate_prorate`
- `helpdesk/customer_ppp_detail/{id}`
- `helpdesk/update_status`
- `monitoring/snapshot_json`
- `monitoring/check_now`
- `network/*` untuk CRUD node dan map data
- `manual-isolir/*`
- `ont-remote/*`
- `notification/*`

#### API / webhook eksternal

- `api/ont/*`
  - machine-to-machine endpoint untuk aksi ONT
- `telegram_webhook`
  - menerima callback dan message update dari Telegram
- route cron HTTP tertentu
  - misalnya notifikasi dan monitoring

### Integrasi Eksternal yang Benar-Benar Dipakai

- MikroTik RouterOS API
- GenieACS
- FreeACS / TR-069 bridge
- Pusher realtime
- Redis queue
- Telegram bot / group
- Dompdf untuk export PDF

---

## 3. Struktur Folder Hasil Analisis

### Struktur Root yang Relevan

| Folder/File | Fungsi di Project Ini |
| --- | --- |
| `application/` | Inti aplikasi CI3: controller, model, view, config, migration, library, jobs |
| `system/` | Core framework CodeIgniter 3 |
| `vendor/` | Dependency Composer, termasuk Dompdf, Predis, Pusher |
| `assets/` | CSS, JS, dan asset frontend utama |
| `public/` | Direktori public tambahan dan area upload tertentu |
| `docs/` | Dokumen operasional terbaru, SQL dump, runbook |
| `deployment/` | Installer Ubuntu dan template Nginx |
| `mobile-app/` | Wrapper Android Capacitor yang memuat aplikasi web dari URL server |
| `pwa/`, `manifest.json`, `pwa-sw.js`, `offline.html` | Dukungan PWA |
| `.htaccess` | Hardening dan rewrite untuk environment Apache |

### Struktur `application/` yang Relevan

| Folder | Analisis fungsi |
| --- | --- |
| `application/controllers/` | Controller utama aplikasi web |
| `application/controllers/api/` | Endpoint API machine-to-machine |
| `application/controllers/cron/` | Controller cron berbasis CLI/HTTP |
| `application/core/` | `MY_Controller` untuk auth dan router scope |
| `application/helpers/` | Helper RBAC dan router scope |
| `application/libraries/` | Service layer: billing, MikroTik, GenieACS, Telegram, Pusher, queue |
| `application/models/` | Model akses data utama |
| `application/migrations/` | Bukti evolusi schema: multi-router, async queue, realtime notifications, ONT, customer upgrade, fiber map |
| `application/jobs/` | Handler async job seperti billing generate, isolir, create secret, monitoring, telegram |
| `application/views/` | Server-rendered UI aktif |
| `application/views/layout/` | Layout aktif aplikasi |
| `application/views/layouts/` | Wrapper layout lama/compatibility |
| `application/views/ui/` | View lama yang tampak sebagai legacy UI |
| `application/cache/login_attempts/` | Penyimpanan state rate-limit login |

### Controller Utama dan Fungsinya

#### Controller aktif/canonical

| Controller | Route/Area utama | Fungsi utama |
| --- | --- | --- |
| `Auth.php` | `auth/*` | Login, logout, captcha, rate limiting, session bootstrap |
| `Dashboard.php` | `dashboard` | Dashboard utama admin dan switch router untuk superadmin |
| `Customers.php` | `customers/*` | CRUD pelanggan, provisioning PPPoE/static, invoice awal, bulk action, integrasi Telegram |
| `CustomerUpgrade.php` | `customers/upgrade/*` | Upgrade paket, hitung prorate, history perubahan layanan, update profil MikroTik |
| `Ppp_profiles.php` | `ppp-profiles/*` | CRUD service plan / PPP profile dan sync dari router |
| `Ip_pools.php` | `ip-pools/*` | CRUD IP pool dan refresh usage |
| `Billing_ui.php` | `billing` | Tampilan list invoice dan filter utama |
| `Billing.php` | `billing/*` | Generate invoice, record payment, mark paid/overdue, auto suspend, edit invoice |
| `Cashflow_ui.php` | `cashflow/*` | CRUD transaksi cashflow, review request, bulk action |
| `Manual_isolir.php` | `manual-isolir/*` | Isolir/release manual user via popup dan endpoint JSON |
| `Static_ip_sync.php` | `router-sync`, `static-ip-sync/*` | Sinkronisasi static IP/ARP dan cek isolir static |
| `Monitoring.php` | `monitoring/*` | Monitoring dashboard realtime, snapshot JSON, check now, config interface |
| `NetworkMap.php` | `network/*` | Fiber network map, CRUD router/OLT/ODC/ODP untuk visualisasi jaringan |
| `Master_references.php` | `master-references/*` | Master lokasi dan master OLT |
| `Workorders.php` | `workorders/*` | Work order teknisi, status selesai, notifikasi Telegram |
| `Helpdesk.php` | `helpdesk/*` | Ticket list/detail, create ticket, reply, attachment, update status |
| `Helpdesk_dashboard.php` | `helpdesk/dashboard` | Dashboard helpdesk dan export PDF/Excel |
| `Helpdesk_report.php` | `helpdesk-report/*` | Laporan helpdesk PDF |
| `Teknisi_dashboard.php` | `teknisi-dashboard` | Dashboard performa teknisi dan export PDF |
| `Ont.php` | `ont/*` | ONT list, online, offline, detail monitoring |
| `Ont_remote.php` | `ont-remote/*` | Detail ONT by customer, set wifi, reboot, connected devices, summary |
| `Routers.php` | `routers/*`, `settings/routers/*` | CRUD router, test koneksi, upload logo, metadata branding invoice |
| `Routeracs.php` | `router-acs/*`, `settings/router-acs/*` | Pengaturan dan test koneksi ACS per router |
| `Settings.php` | `settings/*`, `pppoe-sync/*` | Settings Telegram, database, PPPoE sync, migrasi secret |
| `Users.php` | `users/*` | CRUD user dan assignment `router_scope_id` |
| `User_logs.php` | `user-logs` | Audit log aktivitas user |
| `Notification.php` | `notification/*` | Realtime notification JSON endpoint dan auth Pusher |
| `Telegram_webhook.php` | `telegram_webhook` | Telegram callback, credential reveal, callback work order |
| `CronNotification.php` | `cron-notification/*` | Notifikasi invoice overdue, router down, ticket pending, inventory minimum |
| `Worker.php` | CLI | Worker queue `php index.php worker run` |
| `Migrate.php` | CLI | Menjalankan migration terbaru |

#### Controller pendukung / legacy / compatibility

| Controller | Catatan |
| --- | --- |
| `Cashflow.php` | Modul cashflow generasi lama; route utama kini menunjuk ke `Cashflow_ui.php` |
| `Tickets.php` | Ticket module sederhana; masih ada, tetapi modul helpdesk lebih lengkap dan tampak lebih aktif |
| `Provisioning.php` | Endpoint provisioning lama yang masih menggabungkan create customer + secret + assign teknisi + Telegram |
| `System_monitoring.php` | Pendahulu monitoring UI; route aktif sekarang memakai `Monitoring.php` |
| `Work_order.php` | Controller work order lama / action per status |
| `Tenant_dashboard.php` | Sisa mode SaaS / multitenant |

### Model Utama dan Fungsinya

| Model | Tabel/Domain yang ditangani | Fungsi utama |
| --- | --- | --- |
| `Customer_model.php` | `customers` | CRUD pelanggan, uniqueness PPPoE, query router-aware |
| `Billing_automation_model.php` | `customers`, `customer_services`, `invoices`, `payments`, `cashflow_transactions` | Query billing inti, nomor invoice, dashboard metrics, payment linkage |
| `CustomerUpgrade_model.php` | `customer_service_history`, `invoices` | History upgrade dan pembuatan invoice prorate |
| `Ppp_profile_model.php` | `ppp_profiles` | Service plan / paket internet |
| `Ip_pool_model.php` | `ip_pools` | Pool IP dan usage |
| `Master_reference_model.php` | `master_locations`, `master_olts` | Master referensi lokasi / OLT |
| `Ticket_model.php` | `tickets`, `ticket_replies`, `ticket_attachments` versi code | Query ticket, SLA, reply, attachment |
| `Helpdesk_stats_model.php` | `tickets` | Statistik dashboard helpdesk |
| `Monitoring_model.php` | monitoring snapshot/log | Data monitoring router, interface, isolir sync |
| `NetworkMap_model.php` | `routers`, `fiber_odc`, `fiber_odp`, `master_olts`, `customers` | Data peta fiber |
| `Router_model.php` | `routers` | CRUD router, credential terenkripsi, branding invoice, ACS |
| `Settings_model.php` | `settings_*`, `telegram_*`, `routers` | Ambil/simpan konfigurasi sistem dan Telegram |
| `Notification_model.php` | `notifications` | Storage notifikasi user |
| `Ont_device_model.php` | `ont_devices` | Relasi device ONT/ACS |
| `Tr069_acs_model.php` | TR-069 / FreeACS | Operasi remote ONT berbasis customer |
| `Static_ip_sync_model.php` | static sync domain | Sinkronisasi static IP dan audit isolir |
| `User_model.php` | `users` | CRUD user dan role/scope |
| `User_activity_log_model.php` | `user_activity_logs` | Audit log aktivitas |
| `Teknisi_dashboard_model.php` | work order / ticket domain | Statistik performa teknisi |

### Library/Service Layer Penting

| Library | Fungsi |
| --- | --- |
| `Billing_automation_service.php` | Generate invoice, record payment, suspend customer |
| `MikrotikManager.php` | Integrasi MikroTik tingkat tinggi |
| `Genieacs.php` | Client integrasi GenieACS |
| `Pusher_lib.php` | Realtime notification melalui Pusher |
| `JobDispatcher.php` | Dispatch async job ke Redis/database |
| `Router_monitoring_service.php` | Monitoring router dan interface |
| `Telegram_service.php` / `Telegram_notifier.php` | Pengiriman pesan Telegram |

### View Utama dan Halaman yang Dihasilkan

| View | Halaman |
| --- | --- |
| `application/views/layout/main.php` | Layout utama seluruh aplikasi setelah login |
| `application/views/layout/header.php` | Header, router switcher, notification dropdown, profile menu |
| `application/views/layout/sidebar.php` | Struktur menu produk yang aktif |
| `application/views/auth/login.php` | Halaman login |
| `application/views/dashboard/index.php` | Dashboard admin utama |
| `application/views/customers/list.php` | Daftar pelanggan |
| `application/views/customers/create.php` | Form tambah pelanggan |
| `application/views/customers/form.php` | Form edit pelanggan / partial |
| `application/views/customers/upgrade_page.php` | Upgrade paket pelanggan |
| `application/views/billing/list.php` | Daftar invoice |
| `application/views/cashflow/list.php` | Daftar cashflow |
| `application/views/workorders/list.php` | Daftar work order |
| `application/views/helpdesk/index.php` | Daftar ticket helpdesk |
| `application/views/helpdesk/create.php` | Form create ticket |
| `application/views/helpdesk/detail.php` | Detail ticket, reply, attachment, status |
| `application/views/helpdesk/dashboard.php` | Dashboard statistik helpdesk |
| `application/views/monitoring/index.php` | Monitoring router realtime |
| `application/views/network/fiber_network_map.php` | Peta jaringan fiber |
| `application/views/network/network_nodes.php` | Manajemen ODP/ODC |
| `application/views/ont/index.php` | List ONT |
| `application/views/ont_remote/index.php` | Remote action ONT per pelanggan |
| `application/views/routers/list.php` | Router list |
| `application/views/router/acs_index.php` | Konfigurasi ACS per router |
| `application/views/settings/*.php` | Halaman setting Telegram, database, PPPoE sync |
| `application/views/users/*` | User management |
| `application/views/user_logs/index.php` | User activity logs |
| `application/views/teknisi/dashboard.php` | Dashboard teknisi |

### Struktur Navigasi Produk dari Sidebar Aktif

Berdasarkan `application/views/layout/sidebar.php`, surface area aplikasi yang benar-benar ditampilkan ke user adalah:

- Dashboard / Dashboard Teknisi
- Access
  - Customers
  - Upgrade Paket
  - Service Plan
  - IP Pools
- Operations
  - Invoice
  - System Isolir Manual
  - Router Sync
  - Cashflow
- Network
  - Fiber Network Map
  - Manajemen ODP/ODC
  - Master Lokasi
  - Master OLT
- Support
  - Work Orders
  - Dashboard Teknisi
  - Helpdesk Tickets
  - Monitoring
- ONT Monitoring
  - ONT List
  - ONT Online
  - ONT Offline
- Router Management
  - Router List
  - Config ACS
- System
  - User Management
  - User Logs
- Settings
  - Telegram
  - Database

---

## 4. Flow Aplikasi Nyata

### 4.1 Login Flow

1. User membuka `auth/login`.
2. `Auth::login()` membuat captcha matematika sederhana.
3. User submit `username`, `password`, dan `captcha_answer` ke `auth/process_login`.
4. Controller melakukan:
   - validasi form
   - validasi rate limit berbasis file di `application/cache/login_attempts`
   - validasi captcha
   - cek user aktif di tabel `users`
   - `password_verify()` terhadap hash password
   - normalisasi role ke `superadmin`, `admin`, atau `teknisi`
5. Session dibuat dan di-regenerate.
6. Redirect:
   - `teknisi` -> `teknisi-dashboard`
   - selain itu -> `dashboard`

### 4.2 Flow Session dan Router Scope

1. Semua controller yang extend `MY_Controller` akan memanggil enforce login.
2. `MY_Controller` juga memeriksa idle timeout session.
3. Jika role `superadmin`, user dapat memilih router aktif dari dropdown header.
4. Jika role `admin` atau `teknisi`, aplikasi memaksa scope data ke `users.router_scope_id`.
5. Semua query penting seharusnya memakai `applyRouterFilter()` atau helper sejenis.
6. UI menampilkan badge `Distribusi: ...` untuk router aktif.

Flow ini sangat penting karena router scope adalah pemisah data utama di arsitektur produk ini.

### 4.3 Flow Tambah Pelanggan PPPoE

1. Admin membuka `customers/create`.
2. Form meminta data pelanggan, plan/PPP profile, router, dan credential PPPoE.
3. AJAX dapat dipakai untuk:
   - generate credential
   - preview/suggest remote IP
4. `Customers::store()` memvalidasi:
   - mode layanan
   - username PPP unik
   - format username/password
   - profile dan router
5. Data pelanggan disimpan ke `customers`.
6. Data layanan aktif di-upsert ke `customer_services`.
7. Secret PPP dibuat / disinkronkan ke MikroTik melalui `MikrotikManager`.
8. Invoice awal dibuat otomatis melalui `create_initial_invoice()`.
9. Sistem dapat mengirim notifikasi Telegram untuk instalasi baru.
10. Pada beberapa alur, work order instalasi juga ikut dihubungkan.

### 4.4 Flow Tambah Pelanggan Static IP

1. User memilih `service_mode = static`.
2. Controller membuang payload khusus PPPoE yang tidak relevan.
3. Pelanggan dan service tetap dibuat.
4. Invoice awal tetap bisa dibentuk.
5. Provisioning PPP secret ke router dilewati.

Artinya modul pelanggan memang dirancang untuk dua mode layanan aktif, bukan PPPoE saja.

### 4.5 Flow Upgrade Paket Pelanggan

1. User membuka `customers/upgrade`.
2. Sistem memuat konteks pelanggan aktif, paket lama, dan paket target.
3. AJAX `calculate_prorate` menghitung selisih biaya berdasarkan sisa hari bulan berjalan.
4. `CustomerUpgrade::process_upgrade()` melakukan:
   - validasi paket target
   - hitung prorate
   - update profile layanan di database
   - simpan history ke `customer_service_history`
   - buat invoice prorate bila diperlukan
   - update PPP secret/profile di MikroTik
   - disconnect PPP active agar profil baru segera aktif

### 4.6 Flow Billing dan Pembayaran

1. Invoice list ditampilkan oleh `Billing_ui::index()`.
2. Aksi bisnis ditangani `Billing.php`, antara lain:
   - generate invoice bulanan
   - generate rolling invoice
   - record payment
   - mark paid
   - mark overdue
   - edit/update invoice
   - delete / void invoice
   - auto suspend
3. Saat payment dicatat:
   - data masuk ke `payments`
   - status invoice diperbarui
   - `cashflow_transactions` dapat ikut dibuat
   - notifikasi user dibuat
4. Saat auto suspend / manual isolir dijalankan:
   - status invoice/service/customer dapat berubah
   - integrasi ke MikroTik dipanggil

### 4.7 Flow Helpdesk dan Work Order

1. Tiket dibuat dari `helpdesk/create`.
2. Sistem dapat mengambil detail PPP/customer via endpoint AJAX.
3. Tiket disimpan ke `tickets`.
4. Di detail tiket, user dapat:
   - update status
   - add reply
   - upload attachment
   - mark done
5. Dashboard helpdesk membaca statistik bulanan dan dapat export PDF/Excel.
6. Work order teknisi dikelola terpisah melalui `Workorders.php`, tetapi domainnya tetap terhubung dengan customer/service dan teknisi.
7. Perubahan status work order dapat mendorong notifikasi Telegram.

### 4.8 Flow Monitoring Router dan ONT

1. Monitoring router memakai `Monitoring.php`.
2. Dashboard melakukan polling `snapshot_json`.
3. Library monitoring membaca status router/interface, target gateway/public ping, dan alert threshold.
4. ONT monitoring memakai dua lapisan:
   - `Ont.php` untuk list/online/offline/status
   - `Ont_remote.php` untuk aksi customer-centric seperti set WiFi dan reboot
5. Endpoint API `api/Ont.php` menyediakan akses machine-to-machine dengan token/basic auth.

### 4.9 Flow Notifikasi, Queue, dan Cron

1. Event operasional tertentu menulis record notifikasi ke database.
2. Pusher dipakai untuk push realtime ke channel private user.
3. Job async dapat didorong ke Redis atau fallback database `background_jobs`.
4. `Worker.php` memproses job seperti:
   - billing generate
   - isolir
   - create secret MikroTik
   - monitoring
   - telegram send
5. Cron controller menangani pekerjaan periodik seperti:
   - generate invoice
   - overdue check
   - auto suspend
   - monitoring health check
   - static IP sync
   - notifikasi operasional

---

## 5. Backend Documentation

## 5.1 Authentication, Middleware, dan Access Control

### `application/controllers/Auth.php`

Fungsi penting:

- `login()`
- `process_login()`
- `logout()`

Logic utama:

- memakai `form_validation`
- validasi captcha aritmetika sederhana
- rate limit login berbasis file cache
- `password_verify()` untuk autentikasi
- hanya menerima user `status = active`
- normalize role legacy ke role aktif
- regenerate session saat login sukses

Interaksi database:

- baca tabel `users`
- tulis `user_activity_logs` saat login/logout

### `application/core/MY_Controller.php`

Fungsi penting:

- enforce login
- enforce HTTPS bila diperlukan
- enforce session idle timeout
- validasi user aktif dari DB
- `applyRouterScope()`
- `applyRouterFilter()`
- `share_active_router_indicator()`
- activity logging

Peran file ini sangat sentral. Dalam praktiknya, ini adalah middleware utama produk.

### `application/helpers/rbac_helper.php`

Role aktif yang terdeteksi:

- `superadmin`
- `admin`
- `teknisi`

Legacy mapping:

- `platform_owner`, `owner` -> `superadmin`
- `tenant_owner` -> `admin`
- `technician`, `tech` -> `teknisi`

### `application/helpers/router_scope_helper.php`

Fungsi penting:

- `getRouterScopeId()`
- `active_router_id()`
- `getActiveRouter()`

File ini penting untuk produk multi-router karena banyak modul membaca konteks router aktif dari helper ini, bukan langsung dari URI.

## 5.2 Customer dan Service Management

### `application/controllers/Customers.php`

Fungsi penting yang terdeteksi:

- list/index pelanggan
- create/store customer
- edit/update customer
- bulk delete
- bulk disable
- bulk generate invoice
- generate credential
- preview/suggest remote IP

Logic utama:

- mendukung mode `pppoe` dan `static`
- validasi username PPP unik lintas `customers` dan `pppoe_secrets`
- membuat atau mengupdate service di `customer_services`
- membuat invoice awal saat customer baru dibuat
- provisioning secret PPP ke MikroTik
- mengirim notifikasi Telegram untuk instalasi baru
- menegakkan router scope pada data customer

Validasi yang menonjol:

- `service_mode` wajib salah satu dari `pppoe` atau `static`
- username PPP regex-safe
- password minimum
- router/profile harus valid

Interaksi database:

- `customers`
- `customer_services`
- `pppoe_secrets`
- `invoices`
- kemungkinan linkage ke work order dan Telegram settings

### `application/controllers/CustomerUpgrade.php`

Fungsi penting:

- `index()`
- `show_form()`
- `calculate_prorate()`
- `process_upgrade()`

Logic utama:

- membaca paket aktif pelanggan
- menghitung selisih prorate
- menyimpan history upgrade
- membuat invoice prorate bila perlu
- mengubah PPP profile/remote IP
- sinkronisasi ke MikroTik dan disconnect active session

Interaksi database:

- `customer_services`
- `customer_service_history`
- `invoices`

### `application/controllers/Ppp_profiles.php`

Fungsi:

- CRUD paket/service plan
- sync profile dari router

Peran:

- menjadi master plan layanan yang dipakai customer dan billing

### `application/controllers/Ip_pools.php`

Fungsi:

- CRUD IP pool
- sync dari router
- refresh usage

Peran:

- dipakai untuk penentuan remote IP, terutama di flow upgrade dan static assignment

## 5.3 Billing dan Finance

### `application/controllers/Billing_ui.php`

Peran:

- halaman list invoice
- filter, summary, dan titik masuk UI billing

### `application/controllers/Billing.php`

Fungsi penting:

- `generate_monthly_invoices()`
- `manual_generate_invoice()`
- `auto_suspend()`
- `record_payment()`
- `view_invoice()`
- `edit_invoice()`
- `update_invoice()`
- `mark_paid()`
- `mark_overdue()`
- `delete_invoice()`
- `bulk_action()`

Logic utama:

- generate invoice bulanan dan rolling invoice
- menjaga state invoice terhadap pembayaran aktual
- menghapus/mereset payment dan cashflow bila invoice diubah dengan cara tertentu
- menghubungkan aksi billing ke notifikasi user
- menghubungkan suspend/isolir ke MikroTik

Validasi yang menonjol:

- validasi status invoice editable
- cegah `paid`/`void` masuk ke alur overdue tertentu
- cek total payment terkonfirmasi sebelum reset invoice

Interaksi database:

- `invoices`
- `payments`
- `cashflow_transactions`
- `customer_services`
- `customers`

### `application/controllers/Cashflow_ui.php`

Fungsi:

- list cashflow
- add income
- add expense
- update
- delete
- review request
- bulk action

Catatan:

- controller ini tampak sebagai modul cashflow aktif; `Cashflow.php` terlihat sebagai generasi lama.

## 5.4 Support dan Operasional Lapangan

### `application/controllers/Workorders.php`

Fungsi penting:

- `index()`
- `store()`
- `mark_done()`
- `delete()`

Logic utama:

- work order berbasis teknisi
- customer/service-aware
- role-aware
- perubahan status memicu Telegram notification

Interaksi database yang kemungkinan dominan:

- `work_orders`
- `customers`
- `users`
- kemungkinan `customer_services`

### `application/controllers/Helpdesk.php`

Fungsi penting:

- `index()`
- `create()`
- `store()`
- `detail()`
- `customer_ppp_detail()`
- `update_status()`
- `mark_done()`
- `add_reply()`
- `upload_attachment()`
- `delete()`

Logic utama:

- ticket list/detail yang cukup lengkap
- customer-aware dan router-aware
- dukungan reply dan lampiran
- ada pemetaan SLA lewat `Helpdesk_stats_model` dan `Ticket_model`

Interaksi database menurut code:

- `tickets`
- `ticket_replies`
- `ticket_attachments`

Catatan penting:

- SQL dump yang tersedia justru menunjukkan tabel `ticket_comments`, bukan `ticket_replies` / `ticket_attachments`. Ini indikasi kuat adanya drift schema yang harus diselesaikan sebelum distribusi produk.

### `application/controllers/Helpdesk_dashboard.php`

Fungsi:

- dashboard statistik bulanan
- export PDF
- export Excel

### `application/controllers/Teknisi_dashboard.php`

Fungsi:

- dashboard performa teknisi
- export PDF

### `application/controllers/Tickets.php`

Peran:

- modul ticket versi lebih sederhana

Kesimpulan:

- masih aktif secara teknis, tetapi secara kapabilitas kalah lengkap dari `Helpdesk.php`. Untuk produk yang dijual, sebaiknya salah satu dijadikan canonical dan yang lain dipensiunkan atau diposisikan jelas.

## 5.5 Network, Router, dan Device Operations

### `application/controllers/Monitoring.php`

Fungsi:

- dashboard monitoring
- snapshot JSON realtime
- manual check sekarang
- simpan interface config

Library terkait:

- `Monitoring_model`
- `Router_monitoring_service`

### `application/controllers/NetworkMap.php`

Fungsi:

- `index()` untuk fiber network map
- `nodes()` untuk manajemen node
- CRUD router/OLT/ODC/ODP dalam konteks visualisasi jaringan

Catatan:

- modul ini menunjukkan produk bukan hanya billing app, tetapi juga network operations panel.

### `application/controllers/Static_ip_sync.php`

Fungsi:

- halaman router sync
- jalankan sync ARP/static IP
- cek isolir static

### `application/controllers/Ont.php`

Fungsi:

- list ONT
- filter online/offline
- detail monitoring ONT

### `application/controllers/Ont_remote.php`

Fungsi:

- detail remote by customer
- set WiFi
- reboot
- baca connected devices
- summary

Library/model terkait:

- `Tr069_acs_model`
- `Ont_device_model`

### `application/controllers/Routers.php`

Fungsi:

- CRUD router
- test koneksi RouterOS API
- upload brand logo
- simpan atribut invoice branding dan konfigurasi ACS per router

### `application/controllers/Routeracs.php`

Fungsi:

- edit dan test koneksi ACS untuk router tertentu

### `application/controllers/Settings.php`

Fungsi yang tampak aktif:

- `telegram()`
- `database()`
- `pppoe_sync()`
- `sync_pppoe_now()`
- `migrate_ppp_secret()`
- beberapa `save_*` method

Catatan:

- file ini memadukan system setting dan operasi sinkronisasi data router, sehingga skalanya cukup besar.

## 5.6 Notification, Async Worker, Cron, dan Integrasi

### `application/controllers/Notification.php`

Fungsi:

- ambil daftar notifikasi terbaru
- hitung unread
- mark read
- mark all read
- auth private channel Pusher

### `application/controllers/Telegram_webhook.php`

Fungsi:

- menerima callback query Telegram
- memetakan tombol inline ke aksi operasional
- mengembalikan info PPP username/password/VLAN
- menandai work order selesai untuk skenario tertentu

### `application/controllers/Worker.php`

Fungsi:

- menjalankan queue worker
- reserve job dari Redis atau database
- retry/backoff
- resolve handler job

Job handler yang tersedia di `application/jobs/`:

- `BillingGenerateJob.php`
- `IsolirJob.php`
- `MikrotikCreateSecretJob.php`
- `MonitoringJob.php`
- `TelegramSendJob.php`

### Controller cron yang terdeteksi

| Controller | Fungsi |
| --- | --- |
| `cron/Billing_cron.php` | Generate invoice dan auto suspend |
| `cron/Invoice_cron.php` | Generate invoice dan check overdue |
| `cron/Monitoring_cron.php` | Health check monitoring, token-aware |
| `cron/Static_ip_cron.php` | Static IP sync dan check isolir |
| `CronNotification.php` | Operasional notifikasi berkala |
| `Helpdesk_cron.php` | SLA check helpdesk |

### API eksternal

`application/controllers/api/Ont.php` menyediakan:

- `set_wifi`
- `reboot`
- `connected_devices`

Auth yang dipakai:

- token
- optional Basic Auth

---

## 6. Frontend Documentation

### Pola Frontend yang Digunakan

Frontend project ini adalah **server-rendered dashboard application** berbasis:

- Bootstrap 5.3
- Tabler Icons
- Bootstrap Icons
- Chart.js
- SweetAlert2
- Choices.js
- Pusher JS

Bukan SPA penuh, tetapi cukup interaktif karena banyak halaman memakai AJAX/fetch.

### Layout Frontend Aktif

Urutan layout yang terlihat aktif:

1. controller membangun `$content`
2. wrapper masuk ke `application/views/layout/main.php`
3. `main.php` memuat:
   - `layout/header.php`
   - `layout/sidebar.php`
   - `layout/footer.php`
4. beberapa wrapper legacy masih ada di `application/views/layouts/master.php`

### Komponen UI Global

#### Header

`application/views/layout/header.php` memuat:

- brand/logo
- tombol collapse sidebar
- superadmin router switcher
- badge router aktif
- dark mode toggle
- notification dropdown
- profile dropdown

#### Sidebar

`application/views/layout/sidebar.php` adalah sumber paling akurat untuk memetakan halaman yang benar-benar dipakai user.

Sidebar juga memperlihatkan:

- gating menu berdasarkan role
- invoice badge dan unpaid badge
- grouping fitur secara produk

### Halaman Frontend yang Tersedia

| Area | Halaman utama |
| --- | --- |
| Auth | Login |
| Dashboard | Dashboard admin, Dashboard teknisi |
| Customer | List customer, create/edit customer, upgrade paket |
| Billing | List invoice, detail invoice, edit invoice |
| Cashflow | List cashflow, add income/expense |
| Operations | Manual isolir, router sync |
| Support | Work orders, helpdesk list/detail/create, monitoring |
| Network | Fiber network map, manajemen ODP/ODC, master lokasi, master OLT |
| ONT | ONT list, online/offline, remote action |
| Router Management | Router list, config ACS |
| System | User management, user logs |
| Settings | Telegram, database, PPPoE sync |

### Interaksi User yang Menggunakan JS/AJAX

| Halaman | Interaksi |
| --- | --- |
| `customers/list.php` | bulk action via JSON |
| `customers/create.php` | generate credential, preview remote IP |
| `customers/upgrade_page.php` | modal/form upgrade, hitung prorate via AJAX |
| `helpdesk/create.php` | ambil context PPP customer |
| `helpdesk/detail.php` | update status, reply/attachment flow |
| `monitoring/index.php` | polling snapshot monitoring |
| `network/fiber_network_map.php` | fetch map data, CRUD node |
| `ont_remote/index.php` | reboot/set WiFi/detail perangkat |
| `router/acs_*` | test koneksi ACS |
| `isolir/manual.php` | isolate/release via popup dan endpoint JSON |
| `layout/header_notification.php` + `assets/js/header-notification.js` | realtime notification polling + Pusher |

### PWA

Dukungan PWA terdeteksi jelas:

- `manifest.json`
- `manifest.webmanifest`
- `pwa-sw.js`
- `offline.html`
- registration service worker di `layout/main.php` dan `auth/login.php`

Artinya aplikasi ini memang sudah didorong agar usable dari mobile browser dan dapat dipasang sebagai app ringan.

### Mobile App Wrapper

Folder `mobile-app/` bukan frontend utama, melainkan wrapper Android Capacitor yang membuka URL aplikasi web langsung.

Fakta yang terdeteksi:

- menggunakan `@capacitor/core` 7.6
- memakai `server.url`
- konfigurasi wrapper kini sudah disanitasi ke placeholder `https://example.com/rtrwnet` dan folder `mobile-app/` dikeluarkan dari baseline package server

Implikasi produk:

- mobile app bergantung pada availability server web
- tidak ada frontend native terpisah
- perubahan URL server harus diikuti sinkronisasi config Capacitor

---

## 7. Database Documentation

## 7.1 Tabel yang Terkonfirmasi dari Snapshot Audit Historis

Saat audit awal masih tersedia snapshot SQL historis di repo. Dari snapshot tersebut, tabel yang benar-benar terlihat ada adalah:

### Identity dan audit

- `users`
- `user_activity_logs`

### Customer dan service

- `customers`
- `customer_services`
- `ppp_profiles`
- `ip_pools`
- `pppoe_secrets`
- `service_plans`
- `master_locations`
- `master_olts`
- `routers`

### Finance

- `invoices`
- `invoice_items`
- `payments`
- `cashflow_categories`
- `cashflow_transactions`

### Settings dan sinkronisasi

- `settings_database`
- `settings_pppoe_sync`
- `telegram_bots`
- `telegram_groups`
- `sync_logs`
- `system_logs`

### Support dan operasional

- `tickets`
- `ticket_comments`
- `work_orders`

### Async/system

- `background_jobs`
- `migrations`

## 7.2 Tabel yang Sangat Mungkin Ada dari Code dan Migration

Tabel berikut sangat kuat indikasinya dipakai, walau tidak terlihat di SQL dump yang dianalisis:

| Tabel | Dasar analisis |
| --- | --- |
| `notifications` | dipakai `Notification_model`, controller notification, migration realtime Pusher |
| `ont_devices` | dipakai `Ont_device_model`, ONT/GenieACS migration |
| `customer_service_history` | dipakai `CustomerUpgrade_model`, migration customer upgrade |
| `fiber_odc` | dipakai `NetworkMap_model`, migration fiber network map |
| `fiber_odp` | dipakai `NetworkMap_model`, flow network nodes |
| `ticket_replies` | direferensikan `Ticket_model` |
| `ticket_attachments` | direferensikan `Ticket_model` |

Catatan penting:

- `ticket_comments` di dump kemungkinan adalah schema lama atau representasi alternatif dari `ticket_replies`.
- Sebelum produk ini dijual, schema final harus dibekukan dan disesuaikan dengan code aktif.

## 7.3 Relasi Data yang Paling Mungkin

Berikut relasi bisnis yang paling kuat dari hasil pembacaan code:

- `users` memiliki role dan, untuk non-superadmin, `router_scope_id`
- `routers` menjadi parent scope untuk banyak domain data
- `customers` terhubung ke:
  - `customer_services`
  - `invoices`
  - `tickets`
  - `work_orders`
  - kemungkinan `ont_devices`
- `customer_services` terhubung ke:
  - `ppp_profiles`
  - `routers`
  - customer aktif
- `invoices` terhubung ke:
  - `customers`
  - `customer_services`
  - `payments`
  - `cashflow_transactions`
- `work_orders` terhubung ke:
  - `customers`
  - `users` sebagai teknisi
- `tickets` terhubung ke:
  - `customers`
  - `users`
  - `ticket_replies` / `ticket_comments`
  - `ticket_attachments`

## 7.4 Field Penting yang Perlu Dipahami Developer

| Tabel | Field penting | Makna |
| --- | --- | --- |
| `users` | `id`, `username`, `password`, `role`, `status`, `router_scope_id` | Identitas login dan pembatasan scope |
| `routers` | `id`, `name`, `host`, `username`, `password`, `acs_url`, `acs_nbi_url`, `is_active` | Distribusi/router utama |
| `customers` | `id`, `name`, `phone`, `address`, `router_id`, `pppoe_username`, `ip_address`, `status` | Master pelanggan |
| `customer_services` | `id`, `customer_id`, `ppp_profile_id`, `price`, `router_id`, `pppoe_username`, `status` | Service aktif pelanggan |
| `ppp_profiles` | `id`, `name`, `price`, `router_id`, `local_address`, `remote_pool` | Paket internet / service plan |
| `invoices` | `id`, `invoice_number`, `customer_id`, `customer_service_id`, `status`, `total_amount`, `due_date`, `router_id`, `invoice_type` | Tagihan pelanggan |
| `payments` | `id`, `invoice_id`, `amount`, `method`, `payment_date`, `status` | Pembayaran invoice |
| `cashflow_transactions` | `id`, `type`, `amount`, `invoice_id`, `payment_id`, `category_id` | Ledger cashflow |
| `tickets` | `id`, `ticket_number`, `customer_id`, `assigned_to`, `status`, `priority`, `router_id` | Ticket support |
| `work_orders` | `id`, `customer_id`, `assigned_to`, `status`, `router_id` | Tugas teknisi |

## 7.5 Estimasi Cerdas Skema Produk

Jika database akan dipaketkan untuk dijual, schema inti yang saya anggap wajib distabilkan adalah:

- user + role + router scope
- router
- customer
- customer service
- ppp profile
- ip pool
- invoice
- payment
- cashflow transaction
- ticket + reply/attachment
- work order
- notification
- ont device
- customer service history
- fiber node map
- background jobs

---

## 8. Konfigurasi Project

### File Konfigurasi Penting

| File | Fungsi |
| --- | --- |
| `application/config/config.php` | base URL, session, cookie, CSRF, hardening security, auth tunables |
| `application/config/routes.php` | route aktif seluruh modul |
| `application/config/database.php` | koneksi database |
| `application/config/app.php` | branding aplikasi |
| `application/config/pusher.php` | kredensial Pusher |
| `application/config/mikrotik.php` | koneksi MikroTik legacy/global |
| `application/config/genieacs.php` | parameter umum GenieACS |
| `application/config/tr069.php` | integrasi TR-069 / FreeACS |
| `application/config/monitoring.php` | threshold monitoring, refresh, cache, cron token |
| `application/config/queue.php` | Redis/database queue |

### `config.php`

Temuan utama:

- `base_url` dibentuk dinamis dari `HTTP_HOST` dan `SCRIPT_NAME`
- `force_https` kini hanya aktif dari `APP_FORCE_HTTPS`/proxy HTTPS, tanpa allowlist host lama
- `index_page` saat ini masih `index.php`
- `cookie_httponly = TRUE`
- `cookie_samesite = 'Lax'`
- `csrf_protection = TRUE`
- `csrf_regenerate = TRUE`
- tuning auth:
  - `auth_login_max_attempts = 8`
  - `auth_login_window_seconds = 900`
  - `auth_login_lock_seconds = 900`
  - `auth_session_idle_timeout = 600`

### `base_url`

Karena `base_url` disusun dinamis, aplikasi ini relatif portable antar domain/host, tetapi developer tetap harus memperhatikan:

- reverse proxy / HTTPS forwarding
- path subdirectory deployment
- host tertentu yang memaksa `force_https`

### CSRF Exclusion yang Terdeteksi

CSRF tidak diterapkan pada endpoint berikut:

- `api/ont/set-wifi`
- `api/ont/set_wifi`
- `api/ont/reboot`
- `api/ont/connected-devices`
- `api/ont/connected_devices`
- `telegram/webhook`
- `telegram_webhook`

Ini konsisten dengan kebutuhan webhook dan machine-to-machine API.

### `database.php`

Temuan utama:

- DB driver: `mysqli`
- host: `localhost`
- database: `rtrwnet`
- username: `root`
- password hardcoded di repository

Ini adalah temuan penting dari sisi keamanan dan kesiapan produk. Kredensial tersebut tidak seharusnya tinggal di repo final.

### Environment

`index.php` menentukan:

- `ENVIRONMENT` dari `$_SERVER['CI_ENV']`
- default fallback ke `production`
- timezone dipaksa ke `Asia/Jakarta`

Implikasi:

- local dev perlu men-set `CI_ENV=development` bila ingin debug lebih terbuka
- runtime production di repo ini mengandalkan `.env`/environment aplikasi; Nginx template tidak mengoverride `CI_ENV`

### Konfigurasi Integrasi Penting

#### `pusher.php`

- kredensial dibaca dari environment variable
- private channel prefix default: `private-user-`
- event default: `new-notification`

#### `queue.php`

- driver default: `redis`
- fallback yang didukung: `database`
- queue async aktif (`queue_enable_async = true`)
- prefix Redis default: `rtrwnet:`

#### `monitoring.php`

- refresh dashboard: 10 detik
- snapshot cache TTL: 45 detik
- target ping internal default: `192.168.88.1`
- target ping publik default: `8.8.8.8`
- cron token diambil dari `MONITORING_CRON_TOKEN`

#### `genieacs.php`

- endpoint ACS/NBI **tidak lagi** dibaca dari config global
- endpoint diambil dari kolom router:
  - `routers.acs_url`
  - `routers.acs_nbi_url`
- config file hanya menyimpan parameter client umum dan virtual parameter sync

#### `tr069.php`

- mendukung mode `soap` dan `rest`
- token API dan basic auth dapat diaktifkan via environment variable
- ada mapping parameter TR-181 dan TR-098

### Route Strategy

`routes.php` menunjukkan pola route campuran:

- dash-style dan underscore-style untuk kompatibilitas
- default controller `dashboard`
- cukup banyak alias route untuk endpoint yang sama

Kelebihan:

- kompatibel dengan URL lama

Kekurangan:

- route surface area membesar dan lebih sulit diaudit

---

## 9. Security dan Authentication

### Mekanisme Login

Fitur keamanan login yang benar-benar terdeteksi:

- password hash diverifikasi dengan `password_verify()`
- captcha aritmetika sederhana
- rate limit login berbasis file
- user harus `status = active`
- role harus valid
- session di-regenerate setelah login sukses

### Session Management

Temuan utama:

- session driver: `files`
- session cookie `httponly`
- idle timeout aplikasi: 10 menit
- `MY_Controller` memaksa logout bila user tidak aktif atau role invalid

### Role dan Authorization

Role aktif:

- `superadmin`
- `admin`
- `teknisi`

Pemisahan akses utama:

- `superadmin`
  - dapat melihat semua data
  - dapat mengganti router aktif
- `admin`
  - dibatasi `router_scope_id`
- `teknisi`
  - dibatasi `router_scope_id`
  - diarahkan ke dashboard teknisi

### Proteksi Request

Proteksi yang terdeteksi:

- CSRF aktif global
- pengecualian CSRF untuk endpoint machine-to-machine dan webhook
- security headers di `index.php`, `.htaccess`, dan template Nginx
- hardening `.htaccess` untuk blok file sensitif
- blok dotfiles dan manifest sensitif di Nginx template

### Area Risiko yang Terlihat

1. **Hardcoded secret**
   - `application/config/database.php`
   - `application/config/mikrotik.php`
   - beberapa default credential/fallback di config integrasi
2. **Schema drift**
   - model helpdesk/ticket tidak sepenuhnya cocok dengan SQL dump
3. **Legacy module coexistence**
   - meningkatkan risiko developer memakai controller/model lama secara tidak sengaja
4. **`index_page` masih `index.php`**
   - berbeda dengan banyak deployment modern yang ingin clean URL
5. **Role matrix helper tidak selalu identik dengan seluruh menu/route**
   - perlu audit konsistensi akses di semua controller

---

## 10. Deployment

## 10.1 Requirement Sistem

Berdasarkan installer Ubuntu dan dependency project, requirement realistis untuk environment baru adalah:

- Ubuntu 22.04 atau 24.04
- Nginx
- PHP 8.1 atau 8.3
- ekstensi PHP:
  - `mysqli`
  - `curl`
  - `mbstring`
  - `xml`
  - `zip`
  - `gd`
  - `intl`
  - `bcmath`
  - `opcache`
- MySQL atau MariaDB kompatibel
- Composer dependency vendor
- Redis untuk queue yang optimal
- akses ke MikroTik RouterOS API
- opsional:
  - Pusher
  - Telegram bot/group
  - GenieACS / FreeACS

Catatan:

- meskipun `composer.json` masih menyebut `php >= 5.3.7` karena basis CI3 lama, code aktual sudah memakai pola modern. Untuk implementasi riil, anggap minimum aman adalah PHP 8.1.

## 10.2 Cara Menjalankan di Local

Langkah minimal yang saya rekomendasikan:

1. Clone/copy project ke web root lokal.
2. Install dependency PHP:

```bash
composer install
```

3. Siapkan database MySQL/MariaDB.
4. Import dump database yang Anda sediakan sendiri dan sudah disanitasi:

```bash
mysql -u root -p rtrwnet < /path/ke/dump-sanitized.sql
```

5. Sesuaikan `application/config/database.php`.
6. Jalankan migration bila database target masih belum sinkron:

```bash
php index.php migrate latest
```

7. Set environment dev:
   - `CI_ENV=development`
8. Pastikan web server mengarah ke root repo ini.
9. Bila ingin clean URL, ubah:

```php
$config['index_page'] = '';
```

10. Siapkan secret integrasi melalui environment variable jika modul terkait akan dipakai.

## 10.3 Deployment ke Ubuntu dengan Nginx

Repo ini menyediakan jalur deployment yang paling resmi melalui:

- `deployment/ubuntu-installer/install.sh`
- `deployment/ubuntu-installer/config.env.example`
- `deployment/ubuntu-installer/nginx/rtrwnet.conf.template`
- `deployment/ubuntu-installer/examples/rtrwnet-worker.service.example`
- `deployment/ubuntu-installer/examples/rtrwnet-crontab.example`

Flow deployment yang didukung script:

1. Salin `deployment/ubuntu-installer/config.env.example` menjadi `deployment/ubuntu-installer/config.env`
2. Isi nilai real dan jangan biarkan placeholder password/domain
3. Pastikan source code sudah berada di `APP_ROOT`
3. Jalankan:

```bash
cd /var/www/rtrwnet/deployment/ubuntu-installer
sudo bash install.sh
```

Yang dilakukan script:

- validasi Ubuntu
- install Nginx
- install PHP-FPM beserta ekstensi
- install MySQL server
- hardening dasar MySQL
- create database dan user aplikasi
- import SQL bila `SQL_FILE` diisi
- install phpMyAdmin hanya bila `ENABLE_PHPMYADMIN=1`
- set permission project
- generate dan aktifkan config Nginx

Catatan penting dari installer:

- script mengupdate file environment runtime aplikasi di root project, misalnya `.env`, agar `application/config/database.php` bisa membaca `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, dan `DB_PASS`
- jika ingin URL tanpa `index.php`, developer tetap perlu mengubah `config.php`

## 10.4 Nginx Behavior yang Didukung

Template Nginx menunjukkan:

- document root langsung ke root project
- `try_files $uri $uri/ /index.php$is_args$args`
- static asset caching
- blok akses dotfiles, direktori non-public (`application/`, `system/`, `vendor/`, `docs/`, `deployment/`, `mobile-app/`) dan extension sensitif (`sql`, `log`, `md`, `env`, `sh`, `bak`, `old`)
- tidak mengoverride `CI_ENV`; runtime mengikuti `.env`/environment aplikasi

## 10.5 Deployment Apache

Repo tidak menyediakan installer Apache, tetapi secara teknis Apache tetap mungkin karena:

- ada `.htaccess` aktif
- rewrite dan hardening dasar sudah tersedia

Rekomendasi untuk Apache:

- aktifkan `mod_rewrite` dan `mod_headers`
- arahkan `DocumentRoot` ke root project
- set `AllowOverride All`
- pasang `SetEnv CI_ENV production`
- pertimbangkan PHP-FPM melalui `proxy_fcgi`

Status dokumentasi ini untuk Apache adalah **asumsi teknis yang masuk akal**, bukan jalur deployment yang resmi disediakan repo.

## 10.6 Queue Worker dan Cron yang Direkomendasikan

### Worker

Command worker yang terdeteksi:

```bash
php index.php worker run
```

Template systemd service canonical sekarang tersedia di:

- `deployment/ubuntu-installer/examples/rtrwnet-worker.service.example`

Butuh `redis-server` dan ekstensi Redis PHP jika `queue_driver=redis` tetap dipakai.
Gunakan isi file example tersebut apa adanya sebagai baseline, lalu sesuaikan path/owner bila struktur server berbeda.

### Cron

Template cron canonical sekarang tersedia di:

- `deployment/ubuntu-installer/examples/rtrwnet-crontab.example`

Contoh job yang masuk akal dari controller yang tersedia:

```cron
*/5 * * * * /usr/bin/php /var/www/rtrwnet/index.php cron/Invoice_cron generate >/dev/null 2>&1
*/10 * * * * /usr/bin/php /var/www/rtrwnet/index.php cron/Invoice_cron check_overdue >/dev/null 2>&1
*/10 * * * * /usr/bin/php /var/www/rtrwnet/index.php cron/Billing_cron auto_suspend >/dev/null 2>&1
*/2 * * * * /usr/bin/php /var/www/rtrwnet/index.php cron/Monitoring_cron check_health >/dev/null 2>&1
*/10 * * * * /usr/bin/php /var/www/rtrwnet/index.php cron/Static_ip_cron sync_static_ip_arp >/dev/null 2>&1
*/10 * * * * /usr/bin/php /var/www/rtrwnet/index.php CronNotification run_all >/dev/null 2>&1
*/15 * * * * /usr/bin/php /var/www/rtrwnet/index.php Helpdesk_cron check_sla >/dev/null 2>&1
```

Catatan:

- schedule di atas adalah rekomendasi operasional berbasis controller yang tersedia
- monitoring tertentu bisa memerlukan token bila dipanggil via HTTP

---

## 11. Insight Developer

### Kelebihan Struktur Project Ini

1. **Arsitektur bisnisnya sudah dekat ke produk operasional nyata**
   - bukan sekadar customer CRUD
   - modul customer, billing, support, monitoring, dan network operations saling terhubung

2. **Router-aware design cukup kuat**
   - `MY_Controller` dan helper router scope memberi pondasi multi-router yang konsisten

3. **Otomasi cukup matang**
   - invoice generation
   - auto suspend
   - telegram automation
   - async queue
   - realtime notification

4. **Surface area produk jelas**
   - sidebar aktif menunjukkan product packaging yang sudah bisa dipresentasikan ke pengguna bisnis

5. **Deployment Ubuntu sudah disiapkan**
   - ini nilai tambah besar untuk produk yang akan dijual

6. **PWA dan Android wrapper menambah nilai komersial**
   - walau wrapper masih bergantung ke web URL, secara packaging sudah membantu

### Kekurangan dan Potensi Masalah

1. **Schema dan code belum sepenuhnya sinkron**
   - paling jelas di domain helpdesk/ticket
   - ini risiko tertinggi untuk deployment baru

2. **Masih ada campuran modul legacy dan modul baru**
   - `Cashflow` vs `Cashflow_ui`
   - `Tickets` vs `Helpdesk`
   - `System_monitoring` vs `Monitoring`
   - `Tenant_*` artefact dari fase SaaS

3. **Secret sensitif masih berada di repository**
   - DB password
   - MikroTik config
   - beberapa default integrasi

4. **Naming dan struktur controller belum sepenuhnya konsisten**
   - campuran dash/underscore route
   - pasangan controller UI dan logic di sebagian modul, tetapi tidak di semua modul

5. **`index_page` masih `index.php`**
   - ini bisa membingungkan antara ekspektasi developer dan template Nginx yang sudah mendukung clean routing

6. **Surface area route cukup besar**
   - banyak alias untuk endpoint yang sama
   - bagus untuk backward compatibility, tetapi buruk untuk audit dan maintenance

### Rekomendasi Improvement

#### Prioritas tinggi

1. Bekukan schema final dan sinkronkan dengan code aktif.
2. Pindahkan seluruh secret ke environment variable atau secret manager.
3. Tentukan modul canonical dan pensiunkan modul legacy yang tidak lagi dipakai.
4. Tambahkan smoke test minimal untuk flow:
   - login
   - create customer
   - generate invoice
   - record payment
   - create ticket
   - work order done

#### Prioritas menengah

1. Rapikan naming controller dan route.
2. Pisahkan controller UI dan service/orchestration secara lebih konsisten.
3. Tambahkan dokumentasi schema database final per release.
4. Audit seluruh controller untuk memastikan `router scope` diterapkan konsisten di semua query.

#### Prioritas produk

1. Buat paket instalasi production yang juga mengisi config database secara otomatis.
2. Siapkan seed data demo untuk presentasi produk.
3. Tambahkan halaman health check deployment dan diagnostics.
4. Dokumentasikan dependency eksternal opsional versus wajib:
   - Redis
   - Pusher
   - Telegram
   - GenieACS
   - FreeACS

### Kesimpulan Audit

Codebase ini **cukup layak dijadikan produk operasional ISP**, karena modul intinya sudah saling terhubung dan tidak terasa seperti prototype CRUD biasa. Namun sebelum dijual lebih luas, dua pekerjaan harus diprioritaskan:

1. **stabilisasi schema dan canonical module**
2. **hardening secret/configuration management**

Jika dua hal itu dibereskan, fondasi teknis project ini sudah cukup kuat untuk diposisikan sebagai panel operasional ISP yang serius.

---

## Ringkasan Identifikasi Utama

### Controller utama

- `Auth`
- `Dashboard`
- `Customers`
- `CustomerUpgrade`
- `Billing_ui`
- `Billing`
- `Cashflow_ui`
- `Workorders`
- `Helpdesk`
- `Monitoring`
- `NetworkMap`
- `Ont`
- `Ont_remote`
- `Routers`
- `Routeracs`
- `Settings`
- `Users`

### Model utama

- `Customer_model`
- `Billing_automation_model`
- `CustomerUpgrade_model`
- `Ticket_model`
- `Monitoring_model`
- `NetworkMap_model`
- `Router_model`
- `Settings_model`
- `User_model`
- `Ont_device_model`
- `Tr069_acs_model`

### View utama

- `layout/main.php`
- `auth/login.php`
- `dashboard/index.php`
- `customers/list.php`
- `customers/create.php`
- `customers/upgrade_page.php`
- `billing/list.php`
- `cashflow/list.php`
- `workorders/list.php`
- `helpdesk/index.php`
- `helpdesk/detail.php`
- `monitoring/index.php`
- `network/fiber_network_map.php`
- `ont/index.php`
- `routers/list.php`

### Modul/fitur utama

- Authentication dan RBAC
- Multi-router scoping
- Customer provisioning
- Billing dan payment
- Cashflow
- Manual isolir dan auto suspend
- Helpdesk dan work order
- Monitoring router
- ONT monitoring dan remote action
- Router management dan ACS
- Fiber network map
- Notification, Telegram, queue, cron
