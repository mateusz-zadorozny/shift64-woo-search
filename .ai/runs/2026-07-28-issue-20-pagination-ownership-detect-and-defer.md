# Pagination ownership: detect and defer (#20)

> **Status:** complete
> Source issue: #20 — "AJAX pagination and WooCommerce's Interactivity API both handle block-theme page clicks"
> Stacked on: `feat/issue-17-e2e-cover-blockified-block-theme-markup` (PR #19), which carries the acceptance tests.

## Goal

Stop this plugin from claiming pagination clicks that belong to WooCommerce's
Product Collection block, so a block-theme page click is handled once instead of
twice.

## Scope

`frontend/js/shift64-woo-search-ajax-pagination.js` only — the click delegation
and the `popstate` handler. Plus dropping the `test.fail()` markers in
`tests/e2e/block-theme/blockified.spec.ts`, which exist precisely to be removed
when this lands.

### Ownership matrix (decided on #20)

| Context | Owner |
|---|---|
| Classic Woo markup, Kadence, custom pagers | This plugin (AJAX swap) |
| Product Collection + `data-wp-router-region` | WooCommerce (Interactivity API) |
| Product Collection + `forcePageReload` | Plain browser navigation |

Both block-theme columns mean "not ours", so the discriminator is a single
`closest('.wp-block-woocommerce-product-collection')` test.

## Non-goals

- The Gutenberg **filter-bar** integration. Filter and ordering changes still go
  through this plugin's AJAX swap; routing those through Woo's router is
  separate work, explicitly out of scope per #20.
- Any change to classic/Kadence pagination behavior.
- `stopPropagation()` / capture-phase interception — ruled out on #20.

## Risks

- **Regressing classic pagination.** The change is gated on a selector that does
  not exist in classic markup, so the classic path should be untouched. Verified
  by the Storefront `main` project in CI, which cannot run locally (Storefront is
  not installed on the dev site).
- **`forcePageReload` cannot be faithfully provisioned.** WooCommerce gates the
  router region on `$block['attrs']` but the link directives on
  `$this->parsed_block`, captured in `pre_render_block` — before
  `render_block_data`, where the E2E fixture sets the attribute. The fixture can
  therefore drop the region but not the directives. Confirmed that stock
  WooCommerce, with this plugin's script blocked entirely, produces the same
  half-broken result in that state, so the third column's test asserts only the
  plugin-scoped fact (no extra fetch) rather than "a real navigation happens".

## Progress

PR: #21

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Implement detect-and-defer

- [x] 1.1 Add `ownsPagination()` and the `productCollection` selector
- [x] 1.2 Defer in the pagination click handler (no `preventDefault`)
- [x] 1.3 Defer in the `popstate` handler when the grid is Woo-owned

### Phase 2: Promote the staged acceptance tests

- [x] 2.1 Drop the three `test.fail()` markers
- [x] 2.2 Re-scope the `forcePageReload` assertion to the plugin-scoped fact
- [x] 2.3 Verify all six block-theme tests pass against the fix
