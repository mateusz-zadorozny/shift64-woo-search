<?php
/**
 * Tests for brand extraction in Shift64_Woo_Search_Indexer::build_product_data()
 * and for the brands_text schema field.
 *
 * The ordering assertion matters beyond tidiness: the autocomplete row label
 * renders the FIRST segment of the `brands` TAG value, so a directly-assigned
 * brand must always precede any inherited ancestor.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Brand indexing tests.
 */
class Brands_Indexing_Test extends WP_UnitTestCase {

	/**
	 * Register product_brand the way WooCommerce 9.4+ does.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			register_taxonomy( 'product_brand', 'product', array( 'hierarchical' => true ) );
		}
	}

	/**
	 * Collect the indexed brand names for a product with the given terms.
	 *
	 * Exercises Shift64_Woo_Search_Indexer::collect_brand_names() rather than
	 * build_product_data(), because the latter needs a full WC_Product and
	 * WooCommerce is not loaded in this suite. The seam holds all the brand
	 * logic; build_product_data() only joins its output into the hash fields.
	 *
	 * @param array  $term_ids Term IDs to assign.
	 * @param string $taxonomy Taxonomy to assign them in.
	 * @return array<int,string> Ordered brand names.
	 */
	private function brands_for( $term_ids, $taxonomy = 'product_brand' ) {
		$product_id = $this->factory->post->create( array( 'post_type' => 'product' ) );
		wp_set_object_terms( $product_id, $term_ids, $taxonomy );

		return Shift64_Woo_Search_Indexer::collect_brand_names( $product_id );
	}

	/**
	 * A flat brand assignment produces the brand name in both the TAG and the
	 * TEXT field.
	 */
	public function test_flat_brand_is_indexed_in_tag_and_text() {
		$brand = $this->factory->term->create(
			array(
				'taxonomy' => 'product_brand',
				'name'     => 'Helios Supply',
			)
		);

		$brands = $this->brands_for( array( $brand ) );

		// build_product_data() joins with '|' for the TAG field and ' ' for TEXT.
		$this->assertSame( 'Helios Supply', implode( '|', $brands ) );
		$this->assertSame( 'Helios Supply', implode( ' ', $brands ) );
	}

	/**
	 * A product on a sub-brand also carries the parent, so a parent-brand filter
	 * matches it — and the directly-assigned brand comes first.
	 */
	public function test_hierarchical_brand_indexes_ancestors_after_assigned_terms() {
		$parent = $this->factory->term->create(
			array(
				'taxonomy' => 'product_brand',
				'name'     => 'Aeon Atelier',
			)
		);
		$child  = $this->factory->term->create(
			array(
				'taxonomy' => 'product_brand',
				'name'     => 'Aeon Reserve',
				'parent'   => $parent,
			)
		);

		$brands = $this->brands_for( array( $child ) );

		$this->assertSame( 'Aeon Reserve|Aeon Atelier', implode( '|', $brands ) );
	}

	/**
	 * With several brands assigned, every directly-assigned brand precedes every
	 * inherited ancestor — not just the first one's.
	 */
	public function test_multi_brand_places_all_assigned_terms_before_ancestors() {
		$parent = $this->factory->term->create(
			array(
				'taxonomy' => 'product_brand',
				'name'     => 'Aeon Atelier',
			)
		);
		$child  = $this->factory->term->create(
			array(
				'taxonomy' => 'product_brand',
				'name'     => 'Aeon Reserve',
				'parent'   => $parent,
			)
		);
		$flat   = $this->factory->term->create(
			array(
				'taxonomy' => 'product_brand',
				'name'     => 'Nyx Workshop',
			)
		);

		$values = $this->brands_for( array( $child, $flat ) );

		$this->assertContains( 'Aeon Reserve', $values );
		$this->assertContains( 'Nyx Workshop', $values );
		$this->assertSame( 'Aeon Atelier', end( $values ), 'The inherited ancestor must sort last.' );
	}

	/**
	 * A brandless product yields empty strings rather than a stray separator.
	 */
	public function test_brandless_product_yields_empty_fields() {
		$brands = $this->brands_for( array() );

		$this->assertSame( array(), $brands );
		$this->assertSame( '', implode( '|', $brands ) );
		$this->assertSame( '', implode( ' ', $brands ) );
	}

	/**
	 * Stores predating WooCommerce 9.4 keep working through the legacy pa_brand
	 * attribute taxonomy.
	 */
	public function test_falls_back_to_pa_brand_attribute_taxonomy() {
		if ( ! taxonomy_exists( 'pa_brand' ) ) {
			register_taxonomy( 'pa_brand', 'product', array( 'hierarchical' => false ) );
		}
		$brand = $this->factory->term->create(
			array(
				'taxonomy' => 'pa_brand',
				'name'     => 'Legacy Brand',
			)
		);

		$brands = $this->brands_for( array( $brand ), 'pa_brand' );

		$this->assertSame( array( 'Legacy Brand' ), $brands );
	}

	/**
	 * The brands_text field must be declared as a weighted TEXT field, otherwise typing a
	 * brand name matches nothing.
	 */
	public function test_schema_declares_weighted_brands_text_field() {
		$captured = null;

		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'get_prefix' )->willReturn( 'shift64_woo_search' );
		$redis->method( 'raw_command' )->willReturnCallback(
			function ( ...$args ) use ( &$captured ) {
				$captured = $args;
				return 'OK';
			}
		);

		Shift64_Woo_Search_Schema::create_index( $redis );

		$position = array_search( 'brands_text', $captured, true );
		$this->assertNotFalse( $position, 'brands_text must be present in the FT.CREATE schema.' );
		$this->assertSame( 'TEXT', $captured[ $position + 1 ] );
		$this->assertSame( 'WEIGHT', $captured[ $position + 2 ] );
		$this->assertSame( 5, Shift64_Woo_Search_Schema::get_default_weights()['brands_text'] );
	}
}
