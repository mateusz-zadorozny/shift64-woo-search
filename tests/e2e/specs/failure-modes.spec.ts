import { expect, test } from '@playwright/test';
import { rateLimitedResponse, redisDownPayload } from '../helpers/mocks';
import {
	SEARCH_PAGE,
	SEL,
	isAutocompleteRequest,
	searchInput,
	traySection,
	typeQuery,
	visibleTray,
} from '../helpers/search';

/**
 * JS failure modes via route mocks against a HEALTHY page: the interception
 * never reaches the network, but the page itself needs real assets and a real
 * localized config. Routes match mode=autocomplete only — the focus-triggered
 * mode=suggestions request must keep hitting the real endpoint.
 */
test.describe('failure modes (route-mocked)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(SEARCH_PAGE);
	});

	// Test 15: parked route → 5 s timeout message; redisDown latch stops further
	// fetches; form submit still reaches the real results page.
	test('request timeout shows the timeout message, latches, and submit still works', async ({ page }) => {
		let autocompleteCount = 0;
		await page.route(
			(url) => url.href.includes('mode=autocomplete'),
			() => {
				// Never fulfill — the client's own 5 s timer must fire.
				autocompleteCount++;
			}
		);

		await typeQuery(page, 'athena');

		const message = visibleTray(page).locator(SEL.empty);
		await expect(message).toHaveText('Quick search timed out. Use the search button.', { timeout: 8000 });

		// Prove the latch: typing a new valid query must not issue a request even
		// after the debounce window — the bounded waitForRequest must time out.
		await searchInput(page).fill('hoodie');
		await expect(page.waitForRequest(isAutocompleteRequest, { timeout: 700 })).rejects.toThrow();

		await searchInput(page).press('Enter');
		await page.waitForURL(/[?&]s=hoodie/);
		await expect(page.locator(SEL.productsGrid).first().locator('li.product').first()).toBeVisible();

		expect(autocompleteCount).toBe(1);
	});

	// Test 16: fulfilled 429 → dropdown error state (the client renders its own
	// errorText and ignores both the HTTP status and the server's message);
	// native form submit unaffected. This path does NOT set the redisDown latch.
	test('a 429 response renders the error state and submit still works', async ({ page }) => {
		await page.route(
			(url) => url.href.includes('mode=autocomplete'),
			(route) => route.fulfill(rateLimitedResponse)
		);

		await typeQuery(page, 'athena');

		const message = visibleTray(page).locator(SEL.empty);
		await expect(message).toHaveText('Something went wrong. Please try again.');

		await searchInput(page).press('Enter');
		await page.waitForURL(/[?&]s=athena/);
		await expect(page.locator(SEL.productsGrid).first().locator('li.product').first()).toBeVisible();
	});

	// Test 17: fulfilled success:false + fallback → tray closes, redisDown latch
	// holds (autocomplete route counter stays at 1), form submit works.
	test('a fallback response closes the tray, latches, and submit still works', async ({ page }) => {
		let autocompleteCount = 0;
		await page.route(
			(url) => url.href.includes('mode=autocomplete'),
			(route) => {
				autocompleteCount++;
				return route.fulfill({
					contentType: 'application/json; charset=utf-8',
					body: JSON.stringify(redisDownPayload('athena')),
				});
			}
		);

		// Anchor on the mocked round trip so tray-absence proves the client
		// handled the fallback (an immediate check would pass vacuously).
		const fallbackHandled = page.waitForResponse((res) => isAutocompleteRequest(res.request()));
		await typeQuery(page, 'athena');
		await fallbackHandled;
		await expect(visibleTray(page)).toHaveCount(0);

		// Prove the latch before navigating: no request may fire for a new query.
		await searchInput(page).fill('hoodie');
		await expect(page.waitForRequest(isAutocompleteRequest, { timeout: 700 })).rejects.toThrow();

		await searchInput(page).press('Enter');
		await page.waitForURL(/[?&]s=hoodie/);
		await expect(page.locator(SEL.productsGrid).first().locator('li.product').first()).toBeVisible();

		expect(autocompleteCount).toBe(1);
	});

	test.afterEach(async ({ page }) => {
		await page.unrouteAll({ behavior: 'ignoreErrors' });
	});
});

// Sanity guard for the helper predicate the counters above rely on.
test('autocomplete request predicate matches only autocomplete traffic', async ({ page }) => {
	const seen: string[] = [];
	page.on('request', (req) => {
		if (isAutocompleteRequest(req)) {
			seen.push(req.url());
		}
	});
	await page.goto(SEARCH_PAGE);
	await searchInput(page).click();
	await typeQuery(page, 'athena');
	await expect(traySection(page, 'products')).toBeVisible();
	expect(seen.length).toBe(1);
	expect(seen[0]).toContain('mode=autocomplete');
});
