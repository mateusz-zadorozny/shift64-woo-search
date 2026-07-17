<?php
/**
 * Index builder — transforms WC_Product into Redis hash and manages the index.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product indexer — builds and stores Redis hash data for WC products.
 */
class Shift64_Woo_Search_Indexer {

	/**
	 * Redis connection instance.
	 *
	 * @var Shift64_Woo_Search_Redis
	 */
	private $redis;

	/**
	 * Map of diacritical characters to their ASCII equivalents.
	 *
	 * @var array
	 */
	private static $diacritics_map = array(
		'ą' => 'a',
		'ć' => 'c',
		'ę' => 'e',
		'ł' => 'l',
		'ń' => 'n',
		'ó' => 'o',
		'ś' => 's',
		'ź' => 'z',
		'ż' => 'z',
		'Ą' => 'A',
		'Ć' => 'C',
		'Ę' => 'E',
		'Ł' => 'L',
		'Ń' => 'N',
		'Ó' => 'O',
		'Ś' => 'S',
		'Ź' => 'Z',
		'Ż' => 'Z',
		'à' => 'a',
		'â' => 'a',
		'ä' => 'a',
		'é' => 'e',
		'è' => 'e',
		'ê' => 'e',
		'ë' => 'e',
		'ï' => 'i',
		'î' => 'i',
		'ô' => 'o',
		'ù' => 'u',
		'û' => 'u',
		'ü' => 'u',
		'ÿ' => 'y',
		'ç' => 'c',
		'ñ' => 'n',
		'ß' => 'ss',
	);

	/**
	 * Replace diacritical characters with ASCII equivalents.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	public static function strip_diacritics( $text ) {
		return strtr( $text, self::$diacritics_map );
	}

	/**
	 * Extract unit from attribute label, e.g. "długość wstęgi [m]" → "m".
	 *
	 * Matches trailing bracketed alphabetic tokens only.
	 *
	 * @param string $attr_name Attribute display label.
	 * @return string Unit or empty string if none detected.
	 */
	public static function extract_unit_from_attribute_name( $attr_name ) {
		if ( preg_match( '/\[([a-zA-Z]+)\]\s*$/u', (string) $attr_name, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * Generate unit-appended tokens for a numeric value or numeric range.
	 *
	 * Integers only. Decimals are rejected on purpose: the query sanitizer strips
	 * `.` and `,` (see Shift64_Woo_Search_Query::sanitize_query), and RediSearch's
	 * default tokenizer also splits on `.`, so a user query like "1.5kg" would
	 * become "15kg" and never match an indexed "1.5kg". Keeping indexer and
	 * search consistent beats producing unreachable tokens.
	 *
	 * Examples (unit = "s"):
	 *   "30"         → ["30s"]
	 *   "10 - 12"    → ["10-12s", "10s", "12s"]
	 *   "10-15"      → ["10-15s", "10s", "15s"]
	 *   "1.5"        → []
	 *   "abc"        → []
	 *
	 * @param string $value Raw attribute value.
	 * @param string $unit  Unit extracted from attribute label.
	 * @return array Additional tokens (may be empty).
	 */
	public static function expand_value_with_unit( $value, $unit ) {
		$value = trim( (string) $value );
		$unit  = trim( (string) $unit );
		if ( '' === $value || '' === $unit ) {
			return array();
		}
		// Integer range: "10 - 12", "10-15".
		if ( preg_match( '/^(\d+)\s*-\s*(\d+)$/', $value, $m ) ) {
			return array(
				$m[1] . '-' . $m[2] . $unit,
				$m[1] . $unit,
				$m[2] . $unit,
			);
		}
		// Single integer.
		if ( preg_match( '/^\d+$/', $value ) ) {
			return array( $value . $unit );
		}
		return array();
	}

	/**
	 * Constructor.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 */
	public function __construct( $redis ) {
		$this->redis = $redis;
	}

	/**
	 * Transform a WC_Product into a flat hash array for Redis.
	 *
	 * @param WC_Product $product Product to transform.
	 * @return array|false
	 */
	public function build_product_data( $product ) {
		if ( ! $product || ! $product->get_id() ) {
			return false;
		}

		// Skip non-published products.
		if ( 'publish' !== get_post_status( $product->get_id() ) ) {
			return false;
		}

		// Categories — direct assignments + full ancestor chain. The hierarchy is
		// stored in BOTH the TAG field (for filtering: a query for parent term X
		// matches products assigned to descendants X/A, X/B, …) and the TEXT
		// field (for full-text scoring on category names).
		$categories = array();
		$terms      = get_the_terms( $product->get_id(), 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = $term->name;
				$ancestors    = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
				foreach ( $ancestors as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, 'product_cat' );
					if ( $ancestor && ! is_wp_error( $ancestor ) ) {
						$categories[] = $ancestor->name;
					}
				}
			}
		}
		$categories = array_values( array_unique( $categories ) );

		// Brands (pa_brand taxonomy or custom).
		$brands      = array();
		$brand_terms = get_the_terms( $product->get_id(), 'product_brand' );
		if ( ! $brand_terms || is_wp_error( $brand_terms ) ) {
			$brand_terms = get_the_terms( $product->get_id(), 'pa_brand' );
		}
		if ( $brand_terms && ! is_wp_error( $brand_terms ) ) {
			foreach ( $brand_terms as $term ) {
				$brands[] = $term->name;
			}
		}

		// Tags.
		$tags      = array();
		$tag_terms = get_the_terms( $product->get_id(), 'product_tag' );
		if ( $tag_terms && ! is_wp_error( $tag_terms ) ) {
			foreach ( $tag_terms as $term ) {
				$tags[] = $term->name;
			}
		}

		// Attributes as readable string.
		$attributes_parts = array();
		$attrs            = $product->get_attributes();
		if ( $attrs ) {
			foreach ( $attrs as $attr ) {
				if ( is_a( $attr, 'WC_Product_Attribute' ) ) {
					$name    = wc_attribute_label( $attr->get_name() );
					$options = $attr->get_options();
					if ( $attr->is_taxonomy() ) {
						$values = array();
						foreach ( $options as $term_id ) {
							$term = get_term( $term_id );
							if ( $term && ! is_wp_error( $term ) ) {
								$values[] = $term->name;
							}
						}
						$options = $values;
					}
					if ( ! empty( $options ) ) {
						$unit        = self::extract_unit_from_attribute_name( $name );
						$final_parts = $options;
						if ( '' !== $unit ) {
							foreach ( $options as $opt ) {
								$extra = self::expand_value_with_unit( $opt, $unit );
								if ( ! empty( $extra ) ) {
									$final_parts = array_merge( $final_parts, $extra );
								}
							}
						}
						$attributes_parts[] = $name . ': ' . implode( ', ', $final_parts );
					}
				}
			}
		}

		// Per-attribute TAG fields for faceted filtering.
		$filter_attrs  = Shift64_Woo_Search_Schema::get_filter_attributes();
		$attr_tag_data = array();
		if ( $attrs && ! empty( $filter_attrs ) ) {
			foreach ( $attrs as $attr ) {
				if ( is_a( $attr, 'WC_Product_Attribute' ) && $attr->is_taxonomy() ) {
					$taxonomy = $attr->get_name();
					if ( in_array( $taxonomy, $filter_attrs, true ) ) {
						$values = array();
						foreach ( $attr->get_options() as $term_id ) {
							$term = get_term( $term_id );
							if ( $term && ! is_wp_error( $term ) ) {
								// Sanitize pipe characters to prevent TAG separator conflicts.
								$values[] = str_replace( '|', '-', $term->name );
							}
						}
						if ( ! empty( $values ) ) {
							$attr_tag_data[ 'attr_' . $taxonomy ] = implode( '|', $values );
						}
					}
				}
			}
		}

		// Index only the parent product SKU. Variations are intentionally excluded
		// from product retrieval, even when they have their own WooCommerce SKUs.
		$sku      = $product->get_sku();
		$sku_text = $sku;

		// Old catalog number — append to sku_text so it is searchable with the same weight.
		$old_number = get_post_meta( $product->get_id(), 'old_number', true );
		if ( ! empty( $old_number ) ) {
			$sku_text = trim( $sku_text . ' ' . $old_number );
		}

		// Image URL.
		$image_url = '';
		$image_id  = $product->get_image_id();
		if ( $image_id ) {
			$image_url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
		}
		if ( ! $image_url ) {
			$image_url = wc_placeholder_img_src( 'thumbnail' );
		}

		$title = $product->get_name();
		if ( empty( $title ) ) {
			$title = '(untitled)';
		}

		return array_merge(
			array(
				'title'           => $title,
				'title_ascii'     => self::strip_diacritics( $title ),
				'sku'             => (string) $sku,
				'sku_text'        => (string) $sku_text,
				'short_desc'      => wp_strip_all_tags( $product->get_short_description() ),
				'description'     => wp_strip_all_tags( $product->get_description() ),
				'categories'      => implode( '|', $categories ),
				'categories_text' => implode( ' ', $categories ),
				'brands'          => implode( '|', $brands ),
				'tags'            => implode( '|', $tags ),
				'attributes'      => implode( ', ', $attributes_parts ),
				'image_url'       => (string) $image_url,
				'permalink'       => get_permalink( $product->get_id() ),
				'price'           => (float) $product->get_price(),
				'regular_price'   => (float) $product->get_regular_price(),
				'stock_status'    => $product->get_stock_status(),
				'visibility'      => $product->get_catalog_visibility(),
				'product_type'    => $product->get_type(),
				'old_number'      => (string) $old_number,
				'promoted'        => get_post_meta( $product->get_id(), '_shift64_woo_search_promoted', true ) ? 'yes' : 'no',
				'excluded'        => get_post_meta( $product->get_id(), '_shift64_woo_search_excluded', true ) ? 'yes' : 'no',
				'menu_order'      => (int) $product->get_menu_order(),
				'total_sales'     => (int) $product->get_total_sales(),
				'average_rating'  => (float) $product->get_average_rating(),
				'post_id'         => (int) $product->get_id(),
			),
			$attr_tag_data
		);
	}

	/**
	 * Index a single product into Redis.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function index_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$data = $this->build_product_data( $product );
		if ( ! $data ) {
			return false;
		}

		$key    = $this->redis->get_product_key( $product_id );
		$client = $this->redis->get_client();

		if ( ! $client ) {
			return false;
		}

		try {
			// Build flat array for HSET: key, field1, val1, field2, val2...
			$args = array();
			foreach ( $data as $field => $value ) {
				$args[] = $field;
				$args[] = (string) $value;
			}

			$client->hMSet( $key, $data );
			return true;
		} catch ( RedisException $e ) {
			return false;
		}
	}

	/**
	 * Remove a product from the Redis index.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function remove_product( $product_id ) {
		$client = $this->redis->get_client();
		if ( ! $client ) {
			return false;
		}

		$key = $this->redis->get_product_key( $product_id );

		try {
			$client->del( $key );
			return true;
		} catch ( RedisException $e ) {
			return false;
		}
	}

	/**
	 * Cache all WooCommerce product categories to Redis as JSON.
	 *
	 * Stores at {prefix}:categories for fast lookup in the SHORTINIT endpoint.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 * @return int Number of categories cached.
	 */
	public static function cache_categories_to_redis( $redis ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'fields'     => 'all',
			)
		);

		// On query error (e.g. taxonomy not registered yet during early bootstrap),
		// don't touch the cache — a transient WP failure shouldn't clobber a
		// previously-valid list. Empty result is different: it means the site
		// genuinely has no categories and the cache must reflect that.
		if ( is_wp_error( $terms ) ) {
			return 0;
		}

		// Per-category autocomplete boost multipliers, keyed by term_id.
		$boosts = get_option( 'shift64_woo_search_category_boosts', array() );
		if ( ! is_array( $boosts ) ) {
			$boosts = array();
		}

		// Categories the administrator has hidden from the suggestion list.
		// Applied here at blob-build time (rather than in the endpoint) so an
		// excluded category simply never enters {prefix}:categories — the
		// SHORTINIT endpoint needs no knowledge of the exclusion list. Product
		// search results are unaffected; this only hides the suggestion row.
		$excluded = get_option( 'shift64_woo_search_category_suggest_exclude', array() );
		$excluded = is_array( $excluded ) ? array_map( 'intval', $excluded ) : array();
		$excluded = array_flip( $excluded );

		$categories = array();
		if ( ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( isset( $excluded[ (int) $term->term_id ] ) ) {
					continue;
				}
				$url = get_term_link( $term, 'product_cat' );
				if ( is_wp_error( $url ) ) {
					continue;
				}
				$entry = array(
					'name'       => $term->name,
					'name_ascii' => self::strip_diacritics( mb_strtolower( $term->name ) ),
					'slug'       => $term->slug,
					'url'        => $url,
					'count'      => (int) $term->count,
				);
				// Only emit a boost field when it deviates from the 1.0 default,
				// keeping the blob compact and the endpoint default unambiguous.
				if ( isset( $boosts[ $term->term_id ] ) ) {
					$factor = (float) $boosts[ $term->term_id ];
					if ( $factor > 0 && 1.0 !== $factor ) {
						$entry['boost'] = $factor;
					}
				}
				$categories[] = $entry;
			}
		}

		// Write even when empty — clears stale JSON when the last (or last
		// non-empty) category is deleted.
		$key = $redis->get_prefix() . ':categories';
		$redis->raw_command( 'SET', $key, wp_json_encode( $categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

		return count( $categories );
	}

	/**
	 * Reindex all published products.
	 *
	 * @param callable|null $callback Optional progress callback receiving ($indexed, $total).
	 * @return int Number of products indexed.
	 */
	public function reindex_all( $callback = null ) {
		$batch_size = 100;
		$page       = 1;
		$indexed    = 0;

		// Count total for progress.
		$wc_counts = wp_count_posts( 'product' );
		$total     = isset( $wc_counts->publish ) ? (int) $wc_counts->publish : 0;

		$client = $this->redis->get_client();
		if ( ! $client ) {
			return 0;
		}

		while ( true ) {
			$args = array(
				'status'  => 'publish',
				'limit'   => $batch_size,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
			);

			$products = wc_get_products( $args );

			if ( empty( $products ) ) {
				break;
			}

			// Use pipeline for batch performance.
			try {
				$client->multi( Redis::PIPELINE );

				foreach ( $products as $product ) {
					$data = $this->build_product_data( $product );
					if ( $data ) {
						$key = $this->redis->get_product_key( $product->get_id() );
						$client->hMSet( $key, array_map( 'strval', $data ) );
						++$indexed;
					}
				}

				$client->exec();
			} catch ( RedisException $e ) {
				// If pipeline fails, try individual inserts.
				foreach ( $products as $product ) {
					if ( $this->index_product( $product->get_id() ) ) {
						++$indexed;
					}
				}
			}

			if ( is_callable( $callback ) ) {
				call_user_func( $callback, $indexed, $total );
			}

			// Free memory: clear WC product cache and detach objects.
			unset( $products );
			if ( function_exists( 'wc_get_container' ) ) {
				// WC 7+ — clear the data stores cache.
				wp_cache_flush_group( 'products' );
			}
			wp_cache_flush_runtime();

			++$page;
		}

		return $indexed;
	}
}
