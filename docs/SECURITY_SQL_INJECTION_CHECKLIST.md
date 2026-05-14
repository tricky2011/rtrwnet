# SQL Injection Security Checklist (CI3 Nawacore)

## Status Audit
- Query utama modul (Customers, Billing, Workorders, Tickets, Cashflow) dominan sudah memakai Query Builder (`where`, `like`, `or_like`, `where_in`) sehingga input user otomatis di-escape.
- Titik raw SQL dinamis yang tersisa berada di query metadata (`SHOW COLUMNS ...`).
- Hardening sudah dipasang agar nama tabel/kolom wajib lolos whitelist regex `^[A-Za-z0-9_]+$` + validasi `table_exists()` sebelum query metadata dieksekusi.

## File yang di-hardening
- `application/controllers/Customers.php`
- `application/controllers/Provisioning.php`
- `application/models/Settings_model.php`
- `application/controllers/Tickets.php`
- `application/controllers/Workorders.php`
- `application/controllers/Telegram_webhook.php`
- `application/models/Static_ip_sync_model.php`

## Skenario Uji SQL Injection

### 1) Search Customer
- URL: `/customers?search=' OR 1=1 --`
- Expected:
  - Tidak crash.
  - Data tidak bocor lintas scope router.
  - Hasil hanya dianggap string pencarian biasa.

### 2) Search Billing
- URL: `/billing?search=' UNION SELECT 1,2,3 --`
- Expected:
  - Tidak muncul SQL error.
  - Hanya 0 hasil / hasil normal pencarian teks.

### 3) Search Work Order
- URL: `/workorders?search=%' OR SLEEP(5) --`
- Expected:
  - Tidak delay abnormal akibat SQL injected function.
  - Request diproses normal.

### 4) Search Helpdesk
- URL: `/helpdesk?search=' OR 'a'='a`
- Expected:
  - Tidak bypass filter status/router.
  - Tidak error DB.

### 5) Manual Isolir username suggestion
- Payload input keyword: `" OR 1=1 --`
- Expected:
  - Suggestion tetap terfilter normal.
  - Tidak menampilkan seluruh data tanpa batas.

### 6) Endpoint yang memakai enum/status resolver
- Trigger create/update ticket/workorder/customer dengan payload kolom manipulatif (misal via devtools):
  - `status` = ``open` OR 1=1 --``
- Expected:
  - Ditolak validasi / fallback aman.
  - Tidak membentuk query metadata berbahaya.

## Quick SQLi Smoke Test (manual)
1. Login sebagai admin/superadmin.
2. Jalankan payload di atas pada field search dan form input.
3. Cek `application/logs/log-*.php`:
   - Tidak ada error SQL syntax terkait payload.
4. Cek data hasil:
   - Tidak ada data lintas router yang bocor.
   - Tidak ada perubahan data tidak sah.

## Rekomendasi lanjutan
- Tambahkan rate-limit untuk endpoint search/suggest yang sering dipanggil.
- Aktifkan WAF rule minimal untuk pola `union select`, `sleep(`, `information_schema`.
- Jalankan periodic security scan pada endpoint GET/POST yang menerima string bebas.
