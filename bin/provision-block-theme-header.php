<?php
/**
 * Provision the block-theme `header` template part the test environments QA against.
 *
 * Run through WP-CLI:
 *
 * wp eval-file wp-content/plugins/shift64-woo-search/bin/provision-block-theme-header.php
 * wp eval-file wp-content/plugins/shift64-woo-search/bin/provision-block-theme-header.php theme=twentytwentyfive
 *
 * Writes — and deliberately overwrites — the site header so both search parents
 * are reachable from every page instead of only from /search-e2e/:
 * `shift64-woo-search/modal-search` sits next to the account and cart icons, and
 * `shift64-woo-search/search` fills the row below it. That is the arrangement a
 * real storefront ships, so it is the one manual QA and UI review should see.
 *
 * Theme resolution, in precedence order: the `theme=` argument, the active
 * theme when it is a block theme, the `E2E_BLOCK_THEME` environment variable,
 * `twentytwentyfive`. The default environment activates the block theme, so the
 * header is live the moment provisioning finishes.
 *
 * Template parts are theme-scoped through the `wp_theme` term, so the part is
 * simply absent under a classic theme — including while
 * tests/e2e/classic-theme/ has Storefront activated for the length of its spec
 * file. Nothing has to be torn down around that switch.
 *
 * The `wp:navigation` ref is resolved at run time. Template part markup exported
 * from the Site Editor carries a `wp_navigation` post ID from THAT site, which
 * on any other install points at nothing and renders an empty menu.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This script must be executed with WP-CLI.\n" );
	return;
}

/**
 * The header markup, verbatim from the Site Editor except for the navigation
 * ref, which is a placeholder resolved against this install below.
 */
$header_markup = <<<'MARKUP'
<!-- wp:group {"align":"full","className":"wc-blocks-pattern-header-large wc-blocks-header-pattern","style":{"spacing":{"padding":{"right":"40px","left":"40px","top":"24px","bottom":"24px"}}},"layout":{"type":"default"}} -->
<div class="wp-block-group alignfull wc-blocks-pattern-header-large wc-blocks-header-pattern" style="padding-top:24px;padding-right:40px;padding-bottom:24px;padding-left:40px"><!-- wp:group {"align":"full","layout":{"type":"default"}} -->
<div class="wp-block-group alignfull"><!-- wp:columns {"verticalAlignment":"center","isStackedOnMobile":false,"align":"full"} -->
<div class="wp-block-columns alignfull are-vertically-aligned-center is-not-stacked-on-mobile"><!-- wp:column {"verticalAlignment":"center","width":"70%","layout":{"type":"default"}} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:70%"><!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"left"}} -->
<div class="wp-block-group"><!-- wp:site-logo {"shouldSyncIcon":true} /-->

<!-- wp:site-title {"fontSize":"medium"} /-->

<!-- wp:navigation {"ref":%NAVIGATION_ID%,"overlayMenu":"always","metadata":{"ignoredHookedBlocks":["woocommerce/customer-account"]}} /--></div>
<!-- /wp:group --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":""} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:group {"style":{"spacing":{"blockGap":"0px"}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"right"}} -->
<div class="wp-block-group"><!-- wp:shift64-woo-search/modal-search {"instanceId":"s64ws-90477a6e0535"} -->
<!-- wp:shift64-woo-search/search-control /-->

<!-- wp:shift64-woo-search/search-panel /-->
<!-- /wp:shift64-woo-search/modal-search -->

<!-- wp:woocommerce/customer-account {"displayStyle":"icon_only","iconStyle":"line","iconClass":"wc-block-customer-account__account-icon"} /-->

<!-- wp:woocommerce/mini-cart /--></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
<div class="wp-block-group alignfull"><!-- wp:group {"style":{"spacing":{"padding":{"right":"0","left":"0"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-right:0;padding-left:0"><!-- wp:shift64-woo-search/search {"instanceId":"s64ws-2b36668ae646"} -->
<!-- wp:shift64-woo-search/search-control /-->

<!-- wp:shift64-woo-search/search-panel /-->
<!-- /wp:shift64-woo-search/search --></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
MARKUP;

$raw_args   = isset( $args ) && is_array( $args ) ? $args : array();
$theme_slug = '';

foreach ( $raw_args as $raw_arg ) {
	$arg = ltrim( (string) $raw_arg, '-' );
	if ( '' === $arg ) {
		continue;
	}
	if ( str_starts_with( $arg, 'theme=' ) ) {
		$theme_slug = trim( substr( $arg, strlen( 'theme=' ) ) );
		continue;
	}
	WP_CLI::error( sprintf( 'Unknown argument "%s". Use theme=<stylesheet>.', (string) $raw_arg ) );
}

if ( '' === $theme_slug ) {
	if ( wp_is_block_theme() ) {
		$theme_slug = (string) get_stylesheet();
	} else {
		$configured = getenv( 'E2E_BLOCK_THEME' );
		$theme_slug = is_string( $configured ) && '' !== $configured ? $configured : 'twentytwentyfive';
	}
}

if ( ! wp_get_theme( $theme_slug )->exists() ) {
	WP_CLI::warning(
		sprintf(
			'Theme "%s" is not installed — skipping the header template part. Install it, then re-run this script.',
			$theme_slug
		)
	);
	return;
}

// One navigation menu is enough for a fixture: reuse whatever the site already
// has so a customized menu survives re-provisioning, and only create one when
// the install has none (a CLI-only install never opens the Site Editor, which
// is what would otherwise create the first `wp_navigation` post).
$navigation_ids = get_posts(
	array(
		'post_type'      => 'wp_navigation',
		'post_status'    => array( 'publish', 'draft' ),
		'posts_per_page' => 1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);

if ( $navigation_ids ) {
	$navigation_id = (int) $navigation_ids[0];
} else {
	$navigation_id = wp_insert_post(
		array(
			'post_type'    => 'wp_navigation',
			'post_status'  => 'publish',
			'post_title'   => 'Header Navigation',
			'post_name'    => 'header-navigation',
			'post_content' => '<!-- wp:page-list /-->',
		),
		true
	);
	if ( is_wp_error( $navigation_id ) ) {
		WP_CLI::error( 'Could not create the header navigation menu: ' . $navigation_id->get_error_message() );
	}
	WP_CLI::log( sprintf( 'Created the header navigation menu (ID %d).', (int) $navigation_id ) );
}

$header_content = str_replace( '%NAVIGATION_ID%', (string) (int) $navigation_id, $header_markup );

$existing_parts = get_posts(
	array(
		'post_type'      => 'wp_template_part',
		'post_status'    => array( 'publish', 'draft', 'auto-draft' ),
		'name'           => 'header',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The wp_theme term is how WordPress itself scopes a template part to a theme.
		'tax_query'      => array(
			array(
				'taxonomy' => 'wp_theme',
				'field'    => 'name',
				'terms'    => $theme_slug,
			),
		),
	)
);

$template_part = array(
	'post_type'    => 'wp_template_part',
	'post_status'  => 'publish',
	'post_title'   => 'Header',
	'post_name'    => 'header',
	'post_content' => $header_content,
	'post_excerpt' => 'Site header carrying the Shift64 modal and inline search blocks.',
);

if ( $existing_parts ) {
	$template_part['ID'] = (int) $existing_parts[0];
	$template_part_id    = wp_update_post( $template_part, true );
} else {
	$template_part_id = wp_insert_post( $template_part, true );
}

if ( is_wp_error( $template_part_id ) ) {
	WP_CLI::error( 'Could not save the header template part: ' . $template_part_id->get_error_message() );
}

// The two terms are what make this row a header template part FOR THIS THEME;
// without them WordPress never resolves it and the theme's own header wins.
$part_terms = array(
	'wp_theme'              => $theme_slug,
	'wp_template_part_area' => 'header',
);

foreach ( $part_terms as $taxonomy => $term_name ) {
	$term = term_exists( $term_name, $taxonomy );
	if ( ! $term ) {
		$term = wp_insert_term( $term_name, $taxonomy );
	}
	if ( is_wp_error( $term ) ) {
		WP_CLI::error(
			sprintf( 'Could not assign the "%1$s" term "%2$s": %3$s', $taxonomy, $term_name, $term->get_error_message() )
		);
	}
	wp_set_post_terms( (int) $template_part_id, array( (int) $term['term_id'] ), $taxonomy );
}

WP_CLI::success(
	sprintf(
		'Header template part %1$s for theme "%2$s" (post ID %3$d, navigation ID %4$d).',
		$existing_parts ? 'updated' : 'created',
		$theme_slug,
		(int) $template_part_id,
		(int) $navigation_id
	)
);
