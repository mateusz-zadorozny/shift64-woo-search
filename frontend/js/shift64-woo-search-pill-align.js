/**
 * Which edge a pill panel should anchor to so it stays on screen.
 *
 * A pill panel is anchored to the trigger's start edge, which is right until
 * the pill itself sits near the end of the viewport — a sort control in the
 * corner of a toolbar, a filter pill at the end of a row — and then the panel
 * opens off the page. CSS cannot see where the trigger landed, so the stores
 * measure once on open and flip the anchor through a class.
 *
 * @package Shift64_Woo_Search
 */

// Breathing room between the panel and the viewport edge, so a flipped panel
// does not sit flush against the window.
export const PILL_VIEWPORT_GUTTER = 8;

/**
 * Decide whether a panel must anchor to the trigger's end edge.
 *
 * @param {number} triggerStart Trigger's distance from the viewport start edge.
 * @param {number} panelWidth   Panel width.
 * @param {number} viewport     Viewport width.
 * @param {number} gutter       Minimum space to leave at the edge.
 * @return {boolean} True when the panel overflows anchored at the start.
 */
export function shouldAlignEnd(
	triggerStart,
	panelWidth,
	viewport,
	gutter = PILL_VIEWPORT_GUTTER
) {
	if ( ! panelWidth || ! viewport ) {
		return false;
	}

	// On a viewport narrower than the panel, both anchors overflow. Flipping
	// then only moves which end is cut off, and cutting off the end of the
	// list reads better than cutting off its first option.
	if ( panelWidth + gutter > viewport ) {
		return false;
	}

	return triggerStart + panelWidth > viewport - gutter;
}

/**
 * Measure a rendered pill and answer the same question.
 *
 * Reads the trigger rather than the panel's own box so the answer does not
 * depend on the alignment already applied — measuring a flipped panel would
 * otherwise flip it straight back.
 *
 * @param {Element|null} trigger Pill trigger element.
 * @param {Element|null} panel   Pill panel element.
 * @return {boolean} True when the panel should anchor to the end edge.
 */
export function panelAlignsToEnd( trigger, panel ) {
	if ( ! trigger || ! panel ) {
		return false;
	}

	return shouldAlignEnd(
		trigger.getBoundingClientRect().left,
		panel.offsetWidth,
		document.documentElement.clientWidth
	);
}
