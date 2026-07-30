<?php
/**
 * Guards the declared license claim against drift and mis-attribution.
 *
 * Regression guard for the licensing attribution correction: LICENSE was
 * copied verbatim from WordPress core at c821b5b and never adapted, so it
 * named the wrong project and copyright holders while composer.json and
 * readme.txt declared GPLv2-or-later on Shift64's behalf, and neither plugin
 * header declared a license at all. These tests pin every place that states
 * the license claim to one expected value, so the repository can never again
 * say two different things about ownership depending on which file you open.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Declared license consistency tests.
 */
class License_Declarations_Test extends WP_UnitTestCase {

	/**
	 * The single source of truth for the human-readable license name.
	 */
	const EXPECTED_LICENSE = 'GPLv2 or later';

	/**
	 * The single source of truth for the license URI.
	 */
	const EXPECTED_LICENSE_URI = 'https://www.gnu.org/licenses/gpl-2.0.html';

	/**
	 * The SPDX identifier composer.json must declare for the same terms.
	 */
	const EXPECTED_SPDX_LICENSE = 'GPL-2.0-or-later';

	/**
	 * The copyright holder of record, as it appears in LICENSE.
	 */
	const EXPECTED_COPYRIGHT = 'Copyright (C) 2026 Shift64 (Mateusz Zadorożny)';

	/**
	 * The URI both plugin headers point at for Plugin URI and Author URI.
	 */
	const EXPECTED_SITE_URI = 'https://shift64.com';

	/**
	 * Absolute path to the plugin root.
	 *
	 * @return string
	 */
	private function plugin_dir() {
		return dirname( __DIR__ );
	}

	/**
	 * The plugin files that carry a WordPress plugin header.
	 *
	 * @return array<string,string> Label => repo-relative path.
	 */
	private function plugin_header_files() {
		return array(
			'shift64-woo-search.php'                      => 'shift64-woo-search.php',
			'mu-plugins/shift64-woo-search-bootstrap.php' => 'mu-plugins/shift64-woo-search-bootstrap.php',
		);
	}

	/**
	 * Reads a repo-relative file.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	private function read( $relative_path ) {
		return file_get_contents( $this->plugin_dir() . '/' . $relative_path );
	}

	/**
	 * LICENSE no longer claims to be WordPress core's own license file.
	 */
	public function test_license_file_does_not_attribute_wordpress() {
		$license = $this->read( 'LICENSE' );

		$this->assertStringNotContainsString(
			'WordPress - Web publishing software',
			$license,
			'LICENSE must not name WordPress as the licensed project.'
		);
		$this->assertStringNotContainsString(
			'Copyright 2011-2026 by the contributors',
			$license,
			'LICENSE must not attribute copyright to the WordPress contributors.'
		);
	}

	/**
	 * LICENSE attributes copyright to Shift64 and carries the GPL-2.0 text.
	 */
	public function test_license_file_attributes_shift64_and_carries_gpl2() {
		$license = $this->read( 'LICENSE' );

		$this->assertStringContainsString(
			self::EXPECTED_COPYRIGHT,
			$license,
			'LICENSE must attribute copyright to Shift64 (Mateusz Zadorożny).'
		);
		$this->assertStringContainsString(
			'GNU GENERAL PUBLIC LICENSE',
			$license,
			'LICENSE must carry the GNU General Public License text.'
		);
		$this->assertStringContainsString(
			'Version 2, June 1991',
			$license,
			'LICENSE must carry version 2 of the GPL, matching the declared terms.'
		);
		$this->assertStringContainsString(
			'END OF TERMS AND CONDITIONS',
			$license,
			'LICENSE must contain the complete GPL terms, not an excerpt.'
		);
	}

	/**
	 * LICENSE grants version 2 or, at the recipient's option, any later version.
	 */
	public function test_license_file_grants_version_2_or_later() {
		$this->assertStringContainsString(
			'either version 2 of the License, or',
			$this->read( 'LICENSE' ),
			'LICENSE must grant "version 2 or (at your option) any later version" to match the declared terms.'
		);
	}

	/**
	 * Every plugin header declares the expected license name and URI.
	 */
	public function test_plugin_headers_declare_the_license() {
		foreach ( $this->plugin_header_files() as $label => $path ) {
			$contents = $this->read( $path );

			$this->assertMatchesRegularExpression(
				'/^ \* License: ' . preg_quote( self::EXPECTED_LICENSE, '/' ) . '$/m',
				$contents,
				"{$label} must declare the expected License in its plugin header."
			);
			$this->assertMatchesRegularExpression(
				'/^ \* License URI: ' . preg_quote( self::EXPECTED_LICENSE_URI, '/' ) . '$/m',
				$contents,
				"{$label} must declare the expected License URI in its plugin header."
			);
		}
	}

	/**
	 * Every plugin header points back at shift64.com.
	 */
	public function test_plugin_headers_declare_the_project_uris() {
		foreach ( $this->plugin_header_files() as $label => $path ) {
			$contents = $this->read( $path );

			$this->assertMatchesRegularExpression(
				'/^ \* Plugin URI: ' . preg_quote( self::EXPECTED_SITE_URI, '/' ) . '$/m',
				$contents,
				"{$label} must declare Plugin URI so the plugin points a user back at shift64.com."
			);
			$this->assertMatchesRegularExpression(
				'/^ \* Author URI: ' . preg_quote( self::EXPECTED_SITE_URI, '/' ) . '$/m',
				$contents,
				"{$label} must declare Author URI so the plugin points a user back at shift64.com."
			);
		}
	}

	/**
	 * The readme.txt file declares the same license name and URI as the headers.
	 */
	public function test_readme_declares_the_same_license() {
		$readme = $this->read( 'readme.txt' );

		$this->assertMatchesRegularExpression(
			'/^License: ' . preg_quote( self::EXPECTED_LICENSE, '/' ) . '$/m',
			$readme,
			'readme.txt must declare the same License as the plugin headers.'
		);
		$this->assertMatchesRegularExpression(
			'/^License URI: ' . preg_quote( self::EXPECTED_LICENSE_URI, '/' ) . '$/m',
			$readme,
			'readme.txt must declare the same License URI as the plugin headers.'
		);
	}

	/**
	 * The composer.json file declares the SPDX identifier for the same terms.
	 */
	public function test_composer_declares_the_same_terms() {
		$composer = json_decode( $this->read( 'composer.json' ), true );

		$this->assertSame(
			self::EXPECTED_SPDX_LICENSE,
			$composer['license'],
			'composer.json must declare the SPDX identifier matching the declared license.'
		);
	}

	/**
	 * The version-sync anchor build-release.sh depends on survives header edits.
	 *
	 * The build-release.sh script rewrites the plugin version with a regex
	 * anchored on `^ \* Version: `. Adding header fields above or below that
	 * line is safe; splitting, duplicating, or reflowing it silently breaks
	 * release builds.
	 */
	public function test_plugin_headers_keep_a_single_version_sync_anchor() {
		foreach ( $this->plugin_header_files() as $label => $path ) {
			$this->assertSame(
				1,
				preg_match_all( '/^ \* Version: .+$/m', $this->read( $path ) ),
				"{$label} must carry exactly one line-anchored ' * Version: ' header for build-release.sh to sync."
			);
		}
	}
}
