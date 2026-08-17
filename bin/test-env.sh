#!/usr/bin/env bash
#
# One-shot isolated test environment for this worktree.
#
#   bin/test-env.sh up [--validate|--no-validate] [--force] [--fresh] [--allow-degraded]
#   bin/test-env.sh status [--json]
#   bin/test-env.sh down [--keep-logs]
#
# From a clean checkout, `up` provisions everything the worktree contract
# needs: Composer dependencies, an isolated MariaDB/MySQL and a RediSearch-
# capable Redis on run-scoped 127.0.0.1 ports, WordPress + WooCommerce via
# bin/e2e-install-wp.sh, plugin state via bin/e2e-provision.sh, the WordPress
# PHPUnit library + test database via bin/install-wp-tests.sh, and a
# worktree-local phpunit.xml — then reports the QA URL and starts the
# validation gate in a supervised background process.
#
# Design doc: .ai/specs/2026-07-31-one-shot-worktree-test-env.md (issue #53).
#
# Every mutable path lives under $RUN_DIR (platform tmp); every port binds to
# 127.0.0.1; the descriptor (.ai/qa/test-env.json) records ownership so `down`
# only ever stops what this script started. "running" is written only after
# every health probe passes — a stale descriptor is detected, torn down, and
# rebuilt, never trusted.

set -euo pipefail

# --------------------------------------------------------------------------
# Identity and layout
# --------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
WORKTREE_HASH="$(printf '%s' "$REPO_ROOT" | sha1sum | cut -c1-8)"
RUN_ID="s64-$(basename "$REPO_ROOT" | tr -cd 'a-zA-Z0-9-' | cut -c1-24)-${WORKTREE_HASH}"
TMP_ROOT="${TMPDIR:-/tmp}"
RUN_DIR="${TMP_ROOT%/}/shift64-test-env/${RUN_ID}"
QA_DIR="$REPO_ROOT/.ai/qa"
LOG_DIR="$QA_DIR/logs"
DESCRIPTOR="$QA_DIR/test-env.json"
VALIDATION_STATUS="$QA_DIR/validation-status.json"
AGENTIC_CONFIG="$REPO_ROOT/.ai/agentic.config.json"

WP_ROOT="$RUN_DIR/wordpress"
MYSQL_DIR="$RUN_DIR/mysql"
REDIS_DIR="$RUN_DIR/redis"
WP_TESTS_DIR_RUN="$RUN_DIR/wordpress-tests-lib"
WP_CORE_DIR_RUN="$RUN_DIR/wordpress-develop"
# UNIX socket paths are capped at ~103 chars on macOS; $RUN_DIR under a
# macOS $TMPDIR (/var/folders/…) can exceed that on its own, so the socket
# lives in /tmp keyed by the worktree hash instead of inside $MYSQL_DIR.
MYSQL_SOCK="/tmp/s64-mysql-${WORKTREE_HASH}.sock"

DB_USER="wp"
DB_PASS="wp"
WP_DB_NAME="wp_e2e_${WORKTREE_HASH}"
TESTS_DB_NAME="wp_tests_${WORKTREE_HASH}"

# PIDs started by THIS invocation (for the abort trap only). A plain
# space-separated string, not an array: empty-array expansion under `set -u`
# is fatal on bash 3.2 (macOS's default /bin/bash).
STARTED_PIDS=""
STARTED_LAUNCH_LABELS=""

# RUN_ID is stable for the worktree. GENERATION_ID changes only when the
# environment is rebuilt, so status can reject validation results left by an
# older generation while warm reuse and app-only recovery retain identity.
GENERATION_ID=""
GENERATION_STARTED_AT=""
RECOVERY_MODE=""
APP_LAUNCH_LABEL=""
VALIDATION_LAUNCH_LABEL=""

# wp-cli refuses to run as root unless told; CI containers and dev servers
# commonly are root.
[ "$(id -u)" = "0" ] && export WP_CLI_ALLOW_ROOT=1

log()  { printf '\n==> %s\n' "$1"; }
info() { printf '    %s\n' "$1"; }
warn() { printf 'WARN: %s\n' "$1" >&2; }
die()  { printf 'FATAL: %s\n' "$1" >&2; exit "${2:-1}"; }

# --------------------------------------------------------------------------
# Small helpers
# --------------------------------------------------------------------------

# Pick a PHP >= 8.3 with mysqli for wp-cli and the served site. The gate's
# own commands keep using the repo default `php`.
select_php() {
	if [ -n "${TEST_ENV_PHP:-}" ]; then
		PHP_BIN="$TEST_ENV_PHP"
		return
	fi
	local candidate
	# First pass: prefer a PHP that already carries phpredis — it saves the
	# automatic pecl install entirely.
	for candidate in php php8.5 php8.4 php8.3; do
		command -v "$candidate" >/dev/null 2>&1 || continue
		if "$candidate" -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("mysqli") && extension_loaded("redis") ? 0 : 1);' 2>/dev/null; then
			PHP_BIN="$(command -v "$candidate")"
			return
		fi
	done
	for candidate in php php8.5 php8.4 php8.3; do
		command -v "$candidate" >/dev/null 2>&1 || continue
		if "$candidate" -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("mysqli") ? 0 : 1);' 2>/dev/null; then
			PHP_BIN="$(command -v "$candidate")"
			return
		fi
	done
	PHP_BIN=""
}

# pecl may enable the extension in php.ini a beat after install; if both its
# entry and the conf.d file this script owns end up active, PHP warns "Module
# redis is already loaded" on every startup (and stray startup output corrupts
# captured values). Remove the file we own and keep pecl's php.ini line.
dedupe_redis_ini() {
	# Capture, don't pipe into grep -q: under pipefail a -q match SIGPIPEs
	# the writer and fails the pipeline exactly when it should succeed.
	local startup_output
	startup_output="$("$PHP_BIN" -r 'exit(0);' 2>&1)" || true
	case "$startup_output" in
		*"already loaded"*) ;;
		*) return 0 ;;
	esac
	local ini_main ini_dir
	ini_main="$("$PHP_BIN" -i 2>/dev/null | sed -n 's/^Loaded Configuration File => //p')"
	ini_dir="$("$PHP_BIN" -i 2>/dev/null | sed -n 's/^Scan this dir for additional .ini files => //p')"
	if [ -f "$ini_main" ] && grep -q '^extension="\{0,1\}redis' "$ini_main" \
		&& [ -n "$ini_dir" ] && [ -f "$ini_dir/ext-redis.ini" ]; then
		rm -f "$ini_dir/ext-redis.ini"
		info "removed duplicate ext-redis.ini (extension already enabled in php.ini)"
	fi
	return 0
}

# Full plugin search needs phpredis in the PHP that serves the site. When it
# is missing, install it automatically (non-interactive pecl) — the "one
# command, live environment" contract beats keeping the host pristine here.
# Returns non-zero only when the extension still cannot be loaded afterwards.
ensure_phpredis() {
	dedupe_redis_ini
	[ "$HAVE_PHPREDIS" = "1" ] && return 0
	if command -v pecl >/dev/null 2>&1; then
		log "phpredis missing in $PHP_BIN — installing automatically (pecl install redis)"
		info "log: $LOG_DIR/pecl-redis-$RUN_ID.log"
		# The four "no"s decline the optional igbinary/zstd/msgpack/lz4 hooks.
		printf 'no\nno\nno\nno\n' | pecl install redis \
			>>"$LOG_DIR/pecl-redis-$RUN_ID.log" 2>&1 \
			|| warn "pecl install redis failed — see $LOG_DIR/pecl-redis-$RUN_ID.log"
		if ! "$PHP_BIN" -r 'exit(extension_loaded("redis") ? 0 : 1);' 2>/dev/null; then
			# Built but not enabled (pecl could not write php.ini): drop an
			# ini file into the scan dir when the installation has one.
			local ini_dir
			ini_dir="$("$PHP_BIN" -i 2>/dev/null | sed -n 's/^Scan this dir for additional .ini files => //p')"
			if [ -n "$ini_dir" ] && [ "$ini_dir" != "(none)" ] && [ -d "$ini_dir" ] && [ ! -f "$ini_dir/ext-redis.ini" ]; then
				echo "extension=redis.so" > "$ini_dir/ext-redis.ini" 2>/dev/null || true
			fi
		fi
		dedupe_redis_ini
		if "$PHP_BIN" -r 'exit(extension_loaded("redis") ? 0 : 1);' 2>/dev/null; then
			HAVE_PHPREDIS=1
			info "phpredis installed and loaded"
			return 0
		fi
	else
		warn "pecl not found — cannot auto-install phpredis"
	fi
	return 1
}

# Known limitation: allocation-to-bind is a TOCTOU window with no
# cross-worktree coordination. A stolen port makes the service start fail
# loudly within its wait loop, and the next `up` self-heals with fresh ports.
# display_errors=stderr: a PHP startup warning printed to stdout would end up
# captured as part of the port number and corrupt the descriptor JSON.
free_port() {
	"$PHP_BIN" -d display_errors=stderr -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);if(!$s){exit(1);}preg_match("/:(\d+)$/",stream_socket_get_name($s,false),$x);echo $x[1];' 2>/dev/null
}

port_listening() {
	"$PHP_BIN" -d display_errors=stderr -r '$c=@stream_socket_client("tcp://127.0.0.1:".$argv[1],$e,$m,1);exit($c?0:1);' "$1" 2>/dev/null
}

# A recorded PID is only "ours" when it is alive AND its command line still
# matches the recorded fingerprint — PID reuse must never lead to killing an
# unrelated process.
pid_matches() {
	local pid="$1" fingerprint="$2"
	[ -n "$pid" ] && [ "$pid" != "null" ] && [ "$pid" != "0" ] || return 1
	if [ -d /proc ]; then
		[ -r "/proc/$pid/cmdline" ] || return 1
		tr '\0' ' ' < "/proc/$pid/cmdline" | grep -qF "$fingerprint"
	else
		# macOS/BSD: no /proc — read the command line from ps instead.
		ps -p "$pid" -o command= 2>/dev/null | grep -qF "$fingerprint"
	fi
}

platform_name() {
	printf '%s' "${TEST_ENV_PLATFORM_OVERRIDE:-$(uname -s)}"
}

# Linux uses setsid; macOS hands the process to launchd. Both mechanisms make
# the service independent of the shell (and agent command session) that ran
# `up`. DETACHED_PID and DETACHED_LABEL are output variables.
start_detached() { # start_detached <role> <logfile> <command...>
	local role="$1" logfile="$2" label pid pid_file i
	shift 2
	DETACHED_PID=0
	DETACHED_LABEL=""
	if [ "$(platform_name)" = "Darwin" ]; then
		label="com.shift64.test-env.${RUN_ID}.${role}"
		pid_file="$RUN_DIR/.${role}-launchd.pid"
		rm -f "$pid_file"
		launchctl remove "$label" >/dev/null 2>&1 || true
		# launchd jobs do not inherit the interactive shell's environment. Carry
		# the already-vetted command path explicitly so validation finds Composer
		# and other lockfile tools after the launching shell exits — and carry
		# PHPRC / PHP_INI_SCAN_DIR too: hosts whose PHP has no compiled-in ini
		# (Local by Flywheel shells, for one) load every extension, phpredis
		# included, through those variables, so dropping them hands `wp server`
		# a PHP with no configuration at all. The wrapper removes its own
		# launchd job after the child exits; `launchctl submit` otherwise
		# restarts even successful one-shot validation processes.
		launchctl submit -l "$label" -o "$logfile" -e "$logfile" -- \
			/usr/bin/env "PATH=$PATH" \
			${PHPRC:+"PHPRC=$PHPRC"} \
			${PHP_INI_SCAN_DIR:+"PHP_INI_SCAN_DIR=$PHP_INI_SCAN_DIR"} \
			bash "$SCRIPT_DIR/test-env.sh" \
			_launchd_wrapper "$label" "$pid_file" "$@" \
			|| die "launchd could not start $role — see $logfile"
		STARTED_LAUNCH_LABELS="$STARTED_LAUNCH_LABELS $label"
		for i in $(seq 1 30); do
			pid="$(cat "$pid_file" 2>/dev/null || true)"
			case "$pid" in ''|*[!0-9]*) pid="" ;; esac
			[ -n "$pid" ] && break
			sleep 1
		done
		[ -n "${pid:-}" ] || die "launchd started $role without a live PID — see $logfile"
		rm -f "$pid_file"
		DETACHED_PID="$pid"
		DETACHED_LABEL="$label"
	else
		setsid nohup "$@" >>"$logfile" 2>&1 &
		DETACHED_PID=$!
	fi
	STARTED_PIDS="$STARTED_PIDS $DETACHED_PID"
}

launchd_wrapper() { # launchd_wrapper <label> <pid-file> <command...>
	local label="$1" pid_file="$2" child exit_code
	shift 2
	"$@" &
	child=$!
	printf '%s\n' "$child" > "$pid_file"
	trap 'kill "$child" 2>/dev/null || true' INT TERM HUP
	set +e
	wait "$child"
	exit_code=$?
	set -e
	# `launchctl submit` jobs restart after normal exit unless explicitly
	# removed. The child has finished and written any terminal status first.
	launchctl remove "$label" >/dev/null 2>&1 || true
	exit "$exit_code"
}

# Native MySQL/Redis use the existing process-group behavior. The app and
# validation supervisor use start_detached(), which is the durable lifecycle
# boundary needed after the launching shell exits.
SETSID="env"
command -v setsid >/dev/null 2>&1 && SETSID="setsid"

atomic_write() { # atomic_write <path> ; body on stdin
	local path="$1" tmp
	tmp="$(mktemp "${path}.XXXXXX")"
	cat > "$tmp"
	mv -f "$tmp" "$path"
}

descriptor_get() { # descriptor_get <jq-expr>
	[ -f "$DESCRIPTOR" ] || return 1
	jq -r "$1" "$DESCRIPTOR" 2>/dev/null
}

# wp-cli must run on the selected PHP. WP_CLI_PHP only works when `wp` is the
# launcher script — a bare phar (shebang `#!/usr/bin/env php`) ignores it — so
# generate a run-scoped shim and put it first on PATH; the bare `wp` calls
# inside bin/e2e-install-wp.sh / bin/e2e-provision.sh then inherit the pin.
make_wp_shim() {
	# The shim lives on the worktree side, not under $RUN_DIR: tmp roots are
	# commonly mounted noexec (this repo's dev server included), which makes a
	# PATH entry there silently unusable.
	local real_wp shim_dir="$QA_DIR/bin"
	real_wp="$(command -v wp)" || die "wp not found"
	mkdir -p "$shim_dir"
	# Pin CLI ini overrides too: hosts with a small default memory_limit
	# (128M is common on Homebrew PHP) OOM while wp-cli extracts WP core, and
	# older wp-cli phars drown the output in deprecation noise on PHP >= 8.1.
	# 24567 = E_ALL & ~E_DEPRECATED & ~E_NOTICE. display_errors=stderr is the
	# load-bearing one: wp-cli re-raises error_reporting internally, and any
	# startup warning printed to STDOUT poisons captured values — provisioning
	# scripts read `wp` output via command substitution (page IDs, ports).
	local php_args="-d memory_limit=512M -d error_reporting=24567 -d display_errors=stderr"
	if head -c 64 "$real_wp" | grep -qi php; then
		printf '#!/bin/sh\nexec "%s" %s "%s" "$@"\n' "$PHP_BIN" "$php_args" "$real_wp" > "$shim_dir/wp"
	else
		printf '#!/bin/sh\nWP_CLI_PHP="%s" WP_CLI_PHP_ARGS="%s" exec "%s" "$@"\n' "$PHP_BIN" "$php_args" "$real_wp" > "$shim_dir/wp"
	fi
	chmod +x "$shim_dir/wp"
	export PATH="$shim_dir:$PATH"
}

wpc() {
	wp "$@" --path="$WP_ROOT"
}

mysql_admin() { # socket-scoped admin client (root works only via socket)
	mysql --no-defaults --socket="$MYSQL_SOCK" -uroot "$@"
}

# --------------------------------------------------------------------------
# Preflight
# --------------------------------------------------------------------------

docker_ready() {
	docker info >/dev/null 2>&1
}

ensure_docker_ready() {
	docker_ready && return 0
	local platform docker_app wait_seconds i
	platform="$(platform_name)"
	docker_app="${TEST_ENV_DOCKER_APP:-/Applications/Docker.app}"
	wait_seconds="${TEST_ENV_DOCKER_WAIT_SECONDS:-90}"
	if [ "$platform" = "Darwin" ] && [ -d "$docker_app" ] && command -v open >/dev/null 2>&1; then
		log "Docker daemon is stopped — starting Docker Desktop"
		open -gja "$docker_app" >/dev/null 2>&1 \
			|| die "Docker Desktop could not be started. Open $docker_app, wait for it to become ready, then retry." 2
		for i in $(seq 1 "$wait_seconds"); do
			docker_ready && { info "Docker daemon is ready"; return 0; }
			sleep 1
		done
		die "Docker Desktop did not become ready within ${wait_seconds}s. Open $docker_app, resolve its reported error, then retry." 2
	fi
	die "Docker CLI is installed but the daemon is unavailable. Start Docker Desktop or the Docker service, verify 'docker info' succeeds, then retry." 2
}

docker_pull_with_retry() { # docker_pull_with_retry <image> <logfile>
	local image="$1" logfile="$2" attempt
	for attempt in 1 2 3; do
		info "pulling $image (attempt $attempt/3; log: $logfile)"
		if docker pull "$image" >>"$logfile" 2>&1; then
			return 0
		fi
		[ "$attempt" = 3 ] || sleep $((attempt * 2))
	done
	die "docker pull $image failed after 3 attempts — see $logfile"
}

preflight() {
	local missing=() docker_required=0
	select_php
	[ -n "$PHP_BIN" ] || missing+=("php >= 8.3 with mysqli (install php8.3-cli + php8.3-mysql, or set TEST_ENV_PHP)")
	command -v wp >/dev/null || missing+=("wp (wp-cli: https://wp-cli.org)")
	command -v composer >/dev/null || missing+=("composer (https://getcomposer.org)")
	command -v jq >/dev/null || missing+=("jq")
	command -v curl >/dev/null || missing+=("curl")
	command -v svn >/dev/null || missing+=("svn (needed by bin/install-wp-tests.sh)")
	command -v mysql >/dev/null || missing+=("mysql client")
	command -v mysqladmin >/dev/null || missing+=("mysqladmin (ships with the mysql/mariadb client tools)")
	if ! command -v mysqld >/dev/null && ! command -v docker >/dev/null; then
		missing+=("mysqld (MariaDB/MySQL server) or docker (fallback chain: native mysqld -> docker)")
	fi
	if ! command -v redis-stack-server >/dev/null && ! command -v redis-server >/dev/null && ! command -v docker >/dev/null; then
		missing+=("redis-stack-server or redis-server or docker (fallback chain: redis-stack-server -> redis-server with FT probe -> docker -> shared instance)")
	fi
	if [ "${#missing[@]}" -gt 0 ]; then
		echo "Preflight failed — missing dependencies:" >&2
		local m; for m in "${missing[@]}"; do echo "  - $m" >&2; done
		exit 2
	fi
	if ! command -v mysqld >/dev/null; then
		docker_required=1
	fi
	if ! command -v redis-stack-server >/dev/null && ! command -v redis-server >/dev/null; then
		docker_required=1
	fi
	[ "$docker_required" = 0 ] || ensure_docker_ready
	if [ "$(platform_name)" = "Darwin" ]; then
		command -v launchctl >/dev/null || die "launchctl is required to keep the test environment alive after this shell exits" 2
	else
		command -v setsid >/dev/null || die "setsid is required to keep the test environment alive after this shell exits" 2
	fi
	# extension_loaded() probe, never `php -m | grep -q`: under pipefail the
	# -q early exit SIGPIPEs php mid-listing and randomly fails the pipeline
	# even when the extension IS present. And NOT `probe && VAR=1` — as the
	# function's last statement that returns 1 when the extension is absent,
	# set -e then kills the whole script.
	HAVE_PHPREDIS=0
	if "$PHP_BIN" -r 'exit(extension_loaded("redis") ? 0 : 1);' 2>/dev/null; then
		HAVE_PHPREDIS=1
	fi
}

# --------------------------------------------------------------------------
# Descriptor
# --------------------------------------------------------------------------

write_descriptor() { # write_descriptor <status> <notes>
	local status="$1" notes="$2"
	mkdir -p "$QA_DIR"
	jq -n \
		--arg runId "$RUN_ID" --arg generationId "$GENERATION_ID" --arg status "$status" \
		--arg recoveryMode "$RECOVERY_MODE" --arg generationStartedAt "$GENERATION_STARTED_AT" \
		--arg baseUrl "http://127.0.0.1:${HTTP_PORT:-0}" \
		--arg wpRoot "$WP_ROOT" \
		--argjson httpPort "${HTTP_PORT:-0}" --argjson appPid "${APP_PID:-0}" --arg appLaunchLabel "${APP_LAUNCH_LABEL:-}" \
		--argjson mysqlPort "${MYSQL_PORT:-0}" --argjson mysqlPid "${MYSQL_PID:-0}" \
		--arg mysqlContainer "${MYSQL_CONTAINER:-}" --arg mysqlDatadir "$MYSQL_DIR" \
		--argjson redisPort "${REDIS_PORT:-0}" --argjson redisPid "${REDIS_PID:-0}" \
		--arg redisContainer "${REDIS_CONTAINER:-}" --arg redisIsolation "${REDIS_ISOLATION:-dedicated}" \
		--arg wpTestsDir "$WP_TESTS_DIR_RUN" --arg wpCoreDir "$WP_CORE_DIR_RUN" \
		--arg testDb "$TESTS_DB_NAME" \
		--arg validationStatusFile ".ai/qa/validation-status.json" \
		--argjson validationPid "${VALIDATION_PID:-0}" --arg validationLaunchLabel "${VALIDATION_LAUNCH_LABEL:-}" \
		--arg platform "$(platform_name | tr '[:upper:]' '[:lower:]')" \
		--arg notes "$notes" \
		'{
			version: 1, runId: $runId, generationId: $generationId, recoveryMode: $recoveryMode,
			status: $status, mode: "ephemeral",
			baseUrl: $baseUrl, startedByThisRepo: true,
			startScript: ".ai/scripts/test-env-up.sh", stopScript: ".ai/scripts/test-env-down.sh",
			app: { startCommand: "wp server", port: $httpPort, healthPath: "/search-e2e/", pid: $appPid,
			       launchLabel: (if $appLaunchLabel == "" then null else $appLaunchLabel end), wpRoot: $wpRoot },
			services: [
				{ type: "mysql", host: "127.0.0.1", port: $mysqlPort, pid: $mysqlPid,
				  container: (if $mysqlContainer == "" then null else $mysqlContainer end), datadir: $mysqlDatadir },
				{ type: "redis", host: "127.0.0.1", port: $redisPort, pid: $redisPid,
				  container: (if $redisContainer == "" then null else $redisContainer end), isolation: $redisIsolation }
			],
			credentials: [ { role: "admin", username: "admin", password: "admin" } ],
			phpunit: { wpTestsDir: $wpTestsDir, wpCoreDir: $wpCoreDir, testDb: $testDb, phpunitXml: "./phpunit.xml" },
			validation: { statusFile: $validationStatusFile, pid: $validationPid,
			              launchLabel: (if $validationLaunchLabel == "" then null else $validationLaunchLabel end) },
			browser: { provider: "agent-browser", installed: false, command: "", version: "", descriptor: ".ai/browsers/agent-browser.md", notes: "provisioned separately by om-prepare-test-env" },
			testRunner: { name: "playwright", config: "playwright.config.ts" },
			platform: $platform, startedAt: $generationStartedAt, notes: $notes
		}' | atomic_write "$DESCRIPTOR"
}

# Load recorded state (ports/pids) from an existing descriptor into shell vars.
load_descriptor_state() {
	HTTP_PORT="$(descriptor_get '.app.port // 0')"
	APP_PID="$(descriptor_get '.app.pid // 0')"
	APP_LAUNCH_LABEL="$(descriptor_get '.app.launchLabel // ""')"
	MYSQL_PORT="$(descriptor_get '.services[] | select(.type == "mysql") | .port // 0')"
	MYSQL_PID="$(descriptor_get '.services[] | select(.type == "mysql") | .pid // 0')"
	MYSQL_CONTAINER="$(descriptor_get '.services[] | select(.type == "mysql") | .container // ""')"
	[ "$MYSQL_CONTAINER" = "null" ] && MYSQL_CONTAINER=""
	REDIS_PORT="$(descriptor_get '.services[] | select(.type == "redis") | .port // 0')"
	REDIS_PID="$(descriptor_get '.services[] | select(.type == "redis") | .pid // 0')"
	REDIS_CONTAINER="$(descriptor_get '.services[] | select(.type == "redis") | .container // ""')"
	[ "$REDIS_CONTAINER" = "null" ] && REDIS_CONTAINER=""
	REDIS_ISOLATION="$(descriptor_get '.services[] | select(.type == "redis") | .isolation // "dedicated"')"
	VALIDATION_PID="$(descriptor_get '.validation.pid // 0')"
	VALIDATION_LAUNCH_LABEL="$(descriptor_get '.validation.launchLabel // ""')"
	GENERATION_ID="$(descriptor_get '.generationId // ""')"
	GENERATION_STARTED_AT="$(descriptor_get '.startedAt // ""')"
	RECOVERY_MODE="$(descriptor_get '.recoveryMode // ""')"
}

begin_generation() { # begin_generation <recovery-mode>
	# A descriptor written before generation tracking may still own a running
	# supervisor. Stop it before clearing the status file so generation rollout
	# cannot start a second PHPUnit gate against the same test database.
	stop_validation
	GENERATION_STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	GENERATION_ID="${RUN_ID}-${GENERATION_STARTED_AT//[^0-9]/}-$$"
	RECOVERY_MODE="$1"
	rm -f "$VALIDATION_STATUS"
}

# --------------------------------------------------------------------------
# Health probes — the single source of truth for "running"
# --------------------------------------------------------------------------

probe_mysql()  { mysqladmin --no-defaults -h127.0.0.1 -P"$MYSQL_PORT" -u"$DB_USER" -p"$DB_PASS" ping >/dev/null 2>&1; }
# Talk to the run's Redis: a host redis-cli when present, else docker exec
# into the run's container (hosts running the Docker fallback often have no
# redis-cli at all — inside the container the server always listens on 6379).
redis_exec() {
	if command -v redis-cli >/dev/null 2>&1; then
		redis-cli -h 127.0.0.1 -p "$REDIS_PORT" "$@"
	elif [ -n "${REDIS_CONTAINER:-}" ]; then
		docker exec "$REDIS_CONTAINER" redis-cli "$@"
	else
		return 1
	fi
}
probe_redis()  { redis_exec ping 2>/dev/null | grep -q PONG; }
# redis-cli exits 0 even when the server answers "ERR unknown command", so FT
# capability must be judged from the reply text, never the exit code.
probe_ft()     { ! redis_exec FT._LIST 2>&1 | grep -qi '^ERR\|unknown command'; }
# First hit after provisioning can exceed 10s (cold opcache, WooCommerce boot
# on the single-threaded built-in server) — probe generously, with one retry.
# Capture the body instead of piping into grep -q: under pipefail, grep's
# early exit SIGPIPEs curl and fails the pipeline even on a healthy page.
probe_http()   {
	local _attempt body
	# shellcheck disable=SC2034
	for _attempt in 1 2; do
		if body="$(curl -sf --max-time 30 "http://127.0.0.1:${HTTP_PORT}/search-e2e/" 2>/dev/null)"; then
			grep -q 'shift64-woo-search' <<<"$body" && return 0
		fi
	done
	return 1
}
probe_tests()  { [ -f "$WP_TESTS_DIR_RUN/includes/functions.php" ]; }

# Infrastructure/provisioning probes are separate from the app probe so a
# dead built-in server can be restarted without reseeding the catalog.
probe_infrastructure() {
	[ -f "$DESCRIPTOR" ] || { echo "no descriptor"; return 1; }
	load_descriptor_state
	# Fingerprints are run-scoped (datadir path, run port, WP_ROOT) so a PID
	# recycled by ANOTHER worktree's identically-named process never matches.
	if [ -n "$MYSQL_CONTAINER" ]; then
		docker inspect -f '{{.State.Running}}' "$MYSQL_CONTAINER" 2>/dev/null | grep -q true \
			|| { echo "mysql container $MYSQL_CONTAINER not running"; return 1; }
	else
		pid_matches "$MYSQL_PID" "$MYSQL_DIR" || { echo "mysqld pid $MYSQL_PID gone"; return 1; }
	fi
	probe_mysql || { echo "mysql not answering on :$MYSQL_PORT"; return 1; }
	if [ -n "$REDIS_CONTAINER" ]; then
		docker inspect -f '{{.State.Running}}' "$REDIS_CONTAINER" 2>/dev/null | grep -q true \
			|| { echo "redis container $REDIS_CONTAINER not running"; return 1; }
	elif [ "$REDIS_ISOLATION" = "dedicated" ]; then
		pid_matches "$REDIS_PID" "127.0.0.1:$REDIS_PORT" || { echo "redis pid $REDIS_PID gone"; return 1; }
	fi
	probe_redis || { echo "redis not answering on :$REDIS_PORT"; return 1; }
	probe_ft || { echo "redis on :$REDIS_PORT has no FT (RediSearch) support"; return 1; }
	[ -f "$WP_ROOT/wp-load.php" ] && [ -f "$WP_ROOT/wp-config.php" ] \
		|| { echo "wordpress files missing at $WP_ROOT"; return 1; }
	probe_tests || { echo "wordpress-tests-lib missing at $WP_TESTS_DIR_RUN"; return 1; }
	return 0
}

probe_app() {
	pid_matches "$APP_PID" "$WP_ROOT" || { echo "wp server pid $APP_PID gone"; return 1; }
	probe_http || { echo "storefront not healthy at http://127.0.0.1:${HTTP_PORT}/search-e2e/"; return 1; }
}

# Full probe set. Prints the first failure; returns non-zero when unhealthy.
probe_all() {
	probe_infrastructure && probe_app
}

# --------------------------------------------------------------------------
# Service backends
# --------------------------------------------------------------------------

start_mysql() {
	MYSQL_CONTAINER=""
	if ! command -v mysqld >/dev/null && ! command -v mariadb-install-db >/dev/null; then
		start_mysql_docker
		return
	fi
	mkdir -p "$MYSQL_DIR"
	local user_flag=""
	[ "$(id -u)" = "0" ] && user_flag="--user=root"
	if [ ! -d "$MYSQL_DIR/mysql" ]; then
		log "Initializing MySQL datadir ($MYSQL_DIR)"
		if command -v mariadb-install-db >/dev/null; then
			mariadb-install-db --no-defaults --datadir="$MYSQL_DIR" \
				--auth-root-authentication-method=normal --skip-test-db \
				${user_flag:+"$user_flag"} >"$LOG_DIR/mysql-init-$RUN_ID.log" 2>&1 \
				|| die "mariadb-install-db failed — see $LOG_DIR/mysql-init-$RUN_ID.log"
		else
			mysqld --no-defaults --initialize-insecure --datadir="$MYSQL_DIR" \
				${user_flag:+"$user_flag"} >"$LOG_DIR/mysql-init-$RUN_ID.log" 2>&1 \
				|| die "mysqld --initialize-insecure failed — see $LOG_DIR/mysql-init-$RUN_ID.log"
		fi
	fi
	log "Starting MySQL on 127.0.0.1:$MYSQL_PORT"
	$SETSID nohup mysqld --no-defaults --datadir="$MYSQL_DIR" \
		--port="$MYSQL_PORT" --bind-address=127.0.0.1 \
		--socket="$MYSQL_SOCK" --pid-file="$MYSQL_DIR/mysqld.pid" \
		--skip-name-resolve ${user_flag:+"$user_flag"} \
		>>"$LOG_DIR/mysql-$RUN_ID.log" 2>&1 &
	MYSQL_PID=$!
	STARTED_PIDS="$STARTED_PIDS $MYSQL_PID"
	local i
	for i in $(seq 1 60); do
		mysqladmin --no-defaults --socket="$MYSQL_SOCK" -uroot ping >/dev/null 2>&1 && break
		[ "$i" = 60 ] && die "MySQL did not come up in 60s — see $LOG_DIR/mysql-$RUN_ID.log"
		sleep 1
	done
	# root only works via socket; every TCP consumer uses a dedicated user.
	mysql_admin -e "CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS'; GRANT ALL PRIVILEGES ON *.* TO '$DB_USER'@'%'; FLUSH PRIVILEGES;"
	probe_mysql || die "MySQL TCP probe failed after user setup"
}

# Docker fallback of the preflight chain "native mysqld -> docker": hosts
# (macOS dev machines in particular) with Docker but no server binary.
start_mysql_docker() {
	ensure_docker_ready
	log "Starting Docker MariaDB on 127.0.0.1:$MYSQL_PORT"
	MYSQL_CONTAINER="s64-mysql-$RUN_ID"
	MYSQL_PID=0
	docker image inspect mariadb:lts >/dev/null 2>&1 || {
		docker_pull_with_retry mariadb:lts "$LOG_DIR/mysql-$RUN_ID.log"
	}
	docker rm -f "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
	docker run -d --name "$MYSQL_CONTAINER" -p "127.0.0.1:$MYSQL_PORT:3306" \
		-e MARIADB_ROOT_PASSWORD=root -e MARIADB_ROOT_HOST=% \
		mariadb:lts >>"$LOG_DIR/mysql-$RUN_ID.log" 2>&1 \
		|| die "Docker MariaDB could not start — see $LOG_DIR/mysql-$RUN_ID.log"
	local i
	for i in $(seq 1 90); do
		mysqladmin --no-defaults -h127.0.0.1 -P"$MYSQL_PORT" -uroot -proot ping >/dev/null 2>&1 && break
		[ "$i" = 90 ] && die "Docker MariaDB did not come up in 90s — docker logs $MYSQL_CONTAINER"
		sleep 1
	done
	docker exec "$MYSQL_CONTAINER" mariadb -uroot -proot \
		-e "CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS'; GRANT ALL PRIVILEGES ON *.* TO '$DB_USER'@'%'; FLUSH PRIVILEGES;"
	probe_mysql || die "MySQL TCP probe failed after user setup (docker)"
}

# Distro redis-server 8.x ships the query engine as a loadable module wired
# in via the system config — a bare spawned instance lacks FT.* until the
# module is passed explicitly. Official Redis 8 builds have it built in.
find_redisearch_module() {
	local p
	for p in /usr/lib/redis/modules/redisearch.so /usr/lib64/redis/modules/redisearch.so \
		/opt/redis-stack/lib/redisearch.so /usr/local/lib/redis/modules/redisearch.so; do
		[ -f "$p" ] && { echo "$p"; return 0; }
	done
	# The MODULE LIST reply line carries redis-cli quoting — reduce it to the
	# bare path or --loadmodule receives a garbled argument.
	if command -v redis-cli >/dev/null 2>&1; then
		redis-cli -p 6379 MODULE LIST 2>/dev/null | grep -m1 'redisearch\.so' \
			| tr -d '"' | awk '{print $NF}' || true
	fi
}

start_redis() {
	mkdir -p "$REDIS_DIR"
	REDIS_CONTAINER=""
	REDIS_ISOLATION="dedicated"
	local binary=""
	command -v redis-stack-server >/dev/null && binary="redis-stack-server"
	[ -z "$binary" ] && command -v redis-server >/dev/null && binary="redis-server"
	if [ -n "$binary" ]; then
		local module=""
		if [ "$binary" = "redis-server" ]; then
			module="$(find_redisearch_module)"
		fi
		log "Starting $binary on 127.0.0.1:$REDIS_PORT${module:+ (with $module)}"
		$SETSID nohup "$binary" --port "$REDIS_PORT" --bind 127.0.0.1 \
			--dir "$REDIS_DIR" --save '' --appendonly no \
			${module:+--loadmodule "$module"} \
			>>"$LOG_DIR/redis-$RUN_ID.log" 2>&1 &
		REDIS_PID=$!
		STARTED_PIDS="$STARTED_PIDS $REDIS_PID"
		local i
		for i in $(seq 1 30); do
			probe_redis && break
			[ "$i" = 30 ] && die "Redis did not come up in 30s — see $LOG_DIR/redis-$RUN_ID.log"
			sleep 1
		done
		if probe_ft; then
			return 0
		fi
		warn "$binary on :$REDIS_PORT lacks FT.* (RediSearch) — falling back"
		kill "$REDIS_PID" 2>/dev/null || true
		REDIS_PID=0
	fi
	if command -v docker >/dev/null; then
		ensure_docker_ready
		log "Starting Docker redis/redis-stack-server on 127.0.0.1:$REDIS_PORT"
		REDIS_CONTAINER="s64-redis-$RUN_ID"
		docker image inspect redis/redis-stack-server:latest >/dev/null 2>&1 \
			|| docker_pull_with_retry redis/redis-stack-server:latest "$LOG_DIR/redis-$RUN_ID.log"
		docker rm -f "$REDIS_CONTAINER" >/dev/null 2>&1 || true
		docker run -d --name "$REDIS_CONTAINER" -p "127.0.0.1:$REDIS_PORT:6379" \
			redis/redis-stack-server:latest >>"$LOG_DIR/redis-$RUN_ID.log" 2>&1 \
			|| die "Docker redis-stack could not start — see $LOG_DIR/redis-$RUN_ID.log"
		local i
		for i in $(seq 1 30); do
			probe_redis && probe_ft && return 0
			sleep 1
		done
		die "Docker redis-stack did not become FT-ready in 30s"
	fi
	# Last resort: a shared, already-running RediSearch-capable instance,
	# isolated by a run-scoped key prefix (recorded truthfully).
	local shared="${TEST_ENV_SHARED_REDIS:-127.0.0.1:6379}"
	local shared_host="${shared%%:*}" shared_port="${shared##*:}"
	if command -v redis-cli >/dev/null 2>&1 \
		&& redis-cli -h "$shared_host" -p "$shared_port" ping 2>/dev/null | grep -q PONG \
		&& ! redis-cli -h "$shared_host" -p "$shared_port" FT._LIST 2>&1 | grep -qi '^ERR\|unknown command'; then
		warn "Using SHARED redis at $shared with run-scoped key prefix (degraded isolation)"
		REDIS_PORT="$shared_port"
		REDIS_PID=0
		REDIS_ISOLATION="shared-redis"
		return 0
	fi
	die "No RediSearch-capable Redis available (tried: native binary, docker, shared $shared)"
}

start_app_server() {
	log "Starting wp server on 127.0.0.1:$HTTP_PORT"
	start_detached app "$LOG_DIR/wp-server-$RUN_ID.log" \
		"$(command -v wp)" server --host=127.0.0.1 --port="$HTTP_PORT" --path="$WP_ROOT"
	APP_PID="$DETACHED_PID"
	APP_LAUNCH_LABEL="$DETACHED_LABEL"
	local i
	for i in $(seq 1 30); do
		curl -sf --max-time 5 "http://127.0.0.1:$HTTP_PORT/" >/dev/null 2>&1 && break
		[ "$i" = 30 ] && die "wp server did not answer in 30s — see $LOG_DIR/wp-server-$RUN_ID.log"
		sleep 1
	done
}

start_wordpress() {
	log "Installing WordPress + WooCommerce (bin/e2e-install-wp.sh)"
	mysql --no-defaults -h127.0.0.1 -P"$MYSQL_PORT" -u"$DB_USER" -p"$DB_PASS" \
		-e "CREATE DATABASE IF NOT EXISTS \`$WP_DB_NAME\`"
	# A rebuilt run may allocate new ports while reusing WP files — realign a
	# pre-existing wp-config.php before the installer's is-installed check.
	if [ -f "$WP_ROOT/wp-config.php" ]; then
		wpc config set DB_NAME "$WP_DB_NAME" >/dev/null
		wpc config set DB_USER "$DB_USER" >/dev/null
		wpc config set DB_PASSWORD "$DB_PASS" >/dev/null
		wpc config set DB_HOST "127.0.0.1:$MYSQL_PORT" >/dev/null
	fi
	WP_ROOT="$WP_ROOT" SITE_URL="http://127.0.0.1:$HTTP_PORT" \
		DB_NAME="$WP_DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" DB_HOST="127.0.0.1:$MYSQL_PORT" \
		bash "$SCRIPT_DIR/e2e-install-wp.sh"
	# The site URL follows the allocated port; align it on reuse-with-new-port.
	wpc option update siteurl "http://127.0.0.1:$HTTP_PORT" >/dev/null
	wpc option update home "http://127.0.0.1:$HTTP_PORT" >/dev/null

	start_app_server

	if [ "$REDIS_ISOLATION" = "shared-redis" ]; then
		wpc option update shift64_woo_search_redis_prefix "s64_${WORKTREE_HASH}" >/dev/null
	fi

	local skip_redis=""
	if [ "$HAVE_PHPREDIS" != "1" ]; then
		warn "phpredis missing in $PHP_BIN — provisioning without Redis wiring (native WooCommerce search fallback)"
		skip_redis=1
	fi
	log "Provisioning plugin state (bin/e2e-provision.sh)"
	WP_ROOT="$WP_ROOT" \
		REDIS_HOST=127.0.0.1 REDIS_PORT="$REDIS_PORT" \
		BASE_URL="http://127.0.0.1:$HTTP_PORT" \
		SKIP_REDIS_WIRING="$skip_redis" \
		bash "$SCRIPT_DIR/e2e-provision.sh"
}

provision_phpunit() {
	log "Provisioning WordPress PHPUnit runtime"
	mysql --no-defaults -h127.0.0.1 -P"$MYSQL_PORT" -u"$DB_USER" -p"$DB_PASS" \
		-e "CREATE DATABASE IF NOT EXISTS \`$TESTS_DB_NAME\`"
	if [ ! -f "$WP_TESTS_DIR_RUN/includes/functions.php" ]; then
		# SKIP_DB_CREATE=true: the database was just created above; the
		# installer's own create path prompts interactively on re-runs.
		( cd "$RUN_DIR" && \
			WP_TESTS_DIR="$WP_TESTS_DIR_RUN" WP_CORE_DIR="$WP_CORE_DIR_RUN" TMPDIR="$RUN_DIR" \
			bash "$SCRIPT_DIR/install-wp-tests.sh" \
				"$TESTS_DB_NAME" "$DB_USER" "$DB_PASS" "127.0.0.1:$MYSQL_PORT" latest true )
	fi
	probe_tests || die "wordpress-tests-lib provisioning failed ($WP_TESTS_DIR_RUN)"
	# Same port-drift concern as wp-config.php: a reused tests-lib must point
	# at the CURRENT run's MySQL port.
	if [ -f "$WP_TESTS_DIR_RUN/wp-tests-config.php" ]; then
		# In-place via temp file + mv: `sed -i` needs a suffix argument on
		# BSD/macOS sed and none on GNU sed — there is no portable spelling.
		sed "s|define( *'DB_HOST'.*|define( 'DB_HOST', '127.0.0.1:$MYSQL_PORT' );|" \
			"$WP_TESTS_DIR_RUN/wp-tests-config.php" > "$WP_TESTS_DIR_RUN/wp-tests-config.php.tmp"
		mv -f "$WP_TESTS_DIR_RUN/wp-tests-config.php.tmp" "$WP_TESTS_DIR_RUN/wp-tests-config.php"
	fi

	log "Generating worktree-local phpunit.xml (injects WP_TESTS_DIR)"
	awk -v tests_dir="$WP_TESTS_DIR_RUN" '
		/<\/phpunit>/ {
			print "\t<php>"
			print "\t\t<env name=\"WP_TESTS_DIR\" value=\"" tests_dir "\"/>"
			print "\t</php>"
		}
		{ print }
	' "$REPO_ROOT/phpunit.xml.dist" > "$REPO_ROOT/phpunit.xml"
}

install_repo_deps() {
	if [ ! -f "$REPO_ROOT/vendor/bin/phpunit" ]; then
		log "Installing Composer dependencies"
		( cd "$REPO_ROOT" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction )
	fi
	# Composer running as root can leave the bin stubs without exec bits
	# (observed on this repo's dev server) — "bare vendor/bin/phpunit" is part
	# of the contract, so repair them unconditionally.
	chmod +x "$REPO_ROOT"/vendor/bin/* 2>/dev/null || true
}

# --------------------------------------------------------------------------
# Teardown (shared by `down`, self-healing, and the abort trap)
# --------------------------------------------------------------------------

stop_app() {
	if [ -n "${APP_LAUNCH_LABEL:-}" ] && command -v launchctl >/dev/null 2>&1; then
		launchctl remove "$APP_LAUNCH_LABEL" >/dev/null 2>&1 || true
	fi
	if pid_matches "${APP_PID:-0}" "$WP_ROOT"; then
		kill -- -"$APP_PID" 2>/dev/null || kill "$APP_PID" 2>/dev/null || true
	fi
	if [ "${HTTP_PORT:-0}" -gt 0 ] 2>/dev/null; then
		pkill -f -- "-S 127.0.0.1:$HTTP_PORT" 2>/dev/null || true
	fi
	APP_PID=0
	APP_LAUNCH_LABEL=""
}

stop_validation() {
	if [ -n "${VALIDATION_LAUNCH_LABEL:-}" ] && command -v launchctl >/dev/null 2>&1; then
		launchctl remove "$VALIDATION_LAUNCH_LABEL" >/dev/null 2>&1 || true
	fi
	if pid_matches "${VALIDATION_PID:-0}" "$SCRIPT_DIR/test-env.sh _supervise"; then
		kill -- -"$VALIDATION_PID" 2>/dev/null || kill "$VALIDATION_PID" 2>/dev/null || true
	fi
	VALIDATION_PID=0
	VALIDATION_LAUNCH_LABEL=""
}

clear_validation_state() {
	[ -f "$DESCRIPTOR" ] && load_descriptor_state
	stop_validation
	rm -f "$VALIDATION_STATUS"
}

stop_owned() { # stop_owned <wipe-datadir: 0|1>
	local wipe="${1:-1}"
	[ -f "$DESCRIPTOR" ] && load_descriptor_state
	stop_app
	stop_validation
	if [ -n "${REDIS_CONTAINER:-}" ]; then
		docker rm -f "$REDIS_CONTAINER" >/dev/null 2>&1 || true
	elif [ "${REDIS_ISOLATION:-dedicated}" = "dedicated" ] && pid_matches "${REDIS_PID:-0}" "127.0.0.1:${REDIS_PORT:-0}"; then
		redis_exec shutdown nosave 2>/dev/null || kill "$REDIS_PID" 2>/dev/null || true
	fi
	if [ -n "${MYSQL_CONTAINER:-}" ]; then
		docker rm -f "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
	fi
	if pid_matches "${MYSQL_PID:-0}" "$MYSQL_DIR"; then
		mysqladmin --no-defaults --socket="$MYSQL_SOCK" -uroot shutdown 2>/dev/null || kill "$MYSQL_PID" 2>/dev/null || true
		local i
		for i in $(seq 1 15); do
			pid_matches "$MYSQL_PID" "$MYSQL_DIR" || break
			sleep 1
		done
	fi
	if [ "$wipe" = "1" ]; then
		rm -rf "$RUN_DIR"
	fi
}

abort_trap() {
	local exit_code=$?
	trap - EXIT
	if [ "$exit_code" -ne 0 ]; then
		warn "up aborted (exit $exit_code) — stopping processes started this run"
		stop_owned 0
		local pid
		for pid in $STARTED_PIDS; do
			kill -- -"$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
		done
		local label
		for label in $STARTED_LAUNCH_LABELS; do
			launchctl remove "$label" >/dev/null 2>&1 || true
		done
		if [ -f "$DESCRIPTOR" ]; then
			jq --arg note "up aborted at $(date -u +%Y-%m-%dT%H:%M:%SZ) (exit $exit_code)" \
				'.status = "unhealthy" | .notes = ((.notes // "") + " | " + $note)' \
				"$DESCRIPTOR" | atomic_write "$DESCRIPTOR" || true
		fi
	fi
	release_lock
	exit "$exit_code"
}

# --------------------------------------------------------------------------
# Lock (one `up`/`down` at a time per worktree)
# --------------------------------------------------------------------------

LOCK_DIR="$RUN_DIR/.lock"
acquire_lock() {
	mkdir -p "$RUN_DIR"
	if mkdir "$LOCK_DIR" 2>/dev/null; then
		echo $$ > "$LOCK_DIR/pid"
		return 0
	fi
	local holder
	holder="$(cat "$LOCK_DIR/pid" 2>/dev/null || echo "")"
	if [ -n "$holder" ] && pid_matches "$holder" "test-env"; then
		die "another test-env.sh (pid $holder) is operating on this worktree — retry when it finishes"
	fi
	warn "removing stale lock (pid ${holder:-unknown} is gone)"
	rm -rf "$LOCK_DIR"
	mkdir "$LOCK_DIR" && echo $$ > "$LOCK_DIR/pid"
}
release_lock() {
	[ -d "$LOCK_DIR" ] && [ "$(cat "$LOCK_DIR/pid" 2>/dev/null)" = "$$" ] && rm -rf "$LOCK_DIR" || true
}

# --------------------------------------------------------------------------
# Validation supervisor
# --------------------------------------------------------------------------

validation_commands() {
	if [ -f "$AGENTIC_CONFIG" ] && jq -e '.validation.commands | length > 0' "$AGENTIC_CONFIG" >/dev/null 2>&1; then
		jq -r '.validation.commands[]' "$AGENTIC_CONFIG"
	else
		printf '%s\n' "composer validate --strict" "vendor/bin/phpcs" "vendor/bin/phpunit"
	fi
}

write_validation_status() { # write_validation_status <status> <finishedAt-or-empty>
	jq -n \
		--arg runId "$RUN_ID" --arg generationId "$GENERATION_ID" --arg status "$1" \
		--arg startedAt "$SUPERVISOR_STARTED" --arg finishedAt "$2" \
		--argjson pid "$$" \
		--argjson commands "$COMMANDS_JSON" \
		--arg log "$SUPERVISOR_LOG_REL" \
		'{ runId: $runId, generationId: $generationId, status: $status, startedAt: $startedAt,
		   finishedAt: (if $finishedAt == "" then null else $finishedAt end),
		   pid: $pid, commands: $commands, log: $log }' \
		| atomic_write "$VALIDATION_STATUS"
}

supervise() {
	cd "$REPO_ROOT"
	# Some managed servers periodically strip exec bits under the web root
	# (this repo's dev server does); repair the gate's tools right before use.
	chmod +x "$REPO_ROOT"/vendor/bin/* 2>/dev/null || true
	SUPERVISOR_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	SUPERVISOR_LOG_REL=".ai/qa/logs/validation-$RUN_ID.log"
	local logfile="$REPO_ROOT/$SUPERVISOR_LOG_REL"
	mkdir -p "$LOG_DIR"
	: > "$logfile"

	local cmds=()
	while IFS= read -r line; do [ -n "$line" ] && cmds+=("$line"); done < <(validation_commands)
	COMMANDS_JSON="$(printf '%s\n' "${cmds[@]}" | jq -R '{command: ., status: "pending", exitCode: null, startedAt: null, finishedAt: null}' | jq -s '.')"
	write_validation_status "running" ""

	local overall=passed idx=0 cmd
	for cmd in "${cmds[@]}"; do
		COMMANDS_JSON="$(jq --argjson i "$idx" --arg t "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
			'.[$i].status = "running" | .[$i].startedAt = $t' <<<"$COMMANDS_JSON")"
		write_validation_status "running" ""
		printf '\n===== [%s] %s =====\n' "$(date -u +%H:%M:%SZ)" "$cmd" >> "$logfile"
		local code=0
		if [ "$overall" = "failed" ]; then
			# Skipped after an earlier failure: status "skipped", exitCode null.
			COMMANDS_JSON="$(jq --argjson i "$idx" '.[$i].status = "skipped"' <<<"$COMMANDS_JSON")"
		else
			set +e
			bash -c "$cmd" >> "$logfile" 2>&1
			code=$?
			set -e
			local st=passed; [ "$code" -ne 0 ] && { st=failed; overall=failed; }
			COMMANDS_JSON="$(jq --argjson i "$idx" --arg s "$st" --argjson c "$code" --arg t "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
				'.[$i].status = $s | .[$i].exitCode = $c | .[$i].finishedAt = $t' <<<"$COMMANDS_JSON")"
		fi
		write_validation_status "running" ""
		idx=$((idx + 1))
	done
	write_validation_status "$overall" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}

start_supervisor() {
	# Never run two gates at once: concurrent phpunit runs reinstall the same
	# tests DB tables and flake each other. A live running supervisor is reused.
	if [ -f "$VALIDATION_STATUS" ]; then
		local prev_status prev_pid
		prev_status="$(jq -r '.status // ""' "$VALIDATION_STATUS" 2>/dev/null)"
		prev_pid="$(jq -r '.pid // 0' "$VALIDATION_STATUS" 2>/dev/null)"
		if [ "$prev_status" = "running" ] && pid_matches "$prev_pid" "$SCRIPT_DIR/test-env.sh _supervise"; then
			VALIDATION_PID="$prev_pid"
			info "validation already running (pid $prev_pid) — not starting a second gate"
			return 0
		fi
	fi
	log "Starting background validation gate (logs: .ai/qa/logs/validation-$RUN_ID.log)"
	# Invoke via bash: exec bits on repo files cannot be relied on here.
	start_detached validation "$LOG_DIR/supervisor-$RUN_ID.log" bash "$SCRIPT_DIR/test-env.sh" _supervise
	VALIDATION_PID="$DETACHED_PID"
	VALIDATION_LAUNCH_LABEL="$DETACHED_LABEL"
	info "validation supervisor pid $VALIDATION_PID — poll .ai/qa/validation-status.json"
}

validation_state() { # echoes the supervisor status, repairing a dead-pid lie
	[ -f "$VALIDATION_STATUS" ] || { echo "none"; return; }
	local st pid status_generation
	status_generation="$(jq -r '.generationId // ""' "$VALIDATION_STATUS" 2>/dev/null)"
	[ -n "$GENERATION_ID" ] && [ "$status_generation" = "$GENERATION_ID" ] \
		|| { echo "none"; return; }
	st="$(jq -r '.status // "none"' "$VALIDATION_STATUS" 2>/dev/null)"
	pid="$(jq -r '.pid // 0' "$VALIDATION_STATUS" 2>/dev/null)"
	if [ "$st" = "running" ] && ! pid_matches "$pid" "$SCRIPT_DIR/test-env.sh _supervise"; then
		jq '.status = "aborted"' "$VALIDATION_STATUS" | atomic_write "$VALIDATION_STATUS" || true
		st="aborted"
	fi
	echo "$st"
}

# --------------------------------------------------------------------------
# Subcommands
# --------------------------------------------------------------------------

report_ready() { # report_ready <reused|restarted-app|rebuilt>
	local how="$1"
	echo
	echo "============================================================"
	echo " Test environment ready ($how)"
	echo "   Base URL:     http://127.0.0.1:$HTTP_PORT"
	echo "   QA page:      http://127.0.0.1:$HTTP_PORT/search-e2e/"
	echo "   Admin:        admin / admin  (http://127.0.0.1:$HTTP_PORT/wp-admin/)"
	echo "   MySQL:        127.0.0.1:$MYSQL_PORT ($DB_USER/$DB_PASS)"
	echo "   Redis:        127.0.0.1:$REDIS_PORT ($REDIS_ISOLATION)"
	echo "   WP_TESTS_DIR: $WP_TESTS_DIR_RUN (already wired into ./phpunit.xml)"
	echo "   Descriptor:   .ai/qa/test-env.json"
	echo "   PHPUnit:      vendor/bin/phpunit   (no exports needed)"
	echo "============================================================"
	if [ "$HAVE_PHPREDIS" != "1" ]; then
		echo
		echo "############################################################"
		echo "# ⚠ DEGRADED: plugin search is DISABLED (no phpredis)."
		echo "# Quick-search will not open and the search form falls back"
		echo "# to native WooCommerce search. Fix: pecl install redis,"
		echo "# then: bin/test-env.sh up --force"
		echo "############################################################"
	fi
}

cmd_up() {
	local validate=1 force=0 fresh=0 allow_degraded=0 arg
	for arg in "$@"; do
		case "$arg" in
			--validate) validate=1 ;;
			--no-validate) validate=0 ;;
			--force) force=1 ;;
			--fresh) fresh=1 ;;
			--allow-degraded) allow_degraded=1 ;;
			*) die "unknown flag for up: $arg" ;;
		esac
	done
	preflight
	mkdir -p "$RUN_DIR" "$QA_DIR" "$LOG_DIR"
	# Full search is the default contract: a degraded storefront (no plugin
	# search) must be an explicit choice, never a silent outcome.
	if ! ensure_phpredis; then
		if [ "$allow_degraded" = 1 ]; then
			warn "--allow-degraded: continuing WITHOUT plugin search (native WooCommerce search fallback)"
		else
			die "phpredis is required for full plugin search and automatic install did not succeed (see $LOG_DIR/pecl-redis-$RUN_ID.log). Install it manually (pecl install redis) or accept a searchless storefront with: bin/test-env.sh up --allow-degraded" 2
		fi
	fi
	make_wp_shim
	acquire_lock
	trap abort_trap EXIT INT TERM
	[ "$validate" = 1 ] || clear_validation_state

	if [ "$fresh" = 1 ]; then
		log "--fresh: wiping $RUN_DIR"
		stop_owned 1
		mkdir -p "$RUN_DIR"; acquire_lock
	elif [ "$force" = 1 ]; then
		log "--force: restarting environment"
		stop_owned 0
	elif [ -f "$DESCRIPTOR" ]; then
		local failure
		if failure="$(probe_all)"; then
			# probe_all ran in a command substitution — reload the recorded
			# ports/pids into THIS shell before reporting.
			load_descriptor_state
			install_repo_deps
			if [ -z "$GENERATION_ID" ]; then begin_generation "reused"; else RECOVERY_MODE="reused"; fi
			write_descriptor "running" "reused healthy environment"
			report_ready "reused"
			[ "$validate" = 1 ] && { start_supervisor; write_descriptor "running" "reused healthy environment"; }
			return 0
		fi
		if failure="$(probe_infrastructure)"; then
			load_descriptor_state
			[ -n "$GENERATION_ID" ] || begin_generation "restarted-app"
			log "Application layer unhealthy — restarting wp server only"
			stop_app
			start_app_server
			RECOVERY_MODE="restarted-app"
			# probe_infrastructure reloads descriptor state; persist the new app
			# PID/launch label first so it cannot restore the dead process record.
			write_descriptor "provisioning" "application layer restarted; verifying health"
			failure="$(probe_all)" || die "application restart completed but environment is unhealthy: $failure"
			write_descriptor "running" "restarted application layer; services and catalog preserved"
			report_ready "restarted-app"
			[ "$validate" = 1 ] && { start_supervisor; write_descriptor "running" "restarted application layer; services and catalog preserved"; }
			return 0
		fi
		log "Existing environment unhealthy ($failure) — rebuilding"
		stop_owned 0
	fi

	install_repo_deps

	# Allocate ports (reuse recorded ones when still free — stable URLs).
	load_descriptor_state 2>/dev/null || true
	{ [ "${HTTP_PORT:-0}" -gt 0 ] && ! port_listening "$HTTP_PORT"; } || HTTP_PORT="$(free_port)"
	{ [ "${MYSQL_PORT:-0}" -gt 0 ] && ! port_listening "$MYSQL_PORT"; } || MYSQL_PORT="$(free_port)"
	{ [ "${REDIS_PORT:-0}" -gt 0 ] && ! port_listening "$REDIS_PORT"; } || REDIS_PORT="$(free_port)"
	APP_PID=0; MYSQL_PID=0; REDIS_PID=0; VALIDATION_PID=0; MYSQL_CONTAINER=""; REDIS_CONTAINER=""; REDIS_ISOLATION="dedicated"
	APP_LAUNCH_LABEL=""; VALIDATION_LAUNCH_LABEL=""
	begin_generation "rebuilt"
	write_descriptor "provisioning" "up started"

	start_mysql
	write_descriptor "provisioning" "mysql up"
	start_redis
	write_descriptor "provisioning" "redis up"
	start_wordpress
	write_descriptor "provisioning" "wordpress up"
	provision_phpunit

	local notes="ok"
	[ "$HAVE_PHPREDIS" != "1" ] && notes="DEGRADED: phpredis missing — Redis wiring skipped, native WooCommerce search only"
	[ "$REDIS_ISOLATION" = "shared-redis" ] && notes="$notes | DEGRADED: shared redis instance, isolated by key prefix s64_${WORKTREE_HASH}"
	write_descriptor "provisioning" "$notes"

	local failure
	failure="$(probe_all)" || die "environment provisioned but unhealthy: $failure"
	write_descriptor "running" "$notes"
	report_ready "rebuilt"
	if [ "$validate" = 1 ]; then
		start_supervisor
		write_descriptor "running" "$notes"
	fi
}

cmd_status() {
	local as_json=0 arg
	for arg in "$@"; do
		case "$arg" in
			--json) as_json=1 ;;
			*) die "unknown flag for status: $arg" ;;
		esac
	done
	select_php
	[ -f "$DESCRIPTOR" ] || { echo "No test environment (no descriptor at .ai/qa/test-env.json). Run: bin/test-env.sh up"; exit 4; }
	local desc_status failure="" healthy=1
	desc_status="$(descriptor_get '.status')"
	if [ "$desc_status" = "stopped" ]; then
		[ "$as_json" = 1 ] && cat "$DESCRIPTOR" || echo "Environment stopped (descriptor says so)."
		exit 4
	fi
	# A live `up` is mid-provision: report that truthfully instead of probing
	# a half-built environment and clobbering its descriptor state.
	if [ "$desc_status" = "provisioning" ] && [ -d "$LOCK_DIR" ] \
		&& pid_matches "$(cat "$LOCK_DIR/pid" 2>/dev/null)" "test-env.sh"; then
		[ "$as_json" = 1 ] && cat "$DESCRIPTOR" || echo "Environment provisioning (an up run is in progress)."
		exit 3
	fi
	if ! failure="$(probe_all)"; then
		healthy=0
		jq '.status = "unhealthy"' "$DESCRIPTOR" | atomic_write "$DESCRIPTOR"
	else
		jq '.status = "running"' "$DESCRIPTOR" | atomic_write "$DESCRIPTOR"
	fi
	local vstate
	load_descriptor_state
	vstate="$(validation_state)"
	if [ "$as_json" = 1 ]; then
		if [ "$vstate" = "none" ]; then
			jq '. + {validationStatus: null}' "$DESCRIPTOR"
		else
			jq --slurpfile v "$VALIDATION_STATUS" '. + {validationStatus: ($v[0] // null)}' "$DESCRIPTOR"
		fi
	else
		if [ "$healthy" = 1 ]; then
			echo "Environment healthy: $(descriptor_get '.baseUrl') (validation: $vstate)"
		else
			echo "Environment UNHEALTHY: $failure"
		fi
	fi
	[ "$healthy" = 0 ] && exit 3
	case "$vstate" in failed|aborted) exit 5 ;; esac
	exit 0
}

cmd_down() {
	local arg
	for arg in "$@"; do
		case "$arg" in
			--keep-logs) : ;; # logs live in .ai/qa/logs and are always kept
			*) die "unknown flag for down: $arg" ;;
		esac
	done
	select_php
	[ -f "$DESCRIPTOR" ] || { echo "Nothing to stop (no descriptor)."; exit 0; }
	# acquire_lock dies loudly when a LIVE up/down holds the lock — tearing an
	# environment down mid-provision would leave half-started resources
	# untracked. A stale lock (dead holder) is recovered inside acquire_lock.
	acquire_lock
	log "Stopping environment $RUN_ID"
	stop_owned 1
	jq '.status = "stopped" | .app.pid = 0 | .app.launchLabel = null |
		(.services[] | select(has("pid"))).pid = 0 |
		.validation.pid = 0 | .validation.launchLabel = null' \
		"$DESCRIPTOR" | atomic_write "$DESCRIPTOR"
	release_lock
	echo "Stopped. Logs kept under .ai/qa/logs/."
}

# --------------------------------------------------------------------------
# Entry
# --------------------------------------------------------------------------

usage() {
	sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'
	exit 1
}

[ $# -ge 1 ] || usage
COMMAND="$1"; shift
case "$COMMAND" in
	up)         cmd_up "$@" ;;
	status)     cmd_status "$@" ;;
	down)       cmd_down "$@" ;;
	_supervise) select_php; load_descriptor_state; supervise ;;
	_launchd_wrapper) launchd_wrapper "$@" ;;
	_preflight) preflight ;;
	*)          usage ;;
esac
