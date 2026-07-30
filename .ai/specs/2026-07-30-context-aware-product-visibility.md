# Context-Aware Product Visibility in Redis Queries

> **Status:** implemented — PR #46, 2026-07-30

## TLDR

Shift64 currently excludes only `hidden` products from Redis searches, allowing
catalog-only products to leak into product-search results. This spec makes the
visibility exclusion an explicit query context: search excludes both `hidden`
and `catalog`, while future Product Collection contexts can supply their own
rules without duplicating query construction.

## Problem Statement

The index already stores WooCommerce's raw catalog visibility values:
`visible`, `catalog`, `search`, and `hidden`. The shared Redis query builder,
however, hardcodes only `-@visibility:{hidden}`. WooCommerce defines
catalog-only products as unavailable to search, so the existing Redis path can
return products that native WooCommerce correctly excludes.

The hardcoded rule also prevents the block-theme Product Collection adapter
from expressing a context-specific visibility policy cleanly. Sorting, filter
blocks, and search retrieval should consume one query builder rather than
forking visibility clauses in each integration.

## Decisions

1. Shopper-facing product search, including autocomplete and the full results
   archive, excludes `hidden` and `catalog`.
2. The shared query builder accepts a closed visibility context or an explicit
   validated exclusion set; callers do not concatenate RediSearch fragments.
3. This spec implements only the search context. Catalog/taxonomy rules belong
   to the Product Collection filtering work that introduces those callers.
4. No option or Site Editor control is added. WooCommerce visibility is a
   product-data contract, not a merchant-selectable search preference.

## Proposed Solution

Replace the hardcoded visibility fragment in
`Shift64_Woo_Search_Query` with a context-aware resolver. Autocomplete, the
search archive, and the block-theme search Product Collection adapter request
`search` visibility, producing:

```text
-@visibility:{hidden|catalog}
```

Unknown contexts fail closed to the existing hidden-only behavior and are
logged in debug mode. The resolver owns escaping and allowed values so callers
cannot inject arbitrary RediSearch syntax.

## Architecture

`Shift64_Woo_Search_Query` remains the only component that renders visibility
clauses. Public query-building entry points receive a small context value;
they do not receive prebuilt query text.

The autocomplete endpoint, current search archive, and its block-native
successor pass `search`. Taxonomy archives retain the hidden-only compatibility
fallback because they are catalog contexts where catalog-only products remain
eligible. No catalog-context branch is added without a real caller and
acceptance tests.

## Data Model

No schema change or rebuild is required. The existing indexed `visibility`
field already contains the required WooCommerce values.

## API Contracts

No REST, SHORTINIT response shape, CLI, block, option, or public hook contract
changes. The public endpoint's default autocomplete mode intentionally changes
result membership to honor WooCommerce's existing search-visibility setting;
the changelog announces that correction. The new visibility context remains an
internal PHP query-service contract.

## Edge Cases & Failure Scenarios

- **Old documents without `visibility`:** retain the existing query behavior
  for missing fields; this spec must not hide all legacy documents before a
  rebuild.
- **Invalid context:** use the hidden-only compatibility fallback and add a
  debug entry; never concatenate the raw value.
- **Redis unavailable:** native WooCommerce search remains authoritative and
  already applies its visibility rules.
- **Catalog-only product bookmarked directly:** direct product access is
  unchanged. The product is removed only from search result membership.

## Risks & Impact Review

- **User-visible correction:** catalog-only products leave Redis-backed search
  results. This is intentional and needs a changelog entry.
- **Blast radius:** shared query construction and search result membership,
  including autocomplete; sorting order, Product Collection pagination, and
  indexing remain unchanged.
- **Rollback:** reverting the resolver restores the previous hidden-only
  exclusion. No stored data or schema must be rolled back.
- **Security:** a closed resolver avoids turning context values into raw
  RediSearch query fragments.

## Phasing

One independently shippable phase: introduce the resolver, adopt it in search,
and document the corrected visibility behavior.

## Implementation Plan

1. Add a pure visibility-context resolver with PHPUnit coverage for `search`,
   compatibility fallback, missing values, and invalid input. Leave current
   callers unchanged.
2. Pass `search` from Redis-backed product-search queries, including
   autocomplete, and assert the built clause excludes `hidden|catalog`.
3. Add integration fixtures proving a catalog-only product is absent from
   Shift64 search and autocomplete while a visible positive control appears
   and the catalog-only product remains directly accessible.
4. Update compatibility documentation and changelog; flip this spec and the
   specs index to implemented in the implementation PR.
