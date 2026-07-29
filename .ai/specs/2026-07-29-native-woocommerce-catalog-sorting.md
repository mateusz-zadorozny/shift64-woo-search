# Native WooCommerce Catalog Sorting on Search Archives

> **Status:** draft

## TLDR

The search-archive interceptor currently replaces WooCommerce's catalog sort
dropdown with only three options (relevance, price asc, price desc) and treats
every non-price `?orderby=` as relevance. This spec removes that limitation:
all default WooCommerce sorting options (`menu_order`, `popularity`, `rating`,
`date`, `price`, `price-desc`) work on search archives, sorted Redis-side, with
`relevance` remaining the search-specific extra. The sorting engine is built as
a context-agnostic service so taxonomy (category) archives can adopt Redis-side
filtering and sorting in a later spec.

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
4. **Dropdown: filter to Woo's stock default list** (`menu_order`,
   `popularity`, `rating`, `date`, `price`, `price-desc`). WooCommerce core
   itself injects `relevance` and removes `menu_order` on search pages — the
   plugin does not duplicate that. Third-party dropdown additions are
   overridden (deliberate, per gate answer), but a third-party `?orderby=`
   arriving via URL still works through the pass-through path.
5. **Amendments (2026-07-29):** (a) the candidate-set path must handle large
   result sets — a hard 1k trim breaks large stores; (b) the index carries the
   fields needed for Woo's sorts (`date`; `menu_order`/`title` already
   sortable) and `visibility` filtering becomes context-aware, so that a future
   spec can serve category pages entirely from Redis filters.

## Problem Statement

`Shift64_Woo_Search_Archive::filter_sort_options()`
(`includes/class-shift64-woo-search-archive.php:603`) replaces the
`woocommerce_catalog_orderby` option list with 3 hardcoded entries, and
`intercept()` (line ~180) classifies orderby as "price or relevance" only. A
shopper choosing "Sort by popularity" on a non-search page loses that option on
search pages; a merchant's configured default catalog sort is ignored. The
mobile sort bottom sheet
(`frontend/class-shift64-woo-search-filters.php:443`) hardcodes the same 3
options. Meanwhile the taxonomy archive
(`includes/class-shift64-woo-search-taxonomy-archive.php`) already follows the
correct philosophy — "Redis acts as a FILTER, orderby is left untouched" — so
the plugin is internally inconsistent.

## Research (market comparison, from prior knowledge — not re-verified online)

- **ElasticPress** maps every WC catalog sort to an indexed engine field
  (`post_date`, `_price`, `total_sales`, `average_rating`) — the approach
  chosen here. It indexes the post date from day one; we retrofit it.
- **Algolia** handles sorting via per-sort index replicas. RediSearch supports
  ad-hoc `SORTBY` on SORTABLE fields, so we skip replica complexity entirely.
- **Relevanssi / FiboSearch** hand ordering back to WP/MySQL (our candidate-set
  path). It composes perfectly with third-party sort logic but scales poorly —
  which is why it is the fallback here, not the default.

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

### Dropdown & mobile sheet

- `filter_sort_options()` returns WooCommerce's stock six-option list instead
  of the 3-option replacement. Core's own search handling then injects
  `relevance` and drops `menu_order`.
- The mobile sort bottom sheet builds its options from the same filtered
  `woocommerce_catalog_orderby` output (`apply_filters` on the stock list)
  instead of a private hardcoded array — one source of truth.

### Default-sort resolution

In `intercept()`: explicit `?orderby=` wins; otherwise
`get_option( 'woocommerce_default_catalog_orderby', 'menu_order' )`, with
`menu_order` → `relevance` remapped on search. **Known wrinkle:** WooCommerce's
orderby template hardcodes `relevance` as the *selected* option on search when
no `?orderby=` is present, so a store default of e.g. `popularity` would sort
by popularity while the dropdown shows "Relevance". The implementation must
align the selected state; the candidate lever is populating the `orderby`
query var / `$_GET['orderby']` superglobal early in `intercept()` (sanitized),
which every WC template reads. This is a hack with precedent in the WC
ecosystem — flagged for review; if it proves fragile, the fallback is a small
override of the `loop/orderby.php` template args. **The mechanism must be
decided (spike + verified against the active theme) in step 5 before the rest
of that step is built** — the fallback is a template override with a
materially larger theme-compatibility surface, and discovering that mid-PR
would silently grow the step's scope.

### Visibility becomes context-aware (enabler for future category pages)

The index already stores raw catalog visibility
(`visible`/`catalog`/`search`/`hidden` — indexer line 293) but the query layer
hardcodes a single exclusion, `-@visibility:{hidden}`
(`class-shift64-woo-search-query.php:920`). This spec **parameterizes** that
exclusion by context and fixes the search context:

- **search context:** `-@visibility:{hidden|catalog}` (catalog-only products
  must not surface in search — this also fixes a latent correctness bug);
- other contexts pass their own exclusion set; **no catalog-context branch is
  implemented here** (it would have zero callers until the future
  "Redis filters on category pages" spec, which will define and test it).

Scope note (from adversarial review): this item is separable from sorting and
is kept in this spec by explicit owner decision (it is the index groundwork for
the category-pages direction). It lands as the final, independently revertable
step of Phase 1 with its own changelog line, so a rollback of the sorting work
and a rollback of the visibility fix stay independent.

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

## API Contracts

No REST/SHORTINIT endpoint changes. Changed public surfaces:

- `woocommerce_catalog_orderby` filter output on search pages changes from 3
  options to the stock six (user-visible behavior change, the point of this
  spec — not a break of any documented plugin contract).
- New filter `shift64_woo_search_wc_sort_candidate_limit` (int, default
  10000). Additive — allowed per BACKWARD_COMPATIBILITY hook rules.
- All existing `shift64_woo_search_*` options keep keys, types, defaults.

## Edge Cases & Failure Scenarios

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
- **AJAX partial re-render (Kadence):** re-renders via
  `woocommerce_catalog_ordering()` — inherits the restored options with no
  extra work.
- **Redis unavailable:** unchanged — `should_intercept()` bails, native WC.

## Risks & Impact Review

- **Blast radius:** search archive sorting/pagination and the sort UI; the
  relevance pipeline, taxonomy archive, autocomplete, and SHORTINIT endpoint
  are untouched. The visibility fix (`catalog`-only products leaving search
  results) is a deliberate, user-visible correctness change — called out in
  the changelog.
- **Rollback:** no destructive migration. `FT.ALTER` adds a field old code
  ignores; reverting the plugin restores prior behavior without a rebuild.
- **Performance:** SORTBY paths are one Redis call, same as today's price
  sort. `wc` mode on large sets does a chunked ID fetch + big `post__in`;
  bounded by the ceiling and reachable only via third-party orderby values or
  B2B price mode.
- **Selected-state superglobal population** is the riskiest implementation
  detail (flagged above for review).
- **Compatibility:** BACKWARD_COMPATIBILITY option/hook rules honored (no
  renames, additive filter only).

## Phasing

- **Phase 1 — full functionality, no reindex required.** Popularity, rating,
  menu_order via existing indexed fields; date via `wc` mode; dropdown +
  mobile sheet + default resolution; large-catalog-safe pass-through;
  context-aware visibility. Independently shippable.
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
5. **Restore the dropdown** (`filter_sort_options` → stock list) **and rebuild
   the mobile sheet options from the filtered `woocommerce_catalog_orderby`
   output.** Selected-state alignment for store defaults. E2E scenario in the
   existing Playwright suite (CI only — never in the validation gate, per
   AGENTS.md): assert option list and result order for price + popularity on a
   seeded search.
6. **Parameterized visibility exclusion** in the shared query builder; search
   context passes `hidden|catalog`; no other context implemented. Lands as its
   own commit, independently revertable. PHPUnit on the built query string;
   changelog note for the behavior fix.

### Phase 2

7. **Schema + indexer:** add `date` NUMERIC SORTABLE; indexer writes the
   timestamp; `setup` creates it fresh.
8. **Migration + gating:** upgrade-time `FT.ALTER`, reindex-completion flag,
   `health` reporting; `date` flips from `wc` mode to `SORTBY date DESC` only
   when the flag is set. Tests for both states.
9. **Docs:** changelog entries; flip this spec's Status header and the
   `.ai/specs/README.md` row in the implementing PR (AGENTS.md lifecycle
   rule).

Each step leaves the plugin releasable; steps 1–4 are invisible until step 5
exposes the new options.
