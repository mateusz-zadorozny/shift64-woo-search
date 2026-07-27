import { expect, test } from '@playwright/test';
import { SEL } from '../helpers/search';

// "clothing" matches every seeded product through the category ancestor chain:
// 48 results = 3 pages at 16/page, every color present — the broadest query the
// deterministic catalog offers, used for filter and pagination journeys.
const BROAD_QUERY = '/?s=clothing&post_type=product';

function productCards(page: import('@playwright/test').Page) {
	return page.locator(SEL.productsGrid).first().locator('li.product');
}

test.describe('search results page (Redis takeover)', () => {
	// Test 8: takeover renders relevant results; orderby control shows Relevance.
	test('renders relevant results with the relevance sort control', async ({ page }) => {
		await page.goto('/?s=athena&post_type=product');

		const cards = productCards(page);
		await expect(cards.first()).toBeVisible();
		await expect(cards.first()).toContainText(/Athena/);

		// Storefront renders the ordering control twice (above and below the
		// loop); block themes render it once — assert on the first.
		const orderby = page.locator(SEL.orderbySelect).first();
		await expect(orderby).toBeVisible();
		await expect(orderby).toHaveValue('relevance');
		await expect(orderby.locator('option[value="relevance"]')).toHaveText('Search relevance');
	});

	// Test 9: color facet checkbox → URL gains filter_pa_color=green; results filtered.
	test('checking a color facet filters the grid and updates the URL', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		const filters = page.locator(SEL.filters).first();
		await expect(filters).toBeVisible();

		// Desktop pills keep their checkbox lists inside a dropdown — open it first.
		await filters.locator('[data-filter-key] .shift64-woo-search-filter__pill', { hasText: /color/i }).click();
		await filters.locator(`${SEL.filterCheckbox}[data-taxonomy="pa_color"][data-slug="green"]`).check();

		await expect(page).toHaveURL(/filter_pa_color=green/);

		const cards = productCards(page);
		await expect(cards.first()).toBeVisible();
		const count = await cards.count();
		expect(count).toBeGreaterThan(0);
		for (let i = 0; i < count; i++) {
			await expect(cards.nth(i)).toContainText(/Green/);
		}
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

	// Test 11: AJAX pagination → grid changes without a full page load.
	test('pagination swaps the grid via AJAX without reloading', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		const cards = productCards(page);
		await expect(cards.first()).toBeVisible();
		const firstTitleBefore = await cards.first().innerText();

		// A full navigation would wipe this flag; the AJAX swap must keep it.
		await page.evaluate(() => {
			(window as unknown as Record<string, unknown>).__e2eNoReload = true;
		});

		await page.locator('a.page-numbers', { hasText: '2' }).first().click();

		await expect(page).toHaveURL(/(\/page\/2\/|[?&]paged=2)/);
		await expect(cards.first()).not.toHaveText(firstTitleBefore);

		// The pagination control itself must be swapped too, not just the grid.
		// `.page-numbers.current` covers classic Woo (span.page-numbers.current)
		// and blockified markup (span.page-numbers.current[aria-current="page"]),
		// so the same assertion holds on Storefront and on a block theme.
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');

		const flagSurvived = await page.evaluate(() => {
			return (window as unknown as Record<string, unknown>).__e2eNoReload === true;
		});
		expect(flagSurvived).toBe(true);
	});
});
