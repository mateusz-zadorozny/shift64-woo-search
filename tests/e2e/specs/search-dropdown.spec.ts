import { expect, test } from '@playwright/test';
import { SEARCH_PAGE, SEL, searchInput, traySection, typeQuery, visibleTray } from '../helpers/search';

test.describe('search dropdown', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(SEARCH_PAGE);
	});

	// Test 1: type "athena" → dropdown visible, ≥1 matching product row, Products header.
	test('typing a product name opens the dropdown with matching products', async ({ page }) => {
		await typeQuery(page, 'athena');

		await expect(visibleTray(page)).toBeVisible();
		const products = traySection(page, 'products');
		await expect(products).toBeVisible();
		await expect(products.locator(SEL.sectionHeader)).toHaveText('PRODUCTS');

		const titles = products.locator(SEL.rowTitle);
		await expect(titles.first()).toBeVisible();
		await expect(titles.first()).toContainText(/athena/i);
	});

	// Test 7: submit "athena" → lands on /?s=athena&post_type=product with results.
	test('submitting the form lands on the results page with matching products', async ({ page }) => {
		const input = searchInput(page);
		await input.fill('athena');
		await input.press('Enter');

		await page.waitForURL((url) => {
			return url.searchParams.get('s') === 'athena' && url.searchParams.get('post_type') === 'product';
		});

		const grid = page.locator(SEL.productsGrid).first();
		await expect(grid).toBeVisible();
		await expect(grid).toContainText(/Athena/);
	});
});
