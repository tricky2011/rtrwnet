# Rencana modul Inventaris dan Stock Opname

Tanggal investigasi: **23 Oktober 2026 (Asia/Jakarta)**. Revisi Git saat investigasi: `4355ad3`; pembacaan mencakup working tree, termasuk perubahan lokal yang belum di-commit.

**Status: Langkah 0 selesai; implementasi belum dimulai.** Dokumen ini adalah rancangan yang ditampilkan sebelum perubahan kode, sesuai instruksi tugas: “Berhenti dan tampilkan rencana sebelum mengubah kode.” Tidak ada migrasi, cron, koneksi database, pengiriman pesan, atau perubahan perangkat yang dijalankan dalam investigasi ini.

## 1. Hasil investigasi dan komponen yang dipakai ulang

Dokumentasi dasar yang ditinjau: [13 — gambaran aplikasi](13_DOKUMENTASI_LENGKAP_BILLING_RTRWNET.md), [14 — spesifikasi teknis/data](14_SPESIFIKASI_TEKNIS_DAN_DATA.md), [15 — operasional/pengujian](15_OPERASIONAL_DEPLOYMENT_DAN_PENGUJIAN.md), serta [16 — referensi endpoint/source](16_REFERENSI_ENDPOINT_DAN_SOURCE.md). Lampiran 16 merupakan hasil generator pada revisi sebelumnya; source working tree menjadi acuan implementasi.

| Area | Temuan source | Keputusan integrasi |
| --- | --- | --- |
| Inventaris | Penelusuran source aplikasi, migrasi, SQL dokumentasi, dan skrip menemukan referensi operasional pada `CronNotification`, tanpa model/service/UI/DDL ledger atau opname | Buat domain inventaris; jangan menganggap tabel runtime pasti belum ada |
| Alarm | [CronNotification::do_check_inventory_minimum](../application/controllers/CronNotification.php#L300) mencoba `inventory_items` lalu `inventory`, membaca `current_stock/stock` dan `minimum_stock/min_stock`, membandingkan `<=`, maksimal 300 baris | Pertahankan endpoint; ganti sumber menjadi saldo gudang per router, kondisi `< stok_minimum`, termasuk saldo nol yang belum memiliki baris cache |
| Deduplikasi alarm | `notify_router_roles_once()` memakai notifikasi user dalam enam jam; belum ada state pemulihan stok atau pengiriman Telegram khusus checker ini | Tambahkan state persisten dan pengiriman per kanal/tujuan, gunakan ulang `Notification_model` dan client Telegram |
| Router/role | [MY_Controller](../application/core/MY_Controller.php) menyiapkan login, status user, router aktif, akses `user_router_access`, RBAC, dan log aktivitas | Semua controller web baru mewarisi `MY_Controller`; gunakan `getEffectiveRouterId()`, `applyRouterFilter()`, normalisasi role dan helper scope yang ada |
| Helper scope | [router_scope_helper](../application/helpers/router_scope_helper.php) menyediakan `getRouterScopeId()` dan `active_router_id()`; default helper terakhir adalah router 1 | Jangan memakai fallback router 1 untuk operasi stok; konteks tulis wajib router eksplisit yang diizinkan |
| Model pelanggan | [Customer_model](../application/models/Customer_model.php) memiliki pola `set_router_scope()` dan adaptasi field | Gunakan referensi pelanggan yang sama; model inventaris wajib menolak non-superadmin tanpa scope, termasuk lookup ID dan query raw ber-lock |
| Master lokasi/OLT | [Master_reference_model](../application/models/Master_reference_model.php) memakai `master_locations`, `master_olts`; [SQL scope master](07_MIGRATION_MASTER_REFERENCES_ROUTER_SCOPE.sql) mendefinisikan router dan indeks | Gudang dapat mereferensikan `master_locations.id`. `master_olts` adalah referensi jaringan/topologi, bukan lokasi saldo atau master perangkat gudang; tidak dibuat ulang |
| WO daftar | [Workorders::mark_done](../application/controllers/Workorders.php#L266) memeriksa POST dan scope bila kolom tersedia, kemudian langsung update status/timestamp serta mengirim notifikasi | Delegasikan penyelesaian dan pemakaian material ke service bersama; saat ini belum ada transaksi stok/WO atau lock WO |
| WO lifecycle | [Work_order::done/activate](../application/controllers/Work_order.php), [Wo_manager::complete_wo/activate_wo](../application/libraries/Wo_manager.php) adalah jalur terpisah. `complete_wo()` menulis WO, history, notifikasi, lalu mencoba aktivasi instalasi | Integrasikan keduanya dengan transaksi penyelesaian bersama, tanpa memaksakan alur aktivasi lifecycle ke tombol daftar yang sebelumnya tidak mengaktifkan pelanggan |
| Scope WO lifecycle | [Wo_model](../application/models/Wo_model.php) mengambil/mengubah WO berdasarkan ID tanpa filter router; `Work_order` menerima ID pelaku dari POST | Tambahkan lookup WO ter-scope dan cek assignment pada jalur yang terlibat; identitas pelaku berasal dari session. ID teknisi POST hanya boleh menjadi pilihan stok yang diverifikasi |
| Status WO | Daftar mengenali `done/completed/activated/cancel`; lifecycle menggunakan `open/process/done/activated/cancel`, dan tipe maintenance dapat memiliki alias repair | Adapter field/status/tipenya tetap mengikuti schema yang tersedia, tetapi tidak menganggap WO batal sebagai keberhasilan posting material |
| Cashflow aktif | [Cashflow_ui](../application/controllers/Cashflow_ui.php) dan `Billing_automation_model` memakai `cashflow_transactions` dan `cashflow_categories` | Satu expense pembelian ditulis ke tabel aktif yang sama melalui model inventaris, dalam koneksi/transaksi yang sama |
| Cashflow legacy | [Cashflow_model](../application/models/Cashflow_model.php) memakai `cashflows`, bukan tabel UI aktif | Jangan memanggil `Cashflow_model::create_expense()` untuk integrasi baru dan jangan membuat tabel kas kedua |
| Mutasi cashflow lama | `Cashflow_ui` menyediakan update, delete permanen superadmin, bulk, dan approval request edit/delete | Lindungi khusus transaksi yang terhubung pembelian inventaris pada seluruh jalur tersebut, agar kas tidak terpisah dari ledger; perilaku transaksi non-inventaris tetap seperti semula |
| Serial ACS | [Ont_device_model](../application/models/Ont_device_model.php) menyimpan cache berdasarkan serial dan router bila tersedia; `ont_devices.status` adalah online/offline | Buat catatan aset serial tersendiri; hubungkan ke cache ACS/customer yang cocok tanpa mengganti status jaringan menjadi status stok |
| UI/PDF | WO memakai [layout/main](../application/views/layout/main.php) dan [layout/sidebar](../application/views/layout/sidebar.php); Dompdf sudah menjadi dependency, dipakai laporan helpdesk/teknisi | Ikuti Bootstrap 5.3, form helper/CSRF, escaping, pagination, Rupiah, dan WIB; gunakan Dompdf yang tersedia |
| Alias route | [routes.php](../application/config/routes.php) memetakan pasangan dash/underscore; `translate_uri_dashes` false | Setiap endpoint baru dengan kata majemuk memiliki kedua alias; cek juga URI controller konvensional |
| Pola regresi | [billing_collection_test.php](../scripts/tests/billing_collection_test.php) bootstrap aplikasi dan memakai tabel TEMPORARY koneksi lokal | Buat regresi inventaris di database uji terisolasi; jangan menjalankan bootstrap uji yang dapat menyentuh produksi |

`scripts/docs/generate_inventory.py` adalah generator inventaris **source**, bukan seed barang. Jalankan pada tahap dokumentasi akhir setelah route/controller/migrasi baru selesai.

## 2. Batas dan keputusan rancangan

- Controller tipis; validasi bisnis, otorisasi tindakan, transaksi dan idempotensi di `Inventory_service`/`Stock_opname_service`; seluruh query, termasuk SQL lock dan adaptasi cashflow, di model.
- Semua data domain memiliki `router_id NOT NULL`. Superadmin dapat membaca gabungan router; untuk menulis harus memilih satu router. Transfer awal hanya dalam router yang sama, sesuai alur gudang–teknisi–gudang.
- Kode barang unik di seluruh aplikasi; barang tetap dimiliki satu router. Serial juga unik di seluruh aplikasi. Duplikasi lintas router ditolak tanpa membocorkan detail router lain.
- Lokasi teknisi unik per `(router_id, technician_user_id)`; seorang teknisi multi-router memiliki saldo terpisah. Assignment diverifikasi terhadap `user_router_access` dan kompatibilitas `users.router_scope_id` yang sudah ada.
- Harga rata-rata bergerak dihitung per barang/router, berlaku bersama untuk lokasi-lokasinya. Transfer tidak mengubah nilai total atau harga rata-rata.
- Saldo fisik dibedakan `good` dan `damaged` agar retur rusak tidak dapat dipakai pemasangan. Laporan total tetap mengelompokkan barang/lokasi, dengan rincian kondisi dan stok layak pakai.
- Master barang/lokasi dinonaktifkan; transaksi posted, mutasi, dan relasi keuangan tidak dihapus. Barang bersaldo atau bereferensi tidak boleh diganti satuan/tracking/router-nya.
- Tidak ada perubahan formula atau alur billing/invoice/payment. Pengembalian pelanggan menggunakan transaksi inventaris atau material WO termination, tanpa menambahkan efek stok otomatis pada setiap perubahan status customer.
- Jika tabel `inventory_items`/`inventory` sudah ada pada lingkungan tujuan, migrasi melakukan pemeriksaan kompatibilitas dan berhenti dengan pesan jelas bila bertabrakan. Tidak menimpa/mengimpor saldo legacy secara otomatis. Schema-only staging diperlukan sebelum finalisasi DDL.

## 3. Tabel, relasi dan indeks yang direncanakan

Seluruh tabel baru: InnoDB, `utf8mb4_unicode_ci`, primary key internal, `created_by`/`created_at` pada tabel transaksi beserta detailnya. Uang `DECIMAL(15,2)`, kuantitas bertanda `DECIMAL(12,3)`, timestamp WIB. `created_by` proses sistem dapat nullable dengan identitas sumber sistem yang eksplisit; transaksi manusia wajib user valid. FK ke tabel existing disesuaikan tipe/engine aktual, tidak diasumsikan seluruh ID legacy identik. Relasi baru memakai RESTRICT, tanpa cascade delete histori.

| Tabel | Isi dan relasi utama | Constraint/indeks penting |
| --- | --- | --- |
| `inventory_categories` | Kamus kategori dasar | UNIQUE `code`; seed ONT, Router, Kabel, Konektor, Adaptor, Patch cord, Splitter, ODP, Lainnya |
| `inventory_units` | Kamus satuan dasar | UNIQUE `code`; seed unit, pcs, meter, roll |
| `inventory_items` | router, code, name, category_id, unit_id, tracking_mode serial/quantity, minimum_stock, is_active, audit | UNIQUE `code`; indeks router/active/category; qty serial selalu bilangan bulat |
| `inventory_locations` | router, code, name, type warehouse/technician, master_location_id nullable, technician_user_id nullable, is_active | UNIQUE `(router_id, code)`; UNIQUE `(router_id, technician_user_id)`; warehouse wajib tanpa technician_user_id; juga menjadi mutex transaksi lokasi |
| `inventory_valuations` | Cache total qty, inventory_value dan average_cost per item/router | UNIQUE `(router_id, item_id)`; row lock untuk serialisasi moving average |
| `inventory_balances` | item, location, stock_condition good/damaged, quantity, audit pembaruan | UNIQUE `(item_id, location_id, stock_condition)`; qty setiap kondisi tidak negatif; indeks router/location |
| `inventory_serials` | router, item, serial_number, MAC nullable, status, current_location_id nullable, customer_id nullable, ont_device_id nullable | UNIQUE `serial_number`; indeks router/item/status/location/customer; status in_stock, with_technician, installed, returned, damaged, lost |
| `inventory_operations` | Identitas perintah posting dan hasilnya, router, operation_type, idempotency_key, request_hash, reference_type/id, created_by/at | UNIQUE `idempotency_key`; hasil tersimpan dalam transaksi yang sama; key sama dengan payload berbeda ditolak |
| `inventory_movements` | router, operation_id, item_id, location_id, serial_id nullable, stock_condition, movement_type, signed qty, unit_cost, value_amount, purchase_id/line_id, work_order_id, customer_id, opname_id/line_id, reverses_movement_id nullable, notes, created_by/at | UNIQUE `idempotency_key` per baris; indeks `(router_id, location_id, id)`, `(router_id, item_id, id)`, WO/customer/opname; hanya INSERT/SELECT dari aplikasi |
| `inventory_purchases` | router, date, supplier text, funding_source, destination_location_id, total, notes, evidence_path nullable, draft/posted, cashflow_transaction_id nullable, posted_by/at | UNIQUE nomor pembelian dan key posting; UNIQUE cashflow_transaction_id bila terisi |
| `inventory_purchase_lines` | purchase, item, qty, unit_cost, line_total, detail serial yang harus diterima | Indeks purchase/item; hanya dapat diedit selama draft; posting serial dipecah menjadi mutasi per serial |
| `inventory_opnames` | router, location, nomor, status, snapshot_at, snapshot_movement_id, counting_cutoff_at/id, total selisih qty/nilai, submitted/approved/posted/rejected by/at, review note | Indeks router/location/status/waktu; kontrol satu sesi aktif melalui baris lokasi yang dikunci |
| `inventory_opname_active_locations` | location_id, opname_id, router, audit | PRIMARY/UNIQUE location_id dan UNIQUE opname_id; diisi pada draft, dilepas hanya posted/rejected, dalam transaksi yang sama |
| `inventory_opname_lines` | opname, item, condition, snapshot_qty, net_movement_qty, expected_qty, physical_qty, difference_qty, approval_unit_cost/value, reason_code/text | UNIQUE `(opname_id, item_id, stock_condition)`; alasan wajib untuk setiap selisih |
| `inventory_opname_serials` | opname/line, serial_id, expected_at_snapshot/cutoff, found/missing, counted_at, catatan | UNIQUE `(opname_id, serial_id)`; identitas serial tidak digantikan hanya oleh selisih jumlah |
| `inventory_opname_unknown_serials` | opname, scanned_serial, MAC opsional, catatan, status resolusi dan reference serial/item yang sudah diverifikasi | UNIQUE `(opname_id, scanned_serial)`; tidak otomatis menambah saldo/merampas serial milik lokasi lain |
| `inventory_opname_events` | Histori transisi status, pelaku, alasan, waktu, ringkasan versi perhitungan | Append-only; indeks opname/id; rejection dan approval dapat diaudit |
| `inventory_alert_states` | router, item, stok gudang agregat, below_minimum, episode, last_triggered_at, cooldown_until, recovered_at | UNIQUE `(router_id, item_id)`; state berubah di bawah row lock |
| `inventory_alert_deliveries` | state/episode, kanal, user/group tujuan, payload, status/retry/sent_at, audit | UNIQUE state/episode/kanal/tujuan/reminder-slot; outbox persisten untuk retry setelah commit |

Kategori/satuan merupakan kamus bersama berisi seed tetap, bukan data operasional router. Seluruh query data barang/lokasi/serial/transaksi/laporan dibatasi router; join ke kamus tidak membuka data router lain. Hak edit kamus tidak menjadi endpoint umum pada tahap awal.

Relasi domain: `routers -> items/locations -> balances/movements`; `items -> serials`; `purchases -> lines -> movements` dan satu `cashflow_transactions`; `work_orders/customers -> movements`; `locations -> opnames -> lines/serial checklist/events -> ADJUST_OPNAME`. Gudang mereferensikan master lokasi existing; tidak ada duplikasi tabel customer, teknisi, OLT atau kas.

## 4. Kontrak posting ledger dan perhitungan biaya

1. Bentuk actor context dari session tervalidasi; periksa role, router, lokasi, status aktif, assignment teknisi, dan semua referensi di server. CLI memakai konteks sistem eksplisit per router, bukan session/fallback global.
2. Validasi input desimal secara ketat: maksimal tiga digit pecahan qty, dua digit harga; tidak memakai float biner sebagai dasar saldo/uang. Gunakan BCMath yang tersedia pada target installer, dengan pemeriksaan prasyarat; round half-up pada batas penyimpanan uang dan simpan `value_amount` untuk rekonsiliasi.
3. Mulai satu transaksi terluar. Reservasi `inventory_operations` dengan key stabil dan hash payload kanonik. Replay operasi committed mengembalikan hasil lama setelah cek akses; key sama/payload berbeda adalah konflik. Request gagal rollback tidak menyisakan key sukses palsu.
4. Kunci header operasi/WO/opname bila relevan, lokasi menurut ID menaik, valuasi menurut item, saldo menurut item/lokasi/kondisi, lalu serial menurut ID. Sediakan baris saldo nol secara aman sebelum `SELECT ... FOR UPDATE`. Semua jalur memakai urutan yang sama; retry deadlock terbatas mengulang seluruh transaksi.
5. Periksa saldo dan state serial; tulis mutasi, saldo, valuasi, relasi serial, header dan cashflow/WO dalam koneksi DB yang sama. Helper posting internal tidak melakukan commit transaksi pemanggil. Kegagalan bagian mana pun menggagalkan keseluruhan posting.
6. Selesaikan transaksi; baru jalankan notifikasi, Telegram, dan aktivasi eksternal. Tidak ada HTTP/aksi MikroTik di dalam transaksi ledger.

Jenis mutasi tetap mengikuti tugas: `IN_PURCHASE`, `OUT_INSTALL`, `OUT_MAINTENANCE`, `TRANSFER`, `RETURN_GOOD`, `RETURN_DAMAGED`, `ADJUST_OPNAME`, `WRITE_OFF`. Qty ditandatangani; pembelian/return positif, pemakaian/write-off negatif, opname sesuai selisih. Serial wajib `serial_id` dan qty `+1/-1` pada setiap barisnya. Transfer satu operation menghasilkan pasangan dengan qty berlawanan, serial dan biaya sama, sehingga total qty/nilai router tidak berubah. Referensi operation mengikat pasangan tanpa perlu update baris mutasi pertama.

Koreksi membuat baris dengan `reverses_movement_id` dan tanda berlawanan; sumber tidak diubah. Validasi akumulasi reversal agar tidak melebihi qty/nilai asal, termasuk pasangan transfer dan posisi serial terkini. Reversal tidak boleh menghindari pemeriksaan saldo. Koreksi pembelian yang memengaruhi kas harus melalui operasi koreksi terkoordinasi; endpoint reversal generik tidak boleh meninggalkan expense lama atau menghapusnya.

Biaya pembelian: `average_cost_baru = (nilai_persediaan_lama + nilai_masuk) / (qty_lama + qty_masuk)`. Pemakaian/write-off memakai rata-rata saat posting; pengembalian memakai biaya dari mutasi pemakaian asal yang dirujuk dan menghitung ulang rata-rata. Transfer membawa biaya yang sama pada kedua sisi. Sisa pembulatan uang diserap pada pengeluaran terakhir ketika qty menjadi nol agar nilai persediaan menjadi nol juga. Stok rusak ditampilkan terpisah, tidak tersedia untuk WO; penghapusan nilainya dilakukan WRITE_OFF oleh superadmin, bukan sekadar mengganti status serial.

Saldo awal bukan update cache atau pembelian fiktif: gunakan sesi opname awal yang disetujui superadmin dan biaya awal terdokumentasi. Cache selalu dapat dijelaskan oleh ledger.

CLI yang direncanakan: `php index.php inventory_cli recalc ROUTER_ID` untuk laporan selisih ledger/cache dengan exit status gagal jika berbeda. Mode perbaikan harus eksplisit `repair ROUTER_ID`, mengunci lokasi/valuasi terkait lalu membangun ulang cache dari ledger; tidak mengubah ledger. Bandingkan juga valuasi serta jumlah serial per kondisi. Endpoint CLI menolak HTTP; command ini belum dibuat atau dijalankan.

## 5. Pembelian, transfer, return dan integrasi WO

### Pembelian

- Form header/detail menerima tanggal valid, supplier, gudang tujuan, sumber `kas_bisnis/biaya_pasang/cicilan_lama`, catatan, foto bukti opsional, kuantitas/harga dan daftar serial untuk barang serial. Total dihitung server, bukan dipercaya dari form.
- Posting mengunci header draft dan membuat mutasi masuk serta satu expense `cashflow_transactions` kategori expense **Perangkat** untuk `kas_bisnis` dan `biaya_pasang`. `cicilan_lama` tetap menambah persediaan tanpa expense baru. Tidak ada efek payment/invoice.
- Gunakan ulang kategori Perangkat existing jika cocok; seed hanya bila belum ada dan schema kompatibel (`category_name/name`, `category_code/code`). Perbedaan nama kolom kas ditangani model; schema wajib yang hilang menghasilkan penolakan jelas, bukan posting stok tanpa expense.
- Penanda referensi kas `inventory_purchase` dan ID pembelian, ditambah relasi kas unik pada header, mengikat satu expense. Header ber-lock dan key operasi mencegah submit ganda termasuk dua key berbeda untuk pembelian yang sama.
- Bukti foto disimpan dengan nama acak, batas tipe/MIME/ukuran, referensi melalui endpoint ter-scope; file yatim saat transaksi gagal dibersihkan tanpa menghapus bukti posted.

### Transfer dan return

- Admin/superadmin memilih dua lokasi aktif dalam router yang sama; pemakaian dibatasi stok `good`. Transfer serial mengecek lokasi/status nyata dan memindahkan ke in_stock/with_technician sesuai tujuan. Tidak boleh ada transfer setengah berhasil.
- Return pelanggan wajib merujuk customer, serial installed dan mutasi pemakaian asal. Return baik menjadi stok layak pakai berstatus returned; return rusak masuk kondisi damaged/status damaged. Putuskan assignment customer pada aset saat return, simpan relasi historis di ledger, dan cocokkan pembaruan `ont_devices.customer_id` hanya untuk serial/router/customer yang sama.
- Barang kuantitas yang dikembalikan juga membatasi akumulasi return terhadap pemakaian asal. Klik ulang atau key baru untuk serial yang sudah kembali tidak boleh menambah stok kedua kali.

### WO

- Tambah form material pada penyelesaian WO: teknisi/lokasi sumber tervalidasi, pilihan serial, qty kabel/konektor/barang lain, serta pernyataan tanpa material dengan alasan bila memang tidak memakai barang. Tidak otomatis menghabiskan stok berdasarkan jenis WO saja.
- `Workorders::mark_done` dan `Work_order::done -> Wo_manager::complete_wo` mendelegasikan bagian database kepada service penyelesaian bersama. Service mengunci WO, memvalidasi status/assignment/scope, memposting OUT_INSTALL atau OUT_MAINTENANCE, memperbarui WO/history dalam transaksi yang sama. Tipe repair dipetakan sebagai maintenance; tipe lain perlu tindakan material eksplisit yang valid, tidak dianggap installation.
- Teknisi hanya boleh menyelesaikan pekerjaan yang diizinkan dan memakai stoknya sendiri. Admin/superadmin dapat memilih stok teknisi yang ditugaskan dalam router WO. ID pelaku POST tidak menjadi otorisasi.
- Qty melampaui stok atau serial tidak tersedia menolak seluruh penyelesaian, termasuk perubahan status dan history. Replay completion tidak menghabiskan material lagi, dan payload material berbeda pada WO selesai ditolak sebagai konflik.
- `activate()` hanya aktivasi/retry setelah DONE dan tidak memposting ulang stok. Pertahankan aktivasi existing setelah commit untuk jalur lifecycle; kegagalan MikroTik tidak menghapus pemakaian yang telah committed.
- Blokir hard delete WO yang telah direferensikan ledger. Cancellation setelah konsumsi memerlukan return/reversal eksplisit, tidak menghilangkan histori.

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
| GET | `stock-opname` / `stock_opname`; `stock-opname/show/{id}` / `stock_opname/show/{id}` | `Stock_opname`: daftar/detail sesuai scope/lokasi |
| POST | `stock-opname/create`, `start-counting/{id}`, `save-count/{id}`, `submit/{id}`; padanan `stock_opname` dengan method underscore | Superadmin/admin; teknisi hanya lokasinya |
| POST | `stock-opname/approve/{id}`, `reject/{id}`, `post/{id}`; padanan `stock_opname` | Hanya superadmin |
| GET | `stock-opname/print/{id}`, `export-pdf/{id}`; padanan `stock_opname/print/{id}`, `export_pdf/{id}` | Cetak/PDF sesuai akses sesi |
| GET | `inventory-reports/balances`, `stock-card/{id}`, `technicians`, `opnames`, `material-usage`; padanan `inventory_reports` dan method underscore | `Inventory_reports`: saldo/nilai, kartu stok, bawaan teknisi, riwayat/selisih opname, pemakaian bulanan/per WO |
| POST | Existing `workorders/mark-done/{id}` / `mark_done/{id}`, endpoint `work_order/done` dan `activate` | Dipertahankan dengan material, scope/assignment, transaksi dan replay guard |
| CLI/guard existing | `CronNotification check_inventory_minimum`; alias existing `cron-notification/check-inventory-minimum` dan tambah `cron_notification/check_inventory_minimum` | Tetap memakai guard cron existing; proses per router eksplisit |
| CLI saja | `inventory_cli recalc ROUTER_ID`, `inventory_cli repair ROUTER_ID` | Laporan/perbaikan cache; tidak menerima HTTP |

Untuk baris endpoint berawalan sama, suffix tabel adalah relatif ke prefix pada baris tersebut. URI statis dideklarasikan sebelum pola ID agar tidak tertangkap route detail.

Hak akses per aksi:

| Aksi | superadmin | admin | teknisi |
| --- | --- | --- | --- |
| Master barang | kelola | kelola | lihat |
| Pembelian/transfer/return langsung | ya | ya | tidak |
| Material WO | ya | ya | pekerjaan yang diizinkan, stok sendiri |
| Opname draft/counting/submit | ya | ya | lokasi sendiri |
| Approve/reject/post opname | ya | tidak | tidak |
| Write-off/reversal | ya | tidak | tidak |
| Laporan | sesuai router aktif/semua jika diizinkan | router aktif | lokasi sendiri dan WO yang diizinkan |

Tambah entri `inventory`, `stock_opname`, `inventory_reports` pada `roleMatrix()` dan menu pada sidebar layout aktif. Menu master/lokasi, saldo/serial, pembelian/transfer/return, stock opname, laporan disesuaikan role; pengecekan server tetap wajib. View memakai layout existing, formatter Rupiah dan qty tiga desimal tanpa trailing zero berlebih, bukan halaman/framework UI terpisah. PDF memakai query ter-scope dan aset lokal; tidak membuka URL bukti arbitrer dari input.

## 8. Laporan dan alarm stok minimum

- Saldo/nilai per barang-lokasi-router dengan good/damaged/available dan harga rata-rata; tampilkan baris stok nol. Nilai total direkonsiliasi dengan valuasi dan ledger, dengan aturan pembulatan yang sama.
- Kartu stok berdasarkan urutan ledger `id`, saldo awal sebelum rentang, signed qty, referensi, biaya, nilai dan saldo berjalan. Filter tanggal memakai waktu posting; tanggal pembelian bisnis disimpan terpisah agar backdate tidak merusak batas snapshot.
- Bawaan teknisi dikelompokkan teknisi/lokasi/router; teknisi hanya miliknya.
- Riwayat opname per periode/status/lokasi dengan nilai surplus, kekurangan, net, dan total absolut agar kerugian tidak tersembunyi oleh surplus.
- Pemakaian bulanan/per WO berasal dari OUT_INSTALL/OUT_MAINTENANCE beserta reversal yang relevan; tampilkan pemakaian bruto, pengembalian dan net bila diperlukan.
- Alarm membandingkan jumlah stok **good gudang** per barang/router terhadap minimum; stok teknisi/rusak tidak menutupi kekurangan gudang. Barang nonaktif dilewati. State awal di bawah minimum memulai episode; pulih ketika stok >= minimum, dan turun lagi membentuk episode baru.
- Cooldown awal enam jam mengikuti perilaku checker lama. Kondisi sama tidak mengirim setiap cron; reminder hanya setelah cooldown. Perubahan qty selama masih di bawah minimum tidak membuat episode baru; retry tujuan gagal tidak mengulang tujuan sukses.
- Dalam transaksi state, simpan outbox notifikasi per user dan Telegram per grup admin/owner aktif milik router yang sama, dengan bot aktif. Gunakan ulang `Notification_model`, query akses user termasuk `user_router_access`, dan `Telegram_service`; jangan fallback ke grup/router lain atau bot global tanpa konteks.
- Pengiriman dilakukan setelah commit. State/payload dan status per tujuan disimpan; cooldown tidak menghilangkan retry pesan gagal. API Telegram tetap memiliki celah duplikasi ketika provider sudah menerima lalu proses mati sebelum status sukses tersimpan; dokumentasikan keterbatasan tersebut.

## 9. Tahapan implementasi dan commit

Nama timestamp berikut **usulan**, diperiksa ulang agar urut setelah migrasi existing `20261023090000_customer_monthly_price_override.php` dan tidak bentrok saat implementasi. Migrasi di filesystem disiapkan tanpa dijalankan ke produksi. `down()` tidak menghapus histori transaksi.

| Tahap | Artefak dan perubahan | Migrasi/commit yang direncanakan | Gerbang verifikasi |
| --- | --- | --- | --- |
| 0 — investigasi | Dokumen rencana ini | Commit dokumentasi rencana tersendiri | Tampilkan rencana, berhenti sebelum kode |
| 1 — master/lokasi/ledger/saldo | `Inventory_model`, `Inventory_service`, controller `Inventory`, `Inventory_cli`, view master/saldo/serial, RBAC, route, sidebar, seed kamus, operasi idempotent/reversal | `20261023100000_inventory_foundation.php`; `feat(inventory): add scoped stock ledger and balances` | Scope/role, nonnegatif, serial, biaya rata-rata, append-only, recalc dan migrasi ulang di DB uji |
| 2 — pembelian/transfer/return | Header/detail pembelian, model adapter kas aktif, kategori Perangkat, proteksi kas pembelian di `Cashflow_ui`, form bukti, transfer/return | `20261023101000_inventory_purchases.php`; `feat(inventory): add purchases transfers and returns` | Pembelian/expense tepat sekali; cicilan_lama tanpa kas; transfer atomik; return/replay/serial |
| 3 — WO | Service penyelesaian WO bersama (mis. `Inventory_work_order_service`), scoped lookup di model WO, hook `Workorders`, `Work_order`, `Wo_manager`, form material, proteksi WO bereferensi | Migrasi tambahan hanya jika benar-benar perlu field existing; `feat(inventory): post work order materials atomically` | Stok kurang rollback WO/history/ledger; kedua jalur konsisten; retry activate tanpa konsumsi |
| 4 — opname | `Stock_opname_model`, `Stock_opname_service`, controller/view sesi-counting-review-print-PDF, slot sesi aktif, snapshot/checklist/events | `20261023102000_inventory_stock_opnames.php`; `feat(inventory): add live stock opname and approval` | Mutasi selama counting, batas snapshot, delta approved, admin ditolak, serial lost, sesi/post ganda |
| 5 — laporan/alarm | `Inventory_report_model`, `Inventory_reports`, `Inventory_alert_service`, state/outbox model, hook checker existing, laporan, regresi lengkap, dokumentasi | `20261023103000_inventory_alert_states.php`; `feat(inventory): add reports and scoped minimum stock alerts` | Saldo/kartu/nilai sesuai ledger, cooldown/recovery/retry, tujuan router, lint dan regresi akhir |

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
12. Alarm gudang nol/<minimum/==minimum, recovery lalu turun lagi, reminder cooldown, dua cron paralel, grup salah router dan kegagalan kanal -> jumlah pesan sesuai state tanpa pengiriman eksternal riil.
13. Uji delete/update/bulk/review request cashflow serta delete WO yang terkait inventaris -> ditolak; transaksi non-inventaris mempertahankan perilaku existing.

Sesudah implementasi: lint PHP file berubah, jalankan regresi inventaris pada DB uji, uji manual login tiga role dan alur ujung-ke-ujung, lalu perbarui dokumen 13 (fitur/role/WO), 14 (schema/constraint/transaksi/biaya), 15 (migrasi staging, CLI recalc, SOP opname, alarm, cara tes/risiko), dan jalankan `python3 scripts/docs/generate_inventory.py` untuk memperbarui dokumen 16 dan JSON source. Regresi billing existing hanya dijalankan di lingkungan uji yang sesuai bila hook WO atau perubahan dokumentasi/schema menimbulkan kebutuhan verifikasi tambahan.

## 11. Risiko dan batas verifikasi

| Risiko | Penanganan / keputusan |
| --- | --- |
| Schema runtime tidak tersedia dalam source; nama inventory existing dapat bertabrakan | Finalisasi migrasi terhadap schema-only staging; pemeriksaan kompatibilitas tanpa overwrite/import otomatis |
| Perbedaan field/enum/engine/ID legacy | Adapter model eksplisit, preflight engine InnoDB dan kolom wajib, FK sesuai tipe aktual; jangan fallback ke query tanpa scope |
| Dua jalur WO dengan perilaku aktivasi berbeda | Satukan hanya bagian transaksi penyelesaian/material; pertahankan kebijakan aktivasi per jalur dan uji keduanya |
| Kas inventaris bisa diubah lewat endpoint lama | Guard terpusat untuk referensi pembelian pada update/delete/bulk/review dan transaksi koreksi yang terkoordinasi |
| Perhitungan fisik diambil pada waktu berbeda saat stok bergerak | Snapshot/cutoff ber-mutex, waktu pengamatan, rekonsiliasi/count ulang baris terdampak sebelum submit; aplikasi tidak dapat menyimpulkan waktu fisik hanya dari angka input |
| Mutasi setelah approval mengubah biaya atau posisi serial | Tolak posting approval stale yang konflik; reject dan buat sesi pengganti dengan data baru, tanpa menulis ulang keputusan lama |
| Deadlock/double-submit/stock race | Urutan lock konsisten, indeks unik, hash payload dan retry transaksi terbatas; uji dua koneksi |
| Cache ACS bisa ditulis ulang oleh sinkronisasi | Inventaris menjadi sumber lifecycle aset; ACS tetap sumber telemetry. Hubungan serial/customer hanya diperbarui jika cocok dan dapat diaudit |
| Pembulatan moving average dan qty pecahan | BCMath, nilai baris eksplisit dan rekonsiliasi nilai; harga rata-rata tidak dihitung dengan float |
| Serial legacy yang sudah installed sebelum ledger ada | Tidak impor otomatis dari online ACS menjadi stok gudang. Perlu inventaris awal tervalidasi/riwayat dasar aset agar retur memiliki referensi yang sah |
| Ledger append-only pada level aplikasi belum mencegah DBA mengubah SQL langsung | Tidak menyediakan method update/delete ledger, histori reversal dan kontrol akun DB; tidak mengklaim perlindungan terhadap perubahan DBA tanpa kontrol database tambahan |
| Pengiriman Telegram bukan exactly-once | Outbox dan status per tujuan mengurangi duplikasi; celah sukses-provider/gagal-persist tetap dicatat |
| UI pada direktori yang mungkin melayani server aktif | Tahap ini hanya menulis rencana. Implementasi diuji sebelum aktivasi; tidak menjalankan migrasi/cron, mengubah layanan, atau deployment produksi dari tugas ini |

Keputusan default untuk implementasi: moving average per barang/router, minimum stock agregat gudang good per router, transfer hanya dalam router, kategori/satuan seed bersama, dan cutoff opname saat submit dengan delta approval tetap. Seluruhnya terdokumentasi agar dapat ditinjau sebelum kode diubah.

## 12. Hasil tahap 0

- Artefak: `docs/INVENTORY_PLAN.md` saja.
- Pemeriksaan yang dilakukan: pembacaan dokumentasi/source, pencarian referensi, pemeriksaan route/role/model/transaksi, dan status Git. Tidak ada pembuktian runtime/database atau regresi fitur baru pada tahap ini.
- Setelah rencana ditampilkan, pekerjaan berhenti sesuai Langkah 0 lampiran. Lima tahap implementasi di atas belum dikerjakan.
