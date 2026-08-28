import { expect, test, type Locator, type Page } from '@playwright/test';
import {
	SEL,
	headerSearchInput,
	headerSearchListbox,
	isAutocompleteRequest,
	traySectionIn,
} from '../helpers/search';

/**
 * Relevance-ordering contract for the block-theme results surface — the browser
 * projection of the fix in #84/#90, which preserves Redis relevance ranking
 * BEFORE the result set is sliced into pages.
 *
 * Design doc: .ai/specs/2026-08-28-product-search-relevance-browser-test.md
 *
 * The unit tests prove the ranking function; the existing browser suite proves
 * that a relevance sort control renders and that the dropdown works. Neither
 * proves the complete journey — that page 1, the header autocomplete, and page
 * 2 all agree on one ranked order. That is what this file locks in, so a future
 * change cannot reintroduce raw Redis insertion order, page before it ranks, or
 * let the archive and the autocomplete diverge while everything else stays
 * green.
 *
 * Pagination OWNERSHIP (which handler performs the navigation) is deliberately
 * not asserted here — tests/e2e/block-theme/blockified.spec.ts owns that
 * contract. This file only asserts what the user sees after the navigation.
 */

// "series" appears in every generated product description, so it matches all 48
// seeded products — the broadest query the deterministic catalog offers. Same
// query, and same coupling to bin/demo-product-catalog.php, as
// specs/search-results-page.spec.ts.
const BROAD_QUERY = '/?s=series&post_type=product';

// How many leading titles each page is asserted on.
const LEADING = 4;

/*
 * The deterministic ranked head of that query — the first 20 products in
 * relevance order, exactly as bin/demo-product-catalog.php seeds them.
 *
 * Exact titles are the point. A weaker assertion (a count, or a regex on one
 * name) would still pass with ranking applied after slicing, which is the
 * regression this file exists to catch.
 *
 * It is a flat ranked list rather than a PAGE_ONE / PAGE_TWO pair because the
 * archive's page SIZE is not a constant: WooCommerce recomputes
 * `woocommerce_catalog_columns` from the incoming theme on every
 * `after_switch_theme` (wc_reset_product_grid_settings), and this suite's
 * classic-theme project switches to Storefront (3 columns) and never restores
 * the option — so a page holds 16 products on a freshly provisioned site and 12
 * on one the suite has already run against. That non-idempotency is tracked in
 * #102; until it is fixed, the page-2 expectation is taken as an OFFSET into
 * this list using the page size actually rendered, which is correct at either
 * size. The list is long enough to reach past the larger one.
 *
 * If the generator's names or ordering change, update this list AND the values
 * recorded in the design doc above, in the same PR.
 */
const RANKED_LEADING = [
	'Aero Artemis Sweater Lightweight Midnight Black',
	'Aero Cipher Laptop Ultra Wide Space Grey',
	'Aero Cascade Micellar Cleanser Intensive Rose',
	'Lumen Apollo Cardigan Everyday Forest Green',
	'Lumen Beacon Monitor Noise Cancelling Copper',
	'Lumen Basalt Bookshelf Matte Clay',
	'Lumen Bloom Face Mask Fragrance Free Aloe',
	'Calm Aurora Keyboard Compact Bronze',
	'Calm Amber Sheet Mask Purifying Mint',
	'Pulse Zenith Mouse Studio Arctic White',
	'Pulse Zest Shampoo Repairing Vanilla',
	'Edge Vector Gaming Mouse Max Onyx',
	'Edge Velvet Volume Shampoo Brightening Cedarwood',
	'Arc Orion Skirt Relaxed Fit Off White',
	'Arc Tesseract Smartphone Pro Graphite',
	'Arc Tonic Conditioner Hydrating Bergamot',
	'Onyx Nike Skirt Ribbed Cobalt Blue',
	'Onyx Spectra Smartphone Fast Charge Ruby Red',
	'Onyx Serene Conditioner Lightweight Coconut',
	'Crest Maia Jacket Brushed Deep Navy',
];

/** The leading titles page 1 must render, whatever the page size is. */
const PAGE_ONE = RANKED_LEADING.slice(0, LEADING);

// Product Collection links page 2 as a pretty permalink here, but a differently
// configured collection emits the query-page form instead. Accept both — this
// file is about WHICH products page 2 holds, not about the URL shape. Mirrors
// the PAGE_2 constant in tests/e2e/block-theme/blockified.spec.ts.
const PAGE_2_URL = /\/page\/2\/|[?&](?:paged|query-page|query-\d+-page)=2/;

/** iPhone-class portrait viewport — the narrow end the result shell must survive. */
const MOBILE_VIEWPORT = { width: 390, height: 844 };

function productCards(page: Page): Locator {
	return page.locator(SEL.productsGrid).first().locator('li.product');
}

/** The product-card titles, in rendered order. Product Collection renders each as a post-title heading. */
function cardTitles(page: Page): Locator {
	return productCards(page).locator('.wp-block-post-title');
}

function resultsHeading(page: Page): Locator {
	return page.getByRole('heading', { name: /Search results for/i });
}

function pagination(page: Page): Locator {
	return page.locator(SEL.pagination).first();
}

/** Assert the first `expected.length` rendered titles, in order. */
async function expectLeadingTitles(titles: Locator, expected: string[]): Promise<void> {
	for (const [index, title] of expected.entries()) {
		await expect(titles.nth(index)).toHaveText(title);
	}
}

test.describe('search relevance ordering (block-theme results surface)', () => {
	test('the archive and the header autocomplete agree on the ranked order', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		await expect(resultsHeading(page)).toBeVisible();
		await expect(page.locator(SEL.productsGrid).first()).toBeVisible();
		await expect(productCards(page).first()).toBeVisible();
		await expectLeadingTitles(cardTitles(page), PAGE_ONE);

		// The provisioned header carries its OWN instance of the search block, so
		// every page matches the generic block selectors. Address the header
		// instance by id; `.first()` would be a coin flip on this page.
		const input = headerSearchInput(page);
		const listbox = headerSearchListbox(page);
		await expect(input).toHaveCount(1);
		await expect(listbox).toHaveCount(1);

		// The archive prefills the field from `?s=`, and the block skips a fetch
		// for a query it already holds. Clear first so filling is a real change
		// and the debounced autocomplete request actually fires.
		await input.click();
		await input.fill('');

		// Wait for the real mode=autocomplete response: a stale suggestions
		// response must never be able to satisfy a product-order assertion.
		const autocomplete = page.waitForResponse((response) =>
			isAutocompleteRequest(response.request())
		);
		await input.fill('series');
		await autocomplete;

		await expect(listbox).toBeVisible();
		const dropdownTitles = traySectionIn(listbox, 'products').locator(SEL.rowTitle);
		await expectLeadingTitles(dropdownTitles, PAGE_ONE);

		await input.press('Escape');
		await expect(listbox).not.toHaveClass(/shift64-woo-search-results--visible/);
		await expect(page).toHaveURL(/[?&]s=series/);
		await expect(productCards(page).first()).toBeVisible();
	});

	test('page 2 holds the next ranked slice, not a repeat of page 1', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		await expect(productCards(page).first()).toBeVisible();
		await expectLeadingTitles(cardTitles(page), PAGE_ONE);
		const pageOneRendered = (await cardTitles(page).allInnerTexts()).slice(0, LEADING);

		// Read the page size instead of assuming it — see RANKED_LEADING and
		// #102. Page 2 therefore starts at rank `pageSize`, whatever that is.
		const pageSize = await cardTitles(page).count();
		const expectedPageTwo = RANKED_LEADING.slice(pageSize, pageSize + LEADING);
		expect(
			expectedPageTwo,
			`RANKED_LEADING is too short for a page size of ${pageSize} — extend it with the next ranked titles.`
		).toHaveLength(LEADING);

		// Navigate the way a user does — the rendered pagination control.
		await pagination(page).getByRole('link', { name: 'Page 2', exact: true }).click();

		await expect(page).toHaveURL(PAGE_2_URL);
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');
		await expectLeadingTitles(cardTitles(page), expectedPageTwo);

		// Compare what was actually rendered, not the fixture: ranking applied
		// after slicing would repeat page 1's products here, and that has to
		// fail even if someone later edits the expected titles.
		const pageTwoRendered = (await cardTitles(page).allInnerTexts()).slice(0, LEADING);
		expect(pageTwoRendered).not.toEqual(pageOneRendered);

		// Page 2 must remain navigable in both directions.
		const nav = pagination(page);
		await expect(nav.getByRole('link', { name: 'Page 1', exact: true })).toBeVisible();
		await expect(nav.getByRole('link', { name: 'Page 3', exact: true })).toBeVisible();
		await expect(nav.locator('.wp-block-query-pagination-previous')).toBeVisible();
		await expect(nav.locator('.wp-block-query-pagination-next')).toBeVisible();
	});

	test('the results shell stays visible at a 390px viewport', async ({ page }) => {
		await page.setViewportSize(MOBILE_VIEWPORT);
		await page.goto(BROAD_QUERY);

		await expect(resultsHeading(page)).toBeVisible();
		await expect(productCards(page).first()).toBeVisible();
		await expect(headerSearchInput(page)).toBeVisible();
		await expect(pagination(page)).toBeVisible();
	});

	// KNOWN FAILING — the contract this asserts is the one we want, and the
	// current frontend does not meet it. At 390px the provisioned storefront
	// scrolls horizontally (390px viewport, 431px document), and hiding just the
	// two plugin-owned blocks below brings the document back to exactly 390px:
	//
	//   .shift64-woo-search-block--form    header search block  -> 431px right edge
	//   .shift64-woo-search-product-sort   results sort pill    -> 418px right edge
	//
	// The header block is a flex item whose control keeps its content min-size
	// (input + submit button), so it cannot shrink into the header row. Tracked
	// in #101; whether the fix belongs in the blocks' own CSS or in the
	// provisioned header layout is that issue's call, not this file's.
	//
	// Marked test.fail() rather than weakened, per the same convention as the
	// pagination-ownership assertions in tests/e2e/block-theme/blockified.spec.ts:
	// a relaxed assertion would codify the overflow as acceptable. When #101
	// lands this starts passing, Playwright reports that as a failure, and that
	// is the signal to drop this marker.
	test('the 390px results shell introduces no horizontal overflow', async ({ page }) => {
		test.fail();
		await page.setViewportSize(MOBILE_VIEWPORT);
		await page.goto(BROAD_QUERY);
		await expect(productCards(page).first()).toBeVisible();

		const overflows = await page.evaluate(
			() => document.documentElement.scrollWidth > window.innerWidth
		);
		expect(overflows).toBe(false);
	});
});
