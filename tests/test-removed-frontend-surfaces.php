<?php
/**
 * Absence tests for the frontend surfaces the block-theme-only cleanup removed.
 *
 * `test-preserved-surfaces.php` pins what must survive; this pins what must not
 * come back. Both halves are needed: a removal that quietly returns — a hook
 * re-registered during a refactor, a helper resurrected because something still
 * called it — reintroduces the two-frontend problem the cleanup exists to end,
 * and would otherwise only show up as a theme conflict on a merchant's site.
 *
 * The hook assertions deliberately do not check `has_filter( $hook )` outright:
 * WooCommerce and the active theme legitimately register on most of these. What
 * must be absent is *this plugin's* callback, so each assertion walks the hook's
 * callbacks and fails if any of them belongs to a Shift64 class.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Removed frontend surface tests.
 */
class Shift64_Woo_Search_Removed_Frontend_Surfaces_Test extends WP_UnitTestCase {

	/**
	 * Instantiate the archive integration so its constructor wires its hooks.
	 */
	public function set_up() {
		parent::set_up();
		new Shift64_Woo_Search_Archive();
	}

	/**
	 * Assert no callback on the given hook belongs to a plugin class.
	 *
	 * @param string $hook   Hook name.
	 * @param string $reason Why the plugin must not be on this hook.
	 */
	private function assertPluginNotHookedTo( $hook, $reason ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			$this->assertTrue( true, $reason );
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];

				if ( is_array( $function ) ) {
					$owner = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];
				} elseif ( is_string( $function ) ) {
					$owner = $function;
				} else {
					continue;
				}

				$this->assertStringNotContainsStringIgnoringCase(
					'shift64_woo_search',
					$owner,
					sprintf( '%s (found %s on "%s")', $reason, $owner, $hook )
				);
			}
		}
	}

	/**
	 * The plugin no longer injects markup into WooCommerce archive placement hooks.
	 */
	public function test_woocommerce_placement_hooks_carry_no_plugin_callback() {
		$this->assertPluginNotHookedTo(
			'woocommerce_before_shop_loop',
			'The filter bar is the Product Filters block now; nothing may inject one above the loop.'
		);
		$this->assertPluginNotHookedTo(
			'woocommerce_archive_description',
			'The archive header belongs to the template.'
		);
		$this->assertPluginNotHookedTo(
			'woocommerce_show_page_title',
			'The plugin no longer suppresses a theme page title.'
		);
	}

	/**
	 * The plugin no longer replaces the catalog sort control or the result count.
	 */
	public function test_sort_and_result_count_are_left_to_woocommerce() {
		$this->assertPluginNotHookedTo(
			'woocommerce_catalog_orderby',
			'Sorting is offered by the Product Sort block; WooCommerce keeps its own control.'
		);
		$this->assertPluginNotHookedTo(
			'ngettext_woocommerce',
			'Product Collection owns its result count.'
		);
		$this->assertPluginNotHookedTo(
			'ngettext_with_context_woocommerce',
			'Product Collection owns its result count.'
		);
	}

	/**
	 * The plugin no longer takes over template rendering or theme layout.
	 */
	public function test_template_and_theme_takeovers_are_gone() {
		$this->assertPluginNotHookedTo(
			'template_include',
			'The archive fragment renderer was removed; templates render in full.'
		);
		$this->assertPluginNotHookedTo(
			'kadence_post_layout',
			'No theme is special-cased by the plugin any more.'
		);
		$this->assertPluginNotHookedTo(
			'get_the_archive_title',
			'Archive titles belong to the template.'
		);
	}

	/**
	 * The removed render methods are gone from the archive class, not merely unhooked.
	 */
	public function test_removed_archive_methods_no_longer_exist() {
		$removed = array(
			'render_search_header',
			'hide_default_page_title',
			'filter_archive_title',
			'shortcode_breadcrumbs',
			'disable_kadence_hero_on_search',
			'maybe_render_partial',
			'filter_sort_options',
			'filter_result_count_text',
			'filter_result_count_text_ctx',
		);

		foreach ( $removed as $method ) {
			$this->assertFalse(
				method_exists( 'Shift64_Woo_Search_Archive', $method ),
				sprintf( 'Shift64_Woo_Search_Archive::%s() was removed by the block-theme-only cleanup.', $method )
			);
		}
	}

	/**
	 * The classic filter-bar renderer class is gone entirely.
	 */
	public function test_legacy_filter_renderer_class_is_gone() {
		$this->assertFalse(
			class_exists( 'Shift64_Woo_Search_Filters' ),
			'Shift64_Woo_Search_Filters rendered the injected filter bar and its mobile tray; both are block surfaces now.'
		);
		$this->assertFileDoesNotExist(
			dirname( __DIR__ ) . '/frontend/class-shift64-woo-search-filters.php'
		);
	}

	/**
	 * No classic-theme shortcode tag is registered.
	 */
	public function test_no_classic_shortcodes_are_registered() {
		foreach ( array( 'shift64_woo_search', 'shift64_woo_search_modal', 'shift64_woo_search_breadcrumbs' ) as $tag ) {
			$this->assertFalse(
				shortcode_exists( $tag ),
				sprintf( '[%s] was removed by the block-theme-only cleanup.', $tag )
			);
		}
	}

	/**
	 * The plugin ships no theme-specific code path.
	 *
	 * Two guards, because theme coupling arrives in two shapes. The first is a
	 * brand name compiled into a code path; the second is theme *detection*
	 * (`get_template()`, `get_stylesheet()`, `wp_get_theme()`), which is how the
	 * removed Kadence partial decided whether to take a page over. Either one
	 * reappearing is exactly the regression this release exists to prevent, and
	 * it would otherwise only be visible on a store running that theme.
	 */
	public function test_no_theme_is_named_or_detected_in_runtime_php() {
		$root    = dirname( __DIR__ );
		$named   = array();
		$detects = array();

		foreach ( array( '/includes', '/frontend', '/admin', '/cli', '/mu-plugins' ) as $dir ) {
			$path = $root . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}

				$relative = str_replace( $root . '/', '', $file->getPathname() );
				$contents = file_get_contents( $file->getPathname() );

				if ( preg_match( '/\\b(kadence|astra|divi|elementor|storefront_)\\b/i', $contents ) ) {
					$named[] = $relative;
				}

				if ( preg_match( '/\\b(get_template|get_stylesheet|wp_get_theme)\\s*\\(/', $contents ) ) {
					$detects[] = $relative;
				}
			}
		}

		$this->assertSame(
			array(),
			$named,
			'Runtime PHP must not name a theme or page builder: ' . implode( ', ', $named )
		);
		$this->assertSame(
			array(),
			$detects,
			'Runtime PHP must not detect the active theme: ' . implode( ', ', $detects )
		);
	}
}
