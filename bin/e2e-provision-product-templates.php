<?php
/**
 * Seed block-theme product archive template overrides for the e2e environment.
 *
 * @package Shift64_Woo_Search
 * @subpackage Test_Environment
 */

/** Load the pure template transformer used by this provisioning entry point. */
require_once __DIR__ . '/test-env-template-controls.php';

if ( ! function_exists( 'wp_insert_post' ) || ! class_exists( 'WP_Block_Type_Registry' ) ) {
	return;
}

$theme_slug = get_stylesheet();
$registry   = WP_Block_Type_Registry::get_instance();
$blocks     = array_filter(
	array(
		'shift64-woo-search/product-filters',
		'shift64-woo-search/product-sort',
	),
	static function ( $block_name ) use ( $registry ) {
		return $registry->is_registered( $block_name );
	}
);

$woocommerce_root = defined( 'WC_PLUGIN_FILE' ) ? dirname( WC_PLUGIN_FILE ) : WP_PLUGIN_DIR . '/woocommerce';

foreach ( shift64_woo_search_test_env_product_template_slugs() as $template_slug ) {
	$template_path = $woocommerce_root . '/templates/templates/blockified/' . $template_slug . '.html';
	if ( ! is_readable( $template_path ) ) {
		WP_CLI::warning( "WooCommerce template not available; skipped {$template_slug}." );
		continue;
	}

	// Local WooCommerce template file; wp_remote_get() is not appropriate here.
	$source = file_get_contents( $template_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $source ) {
		WP_CLI::warning( "Could not read WooCommerce template; skipped {$template_slug}." );
		continue;
	}

	try {
		$content = shift64_woo_search_test_env_transform_product_template( $source, $blocks, $template_slug );
	} catch ( UnexpectedValueException $exception ) {
		WP_CLI::warning( "WooCommerce template {$template_slug} has an unexpected shape; skipped. {$exception->getMessage()}" );
		continue;
	}

	$existing = get_posts(
		array(
			'post_type'      => 'wp_template',
			'post_status'    => 'any',
			'name'           => $template_slug,
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);
	$template = null;
	foreach ( $existing as $candidate ) {
		$terms = wp_get_object_terms( $candidate->ID, 'wp_theme', array( 'fields' => 'slugs' ) );
		if ( ! is_wp_error( $terms ) && in_array( $theme_slug, $terms, true ) ) {
			$template = $candidate;
			break;
		}
	}

	$post = array(
		'post_content' => $content,
		'post_name'    => $template_slug,
		'post_status'  => 'publish',
		'post_title'   => ucwords( str_replace( array( '-', '_' ), ' ', $template_slug ) ),
		'post_type'    => 'wp_template',
	);
	if ( $template instanceof WP_Post ) {
		$post['ID']  = $template->ID;
		$template_id = wp_update_post( wp_slash( $post ), true );
	} else {
		$template_id = wp_insert_post( wp_slash( $post ), true );
	}

	if ( is_wp_error( $template_id ) ) {
		WP_CLI::warning( "Could not save {$template_slug}: {$template_id->get_error_message()}" );
		continue;
	}

	wp_set_post_terms( $template_id, array( $theme_slug ), 'wp_theme', false );
	WP_CLI::log( "Seeded {$theme_slug}/{$template_slug} with Shift64 archive controls." );
}
