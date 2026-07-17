<?php
/**
 * Search form shortcode tests.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Tests for the frontend search shortcode.
 */
class Shift64_Woo_Search_Shortcode_Test extends WP_UnitTestCase {

	/**
	 * The default markup integrates with autocomplete and WooCommerce fallback search.
	 */
	public function test_default_markup_uses_autocomplete_selector_and_product_search() {
		new Shift64_Woo_Search_Frontend();
		$html = do_shortcode( '[shift64_woo_search]' );

		$this->assertStringContainsString( 'class="shift64-woo-search-field__input"', $html );
		$this->assertStringContainsString( 'class="shift64-woo-search-field"', $html );
		$this->assertStringContainsString( 'name="s"', $html );
		$this->assertStringContainsString( 'name="post_type" value="product"', $html );
		$this->assertStringContainsString( 'role="search"', $html );
	}

	/**
	 * User-provided labels are escaped before being added to the form.
	 */
	public function test_shortcode_attributes_are_escaped() {
		$frontend = new Shift64_Woo_Search_Frontend();
		$html     = $frontend->render_search_shortcode(
			array(
				'placeholder' => 'Find <shirts>',
				'button'      => '<strong>Go</strong>',
				'label'       => 'Search <catalog>',
			)
		);

		$this->assertStringContainsString( 'placeholder="Find &lt;shirts&gt;"', $html );
		$this->assertStringContainsString( '&lt;strong&gt;Go&lt;/strong&gt;', $html );
		$this->assertStringContainsString( 'Search &lt;catalog&gt;', $html );
		$this->assertStringNotContainsString( '<strong>Go</strong>', $html );
	}

	/**
	 * The current search query is escaped exactly once in the form value.
	 */
	public function test_search_query_value_is_not_double_escaped() {
		$original_search_query = get_query_var( 's' );
		set_query_var( 's', 'Rock & "Roll"' );

		$frontend = new Shift64_Woo_Search_Frontend();
		$html     = $frontend->render_search_shortcode();

		set_query_var( 's', $original_search_query );

		$this->assertStringContainsString( 'value="Rock &amp; &quot;Roll&quot;"', $html );
		$this->assertStringNotContainsString( '&amp;amp;', $html );
		$this->assertStringNotContainsString( '&amp;quot;', $html );
	}
}
