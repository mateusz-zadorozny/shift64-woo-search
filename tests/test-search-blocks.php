<?php
/**
 * Dynamic search block tests.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Tests for PHP-rendered search blocks and shortcode compatibility.
 */
class Shift64_Woo_Search_Blocks_Test extends WP_UnitTestCase {

	/**
	 * Both blocks are registered while both classic-theme shortcodes remain.
	 */
	public function test_blocks_and_shortcodes_are_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		$this->assertTrue( $registry->is_registered( 'shift64-woo-search/search' ) );
		$this->assertTrue( $registry->is_registered( 'shift64-woo-search/modal-search' ) );
		$this->assertTrue( shortcode_exists( 'shift64_woo_search' ) );
		$this->assertTrue( shortcode_exists( 'shift64_woo_search_modal' ) );
	}

	/**
	 * The regular block passes editor attributes to the shared search renderer.
	 */
	public function test_search_block_renders_custom_text_and_native_wrapper() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/search {"placeholder":"Find lamps","button":"Go","label":"Catalog search"} /-->'
		);

		$this->assertStringContainsString( 'wp-block-shift64-woo-search-search', $html );
		$this->assertStringContainsString( 'shift64-woo-search-block--form', $html );
		$this->assertStringContainsString( 'placeholder="Find lamps"', $html );
		$this->assertStringContainsString( '>Go</button>', $html );
		$this->assertStringContainsString( '>Catalog search</label>', $html );
		$this->assertStringContainsString( 'shift64-woo-search-field__submit wp-element-button', $html );
	}

	/**
	 * Native field colors are applied to the input instead of its outer wrapper.
	 */
	public function test_search_block_applies_native_colors_to_input() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/search {"textColor":"contrast","backgroundColor":"base","gradient":"vivid-cyan-blue-to-vivid-purple"} /-->'
		);

		$this->assertStringContainsString( 'shift64-woo-search-field__input', $html );
		$this->assertStringContainsString( 'has-contrast-color', $html );
		$this->assertStringContainsString( 'has-text-color', $html );
		$this->assertStringContainsString( 'has-base-background-color', $html );
		$this->assertStringContainsString( 'has-background', $html );
		$this->assertStringContainsString( 'has-vivid-cyan-blue-to-vivid-purple-gradient-background', $html );
		$this->assertStringNotContainsString( 'shift64-woo-search-block--form has-background', $html );
	}

	/**
	 * Custom field colors remain live inline styles in the server-rendered preview.
	 */
	public function test_search_block_applies_custom_colors_to_input() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/search {"style":{"color":{"text":"#123456","background":"#abcdef"}}} /-->'
		);

		$this->assertStringContainsString( 'style="color:#123456;background-color:#abcdef;"', $html );
	}

	/**
	 * Native button element colors target the search submit button.
	 */
	public function test_search_block_applies_native_button_colors() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/search {"style":{"elements":{"button":{"color":{"text":"#ffffff","background":"#cc2244"}}}}} /-->'
		);
		$css  = wp_style_engine_get_stylesheet_from_context( 'block-supports' );

		$this->assertMatchesRegularExpression( '/wp-elements-[a-f0-9]+/', $html );
		$this->assertStringContainsString( 'shift64-woo-search-field__submit wp-element-button', $html );
		$this->assertStringContainsString( 'background-color:#cc2244', $css );
		$this->assertStringContainsString( 'color:#ffffff', $css );
	}

	/**
	 * The modal block exposes the icon and accessibility copy through attributes.
	 */
	public function test_modal_block_renders_custom_icon_and_labels() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/modal-search {"icon":"alternative","trigger_label":"Open catalog","close_label":"Close catalog"} /-->'
		);

		$this->assertStringContainsString( 'wp-block-shift64-woo-search-modal-search', $html );
		$this->assertStringContainsString( 'shift64-woo-search-block--modal', $html );
		$this->assertStringContainsString( 'aria-label="Open catalog"', $html );
		$this->assertStringContainsString( 'aria-label="Close catalog"', $html );
		$this->assertStringContainsString( 'M544 513L397.2 364.2', $html );
	}

	/**
	 * WordPress 7 automatically exposes these server-rendered blocks to the editor.
	 */
	public function test_auto_register_support_matches_wordpress_capability() {
		global $wp_version;

		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/search' );

		if ( version_compare( $wp_version, '7.0', '>=' ) ) {
			$this->assertTrue( $block->supports['autoRegister'] );
			$this->assertTrue( $block->attributes['placeholder']['autoGenerateControl'] );
			$this->assertTrue( $block->attributes['button']['autoGenerateControl'] );
			$this->assertTrue( $block->attributes['label']['autoGenerateControl'] );
		} else {
			$this->assertArrayNotHasKey( 'autoRegister', $block->supports );
		}
	}

	/**
	 * Primitive attributes are labelled so WordPress can generate inspector fields.
	 */
	public function test_block_attributes_have_editor_labels() {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/modal-search' );

		foreach ( array( 'placeholder', 'button', 'label', 'trigger_label', 'close_label', 'clear_label', 'icon' ) as $attribute ) {
			$this->assertNotEmpty( $block->attributes[ $attribute ]['label'] );
		}

		$this->assertSame( array( 'default', 'alternative' ), $block->attributes['icon']['enum'] );
	}

	/**
	 * WordPress provides the Default style choice, so custom styles must not duplicate it.
	 */
	public function test_block_styles_only_register_custom_variants() {
		$search = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/search' );
		$modal  = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/modal-search' );

		$this->assertSame( array( 'pill', 'minimal' ), wp_list_pluck( $search->styles, 'name' ) );
		$this->assertSame( array( 'soft', 'outline' ), wp_list_pluck( $modal->styles, 'name' ) );
	}

	/**
	 * The editor exposes native button colors and keeps field colors off the wrapper.
	 */
	public function test_search_block_color_supports_target_field_and_button() {
		$search = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/search' );

		$this->assertTrue( $search->supports['color']['button'] );
		$this->assertSame(
			array( 'text', 'background', 'gradients' ),
			$search->supports['color']['__experimentalSkipSerialization']
		);
	}
}
