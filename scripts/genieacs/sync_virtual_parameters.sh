#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$PROJECT_ROOT"

DRY_RUN=0
BACKUP=1
OVERRIDE_DB=""
OVERRIDE_NDJSON=""

usage() {
  cat <<'EOF'
Usage:
  ./scripts/genieacs/sync_virtual_parameters.sh [options]

Options:
  --dry-run             Tampilkan rencana sync tanpa menulis ke database.
  --no-backup           Skip backup collection virtualParameters.
  --db <name>           Override nama database MongoDB (default dari config).
  --ndjson <path>       Override path file NDJSON virtual parameter.
  -h, --help            Tampilkan bantuan.

Catatan:
  Script membaca konfigurasi dari application/config/genieacs.php:
  - genieacs_vparam_sync_enabled
  - genieacs_vparam_source
  - genieacs_vparam_base_dir
  - genieacs_vparam_ndjson_path
  - genieacs_vparam_manifest_path
  - genieacs_vparam_mongo_db
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    --no-backup)
      BACKUP=0
      shift
      ;;
    --db)
      OVERRIDE_DB="${2:-}"
      shift 2
      ;;
    --ndjson)
      OVERRIDE_NDJSON="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage
      exit 1
      ;;
  esac
done

for bin in php jq mongo mongoimport mongoexport; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "Dependency not found: $bin" >&2
    exit 1
  fi
done

resolve_path() {
  local path="$1"
  if [[ "$path" = /* ]]; then
    printf '%s\n' "$path"
  else
    printf '%s/%s\n' "$PROJECT_ROOT" "$path"
  fi
}

CONFIG_JSON="$(
  php -r '
    define("BASEPATH", getcwd() . "/system/");
    $config = array();
    include "application/config/genieacs.php";
    echo json_encode(array(
      "sync_enabled" => (bool) ($config["genieacs_vparam_sync_enabled"] ?? true),
      "source" => (string) ($config["genieacs_vparam_source"] ?? "alijayanet"),
      "base_dir" => (string) ($config["genieacs_vparam_base_dir"] ?? "docs/genieacs_virtual_parameters"),
      "ndjson_path" => (string) ($config["genieacs_vparam_ndjson_path"] ?? ""),
      "manifest_path" => (string) ($config["genieacs_vparam_manifest_path"] ?? ""),
      "mongo_db" => (string) ($config["genieacs_vparam_mongo_db"] ?? "genieacs")
    ), JSON_UNESCAPED_SLASHES);
  '
)"

if [[ -z "$CONFIG_JSON" || "$CONFIG_JSON" == "null" ]]; then
  echo "Failed membaca application/config/genieacs.php" >&2
  exit 1
fi

SYNC_ENABLED="$(jq -r '.sync_enabled' <<<"$CONFIG_JSON")"
if [[ "$SYNC_ENABLED" != "true" ]]; then
  echo "Sync virtual parameter dinonaktifkan di config (genieacs_vparam_sync_enabled=false)."
  exit 0
fi

SOURCE="$(jq -r '.source // "alijayanet"' <<<"$CONFIG_JSON")"
BASE_DIR="$(jq -r '.base_dir // "docs/genieacs_virtual_parameters"' <<<"$CONFIG_JSON")"
NDJSON_PATH_CFG="$(jq -r '.ndjson_path // ""' <<<"$CONFIG_JSON")"
MANIFEST_PATH_CFG="$(jq -r '.manifest_path // ""' <<<"$CONFIG_JSON")"
MONGO_DB_CFG="$(jq -r '.mongo_db // "genieacs"' <<<"$CONFIG_JSON")"

SOURCE_DIR="${BASE_DIR%/}/${SOURCE}"
if [[ -n "$OVERRIDE_NDJSON" ]]; then
  NDJSON_PATH="$OVERRIDE_NDJSON"
elif [[ -n "$NDJSON_PATH_CFG" ]]; then
  NDJSON_PATH="$NDJSON_PATH_CFG"
else
  NDJSON_PATH="${SOURCE_DIR}/json/virtualParameters.ndjson"
fi

if [[ -n "$MANIFEST_PATH_CFG" ]]; then
  MANIFEST_PATH="$MANIFEST_PATH_CFG"
else
  MANIFEST_PATH="${SOURCE_DIR}/json/manifest.json"
fi

if [[ -n "$OVERRIDE_DB" ]]; then
  MONGO_DB="$OVERRIDE_DB"
else
  MONGO_DB="$MONGO_DB_CFG"
fi

NDJSON_ABS="$(resolve_path "$NDJSON_PATH")"
MANIFEST_ABS="$(resolve_path "$MANIFEST_PATH")"

if [[ ! -f "$NDJSON_ABS" ]]; then
  echo "File NDJSON tidak ditemukan: $NDJSON_ABS" >&2
  exit 1
fi

SOURCE_COUNT="$(wc -l < "$NDJSON_ABS" | tr -d ' ')"
BEFORE_COUNT="$(mongo --quiet "$MONGO_DB" --eval 'db.virtualParameters.count()' | tr -d '\r\n')"

echo "Config source      : $SOURCE"
echo "NDJSON path        : $NDJSON_ABS"
echo "Mongo DB           : $MONGO_DB"
echo "Rows source        : $SOURCE_COUNT"
echo "Rows before import : ${BEFORE_COUNT:-0}"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "Dry-run mode: tidak ada perubahan database."
  exit 0
fi

if [[ "$BACKUP" -eq 1 ]]; then
  TS="$(date +%Y%m%d_%H%M%S)"
  BACKUP_PATH="/tmp/virtualParameters_backup_${TS}.json"
  mongoexport --quiet -d "$MONGO_DB" -c virtualParameters --out "$BACKUP_PATH"
  echo "Backup created     : $BACKUP_PATH"
fi

mongoimport --quiet -d "$MONGO_DB" -c virtualParameters --mode upsert --upsertFields _id --file "$NDJSON_ABS"

AFTER_COUNT="$(mongo --quiet "$MONGO_DB" --eval 'db.virtualParameters.count()' | tr -d '\r\n')"
echo "Rows after import  : ${AFTER_COUNT:-0}"

if [[ -f "$MANIFEST_ABS" ]]; then
  MANIFEST_COUNT="$(jq 'length' "$MANIFEST_ABS" 2>/dev/null || echo "0")"
  echo "Manifest count     : $MANIFEST_COUNT ($MANIFEST_ABS)"
fi

echo "Sync virtualParameters selesai."
