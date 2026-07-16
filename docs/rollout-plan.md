# Rollout Plan

## Search Comparison and Tuning Page

The settings area should include a dedicated comparison page for search tuning.

Purpose:

- compare the same query across different search settings
- compare strict and fuzzy behavior side by side
- validate ranking changes before making them the default
- make search tuning easier for non-developers

Recommended capabilities:

- enter one or more test queries
- run the same queries against multiple setting combinations
- compare result order side by side
- show the generated `FT.SEARCH` query
- show raw Redis score
- show final score after PHP business reranking
- show whether a result came from strict or fuzzy fallback
- highlight hidden, excluded, and out-of-stock handling

Useful comparison presets:

- current mixed behavior
- strict-first, fuzzy-fallback
- fuzzy level 1
- fuzzy level 2
- fuzzy level 3

This page should be treated as a tuning and diagnostics tool, not only as a developer debug screen.

## Price Sorting Guidance

Base-price filtering is usually acceptable.

Base-price sorting may also be acceptable if customer prices are mostly percentage discounts.

However, if the store uses:

- fixed negotiated prices
- product-level pricing overrides
- bundles
- complex rule-based pricing

then Redis/base-price sorting may be incorrect for a specific customer.

Because of that, personalized price sorting should be treated cautiously.

## Recommended Rollout

1. Use Redis for search results pages.
2. Use Redis for category and attribute filtering.
3. Keep WooCommerce rendering for all product cards.
4. Keep price-based behavior conservative at first.
5. Re-evaluate personalized price sorting only after the retrieval layer is stable.

This is the lower-risk path for a B2B shop.
