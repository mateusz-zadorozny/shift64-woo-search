<?php
/**
 * Archive/Search query interceptor — replaces WooCommerce MySQL queries with Redis FT.SEARCH.
 *
 * Phase 6.0: Only intercepts product search results pages (?s=query&post_type=product).
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intercept WooCommerce product search archives and serve Redis results.
 */
class Shift64_Woo_Search_Archive implements Shift64_Woo_Search_Facet_Context {

	/**
	 * Total results from Redis for the found_posts filter.
	 *
	 * @var int|null
	 */
	private $redis_total = null;

	/**
	 * Real page number saved before resetting MySQL pagination.
	 *
	 * @var int|null
	 */
	private $real_paged = null;

	/**
	 * Facet data for filter rendering.
	 *
	 * @var array|null
	 */
	private $facet_data = null;

	/**
	 * Active filters parsed from URL parameters.
	 *
	 * @var array
	 */
	private $active_filters = array();

	/**
	 * Debug log for the current request.
	 *
	 * @var array
	 */
	private $debug_log = array();

	/**
	 * Debug timer start.
	 *
	 * @var float
	 */
	private $debug_start = 0;

	/**
	 * Offset of the last debug entry, in milliseconds since `debug_start`.
	 *
	 * This is where the search work ended. Everything after it is WordPress and
	 * the theme rendering the page, which is what the request-phase breakdown
	 * needs in order to separate "our time" from "everyone else's time".
	 *
	 * @var float
	 */
	private $debug_last_offset = 0;

	/**
	 * Original term saved before clearing the MySQL search value.
	 *
	 * @var string
	 */
	private $search_term = '';

	/**
	 * Static reference for filter renderer access.
	 *
	 * @var Shift64_Woo_Search_Archive|null
	 */
	private static $instance = null;

	/**
	 * Register archive integrations.
	 */
	public function __construct() {
		self::$instance = $this;

		if ( is_admin() ) {
			return;
		}

		add_action( 'pre_get_posts', array( $this, 'intercept' ), 99 );
		add_action( 'wp_footer', array( $this, 'render_debug' ), 999 );
		add_filter( 'woocommerce_catalog_orderby', array( $this, 'filter_sort_options' ) );
		add_filter( 'ngettext_woocommerce', array( $this, 'filter_result_count_text' ), 10, 5 );
		add_filter( 'ngettext_with_context_woocommerce', array( $this, 'filter_result_count_text_ctx' ), 10, 6 );
		add_filter( 'template_include', array( $this, 'maybe_render_partial' ), 999 );
		add_filter( 'paginate_links', array( $this, 'preserve_filter_params_in_pagination' ) );
		add_filter( 'kadence_post_layout', array( $this, 'disable_kadence_hero_on_search' ) );
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );
	}

	/**
	 * Append an entry to the request debug log.
	 *
	 * @param string $message Debug message.
	 * @param mixed  $data    Optional structured context.
	 */
	private function log( $message, $data = null ) {
		$offset                  = ( microtime( true ) - $this->debug_start ) * 1000;
		$this->debug_last_offset = $offset;

		$entry = sprintf( '[%.1fms] %s', $offset, $message );
		if ( null !== $data ) {
			$entry .= ' → ' . ( is_scalar( $data ) ? $data : wp_json_encode( $data ) );
		}
		$this->debug_log[] = $entry;
	}

	/**
	 * Break the PHP side of the request into the three phases that matter.
	 *
	 * The per-entry timings above are all relative to the moment the query was
	 * intercepted, so they answer "how long did the search take" and nothing
	 * else. That is misleading on its own: a page can report a 3ms search and
	 * still take 400ms to arrive, because the search is a small slice of a
	 * request that also has to boot WordPress and render the products.
	 *
	 * Splitting the wall clock into bootstrap / search / render is what makes the
	 * difference legible — and, together with the browser-side numbers the AJAX
	 * script adds, it accounts for the whole gap between this panel and what the
	 * network tab shows.
	 *
	 * `REQUEST_TIME_FLOAT` is when PHP began handling the request, so "bootstrap"
	 * covers WordPress core, every other plugin, and the theme — not just us.
	 *
	 * @return array{total: float, bootstrap: float, search: float, render: float}|null
	 *         Phase durations in milliseconds, or null when the request was never intercepted.
	 */
	private function request_phases() {
		if ( $this->debug_start <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server-generated float, not user input.
		$request_start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
		if ( $request_start <= 0 ) {
			return null;
		}

		$now       = microtime( true );
		$total     = ( $now - $request_start ) * 1000;
		$bootstrap = ( $this->debug_start - $request_start ) * 1000;
		$search    = $this->debug_last_offset;
		$render    = $total - $bootstrap - $search;

		return array(
			'total'     => max( 0, $total ),
			'bootstrap' => max( 0, $bootstrap ),
			'search'    => max( 0, $search ),
			'render'    => max( 0, $render ),
		);
	}

	/**
	 * Render the phase breakdown as one debug line.
	 *
	 * @return string Formatted line, or '' when timings are unavailable.
	 */
	private function server_timing_entry() {
		$phases = $this->request_phases();
		if ( null === $phases ) {
			return '';
		}

		return sprintf(
			'[server] PHP %.1fms → bootstrap %.1fms · search %.1fms · render %.1fms',
			$phases['total'],
			$phases['bootstrap'],
			$phases['search'],
			$phases['render']
		);
	}

	/**
	 * Whether the storefront debug panel may be rendered for this request.
	 *
	 * Off unless a merchant opts in: the panel is a fixed-position overlay on the
	 * storefront, so leaving it on by default put it in front of every shop
	 * manager who happened to be browsing. The capability check stays on top of
	 * the option — the option narrows who sees the panel, never widens it.
	 *
	 * Both render paths (`wp_footer` and the AJAX partial) go through here so they
	 * cannot drift apart.
	 *
	 * @return bool
	 */
	private function debug_enabled() {
		if ( 'yes' !== get_option( 'shift64_woo_search_archive_debug_enabled', 'no' ) ) {
			return false;
		}

		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Check if this query should be intercepted.
	 *
	 * @param WP_Query $query Query to evaluate.
	 * @return bool
	 */
	private function should_intercept( $query ) {
		// Only main frontend search queries.
		if ( ! $query->is_main_query() ) {
			return false;
		}

		if ( ! $query->is_search() ) {
			return false;
		}

		// Only product searches.
		$post_type = $query->get( 'post_type' );
		if ( 'product' !== $post_type ) {
			return false;
		}

		// Feature toggle.
		if ( 'yes' !== get_option( 'shift64_woo_search_archive_enabled', 'no' ) ) {
			return false;
		}

		// Redis must be available.
		$redis = Shift64_Woo_Search_Redis::get_instance();
		if ( ! $redis->is_available() ) {
			return false;
		}

		// Need a search query.
		$search = $query->get( 's' );
		$min    = (int) get_option( 'shift64_woo_search_min_query', 2 );
		if ( empty( $search ) || mb_strlen( trim( $search ) ) < $min ) {
			return false;
		}

		return true;
	}

	/**
	 * Intercept the WP_Query and replace MySQL search with Redis results.
	 *
	 * @param WP_Query $query Query to intercept.
	 */
	public function intercept( $query ) {
		if ( ! $this->should_intercept( $query ) ) {
			return;
		}

		// Start a fresh log alongside the fresh timer. Every entry's timestamp is
		// relative to `debug_start`, so carrying entries across an interception
		// would mix two timelines into one panel — the reset keeps the log
		// describing exactly the query that is about to run.
		$this->debug_log   = array();
		$this->debug_start = microtime( true );
		$search_term       = trim( $query->get( 's' ) );
		$this->search_term = $search_term;
		$paged             = Shift64_Woo_Search_Catalog_State::requested_page( $query->get( 'paged' ) );
		$per_page          = (int) $query->get( 'posts_per_page' );
		if ( $per_page <= 0 ) {
			$per_page = (int) get_option( 'posts_per_page', 12 );
		}

		// Determine sort mode.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public archive sorting uses a read-only GET parameter.
		$requested_orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : null;
		$orderby           = Shift64_Woo_Search_Sort::get_effective_sort( $requested_orderby, true );
		$sort_res          = Shift64_Woo_Search_Sort::resolve_mode( $orderby );
		$sort_mode         = $sort_res['mode'];

		// Parse facet filter parameters from URL.
		$this->active_filters = $this->parse_filter_params();

		// Prevent WooCommerce from redirecting to single product page when filtered
		// results contain exactly 1 item — the user expects to see the filtered list.
		if ( ! empty( $this->active_filters ) ) {
			add_filter( 'woocommerce_redirect_single_search_result', '__return_false' );
		}

		$this->log( 'Intercepted', "q=\"{$search_term}\" page={$paged} per_page={$per_page} orderby={$orderby}" );
		if ( ! empty( $this->active_filters ) ) {
			$this->log( 'Active filters', $this->active_filters );
		}

		$redis  = Shift64_Woo_Search_Redis::get_instance();
		$config = $this->build_config();

		$search_query = new Shift64_Woo_Search_Query( $redis, $config );
		$terms        = $search_query->get_search_terms( $search_query->sanitize_query( $search_term ) );

		if ( empty( $terms ) ) {
			$this->log( 'Skipped — no valid terms after sanitization' );
			return;
		}

		$this->log( 'Terms', $terms );

		$strategy = $config['strategy'] ?? 'strict_first';

		if ( Shift64_Woo_Search_Sort::MODE_WC === $sort_mode ) {
			$candidate_limit = Shift64_Woo_Search_Sort::get_candidate_limit();
			$this->log( 'Sort mode', 'WC candidate pass-through (' . $orderby . ')' );
			$result = $this->execute_candidate_search( $search_query, $search_term, $strategy, $config, $candidate_limit, $this->active_filters );

			if ( false === $result ) {
				$this->log( 'Redis search failed — falling back to MySQL' );
				return;
			}

			if ( $result['total'] > $candidate_limit ) {
				$this->log( sprintf( 'wc-sort candidate limit exceeded (%d > %d) — native fallback', $result['total'], $candidate_limit ) );
				return;
			}

			$post_ids          = $result['ids'];
			$this->redis_total = $result['total'];
			$this->log( 'Results', "candidate_total={$this->redis_total} matched_ids=" . count( $post_ids ) );

			$this->facet_data = $this->compute_facets( $search_query, $search_term, $this->active_filters );
			Shift64_Woo_Search_Facet_Registry::set_current( $this );

			if ( empty( $post_ids ) ) {
				$post_ids = array( 0 );
			}

			$query->set( 'post__in', $post_ids );
			$query->set( 's', '' );
			$query->set( 'post_type', 'product' );
			$query->set( 'orderby', $orderby );

			add_filter( 'the_posts', array( $this, 'restore_search_query_var' ), 5, 2 );
			$this->log( 'Injected post__in + WC orderby=' . $orderby );
			return;
		}

		$sort_label = $sort_res['sort_by']
			? "Redis SORTBY {$sort_res['sort_by']}"
			: ( Shift64_Woo_Search_Sort::MODE_REDIS_COMPOSITE === $sort_mode ? 'Redis composite (menu_order, title)' : 'Redis relevance' );
		$this->log( 'Sort mode', $sort_label );

		$result = $this->execute_search( $search_query, $search_term, $strategy, $config, $per_page, $paged, $sort_res, $this->active_filters );

		if ( false === $result ) {
			$this->log( 'Redis search failed — falling back to MySQL' );
			return;
		}

		$post_ids          = $result['ids'];
		$this->redis_total = $result['total'];

		$this->log( 'Results', "total={$this->redis_total} ids_on_page=" . count( $post_ids ) );

		// Compute facet data for filter sidebar.
		$this->facet_data = $this->compute_facets( $search_query, $search_term, $this->active_filters );

		// Register as the active facet context — renderer reads from here
		// instead of depending on our singleton directly.
		Shift64_Woo_Search_Facet_Registry::set_current( $this );

		// Inject results into WP_Query.
		if ( empty( $post_ids ) ) {
			$post_ids = array( 0 ); // Force no results.
		}

		$query->set( 'post__in', $post_ids );
		$query->set( 's', '' ); // Clear search — prevents MySQL LIKE query.
		$query->set( 'post_type', 'product' );
		$query->set( 'orderby', 'post__in' );

		// The blank `s` only has to survive until the SQL is built.
		add_filter( 'the_posts', array( $this, 'restore_search_query_var' ), 5, 2 );

		// Redis already paginated — reset paged to 1 so WordPress doesn't
		// apply a second OFFSET on top of the already-sliced result set.
		$this->real_paged = $paged;
		$query->set( 'paged', 1 );
		add_filter( 'the_posts', array( $this, 'restore_paged' ), 10, 2 );
		$this->log( 'Injected post__in + orderby=post__in' );
		add_filter( 'found_posts', array( $this, 'override_found_posts' ), 10, 2 );
	}

	/**
	 * Override WP's found_posts with Redis total count for correct pagination.
	 *
	 * @param int      $found_posts Found post count from WordPress.
	 * @param WP_Query $query       Query being filtered.
	 * @return int
	 */
	public function override_found_posts( $found_posts, $query ) {
		if ( $query->is_main_query() && null !== $this->redis_total ) {
			$found_posts = $this->redis_total;
			// Remove filter after use — one-shot.
			remove_filter( 'found_posts', array( $this, 'override_found_posts' ), 10 );
		}
		return $found_posts;
	}

	/**
	 * Restore the search term on the main query after the SQL has executed.
	 *
	 * `intercept()` blanks `s` so WordPress skips its MySQL LIKE search, but the
	 * query var is also the source every renderer reads: WooCommerce's breadcrumb
	 * search trail and theme search headings both call get_search_query(), which
	 * returned an empty string and produced `Search results for “”`. This filter
	 * fires once the posts are back from the database — after the only consumer
	 * that must not see the term — and puts it back for the render pass.
	 *
	 * @param array    $posts Returned posts.
	 * @param WP_Query $query Query that produced the posts.
	 * @return array
	 */
	public function restore_search_query_var( $posts, $query ) {
		if ( $query->is_main_query() && '' !== $this->search_term ) {
			$query->set( 's', $this->search_term );
			remove_filter( 'the_posts', array( $this, 'restore_search_query_var' ), 5 );
		}
		return $posts;
	}

	/**
	 * Restore the real page number after MySQL query has executed.
	 *
	 * We reset paged=1 in pre_get_posts to prevent double pagination (Redis
	 * already sliced the results). This filter fires after SQL but before
	 * WooCommerce reads paged for the "Showing X–Y of Z" display.
	 *
	 * @param array    $posts Returned posts.
	 * @param WP_Query $query Query that produced the posts.
	 * @return array
	 */
	public function restore_paged( $posts, $query ) {
		if ( $query->is_main_query() && null !== $this->real_paged ) {
			$query->set( 'paged', $this->real_paged );
			$this->real_paged = null;
			remove_filter( 'the_posts', array( $this, 'restore_paged' ), 10 );
		}
		return $posts;
	}

	/**
	 * Execute candidate search for WC pass-through mode without pagination.
	 *
	 * @param Shift64_Woo_Search_Query $search_query Query service.
	 * @param string                   $search_term  Original search term.
	 * @param string                   $strategy     Retrieval strategy.
	 * @param array                    $config       Search configuration.
	 * @param int                      $limit        Maximum candidate count.
	 * @param array                    $filters      Active facet filters from URL.
	 * @return array|false
	 */
	private function execute_candidate_search( $search_query, $search_term, $strategy, $config, $limit, $filters = array() ) {
		$redis      = Shift64_Woo_Search_Redis::get_instance();
		$index_name = $redis->get_index_name();
		$sanitized  = $search_query->sanitize_query( $search_term );
		$terms      = $search_query->get_search_terms( $sanitized );

		// Pass 1: Strict (prefix), or the hybrid single pass under 'mixed'.
		$ft_query = 'mixed' === $strategy
			? $search_query->build_hybrid_query( $terms, $filters, null, 'search' )
			: $search_query->build_strict_query( $terms, $filters, null, 'search' );
		$this->log( 'mixed' === $strategy ? 'Candidate Pass 1 (mixed)' : 'Candidate Pass 1 (strict)', $ft_query );
		$t0     = microtime( true );
		$result = $this->ft_search_with_offset( $redis, $index_name, $ft_query, 0, $limit, null );
		$this->log( 'Candidate Pass 1 result', sprintf( 'total=%d ids=%d (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, ( microtime( true ) - $t0 ) * 1000 ) );

		if ( 'mixed' === $strategy ) {
			return $result;
		}

		// Pass 2: Token reduction fallback.
		if ( $this->is_empty_result( $result ) && ! empty( $config['token_reduction_enabled'] ) && count( $terms ) > 1 ) {
			$reduced = $search_query->reduce_tokens( $terms );
			if ( count( $reduced ) < count( $terms ) && ! empty( $reduced ) ) {
				$ft_query = $search_query->build_strict_query( $reduced, $filters, null, 'search' );
				$this->log( 'Candidate Pass 2 (token reduced)', $ft_query );
				$t0     = microtime( true );
				$result = $this->ft_search_with_offset( $redis, $index_name, $ft_query, 0, $limit, null );
				$this->log( 'Candidate Pass 2 result', sprintf( 'total=%d ids=%d (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, ( microtime( true ) - $t0 ) * 1000 ) );
			}
		}

		// Pass 3: Per-token fuzzy — repairs a typo in one word without dropping the rest.
		if ( $this->is_empty_result( $result ) ) {
			$ft_query = $search_query->build_hybrid_query( $terms, $filters, $config['fallback_fuzzy_level'] ?? null, 'search' );
			$this->log( 'Candidate Pass 3 (token_fuzzy)', $ft_query );
			$t0     = microtime( true );
			$result = $this->ft_search_with_offset( $redis, $index_name, $ft_query, 0, $limit, null );
			$this->log( 'Candidate Pass 3 result', sprintf( 'total=%d ids=%d (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, ( microtime( true ) - $t0 ) * 1000 ) );
		}

		// Pass 4: OR prefix fallback.
		if ( $this->is_empty_result( $result ) && 'AND' === strtoupper( $config['logic'] ?? 'AND' ) ) {
			$ft_query = $search_query->build_strict_query( $terms, $filters, 'OR', 'search' );
			$this->log( 'Candidate Pass 4 (or_prefix)', $ft_query );
			$t0     = microtime( true );
			$result = $this->ft_search_with_offset( $redis, $index_name, $ft_query, 0, $limit, null );
			$this->log( 'Candidate Pass 4 result', sprintf( 'total=%d ids=%d (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, ( microtime( true ) - $t0 ) * 1000 ) );
		}

		// Pass 5: Fuzzy fallback.
		if ( $this->is_empty_result( $result ) ) {
			$ft_query = $search_query->build_fuzzy_query( $terms, $filters, null, 'search' );
			$this->log( 'Candidate Pass 5 (fuzzy)', $ft_query );
			$t0     = microtime( true );
			$result = $this->ft_search_with_offset( $redis, $index_name, $ft_query, 0, $limit, null );
			$this->log( 'Candidate Pass 5 result', sprintf( 'total=%d ids=%d (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, ( microtime( true ) - $t0 ) * 1000 ) );
		}

		return $result;
	}

	/**
	 * Execute Redis search with the full fallback chain.
	 *
	 * Returns array with 'ids' and 'total', or false on failure.
	 *
	 * @param Shift64_Woo_Search_Query $search_query Query service.
	 * @param string                   $search_term  Original search term.
	 * @param string                   $strategy     Retrieval strategy.
	 * @param array                    $config       Search configuration.
	 * @param int                      $per_page     Products per page.
	 * @param int                      $paged        Requested page.
	 * @param array                    $sort_res     Resolved sort mode and parameters.
	 * @param array                    $filters      Active facet filters from URL.
	 * @return array|false
	 */
	private function execute_search( $search_query, $search_term, $strategy, $config, $per_page, $paged, $sort_res = array(), $filters = array() ) {
		$redis      = Shift64_Woo_Search_Redis::get_instance();
		$index_name = $redis->get_index_name();
		$sanitized  = $search_query->sanitize_query( $search_term );
		$terms      = $search_query->get_search_terms( $sanitized );
		$stock_mode = $config['outofstock_mode'] ?? 'exclude';

		$sort_mode      = $sort_res['mode'] ?? Shift64_Woo_Search_Sort::MODE_RELEVANCE;
		$relevance_mode = ( Shift64_Woo_Search_Sort::MODE_RELEVANCE === $sort_mode );

		if ( $relevance_mode ) {
			$offset      = 0;
			$fetch_limit = max( $per_page * $paged * 3, 300 );
		} else {
			$offset      = ( $paged - 1 ) * $per_page;
			$fetch_limit = ( 'demote' === $stock_mode && Shift64_Woo_Search_Sort::MODE_REDIS === $sort_mode ) ? $per_page * 3 : $per_page;
		}

		$or_logic  = ( 'OR' === strtoupper( $config['logic'] ?? 'AND' ) );
		$min_score = (float) ( $config['fallback_score_threshold'] ?? 0.5 );
		$needles   = $search_query->term_coverage_needles( $terms );

		// Pass 1: Strict (prefix), or the hybrid single pass under 'mixed'.
		$ft_query = 'mixed' === $strategy
			? $search_query->build_hybrid_query( $terms, $filters, null, 'search' )
			: $search_query->build_strict_query( $terms, $filters, null, 'search' );
		$this->log( 'mixed' === $strategy ? 'Pass 1 (mixed)' : 'Pass 1 (strict)', $ft_query );
		$t0 = microtime( true );
		// Neither pass is score-filtered. 'strict' is exact, and 'mixed' matches
		// every token as prefix OR fuzzy, so it carries exact matches whose TFIDF
		// tracks how common the term is — see Query::pass_is_fuzzy(). Filtering it
		// dropped exact matches for the frequent term "series", which emptied the
		// results page and took its pagination with it, and `$total` follows the
		// filtered count so the page count collapsed with it.
		// 'mixed' is single-pass, so it also re-ranks by term coverage without
		// dropping: no later pass would catch what a match ratio discards.
		$result = 'mixed' === $strategy
			? $this->execute_pass_query( $redis, $index_name, $ft_query, $sort_res, $terms, $offset, $fetch_limit, $or_logic, $stock_mode, 0.0, $needles, 0.0, $search_query, $sanitized )
			: $this->execute_pass_query( $redis, $index_name, $ft_query, $sort_res, $terms, $offset, $fetch_limit, $or_logic, $stock_mode, 0.0, $needles, 0.4, $search_query, $sanitized );
		$this->log( 'Pass 1 result', sprintf( 'total=%d ids=%d cov=%.2f (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, $result['coverage'] ?? 0, ( microtime( true ) - $t0 ) * 1000 ) );

		if ( 'mixed' !== $strategy ) {
			$resolved = ! $this->needs_fallback( $result, $config );

			// Pass 2: Token reduction fallback.
			if ( ! $resolved && ! empty( $config['token_reduction_enabled'] ) && count( $terms ) > 1 ) {
				$reduced = $search_query->reduce_tokens( $terms );
				if ( count( $reduced ) < count( $terms ) && ! empty( $reduced ) ) {
					$ft_query = $search_query->build_strict_query( $reduced, $filters, null, 'search' );
					$this->log( 'Pass 2 (token reduced)', $ft_query );
					$t0        = microtime( true );
					$candidate = $this->execute_pass_query( $redis, $index_name, $ft_query, $sort_res, $reduced, $offset, $fetch_limit, $or_logic, $stock_mode, 0.0, $search_query->term_coverage_needles( $reduced ), 0.4, $search_query, implode( ' ', $reduced ) );
					$this->log( 'Pass 2 result', sprintf( 'total=%d ids=%d cov=%.2f (%.1fms)', $candidate ? $candidate['total'] : 0, $candidate ? count( $candidate['ids'] ) : 0, $candidate['coverage'] ?? 0, ( microtime( true ) - $t0 ) * 1000 ) );
					if ( ! $this->needs_fallback( $candidate, $config ) ) {
						$result   = $candidate;
						$resolved = true;
					}
				}
			}

			// Pass 3: Per-token fuzzy — repairs a typo in one word without dropping
			// the rest. Terminal on any surviving hit, exactly as in Query::search():
			// its matches are approximate, so re-testing coverage would always fail
			// and hand a good answer to the broader passes below.
			if ( ! $resolved ) {
				$ft_query = $search_query->build_hybrid_query( $terms, $filters, $config['fallback_fuzzy_level'] ?? null, 'search' );
				$this->log( 'Pass 3 (token_fuzzy)', $ft_query );
				$t0        = microtime( true );
				$candidate = $this->execute_pass_query( $redis, $index_name, $ft_query, $sort_res, $terms, $offset, $fetch_limit, $or_logic, $stock_mode, 0.0, $needles, 0.0, $search_query, $sanitized );
				$this->log( 'Pass 3 result', sprintf( 'total=%d ids=%d (%.1fms)', $candidate ? $candidate['total'] : 0, $candidate ? count( $candidate['ids'] ) : 0, ( microtime( true ) - $t0 ) * 1000 ) );
				if ( ! $this->is_empty_result( $candidate ) ) {
					$result   = $candidate;
					$resolved = true;
				}
			}

			// Pass 4: OR prefix fallback (relax AND → OR).
			if ( ! $resolved && ! $or_logic ) {
				$ft_query = $search_query->build_strict_query( $terms, $filters, 'OR', 'search' );
				$this->log( 'Pass 4 (or_prefix)', $ft_query );
				$t0     = microtime( true );
				$result = $this->execute_pass_query( $redis, $index_name, $ft_query, $sort_res, $terms, $offset, $fetch_limit, true, $stock_mode, 0.0, $needles, 0.4, $search_query, $sanitized );
				$this->log( 'Pass 4 result', sprintf( 'total=%d ids=%d cov=%.2f (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, $result['coverage'] ?? 0, ( microtime( true ) - $t0 ) * 1000 ) );
				$resolved = ! $this->needs_fallback( $result, $config );
			}

			// Pass 5: Fuzzy fallback.
			if ( ! $resolved ) {
				$ft_query = $search_query->build_fuzzy_query( $terms, $filters, null, 'search' );
				$this->log( 'Pass 5 (fuzzy)', $ft_query );
				$t0     = microtime( true );
				$result = $this->execute_pass_query( $redis, $index_name, $ft_query, $sort_res, $terms, $offset, $fetch_limit, false, $stock_mode, $min_score, $needles, 0.4, $search_query, $sanitized );
				$this->log( 'Pass 5 result', sprintf( 'total=%d ids=%d (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, ( microtime( true ) - $t0 ) * 1000 ) );
			}
		}

		if ( false === $result ) {
			return false;
		}

		// For relevance mode, paginate the re-ranked results.
		if ( $relevance_mode ) {
			$page_offset = ( $paged - 1 ) * $per_page;
			return array(
				'ids'   => array_slice( $result['ids'], $page_offset, $per_page ),
				'total' => $result['total'],
			);
		}

		return array(
			'ids'   => $result['ids'],
			'total' => $result['total'],
		);
	}

	/**
	 * Dispatch one search pass to the appropriate query executor.
	 *
	 * @param Shift64_Woo_Search_Redis $redis         Redis connection.
	 * @param string                   $index_name    Index name.
	 * @param string                   $ft_query      RediSearch query.
	 * @param array                    $sort_res      Resolved sort mode definition.
	 * @param array                    $terms         Search terms.
	 * @param int                      $offset        Offset.
	 * @param int                      $fetch_limit   Limit.
	 * @param bool                     $or_mode       Whether OR fallback mode is active.
	 * @param string                   $stock_mode    Out-of-stock mode.
	 * @param float                    $min_score     Minimum score threshold.
	 * @param array                    $needles       Accepted literals per term, for coverage.
	 * @param float                    $min_ratio     Minimum share of terms a row must match; 0 disables dropping.
	 * @param Shift64_Woo_Search_Query $search_query Query object for shared relevance ranking.
	 * @param string                   $search_text  Sanitized query text for configured boosts.
	 * @return array|false
	 */
	private function execute_pass_query( $redis, $index_name, $ft_query, $sort_res, $terms, $offset, $fetch_limit, $or_mode, $stock_mode, $min_score = 0.0, $needles = array(), $min_ratio = 0.4, $search_query = null, $search_text = '' ) {
		$sort_mode = $sort_res['mode'] ?? Shift64_Woo_Search_Sort::MODE_RELEVANCE;

		if ( Shift64_Woo_Search_Sort::MODE_RELEVANCE === $sort_mode ) {
			return $this->ft_search_relevance( $redis, $index_name, $ft_query, $terms, $fetch_limit, $or_mode, $stock_mode, $min_score, $needles, $min_ratio, $search_query, $search_text );
		}

		if ( Shift64_Woo_Search_Sort::MODE_REDIS_COMPOSITE === $sort_mode && ! empty( $sort_res['sort_fields'] ) ) {
			return $this->ft_aggregate_composite_sort( $redis, $index_name, $ft_query, $offset, $fetch_limit, $sort_res['sort_fields'] );
		}

		if ( Shift64_Woo_Search_Sort::MODE_REDIS === $sort_mode ) {
			return $this->ft_search_with_offset( $redis, $index_name, $ft_query, $offset, $fetch_limit, $sort_res['sort_by'] );
		}

		return $this->ft_search_with_offset( $redis, $index_name, $ft_query, 0, $fetch_limit, null );
	}

	/**
	 * Check whether a search result is empty or failed.
	 *
	 * @param array|false $result Search result.
	 * @return bool
	 */
	private function is_empty_result( $result ) {
		return false === $result || ! isset( $result['ids'] ) || empty( $result['ids'] );
	}

	/**
	 * Whether a pass should hand over to the next one.
	 *
	 * Mirrors Query::should_fallback(): empty, or no result in the leading few
	 * covering every search term. Only relevance mode reports `coverage` —
	 * the other sort modes fetch IDs without document fields, so there they
	 * hand over on an empty result alone.
	 *
	 * @param array|false $result Pass result.
	 * @param array       $config Search configuration.
	 * @return bool
	 */
	private function needs_fallback( $result, $config ) {
		if ( $this->is_empty_result( $result ) ) {
			return true;
		}

		if ( 'no_results' === ( $config['fallback_trigger'] ?? 'low_score' ) ) {
			return false;
		}

		return isset( $result['coverage'] ) && $result['coverage'] < 1.0;
	}

	/**
	 * Execute FT.AGGREGATE with composite SORTBY for menu_order + title.
	 *
	 * @param Shift64_Woo_Search_Redis $redis       Redis service.
	 * @param string                   $index_name  Index name.
	 * @param string                   $ft_query    RediSearch query.
	 * @param int                      $offset      Offset.
	 * @param int                      $limit       Limit.
	 * @param array<string,string>     $sort_fields Field => direction map.
	 * @return array|false
	 */
	private function ft_aggregate_composite_sort( $redis, $index_name, $ft_query, $offset, $limit, array $sort_fields ) {
		$parts        = preg_split( '/\s+/', trim( $ft_query ) );
		$has_positive = false;
		foreach ( $parts as $part ) {
			if ( '' !== $part && '-' !== substr( $part, 0, 1 ) ) {
				$has_positive = true;
				break;
			}
		}
		if ( ! $has_positive ) {
			$ft_query = trim( '* ' . $ft_query );
		}

		$sortby_args = array();
		foreach ( $sort_fields as $field => $dir ) {
			$field_name    = '@' . ltrim( (string) $field, '@' );
			$sortby_args[] = $field_name;
			$sortby_args[] = strtoupper( (string) $dir ) === 'DESC' ? 'DESC' : 'ASC';
		}

		$args   = array(
			'FT.AGGREGATE',
			$index_name,
			$ft_query,
			'LOAD',
			'1',
			'@post_id',
			'SORTBY',
			(string) count( $sortby_args ),
		);
		$args   = array_merge( $args, $sortby_args );
		$args[] = 'LIMIT';
		$args[] = (string) $offset;
		$args[] = (string) $limit;

		$raw = $redis->raw_command( ...$args );
		if ( false === $raw || ! is_array( $raw ) || empty( $raw ) ) {
			return false;
		}

		$total = max( 0, (int) array_shift( $raw ) );
		$ids   = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rc = count( $row );
			for ( $j = 0; $j < $rc - 1; $j += 2 ) {
				if ( 'post_id' === $row[ $j ] || '@post_id' === $row[ $j ] ) {
					$id = absint( $row[ $j + 1 ] );
					if ( $id > 0 ) {
						$ids[] = $id;
					}
					break;
				}
			}
		}

		return array(
			'ids'   => $ids,
			'total' => $total,
		);
	}

	/**
	 * Execute FT.SEARCH with scores and ranking fields for relevance re-ranking.
	 *
	 * Fetches a batch from Redis, applies OR term coverage, then delegates the
	 * remaining ordering rules to Query::rank_relevance_results(). When called
	 * without a Query object, the title-start fallback keeps direct reflection
	 * callers compatible.
	 *
	 * @param Shift64_Woo_Search_Redis $redis         Redis service.
	 * @param string                   $index_name    RediSearch index name.
	 * @param string                   $ft_query      RediSearch query.
	 * @param array                    $terms         Normalized query terms.
	 * @param int                      $limit         Candidate limit.
	 * @param bool                     $or_mode       Apply term-match-count boost and filter.
	 * @param string                   $stock_mode    Out-of-stock handling mode.
	 * @param float                    $min_score     Drop results scoring below this; 0 disables.
	 * @param array                    $needles       Accepted literals per term, for coverage.
	 * @param float                    $min_ratio     Minimum share of terms a row must match; 0 disables dropping.
	 * @param Shift64_Woo_Search_Query $search_query Query object for shared relevance ranking.
	 * @param string                   $search_text  Sanitized query text for configured boosts.
	 * @return array|false
	 */
	private function ft_search_relevance( $redis, $index_name, $ft_query, $terms, $limit, $or_mode = false, $stock_mode = 'exclude', $min_score = 0.0, $needles = array(), $min_ratio = 0.4, $search_query = null, $search_text = '' ) {
		$args = array(
			'FT.SEARCH',
			$index_name,
			$ft_query,
			'SCORER',
			'TFIDF',
			'LIMIT',
			'0',
			(string) $limit,
			'WITHSCORES',
			'RETURN',
			'13',
			'post_id',
			'title',
			'stock_status',
			// The identity fields Query::searchable_text() reads. Without them
			// boost_term_match_count() degrades to title-only here while the
			// dropdown counts a brand or category hit, and the two paths
			// disagree about which products matched.
			'title_ascii',
			'sku_text',
			'categories_text',
			'brands_text',
			'attributes',
			'sku',
			'old_number',
			'categories',
			'tags',
			'promoted',
			'DIALECT',
			'2',
		);

		$raw = $redis->raw_command( ...$args );
		if ( false === $raw || ! is_array( $raw ) || count( $raw ) < 2 ) {
			return false;
		}

		$total   = (int) $raw[0];
		$results = array();
		$i       = 1;
		$count   = count( $raw );

		while ( $i < $count ) {
			$score      = isset( $raw[ $i + 1 ] ) ? (float) $raw[ $i + 1 ] : 0;
			$fields_raw = isset( $raw[ $i + 2 ] ) ? $raw[ $i + 2 ] : array();

			$fields = array();
			if ( is_array( $fields_raw ) ) {
				$fc = count( $fields_raw );
				for ( $j = 0; $j < $fc - 1; $j += 2 ) {
					$fields[ $fields_raw[ $j ] ] = $fields_raw[ $j + 1 ];
				}
			}

			$post_id = isset( $fields['post_id'] ) ? (int) $fields['post_id'] : 0;
			if ( $post_id > 0 ) {
				$fields['id']           = $post_id;
				$fields['score']        = $score;
				$fields['title']        = $fields['title'] ?? '';
				$fields['stock_status'] = $fields['stock_status'] ?? 'instock';
				$results[]              = $fields;
			}

			$i += 3;
		}

		$fetched = count( $results );

		if ( $min_score > 0 ) {
			$results = Shift64_Woo_Search_Query::filter_low_scores( $results, $min_score, 'score' );
		}

		if ( $or_mode ) {
			// Apply term coverage before the shared relevance chain so a complete
			// answer is not accepted merely because another title starts with a
			// query prefix.
			$results = Shift64_Woo_Search_Query::boost_term_match_count( $terms, $results, $min_ratio, 'score' );
		}

		$results = $search_query->rank_relevance_results(
			'' !== $search_text ? $search_text : implode( ' ', $terms ),
			$terms,
			$results,
			$stock_mode,
			'score'
		);

		// `$total` is the RediSearch hit count and becomes found_posts, so it
		// drives the result count and the page links. This pass only ever sees
		// the first `$limit` rows of it, so replacing it with the surviving row
		// count collapsed a 5 000-hit OR query to "of 300 results" — for `mixed`
		// too, where the match ratio drops nothing. Take off only what the
		// score threshold and the match ratio actually removed.
		$dropped = $fetched - count( $results );
		if ( $dropped > 0 ) {
			$total = max( count( $results ), $total - $dropped );
		}

		$ids = array();
		foreach ( $results as $r ) {
			$ids[] = $r['id'];
		}

		return array(
			'ids'      => $ids,
			'total'    => $total,
			// Only relevance mode returns document fields, so only relevance
			// mode can answer "did this pass cover every term". The other sort
			// modes fetch IDs alone and hand over on an empty result instead.
			'coverage' => Shift64_Woo_Search_Query::best_term_coverage( $needles, $results ),
		);
	}

	/**
	 * Execute FT.SEARCH with LIMIT offset/count and return post IDs + total.
	 *
	 * @param Shift64_Woo_Search_Redis $redis      Redis service.
	 * @param string                   $index_name RediSearch index name.
	 * @param string                   $ft_query   RediSearch query.
	 * @param int                      $offset     Result offset.
	 * @param int                      $limit      Result limit.
	 * @param string|null              $sort       Optional SORTBY clause.
	 * @return array|false
	 */
	private function ft_search_with_offset( $redis, $index_name, $ft_query, $offset, $limit, $sort = null ) {
		$args = array(
			'FT.SEARCH',
			$index_name,
			$ft_query,
			'SCORER',
			'TFIDF',
		);

		if ( $sort ) {
			$parts  = explode( ' ', $sort );
			$args[] = 'SORTBY';
			$args[] = $parts[0];
			$args[] = isset( $parts[1] ) ? strtoupper( $parts[1] ) : 'ASC';
		}

		$args[] = 'LIMIT';
		$args[] = (string) $offset;
		$args[] = (string) $limit;
		$args[] = 'RETURN';
		$args[] = '1';
		$args[] = 'post_id';
		$args[] = 'DIALECT';
		$args[] = '2';

		$raw = $redis->raw_command( ...$args );

		if ( false === $raw || ! is_array( $raw ) || empty( $raw ) ) {
			return false;
		}

		$total     = (int) $raw[0];
		$ids       = array();
		$raw_count = count( $raw );
		for ( $i = 1; $i < $raw_count; $i += 2 ) {
			$fields_raw = $raw[ $i + 1 ] ?? array();
			if ( is_array( $fields_raw ) && count( $fields_raw ) >= 2 ) {
				$ids[] = (int) $fields_raw[1];
			}
		}

		return array(
			'ids'   => $ids,
			'total' => $total,
		);
	}

	/**
	 * Filter WooCommerce catalog orderby options on search pages.
	 *
	 * Core WooCommerce removes 'Default sorting' on search results and maps
	 * default menu_order to relevance. Shift64 prepends relevance and removes
	 * menu_order in search contexts while retaining the remaining options.
	 *
	 * @param array<string,string> $options Available sort options.
	 * @return array<string,string>
	 */
	public function filter_sort_options( $options ) {
		if ( 'yes' !== get_option( 'shift64_woo_search_archive_enabled', 'no' ) ) {
			return $options;
		}

		if ( ! is_search() ) {
			return $options;
		}

		$filtered = array(
			'relevance' => __( 'Search relevance', 'shift64-woo-search' ),
		);

		foreach ( $options as $key => $label ) {
			if ( 'menu_order' === $key ) {
				continue;
			}
			$filtered[ $key ] = $label;
		}

		return $filtered;
	}

	/**
	 * Replace WooCommerce result count text on search pages (_n variant).
	 *
	 * @param string $translation Translated text.
	 * @param string $single      Singular form.
	 * @param string $plural      Plural form.
	 * @param int    $number      Count.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_result_count_text( $translation, $single, $plural, $number, $domain ) {
		return $this->replace_result_count( $translation, $single );
	}

	/**
	 * Replace WooCommerce result count text on search pages (_nx variant).
	 *
	 * @param string $translation Translated text.
	 * @param string $single      Singular form.
	 * @param string $plural      Plural form.
	 * @param int    $number      Count.
	 * @param string $context     Translation context.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_result_count_text_ctx( $translation, $single, $plural, $number, $context, $domain ) {
		return $this->replace_result_count( $translation, $single );
	}

	/**
	 * Shared result count replacement logic.
	 *
	 * Returns a format string — WooCommerce's printf() fills in the placeholders.
	 *
	 * @param string $translation Original translated text.
	 * @param string $single      English singular form to match against.
	 * @return string
	 */
	private function replace_result_count( $translation, $single ) {
		if ( ! is_search() || 'product' !== get_query_var( 'post_type' ) ) {
			return $translation;
		}

		if ( 'Showing all %1$d result' === $single ) {
			return 'Products: %1$d';
		}

		if ( 'Showing %1$d&ndash;%2$d of %3$d result' === $single ) {
			return 'Products: %3$d';
		}

		if ( 'Showing the single result' === $single ) {
			return 'Products: 1';
		}

		return $translation;
	}

	/**
	 * For AJAX filter/pagination requests, render only the product wrap fragment
	 * instead of the full page. Skips header, footer, sidebar, wp_head/wp_footer.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public function maybe_render_partial( $template ) {
		if ( empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) || 'XMLHttpRequest' !== $_SERVER['HTTP_X_REQUESTED_WITH'] ) {
			return $template;
		}

		if ( ! is_search() || 'product' !== get_query_var( 'post_type' ) ) {
			return $template;
		}

		if ( 'yes' !== get_option( 'shift64_woo_search_archive_enabled', 'no' ) ) {
			return $template;
		}

		// Only intercept with partial fragment for Kadence theme.
		// Standard Woo and block themes rely on full-page template rendering
		// so block-template product cards (wp-block-post-title) keep their styling.
		$is_kadence = function_exists( 'kadence' ) || defined( 'KADENCE_VERSION' ) || 'kadence' === get_template() || 'kadence' === get_stylesheet();
		if ( ! $is_kadence ) {
			return $template;
		}

		echo '<div class="kwt-products-wrap">';

		// Top row: result count + ordering (Kadence hooks).
		echo '<div class="kadence-shop-top-row">';
		echo '<div class="kadence-shop-top-item kadence-woo-results-count">';
		woocommerce_result_count();
		echo '</div>';
		echo '<div class="kadence-shop-top-item kadence-woo-ordering">';
		woocommerce_catalog_ordering();
		echo '</div>';
		echo '</div>';

		// Product loop.
		if ( woocommerce_product_loop() ) {
			woocommerce_product_loop_start();

			if ( wc_get_loop_prop( 'total' ) ) {
				while ( have_posts() ) {
					the_post();
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
					do_action( 'woocommerce_shop_loop' );
					wc_get_template_part( 'content', 'product' );
				}
			}

			woocommerce_product_loop_end();

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
			do_action( 'woocommerce_after_shop_loop' );
		} else {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
			do_action( 'woocommerce_no_products_found' );
		}

		echo '</div>';

		// Debug info for admins, when the storefront debug panel is switched on.
		if ( ! empty( $this->debug_log ) && $this->debug_enabled() ) {
			echo '<!-- Shift64 Archive Debug (partial) -->';
			echo '<div class="shift64-woo-search-archive-debug" style="display:none">';
			foreach ( $this->debug_log as $entry ) {
				echo esc_html( $entry ) . "\n";
			}

			$server_timing = $this->server_timing_entry();
			if ( '' !== $server_timing ) {
				echo esc_html( $server_timing ) . "\n";
			}

			echo '</div>';
		}

		exit;
	}

	/**
	 * Override the browser document title on product search pages.
	 *
	 * We clear the main query's `s` param to skip MySQL LIKE, which leaves core
	 * search-title builders with an empty query string. Use the original term
	 * captured in intercept() so the browser title remains meaningful.
	 *
	 * @param string $title Current document title.
	 * @return string
	 */
	public function filter_document_title( $title ) {
		if ( ! is_search() || 'product' !== get_query_var( 'post_type' ) ) {
			return $title;
		}

		if ( 'yes' !== get_option( 'shift64_woo_search_archive_enabled', 'no' ) ) {
			return $title;
		}

		$query = $this->search_term;
		if ( empty( $query ) ) {
			$query = get_search_query();
		}
		if ( empty( $query ) ) {
			return $title;
		}

		return sprintf(
			/* translators: %s: search query. */
			esc_html__( 'Search results for: "%s"', 'shift64-woo-search' ),
			$query
		);
	}

	/**
	 * Disable Kadence theme hero title section on product search pages.
	 *
	 * Kadence renders an archive hero (breadcrumbs + title) above the content
	 * when title layout is "above". We hide it and render our own header
	 * via woocommerce_archive_description instead.
	 *
	 * @param array $layout Kadence layout settings.
	 * @return array
	 */
	public function disable_kadence_hero_on_search( $layout ) {
		if ( is_search() && 'product' === get_query_var( 'post_type' )
			&& 'yes' === get_option( 'shift64_woo_search_archive_enabled', 'no' ) ) {
			$layout['title'] = 'hide';
		}
		return $layout;
	}

	/**
	 * Render debug info in wp_footer — only for admins who opted in.
	 */
	public function render_debug() {
		if ( empty( $this->debug_log ) ) {
			return;
		}

		if ( ! $this->debug_enabled() ) {
			return;
		}

		// HTML comment (visible in View Source).
		echo "\n<!-- Shift64 Woo Search Archive Debug\n";
		foreach ( $this->debug_log as $line ) {
			echo esc_html( $line ) . "\n";
		}
		echo "-->\n";

		// Visual debug bar. Styling lives in the frontend stylesheet rather than an
		// inline style attribute so the AJAX handler can rebuild the bar after a
		// filter change without restating it in JavaScript.
		//
		// The lines sit in their own container: a filter change replaces only that
		// container's contents, which keeps the heading stable and means the JS
		// never has to know how the bar is titled.
		echo '<div class="shift64-woo-search-debug-bar">';
		echo '<strong>Shift64 Archive Debug</strong><br>';
		echo '<span class="shift64-woo-search-debug-bar__lines">';
		foreach ( $this->debug_log as $line ) {
			echo esc_html( $line ) . '<br>';
		}

		$server_timing = $this->server_timing_entry();
		if ( '' !== $server_timing ) {
			echo esc_html( $server_timing ) . '<br>';
		}

		echo '</span>';

		// Owned by the AJAX script, which fills it with Navigation/Resource Timing
		// numbers once the browser has them. Kept out of the lines container so a
		// filter-change refresh replacing the server lines cannot wipe it.
		echo '<span class="shift64-woo-search-debug-bar__client"></span>';
		echo '</div>';
	}

	/**
	 * Get the singleton instance (for filter renderer access).
	 *
	 * @return Shift64_Woo_Search_Archive|null
	 */
	public static function get_instance() {
		return self::$instance;
	}

	/**
	 * Get computed facet data for filter rendering.
	 *
	 * @return array|null Keyed by dimension: ['categories' => [...], 'attr_pa_kolor' => [...]]
	 */
	public function get_facet_data() {
		return $this->facet_data;
	}

	/**
	 * Get active filters parsed from URL parameters.
	 *
	 * @return array
	 */
	public function get_active_filters() {
		return $this->active_filters;
	}

	/**
	 * Get the total number of results from the last Redis search.
	 *
	 * @return int|null
	 */
	public function get_redis_total() {
		return $this->redis_total;
	}

	/**
	 * Parse filter parameters from the URL query string.
	 *
	 * Converts slugs to term names for TAG matching in Redis.
	 *
	 * @return array Filters array compatible with build_filter_parts().
	 */
	private function parse_filter_params() {
		$filters = array();

		// Category filter.
		if ( ! empty( $_GET['filter_product_cat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL filter.
			$cats      = array_map( 'sanitize_text_field', explode( ',', sanitize_text_field( wp_unslash( $_GET['filter_product_cat'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$cat_names = array();
			foreach ( $cats as $slug ) {
				$term = get_term_by( 'slug', $slug, 'product_cat' );
				if ( $term ) {
					$cat_names[] = $term->name;
				}
			}
			if ( ! empty( $cat_names ) ) {
				$filters['category'] = $cat_names;
			}
		}

		// Brand filter. Unknown slugs are ignored, same as categories.
		if ( ! empty( $_GET['filter_product_brand'] ) && taxonomy_exists( 'product_brand' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL filter.
			$brands      = array_map( 'sanitize_text_field', explode( ',', sanitize_text_field( wp_unslash( $_GET['filter_product_brand'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$brand_names = array();
			foreach ( $brands as $slug ) {
				$term = get_term_by( 'slug', $slug, 'product_brand' );
				if ( $term ) {
					$brand_names[] = $term->name;
				}
			}
			if ( ! empty( $brand_names ) ) {
				$filters['brand'] = $brand_names;
			}
		}

		// Attribute filters.
		$filter_attrs = Shift64_Woo_Search_Schema::get_filter_attributes();
		foreach ( $filter_attrs as $taxonomy ) {
			$param_key = 'filter_' . $taxonomy;
			if ( ! empty( $_GET[ $param_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL filter.
				$slugs = array_map( 'sanitize_text_field', explode( ',', sanitize_text_field( wp_unslash( $_GET[ $param_key ] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$names = array();
				foreach ( $slugs as $slug ) {
					$term = get_term_by( 'slug', $slug, $taxonomy );
					if ( $term ) {
						$names[] = $term->name;
					}
				}
				if ( ! empty( $names ) ) {
					$filters[ 'attr_' . $taxonomy ] = $names;
				}
			}
		}

		return $filters;
	}

	/**
	 * Compute facet counts for all configured filter dimensions.
	 *
	 * Thin wrapper around `Shift64_Woo_Search_Facets::compute()` — the actual
	 * orchestration lives in the service so taxonomy archives can reuse it
	 * without depending on search-archive state. Kept here so existing call
	 * sites inside this class don't need to change.
	 *
	 * @param Shift64_Woo_Search_Query $search_query Query builder instance.
	 * @param string                   $search_term  Original search term.
	 * @param array                    $filters      Active user filters.
	 * @return array Keyed by dimension name.
	 */
	private function compute_facets( $search_query, $search_term, $filters ) {
		$terms = $search_query->get_search_terms( $search_query->sanitize_query( $search_term ) );

		// Preserve pre-refactor behavior: search archive bails out without terms.
		// The new service accepts $terms=null, but search context has no scope to fall back on.
		if ( empty( $terms ) ) {
			return array();
		}

		$t0      = microtime( true );
		$facets  = Shift64_Woo_Search_Facets::compute( $search_query, array(), $filters, $terms, 'search' );
		$elapsed = ( microtime( true ) - $t0 ) * 1000;
		$this->log( 'Facets computed', sprintf( '%d dimensions in %.1fms', count( $facets ), $elapsed ) );

		return $facets;
	}

	/**
	 * Build search config from wp_options (same as admin/SHORTINIT).
	 *
	 * @return array
	 */
	private function build_config() {
		return Shift64_Woo_Search_Settings::search_config();
	}

	/**
	 * Append active filter_pa_* parameters to WooCommerce pagination links.
	 *
	 * Without this, clicking page 2 while a filter is active silently drops
	 * the filter and shows unfiltered results.
	 *
	 * @param string $link Pagination link HTML.
	 * @return string Modified link with filter params preserved.
	 */
	public function preserve_filter_params_in_pagination( $link ) {
		if ( empty( $this->active_filters ) ) {
			return $link;
		}

		// Collect filter_* query params from the current request.
		$filter_params = array();
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( str_starts_with( $key, 'filter_' ) ) {
				$filter_params[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}

		if ( empty( $filter_params ) ) {
			return $link;
		}

		// Inject filter params into each href in the pagination link.
		return preg_replace_callback(
			'/href=["\']([^"\']+)["\']/',
			function ( $matches ) use ( $filter_params ) {
				$url = html_entity_decode( $matches[1] );
				$url = add_query_arg( $filter_params, $url );
				return 'href="' . esc_url( $url ) . '"';
			},
			$link
		);
	}
}
