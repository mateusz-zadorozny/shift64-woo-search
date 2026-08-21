/**
 * Product Filters interactivity store.
 *
 * Upgrades the server-rendered details/summary pills with one-open-at-a-time
 * disclosure, Escape/backdrop dismissal, a focus-contained narrow-screen
 * tray, and canonical router navigation for desktop option changes plus
 * mobile Apply / Clear / Clear all. The markup keeps working without this
 * module — forms and links navigate to the same canonical URLs.
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
	shouldLockScroll,
	trapTabIndex,
} from './helpers';

const NAMESPACE = 'shift64-woo-search/product-filters';
const TRAY_MEDIA = '(max-width: 782px)';
const trayQuery =
	typeof window !== 'undefined' && window.matchMedia
		? window.matchMedia( TRAY_MEDIA )
		: null;
const FOCUSABLE =
	'summary, a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
const SCROLL_LOCK_CLASS = 'shift64-woo-search-has-open-filter';

function pillRoot( element ) {
	return element ? element.closest( 'details' ) : null;
}

/**
 * Hold the page still while a tray is open.
 *
 * The tray is a modal surface: a touch drag that misses the option list must
 * not scroll the catalog out from under it. Only the tray presentation locks —
 * the desktop dropdown is an inline panel and locking there would freeze a page
 * the shopper can still see all of. The stylesheet scopes the lock to the same
 * media query, so a stale class can never strand a wide viewport.
 */
function syncScrollLock() {
	if ( typeof document === 'undefined' ) {
		return;
	}
	const locked = shouldLockScroll(
		trayQuery && trayQuery.matches,
		state.open
	);
	document.documentElement.classList.toggle( SCROLL_LOCK_CLASS, locked );
}

function focusTrigger( element ) {
	const details = pillRoot( element );
	const summary = details ? details.querySelector( 'summary' ) : null;
	if ( summary ) {
		summary.focus();
	}
}

function formSelection( form ) {
	return selectionFromInputs( [
		...form.querySelectorAll(
			'input[type="checkbox"], input[type="radio"]'
		),
	] );
}

const { state, actions } = store( NAMESPACE, {
	state: {
		// Open pill per Product Filters parent: parentId -> pillId | ''.
		open: {},

		// Progressive-enhancement flag: the server renders JavaScript-only
		// controls hidden, and binding against this unhides them once the
		// store is running.
		enhanced: true,

		get isPillOpen() {
			const context = getContext();
			return state.open[ context.parentId ] === context.pillId;
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
			syncScrollLock();
		},

		closeOpenPill() {
			state.open[ getContext().parentId ] = '';
			syncScrollLock();
		},

		// The tray's own close button. Dismissing a modal surface has to put
		// focus back where it came from, exactly as Escape does — otherwise
		// focus falls to the document and a keyboard shopper has to tab in
		// from the top of the page again.
		dismissTray( event ) {
			focusTrigger( event.target );
			actions.closeOpenPill();
		},

		panelKeydown( event ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				event.stopPropagation();
				focusTrigger( event.target );
				actions.closeOpenPill();
				return;
			}
			if ( event.key === 'Tab' && trayQuery && trayQuery.matches ) {
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
			const form =
				event.target.tagName === 'FORM'
					? event.target
					: event.target.closest( 'form' );
			if ( ! form ) {
				return;
			}
			const context = getContext();
			const slugs = formSelection( form );
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

		async optionChanged( event ) {
			if ( trayQuery && trayQuery.matches ) {
				return;
			}
			const form = event.target.closest( 'form' );
			if ( ! form ) {
				return;
			}
			const context = getContext();
			actions.closeOpenPill();
			await navigate(
				buildCatalogUrl(
					window.location.href,
					selectionChanges(
						context.taxonomy,
						formSelection( form ),
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
		syncScrollLock();
	} );
}
