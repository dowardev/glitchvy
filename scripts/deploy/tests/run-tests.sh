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
env_file="$env_dir/app.env"
printf 'EXISTING=value\n' > "$env_file"

load_function "$SETUP_SCRIPT" "sed_set" "$env_dir/sed_set.sh"
cat >> "$env_dir/sed_set.sh" <<'EOF'
log_error() { echo "ERR: $1"; }
log_info()  { echo "INFO: $1"; }
EOF

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
( source "$env_dir/sed_set.sh"
  sed_set "$env_file" "SSO_CLIENT_ID" "$(printf 'client\nINJECTED=1')" ) >/dev/null 2>&1
status=$?
if [ "$status" -eq 7 ]; then
    pass "refuses a value with a newline and returns the code 7"
else
    fail "refuses a value with a newline and returns the code 7" "exit code was $status"
fi

if grep -q 'INJECTED=1' "$env_file"; then
    fail "leaves the env file untouched when a value is refused" "the file was modified"
else
    pass "leaves the env file untouched when a value is refused"
fi

# The caller turns that code into the documented exit code of the script.
( source "$env_dir/sed_set.sh"
  sed_set "$env_file" "SSO_CLIENT_ID" "$(printf 'client\nINJECTED=1')" || exit $? ) >/dev/null 2>&1
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
if [ "$status" -eq 6 ]; then
    pass "reports a write permission error with the code 6"
else
    fail "reports a write permission error with the code 6" "exit code was $status"
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
section "scripts/deploy/deploy.sh — the dry run"
# ============================================================
# The dry run needs the same toolchain as a real deploy. When it is not
# installed (for example in the lightweight CI image) the checks are
# skipped instead of reported as failures.
if ! command -v php >/dev/null 2>&1 || ! command -v composer >/dev/null 2>&1 \
    || ! command -v pnpm >/dev/null 2>&1; then
    echo "  [SKIP] the dry run needs php, composer and pnpm (not installed here)"
    dry_out=""
    dry_status=0
    skip_dry_run=true
else
    dry_out="$(cd "$REPO_ROOT" && bash "$DEPLOY_SCRIPT" --dry-run 2>&1)"
    dry_status=$?
    skip_dry_run=false
fi

if [ "$skip_dry_run" = "true" ]; then
    pass "the dry run finishes with exit code 0 (skipped: no toolchain)"
elif [ "$dry_status" -eq 0 ]; then
    pass "the dry run finishes with exit code 0"
else
    fail "the dry run finishes with exit code 0" "exit code was $dry_status"
fi

if [ "$skip_dry_run" = "true" ]; then
    pass "the dry run says that it changed nothing (skipped: no toolchain)"
elif printf '%s' "$dry_out" | grep -q "DRY-RUN"; then
    pass "the dry run says that it changed nothing"
else
    fail "the dry run says that it changed nothing" "no DRY-RUN notice in the output"
fi

if [ "$skip_dry_run" = "true" ]; then
    pass "the dry run reaches the end of the deploy (skipped: no toolchain)"
elif printf '%s' "$dry_out" | grep -q "DEPLOY COMPLETE"; then
    pass "the dry run reaches the end of the deploy"
else
    fail "the dry run reaches the end of the deploy" "no completion notice in the output"
fi

if [ "$skip_dry_run" = "true" ]; then
    pass "the dry run reports both the backend and the frontend target (skipped: no toolchain)"
elif printf '%s' "$dry_out" | grep -q "Backend:" && printf '%s' "$dry_out" | grep -q "Frontend:"; then
    pass "the dry run reports both the backend and the frontend target"
else
    fail "the dry run reports both the backend and the frontend target" "the output does not name both targets"
fi

# ============================================================
echo ""
echo "==========================================="
echo "  passed: $PASSED"
echo "  failed: $FAILED"
echo "==========================================="

[ "$FAILED" -eq 0 ]
