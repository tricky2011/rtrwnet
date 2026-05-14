# GenieACS Virtual Parameter Extract (alijayanet)

Script otomatis untuk:
- clone repo `https://github.com/alijayanet/genieacs`
- extract `db/virtualParameters.bson`
- generate file:
  - `virtualParameters.ndjson`
  - `virtualParameters.array.json`
  - `manifest.json`
  - file `.js` per virtual parameter

## Jalankan

```bash
cd /var/www/rtrwnet
./scripts/genieacs/download_and_extract_virtual_parameters.sh
```

Output default:

`docs/genieacs_virtual_parameters/alijayanet/`

## Opsional: langsung import ke GenieACS

```bash
./scripts/genieacs/download_and_extract_virtual_parameters.sh \
  --nbi http://127.0.0.1:7557
```

## Opsi tambahan

```bash
./scripts/genieacs/download_and_extract_virtual_parameters.sh --help
```

## Sinkronisasi berbasis config project

Setelah file `alijayanet` tersedia di `docs/genieacs_virtual_parameters/alijayanet`, jalankan:

```bash
cd /var/www/rtrwnet
./scripts/genieacs/sync_virtual_parameters.sh
```

Script ini membaca konfigurasi dari:

- `application/config/genieacs.php`
  - `genieacs_vparam_sync_enabled`
  - `genieacs_vparam_source`
  - `genieacs_vparam_base_dir`
  - `genieacs_vparam_ndjson_path`
  - `genieacs_vparam_manifest_path`
  - `genieacs_vparam_mongo_db`

Mode validasi tanpa write:

```bash
./scripts/genieacs/sync_virtual_parameters.sh --dry-run
```
