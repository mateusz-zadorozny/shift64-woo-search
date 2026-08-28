# Block theme-only migration

Shift64 Woo Search used to ship two storefronts. One was block-native: the
plugin's Site Editor blocks reading an inherited WooCommerce Product Collection.
The other was a pre-1.0 classic surface that injected itself into whatever theme
was active — shortcodes, WooCommerce placement hooks, Kadence-specific rendering,
a bespoke AJAX archive fragment swap, and the admin appearance controls that
configured all of it.

Two frontends meant two sets of ownership rules for the same pixels. This release
removes the classic one. The supported storefront is a modern block theme using
an inherited Product Collection, with the plugin's blocks placed in the Site
Editor.

Design record: [`.ai/specs/2026-07-30-block-theme-only-legacy-removal.md`](../.ai/specs/2026-07-30-block-theme-only-legacy-removal.md).

## Surface inventory

This is the inventory the removal was planned against, generated from repository
searches for `add_shortcode`, WooCommerce placement actions, theme-named classes
and selectors, registered asset handles, and the settings registration and
config-export code paths. It is published so that no alias can survive the
cleanup unnoticed: anything not listed here as removed is still expected to work,
and anything listed as removed should not be findable in the plugin after this
release.

### Shortcodes

| Tag | Status | Replacement |
|-----|--------|-------------|
| `[shift64_woo_search]` | Removed | The `shift64-woo-search/search` block. |
| `[shift64_woo_search_modal]` | Removed | The `shift64-woo-search/modal-search` block. |
| `[shift64_woo_search_breadcrumbs]` | Removed | The core Breadcrumbs / WooCommerce Breadcrumbs block in the template. |

No compatibility aliases are kept. A removed tag left in post content renders as
literal text, which is why the pre-upgrade step below is to find and replace them
first. For one release, WP Admin reports where they still occur; it never renders
them.

### Frontend placement hooks and theme takeovers

Every entry below was registered by `Shift64_Woo_Search_Archive` or
`Shift64_Woo_Search_Filters` and existed only to put plugin markup into a theme's
output, or to suppress the theme's own.

| Hook | Callback | Status | Why |
|------|----------|--------|-----|
| `woocommerce_before_shop_loop` | `Shift64_Woo_Search_Filters::render_filters` | Removed | The Product Filters and Filter Pill blocks own the filter bar; the whole renderer class is deleted, including the mobile filter/sort tray. |
| `woocommerce_archive_description` | `render_search_header` | Removed | Archive header output belongs to the template. |
| `woocommerce_show_page_title` | `hide_default_page_title` | Removed | The plugin no longer suppresses a theme's title. |
| `get_the_archive_title` | `filter_archive_title` | Removed | Existed to feed Kadence Blocks Pro dynamic headings. |
| `woocommerce_catalog_orderby` | `filter_sort_options` | Removed | The Product Sort block offers sorting; the plugin no longer replaces WooCommerce's control. |
| `ngettext_woocommerce` | `filter_result_count_text` | Removed | Product Collection owns its own result count. |
| `ngettext_with_context_woocommerce` | `filter_result_count_text_ctx` | Removed | As above. |
| `template_include` | `maybe_render_partial` | Removed | The Kadence partial-fragment renderer behind the AJAX archive swap. |
| `paginate_links` | `preserve_filter_params_in_pagination` | Removed | Pagination links are the template's, per the #20 ownership matrix. |
| `kadence_post_layout` | `disable_kadence_hero_on_search` | Removed | The last theme-specific integration. |
| `wp_enqueue_scripts` | `Shift64_Woo_Search_Frontend::enqueue_assets` | Removed as a global hook | Assets now load through block metadata when a block renders. |

After this release the plugin detects, names, or special-cases no theme anywhere.

### Retained hooks

"No hooks" in the design record means no theme placement or markup-takeover
hooks. The engine boundary keeps the WordPress and WooCommerce filters it needs
to adapt queries and keep the index fresh:

| Hook | Owner | Purpose |
|------|-------|---------|
| `pre_get_posts` | `Shift64_Woo_Search_Archive`, `Shift64_Woo_Search_Taxonomy_Archive` | Redis interception of the product query. |
| `posts_clauses` | `Shift64_Woo_Search_Taxonomy_Archive` | Scoped `post__in` injection for non-main queries. |
| `render_block_context`, `pre_render_block`, `render_block`, `query_loop_block_query_vars`, `found_posts` | `Shift64_Woo_Search_Product_Collection_Query` | Product Collection query eligibility, adaptation, and marker-scoped totals. |
| `template_redirect` | `Shift64_Woo_Search_Filter_Blocks` | Canonicalizes array-form filter query parameters. |
| `init` | `Shift64_Woo_Search_Blocks`, `Shift64_Woo_Search_Catalog_Navigation` | Block and script-module registration. |
| `render_block_data` | `Shift64_Woo_Search_Blocks` | Per-instance runtime ids without mutating saved content. |
| `pre_get_document_title` | `Shift64_Woo_Search_Archive` | The browser document title on search results. This is not theme output takeover, and removing it would regress the results page title. |
| `wp_footer` | `Shift64_Woo_Search_Archive` | The opt-in archive debug panel. |
| Product, term, and import sync actions | `Shift64_Woo_Search_Sync` | Index freshness. |
| `rest_api_init` | `Shift64_Woo_Search_Editor_Facets_Rest` | The editor's facet endpoint. |
| `redis_object_cache_flush`, `shift64_woo_search_lazy_reindex`, `upgrader_process_complete` | Plugin bootstrap | Index healing and MU-plugin deployment. |

### Blocks

| Block | Status |
|-------|--------|
| `shift64-woo-search/search` | Preserved, name and attributes unchanged. |
| `shift64-woo-search/modal-search` | Preserved, name and attributes unchanged. |
| `shift64-woo-search/search-control` | Preserved. |
| `shift64-woo-search/search-panel` | Preserved. |
| `shift64-woo-search/product-filters` | Preserved. |
| `shift64-woo-search/filter-pill` | Preserved. |
| `shift64-woo-search/product-sort` | Preserved. |

A `shift64-woo-search/search` or `modal-search` block saved before the composable
children existed has no inner blocks. That childless form still renders, through
the same markup builder the removed shortcodes used — the builder survives as the
block's fallback renderer, only its shortcode registration is gone.

### Asset handles

| Handle | Kind | Status |
|--------|------|--------|
| `shift64-woo-search` | style | Retained, but enqueued through block metadata rather than on every page. |
| `shift64-woo-search` | script | Retained only as the childless-block fallback autocomplete; no longer globally enqueued, and no longer bound to configured theme selectors. |
| `shift64-woo-search-ajax-pagination` | script | Removed with the archive fragment swap. |
| `shift64-woo-search/catalog-navigation` | script module | Retained. |
| `shift64-woo-search/product-sort` | script module | Retained. |
| Per-block `style-index.css` and `view.js` | block metadata | Retained. |

### Generated SHORTINIT constants

The MU-plugin `config.php` the lightweight endpoint reads exports Redis
connection, search behavior, strategy, normalization, boost/pin, brand
suggestion, facet attribute, and rate-limit constants. It never exported an
appearance, selector, or placement value, so its format changes only in that this
release locks that property down with a test. The file stays generated on
activation, plugin update, and `wp shift64-woo-search setup`, and is never
committed.

## Option classification

No option row is deleted or renamed by this release. Every value below is left in
place so that rolling the plugin back finds its settings intact. A later,
explicitly specified data-cleanup migration may remove the orphaned keys; this
release does not.

### Retained and active

Unchanged in behavior, still configurable in WP Admin.

`shift64_woo_search_redis_host`, `_redis_port`, `_redis_auth_enabled`,
`_redis_username`, `_redis_password`, `_redis_db`, `_redis_prefix`,
`_min_query`, `_autocomplete_limit`, `_full_limit`, `_fuzzy_level`, `_logic`,
`_strategy`, `_fallback_trigger`, `_fallback_score_threshold`,
`_fallback_fuzzy_level`, `_token_reduction_enabled`, `_weak_tokens`,
`_drop_trailing_weak_token_only`, `_diacritics_normalization`,
`_fuzzy_synonyms`, `_outofstock_mode`, `_outofstock_demote_factor`,
`_category_boost_rules`, `_category_pin_rules`, `_category_boosts`,
`_category_suggest_fuzzy`, `_category_suggest_exclude`,
`_brand_suggest_enabled`, `_debounce`, `_show_sku`, `_show_category`, `_show_brand`,
`_filter_attributes`, `_filter_categories_enabled`,
`_filter_categories_excluded`, `_filter_brands_enabled`, `_weights`,
`_synonyms`, `_archive_enabled`, `_archive_debug_enabled`,
`_taxonomy_archive_scopes`, `_price_sort_mode`, `_rate_limit`, `_db_version`,
`_date_indexed`, `_auto_rebuild`, `_facet_entries`.

`shift64_woo_search_archive_enabled` keeps its key and its meaning: it still
gates Redis interception of the product search query. Its label moves to describe
Product Collection search integration, because that is the only surface the
interception now feeds — the underlying behavior is identical, so per the design
record the key is reused rather than migrated.

### Inert for rollback

Still stored, no longer read by any code path, no longer exposed in WP Admin.
They exist so that a merchant who rolls back to the previous plugin version gets
their old configuration back.

| Option | What it configured | Replacement |
|--------|--------------------|-------------|
| `shift64_woo_search_input_selector` | The CSS selector the autocomplete attached itself to in an arbitrary theme. | The block renders its own field; nothing to point at. |
| `shift64_woo_search_additional_selectors` | Extra selectors to enhance. | As above. |
| `shift64_woo_search_button_selector` | The theme's search submit button. | As above. |
| `shift64_woo_search_dropdown_width_mode` | Autocomplete tray width mode, applied as a global inline style. | The Search block's own width controls; the fallback renderer uses the stylesheet default. |
| `shift64_woo_search_dropdown_width` | Custom tray width in pixels. | As above. |

`shift64_woo_search_debounce` is deliberately **not** in this table: the Search
block reads it for its Interactivity context, so it stays active and stays in WP
Admin.

### Never released as public surface

None. Every key above shipped in a release, so none is removed outright.

## Upgrading a store to the block-only frontend

Do this **before** updating, on a staging copy if you have one. The update itself
is not destructive — no product data, index or setting is deleted — but a
storefront that relied on the removed surfaces will render without its search,
filter and sort controls until its templates are edited.

### Before you update

1. **Confirm you are on a block theme.** Appearance → Editor exists only for
   block themes. If it does not, switch to one first: on a classic theme the
   plugin keeps indexing and searching, and the SHORTINIT endpoint and the CLI
   keep working, but no storefront control is rendered.
2. **Find and replace the removed shortcodes.** Search your pages, posts,
   widgets, and any page-builder content for `[shift64_woo_search]`,
   `[shift64_woo_search_modal]` and `[shift64_woo_search_breadcrumbs]`. After the
   update WP Admin reports remaining occurrences for one release, but a tag left
   in published content prints as literal text on the storefront in the meantime.
3. **Back up your block templates.** Export them from Appearance → Editor →
   Templates. This is what makes a rollback possible; see below.

### Edit each product template

In Appearance → Editor → Templates, open every template that lists products —
Product Catalog, Product Search Results, and each product-taxonomy archive you
have enabled — and for each one:

4. **Insert one inherited Product Collection** if the template does not already
   have one. Add the WooCommerce **Product Collection** block and leave its query
   inheriting the template's own query. One per template: a second collection
   gives the page two competing product lists.
5. **Insert Search or Modal Search** where the search box belongs — usually the
   header template rather than each archive.
6. **Insert Product Filters**, then add a **Filter Pill** inside it for each facet
   you want to offer, and configure each pill's taxonomy and display options.
7. **Insert Product Sort**, choose and order the sort modes you want to offer, or
   leave it out entirely if you do not want a sort control.
8. **Save, then check the storefront**: search, each filter, sort, pagination, and
   the mobile layout. Use Back and Forward as well — filtering and paging update
   the URL, and both should restore the results you were looking at.
9. **Remove the leftovers**: old shortcode blocks, widgets holding the removed
   tags, and any theme-specific placeholder that existed only to receive the
   plugin's injected markup.
10. **Run Shift64 setup or a rebuild only if asked.** Check the Facets and sorting
    status in the plugin's admin screens; if neither says a rebuild is required,
    your index is already correct and nothing needs re-indexing for this upgrade.

### The Product Collection the controls attach to

Shift64's controls attach to a Product Collection that inherits the template
query. In block markup that is:

```html
<!-- wp:woocommerce/product-collection {"queryId":0,"query":{"inherit":true,"isProductCollectionBlock":true}} -->
  <!-- wp:woocommerce/product-template -->
    <!-- your product card blocks -->
  <!-- /wp:woocommerce/product-template -->
  <!-- wp:query-pagination -->
    <!-- wp:query-pagination-previous /-->
    <!-- wp:query-pagination-numbers /-->
    <!-- wp:query-pagination-next /-->
  <!-- /wp:query-pagination -->
<!-- /wp:woocommerce/product-collection -->
```

Three properties are what the plugin looks for: `isProductCollectionBlock` is
`true`, `inherit` is `true`, and `queryId` is a stable number. Everything inside
is yours — card composition, columns, alignment, no-results content and
pagination layout are deliberately unconstrained, and the plugin never rewrites
them. Inserting the block through the editor produces this shape by default; the
markup above is only here so you can recognise a correct one.

If a template has controls but no compatible Product Collection, the editor shows
a setup notice on the control and the storefront still navigates natively. The
plugin will not create a hidden product loop to compensate.

### After you update

- **Purge caches.** Page caches and CDNs will still be serving the old markup and
  the old asset URLs. Purge both, or shoppers see a mixture of the two frontends
  until the cache expires.
- **Expect old JavaScript to do nothing.** A cached copy of the removed scripts
  finds none of the nodes it looked for and exits without error.
- **Check the admin notices.** The plugin reports any remaining shortcode
  occurrences, and tells you if the runtime is below the supported baseline.

### Rolling back

Rolling back is supported for one release, and takes three things, not one:

1. **Reinstall the previous plugin version.** No option is deleted by this
   release. The retired appearance, selector and tray-width settings keep their
   stored values, so the old frontend finds its configuration again.
2. **Restore the block templates you exported.** The new blocks you inserted are
   unknown to the old version and would render as unrecognised block comments.
   This is why the template backup is step 3 and not an afterthought.
3. **Purge caches again.**

No Redis downgrade or index rebuild is needed either way: the index schema, key
naming and stored data are untouched by this release.

## Requirements

This release raises the supported baseline to **WordPress 7.0**, **WooCommerce
10.9** and **PHP 8.3**, because the block and Interactivity APIs the storefront
is built on require them.

Below that baseline the plugin does not switch itself off wholesale: search,
indexing, the SHORTINIT endpoint, the admin screens and the CLI keep working, and
only the block layer stops loading — registering blocks against an API that is
not there is how a version mismatch becomes a fatal error. An admin notice names
what to upgrade, and the CLI exits with a non-zero explanatory error rather than
failing part-way through a rebuild.
