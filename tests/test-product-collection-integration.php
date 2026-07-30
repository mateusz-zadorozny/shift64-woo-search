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
	 * Woo's inherited-query clone is bridged into the public query filter.
	 */
	public function test_scoped_context_bridge_preserves_inherited_eligibility() {
		$this->go_to( '/?s=series&post_type=product' );
		$adapter = new Shift64_Woo_Search_Product_Collection_Query();
		$context = array(
			'queryId' => 7,
			'query'   => array(
				'isProductCollectionBlock' => true,
				'inherit'                  => true,
				'postType'                 => 'product',
				'perPage'                  => 12,
			),
		);

		$scoped = $adapter->scope_inherited_query_context(
			$context,
			array( 'blockName' => 'woocommerce/product-template' )
		);

		$this->assertFalse( $scoped['query']['inherit'] );
		$this->assertTrue( $scoped['query'][ Shift64_Woo_Search_Product_Collection_Context::SCOPED_INHERIT_MARKER ] );

		$block          = $this->product_collection_block( false, 7, 'woocommerce/product-template' );
		$block->context = $scoped;
		$resolved       = Shift64_Woo_Search_Product_Collection_Context::from_block(
			array(
				'post_type'      => 'product',
				'posts_per_page' => 12,
				's'              => 'series',
			),
			$block,
			1,
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

		$this->assertInstanceOf( Shift64_Woo_Search_Product_Collection_Context::class, $resolved );
	}

	/**
	 * A real inherited Product Collection render reaches the scoped adapter.
	 */
	public function test_real_product_collection_render_reaches_adapter() {
		$this->go_to( '/?s=series&post_type=product' );
		$service = new class() extends Shift64_Woo_Search_Product_Collection_Query_Service {
			/**
			 * Number of query executions.
			 *
			 * @var int
			 */
			public $calls = 0;

			public function execute( Shift64_Woo_Search_Product_Collection_Context $context, Shift64_Woo_Search_Catalog_State $state ) {
				++$this->calls;
				return new Shift64_Woo_Search_Product_Collection_Result(
					$context->get_request_key(),
					array(),
					0,
					$state->get_page(),
					$context->get_per_page(),
					$state->get_sort(),
					array(),
					array(),
					Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS
				);
			}
		};

		$adapter = new Shift64_Woo_Search_Product_Collection_Query( $service );

		$registry = WP_Block_Type_Registry::get_instance();

		$registered_collection = false;
		$registered_template   = false;

		if ( ! $registry->is_registered( 'woocommerce/product-collection' ) ) {
			register_block_type(
				'woocommerce/product-collection',
				array(
					'attributes'       => array(
						'query'   => array( 'type' => 'object' ),
						'queryId' => array( 'type' => 'number' ),
					),
					'provides_context' => array(
						'query'   => 'query',
						'queryId' => 'queryId',
					),
				)
			);
			$registered_collection = true;
		}

		if ( ! $registry->is_registered( 'woocommerce/product-template' ) ) {
			register_block_type(
				'woocommerce/product-template',
				array(
					'uses_context'    => array( 'query', 'queryId' ),
					'render_callback' => static function ( $attributes, $content, $block ) {
						$query_vars = array(
							'post_type'      => 'product',
							'posts_per_page' => 12,
							's'              => 'series',
						);
						apply_filters( 'query_loop_block_query_vars', $query_vars, $block, 1 );
						return $content;
					},
				)
			);
			$registered_template = true;
		}

		$markup = '<!-- wp:woocommerce/product-collection {"queryId":99,"query":{"isProductCollectionBlock":true,"inherit":true,"postType":"product","perPage":12}} -->'
			. '<!-- wp:woocommerce/product-template --><!-- wp:post-title /--><!-- /wp:woocommerce/product-template -->'
			. '<!-- wp:woocommerce/product-collection-no-results --><p>No products.</p><!-- /wp:woocommerce/product-collection-no-results -->'
			. '<!-- /wp:woocommerce/product-collection -->';
		try {
			do_blocks( $markup );
			$this->assertSame( 1, $service->calls );
		} finally {
			remove_filter( 'render_block_context', array( $adapter, 'scope_inherited_query_context' ), 99 );
			remove_filter( 'query_loop_block_query_vars', array( $adapter, 'filter_query_vars' ), 99 );
			remove_filter( 'found_posts', array( $adapter, 'filter_found_posts' ), 99 );
			if ( $registered_template ) {
				unregister_block_type( 'woocommerce/product-template' );
			}
			if ( $registered_collection ) {
				unregister_block_type( 'woocommerce/product-collection' );
			}
		}
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
		$this->assertSame( array( 'category' => 'and' ), $state->get_redis_operators() );
	}

	/**
	 * Archive presentation sees Product Collection's query-ID page.
	 */
	public function test_requested_page_bridges_product_collection_state_to_archive() {
		$this->assertSame(
			2,
			Shift64_Woo_Search_Catalog_State::requested_page(
				1,
				array(
					's'            => 'series',
					'post_type'    => 'product',
					'query-0-page' => '2',
				),
				'/?s=series&post_type=product&query-0-page=2'
			)
		);
	}

	/**
	 * State changes reset all paging forms and drop private preview parameters.
	 */
	public function test_canonical_url_resets_paging_and_preserves_safe_parameters() {
		$url = Shift64_Woo_Search_Catalog_State::build_url(
			'https://example.test/shop/page/4/?query-7-page=4&query-99-page=2&paged=4&utm_source=test&_wpnonce=secret#products',
			array( 'orderby' => 'price' ),
			7
		);

		$this->assertSame(
			'https://example.test/shop/?orderby=price&utm_source=test#products',
			$url
		);
	}

	/**
	 * Disabled facet taxonomies cannot be smuggled into Redis state.
	 */
	public function test_catalog_state_drops_disabled_facets() {
		update_option( 'shift64_woo_search_filter_categories_enabled', 'no' );
		$term = $this->factory->term->create_and_get(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Skin Care',
				'slug'     => 'skin-care',
			)
		);
		$this->assertInstanceOf( WP_Term::class, $term );

		$context = new Shift64_Woo_Search_Product_Collection_Context( 7, 'pc-7-test', 1, 12, '', '', '', null );
		$state   = Shift64_Woo_Search_Catalog_State::from_request(
			$context,
			array( 'filter_product_cat' => 'skin-care' ),
			'/shop/'
		);

		$this->assertSame( array(), $state->get_selected_filters() );
		$this->assertSame( array(), $state->get_redis_filters() );
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
	 * Redis membership narrows an existing WooCommerce post constraint.
	 */
	public function test_query_adapter_intersects_existing_post_in_in_redis_order() {
		$result = new Shift64_Woo_Search_Product_Collection_Result(
			'pc-7-test',
			array( 11, 12, 13 ),
			3,
			1,
			12,
			'relevance',
			array(),
			array(),
			Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS
		);

		$adapted = Shift64_Woo_Search_Product_Collection_Query::apply_result(
			array( 'post__in' => array( 13, 11, 99 ) ),
			$result
		);

		$this->assertSame( array( 11, 13 ), $adapted['post__in'] );
	}

	/**
	 * Disjoint WooCommerce and Redis memberships remain impossible.
	 */
	public function test_query_adapter_keeps_disjoint_post_in_empty() {
		$result = new Shift64_Woo_Search_Product_Collection_Result(
			'pc-7-test',
			array( 11, 12 ),
			2,
			1,
			12,
			'relevance',
			array(),
			array(),
			Shift64_Woo_Search_Product_Collection_Result::STATUS_REDIS
		);

		$adapted = Shift64_Woo_Search_Product_Collection_Query::apply_result(
			array( 'post__in' => array( 99 ) ),
			$result
		);

		$this->assertSame( array( 0 ), $adapted['post__in'] );
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
		$this->assertTrue( $result['ok'] );
		$this->assertSame( '12', (string) $captured[ array_search( 'LIMIT', $captured, true ) + 1 ] );
		$this->assertSame( 'price', $captured[ array_search( 'SORTBY', $captured, true ) + 1 ] );
		$this->assertStringContainsString( '-@visibility:{hidden|catalog}', $captured[2] );
	}

	/**
	 * Canonical AND filters become repeated TAG clauses, while OR stays one clause.
	 */
	public function test_catalog_query_honors_per_filter_operators() {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$query = new Shift64_Woo_Search_Query( $redis );

		$and_parts = $query->build_filter_parts(
			array( 'category' => array( 'Skin Care', 'Body Care' ) ),
			null,
			array( 'category' => 'and' )
		);
		$or_parts  = $query->build_filter_parts(
			array( 'category' => array( 'Skin Care', 'Body Care' ) ),
			null,
			array( 'category' => 'or' )
		);

		$this->assertContains( '@categories:{Skin\\ Care}', $and_parts );
		$this->assertContains( '@categories:{Body\\ Care}', $and_parts );
		$this->assertContains( '@categories:{Skin\\ Care|Body\\ Care}', $or_parts );
	}

	/**
	 * A failed Redis command is not mistaken for a legitimate empty result.
	 */
	public function test_query_service_uses_native_fallback_when_redis_command_fails() {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )->willReturn( false );

		$service = new Shift64_Woo_Search_Product_Collection_Query_Service( new Shift64_Woo_Search_Query( $redis ) );
		$context = new Shift64_Woo_Search_Product_Collection_Context( 7, 'pc-7-test', 1, 12, 'series', '', '', 'search' );
		$state   = Shift64_Woo_Search_Catalog_State::from_request(
			$context,
			array(
				's'         => 'series',
				'post_type' => 'product',
			),
			'/'
		);
		$result  = $service->execute( $context, $state );

		$this->assertSame( Shift64_Woo_Search_Product_Collection_Result::STATUS_NATIVE_FALLBACK, $result->get_status() );
	}

	/**
	 * Sort modes not yet owned by Redis remain native WooCommerce queries.
	 */
	public function test_query_service_preserves_native_sort_modes() {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$query = new Shift64_Woo_Search_Query( $redis );

		$service = new Shift64_Woo_Search_Product_Collection_Query_Service( $query );
		$context = new Shift64_Woo_Search_Product_Collection_Context( 7, 'pc-7-test', 1, 12, '', '', '', null );
		$state   = Shift64_Woo_Search_Catalog_State::from_request(
			$context,
			array( 'orderby' => 'date' ),
			'/shop/'
		);
		$result  = $service->execute( $context, $state );

		$this->assertSame( Shift64_Woo_Search_Product_Collection_Result::STATUS_NATIVE_FALLBACK, $result->get_status() );
	}
}
