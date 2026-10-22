# RTRWNet — Indeks Dokumentasi

## Dokumentasi lengkap terbaru

Baseline source **22 Oktober 2026**. Mulai dari dokumen utama untuk spesifikasi fitur dan aturan bisnis; gunakan lampiran untuk implementasi dan operasional.

| Dokumen | Cakupan |
| --- | --- |
| [13 — Dokumentasi lengkap billing dan operasional](13_DOKUMENTASI_LENGKAP_BILLING_RTRWNET.md) | Produk, fitur per modul, role, alur pelanggan, billing 1/2 bulan, pembayaran, isolir, penagihan rumah, batas implementasi |
| [14 — Spesifikasi teknis dan data](14_SPESIFIKASI_TEKNIS_DAN_DATA.md) | Stack, arsitektur, diagram relasi, kamus field, integrasi/API, environment, kontrol akses |
| [15 — Operasional, deployment, dan pengujian](15_OPERASIONAL_DEPLOYMENT_DAN_PENGUJIAN.md) | Instalasi, migrasi, pemilihan cron billing, worker, SOP, backup/restore, troubleshooting, skenario penerimaan |
| [16 — Referensi endpoint dan source](16_REFERENSI_ENDPOINT_DAN_SOURCE.md) | Inventaris route, controller/public method, migrasi, dependency, nama environment dan sumbernya |
| [Inventaris source JSON](INVENTARIS_SOURCE_RTRWNET.json) | Data inventaris yang dapat diolah tooling/spreadsheet |

Lampiran inventaris dapat diperbarui dengan `python3 scripts/docs/generate_inventory.py` dari root repository. Dokumentasi ini tidak memuat nilai secret/data produksi dan tidak membuktikan semua integrasi sudah aktif. Perbedaan source dengan cron installer lama, serta dependensi engine billing legacy yang tidak lengkap, dijelaskan pada dokumen utama dan runbook.

## Panduan fitur tambahan

- [Siklus tagihan dua bulan dan penagihan rumah](BILLING_CYCLES_OFFLINE_COLLECTION.md)
- [WhatsApp gateway billing](WHATSAPP_GATEWAY_USAGE.md)
- [GenieACS runbook](10_GENIEACS_RUNBOOK_SUPERAPPS.md)
- [Preset vendor GenieACS](GENIEACS_VENDOR_PRESETS.md)
- [Audit teknis historis](07_DOKUMENTASI_TEKNIS_AUDIT_CODEBASE_CI3.md)

## Dokumen baseline dan pemeliharaan sebelumnya

Bagian berikut dipertahankan sebagai konteks historis. Beberapa penjelasan lama (misalnya WhatsApp hanya deeplink, scope satu router per user, atau invoice tidak pernah dihapus) sudah dilengkapi/dikoreksi oleh dokumen `13`–`16` berdasarkan source sekarang.

Dokumen baseline sebelumnya merekam kondisi setelah rollback dari eksperimen SaaS ke mode **single install** dengan dukungan:

- Multi Router MikroTik
- Multi Bot + Multi Group Telegram
- Billing, Cashflow, Work Order, Helpdesk
- PWA mobile-ready

## Isi Dokumen

1. `01_DOKUMENTASI_FINAL_SINGLE_INSTALL.md`
   - Alur sistem end-to-end
   - Struktur modul utama
   - Role & permission
   - Endpoint operasional

2. `03_RUNBOOK_RESTORE_DAN_DEBUG.md`
   - Langkah restore database
   - Verifikasi pasca-restore
   - Checklist troubleshooting

3. `04_MIGRATION_ROUTER_SCOPE_SYSTEM.sql`
   - Migration router scope engine (global filter router per role/user)

4. `05_MIGRATION_BIND_LEGACY_TO_KALISARI_ROUTER.sql`
   - Binding seluruh data legacy ke router `Kalisari`
   - Enforce `router_id` NOT NULL + FK `ON DELETE RESTRICT`
   - Tambah `users.router_scope_id` untuk role scope

5. `06_DB_REPAIR_MULTI_ROUTER_PPP_SYNC.sql`
   - Full repair DB multi-router (detect orphan, bind legacy, rebuild FK, audit akhir)
   - Enforce unique PPPoE per router: `UNIQUE(router_id, username)`

6. `06_RUNBOOK_DB_REPAIR_MULTI_ROUTER.md`
   - Cara backup, eksekusi, dan validasi hasil repair

7. `06_QUERY_AUDIT_ROUTER_AWARE.sql`
   - Query audit cepat untuk cek NULL/orphan/FK/index router-aware

## Catatan

- Folder backup historis dan dump produksi tidak lagi dipertahankan di source baseline untuk mencegah kebocoran data saat packaging.
- Jika butuh restore, gunakan dump yang Anda sediakan sendiri dan sudah disanitasi untuk environment target.
