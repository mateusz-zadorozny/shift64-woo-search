<?php
/**
 * WooCommerce Product Collection query adapter.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapts eligible inherited Product Collections to Redis membership.
 */
class Shift64_Woo_Search_Product_Collection_Query {

	const QUERY_MARKER = 'shift64_woo_search_product_collection_key';

	/**
	 * Query service.
	 *
	 * @var Shift64_Woo_Search_Product_Collection_Query_Service
	 */
	private $service;

	/**
	 * Cached request-level eligibility.
	 *
	 * @var bool|null
	 */
	private $request_eligible;

	/**
	 * Results prepared before WooCommerce decides how to build child queries.
	 *
	 * @var array<string,Shift64_Woo_Search_Product_Collection_Result|null>
	 */
	private $prepared_results = array();

	/**
	 * Sequence for request-local result keys.
	 *
	 * @var int
	 */
	private $result_sequence = 0;

	/**
	 * Main WooCommerce loop values saved while rendering the count block.
	 *
	 * @var array<string,int>|null
	 */
	private $saved_results_count_loop = null;

	/**
	 * Register public query hooks.
	 *
	 * @param Shift64_Woo_Search_Product_Collection_Query_Service|null $service Query service.
	 */
	public function __construct( $service = null ) {
		$this->service = $service ?? new Shift64_Woo_Search_Product_Collection_Query_Service();
		add_filter( 'render_block_context', array( $this, 'scope_inherited_query_context' ), 99, 2 );
		add_filter( 'pre_render_block', array( $this, 'track_results_count_block' ), 10, 3 );
		add_filter( 'render_block', array( $this, 'restore_count_loop' ), 10, 3 );
		add_filter( 'query_loop_block_query_vars', array( $this, 'filter_query_vars' ), 99, 3 );
		add_filter( 'found_posts', array( $this, 'filter_found_posts' ), 99, 2 );
	}

	/**
	 * Mark the WooCommerce block whose renderer calls woocommerce_result_count().
	 *
	 * The Product Results Count block is a sibling of the Product Collection,
	 * so it does not inherit the collection query's request-scoped result.
	 * The loop total is adjusted before WooCommerce builds the block's template
	 * arguments, then restored by the render_block filter after the block.
	 *
	 * @param string|null   $pre_render   Existing short-circuit value.
	 * @param array         $parsed_block Parsed block.
	 * @param WP_Block|null $parent_block Parent block.
	 * @return string|null
	 */
	public function track_results_count_block( $pre_render, $parsed_block, $parent_block = null ) {
		unset( $parent_block );
		if ( 'woocommerce/product-results-count' === ( $parsed_block['blockName'] ?? '' ) ) {
			$this->use_collection_total_for_count();
		}
		return $pre_render;
	}

	/**
	 * Make WooCommerce's result-count template read the Redis collection total.
	 */
	public function use_collection_total_for_count() {
		if ( null !== $this->saved_results_count_loop ) {
			return;
		}

		$count_page = Shift64_Woo_Search_Catalog_State::requested_page( 1, null, null, null );
		$context    = Shift64_Woo_Search_Product_Collection_Context::for_current_request( 'shift64-woo-search-result-count', $count_page );
		if ( null === $context ) {
			return;
		}

		$result = $this->service->execute(
			$context,
			Shift64_Woo_Search_Catalog_State::from_request( $context )
		);
		if ( Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS !== $result->get_status() ) {
			return;
		}

		Shift64_Woo_Search_Product_Collection_Results::set( $result );
		$collection_per_page            = $this->current_collection_per_page();
		$this->saved_results_count_loop = array(
			'total'        => (int) wc_get_loop_prop( 'total' ),
			'per_page'     => (int) wc_get_loop_prop( 'per_page' ),
			'current_page' => (int) wc_get_loop_prop( 'current_page' ),
		);
		wc_set_loop_prop( 'total', $result->get_total() );
		wc_set_loop_prop( 'current_page', $result->get_page() );
		if ( null !== $collection_per_page ) {
			wc_set_loop_prop( 'per_page', $collection_per_page );
		}
	}

	/**
	 * Read the active Product Collection page size before its sibling renders.
	 *
	 * WooCommerce renders Product Results Count before the Product Collection,
	 * so the collection's request context is not available yet. The active
	 * block template is already resolved at this point; use its saved block
	 * attributes to keep WooCommerce's range text aligned with the collection.
	 *
	 * @return int|null Product Collection page size, or null when unavailable.
	 */
	private function current_collection_per_page() {
		global $_wp_current_template_content;

		if ( ! is_string( $_wp_current_template_content ) || '' === $_wp_current_template_content ) {
			return null;
		}

		return $this->find_collection_per_page( parse_blocks( $_wp_current_template_content ) );
	}

	/**
	 * Find the first Product Collection page size in parsed template blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return int|null Product Collection page size, or null when not found.
	 */
	private function find_collection_per_page( array $blocks ) {
		foreach ( $blocks as $block ) {
			if ( 'woocommerce/product-collection' === ( $block['blockName'] ?? '' ) ) {
				$per_page = $block['attrs']['query']['perPage'] ?? null;
				if ( is_numeric( $per_page ) && (int) $per_page > 0 ) {
					return (int) $per_page;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$per_page = $this->find_collection_per_page( $block['innerBlocks'] );
				if ( null !== $per_page ) {
					return $per_page;
				}
			}
		}

		return null;
	}

	/**
	 * Restore the main loop after the count block has rendered.
	 *
	 * @param string        $block_content Rendered block content.
	 * @param array         $parsed_block  Parsed block.
	 * @param WP_Block|null $block        Block instance.
	 * @return string
	 */
	public function restore_count_loop( $block_content, $parsed_block, $block = null ) {
		unset( $block );
		if (
			'woocommerce/product-results-count' !== ( $parsed_block['blockName'] ?? '' )
			|| null === $this->saved_results_count_loop
		) {
			return $block_content;
		}

		foreach ( $this->saved_results_count_loop as $prop => $value ) {
			wc_set_loop_prop( $prop, $value );
		}
		$this->saved_results_count_loop = null;
		return $block_content;
	}

	/**
	 * Route inherited Product Collection query consumers through a scoped query.
	 *
	 * WooCommerce clones the already-executed main query for inherited
	 * collections. After a successful Redis query, changing only the child block
	 * context to non-inherited makes its public query builder run, where the late
	 * adapter can constrain Redis membership. A native-fallback result leaves the
	 * inherited context untouched. The parsed block attributes and main query
	 * remain untouched.
	 *
	 * @param array $context      Available block context.
	 * @param array $parsed_block Parsed block.
	 * @return array
	 */
	public function scope_inherited_query_context( $context, $parsed_block ) {
		if ( ! is_array( $context ) || ! is_array( $parsed_block ) ) {
			return $context;
		}

		$block_name = $parsed_block['blockName'] ?? '';
		if ( ! self::is_query_consumer( $block_name ) ) {
			return $context;
		}

		$query = is_array( $context['query'] ?? null ) ? $context['query'] : array();
		if (
			true !== ( $query['isProductCollectionBlock'] ?? false )
			|| true !== ( $query['inherit'] ?? false )
			|| ! $this->is_eligible_request()
		) {
			return $context;
		}

		$signature = $this->context_signature( $context );
		if ( ! array_key_exists( $signature, $this->prepared_results ) ) {
			$request_key = sprintf(
				'pc-%s-scope-%d',
				isset( $context['queryId'] ) ? absint( $context['queryId'] ) : 'fallback',
				++$this->result_sequence
			);
			$resolved    = Shift64_Woo_Search_Product_Collection_Context::from_render_context( $context, $request_key );
			if ( null === $resolved ) {
				$this->prepared_results[ $signature ] = null;
				return $context;
			}

			$state  = Shift64_Woo_Search_Catalog_State::from_request( $resolved );
			$result = $this->service->execute( $resolved, $state );
			Shift64_Woo_Search_Product_Collection_Results::set( $result );
			$this->prepared_results[ $signature ] = $result;
		}

		$result = $this->prepared_results[ $signature ];
		if (
			! $result instanceof Shift64_Woo_Search_Product_Collection_Result
			|| Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS !== $result->get_status()
		) {
			return $context;
		}

		$query[ Shift64_Woo_Search_Product_Collection_Context::SCOPED_INHERIT_MARKER ] = true;
		$query[ Shift64_Woo_Search_Product_Collection_Context::SCOPED_RESULT_KEY ]     = $result->get_request_key();
		$query['inherit'] = false;
		$context['query'] = $query;
		return $context;
	}

	/**
	 * Resolve archive eligibility once per request.
	 *
	 * @return bool
	 */
	private function is_eligible_request() {
		if ( null === $this->request_eligible ) {
			$this->request_eligible = 'yes' === get_option( 'shift64_woo_search_archive_enabled', 'no' )
				&& Shift64_Woo_Search_Product_Collection_Context::is_current_archive_request();
		}
		return $this->request_eligible;
	}

	/**
	 * Build a stable key shared by one collection's query-consuming children.
	 *
	 * @param array $context Block context.
	 * @return string
	 */
	private function context_signature( array $context ) {
		return md5(
			wp_json_encode(
				array(
					'queryId' => $context['queryId'] ?? null,
					'query'   => $context['query'] ?? array(),
				)
			)
		);
	}

	/**
	 * Whether a Product Collection child executes or counts its query.
	 *
	 * @param string $block_name Block name.
	 * @return bool
	 */
	private static function is_query_consumer( $block_name ) {
		return in_array(
			$block_name,
			array(
				'woocommerce/product-template',
				'woocommerce/product-collection-no-results',
				'core/query-pagination-next',
				'core/query-pagination-previous',
				'core/query-pagination-numbers',
				'core/query-total',
			),
			true
		);
	}

	/**
	 * Adapt a WooCommerce-composed query.
	 *
	 * @param array    $query_vars Query variables.
	 * @param WP_Block $block Product Collection block.
	 * @param int      $page Query Loop page.
	 * @return array
	 */
	public function filter_query_vars( $query_vars, $block, $page ) {
		if ( ! is_array( $query_vars ) ) {
			return $query_vars;
		}

		$context = Shift64_Woo_Search_Product_Collection_Context::from_block( $query_vars, $block, $page );
		if ( null === $context ) {
			return $query_vars;
		}

		$result = Shift64_Woo_Search_Product_Collection_Results::get( $context->get_request_key() );
		if ( null === $result ) {
			$state  = Shift64_Woo_Search_Catalog_State::from_request( $context );
			$result = $this->service->execute( $context, $state );
			Shift64_Woo_Search_Product_Collection_Results::set( $result );
		}

		return self::apply_result( $query_vars, $result );
	}

	/**
	 * Pure query-var adaptation unit.
	 *
	 * @param array                                        $query_vars Existing query variables.
	 * @param Shift64_Woo_Search_Product_Collection_Result $result Redis result.
	 * @return array
	 */
	public static function apply_result( array $query_vars, Shift64_Woo_Search_Product_Collection_Result $result ) {
		$status = $result->get_status();
		if ( Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS !== $status && Shift64_Woo_Search_Product_Collection_Result::STATUS_WC_PASS_THROUGH !== $status ) {
			return $query_vars;
		}

		$ids = array_values( array_unique( array_map( 'absint', $result->get_product_ids() ) ) );
		if ( ! empty( $query_vars['post__in'] ) && is_array( $query_vars['post__in'] ) ) {
			$allowed = array_values(
				array_filter(
					array_unique( array_map( 'intval', $query_vars['post__in'] ) ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			);
			$ids     = array_values( array_intersect( $ids, $allowed ) );
		}

		$query_vars['post__in']           = empty( $ids ) ? array( 0 ) : $ids;
		$query_vars['s']                  = '';
		$query_vars[ self::QUERY_MARKER ] = $result->get_request_key();

		if ( Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS === $status ) {
			$query_vars['orderby'] = 'post__in';
			$query_vars['paged']   = 1;
			$query_vars['offset']  = 0;
		}

		return $query_vars;
	}

	/**
	 * Supply Redis totals only to the exact marker-bearing WP_Query.
	 *
	 * @param int      $found_posts Native found-post count.
	 * @param WP_Query $query Query instance.
	 * @return int
	 */
	public function filter_found_posts( $found_posts, $query ) {
		$request_key = $query->get( self::QUERY_MARKER );
		if ( ! is_string( $request_key ) || '' === $request_key ) {
			return $found_posts;
		}

		$result = Shift64_Woo_Search_Product_Collection_Results::get( $request_key );
		if ( null === $result || Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS !== $result->get_status() ) {
			return $found_posts;
		}
		return $result->get_total();
	}
}
