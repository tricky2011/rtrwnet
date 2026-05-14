#!/usr/bin/env bash
set -euo pipefail

REPO_URL="https://github.com/alijayanet/genieacs.git"
NBI_URL="${GENIEACS_NBI_URL:-}"
OUT_DIR="docs/genieacs_virtual_parameters/alijayanet"
KEEP_TEMP=0

usage() {
  cat <<'EOF'
Usage:
  ./scripts/genieacs/download_and_extract_virtual_parameters.sh [options]

Options:
  --repo <url>       Git repo source (default: https://github.com/alijayanet/genieacs.git)
  --out <path>       Output directory (default: docs/genieacs_virtual_parameters/alijayanet)
  --nbi <url>        Optional GenieACS NBI URL, ex: http://127.0.0.1:7557
  --keep-temp        Do not delete temporary clone folder
  -h, --help         Show help

Examples:
  ./scripts/genieacs/download_and_extract_virtual_parameters.sh
  ./scripts/genieacs/download_and_extract_virtual_parameters.sh --nbi http://127.0.0.1:7557
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --repo)
      REPO_URL="${2:-}"
      shift 2
      ;;
    --out)
      OUT_DIR="${2:-}"
      shift 2
      ;;
    --nbi)
      NBI_URL="${2:-}"
      shift 2
      ;;
    --keep-temp)
      KEEP_TEMP=1
      shift
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

for bin in git bsondump jq python3 curl; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "Dependency not found: $bin" >&2
    exit 1
  fi
done

TMP_DIR="$(mktemp -d /tmp/genieacs-vparam-XXXXXX)"
if [[ "$KEEP_TEMP" -eq 0 ]]; then
  trap 'rm -rf "$TMP_DIR"' EXIT
fi

echo "[1/5] Cloning repo: $REPO_URL"
git clone --depth 1 "$REPO_URL" "$TMP_DIR/repo" >/dev/null 2>&1

echo "[2/5] Locating virtualParameters.bson"
BSON_PATH="$(find "$TMP_DIR/repo" -type f -name 'virtualParameters.bson' | head -n1 || true)"
if [[ -z "$BSON_PATH" ]]; then
  echo "virtualParameters.bson not found in repository." >&2
  exit 1
fi

mkdir -p "$OUT_DIR/json" "$OUT_DIR/scripts"
NDJSON_PATH="$OUT_DIR/json/virtualParameters.ndjson"
JSON_ARRAY_PATH="$OUT_DIR/json/virtualParameters.array.json"
MANIFEST_PATH="$OUT_DIR/json/manifest.json"

echo "[3/5] Extracting BSON -> NDJSON"
bsondump --quiet "$BSON_PATH" > "$NDJSON_PATH"

if [[ ! -s "$NDJSON_PATH" ]]; then
  echo "Extraction produced empty file: $NDJSON_PATH" >&2
  exit 1
fi

echo "[4/5] Building JSON + JS files"
jq -s '.' "$NDJSON_PATH" > "$JSON_ARRAY_PATH"

count_total=0
while IFS= read -r row; do
  id="$(jq -r '._id // empty' <<<"$row")"
  script_body="$(jq -r '.script // ""' <<<"$row")"
  [[ -z "$id" ]] && continue
  safe_id="$(sed 's/[^A-Za-z0-9._-]/_/g' <<<"$id")"
  printf '%s\n' "$script_body" > "$OUT_DIR/scripts/${safe_id}.js"
  count_total=$((count_total + 1))
done < "$NDJSON_PATH"

jq -r '[.[] | {id: ._id, script_file: ("scripts/" + ((._id|tostring) | gsub("[^A-Za-z0-9._-]"; "_")) + ".js")}]' \
  "$JSON_ARRAY_PATH" > "$MANIFEST_PATH"

echo "Extracted: $count_total virtual parameters"
echo "Output dir: $OUT_DIR"

if [[ -n "$NBI_URL" ]]; then
  echo "[5/5] Importing to GenieACS NBI: $NBI_URL"
  ok=0
  fail=0
  while IFS= read -r row; do
    id="$(jq -r '._id // empty' <<<"$row")"
    script_body="$(jq -r '.script // ""' <<<"$row")"
    [[ -z "$id" ]] && continue
    safe_id_tmp="$(sed 's/[^A-Za-z0-9._-]/_/g' <<<"$id")"
    script_tmp="/tmp/genieacs_vparam_${safe_id_tmp}.js"
    printf '%s\n' "$script_body" > "$script_tmp"
    id_enc="$(python3 - <<'PY' "$id"
import sys, urllib.parse
print(urllib.parse.quote(sys.argv[1], safe=''))
PY
)"
    code="$(curl -sS -o /tmp/genieacs_vparam_import.out -w '%{http_code}' \
      -X PUT "${NBI_URL%/}/virtual_parameters/${id_enc}" \
      -H 'Content-Type: text/plain' \
      --data-binary @"$script_tmp" || true)"
    if [[ "$code" == "200" || "$code" == "201" ]]; then
      ok=$((ok + 1))
    else
      fail=$((fail + 1))
      echo "Failed import: ${id} (HTTP ${code})"
    fi
  done < "$NDJSON_PATH"
  echo "Import summary -> success: $ok, failed: $fail"
fi

if [[ "$KEEP_TEMP" -eq 1 ]]; then
  echo "Temp directory kept: $TMP_DIR"
fi

echo "Done."
