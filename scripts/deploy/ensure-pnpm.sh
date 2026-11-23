#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 DowarDev
# SPDX-License-Identifier: AGPL-3.0-only
#
# Make pnpm available for the deploy commands.
#
# The deploy script runs every command in a child shell (`bash -c`), and a
# child shell does not inherit the functions of the parent, so the pnpm
# bootstrap lives in an executable script instead of a shell function.
set -uo pipefail

if command -v pnpm >/dev/null 2>&1; then
    exit 0
fi

if ! command -v corepack >/dev/null 2>&1; then
    echo "pnpm is not installed and corepack is not available to provide it" >&2
    exit 1
fi

corepack enable || true

if ! command -v pnpm >/dev/null 2>&1; then
    echo "pnpm is still not on the PATH after 'corepack enable'" >&2
    exit 1
fi
