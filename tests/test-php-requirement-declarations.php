<?php
/**
 * Guards the declared runtime minimums against drift.
 *
 * Regression guard for issue #5: the repo declared PHP 7.4 in four places
 * while CI only exercised 8.3+, and commit f370337 changed the CI matrix
 * without touching any declaration — nothing noticed. These tests pin every
 * place that states a runtime floor to a single expected value, so raising one
 * again is impossible without updating all of them together (and this test).
 *
 * The block-theme-only release extended the same treatment to WordPress and
 * WooCommerce. Both floors were raised because the block and Interactivity APIs
 * the storefront now depends on require them, and a floor is only a promise if
 * every declaration agrees and CI actually runs against it.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Declared PHP minimum consistency tests.
 */
class Php_Requirement_Declarations_Test extends WP_UnitTestCase {

	/**
	 * The single source of truth for the declared PHP minimum.
	 */
	const EXPECTED_PHP_MINIMUM = '8.3';

	/**
	 * The single source of truth for the declared WordPress minimum.
	 */
	const EXPECTED_WP_MINIMUM = '7.0';

	/**
	 * The single source of truth for the declared WooCommerce minimum.
	 */
	const EXPECTED_WC_MINIMUM = '10.9';

	/**
	 * Absolute path to the plugin root.
	 *
	 * @return string
	 */
	private function plugin_dir() {
		return dirname( __DIR__ );
	}

	/**
	 * The plugin header declares the expected minimum.
	 */
	public function test_plugin_header_requires_php() {
		$header = file_get_contents( $this->plugin_dir() . '/shift64-woo-search.php' );
		$this->assertMatchesRegularExpression(
			'/^\s*\*\s*Requires PHP:\s*' . preg_quote( self::EXPECTED_PHP_MINIMUM, '/' ) . '\s*$/m',
			$header,
			'Plugin header "Requires PHP" must match the declared minimum.'
		);
	}

	/**
	 * The readme.txt file declares the expected minimum.
	 */
	public function test_readme_requires_php() {
		$readme = file_get_contents( $this->plugin_dir() . '/readme.txt' );
		$this->assertMatchesRegularExpression(
			'/^Requires PHP:\s*' . preg_quote( self::EXPECTED_PHP_MINIMUM, '/' ) . '\s*$/m',
			$readme,
			'readme.txt "Requires PHP" must match the declared minimum.'
		);
	}

	/**
	 * The composer.json file requires the expected minimum and pins the platform to it.
	 */
	public function test_composer_php_requirement_and_platform_pin() {
		$composer = json_decode( file_get_contents( $this->plugin_dir() . '/composer.json' ), true );

		$this->assertSame(
			'>=' . self::EXPECTED_PHP_MINIMUM,
			$composer['require']['php'],
			'composer.json require.php must match the declared minimum.'
		);
		$this->assertSame(
			self::EXPECTED_PHP_MINIMUM . '.0',
			$composer['config']['platform']['php'],
			'composer.json config.platform.php must pin the declared minimum.'
		);
	}

	/**
	 * The phpcs ruleset machine-checks the same floor via PHPCompatibility.
	 */
	public function test_phpcs_test_version_matches() {
		$ruleset = file_get_contents( $this->plugin_dir() . '/.phpcs.xml.dist' );
		$this->assertStringContainsString(
			'<config name="testVersion" value="' . self::EXPECTED_PHP_MINIMUM . '-"/>',
			$ruleset,
			'.phpcs.xml.dist testVersion must match the declared minimum.'
		);
		$this->assertStringContainsString(
			'<rule ref="PHPCompatibilityWP"/>',
			$ruleset,
			'.phpcs.xml.dist must run the PHPCompatibilityWP standard.'
		);
	}

	/**
	 * The CI matrix includes the declared minimum, and tests nothing below it.
	 */
	public function test_ci_matrix_agrees_with_declared_minimum() {
		$workflow = file_get_contents( $this->plugin_dir() . '/.github/workflows/release.yml' );
		$this->assertSame(
			1,
			preg_match( '/php-version:\s*\[([^\]]+)\]/', $workflow, $matches ),
			'CI workflow must define a php-version matrix.'
		);

		$versions = array_map(
			static function ( $version ) {
				return trim( $version, " \"'" );
			},
			explode( ',', $matches[1] )
		);

		$this->assertContains(
			self::EXPECTED_PHP_MINIMUM,
			$versions,
			'CI must test the declared minimum PHP version.'
		);
		foreach ( $versions as $version ) {
			$this->assertTrue(
				version_compare( $version, self::EXPECTED_PHP_MINIMUM, '>=' ),
				"CI tests PHP {$version}, which is below the declared minimum — either restore support or drop it from the matrix."
			);
		}
	}

	/**
	 * The plugin header declares the WordPress and WooCommerce minimums.
	 *
	 * `Requires at least` is what WordPress itself enforces on install and
	 * update; `WC requires at least` is what WooCommerce reads to warn a merchant
	 * before the plugin misbehaves.
	 */
	public function test_plugin_header_declares_wordpress_and_woocommerce_minimums() {
		$header = file_get_contents( $this->plugin_dir() . '/shift64-woo-search.php' );

		$this->assertMatchesRegularExpression(
			'/^\s*\*\s*Requires at least:\s*' . preg_quote( self::EXPECTED_WP_MINIMUM, '/' ) . '\s*$/m',
			$header,
			'Plugin header "Requires at least" must match the declared WordPress minimum.'
		);
		$this->assertMatchesRegularExpression(
			'/^\s*\*\s*WC requires at least:\s*' . preg_quote( self::EXPECTED_WC_MINIMUM, '/' ) . '\s*$/m',
			$header,
			'Plugin header "WC requires at least" must match the declared WooCommerce minimum.'
		);
	}

	/**
	 * The readme.txt header declares the same two minimums.
	 */
	public function test_readme_declares_wordpress_and_woocommerce_minimums() {
		$readme = file_get_contents( $this->plugin_dir() . '/readme.txt' );

		$this->assertMatchesRegularExpression(
			'/^Requires at least:\s*' . preg_quote( self::EXPECTED_WP_MINIMUM, '/' ) . '\s*$/m',
			$readme,
			'readme.txt "Requires at least" must match the declared WordPress minimum.'
		);
		$this->assertMatchesRegularExpression(
			'/^WC requires at least:\s*' . preg_quote( self::EXPECTED_WC_MINIMUM, '/' ) . '\s*$/m',
			$readme,
			'readme.txt "WC requires at least" must match the declared WooCommerce minimum.'
		);
	}

	/**
	 * The readme.txt header never claims testing below the version it requires.
	 */
	public function test_readme_tested_up_to_is_not_below_the_minimum() {
		$readme = file_get_contents( $this->plugin_dir() . '/readme.txt' );

		$this->assertSame(
			1,
			preg_match( '/^Tested up to:\s*(\S+)\s*$/m', $readme, $matches ),
			'readme.txt must declare "Tested up to".'
		);
		$this->assertTrue(
			version_compare( $matches[1], self::EXPECTED_WP_MINIMUM, '>=' ),
			sprintf(
				'readme.txt claims testing up to WordPress %s, below the declared minimum of %s.',
				$matches[1],
				self::EXPECTED_WP_MINIMUM
			)
		);
	}

	/**
	 * The prose documentation states the same floors as the machine-readable headers.
	 *
	 * A merchant reads the README and the docs site, not the plugin header, so a
	 * disagreement between them is what actually strands somebody mid-upgrade.
	 */
	public function test_documentation_states_the_same_minimums() {
		$sources = array(
			'/README.md',
			'/docs/src/content/docs/getting-started/requirements.mdx',
			'/BACKWARD_COMPATIBILITY.md',
		);

		foreach ( $sources as $relative ) {
			$path = $this->plugin_dir() . $relative;
			$this->assertFileExists( $path );

			$contents = file_get_contents( $path );

			$this->assertMatchesRegularExpression(
				'/WordPress\D{0,20}' . preg_quote( self::EXPECTED_WP_MINIMUM, '/' ) . '/',
				$contents,
				$relative . ' must state the declared WordPress minimum.'
			);
			$this->assertMatchesRegularExpression(
				'/WooCommerce\D{0,20}' . preg_quote( self::EXPECTED_WC_MINIMUM, '/' ) . '/',
				$contents,
				$relative . ' must state the declared WooCommerce minimum.'
			);
		}
	}

	/**
	 * CI runs the declared WordPress floor, not only the current release.
	 *
	 * Testing exclusively against the latest WordPress makes the declared minimum
	 * an untested claim: the release would state 7.0 while every job ran 7.1 or
	 * later. One matrix entry pins the floor so the claim is exercised.
	 */
	public function test_ci_matrix_exercises_the_declared_wordpress_minimum() {
		$workflow = file_get_contents( $this->plugin_dir() . '/.github/workflows/release.yml' );

		$this->assertMatchesRegularExpression(
			'/wp-version:\s*"' . preg_quote( self::EXPECTED_WP_MINIMUM, '/' ) . '"/',
			$workflow,
			'CI must run one job against the declared WordPress minimum.'
		);
		$this->assertStringContainsString(
			'wp-version: ["latest"]',
			$workflow,
			'CI must also track the current WordPress release.'
		);
	}
}
