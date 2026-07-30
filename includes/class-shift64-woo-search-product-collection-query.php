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
	 * Register public query hooks.
	 *
	 * @param Shift64_Woo_Search_Product_Collection_Query_Service|null $service Query service.
	 */
	public function __construct( $service = null ) {
		$this->service = $service ?? new Shift64_Woo_Search_Product_Collection_Query_Service();
		add_filter( 'render_block_context', array( $this, 'scope_inherited_query_context' ), 99, 2 );
		add_filter( 'query_loop_block_query_vars', array( $this, 'filter_query_vars' ), 99, 3 );
		add_filter( 'found_posts', array( $this, 'filter_found_posts' ), 99, 2 );
	}

	/**
	 * Route inherited Product Collection query consumers through a scoped query.
	 *
	 * WooCommerce clones the already-executed main query for inherited
	 * collections. Changing only the child block context to non-inherited makes
	 * its public query builder run, where the late adapter can constrain Redis
	 * membership. The parsed block attributes and main query remain untouched.
	 *
	 * @param array $context      Available block context.
	 * @param array $parsed_block Parsed block.
	 * @return array
	 */
	public function scope_inherited_query_context( $context, $parsed_block ) {
		if (
			! is_array( $context )
			|| ! is_array( $parsed_block )
			|| 'yes' !== get_option( 'shift64_woo_search_archive_enabled', 'no' )
			|| ! Shift64_Woo_Search_Product_Collection_Context::is_current_archive_request()
		) {
			return $context;
		}

		$query = is_array( $context['query'] ?? null ) ? $context['query'] : array();
		if (
			true !== ( $query['isProductCollectionBlock'] ?? false )
			|| true !== ( $query['inherit'] ?? false )
			|| ! self::is_query_consumer( $parsed_block['blockName'] ?? '' )
		) {
			return $context;
		}

		$query[ Shift64_Woo_Search_Product_Collection_Context::SCOPED_INHERIT_MARKER ] = true;
		$query['inherit'] = false;
		$context['query'] = $query;
		return $context;
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

		$state  = Shift64_Woo_Search_Catalog_State::from_request( $context );
		$result = $this->service->execute( $context, $state );
		Shift64_Woo_Search_Product_Collection_Results::set( $result );

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
		if ( Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS !== $result->get_status() ) {
			return $query_vars;
		}

		$ids                              = $result->get_product_ids();
		$query_vars['post__in']           = empty( $ids ) ? array( 0 ) : $ids;
		$query_vars['orderby']            = 'post__in';
		$query_vars['paged']              = 1;
		$query_vars['offset']             = 0;
		$query_vars['s']                  = '';
		$query_vars[ self::QUERY_MARKER ] = $result->get_request_key();
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
