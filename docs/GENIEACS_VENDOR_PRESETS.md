# GenieACS Vendor Presets (Nawacore)

Preset dan provision vendor untuk pengambilan parameter ONT:

- ZTE
- FiberHome
- VSOL
- Zimlink

## File Script

- `scripts/genieacs/apply_vendor_presets.sh`
- `scripts/genieacs/force_refresh_params.sh`

## Cara Pakai

```bash
./scripts/genieacs/apply_vendor_presets.sh http://127.0.0.1:7557
./scripts/genieacs/force_refresh_params.sh http://127.0.0.1:7557 120
php index.php ont sync
```

## Preset yang Dibuat

- `superapps_preset_common`
- `superapps_preset_zte`
- `superapps_preset_fiberhome`
- `superapps_preset_vsol`
- `superapps_preset_zimlink`

## Provision yang Dibuat

- `superapps_collect_common`
- `superapps_collect_zte`
- `superapps_collect_fiberhome`
- `superapps_collect_vsol`
- `superapps_collect_zimlink`

## Catatan Teknis

1. Jika kolom `SSID`, `WiFi password`, atau `Redaman` masih kosong, berarti ONT tidak mengirim parameter tersebut via TR-069 pada profile saat ini.
2. `force_refresh_params.sh` sudah mengirim task `getParameterValues`; jika tetap kosong, biasanya parameter:
   - tidak diexpose firmware,
   - butuh credential level operator/ISP,
   - atau path vendor berbeda dari profile ONT saat ini.
3. Data yang sudah pasti umum terbaca: serial, manufacturer, product class, WAN IP, dan sebagian username/password PPP (tergantung vendor).

