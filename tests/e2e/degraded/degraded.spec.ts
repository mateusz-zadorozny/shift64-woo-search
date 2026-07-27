import { expect, test } from '@playwright/test';
import { wpCli } from '../helpers/env';
import {
	ENDPOINT_PATH,
	SEARCH_PAGE,
	SEL,
	isAutocompleteRequest,
	searchInput,
	typeQuery,
	visibleTray,
} from '../helpers/search';

/**
 * Real environment mutation — no mocks. The degrade setup pointed the Redis
 * port option AND the generated SHORTINIT config at a dead port; the restore
 * teardown heals both.
 */

// Test 18: real dead Redis — no dropdown, no page errors, endpoint returns the
// fallback JSON, and /?s= serves native WordPress search.
test('dead Redis degrades gracefully to native search', async ({ page }) => {
	const pageErrors: Error[] = [];
	page.on('pageerror', (err) => pageErrors.push(err));

	await page.goto(SEARCH_PAGE);

	// Anchor on the real round trip: asserting tray-absence before the debounced
	// fetch resolves would pass vacuously. Only after the fallback response has
	// been handled does "no dropdown" prove the client's behavior.
	const fallbackResponse = page.waitForResponse((res) => isAutocompleteRequest(res.request()));
	await typeQuery(page, 'athena');
	const clientResponse = await fallbackResponse;
	expect((await clientResponse.json()).success).toBe(false);

	await expect(visibleTray(page)).toHaveCount(0);
	// The response handler must also have cleared the pending loading placeholder.
	await expect(page.locator('.shift64-woo-search-results__loading')).toHaveCount(0);

	const res = await page.request.get(`${ENDPOINT_PATH}?q=athena&mode=autocomplete`);
	expect(res.status()).toBe(200);
	const body = await res.json();
	expect(body.success).toBe(false);
	expect(body.fallback).toContain('s=athena');

	await page.goto('/?s=athena&post_type=product');
	const cards = page.locator(SEL.productsGrid).first().locator('li.product');
	await expect(cards.first()).toBeVisible();
	await expect(cards.first()).toContainText(/Athena/);

	expect(pageErrors).toEqual([]);
});

// Test 19: cleared redis_host — the asset-enqueue gate reads the live option,
// so the script tag and localized config disappear entirely; the plain form
// still submits natively.
test('cleared Redis host disables assets and native form submit works', async ({ page }) => {
	wpCli(['option', 'update', 'shift64_woo_search_redis_host', '']);

	await page.goto(SEARCH_PAGE);

	await expect(page.locator('script#shift64-woo-search-js')).toHaveCount(0);
	const hasConfig = await page.evaluate(() => {
		return typeof (window as unknown as Record<string, unknown>).shift64_woo_search_config !== 'undefined';
	});
	expect(hasConfig).toBe(false);

	// With no JS the form performs a genuine native GET submit.
	const input = searchInput(page);
	await input.fill('athena');
	await input.press('Enter');
	await page.waitForURL(/[?&]s=athena.*post_type=product|[?&]post_type=product.*s=athena/);

	const cards = page.locator(SEL.productsGrid).first().locator('li.product');
	await expect(cards.first()).toBeVisible();
	await expect(cards.first()).toContainText(/Athena/);
});
