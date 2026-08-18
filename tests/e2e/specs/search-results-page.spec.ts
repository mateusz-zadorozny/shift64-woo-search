import { expect, test } from '@playwright/test';
import { SEARCH_PAGE, SEL, traySection, typeQuery, visibleTray } from '../helpers/search';

// The seeded catalog spans four verticals (apparel, tech, home, beauty), so no
// single category ancestor covers it any more — "clothing" now matches only the
// 12 apparel products. Every generated description instead carries the shared
// phrase "belongs to the <X> series and is built for everyday use", so "series"
// matches all 48: 3 pages at 16/page, every color present. This is the broadest
// query the deterministic catalog offers, used for filter and pagination
// journeys. If the description template in bin/demo-product-catalog.php changes,
// this constant must change with it.
const BROAD_QUERY = '/?s=series&post_type=product';

// "Pulse" is the most frequent name prefix in the seeded 48 (8 products, across
// all four verticals and both product types) — enough for a meaningful relevance
// assertion without depending on a single product.
const RELEVANCE_QUERY = '/?s=pulse&post_type=product';

const VISIBILITY_FIXTURE_NAME = 'Visibilityfixture Catalog-only Product';
const VISIBILITY_CONTROL_NAME = 'Visibilityfixture Visible Product';
const VISIBILITY_FIXTURE_QUERY = '/?s=visibilityfixture&post_type=product';
const VISIBILITY_FIXTURE_PERMALINK = '/product/visibilityfixture-catalog-only-product/';

function productCards(page: import('@playwright/test').Page) {
	return page.locator(SEL.productsGrid).first().locator('li.product');
}

test.describe('search results page (Redis takeover)', () => {
	// Test 8: takeover renders relevant results; orderby control shows Relevance.
	test('renders relevant results with the relevance sort control', async ({ page }) => {
		await page.goto(RELEVANCE_QUERY);

		const cards = productCards(page);
		await expect(cards.first()).toBeVisible();
		await expect(cards.first()).toContainText(/Pulse/);

		// Block themes render the ordering control once (via WooCommerce's
		// blockified compatibility layer); Storefront renders it twice (above
		// and below the loop) — assert on the first so both hold.
		const orderby = page.locator(SEL.orderbySelect).first();
		await expect(orderby).toBeVisible();
		await expect(orderby).toHaveValue('relevance');
		await expect(orderby.locator('option[value="relevance"]')).toHaveText('Search relevance');
	});

	test('excludes catalog-only products from search surfaces while keeping direct access', async ({
		page,
	}) => {
		await page.goto(VISIBILITY_FIXTURE_QUERY);

		await expect(productCards(page).filter({ hasText: VISIBILITY_CONTROL_NAME })).toHaveCount(1);
		await expect(productCards(page).filter({ hasText: VISIBILITY_FIXTURE_NAME })).toHaveCount(0);

		await page.goto(SEARCH_PAGE);
		await typeQuery(page, 'visibilityfixture');
		await expect(visibleTray(page)).toBeVisible();
		const autocompleteProducts = traySection(page, 'products');
		await expect(autocompleteProducts.locator(SEL.row, { hasText: VISIBILITY_CONTROL_NAME })).toHaveCount(
			1
		);
		await expect(autocompleteProducts.locator(SEL.row, { hasText: VISIBILITY_FIXTURE_NAME })).toHaveCount(
			0
		);

		const response = await page.goto(VISIBILITY_FIXTURE_PERMALINK);
		expect(response?.status()).toBeLessThan(400);
		await expect(
			page.getByRole('heading', { name: VISIBILITY_FIXTURE_NAME, exact: true })
		).toBeVisible();
	});

	// Test 9: color facet checkbox → URL gains filter_pa_color=copper; results filtered.
	// The multi-vertical catalog has no plain "Green" finish; "Copper" is a real
	// seeded color shared by exactly two products (a tech monitor and a home
	// pendant light), which keeps the post-filter result set on a single page.
	test('checking a color facet filters the grid and updates the URL', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		const filters = page.locator(SEL.filters).first();
		await expect(filters).toBeVisible();

		// Desktop pills keep their checkbox lists inside a dropdown — open it first.
		await filters.locator('[data-filter-key] .shift64-woo-search-filter__pill', { hasText: /color/i }).click();
		await filters.locator(`${SEL.filterCheckbox}[data-taxonomy="pa_color"][data-slug="copper"]`).check();

		await expect(page).toHaveURL(/filter_pa_color=copper/);

		const cards = productCards(page);
		await expect(cards.first()).toBeVisible();
		const count = await cards.count();
		expect(count).toBeGreaterThan(0);
		for (let i = 0; i < count; i++) {
			await expect(cards.nth(i)).toContainText(/Copper/);
		}

		// Copper narrows 48 results to a single page, so no pagination control
		// may remain visible. On a block theme WooCommerce re-renders and
		// drops the nav from the DOM; on classic markup the plugin's swap
		// hides it — toBeHidden() accepts both (zero matches or display:none).
		// The stricter classic-only `display` assertion lives in
		// tests/e2e/classic-theme/classic.spec.ts.
		await expect(page.locator(SEL.pagination).first()).toBeHidden();
	});

	// Test 10: orderby=price → rendered prices non-decreasing.
	test('price sort renders prices in non-decreasing order', async ({ page }) => {
		await page.goto(`${BROAD_QUERY}&orderby=price`);

		const cards = productCards(page);
		await expect(cards.first()).toBeVisible();

		const count = await cards.count();
		expect(count).toBeGreaterThan(1);

		const prices: number[] = [];
		for (let i = 0; i < count; i++) {
			const text = await cards.nth(i).locator(SEL.price).first().innerText();
			const value = parseFloat(text.replace(/[^\d,.]/g, '').replace(',', '.'));
			expect(Number.isNaN(value)).toBe(false);
			prices.push(value);
		}
		for (let i = 1; i < prices.length; i++) {
			expect(prices[i]).toBeGreaterThanOrEqual(prices[i - 1]);
		}
	});

	// The plugin-owned AJAX pagination journey ("pagination swaps the grid via
	// AJAX without reloading") lives in tests/e2e/classic-theme/ — it only
	// exists on classic Woo markup. The block-theme pagination journeys live
	// in tests/e2e/block-theme/.
});
