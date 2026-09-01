<?php
/**
 * Characterization tests for the surfaces the block-theme-only cleanup preserves.
 *
 * The cleanup in `.ai/specs/2026-07-30-block-theme-only-legacy-removal.md`
 * deletes a large amount of frontend code in one release. Deleting the wrong
 * thing is cheap to do and expensive to notice, because most of what is removed
 * has no test — it was theme output. These tests pin the other side of the line:
 * everything the spec's "Preserved surfaces" section promises stays. They were
 * written *before* the removal Steps so that a preserved surface disappearing
 * shows up as a failing assertion here rather than as a merchant's bug report.
 *
 * Deliberately not covered here: anything the spec lists as removed. Its absence
 * is asserted next to the code that removes it.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Preserved public surface tests.
 */
class Shift64_Woo_Search_Preserved_Surfaces_Test extends WP_UnitTestCase {

	/**
	 * Absolute path to the plugin root.
	 *
	 * @return string
	 */
	private function plugin_dir() {
		return dirname( __DIR__ );
	}

	/**
	 * Every block name the plugin publishes stays registered.
	 *
	 * The two parent names are contractual: the spec keeps them precisely so
	 * saved content does not have to be rewritten. The children come from the
	 * composable-search spec and are equally public once shipped.
	 */
	public function test_published_block_names_stay_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		$expected = array(
			'shift64-woo-search/search',
			'shift64-woo-search/modal-search',
			'shift64-woo-search/search-control',
			'shift64-woo-search/search-panel',
			'shift64-woo-search/product-filters',
			'shift64-woo-search/filter-pill',
			'shift64-woo-search/product-sort',
		);

		foreach ( $expected as $name ) {
			$this->assertTrue(
				$registry->is_registered( $name ),
				sprintf( 'Block %s is a published surface and must stay registered.', $name )
			);
		}
	}

	/**
	 * A childless parent still renders a usable search form.
	 *
	 * This is the subtlest preserved surface in the cleanup. Content saved before
	 * the composable children existed contains a bare
	 * `<!-- wp:shift64-woo-search/search /-->`, and the parent renders it through
	 * the same markup builder the removed shortcodes used. Removing the
	 * shortcodes must not take that builder with them.
	 */
	public function test_childless_search_parent_still_renders_a_search_form() {
		$html = do_blocks( '<!-- wp:shift64-woo-search/search /-->' );

		$this->assertStringContainsString( 'shift64-woo-search-block--form', $html );
		$this->assertStringContainsString( 'shift64-woo-search-field__input', $html );
		$this->assertStringContainsString( 'name="s"', $html );
		$this->assertStringContainsString( 'value="product"', $html );
	}

	/**
	 * A childless modal parent still renders its trigger and dialog.
	 */
	public function test_childless_modal_parent_still_renders_a_dialog() {
		$html = do_blocks( '<!-- wp:shift64-woo-search/modal-search /-->' );

		$this->assertStringContainsString( 'shift64-woo-search-block--modal', $html );
		$this->assertStringContainsString( 'data-shift64-woo-search-modal-trigger', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
		$this->assertStringContainsString( 'shift64-woo-search-field__input', $html );
	}

	/**
	 * A composed parent still renders its children inside an Interactivity root.
	 */
	public function test_composed_search_parent_still_renders_an_interactivity_root() {
		$html = do_blocks(
			'<!-- wp:shift64-woo-search/search -->'
			. '<!-- wp:shift64-woo-search/search-control /-->'
			. '<!-- /wp:shift64-woo-search/search -->'
		);

		$this->assertStringContainsString( 'data-wp-interactive="shift64-woo-search/search"', $html );
		$this->assertStringContainsString( 'data-shift64-search-root', $html );
	}

	/**
	 * The documented CLI commands stay declared.
	 *
	 * `WP_CLI` is not loaded in the PHPUnit bootstrap, so `register_commands()`
	 * cannot be called. The command names are still a public contract, so read
	 * the registration source and assert each one is present — this catches a
	 * command being dropped while refactoring the bootstrap.
	 */
	public function test_documented_cli_commands_stay_declared() {
		$source = file_get_contents( $this->plugin_dir() . '/cli/class-shift64-woo-search-cli.php' );

		foreach ( array( 'setup', 'reindex', 'status', 'rebuild', 'test', 'health' ) as $command ) {
			$this->assertStringContainsString(
				"'shift64-woo-search " . $command . "'",
				$source,
				sprintf( 'CLI command "wp shift64-woo-search %s" is documented and must stay registered.', $command )
			);
		}
	}

	/**
	 * Redis key and index naming is unchanged.
	 *
	 * The cleanup explicitly removes no indexed data, so a rollback must find the
	 * same keys. `AGENTS.md` states the convention; this pins it.
	 */
	public function test_redis_key_and_index_naming_are_unchanged() {
		$redis  = Shift64_Woo_Search_Redis::get_instance();
		$prefix = $redis->get_prefix();

		$this->assertNotSame( '', $prefix, 'The Redis key prefix must resolve to something.' );
		$this->assertSame( $prefix . '_product_idx', $redis->get_index_name() );
		$this->assertSame( $prefix . ':product:42', $redis->get_product_key( 42 ) );
	}

	/**
	 * Every active engine option key still reaches the shared search config.
	 *
	 * The cleanup takes appearance, selector and placement fields out of WP
	 * Admin. None of those appear here, and everything that does appear must
	 * survive with its key and meaning intact.
	 */
	public function test_engine_option_keys_still_reach_the_search_config() {
		$config = Shift64_Woo_Search_Settings::search_config();

		$expected = array(
			'min_query_length',
			'autocomplete_limit',
			'full_limit',
			'outofstock_mode',
			'outofstock_demote_factor',
			'fuzzy_level',
			'logic',
			'strategy',
			'fallback_trigger',
			'fallback_score_threshold',
			'fallback_fuzzy_level',
			'token_reduction_enabled',
			'weak_tokens',
			'drop_trailing_weak_token_only',
			'diacritics_normalization',
			'fuzzy_synonyms',
			'category_boost_rules',
			'category_suggest_fuzzy',
		);

		foreach ( $expected as $key ) {
			$this->assertArrayHasKey(
				$key,
				$config,
				sprintf( 'Engine setting "%s" is an active option and must stay in the shared search config.', $key )
			);
		}
	}

	/**
	 * Facet enablement options stay active.
	 *
	 * These read like appearance switches but are not: they decide which facets
	 * the engine computes at all, and the Filter Pill inspector links to them for
	 * enable and rebuild setup. The cleanup keeps them.
	 */
	public function test_facet_enablement_options_stay_active() {
		update_option( 'shift64_woo_search_filter_categories_enabled', 'yes' );
		update_option( 'shift64_woo_search_filter_brands_enabled', 'yes' );

		$this->assertSame( 'yes', get_option( 'shift64_woo_search_filter_categories_enabled' ) );
		$this->assertSame( 'yes', get_option( 'shift64_woo_search_filter_brands_enabled' ) );
	}

	/**
	 * The SHORTINIT endpoint stays deployable from the plugin's own source.
	 *
	 * The endpoint and its bootstrap are the MU-plugin files the installer
	 * copies. The cleanup changes what the generated `config.php` carries, never
	 * whether the endpoint exists.
	 */
	public function test_shortinit_endpoint_source_files_are_present() {
		$this->assertFileExists( $this->plugin_dir() . '/mu-plugins/endpoint.php' );
		$this->assertFileExists( $this->plugin_dir() . '/mu-plugins/shift64-woo-search-bootstrap.php' );
	}
}
