<?php
/**
 * Shift64 Woo Search — brand autocomplete suggestion ranking.
 *
 * Brands rank exactly like categories: the endpoint reads a precomputed JSON
 * blob ({prefix}:brands) and scores it with the shared, WordPress-free core in
 * Shift64_Woo_Search_Category_Suggest::rank_entries(). Brands have no pin rules
 * and no per-brand boost yet, so those inputs are empty here.
 *
 * Kept dependency-free so it can be loaded inside the SHORTINIT endpoint, which
 * cannot boot full WordPress. Both loaders — the plugin bootstrap and
 * endpoint.php — require class-shift64-woo-search-category-suggest.php ahead of
 * this file, which is where the shared scoring core lives.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Brand suggestion ranking.
 */
class Shift64_Woo_Search_Brand_Suggest {

	/**
	 * Rank brand suggestions for a query.
	 *
	 * @param array  $all_brands    Decoded {prefix}:brands blob.
	 * @param string $query         Raw search query.
	 * @param bool   $fuzzy_enabled Whether typo-tolerant matching is enabled.
	 * @return array<int,array{name:string,url:string,count:int}> Up to LIMIT rows.
	 */
	public static function rank( $all_brands, $query, $fuzzy_enabled = false ) {
		return Shift64_Woo_Search_Category_Suggest::rank_entries( $all_brands, $query, '', $fuzzy_enabled );
	}
}
