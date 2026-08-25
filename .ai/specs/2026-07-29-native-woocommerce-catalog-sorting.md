# Native WooCommerce Catalog Sorting Engine and Product Sort Block

> **Status:** implemented — PR #73, 2026-08-19

## TLDR

The search-archive interceptor currently supports only relevance and two price
sorts. This spec adds a context-agnostic Redis sorting engine for WooCommerce's
native catalog modes and exposes it through a block-theme-only
`shift64-woo-search/product-sort` block. A merchant places and styles that
block in the Site Editor, chooses and orders the modes shoppers may select, and
may omit the block entirely; Search Relevance is available only in search
contexts.

The block presentation and query integration depend on:

- `2026-07-30-block-theme-product-collection-integration.md` for inherited
  Product Collection query, totals, URL state, and router ownership; and
- `2026-07-30-product-filter-pill-blocks.md` for the shared pill
  trigger/panel/block-support primitive.

The pure sorting engine can be implemented before those dependencies. The
Product Sort block is not exposed until both contracts are available.

## Decisions (resolved gate questions)

1. **Sort engine: Redis-side for all known Woo sorts.** `SORTBY` on indexed
   numeric fields; `FT.AGGREGATE` multi-property SORTBY where Woo's sort is
   composite (`menu_order ASC, title ASC`). Requires adding a `date` field to
   the schema (Phase 2).
2. **Unknown third-party orderby values pass through to WooCommerce** via a
   candidate-set path (Redis filters, WC sorts). This path must not silently
   trim results — see "Large catalogs" below.
3. **Default sort with no `?orderby=`: the store's configured
   `woocommerce_default_catalog_orderby`**, with one remap: a store default of
   `menu_order` acts as `relevance` on search pages, matching WooCommerce
   core's own search remap (core removes "Default sorting" from the dropdown on
   search and maps `menu_order` → `relevance`).
4. **Canonical choices come from WooCommerce.** The Product Sort block starts
   with Woo's filtered stock list (`menu_order`, `popularity`, `rating`,
   `date`, `price`, `price-desc`), applies Woo's context rules, and adds
   Shift64's `relevance` choice only on product-search results. Third-party
   `?orderby=` values arriving by URL still work through the pass-through path,
   but are not automatically exposed in the block editor.
5. **Amendment (2026-07-29):** the candidate-set path must handle large result
   sets — a hard 1k trim breaks large stores. The index carries the fields
   needed for Woo's sorts (`date`; `menu_order`/`title` already sortable).
6. **Amendment (2026-07-30): block-native presentation.** There is no automatic
   hook placement, shortcode, mobile sheet, theme takeover, or appearance
   setting in WP Admin. The merchant places `shift64-woo-search/product-sort`
   beside the inherited WooCommerce Product Collection, selects an ordered
   subset of available modes, optionally overrides their labels, and styles
   the block with Site Editor block supports. Omitting the block means the
   storefront exposes no Shift64 sorting control.

## Problem Statement

`Shift64_Woo_Search_Archive::filter_sort_options()`
(`includes/class-shift64-woo-search-archive.php:603`) replaces the
`woocommerce_catalog_orderby` option list with 3 hardcoded entries, and
`intercept()` (line ~180) classifies orderby as "price or relevance" only. A
shopper choosing "Sort by popularity" on a non-search page loses that option on
search pages; a merchant's configured default catalog sort is ignored. The
current mobile sort bottom sheet
(`frontend/class-shift64-woo-search-filters.php:443`) hardcodes the same three
options and couples presentation to theme-hook rendering. The new product
direction supports only block themes, so the desired UI is not a repaired
classic dropdown: it is an explicitly placed, Site Editor-styled block that
lets the merchant omit unwanted modes such as popularity. Meanwhile the
taxonomy archive
(`includes/class-shift64-woo-search-taxonomy-archive.php`) already follows the
correct philosophy — "Redis acts as a FILTER, orderby is left untouched" — so
the plugin is internally inconsistent.

## Research

- **ElasticPress** maps every WC catalog sort to an indexed engine field
  (`post_date`, `_price`, `total_sales`, `average_rating`) — the approach
  chosen here. It indexes the post date from day one; we retrofit it.
- **Algolia** handles sorting via per-sort index replicas. RediSearch supports
  ad-hoc `SORTBY` on SORTABLE fields, so we skip replica complexity entirely.
- **Relevanssi / FiboSearch** hand ordering back to WP/MySQL (our candidate-set
  path). It composes perfectly with third-party sort logic but scales poorly —
  which is why it is the fallback here, not the default.
- **WooCommerce Product Collection and Catalog Sorting** use canonical
  `orderby` URL state, server-rendered block output, and the WordPress
  Interactivity Router. Shift64 follows those public contracts rather than
  owning pagination or hiding a theme control. The Product Sort block declares
  Interactivity API client-navigation support so its script module remains
  active after Product Collection navigation. See WooCommerce's
  [Product Collection extensibility documentation](https://developer.woocommerce.com/docs/block-development/extensible-blocks/product-collection-block/)
  and WordPress's
  [client-navigation compatibility contract](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/core-concepts/client-side-navigation-compatibility/).

### Scope cohesion decision

The Redis sorting engine and its Product Sort block can be tested separately,
but the block is the only supported merchant-facing surface for the engine in
the block-theme direction. The owner explicitly chose to connect them in this
existing spec so every exposed mode lands with its complete query semantics.
Phase 1 keeps the pure engine steps invisible until the block exposes them;
Phase 2 remains a separate performance/migration PR.

## Architecture

### Sort resolution service

New class `Shift64_Woo_Search_Sort` (in `includes/`), context-agnostic (no
dependency on search-archive state), used by the search archive now and
available to the taxonomy archive later. It resolves an orderby slug to a sort
mode:

| `?orderby=`  | Mode        | Redis mechanism                                  |
| ------------ | ----------- | ------------------------------------------------ |
| `relevance`  | `relevance` | existing re-rank pipeline (unchanged)            |
| `price`      | `redis`     | `SORTBY price ASC` (existing)                    |
| `price-desc` | `redis`     | `SORTBY price DESC` (existing)                   |
| `popularity` | `redis`     | `SORTBY total_sales DESC`                        |
| `rating`     | `redis`     | `SORTBY average_rating DESC`                     |
| `date`       | `redis`     | `SORTBY date DESC` (new field; Phase 2)          |
| `menu_order` | `redis`     | `FT.AGGREGATE … SORTBY 4 @menu_order ASC @title ASC` |
| *(unknown)*  | `wc`        | candidate-set pass-through, WC sorts             |

- `menu_order` and `title` are already `SORTABLE` in the schema
  (`class-shift64-woo-search-schema.php:88,153-155`); WooCommerce's default
  sort is the composite `menu_order ASC, title ASC`, which `FT.SEARCH` cannot
  express (single-field SORTBY) but `FT.AGGREGATE` can.
- The existing `shift64_woo_search_price_sort_mode` = `db` behavior (B2B:
  logged-in users sort by real DB prices) is preserved by routing `price` /
  `price-desc` to the `wc` mode in that configuration — it becomes an instance
  of the generalized pass-through path instead of a bespoke branch.
- Until the `date` field exists in the index (pre-rebuild), `date` resolves to
  `wc` mode (correct results, slower) — see Failure Scenarios.

### `wc` mode (candidate-set pass-through), large-catalog safe

Generalizes the current `use_wc_price` branch in `intercept()`:

1. Fetch **all** matching product IDs from Redis — chunked
   `FT.AGGREGATE … WITHCURSOR LOAD @post_id` (or windowed `FT.SEARCH LIMIT`
   loops), not a single capped call.
2. Inject `post__in` with the full ID set, leave `orderby` untouched, let
   WP/WC sort and paginate (no `found_posts` override, no paged reset).
3. A ceiling protects MySQL from absurd `post__in` sizes: default **10 000**
   IDs, filterable via `shift64_woo_search_wc_sort_candidate_limit`. **Above
   the ceiling the plugin declines to intercept** (full native WooCommerce
   search handles the request, complete and correctly sorted, without Redis
   quality) and logs the decision to the debug bar. Silent truncation is
   explicitly rejected: dropping matches "breaks stores" worse than one slow
   native query.
4. The existing hardcoded `1000` in the B2B price path inherits this mechanism.

### Product Sort block

Register one dynamic block:

- name: `shift64-woo-search/product-sort`;
- placement: manual in the Site Editor only;
- supported host: an inherited `woocommerce/product-collection` on a block
  template;
- navigation: canonical `?orderby=<slug>` through the WordPress Interactivity
  Router, with `/page/N/`, `paged`, `query-page`, and
  `query-{queryId}-page` removed when the mode changes;
- presentation: the same pill trigger, popover/list semantics, and block-support
  contract as `shift64-woo-search/filter-pill`;
- pagination ownership: WooCommerce Product Collection, never Shift64.

The editor exposes an ordered checklist of sort choices. The merchant can
enable only the modes the storefront needs, reorder them, and optionally
override the visible label for each enabled mode. The default selection
contains every context-compatible Woo mode plus Search Relevance. The editor
must prevent an inserted block from being saved with zero enabled choices;
merchants who want no sorting remove the block instead.

The option resolver has one source of truth:

1. Start with WooCommerce's stock list after
   `woocommerce_catalog_orderby`.
2. Apply Woo's availability rules, including removing rating when reviews are
   disabled.
3. On product-search results, prepend `relevance` and remove `menu_order`.
4. Outside product-search results, omit `relevance`.
5. Intersect that result with the block's ordered `enabledOptions` attribute
   and apply non-empty label overrides.

This is configuration of the visible control, not a global sorting allowlist.
A known or third-party `orderby` arriving through a direct URL remains an
engine/query concern and is not rejected merely because one block instance
does not advertise it.

### Default-sort resolution

In the Product Collection query adapter, explicit `?orderby=` wins; otherwise
use `get_option( 'woocommerce_default_catalog_orderby', 'menu_order' )`, with
`menu_order` → `relevance` remapped on search. The Product Sort block receives
the same resolved value during server rendering, so its selected state and the
Redis query cannot diverge. No superglobal mutation, Woo template override, or
classic-theme dropdown synchronization is required.

## Data Model

One index schema addition (Phase 2):

- `date` — `NUMERIC SORTABLE`, Unix timestamp of `post_date_gmt`, written by
  the indexer alongside the existing numeric fields
  (`class-shift64-woo-search-indexer.php:290-300`).

Migration: there is no schema-version mechanism in the plugin; index changes
ship via `wp shift64-woo-search setup` / `rebuild`. On plugin update the
activation/upgrade routine issues an idempotent
`FT.ALTER {index} SCHEMA ADD date NUMERIC SORTABLE` (cheap, no downtime), after
which existing documents still lack the field until reindexed — `wp
shift64-woo-search reindex` backfills. The `health` command reports
"date field missing/partially indexed — run reindex" until complete. No
`wp_options` change, no new option (the candidate ceiling is a filter, not an
option, per BACKWARD_COMPATIBILITY §"options" — adding options carries
contract weight this tunable doesn't need).

The Product Sort block stores presentation choices in block content:

| Attribute | Type | Default | Contract |
| --- | --- | --- | --- |
| `enabledOptions` | ordered string array | all seven canonical slugs | Closed to `menu_order`, `popularity`, `rating`, `date`, `price`, `price-desc`, `relevance`; context filtering happens at render time |
| `labels` | object keyed by slug | `{}` | Empty/missing uses WooCommerce's translated label; values are plain text |

Appearance uses standard block-support attributes (`style`, preset classes,
font size, colors, spacing, borders, and dimensions), not Shift64 options.
There is no migration or generated SHORTINIT config entry for block
presentation.

## API Contracts

No SHORTINIT search-endpoint changes. Changed public surfaces:

- New dynamic block name `shift64-woo-search/product-sort`.
- Persistent block attributes `enabledOptions` and `labels` with the shapes
  above.
- New filter `shift64_woo_search_wc_sort_candidate_limit` (int, default
  10000). Additive — allowed per BACKWARD_COMPATIBILITY hook rules.
- All existing `shift64_woo_search_*` options keep keys, types, defaults.

The block uses a WordPress script module declared through `block.json` with
Interactivity API client-navigation support. It does not read a private
WooCommerce JavaScript store. Its only browser-visible state contract is the
canonical `orderby` query parameter. It uses the Catalog State parser/builder
from the Product Collection integration spec and the visual primitive from the
Filter Pill spec; it does not create alternate URL or panel implementations.

## UI/UX

### Site Editor

- The merchant inserts **Shift64 Product Sort** wherever the control belongs
  in the archive/search template.
- Inspector controls show an ordered list of canonical modes with enable
  toggles, drag ordering, and optional label overrides.
- Search Relevance is identified as search-only. It remains configurable in
  the template, but the editor preview explains that it renders only for a
  product-search context.
- The block exposes the same color, typography, spacing, border, dimensions,
  and focus-state styling contract as Filter Pill. No appearance control links
  to Shift64 WP Admin.
- If every mode is disabled, the editor shows a blocking validation message:
  remove the block to offer no sorting, or enable at least one mode.

### Storefront

- The closed control is visually compatible with Filter Pill and announces
  the current mode.
- Activating it reveals only the merchant-enabled choices that are valid in
  the current context.
- Choosing a mode updates `orderby`, removes path/query pagination, closes the
  choice surface, and delegates navigation/rendering to the WordPress router
  and WooCommerce Product Collection.
- The current choice is communicated with native selected/checked semantics;
  the trigger has an accessible name independent of custom visible labels.
- Removing the block from the template removes Shift64's sorting UI. The
  plugin never auto-inserts it and never hides a separately inserted
  WooCommerce Catalog Sorting block.

## Edge Cases & Failure Scenarios

- **Search Relevance outside search:** omitted even when present in
  `enabledOptions`; it never becomes a meaningless catalog ordering mode.
- **A configured native mode becomes unavailable:** for example, rating after
  reviews are disabled. The resolver omits it without changing stored block
  content, so it returns automatically if the Woo feature is re-enabled.
- **Direct URL uses a mode omitted from this block:** the engine still honors
  a known mode, and third-party values still use `wc` pass-through. To keep the
  control truthful, the current URL mode is rendered as a temporary selected
  entry for that request; once the shopper chooses an enabled mode, the
  omitted mode is no longer offered.
- **No context-compatible configured choices:** render no frontend control and
  emit an editor warning. This can occur when a block contains only
  `relevance` but is placed on a non-search archive.
- **Product Sort and Woo Catalog Sorting are both inserted:** both remain
  visible. Site Editor placement is authoritative; Shift64 does not hide or
  take over another block.
- **`date` sort before reindex completes:** `SORTBY date` on documents missing
  the field would silently misorder (RediSearch treats missing numeric as 0) —
  therefore `date` routes to `wc` mode until `health` confirms the field is
  fully indexed (stored flag set by reindex completion). User sees correct
  ordering either way.
- **Result set above the candidate ceiling in `wc` mode:** plugin declines to
  intercept; native WC search serves the request; debug bar logs
  `wc-sort candidate limit exceeded (N > 10000) — native fallback`.
- **`FT.AGGREGATE` failure (old RediSearch, syntax error):** treat like any
  Redis failure — existing MySQL fallback path (`intercept()` returns without
  injecting). Logged.
- **All products `menu_order = 0`:** composite SORTBY makes `title ASC` the
  effective order, matching WooCommerce exactly.
- **Out-of-stock handling:** `exclude` mode stays part of the FT query in every
  mode. `demote` mode only ever applied to relevance ordering (unchanged);
  deterministic sorts ignore it, as they do today for price.
- **Facets/filters combined with sorting:** filter TAG clauses are part of the
  FT query string and compose with SORTBY/AGGREGATE unchanged; `wc` mode
  passes the same filtered query to the ID fetch.
- **Product Collection client navigation:** the Sort block script module is
  declared client-navigation compatible and the control is server-rendered in
  its own router-refreshable region, so selected state survives pagination,
  filtering, browser history, and direct navigation.
- **Redis unavailable:** the Product Collection query adapter declines the
  Redis path and WooCommerce serves its native result query; the Sort block
  retains canonical URL behavior.

## Risks & Impact Review

- **Blast radius:** Product Collection sorting and the Product Sort block; the
  relevance pipeline, taxonomy archive, autocomplete, and SHORTINIT endpoint
  are untouched.
- **Rollback:** no destructive migration. `FT.ALTER` adds a field old code
  ignores; reverting the plugin restores prior behavior without a rebuild.
- **Performance:** SORTBY paths are one Redis call, same as today's price
  sort. `wc` mode on large sets does a chunked ID fetch + big `post__in`;
  bounded by the ceiling and reachable only via third-party orderby values or
  B2B price mode.
- **Router integration** is the riskiest presentation detail. E2E coverage
  must prove one navigation, one history entry, pagination reset, selected
  state, and correct Product Collection ordering.
- **Compatibility:** BACKWARD_COMPATIBILITY option/hook rules honored (no
  renames, additive filter and block only). The separate block-theme transition
  work owns removal of classic-theme and shortcode contracts.

## Phasing

- **Phase 1 — engine plus Product Sort block, no reindex required.**
  Popularity, rating, and menu_order use existing indexed fields; date uses
  `wc` mode. Engine steps are independently shippable but remain unexposed.
  Once the Product Collection and Filter Pill shared contracts are
  implemented, the manually placed block exposes the merchant-selected subset,
  routes through Product Collection navigation, and includes default
  resolution and large-catalog-safe pass-through.
- **Phase 2 — `date` field.** Schema addition + indexer + migration + health
  reporting; `date` upgrades from `wc` mode to `SORTBY`. Pure performance
  upgrade, independently shippable. It touches a different subsystem (indexer,
  upgrade routine, CLI health) with a migration surface — deliver it as its
  own PR with its own changelog entry, not as a tail on the Phase 1 PR.

## Implementation Plan

### Phase 1

1. **`Shift64_Woo_Search_Sort` service** — orderby → mode/SORTBY resolution
   table, default-sort resolution (store default + `menu_order`→`relevance`
   search remap). Pure logic, PHPUnit-covered. Unused yet; app unchanged.
2. **Generalize the `use_wc_price` branch into `wc` mode** — chunked full-ID
   fetch, ceiling filter, decline-to-intercept overflow behavior; B2B price
   path rides it. PHPUnit for the fetch/ceiling logic; app works after.
3. **Wire `redis` mode for `popularity`/`rating`** through
   `ft_search_with_offset()`; route unknown orderby to `wc` mode. Tests per
   orderby value asserting the FT command built.
4. **`menu_order` composite sort via `FT.AGGREGATE`** with failure fallback to
   the MySQL bail-out. Tests for command shape + failure path.
5. **Adopt the Product Collection and Filter Pill contracts.** Require their
   implemented spec statuses; reuse Catalog State, the request-scoped result
   envelope, router ownership, and shared pill visual primitive. Add contract
   tests proving no duplicate URL builder, Woo region replacement, or private
   Woo JS store.
6. **Register `shift64-woo-search/product-sort`.** Add block metadata, an
   editor ordered-checklist/label model, standard block supports, dynamic
   rendering, canonical Woo/context option resolution, and selected-state
   rendering. PHPUnit covers attribute validation, Woo feature/context
   filtering, label fallbacks, and direct-URL truthfulness.
7. **Connect the block to Product Collection navigation.** Add an Interactivity
   API script module that changes canonical `orderby`, removes pagination,
   closes/restores focus, and delegates navigation to the WordPress router.
   E2E in the block-theme project asserts merchant-selected option visibility,
   relevance only on search, price + popularity result order, one request, one
   history entry, Back behavior, and pagination reset. Playwright remains out
   of the hermetic validation gate per `AGENTS.md`.

### Phase 2

8. **Schema + indexer:** add `date` NUMERIC SORTABLE; indexer writes the
   timestamp; `setup` creates it fresh.
9. **Migration + gating:** upgrade-time `FT.ALTER`, reindex-completion flag,
   `health` reporting; `date` flips from `wc` mode to `SORTBY date DESC` only
   when the flag is set. Tests for both states.
10. **Docs:** changelog entries; document the Product Sort block and its Site
   Editor-only placement; flip this spec's Status header and the
   `.ai/specs/README.md` row in the implementing PR (AGENTS.md lifecycle
   rule).

Each step leaves the plugin releasable; steps 1–4 are invisible until steps
5–7 expose the new options.
