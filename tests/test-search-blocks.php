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
			'<!-- wp:shift64-woo-search/modal-search {"icon":"alternative","trigger_label":"Open catalog","close_label":"Close catalog","trigger_style":"outline","modal_search_style":"pill","trigger_icon_color":"#123456","trigger_icon_hover_color":"#abcdef","trigger_surface_color":"#234567","trigger_surface_hover_color":"#bcdef0","trigger_border_radius":8,"trigger_icon_size":32,"modal_background_color":"#0a141e","modal_background_transparency":40} /-->'
		);

		$this->assertStringContainsString( 'wp-block-shift64-woo-search-modal-search', $html );
		$this->assertStringContainsString( 'shift64-woo-search-block--modal', $html );
		$this->assertStringContainsString( 'aria-label="Open catalog"', $html );
		$this->assertStringContainsString( 'aria-label="Close catalog"', $html );
		$this->assertStringContainsString( 'M544 513L397.2 364.2', $html );
		$this->assertStringContainsString( 'has-trigger-style-outline', $html );
		$this->assertStringContainsString( 'has-modal-search-style-pill', $html );
		$this->assertMatchesRegularExpression( '/shift64-woo-search-modal--block has-modal-search-style-pill/', $html );
		$this->assertStringContainsString( '--s64ws-trigger-icon-color:#123456', $html );
		$this->assertStringContainsString( '--s64ws-trigger-icon-hover-color:#abcdef', $html );
		$this->assertStringContainsString( '--s64ws-trigger-surface-color:#234567', $html );
		$this->assertStringContainsString( '--s64ws-trigger-surface-hover-color:#bcdef0', $html );
		$this->assertStringContainsString( '--s64ws-trigger-radius:8px', $html );
		$this->assertStringContainsString( '--s64ws-trigger-icon-size:32px', $html );
		$this->assertStringContainsString( '--s64ws-modal-background-color:#0a141e', $html );
		$this->assertStringContainsString( '--s64ws-modal-background-opacity:60%', $html );
		$this->assertMatchesRegularExpression( '/class="shift64-woo-search-modal shift64-woo-search-modal--block[^"]*" style="[^"]*--s64ws-modal-background-color:#0a141e;--s64ws-modal-background-opacity:60%;/', $html );
		$this->assertStringNotContainsString( 'shift64-woo-search-modal__trigger wp-element-button', $html );
		$this->assertStringContainsString( 'shift64-woo-search-field__submit wp-element-button', $html );
	}

	/**
	 * Native field and button colors target only the search form inside the modal.
	 */
	public function test_modal_block_applies_native_search_colors() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/modal-search {"style":{"color":{"text":"#123456","background":"#abcdef"},"elements":{"button":{"color":{"text":"#ffffff","background":"#cc2244"}}}}} /-->'
		);
		$css  = wp_style_engine_get_stylesheet_from_context( 'block-supports' );

		$this->assertMatchesRegularExpression( '/wp-elements-[a-f0-9]+/', $html );
		$this->assertMatchesRegularExpression( '/shift64-woo-search-modal--block[^\"]*wp-elements-[a-f0-9]+/', $html );
		$this->assertStringContainsString( 'shift64-woo-search-field__input', $html );
		$this->assertStringContainsString( 'style="color:#123456;background-color:#abcdef;"', $html );
		$this->assertStringNotContainsString( 'shift64-woo-search-modal__trigger wp-element-button', $html );
		$this->assertStringContainsString( 'shift64-woo-search-field__submit wp-element-button', $html );
		$this->assertStringContainsString( 'background-color:#cc2244', $css );
		$this->assertStringContainsString( 'color:#ffffff', $css );
	}

	/**
	 * Modal typography stays on the portal element after it moves under body.
	 */
	public function test_modal_block_applies_typography_to_portal_element() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/modal-search {"fontSize":"large","style":{"typography":{"fontWeight":"700","lineHeight":"1.2"}}} /-->'
		);

		$this->assertMatchesRegularExpression( '/shift64-woo-search-modal--block[^"]*has-large-font-size/', $html );
		$this->assertMatchesRegularExpression( '/class="shift64-woo-search-modal[^"]*" style="[^"]*font-weight:700;line-height:1.2;/', $html );
	}

	/**
	 * Invalid custom colors never reach the rendered style attribute.
	 */
	public function test_modal_block_rejects_unsafe_custom_colors() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/modal-search {"trigger_icon_color":"red;display:none","modal_background_color":"url(javascript:alert(1))"} /-->'
		);

		$this->assertStringNotContainsString( 'red;display:none', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	/**
	 * The modal preview is closed by default and can be enabled in the editor.
	 */
	public function test_modal_block_preview_is_opt_in() {
		$closed = do_blocks( '<!-- wp:shift64-woo-search/modal-search /-->' );
		$open   = do_blocks( '<!-- wp:shift64-woo-search/modal-search {"preview":true} /-->' );

		$this->assertStringNotContainsString( 'is-preview-open', $closed );
		$this->assertStringContainsString( 'is-preview-open', $open );
	}

	/**
	 * WordPress 7 automatically exposes these server-rendered blocks to the editor.
	 */
	public function test_auto_register_support_matches_wordpress_capability() {
		global $wp_version;

		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/search' );
		$modal = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/modal-search' );

		if ( version_compare( $wp_version, '7.0', '>=' ) ) {
			$this->assertTrue( $block->supports['autoRegister'] );
			$this->assertTrue( $block->attributes['placeholder']['autoGenerateControl'] );
			$this->assertTrue( $block->attributes['button']['autoGenerateControl'] );
			$this->assertTrue( $block->attributes['label']['autoGenerateControl'] );
			$this->assertArrayNotHasKey( 'autoGenerateControl', $modal->attributes['preview'] );
			$this->assertArrayNotHasKey( 'autoGenerateControl', $modal->attributes['trigger_style'] );
			$this->assertTrue( $modal->attributes['placeholder']['autoGenerateControl'] );
		} else {
			$this->assertArrayNotHasKey( 'autoRegister', $block->supports );
		}
	}

	/**
	 * Primitive attributes are labelled so WordPress can generate inspector fields.
	 */
	public function test_block_attributes_have_editor_labels() {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/modal-search' );

		foreach ( array( 'placeholder', 'button', 'label', 'trigger_label', 'close_label', 'clear_label', 'icon', 'preview', 'trigger_style', 'trigger_icon_color', 'trigger_icon_hover_color', 'trigger_surface_color', 'trigger_surface_hover_color', 'trigger_border_radius', 'trigger_icon_size', 'modal_search_style', 'modal_background_color', 'modal_background_transparency' ) as $attribute ) {
			$this->assertNotEmpty( $block->attributes[ $attribute ]['label'] );
		}

		$this->assertSame( array( 'default', 'alternative' ), $block->attributes['icon']['enum'] );
		$this->assertSame( array( 'icon', 'background', 'outline' ), $block->attributes['trigger_style']['enum'] );
		$this->assertSame( array( 'default', 'pill', 'minimal' ), $block->attributes['modal_search_style']['enum'] );
	}

	/**
	 * WordPress provides the Default style choice, so custom styles must not duplicate it.
	 */
	public function test_block_styles_only_register_custom_variants() {
		$search = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/search' );
		$modal  = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/modal-search' );

		$this->assertSame( array( 'pill', 'minimal' ), wp_list_pluck( $search->styles, 'name' ) );
		$this->assertSame( array(), wp_list_pluck( $modal->styles, 'name' ) );
	}

	/**
	 * Both blocks expose native search colors and keep field colors off the wrapper.
	 */
	public function test_search_block_color_supports_target_field_and_button() {
		$search = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/search' );
		$modal  = WP_Block_Type_Registry::get_instance()->get_registered( 'shift64-woo-search/modal-search' );

		$this->assertTrue( $search->supports['color']['button'] );
		$this->assertTrue( $modal->supports['color']['button'] );
		$this->assertSame(
			array( 'text', 'background', 'gradients' ),
			$search->supports['color']['__experimentalSkipSerialization']
		);
		$this->assertSame(
			array( 'text', 'background', 'gradients' ),
			$modal->supports['color']['__experimentalSkipSerialization']
		);
	}

	/**
	 * The modal block loads its purpose-built editor controls.
	 */
	public function test_modal_block_registers_editor_control_script() {
		$this->assertTrue( wp_script_is( 'shift64-woo-search-block-editor', 'registered' ) );

		do_action( 'enqueue_block_editor_assets' );
		$this->assertTrue( wp_script_is( 'shift64-woo-search-block-editor', 'enqueued' ) );
	}

	/**
	 * The editor script provides every dedicated modal and trigger design control.
	 */
	public function test_modal_editor_script_exposes_dedicated_design_controls() {
		$script = file_get_contents( SHIFT64_WOO_SEARCH_PATH . 'admin/js/shift64-woo-search-block-editor.js' );

		$this->assertStringContainsString( "'blocks.registerBlockType'", $script );
		foreach ( array( 'Button style', 'Icon size', 'Border radius', 'Icon color', 'Icon hover color', 'Background or outline color', 'Background or outline hover color', 'Color of search box and button', 'Search box text', 'Search box background', 'Search button text', 'Search button background', 'Search field style', 'Modal background color', 'Modal transparency', 'Show preview in editor' ) as $label ) {
			$this->assertStringContainsString( "'" . $label . "'", $script );
		}
		$this->assertStringContainsString( 'color: false', $script );
		$this->assertTrue(
			strpos( $script, "title: __( 'Trigger button'" ) < strpos( $script, "title: __( 'Color of search box and button'" )
		);
		$this->assertTrue(
			strpos( $script, "title: __( 'Color of search box and button'" ) < strpos( $script, "title: __( 'Modal'" )
		);
	}

	/**
	 * The opt-in editor preview occupies a new row below the trigger.
	 */
	public function test_modal_editor_preview_stacks_below_trigger() {
		$css = file_get_contents( SHIFT64_WOO_SEARCH_PATH . 'frontend/css/shift64-woo-search.css' );

		$this->assertMatchesRegularExpression(
			'/\.is-preview-open \.shift64-woo-search-modal-shortcode\s*\{[^}]*flex-direction:\s*column;[^}]*width:\s*100%;/s',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/\.is-preview-open \.shift64-woo-search-modal\[hidden\]\s*\{[^}]*align-self:\s*stretch;[^}]*width:\s*100%;[^}]*margin-top:\s*12px;/s',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/\.shift64-woo-search-block--modal \.shift64-woo-search-modal__trigger\s*\{[^}]*width:\s*max\(44px, calc\(var\(--s64ws-trigger-icon-size, 24px\) \+ 20px\)\);[^}]*height:\s*max\(44px, calc\(var\(--s64ws-trigger-icon-size, 24px\) \+ 20px\)\);/s',
			$css
		);
	}
}
