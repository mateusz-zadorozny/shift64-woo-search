import type { Locator, Page, Request } from '@playwright/test';

/**
 * Central selector contract. A markup refactor should only ever touch this file.
 *
 * Note: the filter bar element carries BOTH id="shift64-woo-search-filters" and
 * the class; the JS targets the class, so helpers do too.
 */
export const SEL = {
	input: '.shift64-woo-search-field__input',
	form: 'form.shift64-woo-search-field',
	tray: '.shift64-woo-search-results',
	trayVisible: '.shift64-woo-search-results--visible',
	row: '.shift64-woo-search-result',
	rowActive: '.shift64-woo-search-result--active',
	rowTitle: '.shift64-woo-search-result__title',
	rowSku: '.shift64-woo-search-result__sku',
	rowCategory: '.shift64-woo-search-result__category',
	sectionHeader: '.shift64-woo-search-section__header',
	empty: '.shift64-woo-search-results__empty',
	seeAll: '.shift64-woo-search-results__all',
	modal: '[data-shift64-woo-search-modal]',
	modalTrigger: '[data-shift64-woo-search-modal-trigger]',
	modalClose: '[data-shift64-woo-search-modal-close]',
	modalClear: '[data-shift64-woo-search-clear]',
	filters: '.shift64-woo-search-filters',
	filterCheckbox: '.shift64-woo-search-filter__checkbox',
	// Theme-agnostic product grid (mirrors the AJAX pagination script's list).
	productsGrid:
		'.kwt-products-wrap, ul.products, .products, .wc-block-grid__products, .wp-block-woocommerce-product-template',
	orderbySelect: '.woocommerce-ordering select.orderby',
	// Classic Woo markup plus the blockified nav block themes render instead
	// (mirrors the AJAX pagination script's SELECTORS.pagination).
	pagination: 'nav.woocommerce-pagination, nav.wp-block-query-pagination',
	resultCount: '.woocommerce-result-count',
	price: '.woocommerce-Price-amount',
} as const;

/** Body class set while the search modal (or mobile filter UI) is open. */
export const MODAL_OPEN_BODY_CLASS = 'shift64-woo-search-modal-open';

/** Page created by bin/e2e-provision.sh containing both search blocks. */
export const SEARCH_PAGE = '/search-e2e/';

export const ENDPOINT_PATH = '/wp-content/mu-plugins/shift64-woo-search/endpoint.php';

/**
 * True for autocomplete fetches only. Focusing an empty input legitimately
 * fires a `mode=suggestions` request to the same endpoint, so request-count
 * assertions must scope to `mode=autocomplete` — never to the endpoint URL.
 */
export function isAutocompleteRequest(req: Request): boolean {
	const url = req.url();
	return url.includes(ENDPOINT_PATH) && url.includes('mode=autocomplete');
}

export function isSuggestionsRequest(req: Request): boolean {
	const url = req.url();
	return url.includes(ENDPOINT_PATH) && url.includes('mode=suggestions');
}

/**
 * The `instanceId` attributes bin/e2e-provision.sh writes into the two blocks
 * on /search-e2e/. The plugin derives every DOM id on an instance from them
 * (`<instanceId>-input`, `-listbox`, `-dialog`), so they are the suite's stable
 * address for "the block under test".
 *
 * Addressing them by INSTANCE rather than by block wrapper is load-bearing: the
 * provisioned theme header carries its own instance of BOTH blocks, so
 * `.shift64-woo-search-block--form` now matches on every page — the header's
 * block is the same block, not a lookalike.
 */
export const E2E_INLINE_INSTANCE = 'search-e2e-inline';
export const E2E_MODAL_INSTANCE = 'search-e2e-modal';

/** Stable instances provisioned into the active block theme's header. */
export const E2E_HEADER_INLINE_INSTANCE = 'shift64-e2e-header-inline';
export const E2E_HEADER_MODAL_INSTANCE = 'shift64-e2e-header-modal';

/** The plain search-block input on the e2e page. */
export function searchInput(page: Page): Locator {
	return page.locator(`#${E2E_INLINE_INSTANCE}-input`);
}

/** The e2e page's modal trigger, paired to its dialog rather than to a wrapper. */
export function modalTrigger(page: Page): Locator {
	return page.locator(`${SEL.modalTrigger}[aria-controls="${E2E_MODAL_INSTANCE}-dialog"]`);
}

/**
 * The native dialog paired with the block's trigger. Pair through the
 * trigger's aria-controls id so this stays independent of DOM placement.
 */
export async function blockModal(page: Page): Promise<Locator> {
	const controls = await modalTrigger(page).getAttribute('aria-controls');
	if (!controls) {
		throw new Error('Modal trigger has no aria-controls id.');
	}
	return page.locator(`#${controls}`);
}

/** The currently open dropdown tray. */
export function visibleTray(page: Page): Locator {
	return page.locator(SEL.trayVisible);
}

/** A dropdown section (`suggestions` | `categories` | `brands` | `products`) inside the open tray. */
export function traySection(
	page: Page,
	name: 'suggestions' | 'categories' | 'brands' | 'products'
): Locator {
	return visibleTray(page).locator(`.shift64-woo-search-section--${name}`);
}

/**
 * Focus the input and type a query. `fill()` fires a single input event, which
 * is enough for the debounced autocomplete flow.
 */
export async function typeQuery(page: Page, query: string): Promise<void> {
	const input = searchInput(page);
	await input.click();
	await input.fill(query);
}
