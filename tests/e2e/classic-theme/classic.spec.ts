import { expect, test } from '@playwright/test';
import { CLASSIC_THEME, wpCli } from '../helpers/env';
import { SEL } from '../helpers/search';

/**
 * Classic-theme (Storefront) projection of the plugin-owned AJAX-swap
 * journeys — issue #63.
 *
 * The environment default is a block theme now, so the bulk of the suite runs
 * against blockified markup. Per the ownership contract decided on #20:
 *
 *   | context                                    | owner            |
 *   |--------------------------------------------|------------------|
 *   | classic Woo markup, Kadence, custom pager   | THIS PLUGIN      |
 *   | Product Collection + data-wp-router-region  | WooCommerce (IA) |
 *   | Product Collection + forcePageReload        | the browser      |
 *
 * The first column only exists on a classic theme, so this file switches to
 * Storefront and asserts the journeys the plugin's own AJAX swap owns there:
 * pagination without a reload, the facet swap hiding an emptied pagination
 * nav, and the archive debug panel refreshing alongside the swapped grid.
 * The two block-theme columns live in tests/e2e/block-theme/.
 *
 * REAL environment mutation, like the degraded project: this file activates
 * the classic theme in beforeAll and restores the previous one in afterAll.
 * The bounds are beforeAll/afterAll rather than a Playwright setup/teardown
 * project pair on purpose — a spec file is the unit a worker runs to
 * completion, so no other project can observe the switched theme, and
 * afterAll still runs when a test fails. If a run is hard-killed in between,
 * restore with `wp theme activate twentytwentyfive`.
 */

// 48 results = 3 pages at 16/page — the same broad query the main journeys
// use; see the comment in specs/search-results-page.spec.ts.
const BROAD_QUERY = '/?s=series&post_type=product';

const DEBUG_OPTION = 'shift64_woo_search_archive_debug_enabled';
const BAR = '.shift64-woo-search-debug-bar';
const BAR_LINES = '.shift64-woo-search-debug-bar__lines';

let originalTheme = '';

test.beforeAll(() => {
	originalTheme = wpCli(['theme', 'list', '--status=active', '--field=name']).trim();
	if (originalTheme !== CLASSIC_THEME) {
		wpCli(['theme', 'activate', CLASSIC_THEME]);
	}
});

test.afterAll(() => {
	if (originalTheme && originalTheme !== CLASSIC_THEME) {
		wpCli(['theme', 'activate', originalTheme]);
	}
});

function productCards(page: import('@playwright/test').Page) {
	return page.locator(SEL.productsGrid).first().locator('li.product');
}

test.describe('classic theme + plugin-owned AJAX swap', () => {
	// The projection is only worth anything if the theme really renders
	// classic Woo markup. Without this guard, a theme that quietly rendered
	// blockified templates would make every assertion below meaningless.
	test('renders classic Woo markup with the plugin in charge of pagination', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		await expect(productCards(page).first()).toBeVisible();
		await expect(page.locator('nav.woocommerce-pagination').first()).toBeAttached();
		await expect(page.locator('nav.wp-block-query-pagination')).toHaveCount(0);
		await expect(
			page.locator('[data-wp-router-region^="wc-product-collection"]')
		).toHaveCount(0);
	});

	// AJAX pagination → grid changes without a full page load.
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

		// The pagination control itself must be swapped too, not just the grid:
		// classic Woo marks the active page as span.page-numbers.current.
		await expect(page.locator('.page-numbers.current').first()).toHaveText('2');

		const flagSurvived = await page.evaluate(() => {
			return (window as unknown as Record<string, unknown>).__e2eNoReload === true;
		});
		expect(flagSurvived).toBe(true);
	});

	// Facet swap end state: Copper narrows 48 results to a single page, so the
	// swap must hide the pagination control outright. Merely emptying it
	// leaves a nav that still occupies its box — asserting on `display`
	// rather than visibility is what distinguishes the two. (On a block theme
	// WooCommerce re-renders and drops the nav from the DOM instead; that
	// projection lives in the main suite.)
	test('the facet swap hides the emptied pagination nav', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		const filters = page.locator(SEL.filters).first();
		await expect(filters).toBeVisible();

		// Desktop pills keep their checkbox lists inside a dropdown — open it first.
		await filters
			.locator('[data-filter-key] .shift64-woo-search-filter__pill', { hasText: /color/i })
			.click();
		await filters
			.locator(`${SEL.filterCheckbox}[data-taxonomy="pa_color"][data-slug="copper"]`)
			.check();

		await expect(page).toHaveURL(/filter_pa_color=copper/);
		await expect(productCards(page).first()).toBeVisible();
		await expect(page.locator(SEL.pagination).first()).toHaveCSS('display', 'none');
	});
});

test.describe('classic theme + archive debug panel', () => {
	let previous = '';

	function readOption(name: string): string {
		try {
			return wpCli(['option', 'get', name]).trim();
		} catch {
			// Never set — `option get` exits non-zero rather than printing nothing.
			return '';
		}
	}

	test.beforeAll(() => {
		previous = readOption(DEBUG_OPTION);
		wpCli(['option', 'update', DEBUG_OPTION, 'yes']);
	});

	test.afterAll(() => {
		// Restore exactly what was there, including "never set".
		if (previous === '') {
			wpCli(['option', 'delete', DEBUG_OPTION]);
		} else {
			wpCli(['option', 'update', DEBUG_OPTION, previous]);
		}
	});

	// The AJAX swap — no navigation — is exactly the path that used to leave
	// the panel frozen describing the unfiltered query, which is why this
	// regression guard lives in the classic projection: on a block theme a
	// filter change navigates, and the server re-renders the panel anyway.
	test('refreshes its contents when a filter changes', async ({ page }) => {
		// Logging in is what makes the assertion meaningful: the panel is
		// gated on manage_woocommerce.
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'admin');
		await page.click('#wp-submit');
		await expect(page).toHaveURL(/wp-admin/);

		await page.goto(BROAD_QUERY);

		const bar = page.locator(BAR);
		await expect(bar).toBeVisible();

		const before = (await page.locator(BAR_LINES).innerText()).trim();
		expect(before).not.toBe('');

		// The interception logs one entry per stage, so a healthy panel is
		// always several lines. Asserting the structure catches a refresh that
		// collapses them into one run-on line — which is what happens if the
		// handler reads the lines with textContent, since that discards the
		// <br> separators.
		expect(before.split('\n').filter(Boolean).length).toBeGreaterThan(1);

		const filters = page.locator(SEL.filters).first();
		await expect(filters).toBeVisible();

		await filters
			.locator('[data-filter-key] .shift64-woo-search-filter__pill', { hasText: /color/i })
			.click();
		await filters
			.locator(`${SEL.filterCheckbox}[data-taxonomy="pa_color"][data-slug="copper"]`)
			.check();

		await expect(page).toHaveURL(/filter_pa_color=copper/);

		// The panel must end up describing the filtered query, not the first one.
		await expect
			.poll(async () => (await page.locator(BAR_LINES).innerText()).trim(), {
				message: 'debug panel should re-render for the filtered query',
			})
			.not.toBe(before);

		// Still a single bar with its heading intact — the refresh replaces
		// the lines, not the whole element.
		await expect(bar).toHaveCount(1);
		await expect(bar).toContainText('Shift64 Archive Debug');

		// And still structured as lines after the swap, not one collapsed blob.
		const after = (await page.locator(BAR_LINES).innerText()).trim();
		expect(after.split('\n').filter(Boolean).length).toBeGreaterThan(1);
	});
});
