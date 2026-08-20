/**
 * Pill style tokens shared by the editor preview and the PHP renderer.
 *
 * WordPress 7.1 shipped per-block interactive states (`:hover`, `:focus`,
 * `:active`), but the opt-in is a hardcoded core allowlist — `core/button` and
 * `core/navigation-link` only, in both `WP_Theme_JSON::VALID_BLOCK_PSEUDO_SELECTORS`
 * and the block-editor bundle's `VALID_BLOCK_PSEUDO_STATES`. Third-party blocks
 * cannot register for it. So the Product Filters parent owns one `pillStyle`
 * attribute shaped exactly like core's `style` object — including the `:hover`
 * key — and we resolve it to CSS custom properties ourselves. When core opens
 * the allowlist the stored data already matches and this module becomes a
 * deletion, not a migration.
 *
 * Styling lives on the parent, never on the individual pill: the pill's own
 * block wrapper is the `<div>` around `<details>`, so a wrapper background
 * paints the box around the control instead of the control itself.
 *
 * Keep this map in sync with Shift64_Woo_Search_Filter_Blocks::pill_style_vars().
 */

export const PILL_STYLE_VARS = [
	{ path: [ 'color', 'text' ], token: '--s64ws-pill-color' },
	{ path: [ 'color', 'background' ], token: '--s64ws-pill-bg' },
	{ path: [ 'border', 'color' ], token: '--s64ws-pill-border-color' },
	{ path: [ 'border', 'width' ], token: '--s64ws-pill-border-width' },
	{ path: [ 'border', 'radius' ], token: '--s64ws-pill-radius' },
	{ path: [ ':hover', 'color', 'text' ], token: '--s64ws-pill-color-hover' },
	{
		path: [ ':hover', 'color', 'background' ],
		token: '--s64ws-pill-bg-hover',
	},
	{
		path: [ ':hover', 'border', 'color' ],
		token: '--s64ws-pill-border-color-hover',
	},
];

/**
 * Resolve a theme preset reference to a CSS custom property reference.
 *
 * Mirrors core's wp_normalize_state_preset_vars(): state styles are emitted as
 * declarations, so `var:preset|color|accent-3` has to become
 * `var(--wp--preset--color--accent-3)` rather than a preset classname.
 *
 * @param {string} value Stored style value.
 * @return {string} CSS-ready value.
 */
export function normalizePresetValue( value ) {
	if ( typeof value !== 'string' || ! value.startsWith( 'var:preset|' ) ) {
		return value;
	}

	return `var(--wp--${ value
		.slice( 'var:'.length )
		.replace( /\|/g, '--' ) })`;
}

function readPath( source, path ) {
	return path.reduce(
		( carry, key ) =>
			carry && typeof carry === 'object' ? carry[ key ] : undefined,
		source
	);
}

/**
 * Turn a stored pillStyle object into CSS custom properties.
 *
 * @param {Object} pillStyle Stored pillStyle attribute.
 * @return {Object} Map of custom property name to value; empty when unstyled.
 */
export function pillStyleToVars( pillStyle ) {
	if ( ! pillStyle || typeof pillStyle !== 'object' ) {
		return {};
	}

	return PILL_STYLE_VARS.reduce( ( vars, { path, token } ) => {
		const value = readPath( pillStyle, path );
		if ( typeof value === 'string' && value !== '' ) {
			vars[ token ] = normalizePresetValue( value );
		}
		return vars;
	}, {} );
}

/**
 * Immutably set one value inside the pillStyle object, pruning empty branches.
 *
 * Pruning matters: a `pillStyle` left holding `{ color: {} }` would serialize
 * empty objects into post content and make "is this block styled?" checks lie.
 *
 * @param {Object}        pillStyle Current pillStyle attribute.
 * @param {Array<string>} path      Path to write.
 * @param {string|number} value     Next value; empty clears the path.
 * @return {Object} Next pillStyle attribute.
 */
export function setPillStyleValue( pillStyle, path, value ) {
	const [ key, ...rest ] = path;
	const source =
		pillStyle && typeof pillStyle === 'object' ? { ...pillStyle } : {};

	if ( rest.length === 0 ) {
		if ( value === undefined || value === '' || value === null ) {
			delete source[ key ];
		} else {
			source[ key ] = value;
		}
	} else {
		const branch = setPillStyleValue( source[ key ], rest, value );
		if ( Object.keys( branch ).length === 0 ) {
			delete source[ key ];
		} else {
			source[ key ] = branch;
		}
	}

	return source;
}
