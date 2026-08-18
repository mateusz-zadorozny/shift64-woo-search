import { expect, test } from '@playwright/test';
import {
	SEARCH_PAGE,
	SEL,
	isAutocompleteRequest,
	isSuggestionsRequest,
	searchInput,
	traySection,
	typeQuery,
	visibleTray,
} from '../helpers/search';

test.describe('search dropdown', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(SEARCH_PAGE);
	});

	// Test 1: type "athena" → dropdown visible, ≥1 matching product row, Products header.
	test('typing a product name opens the dropdown with matching products', async ({ page }) => {
		// Narrow viewport on purpose: the stacking probe below needs the open
		// tray to really cover the modal trigger, and only a single-column
		// layout guarantees that geometry on every theme (at desktop width a
		// block theme lays the two blocks out side by side, so they never
		// overlap and the z-order assertion would be vacuous).
		await page.setViewportSize({ width: 480, height: 800 });
		await page.goto(SEARCH_PAGE);
		await typeQuery(page, 'athena');

		await expect(visibleTray(page)).toBeVisible();
		const overlay = page.locator(
			'.wp-block-shift64-woo-search-search-panel.is-inline .shift64-woo-search-results__overlay:not([hidden])'
		);
		await expect(overlay).toBeVisible();
		const overlayAppearance = await overlay.evaluate((element) => {
			const style = getComputedStyle(element);
			return {
				backgroundColor: style.backgroundColor,
				backgroundImage: style.backgroundImage,
				borderTopWidth: style.borderTopWidth,
				boxShadow: style.boxShadow,
			};
		});
		expect(overlayAppearance).toEqual({
			backgroundColor: 'rgba(0, 0, 0, 0)',
			backgroundImage: 'none',
			borderTopWidth: '0px',
			boxShadow: 'none',
		});
		const stacking = await page.evaluate(() => {
			const tray = document.querySelector<HTMLElement>(
				'.shift64-woo-search-results--visible'
			);
			const modalControl = document.querySelector<HTMLElement>(
				'.wp-block-shift64-woo-search-search-control.is-modal'
			);
			const modalTrigger = modalControl?.querySelector<HTMLElement>(
				'.shift64-woo-search-modal__trigger'
			);
			if (!tray || !modalControl || !modalTrigger) {
				return null;
			}

			const trayBox = tray.getBoundingClientRect();
			const triggerBox = modalTrigger.getBoundingClientRect();
			const overlap =
				triggerBox.left < trayBox.right &&
				triggerBox.right > trayBox.left &&
				triggerBox.top < trayBox.bottom &&
				triggerBox.bottom > trayBox.top;
			const topmost = document.elementFromPoint(
				triggerBox.left + triggerBox.width / 2,
				triggerBox.top + triggerBox.height / 2
			);

			return {
				modalControlZIndex: getComputedStyle(modalControl).zIndex,
				overlap,
				trayOwnsOverlap: !overlap || Boolean(topmost && tray.contains(topmost)),
			};
		});
		expect(stacking).toEqual({
			modalControlZIndex: 'auto',
			overlap: true,
			trayOwnsOverlap: true,
		});
		const products = traySection(page, 'products');
		await expect(products).toBeVisible();
		await expect(products.locator(SEL.sectionHeader)).toHaveText('PRODUCTS');

		const titles = products.locator(SEL.rowTitle);
		await expect(titles.first()).toBeVisible();
		await expect(titles.first()).toContainText(/athena/i);
	});

	// Test 2: product row shows SKU + category; click navigates to that product page.
	test('product rows show SKU and category, and clicking one opens the product', async ({ page }) => {
		await typeQuery(page, 'athena');

		const firstProduct = traySection(page, 'products').locator(SEL.row).first();
		await expect(firstProduct).toBeVisible();
		// SKUs are DEMO-[VERTICAL]-[SEED]-[ID], e.g. DEMO-APP-6464-000009. The seed
		// segment carries the whole seed, zero-padded to at least four digits.
		await expect(firstProduct.locator(SEL.rowSku)).toContainText(/DEMO-[A-Z]{3}-\d{4,}-\d{6}/);
		await expect(firstProduct.locator(SEL.rowCategory)).not.toBeEmpty();

		const targetUrl = await firstProduct.getAttribute('data-url');
		expect(targetUrl).toContain('/product/');

		await firstProduct.click();
		await page.waitForURL(targetUrl as string);
		await expect(page).toHaveTitle(/Athena/);
	});

	// Test 3: focus on empty input → mode=suggestions request; seeded terms rendered.
	// The endpoint shuffles suggestions, so assert set membership — never order.
	test('focusing the empty input renders the seeded suggestions', async ({ page }) => {
		const suggestionsResponse = page.waitForResponse((res) => isSuggestionsRequest(res.request()));
		await searchInput(page).click();
		await suggestionsResponse;

		const rows = traySection(page, 'suggestions').locator(SEL.row);
		await expect(rows).toHaveCount(3);

		const texts = (await rows.allInnerTexts()).map((t) => t.trim().toLowerCase());
		expect(texts.sort()).toEqual(['headphones', 'serum', 'shirt']);
	});

	// Test 4: type "shirt" → Categories section suggests the Shirts category; click → archive.
	test('typing a category name suggests it and clicking navigates to its archive', async ({ page }) => {
		await typeQuery(page, 'shirt');

		const categories = traySection(page, 'categories');
		await expect(categories).toBeVisible();
		const shirtsRow = categories.locator(SEL.row, { hasText: /Shirts/ }).first();
		await expect(shirtsRow).toBeVisible();

		await shirtsRow.click();
		await page.waitForURL(/\/product-category\//);
	});

	// Test 5: ArrowDown ×2 → second row active; Enter navigates to it; Escape closes the tray.
	// Needs a query whose tray holds at least two rows, so nth(1) exists and
	// carries a data-url. "shirt" matches two products (T-Shirt, Polo Shirt), is
	// itself a seeded suggestion, and matches the Shirts categories. "athena" only
	// ever reached two rows because it prefix-matched the old seeded suggestion
	// list; on its own it matches a single product.
	test('keyboard navigation activates rows, Enter navigates, Escape closes', async ({ page }) => {
		await typeQuery(page, 'shirt');
		const rows = visibleTray(page).locator(SEL.row);
		await expect(traySection(page, 'products').locator(SEL.row).first()).toBeVisible();

		const input = searchInput(page);
		await input.press('ArrowDown');
		await input.press('ArrowDown');

		const secondRow = rows.nth(1);
		await expect(secondRow).toHaveClass(/shift64-woo-search-result--active/);
		const targetUrl = await secondRow.getAttribute('data-url');

		await input.press('Enter');
		await page.waitForURL(targetUrl as string);

		// Escape closes the tray (valid only while result rows are rendered —
		// the keydown handler ignores Escape on an empty tray).
		await page.goto(SEARCH_PAGE);
		await typeQuery(page, 'shirt');
		await expect(traySection(page, 'products').locator(SEL.row).first()).toBeVisible();
		await searchInput(page).press('Escape');
		await expect(visibleTray(page)).toHaveCount(0);
	});

	// Test 6: 1-char query (< minQueryLength 2) → zero autocomplete requests.
	// Focusing the empty input legitimately fires mode=suggestions and may open
	// the tray with seeded suggestions, so only mode=autocomplete traffic counts.
	test('a query below the minimum length never fires an autocomplete request', async ({ page }) => {
		const autocompleteQueries: string[] = [];
		page.on('request', (req) => {
			if (isAutocompleteRequest(req)) {
				autocompleteQueries.push(new URL(req.url()).searchParams.get('q') ?? '');
			}
		});

		const input = searchInput(page);
		await input.click();
		await input.fill('a');

		// Deterministic wait: extend to a valid 2-char query and wait for ITS
		// request — if "a" had fired, it would have been recorded first.
		await input.fill('at');
		await page.waitForRequest((req) => isAutocompleteRequest(req) && req.url().includes('q=at'));

		expect(autocompleteQueries).not.toContain('a');
		expect(autocompleteQueries).toEqual(['at']);
	});

	// Test 7: submit "pulse" → lands on /?s=pulse&post_type=product with results.
	// "pulse" is the most frequent name prefix in the seeded catalog (8 of 48),
	// so the results page has plenty to render; "athena" now matches a single
	// product and makes this a needlessly brittle assertion.
	test('submitting the form lands on the results page with matching products', async ({ page }) => {
		const input = searchInput(page);
		await input.fill('pulse');
		await input.press('Enter');

		await page.waitForURL((url) => {
			return url.searchParams.get('s') === 'pulse' && url.searchParams.get('post_type') === 'product';
		});

		const grid = page.locator(SEL.productsGrid).first();
		await expect(grid).toBeVisible();
		await expect(grid).toContainText(/Pulse/);
	});
});
