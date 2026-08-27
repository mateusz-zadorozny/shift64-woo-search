# Execution plan — deprecate `logic = OR` and `fallback_trigger = no_results`

Source doc: .ai/specs/2026-08-27-deprecate-or-logic-and-no-results-trigger.md
Engine: om-auto-create-pr (steps: 12, --loop: no)
Status: in-progress

## Goal

Mark `shift64_woo_search_logic = OR` and
`shift64_woo_search_fallback_trigger = no_results` as deprecated — labelled in
the admin, reported to stores that have one stored, and surfaced to headless
operators via WP-CLI — while both values keep working exactly as they do today.

## Scope

- **New:** `includes/class-shift64-woo-search-deprecations.php` — a stateless
  registry of deprecated option values plus a reader for the subset a store
  currently has stored.
- **Changed:** `admin/class-shift64-woo-search-admin.php` — an optional
  `$deprecated` parameter on `render_select_field()`, the two field call sites,
  and a new stateless notice rendered from `render_page()`.
- **Changed:** `admin/css/` — one description-variant rule.
- **Changed:** `cli/class-shift64-woo-search-cli.php` — a deprecation block at
  the end of `health()`.
- **Changed:** `BACKWARD_COMPATIBILITY.md` §6 — a "Deprecated values" note.
- **New tests:** registry contents, stored-value resolution, drift guard,
  renderer behavior with and without the new parameter, notice presence and
  absence, and the runtime-unchanged invariant.

## Non-goals

- Removing either value, changing either default, or migrating stored values.
  That is a breaking change under `BACKWARD_COMPATIBILITY.md` §6 and needs its
  own spec; this run only opens the deprecation window.
- Touching the SHORTINIT endpoint, the generated mu-plugin config, the query
  builder, or the archive interceptor. Those are the "keep them working" path
  and the run asserts they are unchanged rather than editing them.
- Deprecating `strategy = strict_first` — issue #85 argues explicitly for
  keeping it.
- The dropdown re-rank defect under `OR` — that is issue #84.
- Hiding the deprecated value from the dropdown (spec Q1 resolved to keep it
  selectable).

## Branch note

This branch is stacked on `spec/deprecate-or-logic-and-no-results-trigger`
(PR #86) rather than cut from `main`. `AGENTS.md` requires the PR that
implements a spec to flip that spec's `> **Status:**` line and its
`.ai/specs/README.md` row **in the same PR**, which is only possible if the
spec file is present on this branch. PR #86 stays the design-review vehicle and
merges first; this PR's diff against `main` collapses to the implementation
plus the status flip once it does.

## Risks

- **Shared primitive.** `render_select_field()` is used by every select on the
  settings screens. The new parameter is optional and trailing with an
  empty-array default, so existing call sites are untouched — asserted by a
  test rather than assumed.
- **Silent runtime regression.** The whole point is that retrieval does not
  change; a careless edit to a shared admin file could still leak into the
  config the storefront reads. Step 8 pins that invariant explicitly.
- **Notice statelessness.** The relocation notice's docblock establishes that
  "rendering a route never writes". A dismissal-remembering notice would break
  that contract, so step 6 asserts no option or user-meta write happens during
  a render.
- **Deprecate-and-forget.** A deprecation nobody ever removes becomes noise.
  Step 12 files the removal follow-up so the window has an owner.

## Implementation Plan

### Phase 1 — Deprecation visible in the admin

1.1 Add `includes/class-shift64-woo-search-deprecations.php` with
`registry()` and `stored()`; require it alongside the other `includes/`
classes. Test: `registry()` holds exactly the two documented entries.

1.2 Cover `stored()` across option states: both recommended, no rows at all,
one deprecated, both deprecated (registry order), and a hand-edited unknown
value.

1.3 Drift guard: every registry option is exposed by
`Shift64_Woo_Search_Settings::search_config()`, and with no row stored the
config value is not the deprecated one.

1.4 Extend `render_select_field()` with the optional trailing
`$deprecated = array()`: `— deprecated` suffix via `sprintf()` on matching
option labels, plus a reason paragraph only when the stored value is the
deprecated one.

1.5 Wire the registry into the two fields (`shift64_woo_search_logic`,
`shift64_woo_search_fallback_trigger`), leaving PR #78's help text as-is.

1.6 Add `render_deprecated_settings_notice()`, called from `render_page()`;
renders nothing when `stored()` is empty, otherwise one line per entry with a
`get_route_url()` link. Stateless — asserted.

1.7 Add the `.shift64-woo-search-admin__deprecated-note` CSS rule.

1.8 Pin the runtime-unchanged invariant: with both deprecated values stored,
`search_config()` returns them verbatim and `generate_mu_plugin_config()` still
emits the matching constants.

### Phase 2 — Headless parity and the record

2.1 Report deprecations in `wp shift64-woo-search health` — one
`WP_CLI::warning()` per stored entry, or a "none" log line; exit status
unchanged.

2.2 Record the deprecation in `BACKWARD_COMPATIBILITY.md` §6.

2.3 Flip the spec's `> **Status:**` line and its `.ai/specs/README.md` row.

2.4 File the removal follow-up issue and link it from #85.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Deprecation visible in the admin

- [ ] 1.1 Add the deprecation registry class and load it
- [ ] 1.2 Cover stored() against the option states
- [ ] 1.3 Guard the registry against drift
- [ ] 1.4 Extend render_select_field() with the deprecated parameter
- [ ] 1.5 Pass the registry into the two settings fields
- [ ] 1.6 Add the stateless deprecated-settings notice
- [ ] 1.7 Add the deprecated-note CSS rule
- [ ] 1.8 Assert the runtime is untouched

### Phase 2: Headless parity and the record

- [ ] 2.1 Report deprecations in wp shift64-woo-search health
- [ ] 2.2 Record the deprecation in BACKWARD_COMPATIBILITY.md section 6
- [ ] 2.3 Flip the spec status and the specs index row
- [ ] 2.4 File the removal follow-up issue
