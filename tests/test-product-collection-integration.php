<?php
/**
 * Product Collection query and canonical state integration tests.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Product Collection integration contract tests.
 */
class Product_Collection_Integration_Test extends WP_UnitTestCase {

	/**
	 * Reset request-local state and enable archive queries.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'shift64_woo_search_archive_enabled', 'yes' );
		update_option( 'shift64_woo_search_filter_attributes', array() );
		Shift64_Woo_Search_Product_Collection_Results::reset();

		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy( 'product_cat', 'product' );
		}
	}

	/**
	 * Build an inherited Product Collection block instance.
	 *
	 * @param bool   $inherit Whether the query inherits archive context.
	 * @param int    $query_id Product Collection query ID.
	 * @param string $block_name Rendered host or Product Template block name.
	 * @return WP_Block
	 */
	private function product_collection_block( $inherit = true, $query_id = 7, $block_name = 'woocommerce/product-collection' ) {
		$parsed         = array(
			'blockName'   => $block_name,
			'attrs'       => array(),
			'innerBlocks' => array(),
		);
		$block          = new WP_Block( $parsed );
		$block->context = array(
			'queryId' => $query_id,
			'query'   => array(
				'isProductCollectionBlock' => true,
				'inherit'                  => $inherit,
				'perPage'                  => 12,
			),
		);
		return $block;
	}

	/**
	 * Eligible inherited Product Collections resolve to an immutable context.
	 */
	public function test_context_resolves_eligible_product_search() {
		$context = Shift64_Woo_Search_Product_Collection_Context::from_block(
			array(
				'post_type'      => 'product',
				'posts_per_page' => 16,
				's'              => 'series',
			),
			$this->product_collection_block(),
			2,
			array(
				'is_admin'          => false,
				'is_rest'           => false,
				'is_feed'           => false,
				'is_product_search' => true,
				'is_shop'           => false,
				'taxonomy'          => '',
				'taxonomy_enabled'  => false,
				'search_term'       => 'series',
			)
		);

		$this->assertInstanceOf( Shift64_Woo_Search_Product_Collection_Context::class, $context );
		$this->assertSame( 7, $context->get_query_id() );
		$this->assertSame( 2, $context->get_page() );
		$this->assertSame( 16, $context->get_per_page() );
		$this->assertSame( 'series', $context->get_search_term() );
		$this->assertSame( 'search', $context->get_visibility_policy() );
	}

	/**
	 * WooCommerce invokes the public query filter for Product Template children.
	 */
	public function test_context_accepts_product_template_filter_instance() {
		$context = Shift64_Woo_Search_Product_Collection_Context::from_block(
			array(
				'post_type'      => 'product',
				'posts_per_page' => 12,
			),
			$this->product_collection_block( true, 7, 'woocommerce/product-template' ),
			1,
			array(
				'is_admin'          => false,
				'is_rest'           => false,
				'is_feed'           => false,
				'is_product_search' => false,
				'is_shop'           => true,
				'taxonomy'          => '',
				'taxonomy_enabled'  => false,
				'search_term'       => '',
			)
		);

		$this->assertInstanceOf( Shift64_Woo_Search_Product_Collection_Context::class, $context );
	}

	/**
	 * Standalone or REST Product Collections keep native WooCommerce queries.
	 */
	public function test_context_rejects_ineligible_collection() {
		$facts = array(
			'is_admin'          => false,
			'is_rest'           => false,
			'is_feed'           => false,
			'is_product_search' => true,
			'is_shop'           => false,
			'taxonomy'          => '',
			'taxonomy_enabled'  => false,
			'search_term'       => 'series',
		);

		$this->assertNull(
			Shift64_Woo_Search_Product_Collection_Context::from_block(
				array( 'post_type' => 'product' ),
				$this->product_collection_block( false ),
				1,
				$facts
			)
		);

		$facts['is_rest'] = true;
		$this->assertNull(
			Shift64_Woo_Search_Product_Collection_Context::from_block(
				array( 'post_type' => 'product' ),
				$this->product_collection_block(),
				1,
				$facts
			)
		);
	}

	/**
	 * Query-ID paging wins and invalid URL values are discarded.
	 */
	public function test_catalog_state_normalizes_paging_filters_and_sort() {
		$term = $this->factory->term->create_and_get(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Skin Care',
				'slug'     => 'skin-care',
			)
		);
		$this->assertInstanceOf( WP_Term::class, $term );

		$context = new Shift64_Woo_Search_Product_Collection_Context( 7, 'pc-7-test', 1, 12, 'series', '', '', 'search' );
		$state   = Shift64_Woo_Search_Catalog_State::from_request(
			$context,
			array(
				'query-7-page'           => '3',
				'query-page'             => '9',
				'orderby'                => 'not-valid',
				's'                      => 'series',
				'post_type'              => 'product',
				'filter_product_cat'     => 'unknown,skin-care,skin-care',
				'query_type_product_cat' => 'and',
			),
			'/shop/page/5/'
		);

		$this->assertSame( 3, $state->get_page() );
		$this->assertSame( 'relevance', $state->get_sort() );
		$this->assertSame( 'series', $state->get_search() );
		$this->assertSame( array( 'filter_product_cat' => array( 'skin-care' ) ), $state->get_selected_filters() );
		$this->assertSame( array( 'category' => array( 'Skin Care' ) ), $state->get_redis_filters() );
		$this->assertSame( array( 'query_type_product_cat' => 'and' ), $state->get_operators() );
	}

	/**
	 * State changes reset all paging forms and drop private preview parameters.
	 */
	public function test_canonical_url_resets_paging_and_preserves_safe_parameters() {
		$url = Shift64_Woo_Search_Catalog_State::build_url(
			'https://example.test/shop/page/4/?query-7-page=4&paged=4&utm_source=test&_wpnonce=secret',
			array( 'orderby' => 'price' ),
			7
		);

		$this->assertSame(
			'https://example.test/shop/?orderby=price&utm_source=test',
			$url
		);
	}

	/**
	 * Empty results use an impossible post__in and keep unrelated vars.
	 */
	public function test_query_adapter_applies_empty_result_safely() {
		$result  = new Shift64_Woo_Search_Product_Collection_Result(
			'pc-7-test',
			array(),
			0,
			1,
			12,
			'relevance',
			array(),
			array(),
			Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS
		);
		$adapted = Shift64_Woo_Search_Product_Collection_Query::apply_result(
			array(
				'post_type' => 'product',
				'author'    => 23,
			),
			$result
		);

		$this->assertSame( array( 0 ), $adapted['post__in'] );
		$this->assertSame( 'post__in', $adapted['orderby'] );
		$this->assertSame( 1, $adapted['paged'] );
		$this->assertSame( 0, $adapted['offset'] );
		$this->assertSame( '', $adapted['s'] );
		$this->assertSame( 23, $adapted['author'] );
		$this->assertSame( 'pc-7-test', $adapted[ Shift64_Woo_Search_Product_Collection_Query::QUERY_MARKER ] );
	}

	/**
	 * Native fallback returns WooCommerce's query byte-for-byte.
	 */
	public function test_query_adapter_does_not_mutate_native_fallback() {
		$result = new Shift64_Woo_Search_Product_Collection_Result(
			'pc-7-test',
			array(),
			0,
			1,
			12,
			'relevance',
			array(),
			array(),
			Shift64_Woo_Search_Product_Collection_Result::STATUS_NATIVE_FALLBACK
		);
		$query  = array(
			'post_type' => 'product',
			'orderby'   => 'date',
			'paged'     => 4,
		);

		$this->assertSame( $query, Shift64_Woo_Search_Product_Collection_Query::apply_result( $query, $result ) );
	}

	/**
	 * Found-post totals are isolated by the private request marker.
	 */
	public function test_found_posts_override_is_scoped_to_request_key() {
		$result = new Shift64_Woo_Search_Product_Collection_Result(
			'pc-7-test',
			array( 11, 12 ),
			48,
			1,
			2,
			'relevance',
			array(),
			array(),
			Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS
		);
		Shift64_Woo_Search_Product_Collection_Results::set( $result );

		$adapter = new Shift64_Woo_Search_Product_Collection_Query();
		$marked  = new WP_Query();
		$marked->set( Shift64_Woo_Search_Product_Collection_Query::QUERY_MARKER, 'pc-7-test' );
		$other = new WP_Query();

		$this->assertSame( 48, $adapter->filter_found_posts( 2, $marked ) );
		$this->assertSame( 2, $adapter->filter_found_posts( 2, $other ) );
	}

	/**
	 * Registry keys isolate multiple Product Collections and fallback states.
	 */
	public function test_result_registry_isolates_query_ids() {
		$first  = new Shift64_Woo_Search_Product_Collection_Result(
			'pc-1-a',
			array( 1 ),
			1,
			1,
			12,
			'relevance',
			array(),
			array(),
			Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS
		);
		$second = new Shift64_Woo_Search_Product_Collection_Result(
			'pc-2-b',
			array(),
			0,
			1,
			12,
			'relevance',
			array(),
			array(),
			Shift64_Woo_Search_Product_Collection_Result::STATUS_NATIVE_FALLBACK
		);

		Shift64_Woo_Search_Product_Collection_Results::set( $first );
		Shift64_Woo_Search_Product_Collection_Results::set( $second );

		$this->assertSame( array( 1 ), Shift64_Woo_Search_Product_Collection_Results::get( 'pc-1-a' )->get_product_ids() );
		$this->assertSame(
			Shift64_Woo_Search_Product_Collection_Result::STATUS_NATIVE_FALLBACK,
			Shift64_Woo_Search_Product_Collection_Results::get( 'pc-2-b' )->get_status()
		);
	}

	/**
	 * Text catalog query returns Redis membership, total, paging, and sort.
	 */
	public function test_search_catalog_returns_ids_total_and_requested_page() {
		$captured = null;
		$redis    = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )
			->willReturnCallback(
				function () use ( &$captured ) {
					$args = func_get_args();
					if ( 'FT.SEARCH' !== ( $args[0] ?? '' ) ) {
						return false;
					}
					$captured = $args;
					return array(
						25,
						'shift64_woo_search:product:13',
						array( 'post_id', '13' ),
					);
				}
			);

		$query  = new Shift64_Woo_Search_Query( $redis );
		$result = $query->search_catalog( 'series', array(), 12, 2, 'price DESC', 'search' );

		$this->assertSame( array( 13 ), $result['ids'] );
		$this->assertSame( 25, $result['total'] );
		$this->assertSame( '12', (string) $captured[ array_search( 'LIMIT', $captured, true ) + 1 ] );
		$this->assertSame( 'price', $captured[ array_search( 'SORTBY', $captured, true ) + 1 ] );
		$this->assertStringContainsString( '-@visibility:{hidden|catalog}', $captured[2] );
	}
}
