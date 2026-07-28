<?php
/**
 * Generate deterministic English-language WooCommerce demo products.
 *
 * Run through WP-CLI:
 *
 * wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=48
 * wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=50000 mode=mixed catalog=all batch=1000 seed=6464 reset variation-skus
 * wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=5000 catalog=tech mode=simple
 *
 * Products are drawn from four catalog verticals (apparel, tech, home,
 * beauty). Names use a five-segment tuple —
 * "[Prefix] [Series] [Item] [Spec] [Finish]" — which spans more than five
 * million combinations, so runs up to 100,000 parents stay collision free.
 * SKUs are deterministic: DEMO-[VERTICAL]-[SEED]-[ID].
 *
 * The catalog data and the combinatorics live in demo-product-catalog.php so
 * they can be unit tested without WP-CLI or WooCommerce.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This script must be executed with WP-CLI.\n" );
	return;
}

require_once __DIR__ . '/demo-product-catalog.php';

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Variable' ) ) {
	WP_CLI::error( 'WooCommerce must be active.' );
}

if ( ! class_exists( 'Shift64_Woo_Search_Demo_Product_Generator' ) ) {
	/**
	 * Creates a repeatable multi-vertical catalog for local search testing.
	 */
	class Shift64_Woo_Search_Demo_Product_Generator {

		/** Meta marker used to identify products owned by this generator. */
		const GENERATED_META_KEY = Shift64_Woo_Search_Demo_Catalog::GENERATED_META_KEY;

		/** @var array<string,mixed> Parsed CLI options. */
		private $options;

		/** @var int Number of variations created during the current run. */
		private $variation_count = 0;

		/** @var array<string,array<string,int>> Category term IDs keyed by vertical and category key. */
		private $category_ids = array();

		/** @var array<string,array<int,int>> Brand term IDs keyed by vertical. */
		private $brand_ids = array();

		/** @var array<string,array> Global attribute data keyed by attribute slug. */
		private $attributes = array();

		/**
		 * Constructor.
		 *
		 * @param array $options Options produced by Shift64_Woo_Search_Demo_Catalog::parse_args().
		 */
		public function __construct( $options ) {
			$this->options = $options;
		}

		/**
		 * Generate the catalog.
		 */
		public function run() {
			mt_srand( $this->options['seed'] );

			// Taxonomy and comment counts are recalculated once, after the run.
			wp_defer_term_counting( true );
			wp_defer_comment_counting( true );

			try {
				$this->generate();
			} finally {
				wp_defer_term_counting( false );
				wp_defer_comment_counting( false );
			}
		}

		/**
		 * Run the reset, taxonomy setup, and creation loop.
		 */
		private function generate() {
			if ( $this->options['reset'] ) {
				$this->delete_generated_products();
			}

			$this->warn_about_variation_volume();

			foreach ( $this->used_verticals() as $vertical ) {
				$this->category_ids[ $vertical ] = $this->ensure_categories( $vertical );
				$this->brand_ids[ $vertical ]    = $this->ensure_brands( $vertical );
				$this->ensure_vertical_attributes( $vertical );
			}

			$count    = $this->options['count'];
			$progress = \WP_CLI\Utils\make_progress_bar( 'Generating demo products', $count );
			$created  = 0;
			$skipped  = 0;

			for ( $index = 0; $index < $count; ++$index ) {
				$vertical    = Shift64_Woo_Search_Demo_Catalog::vertical_for_index( $this->options['catalog'], $index );
				$ordinal     = Shift64_Woo_Search_Demo_Catalog::ordinal_for_index( $this->options['catalog'], $index );
				$combination = Shift64_Woo_Search_Demo_Catalog::build_combination( $vertical, $ordinal, $this->options['seed'] );
				$sku         = Shift64_Woo_Search_Demo_Catalog::build_sku( $vertical, $this->options['seed'], $index );

				if ( wc_get_product_id_by_sku( $sku ) ) {
					++$skipped;
					$progress->tick();
					$this->maybe_flush( $index );
					continue;
				}

				if ( 'variable' === $this->product_mode( $index ) ) {
					$product_id = $this->create_variable_product( $combination, $sku, $index );
				} else {
					$product_id = $this->create_simple_product( $combination, $sku, $index );
				}

				$this->assign_brands( $product_id, $vertical, $index );

				++$created;
				$progress->tick();
				$this->maybe_flush( $index );
			}

			$progress->finish();

			WP_CLI::success(
				sprintf(
					'Demo catalog ready: %d products created, %d skipped, %d variations created.',
					$created,
					$skipped,
					$this->variation_count
				)
			);
			WP_CLI::log( 'Run `wp shift64-woo-search rebuild` to refresh the search index.' );
		}

		/**
		 * Resolve the product type for an index.
		 *
		 * In mixed mode roughly 75% of the catalog is simple and 25% variable,
		 * which keeps the total post count manageable on high-volume runs.
		 *
		 * @param int $index Zero-based product index.
		 * @return string
		 */
		private function product_mode( $index ) {
			if ( 'mixed' !== $this->options['mode'] ) {
				return $this->options['mode'];
			}
			return 0 === $index % 4 ? 'variable' : 'simple';
		}

		/**
		 * Verticals touched by this run.
		 *
		 * @return string[]
		 */
		private function used_verticals() {
			if ( 'all' === $this->options['catalog'] ) {
				return Shift64_Woo_Search_Demo_Catalog::vertical_keys();
			}
			return array( $this->options['catalog'] );
		}

		/**
		 * Warn when the run would create a very large number of variation posts.
		 */
		private function warn_about_variation_volume() {
			if ( 'simple' === $this->options['mode'] ) {
				return;
			}

			$parents    = 'variable' === $this->options['mode'] ? $this->options['count'] : intdiv( $this->options['count'], 4 );
			$max_terms  = 0;
			foreach ( $this->used_verticals() as $vertical ) {
				$data      = Shift64_Woo_Search_Demo_Catalog::vertical( $vertical );
				$max_terms = max( $max_terms, count( $data['variation']['values'] ) );
			}
			$estimate = $parents * $max_terms;

			if ( $estimate > Shift64_Woo_Search_Demo_Catalog::VARIATION_WARNING_THRESHOLD ) {
				WP_CLI::warning(
					sprintf(
						'This run may create around %d variation records. Consider mode=simple or a lower count if the database is constrained.',
						$estimate
					)
				);
			}
		}

		/**
		 * Flush object cache and collect cycles once per batch.
		 *
		 * @param int $index Zero-based product index.
		 */
		private function maybe_flush( $index ) {
			if ( 0 !== ( $index + 1 ) % $this->options['batch'] ) {
				return;
			}

			wp_cache_flush();
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		/**
		 * Delete only products created by this generator, in batches.
		 */
		private function delete_generated_products() {
			$batch   = $this->options['batch'];
			$deleted = 0;

			do {
				$product_ids = get_posts(
					array(
						'post_type'              => array( 'product', 'product_variation' ),
						'post_status'            => 'any',
						'posts_per_page'         => $batch,
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'update_post_term_cache' => false,
						'meta_key'               => self::GENERATED_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value'             => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);

				$removed = 0;
				foreach ( $product_ids as $product_id ) {
					$product = wc_get_product( $product_id );
					if ( $product ) {
						$product->delete( true );
					} else {
						wp_delete_post( $product_id, true );
					}
					if ( ! get_post( $product_id ) ) {
						++$removed;
					}
					++$deleted;
				}

				if ( ! empty( $product_ids ) && 0 === $removed ) {
					WP_CLI::warning( 'Reset stopped: a batch of generated products could not be deleted.' );
					break;
				}

				wp_cache_flush();
				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
			} while ( ! empty( $product_ids ) );

			if ( 0 === $deleted ) {
				WP_CLI::log( 'Reset requested: no previously generated products found.' );
				return;
			}

			WP_CLI::log( sprintf( 'Reset: deleted %d previously generated posts.', $deleted ) );
		}

		/**
		 * Create the category hierarchy of a vertical.
		 *
		 * @param string $vertical Vertical key.
		 * @return array<string,int> Category term IDs keyed by category key.
		 */
		private function ensure_categories( $vertical ) {
			$data   = Shift64_Woo_Search_Demo_Catalog::vertical( $vertical );
			$root   = $this->ensure_term( $data['root'], 'product_cat', 0 );
			$groups = array();
			$ids    = array();

			foreach ( $data['categories'] as $key => $path ) {
				list( $group, $leaf ) = $path;
				$parent               = $root;
				if ( '' !== $group ) {
					if ( ! isset( $groups[ $group ] ) ) {
						$groups[ $group ] = $this->ensure_term( $group, 'product_cat', $root );
					}
					$parent = $groups[ $group ];
				}
				$ids[ $key ] = $this->ensure_term( $leaf, 'product_cat', $parent );
			}

			return $ids;
		}

		/**
		 * Create the fictional brand hierarchy of a vertical.
		 *
		 * Each vertical keeps one parent brand with children so the indexer's
		 * brand ancestor-chain handling and the parent-brand filter are
		 * exercised locally.
		 *
		 * @param string $vertical Vertical key.
		 * @return array<int,int> Brand term IDs, or an empty array when the store
		 *                        has no product_brand taxonomy.
		 */
		private function ensure_brands( $vertical ) {
			if ( ! taxonomy_exists( 'product_brand' ) ) {
				WP_CLI::warning( 'Taxonomy product_brand is not registered (WooCommerce 9.4+ required) — skipping brand seeding.' );
				return array();
			}

			$data      = Shift64_Woo_Search_Demo_Catalog::vertical( $vertical );
			$brand_ids = array();

			foreach ( $data['brands'] as $parent_name => $children ) {
				$parent_id   = $this->ensure_term( $parent_name, 'product_brand', 0 );
				$brand_ids[] = $parent_id;
				foreach ( $children as $child_name ) {
					$brand_ids[] = $this->ensure_term( $child_name, 'product_brand', $parent_id );
				}
			}

			return $brand_ids;
		}

		/**
		 * Assign brands to a generated product.
		 *
		 * Distribution: ~80% one brand, ~10% two brands, ~10% none — enough to
		 * exercise the single, multi-brand, and brandless paths. Selection is
		 * derived from the product index rather than mt_rand() so it is
		 * reproducible and leaves the seeded random stream untouched.
		 *
		 * @param int    $product_id Product ID.
		 * @param string $vertical   Vertical key.
		 * @param int    $index      Zero-based product index.
		 */
		private function assign_brands( $product_id, $vertical, $index ) {
			$brand_ids = isset( $this->brand_ids[ $vertical ] ) ? $this->brand_ids[ $vertical ] : array();
			if ( empty( $brand_ids ) || ! $product_id ) {
				return;
			}

			$bucket = $index % 10;
			if ( 9 === $bucket ) {
				return; // Brandless.
			}

			$count    = count( $brand_ids );
			$assigned = array( $brand_ids[ ( $index * 7 ) % $count ] );
			if ( 8 === $bucket ) {
				$second = $brand_ids[ ( $index * 7 + 3 ) % $count ];
				if ( $second !== $assigned[0] ) {
					$assigned[] = $second;
				}
			}

			wp_set_object_terms( $product_id, $assigned, 'product_brand' );
		}

		/**
		 * Ensure that a taxonomy term exists.
		 *
		 * @param string $name     Term name.
		 * @param string $taxonomy Taxonomy name.
		 * @param int    $parent   Parent term ID.
		 * @return int Term ID.
		 */
		private function ensure_term( $name, $taxonomy, $parent = 0 ) {
			$existing = term_exists( $name, $taxonomy, $parent );
			if ( $existing ) {
				return (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
			}

			$created = wp_insert_term(
				$name,
				$taxonomy,
				array(
					'parent' => $parent,
				)
			);
			if ( is_wp_error( $created ) ) {
				WP_CLI::error( sprintf( 'Could not create %s term "%s": %s', $taxonomy, $name, $created->get_error_message() ) );
			}

			return (int) $created['term_id'];
		}

		/**
		 * Ensure the global attributes a vertical needs.
		 *
		 * Color carries the finish segment; the vertical's own variation and
		 * extra attributes cover size, material, capacity, and connectivity.
		 *
		 * @param string $vertical Vertical key.
		 */
		private function ensure_vertical_attributes( $vertical ) {
			$data = Shift64_Woo_Search_Demo_Catalog::vertical( $vertical );

			$this->ensure_attribute( 'Color', 'color', $data['finishes'] );
			$this->ensure_attribute( $data['variation']['label'], $data['variation']['slug'], $data['variation']['values'] );
			$this->ensure_attribute( $data['extra']['label'], $data['extra']['slug'], $data['extra']['values'] );
		}

		/**
		 * Ensure a global WooCommerce attribute and all of its terms exist.
		 *
		 * Results are memoized per run, and repeated calls merge newly seen
		 * values into the cached term map.
		 *
		 * @param string $label  Attribute label.
		 * @param string $slug   Attribute slug without the pa_ prefix.
		 * @param array  $values Attribute values in display order.
		 * @return array{id:int,taxonomy:string,terms:array<string,WP_Term>}
		 */
		private function ensure_attribute( $label, $slug, $values ) {
			$attribute_id = wc_attribute_taxonomy_id_by_name( $slug );
			if ( ! $attribute_id ) {
				$attribute_id = wc_create_attribute(
					array(
						'name'         => $label,
						'slug'         => $slug,
						'type'         => 'select',
						'order_by'     => 'menu_order',
						'has_archives' => false,
					)
				);
				if ( is_wp_error( $attribute_id ) ) {
					WP_CLI::error( sprintf( 'Could not create the %s attribute: %s', $label, $attribute_id->get_error_message() ) );
				}
				delete_transient( 'wc_attribute_taxonomies' );
				if ( class_exists( 'WC_Cache_Helper' ) ) {
					WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
				}
			}

			$taxonomy = wc_attribute_taxonomy_name( $slug );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy(
					$taxonomy,
					array( 'product' ),
					array(
						'hierarchical' => false,
						'public'       => false,
						'show_ui'      => false,
						'query_var'    => true,
						'rewrite'      => false,
					)
				);
			}

			$terms    = isset( $this->attributes[ $slug ]['terms'] ) ? $this->attributes[ $slug ]['terms'] : array();
			$position = count( $terms );
			foreach ( $values as $value ) {
				if ( isset( $terms[ $value ] ) ) {
					continue;
				}
				$term_id = $this->ensure_term( $value, $taxonomy, 0 );
				update_term_meta( $term_id, 'order_' . $taxonomy, $position );
				$terms[ $value ] = get_term( $term_id, $taxonomy );
				++$position;
			}

			$this->attributes[ $slug ] = array(
				'id'       => (int) $attribute_id,
				'taxonomy' => $taxonomy,
				'terms'    => $terms,
			);

			return $this->attributes[ $slug ];
		}

		/**
		 * Create a variable product whose variations follow the vertical's
		 * variation attribute (size, capacity, or material).
		 *
		 * @param array  $combination Name combination.
		 * @param string $sku         Parent SKU.
		 * @param int    $index       Zero-based product index.
		 * @return int Product ID.
		 */
		private function create_variable_product( $combination, $sku, $index ) {
			$data           = Shift64_Woo_Search_Demo_Catalog::vertical( $combination['vertical'] );
			$color_data     = $this->attributes['color'];
			$variation_data = $this->attributes[ $data['variation']['slug'] ];

			$product = new WC_Product_Variable();
			$this->apply_common_product_data( $product, $combination, $sku, $index );

			$color_term     = $color_data['terms'][ $combination['finish'] ];
			$variation_ids  = array();
			$variation_list = array();
			foreach ( $data['variation']['values'] as $value ) {
				$term                     = $variation_data['terms'][ $value ];
				$variation_ids[]          = $term->term_id;
				$variation_list[ $value ] = $term;
			}

			$product->set_attributes(
				array(
					$this->make_attribute( $color_data, array( $color_term->term_id ), true, false, 0 ),
					$this->make_attribute( $variation_data, $variation_ids, true, true, 1 ),
				)
			);
			$product_id = $product->save();

			foreach ( $variation_list as $value => $term ) {
				$variation_sku = $this->options['variation_skus'] ? $sku . '-' . strtoupper( $term->slug ) : '';
				if ( '' !== $variation_sku && wc_get_product_id_by_sku( $variation_sku ) ) {
					WP_CLI::warning( sprintf( 'Skipping variation with duplicate SKU "%s".', $variation_sku ) );
					continue;
				}

				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_status( 'publish' );

				try {
					if ( '' !== $variation_sku ) {
						$variation->set_sku( $variation_sku );
					}
					$variation->set_attributes( array( $variation_data['taxonomy'] => $term->slug ) );
					$variation->set_regular_price( wc_format_decimal( (float) $combination['price'], 2 ) );
					$variation->set_manage_stock( true );
					$variation->set_stock_quantity( mt_rand( 3, 40 ) );
					$variation->set_stock_status( 'instock' );
					$variation->set_description( sprintf( '%s in %s %s.', $product->get_name(), strtolower( $data['variation']['label'] ), $value ) );
					$variation->update_meta_data( self::GENERATED_META_KEY, 'yes' );
					$variation->save();
				} catch ( WC_Data_Exception $exception ) {
					if ( '' === $variation_sku || 'product_invalid_sku' !== $exception->getErrorCode() ) {
						throw $exception;
					}

					WP_CLI::warning( sprintf( 'Skipping variation with duplicate SKU "%s".', $variation_sku ) );
					continue;
				}

				++$this->variation_count;
			}

			WC_Product_Variable::sync( $product_id, true );
			wc_delete_product_transients( $product_id );

			return (int) $product_id;
		}

		/**
		 * Create a simple product with visible attributes.
		 *
		 * @param array  $combination Name combination.
		 * @param string $sku         Product SKU.
		 * @param int    $index       Zero-based product index.
		 * @return int Product ID.
		 */
		private function create_simple_product( $combination, $sku, $index ) {
			$data           = Shift64_Woo_Search_Demo_Catalog::vertical( $combination['vertical'] );
			$color_data     = $this->attributes['color'];
			$variation_data = $this->attributes[ $data['variation']['slug'] ];
			$extra_data     = $this->attributes[ $data['extra']['slug'] ];

			$product = new WC_Product_Simple();
			$this->apply_common_product_data( $product, $combination, $sku, $index );

			$color_term     = $color_data['terms'][ $combination['finish'] ];
			$variation_vals = $data['variation']['values'];
			$variation_term = $variation_data['terms'][ $variation_vals[ $index % count( $variation_vals ) ] ];
			$extra_vals     = $data['extra']['values'];
			$extra_term     = $extra_data['terms'][ $extra_vals[ $index % count( $extra_vals ) ] ];

			$product->set_attributes(
				array(
					$this->make_attribute( $color_data, array( $color_term->term_id ), true, false, 0 ),
					$this->make_attribute( $variation_data, array( $variation_term->term_id ), true, false, 1 ),
					$this->make_attribute( $extra_data, array( $extra_term->term_id ), true, false, 2 ),
				)
			);
			$product->set_regular_price( wc_format_decimal( (float) $combination['price'], 2 ) );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( mt_rand( 3, 40 ) );
			$product->set_stock_status( 'instock' );

			return (int) $product->save();
		}

		/**
		 * Set fields shared by simple and variable products.
		 *
		 * @param WC_Product $product     Product instance.
		 * @param array      $combination Name combination.
		 * @param string     $sku         Product SKU.
		 * @param int        $index       Zero-based product index.
		 */
		private function apply_common_product_data( $product, $combination, $sku, $index ) {
			$product_name = Shift64_Woo_Search_Demo_Catalog::build_name( $combination );
			$category_ids = $this->category_ids[ $combination['vertical'] ];

			$product->set_name( $product_name );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
			$product->set_sku( $sku );
			$product->set_category_ids( array( $category_ids[ $combination['category'] ] ) );
			$product->set_short_description(
				sprintf(
					'A %s %s in %s.',
					strtolower( $combination['spec'] ),
					strtolower( $combination['item'] ),
					strtolower( $combination['finish'] )
				)
			);
			$product->set_description(
				sprintf(
					'The %s belongs to the %s series and is built for everyday use. %s finish, %s specification.',
					$product_name,
					$combination['series'],
					$combination['finish'],
					$combination['spec']
				)
			);
			$product->set_menu_order( $index );
			$product->set_weight( wc_format_decimal( 0.2 + ( $index % 12 ) * 0.05, 2 ) );
			$product->update_meta_data( self::GENERATED_META_KEY, 'yes' );
		}

		/**
		 * Build a WooCommerce product attribute object.
		 *
		 * @param array $attribute_data Attribute metadata.
		 * @param array $term_ids       Selected term IDs.
		 * @param bool  $visible        Whether the attribute is customer-visible.
		 * @param bool  $variation      Whether the attribute creates variations.
		 * @param int   $position       Display position.
		 * @return WC_Product_Attribute
		 */
		private function make_attribute( $attribute_data, $term_ids, $visible, $variation, $position ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( $attribute_data['id'] );
			$attribute->set_name( $attribute_data['taxonomy'] );
			$attribute->set_options( array_map( 'intval', $term_ids ) );
			$attribute->set_position( $position );
			$attribute->set_visible( $visible );
			$attribute->set_variation( $variation );
			return $attribute;
		}
	}
}

/**
 * Parse eval-file arguments, reporting failures through WP-CLI.
 *
 * @param array $raw_args Arguments exposed by WP-CLI to eval-file.
 * @return array{count:int,mode:string,catalog:string,batch:int,seed:int,reset:bool,variation_skus:bool}
 */
function shift64_woo_search_parse_demo_product_args( $raw_args ) {
	try {
		return Shift64_Woo_Search_Demo_Catalog::parse_args( $raw_args );
	} catch ( InvalidArgumentException $exception ) {
		WP_CLI::error( $exception->getMessage() );
	}
}

$shift64_demo_raw_args = isset( $args ) && is_array( $args ) ? $args : array();
$shift64_demo_options  = shift64_woo_search_parse_demo_product_args( $shift64_demo_raw_args );
$shift64_demo_runner   = new Shift64_Woo_Search_Demo_Product_Generator( $shift64_demo_options );
$shift64_demo_runner->run();
