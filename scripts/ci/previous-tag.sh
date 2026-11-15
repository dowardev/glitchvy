#!/usr/bin/env bash
# Print the newest stable release OLDER than the tag being deployed.
#
# Used by the rollback jobs in .gitlab-ci.yml. It only considers stable
# semver tags (vMAJOR.MINOR.PATCH): prereleases and malformed tags never
# qualify, and the version comparison is numeric, not lexicographic (so
# v1.10.0 sorts after v1.9.0).
#
# Usage: scripts/ci/previous-tag.sh <current-tag>
set -euo pipefail

# The version key encodes the three components as
# major*MAJOR_FACTOR + minor*MINOR_FACTOR + patch, so each component has
# to stay below COMPONENT_LIMIT (a version like v1.1000.0 is not
# representable and is rejected below).
readonly MAJOR_FACTOR=1000000
readonly MINOR_FACTOR=1000
readonly COMPONENT_LIMIT=1000

CURRENT_TAG="${1:-${CI_COMMIT_TAG:-}}"

if [[ ! "$CURRENT_TAG" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)(-.*)?$ ]]; then
    echo "The current tag '$CURRENT_TAG' is not a semantic version tag (expected vMAJOR.MINOR.PATCH)." >&2
    exit 1
fi

if (( BASH_REMATCH[1] >= COMPONENT_LIMIT || BASH_REMATCH[2] >= COMPONENT_LIMIT || BASH_REMATCH[3] >= COMPONENT_LIMIT )); then
    echo "The version components of '$CURRENT_TAG' must stay below $COMPONENT_LIMIT." >&2
    exit 1
fi

PREVIOUS_TAG=$(git tag --list "v*" \
    | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' \
    | awk -v cur="$CURRENT_TAG" \
           -v major_factor="$MAJOR_FACTOR" \
           -v minor_factor="$MINOR_FACTOR" '
        function vkey(v,   p) {
            sub(/^v/, "", v); split(v, p, ".");
            return p[1] * major_factor + p[2] * minor_factor + p[3]
        }
        BEGIN { ck = vkey(cur) }
        { k = vkey($0); if (k < ck && (!best || k > bk)) { best = $0; bk = k } }
        END { print best }')

if [ -z "$PREVIOUS_TAG" ]; then
    echo "No stable release older than '$CURRENT_TAG' is available to roll back to." >&2
    exit 1
fi

echo "$PREVIOUS_TAG"
