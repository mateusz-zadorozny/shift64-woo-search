# Native WooCommerce Brands Support (`product_brand`)

> **Status:** implemented — PR #11 (issue #10), 2026-07. All seven phases verified in code 2026-07-27 (schema `brands_text`, brand facet, suggest blob + ranker, row label, brand archives, seeder).

## TLDR

Light up the already-indexed but unused `brands` RediSearch field: WooCommerce's native `product_brand` taxonomy (core since WC 9.4) becomes a first-class search dimension. Typing a brand name finds its products, each quick-search product row shows its brand, the dropdown gets a dedicated ranked "Brands" section, the sidebar filter system gains a brand facet, brand archive pages (`/brand/…/`) get RediSearch-powered, and the demo seeder generates brands so all of it is testable locally. The existing **categories** dimension is the architectural template at every layer; no new indexing machinery is invented.

Out of scope by decision: plugin-injected brand labels on WooCommerce search-result cards (WooCommerce/theme owns card markup), brand logos in suggestions, brand boost/pin rules (follow-up parity with categories if brand suggestions prove useful).

## Problem Statement

- The indexer already extracts brand terms (`includes/class-shift64-woo-search-indexer.php:178-188`, `product_brand` primary with `pa_brand` fallback) and the schema defines a `brands` TAG field (`includes/class-shift64-woo-search-schema.php:111-114`) — but nothing downstream reads it. No facet, no filter branch, no autocomplete payload, no suggest blob, no archive scope. Merchants on modern WooCommerce (brands are core since 9.4; local dev runs 10.9.4) get zero brand UX from this plugin.
- `brands` is TAG-only, so typing a brand name matches nothing unless the name happens to appear in the title — the most common brand-related search intent silently fails.
- The demo seeder (`bin/generate-demo-products.php`) creates categories and attributes but no brands, so brand features cannot be developed or QA'd against local data.
- The codebase already anticipates this feature: `class-shift64-woo-search-taxonomy-archive.php:5-6` ("product_brand tomorrow"), `frontend/class-shift64-woo-search-filters.php:31` ("future brand archive").

## Prior Art

- **FiboSearch** (market-leading Woo search): searches brand names as text, shows brands as a dedicated autocomplete section with per-section toggles and limits, and optionally shows brand in the product row details. This spec adopts all three, skipping FiboSearch's generic any-taxonomy engine — only `product_brand` is needed here.
- **Algolia / Doofinder / Klevu**: brand is a standard facet attribute, and autocomplete is federated into sections (products / categories / brands). Confirms the federated-dropdown shape this plugin already has for categories.
- **Relevanssi / SearchWP**: taxonomy terms as weighted searchable content — confirms the weighted `brands_text` TEXT field approach over query-time joins.
- **WooCommerce core**: registers `product_brand` as a **hierarchical** taxonomy with archives (`woocommerce/includes/class-wc-brands.php:310-323`). Hierarchy means the ancestor-chain treatment categories already get (`indexer.php:162-176`) applies to brands too — a parent-brand filter must match sub-brand products.

## Proposed Solution

Follow the hardcoded **categories** pattern at every layer — not the attribute auto-register pattern, and not a new abstraction:

- **Searchable**: add a weighted `brands_text` TEXT field. The free-text query searches all TEXT fields globally, so no query-builder change is needed — the field participates once it exists in the schema and hashes.
- **Filterable**: a `brand` filter key in `Query::build_filter_parts()` (`@brands:{…}`), a brands branch in `Facets::compute()` gated on a new `shift64_woo_search_filter_brands_enabled` option, a brand group in the sidebar renderer, and `filter_product_brand` URL parsing in the search archive.
- **Suggested**: a `{prefix}:brands` blob cached like the categories blob, ranked by a SHORTINIT-safe ranker sharing the `Category_Suggest` scoring core, rendered as a "Brands" dropdown section between Categories and Products.
- **Row label**: `brand` added to the autocomplete product payload and rendered in the row meta line next to SKU/category.
- **Archives**: one `product_brand` entry in the taxonomy-archive scope map; the admin scope checkbox appears automatically.
- **Seeded**: fictional brand pool in the demo generator, deterministic, including one parent→child hierarchy and a few multi-brand and brandless products to exercise edge paths.

**Alternatives considered**

- *Attribute auto-register pattern (`attr_pa_brand`)* — rejected: brands are a fixed core taxonomy like categories, not a merchant-defined attribute; a static toggle is the right control. `pa_brand` remains only as the indexer's legacy fallback.
- *Generic "taxonomy dimension" abstraction* — rejected for now: exactly two fixed taxonomies exist (categories, brands); the abstraction cost exceeds the payoff at n=2. The one place duplication would really hurt — the SHORTINIT ranking math — is handled by extracting the scoring core instead (see Architecture).
- *Query-time brand lookup instead of `brands_text`* — rejected: breaks the SHORTINIT design (no WP APIs) and the index-time denormalization strategy documented in `docs/indexing-strategy.md:37-73`.

## Architecture

Reused unchanged: Redis connection, `Facet_Registry`, `Facet_Context` interface, mobile filter UI plumbing, AJAX partial re-render, breadcrumbs, self-heal (`Schema::ensure_index_healthy()`).

One deliberate prep refactor precedes the schema work: the drop → create → synonyms/suggestions sync → blobs → reindex → config-regen sequence, currently duplicated in `cli/class-shift64-woo-search-cli.php:323-380` and `class-shift64-woo-search-attribute-auto-register.php:120-185`, is extracted into a single shared rebuild routine. Three callers need it after this spec (CLI, auto-register cron, `db_version` upgrade hook), which is past the duplication threshold. It lands as its own phase with no behavior change, before any brand work depends on it.

Changed components, per data-flow (file:line references are as-of-writing, i.e. pre-refactor):

```text
Index time:
  Indexer::build_product_data()      — brands: add ancestor chain (mirror categories :162-176),
                                       ordered directly-assigned terms first; new brands_text value
  Indexer::cache_brands_to_redis()   — NEW, sibling of cache_categories_to_redis() (:385-452) → {prefix}:brands blob
  Schema::create_index()             — add brands_text TEXT weighted field
  Schema::get_default_weights()      — add 'brands_text' => 5 (auto-surfaces in admin Weights tab, admin.php:114,714,1845)
  Rebuild wiring                     — brand blob cached wherever the categories blob is cached: inside the shared
                                       rebuild routine, plus the product-sync and admin save paths that refresh
                                       blobs outside rebuilds (sync.php:145,236 · admin.php:2004,2232 · cli.php:228)
  Upgrade path                       — the db_version check (shift64-woo-search.php:352-356) gains a version-gated
                                       action map: schema versions schedule the shared full rebuild; blob-only
                                       versions run cache_brands_to_redis() alone (cheap, no reindex)

Query time (full WP):
  Query::build_filter_parts()        — 'brand' key → @brands:{…} branch (mirror category :922-926)
  Facets::compute()                  — brands branch gated on shift64_woo_search_filter_brands_enabled (mirror :55-61)
  Query::execute_brand_facet()       — NEW, clone of execute_category_facet() (:1964-2045): PHP-split of the
                                       multi-value TAG (products can carry several brands + ancestor chains,
                                       which rules out FT.AGGREGATE GROUPBY)
  Archive::parse_filter_params()     — filter_product_brand param (mirror filter_product_cat :982-994)
  Taxonomy_Archive::$scope_map       — product_brand entry {filter_key:'brand', redis_field:'brands',
                                       facet_dimensions:['attr_pa_*']} (:35-41)
  Filters::render_filters()          — brand group after Categories (mirror :87-105); mobile UI picks it up free

Query time (SHORTINIT endpoint):
  Query::format_autocomplete_results() — 'brand' key from the already-fetched hash (:1829-1852)
  Category_Suggest                     — scoring core extracted into a taxonomy-agnostic, WP-free method;
                                         category behavior unchanged; brand ranking reuses it with
                                         pins/boosts empty (blob carries boost=1.0 for shape parity)
  endpoint.php                         — load ranker, add brands section next to categories (:186-191),
                                         add brands:[] to empty shapes (:78-99), read one new constant

Frontend JS (shift64-woo-search.js):
  renderResults()                    — "Brands" section between Categories (:481-491) and Products
  renderItem()                       — brand in metaParts (:540-549), same guard shape as category (:547)
```

## Data Model

**RediSearch schema** (index rebuild required — see Risks):

| Field | Type | Notes |
|---|---|---|
| `brands` | TAG, `SEPARATOR \|` | exists; value becomes ancestor-inclusive with a **guaranteed order: directly-assigned terms first, then their ancestors** (deduplicated). Names not slugs, consistent with `categories`. The order is a contract — the autocomplete row label takes the first segment, which must be a brand the product is actually assigned to |
| `brands_text` | TEXT, WEIGHT 5 (default) | NEW; plain-text brand names for free-text matching; weight sits between `sku_text` (8) and `categories_text` (4) — brand queries are high-intent tokens |

**Redis keyspace** (additive; `BACKWARD_COMPATIBILITY.md` §3 gains one row):

- `{prefix}:brands` — JSON blob `[{name, name_ascii, slug, url, count, boost}]`, same shape as `{prefix}:categories` (boost fixed at 1.0 in v1). Written wherever the categories blob is written; read only by the SHORTINIT endpoint.

**Options** (additive; `BACKWARD_COMPATIBILITY.md` §6 list grows):

- `shift64_woo_search_filter_brands_enabled` — `'yes'`/absent, default off (merchant opt-in, same semantics as `_filter_categories_enabled`)
- `shift64_woo_search_brand_suggest_enabled` — `'yes'`/absent, default **on** (harmless when the store has no brands; the section hides when the blob is empty)

There is deliberately **no** show-brand-in-row option: the row label is governed by the hardcoded `showBrand => true` localized-config key, exactly like the existing `showCategory` (`frontend/class-shift64-woo-search-frontend.php:239`).

**Generated config constant** (`BACKWARD_COMPATIBILITY.md` §4 rules apply):

- `SHIFT64_WOO_SEARCH_BRAND_SUGGEST` — `'1'`/`'0'`, written by `generate_mu_plugin_config()` in the same PR that makes the endpoint read it; endpoint treats absence as enabled (matches the option default).

**`shift64_woo_search_db_version`** is bumped twice: once when the schema changes (Phase 4 — triggers the shared full rebuild) and once when the brands blob ships (Phase 6 — triggers a blob-only refresh, no reindex). Both ride the version-gated action map described in Architecture.

No SQL changes. Brand logos (`thumbnail_id` term meta) are not indexed in v1.

## API Contracts

All changes are additive — permitted without deprecation on the `0.x` line and explicitly non-breaking per `BACKWARD_COMPATIBILITY.md` §1/§9; the doc itself is updated in the same PRs.

**SHORTINIT endpoint** (autocomplete mode):

- `results[].brand` — string; pipe-separated brand names exactly like `results[].category`; `''` for brandless products. The first segment is always a directly-assigned brand (see Data Model ordering contract); JS displays only that first segment.
- top-level `brands` — `[{name, url, count}]`, max 5 (same cap as categories, `class-shift64-woo-search-category-suggest.php:27`). **The key is always present in every autocomplete-mode response** — including empty/focus shapes and when `SHIFT64_WOO_SEARCH_BRAND_SUGGEST` disables the feature, where it is `[]`. The frontend hides the section purely on emptiness; the response shape never varies with the toggle.

**URL parameter** (search archive): `filter_product_brand` — comma-separated `product_brand` slugs, identical grammar to `filter_product_cat`. Unknown slugs are ignored (existing category behavior).

**Localized `shift64_woo_search_config`**: new keys `showBrand` (bool, hardcoded `true`, mirroring `showCategory` at `frontend.php:239`) and `brandsHeaderText` (string, sourced like the categories header). JS guards `config.showBrand !== false && brand`, so a brandless product simply shows no label.

**WP-CLI**: no new commands or flags. `rebuild`, `reindex --all`, and `setup` additionally cache the brands blob — an internal behavior addition, not an interface change.

**Hooks**: none added in v1 (every hook is a promise; nothing here needs third-party extension yet).

## UI/UX

Only what is brand-specific; everything inherits existing dropdown/filter styling and keyboard navigation.

- **Dropdown section order**: Suggestions → Categories → **Brands** → Products. Brand rows link to the brand archive URL and show the product count, mirroring category rows. Section hidden whenever the `brands` array is empty — which covers both a brandless store and the disabled state.
- **Product row meta**: `SKU · Category · Brand` (first — i.e. directly-assigned — brand only).
- **Sidebar filters**: "Brand" checkbox group after Categories, before attributes; multi-select is OR within the group, AND across groups (existing semantics). Mobile filter drawer inherits the group automatically (`filters.php:327-506`).
- **Admin, Filters tab**: "Show Brand Filter" checkbox next to the category toggle (`admin.php:809-841`). When `taxonomy_exists('product_brand')` is false (WC < 9.4), render it disabled with a short explanation instead of hiding it.
- **Admin, Search tab**: the brand-archive scope checkbox appears automatically once the scope map has the entry (`admin.php:1143-1168` iterates `get_scope_map()`).
- **Admin, Weights tab**: `brands_text` appears automatically (UI iterates `get_default_weights()`); add its human label where field labels are defined.

## Edge Cases & Failure Scenarios

- **Store with zero brands**: blob missing/empty → `brands: []`, section hidden; facet returns no buckets → sidebar group not rendered; no errors. This is the default state of every existing install.
- **WC without `product_brand`** (< 9.4, or externally deregistered): indexer already falls back to `pa_brand` (`indexer.php:178-188`) — keep it. Admin toggle disabled with explanation; seeder skips brands with a warning; scope-map entry registers only when the taxonomy exists.
- **Multi-brand products**: pipe-separated TAG; `execute_brand_facet()` PHP-splits so each brand counts the product once; row label shows the first directly-assigned brand.
- **Brand hierarchy**: ancestor chain indexed (assigned-first order) → parent-brand filter matches descendant products, parent appears in facet counts, and the row label never shows a parent the product wasn't assigned to. Same filtering semantics as categories today.
- **Brand name containing `|`**: same pre-existing separator limitation as categories/tags. Documented, not fixed here — fixing it is a cross-dimension change that must move all TAG fields together.
- **Brand renamed/deleted**: product hashes and blob refresh through the same triggers as categories (product save via `sync.php`, admin saves, CLI, cron rebuild). Until a rebuild touches unrelated products, their hashes hold the stale name — identical to current category behavior; `wp shift64-woo-search rebuild` is the documented remedy.
- **Upgrade without rebuild (schema)**: `brands_text` absent from the live index → brand-name searches silently return nothing; `ensure_index_healthy()` does **not** detect field-level drift (it checks existence + doc count only, `schema.php:227-314`). This is why the Phase 4 `db_version`-gated rebuild is mandatory, per `BACKWARD_COMPATIBILITY.md` §3 ("a PR that changes the schema and leaves existing installs on a stale index is broken").
- **Upgrade without blob (suggestions)**: on an existing install the `{prefix}:brands` blob doesn't exist until something writes it — without a trigger the Brands section would stay silently hidden until an unrelated product save. Hence the Phase 6 `db_version` bump running a blob-only refresh on upgrade.
- **SHORTINIT discipline**: the brand ranker must stay WP-free (no `get_option`, no i18n) — enforced by reusing the `Category_Suggest` pattern and its unit tests; configuration flows only through generated constants, and the endpoint tolerates their absence (§4).
- **Redis down**: existing degraded fallback (`/?s=…&post_type=product`) is untouched; brand surfaces simply don't render.
- **Facet self-exclusion**: selecting a brand must not zero the brand facet's own counts — inherited by building on `build_facet_query()` exclude-self logic, verified by test.
- **Huge brand lists**: blob is built once per rebuild and linearly scanned per request with a hard result cap of 5 — same cost profile as categories; no new limit needed at realistic catalog sizes.

## Risks & Impact Review

- **Blast radius**: `query.php`, `endpoint.php`, and the frontend JS are hot paths on every storefront search. Mitigations: the filter facet is opt-in (option default off); the suggest section is empty-safe and constant-gated; each phase ships and is QA'd independently.
- **Refactor risk**: extracting the shared rebuild sequence and the suggest-scoring core touches working non-brand code. Both extractions are isolated in their own steps (Phase 3 and Phase 6 step 17), land with **zero behavior change** before anything depends on them, and are covered by existing tests plus new ones written first.
- **Rebuild window**: the shared rebuild sequence uses `FT.DROPINDEX … DD` then reindexes — search degrades (empty index → fallback behavior) for the duration of the reindex on large catalogs. Acceptable on `0.x`; the changelog and release notes must say "rebuild runs automatically on upgrade; expect degraded search for the reindex duration". No new mechanism is introduced — this is the existing rebuild characteristic.
- **Rollback**: turn the two options off → all brand UX disappears; the extra schema field and blob are inert. Full rollback = downgrade plugin + `wp shift64-woo-search rebuild` (recreates the old schema). No data migration in either direction.
- **Compatibility**: every surface change is additive; `BACKWARD_COMPATIBILITY.md` §§1, 3, 4, 6, 9 are amended in the same PRs that touch them. Runtime minimums are unchanged (WP 6.0 / PHP 8.3; no WC minimum is declared and none is added — brand features degrade per the edge cases above).

## Phasing

Each phase is independently shippable and leaves the plugin fully working.

1. **Phase 1 — Seed brands** (dev tooling; enables QA for everything after)
2. **Phase 2 — Row label** (payload + JS meta; depends only on the already-populated `brands` TAG field)
3. **Phase 3 — Rebuild plumbing prep** (shared rebuild routine + version-gated upgrade actions; zero behavior change)
4. **Phase 4 — Searchable brands** (schema + ancestor chain + `db_version` schema bump; depends on Phase 3)
5. **Phase 5 — Brand filter facet** (sidebar + URL + admin toggle)
6. **Phase 6 — Brands dropdown section** (blob + ranker + endpoint + JS + `db_version` blob bump)
7. **Phase 7 — Brand archive pages** (scope-map entry; depends on Phase 5's filter key)

## Implementation Plan

File:line references are as-of-writing; Phase 3 relocates the rebuild sequence, so later phases target the shared routine plus the sync/admin blob call sites that remain outside it.

**Phase 1 — Seeding**

1. Add `get_brands()` (≈8 fictional myth-flavored brands, incl. one parent with two children) and `ensure_brands()` (guard `taxonomy_exists('product_brand')`, skip with warning otherwise) to `bin/generate-demo-products.php`, mirroring `ensure_categories()` (:164-183). *Test*: run via `wp eval-file` on the local site; terms exist incl. hierarchy; idempotent on re-run.
2. Assign brands in `apply_common_product_data()` via `wp_set_object_terms()` after product save: weighted-random single brand for ~80% of products, two brands for ~10%, none for ~10% — deterministic under the existing seeded `mt_srand` (:75). Update `bin/README.md` and the "run rebuild" reminder (:125). *Test*: re-run with a fixed seed twice; identical assignments; distribution roughly as specified.

**Phase 2 — Row label**

3. Payload + config: `'brand'` in `format_autocomplete_results()` (`query.php:1829-1852`, sourced from the already-fetched hash) and `'showBrand' => true` in the localized config (`frontend.php:239`). Update `BACKWARD_COMPATIBILITY.md` §1/§9. *Test*: PHPUnit for the payload shape incl. brandless products.
4. JS: brand in `renderItem()` metaParts with the `config.showBrand !== false && brand` guard (`js:540-549`). *Test*: manual dropdown QA on seeded data — brand shows after SKU/category; brandless rows unchanged.

**Phase 3 — Rebuild plumbing prep (no behavior change)**

5. Extract the drop → create → sync → blobs → reindex → config-regen sequence from `cli.php:323-380` and `attribute-auto-register.php:120-185` into one shared routine; both callers delegate to it. *Test*: existing CLI/rebuild tests stay green; `wp shift64-woo-search rebuild` verified locally end-to-end.
6. Add the version-gated action map to the `db_version` check (`shift64-woo-search.php:352-356`): a version can schedule the shared full rebuild or run a lightweight action. No version is bumped in this phase. *Test*: PHPUnit — simulated stale `db_version` triggers exactly the mapped action, current version triggers nothing.

**Phase 4 — Searchable brands**

7. Indexer: ancestor-chain brand extraction with **directly-assigned terms first** (mirror `indexer.php:162-176`) and `brands_text` in `build_product_data()`. *Test*: PHPUnit covering flat, hierarchical (order asserted), multi-brand, brandless, and `pa_brand`-fallback products.
8. Schema: `brands_text` TEXT in `create_index()`, `'brands_text' => 5` in `get_default_weights()`, admin Weights-tab label. *Test*: PHPUnit asserts the FT.CREATE arg list; manual check of the Weights tab.
9. Bump `db_version` mapped to the shared full rebuild; changelog + `BACKWARD_COMPATIBILITY.md` §3. *Test*: simulate old version → rebuild scheduled and lands; `wp shift64-woo-search test "<seeded brand>"` returns that brand's products.

**Phase 5 — Filter facet**

10. `build_filter_parts()`: `brand` key → `@brands:{…}` with `escape_tag_value()`. *Test*: PHPUnit query-string assertions incl. names needing escaping.
11. `Facets::compute()` brands branch gated on `shift64_woo_search_filter_brands_enabled` + `execute_brand_facet()` (clone of `execute_category_facet()`, multi-value-safe). *Test*: PHPUnit facet-count tests over seeded fixtures, incl. exclude-self and multi-brand.
12. Renderer + URL: brand group in `render_filters()` (after Categories); `filter_product_brand` parsing in `Archive::parse_filter_params()`. *Test*: integration — filtered search URL returns only that brand's products; AJAX partial re-render keeps the group.
13. Admin: "Show Brand Filter" checkbox in the Filters tab + `ajax_save_filters()` branch; disabled state when the taxonomy is missing. `BACKWARD_COMPATIBILITY.md` §6. *Test*: toggle round-trips; disabled state renders on a store without brands.
14. E2E QA on the seeded store (desktop + mobile drawer): select brand → results, counts, self-exclusion, combination with category/attribute filters.

**Phase 6 — Dropdown Brands section**

15. `cache_brands_to_redis()` (sibling of `:385-452`, boost fixed 1.0), called inside the shared rebuild routine and at the sync/admin/CLI blob call sites (`sync.php:145,236` · `admin.php:2004,2232` · `cli.php:228`). `BACKWARD_COMPATIBILITY.md` §3 keyspace row. *Test*: PHPUnit blob-shape test; rebuild populates `{prefix}:brands`.
16. Extract the `Category_Suggest` scoring core into a taxonomy-agnostic WP-free method; brand ranking uses it with empty pins/boosts. *Test*: existing category-suggest unit tests unchanged and green; new brand-ranking tests (prefix, fuzzy, count tiebreak, cap 5).
17. Endpoint + config + upgrade: load the ranker, emit the `brands` section and `brands: []` in every autocomplete shape (including constant-off); `generate_mu_plugin_config()` writes `SHIFT64_WOO_SEARCH_BRAND_SUGGEST` (absence = enabled); new option `shift64_woo_search_brand_suggest_enabled` + admin checkbox; bump `db_version` mapped to a blob-only refresh so upgraded installs get the section without a reindex. `BACKWARD_COMPATIBILITY.md` §§1, 4, 6. *Test*: endpoint integration tests for populated, empty, and constant-off cases (key always present); upgrade simulation populates the blob.
18. JS: render the Brands section between Categories and Products; `brandsHeaderText`; focus/empty states pass `brands: []`. *Test*: manual dropdown QA on seeded data; section hides on a brandless store.

**Phase 7 — Brand archives**

19. Scope map: `product_brand` entry (`filter_key: 'brand'`, `redis_field: 'brands'`, `facet_dimensions: ['attr_pa_*']`), registered only when the taxonomy exists; admin scope checkbox appears automatically. *Test*: integration — `/brand/<seeded>/` intercepted, products via RediSearch, attribute facets + pagination + breadcrumbs work; toggle off restores native WP behavior.
20. Follow-up candidates recorded (not implemented): `brands` facet dimension on category archives, `categories` dimension on brand archives, brand logos in suggestions, brand boost/pin parity.
