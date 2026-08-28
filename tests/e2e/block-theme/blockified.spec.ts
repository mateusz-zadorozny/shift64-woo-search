import { copyFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { BLOCK_THEME, wpCli } from '../helpers/env';
import { SEL } from '../helpers/search';

/**
 * Blockified (block-theme) projection of the pagination journeys — issue #17.
 *
 * Historically the rest of the suite ran on Storefront, whose classic
 * WooCommerce markup hid a whole class of frontend bug from CI (#15), and
 * this file added the missing projection: a real block theme rendering
 * WooCommerce's Product Collection. Since #63 the block theme IS the
 * environment default; this file remains the deep pagination-ownership
 * projection for it.
 *
 * WHAT THIS FILE ASSERTS IS AN OWNERSHIP CONTRACT, NOT "the plugin swaps
 * everything". Pagination ownership, per the decision on #20:
 *
 *   | context                                    | owner            |
 *   |--------------------------------------------|------------------|
 *   | Product Collection + data-wp-router-region  | WooCommerce (IA) |
 *   | Product Collection + forcePageReload        | the browser      |
 *
 * The matrix used to carry a third row — classic Woo markup and custom pagers
 * owned by this plugin's AJAX swap — covered on Storefront by a classic-theme
 * project. The block theme-only release removed that swap and, with it, the row,
 * the project and its spec file. The plugin now owns pagination nowhere, which
 * is what leaves exactly one owner per context.
 *
 * These assertions were introduced as `test.fail()` while #20 was decided but
 * unimplemented. #20 landed, so they are ordinary passing tests and the markers
 * are gone.
 *
 * What they caught on the pre-#20 code, for the record:
 *   - enhanced click  -> 2 fetches of the target page, and 2 history entries,
 *                        so Back needed two presses to leave page 2
 *   - forcePageReload -> the plugin intercepted; no real navigation happened
 *
 * The block theme is the environment default (#63), so the theme guard in
 * beforeAll is normally a no-op; against a differently-themed target it still
 * switches and restores, bounded by beforeAll/afterAll — a spec file is the
 * unit a worker runs to completion, so no other project can observe the
 * switched theme, and afterAll still runs when a test fails. beforeAll also
 * detaches `page_on_front` for the same window — see the comment there for why
 * a shop-as-front-page storefront has no WooCommerce breadcrumb to assert on.
 * The forcePageReload describe REALLY installs/removes a test-only mu-plugin
 * around itself; if a run is hard-killed in between, delete
 * wp-content/mu-plugins/shift64-e2e-force-page-reload.php and restore the front
 * page with `wp option update page_on_front <shop page id>`.
 */

// 48 results = 3 pages at 16/page — the same broad query the main-suite
// journeys use. "series" (not "clothing") is what spans the whole multi-vertical
// catalog; see the comment in specs/search-results-page.spec.ts.
const BROAD_QUERY = '/?s=series&post_type=product';
const EMPTY_QUERY = '/?s=zzqvwxjklmnp&post_type=product';
const PAGE_2 = /\/page\/2\/|[?&](?:paged|query-page|query-\d+-page)=2/;

const MU_FIXTURE = 'shift64-e2e-force-page-reload.php';

let originalTheme = '';
let originalPageOnFront = '';

test.beforeAll(() => {
	originalTheme = wpCli(['theme', 'list', '--status=active', '--field=name']).trim();
	if (originalTheme !== BLOCK_THEME) {
		wpCli(['theme', 'activate', BLOCK_THEME]);
	}

	// The breadcrumb assertions below need a breadcrumb to exist at all.
	// WC_Breadcrumb::generate() returns an EMPTY trail — on every template, not
	// just this one — while `page_on_front` is the shop page and the request is
	// not is_paged(). Provisioning sets exactly that so the QA storefront opens
	// on the catalog, and Product Collection pages through `query-0-page`, which
	// leaves the main query unpaged. So the shop-as-front-page storefront is a
	// site shape in which WooCommerce renders no breadcrumb here.
	//
	// Detaching the front page for the length of this file keeps the assertions
	// pointed at the behavior they were written for (#51: the plugin refreshes
	// page context after a catalog swap) instead of at a WooCommerce display
	// rule. Same bounds and same reasoning as the theme switch above.
	originalPageOnFront = wpCli(['option', 'get', 'page_on_front']).trim();
	if (originalPageOnFront !== '0') {
		wpCli(['option', 'update', 'page_on_front', '0']);
	}
});

test.afterAll(() => {
	if (originalPageOnFront && originalPageOnFront !== '0') {
		wpCli(['option', 'update', 'page_on_front', originalPageOnFront]);
	}
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

function countTargetDocumentRequests(page: import('@playwright/test').Page): { total: () => number } {
	let n = 0;
	page.on('request', (req) => {
		if (req.resourceType() === 'document' && PAGE_2.test(req.url())) {
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
		const page2FirstProduct = await productCards(page).first().innerText();

		const filters = page.locator(SEL.productFilters).first();
		const amber = filters
			.locator(`${SEL.productFilterCheckbox}[name="filter_pa_color[]"][value="amber-musk"]`)
			.first();
		await filters.locator('details', { hasText: /color/i }).locator('summary').click();
		await expect(amber).toBeVisible();
		await amber.check();

		await expect(page).toHaveURL(/filter_pa_color=amber-musk/);
		await expect(page).not.toHaveURL(/[?&](?:page|paged|query-page|query-\d+-page)=/);
		await expect(productCards(page)).toHaveCount(1);
		await expect(page.locator('.page-numbers.current')).toHaveCount(0);
		await expect(page.locator('.woocommerce-breadcrumb').first()).not.toContainText('Page 2');

		await page.goBack();
		await expect(page).toHaveURL(/query-0-page=2/);
		// A scoped collection is sized by the archive it replaces, so a full
		// page holds `loop_shop_per_page` products (WooCommerce's 4x4 default)
		// rather than the `perPage` the template author saved on the block.
		await expect(productCards(page)).toHaveCount(16);
		await expect(productCards(page).first()).toContainText(page2FirstProduct);
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');
		await expect(page.locator('.woocommerce-breadcrumb').first()).toContainText('Page 2');

		await page.goForward();
		await expect(page).toHaveURL(/filter_pa_color=amber-musk/);
		await expect(productCards(page)).toHaveCount(1);
		await expect(page.locator('.page-numbers.current')).toHaveCount(0);
		await expect(page.locator('.woocommerce-breadcrumb').first()).not.toContainText('Page 2');
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

	test('uses one browser-owned document navigation and renders page 2', async ({ page }) => {
		await page.goto(BROAD_QUERY);
		const cards = productCards(page);
		await expect(cards.first()).toBeVisible();
		const firstTitleBefore = await cards.first().innerText();

		await page.evaluate(() => {
			(window as unknown as Record<string, unknown>).__e2eNoReload = true;
		});

		const documentRequests = countTargetDocumentRequests(page);
		const pluginRequests = countPluginTargetRequests(page);
		await page.locator('a.page-numbers', { hasText: '2' }).first().click();
		await expect(page).toHaveURL(PAGE_2);
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');
		await expect(productCards(page).first()).not.toHaveText(firstTitleBefore);

		const survivedNavigation = await page.evaluate(
			() => (window as unknown as Record<string, unknown>).__e2eNoReload === true
		);
		expect(survivedNavigation).not.toBe(true);
		expect(documentRequests.total()).toBe(1);
		expect(pluginRequests.total()).toBe(0);
	});
});
