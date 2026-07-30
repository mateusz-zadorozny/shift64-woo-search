# Filter Bar Gutenberg Block, Flexible Hooks & Result Display Settings

> **Status:** superseded — see `2026-07-30-product-filter-pill-blocks.md`

## TLDR
Introduce a Gutenberg Block (`shift64-woo-search/filter-bar`) and Shortcode (`[shift64_woo_search_filters]`) for placing faceted search filters anywhere on WooCommerce shop and archive pages. Add flexible placement options (Default Hook `woocommerce_before_shop_loop`, alternate hook selector, or manual block/shortcode mode) and a dedicated "Display / Wygląd" WP Admin tab to easily manage filter placement and product card result meta toggles (SKU, Category, Brand).

## Superseded direction

This proposal is retained for history but must not be implemented. The plugin
now targets modern block themes only: merchants compose
`shift64-woo-search/product-filters` from repeatable Filter Pill children in
the Site Editor, appearance comes from block supports, and WooCommerce Product
Collection owns the result grid and pagination. Hooks, shortcodes, theme
placement settings, and an admin Display/appearance tab are explicitly out of
scope. See:

- `2026-07-30-block-theme-product-collection-integration.md`;
- `2026-07-30-product-filter-pill-blocks.md`; and
- `2026-07-30-block-theme-only-legacy-removal.md`.

## Problem Statement
Currently, faceted search filters output automatically via a single hardcoded action hook (`woocommerce_before_shop_loop`). Merchants using Block Themes (FSE), custom page builders (Elementor, Divi), or custom shop layouts cannot position filters in a sidebar, custom header, or alternate hook position without editing PHP code. Furthermore, managing product card result meta lines (SKU, Category, Brand) lacks a unified, easy-to-use Admin setting tab.

## Proposed Solution
1. **Gutenberg Block (`shift64-woo-search/filter-bar`)**:
   - Standard block registered via `register_block_type()` using a dynamic PHP render callback.
   - Inspector controls allowing merchants to choose between `Horizontal` (Pill dropdown bar) and `Vertical` (Sidebar accordion list) layouts.
2. **Shortcode (`[shift64_woo_search_filters]`)**:
   - Shortcode renderer accepting `layout="horizontal|vertical"` for classic themes and page builders.
3. **Flexible Placement System**:
   - `shift64_woo_search_filter_placement_mode`: `auto` (hook-based) vs `manual` (block/shortcode only).
   - `shift64_woo_search_filter_placement_hook`: Target action hook (defaults to `woocommerce_before_shop_loop`, with presets for `woocommerce_archive_description`, `woocommerce_before_main_content`, or custom hook).
4. **Admin "Display / Wygląd" Tab**:
   - Dedicated tab in Admin settings unifying filter placement settings and product result card meta line controls (toggles for SKU, Category, Brand).

## Architecture

```text
[ Admin "Display" Tab ] ---> options in wp_options
                                 |
                                 +---> shift64_woo_search_filter_placement_mode (auto/manual)
                                 +---> shift64_woo_search_filter_placement_hook
                                 +---> shift64_woo_search_card_show_*
                                 |
[ Storefront Archive ] <--------+
       |
       +---> Auto Mode: Hook listener attached to target hook
       +---> Manual Mode: Gutenberg Block / Shortcode rendered dynamically
```

### Components
- `includes/class-shift64-woo-search-block-filter-bar.php`: Handles block registration, shortcode registration, and dynamic rendering.
- `admin/class-shift64-woo-search-admin.php`: Registers the new "Display" settings tab and AJAX save handlers.
- `frontend/class-shift64-woo-search-filters.php`: Binds filter bar rendering to the configured action hook dynamically.
- `frontend/css/shift64-woo-search.css`: Supplies styling for both horizontal pill dropdowns and vertical sidebar accordion layouts.

## Data Model & Options
- `shift64_woo_search_filter_placement_mode` (`auto` | `manual`, default: `auto`)
- `shift64_woo_search_filter_placement_hook` (`woocommerce_before_shop_loop` | custom string, default: `woocommerce_before_shop_loop`)
- `shift64_woo_search_card_show_sku` (`yes` | `no`, default: `yes`)
- `shift64_woo_search_card_show_category` (`yes` | `no`, default: `yes`)
- `shift64_woo_search_card_show_brand` (`yes` | `no`, default: `yes`)

## API Contracts & Block Schema

### Block Definition (`src/blocks/filter-bar/block.json`)
```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "shift64-woo-search/filter-bar",
  "title": "Shift64 Woo Search Filters",
  "category": "woocommerce",
  "icon": "filter",
  "description": "Display Shift64 faceted product filters in horizontal pills or vertical sidebar accordion layout.",
  "attributes": {
    "layout": {
      "type": "string",
      "default": "horizontal"
    }
  },
  "supports": {
    "html": false,
    "align": ["wide", "full"]
  },
  "textdomain": "shift64-woo-search"
}
```

### Shortcode
`[shift64_woo_search_filters layout="horizontal|vertical"]`

## UI / UX

### Admin "Display / Wygląd" Tab
- **Filter Placement Section**:
  - Radio toggle: `Automatic (Hook)` vs `Manual (Block / Shortcode)`.
  - Hook dropdown (when Auto selected):
    - `woocommerce_before_shop_loop` (Default - Before product loop)
    - `woocommerce_archive_description` (Above archive description)
    - `woocommerce_before_main_content` (Above main shop wrapper)
    - `Custom Hook...` (Text input field for theme-specific hooks)
- **Product Card Result Meta Section**:
  - Checkbox list for autocomplete & grid result cards:
    - `[x] Show SKU`
    - `[x] Show Category`
    - `[x] Show Brand`

### Gutenberg Block Inspector
- Layout Control: Toggle / Dropdown between `Horizontal (Pill Bar)` and `Vertical (Sidebar Accordion)`.

## Edge Cases & Failure Scenarios
1. **Manual Mode with No Block Inserted**: Filters will not appear on the frontend. A helpful notification notice is rendered in the Admin "Display" tab explaining where to add the block/shortcode.
2. **Invalid Custom Hook**: If an invalid or unexecuted hook name is entered in Auto mode, filters fail silently without breaking the shop layout.
3. **Multiple Blocks on Same Page**: PHP renderer enforces the single-render guard (`$has_rendered`) per request unless inside the block editor canvas.

## Risks & Impact Review
- **Backward Compatibility**: Existing stores default to `placement_mode = auto` and `hook = woocommerce_before_shop_loop`, preserving 100% existing behavior without requiring manual migration.
- **Performance**: Zero additional DB queries; settings are cached in `wp_options` and exported to `config.php` for SHORTINIT endpoint compatibility.

## Phasing & Implementation Plan

### Phase 1: Admin "Display" Tab & Configurable Hooks
- **Step 1**: Add `'display'` tab to `admin/class-shift64-woo-search-admin.php`, register options (`placement_mode`, `placement_hook`, `card_show_*`), and update JS settings save handler.
- **Step 2**: Update `frontend/class-shift64-woo-search-filters.php` to dynamically register the filter bar output on the configured action hook instead of hardcoding `woocommerce_before_shop_loop`.

### Phase 2: Gutenberg Block & Shortcode Implementation
- **Step 3**: Create `includes/class-shift64-woo-search-block-filter-bar.php` and register shortcode `[shift64_woo_search_filters]`.
- **Step 4**: Register Gutenberg block `shift64-woo-search/filter-bar` with Block Inspector controls (`layout: horizontal|vertical`) and dynamic PHP render callback.

### Phase 3: Vertical Sidebar Layout & AJAX Verification
- **Step 5**: Add vertical sidebar accordion layout CSS to `frontend/css/shift64-woo-search.css`.
- **Step 6**: Verify AJAX DOM node updates (`frontend/js/shift64-woo-search-ajax-pagination.js`), automated `PHPUnit` / `PHPCS` test suite, and `agent-browser` visual QA across block themes.
