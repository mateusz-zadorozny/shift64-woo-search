# Product Filters and Filter Pill Blocks

Shift64 provides a Site Editor-placed **Product Filters** container whose
repeatable **Filter Pill** children each expose one Redis facet as a shopper
control. The blocks live beside an inherited WooCommerce Product Collection
(see [Block Theme Product Collection Integration](block-theme-product-collection.md))
and communicate exclusively through canonical URL parameters —
`filter_{taxonomy}` and `query_type_{taxonomy}`.

```text
Archive template
├── Product Filters (Shift64)
│   ├── Filter Pill: Category
│   ├── Filter Pill: Brand
│   └── Filter Pill: Material
└── WooCommerce Product Collection (inherited)
```

## Merchant workflow

1. Enable the facets you want under **Shift64 → Results → Facets** and rebuild
   the index when the screen says a rebuild is required.
2. In the Site Editor, add **Product Filters (Shift64)** to a product search,
   Shop, or enabled taxonomy archive template — the same template as the
   inherited Product Collection.
3. Add one **Filter Pill** per facet. Each pill's inspector offers only ready
   facets; disabled or rebuild-pending facets are listed with the reason they
   cannot be used yet.
4. Style the parent (layout, wrapping, gap) and each pill (colors, typography,
   spacing, border) with the normal block design tools. There are no plugin
   appearance settings in WP Admin.

A pill whose saved facet later becomes ineligible (setting disabled, index
rebuilt without it, taxonomy removed) keeps its configuration, shows an editor
warning, and simply does not render on the storefront until the facet is ready
again.

## Behavior contract

- Options and counts come from the same Redis result set as the Product
  Collection; counts are disjunctive (a facet's own selection never collapses
  its own list). When counts cannot be computed, options render without
  counts and filtering keeps working.
- Applying or clearing filters navigates to a canonical URL through the
  WordPress Interactivity router next to the Product Collection's own router
  region, producing exactly one history entry and resetting pagination.
- Without JavaScript, each pill is a native `<details>` disclosure with a
  plain GET form that navigates to the same canonical URL.
- Clear removes only that pill's two parameters; Clear All removes only the
  parameters represented by pills in that Product Filters instance, always
  preserving search, sorting, and unrelated query parameters.

## The shared pill primitive

The pill trigger/panel is a documented markup/style/action contract — not a
public block — that other Shift64 controls reuse. The Product Sort block
(spec: `.ai/specs/2026-07-29-native-woocommerce-catalog-sorting.md`) consumes
this primitive with radio choices; it must not import the Product Filters
interactivity store.

Sources:

- Styles: `src/blocks/shared/pill-primitive.scss` (imported by the Filter
  Pill's stylesheet; import the same partial from new consumers).
- Action helpers: `src/interactivity/filters/helpers.js` — pure
  `selectionFromInputs`, `selectionChanges`, `clearAllChanges`,
  `trapTabIndex` — plus the shared catalog navigation utility
  `frontend/js/shift64-woo-search-catalog-navigation.js`
  (`buildCatalogUrl`, `navigate`).
- Visual fixture: `src/blocks/shared/pill-primitive-fixture.html` renders the
  primitive's states against the built stylesheet without WordPress;
  `src/blocks/shared/pill-primitive.test.js` asserts selector parity between
  the stylesheet, the fixture, and this document.

### Stable selectors

Treat renames as breaking changes for every consumer:

| Selector | Role |
| --- | --- |
| `.shift64-woo-search-pill` | Primitive root (one pill) |
| `.shift64-woo-search-pill__disclosure` | Native `<details>` disclosure |
| `.shift64-woo-search-pill__trigger` | `<summary>` pill trigger |
| `.shift64-woo-search-pill__label` | Trigger label |
| `.shift64-woo-search-pill__summary-count` | Selected-count chip on the trigger |
| `.shift64-woo-search-pill__chevron` | Expanded-state chevron |
| `.shift64-woo-search-pill__panel` | Option panel (desktop popover / narrow tray) |
| `.shift64-woo-search-pill__heading` | Panel heading |
| `.shift64-woo-search-pill__form` | Progressive GET form |
| `.shift64-woo-search-pill__options` | Option list |
| `.shift64-woo-search-pill__option` | One option row (native checkbox/radio) |
| `.shift64-woo-search-pill__option-label` | Option label text |
| `.shift64-woo-search-pill__count` | Option result count |
| `.shift64-woo-search-pill__actions` | Apply/Clear action row |
| `.shift64-woo-search-pill__apply` | Apply submit button |
| `.shift64-woo-search-pill__clear` | Clear link/button |
| `.shift64-woo-search-product-filters__clear-all` | Parent-owned Clear all control |
| `.shift64-woo-search-product-filters__backdrop` | Parent-owned narrow-screen backdrop |

### Stacking tokens

Custom properties guarantee the backdrop stacks above page controls and the
tray above the backdrop:

- `--s64ws-pill-panel-z` (default 30) — desktop popover panel.
- `--s64ws-pill-backdrop-z` (default 40) — narrow-screen backdrop.
- `--s64ws-pill-tray-z` (default 50) — narrow-screen tray.

### Interaction contract

- One surface open at a time per Product Filters parent; multiple parents on
  one page stay isolated.
- `Escape` closes the open surface and returns focus to its trigger; the
  narrow-screen tray contains Tab focus; a viewport change across the tray
  breakpoint (782px) closes open surfaces before the presentation swaps.
- Apply reads the checked inputs (the draft state), builds a canonical URL,
  and upgrades navigation to the router only when a compatible Product
  Collection region is present — otherwise it falls back to a full page
  navigation.

## Related specs

- Source spec: `.ai/specs/2026-07-30-product-filter-pill-blocks.md`
- Product Sort (primitive consumer): `.ai/specs/2026-07-29-native-woocommerce-catalog-sorting.md`
- Legacy filter bar removal: `.ai/specs/2026-07-30-block-theme-only-legacy-removal.md`
