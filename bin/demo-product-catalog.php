<?php
/**
 * Pure catalog data and combinatorics for the demo product generator.
 *
 * This file has no side effects and depends on neither WP-CLI nor
 * WooCommerce, so the deterministic parts of the generator (argument
 * parsing, name combinatorics, SKU building) can be unit tested.
 *
 * @package Shift64_Woo_Search
 */

if ( ! class_exists( 'Shift64_Woo_Search_Demo_Catalog' ) ) {
	/**
	 * Deterministic multi-vertical catalog source for demo seeding.
	 */
	class Shift64_Woo_Search_Demo_Catalog {

		/** Meta marker used to identify products owned by the generator. */
		const GENERATED_META_KEY = '_shift64_woo_search_demo_generated';

		/** Highest supported parent product count. */
		const MAX_COUNT = 100000;

		/** Smallest supported batch size. */
		const MIN_BATCH = 50;

		/** Largest supported batch size. */
		const MAX_BATCH = 5000;

		/** Variation record count above which the generator warns. */
		const VARIATION_WARNING_THRESHOLD = 200000;

		/**
		 * Catalog verticals in their fixed rotation order.
		 *
		 * @return string[]
		 */
		public static function vertical_keys() {
			return array( 'apparel', 'tech', 'home', 'beauty' );
		}

		/**
		 * Name prefixes shared by every vertical (segment 1).
		 *
		 * @return string[]
		 */
		public static function prefixes() {
			return array(
				'Aero',
				'Alto',
				'Arc',
				'Aura',
				'Axis',
				'Bold',
				'Calm',
				'Core',
				'Crest',
				'Drift',
				'Echo',
				'Edge',
				'Flux',
				'Forge',
				'Halo',
				'Lumen',
				'Nova',
				'Onyx',
				'Orbit',
				'Prime',
				'Pulse',
				'Quill',
				'Ridge',
				'Vertex',
			);
		}

		/**
		 * Full definition of a catalog vertical.
		 *
		 * Every vertical carries its category tree, brand tree, the four
		 * per-vertical name segments (series, items, specs, finishes), and the
		 * global attributes its products use.
		 *
		 * @param string $vertical Vertical key.
		 * @return array<string,mixed>
		 * @throws InvalidArgumentException When the vertical is unknown.
		 */
		public static function vertical( $vertical ) {
			$verticals = self::verticals();
			if ( ! isset( $verticals[ $vertical ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Unknown catalog vertical "%s".', $vertical ) );
			}
			return $verticals[ $vertical ];
		}

		/**
		 * Every catalog vertical, keyed by vertical key.
		 *
		 * @return array<string,array<string,mixed>>
		 */
		public static function verticals() {
			return array(
				'apparel' => self::apparel_vertical(),
				'tech'    => self::tech_vertical(),
				'home'    => self::home_vertical(),
				'beauty'  => self::beauty_vertical(),
			);
		}

		/**
		 * Number of distinct names a vertical can produce.
		 *
		 * @param string $vertical Vertical key.
		 * @return int
		 */
		public static function name_space_size( $vertical ) {
			$data = self::vertical( $vertical );

			return count( self::prefixes() )
				* count( $data['series'] )
				* count( $data['items'] )
				* count( $data['specs'] )
				* count( $data['finishes'] );
		}

		/**
		 * Total name space across all verticals.
		 *
		 * @return int
		 */
		public static function total_name_space_size() {
			$total = 0;
			foreach ( self::vertical_keys() as $vertical ) {
				$total += self::name_space_size( $vertical );
			}
			return $total;
		}

		/**
		 * Resolve which vertical a zero-based product index belongs to.
		 *
		 * `all` rotates through the verticals so any prefix of the run stays
		 * balanced; a single-vertical catalog always returns that vertical.
		 *
		 * @param string $catalog Catalog option (all or a vertical key).
		 * @param int    $index   Zero-based product index.
		 * @return string
		 */
		public static function vertical_for_index( $catalog, $index ) {
			if ( 'all' !== $catalog ) {
				return $catalog;
			}
			$keys = self::vertical_keys();
			return $keys[ $index % count( $keys ) ];
		}

		/**
		 * Resolve the per-vertical ordinal of a zero-based product index.
		 *
		 * @param string $catalog Catalog option.
		 * @param int    $index   Zero-based product index.
		 * @return int
		 */
		public static function ordinal_for_index( $catalog, $index ) {
			if ( 'all' !== $catalog ) {
				return $index;
			}
			return intdiv( $index, count( self::vertical_keys() ) );
		}

		/**
		 * Resolve the product type for a zero-based product index.
		 *
		 * In mixed mode roughly 75% of the catalog is simple and 25% variable,
		 * which keeps the total post count manageable on high-volume runs.
		 *
		 * The decision keys off the per-vertical ordinal, not the raw index. With
		 * `all`, the index also selects the vertical (index % 4), so testing the
		 * index against the same modulus would make "variable" and "apparel" the
		 * same condition — every variable product would land in one vertical and
		 * the other three would be entirely simple, leaving their variation
		 * attributes never exercised as variations.
		 *
		 * @param string $mode    Mode option (variable, simple, or mixed).
		 * @param string $catalog Catalog option (all or a vertical key).
		 * @param int    $index   Zero-based product index.
		 * @return string
		 */
		public static function product_mode( $mode, $catalog, $index ) {
			if ( 'mixed' !== $mode ) {
				return $mode;
			}
			return 0 === self::ordinal_for_index( $catalog, $index ) % 4 ? 'variable' : 'simple';
		}

		/**
		 * Build the five-segment name combination for a product.
		 *
		 * The ordinal is mapped through a stride that is co-prime with the
		 * vertical's name space, which makes the mapping a bijection: every
		 * ordinal below the name-space size yields a distinct tuple, while
		 * consecutive products still spread across the whole catalog. The
		 * mapping is a pure function of (vertical, ordinal, seed), so a rerun
		 * with the same seed reproduces the same catalog.
		 *
		 * @param string $vertical Vertical key.
		 * @param int    $ordinal  Zero-based per-vertical ordinal.
		 * @param int    $seed     Deterministic seed.
		 * @return array<string,mixed> Name segments plus item metadata.
		 */
		public static function build_combination( $vertical, $ordinal, $seed ) {
			$data  = self::vertical( $vertical );
			$total = self::name_space_size( $vertical );
			$point = ( self::stride( $total, $seed ) * ( $ordinal % $total ) + $seed ) % $total;

			$prefixes = self::prefixes();
			$sizes    = array(
				count( $prefixes ),
				count( $data['series'] ),
				count( $data['items'] ),
				count( $data['specs'] ),
				count( $data['finishes'] ),
			);

			$digits    = array();
			$remainder = $point;
			foreach ( array_reverse( $sizes ) as $size ) {
				array_unshift( $digits, $remainder % $size );
				$remainder = intdiv( $remainder, $size );
			}

			$item = $data['items'][ $digits[2] ];

			return array(
				'vertical' => $vertical,
				'prefix'   => $prefixes[ $digits[0] ],
				'series'   => $data['series'][ $digits[1] ],
				'item'     => $item['name'],
				'spec'     => $data['specs'][ $digits[3] ],
				'finish'   => $data['finishes'][ $digits[4] ],
				'category' => $item['category'],
				'price'    => $item['price'],
			);
		}

		/**
		 * Compose the display name of a combination.
		 *
		 * @param array $combination Combination produced by build_combination().
		 * @return string
		 */
		public static function build_name( $combination ) {
			return sprintf(
				'%s %s %s %s %s',
				$combination['prefix'],
				$combination['series'],
				$combination['item'],
				$combination['spec'],
				$combination['finish']
			);
		}

		/**
		 * Build the deterministic parent SKU.
		 *
		 * Shape: DEMO-[VERTICAL]-[SEED]-[ID].
		 *
		 * @param string $vertical Vertical key.
		 * @param int    $seed     Deterministic seed.
		 * @param int    $index    Zero-based product index.
		 * @return string
		 */
		public static function build_sku( $vertical, $seed, $index ) {
			$data = self::vertical( $vertical );
			return sprintf( 'DEMO-%s-%02d-%06d', $data['sku_prefix'], $seed % 100, $index + 1 );
		}

		/**
		 * Pick a multiplier that is co-prime with the name space.
		 *
		 * The base multiplier sits near the golden-ratio fraction of the name
		 * space so consecutive ordinals move every segment of the tuple, not
		 * just the least significant one — a short run still shows varied
		 * prefixes, series, and items.
		 *
		 * @param int $total Name space size.
		 * @param int $seed  Deterministic seed.
		 * @return int
		 */
		private static function stride( $total, $seed ) {
			$stride = ( ( (int) round( $total * 0.6180339887 ) + $seed ) % $total ) + 1;
			$guard  = 0;
			while ( self::gcd( $stride, $total ) > 1 && $guard < $total ) {
				$stride = ( $stride % $total ) + 1;
				++$guard;
			}
			return $stride;
		}

		/**
		 * Greatest common divisor.
		 *
		 * @param int $a First operand.
		 * @param int $b Second operand.
		 * @return int
		 */
		private static function gcd( $a, $b ) {
			while ( 0 !== $b ) {
				$remainder = $a % $b;
				$a         = $b;
				$b         = $remainder;
			}
			return $a;
		}

		/**
		 * Parse the arguments WP-CLI hands to `wp eval-file`.
		 *
		 * @param array $raw_args Raw argument strings.
		 * @return array{count:int,mode:string,catalog:string,batch:int,seed:int,reset:bool,reset_only:bool,variation_skus:bool}
		 * @throws InvalidArgumentException When an argument is unknown or out of range.
		 */
		public static function parse_args( $raw_args ) {
			$options = array(
				'count'          => 48,
				'mode'           => 'mixed',
				'catalog'        => 'all',
				'batch'          => 1000,
				'seed'           => 6464,
				'reset'          => false,
				'reset_only'     => false,
				'variation_skus' => false,
			);

			// Valueless switches; they never accept a `key=value` form.
			$flags = array(
				'reset'          => 'reset',
				'reset-only'     => 'reset_only',
				'variation-skus' => 'variation_skus',
			);

			foreach ( $raw_args as $raw_arg ) {
				$arg = ltrim( (string) $raw_arg, '-' );
				if ( '' === $arg ) {
					continue;
				}
				if ( isset( $flags[ $arg ] ) ) {
					$options[ $flags[ $arg ] ] = true;
					continue;
				}
				if ( false === strpos( $arg, '=' ) ) {
					throw new InvalidArgumentException(
						sprintf(
							'Unknown argument "%s". Use count=N, mode=variable|simple|mixed, catalog=all|apparel|tech|home|beauty, batch=N, seed=N, reset, reset-only, or variation-skus.',
							(string) $raw_arg
						)
					);
				}

				list( $key, $value ) = array_map( 'trim', explode( '=', $arg, 2 ) );
				$key                 = str_replace( '-', '_', $key );
				if ( ! array_key_exists( $key, $options ) || in_array( $key, $flags, true ) ) {
					throw new InvalidArgumentException( sprintf( 'Unknown option "%s".', $key ) );
				}
				$options[ $key ] = $value;
			}

			$options['count']   = (int) $options['count'];
			$options['batch']   = (int) $options['batch'];
			$options['seed']    = (int) $options['seed'];
			$options['mode']    = strtolower( (string) $options['mode'] );
			$options['catalog'] = strtolower( (string) $options['catalog'] );

			if ( $options['count'] < 1 || $options['count'] > self::MAX_COUNT ) {
				throw new InvalidArgumentException( sprintf( 'count must be between 1 and %d.', self::MAX_COUNT ) );
			}
			if ( $options['batch'] < self::MIN_BATCH || $options['batch'] > self::MAX_BATCH ) {
				throw new InvalidArgumentException( sprintf( 'batch must be between %d and %d.', self::MIN_BATCH, self::MAX_BATCH ) );
			}
			if ( $options['seed'] < 1 ) {
				throw new InvalidArgumentException( 'seed must be a positive integer.' );
			}
			if ( ! in_array( $options['mode'], array( 'variable', 'simple', 'mixed' ), true ) ) {
				throw new InvalidArgumentException( 'mode must be variable, simple, or mixed.' );
			}
			if ( 'all' !== $options['catalog'] && ! in_array( $options['catalog'], self::vertical_keys(), true ) ) {
				throw new InvalidArgumentException( 'catalog must be all, apparel, tech, home, or beauty.' );
			}

			return $options;
		}

		/**
		 * Apparel vertical definition.
		 *
		 * @return array<string,mixed>
		 */
		private static function apparel_vertical() {
			return array(
				'sku_prefix' => 'APP',
				'root'       => 'Clothing',
				'categories' => array(
					't_shirts' => array( 'Tops', 'T-Shirts' ),
					'shirts'   => array( 'Tops', 'Shirts' ),
					'hoodies'  => array( 'Tops', 'Hoodies' ),
					'sweaters' => array( 'Tops', 'Sweaters' ),
					'trousers' => array( 'Bottoms', 'Trousers' ),
					'jeans'    => array( 'Bottoms', 'Jeans' ),
					'shorts'   => array( 'Bottoms', 'Shorts' ),
					'skirts'   => array( 'Bottoms', 'Skirts' ),
					'jackets'  => array( 'Outerwear', 'Jackets' ),
					'coats'    => array( 'Outerwear', 'Coats' ),
					'dresses'  => array( '', 'Dresses' ),
				),
				'brands'     => array(
					'Aeon Atelier'  => array( 'Aeon Everyday', 'Aeon Reserve' ),
					'Helios Wear'   => array(),
					'Nyx Workshop'  => array(),
					'Selene Studio' => array(),
					'Thalia Union'  => array(),
				),
				'series'     => array(
					'Athena',
					'Apollo',
					'Artemis',
					'Atlas',
					'Calypso',
					'Circe',
					'Daphne',
					'Demeter',
					'Echo',
					'Gaia',
					'Hector',
					'Hera',
					'Hermes',
					'Iris',
					'Leto',
					'Maia',
					'Nike',
					'Orion',
					'Selene',
					'Themis',
				),
				'items'      => array(
					array(
						'name'     => 'T-Shirt',
						'category' => 't_shirts',
						'price'    => 29.90,
					),
					array(
						'name'     => 'Polo Shirt',
						'category' => 't_shirts',
						'price'    => 39.90,
					),
					array(
						'name'     => 'Oxford Shirt',
						'category' => 'shirts',
						'price'    => 54.90,
					),
					array(
						'name'     => 'Hoodie',
						'category' => 'hoodies',
						'price'    => 74.90,
					),
					array(
						'name'     => 'Zip Hoodie',
						'category' => 'hoodies',
						'price'    => 79.90,
					),
					array(
						'name'     => 'Sweater',
						'category' => 'sweaters',
						'price'    => 69.90,
					),
					array(
						'name'     => 'Cardigan',
						'category' => 'sweaters',
						'price'    => 84.90,
					),
					array(
						'name'     => 'Trousers',
						'category' => 'trousers',
						'price'    => 79.90,
					),
					array(
						'name'     => 'Jeans',
						'category' => 'jeans',
						'price'    => 84.90,
					),
					array(
						'name'     => 'Shorts',
						'category' => 'shorts',
						'price'    => 44.90,
					),
					array(
						'name'     => 'Skirt',
						'category' => 'skirts',
						'price'    => 59.90,
					),
					array(
						'name'     => 'Jacket',
						'category' => 'jackets',
						'price'    => 109.90,
					),
					array(
						'name'     => 'Coat',
						'category' => 'coats',
						'price'    => 149.90,
					),
					array(
						'name'     => 'Dress',
						'category' => 'dresses',
						'price'    => 89.90,
					),
				),
				'specs'      => array(
					'Relaxed Fit',
					'Slim Fit',
					'Oversized',
					'Tailored',
					'Cropped',
					'Longline',
					'Everyday',
					'Heavyweight',
					'Lightweight',
					'Brushed',
					'Ribbed',
					'Quilted',
				),
				'finishes'   => array(
					'Midnight Black',
					'Deep Navy',
					'Slate Grey',
					'Off White',
					'Cream',
					'Sand',
					'Olive',
					'Forest Green',
					'Burgundy',
					'Rust',
					'Cobalt Blue',
					'Lilac',
					'Blush Pink',
					'Mustard',
					'Terracotta',
					'Charcoal',
				),
				'variation'  => array(
					'label'  => 'Size',
					'slug'   => 'size',
					'values' => array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' ),
				),
				'extra'      => array(
					'label'  => 'Material',
					'slug'   => 'material',
					'values' => array( 'Organic Cotton', 'Soft Jersey', 'Brushed Fleece', 'Denim', 'Merino Wool', 'Recycled Blend' ),
				),
			);
		}

		/**
		 * Technology vertical definition.
		 *
		 * @return array<string,mixed>
		 */
		private static function tech_vertical() {
			return array(
				'sku_prefix' => 'TEC',
				'root'       => 'Technology',
				'categories' => array(
					'headphones'   => array( 'Audio', 'Headphones' ),
					'earbuds'      => array( 'Audio', 'Earbuds' ),
					'speakers'     => array( 'Audio', 'Speakers' ),
					'microphones'  => array( 'Audio', 'Microphones' ),
					'laptops'      => array( 'Computers', 'Laptops' ),
					'monitors'     => array( 'Computers', 'Monitors' ),
					'keyboards'    => array( 'Computers', 'Keyboards' ),
					'mice'         => array( 'Computers', 'Mice' ),
					'smartphones'  => array( 'Mobile', 'Smartphones' ),
					'tablets'      => array( 'Mobile', 'Tablets' ),
					'smartwatches' => array( 'Mobile', 'Smartwatches' ),
					'powerbanks'   => array( 'Mobile', 'Power Banks' ),
				),
				'brands'     => array(
					'Nyx Tech'       => array( 'Nyx Labs', 'Nyx Pro' ),
					'Orpheus Sound'  => array(),
					'Vulcan Gear'    => array(),
					'Zephyr Systems' => array(),
				),
				'series'     => array(
					'Aurora',
					'Beacon',
					'Cipher',
					'Delta',
					'Ember',
					'Falcon',
					'Gravity',
					'Horizon',
					'Ion',
					'Kelvin',
					'Lattice',
					'Meridian',
					'Nimbus',
					'Photon',
					'Quantum',
					'Radian',
					'Spectra',
					'Tesseract',
					'Vector',
					'Zenith',
				),
				'items'      => array(
					array(
						'name'     => 'Headphones',
						'category' => 'headphones',
						'price'    => 199.00,
					),
					array(
						'name'     => 'Studio Headphones',
						'category' => 'headphones',
						'price'    => 349.00,
					),
					array(
						'name'     => 'Earbuds',
						'category' => 'earbuds',
						'price'    => 129.00,
					),
					array(
						'name'     => 'Speaker',
						'category' => 'speakers',
						'price'    => 179.00,
					),
					array(
						'name'     => 'Microphone',
						'category' => 'microphones',
						'price'    => 149.00,
					),
					array(
						'name'     => 'Laptop',
						'category' => 'laptops',
						'price'    => 1299.00,
					),
					array(
						'name'     => 'Monitor',
						'category' => 'monitors',
						'price'    => 429.00,
					),
					array(
						'name'     => 'Keyboard',
						'category' => 'keyboards',
						'price'    => 119.00,
					),
					array(
						'name'     => 'Mouse',
						'category' => 'mice',
						'price'    => 69.00,
					),
					array(
						'name'     => 'Gaming Mouse',
						'category' => 'mice',
						'price'    => 99.00,
					),
					array(
						'name'     => 'Smartphone',
						'category' => 'smartphones',
						'price'    => 899.00,
					),
					array(
						'name'     => 'Tablet',
						'category' => 'tablets',
						'price'    => 649.00,
					),
					array(
						'name'     => 'Smartwatch',
						'category' => 'smartwatches',
						'price'    => 279.00,
					),
					array(
						'name'     => 'Power Bank',
						'category' => 'powerbanks',
						'price'    => 59.00,
					),
				),
				'specs'      => array(
					'Pro',
					'Max',
					'Lite',
					'Studio',
					'Compact',
					'Wireless',
					'Noise Cancelling',
					'High Fidelity',
					'Ultra Wide',
					'Low Latency',
					'Fast Charge',
					'Travel',
				),
				'finishes'   => array(
					'Space Grey',
					'Matte Black',
					'Silver',
					'Graphite',
					'Titanium',
					'Arctic White',
					'Midnight Blue',
					'Copper',
					'Sage',
					'Sunset Orange',
					'Ruby Red',
					'Steel',
					'Onyx',
					'Pearl',
					'Bronze',
					'Ice Blue',
				),
				'variation'  => array(
					'label'  => 'Capacity',
					'slug'   => 'capacity',
					'values' => array( '64 GB', '128 GB', '256 GB', '512 GB', '1 TB', '2 TB' ),
				),
				'extra'      => array(
					'label'  => 'Connectivity',
					'slug'   => 'connectivity',
					'values' => array( 'Bluetooth', 'USB-C', 'Wi-Fi', 'Wired' ),
				),
			);
		}

		/**
		 * Home & living vertical definition.
		 *
		 * @return array<string,mixed>
		 */
		private static function home_vertical() {
			return array(
				'sku_prefix' => 'HOM',
				'root'       => 'Home & Living',
				'categories' => array(
					'chairs'         => array( 'Furniture', 'Chairs' ),
					'desks'          => array( 'Furniture', 'Desks' ),
					'tables'         => array( 'Furniture', 'Tables' ),
					'bookshelves'    => array( 'Furniture', 'Bookshelves' ),
					'desk_lamps'     => array( 'Lighting', 'Desk Lamps' ),
					'floor_lamps'    => array( 'Lighting', 'Floor Lamps' ),
					'pendant_lights' => array( 'Lighting', 'Pendant Lights' ),
					'coffee_makers'  => array( 'Kitchen', 'Coffee Makers' ),
					'kettles'        => array( 'Kitchen', 'Kettles' ),
					'cookware'       => array( 'Kitchen', 'Cookware' ),
				),
				'brands'     => array(
					'Selene Home'   => array( 'Selene Living', 'Selene Kitchen' ),
					'Aeon Interior' => array(),
					'Hestia Works'  => array(),
					'Terra Craft'   => array(),
				),
				'series'     => array(
					'Alder',
					'Basalt',
					'Cedar',
					'Dune',
					'Elm',
					'Fjord',
					'Granite',
					'Harbor',
					'Ivory',
					'Juniper',
					'Kiln',
					'Linden',
					'Meadow',
					'Nordic',
					'Opal',
					'Quarry',
					'Sable',
					'Timber',
					'Umber',
					'Willow',
				),
				'items'      => array(
					array(
						'name'     => 'Office Chair',
						'category' => 'chairs',
						'price'    => 249.00,
					),
					array(
						'name'     => 'Lounge Chair',
						'category' => 'chairs',
						'price'    => 399.00,
					),
					array(
						'name'     => 'Desk',
						'category' => 'desks',
						'price'    => 329.00,
					),
					array(
						'name'     => 'Standing Desk',
						'category' => 'desks',
						'price'    => 599.00,
					),
					array(
						'name'     => 'Dining Table',
						'category' => 'tables',
						'price'    => 749.00,
					),
					array(
						'name'     => 'Side Table',
						'category' => 'tables',
						'price'    => 159.00,
					),
					array(
						'name'     => 'Bookshelf',
						'category' => 'bookshelves',
						'price'    => 279.00,
					),
					array(
						'name'     => 'Desk Lamp',
						'category' => 'desk_lamps',
						'price'    => 79.00,
					),
					array(
						'name'     => 'Floor Lamp',
						'category' => 'floor_lamps',
						'price'    => 139.00,
					),
					array(
						'name'     => 'Pendant Light',
						'category' => 'pendant_lights',
						'price'    => 119.00,
					),
					array(
						'name'     => 'Coffee Maker',
						'category' => 'coffee_makers',
						'price'    => 189.00,
					),
					array(
						'name'     => 'Espresso Machine',
						'category' => 'coffee_makers',
						'price'    => 449.00,
					),
					array(
						'name'     => 'Kettle',
						'category' => 'kettles',
						'price'    => 69.00,
					),
					array(
						'name'     => 'Cookware Set',
						'category' => 'cookware',
						'price'    => 219.00,
					),
				),
				'specs'      => array(
					'Compact',
					'Extended',
					'Adjustable',
					'Foldable',
					'Modular',
					'Handcrafted',
					'Matte',
					'Glossy',
					'Insulated',
					'Stackable',
					'Studio',
					'Heritage',
				),
				'finishes'   => array(
					'Natural Oak',
					'Walnut',
					'Ash Grey',
					'Matte Black',
					'Brushed Steel',
					'Brass',
					'Chalk White',
					'Clay',
					'Moss Green',
					'Deep Teal',
					'Warm Sand',
					'Ink Blue',
					'Copper',
					'Marble',
					'Bamboo',
					'Linen',
				),
				'variation'  => array(
					'label'  => 'Material',
					'slug'   => 'material',
					'values' => array( 'Oak', 'Walnut', 'Steel', 'Marble', 'Linen', 'Bamboo' ),
				),
				'extra'      => array(
					'label'  => 'Capacity',
					'slug'   => 'capacity',
					'values' => array( '0.5 L', '1 L', '1.5 L', '2 L' ),
				),
			);
		}

		/**
		 * Beauty & care vertical definition.
		 *
		 * @return array<string,mixed>
		 */
		private static function beauty_vertical() {
			return array(
				'sku_prefix' => 'BEA',
				'root'       => 'Beauty & Care',
				'categories' => array(
					'serums'          => array( 'Skincare', 'Serums' ),
					'moisturisers'    => array( 'Skincare', 'Moisturisers' ),
					'cleansers'       => array( 'Skincare', 'Cleansers' ),
					'masks'           => array( 'Skincare', 'Masks' ),
					'shampoos'        => array( 'Haircare', 'Shampoos' ),
					'conditioners'    => array( 'Haircare', 'Conditioners' ),
					'styling_oils'    => array( 'Haircare', 'Styling Oils' ),
					'eau_de_parfum'   => array( 'Fragrances', 'Eau de Parfum' ),
					'eau_de_toilette' => array( 'Fragrances', 'Eau de Toilette' ),
				),
				'brands'     => array(
					'Iris Beauty'       => array( 'Iris Skin', 'Iris Hair' ),
					'Selene Care'       => array(),
					'Thalia Labs'       => array(),
					'Aurora Botanicals' => array(),
				),
				'series'     => array(
					'Amber',
					'Bloom',
					'Cascade',
					'Dewdrop',
					'Elixir',
					'Flora',
					'Glow',
					'Hydra',
					'Infuse',
					'Jasmine',
					'Lotus',
					'Mineral',
					'Nectar',
					'Orchid',
					'Petal',
					'Radiance',
					'Serene',
					'Tonic',
					'Velvet',
					'Zest',
				),
				'items'      => array(
					array(
						'name'     => 'Face Serum',
						'category' => 'serums',
						'price'    => 49.00,
					),
					array(
						'name'     => 'Night Serum',
						'category' => 'serums',
						'price'    => 59.00,
					),
					array(
						'name'     => 'Moisturiser',
						'category' => 'moisturisers',
						'price'    => 39.00,
					),
					array(
						'name'     => 'Day Cream',
						'category' => 'moisturisers',
						'price'    => 44.00,
					),
					array(
						'name'     => 'Cleanser',
						'category' => 'cleansers',
						'price'    => 29.00,
					),
					array(
						'name'     => 'Micellar Cleanser',
						'category' => 'cleansers',
						'price'    => 25.00,
					),
					array(
						'name'     => 'Face Mask',
						'category' => 'masks',
						'price'    => 22.00,
					),
					array(
						'name'     => 'Sheet Mask',
						'category' => 'masks',
						'price'    => 12.00,
					),
					array(
						'name'     => 'Shampoo',
						'category' => 'shampoos',
						'price'    => 24.00,
					),
					array(
						'name'     => 'Volume Shampoo',
						'category' => 'shampoos',
						'price'    => 27.00,
					),
					array(
						'name'     => 'Conditioner',
						'category' => 'conditioners',
						'price'    => 26.00,
					),
					array(
						'name'     => 'Styling Oil',
						'category' => 'styling_oils',
						'price'    => 34.00,
					),
					array(
						'name'     => 'Eau de Parfum',
						'category' => 'eau_de_parfum',
						'price'    => 89.00,
					),
					array(
						'name'     => 'Eau de Toilette',
						'category' => 'eau_de_toilette',
						'price'    => 69.00,
					),
				),
				'specs'      => array(
					'Hydrating',
					'Brightening',
					'Soothing',
					'Repairing',
					'Purifying',
					'Nourishing',
					'Fragrance Free',
					'Daily',
					'Intensive',
					'Overnight',
					'Lightweight',
					'Rich',
				),
				'finishes'   => array(
					'Rose',
					'Neroli',
					'Vetiver',
					'Bergamot',
					'Sandalwood',
					'Vanilla',
					'Green Tea',
					'Aloe',
					'Citrus',
					'Lavender',
					'Coconut',
					'Almond',
					'Cedarwood',
					'Peony',
					'Mint',
					'Amber Musk',
				),
				'variation'  => array(
					'label'  => 'Capacity',
					'slug'   => 'capacity',
					'values' => array( '30 ml', '50 ml', '100 ml', '200 ml' ),
				),
				'extra'      => array(
					'label'  => 'Material',
					'slug'   => 'material',
					'values' => array( 'Glass', 'Recycled Plastic', 'Aluminium', 'Refill Pouch' ),
				),
			);
		}
	}
}
