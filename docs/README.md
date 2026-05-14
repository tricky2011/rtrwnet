# RTRWNet - Dokumentasi Final (Single Install)

Dokumen pada folder ini adalah versi final terbaru setelah rollback dari eksperimen SaaS ke mode **single install** dengan dukungan:

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
