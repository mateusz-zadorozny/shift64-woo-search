<?php
/**
 * Guards the phpcs ruleset against losing whole trees again.
 *
 * Regression guard for issue #25: `.phpcs.xml.dist` excluded `/bin/` and
 * `/tests/` outright, so `vendor/bin/phpcs` — step 2 of the validation gate —
 * reported green while never looking at roughly 1,100 lines of PHP that
 * landed there. Excluding a sniff for those trees is still allowed, but only
 * per rule: a top-level `<exclude-pattern>` takes every sniff off the file at
 * once and re-creates the blind spot silently.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Phpcs ruleset coverage tests.
 */
class Phpcs_Ruleset_Coverage_Test extends WP_UnitTestCase {

	/**
	 * Trees that must stay under the standards run.
	 *
	 * @var string[]
	 */
	const COVERED_TREES = array( 'bin', 'tests' );

	/**
	 * The parsed ruleset.
	 *
	 * @return SimpleXMLElement
	 */
	private function ruleset() {
		$path = dirname( __DIR__ ) . '/.phpcs.xml.dist';
		$this->assertFileExists( $path, 'The phpcs ruleset must exist.' );

		$xml = simplexml_load_string( (string) file_get_contents( $path ) );
		$this->assertNotFalse( $xml, '.phpcs.xml.dist must be well-formed XML.' );

		return $xml;
	}

	/**
	 * No top-level exclude-pattern may take a covered tree out of the run.
	 */
	public function test_covered_trees_are_not_excluded_wholesale() {
		foreach ( $this->ruleset()->{'exclude-pattern'} as $pattern ) {
			$value = trim( (string) $pattern );

			foreach ( self::COVERED_TREES as $tree ) {
				$this->assertDoesNotMatchRegularExpression(
					'#(^|/)' . preg_quote( $tree, '#' ) . '(/|$)#',
					$value,
					sprintf(
						'.phpcs.xml.dist must not exclude the whole %s/ tree — scope the exclusion to a single <rule> instead.',
						$tree
					)
				);
			}
		}
	}

	/**
	 * Every exclusion for a covered tree must be scoped to one sniff.
	 */
	public function test_covered_tree_exclusions_are_rule_scoped() {
		$scoped = 0;

		foreach ( $this->ruleset()->rule as $rule ) {
			foreach ( $rule->{'exclude-pattern'} as $pattern ) {
				foreach ( self::COVERED_TREES as $tree ) {
					if ( false === strpos( (string) $pattern, '/' . $tree . '/' ) ) {
						continue;
					}

					$this->assertNotEmpty(
						(string) $rule['ref'],
						'A rule-scoped exclusion must name the sniff it switches off.'
					);
					++$scoped;
				}
			}
		}

		$this->assertGreaterThan(
			0,
			$scoped,
			'The ruleset is expected to narrow at least one sniff for bin/ or tests/; if that is no longer true, drop this assertion.'
		);
	}

	/**
	 * The extension filter keeps shell scripts in bin/ out of the run.
	 */
	public function test_only_php_files_are_scanned() {
		foreach ( $this->ruleset()->arg as $arg ) {
			if ( 'extensions' === (string) $arg['name'] ) {
				$this->assertSame( 'php', (string) $arg['value'] );
				return;
			}
		}

		$this->fail( '.phpcs.xml.dist must restrict the run to PHP files.' );
	}
}
