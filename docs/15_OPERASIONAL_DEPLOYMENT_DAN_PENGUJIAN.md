# Operasional, deployment, dan pengujian RTRWNet

Bagian dari [dokumentasi lengkap](13_DOKUMENTASI_LENGKAP_BILLING_RTRWNET.md), baseline 22 Oktober 2026. Perintah pada dokumen ini adalah panduan untuk operator; penyusunan dokumentasi tidak menjalankan migrasi, cron pengiriman pesan, perubahan router, pembayaran, atau deployment.

## 1. Prasyarat lingkungan

| Komponen | Kebutuhan |
| --- | --- |
| OS jalur installer | Ubuntu 22.04 atau 24.04 sesuai deteksi installer |
| Runtime | PHP CLI dan FPM versi yang sama; target default 8.1/8.3 mengikuti OS |
| Web server | Nginx dari installer, atau Apache yang dikonfigurasi sesuai aplikasi |
| Database | MySQL/MariaDB dan schema dasar yang cocok dengan source |
| Composer/vendor | Dependency dari lock tersedia dan lolos platform requirements |
| Queue | Database queue atau Redis; worker harus berjalan bila memakai proses async |
| Direktori writable | Cache, log, session storage yang dipakai PHP, serta lokasi upload sesuai modul |
| Jaringan | Akses dari server ke MikroTik, ACS, Telegram, WA gateway, Pusher sesuai fitur aktif |
| HTTPS/domain | Domain dan terminasi TLS/proxy konsisten dengan base URL, cookie, callback, dan mobile |
| Data referensi | Router, paket/profile, user dan akses router; bot/grup bila notifikasi dipakai |

Belum ada kapasitas minimum CPU/RAM/disk yang dibuktikan benchmark dalam audit dokumentasi ini. Tentukan kapasitas dari beban representatif dan retensi data, bukan dari jumlah menu.

## 2. Jalur instalasi yang disediakan repository

Installer berada di [deployment/ubuntu-installer/install.sh](../deployment/ubuntu-installer/install.sh). Ia menangani paket OS, Nginx/PHP-FPM, koneksi database, env, migrasi, worker, cron, dan verifikasi tertentu. Database/Redis dapat lokal atau remote sesuai config; phpMyAdmin bersifat opt-in.

Alur fresh install:

1. Siapkan source bersih di lokasi terpisah, misalnya `/opt/rtrwnet-source`. Jangan gunakan direktori runtime aktif sebagai build context.
2. Siapkan schema dasar/seed yang sudah disanitasi di luar source tree. Source saat ini tidak membawa dump dasar lengkap yang menjamin seluruh tabel produk dapat dibuat dari nol.
3. Salin [config.env.example](../deployment/ubuntu-installer/config.env.example) ke lokasi privat di luar source, lalu isi domain, source/app root, DB, SQL_FILE, secret, pilihan Redis/worker/cron.
4. Pastikan APP_ROOT fresh install sesuai persyaratan installer dan tidak menunjuk instalasi aktif berisi data yang belum diamankan.
5. Jalankan installer setelah config selesai direview. Installer dapat memasang paket dan mengubah layanan/database/config sistem.
6. Tinjau hasil verifikasi, schema, jadwal cron, runtime worker, domain/TLS dan data awal. Uji alur inti sebelum menerima transaksi riil.

Contoh pemanggilan dengan config privat yang telah diisi:

```bash
sudo env RTRWNET_INSTALLER_CONFIG=/root/rtrwnet-installer.env bash /opt/rtrwnet-source/deployment/ubuntu-installer/install.sh
```

Installer memeriksa set tabel dasar `users`, `customers`, `routers`, `background_jobs`; keberhasilan pemeriksaan itu belum membuktikan semua tabel billing/helpdesk/ONT siap. SQL import pada installer juga memiliki guard ketika database tidak kosong. Lihat output asli installer untuk menentukan tahapan yang benar-benar dijalankan.

### 2.1 Packaging

[package.allowlist](../deployment/ubuntu-installer/package.allowlist) dan [package.denylist](../deployment/ubuntu-installer/package.denylist) menentukan isi staging. `.env` live, cache/log, dump/backup, dan mobile wrapper tidak boleh ikut sebagai data runtime sembarangan.

Pada allowlist yang ditinjau, dokumentasi lengkap dan `scripts/` tidak disalin seluruhnya; hanya subfolder virtual parameter tertentu dari docs yang masuk. File root `pwa-sw.js` dan `offline.html` juga belum tercantum pada allowlist tersebut. Jadi paket hasil installer perlu diperiksa khusus untuk kelengkapan PWA; keberadaan file di working tree tidak menjamin file ikut deployment. Dokumentasi handover dapat dikirim sebagai artefak terpisah.

### 2.2 Migrasi

Runner [Migrate](../application/controllers/Migrate.php) hanya menerima CLI dan mengaktifkan migration secara lokal saat dijalankan. `application/config/migration.php` tetap default disabled untuk pemakaian normal.

```bash
cd /var/www/rtrwnet
php index.php migrate latest
```

Perintah ini **mengubah schema/data**. Jalankan pada staging atau setelah backup/review versi migrasi untuk environment tujuan. Ia menjalankan migrasi pending menurut urutan timestamp, bukan hanya migrasi fitur terakhir. Sebagian migrasi memiliki asumsi data spesifik, termasuk pengisian due day Kalisari; sejumlah `down()` bersifat non-destruktif dan tidak membalik semua perubahan.

Jangan memakai semua SQL di folder docs sebagai satu rangkaian instalasi universal. Ada SQL repair/binding data legacy dengan prasyarat instalasi tertentu. Gunakan [runbook repair](06_RUNBOOK_DB_REPAIR_MULTI_ROUTER.md) ketika konteksnya memang sesuai.

## 3. Konfigurasi awal operasional

Urutan setup aplikasi yang disarankan:

1. Isi core env: DB, environment, encryption key, callback/cron secret. Selaraskan zona waktu aplikasi dan kebutuhan jadwal.
2. Buat/verifikasi akun superadmin melalui prosedur provisioning akun instalasi; tidak ada kredensial default yang ditetapkan dokumentasi ini.
3. Tambahkan router dan tes koneksi API. Isi branding/logo bila invoice perlu identitas distribusi.
4. Sinkronkan atau buat PPP profile, harga, dan IP pool; verifikasi paket mengarah ke router yang benar.
5. Buat user admin/teknisi serta daftar router yang boleh diakses. Uji switch router dengan akun non-superadmin.
6. Isi lokasi/OLT/topologi bila dipakai, lalu konfigurasi ACS per router dan validasi customer–ONT mapping.
7. Isi Telegram bot/grup berdasarkan router dan fungsi. Untuk penagihan rumah, gunakan tipe `collection` dengan bot aktif pada router yang sama.
8. Isi WA gateway dan Pusher bila digunakan; lakukan tes terkontrol ke tujuan uji sesuai otorisasi operasional.
9. Buat customer uji PPPoE/static, invoice, payment, WO dan tiket; selesaikan verifikasi sebelum data uji dihapus sesuai prosedur.
10. Aktifkan jadwal dan worker yang sudah diuji; periksa hasil run, bukan sekadar keberadaan crontab.

Mengganti encryption key pada instalasi yang sudah menyimpan secret terenkripsi dapat memutus pembacaan secret lama. Key harus dibackup melalui mekanisme privat dan dipulihkan bersama konfigurasi saat restore.

## 4. Cron billing: pilih jalur utama

**Temuan penting:** template [rtrwnet-crontab.example](../deployment/ubuntu-installer/examples/rtrwnet-crontab.example) masih menjalankan `cron/Invoice_cron generate` dan `check_overdue`. Controller tersebut memuat `Billing_engine` yang memerlukan `payment_model`, `package_model`, helper `billing`, serta `Code_generator` yang tidak tersedia lengkap pada baseline ini.

Jalur utama yang dipakai controller Billing adalah `Billing_automation_service`, yang dapat dipanggil melalui `cron/Billing_cron generate_invoice` dan `auto_suspend`. Panduan ini tidak mengubah template installer atau crontab server. Sebelum mengaktifkan otomatisasi, periksa cron terpasang dan hindari dua generator berbeda berjalan tanpa keputusan eksplisit.

### 4.1 Daftar command

| Tujuan | Command dari root aplikasi | Efek |
| --- | --- | --- |
| Generate periode bulan berjalan | `php index.php cron/Billing_cron generate_invoice` | Membuat invoice; dapat enqueue WA |
| Auto suspend | `php index.php cron/Billing_cron auto_suspend` | Mengubah invoice/customer dan MikroTik; default grace 5 hari |
| Sync ARP/static | `php index.php cron/Static_ip_cron sync_static_ip_arp` | Sinkronisasi data jaringan/pelanggan sesuai konfigurasi |
| Rekonsiliasi isolir static | `php index.php cron/Static_ip_cron check_static_isolir` | Dapat mengubah pembatasan jaringan |
| Health monitoring | `php index.php cron/Monitoring_cron check_health` | Mengecek router dan dapat menghasilkan alert |
| Notifikasi umum | `php index.php CronNotification run_all` | Membuat/mengirim event notifikasi sesuai checker |
| SLA helpdesk | `php index.php Helpdesk_cron check_sla` | Mendeteksi breach dan dapat mengirim Telegram |
| Queue WA | `php index.php Cron_whatsapp process_queue` | Mengirim pesan nyata jika config aktif |
| Reminder due WA | `php index.php Cron_whatsapp reminder_due` | Membuat antrean reminder |
| Reminder overdue WA | `php index.php Cron_whatsapp reminder_overdue` | Membuat antrean reminder overdue |
| Collection run | `php index.php cron/Offline_collection_cron run` | Mengirim Telegram pada waktu yang diizinkan service |
| Collection preview | `php index.php cron/Offline_collection_cron preview 2026-11-10` | Ringkasan jumlah pelanggan/tujuan/bagian; tidak mengirim atau menyimpan snapshot |

CLI `generate_invoice` tidak mendeklarasikan argumen posisi untuk periode/mode. Controller membaca opsi HTTP query `period`, `mode`, `date`, `router_id`, `async`; menambahkan `2026-11` setelah nama method di CLI tidak otomatis menjadi period. Untuk generate periode tertentu, gunakan form manual di UI atau integrasi controller yang sudah diautentikasi dengan kontrak yang sesuai.

### 4.2 Contoh jadwal setelah verifikasi staging

Contoh berikut untuk format `/etc/cron.d/...` yang memiliki kolom user. Untuk `crontab -e` milik user, hapus kolom `www-data`. Sesuaikan PHP_BIN/app root dan kapasitas lingkungan. Baris ini **belum dipasang** oleh pekerjaan dokumentasi.

```cron
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# CLI ini default generate per periode bulan berjalan.
5 0 * * * www-data /usr/bin/php /var/www/rtrwnet/index.php cron/Billing_cron generate_invoice
*/10 * * * * www-data /usr/bin/php /var/www/rtrwnet/index.php cron/Billing_cron auto_suspend
*/2 * * * * www-data /usr/bin/php /var/www/rtrwnet/index.php cron/Monitoring_cron check_health
*/10 * * * * www-data /usr/bin/php /var/www/rtrwnet/index.php cron/Static_ip_cron sync_static_ip_arp
*/10 * * * * www-data /usr/bin/php /var/www/rtrwnet/index.php cron/Static_ip_cron check_static_isolir
*/10 * * * * www-data /usr/bin/php /var/www/rtrwnet/index.php CronNotification run_all
*/15 * * * * www-data /usr/bin/php /var/www/rtrwnet/index.php Helpdesk_cron check_sla

# Opsional, setelah gateway dan tujuan uji valid.
*/5 * * * * www-data /usr/bin/php /var/www/rtrwnet/index.php Cron_whatsapp process_queue
0 8 * * * www-data /usr/bin/php /var/www/rtrwnet/index.php Cron_whatsapp reminder_due
0 9 * * * www-data /usr/bin/php /var/www/rtrwnet/index.php Cron_whatsapp reminder_overdue

# Service collection memeriksa tanggal 10 dan jam >=08.00 Asia/Jakarta sendiri.
0 * * * * www-data /usr/bin/php /var/www/rtrwnet/index.php cron/Offline_collection_cron run
```

Cron OS mengikuti timezone daemon/host; aplikasi berjalan dengan Asia/Jakarta. Jadwal jam `0 8` di atas berarti 08.00 timezone cron, bukan otomatis WIB pada host UTC. Untuk waktu harian yang wajib WIB, konfigurasikan timezone scheduler yang didukung lingkungan atau konversi jamnya. Pada host UTC, 08.00 WIB setara 01.00 UTC. Guard collection memakai timezone WIB sendiri sehingga pemanggilan per jam dapat dipakai lintas timezone host.

Jadwal generate harian di atas menjalankan mode per periode: invoice diterbitkan sebelum due day dan panggilan berikutnya memeriksa overlap. Ini mendukung laporan tanggal 10 termasuk pelanggan due day 20. Jika kebijakan operasional memilih rolling harian, sesuaikan ekspektasi laporan: invoice yang belum diterbitkan tidak dapat masuk rekap.

Simpan output/run status ke mekanisme log yang dipantau sesuai lingkungan. Source beberapa cron dapat mengembalikan JSON `success: true` sambil statistik per-item memiliki failure; pantau `total_failed`, error per router/pelanggan, dan antrean pending. Jangan memakai exit code atau status service saja sebagai bukti seluruh invoice/pesan berhasil.

### 4.3 Guard HTTP

| Endpoint/keluarga | Guard di source |
| --- | --- |
| Billing_cron, Static_ip_cron | CLI atau query `token` yang cocok CRON_TOKEN/config terkait |
| Monitoring_cron | CLI atau query `token`, konfigurasi monitoring terkait |
| CronNotification | CLI atau query `key` cocok CRON_NOTIFICATION_KEY |
| Cron_whatsapp | CLI atau query `key`/`secret` atau header `X-Cron-Secret`; cocok WA_CRON_SECRET dengan fallback cron_token |
| Helpdesk_cron | CLI atau query `token` cocok config cron_token yang dimuat runtime |
| Offline_collection_cron, Invoice_cron, Migrate, Worker | CLI-only pada constructor |

Jangan menambahkan secret aktual ke contoh URL dokumentasi/tiket. Token yang benar belum menyelesaikan masalah routing, environment, maupun akses integrasi.

## 5. Worker dan antrean

File contoh unit: [rtrwnet-worker.service.example](../deployment/ubuntu-installer/examples/rtrwnet-worker.service.example). Installer merender placeholder app root, user/group, PHP_BIN, dan dependensi layanan. Default nama unit contoh `rtrwnet-worker.service` dapat diganti oleh config installer.

```bash
systemctl status rtrwnet-worker.service --no-pager
journalctl -u rtrwnet-worker.service -n 100 --no-pager
```

Command worker yang tersedia:

```bash
php index.php worker run
php index.php worker run once
```

Keduanya menjalankan job nyata, bukan dry run. Jangan menjalankan daemon tambahan di terminal produksi tanpa memeriksa worker yang sudah aktif. Mode once hanya satu job. Jika tidak ada tabel background_jobs, worker dapat berhenti tanpa memproses pekerjaan.

Pantau jumlah job pending/processing/failed, attempts, last_error, waktu available_at, serta apakah Redis dan database memakai prefix/queue yang sama. `QUEUE_DRIVER=database` adalah opsi eksplisit yang aman untuk lingkungan tanpa Redis. Bila Redis dipilih, database tetap menyimpan record job; koneksi Redis sukses bukan satu-satunya bukti job diproses.

Setelah update source, restart worker yang relevan melalui prosedur operasional. Pastikan konfigurasi pembatasan filesystem pada systemd mengizinkan semua lokasi yang benar-benar ditulis job. Template saat ini secara eksplisit memberi ReadWritePaths cache/log; job yang menulis lokasi lain perlu diverifikasi.

## 6. SOP billing harian dan bulanan

### Awal periode

1. Verifikasi harga paket, status pelanggan, router, due day, dan anchor siklus.
2. Jalankan satu mekanisme generate yang telah dipilih. Tinjau created, skipped duplicate/not cycle, failed, dan router summary.
3. Periksa sampel pelanggan bulanan, dua bulanan, suspended, serta pelanggan dengan invoice periode sebelumnya.
4. Tinjau antrean WA; invoice tersimpan dan pesan terkirim merupakan dua hasil berbeda.

### Menerima pembayaran

1. Pilih invoice dan konfirmasi pelanggan, router, periode, total, sisa, dan metode transaksi.
2. Catat nominal pembayaran; untuk tombol Lunas sistem memakai seluruh sisa invoice.
3. Periksa payment tersimpan, status invoice/saldo, dan cashflow saat lunas.
4. Periksa tunggakan lain serta hasil release isolir. Jangan menganggap pesan “lunas” membuktikan internet sudah online.
5. Bila pembersihan isolir gagal setelah payment commit, lakukan rekonsiliasi jaringan tanpa memasukkan pembayaran yang sama sekali lagi.

### Tanggal 10: kunjungan rumah

1. Pastikan pelanggan yang perlu dikunjungi ditandai `cash_visit`; jangan menebak dari histori pembayaran cash.
2. Pastikan tagihan periode berjalan sudah tersedia, termasuk pelanggan dengan due date sesudah tanggal 10.
3. Periksa grup collection dan bot aktif pada router yang benar.
4. Jalankan preview bila perlu untuk melihat jumlah pelanggan/tujuan/bagian.
5. Setelah waktu pengiriman, periksa sent_at per bagian. Snapshot laporan adalah kondisi saat snapshot dibuat, sehingga pembayaran sesudahnya perlu dicek lagi saat petugas melakukan penagihan.

### Akhir periode

Rekonsiliasi saldo invoice, pembayaran per tanggal/metode, cashflow otomatis/manual, dan transaksi koreksi. Identifikasi pembayaran sebagian yang belum masuk cashflow otomatis, void/hard delete, serta perubahan invoice yang dapat memengaruhi histori. Arsipkan laporan dan bukti transaksi sesuai kebijakan operator.

## 7. Backup, restore, dan perubahan versi

Komponen yang perlu dipulihkan bersama:

- Database beserta schema, data finansial, migrasi, user/router scope, bot/grup, dan status antrean/pengiriman.
- Konfigurasi runtime dan encryption key melalui penyimpanan privat; bukan di repository publik.
- Upload pelanggan/tiket/branding yang digunakan instalasi.
- Revisi source, dependency lock/vendor yang sesuai, serta config layanan/cron.

Cache dapat dibentuk ulang, tetapi snapshot/antrean dan bukti transaksi tidak boleh dianggap cache yang bebas dihapus. Session dan cache browser memerlukan penanganan tersendiri pada pergantian lingkungan.

Alur restore yang dapat diuji:

1. Pulihkan ke lingkungan terisolasi dengan cron/worker/pengiriman eksternal belum aktif.
2. Pulihkan schema/data serta konfigurasi privat yang cocok; pastikan tidak memakai endpoint router/gateway produksi secara tidak sengaja.
3. Cocokkan revision dan migration state; jalankan migrasi pending hanya setelah review/backup.
4. Verifikasi jumlah/agregat invoice, saldo, payment dan cashflow; cek relasi router dan sampel pelanggan.
5. Uji login/role, halaman inti, dan integrasi memakai target uji yang diizinkan.
6. Aktifkan otomasi secara terkontrol setelah lolos uji; job/pesan yang direstore dapat diproses kembali.

Target RPO/RTO dan retensi backup belum ditetapkan source. Tentukan bersama kebutuhan bisnis lalu ukur dengan simulasi restore. Panduan tambahan: [restore/debug](03_RUNBOOK_RESTORE_DAN_DEBUG.md) dan [repair multi-router](06_RUNBOOK_DB_REPAIR_MULTI_ROUTER.md).

## 8. Troubleshooting

| Gejala | Pemeriksaan awal | Tindakan berdasarkan hasil |
| --- | --- | --- |
| Halaman DB bootstrap gagal | Core env, hak baca env, koneksi/user DB, PHP mysqli | Perbaiki konfigurasi/koneksi; hindari menambah fallback credential hardcoded |
| Login gagal | Status user, role, hash, captcha, lock percobaan, session path | Cocokkan pesan/log dan tunggu lock bila berlaku; uji session/HTTPS |
| User tidak melihat pelanggan | Router aktif, user_router_access, legacy router_scope_id, router_id data | Koreksi assignment/data sesuai scope; jangan mematikan filter global |
| Generator tidak membuat invoice | Mode, bulan anchor, due date rolling, overlap non-void, status pelanggan, schema | Baca statistik skipped/failed dan invoice periode terkait |
| Cron Invoice_cron gagal load model/helper | Pemakaian engine legacy dan dependensi yang hilang | Review perpindahan ke jalur service utama; jangan menutupi error dengan jadwal lebih sering |
| Pelanggan lunas tetap terisolir | Invoice penghalang lain, router mapping, hasil isolir_release, IP aktif/secret/static | Rekonsiliasi entry/profile sesuai konteks, tanpa payment duplikat |
| Nominal dashboard berbeda dari payment | Basis query, tanggal, scope router, cashflow saat lunas, transaksi manual/koreksi | Rekonsiliasi sumber angka dan definisi laporan |
| WA pending/failed | Cron queue, URL/token, normalisasi nomor, limit/retry, respons provider | Perbaiki penyebab; resend terkontrol setelah memastikan status provider |
| Collection tidak terkirim | Tanggal/jam WIB, cash_visit, saldo/periode, grup collection, bot aktif, migrasi, sent_at | Periksa preview dan snapshot; jangan menghapus status kirim untuk mencoba ulang sembarangan |
| Laporan collection tampak lama | Snapshot bulan/router/grup sudah terbentuk | Cocokkan waktu snapshot dengan transaksi terbaru; gunakan data invoice terkini untuk eksekusi lapangan |
| ONT tidak ditemukan/offline | Router ACS, serial/customer mapping, last inform, parameter vendor, NBI | Perbaiki mapping/config; bedakan cache offline dengan kepastian perangkat mati |
| Set WiFi/reboot belum berdampak | Task diterima ACS, perangkat online/inform, parameter vendor, respons task | Verifikasi eksekusi task dan state perangkat |
| Grafik router tidak berubah | TTL snapshot, interface yang dipantau, koneksi router | Tunggu/refresh sesuai TTL dan cek konfigurasi interface |
| Job menumpuk | Worker/journal, schema status, Redis/queue/prefix, available_at, last_error | Pulihkan worker/koneksi dan cek retry; hindari duplikasi job massal |
| Export PDF gagal | Dependency Dompdf, permission/temp, input/view, memori PHP | Perbaiki runtime dan uji export dengan data representatif |
| PWA memakai UI lama/tidak offline | Versi service worker, cache browser, file deploy, allowlist | Verifikasi file terpasang dan lifecycle cache; uji browser baru serta browser lama |
| Android membuka halaman contoh | capacitor.config.json masih URL contoh | Isi domain/app identity yang benar dan sync/build ulang melalui prosedur mobile |

## 9. Skenario penerimaan fitur

Daftar berikut merupakan **rencana pengujian**, bukan hasil pengujian produksi. Gunakan staging, data fiktif, router uji, serta gateway/bot uji bila ada aksi eksternal.

| ID | Skenario | Hasil yang harus diperiksa |
| --- | --- | --- |
| AUTH-01 | Login role valid/invalid, user nonaktif, captcha salah | Penolakan/penerimaan sesuai guard, session terbentuk aman |
| ACL-01 | Admin diberi router A dan B lalu switch | Hanya router yang diizinkan dapat dipilih; konteks data mengikuti router aktif |
| ACL-02 | Akses ID customer/invoice router lain secara langsung | Tidak memperoleh data/aksi yang tidak diizinkan; uji per endpoint |
| CUS-01 | Tambah PPPoE dan simulasi router gagal | Keberhasilan data/secret/invoice/WO konsisten; kegagalan direkonsiliasi |
| CUS-02 | Tambah static dengan/tanpa paket harga positif | Tidak membuat PPP secret; invoice awal mengikuti kondisi harga/siklus |
| CUS-03 | Bulk ubah penagihan Transfer/Cash dengan campuran ID router | Hanya pelanggan yang dapat diakses berubah; metode selain transfer/cash_visit ditolak endpoint bulk |
| BILL-01 | Bulanan, due day 31 pada bulan pendek | Periode dan due date benar, nominal sesuai paket |
| BILL-02 | Siklus 2 mulai Nov, generate Nov/Des/Jan | Nov–Des satu invoice, Des skip, Jan–Feb satu invoice |
| BILL-03 | Ulang generate periode sudah paid | Tidak membuat tagihan overlap non-void |
| BILL-04 | Bandingkan rolling dan mode periodik | Tanggal penerbitan sesuai mode; due day tidak menjadi syarat mode periodik |
| BILL-05 | Pajak/diskon nol generator dan koreksi invoice | Total/saldo serta histori payment/cashflow tetap dapat direkonsiliasi |
| PAY-01 | Pembayaran sebagian lalu pelunasan | Payment bertambah benar; partially_paid ke paid; cashflow service dibuat sekali saat lunas |
| PAY-02 | Overpayment atau pelunasan kedua | Ditolak/idempotent sesuai endpoint; tidak menambah income ganda |
| PAY-03 | Lunas satu invoice, masih ada overdue lain | Isolir tidak dibuka hanya karena satu invoice lunas |
| NET-01 | Auto suspend pada batas grace dan invoice overdue manual | Target dan cutoff sesuai source; perubahan router diverifikasi |
| NET-02 | Pelunasan dengan flag MikroTik 0/1 | Bedakan pemulihan profile dan pembersihan address-list setelah commit |
| DEL-01 | Delete admin vs superadmin | Void vs hard delete dan dampak payment/cashflow sesuai kebijakan |
| UPG-01 | Upgrade dengan/tanpa prorata, tanggal 30/31 | Formula 30 hari, nominal positif dan histori/profile benar |
| UPG-02 | Upgrade pelanggan interval 2 bulan | Periksa kebijakan invoice/prorata dan overlap, jangan mengasumsikan dukungan otomatis |
| CF-01 | Admin request edit/delete, superadmin approve/reject | Request dan hasil transaksi konsisten serta tercatat |
| COL-01 | Cash visit, transfer, unspecified; invoice current/future/paid/void | Hanya data yang memenuhi filter masuk rekap router yang benar |
| COL-02 | Cron bersamaan, gagal pada bagian kedua, retry | Lock bekerja; bagian terkirim dilewati; snapshot dan nomor bagian stabil |
| COL-03 | Bayar/set Chat ID setelah snapshot terbentuk | Perilaku snapshot dan penolakan tujuan berubah dipahami operator |
| WA-01 | Enqueue, proses provider gagal, retry/resend | Status/log/respons benar; tidak menganggap pending sebagai sent |
| HD-01 | SLA LOW/MEDIUM/HIGH/URGENT, reply/upload/export | Deadline 24/12/6/2 jam, scope teknisi, lampiran dan output benar |
| ACS-01 | Sync/router mapping, set WiFi/reboot/connected devices | Tidak lintas router/customer; task/hasil perangkat dapat diverifikasi |
| MAP-01 | Tambah/ubah node, filter router, kapasitas | Struktur topologi dan scope konsisten |
| JOB-01 | Driver database/Redis, delayed/retry, worker restart | Job mencapai state akhir, error dapat ditelusuri, efek eksternal diperiksa |
| MOB-01 | PWA baru/lama dan Android URL final | Asset/service worker tersedia; cache dan akses utama sesuai ekspektasi |
| DR-01 | Restore backup ke staging | Data/saldo/secret/akses dapat dipulihkan tanpa pengiriman eksternal tak disengaja |

Skrip regresi yang sudah ada untuk fitur siklus/collection:

```bash
php scripts/tests/billing_collection_test.php
```

Skrip tersebut memakai bootstrap aplikasi/database dan tabel temporary untuk fixture; memerlukan schema terpasang dan setidaknya router yang dapat dibaca. Ia bukan test unit tanpa dependensi. Tinjau lingkungan target sebelum menjalankannya. Penyusunan paket dokumentasi ini hanya memverifikasi artefak dokumentasi dan inventaris, bukan menjalankan suite integrasi tersebut.

## 10. Memperbarui dokumentasi

Setelah perubahan route, controller, dependency atau migrasi:

```bash
python3 scripts/docs/generate_inventory.py
```

Generator hanya membaca source, `.env.example`, composer lock, dan revision Git; tidak memuat aplikasi atau `.env` live. Ia memperbarui [referensi source](16_REFERENSI_ENDPOINT_DAN_SOURCE.md) dan [inventaris JSON](INVENTARIS_SOURCE_RTRWNET.json). Daftar route/public method tetap perlu ditafsirkan manusia untuk menentukan auth, HTTP method, side effect, dan kelayakan fitur.

Perbarui dokumen fitur/teknis/runbook secara manual untuk perubahan perilaku: formula billing, pengakuan cashflow, isolir, scope, provider, migrasi, dan jadwal. Catat tanggal baseline baru dan hasil uji yang benar-benar telah dijalankan.
