# 50k Demo Product Generator Scaling

> **Status:** draft

## TLDR

Scale `bin/generate-demo-products.php` from a 1,000-product apparel generator to a high-volume (up to 100,000 product) multi-category catalog generator. Expand name combinatorics, SKU patterns, brand distribution, and attribute depth to ensure high data diversity for Redis search benchmarking while optimizing memory consumption via batched garbage collection, deferred WordPress term/comment counting, and unhooked cache invalidation.

## Problem Statement

The existing demo product generator ([generate-demo-products.php](file:///Users/mateuszzadorozny/LocalSites/block-theme-testing/app/public/wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php)) is limited by:

1. **Count Constraint:** Hardcoded validation restricting product count to `1 <= count <= 1000`.
2. **Low Combinatorial Range:** Product names rely on a simple tuple of `[Mythology] + [Garment] + [Color]` (~4,620 total combinations), causing collision and duplicate names when generating larger catalogs.
3. **Domain Limitation:** Products are restricted to the Apparel category hierarchy (`Tops`, `Bottoms`, `Outerwear`, `Dresses`).
4. **Memory & I/O Overhead:** Creating tens of thousands of WooCommerce products using standard ORM (`WC_Product::save()`) without batch cache flushing or term-count deferral causes memory exhaustion (`Fatal error: Allowed memory size of X bytes exhausted`) and exponential slowdown.

## Proposed Solution

1. **Multi-Vertical Catalog Architecture:** Expand category and brand generation across 4 distinct catalog verticals: `apparel`, `tech`, `home`, and `beauty` (or `all` for a balanced mix).
2. **Multi-Segment Combinatorial Engine:** Implement 5-token dynamic name builders (`[Prefix/Series] + [Brand/Vendor] + [Product Type] + [Spec/Variant] + [Color/Finish]`) yielding >5,000,000 unique product name combinations.
3. **Structured SKU & Attribute Generator:** Introduce multi-segment deterministic SKUs (`[CAT]-[VERTICAL]-[ID]-[VAR]`) and expanded global attributes (e.g. *Color, Size, Material, Storage, Capacity, Connection, Warranty*).
4. **Batch Memory & DB Deferral Strategy:**
   - Execute `wp_cache_flush()` and `gc_collect_cycles()` every `$batch` items (default: `1000`).
   - Wrap creation in `wp_defer_term_counts( true )` and `wp_defer_comment_counting( true )` to defer heavy taxonomy recalculations until full batch completion.
5. **Updated CLI Options:** Support `--count=N` (up to 100,000), `--catalog=all|apparel|tech|home|beauty`, `--batch=N`, and default to `--mode=mixed`.

---

## Architecture

```text
WP-CLI command
   │
   ▼
shift64_woo_search_parse_demo_product_args()
   │  (validates count <= 100000, batch >= 50, catalog mode, seed, reset)
   ▼
Shift64_Woo_Search_Demo_Product_Generator->run()
   │
   ├── wp_defer_term_counts(true) & wp_defer_comment_counting(true)
   ├── Taxonomy Initialization (Categories, Brands, Global Attributes)
   │
   ├── Loop (index = 0 .. count - 1)
   │     ├─ Combinatorial Tuple Builder (Multi-vertical generator)
   │     ├─ Product Creation (WC_Product_Simple / WC_Product_Variable)
   │     └─ Batch Check (every N items):
   │           wp_cache_flush()
   │           gc_collect_cycles()
   │
   └── wp_defer_term_counts(false) (recalculates taxonomy counts once)
```

---

## Data Model & Taxonomies

### 1. Catalog Verticals & Categories

- **Apparel (`apparel`):**
  - Tops (T-Shirts, Shirts, Hoodies, Sweaters)
  - Bottoms (Trousers, Jeans, Shorts, Skirts)
  - Outerwear (Jackets, Coats)
  - Dresses
- **Technology (`tech`):**
  - Audio (Headphones, Earbuds, Speakers, Microphones)
  - Computers (Laptops, Monitors, Keyboards, Mice)
  - Mobile (Smartphones, Tablets, Smartwatches, Powerbanks)
- **Home & Living (`home`):**
  - Furniture (Chairs, Desks, Tables, Bookshelves)
  - Lighting (Desk Lamps, Floor Lamps, Pendant Lights)
  - Kitchen (Coffee Makers, Kettles, Cookware)
- **Beauty & Care (`beauty`):**
  - Skincare (Serums, Moisturisers, Cleansers, Masks)
  - Haircare (Shampoos, Conditioners, Styling Oils)
  - Fragrances (Eau de Parfum, Eau de Toilette)

### 2. Brands

- Fictional brands grouped by vertical (e.g. *Aeon Studio, Helios Supply, Nyx Tech, Orpheus Sound, Selene Home, Vulcan Gear, Iris Beauty*). Parent-child brand hierarchies are preserved for brand tree indexing tests.

### 3. Attributes & Variations

- **Global Attributes:** `Color` (pa_color), `Size` (pa_size), `Material` (pa_material), `Capacity/Storage` (pa_capacity), `Connectivity` (pa_connectivity).
- **Variable Mode (`mixed` default):** Simple products (75%) vs Variable products (25%). For variable products in high-volume runs, variations per parent are capped at 4–6 terms to maintain database health (~50k parents yielding ~100k total post records).

---

## CLI Interface & Guardrails

### Command Signature

```bash
wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=50000 mode=mixed catalog=all batch=1000 seed=6464 variation-skus
```

### Argument Matrix

| Argument | Type | Default | Valid Range / Options | Description |
| --- | --- | --- | --- | --- |
| `count` | int | `48` | `1` – `100000` | Number of parent products to create. |
| `mode` | string | `mixed` | `variable`, `simple`, `mixed` | Product type mode. Default changed from `variable` to `mixed`. |
| `catalog` | string | `all` | `all`, `apparel`, `tech`, `home`, `beauty` | Target vertical catalog domain. |
| `batch` | int | `1000` | `50` – `5000` | Items per garbage collection & cache flush cycle. |
| `seed` | int | `6464` | `>= 1` | Deterministic random seed for reproducibility. |
| `reset` | flag | `false` | boolean | Delete previously generated demo products before seeding. |
| `variation-skus` | flag | `false` | boolean | Assign deterministic SKUs to generated variations. |

---

## Performance & Memory Management

To generate 50,000+ products within PHP memory limits (`256M` – `512M`):

1. **WordPress Cache & Term Deferral:**
   ```php
   wp_defer_term_counts( true );
   wp_defer_comment_counting( true );
   // Run generation loop
   wp_defer_term_counts( false );
   wp_defer_comment_counting( false );
   ```
2. **Batched Cache & Memory Cleanup:**
   Every `$batch` items (default 1,000), execute:
   ```php
   wp_cache_flush();
   if ( function_exists( 'gc_collect_cycles' ) ) {
       gc_collect_cycles();
   }
   ```
3. **SKU Lookup Cache Invalidation:**
   `wc_get_product_id_by_sku()` caches lookup results in memory. In batched mode, ensure clean cache flushing so duplicate SKU checks remain fast.

---

## Edge Cases & Failure Scenarios

- **Duplicate SKU Conflicts:** The generator creates deterministic SKUs (`SKU-[VERTICAL]-[ID]`). If an existing product occupies a SKU, the generator skips it and ticks the progress bar.
- **Memory Exhaustion on High Variation Counts:** When `mode=variable` is requested for 50,000 products, warning message is issued if variation count exceeds 200k records.
- **Interrupted Batch Runs:** Partial runs leave `_shift64_woo_search_demo_generated = 'yes'` meta on created products. Re-running with `reset` will safely purge all generated items via batched deletion.

---

## Phasing & Implementation Plan

### Phase 1: CLI Extensions & Performance Architecture

- **Step 1:** Update `shift64_woo_search_parse_demo_product_args()` to allow `count` up to 100,000, parse `catalog` parameter (`all|apparel|tech|home|beauty`), add `batch` parameter (default 1,000), and change default `mode` to `mixed`.
- **Step 2:** Integrate `wp_defer_term_counts()` / `wp_defer_comment_counting()` wrappers around the generation loop in `Shift64_Woo_Search_Demo_Product_Generator::run()`.
- **Step 3:** Implement batched garbage collection (`wp_cache_flush()` + `gc_collect_cycles()`) inside the main creation loop and deletion loop.

### Phase 2: Dynamic Multi-Vertical Combinatorics Engine

- **Step 4:** Implement multi-vertical category hierarchy creation (`ensure_categories()`) supporting Apparel, Tech, Home, and Beauty.
- **Step 5:** Implement expanded multi-vertical brand creation (`ensure_brands()`) with parent-child relationships.
- **Step 6:** Replace 3-tuple name builder with 5-segment dynamic tuple generator (`build_combinations()`) generating >5,000,000 unique names.
- **Step 7:** Implement multi-vertical SKU generator (`sprintf('DEMO-%s-%02d-%06d', $vertical_prefix, $seed_hash, $index + 1)`).

### Phase 3: Attribute Expansion & Verification

- **Step 8:** Add global attributes for `pa_material`, `pa_capacity`, and `pa_connectivity` alongside `pa_color` and `pa_size`.
- **Step 9:** Verify seeding 10,000+ products via WP-CLI locally, verifying execution speed, memory stability, and search index rebuild via `wp shift64-woo-search rebuild`.
