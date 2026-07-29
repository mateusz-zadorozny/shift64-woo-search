import { expect, test } from '@playwright/test';
import { SEL } from '../helpers/search';
import { wpCli } from '../helpers/env';

/**
 * The opt-in storefront debug panel.
 *
 * Two behaviors that PHPUnit cannot reach, because both are decided in the
 * browser:
 *
 *  1. The panel stays absent for a shop manager who has not switched it on.
 *  2. Once on, it tracks the query that produced the results on screen. It used
 *     to be written once at page load and never touched again, so changing a
 *     filter left the bar describing the unfiltered query while the grid below
 *     showed filtered results.
 *
 * This spec REALLY flips `shift64_woo_search_archive_debug_enabled` on the
 * target site and restores the previous value in afterAll — same contract as the
 * degraded project's Redis mutation. A hard-killed run can leave the panel on;
 * `wp option update shift64_woo_search_archive_debug_enabled no` puts it back.
 */

const BROAD_QUERY = '/?s=series&post_type=product';
const DEBUG_OPTION = 'shift64_woo_search_archive_debug_enabled';
const BAR = '.shift64-woo-search-debug-bar';
const BAR_LINES = '.shift64-woo-search-debug-bar__lines';

function readOption(name: string): string {
	try {
		return wpCli(['option', 'get', name]).trim();
	} catch {
		// Never set — `option get` exits non-zero rather than printing nothing.
		return '';
	}
}

function writeOption(name: string, value: string): void {
	wpCli(['option', 'update', name, value]);
}

test.describe('archive debug panel', () => {
	let previous = '';

	test.beforeAll(() => {
		previous = readOption(DEBUG_OPTION);
	});

	test.afterAll(() => {
		// Restore exactly what was there, including "never set".
		if (previous === '') {
			wpCli(['option', 'delete', DEBUG_OPTION]);
		} else {
			writeOption(DEBUG_OPTION, previous);
		}
	});

	// Logging in is what makes these assertions meaningful: the panel is gated on
	// manage_woocommerce, so a logged-out visitor proves nothing about the option.
	async function loginAsAdmin(page: import('@playwright/test').Page) {
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'admin');
		await page.click('#wp-submit');
		await expect(page).toHaveURL(/wp-admin/);
	}

	test('stays hidden for an admin while the setting is off', async ({ page }) => {
		writeOption(DEBUG_OPTION, 'no');
		await loginAsAdmin(page);

		await page.goto(BROAD_QUERY);
		await expect(page.locator(SEL.productsGrid).first()).toBeVisible();

		await expect(page.locator(BAR)).toHaveCount(0);
	});

	test('never renders for a logged-out shopper even when the setting is on', async ({ page }) => {
		writeOption(DEBUG_OPTION, 'yes');

		await page.goto(BROAD_QUERY);
		await expect(page.locator(SEL.productsGrid).first()).toBeVisible();

		await expect(page.locator(BAR)).toHaveCount(0);
	});

	test('refreshes its contents when a filter changes', async ({ page }) => {
		writeOption(DEBUG_OPTION, 'yes');
		await loginAsAdmin(page);

		await page.goto(BROAD_QUERY);

		const bar = page.locator(BAR);
		await expect(bar).toBeVisible();

		const before = (await page.locator(BAR_LINES).innerText()).trim();
		expect(before).not.toBe('');

		// The interception logs one entry per stage, so a healthy panel is always
		// several lines. Asserting the structure catches a refresh that collapses
		// them into one run-on line — which is what happens if the handler reads
		// the lines with textContent, since that discards the <br> separators.
		expect(before.split('\n').filter(Boolean).length).toBeGreaterThan(1);

		// Change a filter. The grid swaps via AJAX — no navigation — which is
		// exactly the path that used to leave the panel frozen.
		const filters = page.locator(SEL.filters).first();
		await expect(filters).toBeVisible();

		const checkbox = filters.locator('input[type="checkbox"]').first();
		await checkbox.check();

		// The panel must end up describing the filtered query, not the first one.
		await expect
			.poll(async () => (await page.locator(BAR_LINES).innerText()).trim(), {
				message: 'debug panel should re-render for the filtered query',
			})
			.not.toBe(before);

		// Still a single bar with its heading intact — the refresh replaces the
		// lines, not the whole element.
		await expect(bar).toHaveCount(1);
		await expect(bar).toContainText('Shift64 Archive Debug');

		// And still structured as lines after the swap, not one collapsed blob.
		const after = (await page.locator(BAR_LINES).innerText()).trim();
		expect(after.split('\n').filter(Boolean).length).toBeGreaterThan(1);
	});
});
