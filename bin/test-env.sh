#!/usr/bin/env bash
#
# One-shot isolated test environment for this worktree.
#
#   bin/test-env.sh up [--validate|--no-validate] [--force] [--fresh]
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
MYSQL_SOCK="$MYSQL_DIR/mysql.sock"

DB_USER="wp"
DB_PASS="wp"
WP_DB_NAME="wp_e2e_${WORKTREE_HASH}"
TESTS_DB_NAME="wp_tests_${WORKTREE_HASH}"

# PIDs started by THIS invocation (for the abort trap only). A plain
# space-separated string, not an array: empty-array expansion under `set -u`
# is fatal on bash 3.2 (macOS's default /bin/bash).
STARTED_PIDS=""

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
	for candidate in php php8.5 php8.4 php8.3; do
		command -v "$candidate" >/dev/null 2>&1 || continue
		if "$candidate" -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("mysqli") ? 0 : 1);' 2>/dev/null; then
			PHP_BIN="$(command -v "$candidate")"
			return
		fi
	done
	PHP_BIN=""
}

# Known limitation: allocation-to-bind is a TOCTOU window with no
# cross-worktree coordination. A stolen port makes the service start fail
# loudly within its wait loop, and the next `up` self-heals with fresh ports.
free_port() {
	"$PHP_BIN" -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);if(!$s){exit(1);}preg_match("/:(\d+)$/",stream_socket_get_name($s,false),$x);echo $x[1];'
}

port_listening() {
	"$PHP_BIN" -r '$c=@stream_socket_client("tcp://127.0.0.1:".$argv[1],$e,$m,1);exit($c?0:1);' "$1" 2>/dev/null
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

# setsid detaches services into their own process group so group-kills work;
# macOS ships no setsid, so degrade to a plain prefix-less spawn there (the
# per-PID kills and the port-scoped pkill sweep still stop everything owned).
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
	# 24567 = E_ALL & ~E_DEPRECATED & ~E_NOTICE.
	local php_args="-d memory_limit=512M -d error_reporting=24567"
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

preflight() {
	local missing=()
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
	# NOT `probe && VAR=1` — as the function's last statement that returns 1
	# when the extension is absent, and set -e then kills the whole script.
	HAVE_PHPREDIS=0
	if "$PHP_BIN" -m 2>/dev/null | grep -qx redis; then
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
		--arg runId "$RUN_ID" --arg status "$status" \
		--arg baseUrl "http://127.0.0.1:${HTTP_PORT:-0}" \
		--arg wpRoot "$WP_ROOT" \
		--argjson httpPort "${HTTP_PORT:-0}" --argjson appPid "${APP_PID:-0}" \
		--argjson mysqlPort "${MYSQL_PORT:-0}" --argjson mysqlPid "${MYSQL_PID:-0}" \
		--arg mysqlContainer "${MYSQL_CONTAINER:-}" --arg mysqlDatadir "$MYSQL_DIR" \
		--argjson redisPort "${REDIS_PORT:-0}" --argjson redisPid "${REDIS_PID:-0}" \
		--arg redisContainer "${REDIS_CONTAINER:-}" --arg redisIsolation "${REDIS_ISOLATION:-dedicated}" \
		--arg wpTestsDir "$WP_TESTS_DIR_RUN" --arg wpCoreDir "$WP_CORE_DIR_RUN" \
		--arg testDb "$TESTS_DB_NAME" \
		--arg validationStatusFile ".ai/qa/validation-status.json" \
		--argjson validationPid "${VALIDATION_PID:-0}" \
		--arg platform "$(uname -s | tr '[:upper:]' '[:lower:]')" \
		--arg startedAt "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
		--arg notes "$notes" \
		'{
			version: 1, runId: $runId, status: $status, mode: "ephemeral",
			baseUrl: $baseUrl, startedByThisRepo: true,
			startScript: ".ai/scripts/test-env-up.sh", stopScript: ".ai/scripts/test-env-down.sh",
			app: { startCommand: "wp server", port: $httpPort, healthPath: "/search-e2e/", pid: $appPid, wpRoot: $wpRoot },
			services: [
				{ type: "mysql", host: "127.0.0.1", port: $mysqlPort, pid: $mysqlPid,
				  container: (if $mysqlContainer == "" then null else $mysqlContainer end), datadir: $mysqlDatadir },
				{ type: "redis", host: "127.0.0.1", port: $redisPort, pid: $redisPid,
				  container: (if $redisContainer == "" then null else $redisContainer end), isolation: $redisIsolation }
			],
			credentials: [ { role: "admin", username: "admin", password: "admin" } ],
			phpunit: { wpTestsDir: $wpTestsDir, wpCoreDir: $wpCoreDir, testDb: $testDb, phpunitXml: "./phpunit.xml" },
			validation: { statusFile: $validationStatusFile, pid: $validationPid },
			browser: { provider: "agent-browser", installed: false, command: "", version: "", descriptor: ".ai/browsers/agent-browser.md", notes: "provisioned separately by om-prepare-test-env" },
			testRunner: { name: "playwright", config: "playwright.config.ts" },
			platform: $platform, startedAt: $startedAt, notes: $notes
		}' | atomic_write "$DESCRIPTOR"
}

# Load recorded state (ports/pids) from an existing descriptor into shell vars.
load_descriptor_state() {
	HTTP_PORT="$(descriptor_get '.app.port // 0')"
	APP_PID="$(descriptor_get '.app.pid // 0')"
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

# Full probe set. Prints the first failure; returns non-zero when unhealthy.
probe_all() {
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
	pid_matches "$APP_PID" "$WP_ROOT" || { echo "wp server pid $APP_PID gone"; return 1; }
	probe_http || { echo "storefront not healthy at http://127.0.0.1:${HTTP_PORT}/search-e2e/"; return 1; }
	probe_tests || { echo "wordpress-tests-lib missing at $WP_TESTS_DIR_RUN"; return 1; }
	return 0
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
	log "Starting Docker MariaDB on 127.0.0.1:$MYSQL_PORT"
	MYSQL_CONTAINER="s64-mysql-$RUN_ID"
	MYSQL_PID=0
	docker image inspect mariadb:lts >/dev/null 2>&1 || {
		info "pulling mariadb:lts (first run only)"
		docker pull mariadb:lts >>"$LOG_DIR/mysql-$RUN_ID.log" 2>&1 || die "docker pull mariadb:lts failed"
	}
	docker rm -f "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
	docker run -d --name "$MYSQL_CONTAINER" -p "127.0.0.1:$MYSQL_PORT:3306" \
		-e MARIADB_ROOT_PASSWORD=root -e MARIADB_ROOT_HOST=% \
		mariadb:lts >/dev/null
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
		log "Starting Docker redis/redis-stack-server on 127.0.0.1:$REDIS_PORT"
		REDIS_CONTAINER="s64-redis-$RUN_ID"
		docker rm -f "$REDIS_CONTAINER" >/dev/null 2>&1 || true
		docker run -d --name "$REDIS_CONTAINER" -p "127.0.0.1:$REDIS_PORT:6379" \
			redis/redis-stack-server:latest >/dev/null
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

	log "Starting wp server on 127.0.0.1:$HTTP_PORT"
	$SETSID nohup wp server --host=127.0.0.1 --port="$HTTP_PORT" --path="$WP_ROOT" \
		>>"$LOG_DIR/wp-server-$RUN_ID.log" 2>&1 &
	APP_PID=$!
	STARTED_PIDS="$STARTED_PIDS $APP_PID"
	local i
	for i in $(seq 1 30); do
		curl -sf --max-time 5 "http://127.0.0.1:$HTTP_PORT/" >/dev/null 2>&1 && break
		[ "$i" = 30 ] && die "wp server did not answer in 30s — see $LOG_DIR/wp-server-$RUN_ID.log"
		sleep 1
	done

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

stop_owned() { # stop_owned <wipe-datadir: 0|1>
	local wipe="${1:-1}"
	[ -f "$DESCRIPTOR" ] && load_descriptor_state
	if pid_matches "${APP_PID:-0}" "$WP_ROOT"; then
		kill -- -"$APP_PID" 2>/dev/null || kill "$APP_PID" 2>/dev/null || true
	fi
	if pid_matches "${VALIDATION_PID:-0}" "$SCRIPT_DIR/test-env.sh _supervise"; then
		kill -- -"$VALIDATION_PID" 2>/dev/null || kill "$VALIDATION_PID" 2>/dev/null || true
	fi
	if [ -n "${REDIS_CONTAINER:-}" ]; then
		docker rm -f "$REDIS_CONTAINER" >/dev/null 2>&1 || true
	elif [ "${REDIS_ISOLATION:-dedicated}" = "dedicated" ] && pid_matches "${REDIS_PID:-0}" "127.0.0.1:$REDIS_PORT"; then
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
	# Catch the one orphan class the targeted kills miss: php -S children
	# surviving a crashed wp-server parent. Match on this run's HTTP port —
	# precise, unlike a broad run-dir sweep that could hit a developer's
	# `tail -f <run>/mysql.log`.
	if [ "${HTTP_PORT:-0}" -gt 0 ] 2>/dev/null; then
		pkill -f -- "-S 127.0.0.1:$HTTP_PORT" 2>/dev/null || true
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
		local pid
		for pid in $STARTED_PIDS; do
			kill -- -"$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
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
		--arg runId "$RUN_ID" --arg status "$1" \
		--arg startedAt "$SUPERVISOR_STARTED" --arg finishedAt "$2" \
		--argjson pid "$$" \
		--argjson commands "$COMMANDS_JSON" \
		--arg log "$SUPERVISOR_LOG_REL" \
		'{ runId: $runId, status: $status, startedAt: $startedAt,
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
	$SETSID nohup bash "$SCRIPT_DIR/test-env.sh" _supervise >>"$LOG_DIR/supervisor-$RUN_ID.log" 2>&1 &
	VALIDATION_PID=$!
	info "validation supervisor pid $VALIDATION_PID — poll .ai/qa/validation-status.json"
}

validation_state() { # echoes the supervisor status, repairing a dead-pid lie
	[ -f "$VALIDATION_STATUS" ] || { echo "none"; return; }
	local st pid
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

report_ready() { # report_ready <reused|provisioned>
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
}

cmd_up() {
	local validate=1 force=0 fresh=0 arg
	for arg in "$@"; do
		case "$arg" in
			--validate) validate=1 ;;
			--no-validate) validate=0 ;;
			--force) force=1 ;;
			--fresh) fresh=1 ;;
			*) die "unknown flag for up: $arg" ;;
		esac
	done
	preflight
	mkdir -p "$RUN_DIR" "$QA_DIR" "$LOG_DIR"
	make_wp_shim
	acquire_lock
	trap abort_trap EXIT INT TERM

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
			write_descriptor "running" "reused healthy environment"
			report_ready "reused"
			[ "$validate" = 1 ] && { start_supervisor; write_descriptor "running" "reused healthy environment"; }
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
	report_ready "provisioned"
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
	vstate="$(validation_state)"
	if [ "$as_json" = 1 ]; then
		jq --slurpfile v <(cat "$VALIDATION_STATUS" 2>/dev/null || echo null) '. + {validationStatus: ($v[0] // null)}' "$DESCRIPTOR"
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
	jq '.status = "stopped" | .app.pid = 0 | (.services[] | select(has("pid"))).pid = 0 | .validation.pid = 0' \
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
	_supervise) select_php; supervise ;;
	*)          usage ;;
esac
