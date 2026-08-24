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
PREFLIGHT_TMP="${TMPDIR:-/tmp}/s64-preflight-$$"

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
	if [ -d "$CLEANROOM/.ai/qa/logs" ]; then
		mkdir -p .ai/qa/logs/test-env-cleanroom
		cp -R "$CLEANROOM/.ai/qa/logs/." .ai/qa/logs/test-env-cleanroom/
	fi
	git worktree remove --force "$CLEANROOM" >/dev/null 2>&1
	git worktree prune >/dev/null 2>&1
	rm -rf "$PREFLIGHT_TMP"
}
trap cleanup EXIT

echo "# Docker daemon preflight (fake macOS seam)"
mkdir -p "$PREFLIGHT_TMP/bin" "$PREFLIGHT_TMP/Docker.app"
for tool in php wp composer jq curl svn mysql mysqladmin sha1sum; do
	tool_path="$(command -v "$tool")"
	ln -s "$tool_path" "$PREFLIGHT_TMP/bin/$tool"
done
cat > "$PREFLIGHT_TMP/bin/docker" <<'SH'
#!/bin/sh
[ "${1:-}" = info ] && [ -f "$FAKE_DOCKER_STATE" ]
SH
cat > "$PREFLIGHT_TMP/bin/open" <<'SH'
#!/bin/sh
touch "$FAKE_DOCKER_STATE"
SH
cat > "$PREFLIGHT_TMP/bin/launchctl" <<'SH'
#!/bin/sh
exit 0
SH
chmod +x "$PREFLIGHT_TMP/bin/docker" "$PREFLIGHT_TMP/bin/open" "$PREFLIGHT_TMP/bin/launchctl"
PREFLIGHT_PATH="$PREFLIGHT_TMP/bin:/usr/bin:/bin"
FAKE_DOCKER_STATE="$PREFLIGHT_TMP/docker-ready" \
	TEST_ENV_PLATFORM_OVERRIDE=Darwin TEST_ENV_DOCKER_APP="$PREFLIGHT_TMP/Docker.app" \
	TEST_ENV_DOCKER_WAIT_SECONDS=2 PATH="$PREFLIGHT_PATH" \
	bash bin/test-env.sh _preflight >/dev/null \
	&& ok "preflight starts Docker Desktop and waits for daemon readiness" \
	|| fail "preflight starts Docker Desktop and waits for daemon readiness"
rm -f "$PREFLIGHT_TMP/docker-ready"
set +e
FAKE_DOCKER_STATE="$PREFLIGHT_TMP/docker-ready" \
	TEST_ENV_PLATFORM_OVERRIDE=Darwin TEST_ENV_DOCKER_APP="$PREFLIGHT_TMP/missing.app" \
	TEST_ENV_DOCKER_WAIT_SECONDS=1 PATH="$PREFLIGHT_PATH" \
	bash bin/test-env.sh _preflight >"$PREFLIGHT_TMP/unavailable.log" 2>&1
rc=$?
set -e
[ "$rc" = 2 ] && grep -q "Docker CLI is installed but the daemon is unavailable" "$PREFLIGHT_TMP/unavailable.log" \
	&& ok "preflight reports the stopped Docker daemon before provisioning" \
	|| fail "preflight reports the stopped Docker daemon before provisioning (got $rc)"

# Fast local/PR diagnostics can exercise the platform seam without starting
# real services; the scheduled clean-room job intentionally leaves this unset.
if [ "${TEST_ENV_PREFLIGHT_ONLY:-0}" = 1 ]; then
	echo "# $PASS passed, $FAIL failed"
	exit "$FAIL"
fi

echo "# clean room: $CLEANROOM"
cd "$REPO_ROOT"
git worktree add --detach "$CLEANROOM" HEAD >/dev/null
cd "$CLEANROOM"

[ ! -d vendor ] || { echo "clean room is not clean (vendor/ exists)"; exit 1; }
[ ! -f .ai/qa/test-env.json ] || { echo "clean room is not clean (descriptor exists)"; exit 1; }

echo "# one-shot up (with background validation)"
# The nested shell exits before health is asserted, catching app processes
# that survive only while their original terminal or agent session stays open.
bash -c 'bash bin/test-env.sh up' && ok "up exits 0 from a clean worktree" || { fail "up exits 0 from a clean worktree"; exit 1; }

DESCRIPTOR=.ai/qa/test-env.json
assert "descriptor exists" test -f "$DESCRIPTOR"
assert "descriptor status is running" bash -c "jq -re '.status == \"running\"' $DESCRIPTOR"
BASE_URL="$(jq -r '.baseUrl' "$DESCRIPTOR")"
assert "storefront serves /search-e2e/ with the search block" \
	bash -c "curl -sf --max-time 10 '$BASE_URL/search-e2e/' | grep -q shift64-woo-search"
sleep 2
assert "storefront survives the launching shell exit" \
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
GENERATION_BEFORE="$(jq -r '.generationId' "$DESCRIPTOR")"
WP_ROOT="$(jq -r '.app.wpRoot' "$DESCRIPTOR")"
CATALOG_BEFORE="$(.ai/qa/bin/wp --path="$WP_ROOT" post list --post_type=product --format=ids)"
APP_PID="$(jq -r '.app.pid' "$DESCRIPTOR")"
kill "$APP_PID" 2>/dev/null || true
sleep 2
set +e; bash bin/test-env.sh status >/dev/null 2>&1; rc=$?; set -e
[ "$rc" = 3 ] && ok "status exits 3 after the server dies (no stale 'running' lie)" \
	|| fail "status exits 3 after the server dies (got $rc)"
RECOVERY_OUTPUT="$(bash -c 'bash bin/test-env.sh up --no-validate')"
[ "$(jq -r '.recoveryMode' "$DESCRIPTOR")" = "restarted-app" ] \
	&& grep -q "ready (restarted-app)" <<<"$RECOVERY_OUTPUT" \
	&& ok "up restarts only the dead application layer" \
	|| fail "up restarts only the dead application layer"
assert "app-only recovery keeps the environment generation" \
	test "$(jq -r '.generationId' "$DESCRIPTOR")" = "$GENERATION_BEFORE"
assert "app-only recovery keeps the QA URL" test "$(jq -r '.baseUrl' "$DESCRIPTOR")" = "$BASE_URL"
CATALOG_AFTER="$(.ai/qa/bin/wp --path="$WP_ROOT" post list --post_type=product --format=ids)"
assert "app-only recovery preserves product IDs" test "$CATALOG_AFTER" = "$CATALOG_BEFORE"
assert "--no-validate reports validation as absent" \
	bash -c "bash bin/test-env.sh status --json | jq -e '.validationStatus == null'"
set +e; bash bin/test-env.sh status >/dev/null 2>&1; rc=$?; set -e
[ "$rc" = 0 ] && ok "status healthy again after repair" || fail "status healthy again after repair (got $rc)"

echo "# warm reuse timing"
warm_started="$(date +%s)"
bash bin/test-env.sh up --no-validate >/dev/null
warm_elapsed=$(( $(date +%s) - warm_started ))
[ "$(jq -r '.recoveryMode' "$DESCRIPTOR")" = "reused" ] && [ "$warm_elapsed" -lt 30 ] \
	&& ok "healthy warm environment is reused in under 30 seconds" \
	|| fail "healthy warm environment is reused quickly (mode: $(jq -r '.recoveryMode' "$DESCRIPTOR"), ${warm_elapsed}s)"

echo "# rebuilt generation rejects stale validation"
jq -n --arg runId "$(jq -r '.runId' "$DESCRIPTOR")" \
	'{runId:$runId,generationId:"stale-generation",status:"passed",pid:0,commands:[]}' \
	> .ai/qa/validation-status.json
bash bin/test-env.sh up --force --no-validate >/dev/null
[ "$(jq -r '.generationId' "$DESCRIPTOR")" != "$GENERATION_BEFORE" ] \
	&& ok "rebuilt environment receives a new generation identifier" \
	|| fail "rebuilt environment receives a new generation identifier"
assert "rebuilt generation does not expose stale validation" \
	bash -c "bash bin/test-env.sh status --json | jq -e '.validationStatus == null'"

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
