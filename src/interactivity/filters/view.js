/**
 * Product Filters interactivity store.
 *
 * Upgrades the server-rendered details/summary pills with one-open-at-a-time
 * disclosure, Escape/backdrop dismissal, a focus-contained narrow-screen
 * tray, and canonical router navigation for Apply / Clear / Clear all. The
 * markup keeps working without this module — forms and links navigate to the
 * same canonical URLs.
 */
import { getContext, getElement, store } from '@wordpress/interactivity';
import {
	buildCatalogUrl,
	navigate,
} from '../../../frontend/js/shift64-woo-search-catalog-navigation';
import {
	clearAllChanges,
	selectionChanges,
	selectionFromInputs,
	trapTabIndex,
} from './helpers';

const NAMESPACE = 'shift64-woo-search/product-filters';
const TRAY_MEDIA = '(max-width: 782px)';
const FOCUSABLE =
	'summary, a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function pillRoot( element ) {
	return element ? element.closest( 'details' ) : null;
}

function focusTrigger( element ) {
	const details = pillRoot( element );
	const summary = details ? details.querySelector( 'summary' ) : null;
	if ( summary ) {
		summary.focus();
	}
}

const { state, actions } = store( NAMESPACE, {
	state: {
		// Open pill per Product Filters parent: parentId -> pillId | ''.
		open: {},

		get isPillOpen() {
			const context = getContext();
			return state.open[ context.parentId ] === context.pillId;
		},

		get isPillClosed() {
			return ! state.isPillOpen;
		},

		get pillExpanded() {
			return state.isPillOpen ? 'true' : 'false';
		},

		get hasOpenPill() {
			return Boolean( state.open[ getContext().parentId ] );
		},
	},

	actions: {
		pillToggled() {
			const context = getContext();
			const { ref } = getElement();
			if ( ref.open ) {
				state.open[ context.parentId ] = context.pillId;
			} else if ( state.open[ context.parentId ] === context.pillId ) {
				state.open[ context.parentId ] = '';
			}
		},

		closeOpenPill() {
			state.open[ getContext().parentId ] = '';
		},

		panelKeydown( event ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				event.stopPropagation();
				focusTrigger( event.target );
				actions.closeOpenPill();
				return;
			}
			if (
				event.key === 'Tab' &&
				window.matchMedia( TRAY_MEDIA ).matches
			) {
				const details = pillRoot( event.target );
				if ( ! details ) {
					return;
				}
				const focusable = [
					...details.querySelectorAll( FOCUSABLE ),
				].filter( ( element ) => element.offsetParent !== null );
				const next = trapTabIndex(
					focusable.length,
					focusable.indexOf( event.target ),
					event.shiftKey
				);
				if ( next >= 0 ) {
					event.preventDefault();
					focusable[ next ].focus();
				}
			}
		},

		async apply( event ) {
			event.preventDefault();
			const context = getContext();
			const form =
				event.target.tagName === 'FORM'
					? event.target
					: event.target.closest( 'form' );
			if ( ! form ) {
				return;
			}
			const slugs = selectionFromInputs( [
				...form.querySelectorAll(
					'input[type="checkbox"], input[type="radio"]'
				),
			] );
			actions.closeOpenPill();
			await navigate(
				buildCatalogUrl(
					window.location.href,
					selectionChanges(
						context.taxonomy,
						slugs,
						Boolean( context.operatorAnd )
					)
				)
			);
		},

		async clear( event ) {
			event.preventDefault();
			const context = getContext();
			actions.closeOpenPill();
			await navigate(
				buildCatalogUrl(
					window.location.href,
					selectionChanges( context.taxonomy, [], false )
				)
			);
		},

		async clearAll( event ) {
			event.preventDefault();
			const context = getContext();
			await navigate(
				buildCatalogUrl(
					window.location.href,
					clearAllChanges( context.clearTaxonomies || [] )
				)
			);
		},
	},
} );

// A viewport change while a surface is open would swap disclosure and tray
// presentation under the user: close everything and return focus first.
if ( typeof window !== 'undefined' && window.matchMedia ) {
	window.matchMedia( TRAY_MEDIA ).addEventListener( 'change', () => {
		Object.keys( state.open ).forEach( ( parentId ) => {
			state.open[ parentId ] = '';
		} );
	} );
}
