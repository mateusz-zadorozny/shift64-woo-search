import { copyFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { BLOCK_THEME, wpCli } from '../helpers/env';
import { SEL } from '../helpers/search';

/**
 * Blockified (block-theme) projection of the pagination journeys — issue #17.
 *
 * The rest of the suite runs on Storefront, whose classic WooCommerce markup
 * hid a whole class of frontend bug from CI (#15). This file adds the missing
 * projection: a real block theme rendering WooCommerce's Product Collection.
 *
 * WHAT THIS FILE ASSERTS IS AN OWNERSHIP CONTRACT, NOT "the plugin swaps
 * everything". Per the decision on #20, pagination ownership is:
 *
 *   | context                                    | owner            |
 *   |--------------------------------------------|------------------|
 *   | classic Woo markup, Kadence, custom pager   | THIS PLUGIN      |
 *   | Product Collection + data-wp-router-region  | WooCommerce (IA) |
 *   | Product Collection + forcePageReload        | the browser      |
 *
 * Scenario 1 (classic markup, plugin owns the AJAX swap) is already covered on
 * Storefront by tests/e2e/specs/search-results-page.spec.ts — it is not
 * duplicated here. This file covers the two block-theme columns.
 *
 * EXPECTED FAILURES: the ownership model is decided but NOT YET IMPLEMENTED
 * (#20). The plugin currently intercepts pagination clicks everywhere, so the
 * ownership assertions below are marked `test.fail()`. That is deliberate:
 * they run against real behavior, keep the suite green while production is
 * still wrong, and turn RED the moment #20 lands — which is the signal to drop
 * the marker. They must NOT be relaxed into passing tests, because a passing
 * version of these assertions would codify the opposite contract.
 *
 * Measured on the pre-#20 code, for reference:
 *   - enhanced click  -> 2 fetches of the target page, 2 history entries
 *   - forcePageReload -> plugin still intercepts; no real navigation
 *
 * REAL environment mutation, like the degraded project: this file activates a
 * block theme in beforeAll and restores the previous one in afterAll, and the
 * forcePageReload describe installs/removes a test-only mu-plugin around
 * itself. The bounds are beforeAll/afterAll rather than a Playwright
 * setup/teardown project pair on purpose — a spec file is the unit a worker
 * runs to completion, so no other project can observe the switched theme, and
 * afterAll still runs when a test fails. If a run is hard-killed in between,
 * restore with `wp theme activate storefront` and delete
 * wp-content/mu-plugins/shift64-e2e-force-page-reload.php.
 */

// 48 results = 3 pages at 16/page — the same broad query the Storefront
// journeys use.
const BROAD_QUERY = '/?s=clothing&post_type=product';
const PAGE_2 = /\/page\/2\/|[?&]paged=2/;

const MU_FIXTURE = 'shift64-e2e-force-page-reload.php';

let originalTheme = '';

test.beforeAll(() => {
	originalTheme = wpCli(['theme', 'list', '--status=active', '--field=name']).trim();
	if (originalTheme !== BLOCK_THEME) {
		wpCli(['theme', 'activate', BLOCK_THEME]);
	}
});

test.afterAll(() => {
	if (originalTheme && originalTheme !== BLOCK_THEME) {
		wpCli(['theme', 'activate', originalTheme]);
	}
});

function productCards(page: import('@playwright/test').Page) {
	return page.locator(SEL.productsGrid).first().locator('li.product');
}

/**
 * Count document/fetch requests for the *target* page only. Scoping to page 2
 * matters: WooCommerce prefetches page 3 on hover, so counting "any paged
 * request" would fold an unrelated, legitimate prefetch into the duplicate.
 */
function countTargetRequests(page: import('@playwright/test').Page): { total: () => number } {
	let n = 0;
	page.on('request', (req) => {
		const type = req.resourceType();
		if ((type === 'document' || type === 'fetch' || type === 'xhr') && PAGE_2.test(req.url())) {
			n += 1;
		}
	});
	return { total: () => n };
}

test.describe('block theme + enhanced pagination (WooCommerce owns navigation)', () => {
	// The projection is only worth anything if the theme really renders
	// blockified markup with the Interactivity API live. Without this guard, a
	// theme that quietly fell back to classic markup would make every
	// assertion below meaningless.
	test('renders blockified markup with the Interactivity API in charge', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		await expect(productCards(page).first()).toBeVisible();
		await expect(page.locator('.wp-block-woocommerce-product-template').first()).toBeAttached();
		await expect(page.locator('nav.wp-block-query-pagination').first()).toBeAttached();
		await expect(page.locator('nav.woocommerce-pagination')).toHaveCount(0);

		// Enhanced pagination really is on: the router region is what makes
		// WooCommerce the owner in this column of the matrix.
		await expect(
			page.locator('[data-wp-router-region^="wc-product-collection"]').first()
		).toBeAttached();
	});

	// Outcome-level correctness. This passes today (both handlers happen to
	// produce the right end state) and must KEEP passing after #20, when Woo
	// alone produces it — which is exactly why it is not marked as failing.
	test('paging lands on the right page without a full reload', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		const cards = productCards(page);
		await expect(cards.first()).toBeVisible();
		const firstTitleBefore = await cards.first().innerText();

		await page.evaluate(() => {
			(window as unknown as Record<string, unknown>).__e2eNoReload = true;
		});

		await page.locator('a.page-numbers', { hasText: '2' }).first().click();

		await expect(page).toHaveURL(PAGE_2);
		await expect(cards.first()).not.toHaveText(firstTitleBefore);
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');

		const noFullReload = await page.evaluate(
			() => (window as unknown as Record<string, unknown>).__e2eNoReload === true
		);
		expect(noFullReload).toBe(true);
	});

	// OWNERSHIP (expected to fail until #20). WooCommerce owns this click, so
	// the target page must be fetched exactly once. Today the plugin's
	// delegated handler fetches it as well, so the browser issues two.
	test('fetches the target page exactly once — no duplicate plugin swap', async ({ page }) => {
		test.fail();

		await page.goto(BROAD_QUERY);
		await expect(productCards(page).first()).toBeVisible();

		const requests = countTargetRequests(page);
		await page.locator('a.page-numbers', { hasText: '2' }).first().click();
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');
		// Let any second, duplicate fetch arrive before counting.
		await page.waitForTimeout(2000);

		expect(requests.total()).toBe(1);
	});

	// OWNERSHIP (expected to fail until #20). Back/forward is the user-visible
	// cost of dual ownership: the plugin pushes its own history entry and Woo
	// pushes another, so ONE click stacks TWO entries and the user has to press
	// Back twice to leave page 2.
	test('one click creates one history entry, and Back returns to page 1', async ({ page }) => {
		test.fail();

		await page.goto(BROAD_QUERY);
		await expect(productCards(page).first()).toBeVisible();

		const historyBefore = await page.evaluate(() => history.length);

		await page.locator('a.page-numbers', { hasText: '2' }).first().click();
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');
		await page.waitForTimeout(2000);

		const historyAfter = await page.evaluate(() => history.length);
		expect(historyAfter - historyBefore).toBe(1);

		await page.goBack();
		await expect(page).not.toHaveURL(PAGE_2);
	});
});

test.describe('block theme + forcePageReload (the browser owns navigation)', () => {
	let installedMuPath = '';

	test.beforeAll(() => {
		const muDir = wpCli(['eval', 'echo WPMU_PLUGIN_DIR;']).trim();
		if (!muDir) {
			throw new Error('Could not resolve WPMU_PLUGIN_DIR from the target install.');
		}
		installedMuPath = join(muDir, MU_FIXTURE);
		// __dirname, not import.meta: Playwright transpiles specs to CJS.
		copyFileSync(join(__dirname, 'force-page-reload.mu.php'), installedMuPath);
	});

	test.afterAll(() => {
		if (installedMuPath) {
			rmSync(installedMuPath, { force: true });
		}
	});

	// Guard: the fixture really put the site in the third column of the matrix.
	test('renders blockified markup with enhanced pagination disabled', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		await expect(productCards(page).first()).toBeVisible();
		await expect(page.locator('nav.wp-block-query-pagination').first()).toBeAttached();
		await expect(page.locator('[data-wp-router-region^="wc-product-collection"]')).toHaveCount(0);
	});

	// OWNERSHIP (expected to fail until #20). With forcePageReload the site has
	// explicitly asked for plain browser navigation. The plugin must respect
	// that rather than substitute its own AJAX swap; today it intercepts the
	// click, so no real navigation happens.
	test('performs a real page navigation instead of an AJAX swap', async ({ page }) => {
		test.fail();

		await page.goto(BROAD_QUERY);
		await expect(productCards(page).first()).toBeVisible();

		// A genuine navigation discards this flag; an AJAX swap preserves it.
		await page.evaluate(() => {
			(window as unknown as Record<string, unknown>).__e2eNoReload = true;
		});

		await page.locator('a.page-numbers', { hasText: '2' }).first().click();
		await expect(page).toHaveURL(PAGE_2);
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');

		const flagSurvived = await page.evaluate(
			() => (window as unknown as Record<string, unknown>).__e2eNoReload === true
		);
		expect(flagSurvived).toBe(false);
	});
});
