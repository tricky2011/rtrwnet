# Rencana modul Inventaris, Stock Opname dan Notifikasi Stok

Tanggal investigasi/pembaruan: **23 Oktober 2026 (Asia/Jakarta)**. Revisi Git awal: `4355ad3`; rencana pertama di-commit pada `a385bce`. Pembaruan ini mengikuti lampiran tugas terbaru dan membaca working tree, termasuk perubahan lokal yang belum di-commit.

**Status: Langkah 0 selesai; implementasi belum dimulai.** Dokumen ini adalah rancangan yang ditampilkan sebelum perubahan kode, sesuai instruksi tugas: “Berhenti dan tampilkan rencana sebelum mengubah kode.” Tidak ada migrasi, cron, koneksi database, pengiriman pesan, atau perubahan perangkat yang dijalankan dalam investigasi ini.

Rancangan terbaru menggantikan asumsi rencana pertama: seluruh perangkat pelanggan adalah **aset Bujanaya yang dipinjamkan**, penggantian tidak ditagihkan; termination otomatis membuat tugas penarikan; tiket ikut transaksi stok; minimum memakai BOM/parameter; alarm per barang/gudang hanya sekali saat low/pulih, dengan ringkasan harian terpisah; Telegram khusus grup **inventory**, bukan admin/owner. Aplikasi hanya untuk pengguna internal.

## 1. Hasil investigasi dan komponen yang dipakai ulang

Dokumentasi dasar yang ditinjau: [13 — gambaran aplikasi](13_DOKUMENTASI_LENGKAP_BILLING_RTRWNET.md), [14 — spesifikasi teknis/data](14_SPESIFIKASI_TEKNIS_DAN_DATA.md), [15 — operasional/pengujian](15_OPERASIONAL_DEPLOYMENT_DAN_PENGUJIAN.md), serta [16 — referensi endpoint/source](16_REFERENSI_ENDPOINT_DAN_SOURCE.md). Lampiran 16 merupakan hasil generator pada revisi sebelumnya; source working tree menjadi acuan implementasi.

| Area | Temuan source | Keputusan integrasi |
| --- | --- | --- |
| Inventaris | Penelusuran source aplikasi, migrasi, SQL dokumentasi, dan skrip menemukan referensi operasional pada `CronNotification`, tanpa model/service/UI/DDL ledger atau opname | Buat domain inventaris; jangan menganggap tabel runtime pasti belum ada |
| Alarm | [CronNotification::do_check_inventory_minimum](../application/controllers/CronNotification.php#L300) mencoba `inventory_items` lalu `inventory`, membaca `current_stock/stock` dan `minimum_stock/min_stock`, membandingkan `<=`, maksimal 300 baris | Pertahankan endpoint; delegasikan perhitungan harian dan ringkasan pukul 07.00 WIB kepada service; stok nol dan seluruh item/gudang tetap diperiksa |
| Deduplikasi alarm | `notify_router_roles_once()` memakai notifikasi user dalam enam jam; belum ada state pemulihan stok atau pengiriman Telegram khusus checker ini | Ganti untuk inventaris menjadi state low/normal per barang/gudang, episode transisi dan outbox; jangan memakai reminder enam jam untuk alarm kejadian |
| Router/role | [MY_Controller](../application/core/MY_Controller.php) menyiapkan login, status user, router aktif, akses `user_router_access`, RBAC, dan log aktivitas | Semua controller web baru mewarisi `MY_Controller`; gunakan `getEffectiveRouterId()`, `applyRouterFilter()`, normalisasi role dan helper scope yang ada |
| Helper scope | [router_scope_helper](../application/helpers/router_scope_helper.php) menyediakan `getRouterScopeId()` dan `active_router_id()`; default helper terakhir adalah router 1 | Jangan memakai fallback router 1 untuk operasi stok; konteks tulis wajib router eksplisit yang diizinkan |
| Model pelanggan | [Customer_model](../application/models/Customer_model.php) memiliki pola `set_router_scope()` dan adaptasi field | Gunakan referensi pelanggan yang sama; model inventaris wajib menolak non-superadmin tanpa scope, termasuk lookup ID dan query raw ber-lock |
| Master lokasi/OLT | [Master_reference_model](../application/models/Master_reference_model.php) memakai `master_locations`, `master_olts`; [SQL scope master](07_MIGRATION_MASTER_REFERENCES_ROUTER_SCOPE.sql) mendefinisikan router dan indeks | Gudang dapat mereferensikan `master_locations.id`. `master_olts` adalah referensi jaringan/topologi, bukan lokasi saldo atau master perangkat gudang; tidak dibuat ulang |
| WO daftar | [Workorders::mark_done](../application/controllers/Workorders.php#L266) memeriksa POST dan scope bila kolom tersedia, kemudian langsung update status/timestamp serta mengirim notifikasi | Delegasikan penyelesaian dan pemakaian material ke service bersama; saat ini belum ada transaksi stok/WO atau lock WO |
| WO lifecycle | [Work_order::done/activate](../application/controllers/Work_order.php), [Wo_manager::complete_wo/activate_wo](../application/libraries/Wo_manager.php) adalah jalur terpisah. `complete_wo()` menulis WO, history, notifikasi, lalu mencoba aktivasi instalasi | Integrasikan keduanya dengan transaksi penyelesaian bersama, tanpa memaksakan alur aktivasi lifecycle ke tombol daftar yang sebelumnya tidak mengaktifkan pelanggan |
| Scope WO lifecycle | [Wo_model](../application/models/Wo_model.php) mengambil/mengubah WO berdasarkan ID tanpa filter router; `Work_order` menerima ID pelaku dari POST | Tambahkan lookup WO ter-scope dan cek assignment pada jalur yang terlibat; identitas pelaku berasal dari session. ID teknisi POST hanya boleh menjadi pilihan stok yang diverifikasi |
| Status WO | Daftar mengenali `done/completed/activated/cancel`; lifecycle menggunakan `open/process/done/activated/cancel`, dan tipe maintenance dapat memiliki alias repair | Adapter field/status/tipenya tetap mengikuti schema yang tersedia, tetapi tidak menganggap WO batal sebagai keberhasilan posting material |
| WO penarikan | `Workorders::store` sudah menerima `termination` melalui `wo_type`/`type`, belum ada pencatatan aset atau otomatisasi penarikan | Buat WO jenis existing ini dari service termination; jangan membuat modul tugas penarikan kedua |
| Pelanggan berhenti | `Customers::update` memiliki cabang static/PPPoE dengan transaksi masing-masing; delete/bulk-delete dapat soft-delete/terminate service, sementara `bulk_disable` memakai suspended dan bukan termination | Hook transisi terminated/aksi berhenti ke satu service pada cabang yang relevan, bukan setiap suspend/isolir; perubahan status, WO dan pending_return harus atomik |
| Penghapusan customer | `hard_delete_customer()` menghapus relasi termasuk WO, tickets, ont_devices, customer_services sebelum customer | Untuk customer dengan aset/riwayat inventaris, gunakan berhenti/arsip; cegah hard delete yang menghilangkan referensi dan tugas penarikan |
| Helpdesk | [Helpdesk::update_status/mark_done](../application/controllers/Helpdesk.php) memanggil `Ticket_model::update_ticket_status`; mark_done daftar saat ini khusus teknisi | Delegasikan seluruh penyelesaian tiket ke satu `Inventory_service::record_ticket_usage`; perluas aksi penyelesaian sesuai matriks tugas untuk admin/superadmin |
| Jenis tiket | `Helpdesk::store` mengenali fo_cut/router_replace/adapter_replace; `Ticket_model` memetakan router_replace/adapter_replace ke category device. `Tickets::store` memakai ticket_type/type/category dengan gangguan/maintenance | Gunakan kategori device dan field jenis existing bila ada; simpan penanda penggantian yang tegas, bukan menebak dari subject. Pertahankan pembedaan adaptor quantity dan ONT serial |
| Tickets legacy | [Tickets::mark_done](../application/controllers/Tickets.php#L333) langsung update tickets tanpa transaksi material; teknisi dapat melewati cek assignment bila assigned_to kosong | Gunakan service tiket yang sama dengan Helpdesk; pekerjaan penggantian wajib teknisi yang benar-benar ditugaskan |
| Model tiket | [Ticket_model](../application/models/Ticket_model.php) memiliki scope fail-closed, normalisasi DONE/RESOLVED, dan validasi transisi. Status dan reply internal saat ini ditulis terpisah; rollback/reopen ditolak oleh flow existing | Tambah primitive lock/update/reply yang dapat ikut transaksi service; guard domain tetap bertahan bila tiket dibuka ulang lewat jalur kompatibilitas. Delete tiket bereferensi ledger ditolak sebelum menghapus lampiran/reply |
| Cashflow aktif | [Cashflow_ui](../application/controllers/Cashflow_ui.php) dan `Billing_automation_model` memakai `cashflow_transactions` dan `cashflow_categories` | Satu expense pembelian ditulis ke tabel aktif yang sama melalui model inventaris, dalam koneksi/transaksi yang sama |
| Cashflow legacy | [Cashflow_model](../application/models/Cashflow_model.php) memakai `cashflows`, bukan tabel UI aktif | Jangan memanggil `Cashflow_model::create_expense()` untuk integrasi baru dan jangan membuat tabel kas kedua |
| Mutasi cashflow lama | `Cashflow_ui` menyediakan update, delete permanen superadmin, bulk, dan approval request edit/delete | Lindungi khusus transaksi yang terhubung pembelian inventaris pada seluruh jalur tersebut, agar kas tidak terpisah dari ledger; perilaku transaksi non-inventaris tetap seperti semula |
| Serial ACS | [Ont_device_model](../application/models/Ont_device_model.php) menyimpan cache berdasarkan serial dan router bila tersedia; `ont_devices.status` adalah online/offline | Buat catatan aset serial tersendiri; hubungkan ke cache ACS/customer yang cocok tanpa mengganti status jaringan menjadi status stok |
| Bot/grup | [Settings_model](../application/models/Settings_model.php) memvalidasi teknisi/admin/owner/alert/collection; `map_group_type_legacy()` memetakan tipe tak dikenal ke general | Tambah inventory di validasi, mapping legacy (tetap inventory), pilihan `Settings::telegram`, view, dan enum type/group_type dengan mempertahankan nilai lama |
| Target ketat | `get_telegram_targets_by_router()` memfilter router/tipe, tetapi join bot masih mengizinkan bot hilang | Mode inventory wajib bot aktif yang benar-benar ada dan grup aktif; router/type yang hilang mengakibatkan tidak ada target, tidak memperluas query |
| Queue Telegram | [TelegramSendJob](../application/jobs/TelegramSendJob.php) mendukung group_type/router_id dan flag fallback, tetapi jalur generic direct chat_ids dapat melewati validasi grup; jalur global tanpa kolom router bisa melebar | Tambah cabang inventory yang memverifikasi delivery/grup/router/bot pada waktu eksekusi, sebelum jalur generic; seluruh fallback dilarang. Gunakan `JobDispatcher`/handler existing |
| Webhook | [Telegram_webhook::index](../application/controllers/Telegram_webhook.php) memverifikasi secret header sebelum callback/message; message handler saat ini berfokus token speedtest dan mengambil bot global | Tangani /stok setelah secret valid dan sebelum handler speedtest; otorisasi berdasarkan grup inventory aktif, bukan session atau chat_id dari teks |
| Jalur WO Telegram | `complete_work_order_after_speedtest()` dapat menulis customer/WO DONE langsung; ada pula helper `mark_latest_work_order_done()` | Jangan biarkan penyelesaian WO melewati material: hubungkan jalur yang aktif ke service yang sama, atau simpan bukti dan minta penyelesaian form web saat material belum lengkap. Helper tak terpakai tidak dijadikan jalur baru |
| Pola collection | [Offline_collection_service](../application/libraries/Offline_collection_service.php) memakai DateTimeImmutable WIB, guard waktu, GET_LOCK/finally RELEASE_LOCK, snapshot bagian dan retry sent_at; tipe collection tanpa fallback | Gunakan pola waktunya untuk 07.00 harian, lock/state persisten dan pemecahan HTML; data collection tidak digunakan sebagai state inventaris |
| Pelanggan aktif | `Billing_automation_model::get_active_customers_with_profile()` memakai active/suspended/isolated/isolir dan resolver service/router | Hitung COUNT DISTINCT customer per router dengan status sama serta pengecualian deleted; tidak bergantung invoice, cash_visit, harga atau keberadaan paket; tidak mengubah generator billing |
| UI/PDF | WO memakai [layout/main](../application/views/layout/main.php) dan [layout/sidebar](../application/views/layout/sidebar.php); Dompdf sudah menjadi dependency, dipakai laporan helpdesk/teknisi | Ikuti Bootstrap 5.3, form helper/CSRF, escaping, pagination, Rupiah, dan WIB; gunakan Dompdf yang tersedia |
| Alias route | [routes.php](../application/config/routes.php) memetakan pasangan dash/underscore; `translate_uri_dashes` false | Setiap endpoint baru dengan kata majemuk memiliki kedua alias; cek juga URI controller konvensional |
| Pola regresi | [billing_collection_test.php](../scripts/tests/billing_collection_test.php) bootstrap aplikasi dan memakai tabel TEMPORARY koneksi lokal | Buat regresi inventaris di database uji terisolasi; jangan menjalankan bootstrap uji yang dapat menyentuh produksi |

`scripts/docs/generate_inventory.py` adalah generator inventaris **source**, bukan seed barang. Jalankan pada tahap dokumentasi akhir setelah route/controller/migrasi baru selesai.

## 2. Batas dan keputusan rancangan

- Controller tipis; validasi bisnis, otorisasi tindakan, transaksi dan idempotensi di `Inventory_service`/`Stock_opname_service`; seluruh query, termasuk SQL lock dan adaptasi cashflow, di model.
- Semua data domain memiliki `router_id NOT NULL`. Superadmin dapat membaca gabungan router; untuk menulis harus memilih satu router. Transfer awal hanya dalam router yang sama, sesuai alur gudang–teknisi–gudang.
- Kode barang unik **per router**, `UNIQUE(router_id, code)`, agar 13 kode seed yang sama tersedia pada tiap router tanpa katalog operasional lintas scope. Ini merevisi pilihan keunikan global dalam rencana pertama. Serial tetap unik di seluruh aplikasi; konflik ditolak tanpa membocorkan detail router lain.
- Lokasi teknisi unik per `(router_id, technician_user_id)`; seorang teknisi multi-router memiliki saldo terpisah. Assignment diverifikasi terhadap `user_router_access` dan kompatibilitas `users.router_scope_id` yang sudah ada.
- Harga rata-rata bergerak dihitung per barang/router, berlaku bersama untuk lokasi-lokasinya. Transfer tidak mengubah nilai total atau harga rata-rata.
- Saldo fisik dibedakan `good` dan `damaged` agar retur rusak tidak dapat dipakai pemasangan. Laporan total tetap mengelompokkan barang/lokasi, dengan rincian kondisi dan stok layak pakai.
- Master barang/lokasi dinonaktifkan; transaksi posted, mutasi, dan relasi keuangan tidak dihapus. Barang bersaldo atau bereferensi tidak boleh diganti satuan/tracking/router-nya.
- Semua perangkat dipinjamkan; installed dan pending_return tetap aset di pelanggan. Nilai aset memakai biaya saat pemasangan/penggantian. Pemakaian/penggantian adalah biaya Bujanaya tanpa invoice, receivable atau payment baru.
- Tidak ada perubahan formula/alur billing/invoice/payment. Hook hanya pada penyelesaian WO/tiket, termination/aksi berhenti, serta pencegahan penghapusan referensi inventaris. Suspended/isolated/isolir tidak membuat WO penarikan.
- Minimum efektif dihitung per barang/gudang dengan parameter gudang pada router itu; stok teknisi/rusak/aset di pelanggan tidak membuat status gudang menjadi normal. Alarm transisi tidak diulang setiap enam jam; ringkasan harian adalah jenis pesan terpisah.
- Jika tabel `inventory_items`/`inventory` sudah ada pada lingkungan tujuan, migrasi melakukan pemeriksaan kompatibilitas dan berhenti dengan pesan jelas bila bertabrakan. Tidak menimpa/mengimpor saldo legacy secara otomatis. Schema-only staging diperlukan sebelum finalisasi DDL.

## 3. Tabel, relasi dan indeks yang direncanakan

Seluruh tabel baru: InnoDB, `utf8mb4_unicode_ci`, primary key internal, `created_by`/`created_at` pada tabel transaksi beserta detailnya. Uang `DECIMAL(15,2)`, kuantitas bertanda `DECIMAL(12,3)`, timestamp WIB. `created_by` proses sistem dapat nullable dengan identitas sumber sistem yang eksplisit; transaksi manusia wajib user valid. FK ke tabel existing disesuaikan tipe/engine aktual, tidak diasumsikan seluruh ID legacy identik. Relasi baru memakai RESTRICT, tanpa cascade delete histori.

| Tabel | Isi dan relasi utama | Constraint/indeks penting |
| --- | --- | --- |
| `inventory_categories` | Kamus kategori dasar | UNIQUE `code`; seed ONT, Aksesoris, Kabel, Splitter; kategori lain dapat ditambah sesuai kebutuhan |
| `inventory_units` | Kamus satuan dasar | UNIQUE `code`; seed unit, pcs, meter, roll |
| `inventory_items` | router, code, name, category_id, unit_id, tracking_mode serial/quantity, pack_size nullable, mode_minimum automatic/manual, minimum_stock_manual, manual_minimum_configured, is_active, audit | UNIQUE `(router_id, code)`; indeks router/active/category; qty serial selalu bilangan bulat; nilai pack_size harus positif bila diisi |
| `inventory_locations` | router, code, name, type warehouse/technician, master_location_id nullable, technician_user_id nullable, is_active | UNIQUE `(router_id, code)`; UNIQUE `(router_id, technician_user_id)`; warehouse wajib tanpa technician_user_id; juga menjadi mutex transaksi lokasi |
| `inventory_valuations` | Cache total qty, inventory_value dan average_cost per item/router | UNIQUE `(router_id, item_id)`; row lock untuk serialisasi moving average |
| `inventory_balances` | item, location, stock_condition good/damaged, quantity, audit pembaruan | UNIQUE `(item_id, location_id, stock_condition)`; qty setiap kondisi tidak negatif; indeks router/location |
| `inventory_serials` | router, item, serial_number, MAC nullable, status, current_location_id nullable, customer_id nullable, ont_device_id nullable, carrying_cost, pending_return_at nullable, ACS sync marker | UNIQUE `serial_number`; indeks router/item/status/location/customer/pending_return_at; status in_stock, with_technician, installed, pending_return, returned, damaged, lost |
| `inventory_operations` | Identitas perintah posting dan hasilnya, router, operation_type, idempotency_key, request_hash, reference_type/id, created_by/at | UNIQUE `idempotency_key`; hasil tersimpan dalam transaksi yang sama; key sama dengan payload berbeda ditolak |
| `inventory_movements` | router, operation_id, item_id, balance_domain stock/loaned_asset, location_id nullable untuk domain aset, serial_id nullable, stock_condition, movement_type, signed qty, unit_cost, value_amount, purchase_id/line_id, work_order_id, ticket_id, customer_id, opname_id/line_id, reverses_movement_id nullable, notes, created_by/at | UNIQUE `idempotency_key` per baris; indeks router/domain/location/id, router/item/id, WO/ticket/customer/opname; hanya INSERT/SELECT dari aplikasi |
| `inventory_asset_balances` | Cache qty/nilai aset dipinjamkan per router/customer/item, dapat direkonsiliasi dari domain loaned_asset ledger | UNIQUE `(router_id, customer_id, item_id)`; nonnegatif; bukan stok yang dapat dipakai teknisi |
| `inventory_serial_events` | Histori serial dan assignment/customer, old_serial_id/new_serial_id bila penggantian, operation_id, ticket_id/WO, jenis baseline/installed/pending_return/returned/lost, biaya, pelaku, waktu | Append-only; indeks serial/customer/ticket/WO; baseline legacy tercatat eksplisit |
| `inventory_usage_records` | Penanda pemakaian pertama per source WO/ticket, payload hash, operation_id, teknisi, customer, reversed operation bila ada | UNIQUE `(router_id, source_type, source_id)`; membatasi satu pemakaian seumur tiket/WO, termasuk key baru/reopen |
| `inventory_recall_tasks` | Relasi tugas ke **work_orders existing** jenis termination, customer/router, episode termination, status aktif/selesai, pelaku/waktu | UNIQUE `(router_id, customer_id, termination_episode)`; satu slot recall aktif per customer, dikunci bersama customer; tabel ini tidak menggandakan isi WO |
| `inventory_ticket_contexts` | Penanda device_replacement dan subtype ONT/quantity bila schema tiket tidak bisa menyimpan penanda lengkap; source tickets.id, router, created_by/at | UNIQUE ticket_id; hanya dibuat bila field jenis existing tidak memadai, tanpa tabel tiket baru |
| `inventory_purchases` | router, date, supplier text, funding_source, destination_location_id, total, notes, evidence_path nullable, draft/posted, cashflow_transaction_id nullable, posted_by/at | UNIQUE nomor pembelian dan key posting; UNIQUE cashflow_transaction_id bila terisi |
| `inventory_purchase_lines` | purchase, item, qty, unit_cost, line_total, detail serial yang harus diterima | Indeks purchase/item; hanya dapat diedit selama draft; posting serial dipecah menjadi mutasi per serial |
| `inventory_opnames` | router, location, nomor, status, snapshot_at, snapshot_movement_id, counting_cutoff_at/id, total selisih qty/nilai, submitted/approved/posted/rejected by/at, review note | Indeks router/location/status/waktu; kontrol satu sesi aktif melalui baris lokasi yang dikunci |
| `inventory_opname_active_locations` | location_id, opname_id, router, audit | PRIMARY/UNIQUE location_id dan UNIQUE opname_id; diisi pada draft, dilepas hanya posted/rejected, dalam transaksi yang sama |
| `inventory_opname_lines` | opname, item, condition, snapshot_qty, net_movement_qty, expected_qty, physical_qty, difference_qty, approval_unit_cost/value, reason_code/text | UNIQUE `(opname_id, item_id, stock_condition)`; alasan wajib untuk setiap selisih |
| `inventory_opname_serials` | opname/line, serial_id, expected_at_snapshot/cutoff, found/missing, counted_at, catatan | UNIQUE `(opname_id, serial_id)`; identitas serial tidak digantikan hanya oleh selisih jumlah |
| `inventory_opname_unknown_serials` | opname, scanned_serial, MAC opsional, catatan, status resolusi dan reference serial/item yang sudah diverifikasi | UNIQUE `(opname_id, scanned_serial)`; tidak otomatis menambah saldo/merampas serial milik lokasi lain |
| `inventory_opname_events` | Histori transisi status, pelaku, alasan, waktu, ringkasan versi perhitungan | Append-only; indeks opname/id; rejection dan approval dapat diaudit |
| `inventory_job_types` / `inventory_bom_lines` | Jenis pekerjaan editable per router dan kebutuhan qty per item; kode job pasang_baru, ganti_ont, ganti_adaptor, fo_putus_relokasi | UNIQUE router/job_code dan job_type/item; job baru dapat ditambah; kuantitas BOM tidak otomatis menjadi pemakaian riil |
| `inventory_minimum_policies` / `inventory_minimum_job_rates` | Parameter router/gudang: siap_pasang_baru N, faktor_target; per job gangguan customers_per_incident X | UNIQUE warehouse_id dan warehouse/job; X > 0, N >= 0, faktor_target >= 1 |
| `inventory_minimum_runs` / `inventory_minimum_results` | Snapshot pelanggan aktif, versi BOM/parameter/override, per-job incidents/contribution, minimum rumus/efektif dan target per item/gudang | Indeks router/date/warehouse/item/version; hasil lama disimpan untuk menjelaskan pesan/report |
| `inventory_minimum_overrides` | Histori perubahan mode automatic/manual, nilai manual, alasan, pelaku/waktu; berlaku per item/router untuk semua gudangnya | Append-only; alasan wajib pada override; kembali otomatis tercatat |
| `inventory_recheck_requests` | Outbox cek ulang stok/minimum pasca-commit, operation/policy change, router dan lokasi/item terdampak, status proses | UNIQUE event key; memastikan kegagalan proses sesudah commit tidak kehilangan alarm |
| `inventory_alert_states` / `inventory_alert_events` | State low/normal, episode, saldo/minimum basis, last_sent_at per item/gudang; event low/recovered append-only | UNIQUE `(router_id, item_id, warehouse_id)` dan state/episode/event_type; tidak ada cooldown reminder alarm |
| `inventory_alert_deliveries` | Outbox pesan per event/kanal/tujuan/part, HTML, grup/bot/chat snapshot, job_id nullable, status/retry/sent_at, audit | UNIQUE event/channel/target/part; retry bagian sukses dilewati; token tidak disalin ke payload |
| `inventory_daily_runs` | Guard harian router/date WIB, status skipped/queued/complete, snapshot hasil minimum dan pesan ringkasan | UNIQUE `(router_id, local_date)`; lock+state mencegah ringkasan ganda |
| `inventory_bot_requests` | Deduplikasi /stok berdasarkan bot/update_id, router/grup tervalidasi, query dan event outbox hasil | UNIQUE `(bot_id, update_id)`; chat tidak terdaftar tidak menghasilkan jawaban/data |

Kategori/satuan merupakan kamus referensi bersama, bukan data operasional router. Master barang/varian, nilai dan minimum dapat diubah melalui UI sesuai role. Seluruh query barang/lokasi/serial/transaksi/BOM/parameter/laporan dibatasi router; join ke kamus tidak membuka data router lain. Kamus boleh dikelola admin/superadmin bila diperlukan, dengan guard yang sama.

Relasi domain: `routers -> items/locations -> balances/movements`; `items -> serials -> serial_events`; `purchases -> lines -> movements` dan satu `cashflow_transactions`; `work_orders/tickets/customers -> usage_records/movements`; customer -> recall relation -> WO termination existing; `locations -> opnames -> lines/serial checklist/events -> ADJUST_OPNAME`; job types -> BOM -> minimum policy/results -> alert state/events/deliveries -> background_jobs existing. Gudang mereferensikan master lokasi existing; tidak ada duplikasi customer, teknisi, OLT, tiket, antrean umum atau kas.

### Seed barang dan BOM

Seed idempotent per router, tanpa membuat stok, pembelian atau harga fiktif dan tanpa menimpa edit operator. Router/gudang baru mendapat seed melalui setup inventory router tersebut. `pack_size` awal nullable karena kelipatan pembelian supplier belum ditentukan. Barang manual bernilai 0 dengan `manual_minimum_configured = false` tampil **perlu diisi**; menyimpan 0 secara sengaja dapat menandainya sudah ditinjau.

| Kode | Nama | Kategori | Satuan | Tracking | Minimum |
| --- | --- | --- | --- | --- | --- |
| ONT-SB | ONT Single Band | ONT | unit | serial | otomatis |
| ONT-DB | ONT Dual Band | ONT | unit | serial | manual, 0/perlu diisi |
| PC-SCSC | Patchcord SC-SC | Aksesoris | pcs | quantity | otomatis |
| SLV-PRT | Sleeve Protector | Aksesoris | pcs | quantity | otomatis |
| ADP-12V1A | Adaptor 12V 1A | Aksesoris | pcs | quantity | otomatis |
| KLM-PAKU | Klem Paku | Aksesoris | pcs | quantity | otomatis |
| KBL-TIES | Kabel Ties | Aksesoris | pcs | quantity | otomatis |
| DC-1C | Dropcore 1 Core | Kabel | meter | quantity | otomatis |
| DC-4C | Dropcore 4 Core (Backbone) | Kabel | meter | quantity | manual, 0/perlu diisi |
| SPL-1-2 | Splitter 1:2 | Splitter | pcs | quantity | manual, 0/perlu diisi |
| SPL-1-4 | Splitter 1:4 | Splitter | pcs | quantity | manual, 0/perlu diisi |
| SPL-1-8 | Splitter 1:8 | Splitter | pcs | quantity | manual, 0/perlu diisi |
| SPL-1-16 | Splitter 1:16 | Splitter | pcs | quantity | manual, 0/perlu diisi |

| Job | BOM seed | Parameter seed |
| --- | --- | --- |
| pasang_baru | ONT-SB 1; DC-1C 200 m; PC-SCSC 1; SLV-PRT 2; KLM-PAKU 20; KBL-TIES 10 | N siap pasang = 2 |
| ganti_ont | ONT-SB 1; PC-SCSC 1 | 1 kejadian / 100 pelanggan |
| ganti_adaptor | ADP-12V1A 1 | 1 kejadian / 50 pelanggan |
| fo_putus_relokasi | DC-1C 200 m; SLV-PRT 2; KLM-PAKU 20; KBL-TIES 10 | 1 kejadian / 100 pelanggan |

Faktor target seed = 1,5. Job type, BOM, pack_size, mode minimum dan parameter dapat diubah admin/superadmin dari UI. Varian adalah item berbeda, sehingga ONT-DB tidak otomatis memakai BOM/biaya/minimum ONT-SB.

## 4. Kontrak posting ledger dan perhitungan biaya

1. Bentuk actor context dari session tervalidasi; periksa role, router, lokasi, status aktif, assignment teknisi, dan semua referensi di server. CLI memakai konteks sistem eksplisit per router, bukan session/fallback global.
2. Validasi input desimal secara ketat: maksimal tiga digit pecahan qty, dua digit harga; tidak memakai float biner sebagai dasar saldo/uang. Gunakan BCMath yang tersedia pada target installer, dengan pemeriksaan prasyarat; round half-up pada batas penyimpanan uang dan simpan `value_amount` untuk rekonsiliasi.
3. Mulai satu transaksi terluar. Reservasi `inventory_operations` dengan key stabil dan hash payload kanonik. Replay operasi committed mengembalikan hasil lama setelah cek akses; key sama/payload berbeda adalah konflik. Request gagal rollback tidak menyisakan key sukses palsu.
4. Kunci customer terkait, header sumber operasi/WO/ticket/opname bila relevan, lokasi menurut ID menaik, valuasi menurut item, saldo fisik/aset menurut key, lalu serial menurut ID. Kunci customer harus konsisten antara pemasangan, penggantian dan termination agar aset baru tidak dipasang ke customer yang sedang diterminasi. Sediakan baris saldo nol secara aman sebelum `SELECT ... FOR UPDATE`. Semua jalur memakai urutan sama; retry deadlock terbatas mengulang seluruh transaksi.
5. Periksa saldo dan state serial; tulis mutasi, saldo, valuasi, relasi/history serial, header, cashflow/WO/ticket/reply dan penanda recheck persisten dalam koneksi DB yang sama. Helper internal tidak commit transaksi pemanggil. Kegagalan bagian mana pun menggagalkan seluruh posting.
6. Selesaikan transaksi; baru evaluasi perubahan stok untuk alarm dan dispatch TelegramSendJob dari outbox. Kegagalan enqueue/kirim tidak membatalkan mutasi; event recheck tetap tersedia untuk retry. Aktivasi existing jalur WO tetap sesudah commit; penggantian perangkat tidak melakukan ACS/MikroTik otomatis. Tidak ada HTTP/Redis push di dalam transaksi ledger.

Jenis mutasi tetap mengikuti tugas: `IN_PURCHASE`, `OUT_INSTALL`, `OUT_MAINTENANCE`, `TRANSFER`, `RETURN_GOOD`, `RETURN_DAMAGED`, `ADJUST_OPNAME`, `WRITE_OFF`. Qty ditandatangani; pembelian/return positif, pemakaian/write-off negatif, opname sesuai selisih. Serial wajib `serial_id` dan qty `+1/-1` pada setiap barisnya. Transfer satu operation menghasilkan pasangan dengan qty berlawanan, serial dan biaya sama, sehingga total qty/nilai router tidak berubah. Referensi operation mengikat pasangan tanpa perlu update baris mutasi pertama.

**Stok fisik dan aset pelanggan:** tanda di atas berlaku untuk domain `stock`. Pemasangan ONT mencatat stock -1 dari lokasi teknisi dan `loaned_asset +1` pada customer, dalam operation yang sama dan dengan biaya sama. Return membalik penguasaan fisiknya: loaned_asset -1 lalu stock +1 good/damaged. Lokasi stok tetap hanya gudang/teknisi; baris loaned_asset memakai customer sebagai pemegang, `location_id = NULL`. Laporan pemakaian dan alarm memfilter stock agar pasangan aset tidak dihitung dua kali. Perubahan installed -> pending_return hanya mengubah lifecycle/history, tanpa mutasi qty.

WRITE_OFF perangkat pending_return/lost mengurangi domain loaned_asset dengan biaya aset yang tersimpan, **tidak** mengurangi stok teknisi lagi setelah OUT sebelumnya. WRITE_OFF barang gudang/teknisi mengurangi stock. Keduanya memakai ledger yang sama, alasan wajib dan role superadmin, tanpa kas/invoice baru. Pemisahan domain diperlukan agar penulisan aset hilang tetap tercatat dan tidak menghasilkan stok teknisi negatif. Recalc memverifikasi masing-masing cache/domain secara terpisah; kuantitas dan nilai kedua domain dapat ditotal untuk laporan seluruh perangkat milik Bujanaya, tanpa mencampurnya dengan stok tersedia.

Koreksi membuat baris dengan `reverses_movement_id` dan tanda berlawanan; sumber tidak diubah. Validasi akumulasi reversal agar tidak melebihi qty/nilai asal, termasuk pasangan transfer dan posisi serial terkini. Reversal tidak boleh menghindari pemeriksaan saldo. Koreksi pembelian yang memengaruhi kas harus melalui operasi koreksi terkoordinasi; endpoint reversal generik tidak boleh meninggalkan expense lama atau menghapusnya.

Biaya pembelian: `average_cost_baru = (nilai_persediaan_lama + nilai_masuk) / (qty_lama + qty_masuk)`. Pemakaian/write-off memakai rata-rata saat posting; pengembalian memakai biaya dari mutasi pemakaian asal yang dirujuk dan menghitung ulang rata-rata. Transfer membawa biaya yang sama pada kedua sisi. Sisa pembulatan uang diserap pada pengeluaran terakhir ketika qty menjadi nol agar nilai persediaan menjadi nol juga. Stok rusak ditampilkan terpisah, tidak tersedia untuk WO; penghapusan nilainya dilakukan WRITE_OFF oleh superadmin, bukan sekadar mengganti status serial.

Saldo awal bukan update cache atau pembelian fiktif: gunakan sesi opname awal yang disetujui superadmin dan biaya awal terdokumentasi. Cache selalu dapat dijelaskan oleh ledger.

CLI yang direncanakan: `php index.php inventory_cli recalc ROUTER_ID` untuk laporan selisih ledger/cache dengan exit status gagal jika berbeda. Mode perbaikan harus eksplisit `repair ROUTER_ID`, mengunci lokasi/valuasi/aset terkait lalu membangun ulang cache dari ledger; tidak mengubah ledger. Bandingkan juga valuasi dan jumlah serial per kondisi/domain. Endpoint CLI menolak HTTP; command ini belum dibuat atau dijalankan.

## 5. Pembelian, transfer, return, WO, tiket dan penarikan aset

### Pembelian

- Form header/detail menerima tanggal valid, supplier, gudang tujuan, sumber `kas_bisnis/biaya_pasang/cicilan_lama`, catatan, foto bukti opsional, kuantitas/harga dan daftar serial untuk barang serial. Total dihitung server, bukan dipercaya dari form.
- Posting mengunci header draft dan membuat mutasi masuk serta satu expense `cashflow_transactions` kategori expense **Perangkat** untuk `kas_bisnis` dan `biaya_pasang`. `cicilan_lama` tetap menambah persediaan tanpa expense baru. Tidak ada efek payment/invoice.
- Gunakan ulang kategori Perangkat existing jika cocok; seed hanya bila belum ada dan schema kompatibel (`category_name/name`, `category_code/code`). Perbedaan nama kolom kas ditangani model; schema wajib yang hilang menghasilkan penolakan jelas, bukan posting stok tanpa expense.
- Penanda referensi kas `inventory_purchase` dan ID pembelian, ditambah relasi kas unik pada header, mengikat satu expense. Header ber-lock dan key operasi mencegah submit ganda termasuk dua key berbeda untuk pembelian yang sama.
- Bukti foto disimpan dengan nama acak, batas tipe/MIME/ukuran, referensi melalui endpoint ter-scope; file yatim saat transaksi gagal dibersihkan tanpa menghapus bukti posted.

### Transfer dan return

- Admin/superadmin memilih dua lokasi aktif dalam router yang sama; pemakaian dibatasi stok `good`. Transfer serial mengecek lokasi/status nyata dan memindahkan ke in_stock/with_technician sesuai tujuan. Tidak boleh ada transfer setengah berhasil.
- Return pelanggan wajib merujuk customer, serial installed/pending_return dan mutasi pemakaian asal atau baseline aset legacy yang terverifikasi. Return baik menjadi stok layak pakai berstatus returned; return rusak masuk kondisi damaged/status damaged. Putuskan assignment customer pada aset saat return, simpan relasi historis di ledger, dan cocokkan pembaruan `ont_devices.customer_id` hanya untuk serial/router/customer yang sama. Serial lama yang belum ada dalam inventaris dapat diregistrasikan secara eksplisit dari form tiket, sebagaimana dijelaskan di bawah.
- Barang kuantitas yang dikembalikan juga membatasi akumulasi return terhadap pemakaian asal. Klik ulang atau key baru untuk serial yang sudah kembali tidak boleh menambah stok kedua kali.

### WO

- Tambah form material pada penyelesaian WO: teknisi/lokasi sumber tervalidasi, pilihan serial, qty kabel/konektor/barang lain, serta pernyataan tanpa material dengan alasan bila memang tidak memakai barang. Tidak otomatis menghabiskan stok berdasarkan jenis WO saja.
- `Workorders::mark_done` dan `Work_order::done -> Wo_manager::complete_wo` mendelegasikan bagian database kepada service penyelesaian bersama. Service mengunci WO, memvalidasi status/assignment/scope, memposting OUT_INSTALL atau OUT_MAINTENANCE, memperbarui WO/history dalam transaksi yang sama. Tipe repair dipetakan sebagai maintenance; tipe lain perlu tindakan material eksplisit yang valid, tidak dianggap installation.
- Teknisi hanya boleh menyelesaikan pekerjaan yang diizinkan dan memakai stoknya sendiri. Admin/superadmin dapat memilih stok teknisi yang ditugaskan dalam router WO. ID pelaku POST tidak menjadi otorisasi.
- Qty melampaui stok atau serial tidak tersedia menolak seluruh penyelesaian, termasuk perubahan status dan history. Replay completion tidak menghabiskan material lagi, dan payload material berbeda pada WO selesai ditolak sebagai konflik.
- `activate()` hanya aktivasi/retry setelah DONE dan tidak memposting ulang stok. Pertahankan aktivasi existing setelah commit untuk jalur lifecycle; kegagalan MikroTik tidak menghapus pemakaian yang telah committed.
- Blokir hard delete WO yang telah direferensikan ledger. Cancellation setelah konsumsi memerlukan return/reversal eksplisit, tidak menghilangkan histori.

### Tiket penggantian perangkat

- Jenis **Penggantian perangkat** memanfaatkan category device/field jenis existing. Nilai kanonik `device_replacement` dan subtype disimpan dalam field yang sudah mendukungnya; bila enum/kolom tidak cukup, gunakan satu relasi `inventory_ticket_contexts` per tiket. Jangan mengandalkan teks subject/description untuk menentukan kewajiban material. Existing router_replace dapat dipetakan ke penggantian ONT serial; adapter_replace tetap pekerjaan barang quantity karena ADP-12V1A memang tidak ber-serial.
- Untuk penggantian ONT: form wajib serial baru dari stock good teknisi yang ditugaskan; serial lama milik customer tiket (atau input manual legacy); kondisi lama rusak/masih_baik; material quantity opsional. Customer dan assignment tidak boleh kosong. Admin/superadmin juga memakai stok teknisi yang benar-benar ditugaskan, bukan stok teknisi lain secara bebas.
- `Helpdesk::update_status` untuk target resolved/done/closed, `Helpdesk::mark_done`, dan `Tickets::mark_done` **semuanya** memanggil `Inventory_service::record_ticket_usage(ticket_id, material, actor, target_status)` yang sama. Method service tersebut memiliki transaksi terluar yang mencakup status/reply tiket dan seluruh mutasi; bukan sekadar hook sesudah update status.
- Inti posting material bersama dipakai jalur WO dan tiket; perbedaan hanya tipe sumber, aturan lifecycle dan jenis pemakaian. Ticket_model menyediakan lookup ber-lock ter-scope, primitive status/reply tanpa commit internal, serta adapter normalisasi enum. Jalur direct update yang dapat menyelesaikan tiket penggantian wajib ditutup.
- Dalam satu transaksi: kunci customer/tiket dan usage record, lokasi/valuasi/saldo/serial; validasi serial lama bukan serial baru, benar-benar milik customer tiket, dan serial baru berada di teknisi yang ditugaskan. OUT_MAINTENANCE baru memindahkan aset ke customer; RETURN_GOOD/DAMAGED lama memindahkan ke teknisi dan membersihkan customer; material tambahan menjadi OUT_MAINTENANCE. Semua baris mereferensikan ticket_id dan customer_id, dengan histori old -> new dan biaya pemakaian yang jelas.
- Serial lama manual yang belum pernah tercatat boleh diregistrasikan dalam transaksi tersebut setelah validasi keunikan global, router/customer dan kondisi. Simpan baseline loaned_asset eksplisit, sumber manual/legacy, pelaku dan bukti/catatan, kemudian return; tidak membuat saldo gudang awal fiktif. Biaya legacy memakai referensi yang dapat diverifikasi; bila belum diketahui, nilai 0 harus diberi penanda **biaya belum diketahui** dan dilaporkan terpisah, bukan dianggap perangkat tanpa nilai. Baseline bukan alasan untuk mengambil serial existing customer/router lain.
- Kekurangan stok, assignment salah, serial lama di customer lain, atau kegagalan update status/reply menggagalkan seluruh transaksi. `UNIQUE(source_type, source_id)` menjamin satu pemakaian seumur tiket, meskipun dua controller/dua key/reopen/resolve ulang dipakai. Penyelesaian ulang tanpa perubahan mengembalikan hasil lama; material berbeda ditolak. Reopen tidak mengembalikan stok, dan resolve ulang tidak memotongnya. Pembatalan pemakaian hanya superadmin melalui reversal eksplisit yang memvalidasi posisi kedua serial dan seluruh material; tidak menghapus usage record untuk memungkinkan replay.
- Inventaris menyimpan assignment serial baru dan penanda **perlu sinkron ACS** bila belum cocok dengan cache GenieACS pada router yang sama. Cache lama dilepas hanya jika cocok customer/serial/router. Jangan membuat telemetry online palsu atau menjalankan perubahan ACS/MikroTik otomatis. Biaya penggantian memakai unit_cost x qty dari mutasi stock; tidak ada tagihan pelanggan dan tidak ada expense kas kedua pada saat pemakaian.
- Perbarui form create/detail/list Helpdesk dan Tickets, endpoint status AJAX dan tombol DONE, history perangkat pelanggan, serta proteksi delete tiket bereferensi. Penggantian adaptor quantity memakai core pemakaian yang sama tetapi tidak meminta serial fiktif pada item non-serial.

### Pelanggan terminated dan WO penarikan

- `Inventory_service::schedule_customer_recall()` dipanggil pada transisi customer menjadi terminated atau aksi berhenti/arsip di Customers, termasuk static/PPPoE dan bulk yang relevan. Kunci customer, ubah status customer/service sesuai alur existing, cari/buat WO termination existing dan relasi recall aktif, kemudian installed -> pending_return beserta `pending_return_at`/history dalam transaksi DB yang sama. Tidak membuat mutasi stok sebelum barang benar-benar ditarik.
- Gunakan satu WO termination aktif yang sesuai customer/router, bukan membuat WO tambahan setiap penyimpanan status. Relasi episode termination dan slot aktif per customer menjamin idempotensi walau dua request memakai key berbeda. Bila pelanggan kemudian aktif lagi, jangan membalik otomatis tugas/serial; admin meninjau tugas existing. Termination berikutnya dengan aset baru dapat menjadi episode baru sesudah tugas lama benar-benar ditutup.
- Existing bulk_disable/suspend/isolir bukan berhenti, sehingga tidak membuat recall. Cegah hard delete customer beserta WO/tiket/ONT yang mempunyai aset atau histori inventaris; aksi tersebut diarahkan ke arsip/berhenti yang menjaga referensi. Pembatasan ini khusus customer yang terkait inventaris, tanpa refactor penghapusan billing umum.
- Form penyelesaian WO termination meminta seluruh serial yang ditarik dan kondisi masing-masing; hanya serial pending_return/installed milik customer WO dapat dikembalikan ke lokasi teknisi yang ditugaskan. Return, status serial/customer, status WO/history dan relasi tugas selesai committed bersama. Aset yang belum kembali membuat WO tetap terbuka; penarikan parsial dapat dicatat sebagai progres dengan operation idempotent tanpa menandai WO selesai.
- Umur dihitung dari pending_return_at, bukan updated_at yang bisa berubah: **lebih dari 14 x 24 jam** masuk laporan Perangkat belum ditarik dan notifikasi aplikasi kepada admin/superadmin router terkait. Deduplikasi notifikasi per serial/episode; tugas ditutup bila semua aset kembali atau telah diputus write-off secara sah. Tidak otomatis lost.
- Hanya superadmin boleh memutus lost/write-off dengan alasan wajib, mengurangi domain aset pada ledger sebagaimana bagian 4. Admin/teknisi ditolak; tidak mengurangi stok teknisi dua kali. Penyelesaian material/tiket serentak terhadap customer terminated harus ditolak atau direkonsiliasi dalam lock customer yang sama.

### WO melalui Telegram

`complete_work_order_after_speedtest()` adalah jalur tambahan yang tidak boleh melompati material. Pertahankan secret/signature callback existing. Bila bukti speedtest diterima tetapi material belum lengkap/terkonfirmasi, simpan bukti sebagai pending completion dan berikan tautan form internal WO; jangan menulis DONE/active langsung. Bila material sudah siap, gunakan service penyelesaian bersama dengan identitas teknisi/assignment yang dipetakan secara sah, bukan mempercayai Telegram actor ID sebagai users.id. `/stok` tetap hanya membaca stok dan tidak memicu jalur provisioning ini.

## 6. Stock opname tanpa menghentikan operasional

Transisi: `draft -> counting -> submitted -> approved -> posted`; penolakan dari submitted/approved menuju rejected menyimpan alasan. Perubahan hasil sesudah submit harus melalui sesi baru setelah rejection, bukan mengedit data yang telah disetujui. Satu lokasi hanya boleh memiliki satu draft/counting/submitted/approved aktif.

1. **Buat draft:** kunci lokasi; insert slot aktif unik dan sesi. Teknisi hanya lokasi miliknya.
2. **Mulai counting:** kunci lokasi sesaat, ambil snapshot seluruh saldo dan serial, termasuk item aktif bersaldo nol; simpan waktu serta batas ID ledger lokasi. Semua penulis ledger wajib mengunci lokasi yang sama sehingga batas snapshot tidak melewatkan mutasi yang masih belum commit. Lock dilepas setelah snapshot; tidak ada lock selama penghitungan fisik.
3. **Counting berjalan:** simpan qty fisik dan checklist found/missing, waktu pengamatan, alasan selisih rusak/hilang/salah_catat/lainnya. Serial tak dikenal masuk tabel terpisah. Barang yang baru masuk setelah snapshot harus ikut daftar. Pergerakan sah sesudah checklist ditandai perlu rekonsiliasi/count ulang pada submit; tidak langsung dianggap hilang.
4. **Submit:** kunci lokasi sesaat, tetapkan cutoff ledger/waktu, hitung `expected = snapshot + SUM(mutasi setelah snapshot sampai cutoff)` dan `difference = physical - expected`. Operator harus mengonfirmasi bahwa physical/checklist merepresentasikan cutoff; input lama yang terdampak mutasi harus diperbarui. Alasan wajib untuk selisih, dan serial yang belum dihitung/unknown yang belum diselesaikan menghalangi submit. Simpan versi hasil ini sebagai dasar approval.
5. **Approve:** hanya superadmin dalam scope; periksa kelengkapan, alasan dan konsistensi jumlah serial terhadap qty. Simpan pelaku, waktu, rincian selisih, biaya rata-rata saat approval dan nilai rupiah yang disetujui. Biaya awal untuk surplus tanpa histori harus diisi dan disetujui, tidak diasumsikan nol diam-diam.
6. **Post:** hanya superadmin; kunci sesi/lokasi/valuasi/saldo/serial. Terapkan **delta yang disetujui** ke saldo saat ini; jangan menimpa saldo dengan physical lama. Gerakan sah sesudah cutoff tetap dipertahankan. ADJUST_OPNAME dibuat hanya pada baris selisih dan per serial; zero difference tetap diaudit tanpa mutasi nol.
7. Jika biaya rata-rata berubah sejak approval, saldo menjadi negatif akibat delta, atau serial kandidat lost sudah dipasang/ditransfer/diubah setelah cutoff, posting ditolak tanpa mengubah ledger. Hasil perlu direkonsiliasi melalui rejection dan sesi pengganti, bukan menghitung ulang nominal secara diam-diam setelah disetujui. Ini menjaga operasi berjalan sekaligus mempertahankan keputusan approval.
8. Serial missing yang masih memenuhi kondisi posting berubah menjadi lost bersamaan dengan pengurangan ledger. Found untuk serial yang semestinya ada tidak memunculkan penerimaan baru. Serial unknown harus dipetakan/diverifikasi; serial lokasi/customer lain diselesaikan melalui transfer/return yang sah, bukan penyesuaian yang menggandakan aset.
9. Posted melepaskan slot aktif pada transaksi yang sama; replay posting mengembalikan hasil sebelumnya. Export/cetak menampilkan lokasi/router, snapshot/cutoff, system/physical/delta, serial unknown/resolusi, alasan, biaya/nilai, pelaku dan histori persetujuan.

Contoh: snapshot kabel 100 m, keluar 20 m saat counting, physical terkonfirmasi di cutoff 78 m. Expected 80 m, delta -2 m. Bila keluar 10 m lagi setelah submit, saldo sebelum posting 70 m dan setelah posting 68 m; tidak diubah menjadi 78 m. Uji wajib mencakup mutasi di detik timestamp yang sama dan transaksi paralel pada batas snapshot.

## 7. Endpoint, UI dan hak akses

Semua mutasi web memakai POST dan CSRF, validasi action di controller maupun service, serta pengecekan ID ter-scope pada model. GET hanya baca/form/export. Alias dash/underscore didaftarkan eksplisit untuk setiap kata majemuk; URI konvensional controller juga menjalankan guard yang sama.

| Method | URI utama; pasangan alias bila berbeda | Controller/tindakan dan role |
| --- | --- | --- |
| GET | `inventory`, `inventory/items`, `inventory/locations`, `inventory/serials` | `Inventory`: lihat sesuai scope; teknisi hanya lokasi/serial yang diizinkan |
| POST | `inventory/store-item` / `inventory/store_item`; `update-item/{id}` / `update_item/{id}`; `deactivate-item/{id}` / `deactivate_item/{id}` | Master barang: superadmin/admin |
| POST | `inventory/store-location` / `inventory/store_location`; `update-location/{id}` / `update_location/{id}`; `deactivate-location/{id}` / `deactivate_location/{id}` | Lokasi: superadmin/admin; verifikasi master lokasi/teknisi/router |
| GET | `inventory/purchases`, `inventory/purchases/{id}`, `inventory/transfers`, `inventory/returns` | Form/daftar/detail: superadmin/admin |
| POST | `inventory/save-purchase` / `save_purchase`; `post-purchase/{id}` / `post_purchase/{id}` | Simpan draft/post pembelian: superadmin/admin |
| POST | `inventory/post-transfer` / `post_transfer`; `post-return` / `post_return` | Transfer/return: superadmin/admin; teknisi hanya mengisi return melalui WO yang diizinkan |
| POST | `inventory/write-off` / `inventory/write_off`; `inventory/reverse-movement/{id}` / `inventory/reverse_movement/{id}` | Penghapusan/koreksi mutasi: superadmin; koreksi kas mengikuti kontrak terkoordinasi |
| GET | `inventory-minimum` / `inventory_minimum`; suffix `bom`, `job-types` / `job_types`, `policies`, `results`, `comparison` | `Inventory_minimum`: BOM, job, parameter gudang, hasil formula dan pembanding pemakaian; lihat sesuai scope |
| POST | Prefix `inventory-minimum` / `inventory_minimum`; suffix `save-job-type` / `save_job_type`, `save-bom` / `save_bom`, `save-policy` / `save_policy`, `set-override` / `set_override`, `restore-automatic` / `restore_automatic` | Admin/superadmin; alasan override wajib; memicu hitung ulang dan evaluasi state setelah commit |
| GET | `stock-opname` / `stock_opname`; `stock-opname/show/{id}` / `stock_opname/show/{id}` | `Stock_opname`: daftar/detail sesuai scope/lokasi |
| POST | `stock-opname/create`, `start-counting/{id}`, `save-count/{id}`, `submit/{id}`; padanan `stock_opname` dengan method underscore | Superadmin/admin; teknisi hanya lokasinya |
| POST | `stock-opname/approve/{id}`, `reject/{id}`, `post/{id}`; padanan `stock_opname` | Hanya superadmin |
| GET | `stock-opname/print/{id}`, `export-pdf/{id}`; padanan `stock_opname/print/{id}`, `export_pdf/{id}` | Cetak/PDF sesuai akses sesi |
| GET | `inventory-reports/balances`, `stock-card/{id}`, `technicians`, `opnames`, `material-usage`; padanan `inventory_reports` dan method underscore | `Inventory_reports`: saldo/nilai, kartu stok, bawaan teknisi, opname, pemakaian bulanan/per WO/per tiket |
| GET | Prefix `inventory-reports` / `inventory_reports`; suffix `customer-assets` / `customer_assets`, `device-history/{customer_id}` / `device_history/{customer_id}`, `replacements`, `damaged`, `pending-returns` / `pending_returns` | Aset dipinjamkan, riwayat old -> new, jumlah/nilai penggantian, rusak, penarikan lewat 14 hari; scope router dan penugasan |
| POST | Existing `workorders/mark-done/{id}` / `mark_done/{id}`, endpoint `work_order/done` dan `activate` | Dipertahankan dengan material, scope/assignment, transaksi dan replay guard |
| POST | Existing `helpdesk/update-status` / `update_status`, `helpdesk/mark-done/{id}` / `mark_done/{id}`, `tickets/mark-done/{id}` / `mark_done/{id}` | Seluruh penyelesaian melalui record_ticket_usage; form material, assignment dan replay guard sama |
| POST | Existing aksi update/berhenti/delete/bulk di `customers` | Hook termination/arsip dengan schedule_customer_recall, tanpa endpoint termination tandingan; cegah hard delete histori aset |
| GET/POST | Existing settings Telegram serta save-group dengan alias yang sudah ada | Tambah pilihan inventory, validasi/mapping model dan opsi UI; tidak membuat pengelolaan bot/grup kedua |
| POST webhook | Existing `telegram/webhook` | Secret header tetap wajib; /stok dan /stok nama hanya grup inventory aktif, router dari grup, bot aktif; bukan auth session |
| CLI/guard existing | `CronNotification check_inventory_minimum`; alias existing `cron-notification/check-inventory-minimum` dan tambah `cron_notification/check_inventory_minimum` | Tetap memakai guard cron existing; proses per router eksplisit |
| CLI saja | `inventory_cli recalc ROUTER_ID`, `inventory_cli repair ROUTER_ID`, `inventory_cli recompute_minimum ROUTER_ID` | Laporan/perbaikan cache dan hitung ulang minimum untuk operator; tidak menerima HTTP; cron harian tetap memakai checker existing |

Untuk baris endpoint berawalan sama, suffix tabel adalah relatif ke prefix pada baris tersebut. URI statis dideklarasikan sebelum pola ID agar tidak tertangkap route detail.

Hak akses per aksi:

| Aksi | superadmin | admin | teknisi |
| --- | --- | --- | --- |
| Master barang/BOM/job/parameter minimum | kelola | kelola | lihat |
| Pembelian/transfer/return langsung | ya | ya | tidak |
| Material WO/tiket | ya, stok teknisi penanggung jawab | ya, stok teknisi penanggung jawab | pekerjaan yang ditugaskan, stok sendiri |
| Opname draft/counting/submit | ya | ya | lokasi sendiri |
| Approve/reject/post opname | ya | tidak | tidak |
| Write-off/ubah lost/batal pemakaian | ya, alasan wajib | tidak | tidak |
| Laporan | sesuai router aktif/semua jika diizinkan | router aktif | lokasi sendiri dan WO/tiket/aset pekerjaan yang diizinkan |

Tambah entri `inventory`, `stock_opname`, `inventory_minimum`, `inventory_reports` pada `roleMatrix()` dan menu pada sidebar layout aktif. Menu master/lokasi, saldo/serial/aset pelanggan, pembelian/transfer/return, stock opname, BOM/minimum, laporan disesuaikan role; pengecekan server tetap wajib. View memakai layout existing, formatter Rupiah dan qty tiga desimal tanpa trailing zero berlebih. Penanda minimum manual perlu diisi, pending_return, biaya legacy belum diketahui, serta perlu sinkron ACS ditampilkan pada konteks yang relevan. PDF memakai query ter-scope dan aset lokal; tidak membuka URL bukti arbitrer dari input.

## 8. Minimum otomatis, notifikasi Telegram dan laporan

### Formula, parameter dan audit minimum

Service khusus `Inventory_minimum_service` menerima router/gudang eksplisit dan membaca BOM/parameter melalui model. Hitung pelanggan aktif sebagai **COUNT DISTINCT customer**, status active/suspended/isolated/isolir, tidak terminated/deleted/soft-deleted. Gunakan resolver router customer/service yang konsisten dengan konteks billing; perbedaan data router ditandai untuk rekonsiliasi, bukan menghitung customer dua kali. Query tidak memakai syarat cash_visit, invoice, harga positif atau jenis PPPoE sehingga static juga dihitung. Definisi status ditinjau dari generator existing, tanpa mengubah logika billing.

Untuk A pelanggan, N siap pasang, job gangguan J dengan parameter Xj, dan kuantitas BOM qji:

```text
kejadian_j              = ceil(A / Xj)
minimum_rumus_i         = SUM_j(kejadian_j * qji) + N * BOM(pasang_baru, i)
minimum_efektif_i       = minimum_rumus_i atau stok_minimum_manual sesuai mode item
target_i                = ceil_ke_kelipatan(minimum_efektif_i * faktor_target, pack_size)
total_saldo_tersedia_i  = SUM(stock good seluruh gudang dan teknisi pada router yang sama)
saran_beli_i            = ceil_ke_kelipatan(max(0, target_i - total_saldo_tersedia_i), pack_size)
pemakaian_30_i          = pemakaian stock OUT_INSTALL/OUT_MAINTENANCE 30 hari terakhir,
                         dikurangi reversal pemakaian yang sah; bukan transfer/opname/write-off
perkiraan_habis_hari_i  = total_saldo_tersedia_i / (pemakaian_30_i / 30), atau "-" bila 0
```

Tanpa pack_size, gunakan kelipatan 1 untuk serial/unit/pcs/roll dan ketelitian 0,001 untuk meter. Pack_size serial harus bulat. Minimum rumus tidak diubah menjadi target dan tidak dibulatkan ke pak pembelian; hanya target/saran beli yang dibulatkan. Quantity BOM harus nonnegatif, X positif, N integer nonnegatif, faktor_target minimal 1. Kuantitas surplus/reversal tidak boleh menghasilkan laju pemakaian negatif; tampilkan pengembalian terpisah dari laju pemakaian. Biaya penggantian memakai biaya OUT baru/material, bukan pembelian kas ulang.

Parameter per gudang memakai pelanggan aktif pada router yang sama. Bila satu router memiliki beberapa gudang, setiap gudang mendapat kebijakan sendiri; tidak ada pembagian pelanggan/target diam-diam. Alarm memakai saldo good **gudang yang bersangkutan**, sedangkan total tersedia untuk burn-rate/saran beli memakai seluruh gudang+teknisi router sesuai rumus di atas. Karena itu gudang dapat low meskipun total cukup: tampilkan saran redistribusi dan saran beli nol, jangan menyembunyikan alarm lokasi.

Hitung ulang harian sebelum ringkasan 07.00 WIB dengan guard waktu di service; panggilan per jam pada host UTC aman dan pemanggilan pertama setelah 07.00 tetap dapat memproses hari itu. Edit BOM, job, policy, pack_size, mode atau override memicu hitung ulang terkontrol sesudah commit tanpa menunggu hari berikutnya. Perubahan jumlah/status customer tercermin paling lambat hitung ulang harian; hasil minimum baru dievaluasi untuk transisi low/pulih walau tidak ada mutasi stok.

Simpan run/version, A, setiap Xj/kejadian/kontribusi, N, mode/override/alasan, faktor/pack_size dan hasil per item/gudang. Update parameter dan override memakai transaksi/lock/idempotensi, dengan alasan wajib untuk berpindah ke manual/mengubah override atau kembali otomatis. Angka perbandingan pemakaian 30/90 hari hanya informasi; tidak mengganti minimum formula.

Pemeriksaan aritmetika seed (rencana hasil uji, bukan hasil query produksi):

| Barang | A = 232 | A = 301 |
| --- | ---: | ---: |
| ONT Single Band | 3 + 2 = **5 unit** | 4 + 2 = **6 unit** |
| Dropcore 1 Core | 3 x 200 + 2 x 200 = **1.000 m** | 4 x 200 + 2 x 200 = **1.200 m** |
| Sleeve Protector | 3 x 2 + 2 x 2 = **10 pcs** | 4 x 2 + 2 x 2 = **12 pcs** |
| Adaptor 12V 1A | ceil(232/50) = **5 pcs** | ceil(301/50) = **7 pcs** |
| Klem Paku | 3 x 20 + 2 x 20 = **100 pcs** | 4 x 20 + 2 x 20 = **120 pcs** |
| Kabel Ties | 3 x 10 + 2 x 10 = **50 pcs** | 4 x 10 + 2 x 10 = **60 pcs** |
| Patchcord SC-SC | 3 + 2 = **5 pcs** | 4 + 2 = **6 pcs** |

### Alarm transisi setelah commit

- Sesudah posting stock atau perubahan minimum commit, proses event recheck per item/gudang. Kunci state dan baca saldo committed serta versi minimum terbaru. Nonaktif dilewati; baris saldo yang belum ada dianggap nol.
- State normal -> low bila saldo gudang < minimum: buat satu event low, satu notifikasi aplikasi per penerima yang berhak, dan satu rangkaian delivery Telegram per grup inventory. Qty turun lagi selama low tidak mengirim event baru. State low -> normal bila saldo >= minimum: buat satu event **stok pulih**. Turun lagi membentuk episode baru.
- Perubahan minimum juga memakai mesin state yang sama, termasuk minimum turun yang memulihkan stok. Minimum awal di bawah stok tidak membuat pesan pulih palsu; state awal low menghasilkan satu alarm. Tidak ada reminder enam jam; ringkasan harian terpisah tidak dianggap alarm kejadian ulang.
- Job/outbox gagal tidak rollback mutasi; recheck persisten, status event dan deduplikasi target memungkinkan retry. `last_sent_at` baru diisi pada delivery sukses, bukan saat enqueue. Simpan status per tujuan/part agar kegagalan satu grup tidak mengulang grup sukses. Recovery dan low berikutnya menjaga urutan per state/episode dalam antrean; event lama tetap berlabel waktu/basis snapshot.

### Telegram inventory, queue dan ringkasan 07.00 WIB

- Tambahkan tipe **inventory** dalam enum type/group_type dengan mempertahankan semua nilai/default/nullability existing, whitelist dan mapping Settings_model, serta pilihan controller/view Settings. Pengelolaan grup tetap menggunakan modul yang ada. Jangan seed bot/chat/grup tujuan nyata.
- Target hanya grup inventory aktif, bot yang benar-benar ada/aktif, dan router sama; jika bot punya router_id, cocokkan juga. Resolusi gagal atau kolom router tidak tersedia berarti tidak ada target. Tidak boleh fallback ke admin/owner/alert/collection, grup global, token global atau router lain. Belum dikonfigurasi -> warning log/notifikasi aplikasi yang dideduplikasi, tanpa pesan Telegram ke grup lain.
- Gunakan **TelegramSendJob** existing dengan cabang inventory yang ketat sebelum jalur generic direct chat_ids. Payload minimal membawa `inventory_delivery_id`, `router_id`, `group_type=inventory`, dan flag fallback false; message/target tersimpan di outbox, token diambil dari Settings saat eksekusi. Handler memverifikasi ulang delivery, grup/bot/router/chat snapshot dan skip jika grup dinonaktifkan/dipindah, bukan mengganti penerima. Payload tidak menyimpan token/secret.
- Dispatch melalui JobDispatcher hanya sesudah commit. Delivery key yang stabil dan claim/lock saat handler bekerja mencegah dua enqueue/worker memproses satu delivery bersamaan. Jika antrean tidak tersedia, simpan pending + warning dan coba ulang pada checker, bukan kirim sinkron ke fallback. Sesudah provider sukses, persist sent_at; celah provider sukses/proses mati sebelum persist tetap menjadi batas exactly-once eksternal.
- `CronNotification::check_inventory_minimum` mendelegasikan: drain recheck/outbox pending; guard perhitungan/ringkasan harian; cek penarikan >14 hari. Untuk ringkasan, `now Asia/Jakarta >= 07.00`, lock per router/date dan UNIQUE daily run. Simpan snapshot semua item low pada router serta seluruh bagiannya dalam satu transaksi; tanpa item low, tandai skipped tanpa pesan. Satu **ringkasan logis** per router/hari, disampaikan ke tiap grup inventory aktif yang berhak, dapat terdiri dari beberapa part. Retry melewati part sukses. Memproses retry delivery lama tidak membuat daily run baru.
- HTML bahasa Indonesia, escaping nama/catatan, pemisah ribuan Indonesia dan satuan jelas. Setiap barang memuat saldo gudang (detail per lokasi bila banyak), saldo teknisi, minimum dan dasar hitung, pemakaian 30 hari, perkiraan habis, target dan saran beli. Contoh: `min 5 unit = ganti ONT 3 (232 pelanggan, 1/100) + siap pasang 2`.
- Pecah pada batas entri barang dengan header/nomor bagian stabil; batas konservatif 3.500 karakter termasuk markup/header/footer dan hard check <=4.096, tag/entity UTF-8 tidak terpotong. Satu entri terlalu panjang dipotong pada field teks secara aman sebelum render. Pola pemecahan collection digunakan ulang/diadaptasi tanpa mengubah jadwal, target atau data collection.

### Perintah /stok

- Handler baru di Telegram_webhook berjalan **sesudah secret header existing valid**, sebelum jalur token speedtest; dukung `/stok` dan `/stok <nama/kode barang>` (serta suffix @bot bila cocok). Tidak ada endpoint publik stok tanpa guard.
- Cari chat.id dari envelope Telegram pada grup inventory aktif dan bot aktif; router berasal dari record grup, bukan query/user text/session. Bila chat tidak terdaftar, DM, grup salah tipe/nonaktif, atau mapping chat ke router/bot ambigu, jawab HTTP 200 tanpa mengirim pesan/data stok; log konfigurasi ambigu secara terbatas. Secret tidak valid tetap ditolak seperti existing.
- Query/pencarian nama dibatasi router grup, menampilkan kode/nama, stok gudang/teknisi/tersedia dan satuan; gunakan snapshot minimum untuk dasar bila tersedia. Tidak memuat kredensial/customer personal. Chat berhak dengan pencarian kosong hasil mendapat jawaban tidak ada barang pada router tersebut, tanpa membocorkan barang router lain.
- Deduplikasi bot/update_id dan simpan delivery reply ke grup yang sama melalui TelegramSendJob inventory ketat; tidak memakai token global handler speedtest. Uji redelivery webhook, banyak bot/grup, nama Unicode/HTML, serta pemecahan pesan panjang.

### Laporan

- Saldo/nilai per barang-lokasi-router dengan good/damaged/available dan harga rata-rata; tampilkan baris stok nol. Nilai total direkonsiliasi dengan valuasi dan ledger, dengan aturan pembulatan yang sama.
- Kartu stok berdasarkan urutan ledger `id`, saldo awal sebelum rentang, signed qty, referensi, biaya, nilai dan saldo berjalan. Filter tanggal memakai waktu posting; tanggal pembelian bisnis disimpan terpisah agar backdate tidak merusak batas snapshot.
- Bawaan teknisi dan barang rusak dikelompokkan teknisi/lokasi/router; teknisi hanya miliknya. Returned good dan damaged tidak dicampur sebagai available.
- Riwayat opname per periode/status/lokasi dengan nilai surplus, kekurangan, net, dan total absolut agar kerugian tidak tersembunyi oleh surplus.
- Pemakaian bulanan/per WO/**per tiket** berasal dari domain stock OUT_INSTALL/OUT_MAINTENANCE beserta reversal yang relevan; tampilkan bruto, return dan net secara terpisah. Retur perangkat lama pada penggantian tidak menghapus biaya pemakaian perangkat baru.
- Riwayat perangkat per customer: tanggal, serial lama -> baru, kondisi, teknisi, tiket/WO, biaya, penanda perlu sinkron ACS. Jumlah/nilai penggantian per bulan dihitung per usage record unik, bukan per banyaknya baris ledger.
- **Aset di pelanggan** mencakup installed/pending_return per pelanggan/lokasi customer/router dengan carrying value dan biaya yang belum diketahui terpisah. **Perangkat belum ditarik** memfilter umur pending_return >14 hari, lengkap WO/petugas; write-off menutup aset tanpa menghapus histori.
- Pembanding minimum rumus vs pemakaian aktual 30/90 hari, target, override/alasan, jumlah pelanggan dan kontribusi BOM. Tidak otomatis mengganti formula dari tren pemakaian.

## 9. Tahapan implementasi dan commit

Nama timestamp berikut **usulan**, diperiksa ulang agar urut setelah migrasi existing `20261023090000_customer_monthly_price_override.php` dan tidak bentrok saat implementasi. Migrasi di filesystem disiapkan tanpa dijalankan ke produksi. `down()` tidak menghapus histori transaksi.

| Tahap | Artefak dan perubahan | Migrasi/commit yang direncanakan | Gerbang verifikasi |
| --- | --- | --- | --- |
| 0 — investigasi | Dokumen rencana ini | Commit dokumentasi rencana tersendiri | Tampilkan rencana, berhenti sebelum kode |
| 1 — master/lokasi/ledger/saldo/seed | `Inventory_model`, `Inventory_service`, controller `Inventory`, `Inventory_cli`, view master/saldo/serial, RBAC, route, sidebar, seed kategori/satuan/13 barang, pack_size/mode minimum, domain saldo stok/aset, operasi idempotent/reversal | `20261023100000_inventory_foundation.php`; `feat(inventory): add scoped stock ledger balances and seed items` | Scope/role, nonnegatif, serial, biaya rata-rata, seed ulang tidak menimpa edit, append-only, recalc dan migrasi ulang di DB uji |
| 2 — pembelian/transfer/return | Header/detail pembelian, model adapter kas aktif, kategori Perangkat, proteksi kas pembelian di `Cashflow_ui`, form bukti, transfer/return | `20261023101000_inventory_purchases.php`; `feat(inventory): add purchases transfers and returns` | Pembelian/expense tepat sekali; cicilan_lama tanpa kas; transfer atomik; return/replay/serial |
| 3 — WO/helpdesk/penarikan | `Inventory_service::record_ticket_usage` dan core pemakaian bersama; hook `Workorders`, `Work_order`, `Wo_manager`, `Helpdesk`, `Tickets`, `Ticket_model`, `Customers`, model WO/customer sesuai kebutuhan, jalur WO Telegram; form material/context penggantian, usage/serial history/recall; proteksi referensi | `20261023102000_inventory_work_usage_and_recalls.php`; `feat(inventory): integrate work orders ticket replacements and asset recalls` | Stok kurang rollback WO/tiket/history; semua jalur identik; legacy manual; reopen/replay; terminated sekali; >14 hari; lost hanya superadmin; tanpa tagihan/ACS otomatis |
| 4 — opname | `Stock_opname_model`, `Stock_opname_service`, controller/view sesi-counting-review-print-PDF, slot sesi aktif, snapshot/checklist/events | `20261023103000_inventory_stock_opnames.php`; `feat(inventory): add live stock opname and approval` | Mutasi selama counting, batas snapshot, delta approved, admin ditolak, serial lost, sesi/post ganda |
| 5 — minimum/Telegram/laporan | `Inventory_minimum_model/service`, `Inventory_alert_model/service`, `Inventory_report_model`, controller/view minimum/laporan; seed BOM/job/policy; Settings model/controller/view, `TelegramSendJob`, `/stok` pada webhook, hook checker existing, state/outbox/daily runs, regresi/dokumentasi | `20261023104000_inventory_minimum_alerts_telegram.php`; `feat(inventory): add BOM minimums inventory Telegram alerts and reports` | Angka 232/301, override/packs, alarm low/pulih dan minimum berubah, ringkasan WIB sekali, target inventory tanpa fallback, /stok terotorisasi, ledger/report, lint dan regresi akhir |

`scripts/tests/inventory_test.php` dibangun bertahap bersama domain, lalu dilengkapi pada tahap akhir. Setiap commit hanya memuat file/hunk pekerjaan inventaris. Perubahan lokal existing pada pelanggan, billing, upgrade, dokumen 14/15 dan pengujian collection dipertahankan; tidak di-stage/commit seluruh working tree.

## 10. Rencana pengujian dan dokumentasi

Pengujian integrasi memakai database staging/uji khusus dengan pengiriman/queue/router eksternal dimock atau dinonaktifkan. Bootstrap harus bebas efek samping sebelum semua tabel fixture disiapkan; tidak menyalin pemanggilan controller produksi tanpa menilai constructor. Tabel TEMPORARY cocok untuk uji koneksi tunggal; uji row lock, deadlock, dan snapshot simultan memerlukan dua koneksi dengan tabel uji persisten pada database uji terpisah. Tidak menjalankan migrasi/cron produksi untuk memvalidasi perubahan.

Skenario wajib `scripts/tests/inventory_test.php`:

1. Beli serial dan quantity -> saldo, valuasi moving average dan satu expense Perangkat; ulang key yang sama dan ulang header dengan key baru -> tetap sekali. Key sama/payload berbeda ditolak. `cicilan_lama` -> tidak ada expense.
2. Paksa gagal insert cashflow -> tidak ada mutasi/saldo/serial/header posted; bukti draft tetap konsisten.
3. Transfer gudang–teknisi–gudang -> pasangan qty/nilai net nol; serial berpindah benar; stok kurang atau salah router -> seluruhnya rollback.
4. WO memakai qty melampaui stok -> WO/history/mutasi tidak berubah; pemakaian valid -> committed bersama. Uji kedua controller, double-submit, POST pelaku palsu, assignment, dan retry activate.
5. Return good/damaged -> saldo kondisi/status/customer benar; return serial atau qty yang sama dua kali ditolak/idempotent tanpa saldo tambahan. Barang rusak tidak dapat dipakai WO.
6. Opname snapshot 100, keluar 20, fisik 78 -> delta -2; keluar lagi 10 setelah submit -> posting menjadi 68. Mutasi timestamp sama dan transaksi belum commit tidak boleh hilang dari perhitungan.
7. Admin/teknisi approve atau post -> ditolak; superadmin dengan router aktif lain juga ditolak. Alasan kosong, checklist belum lengkap, unknown belum selesai, atau surplus serial tanpa identitas -> tidak dapat submit.
8. Serial missing -> lost dan qty turun tepat satu; serial yang sah dipindahkan/installed sesudah cutoff tidak boleh diubah lost. Approval lama dengan perubahan biaya -> perlu review ulang.
9. Dua draft opname pada lokasi sama -> hanya satu berhasil; double-post -> mutasi sekali. Lokasi berbeda tetap dapat beroperasi saat counting.
10. Semua lookup/detail/PDF/laporan/tindakan langsung dites dengan ID router lain dan teknisi lain; non-superadmin tanpa scope tidak memperoleh data. Alias URI dan controller konvensional memiliki guard sama.
11. Recalc ledger = saldo cache dan valuasi; selisih cache disengaja terdeteksi; repair hanya cache, tanpa edit ledger. Reversal tidak boleh melampaui asal atau membuat saldo negatif.
12. Tiket penggantian -> stock serial baru -1, serial lama +1 damaged, baru installed/customer tiket, lama customer kosong, material tambahan berkurang, seluruhnya satu transaksi. Biaya pemakaian benar; invoice/payment/cashflow pembelian tidak berubah.
13. Helpdesk update_status, Helpdesk mark_done dan Tickets mark_done pada fixture identik -> ledger dan state identik. Resolve dua kali, dua key berbeda, reopen/resolve ulang -> satu pemakaian; payload lain konflik. Pembatalan admin ditolak; superadmin reversal terkontrol, tidak membuka replay.
14. Stok baru kosong, serial baru milik teknisi lain, serial lama customer/router lain, serial lama sama dengan baru, assignment kosong atau gagal reply/status -> seluruh mutasi dan status tiket tetap. Serial lama manual unik -> baseline dan return sekali; benturan existing ditolak; biaya legacy unknown dan perlu sinkron ACS terlihat.
15. Pelanggan static/PPPoE terminated atau aksi berhenti berulang -> satu WO termination aktif dan pending_return sekali. Suspend/isolir -> tidak membuat recall. Penarikan valid -> return kondisi dan status WO atomik; parsial tidak menyelesaikan WO. Uji dua request bersamaan, reaktivasi/termination berikutnya, dan pelarangan hard delete referensi.
16. pending_return tepat 14 hari belum masuk daftar; lebih dari 14 hari masuk daftar/notifikasi admin sekali per episode. Tidak otomatis lost; admin write-off ditolak, superadmin dengan alasan membuat WRITE_OFF domain aset, tanpa mengurangi stock teknisi kedua kali.
17. Seed 13 item, kategori/satuan, 4 BOM, parameter default; ulang seed tidak menimpa edit operator. Manual minimum 0 ditandai perlu diisi; simpan 0 sengaja dan kembali otomatis tercatat beserta alasan. Varian ONT-SB/DB punya harga/minimum berbeda.
18. Formula 232 pelanggan -> ONT-SB 5, DC-1C 1.000 m, SLV-PRT 10, ADP-12V1A 5, KLM-PAKU 100, KBL-TIES 50, PC-SCSC 5. Pada 301, cadangan ganti ONT naik ke 4 (minimum ONT total 6). Terminated/deleted tidak dihitung; suspended/isolated/isolir dihitung; static dan beberapa row service tidak menggandakan customer.
19. BOM/job baru, perubahan X/N/faktor, override dengan alasan, restore automatic dan pack rounding -> snapshot rincian dan hasil sesuai rumus; edit langsung memicu evaluasi state sesudah commit. Pembanding 30/90 hari tidak mengubah minimum; saldo damaged/loaned tidak available; target dan saran beli tidak negatif.
20. Saldo gudang melintas minimum -> satu event/pesan low; pemakaian lanjutan -> tidak ada alarm baru; isi ulang sampai ==minimum -> satu pulih; turun lagi -> satu low episode baru. Kenaikan minimum tanpa mutasi memicu low; minimum turun memicu pulih. Transaksi rollback tidak enqueue pesan; kegagalan enqueue/pengiriman tidak rollback stok dan bisa retry tanpa mengulang target sukses.
21. Grup inventory tidak ada/nonaktif/bot hilang/nonaktif/router berbeda/kolom scope hilang -> tidak mengirim ke tipe atau router lain. Flag fallback/direct chat_ids tidak dapat melewati cabang inventory ketat. Target diubah/dinonaktifkan setelah enqueue -> skip aman dan warning aplikasi.
22. /stok dari chat tidak terdaftar, DM, tipe collection/admin atau grup nonaktif -> tidak dijawab; secret invalid ditolak. Grup inventory aktif -> hanya saldo router sendiri; pencarian, redelivery update_id dan HTML/Unicode/pesan >4.096 karakter ditangani aman. Peta chat/router ambigu tidak membocorkan data.
23. Guard waktu injeksi: 06.59 WIB skip ringkasan, 07.00 WIB diproses (00.00 UTC), pemanggilan ulang/per jam/two workers/router sama/hari sama tidak membuat ringkasan kedua. Hari berikutnya boleh baru; tanpa item low tidak ada pesan. Multi-grup/multipart/retry gagal bagian kedua tidak mengulang bagian sukses. Semua pengujian memakai fake transport.
24. Uji delete/update/bulk/review request cashflow serta delete WO/tiket/customer terkait inventaris -> ditolak/arsip yang menjaga histori; non-inventaris mempertahankan perilaku existing. Jalur webhook speedtest tidak dapat menandai DONE melewati material.
25. Rekonsiliasi stock dan loaned_asset masing-masing = cache, history old -> new dan laporan jumlah/nilai penggantian tidak dihitung dua kali dari pasangan ledger. Return damaged tidak menghapus nilai riwayat penggantian; baseline legacy unknown diberi catatan nilai belum lengkap.

Sesudah implementasi: lint PHP file berubah, jalankan regresi inventaris pada DB uji, uji manual login tiga role dan alur ujung-ke-ujung, lalu perbarui dokumen 13 (fitur internal/role/aset dipinjamkan/penggantian/penarikan/BOM/Telegram), 14 (schema/constraint/transaksi/domain biaya/formula minimum), 15 (migrasi staging, CLI recalc, SOP opname/retur, bot inventory, jadwal 07.00 WIB dan worker/retry, cara tes/risiko), dan jalankan `python3 scripts/docs/generate_inventory.py` untuk memperbarui dokumen 16 dan JSON source. Regresi billing existing dijalankan hanya di lingkungan uji yang sesuai untuk memastikan hook customer/WO tidak mengubah invoice/payment. Tahap rencana tidak menjalankan generator karena belum ada perubahan route/controller/migrasi dan dokumen 14/15 masih memiliki perubahan lokal existing.

## 11. Risiko dan batas verifikasi

| Risiko | Penanganan / keputusan |
| --- | --- |
| Schema runtime tidak tersedia dalam source; nama inventory existing dapat bertabrakan | Finalisasi migrasi terhadap schema-only staging; pemeriksaan kompatibilitas tanpa overwrite/import otomatis |
| Perbedaan field/enum/engine/ID legacy | Adapter model eksplisit, preflight engine InnoDB dan kolom wajib, FK sesuai tipe aktual; jangan fallback ke query tanpa scope |
| Dua jalur WO dan completion Telegram, ditambah jalur Helpdesk/Tickets | Satukan core transaksi/material; tiga endpoint penyelesaian tiket memakai satu method; webhook tidak melewati material; pertahankan aktivasi existing per jalur WO |
| Label router_replace/device legacy tidak selalu berarti ONT serial | Gunakan penanda penggantian dan subtype yang tegas; reuse field existing, context 1:1 hanya bila diperlukan; adaptor quantity tidak diberi serial fiktif |
| Serial lama belum tercatat saat penggantian | Input manual legacy diperbolehkan dengan baseline/audit dalam transaksi yang sama, cek konflik customer/router; biaya belum diketahui ditandai, bukan menggagalkan seluruh alur hanya karena histori awal kosong |
| OUT pemasangan sudah mengurangi stok; pending_return lost tidak boleh menguranginya lagi | Ledger dipisahkan domain stock/loaned_asset, saldo cache masing-masing, WRITE_OFF aset pada domain aset dan role superadmin |
| Hard delete customer existing dapat menghapus WO/tiket/cache ONT sebelum penarikan | Guard sebelum efek delete dan gunakan arsip/termination untuk customer terkait inventaris; perubahan terlokalisasi tanpa mengubah billing umum |
| Kas inventaris bisa diubah lewat endpoint lama | Guard terpusat untuk referensi pembelian pada update/delete/bulk/review dan transaksi koreksi yang terkoordinasi |
| Perhitungan fisik diambil pada waktu berbeda saat stok bergerak | Snapshot/cutoff ber-mutex, waktu pengamatan, rekonsiliasi/count ulang baris terdampak sebelum submit; aplikasi tidak dapat menyimpulkan waktu fisik hanya dari angka input |
| Mutasi setelah approval mengubah biaya atau posisi serial | Tolak posting approval stale yang konflik; reject dan buat sesi pengganti dengan data baru, tanpa menulis ulang keputusan lama |
| Deadlock/double-submit/stock race | Urutan lock konsisten, indeks unik, hash payload dan retry transaksi terbatas; uji dua koneksi |
| Cache ACS bisa ditulis ulang oleh sinkronisasi | Inventaris menjadi sumber lifecycle aset; ACS tetap sumber telemetry. Hubungan serial/customer hanya diperbarui jika cocok dan dapat diaudit |
| Pembulatan moving average dan qty pecahan | BCMath, nilai baris eksplisit dan rekonsiliasi nilai; harga rata-rata tidak dihitung dengan float |
| Rekonsiliasi aset awal dan cache ACS | Tidak impor online ACS menjadi stok gudang; baseline aset legacy eksplisit dan jangan membuat telemetry palsu. Nilai unknown dipisahkan dari total nilai yang sudah diketahui |
| Jumlah pelanggan/minimum dapat berbeda karena duplikasi service atau scope data lama | COUNT DISTINCT, status billable yang sama, pengecualian deleted, resolver router konsisten dan rincian snapshot untuk audit |
| Banyak gudang memakai pelanggan router yang sama | Policy tiap gudang ditampilkan eksplisit; tidak mengalokasikan pelanggan/stock teknisi diam-diam; alarm lokasi dan saran beli total router punya dasar berbeda yang dijelaskan di UI |
| Mapping inventory jatuh ke general atau generic TelegramSendJob melewati scope | Tambah mapping/enum inventory dan cabang job ketat; target diverifikasi ulang saat eksekusi, fail-closed tanpa fallback |
| Proses mati sesudah stok commit tetapi sebelum alarm/queue | Recheck outbox persisten dalam transaksi stok; drain setelah commit/checker berikutnya, deduplikasi per event/target/part |
| Jadwal host UTC atau dua cron/worker | Guard DateTimeImmutable Asia/Jakarta; 07.00 WIB = 00.00 UTC; lock/router/date dan state unik, tanpa memasang cron produksi |
| Ledger append-only pada level aplikasi belum mencegah DBA mengubah SQL langsung | Tidak menyediakan method update/delete ledger, histori reversal dan kontrol akun DB; tidak mengklaim perlindungan terhadap perubahan DBA tanpa kontrol database tambahan |
| Pengiriman Telegram bukan exactly-once | Outbox dan status per tujuan mengurangi duplikasi; celah sukses-provider/gagal-persist tetap dicatat |
| UI pada direktori yang mungkin melayani server aktif | Tahap ini hanya menulis rencana. Implementasi diuji sebelum aktivasi; tidak menjalankan migrasi/cron, mengubah layanan, atau deployment produksi dari tugas ini |

Keputusan default untuk implementasi: moving average per barang/router, kode barang unik per router/serial global, minimum+alarm per barang/gudang good, seed 13 item per router, transfer dalam router, ledger domain stok/aset, satu pemakaian seumur WO/tiket, cutoff opname saat submit dan delta approval tetap; grup inventory saja tanpa fallback, alarm low/pulih per transisi dan ringkasan 07.00 WIB sekali per router/hari. Seluruhnya terdokumentasi agar dapat ditinjau sebelum kode diubah.

## 12. Hasil tahap 0

- Artefak: `docs/INVENTORY_PLAN.md` saja.
- Pemeriksaan yang dilakukan: pembacaan dokumentasi/source dan lampiran terbaru, pencarian inventaris/WO/tiket/termination/Telegram, pemeriksaan route/role/model/transaksi/queue/guard waktu, aritmetika formula seed, tautan dokumen, whitespace dan status Git. Tidak ada pembuktian runtime/database atau regresi fitur baru pada tahap ini.
- Setelah rencana ditampilkan, pekerjaan berhenti sesuai Langkah 0 lampiran. Lima tahap implementasi di atas belum dikerjakan.
