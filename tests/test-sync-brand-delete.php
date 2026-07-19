<?php
/**
 * Tests for Shift64_Woo_Search_Sync brand-deletion handling.
 *
 * Covers the pre/post-delete hook coordination that keeps Redis index entries
 * in sync when a product brand is removed — the same mechanism categories use:
 *
 *   1. `on_pre_brand_delete()` snapshots descendant term IDs while the term
 *      hierarchy is still intact (descendants are reparented to 0 once
 *      `wp_delete_term()` finishes).
 *   2. `on_brand_delete()` consumes that snapshot and fires reindex for both
 *      the directly-attached products (`$object_ids`) and descendant products.
 *
 * Only the snapshot/buffer-management logic is exercised here. The actual
 * reindex round-trip (Indexer + Redis HSET) is covered indirectly by the
 * existing indexer tests and is not reachable in this bootstrap because
 * WooCommerce isn't installed and the Redis singleton short-circuits.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Buffer-state tests for brand-deletion sync hooks.
 */
class Sync_Brand_Delete_Test extends WP_UnitTestCase {

	/**
	 * Register product_brand (WooCommerce isn't loaded in this bootstrap) and
	 * clear the static descendants buffer between tests.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			register_taxonomy( 'product_brand', 'product', array( 'hierarchical' => true ) );
		}
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
	 * Read the private static $deleted_brand_descendants via reflection.
	 *
	 * @return array<int, int[]>
	 */
	private function get_descendants_buffer() {
		$reflection = new ReflectionClass( 'Shift64_Woo_Search_Sync' );
		$property   = $reflection->getProperty( 'deleted_brand_descendants' );
		return $property->getValue();
	}

	/**
	 * Reset the private static $deleted_brand_descendants via reflection.
	 */
	private function reset_descendants_buffer() {
		$reflection = new ReflectionClass( 'Shift64_Woo_Search_Sync' );
		$property   = $reflection->getProperty( 'deleted_brand_descendants' );
		$property->setValue( null, array() );
	}

	/**
	 * Seed the buffer directly to test post-delete behavior in isolation.
	 *
	 * @param array<int, int[]> $value Buffer contents to set.
	 */
	private function seed_descendants_buffer( $value ) {
		$reflection = new ReflectionClass( 'Shift64_Woo_Search_Sync' );
		$property   = $reflection->getProperty( 'deleted_brand_descendants' );
		$property->setValue( null, $value );
	}

	/**
	 * The pre-delete hook fires for every taxonomy. We only care about
	 * product_brand — a deletion of, say, a product category must land in the
	 * category buffer, not this one.
	 */
	public function test_pre_delete_ignores_non_product_brand_taxonomy() {
		$term = $this->factory->term->create( array( 'taxonomy' => 'category' ) );

		( new Shift64_Woo_Search_Sync() )->on_pre_brand_delete( $term, 'category' );

		$this->assertEmpty( $this->get_descendants_buffer() );
	}

	/**
	 * A leaf brand (no descendants) shouldn't allocate a buffer entry — only
	 * brands with children require post-delete reindex of those children.
	 */
	public function test_pre_delete_with_no_children_stores_nothing() {
		$term = $this->factory->term->create( array( 'taxonomy' => 'product_brand' ) );

		( new Shift64_Woo_Search_Sync() )->on_pre_brand_delete( $term, 'product_brand' );

		$this->assertEmpty( $this->get_descendants_buffer() );
	}

	/**
	 * Descendant capture must be recursive (children + grandchildren + ...).
	 * Products in deep descendants carry the deleted ancestor's name in their
	 * brands TAG value, so all of them need reindexing.
	 */
	public function test_pre_delete_captures_recursive_descendants_for_product_brand() {
		$parent     = $this->factory->term->create( array( 'taxonomy' => 'product_brand' ) );
		$child      = $this->factory->term->create(
			array(
				'taxonomy' => 'product_brand',
				'parent'   => $parent,
			)
		);
		$grandchild = $this->factory->term->create(
			array(
				'taxonomy' => 'product_brand',
				'parent'   => $child,
			)
		);

		( new Shift64_Woo_Search_Sync() )->on_pre_brand_delete( $parent, 'product_brand' );

		$buffer = $this->get_descendants_buffer();
		$this->assertArrayHasKey( $parent, $buffer );
		$this->assertContains( $child, $buffer[ $parent ] );
		$this->assertContains( $grandchild, $buffer[ $parent ], 'get_term_children() must descend recursively, not just direct children.' );
	}

	/**
	 * The buffer entry for $term_id must be unset() *before* the descendants
	 * are processed — this guards against stale data leaking into a later
	 * deletion of a different brand in the same request.
	 */
	public function test_post_delete_clears_descendants_buffer_even_when_redis_unavailable() {
		$this->seed_descendants_buffer( array( 99 => array( 100, 101 ) ) );

		( new Shift64_Woo_Search_Sync() )->on_brand_delete( 99, 0, null, array() );

		$buffer = $this->get_descendants_buffer();
		$this->assertArrayNotHasKey( 99, $buffer );
	}

	/**
	 * Multiple brands deleted in the same request must not cross-contaminate
	 * each other's descendant snapshots.
	 */
	public function test_post_delete_only_clears_its_own_buffer_entry() {
		$this->seed_descendants_buffer(
			array(
				99  => array( 100, 101 ),
				200 => array( 201, 202 ),
			)
		);

		( new Shift64_Woo_Search_Sync() )->on_brand_delete( 99, 0, null, array() );

		$buffer = $this->get_descendants_buffer();
		$this->assertArrayNotHasKey( 99, $buffer );
		$this->assertArrayHasKey( 200, $buffer );
		$this->assertSame( array( 201, 202 ), $buffer[200] );
	}

	/**
	 * The delete hook must be registered with the 4-arg signature — dropping to
	 * two args silently discards `$object_ids`, the list of products that were
	 * directly attached to the deleted brand, and those products then keep the
	 * deleted brand searchable and filterable until the next full rebuild.
	 */
	public function test_delete_hook_registered_with_object_ids() {
		$sync = new Shift64_Woo_Search_Sync();

		$this->assertNotFalse( has_action( 'delete_product_brand', array( $sync, 'on_brand_delete' ) ) );
		$this->assertNotFalse( has_action( 'pre_delete_term', array( $sync, 'on_pre_brand_delete' ) ) );

		global $wp_filter;
		$registration = $wp_filter['delete_product_brand']->callbacks[10];
		$found        = false;
		foreach ( $registration as $callback ) {
			if ( is_array( $callback['function'] ) && 'on_brand_delete' === $callback['function'][1] ) {
				$found = true;
				$this->assertSame( 4, $callback['accepted_args'], 'on_brand_delete must accept 4 args to receive $object_ids.' );
			}
		}
		$this->assertTrue( $found );
	}
}
