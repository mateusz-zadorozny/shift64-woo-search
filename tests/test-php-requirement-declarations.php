<?php
/**
 * Guards the declared PHP minimum against drift.
 *
 * Regression guard for issue #5: the repo declared PHP 7.4 in four places
 * while CI only exercised 8.3+, and commit f370337 changed the CI matrix
 * without touching any declaration — nothing noticed. These tests pin every
 * place that states the PHP floor to a single expected value, so raising it
 * again is impossible without updating all of them together (and this test).
 *
 * @package Shift64_Woo_Search
 */

class Php_Requirement_Declarations_Test extends WP_UnitTestCase {

	/**
	 * The single source of truth for the declared PHP minimum.
	 */
	const EXPECTED_PHP_MINIMUM = '8.3';

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
	 * readme.txt declares the expected minimum.
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
	 * composer.json requires the expected minimum and pins the platform to it.
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
}
