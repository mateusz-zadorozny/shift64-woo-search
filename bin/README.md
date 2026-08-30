# Development Utilities

These scripts support local development and continuous integration. They are excluded from distribution packages.

- `install-wp-tests.sh` installs the WordPress PHPUnit test suite.
- `install-phpredis-local.sh` installs the PHP Redis extension in a Local development site.
- `diagnose-category-facets.php` inspects category facet data in a WordPress environment.
- `generate-demo-products.php` creates a deterministic English multi-vertical catalog for search testing.
- `provision-block-theme-header.php` writes the block theme's `header` template part so both search blocks sit in the site header.
- `demo-product-catalog.php` holds that generator's catalog data and combinatorics; it has no side effects and is unit tested.

## Demo product generator

The generator seeds up to 100,000 parent products across four verticals —
`apparel`, `tech`, `home`, and `beauty` — each with its own category tree,
brand tree, and attributes. Names use a five-segment tuple,
`[Prefix] [Series] [Item] [Spec] [Finish]` (for example
`Nova Athena Hoodie Oversized Deep Navy`), which spans more than five million
combinations, so even a 100k run produces no duplicate names. SKUs follow
`DEMO-[VERTICAL]-[SEED]-[ID]`, e.g. `DEMO-APP-6464-000001`. The seed segment
carries the whole seed, so two seeds never share a SKU.

Color is a visible global product attribute in every vertical. The variation
attribute depends on the vertical: size for apparel, capacity for tech and
beauty, material for home — four to six terms per parent so high-volume runs
stay database friendly.

Products are also assigned fictional brands from the `product_brand` taxonomy —
roughly 80% get one brand, 10% get two, and 10% stay brandless, so the single,
multi-brand, and brandless paths are all covered. Every vertical contributes one
parent brand with children, which exercises the indexer's brand ancestor chain
and the parent-brand filter. Stores without `product_brand` (WooCommerce below
9.4) are seeded without brands and log a warning.

```bash
# Create 48 mixed products spread evenly across all four verticals.
wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=48

# Seed a 50,000-product benchmark catalog from scratch.
wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=50000 mode=mixed catalog=all batch=1000 seed=6464 reset variation-skus

# Seed a single vertical with simple products only.
wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=5000 catalog=tech mode=simple

# Tear down: delete generator-owned products and stop, without reseeding.
wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php reset-only
```

Supported arguments:

- `count=1..100000` — number of parent products, default `48`;
- `mode=variable|simple|mixed` — default `mixed` (75% simple, 25% variable);
- `catalog=all|apparel|tech|home|beauty` — target vertical, default `all`;
- `batch=50..5000` — items per cache flush and garbage collection cycle, default `1000`;
- `seed=N` — repeatable name/SKU generation, default `6464`; any positive integer, and
  distinct seeds always produce distinct SKUs;
- `variation-skus` — assigns deterministic SKUs to variations; disabled by default;
- `reset` — permanently deletes products marked as generator-owned, in batches, and then seeds the requested catalog;
- `reset-only` — performs the same deletion and stops; nothing is created, so `count` has no
  effect on the run.

`reset` is a modifier on a seeding run, not a teardown. Use `reset-only` when the
goal is to clear a demo catalog off a machine.

Every argument is range-checked before the run mode is considered, so a value that is out of
range is rejected even when the run would not have used it. `count=0` is never valid — pass
`reset-only` on its own rather than trying to suppress seeding with a zero count.

High-volume runs defer term counting and comment counting for the whole run and
flush the object cache once per batch, which keeps memory flat under a 256M–512M
PHP limit.

Repeated execution with the same seed skips existing SKUs. After generation,
run `wp shift64-woo-search rebuild` to refresh the search index.

Variation SKUs are fixture data only and are never added to the product search
index. Search results always resolve parent products.

## Block-theme search header

`bin/e2e-provision.sh` runs this on every provisioning pass; run it directly
after editing the header in the Site Editor to fold your version back in:

```bash
wp eval-file wp-content/plugins/shift64-woo-search/bin/provision-block-theme-header.php
wp eval-file wp-content/plugins/shift64-woo-search/bin/provision-block-theme-header.php theme=twentytwentyfive
```

It replaces the `header` template part with a header carrying
`shift64-woo-search/modal-search` beside the account and cart icons and
`shift64-woo-search/search` on the row below. Theme resolution, in precedence
order: the `theme=` argument, the active theme when it is a block theme, the
`E2E_BLOCK_THEME` environment variable, `twentytwentyfive`.

Two details make it portable in a way a raw Site Editor export is not: the
`wp:navigation` ref is resolved against this install (an exported ID points at
another site's menu), and the `wp_theme` / `wp_template_part_area` terms are
set explicitly, which is what makes WordPress resolve the row as *this theme's*
header. Because the part is theme-scoped, it is simply absent under a classic
theme such as Storefront — which is the supported behaviour: the plugin places
nothing in a theme it does not own.

Production Redis provisioning is intentionally outside the plugin repository. BYOR operators should use their own infrastructure tooling; the managed service will use a separate private control plane.
