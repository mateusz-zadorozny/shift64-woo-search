<?php
/**
 * Generated SHORTINIT config manifest tests.
 *
 * The lightweight autocomplete endpoint never boots the options API. It reads
 * constants out of the generated `wp-content/mu-plugins/shift64-woo-search/config.php`
 * instead, which makes that file a second, invisible copy of the settings surface:
 * nothing in WP Admin shows it, and no page renders it, so a key leaking into it
 * — or falling out of it — is silent.
 *
 * The block-theme-only cleanup makes that matter more than it used to. Several
 * appearance and selector settings are now inert: still stored, so a version
 * rollback finds them, but read by nothing. If one of them were exported here it
 * would look live to anyone reading the generated file, and it would be the one
 * place where "inert" was not actually true.
 *
 * So the manifest is pinned exactly. Adding a genuinely new engine constant means
 * updating this list deliberately, which is the point — the list is the review
 * checkpoint, not an obstacle.
 *
 * @package Shift64_Woo_Search
 */

/**
 * What the generated SHORTINIT config is allowed to carry.
 */
class Shift64_Woo_Search_Shortinit_Config_Manifest_Test extends WP_UnitTestCase {

	/**
	 * Every constant the generated config is expected to define.
	 *
	 * @return string[]
	 */
	private function expected_constants() {
		return array(
			// Connection.
			'SHIFT64_WOO_SEARCH_REDIS_HOST',
			'SHIFT64_WOO_SEARCH_REDIS_PORT',
			'SHIFT64_WOO_SEARCH_REDIS_USERNAME',
			'SHIFT64_WOO_SEARCH_REDIS_PASSWORD',
			'SHIFT64_WOO_SEARCH_REDIS_DB',
			'SHIFT64_WOO_SEARCH_REDIS_PREFIX',
			// Deployment.
			'SHIFT64_WOO_SEARCH_PLUGIN_PATH',
			'SHIFT64_WOO_SEARCH_MU_VERSION',
			// Search behaviour.
			'SHIFT64_WOO_SEARCH_MIN_QUERY',
			'SHIFT64_WOO_SEARCH_AUTOCOMPLETE_LIMIT',
			'SHIFT64_WOO_SEARCH_FULL_LIMIT',
			'SHIFT64_WOO_SEARCH_OUTOFSTOCK_MODE',
			'SHIFT64_WOO_SEARCH_OUTOFSTOCK_DEMOTE_FACTOR',
			'SHIFT64_WOO_SEARCH_FUZZY_LEVEL',
			'SHIFT64_WOO_SEARCH_LOGIC',
			// Strategy and fallback ladder.
			'SHIFT64_WOO_SEARCH_STRATEGY',
			'SHIFT64_WOO_SEARCH_FALLBACK_TRIGGER',
			'SHIFT64_WOO_SEARCH_FALLBACK_SCORE_THRESHOLD',
			'SHIFT64_WOO_SEARCH_FALLBACK_FUZZY_LEVEL',
			'SHIFT64_WOO_SEARCH_TOKEN_REDUCTION_ENABLED',
			'SHIFT64_WOO_SEARCH_WEAK_TOKENS',
			'SHIFT64_WOO_SEARCH_DROP_TRAILING_WEAK_TOKEN_ONLY',
			// Normalization and suggestion tuning.
			'SHIFT64_WOO_SEARCH_DIACRITICS_NORMALIZATION',
			'SHIFT64_WOO_SEARCH_FUZZY_SYNONYMS',
			'SHIFT64_WOO_SEARCH_CATEGORY_SUGGEST_FUZZY',
			'SHIFT64_WOO_SEARCH_CATEGORY_BOOST_RULES',
			'SHIFT64_WOO_SEARCH_CATEGORY_PIN_RULES',
			'SHIFT64_WOO_SEARCH_BRAND_SUGGEST',
			// Facets and protection.
			'SHIFT64_WOO_SEARCH_FILTER_ATTRIBUTES',
			'SHIFT64_WOO_SEARCH_RATE_LIMIT',
		);
	}

	/**
	 * Regenerate the config and return its contents.
	 *
	 * @return string Generated file contents.
	 */
	private function generated_config() {
		Shift64_Woo_Search_Plugin::get_instance()->generate_mu_plugin_config();

		$path = WP_CONTENT_DIR . '/mu-plugins/shift64-woo-search/config.php';

		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped( 'The mu-plugin config could not be written in this environment.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a generated local file.
		return file_get_contents( $path );
	}

	/**
	 * Extract the constant names the generated config defines.
	 *
	 * @param string $config Generated file contents.
	 * @return string[]
	 */
	private function defined_constants( $config ) {
		preg_match_all( "/define\(\s*'([A-Z0-9_]+)'/", $config, $matches );

		return $matches[1];
	}

	/**
	 * The generated config defines exactly the engine constants and nothing else.
	 */
	public function test_generated_config_defines_exactly_the_engine_constants() {
		$defined  = $this->defined_constants( $this->generated_config() );
		$expected = $this->expected_constants();

		sort( $defined );
		sort( $expected );

		$this->assertSame(
			$expected,
			$defined,
			'The SHORTINIT endpoint reads this file; every constant in it must be one it consumes.'
		);
	}

	/**
	 * No retired appearance, selector or placement value reaches the endpoint.
	 *
	 * Asserted by value as well as by name: these settings are inert rather than
	 * deleted, so a store can still hold a value for them, and exporting that
	 * value under any constant name would make the file misrepresent what is live.
	 */
	public function test_no_retired_setting_reaches_the_generated_config() {
		update_option( 'shift64_woo_search_input_selector', '.retired-input-selector' );
		update_option( 'shift64_woo_search_additional_selectors', '.retired-extra-selector' );
		update_option( 'shift64_woo_search_button_selector', '.retired-button-selector' );
		update_option( 'shift64_woo_search_dropdown_width_mode', 'custom' );
		update_option( 'shift64_woo_search_dropdown_width', '911' );

		$config = $this->generated_config();

		foreach ( array( 'SELECTOR', 'DROPDOWN_WIDTH', 'PLACEMENT', 'APPEARANCE', 'THEME' ) as $fragment ) {
			$this->assertStringNotContainsString(
				$fragment,
				$config,
				sprintf( 'A "%s" constant would resurrect a retired setting on the fast path.', $fragment )
			);
		}

		foreach ( array( '.retired-input-selector', '.retired-extra-selector', '.retired-button-selector', '911' ) as $value ) {
			$this->assertStringNotContainsString(
				$value,
				$config,
				'A stored-but-inert value must not reach the endpoint under any constant name.'
			);
		}
	}

	/**
	 * Setup and update regenerate the file through the existing deployment path.
	 *
	 * The generator is what `wp shift64-woo-search setup`, plugin activation and
	 * the post-update hook all call, so changing an engine setting and running it
	 * again is exactly the merchant-visible contract: the fast path picks the new
	 * value up without anyone editing a file by hand.
	 */
	public function test_regeneration_picks_up_a_changed_engine_setting() {
		update_option( 'shift64_woo_search_autocomplete_limit', 7 );
		$this->assertStringContainsString(
			"define( 'SHIFT64_WOO_SEARCH_AUTOCOMPLETE_LIMIT', 7 );",
			$this->generated_config()
		);

		update_option( 'shift64_woo_search_autocomplete_limit', 12 );
		$this->assertStringContainsString(
			"define( 'SHIFT64_WOO_SEARCH_AUTOCOMPLETE_LIMIT', 12 );",
			$this->generated_config(),
			'Setup and update must regenerate the file rather than leave a stale copy.'
		);
	}

	/**
	 * The generated file is never committed to the repository.
	 *
	 * `AGENTS.md` states this outright, and it matters more than tidiness: the file
	 * carries the store's Redis credentials.
	 */
	public function test_the_generated_config_is_not_committed() {
		$this->assertFileDoesNotExist(
			dirname( __DIR__ ) . '/mu-plugins/config.php',
			'The generated config carries Redis credentials and is deployment output, never source.'
		);
	}
}
