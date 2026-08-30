<?php
/**
 * Childless search block fallback tests.
 *
 * These assertions used to drive `[shift64_woo_search]` and
 * `[shift64_woo_search_modal]` directly. The block-theme-only cleanup removed
 * both tags, but not the markup they produced: a `shift64-woo-search/search` or
 * `shift64-woo-search/modal-search` block saved before the composable children
 * existed still renders through the same builder. So the coverage moves to the
 * block, unchanged in what it asserts about the markup — escaping, the
 * WooCommerce product-search fallback, the accessible dialog, and the asset
 * enqueue — plus a guard that the shortcode tags really are gone.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Tests for the childless parent block fallback renderer.
 */
class Shift64_Woo_Search_Block_Fallback_Test extends WP_UnitTestCase {

	/**
	 * Render a childless parent block with the given attributes.
	 *
	 * @param string               $name       Block name without the namespace.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	private function render_childless( $name, $attributes = array() ) {
		$json = empty( $attributes ) ? '' : ' ' . wp_json_encode( $attributes );

		return do_blocks( '<!-- wp:shift64-woo-search/' . $name . $json . ' /-->' );
	}

	/**
	 * The classic-theme shortcodes are gone and are not re-registered anywhere.
	 */
	public function test_legacy_shortcodes_are_no_longer_registered() {
		$this->assertFalse(
			shortcode_exists( 'shift64_woo_search' ),
			'[shift64_woo_search] was removed in the block-theme-only cleanup.'
		);
		$this->assertFalse(
			shortcode_exists( 'shift64_woo_search_modal' ),
			'[shift64_woo_search_modal] was removed in the block-theme-only cleanup.'
		);
	}

	/**
	 * The default markup integrates with autocomplete and WooCommerce fallback search.
	 */
	public function test_default_markup_uses_autocomplete_selector_and_product_search() {
		$html = $this->render_childless( 'search' );

		$this->assertStringContainsString( 'class="shift64-woo-search-field__input"', $html );
		$this->assertStringContainsString( 'class="shift64-woo-search-field"', $html );
		$this->assertStringContainsString( 'name="s"', $html );
		$this->assertStringContainsString( 'name="post_type" value="product"', $html );
		$this->assertStringContainsString( 'role="search"', $html );
	}

	/**
	 * User-provided labels are escaped before being added to the form.
	 */
	public function test_search_attributes_are_escaped() {
		$html = $this->render_childless(
			'search',
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
	 * The modal fallback renders an accessible trigger and product search dialog.
	 */
	public function test_modal_uses_accessible_dialog_and_autocomplete_selector() {
		$html = $this->render_childless( 'modal-search' );

		$this->assertStringContainsString( 'data-shift64-woo-search-modal-trigger', $html );
		$this->assertStringContainsString( 'data-shift64-woo-search-modal hidden', $html );
		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
		$this->assertStringContainsString( 'aria-expanded="false"', $html );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $html );
		$this->assertStringContainsString( 'class="shift64-woo-search-field__input"', $html );
		$this->assertStringContainsString( 'data-shift64-woo-search-clear hidden', $html );
		$this->assertStringContainsString( 'name="post_type" value="product"', $html );
	}

	/**
	 * The modal fallback keeps both bundled magnifier variants available.
	 */
	public function test_modal_supports_alternative_search_icon() {
		$html = $this->render_childless( 'modal-search', array( 'icon' => 'alternative' ) );

		$this->assertStringContainsString( 'M544 513L397.2 364.2', $html );
		$this->assertStringNotContainsString( 'M480 272C480 317.9', $html );
	}

	/**
	 * Modal labels and visible form copy are escaped.
	 */
	public function test_modal_attributes_are_escaped() {
		$html = $this->render_childless(
			'modal-search',
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

		$html = $this->render_childless( 'search' );

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

		$html = $this->render_childless( 'modal-search' );

		set_query_var( 's', $original_search_query );

		$this->assertStringContainsString( 'value="Rock &amp; &quot;Roll&quot;"', $html );
		$this->assertStringNotContainsString( '&amp;amp;', $html );
		$this->assertStringNotContainsString( '&amp;quot;', $html );
	}

	/**
	 * Rendering the fallback queues the assets it needs.
	 */
	public function test_fallback_render_enqueues_its_assets() {
		$original_host = get_option( 'shift64_woo_search_redis_host', null );
		update_option( 'shift64_woo_search_redis_host', '127.0.0.1' );
		wp_dequeue_style( 'shift64-woo-search' );
		wp_dequeue_script( 'shift64-woo-search' );

		$this->render_childless( 'search' );

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
