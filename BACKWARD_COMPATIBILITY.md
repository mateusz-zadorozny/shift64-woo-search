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
archive/frontend (`_archive_enabled`, `_archive_debug_enabled`, `_taxonomy_archive_scopes`,
`_price_sort_mode`, `_debounce`, `_input_selector`, `_additional_selectors`, `_button_selector`).

**Breaking:** renaming an option; changing its stored type or shape; changing a default in a way
that alters behavior for installs that never set it explicitly.

**Required path:** migrate on upgrade behind `shift64_woo_search_db_version`, read the old key as
a fallback for one minor release, and state in the changelog what happens to a site that never
touched the setting. Remember that a changed default only affects installs with no stored value —
say so explicitly rather than leaving people to find out.

`_logic` (`OR` → `AND`) and `_strategy` (`strict_first` → `mixed`) were re-defaulted together.
On a 100k-product catalog the old pair returned the wrong products for two ordinary queries: OR
retrieval ranked a two-of-three-token match above a three-of-three one, so "aero cedar table" put
beauty products above "Aero Cedar Side Table"; and because the ladder never reached a fuzzy pass
while any one token still prefix-matched, "aero cedat" returned everything starting with "aero"
and nothing resembling "cedar". `AND` + `mixed` answers both, and answers them faster, because AND
cuts the candidate set before scoring.

**This reaches fresh installs only, and that is deliberate.** `set_default_options()` has seeded
both keys with `add_option()` since 0.1.0, and it runs on every activation, so an install that has
ever been activated holds `OR` and `strict_first` as *stored* values. `get_option()` never sees the
new default there, and regenerating `mu-plugins/shift64-woo-search/config.php` faithfully writes the
stored pair back out. Upgraded stores therefore keep the behavior they have today — including the
two wrong-result cases above — until someone changes the setting.

No migration flips them, because a stored `OR` is indistinguishable from the seeded `OR`: this
section forbids overwriting a merchant's explicit choice, and there is no signal that separates the
two. A store that wants the new behavior sets **Relevance → Search Logic** to `AND` and
**Search Mode** to `Mixed`; both take effect on save, which also rewrites `config.php`.

The repaired fallback ladder itself is *not* gated on the defaults — every install gets it on
upgrade, in whichever logic and strategy it has stored.

`_fallback_score_threshold` keeps its key, type, and `0.5` default, but no longer decides when one
retrieval pass hands over to the next — term coverage does. It still filters which fuzzy-pass
matches are shown. A store that had tuned it to force more fallbacks will see fewer fuzzy
results and more exact ones; nothing needs to be reset.

Under `mixed` with `OR`, a multi-word query re-ranks by how many search terms each product matches
across its title, SKU, categories, brands, and attributes, so a product matched only through its
description now sorts below one matched by name. This reorders, it does not drop: the minimum match
ratio is disabled for the single-pass strategies, and a hybrid pass is never score-filtered.

That last point narrows `_fallback_score_threshold` further than the paragraph above. It now applies
to the `fuzzy` pass alone — the one where every token was fuzzed and nothing matched exactly.
`mixed` and `token_fuzzy` match each token as prefix OR fuzzy, so they carry exact matches whose
TFIDF score tracks how common the term is, and filtering them is what #26 forbade: on a catalog
where a query term is frequent, an exact match scores under the threshold and the pass empties.

`_archive_debug_enabled` (default `no`) gates the storefront debug panel. It is a *new* key rather
than a re-defaulted one, but it does change behavior for installs that never set it: the panel
used to render for every user holding `manage_woocommerce` and now stays hidden until it is
switched on at **System → Diagnostics**. Nothing else about a search result changes, and the
capability check still applies on top of the option — the option can only narrow who sees the
panel, never widen it. Sites that want the old behavior set the option to `yes`.

Which admin screen writes a key is *not* part of this surface — the key, its type, and its
default are. The admin information-architecture migration moved controls between screens without
renaming, retyping, or re-defaulting a single option; see §11 for the route contract and for the
one write-behavior change that shipped with it.

### Deprecated values

Two option *values* are deprecated. Nothing about them has changed on this surface: both keys keep
their name, type, and default, both values remain readable and writable, and every reader — the
dropdown, the archive interceptor, the Product Collection block, `wp shift64-woo-search test`, and
the constants written into `mu-plugins/shift64-woo-search/config.php` — still honors a stored value
exactly as before. A store running either one gets identical search results after upgrading.

| Option | Deprecated value | Use instead |
|---|---|---|
| `shift64_woo_search_logic` | `OR` | `AND` |
| `shift64_woo_search_fallback_trigger` | `no_results` | `low_score` |

What changed is only what the plugin *says*: the deprecated choice is labelled in the settings
dropdown, a store that has one stored sees a notice on the plugin's admin pages, and
`wp shift64-woo-search health` emits a `WP_CLI::warning()` per stored value. The declared set lives
in `Shift64_Woo_Search_Deprecations::registry()`, which is internal and carries no compatibility
promise of its own.

The evidence is in issue #85: on a 100k-product catalog `OR` scored 9/11 on the probe matrix
against `AND`'s 11/11 and ran 17–25 ms against 1–3 ms, and `no_results` disables the fallback
ladder rather than tightening it, so a typo in one word of a multi-word query is never repaired —
the defect #78 fixed, reachable again through a dropdown. That evidence comes from one synthetic
catalog with very regular naming, which is why these are deprecated rather than removed: a store
with long descriptive titles, heavy synonym use, or mixed languages could behave differently, and
the deprecation window is where that gets measured.

**Removal is a separate, breaking change** and takes this section's required path in full: migrate
behind `shift64_woo_search_db_version`, read the old value as a fallback for one minor release, and
state in the changelog what happens to a store that never touched the setting. Deprecating a value
is not a licence to skip any of that.

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
- `[shift64_woo_search]` — attributes: `placeholder`, `button`, `label`
- `[shift64_woo_search_modal]` — attributes: `placeholder`, `button`, `label`,
  `trigger_label`, `close_label`, `clear_label`, `icon`

It lives in user content, which means a rename breaks pages the user wrote by hand and cannot be
migrated by us. Treat the tag as permanent; if it must change, register the old tag as an alias
and keep it.

Registered dynamic block names:

- `shift64-woo-search/search`
- `shift64-woo-search/modal-search`
- `shift64-woo-search/search-control` (structural child)
- `shift64-woo-search/search-panel` (structural child)

The two parent names and their legacy attribute keys are persistent content APIs. New content
saves a locked Control/Panel pair under either parent. Existing self-closing parent comments
remain valid: the PHP fallback renders their old attributes until the content is migrated in
the editor. Custom legacy modal appearance fields are migration-only; standard child block
supports are the forward styling contract, so exact visual parity is not guaranteed.

The shortcodes remain permanent and keep the `shift64-woo-search` classic runtime. That
runtime deliberately skips roots marked `data-shift64-search-root`; metadata blocks use the
public `shift64-woo-search/search` Interactivity API namespace instead. Neither interface may
be removed when the other changes. See `docs/composable-search-blocks.md` for the migration
matrix and rollback behavior.

## 9. Frontend assets

Handles: `shift64-woo-search` (style + script), `shift64-woo-search-ajax-pagination`,
`shift64-woo-search-admin` (style + script).
Localized objects: `shift64_woo_search_config` (frontend), `shift64_woo_search_admin` (admin).

**Breaking:** renaming a handle (themes dequeue and depend on them); removing or retyping a key
in `shift64_woo_search_config`.

**Required path:** treat `shift64_woo_search_config` as the endpoint's response shape — additive
keys are free, removals and renames are not.

`showBrand` and `brandsHeaderText` were added alongside the brand surfaces, mirroring
`showCategory` / `categoriesHeaderText`. A brandless product renders no label regardless of the
switch.

`showSku`, `showCategory`, and `showBrand` became option-backed in #41
(`shift64_woo_search_show_*`), each defaulting to `yes` so an unconfigured site renders exactly
as before. They stay **bools** — retyping them would break the contract above — but note that
`wp_localize_script` stringifies scalars in transit, so a `false` reaches the browser as `''`,
not as `false`. The script therefore reads them through its `isEnabled()` helper; the older
`config.showX !== false` guard silently treated every disabled switch as enabled and must not
be reintroduced.

`--s64ws-dropdown-width` sizes the expanded search field and the results tray. Unlike the other
`--s64ws-*` tokens it has **no `:root` default**, and that is load-bearing: unset, the
stylesheet's own `auto` / `100%` fallbacks keep the tray matching the search field, which is the
behaviour every site had before #41. The plugin emits it as an inline style only when
`shift64_woo_search_dropdown_width_mode` is `custom`, taking the value from
`shift64_woo_search_dropdown_width` clamped to 320–1200px. Giving the property a `:root` default
would opt every site into a fixed-width tray at once.

The custom width is a **classic/inline concern only**. The modal search keeps its original
sizing — its tray is always as wide as its own dialog — enforced by
`.shift64-woo-search-modal__search .shift64-woo-search-results`, which has the same specificity
as the shortcode rule and therefore has to stay after it in the file.

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

## 11. Admin settings routes

The settings page moved from twelve equal tabs to six task-oriented workspaces addressed by
`tab` + `section`
(`.ai/specs/2026-07-22-admin-settings-information-architecture.md`). Admin URLs are weaker than
the storefront surfaces above — nobody's cron job calls them — but merchants bookmark them,
support articles link them, and `Shift64_Woo_Search_Admin_Routes` is now the single source of
truth for what exists, so the map belongs here.

### Canonical routes

`admin.php?page=shift64-woo-search&tab={workspace}&section={section}`

Six workspaces, nineteen sections. Omitting `section` lands on the workspace default (marked
`*`), so the short form `…&tab=system` stays valid even if a default is renamed.

| `tab` | `section` values |
|---|---|
| `overview` | `overview`* (the page's landing route; `section` is ignored) |
| `experience` | `search-field`*, `autocomplete`, `query-suggestions`, `category-suggestions` |
| `results` | `coverage`*, `facets` |
| `relevance` | `basic`*, `matching`, `synonyms`, `merchandising`, `field-weights`, `test-search`, `compare-passes` |
| `insights` | `statistics`* |
| `system` | `connection`*, `index`, `security`, `diagnostics` |

Resolution is total and case-sensitive: an unknown, non-string, or hostile `tab` resolves to
`overview`; an unknown or non-string `section` resolves to the workspace default. Render
callbacks come only from the registry — request input is never concatenated into a method name.

**Breaking:** removing a workspace or section slug; repointing a slug at different settings;
making a section reachable only with JavaScript.

**Required path:** keep the retired slug accepted as an alias for at least one minor release and
say in the changelog where its settings went.

### Legacy tab aliases

The twelve pre-migration `tab` values still resolve, and are **expected to keep resolving for at
least one minor release — they may remain indefinitely**, since an alias table costs nothing.
They are resolved internally by `Shift64_Woo_Search_Admin_Routes::resolve()`, with **no HTTP
redirect**: a legacy bookmark renders the new screen at the URL the user typed. Legacy URLs never
carried a `section`, so an alias fixes both halves of the destination and ignores any `section`
that was appended by hand.

| Legacy `tab` | Resolves to |
|---|---|
| `frontend` | `experience` / `search-field` |
| `suggestions` | `experience` / `query-suggestions` |
| `catboost` | `experience` / `category-suggestions` |
| `filters` | `results` / `facets` |
| `search` | `relevance` / `basic` |
| `synonyms` | `relevance` / `synonyms` |
| `weights` | `relevance` / `field-weights` |
| `test` | `relevance` / `test-search` |
| `tuning` | `relevance` / `compare-passes` |
| `stats` | `insights` / `statistics` |
| `redis` | `system` / `connection` |
| `index` | `system` / `index` |

`tab=search` is the one alias whose old screen was split across workspaces, so it renders a
non-persistent notice pointing at the other destinations. The notice is presentation only —
removing it is not a contract break.

**Breaking:** dropping an alias inside the compatibility window; turning an alias into a redirect
that changes the URL third-party links point at.

### Reaffirmed unchanged by the migration

The migration was a re-layout, not a rewrite. Everything below is byte-identical to the
pre-migration plugin and must stay that way:

- **Every `shift64_woo_search_*` option** — same keys, same stored types and shapes, same
  defaults. No renames, no normalization, no migration (§6).
- **Every `wp_ajax_shift64_woo_search_*` action name**, including the misspelled
  `shift64_woo_search_synonys64ws_add` / `_update` / `_remove` / `_export` / `_import`. The
  misspelling is deliberate compatibility debt: renaming it breaks any saved automation, so it
  stays until a major.
- **Nonce action `shift64_woo_search_admin`** and the AJAX request/response envelopes.
- **Asset handles `shift64-woo-search-admin`** (style and script) and the localized object
  `shift64_woo_search_admin` with keys `ajax_url`, `nonce`, `default_weights`, `current_weights`
  (§9).
- **Menu slug `shift64-woo-search`** under the `woocommerce` parent, capability
  `manage_woocommerce`, hook suffix `woocommerce_page_shift64-woo-search`.
- **Storefront behavior** — no endpoint, index, or frontend-asset change.

### The one behavior change

Generic partial settings saves used to clear `shift64_woo_search_redis_username` and
`shift64_woo_search_redis_password` whenever the *stored*
`shift64_woo_search_redis_auth_enabled` option read `no`. With settings split across sections,
that meant saving an unrelated section could silently wipe working Redis credentials.

`Shift64_Woo_Search_Admin_Settings::persist()` now clears the two credential options **only when
the submitted payload itself contains `shift64_woo_search_redis_auth_enabled` = `no`**. A payload
that does not mention the key leaves credentials untouched.

Explicitly turning authentication off on System → Connection still clears them, so the intended
path is unchanged; only the accidental one is gone. Any future partial-save form must keep this
property: **a save may only write keys the payload actually submitted.**
