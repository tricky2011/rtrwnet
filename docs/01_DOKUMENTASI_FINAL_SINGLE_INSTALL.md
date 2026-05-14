# Dokumentasi Aplikasi RTRWNet

## 1. Gambaran Umum

Project ini adalah aplikasi operasional ISP/RTRWNet berbasis web yang dipakai untuk mengelola pelanggan, billing, cashflow, router, work order, helpdesk, monitoring, dan ONT/ACS dalam satu instalasi.

Kondisi implementasi saat ini:

- Mode utama: **single install** / non-SaaS
- Database aktif dari konfigurasi aplikasi: **`rtrwnet`**
- Branding aplikasi default baseline: **RTRWNet - ISP Operations Platform**
- Perusahaan pada konfigurasi branding default: **RTRWNet Operator**
- Mendukung **multi-router**, **multi-bot Telegram**, **PWA**, dan **wrapper Android**

Walaupun masih ada beberapa file terkait tenant/subscription/SaaS untuk kompatibilitas lama, alur utama aplikasi yang aktif sekarang adalah **single-install dengan router scope**.

## 2. Arsitektur Ringkas

```text
User Internal
(Superadmin / Admin / Teknisi / Demo)
        |
        v
Web App PHP CodeIgniter 3
        |
        +-- MySQL / MariaDB
        |   - customers
        |   - billing
        |   - cashflow
        |   - work_orders
        |   - tickets
        |   - routers
        |   - ont_devices
        |   - notifications
        |
        +-- MikroTik RouterOS API
        |   - PPP secret / PPP active
        |   - profile / IP pool
        |   - simple queue
        |   - address list isolir
        |   - traffic / resource / interface
        |
        +-- Telegram Bot API
        |   - notifikasi WO
        |   - notifikasi helpdesk
        |   - notifikasi monitoring
        |   - webhook callback
        |
        +-- GenieACS / TR-069 / FreeACS
        |   - ONT monitoring
        |   - reboot ONT
        |   - set WiFi ONT
        |   - baca connected devices
        |
        +-- Redis Queue
        |   - background jobs
        |
        +-- Pusher
        |   - realtime notification
        |
        +-- PWA + Android Capacitor Wrapper
```

## 3. Stack Teknologi

### 3.1 Backend

- PHP
- Framework: **CodeIgniter 3**
- Arsitektur aplikasi: MVC + custom libraries + helper + background jobs
- Composer packages yang terpasang:
  - `dompdf/dompdf`
  - `predis/predis`
  - `pusher/pusher-php-server`

### 3.2 Frontend Web

- HTML server-rendered
- tanpa build frontend terpisah; asset web utama dilayani langsung dari folder `assets/`
- CSS framework: **Bootstrap 5.3.3**
- Icons:
  - Bootstrap Icons
  - Tabler Icons
- Komponen UI tambahan:
  - `choices.js`
  - `sweetalert2`
  - `DataTables` pada beberapa halaman
- Charting:
  - **Chart.js 4**
- Peta jaringan:
  - **Leaflet**
  - **Leaflet MarkerCluster**
- Realtime browser:
  - **Pusher JS**

### 3.3 Database dan Infrastruktur

- Database utama: **MySQL / MariaDB**
- Database dari konfigurasi saat ini: **`rtrwnet`**
- Queue backend: **Redis** dengan fallback database queue
- Realtime notification: **Pusher**
- Cache offline client: **Service Worker PWA**

### 3.4 Integrasi Eksternal

- **MikroTik RouterOS API**
- **Telegram Bot API**
- **GenieACS** per router
- **TR-069 / FreeACS** untuk mode API/bridge
- **WhatsApp deeplink** untuk kirim invoice

### 3.5 Mobile

- Folder mobile terpisah: **`mobile-app/`**
- Wrapper native Android: **Capacitor 7.6.0**
- Plugin kontak: **`@capacitor-community/contacts` 7.1.0**
- Node.js minimum pada wrapper Android: **20+**
- Strategi mobile: Android WebView membuka URL server aplikasi web langsung melalui `server.url`

### 3.6 PWA

- Manifest: `manifest.json` dan `manifest.webmanifest`
- Service worker: `pwa-sw.js`
- Offline fallback: `offline.html`
- Aplikasi bisa di-install sebagai PWA dan memiliki cache asset dasar

## 4. Mode Sistem Saat Ini

Implementasi aktif sekarang memakai pola berikut:

- **Single install**: satu aplikasi utama untuk satu operasional
- **Multi-router aware**: data bisa dipisahkan berdasarkan `router_id`
- **Router scope user**:
  - `superadmin` dapat melihat semua router atau memilih router aktif
  - `admin` dan `teknisi` dibatasi ke router scope masing-masing
- **Compatibility layer SaaS** masih ada di sebagian helper/controller/migration, tetapi guard tenant di `MY_Controller` saat ini dinonaktifkan untuk mode single-install

## 5. Modul dan Fitur Utama

### 5.1 Dashboard dan Ringkasan Bisnis

Fitur dashboard yang tersedia:

- KPI total customer
- customer aktif / suspend
- total invoice unpaid
- income bulanan
- expense bulanan
- profit bulanan
- ringkasan instalasi bulan berjalan
- jumlah tiket bulan berjalan
- ringkasan PPP active
- chart revenue bulanan
- chart tren PPP active
- analytics revenue, ARPU, dan revenue by package
- ranking pencapaian teknisi
- router switcher untuk superadmin

### 5.2 Customer Management

Modul customer saat ini sudah mencakup:

- daftar customer dengan pagination dan pencarian
- create, edit, update, dan delete customer
- dukungan **2 mode layanan**:
  - PPPoE
  - Static IP
- auto generate credential PPPoE
- assign teknisi saat onboarding
- create invoice awal saat customer dibuat
- preview dan suggestion remote IP
- bulk action:
  - bulk delete
  - bulk disable
  - bulk generate invoice
- dukungan data referensi lokasi dan OLT
- penyimpanan data pelanggan yang router-aware

### 5.3 Upgrade Paket

Tersedia modul upgrade pelanggan:

- halaman upgrade paket customer
- kalkulasi prorata
- proses upgrade paket
- update profile PPP di MikroTik
- penanganan secret yang hilang saat upgrade
- penyesuaian assignment jaringan saat paket berubah

### 5.4 Service Plan dan IP Pool

Fitur service plan:

- CRUD PPP Profile / Service Plan
- sync PPP profile dari router
- pricing per profile
- soft delete / role-based delete

Fitur IP pool:

- CRUD IP Pool
- sync IP pool dari router
- refresh penggunaan IP pool
- hitung total IP, used IP, usage percent
- sinkronisasi statistik penggunaan pool ke profil paket

### 5.5 Router Management

Manajemen router saat ini meliputi:

- daftar router
- tambah / edit / hapus router
- test koneksi router
- upload logo branding per router
- router aktif / nonaktif
- koneksi API per router
- dukungan SSL, timeout, dan metadata router

Selain itu tersedia juga:

- **router switcher** di header untuk superadmin
- branding invoice / distribusi berbasis router

### 5.6 Router ACS / ONT / TR-069

Ada dua area ONT yang berbeda:

#### A. ONT Monitoring

- daftar ONT
- filter ONT online / offline
- pencarian serial ONT
- detail ONT
- reboot ONT
- update SSID dan password WiFi
- sync ONT dari GenieACS
- scope per router

#### B. ONT Remote

- pilih customer untuk remote ONT
- lihat detail ONT customer
- ubah WiFi
- reboot ONT
- lihat connected devices
- summary chart per profile dan per model ONT
- statistik total customer dengan ONT dan readiness remote

#### C. Integrasi API

- endpoint machine-to-machine:
  - `api/ont/set-wifi`
  - `api/ont/reboot`
  - `api/ont/connected-devices`
- dukungan konfigurasi:
  - GenieACS per router
  - FreeACS / TR-069 mode SOAP atau REST bridge

### 5.7 Billing

Fitur billing yang aktif:

- daftar invoice
- generate invoice bulanan
- manual generate invoice
- auto suspend
- record payment
- mark paid
- mark overdue
- edit invoice
- update invoice
- delete invoice
- bulk action invoice
- invoice detail view
- tombol kirim invoice ke **WhatsApp**
- cashflow income otomatis saat pembayaran tercatat

Karakter billing engine:

- invoice historis dipertahankan sebagai arsip
- status invoice mendukung alur unpaid / issued / overdue / paid / partially paid
- billing router-aware
- bisa dipicu lewat UI maupun cron

### 5.8 Manual Isolir dan Sync Operasional Router

Fitur operasional router:

- manual isolir user
- manual release user
- suggest user/target isolir
- mendukung isolir **PPPoE** maupun **Static**
- PPPoE isolir via profile / address-list
- Static isolir via simple queue / address-list

Fitur router sync:

- PPPoE sync
- migrasi secret PPP lama ke struktur sekarang
- static IP sync
- check static isolir
- cron sync ARP / queue

### 5.9 Cashflow

Modul cashflow berisi:

- daftar transaksi cashflow
- add income manual
- add expense manual
- update transaksi
- delete transaksi
- bulk action
- ringkasan income / expense / net
- chart cashflow
- kategori cashflow
- review request / change request pada transaksi tertentu
- pengelompokan income internet, instalasi, dan expense

### 5.10 Work Order

Work order yang tersedia saat ini:

- daftar WO dengan filter bulan dan tahun
- create WO manual
- tipe WO:
  - installation
  - maintenance
  - relocation
  - termination
  - other
- assign teknisi
- mark done
- delete WO
- notifikasi Telegram saat WO dibuat atau status berubah
- penomoran WO otomatis

Selain input manual, alur customer onboarding juga sudah mengarah ke operasional WO pemasangan.

### 5.11 Helpdesk dan Ticketing

Fitur helpdesk/ticket:

- daftar tiket
- filter periode, status, prioritas, OLT, teknisi
- create tiket gangguan
- detail tiket
- update status tiket
- add reply
- upload attachment
- mark done
- delete tiket
- lihat detail PPP customer langsung dari MikroTik
- dashboard helpdesk
- report helpdesk
- export PDF
- export Excel pada dashboard helpdesk
- SLA check via cron
- notifikasi Telegram saat tiket dibuat / berubah

Kemampuan lain yang terlihat di implementasi:

- channel statistik tiket
- category statistik
- technician statistik
- recent SLA breached

### 5.12 Dashboard Teknisi

Modul dashboard teknisi berisi:

- KPI teknisi
- target instalasi dan target tiket
- work order rows
- ticket rows
- ranking teknisi
- point rule
- filter teknisi
- export PDF

Role `teknisi` akan diarahkan ke dashboard ini saat membuka dashboard utama.

### 5.13 Monitoring Sistem

Modul monitoring cukup lengkap dan bersifat real-time:

- snapshot dashboard monitoring
- refresh otomatis
- resource router:
  - CPU
  - memory
  - disk
- PPP online total
- traffic RX/TX
- revenue hari ini
- invoice unpaid
- jumlah customer isolir
- ringkasan banyak router sekaligus
- health check manual
- health check via cron
- konfigurasi interface monitoring per router
- watchlist interface down per router
- cache snapshot monitoring
- alert CPU tinggi
- alert router unreachable
- alert interface down
- alert ping RTO
- deteksi drop PPP drastis

### 5.14 Realtime Notification

Notifikasi realtime di aplikasi mendukung:

- latest notification
- unread count
- mark read
- mark all read
- private channel per user
- public channel untuk role tertentu
- auth endpoint Pusher
- cron notification checker:
  - overdue invoice
  - router status
  - ticket pending
  - inventory minimum

### 5.15 Network Map dan Topologi Fiber

Modul jaringan fiber yang tersedia:

- Fiber Network Map
- visualisasi topologi:
  - Router
  - OLT
  - ODC
  - ODP
  - Customer
- filter router
- marker clustering
- popup metadata node
- status utilisasi ODP/ODC berdasarkan kapasitas
- manual line drawing di peta
- manajemen node ODC/ODP
- API create/update/delete router, OLT, ODC, ODP

### 5.16 Master References

Master references yang sudah ada:

- Master Lokasi
- Master OLT
- bulk update
- bulk delete

### 5.17 User Management dan Audit

Modul sistem internal:

- login / logout
- user management
- create user
- edit user
- update user
- delete user
- role management
- router scope assignment ke user
- validasi status user aktif
- user activity log
- user logs view
- demo mode banner / read only indicator

### 5.18 Settings

Area settings mencakup:

- Settings Router
- Settings Router ACS
- Settings Telegram
- Settings Database
- Settings MikroTik legacy
- Settings PPPoE Sync
- test koneksi router
- test koneksi database
- test Telegram
- konfigurasi multi bot Telegram
- konfigurasi multi group Telegram
- dispatch group berdasarkan type:
  - teknisi
  - admin
  - owner
  - alert

### 5.19 Provisioning API

Masih tersedia endpoint provisioning backend untuk:

- create customer pending
- generate username/password
- simpan ke `customers`
- simpan ke `pppoe_secrets`
- create PPP secret ke MikroTik
- assign teknisi otomatis
- kirim Telegram ke teknisi

### 5.20 PWA dan Mobile Wrapper

Fitur akses mobile:

- installable PWA
- cache asset penting
- offline fallback page
- wrapper Android via Capacitor
- membuka aplikasi web langsung dari URL server
- integrasi pemilih kontak pada browser/PWA/Capacitor

## 6. Role dan Hak Akses

### Superadmin

- akses semua modul
- dapat switch router aktif
- dapat hard delete pada modul yang mengizinkan
- dapat mengelola settings sistem
- dapat melihat semua distribusi/router

### Admin

- fokus operasional harian
- akses customer, billing, cashflow, WO, helpdesk, monitoring, router management
- umumnya dibatasi pada router scope
- pada beberapa modul delete dilakukan soft delete / nonaktif
- tidak ditujukan untuk membuat atau mengelola akses superadmin

### Teknisi

- fokus ke work order, helpdesk, dashboard teknisi, dan ONT monitoring internal
- tidak mengakses billing sensitif, cashflow, dan settings inti
- biasanya dibatasi ke router scope tertentu

### Demo

- ada indikator demo/read only di UI
- dipakai untuk mode presentasi atau akses terbatas

## 7. Integrasi Sistem

### 7.1 MikroTik

Dipakai untuk:

- PPP secret
- PPP active
- profile
- IP pool
- simple queue
- address list isolir
- interface list
- system resource
- ping health check

Konsep implementasi:

- koneksi router disimpan per router
- aksi operasional mengambil router aktif / router scope
- ada test connection dari menu router

### 7.2 Telegram

Dipakai untuk:

- notifikasi WO
- notifikasi helpdesk
- notifikasi monitoring
- notifikasi background job tertentu
- webhook callback action

Karakter implementasi:

- multi bot
- multi group
- group type aware
- mendukung inline keyboard dan callback query

### 7.3 GenieACS / TR-069 / FreeACS

Dipakai untuk:

- sinkronisasi data ONT
- remote reboot
- set WiFi
- baca connected devices

Karakter implementasi:

- URL ACS diambil per router
- tidak lagi hanya dari config global
- mendukung mode GenieACS dan FreeACS bridge

### 7.4 WhatsApp

Saat ini digunakan sebagai:

- deeplink pengiriman invoice dari halaman invoice/detail

### 7.5 Pusher

Dipakai untuk:

- notifikasi realtime di header aplikasi

### 7.6 Redis Queue

Dipakai untuk:

- background jobs
- retry job
- delayed job
- worker daemon CLI

Job yang sudah ada:

- `BillingGenerateJob`
- `IsolirJob`
- `MikrotikCreateSecretJob`
- `MonitoringJob`
- `TelegramSendJob`

## 8. Cron dan Background Process

Endpoint/command yang relevan di codebase saat ini:

### Billing

- `php index.php cron/billing_cron/generate_invoice`
- `php index.php cron/billing_cron/auto_suspend`

### Static IP

- `php index.php cron/static_ip_cron/sync_static_ip_arp`
- `php index.php cron/static_ip_cron/check_static_isolir`

### Monitoring

- `php index.php cron/monitoring_cron/check_health`

### Helpdesk

- `php index.php helpdesk_cron/check_sla`

### Notification Cron

- `/cron-notification/run-all`
- `/cron-notification/check-overdue-invoice`
- `/cron-notification/check-router-status`
- `/cron-notification/check-ticket-pending`
- `/cron-notification/check-inventory-minimum`

### Worker Queue

- `php index.php worker run`
- `php index.php worker run once`
- `php index.php worker run once 10`

Catatan:

- beberapa cron bisa diamankan dengan token / CLI guard
- queue driver default saat ini dikonfigurasi ke **Redis**

## 9. Tabel Inti Database

Kelompok tabel penting pada implementasi sekarang:

### User dan Akses

- `users`
- `user_activity_logs`
- `notifications`

### Customer dan Layanan

- `customers`
- `customer_services`
- `pppoe_secrets`
- `ppp_profiles`
- `ip_pools`

### Billing dan Keuangan

- `invoices`
- `invoice_items`
- `payments`
- `cashflow_transactions`
- `cashflow_categories`

### Operasional

- `work_orders`
- `tickets`
- `ticket_comments`
- `ticket_attachments`

### Integrasi dan Infrastruktur

- `routers`
- `telegram_bots`
- `telegram_groups`
- `background_jobs`
- `ont_devices`

### Monitoring dan Audit

- `system_logs`
- `sync_logs`

Snapshot database tidak lagi disertakan di source baseline.
Gunakan dump terpisah yang sudah disanitasi bila butuh restore/migrasi.

## 10. Struktur Folder Penting

```text
application/
  config/          konfigurasi aplikasi, router, telegram, queue, monitoring, TR-069
  controllers/     controller fitur utama
  controllers/cron/ cron operasional
  controllers/api/ API internal / machine-to-machine
  models/          query dan business data layer
  libraries/       integrasi MikroTik, GenieACS, billing, queue, notifier
  helpers/         helper RBAC, router scope, notification, branding
  jobs/            background job handlers
  views/           UI aplikasi

assets/
  css/             custom stylesheet
  js/              custom UI script, PWA, header notification

mobile-app/
  wrapper Android Capacitor

docs/
  dokumentasi operasional, SQL dump, migration, runbook
```

## 11. Catatan Deployment

Agar aplikasi berjalan stabil, lingkungan server minimal perlu menyediakan:

- web server Apache/Nginx
- PHP dengan ekstensi umum yang dibutuhkan aplikasi
- MySQL atau MariaDB
- Redis jika queue async ingin aktif penuh
- akses jaringan ke router MikroTik
- akses internet ke:
  - Telegram API
  - Pusher
  - CDN frontend jika asset CDN tidak dipin lokal

Checklist deployment:

- restore database `rtrwnet`
- sesuaikan `application/config/database.php`
- sesuaikan base URL / HTTPS
- isi credential router
- isi credential Telegram
- isi credential Pusher jika realtime notification dipakai
- isi credential ACS/TR-069 bila ONT remote diaktifkan
- jalankan cron billing, static sync, monitoring, dan SLA
- jalankan worker queue
- siapkan backup database berkala

## 12. Kesimpulan

Secara fungsional, project ini sudah berkembang menjadi **superapp operasional ISP** yang menggabungkan:

- customer dan provisioning
- billing dan cashflow
- work order dan helpdesk
- monitoring router
- ONT/ACS management
- network topology mapping
- notifikasi realtime dan Telegram
- akses PWA dan wrapper Android

Dokumen ini menggambarkan kondisi codebase saat ini, sehingga lebih cocok dipakai sebagai dokumen onboarding, handover, dan referensi arsitektur dibanding dokumen lama yang masih terlalu ringkas.
