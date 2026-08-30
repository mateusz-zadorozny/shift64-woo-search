<?php
/**
 * Frontend asset manifest tests.
 *
 * Before the block-theme-only cleanup the plugin hooked `wp_enqueue_scripts` and
 * shipped its stylesheet and autocomplete script to every storefront request —
 * the product archive, a single product, the cart, a blog post — whether or not
 * anything on the page used them. The design record calls for the opposite:
 * "Assets enqueue through block metadata only when their blocks render."
 *
 * That property is invisible in a normal test run, because nothing fails when a
 * stylesheet loads unnecessarily; it only shows up as weight on every page of a
 * merchant's store. So it is asserted directly: what a page view enqueues, what
 * declares the shared handle, and what is no longer registered at all.
 *
 * @package Shift64_Woo_Search
 */

/**
 * What the storefront actually loads.
 */
class Shift64_Woo_Search_Frontend_Asset_Manifest_Test extends WP_UnitTestCase {

	/**
	 * Handles the plugin owns on the frontend.
	 */
	const SHARED_STYLE  = 'shift64-woo-search';
	const SHARED_SCRIPT = 'shift64-woo-search';

	/**
	 * Start every test from a clean enqueue state with Redis configured.
	 *
	 * Redis has to look configured or `enqueue_assets()` returns early and the
	 * "nothing is enqueued" assertions would pass for the wrong reason.
	 */
	public function set_up() {
		parent::set_up();

		update_option( 'shift64_woo_search_redis_host', '127.0.0.1' );

		wp_dequeue_style( self::SHARED_STYLE );
		wp_dequeue_script( self::SHARED_SCRIPT );
	}

	/**
	 * A page view enqueues nothing on its own.
	 */
	public function test_a_page_view_enqueues_no_plugin_assets() {
		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse(
			wp_style_is( self::SHARED_STYLE, 'enqueued' ),
			'The stylesheet must reach a page through a rendered block, not through every request.'
		);
		$this->assertFalse(
			wp_script_is( self::SHARED_SCRIPT, 'enqueued' ),
			'The autocomplete script belongs to the childless-block fallback, not to every request.'
		);
	}

	/**
	 * The blocks that need the shared stylesheet declare it in their metadata.
	 *
	 * This is the delivery mechanism that replaced the global enqueue: WordPress
	 * loads a block's declared styles when that block renders, and skips them
	 * everywhere else. Losing the declaration would leave those blocks unstyled
	 * rather than fail loudly, so it is pinned here.
	 */
	public function test_blocks_declare_the_shared_stylesheet_in_their_metadata() {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( 'search', 'modal-search', 'product-sort' ) as $name ) {
			$block = $registry->get_registered( 'shift64-woo-search/' . $name );

			$this->assertInstanceOf( WP_Block_Type::class, $block, $name . ' is not registered.' );
			$this->assertContains(
				self::SHARED_STYLE,
				(array) $block->style_handles,
				sprintf( '%s must pull the shared stylesheet through block metadata.', $name )
			);
		}
	}

	/**
	 * Rendering the childless fallback is what pulls its assets in.
	 */
	public function test_the_childless_fallback_enqueues_its_own_assets() {
		do_blocks( '<!-- wp:shift64-woo-search/search /-->' );

		$this->assertTrue( wp_style_is( self::SHARED_STYLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( self::SHARED_SCRIPT, 'enqueued' ) );
	}

	/**
	 * The removed archive-swap script is not registered or enqueued anywhere.
	 */
	public function test_the_removed_archive_script_is_gone_from_the_manifest() {
		do_action( 'wp_enqueue_scripts' );
		do_blocks( '<!-- wp:shift64-woo-search/search /-->' );

		$this->assertFalse( wp_script_is( 'shift64-woo-search-ajax-pagination', 'registered' ) );
		$this->assertFalse( wp_script_is( 'shift64-woo-search-ajax-pagination', 'enqueued' ) );
	}

	/**
	 * Nothing is registered against `wp_enqueue_scripts` by the plugin.
	 *
	 * The manifest assertions above describe one request; this describes every
	 * request, by asserting the hook itself carries no plugin callback.
	 */
	public function test_the_plugin_registers_no_global_frontend_enqueue() {
		global $wp_filter;

		if ( ! isset( $wp_filter['wp_enqueue_scripts'] ) ) {
			$this->assertTrue( true );
			return;
		}

		foreach ( $wp_filter['wp_enqueue_scripts']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( ! is_array( $function ) ) {
					continue;
				}

				$owner = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];

				$this->assertStringNotContainsStringIgnoringCase(
					'shift64_woo_search',
					$owner,
					sprintf( '%s still hooks wp_enqueue_scripts; assets must be block-scoped.', $owner )
				);
			}
		}
	}
}
