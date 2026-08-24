<?php
/**
 * Context-driven Redis query service for Product Collection.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes Redis membership queries without rendering.
 */
class Shift64_Woo_Search_Product_Collection_Query_Service {

	/**
	 * Optional query builder injection for tests.
	 *
	 * @var Shift64_Woo_Search_Query|null
	 */
	private $search_query;

	/**
	 * Constructor.
	 *
	 * @param Shift64_Woo_Search_Query|null $search_query Query builder.
	 */
	public function __construct( $search_query = null ) {
		$this->search_query = $search_query;
	}

	/**
	 * Execute one Product Collection request.
	 *
	 * @param Shift64_Woo_Search_Product_Collection_Context $context Eligibility context.
	 * @param Shift64_Woo_Search_Catalog_State              $state Canonical state.
	 * @return Shift64_Woo_Search_Product_Collection_Result
	 */
	public function execute( Shift64_Woo_Search_Product_Collection_Context $context, Shift64_Woo_Search_Catalog_State $state ) {
		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( null === $this->search_query && ! $redis->is_available() ) {
			return $this->fallback( $context, $state );
		}

		if ( ! in_array( $state->get_sort(), array( 'relevance', 'price', 'price-desc' ), true ) ) {
			return $this->fallback( $context, $state );
		}

		$query = $this->search_query ?? new Shift64_Woo_Search_Query( $redis );
		$scope = array();
		$map   = Shift64_Woo_Search_Taxonomy_Archive::get_scope_map();
		if ( '' !== $context->get_taxonomy() && isset( $map[ $context->get_taxonomy() ] ) && '' !== $context->get_term_name() ) {
			$scope[ $map[ $context->get_taxonomy() ]['filter_key'] ] = array( $context->get_term_name() );
		}

		$sort_by = null;
		if ( 'price' === $state->get_sort() ) {
			$sort_by = 'price ASC';
		} elseif ( 'price-desc' === $state->get_sort() ) {
			$sort_by = 'price DESC';
		}

		if ( '' !== $state->get_search() ) {
			$result = $query->search_catalog(
				$state->get_search(),
				$state->get_redis_filters(),
				$context->get_per_page(),
				$state->get_page(),
				$sort_by,
				'search',
				$state->get_redis_operators()
			);
			$terms  = $query->get_search_terms( $query->sanitize_query( $state->get_search() ) );
		} else {
			$result = $query->search_by_filters(
				$scope,
				$state->get_redis_filters(),
				$context->get_per_page(),
				$state->get_page(),
				$sort_by,
				$context->get_visibility_policy(),
				$state->get_redis_operators()
			);
			$terms  = null;
		}

		if ( ! is_array( $result ) || ! isset( $result['ids'], $result['total'] ) || empty( $result['ok'] ) ) {
			return $this->fallback( $context, $state );
		}

		// Memoized: a Filter Pill rendered before this collection may already
		// have aggregated the same canonical state (never twice per request).
		$facets = Shift64_Woo_Search_Facet_Count_Provider::compute_memoized(
			$query,
			$scope,
			$state->get_redis_filters(),
			$terms,
			$context->get_visibility_policy(),
			$state->get_redis_operators()
		);

		return new Shift64_Woo_Search_Product_Collection_Result(
			$context->get_request_key(),
			$result['ids'],
			$result['total'],
			$state->get_page(),
			$context->get_per_page(),
			$state->get_sort(),
			$state->get_selected_filters(),
			$facets,
			Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS
		);
	}

	/**
	 * Build a native fallback envelope.
	 *
	 * @param Shift64_Woo_Search_Product_Collection_Context $context Context.
	 * @param Shift64_Woo_Search_Catalog_State              $state State.
	 * @return Shift64_Woo_Search_Product_Collection_Result
	 */
	private function fallback( $context, $state ) {
		return new Shift64_Woo_Search_Product_Collection_Result(
			$context->get_request_key(),
			array(),
			0,
			$state->get_page(),
			$context->get_per_page(),
			$state->get_sort(),
			$state->get_selected_filters(),
			array(),
			Shift64_Woo_Search_Product_Collection_Result::STATUS_NATIVE_FALLBACK
		);
	}
}
