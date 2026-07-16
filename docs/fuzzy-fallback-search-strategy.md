# Unified Redis Search Pipeline

This document is now split into focused parts:

- [Search Architecture](./search-architecture.md)
- [Search Behavior](./search-behavior.md)
- [Indexing Strategy](./indexing-strategy.md)
- [Rollout Plan](./rollout-plan.md)

## Scope

Together, these documents define:

- how Redis should fit into autocomplete, full search, and archive filtering
- how strict, fuzzy, and token-reduction behavior should work
- how products, categories, stock, price, and attributes should be indexed
- how to roll out the safer B2B-friendly version of the system

## Current Recommendation

The recommended direction remains:

- one Redis-backed autocomplete layer via `FT.SUGGET`
- one full-results pipeline via `FT.SEARCH`
- strict-first, fuzzy-fallback behavior
- Redis for filtering and candidate retrieval
- WooCommerce for final rendering and business logic
- a hybrid archive model for B2B pricing safety
