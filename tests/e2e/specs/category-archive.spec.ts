import { expect, test } from '@playwright/test';
import { SEL } from '../helpers/search';

// Test 12: category archive takeover + Product Filters block (also proves pretty
// permalinks). The block renders pills only when the RediSearch interceptor ran
// and produced facet data, so their presence is the takeover's observable
// artifact — the block is placed in the template either way.
test('category archive is intercepted and the Product Filters block offers facets', async ({
	page,
}) => {
	await page.goto('/product-category/t-shirts/');

	const cards = page.locator(SEL.productsGrid).first().locator('li.product');
	await expect(cards.first()).toBeVisible();

	const count = await cards.count();
	expect(count).toBeGreaterThan(0);
	// The T-Shirts category holds both "T-Shirt" and "Polo Shirt" items in the
	// multi-vertical catalog, so match the shared "Shirt" token rather than the
	// narrower "T-Shirt" one.
	for (let i = 0; i < count; i++) {
		await expect(cards.nth(i)).toContainText(/Shirt/);
	}

	const filters = page.locator(SEL.productFilters).first();
	await expect(filters).toBeVisible();
	await expect(
		filters.locator(`${SEL.productFilterCheckbox}[name="filter_pa_color[]"]`).first()
	).toBeAttached();
});
