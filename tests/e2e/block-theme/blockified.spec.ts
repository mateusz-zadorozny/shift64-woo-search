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
 * These assertions were introduced as `test.fail()` while #20 was decided but
 * unimplemented. #20 has landed (ownsPagination() in the AJAX pagination
 * script), so they are now ordinary passing tests and the markers are gone.
 *
 * What they caught on the pre-#20 code, for the record:
 *   - enhanced click  -> 2 fetches of the target page, and 2 history entries,
 *                        so Back needed two presses to leave page 2
 *   - forcePageReload -> the plugin intercepted; no real navigation happened
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
// journeys use. "series" (not "clothing") is what spans the whole multi-vertical
// catalog; see the comment in specs/search-results-page.spec.ts.
const BROAD_QUERY = '/?s=series&post_type=product';
const EMPTY_QUERY = '/?s=zzqvwxjklmnp&post_type=product';
const PAGE_2 = /\/page\/2\/|[?&](?:paged|query-page|query-\d+-page)=2/;

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

/**
 * Count only target-page requests issued by this plugin's AJAX pagination.
 * WooCommerce may legitimately prefetch and navigate to the same URL.
 */
function countPluginTargetRequests(page: import('@playwright/test').Page): { total: () => number } {
	let n = 0;
	page.on('request', (req) => {
		const type = req.resourceType();
		const requestedWith = req.headers()['x-requested-with'];
		if (
			(type === 'fetch' || type === 'xhr') &&
			PAGE_2.test(req.url()) &&
			requestedWith === 'XMLHttpRequest'
		) {
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

	test('direct page links and browser refresh preserve Redis membership', async ({ page }) => {
		await page.goto(BROAD_QUERY);
		await expect(productCards(page).first()).toBeVisible();

		const page2Href = await page.locator('a.page-numbers', { hasText: '2' }).first().getAttribute('href');
		expect(page2Href).toBeTruthy();
		await page.goto(page2Href!);

		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');
		const firstProductHref = await productCards(page).first().locator('a').first().getAttribute('href');
		expect(firstProductHref).toBeTruthy();

		await page.reload();
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');
		await expect(productCards(page).first().locator('a').first()).toHaveAttribute(
			'href',
			firstProductHref!
		);
	});

	test('direct Product Collection paging updates breadcrumbs', async ({ page }) => {
		await page.goto(`${BROAD_QUERY}&query-0-page=2`);

		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');
		await expect(page.locator('.woocommerce-breadcrumb').first()).toContainText('Page 2');
	});

	test('facet changes reset Product Collection paging to page one', async ({ page }) => {
		await page.goto(`${BROAD_QUERY}&query-0-page=2`);
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');

		await page
			.locator(`${SEL.filterCheckbox}[data-taxonomy="pa_color"][data-slug="amber-musk"]`)
			.first()
			.check();

		await expect(page).toHaveURL(/filter_pa_color=amber-musk/);
		await expect(page).not.toHaveURL(/[?&](?:page|paged|query-page|query-\d+-page)=/);
		await expect(page.locator('.page-numbers.current').first()).toHaveText('1');
		await expect(productCards(page)).toHaveCount(1);
	});

	test('catalog navigation rejects unsafe destinations', async ({ page }) => {
		await page.goto(BROAD_QUERY);
		const moduleUrl =
			'/wp-content/plugins/shift64-woo-search/frontend/js/shift64-woo-search-catalog-navigation.js';
		const result = await page.evaluate(async (url) => {
			const navigation = await import(url);
			const outcomes: Record<string, boolean> = {};
			for (const [name, destination] of Object.entries({
				javascript: 'javascript:alert(1)',
				crossOrigin: 'https://example.com/shop/',
			})) {
				try {
					navigation.buildCatalogUrl(destination, { orderby: 'price' });
					outcomes[name] = false;
				} catch {
					outcomes[name] = true;
				}
			}
			try {
				await navigation.navigate('https://example.com/shop/', { forceReload: true });
				outcomes.navigate = false;
			} catch {
				outcomes.navigate = true;
			}
			return outcomes;
		}, moduleUrl);

		expect(result).toEqual({ javascript: true, crossOrigin: true, navigate: true });
		await expect(page).toHaveURL(/post_type=product/);
	});

	test('an empty Redis membership lets Product Collection render its no-results surface', async ({
		page,
	}) => {
		await page.goto(EMPTY_QUERY);

		await expect(productCards(page)).toHaveCount(0);
		await expect(
			page
				.locator(
					'.wp-block-woocommerce-product-collection-no-results, .woocommerce-info'
				)
				.first()
		).toBeVisible();
	});

	// WooCommerce owns this click, so the target page must be fetched exactly
	// once. A plugin-side swap here would duplicate both network and history.
	test('fetches the target page exactly once — no duplicate plugin swap', async ({ page }) => {
		await page.goto(BROAD_QUERY);
		await expect(productCards(page).first()).toBeVisible();

		const requests = countTargetRequests(page);
		await page.locator('a.page-numbers', { hasText: '2' }).first().click();
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');
		// Let any second, duplicate fetch arrive before counting.
		await page.waitForTimeout(2000);

		expect(requests.total()).toBe(1);
	});

	test('one click creates one history entry, and Back/Forward restore server state', async ({
		page,
	}) => {
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
		await expect(page.locator('.page-numbers.current').first()).toHaveText('1');

		await page.goForward();
		await expect(page).toHaveURL(PAGE_2);
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');
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

	// OWNERSHIP. With forcePageReload the site has opted out of client-side
	// navigation, so this plugin must not substitute its own AJAX swap. What it
	// asserts is deliberately narrow — that the plugin adds no fetch of its own
	// — rather than "a real page navigation happens".
	//
	// Why not the stronger assertion: WooCommerce gates the router region on
	// $block['attrs'] but the pagination link directives on $this->parsed_block,
	// which it captures in pre_render_block — BEFORE render_block_data, where
	// the fixture sets the attribute. So the fixture can drop the router region
	// but cannot stop the directives, and the resulting page still has Woo's
	// navigate action bound with nothing to re-render.
	//
	// That is WooCommerce's behavior, not ours: with this plugin's pagination
	// script blocked entirely, stock WooCommerce produces exactly the same
	// result (URL advances to /page/2/, no reload, indicator stuck on "1").
	// Asserting a full navigation here would test a WooCommerce bug and would
	// fail for reasons this plugin cannot fix. See #20.
	test('adds no AJAX interception of its own', async ({ page }) => {
		await page.goto(BROAD_QUERY);
		await expect(productCards(page).first()).toBeVisible();

		const requests = countPluginTargetRequests(page);
		await page.locator('a.page-numbers', { hasText: '2' }).first().click();
		await expect(page).toHaveURL(PAGE_2);
		// Give a stray plugin-issued fetch time to appear before counting.
		await page.waitForTimeout(2000);

		// WooCommerce may prefetch and navigate to the same URL. The plugin
		// must not add its XMLHttpRequest-marked fetch to either request.
		expect(requests.total()).toBe(0);
	});
});
