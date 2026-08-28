# Notify — 2026-08-28-block-theme-only-legacy-removal

> Append-only log. Every entry is UTC-timestamped. Never rewrite prior entries.

## 2026-08-28T06:22:13Z — run started

- Brief: implement `.ai/specs/2026-07-30-block-theme-only-legacy-removal.md` —
  remove the classic-theme frontend surface, raise the runtime baseline, and ship
  the migration guide.
- External skill URLs: none.

## 2026-08-28T06:22:13Z — decision: prerequisite gate cleared

The spec is a release gate that forbids deletion while any of its four
prerequisite specs is still `draft`. All four are `implemented`
(`block-theme-product-collection-integration` #51, `composable-search-blocks` #60,
`product-filter-pill-blocks` #72, `native-woocommerce-catalog-sorting` #73), so
the run proceeds.

## 2026-08-28T06:22:13Z — decision: loop engine selected

The drafted plan has 24 Steps, over the repository's configured
`engine.loopStepThreshold` of 20, so `om-auto-create-pr` handed the run to
`om-auto-create-pr-loop`. `--loop` was not passed; the Step count alone decided.

## 2026-08-28T06:22:13Z — decision: baselines verified against reality

The spec names WordPress 7.0 and WooCommerce 10.9 as the new minimums. Checked
against `api.wordpress.org`: WordPress 7.1 and WooCommerce 11.0.1 are current, so
both minimums are one release behind the current stable and are declarable
without stranding the plugin. No deviation from the spec is needed.
