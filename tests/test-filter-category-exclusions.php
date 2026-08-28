<?php
/**
 * Tests for category exclusion in the Filter Pill option list.
 *
 * The exclusion used to be applied by the classic filter-bar renderer that the
 * block-theme-only cleanup deleted. "Excluded Categories" is facet
 * configuration, not appearance, so it stays in WP Admin — which means the block
 * path has to honour it. This is the coverage that keeps it honoured.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Category exclusion filtering tests.
 */
class Filter_Category_Exclusions_Test extends WP_UnitTestCase {

	/**
	 * Register product_cat fresh per test so factory terms attach to it.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy( 'product_cat', 'product', array( 'hierarchical' => true ) );
		}
	}

	/**
	 * Invoke the private exclusion resolver on the block filter renderer.
	 *
	 * @return int[]
	 */
	private function excluded_ids() {
		// No setAccessible() call: private methods have been reflection-invokable
		// without it since PHP 8.1, and PHP 8.5 deprecates the call outright. The
		// plugin's floor is 8.3, so the call would be pure deprecation noise.
		$method = new ReflectionMethod(
			Shift64_Woo_Search_Filter_Blocks::class,
			'excluded_category_ids'
		);

		$result = $method->invoke( null );
		sort( $result );

		return $result;
	}

	/**
	 * Plugin-level excluded category IDs merge with WooCommerce's default category.
	 */
	public function test_plugin_category_exclusions_are_merged() {
		update_option( 'default_product_cat', 12 );
		update_option( 'shift64_woo_search_filter_categories_excluded', array( 56, 78 ) );

		$this->assertSame( array( 12, 56, 78 ), $this->excluded_ids() );
	}

	/**
	 * A duplicate between the two sources is collapsed rather than repeated.
	 */
	public function test_duplicate_exclusions_are_collapsed() {
		update_option( 'default_product_cat', 12 );
		update_option( 'shift64_woo_search_filter_categories_excluded', array( 12, 34 ) );

		$this->assertSame( array( 12, 34 ), $this->excluded_ids() );
	}

	/**
	 * A store with no default category and no configured exclusions excludes nothing.
	 */
	public function test_no_configuration_excludes_nothing() {
		update_option( 'default_product_cat', 0 );
		update_option( 'shift64_woo_search_filter_categories_excluded', array() );

		$this->assertSame( array(), $this->excluded_ids() );
	}

	/**
	 * An excluded category is left out of the rendered pill options.
	 */
	public function test_excluded_category_is_absent_from_pill_options() {
		$kept    = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Kept Category',
				'slug'     => 'kept-category',
			)
		);
		$dropped = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Dropped Category',
				'slug'     => 'dropped-category',
			)
		);

		update_option( 'default_product_cat', 0 );
		update_option( 'shift64_woo_search_filter_categories_excluded', array( $dropped ) );

		$options = $this->pill_options_for( 'product_cat', array() );
		$slugs   = wp_list_pluck( $options, 'slug' );

		$this->assertContains( 'kept-category', $slugs );
		$this->assertNotContains( 'dropped-category', $slugs );
	}

	/**
	 * An excluded category the shopper already selected stays removable.
	 */
	public function test_selected_excluded_category_stays_visible() {
		$dropped = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Selected But Excluded',
				'slug'     => 'selected-but-excluded',
			)
		);
		$slug    = 'selected-but-excluded';

		update_option( 'default_product_cat', 0 );
		update_option( 'shift64_woo_search_filter_categories_excluded', array( $dropped ) );

		$options = $this->pill_options_for( 'product_cat', array( $slug ) );

		$this->assertContains( $slug, wp_list_pluck( $options, 'slug' ) );
	}

	/**
	 * Build the pill option list for a taxonomy with the given selection.
	 *
	 * @param string            $taxonomy Taxonomy name.
	 * @param array<int,string> $selected Selected term slugs.
	 * @return array<int,array<string,mixed>>
	 */
	private function pill_options_for( $taxonomy, $selected ) {
		$blocks = new Shift64_Woo_Search_Filter_Blocks();
		$method = new ReflectionMethod( Shift64_Woo_Search_Filter_Blocks::class, 'pill_options' );

		return $method->invoke(
			$blocks,
			array(
				'taxonomy'    => $taxonomy,
				'redis_field' => 'categories',
			),
			array(
				'hideEmpty'  => false,
				'orderBy'    => 'name-asc',
				'showCounts' => false,
			),
			$selected
		);
	}
}
