# Archive debug panel: opt-in by default, live on filter changes

> Execution plan for `om-auto-create-pr`.

## Goal

Stop the "Shift64 Archive Debug" bar from rendering unless a merchant explicitly
turns it on, give that switch a home in the settings information architecture,
and make the panel reflect the query that produced the results currently on
screen instead of freezing at the initial page load.

## Overview

`Shift64_Woo_Search_Archive` accumulates a per-request `$debug_log` and renders it
in two places:

1. `render_debug()` on `wp_footer` — a fixed-position dark bar across the bottom
   of the storefront, gated only on `current_user_can( 'manage_woocommerce' )`.
2. `maybe_render_partial()` — a hidden `<div class="shift64-woo-search-archive-debug">`
   appended to the AJAX partial, gated on the same capability.

Two defects follow from that:

- **Always on.** Any shop manager browsing the storefront gets a debug bar
  pinned over the site chrome. There is no way to turn it off short of dropping
  the capability. It should be off unless asked for.
- **Frozen.** `frontend/js/shift64-woo-search-ajax-pagination.js` swaps the product
  grid, the filter rail, the pagination, and the result count out of the fetched
  partial — but never the debug bar. The partial already *carries* fresh debug
  output in that hidden div; nothing consumes it. So the bar keeps showing the
  timings of the first, unfiltered query while the grid below it shows filtered
  results.

The fix is therefore small and symmetrical: one new option gating both render
paths, one checkbox in **System › Diagnostics** (the workspace that already owns
SHORTINIT config regeneration and carries a settings form with no fields yet),
and one more swap branch in the AJAX handler that feeds the hidden partial div
into the visible bar.

### External References

None — no `--skill-url` arguments were passed.

## Scope

- New option `shift64_woo_search_archive_debug_enabled`, default `'no'`.
- Gate both debug render paths on it, in addition to the existing capability check.
- Render the toggle in `render_system_diagnostics_section()` and allowlist the
  option in `Shift64_Woo_Search_Admin_Settings::scalar_options()`.
- Teach the AJAX partial swap to refresh the debug bar, and to create/remove it
  so the bar tracks whether the current response carries debug output at all.
- Refresh `languages/shift64-woo-search.pot` via `composer makepot`.
- Unit tests for default-off, the toggle and its sanitization, and regeneration
  of the debug payload on a filter-change (partial) request.

## Non-goals

- No change to *what* gets logged, the log format, or the timing instrumentation.
- No change to the capability requirement — `manage_woocommerce` still gates the
  output; the option narrows it further, never widens it.
- No restyling of the debug bar beyond what is needed to swap its contents.
- No new admin workspace or section; the toggle joins an existing one.
- No SHORTINIT config surface change — the debug bar is a full-WordPress render
  path and the option is never read from the SHORTINIT endpoint.

## Implementation Plan

### Phase 1: Gate both debug render paths behind a default-off option

- Read `shift64_woo_search_archive_debug_enabled` (default `'no'`) in a single
  private `debug_enabled()` helper on the archive class, so the two render paths
  cannot drift apart.
- Apply it in `render_debug()` and in the partial debug block in
  `maybe_render_partial()`, on top of the existing capability check.
- Unit tests: default off, explicit `'no'` off, `'yes'` on, and that a
  capability-less user stays off even with the option on.

### Phase 2: Expose the toggle in System › Diagnostics

- Add `shift64_woo_search_archive_debug_enabled` to the settings allowlist so the
  generic save handler persists it with the same `sanitize_text_field` treatment
  as every other yes/no scalar.
- Render the checkbox in `render_system_diagnostics_section()` under a
  "Storefront Debug" heading, with copy explaining it is admin-visible only.
- Regenerate the `.pot` file.
- Unit tests: the option round-trips through `persist()`, is ignored when absent
  from the payload, and a junk value cannot enable the panel.

### Phase 3: Refresh the debug bar on filter/pagination changes

- In the AJAX partial swap, read `.shift64-woo-search-archive-debug` from the
  fetched document and write its lines into `.shift64-woo-search-debug-bar`,
  creating the bar when the current page has none and removing it when the new
  response carries no debug payload.
- Keep the bar's heading intact and escape nothing by hand — build the rows as
  text nodes so a product title in a log line can never inject markup.
- Unit test: a partial (filter-change) request rebuilds the debug log from the
  new query rather than reusing the initial one.

### Phase 4: Validation, review, PR

- Full gate: `composer validate --strict`, `vendor/bin/phpcs`, `vendor/bin/phpunit`.
- `om-code-review` self-review, breaking-change check against
  `BACKWARD_COMPATIBILITY.md`.
- Open PR, normalize labels, run `om-auto-review-pr` in autofix mode.

## Risks

- **Behavior change for existing installs.** Anyone who relied on the always-on
  bar loses it silently after update. Mitigated by defaulting the *new* option
  only — no existing option changes meaning — and calling it out in the PR body.
  This is the requested behavior, not an accident.
- **The debug bar is `position: fixed` with inline styles.** Creating it from JS
  means duplicating that style string in two languages. Mitigated by having the
  JS clone the server-rendered bar's markup when one exists and only fall back to
  constructing it when the first response had no debug payload.
- **Escaping.** Log lines contain the raw search term. Server-side output already
  runs through `esc_html()`; the JS path must use `textContent`, never
  `innerHTML`, for the same reason.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Gate both debug render paths behind a default-off option

- [ ] 1.1 Add `debug_enabled()` helper reading the new default-off option
- [ ] 1.2 Gate `render_debug()` and the partial debug block on it
- [ ] 1.3 Unit tests for default-off, explicit toggle, and capability interaction

### Phase 2: Expose the toggle in System › Diagnostics

- [ ] 2.1 Allowlist the option in `Shift64_Woo_Search_Admin_Settings`
- [ ] 2.2 Render the checkbox in the Diagnostics section and regenerate the pot
- [ ] 2.3 Unit tests for persistence, absence, and sanitization

### Phase 3: Refresh the debug bar on filter/pagination changes

- [ ] 3.1 Swap the debug bar contents from the fetched partial
- [ ] 3.2 Unit test that a partial request regenerates the debug payload

### Phase 4: Validation, review, PR

- [ ] 4.1 Full validation gate green
- [ ] 4.2 Self-review and breaking-change check
- [ ] 4.3 PR opened, labels normalized, `om-auto-review-pr` clean
