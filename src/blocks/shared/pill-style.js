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

export const ACTION_STYLE_VARS = [
	{
		path: [ 'typography', 'fontSize' ],
		token: '--s64ws-action-font-size',
	},
	{ path: [ 'border', 'radius' ], token: '--s64ws-action-radius' },
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
 * Turn a stored style object into CSS custom properties.
 *
 * @param {Object} style     Stored style attribute.
 * @param {Array}  variables CSS token definitions.
 * @return {Object} Map of custom property name to value; empty when unstyled.
 */
function styleToVars( style, variables ) {
	if ( ! style || typeof style !== 'object' ) {
		return {};
	}

	return variables.reduce( ( vars, { path, token } ) => {
		const value = readPath( style, path );
		if ( typeof value === 'string' && value !== '' ) {
			vars[ token ] = normalizePresetValue( value );
		}
		return vars;
	}, {} );
}

export function pillStyleToVars( pillStyle ) {
	return styleToVars( pillStyle, PILL_STYLE_VARS );
}

export function actionStyleToVars( actionStyle ) {
	return styleToVars( actionStyle, ACTION_STYLE_VARS );
}

/**
 * Immutably set one value inside a style object, pruning empty branches.
 *
 * Pruning matters: a style left holding `{ color: {} }` would serialize empty
 * objects into post content and make "is this block styled?" checks lie.
 *
 * @param {Object}        style Current style attribute.
 * @param {Array<string>} path  Path to write.
 * @param {string|number} value Next value; empty clears the path.
 * @return {Object} Next style attribute.
 */
function setStyleValue( style, path, value ) {
	const [ key, ...rest ] = path;
	const source = style && typeof style === 'object' ? { ...style } : {};

	if ( rest.length === 0 ) {
		if ( value === undefined || value === '' || value === null ) {
			delete source[ key ];
		} else {
			source[ key ] = value;
		}
	} else {
		const branch = setStyleValue( source[ key ], rest, value );
		if ( Object.keys( branch ).length === 0 ) {
			delete source[ key ];
		} else {
			source[ key ] = branch;
		}
	}

	return source;
}

export function setPillStyleValue( pillStyle, path, value ) {
	return setStyleValue( pillStyle, path, value );
}

export function setActionStyleValue( actionStyle, path, value ) {
	return setStyleValue( actionStyle, path, value );
}
