# Backward Compatibility

What this plugin treats as a **protected contract surface**, and what you must do when you
change one. Review skills check changes against this file; implementation skills warn when a
change violates it.

## Where the line sits today

`CONTRIBUTING.md` says it plainly: **the `0.x` line is for product validation, and `1.0.0` marks
the stable public contract.** The plugin is at `0.1.0`, so breaking changes are permitted — but
they are never silent. On `0.x`, a break needs a changelog entry and a migration note. From
`1.0.0` on, every rule below hardens into a major-version requirement.

The surfaces are listed in rough order of how much it hurts when they break.

## 1. The SHORTINIT endpoint (highest exposure)

`/wp-content/mu-plugins/shift64-woo-search/endpoint.php` — public, unauthenticated, called by
every search box on the storefront and cached by nothing.

| Element | Contract |
|---|---|
| Path | `content_url( '/mu-plugins/shift64-woo-search/endpoint.php' )` |
| Params | `q` (string), `mode` (`autocomplete` \| `full` \| `suggestions`), `limit` (int) |
| Success | JSON `{success, count, query, time_ms, results, suggestions, categories, brands}` |
| Degraded | `fallback: /?s=…&post_type=product` when Redis is unavailable |
| Throttled | HTTP 429 + `Retry-After: 1` |

**Breaking:** removing or renaming a param; dropping a `mode` value; removing or retyping a
response key; changing the URL; removing the `fallback` behavior; returning a new status code
the frontend does not handle.

**Not breaking:** adding a new optional param with a safe default; adding a new response key.

`brands` (autocomplete mode) is **always present**, including in the empty/focus shapes and when
`SHIFT64_WOO_SEARCH_BRAND_SUGGEST` is off — it is `[]` there. The response shape must not vary
with a toggle; the frontend hides the section on emptiness alone. Each product row also carries
`brand`: a pipe-separated string like `category`, `''` for brandless products, whose first
segment is guaranteed to be a directly-assigned brand rather than an inherited parent.

**Required path:** the bundled frontend JS and the endpoint ship together, so they can change
together — but third-party code and cached pages call this URL too. Keep an old param accepted
as an alias for at least one minor release, and note the change in the changelog.

## 2. WP-CLI commands

`cli/class-shift64-woo-search-cli.php`. These end up in deploy scripts and cron, where a
rename fails silently at 3am.

| Command | Args |
|---|---|
| `wp shift64-woo-search setup` | `--host` `--port` `--username` `--password` `--db` `--prefix` |
| `wp shift64-woo-search reindex` | `--all` `--id=<product_id>` |
| `wp shift64-woo-search test` | `<query>` `--mode=autocomplete\|full` `--limit` |
| `wp shift64-woo-search status` / `rebuild` / `health` | none |

**Breaking:** renaming a command or flag; changing a default (`--prefix` default
`shift64_woo_search`, `--port` `6379`, `--db` `0`, `--limit` `7`, `--mode` `autocomplete`);
making an optional flag required; changing exit codes or machine-readable output shape.

**Required path:** keep the old flag working as a deprecated alias with a `WP_CLI::warning()`
for one minor release before removal.

## 3. Redis keyspace and index

The keyspace is a contract with **data already on disk in production**, which is what makes it
the sharpest surface here: a rename does not fail, it silently orphans live data.

| Key | Holds |
|---|---|
| `{prefix}_product_idx` | the RediSearch index |
| `{prefix}:product:{id}` | product hash (`FT.CREATE … ON HASH PREFIX 1 {prefix}:product:`) |
| `{prefix}:categories` | precomputed category blob (JSON) |
| `{prefix}:brands` | precomputed brand blob (JSON, same shape as `categories`) |
| `{prefix}:suggestions` | suggestions list (JSON) |
| `{prefix}:synonyms` | synonyms (JSON) |
| `{prefix}:rl:{md5(ip)}` | rate-limit counter (ephemeral) |
| `{prefix}:_heal_lock` | self-heal lock, 300s TTL (ephemeral) |

`{prefix}` resolves from `SHIFT64_WOO_SEARCH_REDIS_PREFIX`, else the
`shift64_woo_search_redis_prefix` option, else `shift64_woo_search`.

**Breaking:** renaming any key pattern or the index; changing an indexed field's name, type, or
weight; changing the JSON shape of the `categories` / `brands` / `suggestions` / `synonyms` blobs.

**Required path:** a schema change must ship with a rebuild trigger — bump
`shift64_woo_search_db_version` and drive `FT.DROPINDEX … DD` plus reindex, or route through the
self-heal path. A PR that changes the schema and leaves existing installs on a stale index is
broken, not merely incompatible. The ephemeral keys (`rl:`, `_heal_lock`) are exempt: they
expire on their own.

The `shift64_woo_search_db_version` check in `shift64-woo-search.php` carries a version→action
map for exactly this. A version mapped to `rebuild` schedules the shared full rebuild
(`Shift64_Woo_Search_Rebuild::run()`); a version mapped to `blobs` only refreshes the endpoint's
JSON blobs, which is the right (and far cheaper) action when a release adds a blob without
touching the index schema. Note that `ensure_index_healthy()` checks index existence and doc
count only — it does **not** detect field-level drift, so a new field always needs the rebuild
mapping.

## 4. The generated mu-plugin config

`wp-content/mu-plugins/shift64-woo-search/config.php`, written by `generate_mu_plugin_config()`
in `shift64-woo-search.php`. It is a flat list of `define()` calls, regenerated on activation,
plugin update, and `wp shift64-woo-search setup`.

This surface has a failure mode unique to it: **the endpoint and the config are deployed
separately.** The endpoint reads constants that a *previously generated* config may not define.

**Breaking:** the endpoint reading a new constant that `generate_mu_plugin_config()` does not
write; removing a constant the endpoint still reads; changing the file's location or format.

**Required path:** every constant the endpoint reads must be written by the generator **in the
same PR**, and the endpoint must tolerate its absence with a sane default —
`SHIFT64_WOO_SEARCH_PLUGIN_PATH` is the sole exception, since the endpoint cannot function
without it and correctly 500s. `SHIFT64_WOO_SEARCH_MU_VERSION` exists to detect a stale config;
use it.

Never commit a generated `config.php`.

## 5. Hooks

The plugin fires exactly one filter for third parties:

- `shift64_woo_search_taxonomy_archive_max_ids` — `int $max_ids`, default `10000`
  (`includes/class-shift64-woo-search-taxonomy-archive.php:373`)

**Breaking:** removing it, renaming it, changing its argument list or the meaning of the value.

**Required path:** `apply_filters_deprecated()` for one minor release. New hooks are additive and
free — but every hook added is a promise, so add them deliberately.

## 6. Options

Every setting is its own top-level `wp_option` (there is no settings array, and no
`register_setting()`). Roughly 40 keys, all prefixed `shift64_woo_search_`: Redis connection
(`_redis_host`, `_redis_port`, `_redis_username`, `_redis_password`, `_redis_db`, `_redis_prefix`,
`_redis_auth_enabled`), search behavior (`_min_query`, `_autocomplete_limit`, `_full_limit`,
`_outofstock_mode`, `_fuzzy_level`, `_logic`, `_strategy`, `_fallback_*`, `_token_reduction_*`,
`_diacritics_normalization`, `_rate_limit`, …), filters/facets/index (`_filter_attributes`,
`_filter_categories_*`, `_filter_brands_enabled`, `_brand_suggest_enabled`, `_weights`,
`_category_boosts`, `_category_suggest_exclude`), and
archive/frontend (`_archive_enabled`, `_taxonomy_archive_scopes`, `_price_sort_mode`, `_debounce`,
`_input_selector`, `_additional_selectors`, `_button_selector`).

**Breaking:** renaming an option; changing its stored type or shape; changing a default in a way
that alters behavior for installs that never set it explicitly.

**Required path:** migrate on upgrade behind `shift64_woo_search_db_version`, read the old key as
a fallback for one minor release, and state in the changelog what happens to a site that never
touched the setting. Remember that a changed default only affects installs with no stored value —
say so explicitly rather than leaving people to find out.

## 7. Database schema

One table: `{$wpdb->prefix}shift64_woo_search_stats` (`includes/class-shift64-woo-search-stats.php`),
created via `dbDelta`, written directly from the SHORTINIT endpoint.

Columns: `id`, `query_text`, `query_normalized`, `results_count`, `response_time_ms`,
`search_mode`, `user_id`, `session_id`, `ip_hash`, `created_at`.
Indexes: `idx_normalized`, `idx_created`, `idx_no_results`.

**Breaking:** dropping or renaming a column; narrowing a type; removing an index the admin stats
screen relies on.

**Required path:** `dbDelta` migration gated on a bumped `shift64_woo_search_db_version`. Both
the endpoint's `$wpdb->insert()` and the admin readers must land in the same PR. `ip_hash` is a
hash, not an IP — keep it that way.

## 8. Shortcode

- `[shift64_woo_search_breadcrumbs]` — no attributes
  (`includes/class-shift64-woo-search-archive.php:99`)

It lives in user content, which means a rename breaks pages the user wrote by hand and cannot be
migrated by us. Treat the tag as permanent; if it must change, register the old tag as an alias
and keep it.

No blocks are registered (`register_block_type()` appears nowhere), despite the roadmap
mentioning a search block. When blocks arrive, block names become a surface on this list.

## 9. Frontend assets

Handles: `shift64-woo-search` (style + script), `shift64-woo-search-ajax-pagination`,
`shift64-woo-search-admin` (style + script).
Localized objects: `shift64_woo_search_config` (frontend), `shift64_woo_search_admin` (admin).

**Breaking:** renaming a handle (themes dequeue and depend on them); removing or retyping a key
in `shift64_woo_search_config`.

**Required path:** treat `shift64_woo_search_config` as the endpoint's response shape — additive
keys are free, removals and renames are not.

`showBrand` (bool, hardcoded `true`) and `brandsHeaderText` were added alongside the brand
surfaces, mirroring `showCategory` / `categoriesHeaderText`. Like `showCategory`, `showBrand` is
deliberately not option-backed: the JS guards on `config.showBrand !== false`, so a brandless
product simply renders no label.

## 10. Runtime requirements

Declared in three places that must agree: the plugin header, `readme.txt`, and `composer.json`
(with `config.platform` pinned to `8.3.0`).

- **WordPress 6.0** minimum
- **PHP 8.3** minimum (raised from 7.4 in #5; CI tests 8.3/8.4/8.5)
- Redis Stack, or Redis with RediSearch, plus the PHP Redis extension

**Breaking:** raising any minimum. **Required path:** bump all three declarations together,
say so prominently in the changelog, and treat it as a minor bump at least — on `1.0.0+`, a major.

The PHP floor is machine-checked: `.phpcs.xml.dist` runs the `PHPCompatibilityWP` standard with
`testVersion 8.3-`, so `vendor/bin/phpcs` (locally and in CI) flags code that does not run on
every supported PHP version. Keep `testVersion`, the three declarations, and the CI matrix in
agreement whenever any of them changes. See `CODE_REVIEW.md`.
