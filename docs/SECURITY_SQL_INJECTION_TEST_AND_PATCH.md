# SQL Injection Test & Patch Checklist

Dokumen ini untuk uji keamanan internal aplikasi CI3 Nawacore terhadap SQL Injection.

## 1) Area yang sudah dipatch

Hardening sudah ditambahkan pada query metadata dinamis (`SHOW COLUMNS` / `information_schema`) agar identifier table/column wajib format aman:

- `application/controllers/Customers.php`
- `application/controllers/Provisioning.php`
- `application/controllers/Tickets.php`
- `application/controllers/Workorders.php`
- `application/controllers/Telegram_webhook.php`
- `application/models/Settings_model.php`
- `application/models/Static_ip_sync_model.php`
- `application/libraries/JobDispatcher.php`
- `application/core/MY_Controller.php` (validasi alias pada `applyRouterFilter()`)

## 2) Skenario uji SQL Injection (manual)

Gunakan akun test, bukan produksi aktif.

### Skenario A - Login bypass

- URL: `POST /index.php/auth/process_login`
- Payload:
  - `username: ' OR 1=1 --`
  - `password: bebas`
- Expected aman:
  - Login gagal.
  - Tidak ada user terautentikasi.

### Skenario B - Search endpoint

- URL:
  - `/index.php/customers?search=' OR 1=1 --`
  - `/index.php/billing?search=' OR 1=1 --`
  - `/index.php/helpdesk?search=' OR 1=1 --`
- Expected aman:
  - Halaman tetap render normal.
  - Tidak ada dump semua data karena bypass WHERE.
  - Tidak muncul SQL error mentah di UI.

### Skenario C - Numeric parameter injection

- URL contoh:
  - `/index.php/billing/view/1 OR 1=1`
  - `/index.php/customers/edit/1 OR 1=1`
- Expected aman:
  - Request ditolak/404/invalid.
  - Tidak menampilkan data record lain.

### Skenario D - Form POST action

- Coba payload di field teks (nama, deskripsi, subject):
  - `abc' OR '1'='1`
  - `x'); DROP TABLE users; --`
- Expected aman:
  - Data tersimpan sebagai teks biasa atau divalidasi gagal.
  - Tidak ada eksekusi query tambahan.

## 3) Validasi log setelah testing

Periksa log aplikasi:

```bash
tail -n 200 application/logs/log-*.php
```

Yang harus dipastikan:

- Tidak ada `You have an error in your SQL syntax` dari payload uji.
- Tidak ada query anomali yang menunjukkan injection berhasil.

## 4) Baseline teknis aman yang dipakai

- Query Builder CI3 (`where`, `like`, `where_in`) untuk input user.
- Parameter binding (`?`) pada raw SQL.
- Validasi identifier table/column via regex.
- Cast integer untuk id/router/user scope.

## 5) Rekomendasi lanjutan

- Pastikan `display_errors=Off` di production.
- `ENVIRONMENT = 'production'` saat live.
- Aktifkan WAF/rate-limit pada endpoint login.
- Tambahkan integration test keamanan untuk payload di atas.
