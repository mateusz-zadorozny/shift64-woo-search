# Product Filter and Filter Pill Blocks

> **Status:** implemented — PR #72, 2026-08-19

## 📝 TLDR

Add a Site Editor-placed Product Filters container whose repeatable Filter
Pill children each select one Redis facet and configure its shopper-facing
behavior. The blocks use canonical WooCommerce URL state, share their visual
primitive with Product Sort, and refresh through the WordPress router beside
the inherited Product Collection.

## 📝 Problem Statement

The current filter bar is inserted by a classic-theme hook, fixes all facets
into one renderer, and hardcodes mobile presentation. Merchants cannot compose
or independently style the filters in a block template, while the earlier
filter-bar spec would add more hooks, a shortcode, and admin appearance
settings that conflict with the block-theme-only direction.

## 📝 Proposed Solution

Register `shift64-woo-search/product-filters` as a constrained InnerBlocks
container and `shift64-woo-search/filter-pill` as its repeatable dynamic
child. Each pill selects an enabled, rebuilt facet and writes validated
canonical filter parameters; Redis remains authoritative for facet values and
counts.

The parent may contain any number of Filter Pills, which merchants can add,
remove, and reorder. It supplies shared state, layout, clear-all behavior, and
one router region. Each child owns a facet choice, label, selection behavior,
option ordering, and appearance. The same trigger/panel visual primitive and
block-support contract is reused by
`shift64-woo-search/product-sort`.

## 📝 Decisions

1. Blocks are placed manually in Site Editor archive templates. There is no
   automatic hook, shortcode, alternative hook selector, or admin appearance
   setting.
2. Initial facet sources are Product Category, native WooCommerce Brand, and
   product attributes (`pa_*`). Price, rating, stock, and custom taxonomies are
   deferred.
3. A facet appears in the block inspector only when it is enabled under
   Shift64 **Results → Facets** and the index rebuild containing it completed.
4. When no eligible facet exists, the inspector is the sole bridge to WP
   Admin: it explains the requirement and links to Facets settings/rebuild.
5. URL parameters are the public state contract. Blocks do not import
   WooCommerce's private Product Filters store.
6. Each block instance renders only options and selections permitted by its
   saved configuration; direct valid URL filters remain query inputs even
   when a template omits the corresponding pill.
7. Selecting a filter always resets Product Collection pagination.

## 📝 Research

- WooCommerce Product Filters establishes the expected block-theme experience:
  independently configurable controls, canonical URLs, Product Collection
  router updates, selected-item state, and mobile overlays.
- Its current state bootstrap uses a private blocks API and its counts follow
  WooCommerce/Store API query semantics. Importing that store would couple
  Shift64 to an unstable contract and could show counts that disagree with the
  Redis result set.
- ElasticPress and Algolia treat facets as independently configured
  presentation controls over one search request. Disjunctive facet counts
  exclude the facet's own selection while retaining all other filters so users
  can see meaningful alternatives.
- WordPress block context and constrained InnerBlocks provide the parent/child
  composition without a second plugin settings UI.

References:

- [WooCommerce Product Filters blocks](https://developer.woocommerce.com/docs/block-development/block-references/product-filters/)
- [WordPress block context](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-context/)
- [WordPress Interactivity API directives](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/api-reference/)

## 📝 Architecture

### Block tree

```text
Product Filters (allowedBlocks=Filter Pill)
├── Filter Pill: Category
├── Filter Pill: Brand
└── Filter Pill: Material
```

The parent does not use `templateLock`: merchants own pill count and ordering.
The child declares the Product Filters parent and is hidden from the global
inserter. The default insertion template contains Category only when Category
is an eligible rebuilt facet; otherwise the parent starts empty with setup
guidance.

Both blocks use API version 3 metadata and standard supports. The parent owns
flex/wrapping/gap/alignment and the clear-all control. Each Pill owns its
trigger/panel colors, typography, spacing, border, dimensions, and focus
styles. Custom CSS supplies semantic layout only and consumes theme presets;
there are no saved raw colors or WP Admin styling options.

### Facet registry

Add `Shift64_Woo_Search_Facet_Registry` as the single source of editor and
runtime eligibility. It combines:

- the Facets setting;
- current Redis schema/index metadata;
- completed rebuild generation;
- WooCommerce taxonomy existence/public visibility; and
- translated default labels.

Each entry exposes a closed facet key, taxonomy, type
(`category|brand|attribute`), label, supported operators, and readiness:
`ready|disabled|rebuild-required|taxonomy-missing`.

The registry never infers eligibility merely because a `filter_*` parameter
arrived. Runtime request parsing accepts only ready registry entries.

### Editor data

Expose a small authenticated editor-only REST response:

`GET /shift64-woo-search/v1/editor/facets`

It returns facet keys, labels, types, supported controls, readiness, the Facets
settings URL, and whether a rebuild is required. Permission requires the
capability already used to edit Shift64 search settings and Site Editor
templates; no index content or customer data is returned.

The editor uses this endpoint for inspector choices and setup notices.
Storefront rendering reads the PHP registry directly and never calls REST.

### Runtime rendering and state

The Product Filters parent is dynamic and renders:

- a stable router region;
- a shared Interactivity API context containing normalized current selections;
- its saved Pill children; and
- an optional clear-all control when at least one represented facet is active.

Each Filter Pill asks the request-scoped Product Collection result envelope for
its facet buckets. Values are term IDs/slugs/labels/counts from the ready
registry and Redis query. The child renders a button plus a progressively
enhanced checkbox/radio form.

For multiple-selection facets, counts are disjunctive: keep search/archive and
all other facet filters, omit the current facet's selected clause, then count
its buckets. This prevents the current selection from collapsing its own list.
The query service may batch/aggregate counts, but renderer code never issues
Redis commands.

### Interactivity and shared visual primitive

Use namespace `shift64-woo-search/product-filters`. Per-parent state tracks the
open pill, draft selection, applied URL selection, viewport presentation, and
focus return target.

The reusable visual primitive is a documented markup/style/action contract,
not another public block:

- pill trigger with label, optional selection summary, and expanded state;
- option panel with heading and native checkbox/radio controls;
- Apply and Clear actions where draft state differs from URL state;
- shared focus-ring, disabled, busy, and selected CSS selectors;
- desktop disclosure panel anchored to its pill;
- narrow-screen modal tray plus backdrop, with focus containment and Escape;
  and
- stacking tokens guaranteeing backdrop above page controls and tray above
  backdrop.

Product Sort imports the primitive's styles/helpers and supplies radio choices.
It does not import the Product Filters state store.

Applying or clearing builds a canonical URL through the shared Catalog State
service, resets all paging forms, and uses the public core router when
compatible. The native form/link fallback navigates to the same URL.

## 📝 Data Model

### Product Filters attributes

| Attribute | Type/default | Contract |
| --- | --- | --- |
| `showClearAll` | boolean/true | Render only when at least one represented facet is active |
| `clearAllLabel` | string/translated default | Plain text |
| `instanceId` | string/generated | Stable, sanitized router/DOM key |

Layout and appearance remain standard block-support attributes.

### Filter Pill attributes

| Attribute | Type/default | Contract |
| --- | --- | --- |
| `facet` | string/empty | Closed registry key; required for frontend output |
| `label` | string/empty | Empty uses registry/taxonomy label |
| `selectionMode` | enum `multiple|single` / `multiple` | Single uses radio semantics and replaces prior value |
| `queryType` | enum `or|and` / `or` | Offered only where the registry says supported |
| `showCounts` | boolean/true | Visible count; accessible name never relies on count alone |
| `hideEmpty` | boolean/true | Hide zero-count unselected terms; selected terms remain visible |
| `orderBy` | enum `count-desc|name-asc|name-desc` / `count-desc` | Deterministic label tie-break |
| `maxOptions` | integer/0 | `0` means all; otherwise clamp 1–100 |
| `applyLabel` | string/translated default | Plain text |
| `clearLabel` | string/translated default | Plain text |

Changing the saved facet does not change global Facets settings or trigger a
rebuild. A now-ineligible saved facet stays in content and renders an editor
warning but no broken storefront control, allowing it to recover after a
rebuild/setting change.

No new persistent plugin option or Redis field is introduced.

## 📝 API Contracts

### Blocks

- `shift64-woo-search/product-filters`
- `shift64-woo-search/filter-pill`

The parent provides context:

- `shift64WooSearch/filterInstanceId`;
- normalized selected facets;
- target Product Collection query ID when discoverable; and
- presentation breakpoint/state identifiers.

The child uses this context and cannot render as a standalone query controller.

### Canonical query parameters

- `filter_{taxonomy}=slug-a,slug-b`
- `query_type_{taxonomy}=and|or` when applicable

Taxonomy keys and slugs are registry/term validated. Clearing one Pill removes
only its two parameters. Clear All removes only filter parameters represented
by children in that Product Filters instance; it preserves search, orderby,
and unrelated safe query parameters.

### Editor REST response

```json
{
  "facets": [
    {
      "key": "pa_material",
      "taxonomy": "pa_material",
      "type": "attribute",
      "label": "Material",
      "operators": ["or", "and"],
      "status": "ready"
    }
  ],
  "settingsUrl": "...",
  "rebuildRequired": false
}
```

Only fixed fields are returned; URLs use WordPress REST/admin URL helpers.

## 📝 UI/UX

### Site Editor

- Inserting Product Filters reveals a constrained child appender.
- Each Pill inspector starts with a **Facet** selector, followed by only the
  settings applicable to that facet.
- Ready facets are selectable. Disabled or rebuild-pending facets are grouped
  separately with the reason they cannot yet be used.
- If none are ready, show:
  **Enable facets in Shift64 Results → Facets, rebuild the index, then return
  here**, with a settings link. No styling controls are redirected to admin.
- Editor preview uses real taxonomy labels plus deterministic sample counts;
  it does not need a product-archive request.
- Parent and each child expose their respective Global Styles/design tools.

### Storefront

- Pills wrap according to the parent layout and summarize selection count or
  selected label without changing their accessible name.
- Desktop panels and narrow trays share the same choices and draft/apply
  behavior.
- Only one surface in a Product Filters parent is open at a time.
- Apply produces one navigation/history entry. Clear and Clear All behave the
  same way.
- A selected zero-count term stays visible and removable.
- Loading/fallback state never removes the Product Collection itself.

## 📝 Edge Cases & Failure Scenarios

- **No compatible Product Collection:** editor shows setup guidance; frontend
  form links still navigate, but no Shift64 counts are claimed.
- **Facet disabled after template save:** child renders an editor warning and
  no storefront control. Its serialized configuration is retained.
- **Rebuild running/stale generation:** do not expose stale buckets as ready;
  show rebuild-required in editor and omit the storefront Pill.
- **Taxonomy/term deleted:** drop invalid request values and omit missing
  options; never preserve an unsafe raw slug.
- **Direct URL filters an unrepresented ready facet:** query honors it, but
  Clear All in this block does not erase invisible state.
- **Direct URL uses disabled/non-indexed facet:** drop it from Shift64 state
  and native-fallback rather than building a Redis field expression that
  cannot exist.
- **Counts query fails while result membership succeeds:** render options
  without counts and log degraded facets; applying filters remains possible.
- **Redis unavailable:** Product Collection uses native fallback; Redis-only
  Pill controls are disabled/omitted with progressive navigation intact.
- **Multiple Product Filters parents:** isolate open/draft state and clear only
  each parent's children; all reflect the same applied canonical URL after
  navigation.
- **Very high-cardinality attribute:** `maxOptions` bounds rendering; this
  phase does not add term search or virtual scrolling.
- **Viewport changes while open:** close and restore focus before changing
  disclosure/dialog behavior to avoid stranded focus.

## 📝 Risks & Impact Review

- **Query cost:** disjunctive counts can require extra Redis aggregation. Batch
  by request and cache with the result envelope; never query once per rendered
  term.
- **Private API risk avoided:** no WooCommerce private state store is imported.
  Canonical URL parameters and public WordPress router APIs are the boundary.
- **Compatibility:** this spec supersedes the 2026-07-21 filter-bar proposal.
  Removing its already-existing classic implementation waits for the legacy
  cleanup spec.
- **Accessibility:** desktop disclosure and narrow modal-tray behavior need
  keyboard, focus, and reduced-motion browser tests.
- **Rollback:** removing these blocks returns the template to the unchanged
  Product Collection. Settings/index data remain intact.
- **Security:** editor REST is capability-gated; storefront parameters use
  registry/term allowlists and output escaping.

## 📋 Phasing

- **Phase 1 — facet registry and block composition.** Merchants can insert and
  configure real ready facets; server rendering and no-JS URLs work.
- **Phase 2 — Redis counts and interactive pill surfaces.** Counts, desktop
  disclosure, narrow tray, and canonical router navigation ship together.
- **Phase 3 — shared visual-contract hardening.** Publish and test the reusable
  pill primitive without importing Product Sort or removing legacy output.
  Product Sort consumption and legacy deletion remain in their owning specs.

## 📋 Implementation Plan

### Phase 1

1. Add the pure Facet Registry with readiness states derived from current
   settings, schema, rebuild generation, and taxonomy existence. PHPUnit
   covers category, native brand, attributes, and every unavailable state.
2. Add the capability-gated editor facets REST route and schema tests,
   including no data leakage for unauthorized requests.
3. Register Product Filters and Filter Pill metadata, parent/child constraints,
   attributes, standard supports, editor preview, and setup link. Add editor
   e2e coverage for add/remove/reorder/configure and saved-content reload.
4. Implement dynamic progressive form/link rendering from canonical URL state.
   PHPUnit covers escaping, invalid saved facets, term deletion, and Clear
   semantics.

### Phase 2

5. Extend the shared Redis query result service with batched disjunctive facet
   envelopes and degraded-count status. Cover combinations of search,
   category, brand, attributes, AND/OR, visibility, sorting, and pagination.
6. Implement the Product Filters Interactivity API store and shared
   trigger/panel primitive with one-open-at-a-time, draft/apply/clear, abort,
   focus restoration, and responsive presentation.
7. Connect canonical actions to the Product Collection router and add
   Playwright coverage for one navigation/history entry, Back, page reset,
   direct URL hydration, no-JS fallback, backdrop stacking, and Redis failure.

### Phase 3

8. Extract and document stable style selectors/action helpers as an internal
   shared primitive. Add a visual fixture that downstream controls can reuse
   to prove support/state-selector parity without coupling stores.
9. Update merchant docs for Product Filters and add forward links to the
   Product Sort and legacy-removal specs. Flip this spec/index status in its
   implementation PR.
