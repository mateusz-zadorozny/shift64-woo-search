#!/usr/bin/env bash
#
# CI-only: install WordPress + WooCommerce from zero on the runner and symlink
# this checkout into wp-content/plugins/. bin/e2e-provision.sh takes over from
# there (it activates plugins, seeds, and wires Redis).
#
# Assumes the MySQL service at 127.0.0.1:3306 (root/root, db wordpress_e2e).
#
# Storefront is installed deliberately: stock block themes render WooCommerce's
# blockified archive templates, which never fire woocommerce_before_shop_loop —
# the hook the filter bar (and the ordering control) depends on. A classic Woo
# theme guarantees the shop-loop hooks the journey tests assert on.
#
# WordPress, WooCommerce, and Storefront versions are deliberately UNPINNED:
# catching breakage against the latest ecosystem releases is part of what this
# suite exists for. If an upstream release reds the gate, that is signal — pin
# temporarily only to unblock an unrelated release, and file the breakage.

set -euo pipefail

WP_ROOT="${WP_ROOT:-/tmp/wordpress-e2e}"
SITE_URL="${SITE_URL:-http://127.0.0.1:8889}"
DB_NAME="${DB_NAME:-wordpress_e2e}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
DB_HOST="${DB_HOST:-127.0.0.1}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SRC="$(dirname "$SCRIPT_DIR")"

mkdir -p "$WP_ROOT"

if [ ! -f "$WP_ROOT/wp-load.php" ]; then
	wp core download --path="$WP_ROOT"
fi

if [ ! -f "$WP_ROOT/wp-config.php" ]; then
	wp config create --path="$WP_ROOT" \
		--dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST"
fi

if ! wp core is-installed --path="$WP_ROOT" 2>/dev/null; then
	wp core install --path="$WP_ROOT" \
		--url="$SITE_URL" --title="Shift64 E2E" \
		--admin_user=admin --admin_password=admin --admin_email=e2e@example.com \
		--skip-email
fi

wp theme install storefront --activate --path="$WP_ROOT"
wp plugin install woocommerce --activate --path="$WP_ROOT"

ln -sfn "$PLUGIN_SRC" "$WP_ROOT/wp-content/plugins/shift64-woo-search"

echo "WordPress + WooCommerce installed at $WP_ROOT (plugin symlinked from $PLUGIN_SRC)."
