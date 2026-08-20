import { copyFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { BLOCK_THEME, wpCli } from '../helpers/env';
import { SEL } from '../helpers/search';

/**
 * Product Filters / Filter Pill journeys (spec:
 * .ai/specs/2026-07-30-product-filter-pill-blocks.md).
 *
 * The mu fixture renders one Product Filters parent (a Category pill) above
 * the inherited Product Collection — a stand-in for Site Editor placement.
 * Everything else is the real pipeline: eligibility from the live index,
 * disjunctive envelope counts, canonical filter_product_cat URLs, and the
 * Interactivity store driving the WordPress router beside WooCommerce's
 * Product Collection region.
 *
 * Redis-failure degradation is asserted at the PHPUnit layer (pills omit
 * counts / are omitted; the collection's native fallback owns the page): the
 * degraded e2e project's Redis takeover runs after this project restores its
 * fixture, so the combined scenario cannot be staged here.
 */

const BROAD_QUERY = '/?s=series&post_type=product';
const PAGE_2 = /\/page\/2\/|[?&](?:paged|query-page|query-\d+-page)=2/;

const MU_FIXTURE = 'shift64-e2e-product-filters.php';

const PILL = '.shift64-woo-search-pill';
const TRIGGER = `${PILL} summary.shift64-woo-search-pill__trigger`;
const PANEL = `${PILL} .shift64-woo-search-pill__panel`;
const OPTION_INPUT = `${PILL} .shift64-woo-search-pill__option input`;
const APPLY = `${PILL} .shift64-woo-search-pill__apply`;
const BACKDROP = '.shift64-woo-search-product-filters__backdrop';
const CLOSE = `${PILL} .shift64-woo-search-pill__close`;
const ACTIONS = `${PILL} .shift64-woo-search-pill__actions`;

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
	copyFileSync(join(__dirname, 'product-filters.mu.php'), installedMuPath);
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

test.describe('Product Filters beside the enhanced Product Collection', () => {
	test('renders a category pill with live counts and a canonical form', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		await expect(productCards(page).first()).toBeVisible();
		const trigger = page.locator(TRIGGER).first();
		await expect(trigger).toBeVisible();

		await trigger.click();
		await expect(page.locator(PANEL).first()).toBeVisible();

		const inputs = page.locator(OPTION_INPUT);
		expect(await inputs.count()).toBeGreaterThan(0);
		await expect(inputs.first()).toHaveAttribute('name', 'filter_product_cat[]');
		// Live envelope counts render beside the option labels.
		expect(
			await page.locator(`${PILL} .shift64-woo-search-pill__count`).count()
		).toBeGreaterThan(0);
	});

	test('apply router-navigates without a reload, resets paging, keeps search', async ({ page }) => {
		await page.goto(BROAD_QUERY);
		await expect(productCards(page).first()).toBeVisible();

		// Walk to page 2 first so the filter demonstrably resets paging.
		await page.locator('a.page-numbers', { hasText: '2' }).first().click();
		await expect(page).toHaveURL(PAGE_2);

		await page.evaluate(() => {
			(window as unknown as Record<string, unknown>).__e2eNoReload = true;
		});

		await page.locator(TRIGGER).first().click();
		const firstInput = page.locator(OPTION_INPUT).first();
		const slug = await firstInput.getAttribute('value');
		// The disjunctive count of the option we are about to apply — the
		// filtered collection must narrow to exactly this membership. This
		// caught the escape_tag_value SEARCH_SYNTAX regression, where the URL
		// changed but the collection silently fell back to unfiltered results.
		const optionCount = Number(
			await page
				.locator(`${PILL} .shift64-woo-search-pill__option`)
				.first()
				.locator('.shift64-woo-search-pill__count')
				.innerText()
		);
		expect(optionCount).toBeGreaterThan(0);
		await firstInput.check();
		await page.locator(APPLY).first().click();

		await expect(page).toHaveURL(new RegExp(`filter_product_cat=${slug}`));
		await expect(page).toHaveURL(/s=series/);
		await expect(page).not.toHaveURL(PAGE_2);
		await expect(productCards(page).first()).toBeVisible();
		// The collection really narrowed: bounded by the option's disjunctive
		// count (which may slightly exceed membership — the search path trims
		// low-score matches that aggregations keep) and strictly below the
		// unfiltered page size.
		const filteredCount = await productCards(page).count();
		expect(filteredCount).toBeGreaterThan(0);
		expect(filteredCount).toBeLessThanOrEqual(optionCount);
		expect(filteredCount).toBeLessThan(16);

		const noFullReload = await page.evaluate(
			() => (window as unknown as Record<string, unknown>).__e2eNoReload === true
		);
		expect(noFullReload).toBe(true);

		// One history entry per apply: Back lands on the pre-filter page-2 URL.
		await page.goBack();
		await expect(page).toHaveURL(PAGE_2);
	});

	test('a direct filtered URL hydrates the pill selection', async ({ page }) => {
		await page.goto(BROAD_QUERY);
		await page.locator(TRIGGER).first().click();
		const slug = await page.locator(OPTION_INPUT).first().getAttribute('value');

		await page.goto(`${BROAD_QUERY}&filter_product_cat=${slug}`);

		await expect(
			page.locator(`${PILL} .shift64-woo-search-pill__summary-count`).first()
		).toHaveText('1');
		await page.locator(TRIGGER).first().click();
		await expect(page.locator(OPTION_INPUT).first()).toBeChecked();
		await expect(
			page.locator(`${PILL} .shift64-woo-search-pill__clear`).first()
		).toBeVisible();
	});

	test('Escape closes the panel and returns focus to the trigger', async ({ page }) => {
		await page.goto(BROAD_QUERY);
		const trigger = page.locator(TRIGGER).first();
		await trigger.click();
		await page.locator(OPTION_INPUT).first().focus();

		await page.keyboard.press('Escape');

		await expect(page.locator(PANEL).first()).toBeHidden();
		await expect(trigger).toBeFocused();
	});

	test('narrow viewports show the tray above a dismissable backdrop', async ({ page }) => {
		await page.setViewportSize({ width: 480, height: 800 });
		await page.goto(BROAD_QUERY);

		await page.locator(TRIGGER).first().click();
		const backdrop = page.locator(BACKDROP).first();
		await expect(backdrop).toBeVisible();

		// Tray stacks above the backdrop.
		const zIndexes = await page.evaluate(() => {
			const panel = document.querySelector('.shift64-woo-search-pill__panel');
			const overlay = document.querySelector('.shift64-woo-search-product-filters__backdrop');
			return {
				panel: panel ? Number(window.getComputedStyle(panel).zIndex) : NaN,
				overlay: overlay ? Number(window.getComputedStyle(overlay).zIndex) : NaN,
			};
		});
		expect(zIndexes.panel).toBeGreaterThan(zIndexes.overlay);

		await backdrop.click({ position: { x: 5, y: 5 } });
		await expect(backdrop).toBeHidden();
		await expect(page.locator(PANEL).first()).toBeHidden();
	});

	test('the tray keeps Apply reachable and holds the catalog still', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 800 });
		await page.goto(BROAD_QUERY);

		await page.locator(TRIGGER).first().click();

		const geometry = await page.evaluate(() => {
			const panel = document.querySelector('.shift64-woo-search-pill__panel');
			const actions = document.querySelector('.shift64-woo-search-pill__actions');
			return {
				trayBottom: panel ? panel.getBoundingClientRect().bottom : NaN,
				trayHeight: panel ? panel.getBoundingClientRect().height : NaN,
				actionsBottom: actions ? actions.getBoundingClientRect().bottom : NaN,
				viewport: window.innerHeight,
			};
		});

		// The tray is anchored to the bottom of the visible viewport and is
		// capped there, so the browser toolbar can never hide its action row.
		expect(geometry.trayBottom).toBeLessThanOrEqual(geometry.viewport + 1);
		expect(geometry.trayHeight).toBeLessThanOrEqual(geometry.viewport * 0.71);
		// Apply is on screen without scrolling the tray, however long the facet.
		expect(geometry.actionsBottom).toBeLessThanOrEqual(geometry.viewport);
		await expect(page.locator(ACTIONS).first()).toBeInViewport();

		// A drag that misses the option list must not scroll the catalog away.
		await expect(page.locator('html')).toHaveClass(/shift64-woo-search-has-open-filter/);
		expect(
			await page.evaluate(
				() => window.getComputedStyle(document.body).overflow
			)
		).toBe('hidden');
	});

	test('the tray close button dismisses it and returns focus', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 800 });
		await page.goto(BROAD_QUERY);

		const trigger = page.locator(TRIGGER).first();
		await trigger.click();

		const close = page.locator(CLOSE).first();
		await expect(close).toBeVisible();
		await close.click();

		await expect(page.locator(PANEL).first()).toBeHidden();
		await expect(trigger).toBeFocused();
		// The lock is released with the surface, not left behind.
		await expect(page.locator('html')).not.toHaveClass(
			/shift64-woo-search-has-open-filter/
		);
	});
});

test.describe('Product Filters without JavaScript', () => {
	test.use({ javaScriptEnabled: false });

	test('the plain GET form navigates to the same canonical result', async ({ page }) => {
		await page.goto(BROAD_QUERY);

		const trigger = page.locator(TRIGGER).first();
		await expect(trigger).toBeVisible();
		await trigger.click();

		const firstInput = page.locator(OPTION_INPUT).first();
		const slug = await firstInput.getAttribute('value');
		await firstInput.check();
		await page.locator(APPLY).first().click();

		// The checkbox form submits filter_product_cat[]=slug; the server
		// 302-redirects that array form to the canonical comma form, so the
		// final URL matches what the JS path would have produced.
		await expect(page).toHaveURL(new RegExp(`filter_product_cat=${slug}`));
		await expect(page).not.toHaveURL(/%5B%5D|\[\]/);
		await expect(page).toHaveURL(/s=series/);
		await expect(productCards(page).first()).toBeVisible();
		await page.locator(TRIGGER).first().click();
		await expect(page.locator(OPTION_INPUT).first()).toBeChecked();
	});

	test('the tray close button never appears without the store', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 800 });
		await page.goto(BROAD_QUERY);

		await page.locator(TRIGGER).first().click();

		// The close button only works through the interactivity store, so
		// without it the shopper must see the native disclosure alone rather
		// than a button that does nothing.
		await expect(page.locator(CLOSE).first()).toBeHidden();
		await expect(page.locator(BACKDROP).first()).toBeHidden();
		await expect(page.locator('html')).not.toHaveClass(
			/shift64-woo-search-has-open-filter/
		);
	});
});
