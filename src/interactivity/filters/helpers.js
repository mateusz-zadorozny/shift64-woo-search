/**
 * Pure helpers for the Product Filters interactivity store.
 *
 * Kept dependency-free so unit tests can exercise the canonical-parameter
 * and focus-trap logic without a DOM or the Interactivity runtime.
 */

/**
 * Collect the selected values from a pill form's inputs.
 *
 * The checked checkboxes/radios ARE the draft state; this reads them at
 * apply time. Values are deduplicated and sorted for canonical URLs.
 *
 * @param {Array<{checked: boolean, value: string}>} inputs Form inputs.
 * @return {string[]} Sorted selected values.
 */
export function selectionFromInputs( inputs ) {
	const values = new Set();
	( inputs || [] ).forEach( ( input ) => {
		if ( input.checked && input.value !== '' ) {
			values.add( input.value );
		}
	} );
	return [ ...values ].sort();
}

/**
 * Canonical URL changes for one pill's selection.
 *
 * Empty selections remove both parameters; the AND operator is only ever
 * emitted alongside a selection on a facet that supports it.
 *
 * @param {string}   taxonomy    Facet taxonomy (e.g. pa_material).
 * @param {string[]} slugs       Selected term slugs.
 * @param {boolean}  operatorAnd Whether this pill is configured for AND.
 * @return {Record<string, string|null>} Changes for buildCatalogUrl.
 */
export function selectionChanges( taxonomy, slugs, operatorAnd ) {
	const changes = {};
	changes[ `filter_${ taxonomy }` ] = slugs.length ? slugs.join( ',' ) : null;
	changes[ `query_type_${ taxonomy }` ] =
		slugs.length && operatorAnd ? 'and' : null;
	return changes;
}

/**
 * Canonical URL changes clearing every represented facet.
 *
 * Only the taxonomies represented by this Product Filters instance are
 * removed — direct URL state for unrepresented facets is not this block's
 * to erase.
 *
 * @param {string[]} taxonomies Represented facet taxonomies.
 * @return {Record<string, null>} Changes for buildCatalogUrl.
 */
export function clearAllChanges( taxonomies ) {
	const changes = {};
	( taxonomies || [] ).forEach( ( taxonomy ) => {
		changes[ `filter_${ taxonomy }` ] = null;
		changes[ `query_type_${ taxonomy }` ] = null;
	} );
	return changes;
}

/**
 * Whether the page behind the surfaces should be held still.
 *
 * Only the narrow-screen tray is modal: it covers the catalog, so a touch drag
 * that misses the option list must not scroll the results out from under it.
 * The desktop dropdown is an inline panel over a page the shopper can still
 * see all of, and locking there would freeze scrolling for no reason.
 *
 * @param {boolean}                isTrayViewport Whether the tray media query matches.
 * @param {Record<string, string>} openByParent   Open pill per parent id.
 * @return {boolean} Whether page scrolling should be locked.
 */
export function shouldLockScroll( isTrayViewport, openByParent ) {
	return (
		Boolean( isTrayViewport ) &&
		Object.values( openByParent || {} ).some( Boolean )
	);
}

/**
 * The index Tab focus should wrap to inside a contained tray.
 *
 * @param {number}  count    Number of focusable elements.
 * @param {number}  index    Index of the currently focused element (-1 when
 *                           focus is outside the list).
 * @param {boolean} shiftKey Whether Shift is held (backwards).
 * @return {number} Next index, or -1 when no wrap is needed.
 */
export function trapTabIndex( count, index, shiftKey ) {
	if ( count === 0 ) {
		return -1;
	}
	if ( shiftKey && index <= 0 ) {
		return count - 1;
	}
	if ( ! shiftKey && index === count - 1 ) {
		return 0;
	}
	return -1;
}
