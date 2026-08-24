import { getContext, getElement, store } from '@wordpress/interactivity';
import {
	buildCatalogUrl,
	navigate,
} from '../../../frontend/js/shift64-woo-search-catalog-navigation';
import { moveActiveIndex, normalizeResponse, safeUrl } from './helpers';

const namespace = 'shift64-woo-search/search';
const runtimes = new Map();
let openModalRoot = null;

function rootFrom( element ) {
	return element?.closest( '[data-shift64-search-root]' ) || null;
}

function runtimeFor( context, root ) {
	if ( ! runtimes.has( context.instanceId ) ) {
		runtimes.set( context.instanceId, {
			root,
			abortController: null,
			debounceTimer: null,
			requestToken: 0,
			focusTarget: null,
			redisDown: false,
		} );
	}
	const runtime = runtimes.get( context.instanceId );
	runtime.root = root || runtime.root;
	return runtime;
}

function clearRequest( runtime ) {
	window.clearTimeout( runtime.debounceTimer );
	window.clearTimeout( runtime.timeoutTimer );
	if ( runtime.abortController ) {
		runtime.abortController.abort();
		runtime.abortController = null;
	}
}

function updateActiveRow( context, root ) {
	const rows = [ ...root.querySelectorAll( '.shift64-woo-search-result' ) ];
	rows.forEach( ( row, index ) => {
		const active = index === context.activeIndex;
		row.classList.toggle( 'shift64-woo-search-result--active', active );
		row.setAttribute( 'aria-selected', active ? 'true' : 'false' );
	} );
	const active = rows[ context.activeIndex ];
	context.activeDescendant = active?.id || '';
	active?.scrollIntoView( { block: 'nearest' } );
}

function appendMeta( content, item ) {
	const values = [
		[ 'sku', item.sku ],
		[ 'category', item.category ],
		[ 'brand', item.brand ],
	].filter( ( pair ) => pair[ 1 ] );
	if ( ! values.length ) {
		return;
	}
	const meta = document.createElement( 'div' );
	meta.className = 'shift64-woo-search-result__meta';
	values.forEach( ( [ type, value ], index ) => {
		if ( index > 0 ) {
			const separator = document.createElement( 'span' );
			separator.className = 'shift64-woo-search-result__meta-sep';
			separator.textContent = '|';
			meta.append( separator );
		}
		const label = document.createElement( 'span' );
		label.className = `shift64-woo-search-result__${ type }`;
		label.textContent = value;
		meta.append( label );
	} );
	content.append( meta );
}

function createRow( item, index, context, root ) {
	const row = document.createElement( 'div' );
	row.id = `${ context.instanceId }-result-${ index }`;
	row.className = `shift64-woo-search-result shift64-woo-search-result--${ item.type }`;
	row.setAttribute( 'role', 'option' );
	row.tabIndex = -1;
	row.dataset.url = item.url;
	row.setAttribute( 'aria-selected', 'false' );

	if ( item.type === 'product' && item.image ) {
		const image = document.createElement( 'img' );
		image.className = 'shift64-woo-search-result__image';
		image.src = item.image;
		image.alt = '';
		image.loading = 'lazy';
		row.append( image );
	}

	const content = document.createElement(
		item.type === 'product' ? 'div' : 'span'
	);
	content.className =
		item.type === 'product'
			? 'shift64-woo-search-result__content'
			: 'shift64-woo-search-result__text';
	const title = document.createElement( 'span' );
	title.className =
		item.type === 'product'
			? 'shift64-woo-search-result__title'
			: 'shift64-woo-search-result__label';
	title.textContent = item.label;
	content.append( title );
	appendMeta( content, item );
	row.append( content );
	row.addEventListener( 'click', () => chooseSuggestion( item.url ) );
	row.addEventListener( 'pointermove', () => {
		context.activeIndex = index;
		updateActiveRow( context, root );
	} );
	return row;
}

function chooseSuggestion( url ) {
	if ( url ) {
		navigate( url );
	}
}

function renderResults(
	context,
	root,
	model,
	status = 'ready',
	emptyLabel = ''
) {
	const results = root.querySelector( '[data-shift64-search-results]' );
	if ( ! results ) {
		return;
	}
	results.replaceChildren();
	const scroll = document.createElement( 'div' );
	scroll.className = 'shift64-woo-search-results__scroll';

	if ( status === 'error' || ! model.items.length ) {
		const empty = document.createElement( 'div' );
		empty.className = 'shift64-woo-search-results__empty';
		empty.textContent =
			emptyLabel ||
			( status === 'error'
				? context.errorLabel
				: context.noResultsLabel );
		scroll.append( empty );
	} else {
		let itemIndex = 0;
		model.sections.forEach( ( section ) => {
			const sectionElement = document.createElement( 'div' );
			sectionElement.className = `shift64-woo-search-section shift64-woo-search-section--${ section.slug }`;
			const heading = document.createElement( 'div' );
			heading.className = 'shift64-woo-search-section__header';
			heading.textContent = section.label;
			sectionElement.append( heading );
			section.items.forEach( ( item ) => {
				sectionElement.append(
					createRow( item, itemIndex, context, root )
				);
				itemIndex += 1;
			} );
			scroll.append( sectionElement );
		} );
	}
	results.append( scroll );

	if ( model.query ) {
		const seeAll = document.createElement( 'a' );
		seeAll.className = 'shift64-woo-search-results__all';
		seeAll.href = safeUrl(
			context.fallbackUrl.replace(
				'{query}',
				encodeURIComponent( model.query )
			),
			context.searchUrl,
			{ sameOrigin: true }
		);
		seeAll.textContent = `${ context.seeAllLabel } →`;
		results.append( seeAll );
	}

	context.suggestions = model.items;
	context.activeIndex = -1;
	context.activeDescendant = '';
	context.requestStatus = status;
	if ( status === 'error' ) {
		context.statusMessage = emptyLabel || context.errorLabel;
	} else if ( model.items.length ) {
		context.statusMessage = context.resultsLabel.replace(
			'%d',
			String( model.items.length )
		);
	} else {
		context.statusMessage = context.noResultsLabel;
	}
	context.panelOpen = true;
}

async function request( context, root, mode ) {
	const runtime = runtimeFor( context, root );
	if ( runtime.redisDown ) {
		return;
	}
	clearRequest( runtime );
	runtime.requestToken += 1;
	const token = runtime.requestToken;
	runtime.abortController = new AbortController();
	context.requestStatus = 'loading';
	context.statusMessage = context.loadingLabel;

	const url = new URL( context.endpoint, context.searchUrl );
	url.searchParams.set( 'mode', mode );
	if ( mode === 'autocomplete' ) {
		url.searchParams.set( 'q', context.query.trim() );
		url.searchParams.set( 'limit', String( context.limit ) );
	}

	runtime.timeoutTimer = window.setTimeout( () => {
		if ( token !== runtime.requestToken ) {
			return;
		}
		runtime.redisDown = true;
		runtime.abortController?.abort();
		renderResults(
			context,
			root,
			{ query: context.query, sections: [], items: [] },
			'error',
			context.timeoutLabel
		);
	}, 5000 );

	try {
		const response = await fetch( url, {
			signal: runtime.abortController.signal,
		} );
		const data = await response.json();
		if ( token !== runtime.requestToken ) {
			return;
		}
		window.clearTimeout( runtime.timeoutTimer );
		if ( ! data.success && data.fallback ) {
			runtime.redisDown = true;
			context.panelOpen = false;
			context.requestStatus = 'degraded';
			context.statusMessage = context.unavailableLabel;
			return;
		}
		const model = normalizeResponse( data, context, context.query );
		renderResults( context, root, model, data.success ? 'ready' : 'error' );
	} catch ( error ) {
		window.clearTimeout( runtime.timeoutTimer );
		if ( error.name !== 'AbortError' && token === runtime.requestToken ) {
			renderResults(
				context,
				root,
				{ query: context.query, sections: [], items: [] },
				'error'
			);
		}
	}
}

function closePanel( context, root ) {
	context.panelOpen = false;
	context.activeIndex = -1;
	context.activeDescendant = '';
	updateActiveRow( context, root );
}

function closeModal( context, root, restoreFocus = true ) {
	const runtime = runtimeFor( context, root );
	const dialog = root.querySelector( 'dialog' );
	closePanel( context, root );
	context.isOpen = false;
	if ( dialog?.open ) {
		dialog.close();
	}
	document.body.classList.toggle(
		'shift64-woo-search-modal-open',
		Boolean( openModalRoot && openModalRoot !== root )
	);
	if ( openModalRoot === root ) {
		openModalRoot = null;
	}
	if ( restoreFocus ) {
		runtime.focusTarget?.focus();
	}
}

const { state } = store( namespace, {
	state: {
		get isBusy() {
			return getContext().requestStatus === 'loading';
		},
		get isExpanded() {
			return Boolean( getContext().panelOpen );
		},
		get isDialogOpen() {
			return Boolean( getContext().isOpen );
		},
		get hasQuery() {
			return Boolean( getContext().query );
		},
		get activeDescendant() {
			return getContext().activeDescendant || undefined;
		},
	},
	actions: {
		open() {
			const element = getElement().ref;
			const root = rootFrom( element );
			if ( ! root ) {
				return;
			}
			const context = getContext();
			if ( openModalRoot && openModalRoot !== root ) {
				const otherContext = openModalRoot.__shift64Context;
				if ( otherContext ) {
					closeModal( otherContext, openModalRoot, false );
				}
			}
			root.__shift64Context = context;
			const runtime = runtimeFor( context, root );
			runtime.focusTarget = element;
			context.isOpen = true;
			openModalRoot = root;
			document.body.classList.add( 'shift64-woo-search-modal-open' );
			const dialog = root.querySelector( 'dialog' );
			if ( dialog?.showModal ) {
				dialog.showModal();
			} else if ( dialog ) {
				dialog.setAttribute( 'open', '' );
			}
			window.requestAnimationFrame( () =>
				root
					.querySelector( '.shift64-woo-search-field__input' )
					?.focus()
			);
		},
		toggle( event ) {
			const context = getContext();
			if ( context.isOpen ) {
				store( namespace ).actions.close( event );
			} else {
				store( namespace ).actions.open();
			}
		},
		close( event ) {
			event?.preventDefault();
			const context = getContext();
			const root = rootFrom( getElement().ref );
			if ( root ) {
				closeModal( context, root );
			}
		},
		closePanel() {
			const context = getContext();
			const root = rootFrom( getElement().ref );
			if ( root ) {
				closePanel( context, root );
			}
		},
		onDialogClick( event ) {
			if ( event.target === getElement().ref ) {
				store( namespace ).actions.close( event );
			}
		},
		onDialogCancel( event ) {
			event.preventDefault();
			store( namespace ).actions.close( event );
		},
		onInput( event ) {
			const root = rootFrom( event.target );
			if ( ! root ) {
				return;
			}
			const context = getContext();
			const runtime = runtimeFor( context, root );
			context.query = event.target.value;
			window.clearTimeout( runtime.debounceTimer );
			if ( context.query.trim().length < context.minQueryLength ) {
				clearRequest( runtime );
				if ( context.query.length === 0 ) {
					request( context, root, 'suggestions' );
				} else {
					closePanel( context, root );
				}
				return;
			}
			runtime.debounceTimer = window.setTimeout(
				() => request( context, root, 'autocomplete' ),
				context.debounce
			);
		},
		onFocus() {
			const context = getContext();
			const root = rootFrom( getElement().ref );
			if ( root && ! context.query.trim() ) {
				request( context, root, 'suggestions' );
			}
		},
		onKeydown( event ) {
			const root = rootFrom( event.target );
			if ( ! root ) {
				return;
			}
			const context = getContext();
			const rows = [
				...root.querySelectorAll( '.shift64-woo-search-result' ),
			];
			if ( [ 'ArrowDown', 'ArrowUp' ].includes( event.key ) ) {
				event.preventDefault();
				context.activeIndex = moveActiveIndex(
					context.activeIndex,
					event.key,
					rows.length
				);
				updateActiveRow( context, root );
			} else if ( event.key === 'Enter' && context.activeIndex >= 0 ) {
				event.preventDefault();
				const url = rows[ context.activeIndex ]?.dataset.url;
				if ( url ) {
					navigate( url );
				}
			} else if ( event.key === 'Escape' ) {
				event.preventDefault();
				if ( context.variant === 'modal' ) {
					closeModal( context, root );
				} else {
					closePanel( context, root );
				}
			}
		},
		clear() {
			const root = rootFrom( getElement().ref );
			if ( ! root ) {
				return;
			}
			const context = getContext();
			context.query = '';
			const input = root.querySelector(
				'.shift64-woo-search-field__input'
			);
			if ( input ) {
				input.value = '';
				input.focus();
			}
			request( context, root, 'suggestions' );
		},
		chooseSuggestion( event ) {
			event?.preventDefault();
			chooseSuggestion( getElement().ref?.dataset.url );
		},
		submit( event ) {
			const context = getContext();
			const query = context.query.trim();
			if ( ! query ) {
				return;
			}
			event.preventDefault();
			const destination = buildCatalogUrl( context.searchUrl, {
				s: query,
				post_type: 'product',
			} );
			navigate( destination );
		},
	},
	callbacks: {
		init() {
			const context = getContext();
			const root = rootFrom( getElement().ref );
			if ( root ) {
				root.__shift64Context = context;
				runtimeFor( context, root );
			}
		},
	},
} );

export { state };
