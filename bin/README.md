# Development Utilities

These scripts support local development and continuous integration. They are excluded from distribution packages.

- `install-wp-tests.sh` installs the WordPress PHPUnit test suite.
- `install-phpredis-local.sh` installs the PHP Redis extension in a Local development site.
- `diagnose-category-facets.php` inspects category facet data in a WordPress environment.
- `generate-demo-products.php` creates a deterministic English apparel catalog for search testing.

## Demo product generator

The default mode creates variable products such as `Athena T-Shirt Green`.
Color is a visible global product attribute; size (`XS` through `XXL`) is the
variation attribute. Categories are organized under Clothing, Tops, Bottoms,
and Outerwear.

```bash
# Create 48 variable products with six size variations each.
wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=48 mode=variable seed=6464

# Optionally assign deterministic SKUs to the generated variations.
wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=48 mode=variable seed=6464 variation-skus

# Delete only previously generated demo products, then create a mixed catalog
# containing 25% simple products and 75% variable products.
wp eval-file wp-content/plugins/shift64-woo-search/bin/generate-demo-products.php count=80 mode=mixed seed=6464 reset
```

Supported arguments:

- `count=1..1000` — number of parent products, default `48`;
- `mode=variable|simple|mixed` — default `variable`;
- `seed=N` — repeatable name/SKU shuffle, default `6464`;
- `variation-skus` — assigns deterministic SKUs to variations; disabled by default;
- `reset` — permanently deletes only products marked as generator-owned.

Repeated execution with the same seed skips existing SKUs. After generation,
run `wp shift64-woo-search rebuild` to refresh the search index.

Variation SKUs are fixture data only and are never added to the product search
index. Search results always resolve parent products.

Production Redis provisioning is intentionally outside the plugin repository. BYOR operators should use their own infrastructure tooling; the managed service will use a separate private control plane.
