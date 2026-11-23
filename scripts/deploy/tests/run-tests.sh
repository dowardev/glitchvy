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
section "setup.sh — the destructive migration guard"
# ============================================================
load_function "$SETUP_SCRIPT" "assert_no_destructive_migration" "$env_dir/guard.sh"
cat >> "$env_dir/guard.sh" <<'EOF'
log_error() { echo "ERR: $1"; }
log_warn()  { echo "WARN: $1"; }
EOF

# shellcheck source=/dev/null
( source "$env_dir/guard.sh"
  assert_no_destructive_migration "alter table users drop column name" ) >/dev/null 2>&1
status=$?
if [ "$status" -ne 0 ]; then
    pass "blocks a destructive migration by default"
else
    fail "blocks a destructive migration by default" "it exited with 0"
fi

# shellcheck source=/dev/null
( source "$env_dir/guard.sh"
  ALLOW_DESTRUCTIVE_MIGRATIONS=1
  export ALLOW_DESTRUCTIVE_MIGRATIONS
  assert_no_destructive_migration "alter table users drop column name" ) >/dev/null 2>&1
status=$?
if [ "$status" -eq 0 ]; then
    pass "allows a destructive migration with ALLOW_DESTRUCTIVE_MIGRATIONS=1"
else
    fail "allows a destructive migration with ALLOW_DESTRUCTIVE_MIGRATIONS=1" "exit code was $status"
fi

# shellcheck source=/dev/null
( source "$env_dir/guard.sh"
  assert_no_destructive_migration "create table sessions (id bigint)" ) >/dev/null 2>&1
status=$?
if [ "$status" -eq 0 ]; then
    pass "allows a regular migration"
else
    fail "allows a regular migration" "exit code was $status"
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
echo ""
echo "==========================================="
echo "  passed: $PASSED"
echo "  skipped: $SKIPPED"
echo "  failed: $FAILED"
echo "==========================================="

[ "$FAILED" -eq 0 ]
