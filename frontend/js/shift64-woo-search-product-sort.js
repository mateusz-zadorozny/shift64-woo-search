/**
 * Interactivity API store for Shift64 Product Sort block.
 *
 * @package Shift64_Woo_Search
 */

import { store, getContext, getElement } from '@wordpress/interactivity';
import { buildCatalogUrl, navigate } from 'shift64-woo-search/catalog-navigation';

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
		toggleDropdown(event) {
			if (event) {
				event.stopPropagation();
			}
			const context = getContext();
			if (context) {
				context.isOpen = !context.isOpen;
			}
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

