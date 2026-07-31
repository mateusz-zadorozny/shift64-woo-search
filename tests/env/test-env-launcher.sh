#!/usr/bin/env bash
#
# Clean-room integration test for bin/test-env.sh (spec:
# .ai/specs/2026-07-31-one-shot-worktree-test-env.md, issue #53).
#
# From a fresh detached git worktree — no vendor/, no node_modules/, no
# wordpress-tests-lib, no descriptor — one `up` must produce a healthy
# storefront, a bare-`vendor/bin/phpunit` green run, and a truthful
# validation status; a killed server must be detected (stale descriptor)
# and repaired by the next `up`; teardown must be complete and re-runnable.
#
# This test spawns real servers and downloads WordPress — it belongs in the
# dispatch/scheduled test-env CI job, never in the per-PR validation gate.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CLEANROOM="${TMPDIR:-/tmp}/s64-cleanroom-$$"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "ok   - $1"; }
fail() { FAIL=$((FAIL+1)); echo "FAIL - $1" >&2; }
assert() { # assert <description> <command...>
	local desc="$1"; shift
	if "$@" >/dev/null 2>&1; then ok "$desc"; else fail "$desc"; fi
}

cleanup() {
	set +e
	( cd "$CLEANROOM" 2>/dev/null && bash bin/test-env.sh down >/dev/null 2>&1 )
	cd "$REPO_ROOT"
	git worktree remove --force "$CLEANROOM" >/dev/null 2>&1
	git worktree prune >/dev/null 2>&1
}
trap cleanup EXIT

echo "# clean room: $CLEANROOM"
cd "$REPO_ROOT"
git worktree add --detach "$CLEANROOM" HEAD >/dev/null
cd "$CLEANROOM"

[ ! -d vendor ] || { echo "clean room is not clean (vendor/ exists)"; exit 1; }
[ ! -f .ai/qa/test-env.json ] || { echo "clean room is not clean (descriptor exists)"; exit 1; }

echo "# one-shot up (with background validation)"
bash bin/test-env.sh up && ok "up exits 0 from a clean worktree" || { fail "up exits 0 from a clean worktree"; exit 1; }

DESCRIPTOR=.ai/qa/test-env.json
assert "descriptor exists" test -f "$DESCRIPTOR"
assert "descriptor status is running" bash -c "jq -re '.status == \"running\"' $DESCRIPTOR"
BASE_URL="$(jq -r '.baseUrl' "$DESCRIPTOR")"
assert "storefront serves /search-e2e/ with the search block" \
	bash -c "curl -sf --max-time 10 '$BASE_URL/search-e2e/' | grep -q shift64-woo-search"

echo "# status truthfulness"
set +e; bash bin/test-env.sh status >/dev/null 2>&1; rc=$?; set -e
[ "$rc" = 0 ] && ok "status exits 0 on a healthy env" || fail "status exits 0 on a healthy env (got $rc)"

echo "# background validation reaches a terminal state"
deadline=$(( $(date +%s) + 1800 ))
vstate=""
while [ "$(date +%s)" -lt "$deadline" ]; do
	vstate="$(jq -r '.status // "none"' .ai/qa/validation-status.json 2>/dev/null || echo none)"
	case "$vstate" in passed|failed|aborted) break ;; esac
	sleep 10
done
[ "$vstate" = "passed" ] && ok "validation gate passed in background" || fail "validation gate passed in background (state: $vstate)"
assert "validation status records per-command exit codes" \
	bash -c "jq -re '.commands | length > 0 and all(.status != \"pending\")' .ai/qa/validation-status.json"

# Only after the gate is terminal: a bare phpunit racing the supervisor's
# phpunit would reinstall the same tests DB tables under it.
echo "# bare phpunit (no WP_TESTS_DIR export)"
assert "vendor/bin/phpunit passes with no exports" env -u WP_TESTS_DIR vendor/bin/phpunit

echo "# stale-descriptor detection and self-healing"
APP_PID="$(jq -r '.app.pid' "$DESCRIPTOR")"
kill "$APP_PID" 2>/dev/null || true
sleep 2
set +e; bash bin/test-env.sh status >/dev/null 2>&1; rc=$?; set -e
[ "$rc" = 3 ] && ok "status exits 3 after the server dies (no stale 'running' lie)" \
	|| fail "status exits 3 after the server dies (got $rc)"
bash bin/test-env.sh up --no-validate >/dev/null && ok "up repairs the dead environment" || fail "up repairs the dead environment"
set +e; bash bin/test-env.sh status >/dev/null 2>&1; rc=$?; set -e
[ "$rc" = 0 ] && ok "status healthy again after repair" || fail "status healthy again after repair (got $rc)"

echo "# teardown"
bash bin/test-env.sh down >/dev/null && ok "down exits 0" || fail "down exits 0"
set +e; bash bin/test-env.sh status >/dev/null 2>&1; rc=$?; set -e
[ "$rc" = 4 ] && ok "status exits 4 after down" || fail "status exits 4 after down (got $rc)"
RUN_DIR_PATH="$(jq -r '.services[] | select(.type == "mysql") | .datadir' "$DESCRIPTOR" | xargs dirname 2>/dev/null || true)"
[ -n "$RUN_DIR_PATH" ] && [ ! -d "$RUN_DIR_PATH" ] && ok "run directory removed" || fail "run directory removed ($RUN_DIR_PATH)"
bash bin/test-env.sh down >/dev/null && ok "down is safe to run twice" || fail "down is safe to run twice"

echo
echo "# $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
