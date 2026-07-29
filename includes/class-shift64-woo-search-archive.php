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
		add_action( 'woocommerce_archive_description', array( $this, 'render_search_header' ), 5 );
		add_filter( 'woocommerce_show_page_title', array( $this, 'hide_default_page_title' ) );
		add_filter( 'kadence_post_layout', array( $this, 'disable_kadence_hero_on_search' ) );
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );

		// Block template support: filter archive title used by Kadence dynamic headings.
		add_filter( 'get_the_archive_title', array( $this, 'filter_archive_title' ), 20 );
		add_shortcode( 'shift64_woo_search_breadcrumbs', array( $this, 'shortcode_breadcrumbs' ) );
	}

	/**
	 * Append an entry to the request debug log.
	 *
	 * @param string $message Debug message.
	 * @param mixed  $data    Optional structured context.
	 */
	private function log( $message, $data = null ) {
		$entry = sprintf( '[%.1fms] %s', ( microtime( true ) - $this->debug_start ) * 1000, $message );
		if ( null !== $data ) {
			$entry .= ' → ' . ( is_scalar( $data ) ? $data : wp_json_encode( $data ) );
		}
		$this->debug_log[] = $entry;
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
		$paged             = max( 1, (int) $query->get( 'paged' ) );
		$per_page          = (int) $query->get( 'posts_per_page' );
		if ( $per_page <= 0 ) {
			$per_page = (int) get_option( 'posts_per_page', 12 );
		}

		// Determine sort mode.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public archive sorting uses a read-only GET parameter.
		$orderby   = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'relevance';
		$is_price  = in_array( $orderby, array( 'price', 'price-desc' ), true );
		$price_dir = ( 'price-desc' === $orderby ) ? 'DESC' : 'ASC';

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

		$strategy     = $config['strategy'] ?? 'strict_first';
		$price_mode   = get_option( 'shift64_woo_search_price_sort_mode', 'redis' );
		$use_wc_price = $is_price && 'db' === $price_mode && is_user_logged_in();
		$redis_sort   = $is_price && ! $use_wc_price ? "price {$price_dir}" : null;

		if ( $use_wc_price ) {
			$this->log( 'Price sort mode', 'WC (logged-in user — B2B prices from DB)' );
			// Get ALL matching IDs from Redis (no pagination — WC will paginate).
			$result = $this->execute_search( $search_query, $search_term, $strategy, $config, 1000, 1, null, $this->active_filters );
		} else {
			$this->log( 'Sort mode', $redis_sort ? "Redis SORTBY {$redis_sort}" : 'Redis relevance' );
			$result = $this->execute_search( $search_query, $search_term, $strategy, $config, $per_page, $paged, $redis_sort, $this->active_filters );
		}

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

		if ( $use_wc_price ) {
			// WC handles pagination — keep paged as-is.
			// Let WC sort by actual customer prices. WP handles pagination.
			$query->set( 'orderby', 'price' === $orderby ? 'meta_value_num' : 'meta_value_num' );
			$query->set( 'order', $price_dir );
			// WC price sorting uses this meta query internally, but we set post__in
			// to limit to Redis candidate set. WC's own orderby hook will handle the rest.
			$query->set( 'orderby', $orderby );
			$this->log( 'Injected post__in + WC orderby=' . $orderby );
		} else {
			$query->set( 'orderby', 'post__in' );
			// Redis already paginated — reset paged to 1 so WordPress doesn't
			// apply a second OFFSET on top of the already-sliced result set.
			// Restore real paged after SQL executes so WooCommerce displays
			// the correct "Showing X–Y of Z" range.
			$this->real_paged = $paged;
			$query->set( 'paged', 1 );
			add_filter( 'the_posts', array( $this, 'restore_paged' ), 10, 2 );
			$this->log( 'Injected post__in + orderby=post__in' );
			// Override found_posts for correct pagination (Redis handles paging).
			add_filter( 'found_posts', array( $this, 'override_found_posts' ), 10, 2 );
		}
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
	 * @param string|null              $sort     Optional Redis SORTBY, e.g. "price ASC".
	 * @param array                    $filters  Active facet filters from URL.
	 * @return array|false
	 */
	private function execute_search( $search_query, $search_term, $strategy, $config, $per_page, $paged, $sort = null, $filters = array() ) {
		$redis         = Shift64_Woo_Search_Redis::get_instance();
		$index_name    = $redis->get_index_name();
		$sanitized     = $search_query->sanitize_query( $search_term );
		$terms         = $search_query->get_search_terms( $sanitized );
		$stock_mode    = $config['outofstock_mode'] ?? 'exclude';
		$demote_factor = (float) ( $config['outofstock_demote_factor'] ?? 0.3 );

		// For relevance ordering, fetch a larger batch, re-rank in PHP, then paginate.
		$relevance_mode = ( null === $sort );
		if ( $relevance_mode ) {
			$offset      = 0;
			$fetch_limit = max( $per_page * $paged * 3, 300 );
		} else {
			$offset      = ( $paged - 1 ) * $per_page;
			$fetch_limit = ( 'demote' === $stock_mode ) ? $per_page * 3 : $per_page;
		}

		// Pass 1: Strict (prefix).
		$ft_query = $search_query->build_strict_query( $terms, $filters );
		$this->log( 'Pass 1 (strict)', $ft_query );
		$t0     = microtime( true );
		$result = $relevance_mode
			? $this->ft_search_relevance( $redis, $index_name, $ft_query, $terms, $fetch_limit, false, $stock_mode, $demote_factor )
			: $this->ft_search_with_offset( $redis, $index_name, $ft_query, $offset, $fetch_limit, $sort );
		$this->log( 'Pass 1 result', sprintf( 'total=%d ids=%d (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, ( microtime( true ) - $t0 ) * 1000 ) );

		// Pass 2: Token reduction fallback.
		if ( $this->is_empty_result( $result ) && ! empty( $config['token_reduction_enabled'] ) && count( $terms ) > 1 ) {
			$reduced = $search_query->reduce_tokens( $terms );
			if ( count( $reduced ) < count( $terms ) && ! empty( $reduced ) ) {
				$ft_query = $search_query->build_strict_query( $reduced, $filters );
				$this->log( 'Pass 2 (token reduced)', $ft_query );
				$t0     = microtime( true );
				$result = $relevance_mode
					? $this->ft_search_relevance( $redis, $index_name, $ft_query, $terms, $fetch_limit, false, $stock_mode, $demote_factor )
					: $this->ft_search_with_offset( $redis, $index_name, $ft_query, $offset, $fetch_limit, $sort );
				$this->log( 'Pass 2 result', sprintf( 'total=%d ids=%d (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, ( microtime( true ) - $t0 ) * 1000 ) );
			}
		}

		// Pass 3: OR prefix fallback (relax AND → OR).
		if ( $this->is_empty_result( $result ) && 'AND' === strtoupper( $config['logic'] ?? 'OR' ) ) {
			$ft_query = $search_query->build_strict_query( $terms, $filters, 'OR' );
			$this->log( 'Pass 3 (or_prefix)', $ft_query );
			$t0     = microtime( true );
			$result = $relevance_mode
				? $this->ft_search_relevance( $redis, $index_name, $ft_query, $terms, $fetch_limit, true, $stock_mode, $demote_factor )
				: $this->ft_search_with_offset( $redis, $index_name, $ft_query, $offset, $fetch_limit, $sort );
			$this->log( 'Pass 3 result', sprintf( 'total=%d ids=%d (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, ( microtime( true ) - $t0 ) * 1000 ) );
		}

		// Pass 4: Fuzzy fallback. Score-filtered to match Query::search(), which
		// drops sub-threshold fuzzy matches — without this the archive kept
		// noise autocomplete had already discarded. Prefix passes above are
		// deliberately not filtered. See Query::filter_low_scores().
		//
		// Known limit: price-sorted requests take the ft_search_with_offset()
		// branch, which asks Redis to sort and so never fetches scores. A
		// fuzzy pass under ?orderby=price therefore stays unfiltered and can
		// still show what autocomplete would drop. Filtering it would mean
		// re-fetching with WITHSCORES and paginating in PHP, losing the point
		// of the SORTBY branch.
		if ( 'strict_first' === $strategy && $this->is_empty_result( $result ) ) {
			$ft_query = $search_query->build_fuzzy_query( $terms, $filters );
			$this->log( 'Pass 4 (fuzzy)', $ft_query );
			$t0        = microtime( true );
			$min_score = (float) ( $config['fallback_score_threshold'] ?? 0.5 );
			$result    = $relevance_mode
				? $this->ft_search_relevance( $redis, $index_name, $ft_query, $terms, $fetch_limit, false, $stock_mode, $demote_factor, $min_score )
				: $this->ft_search_with_offset( $redis, $index_name, $ft_query, $offset, $fetch_limit, $sort );
			$this->log( 'Pass 4 result', sprintf( 'total=%d ids=%d (%.1fms)', $result ? $result['total'] : 0, $result ? count( $result['ids'] ) : 0, ( microtime( true ) - $t0 ) * 1000 ) );
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
	 * Execute FT.SEARCH with scores + title for relevance re-ranking.
	 *
	 * Fetches a batch from Redis, applies out-of-stock demote and title-start
	 * boost, then returns re-ranked IDs. When $or_mode is true, also applies
	 * term-match-count boost/filter (done here because titles are only
	 * available at this level).
	 *
	 * @param Shift64_Woo_Search_Redis $redis         Redis service.
	 * @param string                   $index_name    RediSearch index name.
	 * @param string                   $ft_query      RediSearch query.
	 * @param array                    $terms         Normalized query terms.
	 * @param int                      $limit         Candidate limit.
	 * @param bool                     $or_mode Apply term-match-count boost and filter.
	 * @param string                   $stock_mode Out-of-stock handling mode.
	 * @param float                    $demote_factor Out-of-stock score multiplier.
	 * @param float                    $min_score  Drop results scoring below this; 0 disables.
	 * @return array|false
	 */
	private function ft_search_relevance( $redis, $index_name, $ft_query, $terms, $limit, $or_mode = false, $stock_mode = 'exclude', $demote_factor = 0.3, $min_score = 0.0 ) {
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
			'3',
			'post_id',
			'title',
			'stock_status',
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

			$post_id = 0;
			$title   = '';
			$stock   = 'instock';
			if ( is_array( $fields_raw ) ) {
				$fc = count( $fields_raw );
				for ( $j = 0; $j < $fc - 1; $j += 2 ) {
					if ( 'post_id' === $fields_raw[ $j ] ) {
						$post_id = (int) $fields_raw[ $j + 1 ];
					} elseif ( 'title' === $fields_raw[ $j ] ) {
						$title = $fields_raw[ $j + 1 ];
					} elseif ( 'stock_status' === $fields_raw[ $j ] ) {
						$stock = $fields_raw[ $j + 1 ];
					}
				}
			}

			if ( $post_id > 0 ) {
				$results[] = array(
					'id'           => $post_id,
					'score'        => $score,
					'title'        => $title,
					'stock_status' => $stock,
				);
			}

			$i += 3; // key + score + fields.
		}

		// Drop sub-threshold matches before any boost, mirroring Query::search()
		// which filters ahead of demote/title-start. Total must follow the
		// filtered set or pagination would advertise pages that cannot render.
		if ( $min_score > 0 ) {
			$results = Shift64_Woo_Search_Query::filter_low_scores( $results, $min_score, 'score' );
			$total   = count( $results );
		}

		if ( 'demote' === $stock_mode ) {
			$demote_factor = max( 0.0, min( 1.0, (float) $demote_factor ) );
			foreach ( $results as &$r ) {
				if ( 'outofstock' === ( $r['stock_status'] ?? 'instock' ) ) {
					$r['score'] *= $demote_factor;
				}
			}
			unset( $r );

			usort(
				$results,
				function ( $a, $b ) {
					return $b['score'] <=> $a['score'];
				}
			);
		}

		// Reuse shared title-start boost from Query class (DRY).
		$results = Shift64_Woo_Search_Query::boost_title_start( $terms, $results, 'score' );

		// For OR mode: boost by term-match ratio + filter minimum matches.
		// Done here (not in caller) because titles are only available at this level.
		if ( $or_mode ) {
			$results = Shift64_Woo_Search_Query::boost_term_match_count( $terms, $results, 0.4, 'score' );
			$total   = count( $results );
		}

		$ids = array();
		foreach ( $results as $r ) {
			$ids[] = $r['id'];
		}

		return array(
			'ids'   => $ids,
			'total' => $total,
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
	 * @param string|null              $sort   Optional "price ASC" or "price DESC".
	 * @return array|false  ['ids' => int[], 'total' => int]
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
			$args[] = $parts[0]; // field name.
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

		$total = (int) $raw[0];
		$ids   = array();

		// Parse: [total, key1, [field_data1], key2, [field_data2], ...]
		// With RETURN 1 post_id: each result has key + fields array ["post_id", "123"].
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
	 * Check if FT.SEARCH result is empty.
	 *
	 * @param array|false $result Search result.
	 * @return bool
	 */
	private function is_empty_result( $result ) {
		return false === $result || empty( $result['ids'] );
	}

	/**
	 * Limit WC sort options to relevance + price.
	 *
	 * @param array $options Available sort options.
	 * @return array
	 */
	public function filter_sort_options( $options ) {
		if ( 'yes' !== get_option( 'shift64_woo_search_archive_enabled', 'no' ) ) {
			return $options;
		}

		if ( ! is_search() ) {
			return $options;
		}

		return array(
			'relevance'  => __( 'Search relevance', 'shift64-woo-search' ),
			'price'      => __( 'Price: low to high', 'shift64-woo-search' ),
			'price-desc' => __( 'Price: high to low', 'shift64-woo-search' ),
		);
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

		// Reset filter render guard for AJAX partial.
		Shift64_Woo_Search_Filters::reset_rendered_flag();

		echo '<div class="kwt-products-wrap">';

		( new Shift64_Woo_Search_Filters() )->render_filters();

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
			echo '</div>';
		}

		exit;
	}

	/**
	 * Render search results header with breadcrumbs and title.
	 *
	 * Hooked to woocommerce_archive_description — fires before the shop loop
	 * but after the main content wrapper opens.
	 */
	public function render_search_header() {
		if ( ! is_search() || 'product' !== get_query_var( 'post_type' ) ) {
			return;
		}

		if ( 'yes' !== get_option( 'shift64_woo_search_archive_enabled', 'no' ) ) {
			return;
		}

		$query = $this->search_term;
		if ( empty( $query ) ) {
			$query = get_search_query();
		}
		if ( empty( $query ) ) {
			return;
		}

		echo '<div class="shift64-woo-search-header">';
		echo '<nav class="shift64-woo-search-header__breadcrumbs" aria-label="breadcrumb">';
		echo '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'shift64-woo-search' ) . '</a>';
		echo '<span class="shift64-woo-search-header__separator"> &gt; </span>';
		echo '<span>' . esc_html__( 'Search results', 'shift64-woo-search' ) . '</span>';
		echo '</nav>';
		echo '<h1 class="shift64-woo-search-header__title">';
		echo esc_html__( 'Search results for:', 'shift64-woo-search' ) . ' &ldquo;' . esc_html( $query ) . '&rdquo;';
		echo '</h1>';
		echo '</div>';
	}

	/**
	 * Hide WooCommerce default page title on search results (we render our own).
	 *
	 * @param bool $show Whether to show the page title.
	 * @return bool
	 */
	public function hide_default_page_title( $show ) {
		if ( is_search() && 'product' === get_query_var( 'post_type' )
			&& 'yes' === get_option( 'shift64_woo_search_archive_enabled', 'no' ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Override archive title for product search pages.
	 *
	 * Kadence Blocks Pro dynamic heading (archive|archive_title) calls
	 * get_the_archive_title(). Since we clear the 's' query var to prevent
	 * MySQL LIKE, get_search_query() returns empty — this filter restores
	 * the original search term in the title.
	 *
	 * @param string $title Archive title.
	 * @return string
	 */
	public function filter_archive_title( $title ) {
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

		return esc_html__( 'Search results for:', 'shift64-woo-search' ) . ' &ldquo;' . esc_html( $query ) . '&rdquo;';
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
	 * Shortcode: render search breadcrumbs for block templates.
	 *
	 * Usage: [shift64_woo_search_breadcrumbs]
	 * Only renders on product search pages; returns empty otherwise.
	 *
	 * @return string
	 */
	public function shortcode_breadcrumbs() {
		if ( ! is_search() || 'product' !== get_query_var( 'post_type' ) ) {
			return '';
		}

		if ( 'yes' !== get_option( 'shift64_woo_search_archive_enabled', 'no' ) ) {
			return '';
		}

		return '<nav class="shift64-woo-search-header__breadcrumbs" aria-label="breadcrumb">'
			. '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'shift64-woo-search' ) . '</a>'
			. '<span class="shift64-woo-search-header__separator"> &gt; </span>'
			. '<span>' . esc_html__( 'Search results', 'shift64-woo-search' ) . '</span>'
			. '</nav>';
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
		echo '</span>';
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
		$facets  = Shift64_Woo_Search_Facets::compute( $search_query, array(), $filters, $terms );
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
		return array(
			'min_query_length'              => (int) get_option( 'shift64_woo_search_min_query', 2 ),
			'autocomplete_limit'            => (int) get_option( 'shift64_woo_search_autocomplete_limit', 7 ),
			'full_limit'                    => (int) get_option( 'shift64_woo_search_full_limit', 20 ),
			'outofstock_mode'               => get_option( 'shift64_woo_search_outofstock_mode', 'exclude' ),
			'outofstock_demote_factor'      => (float) get_option( 'shift64_woo_search_outofstock_demote_factor', 0.3 ),
			'fuzzy_level'                   => (int) get_option( 'shift64_woo_search_fuzzy_level', 1 ),
			'logic'                         => get_option( 'shift64_woo_search_logic', 'OR' ),
			'strategy'                      => get_option( 'shift64_woo_search_strategy', 'strict_first' ),
			'fallback_trigger'              => get_option( 'shift64_woo_search_fallback_trigger', 'low_score' ),
			'fallback_score_threshold'      => (float) get_option( 'shift64_woo_search_fallback_score_threshold', 0.5 ),
			'fallback_fuzzy_level'          => (int) get_option( 'shift64_woo_search_fallback_fuzzy_level', 1 ),
			'token_reduction_enabled'       => get_option( 'shift64_woo_search_token_reduction_enabled', 'yes' ) === 'yes',
			'weak_tokens'                   => get_option( 'shift64_woo_search_weak_tokens', 'do,na,z,i,w,od,po,za,ze,we,o,u,a,e' ),
			'drop_trailing_weak_token_only' => get_option( 'shift64_woo_search_drop_trailing_weak_token_only', 'yes' ) === 'yes',
			'diacritics_normalization'      => get_option( 'shift64_woo_search_diacritics_normalization', 'yes' ) === 'yes',
			'fuzzy_synonyms'                => get_option( 'shift64_woo_search_fuzzy_synonyms', 'no' ) === 'yes',
		);
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
