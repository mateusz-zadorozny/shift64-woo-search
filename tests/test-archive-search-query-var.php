<?php
/**
 * Tests for the search term surviving an intercepted product search.
 *
 * The archive blanks the main query's `s` so WordPress skips its MySQL LIKE
 * search. That blank used to outlive the query and reach the render pass, where
 * WooCommerce's breadcrumb and theme search headings read the term back through
 * get_search_query() — producing `Search results for “”`. The term is now put
 * back once the posts are in.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Search query var restoration tests.
 */
class Archive_Search_Query_Var_Test extends WP_UnitTestCase {

	/**
	 * Whether this test replaced the Redis singleton.
	 *
	 * @var bool
	 */
	private $stubbed_redis = false;

	/**
	 * Drop the Redis stub so it cannot leak into the next test.
	 */
	public function tear_down() {
		if ( $this->stubbed_redis ) {
			$instance = new ReflectionProperty( Shift64_Woo_Search_Redis::class, 'instance' );
			$instance->setValue( null, null );
			$this->stubbed_redis = false;
		}

		parent::tear_down();
	}

	/**
	 * Put a Redis stub behind the singleton so `intercept()` gets past its
	 * availability gate. It answers one hit, which is what makes the archive
	 * inject its results instead of falling back to MySQL — the fallback path
	 * never touches the search term, so it would not exercise this behavior.
	 *
	 * @return void
	 */
	private function with_stubbed_redis() {
		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'is_available' )->willReturn( true );
		$redis->method( 'get_prefix' )->willReturn( 'shift64_woo_search' );
		$redis->method( 'raw_command' )->willReturnCallback(
			function ( $command ) {
				// No stored synonyms — an empty reply short-circuits the loader.
				if ( 'GET' === strtoupper( (string) $command ) ) {
					return '';
				}

				// FT.SEARCH: one hit, in the [total, key, score, fields] shape
				// the relevance reader expects.
				return array(
					1,
					'shift64_woo_search:product:123',
					'1',
					array( 'post_id', '123', 'title', 'Walnut table', 'stock_status', 'instock' ),
				);
			}
		);

		$instance = new ReflectionProperty( Shift64_Woo_Search_Redis::class, 'instance' );
		$instance->setValue( null, $redis );

		$this->stubbed_redis = true;
	}

	/**
	 * A main product-search query shaped the way `should_intercept()` requires.
	 *
	 * @param string $term Search term.
	 * @return WP_Query Query the archive will accept.
	 */
	private function product_search_query( $term ) {
		$query = new WP_Query();
		$query->init();
		$query->set( 's', $term );
		$query->set( 'post_type', 'product' );
		$query->is_search     = true;
		$query->is_main_query = true;

		// `is_main_query()` compares against the global, not the flag.
		$GLOBALS['wp_the_query'] = $query;

		return $query;
	}

	/**
	 * Build an archive instance without registering its hooks.
	 *
	 * @return Shift64_Woo_Search_Archive Instance under test.
	 */
	private function archive() {
		return ( new ReflectionClass( Shift64_Woo_Search_Archive::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * The MySQL LIKE search still has to be disarmed while the query runs —
	 * restoring the term must not undo that.
	 */
	public function test_search_term_is_cleared_while_the_query_runs() {
		update_option( 'shift64_woo_search_archive_enabled', 'yes' );
		$this->with_stubbed_redis();

		$query = $this->product_search_query( 'walnut' );
		$this->archive()->intercept( $query );

		$this->assertSame( '', $query->get( 's' ), 'the SQL must be built without a search term' );
	}

	/**
	 * Once the posts are back, the term returns — this is what the breadcrumb and
	 * the page heading read.
	 */
	public function test_search_term_returns_once_the_posts_are_in() {
		update_option( 'shift64_woo_search_archive_enabled', 'yes' );
		$this->with_stubbed_redis();

		$archive = $this->archive();
		$query   = $this->product_search_query( 'walnut' );
		$archive->intercept( $query );

		$archive->restore_search_query_var( array(), $query );

		$this->assertSame( 'walnut', $query->get( 's' ) );
	}

	/**
	 * Product Collection's query-ID page is restored for archive renderers.
	 */
	public function test_product_collection_page_returns_once_the_posts_are_in() {
		update_option( 'shift64_woo_search_archive_enabled', 'yes' );
		$this->with_stubbed_redis();
		$_GET['query-0-page'] = '2';

		try {
			$archive = $this->archive();
			$query   = $this->product_search_query( 'walnut' );
			$query->set( 'paged', 1 );
			$archive->intercept( $query );

			$this->assertSame( 1, $query->get( 'paged' ), 'MySQL must not apply a second page offset' );
			$archive->restore_paged( array(), $query );

			$this->assertSame( 2, $query->get( 'paged' ), 'WooCommerce breadcrumbs must see the Product Collection page' );
		} finally {
			unset( $_GET['query-0-page'] );
		}
	}

	/**
	 * The restoration is scoped to the main query: a secondary query running in
	 * the same request keeps whatever term it was given.
	 */
	public function test_secondary_queries_are_left_alone() {
		update_option( 'shift64_woo_search_archive_enabled', 'yes' );
		$this->with_stubbed_redis();

		$archive = $this->archive();
		$archive->intercept( $this->product_search_query( 'walnut' ) );

		$secondary = new WP_Query();
		$secondary->init();
		$secondary->set( 's', 'oak' );

		$archive->restore_search_query_var( array(), $secondary );

		$this->assertSame( 'oak', $secondary->get( 's' ) );
	}

	/**
	 * A request the archive never intercepted has no term to restore, so the
	 * filter must not write an empty string over a live search.
	 */
	public function test_nothing_is_restored_when_no_query_was_intercepted() {
		$archive = $this->archive();
		$query   = $this->product_search_query( 'walnut' );

		$archive->restore_search_query_var( array(), $query );

		$this->assertSame( 'walnut', $query->get( 's' ) );
	}
}
