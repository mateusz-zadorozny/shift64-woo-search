/**
 * Interactivity API store for Shift64 Product Sort block.
 *
 * @package Shift64_Woo_Search
 */

import { store, getContext, getElement } from '@wordpress/interactivity';
import { buildCatalogUrl, navigate } from 'shift64-woo-search/catalog-navigation';
import { panelAlignsToEnd } from './shift64-woo-search-pill-align.js';

const { state } = store('shift64/woo-search-product-sort', {
	state: {
		get isSelected() {
			const context = getContext();
			const slug = context?.slug || '';
			return Boolean(context?.activeSort && slug && context.activeSort === slug);
		},
		get isChevronOpen() {
			const context = getContext();
			return Boolean(context?.isOpen);
		},
	},
	actions: {
		*toggleDropdown(event) {
			if (event) {
				event.stopPropagation();
			}
			const context = getContext();
			if (!context) {
				return;
			}
			// The directive sits on the trigger, so the element handed to this
			// action is the button — the panel is its sibling. Resolve the pill
			// root now, while the scope is still ours to read.
			const root = getElement()?.ref?.closest('.shift64-woo-search-pill');

			context.isOpen = !context.isOpen;
			if (!context.isOpen) {
				return;
			}

			// The panel is `hidden` until this flip renders, and a hidden
			// element has no box to measure — so the alignment is decided one
			// frame later, before the shopper can perceive either position.
			yield new Promise((resolve) => {
				requestAnimationFrame(resolve);
			});
			context.alignEnd = panelAlignsToEnd(
				root?.querySelector('.shift64-woo-search-pill__trigger'),
				root?.querySelector('.shift64-woo-search-pill__panel')
			);
		},
		closeDropdown() {
			const context = getContext();
			if (context) {
				context.isOpen = false;
			}
		},
		onClickOutside(event) {
			const context = getContext();
			if (!context || !context.isOpen) {
				return;
			}
			const element = getElement();
			if (element?.ref && !element.ref.contains(event.target)) {
				context.isOpen = false;
			}
		},
		onKeyDown(event) {
			const context = getContext();
			if (!context || !context.isOpen) {
				return;
			}
			if (event.key === 'Escape') {
				context.isOpen = false;
				const element = getElement();
				const trigger = element?.ref?.querySelector?.('.shift64-woo-search-product-sort__trigger');
				if (trigger) {
					trigger.focus();
				}
			}
		},
		*selectOption(event) {
			if (event) {
				event.stopPropagation();
			}
			const button = event?.currentTarget;
			const slug = button?.dataset?.slug || '';
			const label = button?.dataset?.label || '';
			const context = getContext();
			if (!context) {
				return;
			}
			if (slug) {
				context.activeSort = slug;
				if (label) {
					context.activeLabel = label;
				}
			}
			context.isOpen = false;

			const queryId = context.queryId ?? null;
			const targetUrl = buildCatalogUrl(window.location.href, { orderby: slug }, queryId);
			yield navigate(targetUrl);
		},
		*onSortChange(event) {
			const select = event?.target;
			const orderby = select ? select.value : '';
			const context = getContext();
			const queryId = context?.queryId ?? null;
			const targetUrl = buildCatalogUrl(window.location.href, { orderby }, queryId);
			yield navigate(targetUrl);
		},
	},
});

