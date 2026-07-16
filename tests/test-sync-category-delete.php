<?php
/**
 * Tests for Shift64_Woo_Search_Sync category-deletion handling.
 *
 * Covers the pre/post-delete hook coordination that keeps Redis index entries
 * in sync when a product category is removed:
 *
 *   1. `on_pre_category_delete()` snapshots descendant term IDs while the
 *      term hierarchy is still intact (descendants are reparented to 0 once
 *      `wp_delete_term()` finishes).
 *   2. `on_category_delete()` consumes that snapshot and fires reindex.
 *
 * Only the snapshot/buffer-management logic is exercised here. The actual
 * reindex round-trip (Indexer + Redis HSET) is covered indirectly by the
 * existing indexer tests and is not reachable in this bootstrap because
 * WooCommerce isn't installed and the Redis singleton short-circuits.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Buffer-state tests for category-deletion sync hooks.
 *
 * Note: `wc_get_products()` is stubbed in `tests/bootstrap.php` to return an
 * empty array, since WooCommerce isn't loaded in the test environment.
 */
class Sync_Category_Delete_Test extends WP_UnitTestCase {

	/**
	 * Register product_cat (WooCommerce isn't loaded in this bootstrap) and
	 * clear the static descendants buffer between tests.
	 */
	public function set_up() {
		parent::set_up();
		register_taxonomy( 'product_cat', 'product', array( 'hierarchical' => true ) );
		$this->reset_descendants_buffer();
	}

	/**
	 * Tear down: clear the buffer so a leaking test doesn't poison the next one.
	 */
	public function tear_down() {
		$this->reset_descendants_buffer();
		parent::tear_down();
	}

	/**
	 * Read the private static $deleted_category_descendants via reflection.
	 *
	 * @return array<int, int[]>
	 */
	private function get_descendants_buffer() {
		$reflection = new ReflectionClass( 'Shift64_Woo_Search_Sync' );
		$property   = $reflection->getProperty( 'deleted_category_descendants' );
		$property->setAccessible( true );
		return $property->getValue();
	}

	/**
	 * Reset the private static $deleted_category_descendants via reflection.
	 */
	private function reset_descendants_buffer() {
		$reflection = new ReflectionClass( 'Shift64_Woo_Search_Sync' );
		$property   = $reflection->getProperty( 'deleted_category_descendants' );
		$property->setAccessible( true );
		$property->setValue( null, array() );
	}

	/**
	 * Seed the buffer directly to test post-delete behavior in isolation.
	 *
	 * @param array<int, int[]> $value Buffer contents to set.
	 */
	private function seed_descendants_buffer( $value ) {
		$reflection = new ReflectionClass( 'Shift64_Woo_Search_Sync' );
		$property   = $reflection->getProperty( 'deleted_category_descendants' );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	/**
	 * The pre-delete hook fires for every taxonomy. We only care about product_cat —
	 * a deletion of, say, a regular post category must not pollute the buffer.
	 */
	public function test_pre_delete_ignores_non_product_cat_taxonomy() {
		$term = $this->factory->term->create( array( 'taxonomy' => 'category' ) );

		( new Shift64_Woo_Search_Sync() )->on_pre_category_delete( $term, 'category' );

		$this->assertEmpty( $this->get_descendants_buffer() );
	}

	/**
	 * A leaf category (no descendants) shouldn't allocate a buffer entry — only
	 * categories with children require post-delete reindex of those children.
	 */
	public function test_pre_delete_with_no_children_stores_nothing() {
		$term = $this->factory->term->create( array( 'taxonomy' => 'product_cat' ) );

		( new Shift64_Woo_Search_Sync() )->on_pre_category_delete( $term, 'product_cat' );

		$this->assertEmpty( $this->get_descendants_buffer() );
	}

	/**
	 * Descendant capture must be recursive (children + grandchildren + ...). Products
	 * in deep descendants have ancestor IDs in their TAG field that reference the
	 * deleted parent, so all of them need reindexing.
	 */
	public function test_pre_delete_captures_recursive_descendants_for_product_cat() {
		$parent     = $this->factory->term->create( array( 'taxonomy' => 'product_cat' ) );
		$child      = $this->factory->term->create(
			array(
				'taxonomy' => 'product_cat',
				'parent'   => $parent,
			)
		);
		$grandchild = $this->factory->term->create(
			array(
				'taxonomy' => 'product_cat',
				'parent'   => $child,
			)
		);

		( new Shift64_Woo_Search_Sync() )->on_pre_category_delete( $parent, 'product_cat' );

		$buffer = $this->get_descendants_buffer();
		$this->assertArrayHasKey( $parent, $buffer );
		$this->assertContains( $child, $buffer[ $parent ] );
		$this->assertContains( $grandchild, $buffer[ $parent ], 'get_term_children() must descend recursively, not just direct children.' );
	}

	/**
	 * The buffer entry for $term_id must be unset() *before* the descendants
	 * are processed — this guards against stale data leaking into a later
	 * deletion of a different category in the same request.
	 */
	public function test_post_delete_clears_descendants_buffer_even_when_redis_unavailable() {
		$this->seed_descendants_buffer( array( 99 => array( 100, 101 ) ) );

		( new Shift64_Woo_Search_Sync() )->on_category_delete( 99, 0, null, array() );

		$buffer = $this->get_descendants_buffer();
		$this->assertArrayNotHasKey( 99, $buffer );
	}

	/**
	 * Multiple categories deleted in the same request must not cross-contaminate
	 * each other's descendant snapshots.
	 */
	public function test_post_delete_only_clears_its_own_buffer_entry() {
		$this->seed_descendants_buffer(
			array(
				99  => array( 100, 101 ),
				200 => array( 201, 202 ),
			)
		);

		( new Shift64_Woo_Search_Sync() )->on_category_delete( 99, 0, null, array() );

		$buffer = $this->get_descendants_buffer();
		$this->assertArrayNotHasKey( 99, $buffer );
		$this->assertArrayHasKey( 200, $buffer );
		$this->assertSame( array( 201, 202 ), $buffer[200] );
	}
}
