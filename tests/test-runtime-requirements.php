<?php
/**
 * Runtime requirement guard tests.
 *
 * Raising a declared minimum is the part of a release that reaches sites the
 * author never sees. The failure it must never produce is a fatal: a merchant
 * upgrades, the plugin registers a block against an API that is not there, and
 * the storefront white-screens on a Tuesday morning. The guard exists so the
 * same situation produces a working store, a switched-off block layer, and a
 * sentence explaining what to upgrade.
 *
 * These tests drive the resolver directly with faked versions rather than the
 * bootstrap, because a unit test cannot actually run on WordPress 6.9.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Declared-minimum resolution and its consequences.
 */
class Shift64_Woo_Search_Runtime_Requirements_Test extends WP_UnitTestCase {

	/**
	 * The real `$wp_version`, restored after each test.
	 *
	 * @var string
	 */
	private $real_wp_version = '';

	public function set_up() {
		parent::set_up();

		global $wp_version;
		$this->real_wp_version = $wp_version;
	}

	public function tear_down() {
		global $wp_version;
		$wp_version = $this->real_wp_version; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the value this test replaced.

		parent::tear_down();
	}

	/**
	 * Pretend the site runs a given WordPress version.
	 *
	 * `get_bloginfo( 'version' )` reads the `$wp_version` global directly and
	 * offers no filter, so faking it means replacing the global. It is restored
	 * in tear_down().
	 *
	 * @param string $version Version to report.
	 */
	private function fake_wp_version( $version ) {
		global $wp_version;
		$wp_version = $version; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately faking the runtime under test.
	}

	/**
	 * The declared minimums match what every other declaration states.
	 *
	 * `test-php-requirement-declarations.php` pins the headers, readme, docs and
	 * CI matrix to one value each. This pins the runtime guard to the same
	 * values, so the code that switches features off cannot drift from the
	 * promise the plugin makes on its listing page.
	 */
	public function test_declared_minimums_match_the_published_ones() {
		$this->assertSame( '7.0', Shift64_Woo_Search_Requirements::MIN_WP );
		$this->assertSame( '10.9', Shift64_Woo_Search_Requirements::MIN_WC );

		$header = file_get_contents( dirname( __DIR__ ) . '/shift64-woo-search.php' );

		$this->assertStringContainsString(
			'Requires at least: ' . Shift64_Woo_Search_Requirements::MIN_WP,
			$header
		);
		$this->assertStringContainsString(
			'WC requires at least: ' . Shift64_Woo_Search_Requirements::MIN_WC,
			$header
		);
	}

	/**
	 * The environment the suite runs in satisfies the declared minimums.
	 *
	 * A sanity check on the resolver as much as on the environment: if this
	 * failed while the suite was green everywhere else, the resolver would be
	 * reading the wrong thing.
	 */
	public function test_the_test_environment_is_supported() {
		$this->assertSame( array(), Shift64_Woo_Search_Requirements::unmet() );
		$this->assertTrue( Shift64_Woo_Search_Requirements::are_met() );
	}

	/**
	 * An older WordPress is reported with both the required and running version.
	 *
	 * A notice that says only "unsupported" leaves the reader to go and find both
	 * numbers, which is the difference between an actionable message and an
	 * irritating one.
	 */
	public function test_an_older_wordpress_is_reported_actionably() {
		$this->fake_wp_version( '6.9' );

		$unmet = Shift64_Woo_Search_Requirements::unmet();

		$this->assertCount( 1, $unmet );
		$this->assertStringContainsString( '7.0', $unmet[0] );
		$this->assertStringContainsString( '6.9', $unmet[0] );
		$this->assertFalse( Shift64_Woo_Search_Requirements::are_met() );
	}

	/**
	 * A pre-release of the required version counts as meeting it.
	 *
	 * `version_compare()` orders `7.0-RC1` below `7.0`, so a naive check would
	 * tell someone testing the release candidate of the very version being
	 * required that their WordPress is too old.
	 */
	public function test_a_release_candidate_of_the_required_version_is_supported() {
		$this->fake_wp_version( '7.0-RC1' );

		$this->assertSame( array(), Shift64_Woo_Search_Requirements::unmet() );
	}

	/**
	 * A newer WordPress is supported without any special-casing.
	 */
	public function test_a_newer_wordpress_is_supported() {
		$this->fake_wp_version( '9.4' );

		$this->assertSame( array(), Shift64_Woo_Search_Requirements::unmet() );
	}

	/**
	 * The guard never fatals, whatever the version string looks like.
	 *
	 * Version strings arrive from a database row and from other plugins'
	 * constants; an empty or nonsense one must produce a verdict, not an error.
	 */
	public function test_a_nonsense_version_string_still_produces_a_verdict() {
		foreach ( array( '', 'not-a-version', '0' ) as $version ) {
			$this->fake_wp_version( $version );

			$unmet = Shift64_Woo_Search_Requirements::unmet();

			$this->assertIsArray( $unmet, sprintf( 'Version "%s" produced no verdict.', $version ) );
		}
	}

	/**
	 * Every CLI command refuses to run on an unsupported runtime.
	 *
	 * Asserted against the source because WP_CLI is not loaded in the PHPUnit
	 * bootstrap. What matters is that no command was left without the guard —
	 * one unguarded command is all it takes for a rebuild to start against a
	 * runtime that cannot finish it.
	 */
	public function test_every_cli_command_asserts_the_runtime_first() {
		$source = file_get_contents( dirname( __DIR__ ) . '/cli/class-shift64-woo-search-cli.php' );

		foreach ( array( 'setup', 'reindex', 'status', 'rebuild', 'test', 'health' ) as $command ) {
			$this->assertSame(
				1,
				preg_match(
					'/public static function ' . $command . '\(.*\n\t\tself::assert_requirements\(\);/',
					$source
				),
				sprintf( 'wp shift64-woo-search %s must assert the runtime before doing anything.', $command )
			);
		}

		$this->assertStringContainsString(
			'WP_CLI::error(',
			$source,
			'The guard must exit non-zero rather than fatal.'
		);
	}

	/**
	 * An unreadable WooCommerce version does not switch the block layer off.
	 *
	 * The guard fails open here on purpose. The plugin already returns early
	 * when WooCommerce is not among the active plugins, so by the time this runs
	 * WooCommerce is active; if `WC_VERSION` is somehow unreadable — a load-order
	 * change, an unusual bundling — disabling every storefront block would be a
	 * far worse outcome than trusting a running installation. The PHPUnit
	 * bootstrap reproduces exactly that state, since it reports WooCommerce as
	 * active without loading it.
	 */
	public function test_an_unreadable_woocommerce_version_is_not_treated_as_unmet() {
		$this->assertFalse(
			defined( 'WC_VERSION' ),
			'This test describes the state where the WooCommerce version cannot be read.'
		);
		$this->assertSame( array(), Shift64_Woo_Search_Requirements::unmet() );
	}

	/**
	 * A classic theme is a supported runtime, not an unmet requirement.
	 *
	 * The distinction is the whole point of the release: on a classic theme
	 * nothing is broken and nothing is switched off — the plugin just renders no
	 * storefront controls, because it no longer injects markup into a theme it
	 * does not own. Folding that into the version check would take the search
	 * endpoint and the CLI down with it for no reason.
	 */
	public function test_the_active_theme_does_not_affect_the_version_verdict() {
		$this->assertSame( array(), Shift64_Woo_Search_Requirements::unmet() );
		$this->assertIsBool( Shift64_Woo_Search_Requirements::block_theme_active() );
	}
}
