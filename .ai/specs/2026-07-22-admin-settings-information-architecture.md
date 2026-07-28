# Admin Settings Information Architecture

> **Status:** implemented — PR #33, 2026-07-28.

## TLDR

Replace the current twelve equal admin tabs with six task-oriented workspaces and secondary sections. Add fixed canonical routes with legacy aliases, relocate existing admin surfaces, and preserve stored options, AJAX contracts, tool behavior, and storefront behavior. This is an information-architecture migration, not a redesign of the controls themselves or an implementation of planned filter-bar, relevance-profile, display, or managed-service features.

## Problem Statement

The current admin page presents merchant settings, curated content, diagnostics, analytics, and infrastructure as twelve equal top-level tabs. Related controls are split across several tabs, technical screens compete with common tasks, the default landing page is a query debugger, and planned features would add a broad thirteenth “Display” tab instead of resolving the underlying organization.

Examples of the resulting ambiguity:

- “Category / Tag Boost Rules” affects product ranking, while the separate “Category Boost” tab affects category suggestions.
- Category-suggestion fuzzy matching, pins, boosts, and exclusions live in different tabs.
- Search, archive, autocomplete, frontend integration, and relevance controls share one large Search form or adjacent technical tabs.
- Test Search and Tuning are tools, Statistics is an insight surface, Index is an operation surface, and Redis is infrastructure, but all look like ordinary settings destinations.
- The legacy Synonyms destination is blank because dynamic routing looks for `render_synonyms_tab()` while the existing renderer is named `render_synonys64ws_tab()`.

The plugin needs stable information architecture before new settings are added. The migration must remain safe for existing stores and must not change what shoppers receive.

## Goals

- Reduce primary navigation from twelve destinations to six durable workspaces.
- Give every existing control, content manager, tool, and status surface one canonical owner.
- Preserve every existing `shift64_woo_search_*` option key, stored value, type/shape, and default.
- Preserve the menu slug, capability, admin asset contract, all existing AJAX actions, and storefront/SHORTINIT behavior.
- Add explicit canonical `tab` + `section` routes and keep the twelve legacy tab URLs working.
- Make section-scoped form saves safe: saving one relocated section must not overwrite options owned by another section.
- Keep implementation incremental and rollback code-only.
- Reserve clear information-architecture destinations for planned features without defining or implementing their behavior here.

## Non-Goals

- Adding new merchant controls or new persistent options.
- Implementing filter-bar blocks, shortcodes, placement modes, hook selection, or vertical layout.
- Implementing autocomplete SKU/category/brand visibility settings or WooCommerce grid-card metadata.
- Introducing versioned relevance profiles or a typed `Search_Config`.
- Adding managed Shift64 onboarding, service keys, billing, or portal operations.
- Changing conditional visibility, control availability, operational copy, or interactions inside existing tools, except where navigation and section-scoped persistence require it.
- Combining Test Search and Tuning into a new interaction; they remain separate secondary sections.
- Hiding, deprecating, fixing, or changing the semantics of `shift64_woo_search_diacritics_normalization`; it is relocated unchanged.
- Replacing raw rule editors with structured builders.
- Replacing the custom AJAX save path with the WordPress Settings API.
- Renaming the misspelled `synonys64ws_*` AJAX actions.
- Building a new admin framework, JavaScript build pipeline, or broad class hierarchy.
- Fixing config/blob refresh error reporting or other pre-existing runtime inconsistencies.

## Research and Design Principles

Two patterns from established WordPress search/facet products support this direction:

- Facet placement belongs to page/block composition, with instance-specific controls on the block and shortcode placement for classic builders. See [FacetWP Blocks](https://facetwp.com/help-center/using-facetwp-with/blocks/) and [FacetWP Shortcodes](https://facetwp.com/help-center/developers/shortcodes-reference/).
- Search configuration, diagnostics/tools, and analytics are distinct operator tasks. See [SearchWP Engines](https://searchwp.com/documentation/setup/engines/), [SearchWP Tools](https://searchwp.com/documentation/setup/tools/), and [SearchWP Metrics](https://searchwp.com/documentation/extensions/metrics/).

Shift64 can stay smaller than either product because it has one WooCommerce product-search engine and one Redis/RediSearch backend. The useful principle is task separation, not copying multiple-engine or generic facet-builder complexity.

Every relocated surface retains its current type:

| Surface type | Meaning | Existing examples |
| --- | --- | --- |
| Setting | Persistent behavior choice | search strategy, enabled facets, Redis host |
| Managed content | Collection edited over time | synonyms, query suggestions, pins, boosts, exclusions |
| Tool/action | Immediate operation | test query, compare passes, rebuild, config regeneration |
| Status/insight | Read-only state/history | index health, connection state, statistics |

The migration changes where these surfaces live, not how they work.

## Proposed Information Architecture

### Primary navigation

```text
Shift64 Woo Search
├── Overview
├── Search Experience
├── Results & Filters
├── Relevance
├── Insights
└── System
```

Future features extend one of these workspaces. Adding a seventh primary destination requires a separate information-architecture decision.

### Workspace and section map

| Workspace | Purpose | Canonical sections |
| --- | --- | --- |
| Overview | Orient the user and link to canonical task destinations | Overview only |
| Search Experience | Search field and autocomplete experience | Search Field, Autocomplete, Query Suggestions, Category Suggestions |
| Results & Filters | Redis-backed listing coverage and shopper facets | Result Coverage, Facets |
| Relevance | Matching, ranking, dictionaries, merchandising, and evaluation tools | Basic Ranking, Matching & Fallback, Synonyms, Merchandising, Field Weights, Test Search, Compare Passes |
| Insights | Search usage and gaps | Search Statistics |
| System | Backend connection, index operations, traffic protection, and generated config | Connection, Index & Health, Security & Traffic, Diagnostics |

Overview is deliberately small in this spec: a read-only landing page with concise descriptions and canonical links. New aggregated health cards, setup checklists, warning engines, or quick actions are a follow-up capability. Existing status content may be linked or reused unchanged only when doing so does not introduce new queries, state, or behavior.

## Route Contract

The WordPress submenu registration remains unchanged:

- parent: `woocommerce`;
- page slug: `shift64-woo-search`;
- capability: `manage_woocommerce`;
- page hook used for assets: `woocommerce_page_shift64-woo-search`.

Canonical URLs use:

```text
admin.php?page=shift64-woo-search&tab={workspace}&section={section}
```

Canonical workspace slugs:

```text
overview
experience
results
relevance
insights
system
```

Rules:

- Missing or invalid `tab` resolves to `overview`.
- `overview` ignores `section`.
- Every other workspace has a fixed default section.
- Missing or invalid `section` resolves to that workspace’s default.
- A fixed registry maps routes to explicit callbacks. Query input is never concatenated into a method name.
- Canonical links are emitted after migration; legacy aliases remain accepted for at least one minor release and may remain indefinitely.

### Canonical section slugs

| Workspace | Sections | Default |
| --- | --- | --- |
| `experience` | `search-field`, `autocomplete`, `query-suggestions`, `category-suggestions` | `search-field` |
| `results` | `coverage`, `facets` | `coverage` |
| `relevance` | `basic`, `matching`, `synonyms`, `merchandising`, `field-weights`, `test-search`, `compare-passes` | `basic` |
| `insights` | `statistics` | `statistics` |
| `system` | `connection`, `index`, `security`, `diagnostics` | `connection` |

### Legacy route aliases

| Legacy `tab` | Canonical destination |
| --- | --- |
| `test` | `relevance/test-search` |
| `tuning` | `relevance/compare-passes` |
| `synonyms` | `relevance/synonyms` |
| `suggestions` | `experience/query-suggestions` |
| `catboost` | `experience/category-suggestions` |
| `stats` | `insights/statistics` |
| `weights` | `relevance/field-weights` |
| `filters` | `results/facets` |
| `index` | `system/index` |
| `redis` | `system/connection` |
| `search` | `relevance/basic` |
| `frontend` | `experience/search-field` |

The former Search tab maps to Relevance because most of its controls affect matching/ranking. During the alias compatibility window, that destination shows a non-persistent relocation notice with links to Search Experience and Results & Filters so users can find the fields moved out of the old mixed page.

Aliases resolve internally without an HTTP redirect. This preserves bookmarks and avoids redirect loops while internal links migrate to canonical URLs.

## Existing Surface Placement

### Overview

No settings or destructive actions. Render navigation cards/links for the five task workspaces plus brief explanatory copy. Merely visiting Overview must not call `update_option()`, regenerate config, mutate Redis, or start an operation.

### Search Experience

| Section | Existing fields/content |
| --- | --- |
| Search Field | `shift64_woo_search_debounce`, `shift64_woo_search_input_selector`, `shift64_woo_search_additional_selectors`, `shift64_woo_search_button_selector` |
| Autocomplete | `shift64_woo_search_min_query`, `shift64_woo_search_autocomplete_limit`, `shift64_woo_search_category_suggest_fuzzy`, `shift64_woo_search_brand_suggest_enabled` |
| Query Suggestions | Existing suggestion CRUD/import/export backed by `shift64_woo_search_suggestions` |
| Category Suggestions | `shift64_woo_search_category_pin_rules`, existing `shift64_woo_search_category_boosts` editor, and `shift64_woo_search_category_suggest_exclude` editor |

Only section/page headings and navigation context change. Existing field copy, editor behavior, storage, AJAX actions, and Redis blob refreshes remain unchanged.

### Results & Filters

| Section | Existing fields/content |
| --- | --- |
| Result Coverage | `shift64_woo_search_archive_enabled`, `shift64_woo_search_price_sort_mode`, `shift64_woo_search_taxonomy_archive_scopes` |
| Facets | `shift64_woo_search_filter_attributes`, `shift64_woo_search_filter_categories_enabled`, `shift64_woo_search_filter_categories_excluded`, `shift64_woo_search_filter_brands_enabled` |

The Facets section intentionally keeps the four existing filter groups in one specialized form. This preserves the current `shift64_woo_search_save_filters` request model and avoids making its non-partial handler clear fields owned by another page. Runtime and rebuild-required fields may have separate headings on the same section, but they share the current Save action.

### Relevance

| Section | Existing fields/content |
| --- | --- |
| Basic Ranking | `shift64_woo_search_logic`, `shift64_woo_search_strategy`, `shift64_woo_search_outofstock_mode`, `shift64_woo_search_outofstock_demote_factor` |
| Matching & Fallback | `shift64_woo_search_fuzzy_level`, `shift64_woo_search_fallback_trigger`, `shift64_woo_search_fallback_score_threshold`, `shift64_woo_search_fallback_fuzzy_level`, `shift64_woo_search_token_reduction_enabled`, `shift64_woo_search_weak_tokens`, `shift64_woo_search_drop_trailing_weak_token_only`, `shift64_woo_search_diacritics_normalization`, `shift64_woo_search_fuzzy_synonyms`, `shift64_woo_search_full_limit` |
| Synonyms | Existing synonym CRUD/import/export backed by `shift64_woo_search_synonyms` |
| Merchandising | `shift64_woo_search_category_boost_rules` |
| Field Weights | Existing weights sliders, Reset, and Apply & Rebuild workflow backed by `shift64_woo_search_weights` |
| Test Search | Existing Test Search tool unchanged |
| Compare Passes | Existing Tuning tool unchanged |

`shift64_woo_search_full_limit` remains editable but is placed with advanced matching/endpoint behavior rather than Search Experience. This spec does not change its label or semantics.

The canonical Synonyms route and legacy `synonyms` alias explicitly invoke the existing renderer. No synonym storage, cache, import/export, or action-name change is part of this migration.

### Insights

`Insights > Search Statistics` renders the existing Statistics view, date filters, cleanup actions, chart, top searches, and zero-result searches unchanged.

### System

| Section | Existing fields/content |
| --- | --- |
| Connection | Redis host, port, auth, username, password, database, prefix, and existing Test Connection action |
| Index & Health | Existing Redis/index/product/memory status plus rebuild action/progress |
| Security & Traffic | `shift64_woo_search_rate_limit` |
| Diagnostics | Existing generated-config status/timestamp and Regenerate SHORTINIT Config action |

The connection test keeps its current behavior in this spec. Making it ephemeral, changing save/test/config ordering, or improving failure reporting is separate admin-correctness work.

## Architecture

### Fixed route registry

`Shift64_Woo_Search_Admin` remains the page owner. Add one fixed registry that defines:

- ordered workspaces and translated labels;
- ordered sections and translated labels;
- workspace defaults;
- explicit render callbacks;
- legacy aliases.

The same registry drives validation and navigation. Do not maintain parallel PHP switch statements or a JavaScript route list. The registry is internal and is not stored in `wp_options` or exposed as a public API.

Conceptual shape:

```php
array(
    'experience' => array(
        'label'   => __( 'Search Experience', 'shift64-woo-search' ),
        'default' => 'search-field',
        'sections' => array(
            'search-field'      => array( 'label' => ..., 'callback' => ... ),
            'autocomplete'      => array( 'label' => ..., 'callback' => ... ),
            'query-suggestions' => array( 'label' => ..., 'callback' => ... ),
        ),
    ),
)
```

This is illustrative. It does not require extracting a generic admin framework.

### Rendering boundaries

- Primary and secondary navigation are server-rendered links.
- Exactly one canonical section renders per request.
- Existing renderers and field helpers are reused where possible.
- Existing dedicated content/tool AJAX handlers remain attached under their current action names.
- Existing admin CSS/script handles and localized data remain unchanged.
- The admin JavaScript continues to initialize behaviors based on markup presence.
- No rendering path writes options, regenerates config, mutates Redis, or normalizes defaults.

### Section-scoped generic settings saves

The former Search and Redis pages contain fields that move into several section forms. The generic `shift64_woo_search_save_setting` handler already accepts a subset of allowlisted keys, but its global Redis-auth cleanup is not section-safe.

Required persistence rule:

- update only allowlisted keys explicitly present in the submitted `settings` payload;
- clear `shift64_woo_search_redis_username` and `shift64_woo_search_redis_password` only when that same payload explicitly contains `shift64_woo_search_redis_auth_enabled = no`;
- saving Search Experience, Results, Relevance, or Security must never clear connection credentials merely because the auth field is absent;
- preserve the existing response envelope and automatic generated-config call;
- preserve scalar storage behavior: the current AJAX path stores sanitized scalar edits as strings and consumers cast them as needed.

For testability, extract the allowlisted persistence loop and the explicit auth-dependent cleanup into a small pure/testable seam invoked by the AJAX wrapper. This is not a typed configuration refactor.

### Specialized Filters form

Keep all options handled by `shift64_woo_search_save_filters` on the same canonical Facets section and retain the current serializer/request. Do not split attributes and visibility into separate saves in this spec. Correcting the handler’s absent-field semantics and missing-brand checkbox behavior is a follow-up admin-correctness task.

### Known Synonyms routing defect

The old dynamic route does not match the implemented renderer name. The new fixed registry points canonical and legacy Synonyms routes to the actual renderer. Existing misspelled `synonys64ws_*` AJAX action names remain registered and unchanged.

## Data Model and Compatibility

### No migration

- No option is added, renamed, deleted, combined, normalized, or given a new default.
- No stored scalar type or structured shape changes.
- No database schema or `shift64_woo_search_db_version` change.
- No Redis key, index, field, or blob-shape change.
- No generated config constant change.
- No endpoint, frontend localized config, CLI, shortcode, or block contract change.
- Rollback is a code revert; the old UI reads the same data.

Structured values remain exactly as they are today:

| Option/content | Preserved shape |
| --- | --- |
| `shift64_woo_search_weights` | associative field → integer map |
| `shift64_woo_search_filter_attributes` | list of taxonomy strings |
| `shift64_woo_search_taxonomy_archive_scopes` | list of taxonomy strings |
| Category facet/suggestion exclusions | lists of term IDs |
| `shift64_woo_search_category_boosts` | term ID → float map |
| Synonyms and query suggestions | existing list formats |

Merely visiting any canonical or legacy route must produce zero writes. Do not auto-save, migrate, or normalize a value during rendering.

### Preserved admin contracts

- `page=shift64-woo-search` and `manage_woocommerce`.
- `shift64-woo-search-admin` style/script handles.
- Localized object `shift64_woo_search_admin` and its `ajax_url`, `nonce`, `default_weights`, and `current_weights` keys.
- All currently registered `wp_ajax_shift64_woo_search_*` actions, including misspelled synonym actions.
- Nonce action/field `shift64_woo_search_admin` / `nonce`.
- Existing request field names and `data.message` success/error convention.
- Existing import/export formats and index/rebuild progress responses.

### Sensitive data

Redis credentials keep their existing storage and generated-config flow. No credential may be copied into Overview content, URLs, navigation labels, data attributes, logs, or tests. Password inputs stay masked. The payload-presence guard prevents unrelated section saves from clearing credentials.

## UI/UX Contract

- Overview is the default landing page.
- Six primary navigation links render in the documented order.
- Workspaces with multiple sections render a secondary link navigation directly below the primary navigation.
- Navigation works without JavaScript and supports refresh, bookmarks, back/forward, and open-in-new-tab.
- The active primary and secondary link use `aria-current="page"` and a visible active state.
- Primary and secondary `nav` elements have distinct accessible labels.
- Heading order is plugin `h1`, canonical section `h2`, then existing content headings.
- Existing fields, buttons, tables, dialogs, confirmations, progress UI, and control visibility remain unchanged after relocation.
- One option has one editable canonical location; Overview contains links, not duplicate “quick settings.”
- No empty placeholder section is rendered for a future capability.
- Primary and secondary navigation remain usable at WordPress admin breakpoints and 200% zoom.

## Future Destination Map

These rows reserve information-architecture destinations only. Behavior, state, storage, precedence, and API contracts belong to each feature’s owning specification.

| Planned capability | Reserved destination |
| --- | --- |
| Filter-bar placement and automatic layout defaults | Results & Filters → future Filter Bar Placement section |
| Per-block/per-shortcode filter layout | Block inspector / shortcode attributes, not global admin settings |
| Autocomplete product-row metadata toggles | Search Experience → Autocomplete |
| Versioned relevance profiles and overrides | Relevance → Basic Ranking / Matching & Fallback |
| Brand suggestion boost/pin parity | Search Experience → Category/Brand Suggestions |
| BYOR versus Managed connection mode | System → Connection |
| Managed usage and service status | Insights or System according to the owning service spec |

The existing `2026-07-21-filter-bar-gutenberg-block-and-placement-modes.md` must be aligned separately before implementation. This spec neither edits nor implements that feature.

## Edge Cases and Failure Scenarios

| Scenario | Required behavior |
| --- | --- |
| Missing/invalid `tab` | Render Overview without PHP warnings |
| Valid workspace with missing/invalid `section` | Render the workspace default |
| Array/path/HTML/private-method route input | Treat as invalid; never invoke an input-derived method |
| Legacy bookmarked tab | Resolve to the documented canonical section |
| Legacy `tab=search` | Render Relevance Basic plus relocation links to Experience and Results |
| Synonyms canonical/legacy route | Render the existing manager rather than a blank content area |
| User lacks `manage_woocommerce` | Existing WordPress page/AJAX access denial remains |
| Redis unavailable/unconfigured | Relocated Connection and Index screens render their current degraded states |
| `product_brand` absent | Relocated brand controls keep their current disabled/omitted behavior |
| Stored option has an unusual legacy value | Rendering does not normalize or overwrite it |
| Saving a non-Connection generic section | Does not clear Redis username/password because auth is absent |
| Saving Connection with auth explicitly disabled | Keeps the existing intentional credential-clearing behavior |
| JavaScript unavailable | Navigation and displayed values remain readable; AJAX-only operations do not corrupt state |
| Narrow admin viewport/zoom | Every primary and secondary destination remains reachable |

Pre-existing config/blob error reporting and connection-test ordering remain documented limitations, not acceptance criteria of this migration.

## Risks and Impact Review

### Risk: split forms overwrite unrelated options

The generic handler is subset-based, but its credential cleanup currently runs outside payload presence. The explicit auth-presence guard is required before or together with the first split generic form. The specialized Filters form stays intact to avoid expanding this risk.

### Risk: route aliases hide incorrect callbacks

Use a fixed registry and alias map with explicit callbacks. Tests cover every canonical route, all twelve aliases, and hostile/invalid values. Dynamic `render_{$tab}_tab` dispatch is removed.

### Risk: old Search bookmarks conceal relocated fields

Map the old mixed page deterministically to Relevance Basic and show a temporary relocation notice with canonical links to the other owners.

### Risk: technical users lose familiar destinations

All functionality remains reachable; legacy URLs work; option keys and data remain unchanged. Maintained documentation and internal links are updated in the same delivery.

### Blast radius

Direct changes are limited to the WooCommerce admin submenu page, its admin CSS/JS, internal admin links, translations, compatibility documentation, and tests. Storefront rendering, SHORTINIT responses, Redis data/indexing, ranking, CLI, blocks, shortcodes, and statistics storage do not change.

### Rollback

Rollback is a code revert. The old twelve tabs read the same options and use the same AJAX actions. No data rollback, index rebuild, Redis cleanup, database migration, or generated-config migration is required.

## Acceptance Criteria

### Navigation and routing

- Exactly six ordered primary workspaces render.
- All nineteen canonical destinations resolve to explicit callbacks and correct headings.
- All twelve legacy tabs resolve to their documented destinations.
- Missing and hostile route values fail safe without warnings or arbitrary method invocation.
- Overview is the default and writes no state.
- Primary/secondary navigation has correct active and accessible state.

### Surface completeness

- Every existing tab’s fields, content manager, status, or tool is reachable in exactly one canonical location.
- Test Search and Compare Passes remain independent, behavior-identical tools.
- Synonyms renders from canonical and legacy routes while existing AJAX action spellings remain unchanged.
- No planned filter-bar, display, profile, or managed-service control is rendered or stored.

### Settings and compatibility

- Visiting every canonical and legacy route leaves a before/after snapshot of all `shift64_woo_search_*` options unchanged.
- Saving each generic section updates only the explicitly submitted allowlisted keys.
- Saving a non-Connection section cannot clear Redis credentials.
- Explicitly saving Redis auth as disabled continues to clear stored credentials.
- The specialized Facets form round-trips its four option groups exactly as before.
- Option keys, defaults, structured shapes, scalar storage behavior, AJAX actions, nonce/capability checks, response envelopes, asset handles, and localized admin data remain compatible.
- No new option, DB version, Redis key, generated constant, or storefront config key appears.

### Quality gate

- `composer validate --strict` passes.
- `vendor/bin/phpcs` passes.
- `vendor/bin/phpunit` passes.
- `node --check admin/js/shift64-woo-search-admin.js` passes.
- `composer makepot` includes intended new navigation/section strings.
- Browser QA passes for canonical/legacy/invalid routes, section saves, Synonyms, tools, Redis healthy/unavailable states, missing `product_brand`, keyboard navigation, 200% zoom, and narrow admin widths.

## Phasing

### Phase 1: Fixed routing and navigation shell

Add the registry/resolver, six primary workspaces, secondary navigation, link-only Overview, fixed legacy aliases, and route tests. Canonical destinations may initially delegate directly to existing renderers so nothing disappears.

### Phase 2: Relocate merchant-facing sections

Move Search Experience, Results & Filters, and Relevance settings/content into their canonical sections. Add the explicit Redis-auth payload guard required for safe generic partial saves. Keep the specialized Facets form intact.

### Phase 3: Relocate tools, insights, and system surfaces

Move Test Search, Tuning, Statistics, Connection, Index, Rate Limiting, and generated-config diagnostics without changing their behavior or AJAX contracts. Update the dashboard Statistics link and other internal links.

### Phase 4: Compatibility, accessibility, and QA

Update maintained documentation and `BACKWARD_COMPATIBILITY.md`, generate translations, finish responsive/accessibility styling, run automated validation, and perform browser QA.

## Implementation Plan

1. **Create a pure route registry/resolver.** Define six ordered workspaces, nineteen canonical destinations, defaults, explicit callbacks, and twelve legacy aliases. Add PHPUnit data-provider coverage for canonical, legacy, missing, malformed, hostile, and unknown inputs. No page output changes yet.
2. **Add the navigation shell and link-only Overview.** Render canonical primary/secondary navigation from the registry, switch the default from Test Search to Overview, and add a read-only destination guide. Test heading/active-link markup and prove rendering does not mutate options.
3. **Make generic partial saves credential-safe.** Extract the allowlisted persistence seam from `ajax_save_setting()` and clear Redis credentials only when the submitted `settings` payload explicitly disables auth. Add sentinel-option tests for subset saves, checkbox `no`, textarea, arrays, unrelated credentials, and explicit auth disable; keep the AJAX wrapper/envelope unchanged.
4. **Relocate Search Experience.** Render Search Field, Autocomplete, Query Suggestions, and Category Suggestions at their canonical routes using existing helpers/managers/actions. Verify each generic form submits only its owned keys and content actions retain current cache behavior.
5. **Relocate Results & Filters.** Move coverage fields into their generic section. Move the complete existing Filters form into one Facets section without splitting its save contract. Verify empty attributes, category/brand toggles, exclusions, scopes, and missing taxonomy behavior match current behavior.
6. **Relocate Relevance.** Move Basic Ranking, Matching & Fallback, Synonyms, Merchandising, Field Weights, Test Search, and Compare Passes. Point Synonyms routes explicitly at the existing renderer and test for `s64ws-syn-table`. Preserve all control visibility, raw editors, tool payloads, product actions, and rebuild behavior.
7. **Relocate Insights and System.** Move Statistics unchanged. Split the former Redis page into Connection, Security & Traffic, and Diagnostics; move Index & Health unchanged. Preserve Test Connection, config regeneration, cleanup confirmations, rebuild progress, and every existing AJAX action/response.
8. **Update links and compatibility docs.** Replace internal legacy links with canonical routes, add the temporary old-Search relocation notice, document the alias window and no-data-migration guarantee, and update maintained docs that name old tabs. Do not edit the filter-bar feature contract in this implementation.
9. **Add focused automated coverage.** Create `tests/test-admin-settings-information-architecture.php` for registry order, explicit callbacks, defaults, aliases, invalid inputs, single ownership, render-without-write, Synonyms regression, partial-save sentinels, and credential absence from Overview. Avoid requiring real WooCommerce/Redis in the unit bootstrap; cover heavy screens by registry tests and browser QA.
10. **Validate and QA.** Run `composer validate --strict`, `vendor/bin/phpcs`, `vendor/bin/phpunit`, `node --check admin/js/shift64-woo-search-admin.js`, and `composer makepot`. In the configured browser environment, visit all nineteen canonical routes, all twelve aliases, invalid routes, representative section saves/actions, Redis degraded states, missing brand taxonomy, keyboard navigation, 200% zoom, and narrow viewports; capture a concise QA report and screenshots.
