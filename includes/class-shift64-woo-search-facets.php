<?php
/**
 * Facet orchestration service.
 *
 * Computes facet counts for all configured filter dimensions (category + attribute
 * TAG fields) using FT.AGGREGATE. Used by both search archive (with text terms)
 * and taxonomy archive (scope-only, no text terms).
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Facet computation service.
 */
class Shift64_Woo_Search_Facets {

	/**
	 * Compute facet counts for all configured dimensions.
	 *
	 * Uses FT.AGGREGATE for each dimension with dependent "exclude-self" faceting:
	 * each dimension excludes its own filter but includes all others.
	 *
	 * @param Shift64_Woo_Search_Query $search_query        Query builder instance.
	 * @param array                    $scope_filters       Archive scope (e.g. ['category' => ['Shift64 Stella']] on a category archive).
	 *                                                      Always ON, never excluded — scope is not a user-toggleable dimension.
	 * @param array                    $active_user_filters User-selected filters from URL params. Exclude-self logic applies per dimension.
	 * @param array|null               $terms               Optional search terms for text-query context (search archive).
	 *                                                      NULL on taxonomy archive — facets are computed without a text query.
	 * @return array Keyed by field name (e.g. 'categories', 'attr_pa_kolor') → list of ['value' => string, 'count' => int].
	 */
	public static function compute(
		Shift64_Woo_Search_Query $search_query,
		array $scope_filters,
		array $active_user_filters,
		?array $terms = null
	) {
		$facets = array();

		// Merged filters are what the facet query engine sees: scope (always on)
		// plus user-selected filters. Exclude-self per dimension happens inside
		// Shift64_Woo_Search_Query::build_facet_query() — the third argument below
		// (field name, e.g. 'category' / 'attr_pa_kolor') is BOTH the dimension
		// being aggregated AND the filter key to skip when building the base
		// query, so users see all candidate values for the dimension they're
		// currently filtering on.
		$all_filters = array_merge( $scope_filters, $active_user_filters );

		// Empty terms array is a valid query — FT query built purely from filters.
		$terms_for_query = $terms ?? array();

		if ( 'yes' === get_option( 'shift64_woo_search_filter_categories_enabled', 'yes' ) ) {
			$cat_query   = $search_query->build_facet_query( $terms_for_query, $all_filters, 'category' );
			$cat_results = $search_query->execute_category_facet( $cat_query );
			if ( ! empty( $cat_results ) ) {
				$facets['categories'] = $cat_results;
			}
		}

		$filter_attrs = Shift64_Woo_Search_Schema::get_filter_attributes();
		foreach ( $filter_attrs as $taxonomy ) {
			$field_name  = 'attr_' . $taxonomy;
			$agg_query   = $search_query->build_facet_query( $terms_for_query, $all_filters, $field_name );
			$agg_results = $search_query->execute_ft_aggregate( $agg_query, $field_name );
			if ( ! empty( $agg_results ) ) {
				$facets[ $field_name ] = $agg_results;
			}
		}

		return $facets;
	}
}
