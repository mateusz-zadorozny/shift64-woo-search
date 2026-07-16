<?php
/**
 * Frontend filter renderer — outputs faceted search filters on archive pages.
 *
 * Renders pill-style dropdown filters for categories and product attributes,
 * using compact pill controls with dropdown panels.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Faceted search filter renderer.
 */
class Shift64_Woo_Search_Filters {

	/**
	 * Register rendering hook.
	 */
	public function __construct() {
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render_filters' ), 5 );
	}

	/**
	 * Render the filter bar above the product grid.
	 *
	 * Gated by the facet registry — any interceptor (search archive, taxonomy
	 * archive, future brand archive) that registers itself becomes the active
	 * context provider. Renderer doesn't know which one it's talking to.
	 */
	public function render_filters() {
		$context = Shift64_Woo_Search_Facet_Registry::get_current();
		if ( null === $context ) {
			return;
		}

		$facet_data = $context->get_facet_data();
		if ( empty( $facet_data ) ) {
			return;
		}

		$active_filters = $context->get_active_filters();
		$filter_attrs   = Shift64_Woo_Search_Schema::get_filter_attributes();
		// O(1) lookup for the global "is this attribute exposed at all?" toggle.
		// Facet order comes from $facet_data. The global option remains a
		// kill switch so administrators can hide an attribute everywhere.
		$filter_attrs_set = array_flip( $filter_attrs );
		$has_any_filter   = false;

		// Check if there's at least one filter to show.
		if ( 'yes' === get_option( 'shift64_woo_search_filter_categories_enabled', 'yes' ) && ! empty( $facet_data['categories'] ) ) {
			$has_any_filter = true;
		}
		if ( ! $has_any_filter ) {
			foreach ( $facet_data as $field_name => $values ) {
				if ( 0 !== strpos( $field_name, 'attr_' ) ) {
					continue;
				}
				if ( empty( $values ) ) {
					continue;
				}
				$taxonomy = substr( $field_name, strlen( 'attr_' ) );
				if ( ! isset( $filter_attrs_set[ $taxonomy ] ) ) {
					continue;
				}
				$has_any_filter = true;
				break;
			}
		}

		if ( ! $has_any_filter ) {
			return;
		}

		// Build excluded category IDs list.
		$excluded_cat_ids = $this->get_excluded_category_ids();

		// Collect filter groups for reuse in mobile modal.
		$filter_groups = array();

		echo '<div class="shift64-woo-search-filters" id="shift64-woo-search-filters">';

		// Category filter.
		if ( 'yes' === get_option( 'shift64_woo_search_filter_categories_enabled', 'yes' ) && ! empty( $facet_data['categories'] ) ) {
			$filtered_cats = $this->filter_categories( $facet_data['categories'], $excluded_cat_ids );
			if ( ! empty( $filtered_cats ) ) {
				$this->render_filter_group(
					'product_cat',
					__( 'Category', 'shift64-woo-search' ),
					$filtered_cats,
					isset( $active_filters['category'] ) ? $active_filters['category'] : array(),
					'product_cat'
				);
				$filter_groups[] = array(
					'key'      => 'product_cat',
					'label'    => __( 'Category', 'shift64-woo-search' ),
					'values'   => $filtered_cats,
					'active'   => isset( $active_filters['category'] ) ? $active_filters['category'] : array(),
					'taxonomy' => 'product_cat',
				);
			}
		}

		// Attribute filters — iterate in $facet_data order so the per-category
		// ordering set by the taxonomy-archive interceptor is preserved.
		foreach ( $facet_data as $field_name => $values ) {
			if ( 0 !== strpos( $field_name, 'attr_' ) ) {
				continue;
			}
			if ( empty( $values ) ) {
				continue;
			}

			$taxonomy = substr( $field_name, strlen( 'attr_' ) );

			// Global kill-switch: admin can hide an attribute even if the
			// backend says "show it on this category".
			if ( ! isset( $filter_attrs_set[ $taxonomy ] ) ) {
				continue;
			}

			$attr_label = wc_attribute_label( $taxonomy );

			// Extract unit from label (e.g. "Czas suszenia [s]" → label "Czas suszenia", unit "s").
			$unit = '';
			if ( preg_match( '/\s*\[([^\]]+)\]\s*$/', $attr_label, $m ) ) {
				$unit       = $m[1];
				$attr_label = trim( preg_replace( '/\s*\[([^\]]+)\]\s*$/', '', $attr_label ) );
			}

			// Filter out empty values and sort naturally.
			$clean_values = $this->clean_and_sort_facet_values( $values, $unit );

			if ( ! empty( $clean_values ) ) {
				$this->render_filter_group(
					$taxonomy,
					$attr_label,
					$clean_values,
					isset( $active_filters[ $field_name ] ) ? $active_filters[ $field_name ] : array(),
					$taxonomy
				);
				$filter_groups[] = array(
					'key'      => $taxonomy,
					'label'    => $attr_label,
					'values'   => $clean_values,
					'active'   => isset( $active_filters[ $field_name ] ) ? $active_filters[ $field_name ] : array(),
					'taxonomy' => $taxonomy,
				);
			}
		}

		// "Clear filters" button — only when at least one filter is active.
		if ( ! empty( $active_filters ) ) {
			echo '<button type="button" class="shift64-woo-search-filters__clear" id="shift64-woo-search-filters-clear">';
			echo esc_html__( 'Clear filters', 'shift64-woo-search' );
			echo '</button>';
		}

		// Mobile UI: trigger bar + filter modal + sort sheet.
		$this->render_mobile_ui( $filter_groups, $active_filters );

		echo '</div>';
	}

	/**
	 * Get list of category term IDs that should be excluded from filters.
	 *
	 * Merges the default WooCommerce category with the plugin-level exclusion
	 * list. The plugin does not consume theme-specific settings or term metadata.
	 *
	 * @return array Array of term IDs to exclude.
	 */
	private function get_excluded_category_ids() {
		$excluded = array();

		// Always exclude "Uncategorized" (default WooCommerce category).
		$uncategorized = get_option( 'default_product_cat', 0 );
		if ( $uncategorized ) {
			$excluded[] = (int) $uncategorized;
		}

		// Plugin-level category facet exclusions.
		$plugin_excluded = get_option( 'shift64_woo_search_filter_categories_excluded', array() );
		if ( is_array( $plugin_excluded ) ) {
			foreach ( $plugin_excluded as $id ) {
				$excluded[] = (int) $id;
			}
		}

		return array_unique( $excluded );
	}

	/**
	 * Filter out excluded categories from facet data.
	 *
	 * @param array $facet_values  Facet values with 'value' and 'count' keys.
	 * @param array $excluded_ids  Term IDs to exclude.
	 * @return array Filtered facet values.
	 */
	private function filter_categories( $facet_values, $excluded_ids ) {
		if ( empty( $excluded_ids ) ) {
			return $facet_values;
		}

		$filtered = array();
		foreach ( $facet_values as $facet ) {
			$term = get_term_by( 'name', $facet['value'], 'product_cat' );
			if ( ! $term ) {
				continue;
			}

			// Check global exclusion list.
			if ( in_array( $term->term_id, $excluded_ids, true ) ) {
				continue;
			}

			$filtered[] = $facet;
		}

		return $filtered;
	}

	/**
	 * Clean facet values: remove empties, append unit suffix, sort naturally.
	 *
	 * @param array  $facet_values Facet values with 'value' and 'count' keys.
	 * @param string $unit         Unit to append (e.g. "s", "mm"). Empty string for none.
	 * @return array Cleaned and sorted facet values.
	 */
	private function clean_and_sort_facet_values( $facet_values, $unit = '' ) {
		$clean = array();
		foreach ( $facet_values as $facet ) {
			$val = trim( $facet['value'] );
			if ( '' === $val || 'b. d.' === mb_strtolower( $val ) ) {
				continue;
			}
			if ( '' !== $unit ) {
				$facet['display'] = $val . ' ' . $unit;
			}
			$clean[] = $facet;
		}

		// Natural sort by value.
		usort(
			$clean,
			function ( $a, $b ) {
				return strnatcasecmp( $a['value'], $b['value'] );
			}
		);

		return $clean;
	}

	/**
	 * Render a single filter group as pill button with dropdown.
	 *
	 * @param string $key           URL parameter key (taxonomy slug).
	 * @param string $label         Human-readable filter title.
	 * @param array  $facet_values  Array of ['value' => name, 'count' => int, 'display' => optional].
	 * @param array  $active_values Currently selected term names.
	 * @param string $taxonomy      WordPress taxonomy name for slug lookup.
	 */
	private function render_filter_group( $key, $label, $facet_values, $active_values, $taxonomy ) {
		$active_count = 0;
		foreach ( $facet_values as $facet ) {
			if ( in_array( $facet['value'], $active_values, true ) ) {
				++$active_count;
			}
		}
		$has_active = $active_count > 0;

		echo '<div class="shift64-woo-search-filter' . ( $has_active ? ' shift64-woo-search-filter--active' : '' ) . '" data-filter-key="' . esc_attr( $key ) . '">';

		// Pill button.
		echo '<button type="button" class="shift64-woo-search-filter__pill">';
		echo '<span class="shift64-woo-search-filter__pill-label">' . esc_html( $label ) . '</span>';
		if ( $has_active ) {
			echo ' <span class="shift64-woo-search-filter__pill-count">' . esc_html( $active_count ) . '</span>';
		}
		echo '<svg class="shift64-woo-search-filter__chevron" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		echo '</button>';

		// Dropdown panel.
		echo '<div class="shift64-woo-search-filter__dropdown">';
		echo '<ul class="shift64-woo-search-filter__list">';

		foreach ( $facet_values as $facet ) {
			$name    = $facet['value'];
			$display = isset( $facet['display'] ) ? $facet['display'] : $name;
			$count   = $facet['count'];

			// Map display name to slug for URL.
			$term = get_term_by( 'name', $name, $taxonomy );
			$slug = $term ? $term->slug : sanitize_title( $name );

			$is_active = in_array( $name, $active_values, true );

			echo '<li class="shift64-woo-search-filter__item">';
			echo '<label>';
			echo '<input type="checkbox" class="shift64-woo-search-filter__checkbox"';
			echo ' data-taxonomy="' . esc_attr( $key ) . '"';
			echo ' data-slug="' . esc_attr( $slug ) . '"';
			echo ' value="' . esc_attr( $slug ) . '"';
			if ( $is_active ) {
				echo ' checked="checked"';
			}
			echo ' />';
			echo ' <span class="shift64-woo-search-filter__label">' . esc_html( $display ) . '</span>';
			echo '</label>';
			echo '</li>';
		}

		echo '</ul>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render mobile UI: trigger bar, filter modal, and sort bottom sheet.
	 *
	 * @param array $filter_groups Collected filter groups from render_filters().
	 * @param array $active_filters Currently active filter selections.
	 */
	private function render_mobile_ui( $filter_groups, $active_filters ) {
		$archive     = Shift64_Woo_Search_Archive::get_instance();
		$redis_total = $archive ? $archive->get_redis_total() : null;

		// Current sort from URL.
		$current_orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'relevance'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$has_active   = ! empty( $active_filters );
		$active_count = 0;
		foreach ( $active_filters as $values ) {
			$active_count += count( $values );
		}

		// Inline SVG icons (use currentColor so they inherit button text color).
		$icon_filter = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8.16662 14C8.0404 14 7.91759 13.9591 7.81662 13.8833L5.48328 12.1333C5.41083 12.079 5.35203 12.0085 5.31153 11.9275C5.27103 11.8465 5.24995 11.7572 5.24995 11.6667V8.38833L1.15728 3.78408C0.866618 3.45618 0.676836 3.05131 0.610745 2.61814C0.544653 2.18496 0.605065 1.74192 0.78472 1.34226C0.964374 0.942594 1.25563 0.603318 1.62347 0.365205C1.99131 0.127092 2.4201 0.000276744 2.85828 0L11.1416 0C11.5798 0.000513946 12.0084 0.12754 12.3761 0.365812C12.7438 0.604085 13.0349 0.943467 13.2144 1.34317C13.3938 1.74288 13.454 2.18591 13.3878 2.61902C13.3215 3.05213 13.1316 3.45689 12.8409 3.78467L8.74995 8.38833V13.4167C8.74995 13.5714 8.68849 13.7197 8.57909 13.8291C8.4697 13.9385 8.32133 14 8.16662 14Z" fill="currentColor"/></svg>';
		$icon_sort   = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.5 6.50098C4.60457 6.50098 5.5 7.39641 5.5 8.50098V10.001C5.49974 11.1053 4.60441 12.001 3.5 12.001H2C0.895704 12.0008 0.000261085 11.1052 0 10.001V8.50098C0 7.39649 0.895543 6.50111 2 6.50098H3.5ZM9.5 0C9.8975 0 10.2793 0.157576 10.5605 0.438477L11.8535 1.72949C11.9 1.77591 11.9368 1.83191 11.9619 1.89258C11.987 1.95317 12 2.01841 12 2.08398C12 2.1495 11.987 2.21485 11.9619 2.27539C11.9368 2.33585 11.8998 2.39119 11.8535 2.4375C11.8072 2.48377 11.7519 2.52082 11.6914 2.5459C11.6309 2.57094 11.5655 2.58396 11.5 2.58398C11.4345 2.58395 11.3691 2.57096 11.3086 2.5459C11.248 2.52079 11.1929 2.48384 11.1465 2.4375L10 1.29199V10.708L11.1465 9.56348C11.2404 9.46972 11.3683 9.4169 11.501 9.41699C11.6335 9.41719 11.7608 9.46974 11.8545 9.56348C11.9482 9.65736 12.0011 9.78529 12.001 9.91797C12.0008 10.0506 11.9474 10.1778 11.8535 10.2715L10.5605 11.5625C10.2789 11.8427 9.89726 12 9.5 12C9.10283 11.9999 8.72201 11.8426 8.44043 11.5625L7.14648 10.2715C7.10007 10.2251 7.06325 10.17 7.03809 10.1094C7.01292 10.0488 7.00007 9.98361 7 9.91797C6.99995 9.85233 7.01303 9.78723 7.03809 9.72656C7.06318 9.66585 7.10006 9.60996 7.14648 9.56348C7.19283 9.51715 7.24807 9.4802 7.30859 9.45508C7.36921 9.42995 7.43438 9.41704 7.5 9.41699C7.56566 9.41696 7.63072 9.42999 7.69141 9.45508C7.75207 9.48016 7.80705 9.5171 7.85352 9.56348L9 10.708V1.29199L7.85352 2.4375C7.80709 2.48385 7.75203 2.52081 7.69141 2.5459C7.63075 2.57099 7.56564 2.58401 7.5 2.58398C7.43435 2.58395 7.36924 2.57104 7.30859 2.5459C7.248 2.52077 7.19287 2.48388 7.14648 2.4375C7.10016 2.39115 7.06319 2.33592 7.03809 2.27539C7.01304 2.21485 7.00004 2.1495 7 2.08398C7.00002 2.01847 7.01305 1.95312 7.03809 1.89258C7.06324 1.83191 7.10003 1.77591 7.14648 1.72949L8.44043 0.438477C8.72162 0.157678 9.10262 6.4516e-05 9.5 0ZM3.5 0.000976562C4.60457 0.000976562 5.5 0.896407 5.5 2.00098V3.50098C5.49974 4.60532 4.60441 5.50098 3.5 5.50098H2C0.895705 5.50084 0.00026379 4.60524 0 3.50098V2.00098C0 0.896488 0.895543 0.00110853 2 0.000976562H3.5Z" fill="currentColor"/></svg>';
		$icon_clear  = '<svg width="17" height="17" viewBox="0 0 17 17" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="17" height="17" rx="8.5" fill="currentColor"/><path d="M8.50003 7.49827L5.99569 4.99393L4.99396 5.99566L7.4983 8.5L4.99396 11.0043L5.99569 12.0061L8.50003 9.50173L11.0044 12.0061L12.0061 11.0043L9.50177 8.5L12.0061 5.99566L11.0044 4.99393L8.50003 7.49827Z" fill="white"/></svg>';

		// --- Mobile trigger bar ---
		echo '<div class="shift64-woo-search-mobile-bar">';

		echo '<button type="button" class="shift64-woo-search-mobile-bar__btn" id="shift64-woo-search-mobile-filter-open">';
		echo $icon_filter; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG markup.
		echo ' <span>' . esc_html__( 'Filters', 'shift64-woo-search' ) . '</span>';
		if ( $active_count > 0 ) {
			echo ' <span class="shift64-woo-search-mobile-bar__count">' . esc_html( $active_count ) . '</span>';
		}
		echo '</button>';

		echo '<button type="button" class="shift64-woo-search-mobile-bar__btn" id="shift64-woo-search-mobile-sort-open">';
		echo $icon_sort; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG markup.
		echo ' <span>' . esc_html__( 'Sort', 'shift64-woo-search' ) . '</span>';
		echo '</button>';

		echo '</div>';

		// --- Filter modal ---
		echo '<div class="shift64-woo-search-filter-modal" id="shift64-woo-search-filter-modal">';
		echo '<div class="shift64-woo-search-filter-modal__backdrop"></div>';
		echo '<div class="shift64-woo-search-filter-modal__panel">';

		// Header.
		echo '<div class="shift64-woo-search-filter-modal__header">';
		echo '<h2 class="shift64-woo-search-filter-modal__heading">' . esc_html__( 'Filters', 'shift64-woo-search' ) . '</h2>';
		echo '<button type="button" class="shift64-woo-search-filter-modal__close" aria-label="' . esc_attr__( 'Close', 'shift64-woo-search' ) . '">';
		echo '<img src="' . esc_url( SHIFT64_WOO_SEARCH_URL . 'frontend/icons/back-icon.svg' ) . '" alt="" width="32" height="32" />';
		echo '</button>';
		echo '</div>';

		// Body — accordion sections.
		echo '<div class="shift64-woo-search-filter-modal__body">';
		foreach ( $filter_groups as $group ) {
			$this->render_mobile_filter_section( $group );
		}
		echo '</div>';

		// Footer.
		echo '<div class="shift64-woo-search-filter-modal__footer">';

		echo '<button type="button" class="shift64-woo-search-filter-modal__apply">';
		echo $icon_filter; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG markup.
		echo ' <span class="shift64-woo-search-filter-modal__apply-text">' . esc_html__( 'Show results', 'shift64-woo-search' ) . '</span>';
		echo '</button>';

		echo '<button type="button" class="shift64-woo-search-filter-modal__clear"' . ( $has_active ? '' : ' disabled' ) . '>';
		echo $icon_clear; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG markup.
		echo ' <span>' . esc_html__( 'Clear filters', 'shift64-woo-search' ) . '</span>';
		echo '</button>';

		echo '</div>'; // Footer.
		echo '</div>'; // Panel.
		echo '</div>'; // Modal.

		// --- Sort bottom sheet ---
		$sort_options = array(
			'relevance'  => __( 'Relevance', 'shift64-woo-search' ),
			'price'      => __( 'Price: low to high', 'shift64-woo-search' ),
			'price-desc' => __( 'Price: high to low', 'shift64-woo-search' ),
		);

		echo '<div class="shift64-woo-search-sort-sheet" id="shift64-woo-search-sort-sheet">';
		echo '<div class="shift64-woo-search-sort-sheet__backdrop"></div>';
		echo '<div class="shift64-woo-search-sort-sheet__panel">';

		// Header.
		echo '<div class="shift64-woo-search-sort-sheet__header">';
		echo '<h2 class="shift64-woo-search-sort-sheet__heading">' . esc_html__( 'Sort', 'shift64-woo-search' ) . '</h2>';
		echo '<button type="button" class="shift64-woo-search-sort-sheet__close" aria-label="' . esc_attr__( 'Close', 'shift64-woo-search' ) . '">';
		echo '<img src="' . esc_url( SHIFT64_WOO_SEARCH_URL . 'frontend/icons/back-icon.svg' ) . '" alt="" width="32" height="32" />';
		echo '</button>';
		echo '</div>';

		// Body.
		echo '<div class="shift64-woo-search-sort-sheet__body">';
		foreach ( $sort_options as $value => $label ) {
			echo '<label class="shift64-woo-search-sort-sheet__option">';
			echo '<input type="radio" class="shift64-woo-search-sort-sheet__radio" name="shift64_woo_search_mobile_sort" value="' . esc_attr( $value ) . '"';
			if ( $value === $current_orderby ) {
				echo ' checked="checked"';
			}
			echo ' />';
			echo ' <span>' . esc_html( $label ) . '</span>';
			echo '</label>';
		}
		echo '</div>';

		echo '</div>'; // Panel.
		echo '</div>'; // Sheet.
	}

	/**
	 * Render a single accordion section inside the mobile filter modal.
	 *
	 * @param array $group Filter group data with keys: key, label, values, active, taxonomy.
	 */
	private function render_mobile_filter_section( $group ) {
		$key      = $group['key'];
		$label    = $group['label'];
		$values   = $group['values'];
		$active   = $group['active'];
		$taxonomy = $group['taxonomy'];

		$active_count = 0;
		foreach ( $values as $facet ) {
			if ( in_array( $facet['value'], $active, true ) ) {
				++$active_count;
			}
		}

		$section_class = 'shift64-woo-search-filter-modal__section';
		echo '<div class="' . esc_attr( $section_class ) . '" data-filter-key="' . esc_attr( $key ) . '">';

		// Section header (accordion toggle).
		echo '<button type="button" class="shift64-woo-search-filter-modal__section-toggle">';
		echo '<div class="shift64-woo-search-filter-modal__section-label">';
		echo '<span class="shift64-woo-search-filter-modal__section-title">' . esc_html( $label ) . '</span>';
		if ( $active_count > 0 ) {
			echo '<span class="shift64-woo-search-filter-modal__section-subtitle">' . esc_html(
				sprintf(
					/* translators: %d: number of selected filter options. */
					_n( '%d selected', '%d selected', $active_count, 'shift64-woo-search' ),
					$active_count
				)
			) . '</span>';
		}
		echo '</div>';
		echo '<svg class="shift64-woo-search-filter-modal__chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		echo '</button>';

		// Section body (checkbox list).
		echo '<div class="shift64-woo-search-filter-modal__section-body">';
		echo '<ul class="shift64-woo-search-filter-modal__list">';

		foreach ( $values as $facet ) {
			$name    = $facet['value'];
			$display = isset( $facet['display'] ) ? $facet['display'] : $name;
			$term    = get_term_by( 'name', $name, $taxonomy );
			$slug    = $term ? $term->slug : sanitize_title( $name );

			$is_active = in_array( $name, $active, true );

			echo '<li class="shift64-woo-search-filter-modal__item">';
			echo '<label>';
			echo '<input type="checkbox" class="shift64-woo-search-filter-modal__checkbox"';
			echo ' data-taxonomy="' . esc_attr( $key ) . '"';
			echo ' data-slug="' . esc_attr( $slug ) . '"';
			echo ' value="' . esc_attr( $slug ) . '"';
			if ( $is_active ) {
				echo ' checked="checked"';
			}
			echo ' />';
			echo ' <span class="shift64-woo-search-filter-modal__label">' . esc_html( $display ) . '</span>';
			echo '</label>';
			echo '</li>';
		}

		echo '</ul>';
		echo '</div>';
		echo '</div>';
	}
}
