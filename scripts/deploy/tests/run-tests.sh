#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 DowarDev
# SPDX-License-Identifier: AGPL-3.0-only
#
# Glitchvy — tests for the deployment tooling.
#
# They cover the parts of the deploy tooling that can break silently:
# the rollback tag selection, the env file writer, the destructive
# migration guard and the tarball exclusions.
#
# Usage: scripts/deploy/tests/run-tests.sh
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PREVIOUS_TAG_SCRIPT="$REPO_ROOT/scripts/ci/previous-tag.sh"
SETUP_SCRIPT="$REPO_ROOT/setup.sh"
DEPLOY_SCRIPT="$REPO_ROOT/scripts/deploy/deploy.sh"

PASSED=0
FAILED=0
SKIPPED=0
WORK_DIR=""

cleanup() {
    [ -n "$WORK_DIR" ] && rm -rf "$WORK_DIR"
}
trap cleanup EXIT

pass() {
    PASSED=$((PASSED + 1))
    echo "  [OK]   $1"
}

fail() {
    FAILED=$((FAILED + 1))
    echo "  [FAIL] $1"
    [ -n "${2:-}" ] && echo "         $2"
}

section() {
    echo ""
    echo "== $1"
}

skip() {
    SKIPPED=$((SKIPPED + 1))
    echo "  [SKIP] $1"
}

# Extract one function from a shell script and load it in isolation, with
# the logging helpers stubbed out.
load_function() {
    local script="$1" name="$2" target="$3"
    # The braces are counted outside strings and comments, so a function
    # with braces inside them is still extracted whole.
    awk -v name="$name" '
        function scan(line,   i, c, quote, out) {
            out = 0; quote = ""; i = 1
            while (i <= length(line)) {
                c = substr(line, i, 1)
                if (quote != "") {
                    if (c == quote) quote = ""
                } else if (c == "\"" || c == "'"'"'") {
                    quote = c
                } else if (c == "#") {
                    break
                } else if (c == "{") {
                    out++
                } else if (c == "}") {
                    out--
                }
                i++
            }
            return out
        }
        !inside && $0 ~ "^" name "\\(\\)[[:space:]]*\\{" { inside = 1; depth = 0 }
        inside {
            print
            depth += scan($0)
            if (depth <= 0) { inside = 0; exit }
        }
    ' "$script" > "$target"
    # The named exit codes of the script travel with the function.
    grep -E "^readonly EXIT_[A-Z_]+=" "$script" >> "$target" || true
}

# ============================================================
section "scripts/ci/previous-tag.sh — rollback tag selection"
# ============================================================
new_repo_with_tags() {
    local dir="$1"; shift
    mkdir -p "$dir"
    git -C "$dir" init -q .
    git -C "$dir" config user.email tests@glitchvy.test
    git -C "$dir" config user.name tests
    git -C "$dir" commit -q --allow-empty -m init
    for tag in "$@"; do git -C "$dir" tag "$tag"; done
}

repo="$(mktemp -d)"
new_repo_with_tags "$repo" v1.0.0 v1.1.0 v1.2.0 v1.2.1 v1.3.0-rc.1 v1.10.0 v2.0.0

selected="$(git -C "$repo" tag --list >/dev/null; cd "$repo" && bash "$PREVIOUS_TAG_SCRIPT" v2.0.0)"
if [ "$selected" = "v1.10.0" ]; then
    pass "selects the newest stable release older than the deployed tag"
else
    fail "selects the newest stable release older than the deployed tag" "got '$selected', expected 'v1.10.0'"
fi

selected="$(cd "$repo" && bash "$PREVIOUS_TAG_SCRIPT" v1.10.0)"
if [ "$selected" = "v1.2.1" ]; then
    pass "compares versions numerically (v1.10.0 is newer than v1.9.0)"
else
    fail "compares versions numerically" "got '$selected', expected 'v1.2.1'"
fi

selected="$(cd "$repo" && bash "$PREVIOUS_TAG_SCRIPT" v1.3.0-rc.1)"
if [ "$selected" = "v1.2.1" ]; then
    pass "ignores prereleases when choosing the rollback target"
else
    fail "ignores prereleases when choosing the rollback target" "got '$selected', expected 'v1.2.1'"
fi

if (cd "$repo" && bash "$PREVIOUS_TAG_SCRIPT" v1.0.0 >/dev/null 2>&1); then
    fail "fails when there is no older release" "it exited with 0"
else
    pass "fails when there is no older release"
fi

output="$(cd "$repo" && bash "$PREVIOUS_TAG_SCRIPT" not-a-tag 2>&1)"
if [ $? -ne 0 ] && printf '%s' "$output" | grep -qi "not a semantic version tag"; then
    pass "rejects a tag that is not a semantic version"
else
    fail "rejects a tag that is not a semantic version" "got: $output"
fi

new_repo_with_tags "$repo" v1.1000.0 v10.0.0
selected="$(cd "$repo" && bash "$PREVIOUS_TAG_SCRIPT" v1.1000.0)"
if [ "$selected" = "v1.10.0" ]; then
    pass "compares version components without an upper bound"
else
    fail "compares version components without an upper bound" "got '$selected', expected 'v1.10.0'"
fi

# ============================================================
section "setup.sh — the env file writer"
# ============================================================
env_dir="$(mktemp -d)"

load_function "$SETUP_SCRIPT" "sed_set" "$env_dir/sed_set.sh"
cat >> "$env_dir/sed_set.sh" <<'EOF'
log_error() { echo "ERR: $1"; }
log_info()  { echo "INFO: $1"; }
EOF

# Every test gets its own file, so one test cannot change what another
# one sees.
env_file="$env_dir/valid.env"
printf 'EXISTING=value\n' > "$env_file"

# shellcheck source=/dev/null
( source "$env_dir/sed_set.sh"
  sed_set "$env_file" "SSO_URL" "https://sso.example.com" )
if grep -q '^SSO_URL=https://sso.example.com$' "$env_file"; then
    pass "writes a valid value into the env file"
else
    fail "writes a valid value into the env file" "content: $(cat "$env_file")"
fi

# A key that the template ships commented out must be activated where it
# is. Appending a second copy leaves the file with two sources of truth
# for the same variable, and the first one wins.
commented_file="$env_dir/commented.env"
printf 'APP_NAME=Glitchvy\n# DB_HOST=127.0.0.1\n# DB_USERNAME=root\n' > "$commented_file"
( source "$env_dir/sed_set.sh"
  sed_set "$commented_file" "DB_HOST" "db.internal" )
if grep -qx 'DB_HOST=db.internal' "$commented_file" \
    && [ "$(grep -c '^DB_HOST=' "$commented_file")" -eq 1 ] \
    && ! grep -q '^# *DB_HOST=' "$commented_file"; then
    pass "activates a commented key in place, without a second copy"
else
    fail "activates a commented key in place, without a second copy" "content: $(cat "$commented_file")"
fi

# Writing a key again must not add a second line either.
( source "$env_dir/sed_set.sh"
  sed_set "$commented_file" "DB_HOST" "db.other" )
if [ "$(grep -c '^DB_HOST=' "$commented_file")" -eq 1 ]; then
    pass "keeps a single line per key when the key is written again"
else
    fail "keeps a single line per key when the key is written again" "content: $(cat "$commented_file")"
fi

# A newline cannot be represented in an env file: the writer has to refuse
# it, report it with its own exit code and leave the file untouched.
newline_file="$env_dir/newline.env"
printf 'EXISTING=value\n' > "$newline_file"
( source "$env_dir/sed_set.sh"
  sed_set "$newline_file" "SSO_CLIENT_ID" "$(printf 'client\nINJECTED=1')" ) >/dev/null 2>&1
status=$?
if [ "$status" -eq 7 ]; then
    pass "refuses a value with a newline and returns the code 7"
else
    fail "refuses a value with a newline and returns the code 7" "exit code was $status"
fi

if grep -q 'INJECTED=1' "$newline_file"; then
    fail "leaves the env file untouched when a value is refused" "the file was modified"
else
    pass "leaves the env file untouched when a value is refused"
fi

# The caller turns that code into the documented exit code of the script.
( source "$env_dir/sed_set.sh"
  sed_set "$newline_file" "SSO_CLIENT_ID" "$(printf 'client\nINJECTED=1')" || exit $? ) >/dev/null 2>&1
status=$?
if [ "$status" -eq 7 ]; then
    pass "the caller aborts with the code the writer returned"
else
    fail "the caller aborts with the code the writer returned" "exit code was $status"
fi

# A file that cannot be written is a different failure: the write
# permission error (6), not the invalid value (7).
locked_dir="$(mktemp -d)"
locked_file="$locked_dir/locked.env"
printf 'EXISTING=value\n' > "$locked_file"
chmod 400 "$locked_dir"
( source "$env_dir/sed_set.sh"
  sed_set "$locked_file" "APP_NAME" "Glitchvy" ) >/dev/null 2>&1
status=$?
chmod 700 "$locked_dir"
if [ "$(id -u)" = "0" ]; then
    # root ignora los permisos del directorio: el error de escritura no se
    # puede reproducir. No es un fallo del codigo, es del entorno.
    skip "reports a write permission error with the code 6" "running as root: file permissions are not enforced"
elif [ "$status" -eq 6 ]; then
    pass "reports a write permission error with the code 6"
else
    fail "reports a write permission error with the code 6" "exit code was $status"
fi

# ============================================================
section "scripts/deploy/deploy.sh — the private key can be content or a path"
# ============================================================
load_function "$DEPLOY_SCRIPT" "write_private_key" "$env_dir/write_private_key.sh"
cat >> "$env_dir/write_private_key.sh" <<'EOF'
EOF

key_content='-----BEGIN PRIVATE KEY-----
not-a-real-key
-----END PRIVATE KEY-----'

# The variable holds the content.
content_target="$env_dir/from_content"
( source "$env_dir/write_private_key.sh"
  write_private_key "$content_target" "$key_content" )
if grep -q "BEGIN PRIVATE KEY" "$content_target"; then
    pass "installs the key when the variable holds the content"
else
    fail "installs the key when the variable holds the content" "the file does not have the key"
fi

# The variable holds the path to a file with the key (a GitLab file
# variable).
key_source_file="$env_dir/key_source"
printf '%s\n' "$key_content" > "$key_source_file"
path_target="$env_dir/from_path"
( source "$env_dir/write_private_key.sh"
  write_private_key "$path_target" "$key_source_file" )
if grep -q "BEGIN PRIVATE KEY" "$path_target" && [ "$(cat "$path_target")" = "$(cat "$key_source_file")" ]; then
    pass "installs the key when the variable holds the path to a file"
else
    fail "installs the key when the variable holds the path to a file" "the file does not have the key"
fi

perms=$(stat -c '%a' "$path_target" 2>/dev/null || stat -f '%Lp' "$path_target")
if [ "$perms" = "600" ]; then
    pass "the installed key is only readable by its owner"
else
    fail "the installed key is only readable by its owner" "the permissions are $perms"
fi

# ============================================================
section "setup.sh — a failure undoes the partial env file"
# ============================================================
# A half written env file is worse than none: the next run reads it as
# the previous values, so the partial write has to be undone.
load_function "$SETUP_SCRIPT" "remember_env_file" "$env_dir/rollback_remember.sh"
load_function "$SETUP_SCRIPT" "restore_env_files" "$env_dir/rollback_restore.sh"
# Each extracted file carries the readonly exit codes: keep them only once.
grep -v "^readonly EXIT_" "$env_dir/rollback_restore.sh" > "$env_dir/rollback_restore_clean.sh"
cat "$env_dir/rollback_remember.sh" "$env_dir/rollback_restore_clean.sh" > "$env_dir/rollback.sh"
cat >> "$env_dir/rollback.sh" <<'EOF'
log_error() { echo "ERR: $1"; }
ROLLBACK_FILES=()
ROLLBACK_BACKUPS=()
EOF

rollback_dir="$(mktemp -d)"
printf 'APP_KEY=old\n' > "$rollback_dir/.env"
cp "$rollback_dir/.env" "$rollback_dir/.env.bak.20260101_000000"

# shellcheck source=/dev/null
out="$( ( source "$env_dir/rollback.sh"
  remember_env_file "$rollback_dir/.env" "$rollback_dir/.env.bak.20260101_000000"
  printf 'APP_KEY=half-written\n' > "$rollback_dir/.env"
  restore_env_files ) 2>&1 )"
restored_content="$(cat "$rollback_dir/.env" 2>/dev/null)"
if [ "$restored_content" = "APP_KEY=old" ]; then
    pass "a failure restores the env file from its backup"
else
    fail "a failure restores the env file from its backup" "content was: $restored_content / $out"
fi

# A file created by this run has no previous state: it has to go away.
printf 'APP_KEY=fresh\n' > "$rollback_dir/.env.new"
# shellcheck source=/dev/null
( source "$env_dir/rollback.sh"
  remember_env_file "$rollback_dir/.env.new" ""
  restore_env_files ) >/dev/null 2>&1
if [ ! -f "$rollback_dir/.env.new" ]; then
    pass "a failure removes an env file that the run created"
else
    fail "a failure removes an env file that the run created" "the file is still there"
fi

rm -rf "$rollback_dir"

# ============================================================
section "the deploy leaves the server env file authoritative"
# ============================================================
# A cached config freezes the values of the env file, so a manual edit of
# the .env would be silently ignored. The deploy must not create it.
config_cache_hits="$(grep -rn "artisan config:cache" \
    "$DEPLOY_SCRIPT" "$SETUP_SCRIPT" "$REPO_ROOT/scripts/deploy/deploy.conf" \
    "$REPO_ROOT/.github/workflows" 2>/dev/null | grep -v "on purpose" || true)"
if [ -z "$config_cache_hits" ]; then
    pass "the deploy never caches the config, so a manual env file edit takes effect"
else
    fail "the deploy never caches the config, so a manual env file edit takes effect" "$config_cache_hits"
fi

# ============================================================
section "the migrations — the data safety lives in the schema"
# ============================================================
# The runtime guard (ALLOW_DESTRUCTIVE_MIGRATIONS) is gone: it read the
# SQL text and could not tell a table rebuild that copies the rows from
# one that drops them. The guarantee is now a rule on the migrations:
# a migration that drops a table copies the rows first.
migrations_dir="$REPO_ROOT/back/database/migrations"

unsafe_migrations=""
while IFS= read -r migration_file; do
    [ -n "$migration_file" ] || continue
    if ! grep -qiE "drop[[:space:]]+table" "$migration_file"; then
        continue
    fi
    # The copy has to come before the drop.
    copy_line="$(grep -niE "insert[[:space:]]+into" "$migration_file" | head -1 | cut -d: -f1)"
    drop_line="$(grep -niE "drop[[:space:]]+table" "$migration_file" | head -1 | cut -d: -f1)"
    if [ -z "$copy_line" ] || [ -z "$drop_line" ] || [ "$copy_line" -gt "$drop_line" ]; then
        unsafe_migrations="$unsafe_migrations $(basename "$migration_file")"
    fi
done < <(find "$migrations_dir" -name '*.php' 2>/dev/null)

if [ -z "$unsafe_migrations" ]; then
    pass "every migration that drops a table copies the rows before dropping it"
else
    fail "every migration that drops a table copies the rows before dropping it" "without a row copy:$unsafe_migrations"
fi

# The external switch must not come back.
flag_hits="$(grep -rl "ALLOW_DESTRUCTIVE_MIGRATIONS" \
    "$SETUP_SCRIPT" "$DEPLOY_SCRIPT" "$REPO_ROOT/.github/workflows" 2>/dev/null | tr '\n' ' ')"
if [ -z "$flag_hits" ]; then
    pass "no script or workflow uses the external destructive migration switch"
else
    fail "no script or workflow uses the external destructive migration switch" "$flag_hits"
fi

# ============================================================
section "scripts/deploy/deploy.sh — the tarball exclusions"
# ============================================================
load_function "$DEPLOY_SCRIPT" "create_tarball" "$env_dir/create_tarball.sh"
cat >> "$env_dir/create_tarball.sh" <<'EOF'
log_step() { echo "STEP: $1"; }
log_info() { echo "INFO: $1"; }
log_warn() { echo "WARN: $1"; }
log_err()  { echo "ERR: $1"; }
log_debug() { :; }
DRY_RUN=false
EOF

artifact_dir="$(mktemp -d)"
mkdir -p "$artifact_dir/vendor" "$artifact_dir/node_modules" "$artifact_dir/storage/logs"
printf 'x\n' > "$artifact_dir/vendor/autoload.php"
printf 'x\n' > "$artifact_dir/node_modules/index.js"
printf 'x\n' > "$artifact_dir/storage/logs/laravel.log"
printf 'x\n' > "$artifact_dir/app.php"
printf 'SECRET=1\n' > "$artifact_dir/.env"

# shellcheck source=/dev/null
( source "$env_dir/create_tarball.sh"
  create_tarball "test" "$artifact_dir/artifact.tar.gz" "$artifact_dir" \
    ".git node_modules vendor .env .env.* storage/logs/*" ) >/dev/null 2>&1

if [ ! -f "$artifact_dir/artifact.tar.gz" ]; then
    fail "creates the tarball" "the tarball was not created"
else
    pass "creates the tarball"
fi

if tar -tzf "$artifact_dir/artifact.tar.gz" 2>/dev/null | grep -q "vendor/autoload.php"; then
    fail "excludes vendor/ from the tarball" "vendor/autoload.php is inside the tarball"
else
    pass "excludes vendor/ from the tarball"
fi

if tar -tzf "$artifact_dir/artifact.tar.gz" 2>/dev/null | grep -q "node_modules/index.js"; then
    fail "excludes node_modules/ from the tarball" "node_modules/index.js is inside the tarball"
else
    pass "excludes node_modules/ from the tarball"
fi

if tar -tzf "$artifact_dir/artifact.tar.gz" 2>/dev/null | grep -q "storage/logs/laravel.log"; then
    fail "excludes the log files from the tarball" "storage/logs/laravel.log is inside the tarball"
else
    pass "excludes the log files from the tarball"
fi

if tar -tzf "$artifact_dir/artifact.tar.gz" 2>/dev/null | grep -q "\.env$"; then
    fail "never ships the env file inside the tarball" "the .env file is inside the tarball"
else
    pass "never ships the env file inside the tarball"
fi

if tar -tzf "$artifact_dir/artifact.tar.gz" 2>/dev/null | grep -q "app.php"; then
    pass "keeps the application files inside the tarball"
else
    fail "keeps the application files inside the tarball" "app.php is missing from the tarball"
fi

# ============================================================
section "the CI pipeline — the rollback verifies the restored release"
# ============================================================
ci_file="$REPO_ROOT/.github/workflows/deploy.yml"
if grep -q 'php artisan --version' "$ci_file" && grep -q 'test -f \$DEPLOY_PATH_BACKEND/public/index.php' "$ci_file"; then
    pass "the backend rollback verifies the restored application"
else
    fail "the backend rollback verifies the restored application" "the verification step is missing"
fi

if grep -q 'test -f \$DEPLOY_PATH_FRONTEND/index.html' "$ci_file"; then
    pass "the frontend rollback verifies the restored build"
else
    fail "the frontend rollback verifies the restored build" "the verification step is missing"
fi

if grep -q 'ROLLBACK_TO_TAG' "$ci_file"; then
    pass "the rollback accepts a manual target override"
else
    fail "the rollback accepts a manual target override" "the override is missing"
fi

# ============================================================
section "scripts/deploy/deploy.sh — the dry run"
# ============================================================
# The dry run needs the same toolchain as a real deploy. When it is not
# installed (for example in the lightweight CI image) the checks are
# skipped instead of reported as failures.
if ! command -v php >/dev/null 2>&1 || ! command -v composer >/dev/null 2>&1 \
    || ! command -v pnpm >/dev/null 2>&1; then
    skip "the dry run needs php, composer and pnpm (not installed here)"
    dry_out=""
    dry_status=0
    skip_dry_run=true
else
    dry_out="$(cd "$REPO_ROOT" && bash "$DEPLOY_SCRIPT" --dry-run 2>&1)"
    dry_status=$?
    skip_dry_run=false
fi

if [ "$skip_dry_run" = "true" ]; then
    skip "the dry run finishes with exit code 0"
elif [ "$dry_status" -eq 0 ]; then
    pass "the dry run finishes with exit code 0"
else
    fail "the dry run finishes with exit code 0" "exit code was $dry_status"
fi

if [ "$skip_dry_run" = "true" ]; then
    skip "the dry run says that it changed nothing"
elif printf '%s' "$dry_out" | grep -q "DRY-RUN"; then
    pass "the dry run says that it changed nothing"
else
    fail "the dry run says that it changed nothing" "no DRY-RUN notice in the output"
fi

if [ "$skip_dry_run" = "true" ]; then
    skip "the dry run reaches the end of the deploy"
elif printf '%s' "$dry_out" | grep -q "DEPLOY COMPLETE"; then
    pass "the dry run reaches the end of the deploy"
else
    fail "the dry run reaches the end of the deploy" "no completion notice in the output"
fi

if [ "$skip_dry_run" = "true" ]; then
    skip "the dry run reports both the backend and the frontend target"
elif printf '%s' "$dry_out" | grep -q "Backend:" && printf '%s' "$dry_out" | grep -q "Frontend:"; then
    pass "the dry run reports both the backend and the frontend target"
else
    fail "the dry run reports both the backend and the frontend target" "the output does not name both targets"
fi

# ============================================================
section "setup.sh and CI — environment file permissions and private key installation"
# ============================================================
perm_dir="$(mktemp -d)"

# The environment file permissions are enforced by a function of setup.sh.
# It is loaded in isolation, like the other functions above.
load_function "$SETUP_SCRIPT" "restrict_env_permissions" "$perm_dir/restrict.sh"

perm_env="$perm_dir/app.env"
: > "$perm_env"
chmod 644 "$perm_env"
perm_backup="$perm_dir/app.env.bak.20260101_000000"
: > "$perm_backup"
chmod 644 "$perm_backup"
(
    set -uo pipefail
    . "$perm_dir/restrict.sh"
    restrict_env_permissions "$perm_env"
) >/dev/null 2>&1
perm_mode="$(stat -c %a "$perm_env" 2>/dev/null || echo '?')"
perm_backup_mode="$(stat -c %a "$perm_backup" 2>/dev/null || echo '?')"
if [ "$perm_mode" = "600" ] && [ "$perm_backup_mode" = "600" ]; then
    pass "setup.sh restricts the environment file and its backups to the owner"
else
    fail "setup.sh restricts the environment file and its backups to the owner" "modes: $perm_mode / $perm_backup_mode"
fi

# A backup that cannot be restricted is a secret left readable: the failure
# has to reach the caller instead of being swallowed.
(
    set -uo pipefail
    chmod() { return 1; }
    . "$perm_dir/restrict.sh"
    restrict_env_permissions "$perm_env"
) >/dev/null 2>&1
restrict_status=$?
if [ "$restrict_status" -ne 0 ]; then
    pass "setup.sh reports a failure when the environment file cannot be restricted"
else
    fail "setup.sh reports a failure when the environment file cannot be restricted" "the function returned 0"
fi

configure_backend_body="$(awk '/^configure_backend\(\) \{/,/^\}/' "$SETUP_SCRIPT")"
if grep -q "restrict_env_permissions" <<< "$configure_backend_body"; then
    pass "setup.sh applies the environment file permissions in configure_backend"
else
    fail "setup.sh applies the environment file permissions in configure_backend" "configure_backend does not call restrict_env_permissions"
fi

# The key installation of the pipeline is extracted from the CI file, so the
# test runs the very same code the runner executes.
# The extraction is robust to indentation: in GitHub Actions the block is
# a `run: |` heredoc at 8-space indent, with content at 10-space indent.
# We match on the `run: |` marker and collect content lines. We fail loudly
# if the block is not found.
ci_key_block="$(awk '
    /^        run: \|$/ { collecting = 1; block = ""; next }
    collecting {
        if ($0 == "") { next }
        if (substr($0, 1, 10) == "          ") { block = block substr($0, 11) "\n"; next }
        if (block ~ /SSH_PRIVATE_KEY/) { print block; exit }
        collecting = 0
    }
' "$REPO_ROOT/.github/workflows/deploy.yml")"
if [ -z "$ci_key_block" ]; then
    echo "FATAL: Could not extract SSH key installation block from .github/workflows/deploy.yml" >&2
    exit 1
fi

runner_ssh_dir=".ssh"
runner_key="id_rsa"

run_key_block() {
    local value="$1" home_dir="$2"
    mkdir -p "$home_dir/$runner_ssh_dir"
    chmod 700 "$home_dir/$runner_ssh_dir"
    HOME="$home_dir" SSH_PRIVATE_KEY="$value" bash -c "$ci_key_block" 2>&1
}

# The block extraction above already fails noisily if not found, so we
# don't need the silent-skip branch anymore.
# The block extraction above already fails noisily if not found, so there is no
# silent-skip branch here: a missing block aborts the suite.
empty_out="$(run_key_block "" "$perm_dir/home-empty")"
empty_status=$?
if [ "$empty_status" -ne 0 ] && grep -q "SSH_PRIVATE_KEY is empty" <<< "$empty_out"; then
    pass "the pipeline refuses an empty private key with a clear message"
else
    fail "the pipeline refuses an empty private key with a clear message" "exit $empty_status: $empty_out"
fi

missing_out="$(run_key_block "$perm_dir/no-such-key" "$perm_dir/home-missing")"
missing_status=$?
if [ "$missing_status" -ne 0 ] && grep -q "does not hold a valid private key" <<< "$missing_out"; then
    pass "the pipeline refuses a private key that is not a key with a clear message"
else
    fail "the pipeline refuses a private key that is not a key with a clear message" "exit $missing_status: $missing_out"
fi

if command -v ssh-keygen >/dev/null 2>&1; then
    valid_key="$perm_dir/test-key"
    ssh-keygen -t ed25519 -N "" -f "$valid_key" >/dev/null 2>&1
    valid_out="$(run_key_block "$valid_key" "$perm_dir/home-valid")"
    valid_status=$?
    valid_mode="$(stat -c %a "$perm_dir/home-valid/$runner_ssh_dir/$runner_key" 2>/dev/null || echo '?')"
    if [ "$valid_status" -eq 0 ] && [ "$valid_mode" = "600" ]; then
        pass "the pipeline installs a valid private key with owner-only permissions"
    else
        fail "the pipeline installs a valid private key with owner-only permissions" "exit $valid_status, mode $valid_mode: $valid_out"
    fi
else
    skip "the pipeline installs a valid private key with owner-only permissions" "ssh-keygen is not available"
fi

# ============================================================
section "scripts/deploy/deploy.sh — the child shell"

# run_cmd executes its commands with `bash -c`, and a child shell does not
# inherit the functions of the parent. A function called from a command
# string therefore fails with "command not found" (exit 127).

helper="$REPO_ROOT/scripts/deploy/ensure-pnpm.sh"

if [ -x "$helper" ]; then
    pass "the pnpm bootstrap is an executable script, not a shell function"
else
    fail "the pnpm bootstrap is an executable script, not a shell function" "$helper is missing or not executable"
fi

# The invocation must work in a child shell. A stub pnpm keeps the check
# deterministic: it does not depend on pnpm being installed on the machine
# that runs the suite.
stub_bin="$(mktemp -d)"
cat > "$stub_bin/pnpm" <<'STUB'
#!/usr/bin/env bash
echo "9.9.9-stub"
STUB
chmod +x "$stub_bin/pnpm"

stub_out="$(PATH="$stub_bin:$PATH" bash -c "\"$helper\" && pnpm --version" 2>&1)"
stub_status=$?
if [ "$stub_status" -eq 0 ] && grep -q "9.9.9-stub" <<< "$stub_out"; then
    pass "the pnpm bootstrap resolves pnpm inside a child shell"
else
    fail "the pnpm bootstrap resolves pnpm inside a child shell" "exit $stub_status: $stub_out"
fi

# Without pnpm the bootstrap must fail with a clear message, never with
# "command not found".
no_pnpm_dir="$(mktemp -d)"

cat > "$no_pnpm_dir/corepack" <<'STUB'
#!/usr/bin/env bash
# A corepack that does nothing: the bootstrap must still fail clearly.
# It reports the download prompt setting so the check can verify it.
echo "PROMPT=${COREPACK_ENABLE_DOWNLOAD_PROMPT:-unset}"
exit 0
STUB
chmod +x "$no_pnpm_dir/corepack"

pnpm_bin="$(command -v pnpm 2>/dev/null || true)"
pnpm_dir=""
[ -n "$pnpm_bin" ] && pnpm_dir="$(dirname "$pnpm_bin")"

clean_path="$no_pnpm_dir:$(dirname "$(command -v bash)")"
old_ifs="$IFS"
IFS=':'
for part in $PATH; do
    [ "$part" = "$pnpm_dir" ] && continue
    clean_path="$clean_path:$part"
done
IFS="$old_ifs"

missing_out="$(PATH="$clean_path" bash -c "\"$helper\"" 2>&1)"
missing_status=$?
if [ "$missing_status" -ne 0 ] && grep -qi "pnpm" <<< "$missing_out" && ! grep -q "command not found" <<< "$missing_out"; then
    pass "the pnpm bootstrap fails with a clear message when pnpm is missing"
else
    fail "the pnpm bootstrap fails with a clear message when pnpm is missing" "exit $missing_status: $missing_out"
fi

# Corepack asks before it downloads pnpm the first time. A deploy must
# never wait for an answer.
if grep -q "PROMPT=0" <<< "$missing_out"; then
    pass "the pnpm bootstrap disables the corepack download prompt"
else
    fail "the pnpm bootstrap disables the corepack download prompt" "$missing_out"
fi

if grep -q "export COREPACK_ENABLE_DOWNLOAD_PROMPT=0" "$DEPLOY_SCRIPT"; then
    pass "the deploy script disables the corepack download prompt"
else
    fail "the deploy script disables the corepack download prompt" "COREPACK_ENABLE_DOWNLOAD_PROMPT is not exported"
fi

# Every function the script defines must not be called from a command
# string: the child shell would not know it.
script_functions="$(grep -oE '^[a-z_][a-z0-9_]*\(\)' "$DEPLOY_SCRIPT" | tr -d '()' | sort -u)"
command_strings="$(awk '
    /run_cmd[ \t]|run_remote[ \t]/ {
        line = $0
        sub(/^.*run_(cmd|remote)[ \t]+"/, "", line)
        sub(/^[^"]*"/, "", line)
        if (line ~ /^"/) { print line; collect = 0; next }
        collect = 1
        next
    }
    collect && /^[[:space:]]*"/ { print; next }
    collect { collect = 0 }
' "$DEPLOY_SCRIPT")"

missing_in_child=""
for func_name in $script_functions; do
    if grep -qw -- "$func_name" <<< "$command_strings"; then
        missing_in_child="$missing_in_child $func_name"
    fi
done

if [ -z "$missing_in_child" ]; then
    pass "no deploy command calls a shell function that the child shell would not inherit"
else
    fail "no deploy command calls a shell function that the child shell would not inherit" "functions used in a command string:$missing_in_child"
fi

# The deploy lets pnpm run the version the repository pins: pnpm
# downloads it once, without asking, and the lockfile, the workspace
# layout and the installed pnpm always agree. Forcing the host pnpm
# made an older pnpm try to purge the modules tree of the pinned one,
# and that purge aborts when there is no TTY to ask on.
if grep -q "npm_config_manage_package_manager_versions=false" "$DEPLOY_SCRIPT"; then
    fail "the deploy script lets pnpm run the version the repository pins" "manage-package-manager-versions is disabled"
else
    pass "the deploy script lets pnpm run the version the repository pins"
fi

# The deploy prints the branch and the commit it runs: the operator works
# from more than one checkout and the copies drift.
if grep -q 'DEPLOY_BRANCH="\$(git -C "\$REPO_ROOT" rev-parse --abbrev-ref HEAD' "$DEPLOY_SCRIPT" \
    && grep -q 'DEPLOY_COMMIT="\$(git -C "\$REPO_ROOT" rev-parse --short HEAD' "$DEPLOY_SCRIPT"; then
    pass "the deploy prints the branch and the commit it runs"
else
    fail "the deploy prints the branch and the commit it runs" "the deploy does not report its branch and commit"
fi

# The target directory is verified before the upload: a path that points
# at another copy of the application publishes the release there.
if grep -q 'Backend: verify the target directory' "$DEPLOY_SCRIPT" \
    && grep -q "does not hold a Laravel application" "$DEPLOY_SCRIPT"; then
    pass "the deploy verifies the target directory before uploading"
else
    fail "the deploy verifies the target directory before uploading" "the deploy does not check the target"
fi

# The uploaded setup script is verified against the local one: the
# operator runs the script from the server copy, and a copy that did not
# arrive leaves the old script there, silently.
if grep -q 'Setup: verify the uploaded' "$DEPLOY_SCRIPT" \
    && grep -q 'md5sum "\$local_path"' "$DEPLOY_SCRIPT"; then
    pass "the deploy verifies the uploaded setup script against the local one"
else
    fail "the deploy verifies the uploaded setup script against the local one" "the deploy does not compare the checksums"
fi

# The version drift between the host pnpm and the pinned one is reported.
version_dir="$(mktemp -d)"
mkdir -p "$version_dir/bin"
load_function "$DEPLOY_SCRIPT" "check_pnpm_version" "$version_dir/check_pnpm_version.sh"
cat >> "$version_dir/check_pnpm_version.sh" <<'EOF'
log_warn() { echo "WARN: $1"; }
log_info() { echo "INFO: $1"; }
EOF

cat > "$version_dir/bin/pnpm" <<'EOF'
#!/usr/bin/env bash
echo "10.34.9"
EOF
chmod +x "$version_dir/bin/pnpm"

drift_out="$( ( source "$version_dir/check_pnpm_version.sh"; PATH="$version_dir/bin:$PATH"; check_pnpm_version ) 2>&1 )"
if grep -q "10.34.9" <<< "$drift_out" && grep -q "pins pnpm@" <<< "$drift_out"; then
    pass "the deploy reports a pnpm version drift with the version the repository pins"
else
    fail "the deploy reports a pnpm version drift with the version the repository pins" "$drift_out"
fi

pinned_version="$(sed -n 's/.*"packageManager"[[:space:]]*:[[:space:]]*"pnpm@\([^"]*\)".*/\1/p' "$REPO_ROOT/package.json" | head -1)"
cat > "$version_dir/bin/pnpm" <<EOF
#!/usr/bin/env bash
echo "$pinned_version"
EOF
chmod +x "$version_dir/bin/pnpm"

same_out="$( ( source "$version_dir/check_pnpm_version.sh"; PATH="$version_dir/bin:$PATH"; check_pnpm_version ) 2>&1 )"
if [ -z "$same_out" ]; then
    pass "the deploy stays quiet when the host pnpm matches the pinned version"
else
    fail "the deploy stays quiet when the host pnpm matches the pinned version" "$same_out"
fi

rm -rf "$version_dir"

# Every variable written to the env file must be assigned in both paths:
# the interactive one and the non-interactive one. A variable assigned in
# only one of them ends the run with "unbound variable" in the other.
interactive_branch="$(mktemp -d)"
awk '
    /if \[ "\$NON_INTERACTIVE" = true \]; then/ { in_non = 1; next }
    in_non && /^    else$/ { in_int = 1; next }
    in_int && /^    fi$/ { in_int = 0; in_common = 1; next }
    in_int { print > "'"$interactive_branch"'/interactive"; next }
    in_common { print > "'"$interactive_branch"'/common" }
' "$SETUP_SCRIPT"

env_vars="$(grep -oE 'sed_set "\.env" "[A-Z_]+" "\$[a-z_]+"' "$SETUP_SCRIPT" | grep -oE '\$[a-z_]+' | tr -d '$' | sort -u)"

unassigned_vars=""
for env_var in $env_vars; do
    if ! grep -qE "(^|[^a-zA-Z0-9_])${env_var}=" "$interactive_branch/interactive" \
        && ! grep -qE "(^|[^a-zA-Z0-9_])${env_var}=" "$interactive_branch/common"; then
        unassigned_vars="$unassigned_vars $env_var"
    fi
done

if [ -z "$unassigned_vars" ]; then
    pass "the interactive setup assigns every variable it writes to the env file"
else
    fail "the interactive setup assigns every variable it writes to the env file" "not assigned in the interactive path:$unassigned_vars"
fi

rm -rf "$interactive_branch"

# ============================================================
section "scripts/deploy/build-frontend.sh — the production build inputs"

# Vite loads .env, .env.local and .env.development* in every mode, so a
# developer's local file ends up baked into the published bundle. The
# helper hides them while the build runs and restores them after, also
# when the build fails.
build_dir="$(mktemp -d)"
mkdir -p "$build_dir/front" "$build_dir/bin"
printf 'VITE_GLITCHVY_API_URL=/api\n' > "$build_dir/front/.env.local"
printf 'VITE_GLITCHVY_API_URL=https://api.example.com\n' > "$build_dir/front/.env.production"

cat > "$build_dir/bin/pnpm" <<'EOF'
#!/usr/bin/env bash
if [ -f "$FRONT_DIR_FOR_TEST/.env.local" ]; then
    echo "visible" > "$RESULT_FOR_TEST"
else
    echo "hidden" > "$RESULT_FOR_TEST"
fi
exit "${PNPM_EXIT_FOR_TEST:-0}"
EOF
chmod +x "$build_dir/bin/pnpm"

FRONT_DIR_FOR_TEST="$build_dir/front" RESULT_FOR_TEST="$build_dir/result" \
    PATH="$build_dir/bin:$PATH" \
    "$REPO_ROOT/scripts/deploy/build-frontend.sh" "$build_dir" "$build_dir/front" >/dev/null 2>&1

if [ "$(cat "$build_dir/result" 2>/dev/null)" = "hidden" ]; then
    pass "hides the development env files while the build runs"
else
    fail "hides the development env files while the build runs" "the file was visible during the build"
fi

if [ -f "$build_dir/front/.env.local" ] && [ -f "$build_dir/front/.env.production" ]; then
    pass "restores the development env files after the build"
else
    fail "restores the development env files after the build" "the files were not restored"
fi

# A failed build must restore them too: the operator keeps its local
# files and the next run starts from the same state.
FRONT_DIR_FOR_TEST="$build_dir/front" RESULT_FOR_TEST="$build_dir/result" \
    PNPM_EXIT_FOR_TEST=1 PATH="$build_dir/bin:$PATH" \
    "$REPO_ROOT/scripts/deploy/build-frontend.sh" "$build_dir" "$build_dir/front" >/dev/null 2>&1
status=$?

if [ -f "$build_dir/front/.env.local" ]; then
    pass "restores the development env files when the build fails"
else
    fail "restores the development env files when the build fails" "the file was not restored"
fi

if [ "$status" -ne 0 ]; then
    pass "reports the failure of the build"
else
    fail "reports the failure of the build" "the exit code was 0"
fi

rm -rf "$build_dir"

# ============================================================
rm -rf "$stub_bin" "$no_pnpm_dir"

echo ""
echo "==========================================="
echo "  passed: $PASSED"
echo "  skipped: $SKIPPED"
echo "  failed: $FAILED"
echo "==========================================="

[ "$FAILED" -eq 0 ]
