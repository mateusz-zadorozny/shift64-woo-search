import { expect, test } from '@playwright/test';
import { MODAL_OPEN_BODY_CLASS, SEARCH_PAGE, SEL, blockModal, modalTrigger, traySection } from '../helpers/search';

// The modal element is portaled to document.body on init and the theme may
// render its own modal-search instance, so the trigger is scoped to the block
// wrapper and the modal is resolved through the trigger's aria-controls id.
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
