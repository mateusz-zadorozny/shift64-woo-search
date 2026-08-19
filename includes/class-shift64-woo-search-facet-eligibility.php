<?php
/**
 * Facet eligibility service.
 *
 * The single source of editor and runtime facet eligibility for the Product
 * Filters / Filter Pill blocks (spec: .ai/specs/2026-07-30-product-filter-pill-blocks.md).
 * It combines the Facets settings, the live RediSearch index schema, and
 * WooCommerce taxonomy existence into one closed set of facet entries, each
 * carrying a readiness status.
 *
 * The spec names this component "Facet Registry", but that class name is
 * already taken by the legacy per-request facet-context registry
 * (`Shift64_Woo_Search_Facet_Registry`), so the eligibility source lives here
 * under a non-colliding name.
 *
 * Readiness never derives from an incoming `filter_*` parameter — only from
 * settings, the index schema, and taxonomy existence. Attribute facets are
 * ready only when their `attr_{taxonomy}` TAG field exists in the live index,
 * which is the authoritative record of what the last completed rebuild
 * contains; category and brand TAG fields are part of every index build.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes per-facet eligibility entries.
 */
class Shift64_Woo_Search_Facet_Eligibility {

	const STATUS_READY            = 'ready';
	const STATUS_DISABLED         = 'disabled';
	const STATUS_REBUILD_REQUIRED = 'rebuild-required';
	const STATUS_TAXONOMY_MISSING = 'taxonomy-missing';

	const TYPE_CATEGORY  = 'category';
	const TYPE_BRAND     = 'brand';
	const TYPE_ATTRIBUTE = 'attribute';

	/**
	 * Per-request memo of the live index field list.
	 *
	 * Null means "not fetched yet"; false means "index unavailable".
	 *
	 * @var array|false|null
	 */
	private static $index_fields = null;

	/**
	 * All known facet entries keyed by facet key.
	 *
	 * The facet key equals the taxonomy slug — a closed set built from the
	 * category toggle, the brand toggle, and the union of configured filter
	 * attributes and registered WooCommerce attribute taxonomies.
	 *
	 * @param Shift64_Woo_Search_Redis|null $redis Optional connection (tests).
	 * @return array<string,array{key:string,taxonomy:string,type:string,label:string,operators:array,status:string,redis_field:string}> Entries; redis_field is the aggregation bucket key (categories|brands|attr_{taxonomy}).
	 */
	public static function get_entries( $redis = null ) {
		$fields  = self::index_fields( $redis );
		$entries = array();

		$categories_enabled     = 'yes' === get_option( 'shift64_woo_search_filter_categories_enabled', 'yes' );
		$entries['product_cat'] = array(
			'key'         => 'product_cat',
			'taxonomy'    => 'product_cat',
			'type'        => self::TYPE_CATEGORY,
			'label'       => self::taxonomy_label( 'product_cat', __( 'Category', 'shift64-woo-search' ) ),
			'operators'   => array( 'or' ),
			'status'      => self::core_status( $categories_enabled, 'product_cat', $fields ),
			'redis_field' => 'categories',
		);

		$brands_enabled           = 'yes' === get_option( 'shift64_woo_search_filter_brands_enabled', 'no' );
		$entries['product_brand'] = array(
			'key'         => 'product_brand',
			'taxonomy'    => 'product_brand',
			'type'        => self::TYPE_BRAND,
			'label'       => self::taxonomy_label( 'product_brand', __( 'Brand', 'shift64-woo-search' ) ),
			'operators'   => array( 'or' ),
			'status'      => self::core_status( $brands_enabled, 'product_brand', $fields ),
			'redis_field' => 'brands',
		);

		foreach ( self::attribute_taxonomies() as $taxonomy => $selected ) {
			$entries[ $taxonomy ] = array(
				'key'         => $taxonomy,
				'taxonomy'    => $taxonomy,
				'type'        => self::TYPE_ATTRIBUTE,
				'label'       => self::attribute_label( $taxonomy ),
				'operators'   => array( 'or', 'and' ),
				'status'      => self::attribute_status( $taxonomy, $selected, $fields ),
				'redis_field' => 'attr_' . $taxonomy,
			);
		}

		/**
		 * Filter the computed facet eligibility entries.
		 *
		 * Statuses may be adjusted (e.g. forced ready in isolated tests), but
		 * the key set stays closed: consumers ignore entries whose key does
		 * not match their saved facet configuration.
		 *
		 * @param array $entries Facet entries keyed by facet key.
		 */
		return apply_filters( 'shift64_woo_search_facet_entries', $entries );
	}

	/**
	 * One entry by facet key, or null for a key outside the closed set.
	 *
	 * @param string                        $key   Facet key (taxonomy slug).
	 * @param Shift64_Woo_Search_Redis|null $redis Optional connection (tests).
	 * @return array|null
	 */
	public static function get_entry( $key, $redis = null ) {
		$entries = self::get_entries( $redis );
		return isset( $entries[ $key ] ) ? $entries[ $key ] : null;
	}

	/**
	 * Only the entries whose status is ready.
	 *
	 * @param Shift64_Woo_Search_Redis|null $redis Optional connection (tests).
	 * @return array<string,array>
	 */
	public static function get_ready( $redis = null ) {
		return array_filter(
			self::get_entries( $redis ),
			static function ( $entry ) {
				return self::STATUS_READY === $entry['status'];
			}
		);
	}

	/**
	 * Clear the per-request index-schema memo (tests and long-running runtimes).
	 */
	public static function reset() {
		self::$index_fields = null;
	}

	/**
	 * Status for category/brand facets, whose TAG fields exist in every index build.
	 *
	 * @param bool        $enabled  Facet toggle from settings.
	 * @param string      $taxonomy Taxonomy slug.
	 * @param array|false $fields   Live index field names, or false when unavailable.
	 * @return string
	 */
	private static function core_status( $enabled, $taxonomy, $fields ) {
		if ( ! $enabled ) {
			return self::STATUS_DISABLED;
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return self::STATUS_TAXONOMY_MISSING;
		}
		if ( false === $fields ) {
			return self::STATUS_REBUILD_REQUIRED;
		}
		return self::STATUS_READY;
	}

	/**
	 * Status for an attribute facet.
	 *
	 * @param string      $taxonomy Attribute taxonomy slug (pa_*).
	 * @param bool        $selected Whether the attribute is selected in settings.
	 * @param array|false $fields   Live index field names, or false when unavailable.
	 * @return string
	 */
	private static function attribute_status( $taxonomy, $selected, $fields ) {
		if ( ! $selected ) {
			return self::STATUS_DISABLED;
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return self::STATUS_TAXONOMY_MISSING;
		}
		if ( false === $fields || ! in_array( 'attr_' . $taxonomy, $fields, true ) ) {
			return self::STATUS_REBUILD_REQUIRED;
		}
		return self::STATUS_READY;
	}

	/**
	 * The closed attribute-taxonomy set: configured filter attributes united
	 * with registered WooCommerce attribute taxonomies.
	 *
	 * @return array<string,bool> Taxonomy slug => selected in settings.
	 */
	private static function attribute_taxonomies() {
		$selected = Shift64_Woo_Search_Schema::get_filter_attributes();
		$selected = array_values( array_filter( array_map( 'sanitize_key', $selected ) ) );

		$known = array();
		foreach ( $selected as $taxonomy ) {
			$known[ $taxonomy ] = true;
		}

		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attribute ) {
				if ( empty( $attribute->attribute_name ) ) {
					continue;
				}
				$taxonomy = 'pa_' . sanitize_key( $attribute->attribute_name );
				if ( ! isset( $known[ $taxonomy ] ) ) {
					$known[ $taxonomy ] = false;
				}
			}
		}

		ksort( $known, SORT_STRING );
		return $known;
	}

	/**
	 * Translated label for a taxonomy, with a default when unregistered.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $fallback Default label.
	 * @return string
	 */
	private static function taxonomy_label( $taxonomy, $fallback ) {
		$object = get_taxonomy( $taxonomy );
		if ( $object && ! empty( $object->labels->singular_name ) ) {
			return $object->labels->singular_name;
		}
		return $fallback;
	}

	/**
	 * Merchant-facing label for an attribute facet.
	 *
	 * @param string $taxonomy Attribute taxonomy slug (pa_*).
	 * @return string
	 */
	private static function attribute_label( $taxonomy ) {
		if ( function_exists( 'wc_attribute_label' ) && taxonomy_exists( $taxonomy ) ) {
			$label = wc_attribute_label( $taxonomy );
			if ( '' !== $label ) {
				return $label;
			}
		}
		return self::taxonomy_label( $taxonomy, preg_replace( '/^pa_/', '', $taxonomy ) );
	}

	/**
	 * Live index field names, memoized per request.
	 *
	 * @param Shift64_Woo_Search_Redis|null $redis Optional connection (tests).
	 * @return array|false
	 */
	private static function index_fields( $redis = null ) {
		if ( null !== $redis ) {
			return self::fetch_index_fields( $redis );
		}
		if ( null === self::$index_fields ) {
			self::$index_fields = self::fetch_index_fields( Shift64_Woo_Search_Redis::get_instance() );
		}
		return self::$index_fields;
	}

	/**
	 * Fetch the field list from the live index.
	 *
	 * @param Shift64_Woo_Search_Redis $redis Redis connection instance.
	 * @return array|false
	 */
	private static function fetch_index_fields( $redis ) {
		if ( ! $redis->is_available() ) {
			return false;
		}
		return Shift64_Woo_Search_Schema::get_index_field_names( $redis );
	}
}
