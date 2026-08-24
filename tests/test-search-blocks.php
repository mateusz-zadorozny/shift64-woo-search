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
	 * Parent and child blocks are registered while classic-theme shortcodes remain.
	 */
	public function test_blocks_and_shortcodes_are_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		$this->assertTrue( $registry->is_registered( 'shift64-woo-search/search' ) );
		$this->assertTrue( $registry->is_registered( 'shift64-woo-search/modal-search' ) );
		$this->assertTrue( $registry->is_registered( 'shift64-woo-search/search-control' ) );
		$this->assertTrue( $registry->is_registered( 'shift64-woo-search/search-panel' ) );
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
			'<!-- wp:shift64-woo-search/modal-search {"icon":"alternative","trigger_label":"Open catalog","close_label":"Close catalog","trigger_style":"outline","modal_search_style":"pill","trigger_icon_color":"#123456","trigger_icon_hover_color":"#abcdef","trigger_surface_color":"#234567","trigger_surface_hover_color":"#bcdef0","trigger_border_radius":8,"trigger_icon_size":32,"trigger_padding":6,"trigger_outline_width":4,"modal_background_color":"#0a141e","modal_background_transparency":40,"search_input_text_color":"#102030","search_input_background_color":"#f1f2f3","search_button_color":"#405060","search_button_background_color":"#d1d2d3","search_button_hover_color":"#708090","search_button_background_hover_color":"#a1a2a3"} /-->'
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
		$this->assertStringContainsString( '--s64ws-trigger-padding:6px', $html );
		$this->assertStringContainsString( '--s64ws-trigger-outline-width:4px', $html );
		$this->assertStringContainsString( '--s64ws-modal-background-color:#0a141e', $html );
		$this->assertStringContainsString( '--s64ws-modal-background-opacity:60%', $html );
		$this->assertStringContainsString( '--s64ws-modal-input-color:#102030', $html );
		$this->assertStringContainsString( '--s64ws-modal-input-background:#f1f2f3', $html );
		$this->assertStringContainsString( '--s64ws-modal-button-color:#405060', $html );
		$this->assertStringContainsString( '--s64ws-modal-button-background:#d1d2d3', $html );
		$this->assertStringContainsString( '--s64ws-modal-button-hover-color:#708090', $html );
		$this->assertStringContainsString( '--s64ws-modal-button-hover-background:#a1a2a3', $html );
		$this->assertStringContainsString( 'has-search-input-text-color', $html );
		$this->assertStringContainsString( 'has-search-input-background-color', $html );
		$this->assertStringContainsString( 'has-search-button-color', $html );
		$this->assertStringContainsString( 'has-search-button-background-color', $html );
		$this->assertStringContainsString( 'has-search-button-hover-color', $html );
		$this->assertStringContainsString( 'has-search-button-background-hover-color', $html );
		$this->assertMatchesRegularExpression( '/class="shift64-woo-search-modal shift64-woo-search-modal--block[^"]*" style="[^"]*--s64ws-modal-background-color:#0a141e;--s64ws-modal-background-opacity:60%;/', $html );
		$this->assertStringNotContainsString( 'shift64-woo-search-modal__trigger wp-element-button', $html );
		$this->assertStringContainsString( 'shift64-woo-search-field__submit wp-element-button', $html );
	}

	/**
	 * Legacy native color values migrate to portal variables instead of the wrapper.
	 */
	public function test_modal_block_migrates_legacy_search_colors() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/modal-search {"style":{"color":{"text":"#123456","background":"#abcdef"},"elements":{"button":{"color":{"text":"#ffffff","background":"#cc2244"}}}}} /-->'
		);

		$this->assertStringContainsString( '--s64ws-modal-input-color:#123456', $html );
		$this->assertStringContainsString( '--s64ws-modal-input-background:#abcdef', $html );
		$this->assertStringContainsString( '--s64ws-modal-button-color:#ffffff', $html );
		$this->assertStringContainsString( '--s64ws-modal-button-background:#cc2244', $html );
		$this->assertStringNotContainsString( 'has-background', $html );
		$this->assertStringNotContainsString( 'has-text-color', $html );
		$this->assertStringNotContainsString( 'style="color:#123456;background-color:#abcdef;"', $html );
		$this->assertStringNotContainsString( 'shift64-woo-search-modal__trigger wp-element-button', $html );
		$this->assertStringContainsString( 'shift64-woo-search-field__submit wp-element-button', $html );
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
			'<!-- wp:shift64-woo-search/modal-search {"trigger_icon_color":"red;display:none","modal_background_color":"url(javascript:alert(1))","search_input_text_color":"red;display:none"} /-->'
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
	 * All composable blocks use API v3 metadata and the public parent names stay stable.
	 */
	public function test_blocks_use_api_v3_metadata() {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( 'search', 'modal-search', 'search-control', 'search-panel' ) as $name ) {
			$block = $registry->get_registered( 'shift64-woo-search/' . $name );
			$this->assertSame( 3, $block->api_version );
			$this->assertIsCallable( $block->render_callback );
		}
	}

	/**
	 * Child blocks can only be inserted under one of the stable parent blocks.
	 */
	public function test_child_blocks_are_parent_scoped_and_hidden_from_inserter() {
		$registry = WP_Block_Type_Registry::get_instance();
		$parents  = array( 'shift64-woo-search/search', 'shift64-woo-search/modal-search' );

		foreach ( array( 'search-control', 'search-panel' ) as $name ) {
			$block = $registry->get_registered( 'shift64-woo-search/' . $name );
			$this->assertSame( $parents, $block->parent );
			$this->assertSame( $parents, $block->ancestor );
			$this->assertFalse( $block->supports['inserter'] );
		}
	}

	/**
	 * Child blocks keep spacing/typography supports; color moved to the
	 * blocks' own attribute-backed panels (with hover variants), so the
	 * native color support is intentionally absent.
	 */
	public function test_child_blocks_expose_native_design_supports() {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( 'search-control', 'search-panel' ) as $name ) {
			$block = $registry->get_registered( 'shift64-woo-search/' . $name );
			$this->assertArrayNotHasKey( 'color', $block->supports );
			$this->assertTrue( $block->supports['spacing']['padding'] );
			$this->assertTrue( $block->supports['typography']['fontSize'] );
		}
	}

	/**
	 * Metadata-built editor scripts and view modules are registered.
	 */
	public function test_blocks_register_built_editor_and_view_assets() {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( 'search', 'modal-search', 'search-control', 'search-panel' ) as $name ) {
			$block = $registry->get_registered( 'shift64-woo-search/' . $name );
			$this->assertNotEmpty( $block->editor_script_handles );
		}

		$this->assertNotEmpty( $registry->get_registered( 'shift64-woo-search/search' )->view_script_module_ids );
		$this->assertNotEmpty( $registry->get_registered( 'shift64-woo-search/modal-search' )->view_script_module_ids );
	}

	/**
	 * Nested inline content renders a native form and a scoped interactive listbox.
	 */
	public function test_composable_inline_block_renders_native_interactive_form() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/search {"instanceId":"catalog-search"} --><!-- wp:shift64-woo-search/search-control {"label":"Catalog search","placeholder":"Find lamps","submitLabel":"Go"} /--><!-- wp:shift64-woo-search/search-panel {"noResultsLabel":"Nothing found"} /--><!-- /wp:shift64-woo-search/search -->'
		);

		$this->assertStringContainsString( 'data-wp-interactive="shift64-woo-search/search"', $html );
		$this->assertStringContainsString( 'data-shift64-search-root', $html );
		$this->assertStringContainsString( 'action="http://example.org/"', $html );
		$this->assertStringContainsString( 'method="get"', $html );
		$this->assertStringContainsString( 'data-wp-on--submit="actions.submit"', $html );
		$this->assertStringContainsString( 'data-wp-bind--value="context.query"', $html );
		$this->assertStringContainsString( 'name="post_type" value="product"', $html );
		$this->assertStringContainsString( 'placeholder="Find lamps"', $html );
		$this->assertStringContainsString( '>Go</button>', $html );
		$this->assertStringContainsString( 'id="catalog-search-listbox"', $html );
		$this->assertStringContainsString( 'Nothing found', html_entity_decode( $html ) );
	}

	/**
	 * Modal content uses native dialog semantics and scoped actions.
	 */
	public function test_composable_modal_block_renders_native_dialog() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/modal-search {"instanceId":"header-search"} --><!-- wp:shift64-woo-search/search-control {"triggerLabel":"Open catalog"} /--><!-- wp:shift64-woo-search/search-panel {"dialogLabel":"Catalog dialog","closeLabel":"Close catalog"} /--><!-- /wp:shift64-woo-search/modal-search -->'
		);

		$this->assertStringContainsString( 'aria-controls="header-search-dialog"', $html );
		$this->assertStringContainsString( 'data-wp-on--click="actions.open"', $html );
		$this->assertStringContainsString( '<dialog ', $html );
		$this->assertStringContainsString( 'id="header-search-dialog"', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
		$this->assertStringContainsString( 'data-wp-on--cancel="actions.onDialogCancel"', $html );
	}

	/**
	 * Duplicate saved instance IDs receive unique runtime-only DOM IDs.
	 */
	public function test_duplicate_instance_ids_are_disambiguated_at_render_time() {
		$block = '<!-- wp:shift64-woo-search/search {"instanceId":"duplicate"} --><!-- wp:shift64-woo-search/search-control /--><!-- wp:shift64-woo-search/search-panel /--><!-- /wp:shift64-woo-search/search -->';
		$html  = do_blocks( $block . $block );

		$this->assertStringContainsString( 'id="duplicate-input"', $html );
		$this->assertStringContainsString( 'id="duplicate-2-input"', $html );
		$this->assertSame(
			1,
			substr_count( $html, 'id="duplicate-listbox"' )
		);
	}

	/**
	 * Search parents nested inside layout blocks still provide one stable ID to
	 * their control, panel, and Interactivity API context.
	 */
	public function test_nested_composable_blocks_share_the_parent_runtime_id() {
		$html = do_blocks(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:shift64-woo-search/modal-search {"instanceId":"nested-modal"} --><!-- wp:shift64-woo-search/search-control /--><!-- wp:shift64-woo-search/search-panel /--><!-- /wp:shift64-woo-search/modal-search --><!-- wp:shift64-woo-search/search {"instanceId":"nested-inline"} --><!-- wp:shift64-woo-search/search-control /--><!-- wp:shift64-woo-search/search-panel /--><!-- /wp:shift64-woo-search/search --></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->'
		);

		$this->assertStringContainsString( 'aria-controls="nested-modal-dialog"', $html );
		$this->assertStringContainsString( 'id="nested-modal-dialog"', $html );
		$this->assertStringContainsString( 'id="nested-modal-input"', $html );
		$this->assertStringContainsString( 'aria-controls="nested-modal-listbox"', $html );
		$this->assertStringContainsString( 'id="nested-modal-listbox"', $html );
		$this->assertStringContainsString( 'id="nested-inline-input"', $html );
		$this->assertStringContainsString( 'aria-controls="nested-inline-listbox"', $html );
		$this->assertStringContainsString( 'id="nested-inline-listbox"', $html );
		$decoded_html = html_entity_decode( $html );
		$this->assertStringContainsString( '"instanceId":"nested-modal"', $decoded_html );
		$this->assertStringContainsString( '"instanceId":"nested-inline"', $decoded_html );
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
			'/\.shift64-woo-search-block--modal \.shift64-woo-search-modal__trigger\s*\{[^}]*width:\s*calc\(var\(--s64ws-trigger-icon-size, 24px\) \+ var\(--s64ws-trigger-padding, 10px\) \+ var\(--s64ws-trigger-padding, 10px\)\);[^}]*height:\s*calc\(var\(--s64ws-trigger-icon-size, 24px\) \+ var\(--s64ws-trigger-padding, 10px\) \+ var\(--s64ws-trigger-padding, 10px\)\);/s',
			$css
		);
		$this->assertStringContainsString( 'border-width: var(--s64ws-trigger-outline-width, 1px);', $css );
		$this->assertStringContainsString( '.has-search-input-text-color', $css );
		$this->assertStringContainsString( 'color: var(--s64ws-modal-input-color);', $css );
		$this->assertStringContainsString( 'background-color: var(--s64ws-modal-input-background);', $css );
		$this->assertStringContainsString( 'color: var(--s64ws-modal-button-hover-color);', $css );
		$this->assertStringContainsString( 'background: var(--s64ws-modal-button-hover-background);', $css );
	}
}
