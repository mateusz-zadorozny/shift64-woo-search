/**
 * Option-list settings owned by the Product Filters parent.
 *
 * Selection mode, counts, ordering, and the button labels describe the filter
 * row rather than one facet: a merchant sets them once and every pill obeys.
 * They therefore live on the parent and travel down as block context. Only
 * facet identity, the pill's own label, and the AND/OR operator — meaningless
 * for facets whose index field cannot do AND — stay per-pill.
 *
 * Keep the defaults in sync with product-filters/block.json and
 * Shift64_Woo_Search_Filter_Blocks::pill_settings().
 */

export const PILL_SETTING_DEFAULTS = {
	selectionMode: 'multiple',
	showCounts: true,
	hideEmpty: true,
	orderBy: 'count-desc',
	maxOptions: 0,
	applyLabel: '',
	clearLabel: '',
};

const CONTEXT_NAMESPACE = 'shift64WooSearch/';

/**
 * Read the parent-provided settings out of a pill's block context.
 *
 * A pill can only exist inside the parent, but context is still absent while a
 * block is being inserted, so every key falls back to its declared default.
 *
 * @param {Object} context Block context passed to the pill's edit component.
 * @return {Object} Resolved settings.
 */
export function pillSettings( context ) {
	const source = context && typeof context === 'object' ? context : {};

	return Object.keys( PILL_SETTING_DEFAULTS ).reduce( ( settings, key ) => {
		const value = source[ `${ CONTEXT_NAMESPACE }${ key }` ];
		settings[ key ] =
			undefined === value || null === value
				? PILL_SETTING_DEFAULTS[ key ]
				: value;
		return settings;
	}, {} );
}
