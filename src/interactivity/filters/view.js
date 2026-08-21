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
let lockedScrollY = null;
let scrollLockActive = false;

function pillRoot( element ) {
	return element ? element.closest( 'details' ) : null;
}

function parentRoot( element ) {
	return element
		? element.closest( '.shift64-woo-search-product-filters' )
		: null;
}

function closePills( element, except = null ) {
	const root = parentRoot( element );
	if ( ! root ) {
		return;
	}
	root.querySelectorAll( 'details[open]' ).forEach( ( details ) => {
		if ( details !== except ) {
			details.open = false;
		}
	} );
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
	// A native details toggle updates the rendered surface before the
	// Interactivity state has necessarily settled. Use that DOM state as a
	// second source of truth so reopening a different pill after dismissal
	// cannot leave the modal tray unlocked.
	const hasRenderedSurface = [
		...document.querySelectorAll(
			'.shift64-woo-search-product-filters'
		),
	].some( ( root ) =>
		Boolean(
			root.querySelector( 'details[open]' ) ||
			root.querySelector(
				'.shift64-woo-search-product-filters__mobile-trigger[aria-expanded="true"]'
			)
		)
	);
	const locked = shouldLockScroll(
		trayQuery && trayQuery.matches,
		{ ...state.open, ...state.combinedOpen }
	);
	const shouldLock = locked || ( trayQuery && trayQuery.matches && hasRenderedSurface );
	if ( shouldLock && ! scrollLockActive && typeof window !== 'undefined' ) {
		lockedScrollY = window.scrollY;
	}
	document.documentElement.classList.toggle( SCROLL_LOCK_CLASS, shouldLock );
	if (
		! shouldLock &&
		scrollLockActive &&
		lockedScrollY !== null &&
		typeof window !== 'undefined' &&
		typeof window.scrollTo === 'function'
	) {
		window.scrollTo( 0, lockedScrollY );
		lockedScrollY = null;
	}
	scrollLockActive = shouldLock;
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

function combinedChanges( root ) {
	const changes = {};
	if ( ! root ) {
		return changes;
	}
	root.querySelectorAll( 'form[data-taxonomy]' ).forEach( ( form ) => {
		Object.assign(
			changes,
			selectionChanges(
				form.dataset.taxonomy,
				formSelection( form ),
				form.dataset.operatorAnd === 'true'
			)
		);
	} );
	return changes;
}

function focusCombinedTrigger( element ) {
	const root = parentRoot( element );
	const trigger = root
		? root.querySelector(
				'.shift64-woo-search-product-filters__mobile-trigger'
		  )
		: null;
	if ( trigger ) {
		trigger.focus();
	}
}

const { state, actions } = store( NAMESPACE, {
	state: {
		// Open pill per Product Filters parent: parentId -> pillId | ''.
		open: {},

		// Open combined mobile tray per Product Filters parent.
		combinedOpen: {},

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

		get isCombinedOpen() {
			return Boolean( state.combinedOpen[ getContext().parentId ] );
		},

		get combinedExpanded() {
			return state.isCombinedOpen ? 'true' : 'false';
		},

		get hasOpenSurface() {
			return state.isCombinedOpen || state.hasOpenPill;
		},

	},

	actions: {
		toggleCombined() {
			const context = getContext();
			const open = Boolean( state.combinedOpen[ context.parentId ] );
			state.combinedOpen[ context.parentId ] = ! open;
			if ( ! open ) {
				state.open[ context.parentId ] = '';
				closePills( getElement().ref );
			}
			syncScrollLock();
		},

		closeSurface() {
			const context = getContext();
			state.combinedOpen[ context.parentId ] = false;
			state.open[ context.parentId ] = '';
			closePills( getElement().ref );
			syncScrollLock();
		},

		dismissCombined( event ) {
			focusCombinedTrigger( event.target );
			actions.closeSurface();
		},

		combinedKeydown( event ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				event.stopPropagation();
				focusCombinedTrigger( event.target );
				actions.closeSurface();
				return;
			}
			if ( event.key !== 'Tab' || ! ( trayQuery && trayQuery.matches ) ) {
				return;
			}
			const root = parentRoot( event.target );
			if ( ! root ) {
				return;
			}
			const focusable = [ ...root.querySelectorAll( FOCUSABLE ) ].filter(
				( element ) => element.offsetParent !== null
			);
			const next = trapTabIndex(
				focusable.length,
				focusable.indexOf( event.target ),
				event.shiftKey
			);
			if ( next >= 0 ) {
				event.preventDefault();
				focusable[ next ].focus();
			}
		},

		pillToggled() {
			const context = getContext();
			const { ref } = getElement();
			if ( ref.open ) {
				closePills( ref, ref );
				state.open[ context.parentId ] = context.pillId;
			} else if ( state.open[ context.parentId ] === context.pillId ) {
				state.open[ context.parentId ] = '';
			}
			syncScrollLock();
		},

		closeOpenPill() {
			state.open[ getContext().parentId ] = '';
			closePills( getElement().ref );
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

		async combinedApply( event ) {
			event.preventDefault();
			const root = parentRoot( event.target );
			actions.closeSurface();
			await navigate(
				buildCatalogUrl( window.location.href, combinedChanges( root ) )
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
			actions.closeSurface();
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
		Object.keys( state.combinedOpen ).forEach( ( parentId ) => {
			state.combinedOpen[ parentId ] = false;
		} );
		syncScrollLock();
	} );
}
