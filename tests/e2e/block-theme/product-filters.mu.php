<?php
/**
 * E2E fixture (block-theme project) — installed into WPMU_PLUGIN_DIR by
 * product-filters.spec.ts and deleted afterwards. NOT part of the shipped
 * plugin.
 *
 * Renders a Product Filters parent (one Category Filter Pill) directly above
 * the inherited Product Collection, standing in for a merchant placing the
 * blocks in a Site Editor archive template. Rendering goes through do_blocks,
 * so the real dynamic render callbacks, eligibility checks, and canonical URL
 * building are exercised — only the template placement is simulated.
 *
 * @package Shift64_Woo_Search
 */

add_filter(
	'render_block_woocommerce/product-collection',
	static function ( $block_content, $block ) {
		static $rendered = false;
		if ( $rendered || is_admin() ) {
			return $block_content;
		}
		if ( empty( $block['attrs']['query']['inherit'] ) ) {
			return $block_content;
		}
		$rendered = true;

		$filters = do_blocks(
			'<!-- wp:shift64-woo-search/product-filters -->' .
			'<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat","hideEmpty":false} /-->' .
			'<!-- /wp:shift64-woo-search/product-filters -->'
		);

		return $filters . $block_content;
	},
	20,
	2
);
