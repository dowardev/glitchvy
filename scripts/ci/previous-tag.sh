#!/usr/bin/env bash
# Print the newest stable release OLDER than the tag being deployed.
#
# Used by the rollback jobs in .gitlab-ci.yml. It only considers stable
# semver tags (vMAJOR.MINOR.PATCH): prereleases and malformed tags never
# qualify, and the version comparison is numeric, not lexicographic (so
# v1.10.0 sorts after v1.9.0).
#
# The three version components are compared one by one, so there is no
# upper bound on any of them.
#
# Usage: scripts/ci/previous-tag.sh <current-tag>
set -euo pipefail

CURRENT_TAG="${1:-${CI_COMMIT_TAG:-}}"

if [[ ! "$CURRENT_TAG" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)(-.*)?$ ]]; then
    echo "The current tag '$CURRENT_TAG' is not a semantic version tag (expected vMAJOR.MINOR.PATCH, with an optional prerelease suffix)." >&2
    exit 1
fi

PREVIOUS_TAG=$(git tag --list "v*" \
    | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' \
    | awk -v cur="$CURRENT_TAG" '
        # -1 when a is older than b, 0 when they are the same, 1 when a is
        # newer. It compares the three numeric components one by one, so
        # no component has an upper bound.
        function compare(a, b,   x, y, i) {
            split(a, x, "."); split(b, y, ".");
            sub(/^v/, "", x[1]); sub(/^v/, "", y[1]);
            for (i = 1; i <= 3; i++) {
                if ((x[i] + 0) < (y[i] + 0)) return -1;
                if ((x[i] + 0) > (y[i] + 0)) return 1;
            }
            return 0
        }
        BEGIN { current = cur }
        {
            if (compare($0, current) < 0 && (best == "" || compare($0, best) > 0)) best = $0
        }
        END { print best }')

if [ -z "$PREVIOUS_TAG" ]; then
    echo "No stable release older than '$CURRENT_TAG' was found." >&2
    echo "Check that the tags were fetched (git fetch --tags) and that the older release exists." >&2
    exit 1
fi

echo "$PREVIOUS_TAG"
