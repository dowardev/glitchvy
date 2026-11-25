#!/usr/bin/env bash
# Glitchvy deploy script — builds the Laravel backend (back/) and the admin
# SPA (front/) and ships them to the server over SSH.
# Configuration via scripts/deploy/deploy.conf (PROJECT_TYPE determines flow).
#
# Usage:
#   ./scripts/deploy/deploy.sh            # checks + build + deploy from the current commit
#   ./scripts/deploy/deploy.sh --backend  # only the backend
#   ./scripts/deploy/deploy.sh --frontend # only the admin SPA
#   ./scripts/deploy/deploy.sh --all      # both (explicit)
#   ./scripts/deploy/deploy.sh --tag v1.2.3   # checkout the tag first, then deploy
# Exit codes: 0 success, 1 the deploy failed, 2 a missing prerequisite or an
# invalid option.
#
#   ./scripts/deploy/deploy.sh --skip-checks  # skip the checks (faster, use with caution)
#   ./scripts/deploy/deploy.sh --migrate     # also run the pending migrations (opt-in)
#   ./scripts/deploy/deploy.sh --dry-run      # print the commands without running scp/ssh
#   ./scripts/deploy/deploy.sh --verbose      # show every command (debug)
#   ./scripts/deploy/deploy.sh -h|--help      # show this help
#
# Secrets: scripts/deploy/.env (gitignored)
# Copy scripts/deploy/.env.example → scripts/deploy/.env and fill in your values.
#
# Project config: scripts/deploy/deploy.conf (committed)
# Copy scripts/deploy/deploy.conf.example → scripts/deploy/deploy.conf to override.
#
# Required env vars (from scripts/deploy/.env):
#   SSH_HOST, SSH_PORT, SSH_USER
#   DEPLOY_PATH_BACKEND, DEPLOY_PATH_FRONTEND (fullstack)
#   SSH_PRIVATE_KEY (the key content, or the path to a file with it) OR
#   SSH_PRIVATE_KEY_PATH (file) — optional
#   SSH_KNOWN_HOSTS — optional
#
# Required config vars (from scripts/deploy/deploy.conf):
#   PROJECT_TYPE="fullstack"
#   BACKEND_DIR, FRONTEND_DIR, BACKEND_ARTIFACT_EXCLUDES, BACKEND_REMOTE_CMD,
#   FRONTEND_BUILD_CMD, FRONTEND_ARTIFACT_SOURCE
#   Optional: SETUP_SCRIPT (repo-root first-run setup script, uploaded but never run)
#   Optional: VERSION_CHECK_PKG / VERSION_CHECK_FILE (npm registry check)
#
# The npm SDK (packages/sdk) is NOT published here: it is released separately.

set -euo pipefail

# ============================================================
# Colors & Logging
# ============================================================
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

VERBOSE=false
DRY_RUN=false
SKIP_CHECKS=false
RUN_MIGRATIONS=false
DEPLOY_BACKEND=true
DEPLOY_FRONTEND=true
TARGET_REF=""

log_info()  { echo -e "${GREEN}[INFO]${NC} $*"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
log_err()   { echo -e "${RED}[ERR]${NC} $*"; }
log_debug() { [[ "$VERBOSE" == "true" ]] && echo -e "${CYAN}[DEBUG]${NC} $*" || true; }
log_step()  { echo -e "\n${BOLD}${BLUE}▶ $*${NC}"; }
log_phase() { echo -e "\n${BOLD}${CYAN}═══ $* ═══${NC}"; }

# ============================================================
# Args
# ============================================================
while [[ $# -gt 0 ]]; do
  case "$1" in
    --backend)       DEPLOY_FRONTEND=false; shift ;;
    --frontend)      DEPLOY_BACKEND=false; shift ;;
    --all)           DEPLOY_BACKEND=true; DEPLOY_FRONTEND=true; shift ;;
    --tag)           TARGET_REF="$2"; shift 2 ;;
    --skip-checks)   SKIP_CHECKS=true; shift ;;
    --migrate)      RUN_MIGRATIONS=true; shift ;;
    --dry-run)       DRY_RUN=true; shift ;;
    --verbose)       VERBOSE=true; shift ;;
    -h|--help)
      sed -n '2,30p' "$0"
      exit 0
      ;;
    *)
      log_err "Unknown argument: $1"
      echo "Run with --help for usage." >&2
      exit 1
      ;;
  esac
done

# ============================================================
# Paths & Load Config
# ============================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
DEPLOY_DIR="$REPO_ROOT/scripts/deploy"
ENV_FILE="$DEPLOY_DIR/.env"
CONF_FILE="$DEPLOY_DIR/deploy.conf"

# The deploy must never wait for an answer: corepack asks before it
# downloads pnpm the first time, which would hang a deploy (and every
# CI job) on a prompt nobody can answer.
export COREPACK_ENABLE_DOWNLOAD_PROMPT=0

# The deploy uses the pnpm version the repository pins in
# packageManager. pnpm switches to it on its own: it downloads the
# pinned version once, without asking anything, and then every command
# runs with the version the lockfile was written for.
#
# Forcing the host pnpm instead (manage-package-manager-versions=false)
# made an older pnpm try to purge the node_modules tree that the
# pinned pnpm had built, and that purge aborts when there is no TTY to
# ask on.
export COREPACK_ENABLE_DOWNLOAD_PROMPT=0

log_info "Repository root: $REPO_ROOT"
# The operator runs the deploy from more than one checkout (the main clone
# and the review worktree), and the copies drift: a deploy that runs an
# older script fails in a way that has nothing to do with the server.
# The branch and the commit are printed so the run says which copy ran.
DEPLOY_BRANCH="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
DEPLOY_COMMIT="$(git -C "$REPO_ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"
log_info "Deploying: $DEPLOY_BRANCH ($DEPLOY_COMMIT)"
[[ "$DRY_RUN" == "true" ]] && log_warn "DRY-RUN MODE — no changes will be made"
[[ "$VERBOSE" == "true" ]] && log_info "Verbose output enabled"
[[ "$SKIP_CHECKS" == "true" ]] && log_warn "Checks skipped (--skip-checks)"

# Load .env (secrets) — scripts/deploy/
if [[ ! -f "$ENV_FILE" ]]; then
  log_err "$ENV_FILE not found."
  log_err "Copy scripts/deploy/.env.example → scripts/deploy/.env and fill in your server values."
  exit 1
fi
# shellcheck disable=SC1090
set -a; source "$ENV_FILE"; set +a
log_info "Loaded .env"

# Load deploy.conf (project config) — scripts/deploy/
if [[ -f "$REPO_ROOT/scripts/deploy.conf" ]]; then
  CONF_FILE="$REPO_ROOT/scripts/deploy.conf"
  log_info "Config: $CONF_FILE (override)"
fi
if [[ ! -f "$CONF_FILE" ]]; then
  log_err "Config file not found: $CONF_FILE"
  log_err "Copy scripts/deploy/deploy.conf.example → scripts/deploy/deploy.conf"
  exit 1
fi
# shellcheck disable=SC1090
source "$CONF_FILE"
log_info "Loaded config: $CONF_FILE (PROJECT_TYPE=${PROJECT_TYPE:-unset})"

# ============================================================
# Validate PROJECT_TYPE
# ============================================================
case "${PROJECT_TYPE:-}" in
  fullstack|single|docs) ;;
  "")
    log_err "PROJECT_TYPE not set in $CONF_FILE (must be: fullstack|single|docs)"
    exit 1
    ;;
  *)
    log_err "Invalid PROJECT_TYPE: $PROJECT_TYPE (must be: fullstack|single|docs)"
    exit 1
    ;;
esac

# ============================================================
# Validate Required Env Vars (by type)
# ============================================================
REQUIRED_ENV_COMMON=("SSH_HOST" "SSH_PORT" "SSH_USER")
for var in "${REQUIRED_ENV_COMMON[@]}"; do
  if [[ -z "${!var:-}" ]]; then
    log_err "Missing required env var: $var (in $ENV_FILE)"
    exit 1
  fi
done

case "$PROJECT_TYPE" in
  fullstack)
    for var in DEPLOY_PATH_BACKEND DEPLOY_PATH_FRONTEND; do
      if [[ -z "${!var:-}" ]]; then
        log_err "Missing required env var for fullstack: $var (in $ENV_FILE)"
        exit 1
      fi
    done
    ;;
  single|docs)
    if [[ -z "${DEPLOY_PATH:-}" ]]; then
      log_err "Missing required env var: DEPLOY_PATH (in $ENV_FILE)"
      exit 1
    fi
    ;;
esac

# SSH auth optional
if [[ -z "${SSH_PRIVATE_KEY:-}" && -z "${SSH_PRIVATE_KEY_PATH:-}" ]]; then
  log_warn "No SSH_PRIVATE_KEY or SSH_PRIVATE_KEY_PATH set — will use ssh-agent"
fi

# ============================================================
# Validate Required Config Vars (by type)
# ============================================================
validate_conf() {
  local missing=()
  for var in "$@"; do
    if [[ -z "${!var:-}" ]]; then
      missing+=("$var")
    fi
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    log_err "Missing required config vars in $CONF_FILE: ${missing[*]}"
    exit 1
  fi
}

case "$PROJECT_TYPE" in
  fullstack)
    validate_conf BACKEND_DIR FRONTEND_DIR BACKEND_ARTIFACT_EXCLUDES \
      BACKEND_REMOTE_CMD FRONTEND_BUILD_CMD FRONTEND_ARTIFACT_SOURCE
    ;;
  single)
    validate_conf BUILD_CMD ARTIFACT_EXCLUDES REMOTE_CMD
    ;;
docs)
      validate_conf BUILD_CMD ARTIFACT_SOURCE ARTIFACT_NAME TEST_CMD
      ;;
esac

# Check that a private key file is a real private key. ssh-keygen parses it
# without printing anything; a file that is not a key is rejected before it is
# installed or used as the ssh identity.
# The private key can arrive in two shapes: as the content of the
# variable, or as the path to a file (a GitLab file variable holds the
# content in a file and puts the path in the variable).
write_private_key() {
    local destination="$1" source_value="$2"
    if [ -f "$source_value" ]; then
        cp "$source_value" "$destination"
    else
        printf '%s\n' "$source_value" > "$destination"
    fi
    chmod 600 "$destination"
}

validate_private_key() {
    local key_file="$1"
    if ! command -v ssh-keygen >/dev/null 2>&1; then
        # Without ssh-keygen the key format cannot be checked: the key
        # would only fail later at connection time with an opaque error.
        # The operator can accept that risk explicitly.
        if [[ "${ALLOW_UNVALIDATED_SSH_KEY:-}" == "1" ]]; then
            log_warn "ssh-keygen not found — the private key format is NOT validated (ALLOW_UNVALIDATED_SSH_KEY=1)"
            return 0
        fi
        log_error "ssh-keygen not found — the private key cannot be validated"
        log_error "Install the OpenSSH client, or set ALLOW_UNVALIDATED_SSH_KEY=1 to deploy without validating the key"
        return 1
    fi
    if ssh-keygen -y -f "$key_file" >/dev/null 2>&1; then
        log_debug "Private key format OK: $key_file"
        return 0
    fi
    log_error "Invalid private key: $key_file (ssh-keygen could not read it)"
    return 1
}

# ============================================================
# SSH Setup — mirrors .gitlab-ci.yml .ssh_setup exactly
# ============================================================
setup_ssh() {
  local ssh_dir="$HOME/.ssh"

  # One definition for every branch below: the connection timeout and the
  # keepalives keep a shared-hosting connection from hanging forever.
  SSH_OPTS=(-p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=30 -o ServerAliveCountMax=3)
  SCP_OPTS=(-P "$SSH_PORT" -o ConnectTimeout=15 -o ServerAliveInterval=30 -o ServerAliveCountMax=3)

  if [[ "$DRY_RUN" == "true" ]]; then
    log_debug "SKIP setup_ssh (dry-run) — no ~/.ssh writes"
    return 0
  fi

  mkdir -p "$ssh_dir"
  chmod 700 "$ssh_dir"

  if [[ -n "${SSH_PRIVATE_KEY:-}" ]]; then
    if [[ -f "$ssh_dir/id_rsa" ]]; then
      log_warn "SSH auth: SSH_PRIVATE_KEY set but $ssh_dir/id_rsa already exists — keeping existing key"
      validate_private_key "$ssh_dir/id_rsa" || exit 2
      USE_KEY_FILE=true
      KEY_SOURCE="existing-file"
    else
      # Validate the key before it becomes the ssh identity: a malformed key
      # would fail later with an opaque ssh error, and a wrong file must never
      # be installed as ~/.ssh/id_rsa.
      local key_tmp
      key_tmp="$(mktemp)"
      write_private_key "$key_tmp" "$SSH_PRIVATE_KEY"
      validate_private_key "$key_tmp" || { rm -f "$key_tmp"; exit 2; }
      mv "$key_tmp" "$ssh_dir/id_rsa"
      chmod 600 "$ssh_dir/id_rsa"
      log_info "SSH auth: SSH_PRIVATE_KEY (GitLab CI style)"
      USE_KEY_FILE=true
      KEY_SOURCE="SSH_PRIVATE_KEY"
    fi
  elif [[ -n "${SSH_PRIVATE_KEY_PATH:-}" && -f "${SSH_PRIVATE_KEY_PATH:-}" ]]; then
    validate_private_key "$SSH_PRIVATE_KEY_PATH" || exit 2
    log_info "SSH auth: SSH_PRIVATE_KEY_PATH ($SSH_PRIVATE_KEY_PATH)"
    USE_KEY_FILE=true
    KEY_SOURCE="SSH_PRIVATE_KEY_PATH"
  else
    log_info "SSH auth: ssh-agent (no explicit key)"
    USE_KEY_FILE=false
    KEY_SOURCE="ssh-agent"
  fi

  if [[ -n "${SSH_KNOWN_HOSTS:-}" ]]; then
    if grep -qF -- "$SSH_KNOWN_HOSTS" "$ssh_dir/known_hosts" 2>/dev/null; then
      log_debug "SSH known_hosts: already present, skip append"
    else
      echo "$SSH_KNOWN_HOSTS" >> "$ssh_dir/known_hosts"
      log_info "SSH known_hosts: loaded from SSH_KNOWN_HOSTS"
    fi
  fi

  if [[ ! -f "$ssh_dir/config" ]]; then
    cat > "$ssh_dir/config" <<'SSH_CONFIG'
Host *
  HostKeyAlgorithms +ssh-rsa
  PubkeyAcceptedAlgorithms +ssh-rsa
  StrictHostKeyChecking accept-new
SSH_CONFIG
    chmod 600 "$ssh_dir/config"
    log_info "SSH config: created $ssh_dir/config"
  else
    log_warn "SSH config: $ssh_dir/config exists — NOT overwriting (add HostKeyAlgorithms/StrictHostKeyChecking lines manually if needed)"
  fi

  if [[ "$KEY_SOURCE" == "existing-file" ]]; then
    # Key file already present: do NOT start a fresh agent — a new agent is
    # empty and would overwrite SSH_AUTH_SOCK, orphaning any running agent
    # and losing its loaded keys. ssh falls back to the default identity
    # file ~/.ssh/id_rsa automatically.
    unset SSH_AUTH_SOCK SSH_AGENT_PID 2>/dev/null || true
  elif [[ "$USE_KEY_FILE" == "true" ]]; then
    eval "$(ssh-agent -s)" >/dev/null
    if [[ "$KEY_SOURCE" == "SSH_PRIVATE_KEY" ]]; then
      ssh-add "$ssh_dir/id_rsa" 2>/dev/null
    elif [[ "$KEY_SOURCE" == "SSH_PRIVATE_KEY_PATH" ]]; then
      ssh-add "$SSH_PRIVATE_KEY_PATH" 2>/dev/null
    fi
    export SSH_AUTH_SOCK SSH_AGENT_PID
  else
    export SSH_AUTH_SOCK SSH_AGENT_PID
  fi

  log_debug "SSH_OPTS: ${SSH_OPTS[*]}"
  log_debug "SCP_OPTS: ${SCP_OPTS[*]}"
}

setup_ssh

# ============================================================
# Optional tag checkout
# ============================================================
cd "$REPO_ROOT"
ORIG_BRANCH=""
restore_original_branch() {
  if [[ -n "$ORIG_BRANCH" && "$DRY_RUN" != "true" ]]; then
    if git checkout "$ORIG_BRANCH" 2>/dev/null; then
      log_info "Restored branch: $ORIG_BRANCH"
    else
      log_warn "Branch restore failed — repo left detached (was on $ORIG_BRANCH)"
    fi
  fi
}
trap restore_original_branch EXIT

if [[ -n "$TARGET_REF" ]]; then
  log_phase "CHECKOUT: $TARGET_REF"
  if [[ "$DRY_RUN" == "true" ]]; then
    log_debug "git fetch --tags"
    log_debug "git checkout $TARGET_REF"
  else
    ORIG_BRANCH="$(git symbolic-ref --short HEAD 2>/dev/null || git rev-parse --short HEAD 2>/dev/null || true)"
    git fetch --tags
    git checkout "$TARGET_REF"
    log_info "Checked out $TARGET_REF"
  fi
fi

# ============================================================
# Helpers
# ============================================================
# Build a tarball without eval. The exclude patterns come from the committed
# config, so they must never be evaluated as shell code: the patterns are read
# into an array and passed to tar as plain arguments.
create_tarball() {
    local desc="$1" tarball="$2" source="$3" excludes="$4"
    local -a patterns=() args=()
    local pattern
    # The exclude list is a space-separated list of glob patterns, and a
    # pattern cannot contain spaces (they would split it in two). The
    # deployment config documents that contract.
    read -r -a patterns <<< "$excludes"
    for pattern in "${patterns[@]}"; do
        args+=("--exclude=$pattern")
    done

    log_step "$desc"
    log_debug "Command: tar -czf $tarball ${args[*]} -C $source ."

    if [[ "$DRY_RUN" == "true" ]]; then
        log_debug "[dry-run] tar -czf $tarball ${args[*]} -C $source ."
        return 0
    fi

    if tar -czf "$tarball" "${args[@]}" -C "$source" .; then
        log_info "✓ $desc completed"
        return 0
    else
        log_err "✗ $desc failed"
        return 1
    fi
}

# The shared hosting drops connections from time to time. A command that
# failed because the connection broke is retried once; a command that
# failed for any other reason (a bad file, a permission error) is not,
# because retrying it only delays the inevitable failure.
retry_attempts() {
    local exit_code="$1"
    # ssh and scp use 255 for a connection failure.
    [ "$exit_code" -eq 255 ] || return 1
    [ "${RETRY_ATTEMPTS:-2}" -gt 1 ] || return 1
    return 0
}

run_cmd() {
  local desc="$1"
  shift
  local cmd="$*"

  log_step "$desc"
  log_debug "Command: $cmd"

  if [[ "$DRY_RUN" == "true" ]]; then
    log_debug "[dry-run] $cmd"
    return 0
  fi

  # The command string comes from the deploy config (operator
  # controlled), but it is executed in its own shell instead of with
  # eval, so the current shell can never be re-defined by it.
  local exit_code=0
  if ( bash -c "$cmd" ); then
    log_info "✓ $desc completed"
    return 0
  else
    exit_code=$?
  fi

  if retry_attempts "$exit_code"; then
    log_warn "✗ $desc failed to connect (exit $exit_code) — retrying in ${RETRY_DELAY_SECONDS:-5}s"
    sleep "${RETRY_DELAY_SECONDS:-5}"
    if ( bash -c "$cmd" ); then
      log_info "✓ $desc completed"
      return 0
    else
      exit_code=$?
    fi
  fi

  log_err "✗ $desc failed (exit $exit_code)"
  return $exit_code
}

run_remote() {
  local desc="$1"
  local remote_cmd="$2"

  log_step "$desc"
  log_debug "Remote command:\n$remote_cmd"

  if [[ "$DRY_RUN" == "true" ]]; then
    log_debug "[dry-run] ssh ${SSH_OPTS[*]} $SSH_USER@$SSH_HOST <<< \$REMOTE_CMD"
    return 0
  fi

  local exit_code=0
  if ssh "${SSH_OPTS[@]}" "$SSH_USER@$SSH_HOST" "$remote_cmd"; then
    log_info "✓ $desc completed"
    return 0
  else
    exit_code=$?
  fi

  if retry_attempts "$exit_code"; then
    log_warn "✗ $desc failed to connect (exit $exit_code) — retrying in ${RETRY_DELAY_SECONDS:-5}s"
    sleep "${RETRY_DELAY_SECONDS:-5}"
    if ssh "${SSH_OPTS[@]}" "$SSH_USER@$SSH_HOST" "$remote_cmd"; then
      log_info "✓ $desc completed"
      return 0
    else
      exit_code=$?
    fi
  fi

  log_err "✗ $desc failed (exit $exit_code)"
  return $exit_code
}

# The pnpm bootstrap lives in scripts/deploy/ensure-pnpm.sh, not in a shell
# function: run_cmd executes its commands in a child shell (`bash -c`) and a
# child shell does not inherit the functions of the parent, so a function
# called from a command string would fail with "command not found".

# Report the pnpm version drift between the host and the version the
# repository pins. The deploy does not fail on it: the host tooling is
# the operator's choice, and the lockfile is compatible.
check_pnpm_version() {
  local pinned current
  pinned="$(sed -n 's/.*"packageManager"[[:space:]]*:[[:space:]]*"pnpm@\([^"]*\)".*/\1/p' "$REPO_ROOT/package.json" 2>/dev/null | head -1)"
  current="$(pnpm --version 2>/dev/null || true)"

  if [[ -z "$pinned" || -z "$current" ]]; then
    return 0
  fi

  if [[ "$pinned" != "$current" ]]; then
    log_warn "pnpm $current is running, the repository pins pnpm@$pinned."
    log_warn "The modules tree is rebuilt with the running pnpm."
    # A different pnpm wants to purge the modules tree of the pinned
    # one, and that purge asks for a confirmation. Without a TTY the
    # question cannot be answered and the install aborts, so the
    # non-interactive mode answers it: the tree is rebuilt.
    export CI=true
  fi
}

# ============================================================
# Test Environment Validation
# ============================================================
validate_test_environment() {
  local project_root="${1:-$PWD}"
  local backend_dir="${2:-$project_root}"
  local missing=()
  local hints=()

  # Check 1: PHP sqlite driver (loaded)
  if ! php -m 2>/dev/null | grep -qi 'pdo_sqlite'; then
    missing+=("Extensión PHP pdo_sqlite NO CARGADA")
    
    # Check if package is installed but not enabled
    local sqlite_so=$(find /usr/lib/php/modules -name 'pdo_sqlite.so' 2>/dev/null | head -1)
    if [[ -n "$sqlite_so" ]]; then
      hints+=("  → El paquete está instalado ($sqlite_so) pero NO habilitado en php.ini")
      hints+=("  → Editá /etc/php/php.ini y descomentá:")
      hints+=("      extension=pdo_sqlite")
      hints+=("      extension=sqlite3")
      hints+=("  → Luego reiniciá PHP-FPM / servidor web o volvé a abrir la terminal")
    else
      hints+=("  → Instalá paquete del sistema:")
      hints+=("      sudo apt-get install php-sqlite3    # Debian/Ubuntu")
      hints+=("      sudo pacman -S php-sqlite             # Arch")
      hints+=("      sudo dnf install php-sqlite3          # Fedora/RHEL")
      hints+=("  → En Arch/Fedora: tras instalar, habilitá en /etc/php/php.ini:")
      hints+=("      extension=pdo_sqlite")
      hints+=("      extension=sqlite3")
    fi
  fi

  # Print a clean code block for copy-paste (no log prefixes)
  print_code_block() {
    local title="$1"
    shift
    echo
    log_info "═══ $title ═══"
    for line in "$@"; do
      echo "$line"
    done
    log_info "══════════════════════════════════"
    echo
  }

  # Check 2: APP_KEY in .env.testing or phpunit.xml
  local has_app_key=false
  local env_testing_exists=false
  if [[ -f "$backend_dir/.env.testing" ]]; then
    env_testing_exists=true
    grep -q "^APP_KEY=" "$backend_dir/.env.testing" && has_app_key=true
  fi
  if [[ -f "$backend_dir/phpunit.xml" ]] || [[ -f "$backend_dir/phpunit.xml.dist" ]]; then
    local phpunit_file="$backend_dir/phpunit.xml"
    [[ -f "$backend_dir/phpunit.xml.dist" ]] && phpunit_file="$backend_dir/phpunit.xml.dist"
    grep -q 'name="APP_KEY"' "$phpunit_file" && has_app_key=true
  fi
  if [[ "$has_app_key" == "false" ]]; then
    if [[ "$env_testing_exists" == "false" ]]; then
      missing+=("Archivo .env.testing NO EXISTE")
      # printf is universal (bash, zsh, fish, sh, dash, ksh)
      hints+=("codeblock|Crealo PRIMERO (key:generate NO crea el archivo)|printf 'APP_NAME=Laravel\\nAPP_ENV=testing\\nAPP_KEY=\\nAPP_DEBUG=false\\nAPP_URL=http://localhost\\n\\nDB_CONNECTION=sqlite\\nDB_DATABASE=:memory:\\n\\nCACHE_DRIVER=array\\nSESSION_DRIVER=array\\nQUEUE_DRIVER=sync\\nMAIL_MAILER=array\\n' > $backend_dir/.env.testing")
      hints+=("  → LUEGO generá la key:")
      hints+=("      cd $backend_dir && php artisan key:generate --env=testing")
    else
      missing+=(".env.testing existe pero SIN APP_KEY")
      hints+=("  → Generá: cd $backend_dir && php artisan key:generate --env=testing")
    fi
    # Also check phpunit.xml
    local phpunit_has_key=false
    if [[ -f "$backend_dir/phpunit.xml" ]] || [[ -f "$backend_dir/phpunit.xml.dist" ]]; then
      local phpunit_file="$backend_dir/phpunit.xml"
      [[ -f "$backend_dir/phpunit.xml.dist" ]] && phpunit_file="$backend_dir/phpunit.xml.dist"
      grep -q 'name="APP_KEY"' "$phpunit_file" && phpunit_has_key=true
    fi
    if [[ "$phpunit_has_key" == "false" ]]; then
      hints+=("  → O agregá a phpunit.xml: <env name=\"APP_KEY\" value=\"base64:...\"/>")
    fi
  fi

  if [[ ${#missing[@]} -gt 0 ]]; then
    log_warn "Entorno de test incompleto — se omitirán tests de backend"
    for m in "${missing[@]}"; do
      log_warn "  ✗ $m"
    done
    for h in "${hints[@]}"; do
      if [[ "$h" == codeblock* ]]; then
        # Parse codeblock: codeblock|title|line1|line2|...
        IFS='|' read -ra parts <<< "$h"
        print_code_block "${parts[1]}" "${parts[@]:2}"
      else
        log_info "$h"
      fi
    done
    return 1
  fi

  return 0
}

# ============================================================
run_checks() {
  if [[ "$SKIP_CHECKS" == "true" ]]; then
    log_phase "PRE-DEPLOY CHECKS SKIPPED (--skip-checks)"
    return 0
  fi

  log_phase "PRE-DEPLOY CHECKS"

  case "$PROJECT_TYPE" in
    fullstack)
      if [[ "$DEPLOY_BACKEND" == "true" ]]; then
        log_phase "BACKEND CHECKS"
        if [[ ! -f "$BACKEND_DIR/vendor/autoload.php" ]]; then
          run_cmd "Backend: install dev dependencies" \
            "cd $BACKEND_DIR && composer install --no-interaction"
        fi
        # NOTE: `vendor/bin/pint --test` is intentionally NOT a deploy gate:
        # the codebase is not pint-clean yet and the CI lint stage only runs
        # `composer validate`. Run pint yourself before committing if you want
        # the style checks green.
        run_cmd "Backend: tests (PHPUnit, sqlite :memory:)" \
          "cd $BACKEND_DIR && php artisan test"
      fi

      if [[ "$DEPLOY_FRONTEND" == "true" ]]; then
        log_phase "FRONTEND CHECKS"
        check_pnpm_version
        run_cmd "Frontend: typecheck (vue-tsc)" \
          "cd $FRONTEND_DIR && \"$REPO_ROOT/scripts/deploy/ensure-pnpm.sh\" && pnpm exec vue-tsc --noEmit"
        run_cmd "Frontend: unit tests (vitest --run)" \
          "cd $FRONTEND_DIR && pnpm exec vitest --run"
      fi

      # The SDK is not published by this script, but a broken SDK must not
      # reach a release either, so its checks run with every deploy.
      log_phase "SDK CHECKS"
      run_cmd "SDK: typecheck (tsc --noEmit)" \
        "\"$REPO_ROOT/scripts/deploy/ensure-pnpm.sh\" && pnpm --filter @dowardev/glitchvy lint"
      run_cmd "SDK: unit tests (vitest --run)" \
        "pnpm --filter @dowardev/glitchvy test:run"
      ;;

    single)
      run_cmd "Backend: lint (pint)" \
        "composer install --prefer-dist --no-progress --no-scripts --no-interaction && vendor/bin/pint --test"
      run_cmd "Frontend: lint (oxlint + types)" \
        "\"$REPO_ROOT/scripts/deploy/ensure-pnpm.sh\" && pnpm install --frozen-lockfile && pnpm run lint:oxlint && pnpm run types:check"
      
      if validate_test_environment "$PWD" "$PWD"; then
        run_cmd "Backend: tests (PHPUnit, sqlite :memory:)" \
          "composer install --prefer-dist --no-progress --no-interaction && php artisan test"
      else
        if [[ "$DRY_RUN" == "true" ]]; then
          log_warn "Dry-run: backend test env incomplete — checks would fail in a real run"
        else
          log_err "BACKEND TESTS NO EJECUTADOS — entorno de test incompleto"
          log_err "Instalá lo que falta arriba ↑ y re-ejecutá, o usá --skip-checks para omitir a propósito"
          return 1
        fi
      fi
      ;;

    docs)
      run_cmd "Lint (all workspaces)" "pnpm -r lint"
      run_cmd "Unit tests" "$TEST_CMD"
      ;;
  esac
}

# ============================================================
# Deploy Functions (by type)
# ============================================================
deploy_fullstack() {
  # Backend
  if [[ "$DEPLOY_BACKEND" == "true" ]]; then
    log_phase "BACKEND DEPLOY"

    TMP_ROOT="/tmp/backend-deploy-$$"
    BUILD_DIR="$TMP_ROOT/back"
    TARBALL="/tmp/backend.tar.gz"

    run_cmd "Backend: prepare build directory" \
      "mkdir -p '$BUILD_DIR' && cp -r $BACKEND_DIR/* $BACKEND_DIR/.[^.]* '$BUILD_DIR/'"

    # The dependencies are installed on the server (BACKEND_REMOTE_CMD): the
    # vendor/ directory is not shipped, so the server resolves them against
    # its own PHP version and composer.lock.

    # The exclude patterns reach tar literally and are never evaluated as
    # shell code (see create_tarball): a tampered config cannot inject
    # commands here.
    create_tarball "Backend: create tarball" "$TARBALL" "$BUILD_DIR" "$BACKEND_ARTIFACT_EXCLUDES"

    run_cmd "Backend: cleanup build dir" \
      "rm -rf '$TMP_ROOT'"

    run_cmd "Backend: upload tarball to server" \
      "scp ${SCP_OPTS[*]} '$TARBALL' '$SSH_USER@$SSH_HOST:/tmp/backend.tar.gz'"

    REMOTE_CMD=$(cat <<EOF
set -e
mkdir -p "${DEPLOY_PATH_BACKEND}"
tar -xzf /tmp/backend.tar.gz -C "${DEPLOY_PATH_BACKEND}"
cd "${DEPLOY_PATH_BACKEND}"
$BACKEND_REMOTE_CMD
rm /tmp/backend.tar.gz
EOF
)
    run_remote "Backend: extract & deploy on server" "$REMOTE_CMD"

    if [[ "$DRY_RUN" != "true" ]]; then rm -f "$TARBALL"; fi
  fi

  # Frontend
  if [[ "$DEPLOY_FRONTEND" == "true" ]]; then
    log_phase "FRONTEND DEPLOY"

    check_pnpm_version
    run_cmd "Frontend: pnpm install --frozen-lockfile" \
      "\"$REPO_ROOT/scripts/deploy/ensure-pnpm.sh\" && pnpm install --frozen-lockfile"

    # The build runs through a helper: Vite loads .env, .env.local and
    # .env.development* in every mode, so a developer's local file would
    # be baked into the published bundle. The helper hides them and keeps
    # only the production inputs.
    run_cmd "Frontend: build (vue-tsc + vite build)" \
      "\"$REPO_ROOT/scripts/deploy/build-frontend.sh\" \"$REPO_ROOT\" \"$FRONTEND_DIR\""

    TARBALL="/tmp/frontend.tar.gz"

    if [[ ! -d "$FRONTEND_ARTIFACT_SOURCE" ]]; then
      log_err "$FRONTEND_ARTIFACT_SOURCE missing — build failed?"
      return 1
    fi

    create_tarball "Frontend: create tarball from dist/" "$TARBALL" "$FRONTEND_ARTIFACT_SOURCE" ""

    run_cmd "Frontend: upload tarball to server" \
      "scp ${SCP_OPTS[*]} '$TARBALL' '$SSH_USER@$SSH_HOST:/tmp/frontend.tar.gz'"

    REMOTE_CMD=$(cat <<EOF
set -e
mkdir -p "${DEPLOY_PATH_FRONTEND}"
tar -xzf /tmp/frontend.tar.gz -C "${DEPLOY_PATH_FRONTEND}"
rm /tmp/frontend.tar.gz
EOF
)
    run_remote "Frontend: extract on server" "$REMOTE_CMD"

    if [[ "$DRY_RUN" != "true" ]]; then rm -f "$TARBALL"; fi
  fi
}

deploy_single() {
  log_phase "BUILD"
  run_cmd "Frontend: SSR build" "$BUILD_CMD"

  log_phase "DEPLOY"

  TARBALL="/tmp/deploy.tar.gz"
  # Same as the backend build: no eval, the patterns are plain tar arguments.
  create_tarball "Create deployment tarball" "$TARBALL" "." "$ARTIFACT_EXCLUDES"

  run_cmd "Upload tarball to server" \
    "scp ${SCP_OPTS[*]} '$TARBALL' '$SSH_USER@$SSH_HOST:/tmp/deploy.tar.gz'"

  REMOTE_CMD_FULL=$(cat <<EOF
set -e
mkdir -p "${DEPLOY_PATH}"
tar -xzf /tmp/deploy.tar.gz -C "${DEPLOY_PATH}"
cd "${DEPLOY_PATH}"
$REMOTE_CMD
rm /tmp/deploy.tar.gz
EOF
)
  run_remote "Extract & deploy on server" "$REMOTE_CMD_FULL"

  if [[ "$DRY_RUN" != "true" ]]; then rm -f "$TARBALL"; fi
}

deploy_docs() {
  log_phase "INSTALL DEPENDENCIES"
  run_cmd "pnpm install --frozen-lockfile" "pnpm install --frozen-lockfile"

  log_phase "BUILD"
  run_cmd "Build all packages" "$BUILD_CMD"

  log_phase "PACKAGE ARTIFACT"
  run_cmd "Compress docs build" "cd '$ARTIFACT_SOURCE' && tar czf '../../$ARTIFACT_NAME' . && cd ../.."

  log_phase "UPLOAD"
  run_cmd "Upload tarball to server" "scp ${SCP_OPTS[*]} $ARTIFACT_NAME ${SSH_USER}@${SSH_HOST}:${DEPLOY_PATH}"

  log_phase "REMOTE EXTRACT"
  REMOTE_CMD="cd ${DEPLOY_PATH} && tar xzvf ${ARTIFACT_NAME} && rm ${ARTIFACT_NAME}"
  run_remote "Extract on server" "$REMOTE_CMD"

  log_phase "CLEANUP"
  [[ "$DRY_RUN" != "true" ]] && rm -f "$ARTIFACT_NAME"
  log_info "Local artifact cleaned up"
}

# ============================================================
# Migrations (opt-in) — never run unattended
# ============================================================
# The deploy never migrates the production database on its own: the operator
# asks for it explicitly (--migrate). The data safety lives in the
# migrations themselves: a migration that rebuilds a table copies the rows
# before dropping the old one (see the migration checks in the tooling
# suite), and the foreign keys carry their own delete semantics.
run_migrations() {
  if [[ "$RUN_MIGRATIONS" != "true" ]]; then
    log_debug "Migrations skipped (not requested with --migrate)"
    return 0
  fi

  log_phase "MIGRATIONS"

  local remote_cmd
  remote_cmd=$(cat <<EOF
set -e
cd "${DEPLOY_PATH_BACKEND}"
PENDING=\$(php artisan migrate --pretend --force --no-ansi 2>/dev/null) || {
  echo "Cannot read the pending migrations (migrate --pretend failed)." >&2
  exit 1
}
echo "Pending migrations:"
echo "\$PENDING"
${BACKEND_MIGRATE_CMD:-php artisan migrate --force}
EOF
)

  run_remote "Backend: run the pending migrations" "$remote_cmd"
}

# ============================================================
# Setup Script (optional) — uploads a first-run setup script
# ============================================================
# If SETUP_SCRIPT is set in deploy.conf (path relative to repo root), the
# file is uploaded to the app root on the server (parent of the backend path
# for fullstack, DEPLOY_PATH otherwise), made executable, and a reminder is
# printed. It is NEVER executed automatically — setup scripts prompt for
# secrets (DB creds, JWT keys, etc.). Applies to all project types.
SETUP_REMOTE_DIR=""
SETUP_DEPLOYED=false

deploy_setup() {
  if [[ -z "${SETUP_SCRIPT:-}" ]]; then
    log_debug "No SETUP_SCRIPT configured (skipped)"
    return 0
  fi

  local local_path="$REPO_ROOT/$SETUP_SCRIPT"
  if [[ ! -f "$local_path" ]]; then
    log_warn "SETUP_SCRIPT configured ($SETUP_SCRIPT) but file not found at $local_path — skipped"
    return 0
  fi

  case "$PROJECT_TYPE" in
    fullstack) SETUP_REMOTE_DIR="$(dirname "$DEPLOY_PATH_BACKEND")" ;;
    single|docs) SETUP_REMOTE_DIR="$DEPLOY_PATH" ;;
  esac

  if [[ -z "$SETUP_REMOTE_DIR" || "$SETUP_REMOTE_DIR" == "/" ]]; then
    log_warn "Cannot derive a safe setup remote dir — setup upload skipped"
    return 0
  fi

  local base
  base="$(basename "$SETUP_SCRIPT")"

  log_phase "SETUP SCRIPT"
  log_info "Uploading $SETUP_SCRIPT → $SETUP_REMOTE_DIR/$base (not executed)"

  run_remote "Setup: ensure remote app root" "mkdir -p '$SETUP_REMOTE_DIR'"
  run_cmd "Setup: upload $base" \
    "scp ${SCP_OPTS[*]} '$local_path' '$SSH_USER@$SSH_HOST:$SETUP_REMOTE_DIR/$base'"
  run_remote "Setup: chmod +x $base" "chmod +x '$SETUP_REMOTE_DIR/$base'"

  # The setup script creates the real .env files from the .env.*.example
  # templates. The deploy tarballs exclude every .env* file (secrets
  # policy), which also strips the non-secret example templates — so on a
  # fresh server setup.sh would abort with "example file not found" before
  # it could create .env. Upload the example templates into the deployed
  # directories so the setup script can find them on first run.
  local env_pairs=()
  case "$PROJECT_TYPE" in
    fullstack)
      # Only the backend templates are uploaded: the admin SPA is built on a
      # development machine (Vite bakes its VITE_* values into the bundle),
      # so the server never needs a frontend .env.
      env_pairs=(
        "$BACKEND_DIR/.env.example:$DEPLOY_PATH_BACKEND"
        "$BACKEND_DIR/.env.development.example:$DEPLOY_PATH_BACKEND"
        "$BACKEND_DIR/.env.production.example:$DEPLOY_PATH_BACKEND"
      )
      ;;
    single|docs)
      env_pairs=(
        ".env.example:$DEPLOY_PATH"
        ".env.development.example:$DEPLOY_PATH"
        ".env.production.example:$DEPLOY_PATH"
      )
      ;;
  esac

  local pair src_file remote_dir env_example_count=0
  for pair in "${env_pairs[@]:-}"; do
    src_file="${pair%%:*}"
    remote_dir="${pair#*:}"
    if [[ -f "$REPO_ROOT/$src_file" ]]; then
      run_remote "Setup: ensure env dir for $(basename "$remote_dir")" "mkdir -p '$remote_dir'"
      run_cmd "Setup: upload $(basename "$src_file") → $remote_dir/" \
        "scp ${SCP_OPTS[*]} '$REPO_ROOT/$src_file' '$SSH_USER@$SSH_HOST:$remote_dir/'"
      env_example_count=$((env_example_count + 1))
    fi
  done

  if [[ "$env_example_count" -gt 0 ]]; then
    log_info "Uploaded $env_example_count .env.*.example templates for setup.sh"
  else
    log_warn "No .env.*.example templates found to upload for setup.sh"
  fi

  SETUP_DEPLOYED=true
}

# ============================================================
# Version Check (optional, for all project types)
# ============================================================
# Supports up to two published packages per repo:
#   VERSION_CHECK_PKG/VERSION_CHECK_FILE  -> npm registry (package.json)
#   COMPOSER_CHECK_PKG/COMPOSER_CHECK_FILE -> Packagist (composer.json)
# Local composer version reads the "version" field of composer.json,
# falling back to the nearest git tag when absent. Never blocks deploy.
run_version_check() {
  if [[ -n "${VERSION_CHECK_PKG:-}" && -n "${VERSION_CHECK_FILE:-}" ]]; then
    run_version_check_npm
  fi

  if [[ -n "${COMPOSER_CHECK_PKG:-}" && -n "${COMPOSER_CHECK_FILE:-}" ]]; then
    run_version_check_composer
  fi
}

run_version_check_npm() {
  log_phase "VERSION CHECK (npm)"
  local pkg="$VERSION_CHECK_PKG"
  local file="$VERSION_CHECK_FILE"
  local local_v remote_v

  local_v="$(get_json_version "$file" || true)"
  remote_v="$(timeout 20 npm view "$pkg" version 2>/dev/null || true)"

  report_version_status "npm" "$pkg" "$file" "$local_v" "$remote_v"
}

run_version_check_composer() {
  log_phase "VERSION CHECK (composer)"
  local pkg="$COMPOSER_CHECK_PKG"
  local file="$COMPOSER_CHECK_FILE"
  local local_v remote_v

  local_v="$(get_json_version "$file" || true)"
  if [[ -z "$local_v" && -f "$file" ]]; then
    local_v="$(git -C "$(dirname "$file")" describe --tags --abbrev=0 2>/dev/null || true)"
  fi
  remote_v="$(get_packagist_version "$pkg" || true)"

  report_version_status "Packagist" "$pkg" "$file" "$local_v" "$remote_v"
}

get_json_version() {
  local pkg_file="$1"
  if [[ ! -f "$pkg_file" ]]; then return 1; fi
  python3 -c "import json; print(json.load(open('$pkg_file')).get('version', ''))" 2>/dev/null \
    || grep -oE '"version"\s*:\s*"[^"]+"' "$pkg_file" | head -1 | sed -E 's/.*"([^"]+)".*/\1/'
}

get_packagist_version() {
  local pkg="$1"
  local p2_url="https://repo.packagist.org/p2/${pkg}.json"
  timeout 10 curl -fsSL "$p2_url" 2>/dev/null \
    | python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    versions = [p.get('version', '') for p in data.get('packages', {}).get('$pkg', [])]
    if not versions:
        sys.exit(1)
    print(max(versions, key=lambda v: [int(x) for x in v.lstrip('v').split('.') if x.isdigit()] or [0]))
except Exception:
    sys.exit(1)
" 2>/dev/null || true
}

report_version_status() {
  local registry="$1"
  local pkg="$2"
  local file="$3"
  local local_v="$4"
  local remote_v="$5"
  local local_norm remote_norm local_higher

  local_norm="$(echo "$local_v" | sed -E 's/^v//')"
  remote_norm="$(echo "$remote_v" | sed -E 's/^v//')"

  if [[ -z "$local_v" ]]; then
    PKG_CHECK_RESULT="unknown"
    log_warn "Could not read local package version ($file missing?)"
  elif [[ -z "$remote_v" ]]; then
    PKG_CHECK_RESULT="unpublished"
    log_warn "Package '$pkg' has never been published to $registry (or network unreachable)"
  elif [[ "$local_norm" == "$remote_norm" ]]; then
    PKG_CHECK_RESULT="match"
    log_info "Package version in sync with $registry: $local_v"
  else
    local_higher="$(printf '%s\n%s\n' "$local_norm" "$remote_norm" | sort -V | tail -1)"
    if [[ "$local_higher" == "$local_norm" ]]; then
      PKG_CHECK_RESULT="local-newer"
      log_warn "Local package ($local_v) > $registry ($remote_v) — package not yet published"
    else
      PKG_CHECK_RESULT="local-older"
      log_warn "Local package ($local_v) < $registry ($remote_v) — unusual, check manifest"
    fi
  fi

  log_phase "VERSION STATUS"
  echo ""
  case "$PKG_CHECK_RESULT" in
    match) log_info "Package version in sync with $registry: $local_v" ;;
    local-newer)
      echo -e "${YELLOW}============================================================${NC}"
      echo -e "${YELLOW}  REMINDER: package NOT yet published to $registry${NC}"
      echo -e "${YELLOW}  Local:  $local_v${NC}"
      echo -e "${YELLOW}  $registry: $remote_v${NC}"
      echo -e "${YELLOW}============================================================${NC}"
      ;;
    local-older)
      echo -e "${YELLOW}============================================================${NC}"
      echo -e "${YELLOW}  WARNING: local package version is OLDER than $registry${NC}"
      echo -e "${YELLOW}  Local:  $local_v${NC}"
      echo -e "${YELLOW}  $registry: $remote_v${NC}"
      echo -e "${YELLOW}============================================================${NC}"
      ;;
    unpublished)
      echo -e "${RED}============================================================${NC}"
      echo -e "${RED}  WARNING: package has NEVER been published${NC}"
      echo -e "${RED}  Local:  $local_v${NC}"
      echo -e "${RED}  $registry: (not found)${NC}"
      echo -e "${RED}============================================================${NC}"
      ;;
    unknown)
      echo -e "${YELLOW}============================================================${NC}"
      echo -e "${YELLOW}  Could not verify package version against $registry.${NC}"
      echo -e "${YELLOW}============================================================${NC}"
      ;;
  esac
}

# ============================================================
# Health Check Hook (common)
# ============================================================
run_health_check() {
  HOOK_DIR="$REPO_ROOT/scripts/hooks"
  if [[ -f "$HOOK_DIR/health-check.sh" && -x "$HOOK_DIR/health-check.sh" ]]; then
    log_phase "HEALTH CHECK"
    if [[ "$DRY_RUN" == "true" ]]; then
      log_debug "[dry-run] $HOOK_DIR/health-check.sh"
    else
      if "$HOOK_DIR/health-check.sh"; then
        log_info "✓ Health check passed"
      else
        log_err "✗ Health check failed"
        exit 1
      fi
    fi
  else
    log_debug "No health-check.sh hook found (skipped)"
  fi
}

# ============================================================
# Main Flow
# ============================================================
log_phase "DEPLOY TARGETS"
case "$PROJECT_TYPE" in
  fullstack)
    log_info "Backend:  $DEPLOY_PATH_BACKEND"
    log_info "Frontend: $DEPLOY_PATH_FRONTEND"
    ;;
  single)
    log_info "Target:   $DEPLOY_PATH"
    ;;
  docs)
    log_info "Target:   $DEPLOY_PATH (artifact: $ARTIFACT_NAME)"
    ;;
esac
echo ""

run_checks || exit 1

case "$PROJECT_TYPE" in
  fullstack) deploy_fullstack ;;
  single)    deploy_single ;;
  docs)      deploy_docs ;;
esac

if [[ "$PROJECT_TYPE" == "fullstack" ]]; then
  run_migrations
fi

deploy_setup

if [[ "$DRY_RUN" == "true" ]]; then
  log_debug "SKIP version check (dry-run)"
else
  run_version_check
fi
run_health_check

# ============================================================
# Done
# ============================================================
log_phase "DEPLOY COMPLETE"
case "$PROJECT_TYPE" in
  fullstack)
    log_info "Backend:  $DEPLOY_BACKEND"
    log_info "Frontend: $DEPLOY_FRONTEND"
    ;;
  single)
    log_info "Mode: single (Laravel SSR)"
    ;;
  docs)
    log_info "Mode: docs only"
    ;;
esac
[[ "$DRY_RUN" == "true" ]] && log_warn "DRY-RUN — no actual changes made"

if [[ "$SETUP_DEPLOYED" == "true" ]]; then
  log_phase "SETUP REMINDER"
  echo -e "${YELLOW}============================================================${NC}"
  echo -e "${YELLOW}  Configure the environment on the server (setup.sh is NOT run automatically):${NC}"
  echo -e "${YELLOW}  ssh -p $SSH_PORT $SSH_USER@$SSH_HOST \"cd $SETUP_REMOTE_DIR && ./$(basename "$SETUP_SCRIPT") --non-interactive\"${NC}"
  echo -e "${YELLOW}  (omit --non-interactive for an interactive run)${NC}"
  echo -e "${YELLOW}============================================================${NC}"
fi