# Runbook - DB Repair Multi Router PPPoE

Dokumen ini untuk eksekusi repair database multi-router tanpa menghapus data historis.

## File terkait

- SQL repair utama: `docs/06_DB_REPAIR_MULTI_ROUTER_PPP_SYNC.sql`
- Log eksekusi terakhir: `docs/06_EXECUTION_LOG_DB_REPAIR_MULTI_ROUTER.txt`

## Tujuan

- Validasi router `Kalisari` dan `Tembalang`.
- Deteksi dan perbaikan `router_id` NULL/orphan.
- Binding data legacy ke `Kalisari`.
- Rebuild FK `router_id -> routers.id` dengan `ON UPDATE CASCADE ON DELETE RESTRICT`.
- Enforce unique PPPoE per router: `UNIQUE(router_id, username)`.

## Eksekusi

1. Backup database dulu.

```bash
mysqldump -uroot -p nawacore_db > /root/backup_nawacore_before_router_repair.sql
```

2. Jalankan SQL repair.

```bash
mysql -uroot -p -D nawacore_db < docs/06_DB_REPAIR_MULTI_ROUTER_PPP_SYNC.sql \
  > docs/06_EXECUTION_LOG_DB_REPAIR_MULTI_ROUTER.txt 2>&1
```

3. Validasi hasil di log.

- Cek bagian `STEP 4.5 - VALIDASI PASCA BIND/FIX`.
- Pastikan `null_router_rows = 0` dan `orphan_router_rows = 0`.
- Cek `STEP 6/7/8 - FINAL AUDIT`.

## Catatan penting

- Script **idempotent**: aman dijalankan ulang.
- `static_packages` akan di-skip jika tabel memang tidak ada.
- Script tidak menghapus data historis.
- Jika ada error, investigasi dari file log eksekusi di atas.
