import { copyFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { BLOCK_THEME, wpCli } from '../helpers/env';
import { SEL } from '../helpers/search';

/**
 * Second, blockified projection of the AJAX-swap journeys (issue #17).
 *
 * The rest of the suite runs on Storefront, which renders classic WooCommerce
 * markup. That made a whole class of frontend bug invisible to CI: #15 was
 * exactly that — the AJAX pagination script matched only
 * nav.woocommerce-pagination, so on block themes the current-page indicator
 * never moved, and the regression assertion added for it passed on Storefront
 * with AND without the fix.
 *
 * Scope is deliberately the "minimum viable version" from #17: the AJAX swap
 * journeys (grid + pagination indicator + result count), not the whole suite.
 * The facet/ordering journeys stay Storefront-only — they lean on the
 * shop-loop hooks that blockified archive templates never fire, and making
 * them work on a block theme is its own design problem.
 *
 * REAL environment mutation, like the degraded project: this file activates a
 * block theme AND installs a test-only mu-plugin in beforeAll, then restores
 * both in afterAll. The bounds are beforeAll/afterAll rather than a Playwright
 * setup/teardown project on purpose — a spec file is the unit a worker runs to
 * completion, so no other project can observe the switched theme, and afterAll
 * still runs when a test fails. If a run is hard-killed in between, restore
 * with `wp theme activate storefront` and delete
 * wp-content/mu-plugins/shift64-e2e-force-page-reload.php.
 *
 * The mu-plugin turns OFF WooCommerce's enhanced (Interactivity-API)
 * pagination — see force-page-reload.mu.php for why that is load-bearing
 * rather than incidental. Short version: with it on, Woo updates the
 * pagination block itself and the journey passes even against #15's pre-fix
 * code, which would make this whole project a green no-op.
 */

// Same broad query the Storefront journeys use: 48 results = 3 pages at 16/page.
const BROAD_QUERY = '/?s=clothing&post_type=product';

const MU_FIXTURE = 'shift64-e2e-force-page-reload.php';

let originalTheme = '';
let installedMuPath = '';

test.beforeAll(() => {
	originalTheme = wpCli(['theme', 'list', '--status=active', '--field=name']).trim();
	if (originalTheme !== BLOCK_THEME) {
		wpCli(['theme', 'activate', BLOCK_THEME]);
	}

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
	if (originalTheme && originalTheme !== BLOCK_THEME) {
		wpCli(['theme', 'activate', originalTheme]);
	}
});

function productCards(page: import('@playwright/test').Page) {
	return page.locator(SEL.productsGrid).first().locator('li.product');
}

// The projection is only worth anything if the theme really renders blockified
// markup. Without this guard a theme that quietly falls back to classic markup
// would turn the whole project into a green no-op — the exact failure mode #17
// exists to close.
test('the block theme really renders blockified archive markup', async ({ page }) => {
	await page.goto(BROAD_QUERY);

	await expect(productCards(page).first()).toBeVisible();

	// WooCommerce's Product Template block, not a classic ul.products loop.
	await expect(page.locator('.wp-block-woocommerce-product-template').first()).toBeAttached();
	// The blockified nav — the element #15's bug could not see.
	await expect(page.locator('nav.wp-block-query-pagination').first()).toBeAttached();
	await expect(page.locator('nav.woocommerce-pagination')).toHaveCount(0);

	// The mu-plugin fixture must really have disabled Woo's enhanced
	// pagination. If a future WooCommerce stops honoring forcePageReload, the
	// router region comes back, Woo starts updating the pagination block
	// itself, and the journey below would pass no matter what this plugin
	// does — the no-op failure mode this project exists to prevent. Fail here
	// instead, loudly.
	await expect(page.locator('[data-wp-router-region^="wc-product-collection"]')).toHaveCount(0);
});

// The blockified counterpart of the Storefront pagination journey. This is the
// assertion that actually fails without the #15 fix: the grid and the URL
// update either way, only the current-page indicator stays behind.
test('pagination swaps the grid, the indicator, and the result count via AJAX', async ({ page }) => {
	await page.goto(BROAD_QUERY);

	const cards = productCards(page);
	await expect(cards.first()).toBeVisible();
	const firstTitleBefore = await cards.first().innerText();

	const resultCount = page.locator(SEL.resultCount).first();
	await expect(resultCount).toBeVisible();
	const countTextBefore = await resultCount.innerText();

	// A full navigation would wipe this flag; the AJAX swap must keep it.
	await page.evaluate(() => {
		(window as unknown as Record<string, unknown>).__e2eNoReload = true;
	});

	await page.locator('a.page-numbers', { hasText: '2' }).first().click();

	await expect(page).toHaveURL(/(\/page\/2\/|[?&]paged=2)/);
	await expect(cards.first()).not.toHaveText(firstTitleBefore);

	// The blockified current-page marker is span.page-numbers.current with
	// aria-current="page". Before the #15 fix this stayed on "1".
	await expect(page.locator('.page-numbers.current').first()).toHaveText('2');

	// The result count is swapped too. It reports the total for the query, not
	// the current page's slice, so the correct assertion is that it SURVIVES
	// the swap unchanged — a swap that dropped the element or pulled in a stale
	// or empty count would fail here.
	await expect(resultCount).toBeVisible();
	await expect(resultCount).toHaveText(countTextBefore);

	const flagSurvived = await page.evaluate(() => {
		return (window as unknown as Record<string, unknown>).__e2eNoReload === true;
	});
	expect(flagSurvived).toBe(true);
});
