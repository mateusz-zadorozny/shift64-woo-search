import { expect, test } from '@playwright/test';
import { SEL } from '../helpers/search';
import { wpCli } from '../helpers/env';

/**
 * The opt-in storefront debug panel.
 *
 * Gating behavior that PHPUnit cannot reach, because it is decided in the
 * browser: the panel stays absent for a shop manager who has not switched it
 * on, and never renders for a logged-out shopper. (The companion journey —
 * the panel refreshing alongside the plugin's AJAX grid swap — only exists on
 * classic Woo markup and lives in tests/e2e/classic-theme/classic.spec.ts.)
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

	test('renders for an admin once the setting is on', async ({ page }) => {
		writeOption(DEBUG_OPTION, 'yes');
		await loginAsAdmin(page);

		await page.goto(BROAD_QUERY);

		const bar = page.locator(BAR);
		await expect(bar).toBeVisible();
		await expect(bar).toContainText('Shift64 Archive Debug');

		const lines = (await page.locator(BAR_LINES).innerText()).trim();
		expect(lines).not.toBe('');

		// The interception logs one entry per stage, so a healthy panel is always
		// several lines — one collapsed run-on line means broken markup.
		expect(lines.split('\n').filter(Boolean).length).toBeGreaterThan(1);
	});
});
