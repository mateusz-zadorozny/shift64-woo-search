<?php
/**
 * E2E fixture (block-theme project) — installed into WPMU_PLUGIN_DIR by
 * blockified.spec.ts for the duration of that spec file and deleted afterwards.
 * It is NOT part of the shipped plugin.
 *
 * Turns on WooCommerce's "Force page reload" option for the Product Collection
 * block. That drops the block's data-wp-router-region, which disables Woo's
 * enhanced (Interactivity-API) pagination.
 *
 * Why the suite needs this: with enhanced pagination ON, clicking a page link
 * fires BOTH handlers — this plugin's delegated a.page-numbers handler and
 * WooCommerce's own Interactivity-API navigate action. preventDefault() stops
 * the link's default navigation but not Woo's listener, so Woo re-renders the
 * pagination block independently and the current-page indicator lands on the
 * right number whether or not this plugin swapped it. That masks the exact
 * class of bug the block-theme project exists to catch: verified against
 * issue #15's pre-fix code, the blockified journey passes with AND without
 * the fix while enhanced pagination is on, and fails with the pre-fix code
 * (indicator stuck on "1") once it is off.
 *
 * Forcing page reload therefore is not a workaround — it pins the test to the
 * configuration in which this plugin's AJAX swap is the ONLY thing updating
 * the pagination control, which is the contract the swap actually owns.
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
