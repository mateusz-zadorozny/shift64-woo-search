#!/usr/bin/env bash
#
# Unit tests for the pure logic of bin/test-env.sh: PHP discovery and run
# directory derivation. Nothing is provisioned — no MySQL, no Redis, no
# WordPress download — so unlike test-env-launcher.sh this one is fast enough
# for the per-PR gate, and it needs no PHP on the box (the candidates are
# stubs).
#
# Regressions it pins:
#   1. php_pick() must not capture the probe binary's stdout. PHP prints
#      startup warnings ("Module redis is already loaded", which this script's
#      own `pecl install redis` can provoke) to STDOUT, and php_pick's stdout
#      becomes $PHP_BIN — a stray warning would corrupt every later invocation.
#   2. RUN_DIR must never be anchored to $TMPDIR: agent harnesses recycle it
#      while the environment is still running, leaving `wp server` and `mysqld`
#      alive on top of a deleted docroot.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
FIXTURES="$(mktemp -d)"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "ok   - $1"; }
fail() { FAIL=$((FAIL+1)); echo "FAIL - $1" >&2; }
assert_eq() { # assert_eq <description> <expected> <actual>
	if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (expected '$2', got '$3')"; fi
}

cleanup() { rm -rf "$FIXTURES"; }
trap cleanup EXIT

# A PHP that satisfies every probe but is noisy on stdout at startup — the
# duplicate-extension case, verbatim.
mkdir -p "$FIXTURES/bin-noisy"
cat >"$FIXTURES/bin-noisy/php" <<'STUB'
#!/bin/sh
echo ""
echo 'Warning: Module "redis" is already loaded in Unknown on line 0'
exit 0
STUB
chmod +x "$FIXTURES/bin-noisy/php"

# A PHP that fails every probe (too old / no mysqli), used to prove discovery
# keeps looking past the first hit instead of stopping at it.
mkdir -p "$FIXTURES/bin-unfit"
cat >"$FIXTURES/bin-unfit/php" <<'STUB'
#!/bin/sh
exit 1
STUB
chmod +x "$FIXTURES/bin-unfit/php"

# --- PHP discovery -------------------------------------------------------

# Fixtures are PREPENDED to the caller's PATH rather than replacing it: the
# script needs coreutils (dirname, sha1sum) at source time, and hard-coding
# where those live is exactly the portability assumption this suite exists to
# avoid. php_candidates scans PATH in order, so a fixture still outranks any
# real PHP on the box.
SAFE_PATH="$PATH"

run_select_php() { # run_select_php <PATH-prefix>
	# PATH is narrowed inside the subshell, never around `bash` itself.
	bash -c '
		set -euo pipefail
		PATH="$2"
		unset TEST_ENV_PHP
		. "$1"
		select_php
		printf "%s" "$PHP_BIN"
	' _ "$REPO_ROOT/bin/test-env.sh" "$1:$SAFE_PATH"
}

picked="$(run_select_php "$FIXTURES/bin-noisy")"
assert_eq "php_pick returns a bare path for a stdout-noisy PHP" \
	"$FIXTURES/bin-noisy/php" "$picked"
if [ -x "$picked" ]; then
	ok "the selected PHP_BIN is executable (no startup output glued to it)"
else
	fail "the selected PHP_BIN is not executable: '$picked'"
fi

picked="$(run_select_php "$FIXTURES/bin-unfit:$FIXTURES/bin-noisy")"
assert_eq "discovery looks past an unfit first candidate on PATH" \
	"$FIXTURES/bin-noisy/php" "$picked"

picked="$(TEST_ENV_PHP="/explicit/php" bash -c '
	set -euo pipefail
	PATH="$2"
	. "$1"
	select_php
	printf "%s" "$PHP_BIN"
' _ "$REPO_ROOT/bin/test-env.sh" "$FIXTURES/bin-unfit:$SAFE_PATH")"
assert_eq "TEST_ENV_PHP overrides discovery" "/explicit/php" "$picked"

# --- Run directory -------------------------------------------------------

run_dir_with() { # run_dir_with <env-assignments...>
	env "$@" bash -c '
		set -euo pipefail
		. "$1"
		printf "%s" "$RUN_DIR"
	' _ "$REPO_ROOT/bin/test-env.sh"
}

run_dir="$(run_dir_with "TEST_ENV_RUN_ROOT=$FIXTURES/custom-root" "TMPDIR=$FIXTURES/scratch")"
case "$run_dir" in
	"$FIXTURES/custom-root"/*) ok "TEST_ENV_RUN_ROOT anchors RUN_DIR" ;;
	*) fail "TEST_ENV_RUN_ROOT ignored: RUN_DIR='$run_dir'" ;;
esac

run_dir="$(run_dir_with "TMPDIR=$FIXTURES/scratch" "XDG_CACHE_HOME=$FIXTURES/cache")"
case "$run_dir" in
	*"$FIXTURES/scratch"*) fail "RUN_DIR is anchored to \$TMPDIR: '$run_dir'" ;;
	"$FIXTURES/cache/shift64-test-env"/*) ok "RUN_DIR defaults under \$XDG_CACHE_HOME, not \$TMPDIR" ;;
	*) fail "unexpected default RUN_DIR: '$run_dir'" ;;
esac

echo "# $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
