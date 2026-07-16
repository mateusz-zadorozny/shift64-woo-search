<?php
/**
 * Generate deterministic English-language WooCommerce demo products.
 *
 * Run through WP-CLI:
 *
 * wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=48 mode=variable seed=6464
 * wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=48 mode=mixed seed=6464 reset
 *
 * The generator creates one parent product per color. Color is a visible
 * global attribute, while size is the variation attribute. A generated name
 * therefore looks like "Athena T-Shirt Green", with XS-XXL variations.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This script must be executed with WP-CLI.\n" );
	return;
}

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Variable' ) ) {
	WP_CLI::error( 'WooCommerce must be active.' );
}

if ( ! class_exists( 'Shift64_Woo_Search_Demo_Product_Generator' ) ) {
	/**
	 * Creates a repeatable apparel catalog for local search testing.
	 */
	class Shift64_Woo_Search_Demo_Product_Generator {

		/** Meta marker used to identify products owned by this generator. */
		const GENERATED_META_KEY = '_shift64_woo_search_demo_generated';

		/** @var int Number of parent products to create. */
		private $count;

		/** @var string Product mode: variable, simple, or mixed. */
		private $mode;

		/** @var int Deterministic random seed. */
		private $seed;

		/** @var bool Whether to delete previously generated products first. */
		private $reset;

		/** @var int Number of variations created during the current run. */
		private $variation_count = 0;

		/**
		 * Constructor.
		 *
		 * @param int    $count Parent product count.
		 * @param string $mode  Product mode.
		 * @param int    $seed  Random seed.
		 * @param bool   $reset Delete previous demo products first.
		 */
		public function __construct( $count, $mode, $seed, $reset ) {
			$this->count = $count;
			$this->mode  = $mode;
			$this->seed  = $seed;
			$this->reset = $reset;
		}

		/**
		 * Generate the catalog.
		 */
		public function run() {
			mt_srand( $this->seed );

			if ( $this->reset ) {
				$this->delete_generated_products();
			}

			$category_ids = $this->ensure_categories();
			$color_data   = $this->ensure_attribute( 'Color', 'color', $this->get_colors() );
			$size_data    = $this->ensure_attribute( 'Size', 'size', $this->get_sizes() );
			$combinations = $this->build_combinations( $this->count );

			if ( $this->count > count( $combinations ) ) {
				WP_CLI::error( 'Requested product count exceeds the number of unique name combinations.' );
			}

			$progress = \WP_CLI\Utils\make_progress_bar( 'Generating demo products', $this->count );
			$created  = 0;
			$skipped  = 0;

			for ( $index = 0; $index < $this->count; ++$index ) {
				$combination = $combinations[ $index ];
				$sku         = sprintf( 'DEMO%02d%04d', $this->seed % 100, $index + 1 );

				if ( wc_get_product_id_by_sku( $sku ) ) {
					++$skipped;
					$progress->tick();
					continue;
				}

				$product_mode = $this->mode;
				if ( 'mixed' === $product_mode ) {
					$product_mode = 0 === $index % 4 ? 'simple' : 'variable';
				}

				if ( 'simple' === $product_mode ) {
					$this->create_simple_product( $combination, $sku, $category_ids, $color_data, $size_data, $index );
				} else {
					$this->create_variable_product( $combination, $sku, $category_ids, $color_data, $size_data, $index );
				}

				++$created;
				$progress->tick();
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
		 * Delete only products created by this generator.
		 */
		private function delete_generated_products() {
			$product_ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => self::GENERATED_META_KEY,
					'meta_value'     => 'yes',
				)
			);

			if ( empty( $product_ids ) ) {
				WP_CLI::log( 'Reset requested: no previously generated products found.' );
				return;
			}

			$progress = \WP_CLI\Utils\make_progress_bar( 'Deleting previous demo products', count( $product_ids ) );
			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$product->delete( true );
				}
				$progress->tick();
			}
			$progress->finish();
		}

		/**
		 * Create the apparel category hierarchy.
		 *
		 * @return array<string,int> Category IDs keyed by garment category key.
		 */
		private function ensure_categories() {
			$clothing = $this->ensure_term( 'Clothing', 'product_cat', 0 );
			$tops     = $this->ensure_term( 'Tops', 'product_cat', $clothing );
			$bottoms  = $this->ensure_term( 'Bottoms', 'product_cat', $clothing );
			$outer    = $this->ensure_term( 'Outerwear', 'product_cat', $clothing );

			return array(
				't_shirts' => $this->ensure_term( 'T-Shirts', 'product_cat', $tops ),
				'shirts'   => $this->ensure_term( 'Shirts', 'product_cat', $tops ),
				'hoodies'  => $this->ensure_term( 'Hoodies', 'product_cat', $tops ),
				'sweaters' => $this->ensure_term( 'Sweaters', 'product_cat', $tops ),
				'trousers' => $this->ensure_term( 'Trousers', 'product_cat', $bottoms ),
				'jeans'    => $this->ensure_term( 'Jeans', 'product_cat', $bottoms ),
				'shorts'   => $this->ensure_term( 'Shorts', 'product_cat', $bottoms ),
				'skirts'   => $this->ensure_term( 'Skirts', 'product_cat', $bottoms ),
				'jackets'  => $this->ensure_term( 'Jackets', 'product_cat', $outer ),
				'coats'    => $this->ensure_term( 'Coats', 'product_cat', $outer ),
				'dresses'  => $this->ensure_term( 'Dresses', 'product_cat', $clothing ),
			);
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
		 * Ensure a global WooCommerce attribute and all of its terms exist.
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

			$terms = array();
			foreach ( $values as $position => $value ) {
				$term_id = $this->ensure_term( $value, $taxonomy, 0 );
				update_term_meta( $term_id, 'order_' . $taxonomy, $position );
				$terms[ $value ] = get_term( $term_id, $taxonomy );
			}

			return array(
				'id'       => (int) $attribute_id,
				'taxonomy' => $taxonomy,
				'terms'    => $terms,
			);
		}

		/**
		 * Build balanced, unique name combinations.
		 *
		 * Each source list is shuffled independently and then cycled. The list
		 * lengths are pairwise co-prime enough to keep the first 1000 tuples
		 * unique while guaranteeing early coverage of every garment and color.
		 *
		 * @param int $count Number of combinations to build.
		 * @return array<int,array{myth:string,garment:array,color:string}>
		 */
		private function build_combinations( $count ) {
			$myths    = $this->get_myth_names();
			$garments = $this->get_garments();
			$colors   = $this->get_colors();
			shuffle( $myths );
			shuffle( $garments );
			shuffle( $colors );

			$combinations = array();
			for ( $index = 0; $index < $count; ++$index ) {
				$combinations[] = array(
					'myth'    => $myths[ $index % count( $myths ) ],
					'garment' => $garments[ $index % count( $garments ) ],
					'color'   => $colors[ $index % count( $colors ) ],
				);
			}
			return $combinations;
		}

		/**
		 * Create a variable product whose variations represent sizes.
		 *
		 * @param array $data         Name combination.
		 * @param string $sku         Parent SKU.
		 * @param array $category_ids Category map.
		 * @param array $color_data   Color attribute data.
		 * @param array $size_data    Size attribute data.
		 * @param int   $index        Zero-based product index.
		 */
		private function create_variable_product( $data, $sku, $category_ids, $color_data, $size_data, $index ) {
			$product = new WC_Product_Variable();
			$this->apply_common_product_data( $product, $data, $sku, $category_ids, $index );

			$color_term = $color_data['terms'][ $data['color'] ];
			$color_attr = $this->make_attribute( $color_data, array( $color_term->term_id ), true, false, 0 );
			$size_ids   = array();
			foreach ( $size_data['terms'] as $size_term ) {
				$size_ids[] = $size_term->term_id;
			}
			$size_attr = $this->make_attribute( $size_data, $size_ids, true, true, 1 );

			$product->set_attributes( array( $color_attr, $size_attr ) );
			$product_id = $product->save();

			$base_price = (float) $data['garment']['price'];
			foreach ( $size_data['terms'] as $size_name => $size_term ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_status( 'publish' );
				$variation->set_sku( $sku . strtoupper( $size_term->slug ) );
				$variation->set_attributes( array( $size_data['taxonomy'] => $size_term->slug ) );
				$variation->set_regular_price( wc_format_decimal( $base_price, 2 ) );
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( mt_rand( 3, 40 ) );
				$variation->set_stock_status( 'instock' );
				$variation->set_description( sprintf( '%s in size %s.', $product->get_name(), $size_name ) );
				$variation->update_meta_data( self::GENERATED_META_KEY, 'yes' );
				$variation->save();
				++$this->variation_count;
			}

			WC_Product_Variable::sync( $product_id, true );
			wc_delete_product_transients( $product_id );
		}

		/**
		 * Create a simple product with visible color and size attributes.
		 *
		 * @param array $data         Name combination.
		 * @param string $sku         Product SKU.
		 * @param array $category_ids Category map.
		 * @param array $color_data   Color attribute data.
		 * @param array $size_data    Size attribute data.
		 * @param int   $index        Zero-based product index.
		 */
		private function create_simple_product( $data, $sku, $category_ids, $color_data, $size_data, $index ) {
			$product = new WC_Product_Simple();
			$this->apply_common_product_data( $product, $data, $sku, $category_ids, $index );

			$color_term = $color_data['terms'][ $data['color'] ];
			$size_names = array_keys( $size_data['terms'] );
			$size_name  = $size_names[ $index % count( $size_names ) ];
			$size_term  = $size_data['terms'][ $size_name ];

			$product->set_attributes(
				array(
					$this->make_attribute( $color_data, array( $color_term->term_id ), true, false, 0 ),
					$this->make_attribute( $size_data, array( $size_term->term_id ), true, false, 1 ),
				)
			);
			$product->set_regular_price( wc_format_decimal( $data['garment']['price'], 2 ) );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( mt_rand( 3, 40 ) );
			$product->set_stock_status( 'instock' );
			$product->save();
		}

		/**
		 * Set fields shared by simple and variable products.
		 *
		 * @param WC_Product $product      Product instance.
		 * @param array      $data         Name combination.
		 * @param string     $sku          Product SKU.
		 * @param array      $category_ids Category map.
		 * @param int        $index        Zero-based product index.
		 */
		private function apply_common_product_data( $product, $data, $sku, $category_ids, $index ) {
			$garment      = $data['garment'];
			$product_name = sprintf( '%s %s %s', $data['myth'], $garment['name'], $data['color'] );
			$materials    = array( 'organic cotton', 'soft jersey', 'brushed fleece', 'lightweight denim', 'recycled fabric' );
			$styles       = array( 'everyday', 'minimal', 'relaxed', 'tailored', 'sport-inspired', 'classic' );
			$material     = $materials[ mt_rand( 0, count( $materials ) - 1 ) ];
			$style        = $styles[ mt_rand( 0, count( $styles ) - 1 ) ];

			$product->set_name( $product_name );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
			$product->set_sku( $sku );
			$product->set_category_ids( array( $category_ids[ $garment['category'] ] ) );
			$product->set_short_description( sprintf( 'A %s %s design in %s.', $style, strtolower( $garment['name'] ), strtolower( $data['color'] ) ) );
			$product->set_description(
				sprintf(
					'The %s is a %s wardrobe essential made from %s. Designed for comfortable daily wear and easy outfit pairing.',
					$product_name,
					$style,
					$material
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

		/**
		 * Mythological collection names.
		 *
		 * @return string[]
		 */
		private function get_myth_names() {
			return array(
				'Achilles', 'Adonis', 'Apollo', 'Ares', 'Artemis', 'Athena', 'Atlas', 'Calypso',
				'Circe', 'Clio', 'Daphne', 'Demeter', 'Dione', 'Echo', 'Eos', 'Gaia', 'Hector',
				'Helios', 'Hera', 'Hermes', 'Iris', 'Jason', 'Leto', 'Maia', 'Medea', 'Nike',
				'Nyx', 'Orion', 'Perseus', 'Phoebe', 'Rhea', 'Selene', 'Themis', 'Triton', 'Zeus',
			);
		}

		/**
		 * Garment definitions.
		 *
		 * @return array<int,array{name:string,category:string,price:float}>
		 */
		private function get_garments() {
			return array(
				array( 'name' => 'T-Shirt', 'category' => 't_shirts', 'price' => 29.90 ),
				array( 'name' => 'Shirt', 'category' => 'shirts', 'price' => 54.90 ),
				array( 'name' => 'Hoodie', 'category' => 'hoodies', 'price' => 74.90 ),
				array( 'name' => 'Sweater', 'category' => 'sweaters', 'price' => 69.90 ),
				array( 'name' => 'Trousers', 'category' => 'trousers', 'price' => 79.90 ),
				array( 'name' => 'Jeans', 'category' => 'jeans', 'price' => 84.90 ),
				array( 'name' => 'Shorts', 'category' => 'shorts', 'price' => 44.90 ),
				array( 'name' => 'Skirt', 'category' => 'skirts', 'price' => 59.90 ),
				array( 'name' => 'Jacket', 'category' => 'jackets', 'price' => 109.90 ),
				array( 'name' => 'Coat', 'category' => 'coats', 'price' => 149.90 ),
				array( 'name' => 'Dress', 'category' => 'dresses', 'price' => 89.90 ),
			);
		}

		/**
		 * Available colors.
		 *
		 * @return string[]
		 */
		private function get_colors() {
			return array( 'Black', 'Blue', 'Cream', 'Gold', 'Green', 'Grey', 'Navy', 'Orange', 'Purple', 'Red', 'White', 'Yellow' );
		}

		/**
		 * Available garment sizes.
		 *
		 * @return string[]
		 */
		private function get_sizes() {
			return array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' );
		}
	}
}

/**
 * Parse eval-file arguments.
 *
 * @param array $raw_args Arguments exposed by WP-CLI to eval-file.
 * @return array{count:int,mode:string,seed:int,reset:bool}
 */
function shift64_woo_search_parse_demo_product_args( $raw_args ) {
	$options = array(
		'count' => 48,
		'mode'  => 'variable',
		'seed'  => 6464,
		'reset' => false,
	);

	foreach ( $raw_args as $raw_arg ) {
		$arg = ltrim( (string) $raw_arg, '-' );
		if ( '' === $arg ) {
			continue;
		}
		if ( 'reset' === $arg ) {
			$options['reset'] = true;
			continue;
		}
		if ( false === strpos( $arg, '=' ) ) {
			WP_CLI::error( sprintf( 'Unknown argument "%s". Use count=N, mode=variable|simple|mixed, seed=N, or reset.', $raw_arg ) );
		}

		list( $key, $value ) = array_map( 'trim', explode( '=', $arg, 2 ) );
		if ( ! array_key_exists( $key, $options ) || 'reset' === $key ) {
			WP_CLI::error( sprintf( 'Unknown option "%s".', $key ) );
		}
		$options[ $key ] = $value;
	}

	$options['count'] = (int) $options['count'];
	$options['seed']  = (int) $options['seed'];
	$options['mode']  = strtolower( (string) $options['mode'] );

	if ( $options['count'] < 1 || $options['count'] > 1000 ) {
		WP_CLI::error( 'count must be between 1 and 1000.' );
	}
	if ( $options['seed'] < 1 ) {
		WP_CLI::error( 'seed must be a positive integer.' );
	}
	if ( ! in_array( $options['mode'], array( 'variable', 'simple', 'mixed' ), true ) ) {
		WP_CLI::error( 'mode must be variable, simple, or mixed.' );
	}

	return $options;
}

$shift64_demo_raw_args = isset( $args ) && is_array( $args ) ? $args : array();
$shift64_demo_options  = shift64_woo_search_parse_demo_product_args( $shift64_demo_raw_args );
$shift64_demo_runner   = new Shift64_Woo_Search_Demo_Product_Generator(
	$shift64_demo_options['count'],
	$shift64_demo_options['mode'],
	$shift64_demo_options['seed'],
	$shift64_demo_options['reset']
);
$shift64_demo_runner->run();
