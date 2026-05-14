# Runbook Restore dan Debug

## 1. Restore Database dari Dump Terbaru

Gunakan dump yang Anda sediakan sendiri dan sudah disanitasi untuk environment target.
Jangan mengandalkan dump produksi yang tertinggal di source tree.

Perintah restore:

```bash
mysql -u root -p rtrwnet_prod < /path/ke/dump-sanitized.sql
```

Jika database belum ada:

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS rtrwnet_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p rtrwnet_prod < /path/ke/dump-sanitized.sql
```

## 2. Verifikasi Pasca Restore

Cek tabel inti:

```sql
SHOW TABLES;
```

Cek router aktif:

```sql
SELECT id,name,ip_address,username,is_active,status FROM routers;
```

Cek bot/group telegram:

```sql
SELECT id,bot_name,is_active FROM telegram_bots;
SELECT id,group_name,chat_id,type,is_active FROM telegram_groups;
```

Cek user login:

```sql
SELECT id,name,username,role,status FROM users;
```

## 3. Uji Integrasi MikroTik

### 3.1 Uji dari UI
- Settings > Router > tombol `Test`

### 3.2 Uji dari CLI

```bash
php /var/www/rtrwnet/index.php cron/Static_ip_cron/sync_static_ip_arp
php /var/www/rtrwnet/index.php cron/Static_ip_cron/check_static_isolir
```

## 4. Uji Integrasi Telegram

- Settings > Telegram > `Quick Test Multi-Chat`
- Pastikan bot token dan chat_id valid

## 5. Jika Muncul Error “Konfigurasi router belum lengkap”

Checklist:

1. Kolom router terisi:
   - `ip_address`
   - `username`
   - `password` atau `api_password_enc`
2. Router status aktif (`is_active=1` / `status='active'`)
3. API MikroTik hidup dan port benar (default 8728)
4. User API memiliki permission yang diperlukan

Cek cepat:

```sql
SELECT id,name,ip_address,username,LENGTH(password) pass_len,LENGTH(api_password_enc) enc_len,is_active,status
FROM routers;
```

## 6. Jika UI masih pakai cache lama

Restart web service sesuai versi PHP yang terpasang:

```bash
sudo systemctl restart php8.1-fpm
sudo systemctl restart nginx
```

## 7. Lokasi Log yang Perlu Dicek

- PHP-FPM log: `/var/log/php8.1-fpm.log`, `/var/log/php8.3-fpm.log`, atau `/var/log/php*/error.log`
- Nginx error log: `/var/log/nginx/error.log`
- CI3 logs: `application/logs/`

## 8. Backup Berkala (Rekomendasi)

Contoh backup harian:

```bash
mysqldump -u root -p --single-transaction rtrwnet_prod > /backup/rtrwnet_$(date +%F).sql
```

Retensi minimal: 7-30 hari.
