/**
 * Editor-side facet eligibility data and pure helpers for the Product
 * Filters / Filter Pill blocks. The REST payload is fetched once per editor
 * session and shared between every block instance.
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

export const FACETS_PATH = '/shift64-woo-search/v1/editor/facets';

export const EMPTY_PAYLOAD = {
	facets: [],
	settingsUrl: '',
	rebuildRequired: false,
};

let cachedPayload = null;
let pendingRequest = null;

export * from './facets-helpers';

/**
 * Reset the shared payload cache (tests only).
 */
export function resetFacetsCache() {
	cachedPayload = null;
	pendingRequest = null;
}

/**
 * Fetch the editor facets payload once and share it across block instances.
 *
 * @return {{payload: Object, isLoading: boolean, error: boolean}} State.
 */
export function useEditorFacets() {
	const [ payload, setPayload ] = useState( cachedPayload );
	const [ error, setError ] = useState( false );

	useEffect( () => {
		if ( cachedPayload ) {
			return;
		}
		if ( ! pendingRequest ) {
			pendingRequest = apiFetch( { path: FACETS_PATH } ).then(
				( response ) => {
					cachedPayload = {
						...EMPTY_PAYLOAD,
						...( response || {} ),
					};
					return cachedPayload;
				}
			);
		}
		let cancelled = false;
		pendingRequest
			.then( ( response ) => {
				if ( ! cancelled ) {
					setPayload( response );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError( true );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	return {
		payload: payload || EMPTY_PAYLOAD,
		isLoading: ! payload && ! error,
		error,
	};
}
