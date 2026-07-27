import { expect, test } from '@playwright/test';
import { SEL } from '../helpers/search';

// Test 12: category archive takeover + facet bar (also proves pretty permalinks).
// The filter bar renders only when the RediSearch interceptor ran and produced
// facet data, so its presence is the takeover's observable artifact.
test('category archive is intercepted and renders the facet bar', async ({ page }) => {
	await page.goto('/product-category/t-shirts/');

	const cards = page.locator(SEL.productsGrid).first().locator('li.product');
	await expect(cards.first()).toBeVisible();

	const count = await cards.count();
	expect(count).toBeGreaterThan(0);
	for (let i = 0; i < count; i++) {
		await expect(cards.nth(i)).toContainText(/T-Shirt/);
	}

	const filters = page.locator(SEL.filters).first();
	await expect(filters).toBeVisible();
	await expect(
		filters.locator(`${SEL.filterCheckbox}[data-taxonomy="pa_color"]`).first()
	).toBeAttached();
});
