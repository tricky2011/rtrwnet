# RTRWNet Mobile App

Wrapper Android Capacitor untuk CI3 `RTRWNet` yang tetap memuat aplikasi web dari:

- `https://example.com/rtrwnet`

Project ini memakai `server.url`, jadi Android WebView akan membuka URL CI3 langsung tanpa build frontend terpisah.

## Catatan penting

- Capacitor yang dipakai di project ini adalah `7.6.0`.
- Plugin kontak yang dipakai adalah `@capacitor-community/contacts@7.1.0`.
- Kombinasi ini dipilih karena `@capacitor-community/contacts` versi `7.x` ditujukan untuk Capacitor `7.x`.
- Capacitor CLI `7.x` membutuhkan Node.js `>=20`. File `.nvmrc` sudah diset ke `20`.

## Setup dari nol

Di folder root CI3:

```bash
mkdir mobile-app
cd mobile-app
npm init -y
npm install @capacitor/core@7.6.0 @capacitor/cli@7.6.0
npx cap init RTRWNetMobile com.example.rtrwnet.mobile --web-dir=www
npm install @capacitor/android@7.6.0
npx cap add android
npm install @capacitor-community/contacts@7.1.0
npx cap sync
```

Kalau environment Anda masih Node 18, gunakan Node 20 dulu:

```bash
nvm use
```

Atau pakai helper script yang sudah ada:

```bash
npm run android:add
npm run android:sync
npm run android:open
```

## Konfigurasi Capacitor

File utama:

- `mobile-app/capacitor.config.json`

Konfigurasi aktif saat ini:

```json
{
  "appId": "com.example.rtrwnet.mobile",
  "appName": "RTRWNet Mobile",
  "webDir": "www",
  "server": {
    "url": "https://example.com/rtrwnet",
    "androidScheme": "https",
    "allowNavigation": [
      "example.com"
    ]
  }
}
```

Jika nanti server diganti ke `http://...`, tambahkan `cleartext: true`:

```json
{
  "server": {
    "url": "http://example.com/rtrwnet",
    "androidScheme": "http",
    "cleartext": true,
    "allowNavigation": [
      "example.com"
    ]
  }
}
```

Sesudah mengubah config, jalankan:

```bash
npx cap sync android
```

## Permission Android

Lokasi file:

- `mobile-app/android/app/src/main/AndroidManifest.xml`

Permission yang sudah ditambahkan:

```xml
<uses-permission android:name="android.permission.READ_CONTACTS" />
<uses-permission android:name="android.permission.WRITE_CONTACTS" />
```

Untuk `pickContact()`, kebutuhan minimal umumnya `READ_CONTACTS`. Namun plugin ini mendeklarasikan alias permission kontak yang mencakup `READ_CONTACTS` dan `WRITE_CONTACTS`, jadi keduanya disimpan di manifest agar sinkron dengan plugin.

## Contoh JavaScript plain JS

Contoh sederhana untuk runtime Android Capacitor:

```html
<button type="button" id="pick-contact-btn">Ambil Kontak</button>
<div id="picked-phone"></div>

<script>
  (function () {
    if (!window.Capacitor || typeof window.Capacitor.registerPlugin !== 'function') {
      console.warn('Capacitor bridge belum tersedia.');
      return;
    }

    const Contacts = window.Capacitor.registerPlugin('Contacts');
    const button = document.getElementById('pick-contact-btn');
    const result = document.getElementById('picked-phone');

    async function pickPhoneContact() {
      try {
        const platform = window.Capacitor.getPlatform();
        console.log('Platform:', platform);

        if (platform !== 'android') {
          result.textContent = 'Fitur ini harus dibuka dari app Android Capacitor.';
          return;
        }

        const permission = await Contacts.requestPermissions();
        if (!permission || permission.contacts !== 'granted') {
          result.textContent = 'Izin kontak ditolak.';
          return;
        }

        const picked = await Contacts.pickContact({
          projection: {
            name: true,
            phones: true
          }
        });

        const phones = picked && picked.contact && Array.isArray(picked.contact.phones)
          ? picked.contact.phones
          : [];

        const firstPhone = phones.find(function (item) {
          return item && item.number;
        });

        result.textContent = firstPhone
          ? 'Nomor HP: ' + firstPhone.number
          : 'Kontak tidak punya nomor HP.';
      } catch (error) {
        console.error('Gagal pick contact:', error);
        result.textContent = 'Gagal mengambil kontak.';
      }
    }

    if (button) {
      button.addEventListener('click', pickPhoneContact);
    }
  })();
</script>
```

Di project CI3 ini, integrasi kontak yang lebih lengkap sudah ada di:

- `assets/js/app-ui.js`

## Menjalankan Android

Masuk ke folder:

```bash
cd /var/www/rtrwnet/mobile-app
```

Sinkronisasi dulu kalau ada perubahan:

```bash
npx cap sync android
```

Buka project Android Studio:

```bash
npx cap open android
```

Lalu:

- Pilih emulator Android yang aktif, atau
- Sambungkan HP Android via USB dan aktifkan USB debugging
- Tekan `Run` di Android Studio

## Checklist validasi

- `window.Capacitor.getPlatform()` mengembalikan `"android"`
- `window.Capacitor.isNativePlatform()` mengembalikan `true`
- `window.Capacitor.registerPlugin('Contacts')` tidak melempar error
- Dialog permission kontak muncul saat pertama kali akses
- `pickContact()` membuka contact picker native Android
- Nomor HP dari kontak terpilih tampil di UI
- Jika config diubah, `npx cap sync android` selalu dijalankan lagi

## Struktur file penting

- `mobile-app/package.json`
- `mobile-app/capacitor.config.json`
- `mobile-app/android/app/src/main/AndroidManifest.xml`
- `mobile-app/www/index.html`
- `assets/js/app-ui.js`
