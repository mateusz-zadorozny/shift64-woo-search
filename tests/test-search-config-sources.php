<?php
/**
 * Every search surface must answer on the store's stored configuration.
 *
 * Four call sites built `Shift64_Woo_Search_Query` with no config, so they ran
 * on the class defaults instead: the Product Collection block (the results page
 * on a block theme), both taxonomy-archive paths, and the facet count provider.
 * That was invisible while the class defaults equalled the values
 * `set_default_options()` seeds. Once the defaults moved to `AND` + `mixed`
 * while upgraded stores kept `OR` + `strict_first` stored, the surfaces split:
 * the dropdown and the archive answered on the store's settings and the results
 * page answered on the new defaults, for the same query, in the same request.
 *
 * @package Shift64_Woo_Search
 */

/**
 * Config plumbing: one reader, and no surface bypassing it.
 */
class Search_Config_Sources_Test extends WP_UnitTestCase {

	/**
	 * Production directories that run inside a full WordPress request.
	 *
	 * The SHORTINIT endpoint is deliberately excluded: it never boots the
	 * options API and reads the same values from generated constants.
	 *
	 * @var string[]
	 */
	private const SCANNED_DIRS = array( 'includes', 'admin', 'cli' );

	/**
	 * Every PHP file under the scanned directories.
	 *
	 * @return string[]
	 */
	private function production_files() {
		$root  = dirname( __DIR__ );
		$files = array();

		foreach ( self::SCANNED_DIRS as $dir ) {
			$path = $root . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	/**
	 * A surface that skips the config argument silently answers on the class
	 * defaults, which are not what the merchant configured.
	 */
	public function test_no_surface_builds_a_query_without_the_stored_config() {
		$offenders = array();

		foreach ( $this->production_files() as $file ) {
			$contents = file_get_contents( $file );
			if ( preg_match_all( '/new\s+Shift64_Woo_Search_Query\s*\(([^)]*)\)/', $contents, $matches ) ) {
				foreach ( $matches[1] as $args ) {
					// One argument means the connector alone, with no config.
					if ( false === strpos( $args, ',' ) ) {
						$offenders[] = basename( $file ) . ': new Shift64_Woo_Search_Query(' . trim( $args ) . ')';
					}
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Pass Shift64_Woo_Search_Settings::search_config() so the surface answers on the store's settings:\n"
				. implode( "\n", $offenders )
		);
	}

	/**
	 * The shared reader has to actually read, or passing it changes nothing.
	 */
	public function test_search_config_reflects_stored_options() {
		update_option( 'shift64_woo_search_logic', 'OR' );
		update_option( 'shift64_woo_search_strategy', 'strict_first' );
		update_option( 'shift64_woo_search_fallback_trigger', 'no_results' );

		$config = Shift64_Woo_Search_Settings::search_config();

		$this->assertSame( 'OR', $config['logic'] );
		$this->assertSame( 'strict_first', $config['strategy'] );
		$this->assertSame( 'no_results', $config['fallback_trigger'] );
	}

	/**
	 * With nothing stored — a fresh install — the reader hands back the
	 * defaults this release ships, matching set_default_options().
	 */
	public function test_search_config_falls_back_to_the_shipped_defaults() {
		delete_option( 'shift64_woo_search_logic' );
		delete_option( 'shift64_woo_search_strategy' );

		$config = Shift64_Woo_Search_Settings::search_config();

		$this->assertSame( 'AND', $config['logic'] );
		$this->assertSame( 'mixed', $config['strategy'] );
	}

	/**
	 * A query built from the reader must behave as the stored settings say —
	 * the split showed up as one surface running a different retrieval logic
	 * than another for the same query.
	 */
	public function test_a_query_built_from_the_reader_uses_the_stored_logic() {
		update_option( 'shift64_woo_search_logic', 'OR' );

		$redis = $this->getMockBuilder( Shift64_Woo_Search_Redis::class )
			->disableOriginalConstructor()
			->getMock();
		$redis->method( 'get_prefix' )->willReturn( 'shift64_woo_search' );
		$redis->method( 'get_index_name' )->willReturn( 'shift64_woo_search_product_idx' );
		$redis->method( 'raw_command' )->willReturn( false );

		$query = new Shift64_Woo_Search_Query( $redis, Shift64_Woo_Search_Settings::search_config() );

		$this->assertStringContainsString(
			'(nova*|athena*)',
			$query->build_strict_query( array( 'nova', 'athena' ) ),
			'Stored OR logic must reach the query builder.'
		);
	}
}
