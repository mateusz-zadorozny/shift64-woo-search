<?php
/**
 * E2E fixture (block-theme project) — installed into WPMU_PLUGIN_DIR by the
 * forcePageReload describe block in blockified.spec.ts and deleted afterwards.
 * It is NOT part of the shipped plugin.
 *
 * Turns on WooCommerce's "Force page reload" option for the Product Collection
 * block, which drops the block's data-wp-router-region and disables Woo's
 * enhanced (Interactivity-API) pagination.
 *
 * WHAT THIS SETS UP: the third column of the #20 ownership matrix — a site
 * that has explicitly opted out of client-side navigation, so plain browser
 * navigation owns the pagination click. The plugin is expected to RESPECT that
 * and stay out of the way, not to substitute its own AJAX swap.
 *
 * This is a scenario fixture, not a lever to make the plugin win. An earlier
 * revision of this file used it the other way around — to prove the plugin's
 * swap owned blockified pagination — which would have codified the opposite of
 * the decided contract as a passing test.
 *
 * @package Shift64_Woo_Search
 */

add_filter(
	'render_block_data',
	static function ( $parsed_block ) {
		if ( isset( $parsed_block['blockName'] ) && 'woocommerce/product-collection' === $parsed_block['blockName'] ) {
			$parsed_block['attrs']['forcePageReload'] = true;
		}
		return $parsed_block;
	}
);

// WooCommerce captures pagination directives before render_block_data runs.
// Remove those stale navigate/prefetch directives from the final pagination
// links so this fixture represents the saved forcePageReload contract and the
// browser follows the links as ordinary document navigation.
add_filter(
	'render_block',
	static function ( $block_content, $parsed_block ) {
		$block_name = $parsed_block['blockName'] ?? '';
		if ( 0 !== strpos( $block_name, 'core/query-pagination' ) ) {
			return $block_content;
		}

		return preg_replace( '/\\sdata-wp-(?:on--(?:click|mouseenter)|watch)="[^"]*"/', '', $block_content );
	},
	10,
	2
);
