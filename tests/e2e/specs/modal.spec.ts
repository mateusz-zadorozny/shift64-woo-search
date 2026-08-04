import { expect, test } from '@playwright/test';
import {
	MODAL_OPEN_BODY_CLASS,
	SEARCH_PAGE,
	SEL,
	blockModal,
	modalTrigger,
	searchInput,
	traySection,
} from '../helpers/search';

// Native dialog keeps its Panel-owned styles and may coexist with a theme's
// own modal-search instance, so the trigger is scoped to the block wrapper and
// the dialog is resolved through the trigger's aria-controls id.
test.describe('modal search', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(SEARCH_PAGE);
	});

	// Test 13: trigger opens modal (body class, input focused); search works;
	// Escape closes and focus returns to the trigger.
	test('opens on trigger, searches, and closes on Escape with focus restored', async ({ page }) => {
		const trigger = modalTrigger(page);
		const modal = await blockModal(page);
		const input = modal.locator(SEL.input);
		expect(await modal.evaluate((element) => element.tagName)).toBe('DIALOG');

		await trigger.click();
		await expect(modal).toBeVisible();
		await expect(page.locator('body')).toHaveClass(new RegExp(MODAL_OPEN_BODY_CLASS));
		await expect(input).toBeFocused();

		await input.fill('athena');
		const products = traySection(page, 'products');
		await expect(products).toBeVisible();
		await expect(products.locator(SEL.rowTitle).first()).toContainText(/Athena/);

		// A single Escape closes both the tray and the modal: the dropdown's
		// keydown handler closes the tray without stopping propagation, so the
		// same event reaches the modal's keydown handler (verified empirically).
		await input.press('Escape');
		await expect(modal).toBeHidden();
		await expect(page.locator('body')).not.toHaveClass(new RegExp(MODAL_OPEN_BODY_CLASS));
		await expect(trigger).toBeFocused();
	});

	test('keeps inline and modal instances isolated', async ({ page }) => {
		const inlineInput = searchInput(page);
		await inlineInput.click();
		await inlineInput.fill('a');
		await expect(inlineInput).toHaveValue('a');

		const trigger = modalTrigger(page);
		const modal = await blockModal(page);
		const modalInput = modal.locator(SEL.input);
		await trigger.click();
		await expect(inlineInput).toHaveValue('a');

		await expect(modalInput).toHaveValue('');
		await modalInput.fill('athena');
		await expect(inlineInput).toHaveValue('a');
		await expect(traySection(page, 'products')).toBeVisible();
		await modal.locator(SEL.modalClose).click();

		await expect(inlineInput).toHaveValue('a');
		await expect(trigger).toBeFocused();
	});

	// Test 14: mobile viewport — open, clear button empties the input, close dismisses.
	test.describe('mobile viewport', () => {
		test.use({ viewport: { width: 390, height: 844 } });

		test('opens, clears the input, and dismisses', async ({ page }) => {
			const trigger = modalTrigger(page);
			const modal = await blockModal(page);
			const input = modal.locator(SEL.input);
			const clear = modal.locator(SEL.modalClear);

			await trigger.click();
			await expect(modal).toBeVisible();

			await input.fill('athena');
			await expect(clear).toBeVisible();
			await clear.click();
			await expect(input).toHaveValue('');
			await expect(clear).toBeHidden();

			await modal.locator(SEL.modalClose).click();
			await expect(modal).toBeHidden();
			await expect(page.locator('body')).not.toHaveClass(new RegExp(MODAL_OPEN_BODY_CLASS));
		});
	});
});
