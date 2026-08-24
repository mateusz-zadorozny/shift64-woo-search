/**
 * Pure helpers for the Product Filters / Filter Pill editor UI.
 * Kept free of runtime-only dependencies so unit tests can import them.
 */
import { __ } from '@wordpress/i18n';

/**
 * Human-readable reason a facet cannot be used yet.
 *
 * @param {string} status Eligibility status.
 * @return {string} Reason text, empty for ready facets.
 */
export function statusReason( status ) {
	switch ( status ) {
		case 'disabled':
			return __(
				'Enable it in Shift64 Results → Facets first.',
				'shift64-woo-search'
			);
		case 'rebuild-required':
			return __(
				'Rebuild the search index to include it.',
				'shift64-woo-search'
			);
		case 'taxonomy-missing':
			return __(
				'Its taxonomy is not registered on this site.',
				'shift64-woo-search'
			);
		default:
			return '';
	}
}

/**
 * Split facets into selectable (ready) and unavailable groups.
 *
 * @param {Array} facets REST facet entries.
 * @return {{ready: Array, unavailable: Array}} Grouped entries.
 */
export function groupFacets( facets ) {
	const ready = [];
	const unavailable = [];
	( facets || [] ).forEach( ( facet ) => {
		if ( facet.status === 'ready' ) {
			ready.push( facet );
		} else {
			unavailable.push( facet );
		}
	} );
	return { ready, unavailable };
}

/**
 * Deterministic sample count for editor previews. Stable for a given facet
 * key and option index so previews never flicker between renders.
 *
 * @param {string} facetKey Facet key.
 * @param {number} index    Option index.
 * @return {number} Sample count between 1 and 24.
 */
export function sampleCount( facetKey, index ) {
	let seed = 0;
	const key = String( facetKey || '' );
	for ( let i = 0; i < key.length; i++ ) {
		seed = ( seed + key.charCodeAt( i ) ) % 97;
	}
	return ( ( seed + ( index + 1 ) * 7 ) % 24 ) + 1;
}

/**
 * Order and bound preview options the way the storefront renderer will.
 *
 * @param {Array}  options    Options as {name, count} records.
 * @param {string} orderBy    One of count-desc | name-asc | name-desc.
 * @param {number} maxOptions 0 for all, otherwise a 1–100 clamp.
 * @return {Array} Ordered, bounded options.
 */
export function orderPreviewOptions( options, orderBy, maxOptions ) {
	const ordered = [ ...( options || [] ) ];
	ordered.sort( ( a, b ) => {
		if ( orderBy === 'name-asc' ) {
			return a.name.localeCompare( b.name );
		}
		if ( orderBy === 'name-desc' ) {
			return b.name.localeCompare( a.name );
		}
		// count-desc with a deterministic label tie-break.
		return b.count - a.count || a.name.localeCompare( b.name );
	} );
	const bound = clampMaxOptions( maxOptions );
	return bound > 0 ? ordered.slice( 0, bound ) : ordered;
}

/**
 * Clamp the maxOptions attribute to its contract: 0 means all, anything
 * else lands in 1–100.
 *
 * @param {number} value Raw attribute value.
 * @return {number} Clamped value.
 */
export function clampMaxOptions( value ) {
	const numeric = Number.isFinite( Number( value ) ) ? Number( value ) : 0;
	if ( numeric <= 0 ) {
		return 0;
	}
	return Math.min( 100, Math.max( 1, Math.round( numeric ) ) );
}
