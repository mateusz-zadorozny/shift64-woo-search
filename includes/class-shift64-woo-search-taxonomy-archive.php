<?php
/**
 * Taxonomy archive interceptor — Redis-backed filtering for product_cat / product_brand archives.
 *
 * Scope-driven: one class handles all configured taxonomies (product_cat today,
 * product_brand tomorrow). Adding a new scope = one line in SCOPE_MAP + admin toggle.
 *
 * Contract:
 * - Redis acts as a FILTER (post__in WHERE clause), not a sorter.
 * - Orderby is left untouched so theme/WC/custom sort logic composes cleanly.
 * - found_posts override is NOT used — Redis returns the full matching ID set,
 *   WP paginates normally on post__in.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy archive query interceptor implementing facet context for renderer.
 */
class Shift64_Woo_Search_Taxonomy_Archive implements Shift64_Woo_Search_Facet_Context {

	/**
	 * Scope configuration — maps WP taxonomy slug to Redis filter key + index field.
	 *
	 * - filter_key:       key used in Shift64_Woo_Search_Query::search_by_filters() scope_filters array.
	 * - redis_field:      field name in the RediSearch index (for facet aggregation labeling).
	 * - facet_dimensions: which facet dimensions to render on this scope. MVP = attributes only.
	 *
	 * @var array
	 */
	private static $scope_map = array(
		'product_cat'   => array(
			'filter_key'       => 'category',
			'redis_field'      => 'categories',
			'facet_dimensions' => array( 'attr_pa_*' ),
		),
		'product_brand' => array(
			'filter_key'       => 'brand',
			'redis_field'      => 'brands',
			'facet_dimensions' => array( 'attr_pa_*' ),
		),
	);

	/**
	 * Current request's facet data, populated after intercept succeeds.
	 *
	 * @var array
	 */
	private $facet_data = array();

	/**
	 * Current request's active user filters, parsed from URL.
	 *
	 * @var array
	 */
	private $active_filters = array();

	/**
	 * Per-request cache of Redis-filtered product IDs, keyed by
	 * `{taxonomy}:{term_id}:md5(user_filters)`. First query computes,
	 * subsequent calls (e.g. Kadence block sub-query after the main
	 * query already intercepted) reuse the result — Redis is hit once
	 * per page regardless of how many child WP_Query instances render.
	 *
	 * @var array<string, int[]>
	 */
	private $cached_post_ids = array();

	/**
	 * Wire hooks on frontend requests only.
	 */
	public function __construct() {
		if ( is_admin() ) {
			return;
		}

		add_action( 'pre_get_posts', array( $this, 'intercept' ), 99 );

		// Catch non-main queries (Kadence Woo Template Block creates its own
		// WP_Query with is_main_query()=false, skipping pre_get_posts). See
		// issue #17.
		add_filter( 'posts_clauses', array( $this, 'inject_post_in' ), 10, 2 );
	}

	/**
	 * Return the scope map. Exposed for tests and admin UI.
	 *
	 * @return array
	 */
	public static function get_scope_map() {
		// product_brand is WooCommerce core only from 9.4 onwards, and can be
		// deregistered. Hide the scope (and with it the admin checkbox) rather
		// than offering an archive that cannot exist.
		return array_filter(
			self::$scope_map,
			static function ( $taxonomy ) {
				return 'product_cat' === $taxonomy || taxonomy_exists( $taxonomy );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Check whether a scope (taxonomy) is enabled via the admin toggle option.
	 *
	 * Only taxonomies listed in SCOPE_MAP *and* in the scopes option count —
	 * typos or stale entries in the option are silently ignored.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	public function is_scope_enabled( $taxonomy ) {
		// Through the accessor, so a scope whose taxonomy is not registered stays
		// off even if a stale option value still lists it.
		$scope_map = self::get_scope_map();
		if ( ! isset( $scope_map[ $taxonomy ] ) ) {
			return false;
		}

		$enabled = (array) get_option( 'shift64_woo_search_taxonomy_archive_scopes', array() );
		return in_array( $taxonomy, $enabled, true );
	}

	/**
	 * Parse filter_pa_* query string params into the shape build_filter_parts() expects.
	 *
	 * Slug → name conversion is required because the Redis index stores term
	 * NAMES in TAG fields (see schema), but URL params use SLUGS by
	 * convention (WC facet URLs, the JS applyFilters() emitter, theme links).
	 * Unknown slugs are dropped: a typo shouldn't reach Redis and be
	 * escape-mangled into an alternation token.
	 *
	 * @return array Keyed by 'attr_pa_*' → list of term names.
	 */
	public function get_active_filters() {
		$filters = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public archive page, GET param read.
		foreach ( $_GET as $key => $value ) {
			if ( 0 !== strpos( $key, 'filter_pa_' ) ) {
				continue;
			}
			$taxonomy  = substr( $key, strlen( 'filter_' ) );
			$field_key = 'attr_' . $taxonomy;
			$raw       = sanitize_text_field( wp_unslash( $value ) );
			$slugs     = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

			$names = array();
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term ) {
					$names[] = $term->name;
				}
			}

			if ( ! empty( $names ) ) {
				$filters[ $field_key ] = $names;
			}
		}

		return $filters;
	}

	/**
	 * Facet data computed during intercept(), consumed by the renderer.
	 *
	 * @return array
	 */
	public function get_facet_data() {
		return $this->facet_data;
	}

	/**
	 * Handle pre_get_posts by querying Redis and injecting post__in.
	 *
	 * @param WP_Query $query Query being prepared.
	 */
	public function intercept( $query ) {
		if ( ! $this->should_intercept( $query ) ) {
			return;
		}

		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$scope_config = self::$scope_map[ $term->taxonomy ] ?? null;
		if ( null === $scope_config ) {
			return;
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			return;
		}

		$search_query  = new Shift64_Woo_Search_Query( $redis, Shift64_Woo_Search_Settings::search_config() );
		$scope_filters = array( $scope_config['filter_key'] => array( $term->name ) );

		// Fetch once, cache for the rest of the request so posts_clauses
		// (Kadence block sub-queries, widgets) reuses the same Redis result.
		// get_cached_ids_for_term() populates $this->active_filters as a side
		// effect; Facets::compute below relies on that.
		$post_ids = $this->get_cached_ids_for_term( $term );

		// Facet data for renderer.
		$this->facet_data = Shift64_Woo_Search_Facets::compute(
			$search_query,
			$scope_filters,
			$this->active_filters,
			null
		);

		// Respect this scope's declared facet_dimensions — e.g. product_cat
		// only renders attr_pa_* dimensions (no category pill when the
		// user is already browsing inside a category).
		$this->facet_data = $this->filter_dimensions(
			$this->facet_data,
			$scope_config['facet_dimensions'] ?? array()
		);

		if ( empty( $post_ids ) ) {
			$post_ids = array( 0 ); // Force "no results" state.
		}

		$query->set( 'post__in', $post_ids );

		Shift64_Woo_Search_Facet_Registry::set_current( $this );
	}

	/**
	 * Inject Redis post__in for non-main queries on
	 * scoped taxonomy archives. Gated tightly so menus, widgets, and
	 * unrelated queries are untouched.
	 *
	 * @param array    $clauses Query clauses (where/join/groupby/...).
	 * @param WP_Query $query   The executing query.
	 * @return array Clauses, possibly with an extra AND posts.ID IN (...) in `where`.
	 */
	public function inject_post_in( $clauses, $query ) {
		if ( $query->get( Shift64_Woo_Search_Product_Collection_Query::QUERY_MARKER ) ) {
			return $clauses;
		}

		// Skip main query — pre_get_posts already handled it.
		if ( $query->is_main_query() ) {
			return $clauses;
		}

		// Accept only clean product queries. Mixed types like
		// ['product', 'product_variation'] are intentionally skipped — they're
		// usually admin-side or variation-lookup queries, never a product
		// archive grid that we want to filter.
		$post_type = $query->get( 'post_type' );
		if ( 'product' !== $post_type && array( 'product' ) !== $post_type ) {
			return $clauses;
		}

		$queried = $this->resolve_queried_term( $query );
		if ( ! $queried instanceof WP_Term ) {
			return $clauses;
		}

		if ( ! isset( self::$scope_map[ $queried->taxonomy ] ) ) {
			return $clauses;
		}

		if ( ! $this->is_scope_enabled( $queried->taxonomy ) ) {
			return $clauses;
		}

		$ids = $this->get_cached_ids_for_term( $queried );

		return $this->apply_clauses_for_block_query( $clauses, $query, $ids );
	}

	/**
	 * Resolve the queried term, preferring the query's own object over the
	 * global $wp_query. Defensive against plugins / custom loops that mutate
	 * the global before a sub-query runs.
	 *
	 * @param WP_Query $query Query whose archive term should be resolved.
	 * @return WP_Term|null
	 */
	private function resolve_queried_term( $query ) {
		if ( is_callable( array( $query, 'get_queried_object' ) ) ) {
			$local = $query->get_queried_object();
			if ( $local instanceof WP_Term ) {
				return $local;
			}
		}

		$global = get_queried_object();
		return $global instanceof WP_Term ? $global : null;
	}

	/**
	 * Pure clause-injection — takes IDs from the caller, no Redis call.
	 *
	 * Split out so tests can drive the filter without standing up the full
	 * Redis stack. `inject_post_in()` is the production wiring; this is the
	 * logic unit.
	 *
	 * @param array    $clauses Query clauses.
	 * @param WP_Query $query   Executing query (used only for gates).
	 * @param int[]    $ids     Product IDs to restrict the query to. Empty → force no-results.
	 * @return array
	 */
	public function apply_clauses_for_block_query( $clauses, $query, array $ids ) {
		if ( $query->is_main_query() ) {
			return $clauses;
		}

		$post_type = $query->get( 'post_type' );
		if ( 'product' !== $post_type && array( 'product' ) !== $post_type ) {
			return $clauses;
		}

		$queried = $this->resolve_queried_term( $query );
		if ( ! $queried instanceof WP_Term ) {
			return $clauses;
		}

		// Defense in depth — mirror the gate in `inject_post_in()` so this
		// helper is safe to call from any code path, not just our own hook.
		if ( ! isset( self::$scope_map[ $queried->taxonomy ] ) ) {
			return $clauses;
		}

		if ( ! $this->is_scope_enabled( $queried->taxonomy ) ) {
			return $clauses;
		}

		global $wpdb;

		// Keep only positive integer IDs — drops negatives, zeros, non-numeric
		// strings, and SQL-injection attempts (intval bails at the first
		// non-digit). Empty result → force `IN (0)` sentinel so WP_Query sees
		// "no matches" instead of a SQL error on empty IN ().
		$sanitized = array();
		foreach ( $ids as $raw ) {
			$id = (int) $raw;
			if ( $id > 0 ) {
				$sanitized[] = $id;
			}
		}
		if ( empty( $sanitized ) ) {
			$sanitized = array( 0 );
		}

		$ids_sql          = implode( ',', $sanitized );
		$clauses['where'] = ( $clauses['where'] ?? '' ) . " AND {$wpdb->posts}.ID IN ({$ids_sql})";

		return $clauses;
	}

	/**
	 * Fetch Redis IDs for a taxonomy+term+filters combo, with per-request cache.
	 *
	 * Shared between intercept() (main query) and inject_post_in() (block /
	 * sub-queries) so Redis is hit once per page even if multiple WP_Query
	 * instances render the same scoped archive.
	 *
	 * @param WP_Term $term Queried taxonomy term.
	 * @return int[] Product IDs. Empty on Redis unavailable.
	 */
	private function get_cached_ids_for_term( $term ) {
		$this->active_filters = $this->get_active_filters();
		$cache_key            = $term->taxonomy . ':' . $term->term_id . ':' . md5( wp_json_encode( $this->active_filters ) );

		if ( isset( $this->cached_post_ids[ $cache_key ] ) ) {
			return $this->cached_post_ids[ $cache_key ];
		}

		$scope_config = self::$scope_map[ $term->taxonomy ] ?? null;
		if ( null === $scope_config ) {
			$this->cached_post_ids[ $cache_key ] = array();
			return array();
		}

		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			$this->cached_post_ids[ $cache_key ] = array();
			return array();
		}

		$search_query  = new Shift64_Woo_Search_Query( $redis, Shift64_Woo_Search_Settings::search_config() );
		$scope_filters = array( $scope_config['filter_key'] => array( $term->name ) );
		$max_ids       = (int) apply_filters( 'shift64_woo_search_taxonomy_archive_max_ids', 10000 );

		$result                              = $search_query->search_by_filters(
			$scope_filters,
			$this->active_filters,
			$max_ids,
			1
		);
		$this->cached_post_ids[ $cache_key ] = $result['ids'];

		return $this->cached_post_ids[ $cache_key ];
	}

	/**
	 * Restrict a facet_data array to the dimensions declared by the scope.
	 *
	 * Supports exact dimension names (e.g. 'categories', 'attr_pa_kolor') and
	 * prefix wildcards (e.g. 'attr_pa_*'). Empty $allowed acts as a no-op so
	 * callers can pass a missing/empty config without surprise.
	 *
	 * @param array    $facet_data Facet data keyed by dimension name.
	 * @param string[] $allowed    Allowed dimensions (plain names or `prefix*` wildcards).
	 * @return array Filtered facet data.
	 */
	public function filter_dimensions( array $facet_data, array $allowed ) {
		if ( empty( $allowed ) ) {
			return $facet_data;
		}

		$filtered = array();
		foreach ( $facet_data as $dim => $values ) {
			foreach ( $allowed as $pattern ) {
				if ( $pattern === $dim ) {
					$filtered[ $dim ] = $values;
					continue 2;
				}
				if ( '*' === substr( $pattern, -1 ) ) {
					$prefix = substr( $pattern, 0, -1 );
					if ( '' !== $prefix && 0 === strpos( $dim, $prefix ) ) {
						$filtered[ $dim ] = $values;
						continue 2;
					}
				}
			}
		}

		return $filtered;
	}

	/**
	 * Gate for intercept(): main_query + taxonomy archive + scope enabled.
	 *
	 * @param WP_Query $query Query to evaluate.
	 * @return bool
	 */
	private function should_intercept( $query ) {
		if ( ! $query->is_main_query() ) {
			return false;
		}

		// Explicit taxonomy gate — more self-documenting than inferring from
		// get_queried_object(), and robust if WP core ever changes the
		// queried-object contract for archive requests.
		if ( ! $query->is_tax( array_keys( self::$scope_map ) ) ) {
			return false;
		}

		// Only product archive queries — reject 'any' and unrelated post types
		// explicitly, instead of falling through.
		$post_type = $query->get( 'post_type' );
		if ( ! in_array( $post_type, array( '', 'product' ), true ) ) {
			return false;
		}

		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return false;
		}

		return $this->is_scope_enabled( $term->taxonomy );
	}
}
