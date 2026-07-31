#!/bin/sh
# Repo-owned entrypoint: brings up the one-shot isolated test environment for
# this worktree. Thin wrapper over bin/test-env.sh (see
# docs/test-environments.md); kept at this path so om-prepare-test-env and
# other QA consumers find it. Maps the entrypoint-contract flags:
#   --force          -> restart even when healthy
#   --force-rebuild  -> full wipe + rebuild (bin/test-env.sh --fresh)
set -eu
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ARGS=""
for arg in "$@"; do
	case "$arg" in
		--force-rebuild) ARGS="$ARGS --fresh" ;;
		*) ARGS="$ARGS $arg" ;;
	esac
done
# shellcheck disable=SC2086
exec bash "$REPO_ROOT/bin/test-env.sh" up $ARGS
