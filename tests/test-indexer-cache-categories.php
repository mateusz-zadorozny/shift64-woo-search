<?php
/**
 * Tests for Shift64_Woo_Search_Indexer::cache_categories_to_redis() empty-state handling.
 *
 * The cache must always end up consistent with the live taxonomy:
 *
 *   - If `get_terms()` returns an empty array (e.g. the last category was just
 *     deleted), we write `[]` so the SHORTINIT endpoint stops serving stale
 *     entries.
 *   - If `get_terms()` returns a `WP_Error` (e.g. taxonomy not registered yet
 *     during early bootstrap), we leave the existing cache untouched — a
 *     transient query failure must not clobber valid data.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Empty-state and error-handling tests for cache_categories_to_redis.
 */
class IndexerCacheCategoriesTest extends WP_UnitTestCase {

	/**
	 * Ensure each test starts with a known taxonomy state.
	 */
	public function set_up() {
		parent::set_up();
		if ( taxonomy_exists( 'product_cat' ) ) {
			unregister_taxonomy( 'product_cat' );
		}
	}

	/**
	 * Build a Shift64_Woo_Search_Redis mock that exposes only the methods the cache
	 * function calls.
	 *
	 * @return PHPUnit\Framework\MockObject\MockObject
	 */
	private function build_redis_mock() {
		return $this->getMockBuilder( 'Shift64_Woo_Search_Redis' )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_prefix', 'raw_command' ) )
			->getMock();
	}

	/**
	 * Deleting the last product category clears the Redis cache entry — we
	 * write an empty JSON array instead of leaving stale data behind.
	 */
	public function test_writes_empty_array_when_no_categories_exist() {
		register_taxonomy( 'product_cat', 'product', array( 'hierarchical' => true ) );

		$redis = $this->build_redis_mock();
		$redis->method( 'get_prefix' )->willReturn( 'test_prefix' );
		$redis->expects( $this->once() )
			->method( 'raw_command' )
			->with( 'SET', 'test_prefix:categories', '[]' );

		$count = Shift64_Woo_Search_Indexer::cache_categories_to_redis( $redis );

		$this->assertSame( 0, $count );
	}

	/**
	 * A `WP_Error` from `get_terms()` (taxonomy not registered) must leave
	 * the existing cache untouched — no `SET` call.
	 */
	public function test_does_not_overwrite_cache_on_query_error() {
		// product_cat intentionally not registered → get_terms() returns WP_Error.

		$redis = $this->build_redis_mock();
		$redis->expects( $this->never() )->method( 'raw_command' );

		$count = Shift64_Woo_Search_Indexer::cache_categories_to_redis( $redis );

		$this->assertSame( 0, $count );
	}

	/**
	 * Create a non-empty product_cat term so `get_terms( hide_empty => true )`
	 * surfaces it: a published `product` post assigned to the term bumps its
	 * count via `wp_set_object_terms()`.
	 *
	 * @param string $name Category display name.
	 * @return int Created term ID.
	 */
	private function create_non_empty_category( $name ) {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => $name,
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $post_id, array( $term_id ), 'product_cat' );
		return $term_id;
	}

	/**
	 * A category whose term_id is in shift64_woo_search_category_suggest_exclude must
	 * be omitted from the {prefix}:categories blob, while its siblings remain.
	 * Product search results are unaffected — this only hides the suggestion row.
	 */
	public function test_excluded_category_is_omitted_from_blob() {
		register_taxonomy( 'product_cat', 'product', array( 'hierarchical' => true ) );

		$keep_id = $this->create_non_empty_category( 'Meble' );
		$hide_id = $this->create_non_empty_category( 'Krzesła' );

		update_option( 'shift64_woo_search_category_suggest_exclude', array( $hide_id ) );

		$captured = '';
		$redis    = $this->build_redis_mock();
		$redis->method( 'get_prefix' )->willReturn( 'test_prefix' );
		$redis->method( 'raw_command' )->willReturnCallback(
			function ( $cmd, $key, $payload ) use ( &$captured ) {
				$captured = $payload;
			}
		);

		$count = Shift64_Woo_Search_Indexer::cache_categories_to_redis( $redis );

		$names = wp_list_pluck( json_decode( $captured, true ), 'name' );
		$this->assertContains( 'Meble', $names );
		$this->assertNotContains( 'Krzesła', $names );
		$this->assertSame( 1, $count );
	}
}
