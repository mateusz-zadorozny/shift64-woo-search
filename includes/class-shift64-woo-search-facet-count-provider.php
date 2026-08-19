<?php
/**
 * Request-scoped facet count provisioning for Filter Pill rendering.
 *
 * Blocks render in template order, so a Filter Pill above the inherited
 * Product Collection renders before the collection executes its Redis query.
 * This provider gives pills one authoritative bucket source either way:
 *
 * 1. Reuse the facet buckets of an already-executed Redis result envelope.
 * 2. Otherwise compute the disjunctive buckets on demand from the current
 *    request through the shared query machinery.
 *
 * Both this provider and the Product Collection query service route their
 * aggregation through {@see self::compute_memoized()}, so the Redis
 * FT.AGGREGATE set runs at most once per canonical request state regardless
 * of render order.
 *
 * All state is per-request statics (php-fpm resets them per request;
 * long-running runtimes must call `reset()` per request cycle, matching the
 * legacy facet registry's documented contract).
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves facet buckets once per request.
 */
class Shift64_Woo_Search_Facet_Count_Provider {

	const STATUS_NONE        = 'none';
	const STATUS_ENVELOPE    = 'envelope';
	const STATUS_COMPUTED    = 'computed';
	const STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * Resolved buckets keyed by Redis field, false when counts are unavailable.
	 *
	 * @var array<string,array>|false|null
	 */
	private static $facets = null;

	/**
	 * How the buckets were resolved.
	 *
	 * @var string
	 */
	private static $status = self::STATUS_NONE;

	/**
	 * Memoized aggregation results keyed by canonical argument hash.
	 *
	 * @var array<string,array>
	 */
	private static $memo = array();

	/**
	 * Injected query builder (tests).
	 *
	 * @var Shift64_Woo_Search_Query|null
	 */
	private static $search_query = null;

	/**
	 * Buckets for one Redis facet field, or null when counts are unavailable
	 * or that dimension degraded (renderers then omit counts, never invent them).
	 *
	 * @param string $redis_field Redis facet field (category, brand, attr_pa_*).
	 * @return array<int,array{value:string,count:int}>|null
	 */
	public static function get_buckets( $redis_field ) {
		$facets = self::resolve();
		if ( ! is_array( $facets ) || ! isset( $facets[ $redis_field ] ) || ! is_array( $facets[ $redis_field ] ) ) {
			return null;
		}
		return $facets[ $redis_field ];
	}

	/**
	 * How buckets were (or were not) resolved this request.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public static function get_status() {
		self::resolve();
		return self::$status;
	}

	/**
	 * Run the shared facet aggregation at most once per canonical state.
	 *
	 * The Product Collection query service calls this too, so a pill-first
	 * render order never doubles the FT.AGGREGATE set.
	 *
	 * @param Shift64_Woo_Search_Query $search_query        Query builder.
	 * @param array                    $scope_filters       Archive scope filters.
	 * @param array                    $active_user_filters Selected facet filters.
	 * @param array|null               $terms               Search terms or null.
	 * @param string|string[]|null     $visibility_policy   Visibility context.
	 * @param array                    $filter_operators    Per-filter operators.
	 * @return array Facet buckets keyed by Redis field.
	 */
	public static function compute_memoized(
		Shift64_Woo_Search_Query $search_query,
		array $scope_filters,
		array $active_user_filters,
		?array $terms = null,
		$visibility_policy = null,
		array $filter_operators = array()
	) {
		$key = md5( wp_json_encode( array( $scope_filters, $active_user_filters, $terms, $visibility_policy, $filter_operators ) ) );
		if ( ! isset( self::$memo[ $key ] ) ) {
			self::$memo[ $key ] = Shift64_Woo_Search_Facets::compute(
				$search_query,
				$scope_filters,
				$active_user_filters,
				$terms,
				$visibility_policy,
				$filter_operators
			);
		}
		return self::$memo[ $key ];
	}

	/**
	 * Inject a query builder (tests exercising the on-demand path).
	 *
	 * @param Shift64_Woo_Search_Query|null $search_query Query builder.
	 */
	public static function set_search_query( $search_query ) {
		self::$search_query = $search_query;
	}

	/**
	 * Clear request state (tests and long-running runtimes).
	 */
	public static function reset() {
		self::$facets       = null;
		self::$status       = self::STATUS_NONE;
		self::$memo         = array();
		self::$search_query = null;
	}

	/**
	 * Resolve buckets once per request.
	 *
	 * @return array<string,array>|false
	 */
	private static function resolve() {
		if ( null !== self::$facets ) {
			return self::$facets;
		}

		$envelope = Shift64_Woo_Search_Product_Collection_Results::first_redis();
		if ( null !== $envelope ) {
			self::$facets = $envelope->get_facets();
			self::$status = self::STATUS_ENVELOPE;
			return self::$facets;
		}

		$context = Shift64_Woo_Search_Product_Collection_Context::for_current_request( 'shift64-woo-search-filter-counts' );
		$query   = self::$search_query;
		if ( null === $query ) {
			$redis = Shift64_Woo_Search_Redis::get_instance();
			if ( $redis->is_available() ) {
				$query = new Shift64_Woo_Search_Query( $redis );
			}
		}

		if ( null === $context || null === $query ) {
			self::$facets = false;
			self::$status = self::STATUS_UNAVAILABLE;
			return self::$facets;
		}

		$state = Shift64_Woo_Search_Catalog_State::from_request( $context );

		$scope = array();
		$map   = Shift64_Woo_Search_Taxonomy_Archive::get_scope_map();
		if ( '' !== $context->get_taxonomy() && isset( $map[ $context->get_taxonomy() ] ) && '' !== $context->get_term_name() ) {
			$scope[ $map[ $context->get_taxonomy() ]['filter_key'] ] = array( $context->get_term_name() );
		}

		$terms = null;
		if ( '' !== $state->get_search() ) {
			$terms = $query->get_search_terms( $query->sanitize_query( $state->get_search() ) );
		}

		self::$facets = self::compute_memoized(
			$query,
			$scope,
			$state->get_redis_filters(),
			$terms,
			$context->get_visibility_policy(),
			$state->get_redis_operators()
		);
		self::$status = self::STATUS_COMPUTED;

		return self::$facets;
	}
}
