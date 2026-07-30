# Context-Aware Product Visibility Implementation

Source doc: .ai/specs/2026-07-30-context-aware-product-visibility.md

## Goal

Make Redis-backed product search exclude WooCommerce products whose catalog
visibility is either `hidden` or `catalog`, while preserving the existing
hidden-only behavior for autocomplete and callers that do not opt into a
visibility context.

## Scope

- Add a closed, injection-safe visibility policy resolver to the shared query
  service.
- Apply the `search` visibility context to full-search query building, the
  product search archive fallback chain, and search-archive facet queries.
- Add unit coverage and a live WooCommerce fixture that proves a catalog-only
  product is absent from search but remains directly accessible.
- Update search compatibility documentation, the changelog, and the spec
  lifecycle metadata.

## Non-goals

- Do not introduce catalog or taxonomy visibility contexts before a concrete
  Product Collection caller exists.
- Do not change autocomplete membership, indexing schema, stored documents,
  Redis setup, merchant settings, direct product access, or WooCommerce's
  native fallback behavior.
- Do not add the Playwright suite to the hermetic agentic validation gate.

## Implementation Plan

### Phase 1: Define the visibility contract

- [ ] 1.1 Add and unit-test a pure visibility resolver covering search, compatibility fallback, missing values, validated explicit exclusions, and invalid input.

### Phase 2: Adopt search visibility

- [ ] 2.1 Thread the search context through full-search builders, the Redis-backed product search archive, and search facet construction without changing autocomplete or taxonomy callers.

### Phase 3: Prove the WooCommerce behavior

- [ ] 3.1 Add a deterministic catalog-only E2E product fixture and assert that Redis search excludes it while its direct product permalink remains accessible.

### Phase 4: Document and close the spec

- [ ] 4.1 Update compatibility documentation and the changelog, then mark the source spec and specs index implemented by this PR.

## Risks

- A context propagated too broadly could unintentionally remove catalog-only
  products from autocomplete or taxonomy archives; focused query-string tests
  guard those compatibility boundaries.
- Facet queries must use the same visibility policy as their result set or
  counts can advertise products that the archive cannot render.
- RediSearch TAG syntax must never contain caller-controlled fragments; the
  resolver accepts only known contexts or a fully validated allowlisted set.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Define the visibility contract

- [x] 1.1 Add and unit-test a pure visibility resolver covering search, compatibility fallback, missing values, validated explicit exclusions, and invalid input. — 875e640

### Phase 2: Adopt search visibility

- [ ] 2.1 Thread the search context through full-search builders, the Redis-backed product search archive, and search facet construction without changing autocomplete or taxonomy callers.

### Phase 3: Prove the WooCommerce behavior

- [ ] 3.1 Add a deterministic catalog-only E2E product fixture and assert that Redis search excludes it while its direct product permalink remains accessible.

### Phase 4: Document and close the spec

- [ ] 4.1 Update compatibility documentation and the changelog, then mark the source spec and specs index implemented by this PR.
