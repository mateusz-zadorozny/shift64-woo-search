#!/usr/bin/env bash
# bin/install-phpredis-local.sh
#
# Build and install the phpredis extension into Local by Flywheel's bundled
# PHP for this site. Re-run after Local upgrades PHP (e.g. 8.4.18 -> 8.4.19),
# after switching the site's PHP version in Local UI, or when "Test Connection"
# in the plugin shows "phpredis extension is not installed".
#
# Usage:
#   bin/install-phpredis-local.sh                   # auto-detect running site
#   bin/install-phpredis-local.sh <SITE_ID>         # explicit Local site id
#
# The site must be RUNNING in Local before invoking this script (the script
# detects which PHP bundle the site uses by inspecting the running php-fpm
# master). See docs/local-phpredis-setup.md for background and troubleshooting.

set -euo pipefail

LOCAL_DIR="$HOME/Library/Application Support/Local"
RUN_DIR="$LOCAL_DIR/run"

# ---------------------------------------------------------------------------
# 1. Pick the Local site (explicit arg or first running php-fpm we can find)
# ---------------------------------------------------------------------------

detect_running_site() {
    local cmds
    cmds=$(ps ax -o command 2>/dev/null) || return 1
    for site_dir in "$RUN_DIR"/*; do
        [ -d "$site_dir" ] || continue
        local id
        id=$(basename "$site_dir")
        [ "$id" = "router" ] && continue
        case "$cmds" in
            *"Local/run/$id/conf/php"*)
                echo "$id"
                return 0
                ;;
        esac
    done
    return 1
}

SITE_ID="${1:-}"
if [ -z "$SITE_ID" ]; then
    SITE_ID=$(detect_running_site) || {
        echo "ERROR: no running Local site detected. Start the site in Local," >&2
        echo "       or pass its SITE_ID explicitly:" >&2
        echo "         bin/install-phpredis-local.sh <SITE_ID>" >&2
        exit 1
    }
fi

SITE_CONF="$RUN_DIR/$SITE_ID"
SITE_INI="$SITE_CONF/conf/php/php.ini"
if [ ! -f "$SITE_INI" ]; then
    echo "ERROR: site '$SITE_ID' has no php.ini at:" >&2
    echo "       $SITE_INI" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Detect which PHP bundle the running php-fpm master is using.
#    We do not trust Local's php.ini "extension_dir" — Local hardcodes a CI
#    path (/Users/distiller/project/...) into both phpize and the PHP binary.
# ---------------------------------------------------------------------------

# Capture producer output to variables, then awk-parse via here-string (<<<).
# This avoids SIGPIPE+pipefail aborts: with a here-string, awk reads from a
# temp fd rather than a pipe, so awk's `exit` doesn't send SIGPIPE upstream.
PS_OUTPUT=$(ps ax -o pid=,command= 2>/dev/null || true)
PHPFPM_PID=$(awk -v conf="$SITE_CONF/conf/php" '
    index($0, "php-fpm: master") && index($0, conf) { print $1; exit }' \
    <<<"$PS_OUTPUT")
if [ -z "$PHPFPM_PID" ]; then
    echo "ERROR: site '$SITE_ID' is not running. Start it in Local first." >&2
    exit 1
fi

LSOF_OUTPUT=$(lsof -p "$PHPFPM_PID" 2>/dev/null || true)
# Match the txt mapping for php-fpm executable. The path lives under
# ~/Library/Application Support/Local/... so it contains a space — we anchor
# on /Users/ at the start and let .* span the space.
PHP_BUNDLE=$(awk '
    match($0, /\/Users\/.*\/lightning-services\/php-[^\/]+\/bin\/[^\/]+\/sbin\/php-fpm$/) {
        path = substr($0, RSTART, RLENGTH)
        sub(/\/sbin\/php-fpm$/, "", path)
        print path
        exit
    }' <<<"$LSOF_OUTPUT")
if [ -z "$PHP_BUNDLE" ] || [ ! -x "$PHP_BUNDLE/bin/php" ]; then
    echo "ERROR: couldn't detect PHP bundle for site '$SITE_ID'." >&2
    echo "       php-fpm PID was $PHPFPM_PID." >&2
    exit 1
fi

PHP_BUNDLE_NAME=$(basename "$(dirname "$(dirname "$PHP_BUNDLE")")")
LOCAL_PHP_BIN="$PHP_BUNDLE/bin/php"
LOCAL_PHPIZE="$PHP_BUNDLE/bin/phpize"
LOCAL_PHP_CONFIG="$PHP_BUNDLE/bin/php-config"

PHP_INFO_OUTPUT=$("$LOCAL_PHP_BIN" -i 2>/dev/null || true)
PHP_API=$(awk -F' => ' '
    /^PHP API/ { gsub(/[ \r]/, "", $2); print $2; exit }' \
    <<<"$PHP_INFO_OUTPUT")
if [ -z "$PHP_API" ]; then
    echo "ERROR: couldn't determine PHP API version." >&2
    exit 1
fi

EXT_DIR="$PHP_BUNDLE/lib/php/extensions/no-debug-non-zts-$PHP_API"
EXT_PATH="$EXT_DIR/redis.so"

echo "==> Site:           $SITE_ID"
echo "==> PHP bundle:     $PHP_BUNDLE_NAME"
echo "==> PHP API:        $PHP_API"
echo "==> Extension dir:  $EXT_DIR"

# ---------------------------------------------------------------------------
# 3. Build phpredis.
#    Two Local-specific obstacles to bypass:
#      (a) phpize/php-config have a hardcoded CI prefix
#          (/Users/distiller/project/php/<ver>/bin/darwin-arm64).
#      (b) phpize refuses paths containing whitespace, and the real bundle
#          path contains "Application Support". We work around this by
#          symlinking the bundle into /tmp under a no-space name and pointing
#          patched copies of phpize/php-config at that symlink.
# ---------------------------------------------------------------------------

DISTILLER=$(grep -oE "/Users/distiller/project/php/[^'\" ]+" "$LOCAL_PHPIZE" 2>/dev/null \
    | head -1 || true)

WORK=$(mktemp -d -t phpredis-build)
SHIM="/tmp/lp-phpredis-shim.$$"
cleanup() {
    rm -rf "$WORK"
    [ -L "$SHIM" ] && rm -f "$SHIM"
}
trap cleanup EXIT

ln -s "$PHP_BUNDLE" "$SHIM"
mkdir -p "$WORK/patched-bin"
if [ -n "$DISTILLER" ]; then
    sed "s|$DISTILLER|$SHIM|g" "$LOCAL_PHPIZE"     > "$WORK/patched-bin/phpize"
    sed "s|$DISTILLER|$SHIM|g" "$LOCAL_PHP_CONFIG" > "$WORK/patched-bin/php-config"
    echo "==> Patched CI prefix:  $DISTILLER -> $SHIM"
else
    cp "$LOCAL_PHPIZE"     "$WORK/patched-bin/phpize"
    cp "$LOCAL_PHP_CONFIG" "$WORK/patched-bin/php-config"
    echo "==> No CI prefix found in phpize (already clean)."
fi
chmod +x "$WORK/patched-bin/phpize" "$WORK/patched-bin/php-config"

echo "==> Cloning phpredis (master)..."
git -C "$WORK" clone --depth 1 https://github.com/phpredis/phpredis.git >/dev/null 2>&1
cd "$WORK/phpredis"

LOG="$WORK/build.log"
{
    echo "--- phpize ---"
    "$WORK/patched-bin/phpize"
    echo "--- configure ---"
    ./configure --with-php-config="$WORK/patched-bin/php-config" --enable-redis
    echo "--- make ---"
    make -j"$(sysctl -n hw.ncpu 2>/dev/null || echo 4)"
} >"$LOG" 2>&1 || {
    echo "ERROR: build failed. Full log:" >&2
    echo "       $LOG" >&2
    tail -30 "$LOG" >&2
    trap - EXIT  # keep WORK around so the user can inspect $LOG
    exit 2
}

[ -f modules/redis.so ] || {
    echo "ERROR: build succeeded but modules/redis.so missing." >&2
    exit 2
}
echo "==> Built: $(file -b modules/redis.so)"

# ---------------------------------------------------------------------------
# 4. Sanity check — does CLI PHP actually load the freshly built .so?
# ---------------------------------------------------------------------------

echo "==> Loading test (CLI)..."
"$LOCAL_PHP_BIN" -d "extension=$WORK/phpredis/modules/redis.so" -r '
    if (!class_exists("Redis")) {
        fwrite(STDERR, "class Redis not registered\n");
        exit(1);
    }
    fwrite(STDOUT, "    phpredis " . phpversion("redis") . " loads OK\n");
' || {
    echo "ERROR: freshly-built redis.so failed to load." >&2
    exit 3
}

# ---------------------------------------------------------------------------
# 5. Install: copy .so into Local's extension dir + patch site php.ini.
#    The php.ini line MUST use the absolute path because Local's PHP binary
#    has its extension_dir hardcoded to the distiller CI path.
# ---------------------------------------------------------------------------

mkdir -p "$EXT_DIR"
cp "$WORK/phpredis/modules/redis.so" "$EXT_PATH"
echo "==> Installed:      $EXT_PATH"

BACKUP="${SITE_INI}.bak.$(date +%s)"
cp "$SITE_INI" "$BACKUP"
echo "==> Backup of ini:  $BACKUP"

NEEDED_LINE="extension = $EXT_PATH"
if grep -qE "^[^;]*extension *= *.*redis\.so" "$SITE_INI"; then
    # Remove any existing (possibly wrong-path) redis extension line, then re-add canonical absolute path.
    awk '!/^[^;]*extension *= *.*redis\.so/' "$SITE_INI" > "$SITE_INI.new"
    mv "$SITE_INI.new" "$SITE_INI"
    printf '\n; phpredis extension for Shift64 Woo Search\n%s\n' "$NEEDED_LINE" >> "$SITE_INI"
    echo "==> Replaced existing redis extension line with absolute path."
else
    printf '\n; phpredis extension for Shift64 Woo Search\n%s\n' "$NEEDED_LINE" >> "$SITE_INI"
    echo "==> Appended extension line to site php.ini."
fi

# ---------------------------------------------------------------------------
# 6. Graceful reload of php-fpm so the new ini takes effect without dropping
#    requests. USR2 = "reload config, gracefully replace workers".
# ---------------------------------------------------------------------------

echo "==> Reloading php-fpm (USR2 -> PID $PHPFPM_PID)..."
kill -USR2 "$PHPFPM_PID"
sleep 1

# ---------------------------------------------------------------------------
# 7. End-to-end verify via the site's own php.ini.
# ---------------------------------------------------------------------------

echo "==> Final verify (using site php.ini)..."
if "$LOCAL_PHP_BIN" -c "$SITE_INI" -r '
    if (!class_exists("Redis")) { fwrite(STDERR, "class Redis not registered\n"); exit(1); }
    $r = new Redis();
    if (!@$r->connect("127.0.0.1", 6379, 1.0)) {
        fwrite(STDERR, "Redis connect failed (is the redis-stack container running on 6379?)\n");
        exit(2);
    }
    $pong = $r->ping();
    if ($pong !== true && $pong !== "+PONG" && $pong !== "PONG") {
        fwrite(STDERR, "PING returned: " . var_export($pong, true) . "\n");
        exit(3);
    }
    fwrite(STDOUT, "    OK: phpredis " . phpversion("redis") . ", Redis responded to PING\n");
'; then
    echo
    echo "Done. In the plugin admin:"
    echo "  1. Open WooCommerce -> Shift64 Woo Search -> Settings"
    echo "  2. Click 'Test Connection' (should be green)"
    echo "  3. Click 'Regenerate SHORTINIT Config'"
else
    rc=$?
    echo "ERROR: final verify failed (exit $rc)." >&2
    echo "       The extension is installed but PHP-FPM may not have picked it up." >&2
    echo "       Try restarting the site from Local UI." >&2
    exit 3
fi
