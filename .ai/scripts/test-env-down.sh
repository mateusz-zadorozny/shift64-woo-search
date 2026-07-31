#!/bin/sh
# Repo-owned teardown: stops exactly what .ai/scripts/test-env-up.sh started
# for this worktree. Thin wrapper over bin/test-env.sh down.
set -eu
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
exec bash "$REPO_ROOT/bin/test-env.sh" down "$@"
