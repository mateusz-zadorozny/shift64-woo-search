#!/usr/bin/env bash
#
# Idempotent e2e provisioning: takes ANY WordPress + WooCommerce install to the
# known state the Playwright suite (tests/e2e/) assumes. Safe to re-run; targets
# both the CI runner (WP_ROOT set) and a LocalWP dev site (run from the site
# shell, WP_ROOT unset).
#
# Environment contract:
#   WP_CLI_BIN   wp-cli binary (default: wp)
#   WP_ROOT      when set, every call gets --path="$WP_ROOT" (CI); unset in LocalWP
#   REDIS_HOST   Redis host passed to `wp shift64-woo-search setup` (default: 127.0.0.1)
#   REDIS_PORT   Redis port (default: 6379)
#   BASE_URL     when set, enables the final HTTP smoke-curl of the endpoint
#
# Recovery note: if an aborted degraded e2e run (Ctrl-C between degrade and
# restore) left the site pointing at a dead Redis port, re-running this script
# (or `wp shift64-woo-search setup`) restores a healthy state.

set -euo pipefail

WP_CLI_BIN="${WP_CLI_BIN:-wp}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Term used by the verification tail to prove the index and endpoint work. It
# must match several products in the seeded catalog and stay URL-safe; "shirt"
# hits both the T-Shirt and the Polo Shirt in the apparel vertical.
CANARY_QUERY="shirt"

wpc() {
	if [ -n "${WP_ROOT:-}" ]; then
		"$WP_CLI_BIN" "$@" --path="$WP_ROOT"
	else
		"$WP_CLI_BIN" "$@"
	fi
}

log() { printf '\n==> %s\n' "$1"; }

TARGET_URL="$(wpc option get siteurl)"
printf '\n############################################################\n'
printf '# Provisioning target: %s\n' "$TARGET_URL"
printf '# This OVERWRITES this site'\''s Shift64 Woo Search options\n'
printf '# (rate limit, suggestions, archive gates, filter attributes)\n'
printf '# and reseeds the demo catalog (generator-owned products only).\n'
printf '############################################################\n'

log "WooCommerce active + storefront visible"
# The plugin's CLI commands only register when WooCommerce is active, and the
# plugin's own activation hook silently no-ops while WooCommerce is inactive.
wpc plugin is-active woocommerce >/dev/null 2>&1 || wpc plugin activate woocommerce
wpc option update woocommerce_coming_soon no

log "Activate shift64-woo-search"
if ! wpc plugin is-active shift64-woo-search >/dev/null 2>&1; then
	wpc plugin activate shift64-woo-search
fi
if ! wpc plugin is-active shift64-woo-search >/dev/null 2>&1; then
	echo 'FATAL: shift64-woo-search failed to activate (is WooCommerce really active?).' >&2
	exit 1
fi
# Re-run the activation routine idempotently (stats table, default options,
# mu-plugin install, config regen) WITHOUT a deactivate/activate window: it
# covers the edge where the plugin was activated while WooCommerce was
# inactive, in which case the real activation hook silently never ran.
wpc eval 'Shift64_Woo_Search_Plugin::get_instance()->activate();'

log "Pretty permalinks"
wpc rewrite structure '/%postname%/' >/dev/null
wpc rewrite flush --hard

log "Seed deterministic demo catalog (48 products, seed 6464)"
wpc eval-file "$SCRIPT_DIR/generate-demo-products.php" count=48 mode=mixed seed=6464 reset

log "Options (before setup/rebuild — they feed the generated config, the index schema, and the blobs)"
# Rate limit becomes the generated SHIFT64_WOO_SEARCH_RATE_LIMIT constant: real
# typing tests must never trip the default 30 req/s limiter (the 429 path is
# covered by a route mock instead).
wpc option update shift64_woo_search_rate_limit 1000
# Baked into the suggestions blob by rebuild; focus-on-empty suggestions are
# admin-curated and default empty, so test 3 needs them seeded.
#
# Seed EXACTLY three: mu-plugins/endpoint.php shuffles the stored list and slices
# it to 3, so seeding more makes test 3 assert against a random subset and turns
# it flaky.
#
# Each term must match real products in the seeded catalog — a suggestion that
# leads to an empty results page is a broken fixture even when the test passes.
# These three each match two products and span three verticals:
#   shirt      → apparel (T-Shirt, Polo Shirt)
#   headphones → tech    (Headphones, Studio Headphones)
#   serum      → beauty  (Face Serum, Night Serum)
wpc option update shift64_woo_search_suggestions '["shirt","headphones","serum"]' --format=json
# Live options read at request time.
wpc option update shift64_woo_search_archive_enabled yes
wpc option update shift64_woo_search_taxonomy_archive_scopes '["product_cat"]' --format=json
# Facet allowlist: without it the index schema has no attr_pa_color TAG field,
# the search page renders no color filter group, and the category archive
# renders no filter bar at all (its scope map exposes attr_pa_* only).
wpc option update shift64_woo_search_filter_attributes '["pa_color"]' --format=json

log "Wire Redis + regenerate SHORTINIT config (PINGs first; fails loudly on dead Redis)"
wpc shift64-woo-search setup --host="$REDIS_HOST" --port="$REDIS_PORT"

log "Rebuild index + synonym/suggestion/category blobs"
wpc shift64-woo-search rebuild

log "Ensure the search-e2e page (both search blocks)"
EXISTING_PAGE="$(wpc post list --post_type=page --name=search-e2e --field=ID --posts_per_page=1)"
if [ -z "$EXISTING_PAGE" ]; then
	wpc post create --post_type=page --post_status=publish \
		--post_title='Search E2E' --post_name=search-e2e \
		--post_content='<!-- wp:shift64-woo-search/search /--><!-- wp:shift64-woo-search/modal-search /-->' >/dev/null
	echo "Created page /search-e2e/."
else
	echo "Page /search-e2e/ already exists (ID $EXISTING_PAGE)."
fi

log "Verification tail (fails loudly so tests never inherit a broken environment)"
# `health` exits 0 even when the index is missing (that path is only a
# WP_CLI::warning), so assert on the output, not the exit code.
HEALTH_OUT="$(wpc shift64-woo-search health)"
echo "$HEALTH_OUT"
echo "$HEALTH_OUT" | grep -q 'Index: OK' || { echo 'FATAL: index is not healthy after rebuild.' >&2; exit 1; }

# Same for `test`: zero results is only a warning with exit code 0.
# Use a term with several matches in the seeded catalog, not a one-off: this gate
# exists to prove the index works, so a canary that matches a single product
# turns an ordinary catalog tweak into a provisioning failure.
TEST_OUT="$(wpc shift64-woo-search test "$CANARY_QUERY")"
echo "$TEST_OUT"
RESULT_COUNT="$(echo "$TEST_OUT" | sed -n 's/^Results: \([0-9][0-9]*\).*/\1/p' | head -1)"
if [ -z "$RESULT_COUNT" ] || [ "$RESULT_COUNT" -lt 1 ]; then
	echo "FATAL: \`wp shift64-woo-search test \"$CANARY_QUERY\"\` returned no results." >&2
	exit 1
fi

if [ -n "${BASE_URL:-}" ]; then
	SMOKE_URL="${BASE_URL%/}/wp-content/mu-plugins/shift64-woo-search/endpoint.php?q=${CANARY_QUERY}&mode=autocomplete"
	echo "Smoke-curl: $SMOKE_URL"
	if ! SMOKE_BODY="$(curl -sS -L --max-time 15 "$SMOKE_URL" 2>&1)"; then
		echo "FATAL: endpoint smoke-curl failed: $SMOKE_BODY" >&2
		exit 1
	fi
	echo "$SMOKE_BODY" | grep -q '"success":true' || { echo "FATAL: endpoint smoke-curl did not return \"success\":true — got: $SMOKE_BODY" >&2; exit 1; }
	echo "Endpoint smoke-curl OK."
fi

log "Provisioning complete."
