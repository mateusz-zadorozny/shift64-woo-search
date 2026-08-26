<?php
/**
 * Pure helpers for the block-theme test-environment template overrides.
 *
 * @package Shift64_Woo_Search
 * @subpackage Test_Environment
 */

/**
 * Return the WooCommerce blockified templates that render product archives.
 *
 * `archive-product` is WooCommerce's shared fallback for the shop, product
 * category, and product tag archives. Product attribute archives have their
 * own template, while product search results use a separate template.
 *
 * @return array<int, string>
 */
function shift64_woo_search_test_env_product_template_slugs() {
	return array(
		'archive-product',
		'product-search-results',
		'taxonomy-product_attribute',
	);
}

/**
 * Transform one upstream WooCommerce product template for test environments.
 *
 * The upstream file remains the source of truth for the page shell and product
 * cards. Only the catalog-sorting marker is replaced and the Shift64 filters
 * are inserted next to the results count. If the custom sort block is not
 * registered, WooCommerce's sorting block remains in place as a compatibility
 * fallback for older plugin checkouts.
 *
 * @param string             $template          Upstream template content.
 * @param array<int, string> $registered_blocks Registered block names.
 * @param string             $template_slug     Template slug for instance IDs.
 * @return string
 * @throws UnexpectedValueException When the upstream template has no result marker.
 */
function shift64_woo_search_test_env_transform_product_template( $template, array $registered_blocks, $template_slug ) {
	if ( false !== strpos( $template, '<!-- wp:shift64-woo-search/product-filters' ) ) {
		return $template;
	}

	$sort_block = '<!-- wp:woocommerce/catalog-sorting /-->';
	if ( in_array( 'shift64-woo-search/product-sort', $registered_blocks, true ) ) {
		$sort_block = '<!-- wp:shift64-woo-search/product-sort {"orderedOptions":["menu_order","popularity","rating","date","price","price-desc"]} /-->';
	}

	$sort_marker = '<!-- wp:woocommerce/catalog-sorting /-->';
	if ( false !== strpos( $template, $sort_marker ) ) {
		$template = str_replace( $sort_marker, $sort_block, $template );
	}

	$results_marker = '<!-- wp:woocommerce/product-results-count /-->';
	if ( false === strpos( $template, $results_marker ) ) {
		throw new UnexpectedValueException( 'WooCommerce product template has no product-results-count marker.' );
	}

	$template_key = preg_replace( '/[^a-z0-9_-]/i', '-', (string) $template_slug );
	$filters      = '<!-- wp:shift64-woo-search/product-filters {"instanceId":"test-env-' . $template_key . '","showCounts":true,"hideEmpty":false} -->'
		. "\n\t\t"
		. '<!-- wp:shift64-woo-search/filter-pill {"facet":"product_cat"} /-->'
		. "\n\t\t"
		. '<!-- wp:shift64-woo-search/filter-pill {"facet":"pa_color"} /-->'
		. "\n\t"
		. '<!-- /wp:shift64-woo-search/product-filters -->';

	$replacement_count = 0;
	return str_replace( $results_marker, $filters . "\n\t\t" . $results_marker, $template, $replacement_count );
}
