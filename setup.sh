#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 DowarDev
# SPDX-License-Identifier: AGPL-3.0-only
#
# Glitchvy — first-run setup script for the server.
#
# It configures the Laravel backend (back/) and the admin SPA (front/),
# installs the dependencies, runs the migrations, caches the config and
# finishes with a health-check report.
#
# Usage:
#   ./setup.sh                     # interactive setup
#   ./setup.sh --dev               # development mode (.env.development.example)
#   ./setup.sh --non-interactive   # unattended (requires GLITCHVY_* env vars)
#   ./setup.sh --no-backup         # do not back up existing .env files
#   ./setup.sh --skip-front-build  # do not build the admin SPA
#   ./setup.sh --help
#
# It is uploaded to the server by scripts/deploy/deploy.sh, but it is NEVER
# executed automatically: it prompts for secrets (DB credentials, admin
# password), so a human has to run it.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACK_DIR="$SCRIPT_DIR/back"
FRONT_DIR="$SCRIPT_DIR/front"
BACKUP_KEEP=5
CURRENT_STEP=0

DEV_MODE=false
NON_INTERACTIVE=false
NO_BACKUP=false
HAS_NODE=false
HAS_PNPM=false
HOSTING_ENV=""
SKIP_FRONT_BUILD=false
HAS_FRONT_SRC=false
HEALTH_OK=true
HEALTH_RESULTS=()

# ============================================================
# Logging & helpers
# ============================================================
color() { printf '\033[3%sm%s\033[0m' "$1" "$2"; }
log_ok()   { echo "  $(color 2 "[OK]")   $1"; }
log_info() { echo "  $(color 6 "[..]")  $1"; }
log_warn() { echo "  $(color 3 "[!]")   $1"; }
log_error(){ echo "  $(color 1 "[ER]")  $1"; }

confirm() {
    local prompt="$1" default="${2:-N}"
    if [ "$NON_INTERACTIVE" = true ]; then
        [[ "$default" =~ ^[Yy]$ ]]
        return $?
    fi
    local yn
    if [[ "$default" =~ ^[Yy]$ ]]; then
        printf "%s [Y/n]: " "$prompt" >&2
        read -r yn || true
        yn="${yn:-Y}"
    else
        printf "%s [y/N]: " "$prompt" >&2
        read -r yn || true
        yn="${yn:-N}"
    fi
    [[ "$yn" =~ ^[Yy]$ ]]
}

ask() {
    local prompt="$1" default="$2"
    if [ "$NON_INTERACTIVE" = true ]; then
        echo "$default"
        return 0
    fi
    local val
    printf "%s [%s]: " "$prompt" "$default" >&2
    read -r val || true
    echo "${val:-$default}"
}

ask_secret() {
    local prompt="$1" default="$2"
    if [ "$NON_INTERACTIVE" = true ]; then
        echo "$default"
        return 0
    fi
    local val
    printf "%s: " "$prompt" >&2
    read -s -r val || true
    echo >&2
    echo "${val:-$default}"
}

get_env_default() {
    local key="$1" file="$2"
    grep -E "^${key}=" "$file" 2>/dev/null | cut -d= -f2- || true
}

# Strip the .env quoting from a captured value: remove the wrapping double
# quotes and unescape inner \" and \\ so re-running sed_set does not
# double-escape the value.
unquote_env_val() {
    local val="$1"
    if [[ "$val" == \"*\" ]] && [ "${#val}" -ge 2 ]; then
        val="${val:1:${#val}-2}"
        val="$(printf '%s' "$val" | sed 's/\\\\/\\/g; s/\\"/"/g')"
    fi
    printf '%s' "$val"
}

require_cmd() {
    local bin="$1" hint="${2:-}"
    if ! command -v "$bin" >/dev/null 2>&1; then
        log_error "Required command not found: $bin"
        [ -n "$hint" ] && log_error "Hint: $hint"
        exit 2
    fi
}

backup_file() {
    local path="$1"
    [ "$NO_BACKUP" = true ] && return 0
    [ ! -f "$path" ] && return 0
    local ts backup
    ts="$(date +%Y%m%d_%H%M%S)"
    backup="${path}.bak.${ts}"
    cp "$path" "$backup"
    local backups=()
    for f in "${path}.bak".*; do
        [ -f "$f" ] && backups+=("$f")
    done
    local count=${#backups[@]}
    if [ "$count" -gt "$BACKUP_KEEP" ]; then
        local remove_count=$((count - BACKUP_KEEP))
        for f in $(printf '%s\n' "${backups[@]}" | sort | head -n "$remove_count"); do
            rm -f "$f"
        done
    fi
    printf '%s' "$backup"
}

# The backend environment file holds the database password, the application
# key, the mail credentials and the SSO client secret. The default umask
# would leave it readable by every other user of the shared host, so the
# file and its backups are restricted to their owner.
restrict_env_permissions() {
    local path="$1"
    [ -f "$path" ] || return 0
    chmod 600 "$path" || return 1
    local backup
    for backup in "${path}".bak.*; do
        [ -f "$backup" ] || continue
        # A backup that cannot be restricted is a secret left readable:
        # the failure is reported instead of being swallowed.
        chmod 600 "$backup" || return 1
    done
    return 0
}

# The exit codes of this script (see usage()).
readonly EXIT_WRITE_ERROR=6
readonly EXIT_INVALID_VALUE=7

sed_set() {
    local file="$1" key="$2" val="$3"
    # A newline cannot be represented in a .env file: the value would break
    # the file into two lines and the rest of it would be parsed as a
    # different variable. Refuse it instead of writing a broken .env.
    #
    # The helper returns an error code instead of aborting the script: a
    # helper that calls exit only ends the subshell it runs in. The caller
    # decides what to do, and every caller turns it into the documented
    # exit code (7 for an invalid value, 6 for a write failure).
    if [[ "$val" == *$'\n'* || "$val" == *$'\r'* ]]; then
        log_error "Refusing to write '$key': the value contains a newline"
        return 7
    fi
    local escaped_val="$val"
    local needs_quoting=false
    if [[ "$val" =~ [[:space:]#\"\'] ]] || [ -z "$val" ]; then
        needs_quoting=true
        escaped_val="${escaped_val//\"/\\\"}"
    fi
    escaped_val="${escaped_val//\\/\\\\}"
    escaped_val="${escaped_val//&/\\&}"
    escaped_val="${escaped_val//|/\\|}"
    if [ "$needs_quoting" = true ]; then
        escaped_val="\"${escaped_val}\""
    fi
    if grep -qE "^\s*${key}=" "$file" 2>/dev/null; then
        if ! sed -i "s|^[[:space:]]*${key}=.*|${key}=${escaped_val}|" "$file"; then
            log_error "Cannot write '$key' to $file"
            return $EXIT_WRITE_ERROR
        fi
    else
        if ! printf '%s\n' "${key}=${escaped_val}" >> "$file"; then
            log_error "Cannot write '$key' to $file"
            return $EXIT_WRITE_ERROR
        fi
    fi
    return 0
}

normalize_url() {
    local value="$1"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    if [ -z "$value" ]; then
        return 1
    fi
    if [[ ! "$value" =~ ^https?:// ]]; then
        value="http://$value"
    fi
    echo "$value"
}

detect_hosting() {
    if [ -d "/usr/local/cpanel" ] || [ -n "${CPANEL:-}" ]; then
        echo "shared"
    elif command -v sudo >/dev/null 2>&1 || command -v apt >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
        echo "vps"
    else
        echo "shared"
    fi
}

usage() {
    cat <<EOF
Usage: $0 [OPTIONS]

Glitchvy setup script for the Laravel backend (back/) and the admin SPA (front/).

Options:
  --dev               Development mode (.env.development.example)
  --non-interactive   Non-interactive mode (requires GLITCHVY_* env vars)
  --no-backup         Skip backups of existing files
  --skip-front-build  Skip the frontend build step
  --help              Show this help message and exit

Exit codes:
  0   Success
  2   Missing prerequisite
  3   Missing required environment variable
  4   No TTY available (use --non-interactive) or DB connection failed
  5   Database migration or seeding failed
  6   Write permission error
  7   Invalid value (a value that cannot be written to an env file)
EOF
    exit 0
}

while [ $# -gt 0 ]; do
    case "$1" in
        --dev) DEV_MODE=true; shift ;;
        --non-interactive) NON_INTERACTIVE=true; shift ;;
        --no-backup) NO_BACKUP=true; shift ;;
        --skip-front-build) SKIP_FRONT_BUILD=true; shift ;;
        --help) usage ;;
        *) log_error "Unknown option: $1"; usage; exit 2 ;;
    esac
done

# The env files this run touched. A failure leaves a half written file,
# and the next run reads that file as the previous values, so the partial
# write has to be undone before the script exits.
ROLLBACK_FILES=()
ROLLBACK_BACKUPS=()

remember_env_file() {
    ROLLBACK_FILES+=("$1")
    ROLLBACK_BACKUPS+=("$2")
}

restore_env_files() {
    local i path backup
    for i in "${!ROLLBACK_FILES[@]}"; do
        path="${ROLLBACK_FILES[$i]}"
        backup="${ROLLBACK_BACKUPS[$i]}"
        if [ -n "$backup" ] && [ -f "$backup" ]; then
            cp "$backup" "$path" && log_error "Restored $path from $backup"
        elif [ -f "$path" ]; then
            rm -f "$path" && log_error "Removed $path: this run created it and it was left incomplete"
        fi
    done
}

die() {
    restore_env_files
    log_error "Aborted (exit code: $1)"
    exit "$1"
}

error_handler() {
    local exit_code=$?
    restore_env_files
    log_error "Error on line $1 (exit code: $exit_code)"
    exit "$exit_code"
}
trap 'error_handler $LINENO' ERR

banner() {
    echo ""
    echo "  +------------------------------------------+"
    echo "  |   Glitchvy - Setup Script                 |"
    echo "  +------------------------------------------+"
    echo ""
}

# ============================================================
# Steps
# ============================================================
check_prereqs() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Checking prerequisites..."

    require_cmd php "Install PHP 8.3+ (e.g., apt install php8.3-cli)"
    require_cmd composer "Install Composer (https://getcomposer.org)"

    local php_ver
    php_ver="$(php -v | head -1 | sed -E 's/^PHP[[:space:]]+//; s/[[:space:]].*//')"
    if ! echo "$php_ver" | grep -qE '^[0-9]+\.[0-9]+'; then
        log_warn "Could not determine PHP version, assuming compatible"
    else
        local min_ver="8.3.0"
        if ! printf '%s\n' "$php_ver" "$min_ver" | sort -V | head -1 | grep -qF "$min_ver"; then
            log_error "PHP 8.3+ required, found $php_ver"
            exit 2
        fi
        log_ok "PHP $php_ver"
    fi

    if ! php -m 2>/dev/null | grep -qi 'pdo_sqlite'; then
        log_warn "PHP extension pdo_sqlite is not loaded - the sqlite driver will not work"
    fi
    if ! php -m 2>/dev/null | grep -qi 'pdo_mysql'; then
        log_warn "PHP extension pdo_mysql is not loaded - the mysql driver will not work"
    fi

    if command -v node >/dev/null 2>&1 && command -v pnpm >/dev/null 2>&1; then
        HAS_NODE=true
        HAS_PNPM=true
        log_ok "Node.js + pnpm found"
    else
        HAS_NODE=false
        HAS_PNPM=false
        log_warn "Node.js/pnpm not found - the admin SPA build will be skipped"
    fi

    HOSTING_ENV="$(detect_hosting)"
    log_info "Hosting environment detected: $HOSTING_ENV"

    log_ok "Prerequisites met"
}

configure_backend() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Configuring backend..."

    cd "$BACK_DIR"

    local example_file=""
    if [ "$DEV_MODE" = true ]; then
        example_file=".env.development.example"
    else
        example_file=".env.production.example"
    fi

    if [ ! -f "$example_file" ]; then
        log_warn "Backend example file not found: $example_file, trying .env.example"
        if [ -f ".env.example" ]; then
            example_file=".env.example"
        else
            log_error "Backend: no example file found (tried $example_file and .env.example)"
            log_error "Restore it from the repository (it is tracked in git) or re-run the deploy to upload the templates."
            exit 6
        fi
    fi

    # Preserve the existing values so a re-run after a mid-way failure does not
    # force the user to re-enter everything: the previous values become the
    # defaults.
    local prev_app_name prev_app_url prev_frontend_url prev_app_env prev_app_debug
    local prev_db_connection prev_db_host prev_db_port prev_db_database prev_db_username prev_db_password
    local prev_mail_host prev_mail_username prev_mail_password prev_mail_from_address
    local prev_retention_days prev_storage_disk prev_rate_limit
    if [ -f ".env" ]; then
        prev_app_name="$(unquote_env_val "$(get_env_default APP_NAME ".env")")"
        prev_app_url="$(unquote_env_val "$(get_env_default APP_URL ".env")")"
        prev_frontend_url="$(unquote_env_val "$(get_env_default FRONTEND_URL ".env")")"
        prev_app_env="$(unquote_env_val "$(get_env_default APP_ENV ".env")")"
        prev_app_debug="$(unquote_env_val "$(get_env_default APP_DEBUG ".env")")"
        prev_db_connection="$(unquote_env_val "$(get_env_default DB_CONNECTION ".env")")"
        prev_db_host="$(unquote_env_val "$(get_env_default DB_HOST ".env")")"
        prev_db_port="$(unquote_env_val "$(get_env_default DB_PORT ".env")")"
        prev_db_database="$(unquote_env_val "$(get_env_default DB_DATABASE ".env")")"
        prev_db_username="$(unquote_env_val "$(get_env_default DB_USERNAME ".env")")"
        prev_db_password="$(unquote_env_val "$(get_env_default DB_PASSWORD ".env")")"
        prev_mail_host="$(unquote_env_val "$(get_env_default MAIL_HOST ".env")")"
        prev_mail_username="$(unquote_env_val "$(get_env_default MAIL_USERNAME ".env")")"
        prev_mail_password="$(unquote_env_val "$(get_env_default MAIL_PASSWORD ".env")")"
        prev_mail_from_address="$(unquote_env_val "$(get_env_default MAIL_FROM_ADDRESS ".env")")"
        prev_retention_days="$(unquote_env_val "$(get_env_default GLITCHVY_RETENTION_DAYS ".env")")"
        prev_storage_disk="$(unquote_env_val "$(get_env_default GLITCHVY_STORAGE_DISK ".env")")"
        prev_rate_limit="$(unquote_env_val "$(get_env_default GLITCHVY_RATE_LIMIT ".env")")"
    fi

    env_backup="$(backup_file ".env" | tail -1)"
    if [ ! -f ".env" ]; then
        cp "$example_file" ".env"
        restrict_env_permissions ".env" || die $?
        remember_env_file ".env" ""
    else
        remember_env_file ".env" "$env_backup"
    fi

    local app_name app_url frontend_url app_env app_debug env_backup
    local db_connection db_host db_port db_database db_username db_password
    local mail_mailer mail_host mail_port mail_username mail_password mail_from_address
    local retention_days storage_disk rate_limit
    local sanctum_stateful_domains cors_allowed_origins

    if [ "$NON_INTERACTIVE" = true ]; then
        local missing=false
        for var in GLITCHVY_APP_URL GLITCHVY_FRONTEND_URL GLITCHVY_DB_CONNECTION; do
            if [ -z "${!var:-}" ]; then
                log_error "Missing required env var: $var"
                missing=true
            fi
        done
        if [ "$missing" = true ]; then
            exit 3
        fi

        app_name="${GLITCHVY_APP_NAME:-${prev_app_name:-$(get_env_default APP_NAME "$example_file" | tr -d '"')}}"
        app_url="$(normalize_url "${GLITCHVY_APP_URL}")"
        frontend_url="$(normalize_url "${GLITCHVY_FRONTEND_URL}")"
        app_env="${GLITCHVY_APP_ENV:-${prev_app_env:-$(get_env_default APP_ENV "$example_file")}}"
        app_debug="${GLITCHVY_APP_DEBUG:-${prev_app_debug:-false}}"
        db_connection="${GLITCHVY_DB_CONNECTION}"
        db_host="${GLITCHVY_DB_HOST:-${prev_db_host:-127.0.0.1}}"
        db_port="${GLITCHVY_DB_PORT:-${prev_db_port:-3306}}"
        db_database="${GLITCHVY_DB_DATABASE:-${prev_db_database:-glitchvy}}"
        db_username="${GLITCHVY_DB_USERNAME:-${prev_db_username:-root}}"
        db_password="${GLITCHVY_DB_PASSWORD:-${prev_db_password:-}}"
        mail_mailer="${GLITCHVY_MAIL_MAILER:-$(get_env_default MAIL_MAILER "$example_file")}"
        mail_host="${GLITCHVY_MAIL_HOST:-${prev_mail_host:-$(get_env_default MAIL_HOST "$example_file")}}"
        mail_port="${GLITCHVY_MAIL_PORT:-$(get_env_default MAIL_PORT "$example_file")}"
        mail_username="${GLITCHVY_MAIL_USERNAME:-${prev_mail_username:-$(get_env_default MAIL_USERNAME "$example_file")}}"
        mail_password="${GLITCHVY_MAIL_PASSWORD:-${prev_mail_password:-$(get_env_default MAIL_PASSWORD "$example_file")}}"
        mail_from_address="${GLITCHVY_MAIL_FROM_ADDRESS:-${prev_mail_from_address:-$(get_env_default MAIL_FROM_ADDRESS "$example_file" | tr -d '"')}}"
        retention_days="${GLITCHVY_RETENTION_DAYS:-${prev_retention_days:-30}}"
        storage_disk="${GLITCHVY_STORAGE_DISK:-${prev_storage_disk:-private}}"
        rate_limit="${GLITCHVY_RATE_LIMIT:-${prev_rate_limit:-60,1}}"
    else
        local default_app_name default_app_url default_frontend_url default_db_connection
        default_app_name="${prev_app_name:-$(grep -E "^APP_NAME=" "$example_file" | cut -d= -f2- | tr -d '"' || echo "Glitchvy")}"
        default_app_url="${prev_app_url:-$(grep -E "^APP_URL=" "$example_file" | cut -d= -f2- || echo "http://localhost:8000")}"
        default_frontend_url="${prev_frontend_url:-$(grep -E "^FRONTEND_URL=" "$example_file" | cut -d= -f2- || echo "http://localhost:8100")}"
        default_db_connection="${prev_db_connection:-sqlite}"

        app_name="$(ask "App name" "$default_app_name")"
        sed_set ".env" "APP_NAME" "$app_name" || die $?
        while :; do
            app_url="$(ask "App URL (backend)" "$default_app_url")"
            app_url="$(normalize_url "$app_url")" && break
            log_error "URL cannot be empty. Enter something like http://localhost:8000"
        done
        sed_set ".env" "APP_URL" "$app_url" || die $?
        while :; do
            frontend_url="$(ask "Admin SPA URL (frontend)" "$default_frontend_url")"
            frontend_url="$(normalize_url "$frontend_url")" && break
            log_error "URL cannot be empty. Enter something like http://localhost:8100"
        done
        sed_set ".env" "FRONTEND_URL" "$frontend_url" || die $?
        if [ "$DEV_MODE" = true ]; then
            app_env="local"
            app_debug="true"
        else
            app_env="production"
            app_debug="false"
        fi

        db_connection="$(ask "DB driver (sqlite|mysql)" "$default_db_connection")"
        case "$db_connection" in
            sqlite)
                db_host=""
                db_port=""
                db_username=""
                db_password=""
                db_database="${prev_db_database:-$BACK_DIR/database/database.sqlite}"
                if [ ! -f "$db_database" ]; then
                    mkdir -p "$(dirname "$db_database")"
                    touch "$db_database"
                    log_info "Created sqlite database file: $db_database"
                fi
                ;;
            mysql)
                db_host="$(ask "DB host" "${prev_db_host:-127.0.0.1}")"
                db_port="$(ask "DB port" "${prev_db_port:-3306}")"
                db_database="$(ask "DB database" "${prev_db_database:-glitchvy}")"
                db_username="$(ask "DB username" "${prev_db_username:-root}")"
                db_password="$(ask_secret "DB password" "${prev_db_password:-}")"
                ;;
            *)
                log_error "Unsupported DB driver: $db_connection (use sqlite or mysql)"
                exit 3
                ;;
        esac

        mail_mailer="$(get_env_default MAIL_MAILER "$example_file")"
        mail_port="$(get_env_default MAIL_PORT "$example_file")"
        local default_mail_host default_mail_username default_mail_password default_mail_from_address
        default_mail_host="${prev_mail_host:-$(get_env_default MAIL_HOST "$example_file")}"
        default_mail_username="${prev_mail_username:-$(get_env_default MAIL_USERNAME "$example_file")}"
        default_mail_password="${prev_mail_password:-$(get_env_default MAIL_PASSWORD "$example_file")}"
        default_mail_from_address="${prev_mail_from_address:-$(get_env_default MAIL_FROM_ADDRESS "$example_file" | tr -d '"')}"
        if [ -z "$default_mail_from_address" ]; then
            default_mail_from_address="noreply@$(echo "$app_url" | sed -E 's|^https?://||; s|/.*$||')"
        fi

        if [ "$mail_mailer" = "smtp" ]; then
            mail_host="$(ask "Mail host" "$default_mail_host")"
            mail_username="$(ask "Mail username" "$default_mail_username")"
            mail_password="$(ask_secret "Mail password" "$default_mail_password")"
            mail_from_address="$(ask "Mail from address" "$default_mail_from_address")"
        else
            mail_host="$default_mail_host"
            mail_username="$default_mail_username"
            mail_password="$default_mail_password"
            mail_from_address="$default_mail_from_address"
        fi

        retention_days="$(ask "Error retention (days)" "${prev_retention_days:-30}")"
        storage_disk="$(ask "Screenshot storage disk" "${prev_storage_disk:-private}")"
        rate_limit="$(ask "Ingest rate limit (requests,minutes)" "${prev_rate_limit:-60,1}")"
    fi

    # Sanctum's stateful domains and the CORS allow-list are derived from the
    # admin SPA URL: the SPA authenticates with a cookie, so the backend has to
    # trust that exact host.
    sanctum_stateful_domains="$(echo "$frontend_url" | sed -E 's|^https?://||; s|/.*$||')"
    cors_allowed_origins="$frontend_url"

    sed_set ".env" "APP_NAME" "$app_name" || die $?
    sed_set ".env" "APP_ENV" "$app_env" || die $?
    sed_set ".env" "APP_DEBUG" "$app_debug" || die $?
    sed_set ".env" "APP_URL" "$app_url" || die $?
    sed_set ".env" "FRONTEND_URL" "$frontend_url" || die $?
    sed_set ".env" "SANCTUM_STATEFUL_DOMAINS" "$sanctum_stateful_domains" || die $?
    sed_set ".env" "CORS_ALLOWED_ORIGINS" "$cors_allowed_origins" || die $?
    sed_set ".env" "DB_CONNECTION" "$db_connection" || die $?
    if [ "$db_connection" = "mysql" ]; then
        sed_set ".env" "DB_HOST" "$db_host" || die $?
        sed_set ".env" "DB_PORT" "$db_port" || die $?
        sed_set ".env" "DB_DATABASE" "$db_database" || die $?
        sed_set ".env" "DB_USERNAME" "$db_username" || die $?
        sed_set ".env" "DB_PASSWORD" "$db_password" || die $?
    else
        sed_set ".env" "DB_DATABASE" "$db_database" || die $?
    fi
    sed_set ".env" "MAIL_MAILER" "$mail_mailer" || die $?
    sed_set ".env" "MAIL_HOST" "$mail_host" || die $?
    sed_set ".env" "MAIL_PORT" "$mail_port" || die $?
    sed_set ".env" "MAIL_USERNAME" "$mail_username" || die $?
    sed_set ".env" "MAIL_PASSWORD" "$mail_password" || die $?
    sed_set ".env" "MAIL_FROM_ADDRESS" "$mail_from_address" || die $?
    sed_set ".env" "GLITCHVY_RETENTION_DAYS" "$retention_days" || die $?
    sed_set ".env" "GLITCHVY_STORAGE_DISK" "$storage_disk" || die $?
    sed_set ".env" "GLITCHVY_RATE_LIMIT" "$rate_limit" || die $?
    # SSO integration (planned, tracked in odd/tasks/deploy-tooling.md):
    # the deploy can carry the SSO credentials so
    # the server env file is ready when the server-side SSO client lands.
    # They are written only when the operator provides them, so nothing
    # unused ends up in the file.
    if [ -n "${GLITCHVY_SSO_URL:-}" ]; then
        sed_set ".env" "SSO_URL" "$GLITCHVY_SSO_URL" || die $?
    fi
    if [ -n "${GLITCHVY_SSO_CLIENT_ID:-}" ]; then
        sed_set ".env" "SSO_CLIENT_ID" "$GLITCHVY_SSO_CLIENT_ID" || die $?
    fi
    if [ -n "${GLITCHVY_SSO_CLIENT_SECRET:-}" ]; then
        sed_set ".env" "SSO_CLIENT_SECRET" "$GLITCHVY_SSO_CLIENT_SECRET" || die $?
    fi

    restrict_env_permissions ".env" || die $?
    verify_db_connection "$db_connection"
    log_ok "Backend configured"
    cd "$SCRIPT_DIR"
}

# Check the configured database with a real PDO connection. A wrong password
# or an unreachable host is caught here instead of failing later inside
# `php artisan migrate` with a cryptic message.
verify_db_connection() {
    local db_connection="$1"

    local db_check
    db_check="$(php -r '
        $env = file_get_contents(".env");
        $vars = [];
        foreach (explode("\n", $env) as $line) {
            if (preg_match("/^([A-Z_]+)=\"?([^\"]*)\"?/", $line, $m)) {
                $vars[$m[1]] = $m[2];
            }
        }
        $driver = $vars["DB_CONNECTION"] ?? "sqlite";
        if ($driver === "sqlite") {
            $path = $vars["DB_DATABASE"] ?? "";
            if ($path === ":memory:" || $path === "") {
                echo "OK";
                return;
            }
            if (!is_file($path)) {
                echo "FAIL:The sqlite database file does not exist: " . $path;
                return;
            }
            if (!is_writable(dirname($path))) {
                echo "FAIL:The sqlite database directory is not writable: " . dirname($path);
                return;
            }
            try {
                new PDO("sqlite:" . $path);
                echo "OK";
            } catch (\PDOException $e) {
                echo "FAIL:" . $e->getMessage();
            }
            return;
        }
        if ($driver !== "mysql") {
            echo "FAIL:Unsupported driver " . $driver . ". Use sqlite or mysql.";
            return;
        }
        $dsn = "mysql:host={$vars["DB_HOST"]};port={$vars["DB_PORT"]};dbname={$vars["DB_DATABASE"]}";
        try {
            new PDO($dsn, $vars["DB_USERNAME"] ?? "", $vars["DB_PASSWORD"] ?? "");
            echo "OK";
        } catch (\PDOException $e) {
            echo "FAIL:" . $e->getMessage();
        }
    ' 2>/dev/null || echo "FAIL:Could not run PHP check")"

    local db_check_msg="${db_check#FAIL:}"
    case "$db_check" in
        "OK"*)
            log_ok "DB connection verified"
            ;;
        "FAIL:")
            log_error "Could not verify DB connection"
            exit 4
            ;;
        *"Access denied"*)
            log_error "DB access denied - wrong DB_USERNAME or DB_PASSWORD"
            exit 4
            ;;
        *"Connection refused"*|*"not reachable"*)
            log_error "DB host $db_host:$db_port not reachable - is MySQL running?"
            exit 4
            ;;
        *"Unknown database"*|*"Unknown MySQL server"*)
            log_error "DB database '$db_database' does not exist"
            exit 4
            ;;
        *"Unsupported driver"*)
            log_error "$db_check_msg"
            exit 4
            ;;
        *"sqlite"*)
            log_error "$db_check_msg"
            exit 4
            ;;
        *)
            log_error "DB connection failed: $db_check_msg"
            exit 4
            ;;
    esac
}

generate_app_key() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Generating app key..."

    cd "$BACK_DIR"
    if grep -qE "^APP_KEY=.+" ".env" 2>/dev/null; then
        log_ok "APP_KEY already set, skipping"
    else
        php artisan key:generate --force 2>&1 || true
        if grep -qE "^APP_KEY=base64:" ".env" 2>/dev/null; then
            log_ok "APP_KEY generated"
        else
            log_error "APP_KEY generation failed (no key in .env)"
            exit 5
        fi
    fi
    cd "$SCRIPT_DIR"
}

# The setup runs the migrations unattended. The data safety lives in the
# migrations themselves: a migration that rebuilds a table copies the rows
# before dropping the old one (see the migration checks in the tooling
# suite), and the foreign keys carry their own delete semantics.
migrate_db() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Running database migrations..."

    cd "$BACK_DIR"
    local pending
    # The pending migrations are shown before they run, so the operator can
    # see what the deploy is about to apply.
    # --force: in production Laravel asks for a confirmation and cancels
    # the command without it.
    if ! pending=$(php artisan migrate --pretend --force --no-ansi 2>/dev/null); then
        log_error "Cannot read the pending migrations (migrate --pretend failed)"
        exit 5
    fi
    log_debug "Pending migrations:$pending"
    php artisan migrate --force || { log_error "Migration failed"; exit 5; }
    link_storage
    log_ok "Database migrated"
    cd "$SCRIPT_DIR"
}

link_storage() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Linking public storage..."

    cd "$BACK_DIR"
    if [ -L "public/storage" ]; then
        log_ok "Public storage already linked"
    elif [ -d "public" ] && [ -d "storage/app/public" ]; then
        # Plain ln -s instead of `php artisan storage:link`: shared hosts
        # often disable the symlink()/exec() PHP functions, which makes the
        # artisan command fail with "Call to undefined function ... exec()".
        if ln -s ../storage/app/public public/storage 2>/dev/null; then
            log_ok "Public storage linked"
        else
            log_warn "Could not link public/storage - uploaded screenshots may 404"
        fi
    else
        log_warn "public/ or storage/app/public missing - skipping storage link"
    fi
    cd "$SCRIPT_DIR"
}

# The admin user is what unlocks the SPA login. It is optional: on a re-run
# over an existing database the admin already exists.
create_admin_user() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Admin user..."

    cd "$BACK_DIR"

    local user_count=0
    user_count="$(php artisan tinker --execute="echo App\\Models\\User::count();" 2>/dev/null | tail -1 | tr -cd '0-9' || echo "0")"
    user_count="${user_count:-0}"

    if [ "$user_count" -gt 0 ]; then
        log_info "Found $user_count existing user(s) - skipping admin creation"
        cd "$SCRIPT_DIR"
        return 0
    fi

    if [ "$NON_INTERACTIVE" = true ]; then
        if [ -z "${GLITCHVY_ADMIN_EMAIL:-}" ] || [ -z "${GLITCHVY_ADMIN_PASSWORD:-}" ]; then
            log_info "GLITCHVY_ADMIN_EMAIL / GLITCHVY_ADMIN_PASSWORD not set - skipping admin creation"
            cd "$SCRIPT_DIR"
            return 0
        fi
        php artisan glitchvy:create-admin "$GLITCHVY_ADMIN_EMAIL" --password="$GLITCHVY_ADMIN_PASSWORD" \
            || { log_error "Admin creation failed"; cd "$SCRIPT_DIR"; exit 5; }
        log_ok "Admin user created"
        cd "$SCRIPT_DIR"
        return 0
    fi

    if ! confirm "Create the admin user for the SPA login?" "Y"; then
        log_info "Admin creation skipped"
        cd "$SCRIPT_DIR"
        return 0
    fi

    local email password
    email="$(ask "Admin email" "admin@glitchvy.app")"
    password="$(ask_secret "Admin password" "")"
    if [ -z "$password" ]; then
        log_warn "Empty password - admin creation skipped"
        cd "$SCRIPT_DIR"
        return 0
    fi

    php artisan glitchvy:create-admin "$email" --password="$password" \
        || { log_error "Admin creation failed"; cd "$SCRIPT_DIR"; exit 5; }
    log_ok "Admin user created"
    cd "$SCRIPT_DIR"
}

configure_frontend() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Configuring admin SPA..."

    cd "$FRONT_DIR"

    local example_file target_file
    if [ "$DEV_MODE" = true ]; then
        example_file=".env.development.example"
        target_file=".env.development"
    else
        example_file=".env.production.example"
        target_file=".env.production"
    fi

    if [ ! -f "$example_file" ]; then
        log_warn "Frontend example file not found: $example_file, trying .env.example"
        if [ -f ".env.example" ]; then
            example_file=".env.example"
        else
            log_error "No frontend .env example found"
            cd "$SCRIPT_DIR"
            return 1
        fi
    fi

    # Vite bakes the VITE_* values into the bundle at build time, so a
    # missing or placeholder env file produces a broken bundle.
    if [ ! -f "$target_file" ]; then
        log_warn "Frontend env file not found: $target_file"
        if [ "$DEV_MODE" = true ]; then
            log_info "Development mode: creating $target_file from the example template."
        else
            log_error "Production mode requires a configured $target_file. Create it from $example_file, then re-run the script."
            cd "$SCRIPT_DIR"
            return 1
        fi
    fi

    front_backup="$(backup_file "$target_file" | tail -1)"
    if [ ! -f "$target_file" ]; then
        cp "$example_file" "$target_file"
        remember_env_file "$target_file" ""
    else
        remember_env_file "$target_file" "$front_backup"
    fi

    # The API lives under /api on the same host by default, so the SPA needs
    # no absolute URL. A split deployment (SPA on another host) overrides it.
    local prev_api_url="" prev_csp_src="" front_backup
    if [ -f "$target_file" ]; then
        prev_api_url="$(grep -E "^VITE_GLITCHVY_API_URL=" "$target_file" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo "")"
        prev_csp_src="$(grep -E "^VITE_GLITCHVY_CSP_CONNECT_SRC=" "$target_file" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo "")"
    fi

    local api_url csp_src
    api_url="$(ask "VITE_GLITCHVY_API_URL" "${prev_api_url:-/api}")"
    sed_set "$target_file" "VITE_GLITCHVY_API_URL" "$api_url" || die $?
    csp_src="$(ask "VITE_GLITCHVY_CSP_CONNECT_SRC" "${prev_csp_src:-'self'}")"
    sed_set "$target_file" "VITE_GLITCHVY_CSP_CONNECT_SRC" "$csp_src" || die $?
    # SSO integration (planned, tracked in odd/tasks/deploy-tooling.md):
    # the SPA bakes these at build time, so they
    # are written only when the operator provides them.
    if [ -n "${GLITCHVY_SSO_URL:-}" ]; then
        sed_set "$target_file" "VITE_SSO_URL" "$GLITCHVY_SSO_URL" || die $?
    fi
    if [ -n "${GLITCHVY_SSO_CLIENT_ID:-}" ]; then
        sed_set "$target_file" "VITE_SSO_CLIENT_ID" "$GLITCHVY_SSO_CLIENT_ID" || die $?
    fi

    log_ok "Admin SPA configured ($target_file)"
    cd "$SCRIPT_DIR"
}

build_frontend() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Building admin SPA..."

    if [ "$HOSTING_ENV" = "shared" ]; then
        SKIP_FRONT_BUILD=true
        cat <<EOF

  !!! Admin SPA build skipped: shared hosting detected.

  Build the SPA on a development machine and upload the dist/ directory:

      cd front
      pnpm install
      pnpm run build

  Then upload front/dist/ contents to your hosting public directory.
EOF
        log_info "Admin SPA build skipped (shared hosting)"
        return
    fi

    if [ "$HAS_NODE" = false ] || [ "$HAS_PNPM" = false ]; then
        SKIP_FRONT_BUILD=true
        cat <<EOF

  !!! Admin SPA build skipped: Node.js/pnpm not available.

  To build the SPA manually on a development machine:

      cd front
      pnpm install
      pnpm run build

  Then upload front/dist/ contents to your hosting public directory.

  To install pnpm on this machine: npm i -g pnpm  (or: corepack enable)
EOF
        log_info "Admin SPA build skipped (no Node.js/pnpm)"
        return
    fi

    cd "$FRONT_DIR"
    if [ ! -d "node_modules" ]; then
        log_info "Installing admin SPA dependencies..."
        pnpm install || { log_warn "pnpm install failed"; SKIP_FRONT_BUILD=true; cd "$SCRIPT_DIR"; return; }
    fi

    log_info "Building admin SPA..."
    if pnpm run build; then
        log_ok "Admin SPA built successfully"
    else
        SKIP_FRONT_BUILD=true
        log_warn "Admin SPA build failed"
    fi
    cd "$SCRIPT_DIR"
}

# ============================================================
# Health checks
# ============================================================
healthcheck() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Running health checks..."

    cd "$BACK_DIR"

    local all_ok=true
    HEALTH_RESULTS=()

    # DB connection
    local check_result
    check_result="$(php -r '
        $env = file_get_contents(".env");
        $vars = [];
        foreach (explode("\n", $env) as $line) {
            if (preg_match("/^([A-Z_]+)=\"?([^\"]*)\"?/", $line, $m)) {
                $vars[$m[1]] = $m[2];
            }
        }
        $driver = $vars["DB_CONNECTION"] ?? "sqlite";
        try {
            if ($driver === "sqlite") {
                new PDO("sqlite:" . ($vars["DB_DATABASE"] ?? ":memory:"));
            } else {
                new PDO(
                    "mysql:host={$vars["DB_HOST"]};port={$vars["DB_PORT"]};dbname={$vars["DB_DATABASE"]}",
                    $vars["DB_USERNAME"] ?? "",
                    $vars["DB_PASSWORD"] ?? ""
                );
            }
            echo "OK";
        } catch (\PDOException $e) {
            echo "FAIL:" . $e->getMessage();
        }
    ' 2>/dev/null || echo "FAIL:Could not run PHP check")"
    if echo "$check_result" | grep -q "^OK"; then
        HEALTH_RESULTS+=("PASS|DB connection")
    else
        HEALTH_RESULTS+=("FAIL|DB connection: $check_result")
        all_ok=false
    fi

    # APP_KEY present
    if grep -qE "^APP_KEY=base64:" ".env" 2>/dev/null; then
        HEALTH_RESULTS+=("PASS|APP_KEY set")
    else
        HEALTH_RESULTS+=("FAIL|APP_KEY missing")
        all_ok=false
    fi

    # Admin SPA host trusted by Sanctum
    if grep -qE "^SANCTUM_STATEFUL_DOMAINS=.+" ".env" 2>/dev/null; then
        HEALTH_RESULTS+=("PASS|Sanctum stateful domains configured")
    else
        HEALTH_RESULTS+=("FAIL|SANCTUM_STATEFUL_DOMAINS missing")
        all_ok=false
    fi

    if grep -qE "^CORS_ALLOWED_ORIGINS=.+" ".env" 2>/dev/null; then
        HEALTH_RESULTS+=("PASS|CORS allowed origins configured")
    else
        HEALTH_RESULTS+=("FAIL|CORS_ALLOWED_ORIGINS missing")
        all_ok=false
    fi

    # Pending migrations
    # shellcheck disable=SC2086
    if { php artisan migrate:status --no-ansi 2>&1 || true; } | grep -q "Pending"; then
        HEALTH_RESULTS+=("FAIL|Pending migrations detected")
        all_ok=false
    else
        HEALTH_RESULTS+=("PASS|All migrations applied")
    fi

    # Ingest route registered
    if { php artisan route:list --no-ansi 2>&1 || true; } | grep -q "glitchvy/errors"; then
        HEALTH_RESULTS+=("PASS|Ingest route registered")
    else
        HEALTH_RESULTS+=("FAIL|Ingest route not registered")
        all_ok=false
    fi

    # Storage permissions (writable by the web server)
    local storage_ok=true
    for d in storage storage/framework storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
        if [ -d "$d" ] && [ ! -w "$d" ]; then
            storage_ok=false
            HEALTH_RESULTS+=("FAIL|$d not writable")
        fi
    done
    if [ "$storage_ok" = true ]; then
        HEALTH_RESULTS+=("PASS|Storage directories writable")
    else
        all_ok=false
    fi

    # Config cache (informational)
    if php artisan config:clear >/dev/null 2>&1; then
        HEALTH_RESULTS+=("PASS|Config cache cleared")
    else
        HEALTH_RESULTS+=("WARN|Config cache could not be cleared")
    fi

    HEALTH_OK=$all_ok
    cd "$SCRIPT_DIR"
}

# ============================================================
# Summary
# ============================================================
print_summary() {
    echo ""
    echo "  +------------------------------------------+"
    echo "  |   Glitchvy - Setup Complete              |"
    echo "  +------------------------------------------+"
    echo ""

    echo "  Configuration"
    echo "    APP_URL:      $(grep -E "^APP_URL=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo "N/A")"
    echo "    FRONTEND_URL: $(grep -E "^FRONTEND_URL=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo "N/A")"
    echo "    DB:           $(grep -E "^DB_CONNECTION=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- || echo "N/A") $(grep -E "^DB_HOST=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- || echo "")/$(grep -E "^DB_DATABASE=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- || echo "")"
    echo "    Backend env:  $BACK_DIR/.env"
    echo "    Frontend env: $([ "$DEV_MODE" = true ] && echo ".env.development" || echo ".env.production")"
    echo "    Hosting:      $HOSTING_ENV"
    echo ""

    echo "  Health Checks"
    for result in "${HEALTH_RESULTS[@]}"; do
        local status="${result%%|*}"
        local label="${result#*|}"
        if [ "$status" = "PASS" ]; then
            printf "    \033[32m[PASS]\033[0m %s\n" "$label"
        elif [ "$status" = "WARN" ]; then
            printf "    \033[33m[WARN]\033[0m %s\n" "$label"
        else
            printf "    \033[31m[FAIL]\033[0m %s\n" "$label"
        fi
    done
    echo ""

    echo "  Next Steps"
    if [ "$HOSTING_ENV" = "shared" ]; then
        echo "    1. Point your web server's document root to $BACK_DIR/public"
        echo "    2. Upload the admin SPA: front/dist/ contents to your hosting public directory"
        echo "    3. Configure SSL/TLS for your domain"
    else
        echo "    1. Configure your web server (Nginx/Apache) document root to $BACK_DIR/public"
        echo "    2. Serve the admin SPA from front/dist/ (same host as the API, so /api works)"
        if [ "$DEV_MODE" = false ]; then
            echo "    3. Configure SSL/TLS for your domain"
        else
            echo "    3. Run the dev servers: cd $BACK_DIR && php artisan serve  (SPA: cd $FRONT_DIR && pnpm run dev)"
        fi
    fi
    echo "    4. Create the first project and API key: php artisan glitchvy:create-project && php artisan glitchvy:create-api-key"
    echo ""
}

# ============================================================
# Main
# ============================================================
main() {
    if [ ! -t 0 ] && [ "$NON_INTERACTIVE" != true ]; then
        log_error "No TTY available. Use --non-interactive for unattended setup."
        exit 4
    fi

    banner

    if [ "$NON_INTERACTIVE" != true ]; then
        if ! confirm "This script will configure Glitchvy (backend + admin SPA). Continue?" "Y"; then
            log_info "Setup cancelled by user"
            exit 0
        fi
    fi

    if [ ! -d "$BACK_DIR" ]; then
        log_error "Backend directory not found: $BACK_DIR"
        exit 6
    fi
    # The admin SPA source only exists on a development machine. On the
    # server the frontend directory holds the built dist/ contents (there is
    # nothing to build or configure there), so its absence is not an error.
    if [ -f "$FRONT_DIR/package.json" ]; then
        HAS_FRONT_SRC=true
    else
        HAS_FRONT_SRC=false
        log_info "Admin SPA source not found in $FRONT_DIR - the frontend steps are skipped"
    fi

    check_prereqs

    cd "$BACK_DIR"
    if [ ! -d "vendor" ]; then
        log_info "Installing Composer dependencies..."
        composer install --no-interaction --prefer-dist
    else
        log_info "Composer dependencies already installed"
    fi
    # A previous run may have cached the Laravel config (bootstrap/cache/config.php)
    # with old DB or app values; downstream `php artisan migrate` would then use
    # the stale cache and ignore the .env edits we are about to make.
    if [ -f "bootstrap/cache/config.php" ]; then
        php artisan config:clear >/dev/null 2>&1 || true
    fi
    cd "$SCRIPT_DIR"

    configure_backend
    generate_app_key
    migrate_db
    create_admin_user

    # The frontend env (.env.production) is only needed where the SPA is
    # actually built: Vite bakes the VITE_* values into the bundle at compile
    # time. On shared hosting (or without Node.js/pnpm, or with
    # --skip-front-build) the dist/ directory is built on a dev machine and
    # uploaded, so there is no build here that would read .env.production.
    if [ "$HAS_FRONT_SRC" = false ]; then
        log_info "Admin SPA env config skipped: no SPA source in $FRONT_DIR (the dist/ was built and uploaded from a dev machine)"
    elif [ "$HOSTING_ENV" = "shared" ] || [ "$HAS_NODE" = false ] || [ "$HAS_PNPM" = false ] || [ "$SKIP_FRONT_BUILD" = true ]; then
        log_info "Admin SPA env config skipped: the SPA is built on a dev machine and dist/ is uploaded (shared hosting / no Node.js / --skip-front-build)"
    else
        configure_frontend
    fi

    if [ "$HAS_FRONT_SRC" = true ] && [ "$SKIP_FRONT_BUILD" != true ]; then
        build_frontend
    fi

    healthcheck
    print_summary

    if [ "$HEALTH_OK" = true ]; then
        log_ok "Glitchvy setup complete"
    else
        log_warn "Glitchvy setup complete with warnings - review the health checks above"
    fi
}

main "$@"
