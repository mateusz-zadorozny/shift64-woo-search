#!/usr/bin/env bash
#
# CI-only: install WordPress + WooCommerce from zero on the runner and symlink
# this checkout into wp-content/plugins/. bin/e2e-provision.sh takes over from
# there (it activates plugins, seeds, and wires Redis).
#
# Assumes the MySQL service at 127.0.0.1:3306 (root/root, db wordpress_e2e).
#
# The block theme (Twenty Twenty-Five by default) is installed AND activated:
# the plugin is developed and QA'd for modern block themes (theme.json / Site
# Editor era), so the default environment must match that baseline. WooCommerce
# renders its blockified archive templates on it, and every Shift64 storefront
# control is a block placed in those templates — the whole suite runs here.
#
# Storefront is installed alongside it but left INACTIVE. Nothing activates it
# any more: the classic-theme project that did was removed with the plugin-owned
# AJAX archive swap it covered. It stays installed because a classic theme
# present-but-inactive is the shape a real store has mid-migration, and having
# one locally available is useful for manual checks of the classic-theme notice.
#
# WordPress, WooCommerce, Storefront, and the block theme versions are
# deliberately UNPINNED:
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

wp theme install "${E2E_BLOCK_THEME:-twentytwentyfive}" --path="$WP_ROOT"
# Explicit activate rather than install --activate: on a reused environment
# the install is a no-op skip, and the activation must still happen.
wp theme activate "${E2E_BLOCK_THEME:-twentytwentyfive}" --path="$WP_ROOT"
# Inactive on purpose — see the header note. Nothing in the suite activates it.
wp theme install storefront --path="$WP_ROOT"
wp plugin install woocommerce --activate --path="$WP_ROOT"

ln -sfn "$PLUGIN_SRC" "$WP_ROOT/wp-content/plugins/shift64-woo-search"

echo "WordPress + WooCommerce installed at $WP_ROOT (plugin symlinked from $PLUGIN_SRC)."
