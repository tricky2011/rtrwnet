# GenieACS Runbook (Superapps Nawacore)

## Scope
Runbook ini untuk migrasi ACS dari stack lama ke GenieACS dan integrasi ke Superapps (CI3) tanpa merusak modul existing.

## 1) Cleanup ACS Lama

### Verify service lama tidak aktif
```bash
systemctl status openacs --no-pager || true
```

### Hapus stack lama (jika ada)
```bash
# Contoh legacy FreeACS docker
cd /opt/freeacs && docker compose down || true

docker rm -f freeacs-core freeacs-tr069 freeacs-webservice freeacs-web || true
rm -rf /opt/openacs /etc/openacs /opt/freeacs /etc/freeacs
```

### Drop DB lama (opsional)
```sql
DROP DATABASE IF EXISTS acs;
```

### Validasi port bersih
```bash
ss -lntp | rg ':(7547|8088)\\b' || true
```

## 2) Install GenieACS

### Dependencies
```bash
apt update
apt install -y mongodb-server mongodb-clients
npm install -g genieacs
```

### Service user + env
```bash
useradd --system --home /opt/genieacs --shell /usr/sbin/nologin genieacs || true
mkdir -p /opt/genieacs
chown -R genieacs:genieacs /opt/genieacs
```

Buat `/opt/genieacs/genieacs.env`:
```env
GENIEACS_MONGODB_CONNECTION_URL=mongodb://127.0.0.1:27017/genieacs
GENIEACS_CWMP_INTERFACE=0.0.0.0
GENIEACS_CWMP_PORT=7547
GENIEACS_NBI_INTERFACE=127.0.0.1
GENIEACS_NBI_PORT=7557
GENIEACS_FS_INTERFACE=0.0.0.0
GENIEACS_FS_PORT=7567
GENIEACS_UI_INTERFACE=0.0.0.0
GENIEACS_UI_PORT=3000
```

### systemd units
Service aktif:
- `genieacs-cwmp` (7547)
- `genieacs-nbi` (7557 localhost only)
- `genieacs-fs` (7567)
- `genieacs-ui` (3000)

```bash
systemctl daemon-reload
systemctl enable --now mongodb genieacs-cwmp genieacs-nbi genieacs-fs genieacs-ui
systemctl is-active mongodb genieacs-cwmp genieacs-nbi genieacs-fs genieacs-ui
```

### Port verification
```bash
ss -lntp | rg ':(7547|7557|7567|3000|27017)\\b'
```

## 3) Integrasi CI3 (yang sudah diimplementasi)

### Files
- `application/config/genieacs.php`
- `application/libraries/Genieacs.php`
- `application/models/Ont_device_model.php`
- `application/controllers/Ont.php`
- `application/views/ont/index.php`
- `application/views/ont/detail.php`
- `application/config/routes.php`
- `application/views/layout/sidebar.php`

### Route utama
- `GET /index.php/ont`
- `GET /index.php/ont/detail/{serial}`
- `POST /index.php/ont/reboot/{serial}`
- `POST /index.php/ont/set_wifi`
- `GET|CLI /index.php/ont/sync`

### Fungsi library
- `getDevices()`
- `getDevice($serial)`
- `rebootDevice($serial)`
- `setWifi($serial, $ssid, $password)`

## 4) Database ONT
Eksekusi SQL:
- `docs/10_MIGRATION_GENIEACS_ONT_DEVICES.sql`

## 5) Cron Sync ONT
File cron:
`/etc/cron.d/nawacore_ont_sync`

Isi:
```cron
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

*/5 * * * * root /usr/bin/php /var/www/rtrwnet/index.php ont sync >> /var/log/nawacore_ont_sync.log 2>&1
```

## 6) Security Baseline
- NBI bind ke localhost (`127.0.0.1:7557`) 
- Jangan expose port 7557 ke internet
- Validasi form `set_wifi` di backend (sudah ada rule panjang + required)
- Role guard: UI internal only (`superadmin/admin/teknisi`), sync manual restricted

## 7) Quick Test

### NBI health
```bash
curl -s 'http://127.0.0.1:7557/devices?limit=1'
```

### CI3 sync manual
```bash
cd /var/www/rtrwnet
php index.php ont sync
```
Contoh sukses:
`Sync ONT selesai. Total: N, Inserted: X, Updated: Y, Online: A, Offline: B, Failed: 0`

### Cek DB hasil sync
```sql
SELECT status, COUNT(*) FROM ont_devices GROUP BY status;
```

## 8) Troubleshooting

### Error MongoDB fail startup
- Cek `/etc/mongodb.conf` valid INI
- Cek permission `/var/lib/mongodb` dan `/var/log/mongodb`

### Error `Tabel ont_devices belum tersedia`
- Jalankan SQL migration file

### Sync CLI tidak jalan via cron
- Cek log: `/var/log/nawacore_ont_sync.log`
- Jalankan manual `php index.php ont sync`
- Pastikan path php dan project benar
