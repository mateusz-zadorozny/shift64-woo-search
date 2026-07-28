<?php
/**
 * Tests for the demo product generator's catalog and combinatorics.
 *
 * @package Shift64_Woo_Search
 */

if ( ! class_exists( 'Shift64_Woo_Search_Demo_Catalog' ) ) {
	require_once dirname( __DIR__ ) . '/bin/demo-product-catalog.php';
}

/**
 * Covers argument parsing, name combinatorics, and SKU generation.
 */
class Test_Shift64_Woo_Search_Demo_Product_Catalog extends WP_UnitTestCase {

	/**
	 * Defaults follow the 50k scaling spec: mixed mode, full catalog, batch 1000.
	 */
	public function test_default_options() {
		$options = Shift64_Woo_Search_Demo_Catalog::parse_args( array() );

		$this->assertSame( 48, $options['count'] );
		$this->assertSame( 'mixed', $options['mode'] );
		$this->assertSame( 'all', $options['catalog'] );
		$this->assertSame( 1000, $options['batch'] );
		$this->assertSame( 6464, $options['seed'] );
		$this->assertFalse( $options['reset'] );
		$this->assertFalse( $options['reset_only'] );
		$this->assertFalse( $options['variation_skus'] );
	}

	/**
	 * `reset-only` is a standalone teardown switch, independent of `reset`.
	 */
	public function test_parses_reset_only_flag() {
		$options = Shift64_Woo_Search_Demo_Catalog::parse_args( array( 'reset-only' ) );

		$this->assertTrue( $options['reset_only'] );
		$this->assertFalse( $options['reset'] );
	}

	/**
	 * `reset` on its own stays a wipe-then-seed modifier and never implies teardown.
	 */
	public function test_reset_does_not_imply_reset_only() {
		$options = Shift64_Woo_Search_Demo_Catalog::parse_args( array( 'reset' ) );

		$this->assertTrue( $options['reset'] );
		$this->assertFalse( $options['reset_only'] );
	}

	/**
	 * `reset-only` ignores `count`, so the other options still parse alongside it.
	 */
	public function test_reset_only_combines_with_other_options() {
		$options = Shift64_Woo_Search_Demo_Catalog::parse_args( array( 'reset-only', 'batch=50' ) );

		$this->assertTrue( $options['reset_only'] );
		$this->assertSame( 50, $options['batch'] );
	}

	/**
	 * The full high-volume invocation from the spec parses.
	 */
	public function test_parses_high_volume_invocation() {
		$options = Shift64_Woo_Search_Demo_Catalog::parse_args(
			array( 'count=50000', 'mode=mixed', 'catalog=all', 'batch=1000', 'seed=6464', 'reset', 'variation-skus' )
		);

		$this->assertSame( 50000, $options['count'] );
		$this->assertSame( 1000, $options['batch'] );
		$this->assertTrue( $options['reset'] );
		$this->assertTrue( $options['variation_skus'] );
	}

	/**
	 * Counts up to 100,000 are accepted; the old 1,000 ceiling is gone.
	 */
	public function test_accepts_counts_up_to_one_hundred_thousand() {
		$this->assertSame( 100000, Shift64_Woo_Search_Demo_Catalog::parse_args( array( 'count=100000' ) )['count'] );
		$this->assertSame( 1001, Shift64_Woo_Search_Demo_Catalog::parse_args( array( 'count=1001' ) )['count'] );
	}

	/**
	 * Out-of-range and unknown arguments are rejected.
	 *
	 * @dataProvider invalid_argument_provider
	 *
	 * @param array $raw_args Arguments to parse.
	 */
	public function test_rejects_invalid_arguments( $raw_args ) {
		$this->expectException( InvalidArgumentException::class );
		Shift64_Woo_Search_Demo_Catalog::parse_args( $raw_args );
	}

	/**
	 * Invalid argument sets.
	 *
	 * @return array<string,array{0:array}>
	 */
	public function invalid_argument_provider() {
		return array(
			'count too low'      => array( array( 'count=0' ) ),
			'count too high'     => array( array( 'count=100001' ) ),
			'batch too low'      => array( array( 'batch=10' ) ),
			'batch too high'     => array( array( 'batch=5001' ) ),
			'seed not positive'  => array( array( 'seed=0' ) ),
			'unknown mode'       => array( array( 'mode=random' ) ),
			'unknown catalog'    => array( array( 'catalog=toys' ) ),
			'unknown option'     => array( array( 'colour=red' ) ),
			'bare unknown token' => array( array( 'purge' ) ),
			'reset-only=value'   => array( array( 'reset-only=1' ) ),
		);
	}

	/**
	 * Every vertical exists and the combined name space clears five million.
	 */
	public function test_name_space_exceeds_five_million_combinations() {
		$this->assertSame( array( 'apparel', 'tech', 'home', 'beauty' ), Shift64_Woo_Search_Demo_Catalog::vertical_keys() );
		$this->assertGreaterThan( 5000000, Shift64_Woo_Search_Demo_Catalog::total_name_space_size() );
	}

	/**
	 * Every item points at a category the vertical declares.
	 */
	public function test_items_reference_declared_categories() {
		foreach ( Shift64_Woo_Search_Demo_Catalog::verticals() as $key => $data ) {
			foreach ( $data['items'] as $item ) {
				$this->assertArrayHasKey(
					$item['category'],
					$data['categories'],
					sprintf( 'Vertical %s item %s references an undeclared category.', $key, $item['name'] )
				);
			}
			$this->assertGreaterThanOrEqual( 4, count( $data['variation']['values'] ) );
			$this->assertLessThanOrEqual( 6, count( $data['variation']['values'] ) );
		}
	}

	/**
	 * A large run produces no duplicate names and no duplicate SKUs.
	 */
	public function test_large_run_produces_unique_names_and_skus() {
		$count = 20000;
		$seed  = 6464;
		$names = array();
		$skus  = array();

		for ( $index = 0; $index < $count; ++$index ) {
			$vertical    = Shift64_Woo_Search_Demo_Catalog::vertical_for_index( 'all', $index );
			$ordinal     = Shift64_Woo_Search_Demo_Catalog::ordinal_for_index( 'all', $index );
			$combination = Shift64_Woo_Search_Demo_Catalog::build_combination( $vertical, $ordinal, $seed );

			$names[ Shift64_Woo_Search_Demo_Catalog::build_name( $combination ) ]           = true;
			$skus[ Shift64_Woo_Search_Demo_Catalog::build_sku( $vertical, $seed, $index ) ] = true;
		}

		$this->assertCount( $count, $names );
		$this->assertCount( $count, $skus );
	}

	/**
	 * A single-vertical run stays inside that vertical and is also collision free.
	 */
	public function test_single_vertical_run_is_unique() {
		$count = 5000;
		$names = array();

		for ( $index = 0; $index < $count; ++$index ) {
			$vertical = Shift64_Woo_Search_Demo_Catalog::vertical_for_index( 'tech', $index );
			$this->assertSame( 'tech', $vertical );
			$ordinal     = Shift64_Woo_Search_Demo_Catalog::ordinal_for_index( 'tech', $index );
			$combination = Shift64_Woo_Search_Demo_Catalog::build_combination( $vertical, $ordinal, 42 );
			$names[ Shift64_Woo_Search_Demo_Catalog::build_name( $combination ) ] = true;
		}

		$this->assertCount( $count, $names );
	}

	/**
	 * `all` rotates evenly across the four verticals.
	 */
	public function test_all_catalog_balances_verticals() {
		$counts = array();
		for ( $index = 0; $index < 400; ++$index ) {
			$vertical            = Shift64_Woo_Search_Demo_Catalog::vertical_for_index( 'all', $index );
			$counts[ $vertical ] = isset( $counts[ $vertical ] ) ? $counts[ $vertical ] + 1 : 1;
		}

		$this->assertSame(
			array(
				'apparel' => 100,
				'tech'    => 100,
				'home'    => 100,
				'beauty'  => 100,
			),
			$counts
		);
	}

	/**
	 * Short runs vary every name segment, not just the last one.
	 */
	public function test_short_run_varies_every_segment() {
		$segments = array(
			'prefix' => array(),
			'series' => array(),
			'item'   => array(),
			'spec'   => array(),
			'finish' => array(),
		);

		for ( $ordinal = 0; $ordinal < 24; ++$ordinal ) {
			$combination = Shift64_Woo_Search_Demo_Catalog::build_combination( 'apparel', $ordinal, 6464 );
			foreach ( array_keys( $segments ) as $segment ) {
				$segments[ $segment ][ $combination[ $segment ] ] = true;
			}
		}

		foreach ( $segments as $segment => $seen ) {
			$this->assertGreaterThan(
				4,
				count( $seen ),
				sprintf( 'Segment %s barely varies across a 24-product run.', $segment )
			);
		}
	}

	/**
	 * The same seed reproduces the same catalog; a different seed does not.
	 */
	public function test_generation_is_deterministic_per_seed() {
		$first  = Shift64_Woo_Search_Demo_Catalog::build_combination( 'apparel', 17, 6464 );
		$second = Shift64_Woo_Search_Demo_Catalog::build_combination( 'apparel', 17, 6464 );
		$other  = Shift64_Woo_Search_Demo_Catalog::build_combination( 'apparel', 17, 99 );

		$this->assertSame( $first, $second );
		$this->assertNotSame( $first, $other );
	}

	/**
	 * SKUs follow the DEMO-[VERTICAL]-[SEED]-[ID] shape.
	 */
	public function test_sku_shape() {
		$this->assertSame( 'DEMO-APP-64-000001', Shift64_Woo_Search_Demo_Catalog::build_sku( 'apparel', 6464, 0 ) );
		$this->assertSame( 'DEMO-TEC-64-050000', Shift64_Woo_Search_Demo_Catalog::build_sku( 'tech', 6464, 49999 ) );
		$this->assertSame( 'DEMO-HOM-01-000123', Shift64_Woo_Search_Demo_Catalog::build_sku( 'home', 101, 122 ) );
		$this->assertSame( 'DEMO-BEA-42-000002', Shift64_Woo_Search_Demo_Catalog::build_sku( 'beauty', 42, 1 ) );
	}

	/**
	 * Mixed mode splits roughly 75/25 and spreads variable products across
	 * every vertical.
	 *
	 * Regression guard: deciding the mode from the raw index rather than the
	 * per-vertical ordinal makes "variable" and "apparel" the same condition
	 * under `all` (both are index % 4 === 0), so every variable product lands in
	 * one vertical and the Capacity/Material variation attributes are never
	 * exercised as variations.
	 */
	public function test_mixed_mode_spreads_variable_products_across_verticals() {
		$count    = 400;
		$variable = array();
		$total    = 0;

		for ( $index = 0; $index < $count; $index++ ) {
			$vertical = Shift64_Woo_Search_Demo_Catalog::vertical_for_index( 'all', $index );
			if ( 'variable' === Shift64_Woo_Search_Demo_Catalog::product_mode( 'mixed', 'all', $index ) ) {
				$variable[ $vertical ] = isset( $variable[ $vertical ] ) ? $variable[ $vertical ] + 1 : 1;
				++$total;
			}
		}

		foreach ( Shift64_Woo_Search_Demo_Catalog::vertical_keys() as $vertical ) {
			$this->assertArrayHasKey(
				$vertical,
				$variable,
				sprintf( 'Vertical %s got no variable products in mixed mode.', $vertical )
			);
			$this->assertSame( $count / 16, $variable[ $vertical ], 'Variable products are unevenly spread.' );
		}

		$this->assertSame( $count / 4, $total, 'Mixed mode should make a quarter of the catalog variable.' );
	}

	/**
	 * A single-vertical run keeps the same 75/25 split.
	 */
	public function test_mixed_mode_split_holds_for_a_single_vertical() {
		$variable = 0;
		for ( $index = 0; $index < 400; $index++ ) {
			if ( 'variable' === Shift64_Woo_Search_Demo_Catalog::product_mode( 'mixed', 'tech', $index ) ) {
				++$variable;
			}
		}

		$this->assertSame( 100, $variable );
	}

	/**
	 * Explicit modes are returned verbatim, whatever the index.
	 */
	public function test_explicit_modes_ignore_the_index() {
		foreach ( array( 0, 1, 2, 3, 17, 400 ) as $index ) {
			$this->assertSame( 'variable', Shift64_Woo_Search_Demo_Catalog::product_mode( 'variable', 'all', $index ) );
			$this->assertSame( 'simple', Shift64_Woo_Search_Demo_Catalog::product_mode( 'simple', 'all', $index ) );
		}
	}

	/**
	 * An unknown vertical is a programming error, not a silent fallback.
	 */
	public function test_unknown_vertical_throws() {
		$this->expectException( InvalidArgumentException::class );
		Shift64_Woo_Search_Demo_Catalog::vertical( 'toys' );
	}
}
