# Run plan — admin-settings-information-architecture

**Date:** 2026-07-28
**Branch:** `feat/admin-settings-information-architecture`
**Base:** `main`
**Source spec:** `.ai/specs/2026-07-22-admin-settings-information-architecture.md`

## Tasks

> Authoritative status table. `Status` is one of `todo` or `done`. On landing a Step, flip `Status` to `done` and fill the `Commit` column with the short SHA. The first row whose `Status` is not `done` is the resume point for `om-auto-continue-pr-loop`. Step ids are immutable once a Step has a commit.

| Phase | Step | Title | Status | Commit |
|-------|------|-------|--------|--------|
| 1 | 1.1 | Add fixed route registry/resolver with full unit coverage | done | d075047 |
| 1 | 1.2 | Render six-workspace navigation shell, Overview landing, registry dispatch | done | 469ce67 |
| 2 | 2.1 | Extract credential-safe settings persistence seam with sentinel tests | done | 7acf749 |
| 2 | 2.2 | Relocate Search Experience sections to canonical routes | done | a9c2cdc |
| 2 | 2.3 | Relocate Results & Filters sections to canonical routes | done | a058629 |
| 2 | 2.4 | Relocate Relevance sections to canonical routes | done | b07bae9 |
| 3 | 3.1 | Relocate Insights and System sections to canonical routes | done | a05adea |
| 3 | 3.2 | Canonical internal links, legacy-search relocation notice, secondary-nav CSS | done | 7bd33ec |
| 4 | 4.1 | Add focused IA regression suite (test-admin-settings-information-architecture.php) | done | 4b59cb5 |
| 4 | 4.2 | Docs and i18n: spec status flip, specs index, BACKWARD_COMPATIBILITY, makepot | done | a1677ff |
| 4 | 4.2-review-fix | Reword misleading auth-toggle and save_posted_settings comments (self-review nits) | done | 0babbbc |

## Goal

Replace the legacy twelve-equal-tab admin layout with the six task-oriented workspaces, nineteen canonical `tab`+`section` routes, and twelve legacy aliases defined by the source spec — preserving every stored option, AJAX contract, asset handle, and storefront behavior, and making section-scoped saves credential-safe.

## Scope

- `admin/class-shift64-woo-search-admin.php` — routing, navigation shell, section renderers, `ajax_save_setting()` delegation.
- One new registry/resolver class file under `admin/`.
- One new persistence-seam class or method (extracted from `ajax_save_setting()`).
- `admin/css/shift64-woo-search-admin.css` — primary/secondary nav styling only.
- `admin/js/shift64-woo-search-admin.js` — only if markup-presence init needs no change it stays untouched; any change must keep behavior identical.
- New tests: route registry, persistence seam, IA regression suite.
- Docs: `BACKWARD_COMPATIBILITY.md`, `.ai/specs/README.md` index row + spec Status header flip, maintained docs naming old tabs, `languages/` pot refresh.

## Non-goals (from spec — enforced)

- No new merchant controls, options, or persistent state; no Settings API migration.
- No renaming of misspelled `synonys64ws_*` AJAX actions; no option rename/normalization/migration of any kind.
- No filter-bar, display, relevance-profile, or managed-service features.
- No behavior change inside Test Search, Tuning, Statistics, Index, or connection-test flows.
- No splitting of the specialized `shift64_woo_search_save_filters` form.
- No fixing of pre-existing config/blob refresh error reporting.
- Never add Playwright to the agentic validation gate (AGENTS.md).

## Risks

- **Split forms overwriting unrelated options** — mitigated by Step 2.1 landing the payload-presence credential guard *before* any relocated generic form ships (Steps 2.2+).
- **Alias/callback drift** — mitigated by fixed registry + explicit callbacks + data-provider tests over all 19 canonical routes, 12 aliases, and hostile inputs (Steps 1.1, 4.1).
- **Render-time writes** — spec demands zero `update_option()` on any route visit; Step 4.1 snapshots all `shift64_woo_search_*` options before/after render.
- **Old Search bookmarks** — `tab=search` maps to `relevance/basic` with a non-persistent relocation notice (Step 3.2).

## External References

None (`--skill-url` not used). Research links inside the spec are context only.

## Implementation Plan

### Phase 1 — Fixed routing and navigation shell

**Step 1.1 — Add fixed route registry/resolver with full unit coverage**
- New file `admin/class-shift64-woo-search-admin-routes.php`, class `Shift64_Woo_Search_Admin_Routes`.
- Static API (pure, no WP calls beyond `__()`): `get_workspaces()` (ordered map: slug → label, default section, ordered sections with label + explicit callback method name), `get_aliases()` (the twelve legacy `tab` values → `array( workspace, section )` per the spec's alias table), `resolve( $tab, $section )` → `array( 'workspace' => …, 'section' => …, 'callback' => … )`.
- Resolution rules exactly per spec Route Contract: missing/invalid tab → `overview`; `overview` ignores section; missing/invalid section → workspace default; non-string/array/hostile input → treated as invalid; callbacks come only from the registry, never derived from input.
- Workspaces/sections/defaults/aliases exactly per spec tables (19 canonical destinations, 12 aliases).
- Callback names may reference renderers that don't exist yet (`render_*_section`); Phase 1 maps sections to the existing tab renderers where a 1:1 exists (e.g. `relevance/test-search` → `render_test_tab`, `relevance/synonyms` → `render_synonys64ws_tab` — fixing the blank-Synonyms defect) and to `render_search_tab`/`render_redis_tab` placeholders where relocation happens in Phases 2–3.
- New `tests/test-admin-routes.php`: data providers for all canonical routes, all aliases, defaults, and hostile inputs (array tab, path traversal string, HTML, `__construct`, `render_page`, empty string, null). Assert resolved callback is always a registry value.
- One commit: `feat(admin): add fixed route registry and resolver for admin IA`.

**Step 1.2 — Render six-workspace navigation shell, Overview landing, registry dispatch**
- Rewrite `render_page()`: read `tab`/`section` from `$_GET`, resolve via registry, render six primary links (canonical URLs `admin.php?page=shift64-woo-search&tab={ws}&section={sec}`) and secondary section links for multi-section workspaces.
- Remove the dynamic `render_ . $current_tab . _tab` dispatch entirely; invoke the resolved explicit callback.
- Add `render_overview_tab()`: read-only landing (h2 + navigation cards/links to the five task workspaces + brief copy). Zero writes, no status queries.
- Default landing switches from Test Search to Overview.
- Markup contract per spec UI/UX: `aria-current="page"` on active primary and secondary links, distinct accessible labels on the two `nav` elements, heading order h1 (plugin) → h2 (section), works without JS.
- Keep existing `.nav-tab-wrapper` classes where practical so existing CSS/JS keeps working; admin JS initializes on markup presence and must remain untouched in behavior.
- Tests: extend route tests for nav model if renderable in the unit harness (bootstrap has no full WP — prefer testing registry-driven data used by the nav; markup covered by 4.1/browser QA).
- One commit: `feat(admin): render six-workspace navigation shell with Overview landing`.

### Phase 2 — Relocate merchant-facing sections

**Step 2.1 — Extract credential-safe settings persistence seam with sentinel tests**
- Extract the allowlisted persistence loop from `ajax_save_setting()` (admin class, ~line 1896) into a small pure/testable static seam (e.g. `Shift64_Woo_Search_Admin_Settings::persist( array $settings )` in a new file, or a static method — follow existing file conventions).
- Behavior change (the only intended one): clear `shift64_woo_search_redis_username` / `shift64_woo_search_redis_password` **only when the submitted payload itself contains `shift64_woo_search_redis_auth_enabled` = `no`** — not based on the stored option as today.
- Preserve: allowlists (scalar, arrays, textareas), sanitization, scalar-as-string storage, saved-count, response envelope, `Shift64_Woo_Search_Redis::reset_instance()` + `generate_mu_plugin_config()` call order in the AJAX wrapper.
- Tests: sentinel-option tests per spec step 3 — subset saves touch only submitted keys; checkbox `no`; textarea; array option; unrelated save leaves credentials intact; explicit auth-disable still clears them.
- One commit: `feat(admin): make generic partial settings saves credential-safe`.

**Step 2.2 — Relocate Search Experience sections**
- Section renderers at canonical routes: `experience/search-field` (debounce, input selector, additional selectors, button selector — from `render_frontend_tab`), `experience/autocomplete` (min_query, autocomplete_limit, category_suggest_fuzzy, brand_suggest_enabled — out of `render_search_tab`), `experience/query-suggestions` (existing suggestions manager from `render_suggestions_tab`), `experience/category-suggestions` (category_pin_rules + category_boosts editor + category_suggest_exclude editor — from `render_catboost_tab`; note `shift64_woo_search_category_boost_rules` does NOT move here — it goes to Relevance/Merchandising in 2.4).
- Update registry callbacks from Phase-1 placeholders to the new section renderers.
- Each generic form submits only its own keys; reuse existing field helpers, editors, AJAX actions, cache-refresh behavior unchanged.
- Tests: assert registry now points at the new callbacks; sentinel proof that each new section's save payload is the section's own allowlisted keys (via seam tests where feasible).
- One commit: `feat(admin): relocate Search Experience sections to canonical routes`.

**Step 2.3 — Relocate Results & Filters sections**
- `results/coverage`: archive_enabled, price_sort_mode, taxonomy_archive_scopes (from `render_search_tab`).
- `results/facets`: move the complete existing Filters form (`render_filters_tab`) intact — same `shift64_woo_search_save_filters` request model, no split, brand/category toggles and exclusions untouched.
- One commit: `feat(admin): relocate Results & Filters sections to canonical routes`.

**Step 2.4 — Relocate Relevance sections**
- `relevance/basic`: logic, strategy, outofstock_mode, outofstock_demote_factor.
- `relevance/matching`: fuzzy_level, fallback_trigger, fallback_score_threshold, fallback_fuzzy_level, token_reduction_enabled, weak_tokens, drop_trailing_weak_token_only, diacritics_normalization, fuzzy_synonyms, full_limit.
- `relevance/synonyms`: existing `render_synonys64ws_tab` (manager unchanged, misspelled AJAX actions unchanged).
- `relevance/merchandising`: `shift64_woo_search_category_boost_rules` raw editor (from wherever it renders today).
- `relevance/field-weights`: existing weights tab; `relevance/test-search` / `relevance/compare-passes`: existing Test/Tuning tools unchanged.
- After this step `render_search_tab` should have no remaining owned fields; remove or reduce it to the legacy relocation shim (notice itself lands in 3.2).
- One commit: `feat(admin): relocate Relevance sections to canonical routes`.

### Phase 3 — Relocate tools, insights, and system surfaces

**Step 3.1 — Relocate Insights and System sections**
- `insights/statistics`: existing `render_stats_tab` unchanged (date filters, cleanup, chart, tables).
- `system/connection`: Redis host/port/auth/username/password/db/prefix + Test Connection (from `render_redis_tab`).
- `system/index`: existing `render_index_tab` unchanged (status + rebuild/progress).
- `system/security`: `shift64_woo_search_rate_limit` (out of the old Redis tab).
- `system/diagnostics`: generated-config status/timestamp + Regenerate SHORTINIT Config action.
- All AJAX actions, confirmations, progress responses unchanged.
- One commit: `feat(admin): relocate Insights and System sections to canonical routes`.

**Step 3.2 — Canonical internal links, relocation notice, secondary-nav CSS**
- Replace internal legacy-tab links with canonical routes (dashboard widget → `insights/statistics`; any tab-to-tab links inside renderers).
- Non-persistent relocation notice on legacy `tab=search` (renders on `relevance/basic` only when reached via the alias) linking to Search Experience and Results & Filters.
- Admin CSS: secondary nav styling, active states, usable at admin breakpoints and 200% zoom. No JS behavior change.
- One commit: `feat(admin): add canonical links, legacy relocation notice, and nav styling`.

### Phase 4 — Coverage, docs, and QA

**Step 4.1 — Focused IA regression suite**
- New `tests/test-admin-settings-information-architecture.php` per spec step 9: registry order (six workspaces, documented order), all 19 canonical destinations → explicit callbacks, defaults, all 12 aliases, hostile/invalid inputs, single ownership (each option key owned by exactly one section), render-without-write proof (before/after snapshot of `shift64_woo_search_*` options where the harness can render), Synonyms regression (`s64ws-syn-table` marker via canonical + legacy routes if renderable), partial-save sentinels, no credentials in Overview output.
- Keep the unit bootstrap free of real WooCommerce/Redis.
- One commit: `test(admin): add IA regression suite for routes, ownership, and safe saves`.

**Step 4.2 — Docs and i18n**
- Flip spec Status header to `implemented — PR #N, 2026-07-28` and update `.ai/specs/README.md` index row (AGENTS.md hard rule — same PR).
- `BACKWARD_COMPATIBILITY.md`: document canonical route contract, alias window, unchanged option/AJAX surface.
- Update maintained docs that name old tabs (`docs/`, README where applicable).
- `composer makepot` — refresh `languages/` pot with new nav/section strings.
- One commit: `docs(admin): document IA migration, flip spec status, refresh pot`.

## Checkpoints

- **Checkpoint 1** fires after Step 2.3 (five Steps landed): targeted validation (`composer validate --strict`, `vendor/bin/phpcs`, `vendor/bin/phpunit`), `node --check admin/js/shift64-woo-search-admin.js`, plus UI smoke of canonical/legacy routes via browser when the local site is runnable (screenshots into `checkpoint-1-artifacts/`).
- **Final gate** (after 4.2, subsumes checkpoint 2): full `validation.commands`, `node --check`, `composer makepot` idempotence, integration/browser pass per skill step 7, style-compliance skip note (repo has no design-system lint), `final-gate-checks.md`.

## Quality gate (from spec)

`composer validate --strict` · `vendor/bin/phpcs` · `vendor/bin/phpunit` · `node --check admin/js/shift64-woo-search-admin.js` · `composer makepot` · browser QA of canonical/legacy/invalid routes, saves, Synonyms, tools, degraded Redis states, keyboard nav, zoom, narrow widths.
