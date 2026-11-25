#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 DowarDev
# SPDX-License-Identifier: AGPL-3.0-only
#
# Build the admin SPA with only the production environment files.
#
# Vite loads .env, .env.local and .env.development* in every mode, so a
# developer's local file ends up baked into the bundle that the deploy
# publishes: the same commit produces two different artifacts depending
# on which files happen to sit in the working copy.
#
# The production inputs are .env.production and .env.production.local.
# The rest are moved aside while the build runs and restored right
# after, also when the build fails.
#
# Usage: scripts/deploy/build-frontend.sh <repo-root> <front-dir>
set -uo pipefail

REPO_ROOT="${1:-}"
FRONT_DIR="${2:-}"

if [ -z "$REPO_ROOT" ] || [ -z "$FRONT_DIR" ]; then
    echo "usage: build-frontend.sh <repo-root> <front-dir>" >&2
    exit 2
fi

if [ ! -d "$FRONT_DIR" ]; then
    echo "the frontend directory does not exist: $FRONT_DIR" >&2
    exit 2
fi

STASH="$(mktemp -d)"

# The names are listed instead of globbing the stash: the files start
# with a dot, and a glob does not match those.
STASHED_NAMES=".env .env.local .env.development .env.development.local"

restore_env_files() {
    local name
    for name in $STASHED_NAMES; do
        if [ -f "$STASH/$name" ]; then
            if ! mv "$STASH/$name" "$FRONT_DIR/$name"; then
                echo "could not restore $name in $FRONT_DIR" >&2
                return 1
            fi
        fi
    done
    rmdir "$STASH" 2>/dev/null || true
    return 0
}

# The restore runs on the way out no matter how the build ends.
trap 'restore_env_files' EXIT INT TERM

for name in $STASHED_NAMES; do
    if [ -f "$FRONT_DIR/$name" ]; then
        mv "$FRONT_DIR/$name" "$STASH/$name" || {
            echo "could not move $name aside" >&2
            exit 1
        }
    fi
done

cd "$REPO_ROOT" || exit 1
pnpm --filter glitchvy-admin build
status=$?

restore_env_files || status=1
trap - EXIT INT TERM
exit $status
