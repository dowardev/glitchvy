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
    echo "The current tag '$CURRENT_TAG' is not a semantic version tag (expected vMAJOR.MINOR.PATCH)." >&2
    exit 1
fi

PREVIOUS_TAG=$(git tag --list "v*" \
    | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' \
    | awk -v cur="$CURRENT_TAG" '
        # Splits vMAJOR.MINOR.PATCH into its three numeric components.
        function parts(v,   p) {
            sub(/^v/, "", v); split(v, p, ".");
            component[1] = p[1] + 0; component[2] = p[2] + 0; component[3] = p[3] + 0
        }
        # -1 when a is older than b, 0 when they are the same, 1 when a is newer.
        function compare(a, b,   i) {
            for (i = 1; i <= 3; i++) {
                if (a[i] < b[i]) return -1;
                if (a[i] > b[i]) return 1;
            }
            return 0
        }
        BEGIN {
            parts(cur);
            for (i = 1; i <= 3; i++) current[i] = component[i]
            best = ""
        }
        {
            parts($0);
            if (compare(component, current) < 0 && (best == "" || compare(component, best_version) > 0)) {
                best = $0;
                for (i = 1; i <= 3; i++) best_version[i] = component[i]
            }
        }
        END { print best }')

if [ -z "$PREVIOUS_TAG" ]; then
    echo "No stable release older than '$CURRENT_TAG' was found." >&2
    echo "Check that the tags were fetched (git fetch --tags) and that the older release exists." >&2
    exit 1
fi

echo "$PREVIOUS_TAG"
