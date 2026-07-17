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
	 * The modal shortcode renders an accessible trigger and product search dialog.
	 */
	public function test_modal_shortcode_uses_accessible_dialog_and_autocomplete_selector() {
		new Shift64_Woo_Search_Frontend();
		$html = do_shortcode( '[shift64_woo_search_modal]' );

		$this->assertStringContainsString( 'data-shift64-woo-search-modal-trigger', $html );
		$this->assertStringContainsString( 'data-shift64-woo-search-modal hidden', $html );
		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
		$this->assertStringContainsString( 'aria-expanded="false"', $html );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $html );
		$this->assertStringContainsString( 'class="shift64-woo-search-field__input"', $html );
		$this->assertStringContainsString( 'data-shift64-woo-search-clear hidden', $html );
		$this->assertStringContainsString( 'name="post_type" value="product"', $html );
		$this->assertDoesNotMatchRegularExpression( '/>\s+</', $html );
	}

	/**
	 * The modal shortcode keeps both bundled magnifier variants available.
	 */
	public function test_modal_shortcode_supports_alternative_search_icon() {
		$frontend = new Shift64_Woo_Search_Frontend();
		$html     = $frontend->render_search_modal_shortcode(
			array(
				'icon' => 'alternative',
			)
		);

		$this->assertStringContainsString( 'M544 513L397.2 364.2', $html );
		$this->assertStringNotContainsString( 'M480 272C480 317.9', $html );
	}

	/**
	 * Modal labels and visible form copy are escaped.
	 */
	public function test_modal_shortcode_attributes_are_escaped() {
		$frontend = new Shift64_Woo_Search_Frontend();
		$html     = $frontend->render_search_modal_shortcode(
			array(
				'placeholder'   => 'Find <lamps>',
				'button'        => 'Go <now>',
				'label'         => 'Search <catalog>',
				'trigger_label' => 'Open <search>',
				'close_label'   => 'Close <search>',
				'clear_label'   => 'Clear <search>',
			)
		);

		$this->assertStringContainsString( 'placeholder="Find &lt;lamps&gt;"', $html );
		$this->assertStringContainsString( 'aria-label="Go &lt;now&gt;"', $html );
		$this->assertStringContainsString( 'Search &lt;catalog&gt;', $html );
		$this->assertStringContainsString( 'aria-label="Open &lt;search&gt;"', $html );
		$this->assertStringContainsString( 'aria-label="Close &lt;search&gt;"', $html );
		$this->assertStringContainsString( 'aria-label="Clear &lt;search&gt;"', $html );
		$this->assertStringNotContainsString( '<search>', $html );
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

	/**
	 * The modal form also escapes the search query exactly once.
	 */
	public function test_modal_search_query_value_is_not_double_escaped() {
		$original_search_query = get_query_var( 's' );
		set_query_var( 's', 'Rock & "Roll"' );

		$frontend = new Shift64_Woo_Search_Frontend();
		$html     = $frontend->render_search_modal_shortcode();

		set_query_var( 's', $original_search_query );

		$this->assertStringContainsString( 'value="Rock &amp; &quot;Roll&quot;"', $html );
		$this->assertStringNotContainsString( '&amp;amp;', $html );
		$this->assertStringNotContainsString( '&amp;quot;', $html );
	}

	/**
	 * Rendering the shortcode directly ensures its configured assets are queued.
	 */
	public function test_shortcode_enqueues_its_assets() {
		$original_host = get_option( 'shift64_woo_search_redis_host', null );
		update_option( 'shift64_woo_search_redis_host', '127.0.0.1' );
		wp_dequeue_style( 'shift64-woo-search' );
		wp_dequeue_script( 'shift64-woo-search' );

		$frontend = new Shift64_Woo_Search_Frontend();
		$frontend->render_search_shortcode();

		$style_enqueued  = wp_style_is( 'shift64-woo-search', 'enqueued' );
		$script_enqueued = wp_script_is( 'shift64-woo-search', 'enqueued' );

		wp_dequeue_style( 'shift64-woo-search' );
		wp_dequeue_script( 'shift64-woo-search' );
		if ( null === $original_host ) {
			delete_option( 'shift64_woo_search_redis_host' );
		} else {
			update_option( 'shift64_woo_search_redis_host', $original_host );
		}

		$this->assertTrue( $style_enqueued );
		$this->assertTrue( $script_enqueued );
	}
}
