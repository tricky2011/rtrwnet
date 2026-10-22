# Tagihan dua bulan dan penagihan rumah

Form tambah/edit pelanggan menyediakan **Siklus Tagihan**, **Bulan Awal Siklus**,
dan **Cara Penagihan**. Tarif paket tetap tarif bulanan. Siklus 2 bulan menerbitkan
satu invoice senilai dua kali tarif, mencakup dua bulan kalender. Contoh awal
November 2026: Nov–Des 2026, Jan–Feb 2027, Mar–Apr 2027. Jatuh tempo tetap
mengikuti tanggal jatuh tempo pelanggan pada bulan pertama siklus.

Pelanggan lama tetap bersiklus 1 bulan dengan cara penagihan **Belum ditentukan**.
Tandai sendiri pelanggan **Cash — ditagih ke rumah**; riwayat pembayaran cash
tidak otomatis berarti pelanggan memerlukan kunjungan rumah. Pilih bulan awal
yang belum memiliki invoice. Invoice lama tetap tersimpan; generator melewati
periode yang bertumpang tindih dengan invoice non-void, termasuk yang lunas.
Form pembayaran Lunas menyediakan metode Cash dan Transfer.

Jalankan migrasi `20261022120000_customer_billing_cycles_offline_collection.php`
melalui mekanisme migrasi aplikasi sebelum memakai fitur. Migrasi bersifat
idempotent: menambah tiga kolom pelanggan, tipe grup Telegram `collection`,
dan tabel `offline_collection_deliveries`, tanpa mengubah invoice lama.

Di **Settings → Telegram**, tambahkan grup dengan router Kalisari, bot Kalisari
yang aktif, tipe **Penagihan Rumah (Cash)**, dan Chat ID `-5576001646`.
Bot harus sudah menjadi anggota grup dan dapat mengirim pesan.

Cron berikut dapat berjalan pada host dengan zona waktu apa pun. Service hanya
mengirim pada tanggal 10 mulai 08.00 WIB; jadwal per jam juga mencoba ulang
bagian yang gagal pada hari yang sama:

```cron
0 * * * * www-data /usr/bin/php /var/www/rtrwnet/index.php cron/Offline_collection_cron run
```

Laporan hanya menuju grup `collection` aktif pada router yang sama, tanpa
fallback ke grup admin/owner/router lain. Isinya nama, HP, alamat, periode, dan
sisa seluruh tagihan issued/overdue/partially_paid yang periodenya sudah dimulai,
termasuk tagihan berjalan yang jatuh temponya setelah tanggal 10. Pelanggan
transfer/belum ditentukan, terminated, tagihan lunas/void/draft, dan periode yang
belum dimulai dikecualikan. Generator invoice bulanan harus berjalan sebelum
tanggal 10 supaya tagihan bulan berjalan masuk laporan.

Pesan panjang dibagi per pelanggan. Snapshot dan status tiap bagian disimpan
per bulan/router/grup. Cron bersamaan dikunci; bagian yang sudah berhasil
dikirim dilewati saat retry. Seperti pengiriman HTTP biasa, bila proses terhenti
setelah Telegram menerima pesan tetapi sebelum status tersimpan, bagian itu
dapat terkirim lagi. Snapshot retry mempertahankan data saat laporan pertama
dibentuk. Perubahan Chat ID setelah snapshot terbentuk tidak mengirim ulang
snapshot ke tujuan baru.

Preview hanya menampilkan jumlah pelanggan/tujuan/bagian, tanpa mengirim pesan
atau menyimpan snapshot:

```sh
php index.php cron/Offline_collection_cron preview 2026-11-10
php scripts/tests/billing_collection_test.php
```
