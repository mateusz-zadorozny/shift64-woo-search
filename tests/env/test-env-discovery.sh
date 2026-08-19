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
#   3. WORKTREE_HASH must be the same eight hex characters whichever SHA-1
#      binary the host has (issue #68). It keys $RUN_DIR, $MYSQL_SOCK and both
#      database names, so a digest that shifts with the implementation orphans
#      every environment a previous `up` provisioned.

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
# script needs coreutils (dirname, and a SHA-1 binary) at source time, and
# hard-coding where those live is exactly the portability assumption this suite
# exists to avoid. php_candidates scans PATH in order, so a fixture still
# outranks any real PHP on the box. (The worktree-hash section below is the one
# place that *does* build a PATH from scratch — that is its whole point.)
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

# --- Worktree hash (SHA-1 portability) -----------------------------------

# The launcher's top level needs these before it ever reaches the SHA-1 call
# (plus `bash` itself, looked up under the fake PATH); a fake PATH that omitted
# them would fail for the wrong reason.
BASE_TOOLS="bash dirname basename tr cut id"

make_fakebin() { # make_fakebin <name> [sha1-tool...]  -> prints the dir
	local dir="$FIXTURES/fakebin-$1" tool real
	shift
	mkdir -p "$dir"
	for tool in $BASE_TOOLS "$@"; do
		real="$(command -v "$tool" || true)"
		[ -n "$real" ] || { echo "missing host tool: $tool" >&2; exit 1; }
		ln -sf "$real" "$dir/$tool"
	done
	printf '%s' "$dir"
}

hash_under() { # hash_under <PATH>
	PATH="$1" bash -c '
		set -euo pipefail
		. "$1"
		printf "%s" "${WORKTREE_HASH:-}"
	' _ "$REPO_ROOT/bin/test-env.sh"
}

native_hash="$(hash_under "$SAFE_PATH")"
case "$native_hash" in
	[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f])
		ok "WORKTREE_HASH is 8 lowercase hex chars ($native_hash)" ;;
	*)  fail "WORKTREE_HASH is 8 lowercase hex chars (got '$native_hash')" ;;
esac

for impl in sha1sum shasum openssl; do
	if ! command -v "$impl" >/dev/null 2>&1; then
		echo "skip - $impl branch ($impl not installed on this host)"
		continue
	fi
	# openssl without -r prints "SHA1(stdin)= …", which cut -c1-8 would turn
	# into the same constant hash for every worktree — a far worse bug than the
	# missing binary this helper exists to survive. This is the assertion that
	# catches it, and any future change of algorithm or hashed input.
	assert_eq "the $impl branch reproduces the host digest" \
		"$native_hash" "$(hash_under "$(make_fakebin "only-$impl" "$impl")")"
done

set +e
out="$(PATH="$(make_fakebin none)" bash -c '. "$1"' _ "$REPO_ROOT/bin/test-env.sh" 2>&1)"
rc=$?
set -e
assert_eq "a host with no SHA-1 binary at all exits 2" "2" "$rc"
case "$out" in
	*"need one of sha1sum, shasum or openssl"*)
		ok "that failure names the three acceptable binaries" ;;
	*)  fail "that failure names the three acceptable binaries (got: $out)" ;;
esac

echo "# $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
