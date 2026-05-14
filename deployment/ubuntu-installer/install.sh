#!/usr/bin/env bash
set -euo pipefail

umask 027

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
CONFIG_EXAMPLE_FILE="${SCRIPT_DIR}/config.env.example"
BACKUP_SUFFIX="$(date +%Y%m%d_%H%M%S)"

APT_UPDATED=0
MYSQL_SERVICE="mysql"
OS_ID=""
OS_VERSION_ID=""
OS_PRETTY_NAME=""
DEFAULT_PHP_VERSION=""
PHP_FPM_SERVICE=""
PHP_FPM_POOL_FILE=""
PHP_FPM_UPSTREAM=""
NGINX_RUNTIME_USER=""
DB_IS_LOCAL="0"
REDIS_IS_LOCAL="0"
MIGRATION_COMMAND="auto"
MIGRATION_COMMAND_SOURCE=""
CONFIG_FILE=""
CONFIG_DIR=""
SOURCE_ROOT="${PROJECT_ROOT}"
SOURCE_ROOT_CANONICAL="${PROJECT_ROOT}"
INSTALLER_SOURCE_DIR="${SCRIPT_DIR}"
ENV_TEMPLATE_FILE="${PROJECT_ROOT}/.env.example"
NGINX_TEMPLATE_FILE="${SCRIPT_DIR}/nginx/rtrwnet.conf.template"
WORKER_TEMPLATE_FILE="${SCRIPT_DIR}/examples/rtrwnet-worker.service.example"
CRON_TEMPLATE_FILE="${SCRIPT_DIR}/examples/rtrwnet-crontab.example"
PACKAGE_ALLOWLIST_FILE="${SCRIPT_DIR}/package.allowlist"
PACKAGE_DENYLIST_FILE="${SCRIPT_DIR}/package.denylist"
STAGING_DIR=""

SUMMARY_ITEMS=()
VERIFICATION_ITEMS=()
MIGRATION_COMMAND_ARGS=()

log() {
    printf '[INFO] %s\n' "$*"
}

warn() {
    printf '[WARN] %s\n' "$*" >&2
}

die() {
    printf '[ERROR] %s\n' "$*" >&2
    exit 1
}

add_summary() {
    SUMMARY_ITEMS+=("$*")
}

add_verification() {
    VERIFICATION_ITEMS+=("$*")
}

cleanup() {
    if [ -n "${STAGING_DIR:-}" ] && [ -d "$STAGING_DIR" ]; then
        rm -rf "$STAGING_DIR"
    fi
}

trap cleanup EXIT

backup_path() {
    local target="$1"

    if [ -e "$target" ] || [ -L "$target" ]; then
        local backup="${target}.bak.${BACKUP_SUFFIX}"
        cp -a "$target" "$backup"
        add_summary "Backup dibuat: ${backup}"
    fi
}

require_root() {
    if [ "${EUID}" -ne 0 ]; then
        die "Jalankan installer dengan hak root, misalnya: sudo bash install.sh"
    fi
}

canonical_path() {
    local target="$1"

    if [ -e "$target" ] || [ -L "$target" ]; then
        readlink -f "$target"
        return
    fi

    local dir base
    dir="$(cd "$(dirname "$target")" && pwd)"
    base="$(basename "$target")"
    printf '%s/%s\n' "$dir" "$base"
}

path_is_within() {
    local candidate="$1"
    local root="$2"
    local candidate_path root_path

    candidate_path="$(canonical_path "$candidate")"
    root_path="$(canonical_path "$root")"

    case "$candidate_path" in
        "$root_path"|"$root_path"/*)
            return 0
            ;;
    esac

    return 1
}

format_command_for_log() {
    local rendered=()
    local part

    for part in "$@"; do
        rendered+=("$(printf '%q' "$part")")
    done

    printf '%s' "${rendered[*]}"
}

log_path_context() {
    local label="${1:-installer}"
    local staging_path="${STAGING_DIR:-<belum-dibuat>}"

    log "${label}: SOURCE_ROOT=${SOURCE_ROOT_CANONICAL} | STAGING_DIR=${staging_path} | APP_ROOT=${APP_ROOT}"
}

is_ip_literal() {
    local value="$1"

    if [[ "$value" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
        return 0
    fi

    if [[ "$value" == *:* ]]; then
        return 0
    fi

    return 1
}

is_localhost_name() {
    local value="${1,,}"

    case "$value" in
        localhost|localhost.localdomain)
            return 0
            ;;
    esac

    return 1
}

is_local_service_host() {
    local value="${1,,}"

    case "$value" in
        127.0.0.1|::1|localhost|localhost.localdomain)
            return 0
            ;;
    esac

    return 1
}

should_use_nginx_default_server() {
    case "${NGINX_ENABLE_DEFAULT_SERVER:-auto}" in
        1)
            return 0
            ;;
        0)
            return 1
            ;;
        auto)
            if is_ip_literal "$APP_DOMAIN" || is_localhost_name "$APP_DOMAIN"; then
                return 0
            fi
            return 1
            ;;
    esac

    return 1
}

assert_required_project_layout() {
    local root="$1"
    local label="$2"
    local advice="$3"
    local missing=()

    if [ ! -f "${root}/index.php" ]; then
        missing+=("${root}/index.php")
    fi

    if [ ! -d "${root}/application" ]; then
        missing+=("${root}/application")
    fi

    if [ ! -d "${root}/system" ]; then
        missing+=("${root}/system")
    fi

    if [ "${#missing[@]}" -gt 0 ]; then
        die "${label} tidak lengkap. Path wajib yang hilang: ${missing[*]}. Ini mengindikasikan masalah pada ${advice}."
    fi
}

detect_supported_os() {
    if [ ! -f /etc/os-release ]; then
        die "File /etc/os-release tidak ditemukan. Installer final ini hanya mendukung Ubuntu 22.04 dan Ubuntu 24.04."
    fi

    # shellcheck disable=SC1091
    . /etc/os-release

    OS_ID="${ID:-}"
    OS_VERSION_ID="${VERSION_ID:-}"
    OS_PRETTY_NAME="${PRETTY_NAME:-unknown}"

    case "${OS_ID}:${OS_VERSION_ID}" in
        ubuntu:22.04)
            DEFAULT_PHP_VERSION="8.1"
            ;;
        ubuntu:24.04)
            DEFAULT_PHP_VERSION="8.3"
            ;;
        *)
            die "Installer final ini hanya mendukung Ubuntu 22.04 dan Ubuntu 24.04. Sistem terdeteksi: ${OS_PRETTY_NAME}"
            ;;
    esac
}

discover_config_file() {
    local candidate

    for candidate in \
        "${RTRWNET_INSTALLER_CONFIG:-}" \
        "/root/rtrwnet-installer.env" \
        "${SCRIPT_DIR}/config.env"
    do
        [ -n "$candidate" ] || continue
        if [ -f "$candidate" ]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done

    return 1
}

load_config() {
    if CONFIG_FILE="$(discover_config_file)"; then
        CONFIG_FILE="$(canonical_path "$CONFIG_FILE")"
        CONFIG_DIR="$(dirname "$CONFIG_FILE")"
        set -a
        # shellcheck disable=SC1090
        . "$CONFIG_FILE"
        set +a
        log "Memuat konfigurasi installer dari ${CONFIG_FILE}"
        return
    fi

    CONFIG_FILE=""
    CONFIG_DIR=""
    warn "File konfigurasi installer tidak ditemukan. Installer akan mencoba membaca nilai dari environment shell."
}

normalize_config() {
    APP_NAME="${APP_NAME:-RTRWNet Production}"
    SOURCE_ROOT="${SOURCE_ROOT:-$PROJECT_ROOT}"
    SOURCE_ROOT="${SOURCE_ROOT%/}"
    [ -n "$SOURCE_ROOT" ] || SOURCE_ROOT="/"
    SOURCE_ROOT_CANONICAL="$(canonical_path "$SOURCE_ROOT")"

    INSTALLER_SOURCE_DIR="${SOURCE_ROOT_CANONICAL}/deployment/ubuntu-installer"
    ENV_TEMPLATE_FILE="${SOURCE_ROOT_CANONICAL}/.env.example"
    NGINX_TEMPLATE_FILE="${INSTALLER_SOURCE_DIR}/nginx/rtrwnet.conf.template"
    WORKER_TEMPLATE_FILE="${INSTALLER_SOURCE_DIR}/examples/rtrwnet-worker.service.example"
    CRON_TEMPLATE_FILE="${INSTALLER_SOURCE_DIR}/examples/rtrwnet-crontab.example"
    PACKAGE_ALLOWLIST_FILE="${INSTALLER_SOURCE_DIR}/package.allowlist"
    PACKAGE_DENYLIST_FILE="${INSTALLER_SOURCE_DIR}/package.denylist"

    APP_DOMAIN="${APP_DOMAIN:-}"
    APP_SERVER_ALIASES="${APP_SERVER_ALIASES:-}"
    APP_ROOT="${APP_ROOT:-}"
    APP_ROOT="${APP_ROOT%/}"
    if [ -z "$APP_ROOT" ]; then
        APP_ROOT="/var/www/rtrwnet"
    fi

    NGINX_CONF_NAME="${NGINX_CONF_NAME:-rtrwnet.conf}"
    if [[ "$NGINX_CONF_NAME" != *.conf ]]; then
        NGINX_CONF_NAME="${NGINX_CONF_NAME}.conf"
    fi
    NGINX_ENABLE_DEFAULT_SERVER="${NGINX_ENABLE_DEFAULT_SERVER:-auto}"

    CI_ENV="${CI_ENV:-production}"
    PHP_VERSION="${PHP_VERSION:-$DEFAULT_PHP_VERSION}"
    PHP_FPM_SOCK="${PHP_FPM_SOCK:-}"
    PHP_BIN="${PHP_BIN:-}"
    PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"

    WEB_USER="${WEB_USER:-www-data}"
    WEB_GROUP="${WEB_GROUP:-www-data}"

    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT="${DB_PORT:-3306}"
    DB_NAME="${DB_NAME:-}"
    DB_USER="${DB_USER:-}"
    DB_PASS="${DB_PASS:-}"
    DB_ROOT_PASS="${DB_ROOT_PASS:-}"
    SQL_FILE="${SQL_FILE:-}"
    ENABLE_DB_MIGRATIONS="${ENABLE_DB_MIGRATIONS:-1}"
    MIGRATION_COMMAND="${MIGRATION_COMMAND:-auto}"

    CI_ENCRYPTION_KEY="${CI_ENCRYPTION_KEY:-}"
    PROVISIONING_CALLBACK_SECRET="${PROVISIONING_CALLBACK_SECRET:-}"
    TELEGRAM_WEBHOOK_SECRET="${TELEGRAM_WEBHOOK_SECRET:-}"
    CRON_TOKEN="${CRON_TOKEN:-}"
    MONITORING_CRON_TOKEN="${MONITORING_CRON_TOKEN:-}"
    TELEGRAM_BOT_TOKEN="${TELEGRAM_BOT_TOKEN:-}"

    ENABLE_REDIS="${ENABLE_REDIS:-0}"
    REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
    REDIS_PORT="${REDIS_PORT:-6379}"
    REDIS_DB="${REDIS_DB:-0}"
    REDIS_PASSWORD="${REDIS_PASSWORD:-}"
    REDIS_PREFIX="${REDIS_PREFIX:-rtrwnet:}"

    ENABLE_WORKER="${ENABLE_WORKER:-1}"
    WORKER_SERVICE_NAME="${WORKER_SERVICE_NAME:-rtrwnet-worker.service}"
    if [[ "$WORKER_SERVICE_NAME" != *.service ]]; then
        WORKER_SERVICE_NAME="${WORKER_SERVICE_NAME}.service"
    fi

    ENABLE_CRON="${ENABLE_CRON:-1}"
    CRON_FILE_NAME="${CRON_FILE_NAME:-rtrwnet}"

    ENABLE_PHPMYADMIN="${ENABLE_PHPMYADMIN:-0}"
    PHPMYADMIN_URL="${PHPMYADMIN_URL:-/phpmyadmin}"
    PHPMYADMIN_URL="/${PHPMYADMIN_URL#/}"
    PHPMYADMIN_URL="${PHPMYADMIN_URL%/}"
    if [ -z "$PHPMYADMIN_URL" ]; then
        PHPMYADMIN_URL="/phpmyadmin"
    fi

    APP_SERVER_NAMES="${APP_DOMAIN}"
    if [ -n "$APP_SERVER_ALIASES" ]; then
        APP_SERVER_NAMES="${APP_SERVER_NAMES} ${APP_SERVER_ALIASES}"
    fi

    if should_use_nginx_default_server; then
        NGINX_LISTEN_SUFFIX=" default_server"
        NGINX_EXPECT_BY_IP_ROUTING="1"
        APP_SERVER_NAMES="${APP_SERVER_NAMES} _"
    else
        NGINX_LISTEN_SUFFIX=""
        NGINX_EXPECT_BY_IP_ROUTING="0"
    fi

    if [ "${ENABLE_REDIS}" = "1" ]; then
        QUEUE_DRIVER="redis"
    else
        QUEUE_DRIVER="database"
    fi

    if [ "${ENABLE_WORKER}" = "1" ]; then
        QUEUE_ENABLE_ASYNC="1"
    else
        QUEUE_ENABLE_ASYNC="0"
    fi

    NGINX_CONF_TARGET="/etc/nginx/sites-available/${NGINX_CONF_NAME}"
    NGINX_ENABLED_TARGET="/etc/nginx/sites-enabled/${NGINX_CONF_NAME}"
    NGINX_CONF_BASENAME="${NGINX_CONF_NAME%.conf}"
    WORKER_SERVICE_TARGET="/etc/systemd/system/${WORKER_SERVICE_NAME}"
    CRON_TARGET="/etc/cron.d/${CRON_FILE_NAME}"
    DB_IS_LOCAL="0"
    if is_local_service_host "$DB_HOST"; then
        DB_IS_LOCAL="1"
    fi

    REDIS_IS_LOCAL="0"
    if is_local_service_host "$REDIS_HOST"; then
        REDIS_IS_LOCAL="1"
    fi
}

is_placeholder_value() {
    local value="${1:-}"
    local normalized

    normalized="$(printf '%s' "$value" | tr '[:upper:]' '[:lower:]')"

    case "$normalized" in
        ""|example.com|rtrwnet.example.com|changeme|change-me|change_me|password|default)
            return 0
            ;;
    esac

    if [[ "$normalized" == *"ganti"* ]] || [[ "$normalized" == *"example.invalid"* ]]; then
        return 0
    fi

    return 1
}

assert_not_placeholder() {
    local var_name="$1"
    local value="${!var_name:-}"

    if is_placeholder_value "$value"; then
        die "Variabel ${var_name} masih memakai placeholder/default. Isi dengan nilai deployment yang real."
    fi
}

assert_boolean_var() {
    local var_name="$1"
    local value="${!var_name:-}"

    case "$value" in
        0|1)
            ;;
        *)
            die "Variabel ${var_name} hanya boleh bernilai 0 atau 1."
            ;;
    esac
}

validate_config() {
    local required_non_empty=(
        APP_DOMAIN
        APP_ROOT
        NGINX_CONF_NAME
        CI_ENV
        PHP_VERSION
        DB_HOST
        DB_PORT
        DB_NAME
        DB_USER
        DB_PASS
        WEB_USER
        WEB_GROUP
    )

    local var_name
    for var_name in "${required_non_empty[@]}"; do
        if [ -z "${!var_name:-}" ]; then
            if [ -n "$CONFIG_FILE" ]; then
                die "Variabel wajib kosong atau belum diisi: ${var_name}. Perbaiki file konfigurasi ${CONFIG_FILE}."
            fi
            die "Variabel wajib kosong atau belum diisi: ${var_name}. Gunakan template ${CONFIG_EXAMPLE_FILE} sebagai baseline konfigurasi."
        fi
    done

    for var_name in ENABLE_DB_MIGRATIONS ENABLE_REDIS ENABLE_WORKER ENABLE_CRON ENABLE_PHPMYADMIN; do
        assert_boolean_var "$var_name"
    done

    if [[ ! "$APP_ROOT" =~ ^/ ]]; then
        die "APP_ROOT harus berupa path absolute."
    fi

    if [ "$APP_ROOT" = "/" ]; then
        die "APP_ROOT tidak boleh diarahkan ke root filesystem."
    fi

    if [[ "$APP_ROOT" =~ [[:space:]] ]]; then
        die "APP_ROOT tidak boleh mengandung spasi."
    fi

    if [[ "$SOURCE_ROOT_CANONICAL" =~ [[:space:]] ]]; then
        die "SOURCE_ROOT tidak boleh mengandung spasi."
    fi

    if [[ "$APP_DOMAIN" =~ [[:space:]] ]]; then
        die "APP_DOMAIN tidak boleh mengandung spasi."
    fi

    local server_alias
    if [ -n "$APP_SERVER_ALIASES" ]; then
        for server_alias in $APP_SERVER_ALIASES; do
            if [[ ! "$server_alias" =~ ^[A-Za-z0-9._*-]+$ ]]; then
                die "APP_SERVER_ALIASES hanya boleh berisi hostname yang aman dipakai di Nginx."
            fi
        done
    fi

    if [[ ! "$NGINX_CONF_NAME" =~ ^[A-Za-z0-9._-]+\.conf$ ]]; then
        die "NGINX_CONF_NAME harus aman untuk nama file, contoh: rtrwnet.conf"
    fi

    case "$NGINX_ENABLE_DEFAULT_SERVER" in
        auto|0|1)
            ;;
        *)
            die "NGINX_ENABLE_DEFAULT_SERVER hanya boleh bernilai auto, 0, atau 1."
            ;;
    esac

    if [[ ! "$CRON_FILE_NAME" =~ ^[A-Za-z0-9._-]+$ ]]; then
        die "CRON_FILE_NAME harus aman untuk nama file."
    fi

    if [[ ! "$WORKER_SERVICE_NAME" =~ ^[A-Za-z0-9._-]+\.service$ ]]; then
        die "WORKER_SERVICE_NAME harus aman untuk nama unit systemd."
    fi

    if [[ ! "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
        die "PHP_VERSION harus memakai format mayor.minor, contoh: 8.1, 8.2, atau 8.3"
    fi

    if [ -n "$PHP_FPM_SOCK" ] && [[ ! "$PHP_FPM_SOCK" =~ ^/ ]]; then
        die "PHP_FPM_SOCK harus berupa path socket absolute bila diisi manual."
    fi

    if [[ ! "$DB_PORT" =~ ^[0-9]+$ ]]; then
        die "DB_PORT harus berupa angka."
    fi

    if [[ ! "$REDIS_PORT" =~ ^[0-9]+$ ]]; then
        die "REDIS_PORT harus berupa angka."
    fi

    if [[ ! "$REDIS_DB" =~ ^[0-9]+$ ]]; then
        die "REDIS_DB harus berupa angka bulat >= 0."
    fi

    if [[ ! "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
        die "DB_NAME hanya boleh berisi huruf, angka, dan underscore."
    fi

    if [[ ! "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]]; then
        die "DB_USER hanya boleh berisi huruf, angka, dan underscore."
    fi

    if [[ ! "$WEB_USER" =~ ^[A-Za-z0-9._-]+$ ]]; then
        die "WEB_USER mengandung karakter yang tidak aman."
    fi

    if [[ ! "$WEB_GROUP" =~ ^[A-Za-z0-9._-]+$ ]]; then
        die "WEB_GROUP mengandung karakter yang tidak aman."
    fi

    if [[ ! "$PHPMYADMIN_URL" =~ ^/[A-Za-z0-9._/-]+$ ]]; then
        die "PHPMYADMIN_URL harus berupa path URL sederhana, misalnya /phpmyadmin"
    fi

    if [ "$MIGRATION_COMMAND" != "auto" ] && [[ ! "$MIGRATION_COMMAND" =~ ^[A-Za-z0-9_./:-]+([[:space:]]+[A-Za-z0-9_./:-]+)*$ ]]; then
        die "MIGRATION_COMMAND hanya boleh berisi route CLI dan argumen sederhana, misalnya: migrate latest"
    fi

    if [ ! -d "$SOURCE_ROOT_CANONICAL" ]; then
        die "SOURCE_ROOT tidak ditemukan: ${SOURCE_ROOT_CANONICAL}"
    fi

    assert_required_project_layout "$SOURCE_ROOT_CANONICAL" "SOURCE_ROOT" "source tree / package.allowlist"

    if [ ! -f "$ENV_TEMPLATE_FILE" ]; then
        die "Template env canonical tidak ditemukan: ${ENV_TEMPLATE_FILE}"
    fi

    if [ ! -f "$NGINX_TEMPLATE_FILE" ]; then
        die "Template Nginx tidak ditemukan: ${NGINX_TEMPLATE_FILE}"
    fi

    if [ ! -f "$WORKER_TEMPLATE_FILE" ]; then
        die "Template worker service tidak ditemukan: ${WORKER_TEMPLATE_FILE}"
    fi

    if [ ! -f "$CRON_TEMPLATE_FILE" ]; then
        die "Template cron tidak ditemukan: ${CRON_TEMPLATE_FILE}"
    fi

    if [ ! -f "$PACKAGE_ALLOWLIST_FILE" ]; then
        die "package.allowlist tidak ditemukan: ${PACKAGE_ALLOWLIST_FILE}"
    fi

    if [ ! -f "$PACKAGE_DENYLIST_FILE" ]; then
        die "package.denylist tidak ditemukan: ${PACKAGE_DENYLIST_FILE}"
    fi

    if [ -n "$CONFIG_FILE" ] && path_is_within "$CONFIG_FILE" "$SOURCE_ROOT_CANONICAL"; then
        die "File konfigurasi installer berada di dalam SOURCE_ROOT (${CONFIG_FILE}). Pindahkan ke path di luar source tree, misalnya /root/rtrwnet-installer.env, agar secret tidak ikut build context."
    fi

    if [ "$ENABLE_CRON" = "1" ] && [ "$ENABLE_WORKER" != "1" ]; then
        die "ENABLE_CRON=1 membutuhkan ENABLE_WORKER=1 karena ada cron yang menulis job ke background queue."
    fi

    if [ "$ENABLE_REDIS" = "1" ]; then
        assert_not_placeholder REDIS_HOST
    fi

    assert_not_placeholder APP_DOMAIN
    assert_not_placeholder DB_PASS

    if [ -n "$DB_ROOT_PASS" ]; then
        assert_not_placeholder DB_ROOT_PASS
    fi

    local optional_secret_vars=(
        CI_ENCRYPTION_KEY
        PROVISIONING_CALLBACK_SECRET
        TELEGRAM_WEBHOOK_SECRET
        CRON_TOKEN
        MONITORING_CRON_TOKEN
    )

    local value
    for var_name in "${optional_secret_vars[@]}"; do
        value="${!var_name:-}"
        if [ -n "$value" ] && is_placeholder_value "$value"; then
            die "Variabel ${var_name} masih memakai placeholder. Kosongkan agar digenerate installer, atau isi secret yang benar."
        fi
    done

    if [ "$APP_ROOT" = "$SOURCE_ROOT_CANONICAL" ]; then
        die "APP_ROOT tidak boleh sama dengan SOURCE_ROOT. Fresh install final harus membedakan source tree, staging package, dan target deploy."
    fi

    if path_is_within "$APP_ROOT" "$SOURCE_ROOT_CANONICAL" || path_is_within "$SOURCE_ROOT_CANONICAL" "$APP_ROOT"; then
        die "APP_ROOT dan SOURCE_ROOT tidak boleh saling bersarang. Fresh install final membutuhkan target deploy yang terpisah dan kosong."
    fi

    if [ "$DB_IS_LOCAL" != "1" ] && [ -n "$DB_ROOT_PASS" ]; then
        warn "DB_ROOT_PASS diisi tetapi DB_HOST=${DB_HOST} terdeteksi remote. Nilai ini hanya dipakai saat provisioning MySQL lokal."
    fi

    if [ "$REDIS_IS_LOCAL" != "1" ] && [ "$ENABLE_REDIS" = "1" ]; then
        warn "ENABLE_REDIS=1 dengan REDIS_HOST=${REDIS_HOST} akan memakai Redis remote. Installer hanya akan memverifikasi konektivitas, bukan memasang redis-server lokal."
    fi
}

package_installed() {
    dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q "install ok installed"
}

apt_update_once() {
    if [ "$APT_UPDATED" -eq 0 ]; then
        log "Menjalankan apt-get update..."
        if ! apt-get update -o Acquire::Retries=3; then
            die "Apt repository atau network outbound belum siap. Periksa DNS/network ke mirror Ubuntu lalu jalankan installer lagi."
        fi
        APT_UPDATED=1
    fi
}

preflight_apt_readiness() {
    if ! command -v apt-get >/dev/null 2>&1; then
        die "apt-get tidak ditemukan. Installer final ini hanya mendukung Ubuntu 22.04 dan Ubuntu 24.04."
    fi

    log "Memverifikasi readiness apt/network untuk fresh Ubuntu..."
    apt_update_once
    add_summary "APT repository berhasil diakses."
}

assert_safe_app_root_for_fresh_install() {
    if [ -e "$APP_ROOT" ] && [ ! -d "$APP_ROOT" ]; then
        die "APP_ROOT sudah ada tetapi bukan direktori: ${APP_ROOT}"
    fi

    if [ -d "$APP_ROOT" ] && find "$APP_ROOT" -mindepth 1 -print -quit | grep -q .; then
        die "APP_ROOT target fresh install sudah berisi file: ${APP_ROOT}. Kosongkan target deploy ini terlebih dahulu; SOURCE_ROOT (${SOURCE_ROOT_CANONICAL}) tetap harus diperlakukan sebagai source tree terpisah."
    fi
}

install_packages_if_missing() {
    local missing_packages=()
    local package_name

    for package_name in "$@"; do
        if ! package_installed "$package_name"; then
            missing_packages+=("$package_name")
        fi
    done

    if [ "${#missing_packages[@]}" -eq 0 ]; then
        log "Package sudah tersedia: $*"
        return
    fi

    apt_update_once
    log "Menginstall package: ${missing_packages[*]}"
    DEBIAN_FRONTEND=noninteractive apt-get install -y "${missing_packages[@]}"
}

ensure_base_packages() {
    install_packages_if_missing ca-certificates curl debconf-utils gettext-base rsync unzip
}

ensure_web_account() {
    if ! getent group "$WEB_GROUP" >/dev/null 2>&1; then
        groupadd --system "$WEB_GROUP"
        add_summary "Group web dibuat: ${WEB_GROUP}"
    fi

    if ! id "$WEB_USER" >/dev/null 2>&1; then
        useradd --system --gid "$WEB_GROUP" --home-dir /nonexistent --shell /usr/sbin/nologin "$WEB_USER"
        add_summary "User web dibuat: ${WEB_USER}"
    fi
}

collect_source_runtime_matches() {
    (
        cd "$SOURCE_ROOT_CANONICAL"

        if [ -d application/logs ]; then
            find application/logs -mindepth 1 \
                ! -path 'application/logs/index.html' \
                -print
        fi

        if [ -d application/cache ]; then
            find application/cache -mindepth 1 \
                ! -path 'application/cache/index.html' \
                ! -path 'application/cache/login_attempts' \
                ! -path 'application/cache/login_attempts/index.html' \
                -print
        fi

        local runtime_dir
        for runtime_dir in uploads public/uploads public/files storage writable tmp temp; do
            if [ -d "$runtime_dir" ] && find "$runtime_dir" -mindepth 1 -print -quit | grep -q .; then
                printf '%s\n' "$runtime_dir"
            fi
        done
    ) | sort -u
}

assert_source_context_safe() {
    local matches=()
    local line

    while IFS= read -r line; do
        [ -n "$line" ] || continue
        matches+=("$line")
    done < <(
        cd "$SOURCE_ROOT_CANONICAL" && find . -maxdepth 1 -type f \
            \( -name '.env' -o \( -name '.env.*' ! -name '.env.example' \) \) \
            -printf '%P\n' | sort
    )

    if [ -f "${INSTALLER_SOURCE_DIR}/config.env" ]; then
        matches+=("deployment/ubuntu-installer/config.env")
    fi

    while IFS= read -r line; do
        [ -n "$line" ] || continue
        matches+=("$line")
    done < <(collect_source_runtime_matches)

    if [ "${#matches[@]}" -gt 0 ]; then
        printf '[ERROR] Build context source tidak aman. Hapus artefak berikut dari SOURCE_ROOT sebelum install:\n' >&2
        printf ' - %s\n' "${matches[@]}" >&2
        die "Installer hard-fail karena live env atau runtime artefact terdeteksi di build context."
    fi
}

prepare_staging_directory() {
    STAGING_DIR="$(mktemp -d /tmp/rtrwnet-staging.XXXXXX)"
    log_path_context "Staging directory dibuat"
    add_summary "Staging package dibuat di ${STAGING_DIR}"
}

copy_allowlisted_paths_to_staging() {
    local rel_path

    log "Menyalin package staging dari allowlist..."

    (
        cd "$SOURCE_ROOT_CANONICAL"

        while IFS= read -r rel_path || [ -n "$rel_path" ]; do
            rel_path="${rel_path%$'\r'}"
            [[ -z "$rel_path" || "$rel_path" =~ ^[[:space:]]*# ]] && continue

            if [ ! -e "$rel_path" ]; then
                die "Path allowlist tidak ditemukan di SOURCE_ROOT: ${rel_path}"
            fi

            rsync -a --relative -- "$rel_path" "${STAGING_DIR}/"
        done < "$PACKAGE_ALLOWLIST_FILE"
    )
}

write_placeholder_html() {
    local target="$1"

    cat > "$target" <<'EOF'
<!DOCTYPE html>
<html>
<head>
    <title>403 Forbidden</title>
</head>
<body>
<p>Directory access is forbidden.</p>
</body>
</html>
EOF
}

sanitize_runtime_directories_in_staging() {
    log "Menyiapkan runtime directory kosong dari staging..."

    mkdir -p \
        "${STAGING_DIR}/application/logs" \
        "${STAGING_DIR}/application/cache/login_attempts"

    find "${STAGING_DIR}/application/logs" -mindepth 1 -delete || true
    find "${STAGING_DIR}/application/cache" -mindepth 1 -delete || true

    mkdir -p "${STAGING_DIR}/application/cache/login_attempts"
    write_placeholder_html "${STAGING_DIR}/application/logs/index.html"
    write_placeholder_html "${STAGING_DIR}/application/cache/index.html"
    write_placeholder_html "${STAGING_DIR}/application/cache/login_attempts/index.html"
}

collect_denylist_matches() {
    local root="$1"

    (
        cd "$root"
        shopt -s dotglob globstar nullglob

        local pattern is_exception match
        declare -A denied=()
        declare -A allowed=()

        while IFS= read -r pattern || [ -n "$pattern" ]; do
            pattern="${pattern%$'\r'}"
            [[ -z "$pattern" || "$pattern" =~ ^[[:space:]]*# ]] && continue

            is_exception=0
            if [[ "$pattern" == !* ]]; then
                is_exception=1
                pattern="${pattern#!}"
            fi

            for match in $pattern; do
                [ -e "$match" ] || continue
                match="${match%/}"
                if [ "$is_exception" -eq 1 ]; then
                    allowed["$match"]=1
                else
                    denied["$match"]=1
                fi
            done
        done < "$PACKAGE_DENYLIST_FILE"

        for match in "${!denied[@]}"; do
            if [ -n "${allowed[$match]+x}" ]; then
                continue
            fi
            printf '%s\n' "$match"
        done | sort -u
    )
}

validate_staging_package() {
    local matches=()
    local line

    while IFS= read -r line; do
        [ -n "$line" ] || continue
        matches+=("$line")
    done < <(collect_denylist_matches "$STAGING_DIR")

    if [ "${#matches[@]}" -gt 0 ]; then
        printf '[ERROR] Staging package masih mengandung path terlarang:\n' >&2
        printf ' - %s\n' "${matches[@]}" >&2
        die "Validasi package.denylist gagal."
    fi

    if [ -f "${STAGING_DIR}/.env" ]; then
        die "Staging package tidak boleh mengandung .env live."
    fi

    assert_required_project_layout "$STAGING_DIR" "Staging package" "package.allowlist / package.denylist / proses staging"
}

ensure_safe_app_root_for_fresh_install() {
    assert_safe_app_root_for_fresh_install
    mkdir -p "$APP_ROOT"
}

deploy_staging_to_app_root() {
    log "Mendeploy staging package ke APP_ROOT..."
    log_path_context "Deploy flow"

    ensure_safe_app_root_for_fresh_install
    rsync -a --delete "${STAGING_DIR}/" "${APP_ROOT}/"
    assert_required_project_layout "$APP_ROOT" "APP_ROOT deploy" "packaging/allowlist/deploy"
    add_summary "Staging package dideploy ke ${APP_ROOT}"
}

ensure_nginx() {
    install_packages_if_missing nginx

    systemctl enable nginx >/dev/null 2>&1 || true
    systemctl start nginx
    detect_nginx_runtime_user
    add_summary "Nginx aktif."
}

detect_nginx_runtime_user() {
    local detected_user=""

    if [ -f /etc/nginx/nginx.conf ]; then
        detected_user="$(awk '
            /^[[:space:]]*user[[:space:]]+/ {
                gsub(/;/, "", $2)
                print $2
                exit
            }
        ' /etc/nginx/nginx.conf)"
    fi

    NGINX_RUNTIME_USER="${detected_user:-www-data}"
}

resolve_php_binary() {
    if [ -z "$PHP_BIN" ]; then
        if [ -x "/usr/bin/php${PHP_VERSION}" ]; then
            PHP_BIN="/usr/bin/php${PHP_VERSION}"
        else
            PHP_BIN="$(command -v php || true)"
        fi
    fi

    if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
        die "PHP_BIN tidak valid. Isi path binary PHP CLI yang benar."
    fi

    local cli_version
    cli_version="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    if [ "$cli_version" != "$PHP_VERSION" ]; then
        die "PHP_BIN (${PHP_BIN}) memakai versi ${cli_version}, tetapi PHP_VERSION dikonfigurasi ${PHP_VERSION}. Samakan keduanya agar worker/cron konsisten."
    fi
}

configure_php_fpm_pool() {
    PHP_FPM_POOL_FILE="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"

    if [ ! -f "$PHP_FPM_POOL_FILE" ]; then
        die "Pool config PHP-FPM tidak ditemukan: ${PHP_FPM_POOL_FILE}"
    fi

    backup_path "$PHP_FPM_POOL_FILE"

    local rendered_pool
    rendered_pool="$(mktemp)"

    awk -v web_user="$WEB_USER" -v web_group="$WEB_GROUP" '
        BEGIN {
            set_user = 0
            set_group = 0
            set_listen_owner = 0
            set_listen_group = 0
            set_listen_mode = 0
        }
        /^[;[:space:]]*user[[:space:]]*=/ {
            print "user = " web_user
            set_user = 1
            next
        }
        /^[;[:space:]]*group[[:space:]]*=/ {
            print "group = " web_group
            set_group = 1
            next
        }
        /^[;[:space:]]*listen\.owner[[:space:]]*=/ {
            print "listen.owner = " web_user
            set_listen_owner = 1
            next
        }
        /^[;[:space:]]*listen\.group[[:space:]]*=/ {
            print "listen.group = " web_group
            set_listen_group = 1
            next
        }
        /^[;[:space:]]*listen\.mode[[:space:]]*=/ {
            print "listen.mode = 0660"
            set_listen_mode = 1
            next
        }
        { print }
        END {
            if (!set_user) {
                print "user = " web_user
            }
            if (!set_group) {
                print "group = " web_group
            }
            if (!set_listen_owner) {
                print "listen.owner = " web_user
            }
            if (!set_listen_group) {
                print "listen.group = " web_group
            }
            if (!set_listen_mode) {
                print "listen.mode = 0660"
            }
        }
    ' "$PHP_FPM_POOL_FILE" > "$rendered_pool"

    mv "$rendered_pool" "$PHP_FPM_POOL_FILE"
}

detect_php_fpm_socket() {
    local listen_value=""
    local candidate=""

    if [ -n "$PHP_FPM_SOCK" ]; then
        candidate="$PHP_FPM_SOCK"
    elif [ -f "$PHP_FPM_POOL_FILE" ]; then
        candidate="$(awk '
            /^[[:space:]]*;/ { next }
            /^[[:space:]]*listen[[:space:]]*=/ {
                sub(/^[[:space:]]*listen[[:space:]]*=[[:space:]]*/, "", $0)
                sub(/[[:space:]]*;.*/, "", $0)
                gsub(/[[:space:]]+$/, "", $0)
                print
                exit
            }
        ' "$PHP_FPM_POOL_FILE")"
    fi

    if [ -z "$candidate" ]; then
        candidate="/run/php/php${PHP_VERSION}-fpm.sock"
    fi

    listen_value="${candidate}"
    if [[ ! "$listen_value" =~ ^/ ]]; then
        die "PHP-FPM listen endpoint terdeteksi '${listen_value}', bukan UNIX socket. Installer final ini mengharapkan socket path pada Ubuntu fresh install."
    fi

    PHP_FPM_SOCK="$listen_value"
    PHP_FPM_UPSTREAM="unix:${PHP_FPM_SOCK}"
}

ensure_php() {
    local php_packages=(
        "php${PHP_VERSION}-fpm"
        "php${PHP_VERSION}-mysql"
        "php${PHP_VERSION}-cli"
        "php${PHP_VERSION}-curl"
        "php${PHP_VERSION}-mbstring"
        "php${PHP_VERSION}-xml"
        "php${PHP_VERSION}-zip"
        "php${PHP_VERSION}-gd"
        "php${PHP_VERSION}-intl"
        "php${PHP_VERSION}-bcmath"
        "php${PHP_VERSION}-opcache"
        "php${PHP_VERSION}-readline"
    )

    if [ "$ENABLE_REDIS" = "1" ]; then
        php_packages+=("php${PHP_VERSION}-redis")
    fi

    install_packages_if_missing "${php_packages[@]}"
    resolve_php_binary

    configure_php_fpm_pool

    systemctl enable "$PHP_FPM_SERVICE" >/dev/null 2>&1 || true
    systemctl restart "$PHP_FPM_SERVICE"
    detect_php_fpm_socket

    if [ ! -S "$PHP_FPM_SOCK" ]; then
        die "Socket PHP-FPM tidak ditemukan: ${PHP_FPM_SOCK}. Periksa pool config ${PHP_FPM_POOL_FILE}, PHP_VERSION, dan PHP_FPM_SOCK."
    fi

    add_summary "PHP-FPM ${PHP_FPM_SERVICE} aktif dengan socket ${PHP_FPM_SOCK}."
}

detect_mysql_service() {
    if systemctl list-unit-files --type=service | grep -q '^mysql\.service'; then
        MYSQL_SERVICE="mysql"
    elif systemctl list-unit-files --type=service | grep -q '^mariadb\.service'; then
        MYSQL_SERVICE="mariadb"
    else
        MYSQL_SERVICE="mysql"
    fi
}

ensure_database_server() {
    if [ "$DB_IS_LOCAL" = "1" ]; then
        install_packages_if_missing default-mysql-server default-mysql-client

        detect_mysql_service
        systemctl enable "$MYSQL_SERVICE" >/dev/null 2>&1 || true
        systemctl start "$MYSQL_SERVICE"

        local wait_count=0
        until \
            mysqladmin --protocol=socket ping >/dev/null 2>&1 || \
            { [ -n "$DB_ROOT_PASS" ] && mysqladmin --protocol=socket -uroot -p"${DB_ROOT_PASS}" ping >/dev/null 2>&1; } || \
            { [ -n "$DB_ROOT_PASS" ] && mysqladmin -h127.0.0.1 -uroot -p"${DB_ROOT_PASS}" ping >/dev/null 2>&1; }
        do
            wait_count=$((wait_count + 1))
            if [ "$wait_count" -ge 30 ]; then
                die "Service database lokal belum siap setelah menunggu 30 detik."
            fi
            sleep 1
        done

        add_summary "MySQL/MariaDB lokal aktif: ${MYSQL_SERVICE}."
        return
    fi

    MYSQL_SERVICE=""
    install_packages_if_missing default-mysql-client
    add_summary "DB_HOST=${DB_HOST}:${DB_PORT} terdeteksi remote. Installer hanya memasang mysql client dan akan memverifikasi koneksi aplikasi."
}

configure_local_redis_server() {
    local redis_conf="/etc/redis/redis.conf"

    if [ ! -f "$redis_conf" ]; then
        die "Config redis-server tidak ditemukan: ${redis_conf}"
    fi

    backup_path "$redis_conf"

    local rendered_conf
    rendered_conf="$(mktemp)"

    awk -v redis_password="$REDIS_PASSWORD" '
        BEGIN {
            set_requirepass = 0
        }
        /^[[:space:]]*#?[[:space:]]*requirepass[[:space:]]+/ {
            if (redis_password != "") {
                print "requirepass " redis_password
            } else {
                print "# requirepass dikosongkan oleh installer RTRWNet"
            }
            set_requirepass = 1
            next
        }
        { print }
        END {
            if (!set_requirepass && redis_password != "") {
                print "requirepass " redis_password
            }
        }
    ' "$redis_conf" > "$rendered_conf"

    mv "$rendered_conf" "$redis_conf"
}

ensure_redis_stack() {
    if [ "$ENABLE_REDIS" != "1" ]; then
        add_summary "Redis dinonaktifkan. Queue akan memakai fallback database."
        return
    fi

    if [ "$REDIS_IS_LOCAL" = "1" ]; then
        install_packages_if_missing redis-server redis-tools "php${PHP_VERSION}-redis"

        configure_local_redis_server
        systemctl enable redis-server >/dev/null 2>&1 || true
        systemctl restart redis-server
        add_summary "Redis lokal aktif dan queue driver akan memakai Redis."
    else
        install_packages_if_missing redis-tools "php${PHP_VERSION}-redis"
        add_summary "REDIS_HOST=${REDIS_HOST}:${REDIS_PORT} terdeteksi remote. Installer akan memverifikasi koneksi Redis tanpa memasang redis-server lokal."
    fi

    verify_redis_connectivity "post-install"
}

build_mysql_app_client_args() {
    MYSQL_APP_CLIENT_ARGS=(
        --protocol=tcp
        "-h${DB_HOST}"
        "-P${DB_PORT}"
        "-u${DB_USER}"
        "-p${DB_PASS}"
    )
}

mysql_app_query() {
    local sql="$1"

    build_mysql_app_client_args
    mysql "${MYSQL_APP_CLIENT_ARGS[@]}" "$DB_NAME" -Nse "$sql"
}

mysql_app_server_query() {
    local sql="$1"

    build_mysql_app_client_args
    mysql "${MYSQL_APP_CLIENT_ARGS[@]}" -Nse "$sql"
}

mysql_app_import_db() {
    local sql_file="$1"

    build_mysql_app_client_args
    mysql "${MYSQL_APP_CLIENT_ARGS[@]}" "$DB_NAME" < "$sql_file"
}

verify_database_app_login() {
    local context_label="${1:-database verification}"
    local output=""

    if ! output="$(mysql_app_query 'SELECT 1;' 2>&1)"; then
        die "DB login gagal (${context_label}) ke ${DB_HOST}:${DB_PORT}/${DB_NAME} sebagai ${DB_USER}. Output asli: ${output}"
    fi

    if [ "${context_label}" = "smoke verification" ]; then
        add_verification "database app login: ok"
        return
    fi

    add_summary "Koneksi database aplikasi tervalidasi (${context_label})."
}

build_redis_cli_args() {
    REDIS_CLI_ARGS=(
        -h "$REDIS_HOST"
        -p "$REDIS_PORT"
        -n "$REDIS_DB"
    )

    if [ -n "$REDIS_PASSWORD" ]; then
        REDIS_CLI_ARGS+=(--no-auth-warning -a "$REDIS_PASSWORD")
    fi
}

verify_redis_connectivity() {
    local context_label="${1:-redis verification}"
    local output=""

    build_redis_cli_args
    if ! output="$(redis-cli "${REDIS_CLI_ARGS[@]}" ping 2>&1)"; then
        die "redis unreachable (${context_label}) ke ${REDIS_HOST}:${REDIS_PORT}/${REDIS_DB}. Output asli: ${output}"
    fi

    if [ "$output" != "PONG" ]; then
        die "redis unreachable (${context_label}) ke ${REDIS_HOST}:${REDIS_PORT}/${REDIS_DB}. Respons yang diterima: ${output}"
    fi

    if [ "${context_label}" = "smoke verification" ]; then
        add_verification "redis ping: PONG"
        return
    fi

    add_summary "Koneksi Redis tervalidasi (${context_label})."
}

mysql_escape() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\'/\\\'}"
    printf '%s' "$value"
}

dotenv_escape() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    printf '%s' "$value"
}

read_project_env_value() {
    local env_file="$1"
    local key="$2"
    local line value

    if [ ! -f "$env_file" ]; then
        return 0
    fi

    line="$(grep -E "^(export[[:space:]]+)?${key}=" "$env_file" | tail -n 1 || true)"
    if [ -z "$line" ]; then
        return 0
    fi

    line="${line#export }"
    value="${line#*=}"
    value="${value%$'\r'}"

    if [[ "$value" == \"*\" && "$value" == *\" ]]; then
        value="${value:1:${#value}-2}"
        value="${value//\\\\/\\}"
        value="${value//\\\"/\"}"
    elif [[ "$value" == \'*\' && "$value" == *\' ]]; then
        value="${value:1:${#value}-2}"
    else
        value="${value%% #*}"
    fi

    printf '%s' "$value"
}

generate_runtime_secret() {
    "$PHP_BIN" -r 'echo bin2hex(random_bytes(32));'
}

resolve_runtime_env_value() {
    local env_file="$1"
    local key="$2"
    local configured_value="${3:-}"
    local fallback_mode="${4:-}"
    local existing_value

    if [ -n "$configured_value" ]; then
        printf '%s' "$configured_value"
        return 0
    fi

    existing_value="$(read_project_env_value "$env_file" "$key")"
    if [ -n "$existing_value" ]; then
        printf '%s' "$existing_value"
        return 0
    fi

    case "$fallback_mode" in
        generate)
            generate_runtime_secret
            ;;
        *)
            printf '%s' "$fallback_mode"
            ;;
    esac
}

mysql_root_exec() {
    local sql="$1"

    if mysql --protocol=socket -uroot -Nse "SELECT 1;" >/dev/null 2>&1; then
        mysql --protocol=socket -uroot -e "$sql"
        return
    fi

    if [ -n "$DB_ROOT_PASS" ] && mysql --protocol=socket -uroot -p"${DB_ROOT_PASS}" -Nse "SELECT 1;" >/dev/null 2>&1; then
        mysql --protocol=socket -uroot -p"${DB_ROOT_PASS}" -e "$sql"
        return
    fi

    if [ -n "$DB_ROOT_PASS" ] && mysql -h127.0.0.1 -uroot -p"${DB_ROOT_PASS}" -Nse "SELECT 1;" >/dev/null 2>&1; then
        mysql -h127.0.0.1 -uroot -p"${DB_ROOT_PASS}" -e "$sql"
        return
    fi

    die "Tidak bisa login sebagai MySQL root. Periksa service database dan nilai DB_ROOT_PASS."
}

mysql_root_query() {
    local sql="$1"

    if mysql --protocol=socket -uroot -Nse "SELECT 1;" >/dev/null 2>&1; then
        mysql --protocol=socket -uroot -Nse "$sql"
        return
    fi

    if [ -n "$DB_ROOT_PASS" ] && mysql --protocol=socket -uroot -p"${DB_ROOT_PASS}" -Nse "SELECT 1;" >/dev/null 2>&1; then
        mysql --protocol=socket -uroot -p"${DB_ROOT_PASS}" -Nse "$sql"
        return
    fi

    if [ -n "$DB_ROOT_PASS" ] && mysql -h127.0.0.1 -uroot -p"${DB_ROOT_PASS}" -Nse "SELECT 1;" >/dev/null 2>&1; then
        mysql -h127.0.0.1 -uroot -p"${DB_ROOT_PASS}" -Nse "$sql"
        return
    fi

    die "Tidak bisa menjalankan query MySQL root."
}

mysql_root_import_db() {
    local database_name="$1"
    local sql_file="$2"

    if mysql --protocol=socket -uroot -Nse "SELECT 1;" >/dev/null 2>&1; then
        mysql --protocol=socket -uroot "$database_name" < "$sql_file"
        return
    fi

    if [ -n "$DB_ROOT_PASS" ] && mysql --protocol=socket -uroot -p"${DB_ROOT_PASS}" -Nse "SELECT 1;" >/dev/null 2>&1; then
        mysql --protocol=socket -uroot -p"${DB_ROOT_PASS}" "$database_name" < "$sql_file"
        return
    fi

    if [ -n "$DB_ROOT_PASS" ] && mysql -h127.0.0.1 -uroot -p"${DB_ROOT_PASS}" -Nse "SELECT 1;" >/dev/null 2>&1; then
        mysql -h127.0.0.1 -uroot -p"${DB_ROOT_PASS}" "$database_name" < "$sql_file"
        return
    fi

    die "Tidak bisa import SQL ke database ${database_name}."
}

secure_mysql_installation() {
    if [ "$DB_IS_LOCAL" != "1" ]; then
        add_summary "Hardening MySQL lokal dilewati karena DB_HOST=${DB_HOST} terdeteksi remote."
        return
    fi

    log "Menjalankan hardening dasar database..."

    mysql_root_exec "DELETE FROM mysql.user WHERE User='';"
    mysql_root_exec "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
    mysql_root_exec "DROP DATABASE IF EXISTS test;"
    mysql_root_exec "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
    mysql_root_exec "FLUSH PRIVILEGES;"

    if [ -n "$DB_ROOT_PASS" ]; then
        local db_root_pass_escaped
        db_root_pass_escaped="$(mysql_escape "$DB_ROOT_PASS")"
        mysql_root_exec "ALTER USER 'root'@'localhost' IDENTIFIED BY '${db_root_pass_escaped}';"
        mysql_root_exec "FLUSH PRIVILEGES;"
        add_summary "Password root database telah diatur/diupdate."
    else
        add_summary "DB_ROOT_PASS kosong. Root database mengikuti metode autentikasi lokal yang sudah ada."
    fi

    add_summary "Hardening dasar database selesai."
}

ensure_database_and_user() {
    if [ "$DB_IS_LOCAL" != "1" ]; then
        add_summary "Provisioning database/user dilewati karena DB_HOST=${DB_HOST} terdeteksi remote."
        return
    fi

    local db_name_escaped db_user_escaped db_pass_escaped
    db_name_escaped="$(mysql_escape "$DB_NAME")"
    db_user_escaped="$(mysql_escape "$DB_USER")"
    db_pass_escaped="$(mysql_escape "$DB_PASS")"

    mysql_root_exec "CREATE DATABASE IF NOT EXISTS \`${db_name_escaped}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql_root_exec "CREATE USER IF NOT EXISTS '${db_user_escaped}'@'localhost' IDENTIFIED BY '${db_pass_escaped}';"
    mysql_root_exec "ALTER USER '${db_user_escaped}'@'localhost' IDENTIFIED BY '${db_pass_escaped}';"
    mysql_root_exec "CREATE USER IF NOT EXISTS '${db_user_escaped}'@'127.0.0.1' IDENTIFIED BY '${db_pass_escaped}';"
    mysql_root_exec "ALTER USER '${db_user_escaped}'@'127.0.0.1' IDENTIFIED BY '${db_pass_escaped}';"
    mysql_root_exec "GRANT ALL PRIVILEGES ON \`${db_name_escaped}\`.* TO '${db_user_escaped}'@'localhost';"
    mysql_root_exec "GRANT ALL PRIVILEGES ON \`${db_name_escaped}\`.* TO '${db_user_escaped}'@'127.0.0.1';"
    mysql_root_exec "FLUSH PRIVILEGES;"

    add_summary "Database ${DB_NAME} dan user ${DB_USER} siap dipakai."
}

resolve_sql_file() {
    local candidate

    if [ -z "$SQL_FILE" ]; then
        return 1
    fi

    for candidate in \
        "$SQL_FILE" \
        "${CONFIG_DIR:+${CONFIG_DIR}/${SQL_FILE}}" \
        "${PWD}/${SQL_FILE}" \
        "${SCRIPT_DIR}/${SQL_FILE}"
    do
        [ -n "$candidate" ] || continue
        if [ -f "$candidate" ]; then
            candidate="$(canonical_path "$candidate")"
            if path_is_within "$candidate" "$SOURCE_ROOT_CANONICAL"; then
                die "SQL_FILE berada di dalam SOURCE_ROOT (${candidate}). Letakkan seed schema/dump installer di luar build context agar tidak ikut packaging."
            fi
            printf '%s\n' "$candidate"
            return 0
        fi
    done

    return 1
}

import_sql_if_needed() {
    local resolved_sql_file=""
    local table_count="0"
    local db_name_escaped
    local import_output=""

    db_name_escaped="$(mysql_escape "$DB_NAME")"
    if [ "$DB_IS_LOCAL" = "1" ]; then
        table_count="$(mysql_root_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db_name_escaped}';")"
    else
        table_count="$(mysql_app_server_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db_name_escaped}';")"
    fi
    table_count="${table_count:-0}"

    if [ -z "$SQL_FILE" ]; then
        add_summary "SQL_FILE kosong. Import schema awal dilewati."
        return
    fi

    if ! resolved_sql_file="$(resolve_sql_file)"; then
        die "SQL_FILE diisi tetapi file tidak ditemukan: ${SQL_FILE}"
    fi

    if [ "$table_count" -gt 0 ]; then
        warn "Database ${DB_NAME} sudah memiliki ${table_count} tabel. Import SQL dilewati untuk menghindari duplikasi."
        add_summary "Import SQL dilewati karena database ${DB_NAME} tidak kosong."
        return
    fi

    log "Mengimport SQL dari ${resolved_sql_file} ke database ${DB_NAME}..."
    if [ "$DB_IS_LOCAL" = "1" ]; then
        mysql_root_import_db "$DB_NAME" "$resolved_sql_file"
    else
        if ! import_output="$(mysql_app_import_db "$resolved_sql_file" 2>&1)"; then
            die "Import SQL gagal ke database remote ${DB_NAME}. Pastikan database sudah ada dan user ${DB_USER} memiliki hak import yang cukup. Output asli: ${import_output}"
        fi
    fi
    add_summary "Import SQL selesai dari ${resolved_sql_file}."
}

write_project_env() {
    log "Merender .env runtime dari .env.example canonical..."

    local project_env_file="${APP_ROOT}/.env"
    local existing_env_file="${APP_ROOT}/.env"
    local temp_env_file
    local key line existing_value rendered_value

    declare -A replacements=()

    replacements[CI_ENV]="$(resolve_runtime_env_value "$existing_env_file" "CI_ENV" "${CI_ENV:-}" "production")"
    replacements[DB_HOST]="$DB_HOST"
    replacements[DB_PORT]="$DB_PORT"
    replacements[DB_NAME]="$DB_NAME"
    replacements[DB_USER]="$DB_USER"
    replacements[DB_PASS]="$DB_PASS"
    replacements[CI_ENCRYPTION_KEY]="$(resolve_runtime_env_value "$existing_env_file" "CI_ENCRYPTION_KEY" "${CI_ENCRYPTION_KEY:-}" "generate")"
    replacements[PROVISIONING_CALLBACK_SECRET]="$(resolve_runtime_env_value "$existing_env_file" "PROVISIONING_CALLBACK_SECRET" "${PROVISIONING_CALLBACK_SECRET:-}" "generate")"
    replacements[TELEGRAM_WEBHOOK_SECRET]="$(resolve_runtime_env_value "$existing_env_file" "TELEGRAM_WEBHOOK_SECRET" "${TELEGRAM_WEBHOOK_SECRET:-}" "generate")"
    replacements[CRON_TOKEN]="$(resolve_runtime_env_value "$existing_env_file" "CRON_TOKEN" "${CRON_TOKEN:-}" "generate")"
    replacements[MONITORING_CRON_TOKEN]="$(resolve_runtime_env_value "$existing_env_file" "MONITORING_CRON_TOKEN" "${MONITORING_CRON_TOKEN:-}" "generate")"
    replacements[TELEGRAM_BOT_TOKEN]="$(resolve_runtime_env_value "$existing_env_file" "TELEGRAM_BOT_TOKEN" "${TELEGRAM_BOT_TOKEN:-}" "")"
    replacements[QUEUE_DRIVER]="$QUEUE_DRIVER"
    replacements[QUEUE_ENABLE_ASYNC]="$QUEUE_ENABLE_ASYNC"
    replacements[REDIS_HOST]="$(resolve_runtime_env_value "$existing_env_file" "REDIS_HOST" "${REDIS_HOST:-}" "127.0.0.1")"
    replacements[REDIS_PORT]="$(resolve_runtime_env_value "$existing_env_file" "REDIS_PORT" "${REDIS_PORT:-}" "6379")"
    replacements[REDIS_DB]="$(resolve_runtime_env_value "$existing_env_file" "REDIS_DB" "${REDIS_DB:-}" "0")"
    replacements[REDIS_TIMEOUT]="$(resolve_runtime_env_value "$existing_env_file" "REDIS_TIMEOUT" "" "2.0")"
    replacements[REDIS_PASSWORD]="$(resolve_runtime_env_value "$existing_env_file" "REDIS_PASSWORD" "${REDIS_PASSWORD:-}" "")"
    replacements[REDIS_PREFIX]="$(resolve_runtime_env_value "$existing_env_file" "REDIS_PREFIX" "${REDIS_PREFIX:-}" "rtrwnet:")"

    temp_env_file="$(mktemp)"

    if [ -f "$project_env_file" ]; then
        backup_path "$project_env_file"
    fi

    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"

        if [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)= ]]; then
            key="${BASH_REMATCH[1]}"

            if [ -n "${replacements[$key]+x}" ]; then
                rendered_value="${replacements[$key]}"
                printf '%s="%s"\n' "$key" "$(dotenv_escape "$rendered_value")" >> "$temp_env_file"
                continue
            fi

            existing_value="$(read_project_env_value "$existing_env_file" "$key")"
            if [ -n "$existing_value" ]; then
                printf '%s="%s"\n' "$key" "$(dotenv_escape "$existing_value")" >> "$temp_env_file"
                continue
            fi
        fi

        printf '%s\n' "$line" >> "$temp_env_file"
    done < "$ENV_TEMPLATE_FILE"

    mv "$temp_env_file" "$project_env_file"
    chown "root:${WEB_GROUP}" "$project_env_file"
    chmod 0640 "$project_env_file"

    add_summary ".env runtime dirender ulang dari ${ENV_TEMPLATE_FILE}"
    add_summary "QUEUE_DRIVER di-set ke ${QUEUE_DRIVER}; QUEUE_ENABLE_ASYNC di-set ke ${QUEUE_ENABLE_ASYNC}."
}

derive_ci_route_from_controller_file() {
    local controller_file="$1"
    local route=""

    route="${controller_file#${SOURCE_ROOT_CANONICAL}/application/controllers/}"
    route="${route%.php}"
    printf '%s' "${route,,}"
}

controller_has_latest_migration_runner() {
    local controller_file="$1"

    grep -Eq 'function[[:space:]]+latest[[:space:]]*\(' "$controller_file" || return 1
    grep -Eq "load->library\\([[:space:]]*['\"]migration['\"]|migration->latest" "$controller_file"
}

detect_migration_command() {
    local controller_file=""
    local route=""

    MIGRATION_COMMAND_ARGS=()
    MIGRATION_COMMAND_SOURCE=""

    if [ "$ENABLE_DB_MIGRATIONS" != "1" ]; then
        return 0
    fi

    if [ "$MIGRATION_COMMAND" != "auto" ]; then
        read -r -a MIGRATION_COMMAND_ARGS <<< "$MIGRATION_COMMAND"
        if [ "${#MIGRATION_COMMAND_ARGS[@]}" -eq 0 ]; then
            die "MIGRATION_COMMAND diisi tetapi tidak dapat diparse menjadi argumen CLI."
        fi
        MIGRATION_COMMAND_SOURCE="config:MIGRATION_COMMAND"
        return 0
    fi

    while IFS= read -r controller_file; do
        if controller_has_latest_migration_runner "$controller_file"; then
            route="$(derive_ci_route_from_controller_file "$controller_file")"
            MIGRATION_COMMAND_ARGS=("$route" "latest")
            MIGRATION_COMMAND_SOURCE="auto:${controller_file#${SOURCE_ROOT_CANONICAL}/}"
            return 0
        fi
    done < <(find "${SOURCE_ROOT_CANONICAL}/application/controllers" -type f -name '*.php' | sort)

    die "ENABLE_DB_MIGRATIONS=1 tetapi installer tidak dapat mendeteksi command migration CLI dari source tree. Isi MIGRATION_COMMAND di config installer, misalnya: migrate latest"
}

render_migration_command_for_log() {
    if [ "${#MIGRATION_COMMAND_ARGS[@]}" -eq 0 ]; then
        printf '<belum-terdeteksi>'
        return
    fi

    format_command_for_log "${PHP_BIN}" index.php "${MIGRATION_COMMAND_ARGS[@]}"
}

run_as_web_user() {
    local cmd=("$@")
    runuser -u "$WEB_USER" -- "${cmd[@]}"
}

verify_app_cli_bootstrap() {
    local context_label="${1:-smoke verification}"
    local app_index="${APP_ROOT}/index.php"
    local cli_output=""
    local cli_output_canonical=""
    local app_root_canonical=""

    assert_required_project_layout "$APP_ROOT" "APP_ROOT deploy" "packaging/allowlist/deploy"

    if [ ! -r "$app_index" ]; then
        die "File entry point aplikasi tidak readable: ${app_index}. Periksa hasil deploy dan permission APP_ROOT."
    fi

    if ! run_as_web_user test -r "$app_index"; then
        die "File entry point aplikasi tidak readable oleh WEB_USER (${WEB_USER}): ${app_index}. Periksa ownership/permission APP_ROOT sebelum menjalankan CLI aplikasi."
    fi

    log "Menjalankan smoke CLI aplikasi (${context_label}): (cd ${APP_ROOT} && $(format_command_for_log "$PHP_BIN" -r 'echo getcwd();')) sebagai ${WEB_USER}"
    if ! cli_output="$(
        runuser -u "$WEB_USER" -- sh -c '
            set -eu
            cd "$1"
            shift
            exec "$@"
        ' sh "$APP_ROOT" "$PHP_BIN" -r 'echo getcwd();' 2>&1
    )"; then
        die "Smoke CLI aplikasi gagal sebelum ${context_label}. Output: ${cli_output}"
    fi

    app_root_canonical="$(canonical_path "$APP_ROOT")"
    cli_output_canonical="$(canonical_path "$cli_output")"
    if [ "$cli_output_canonical" != "$app_root_canonical" ]; then
        die "Smoke CLI aplikasi memberi working directory yang tidak konsisten (${cli_output}); expected ${APP_ROOT}. Periksa konteks APP_ROOT dan cara eksekusi PHP CLI."
    fi
}

run_app_cli_command_in_app_root() {
    runuser -u "$WEB_USER" -- sh -c '
        set -eu
        cd "$1"
        shift
        exec "$@"
    ' sh "$APP_ROOT" "$PHP_BIN" index.php "$@"
}

log_app_cli_command() {
    local label="$1"
    shift

    log "Menjalankan ${label}: (cd ${APP_ROOT} && $(format_command_for_log "$PHP_BIN" index.php "$@")) sebagai ${WEB_USER}"
}

run_app_cli_as_web_user() {
    local label="$1"
    shift

    verify_app_cli_bootstrap "$label"
    log_app_cli_command "$label" "$@"
    run_app_cli_command_in_app_root "$@"
}

run_database_migrations() {
    if [ "$ENABLE_DB_MIGRATIONS" != "1" ]; then
        add_summary "Migration aplikasi dilewati karena ENABLE_DB_MIGRATIONS=0."
        return
    fi

    local migration_output=""

    log_path_context "Migration flow"
    log "Menjalankan migration aplikasi..."
    if [ "${#MIGRATION_COMMAND_ARGS[@]}" -eq 0 ]; then
        detect_migration_command
    fi
    verify_app_cli_bootstrap "migration aplikasi"
    log "Menjalankan migration aplikasi: (cd ${APP_ROOT} && $(render_migration_command_for_log)) sebagai ${WEB_USER}"
    if ! migration_output="$(run_app_cli_command_in_app_root "${MIGRATION_COMMAND_ARGS[@]}" 2>&1)"; then
        die "Migration command gagal. Source=${MIGRATION_COMMAND_SOURCE}. Command=(cd ${APP_ROOT} && $(render_migration_command_for_log)). Output asli: ${migration_output}"
    fi
    if [ -n "$migration_output" ]; then
        log "Output migration aplikasi: ${migration_output}"
    fi
    add_summary "Migration aplikasi berhasil dijalankan dengan command: $(render_migration_command_for_log)"
}

assert_database_schema_ready() {
    local required_tables=(users customers routers background_jobs)
    local table count missing=()

    for table in "${required_tables[@]}"; do
        count="$(mysql_app_server_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$(mysql_escape "$DB_NAME")' AND table_name='$(mysql_escape "$table")';")"
        count="${count:-0}"
        if [ "$count" -eq 0 ]; then
            missing+=("$table")
        fi
    done

    if [ "${#missing[@]}" -gt 0 ]; then
        die "Schema aplikasi belum lengkap setelah import/migration. Tabel penting yang belum ada: ${missing[*]}. Sediakan SQL_FILE schema final yang aman jika database masih kosong."
    fi
}

ensure_phpmyadmin() {
    if [ "$ENABLE_PHPMYADMIN" != "1" ]; then
        add_summary "phpMyAdmin dilewati karena ENABLE_PHPMYADMIN=0."
        return
    fi

    log "Menyiapkan phpMyAdmin..."

    printf 'phpmyadmin phpmyadmin/reconfigure-webserver multiselect \n' | debconf-set-selections || true
    printf 'phpmyadmin phpmyadmin/dbconfig-install boolean false\n' | debconf-set-selections || true
    install_packages_if_missing phpmyadmin

    if [ ! -d /usr/share/phpmyadmin ]; then
        die "Folder phpMyAdmin tidak ditemukan setelah instalasi."
    fi

    mkdir -p /var/lib/phpmyadmin/tmp
    chown -R "$WEB_USER:$WEB_GROUP" /var/lib/phpmyadmin
    chmod -R u=rwX,g=rwX,o= /var/lib/phpmyadmin

    local phpmyadmin_local_conf="/etc/phpmyadmin/conf.d/99-rtrwnet-local.php"
    backup_path "$phpmyadmin_local_conf"

    cat > "$phpmyadmin_local_conf" <<'EOF'
<?php
$cfg['TempDir'] = '/var/lib/phpmyadmin/tmp';
EOF

    chown "root:${WEB_GROUP}" "$phpmyadmin_local_conf"
    chmod 0640 "$phpmyadmin_local_conf"
    add_summary "phpMyAdmin siap dari package distro."
}

set_project_permissions() {
    log "Mengatur permission project..."

    chown -R "root:${WEB_GROUP}" "$APP_ROOT"

    local app_mode="u=rwX,g=rX,o="
    local permission_baseline="0750/0640"

    if [ -n "$NGINX_RUNTIME_USER" ] && [ "$NGINX_RUNTIME_USER" != "$WEB_USER" ]; then
        app_mode="u=rwX,g=rX,o=rX"
        permission_baseline="0755/0644"
    fi

    chmod -R "$app_mode" "$APP_ROOT"

    local writable_dirs=(
        "application/cache"
        "application/logs"
    )
    local rel_dir abs_dir

    for rel_dir in "${writable_dirs[@]}"; do
        abs_dir="${APP_ROOT}/${rel_dir}"
        mkdir -p "$abs_dir"
        chown -R "${WEB_USER}:${WEB_GROUP}" "$abs_dir"
        find "$abs_dir" -type d -exec chmod 2770 {} +
        find "$abs_dir" -type f -exec chmod 0660 {} +
        add_summary "Writable diaktifkan: ${abs_dir}"
    done

    if [ -f "${APP_ROOT}/.env" ]; then
        chown "root:${WEB_GROUP}" "${APP_ROOT}/.env"
        chmod 0640 "${APP_ROOT}/.env"
    fi

    add_summary "Permission baseline APP_ROOT diterapkan: ${permission_baseline} (nginx user: ${NGINX_RUNTIME_USER:-unknown}, web user: ${WEB_USER})."
}

assert_generated_nginx_site_enabled() {
    local enabled_target=""

    if [ ! -f "$NGINX_CONF_TARGET" ]; then
        die "Verifikasi gagal: generated site belum dibuat di ${NGINX_CONF_TARGET}."
    fi

    if [ ! -L "$NGINX_ENABLED_TARGET" ]; then
        die "Verifikasi gagal: generated site belum enabled di ${NGINX_ENABLED_TARGET}."
    fi

    enabled_target="$(readlink -f "$NGINX_ENABLED_TARGET" 2>/dev/null || true)"
    if [ "$enabled_target" != "$NGINX_CONF_TARGET" ]; then
        die "Verifikasi gagal: generated site enabled mengarah ke ${enabled_target:-<kosong>}, bukan ${NGINX_CONF_TARGET}."
    fi
}

assert_rendered_nginx_config_sane() {
    if [ ! -f "$NGINX_CONF_TARGET" ]; then
        die "Verifikasi gagal: file konfigurasi Nginx tidak ditemukan: ${NGINX_CONF_TARGET}"
    fi

    if ! grep -Fq -- "root ${APP_ROOT};" "$NGINX_CONF_TARGET"; then
        die "Verifikasi gagal: generated Nginx site tidak memuat root APP_ROOT yang benar (${APP_ROOT})."
    fi

    if ! grep -Fq -- 'index index.php index.html index.htm;' "$NGINX_CONF_TARGET"; then
        die "Verifikasi gagal: generated Nginx site tidak memuat directive index yang diharapkan."
    fi

    if ! grep -Fq -- 'try_files $uri $uri/ /index.php$is_args$args;' "$NGINX_CONF_TARGET"; then
        die "Verifikasi gagal: generated Nginx site tidak memuat rewrite try_files utama CodeIgniter."
    fi

    if ! grep -Fq -- "fastcgi_pass ${PHP_FPM_UPSTREAM};" "$NGINX_CONF_TARGET"; then
        die "Verifikasi gagal: generated Nginx site tidak memuat fastcgi_pass ke upstream PHP-FPM ${PHP_FPM_UPSTREAM}."
    fi

    if [ "$NGINX_EXPECT_BY_IP_ROUTING" = "1" ]; then
        if ! grep -Fq -- "listen 80 default_server;" "$NGINX_CONF_TARGET"; then
            die "Verifikasi gagal: generated Nginx site belum dirender sebagai default_server padahal mode by-IP/default-server diharapkan aktif."
        fi
        return
    fi

    if grep -Fq -- "listen 80 default_server;" "$NGINX_CONF_TARGET"; then
        die "Verifikasi gagal: generated Nginx site ter-render sebagai default_server padahal tidak diminta. Gunakan NGINX_ENABLE_DEFAULT_SERVER=1 hanya untuk mode single-app/by-IP."
    fi
}

deploy_nginx_config() {
    log "Merender konfigurasi Nginx..."

    backup_path "$NGINX_CONF_TARGET"
    backup_path "$NGINX_ENABLED_TARGET"

    local rendered_conf
    rendered_conf="$(mktemp)"

    export APP_SERVER_NAMES APP_ROOT PHP_FPM_UPSTREAM PHPMYADMIN_URL NGINX_CONF_BASENAME NGINX_LISTEN_SUFFIX
    envsubst '${APP_SERVER_NAMES} ${APP_ROOT} ${PHP_FPM_UPSTREAM} ${PHPMYADMIN_URL} ${NGINX_CONF_BASENAME} ${NGINX_LISTEN_SUFFIX}' \
        < "$NGINX_TEMPLATE_FILE" \
        > "$rendered_conf"

    if [ "$ENABLE_PHPMYADMIN" != "1" ]; then
        sed -i '/# PHPMYADMIN-BEGIN/,/# PHPMYADMIN-END/d' "$rendered_conf"
    fi

    mv "$rendered_conf" "$NGINX_CONF_TARGET"
    chmod 0644 "$NGINX_CONF_TARGET"

    if [ "$NGINX_EXPECT_BY_IP_ROUTING" = "1" ] && { [ -e /etc/nginx/sites-enabled/default ] || [ -L /etc/nginx/sites-enabled/default ]; }; then
        backup_path /etc/nginx/sites-enabled/default
        rm -f /etc/nginx/sites-enabled/default
        add_summary "Default Nginx site dinonaktifkan karena mode by-IP/default-server aktif."
    fi

    ln -sfn "$NGINX_CONF_TARGET" "$NGINX_ENABLED_TARGET"

    assert_generated_nginx_site_enabled
    assert_rendered_nginx_config_sane
    local nginx_test_output=""
    if ! nginx_test_output="$(nginx -t 2>&1)"; then
        die "generated Nginx site gagal lolos nginx -t. Output asli: ${nginx_test_output}"
    fi
    systemctl reload nginx
    add_summary "Mode routing Nginx: $( [ "$NGINX_EXPECT_BY_IP_ROUTING" = "1" ] && printf '%s' 'default_server/by-IP aktif' || printf '%s' 'host-based routing only' )"
    add_summary "Nginx site diaktifkan: ${NGINX_CONF_TARGET}"
}

build_worker_dependency_lines() {
    local after_targets=("network.target")
    local wants_targets=()

    if [ -n "$MYSQL_SERVICE" ]; then
        after_targets+=("${MYSQL_SERVICE}.service")
    fi

    if [ "$ENABLE_REDIS" = "1" ] && [ "$REDIS_IS_LOCAL" = "1" ]; then
        after_targets+=("redis-server.service")
        wants_targets+=("redis-server.service")
    fi

    WORKER_AFTER_LINE="After=${after_targets[*]}"
    WORKER_WANTS_LINE=""
    if [ "${#wants_targets[@]}" -gt 0 ]; then
        WORKER_WANTS_LINE="Wants=${wants_targets[*]}"
    fi
}

setup_worker_service() {
    if [ "$ENABLE_WORKER" != "1" ]; then
        add_summary "Worker service dilewati karena ENABLE_WORKER=0."
        return
    fi

    log "Merender worker service..."
    backup_path "$WORKER_SERVICE_TARGET"

    build_worker_dependency_lines

    local rendered_service
    rendered_service="$(mktemp)"

    export APP_NAME WEB_USER WEB_GROUP APP_ROOT PHP_BIN WORKER_AFTER_LINE WORKER_WANTS_LINE
    envsubst '${APP_NAME} ${WEB_USER} ${WEB_GROUP} ${APP_ROOT} ${PHP_BIN} ${WORKER_AFTER_LINE} ${WORKER_WANTS_LINE}' \
        < "$WORKER_TEMPLATE_FILE" \
        > "$rendered_service"

    mv "$rendered_service" "$WORKER_SERVICE_TARGET"
    chmod 0644 "$WORKER_SERVICE_TARGET"

    systemctl daemon-reload
    systemctl enable "$WORKER_SERVICE_NAME" >/dev/null 2>&1 || true
    systemctl restart "$WORKER_SERVICE_NAME"
    add_summary "Worker service aktif: ${WORKER_SERVICE_TARGET}"
}

setup_cron_file() {
    if [ "$ENABLE_CRON" != "1" ]; then
        add_summary "Cron project dilewati karena ENABLE_CRON=0."
        return
    fi

    log "Merender cron file..."
    backup_path "$CRON_TARGET"

    local rendered_cron
    rendered_cron="$(mktemp)"

    export WEB_USER PHP_BIN APP_ROOT
    envsubst '${WEB_USER} ${PHP_BIN} ${APP_ROOT}' \
        < "$CRON_TEMPLATE_FILE" \
        > "$rendered_cron"

    install -o root -g root -m 0644 "$rendered_cron" "$CRON_TARGET"
    rm -f "$rendered_cron"
    add_summary "Cron project aktif: ${CRON_TARGET}"
}

verify_service_active() {
    local service_name="$1"
    local label="$2"

    if systemctl is-active --quiet "$service_name"; then
        add_verification "${label}: active"
        return
    fi

    die "Verifikasi gagal: service ${service_name} tidak aktif. Jalankan 'systemctl status ${service_name}' untuk detail log service."
}

verify_service_enabled() {
    local service_name="$1"
    local label="$2"

    if systemctl is-enabled --quiet "$service_name"; then
        add_verification "${label}: enabled"
        return
    fi

    die "Verifikasi gagal: service ${service_name} belum enabled."
}

curl_local_http_status() {
    local host_header="${1:-}"

    if [ -n "$host_header" ]; then
        curl -sS -o /dev/null -w '%{http_code}' --connect-timeout 5 --max-time 15 -H "Host: ${host_header}" http://127.0.0.1/ || true
        return
    fi

    curl -sS -o /dev/null -w '%{http_code}' --connect-timeout 5 --max-time 15 http://127.0.0.1/ || true
}

http_status_is_acceptable() {
    local status="$1"

    case "$status" in
        200|301|302|303|307|308|401|403)
            return 0
            ;;
    esac

    return 1
}

smoke_verify() {
    log "Menjalankan smoke verification..."
    local nginx_test_output=""

    assert_required_project_layout "$APP_ROOT" "APP_ROOT deploy" "packaging/allowlist/deploy"
    add_verification "app root layout: ok"

    assert_generated_nginx_site_enabled
    add_verification "nginx site enabled: ${NGINX_ENABLED_TARGET}"

    assert_rendered_nginx_config_sane
    add_verification "nginx config render: ok"

    if ! nginx_test_output="$(nginx -t 2>&1)"; then
        die "Verifikasi gagal: nginx syntax invalid. Output asli: ${nginx_test_output}"
    fi
    add_verification "nginx syntax: ok"
    verify_service_active nginx "nginx service"
    verify_service_enabled nginx "nginx service"
    verify_service_active "$PHP_FPM_SERVICE" "php-fpm service"
    verify_service_enabled "$PHP_FPM_SERVICE" "php-fpm service"
    if [ ! -S "$PHP_FPM_SOCK" ]; then
        die "Verifikasi gagal: socket PHP-FPM tidak ditemukan di ${PHP_FPM_SOCK}."
    fi
    add_verification "php-fpm socket: ${PHP_FPM_SOCK}"

    if [ "$DB_IS_LOCAL" = "1" ]; then
        verify_service_active "$MYSQL_SERVICE" "database service"
        verify_service_enabled "$MYSQL_SERVICE" "database service"
    else
        add_verification "database service: remote ${DB_HOST}:${DB_PORT}"
    fi

    "$PHP_BIN" -v >/dev/null 2>&1 || die "Verifikasi gagal: PHP CLI tidak bisa dijalankan."
    add_verification "php cli: ${PHP_BIN}"

    verify_app_cli_bootstrap "smoke verification"
    add_verification "app cli bootstrap: ok"

    verify_database_app_login "smoke verification"

    if [ "$ENABLE_REDIS" = "1" ]; then
        if [ "$REDIS_IS_LOCAL" = "1" ]; then
            verify_service_active redis-server "redis service"
            verify_service_enabled redis-server "redis service"
        else
            add_verification "redis service: remote ${REDIS_HOST}:${REDIS_PORT}"
        fi
        verify_redis_connectivity "smoke verification"
    else
        add_verification "redis: disabled, queue driver database"
    fi

    if [ ! -f "${APP_ROOT}/.env" ]; then
        die "Verifikasi gagal: ${APP_ROOT}/.env tidak ditemukan."
    fi

    if [ "$(stat -c '%a' "${APP_ROOT}/.env")" != "640" ]; then
        die "Verifikasi gagal: permission .env harus 640."
    fi
    add_verification ".env runtime: present with 640 permission"

    if [ "$(read_project_env_value "${APP_ROOT}/.env" "QUEUE_DRIVER")" != "$QUEUE_DRIVER" ]; then
        die "Verifikasi gagal: QUEUE_DRIVER di .env tidak sesuai keputusan installer."
    fi
    add_verification "queue driver: ${QUEUE_DRIVER}"

    if [ "$ENABLE_WORKER" = "1" ]; then
        verify_service_active "$WORKER_SERVICE_NAME" "worker service"
        verify_service_enabled "$WORKER_SERVICE_NAME" "worker service"
        run_app_cli_as_web_user "worker CLI smoke" worker run once 1 || \
            die "Verifikasi gagal: worker CLI smoke."
        add_verification "worker cli smoke: ok"
    else
        add_verification "worker: disabled"
    fi

    if [ "$ENABLE_CRON" = "1" ]; then
        if [ ! -f "$CRON_TARGET" ]; then
            die "Verifikasi gagal: cron file tidak ditemukan di ${CRON_TARGET}"
        fi
        add_verification "cron file: ${CRON_TARGET}"
    else
        add_verification "cron: disabled"
    fi

    local host_http_status by_ip_http_status

    host_http_status="$(curl_local_http_status "$APP_DOMAIN")"
    if http_status_is_acceptable "$host_http_status"; then
        add_verification "http smoke (Host: ${APP_DOMAIN}): ${host_http_status}"
    else
        if [ -e /etc/nginx/sites-enabled/default ] || [ -L /etc/nginx/sites-enabled/default ]; then
            die "Verifikasi gagal: host routing gagal untuk ${APP_DOMAIN} dengan status ${host_http_status}. Generated site sudah enabled, tetapi default site masih aktif dan APP_DOMAIN/APP_SERVER_ALIASES bisa jadi tidak match; periksa server_name, symlink site aktif, dan kemungkinan default site interference."
        fi

        die "Verifikasi gagal: host routing gagal untuk ${APP_DOMAIN} dengan status ${host_http_status}. Periksa server_name APP_DOMAIN/APP_SERVER_ALIASES, root APP_ROOT, try_files CI3, dan reload Nginx."
    fi

    by_ip_http_status="$(curl_local_http_status)"
    if [ "$NGINX_EXPECT_BY_IP_ROUTING" != "1" ]; then
        add_verification "http smoke (127.0.0.1): optional, status ${by_ip_http_status}"
        return
    fi

    if http_status_is_acceptable "$by_ip_http_status"; then
        add_verification "http smoke (127.0.0.1): ${by_ip_http_status}"
        return
    fi

    if [ -e /etc/nginx/sites-enabled/default ] || [ -L /etc/nginx/sites-enabled/default ]; then
        die "Verifikasi gagal: by-IP routing memberi status ${by_ip_http_status} dan default nginx site masih aktif. Ini mengindikasikan default site interference terhadap mode by-IP/default-server."
    fi

    if [ "$by_ip_http_status" = "404" ]; then
        die "Verifikasi gagal: by-IP routing ke http://127.0.0.1 memberi 404 padahal mode by-IP/default-server aktif. Periksa listen default_server, symlink site aktif, serta root/try_files Nginx."
    fi

    die "Verifikasi gagal: by-IP routing ke http://127.0.0.1 memberi status ${by_ip_http_status} padahal mode by-IP/default-server aktif. Periksa routing Nginx dan bootstrap aplikasi."
}

print_summary() {
    printf '\n'
    printf '============================================================\n'
    printf ' Deployment Summary\n'
    printf '============================================================\n'
    printf 'App Name        : %s\n' "$APP_NAME"
    printf 'App Domain      : %s\n' "$APP_DOMAIN"
    printf 'Source Root     : %s\n' "$SOURCE_ROOT_CANONICAL"
    printf 'App Root        : %s\n' "$APP_ROOT"
    printf 'OS Target       : %s\n' "$OS_PRETTY_NAME"
    printf 'Nginx Conf      : %s\n' "$NGINX_CONF_TARGET"
    printf 'PHP-FPM         : %s (%s)\n' "$PHP_FPM_SERVICE" "$PHP_FPM_SOCK"
    printf 'PHP CLI         : %s\n' "$PHP_BIN"
    printf 'Database        : %s / %s @ %s:%s (%s)\n' "$DB_NAME" "$DB_USER" "$DB_HOST" "$DB_PORT" "$( [ "$DB_IS_LOCAL" = "1" ] && printf '%s' 'local' || printf '%s' 'remote' )"
    printf 'Redis           : %s\n' "$( [ "$ENABLE_REDIS" = "1" ] && printf '%s @ %s:%s (%s)' "$QUEUE_DRIVER" "$REDIS_HOST" "$REDIS_PORT" "$( [ "$REDIS_IS_LOCAL" = "1" ] && printf '%s' 'local' || printf '%s' 'remote' )" || printf 'disabled' )"
    printf 'Queue Driver    : %s\n' "$QUEUE_DRIVER"
    printf 'Worker          : %s\n' "$( [ "$ENABLE_WORKER" = "1" ] && printf '%s' "$WORKER_SERVICE_TARGET" || printf 'disabled' )"
    printf 'Cron            : %s\n' "$( [ "$ENABLE_CRON" = "1" ] && printf '%s' "$CRON_TARGET" || printf 'disabled' )"
    printf 'App Env         : %s/.env\n' "$APP_ROOT"
    printf '\n'
    printf 'Tindakan yang dilakukan:\n'

    local item
    for item in "${SUMMARY_ITEMS[@]}"; do
        printf ' - %s\n' "$item"
    done

    printf '\n'
    printf 'Smoke verification:\n'
    for item in "${VERIFICATION_ITEMS[@]}"; do
        printf ' - %s\n' "$item"
    done
    printf '============================================================\n'
}

main() {
    require_root
    detect_supported_os
    load_config
    normalize_config
    validate_config
    assert_safe_app_root_for_fresh_install
    assert_source_context_safe
    detect_migration_command

    preflight_apt_readiness
    ensure_base_packages
    ensure_web_account
    prepare_staging_directory
    copy_allowlisted_paths_to_staging
    sanitize_runtime_directories_in_staging
    validate_staging_package
    deploy_staging_to_app_root

    ensure_nginx
    ensure_php
    ensure_database_server
    ensure_redis_stack

    secure_mysql_installation
    ensure_database_and_user
    verify_database_app_login "pre-migration"
    import_sql_if_needed
    write_project_env
    set_project_permissions

    ensure_phpmyadmin
    deploy_nginx_config
    run_database_migrations
    assert_database_schema_ready
    setup_worker_service
    setup_cron_file
    smoke_verify
    print_summary
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
