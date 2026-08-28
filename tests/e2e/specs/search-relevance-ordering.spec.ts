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
// seeded products: 3 pages at 16/page. Same broad query, and same coupling to
// bin/demo-product-catalog.php, as specs/search-results-page.spec.ts.
const BROAD_QUERY = '/?s=series&post_type=product';

// The deterministic leading titles of that query, in relevance order, as
// bin/demo-product-catalog.php seeds them. These are exact on purpose: a
// weaker assertion (a count, or a regex on one name) would still pass if
// ranking were applied after slicing. If the generator's names, ordering, or
// page size change, update BOTH arrays here AND the expected values recorded in
// the design doc above, in the same PR.
const PAGE_ONE = [
	'Aero Artemis Sweater Lightweight Midnight Black',
	'Aero Cipher Laptop Ultra Wide Space Grey',
	'Aero Cascade Micellar Cleanser Intensive Rose',
	'Lumen Apollo Cardigan Everyday Forest Green',
];

const PAGE_TWO = [
	'Onyx Nike Skirt Ribbed Cobalt Blue',
	'Onyx Spectra Smartphone Fast Charge Ruby Red',
	'Onyx Serene Conditioner Lightweight Coconut',
	'Crest Maia Jacket Brushed Deep Navy',
];

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
		const pageOneRendered = (await cardTitles(page).allInnerTexts()).slice(0, PAGE_TWO.length);

		// Navigate the way a user does — the rendered pagination control.
		await pagination(page).getByRole('link', { name: 'Page 2', exact: true }).click();

		await expect(page).toHaveURL(PAGE_2_URL);
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');
		await expectLeadingTitles(cardTitles(page), PAGE_TWO);

		// Compare what was actually rendered, not the two constants: ranking
		// applied after slicing would repeat page 1's products here, and that
		// has to fail even if someone later edits the expected arrays.
		const pageTwoRendered = (await cardTitles(page).allInnerTexts()).slice(0, PAGE_TWO.length);
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
