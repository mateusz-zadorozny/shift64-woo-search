# Context-Aware Product Visibility Implementation

Source doc: .ai/specs/2026-07-30-context-aware-product-visibility.md

## Goal

Make Redis-backed product search, including autocomplete, exclude WooCommerce
products whose catalog visibility is either `hidden` or `catalog`, while
preserving the existing hidden-only behavior for non-search callers that do
not opt into a visibility context.

## Scope

- Add a closed, injection-safe visibility policy resolver to the shared query
  service.
- Apply the `search` visibility context to autocomplete and full-search query
  building, the product search archive fallback chain, and search-archive
  facet queries.
- Add unit coverage and live WooCommerce fixtures that prove a catalog-only
  product is absent from search surfaces, a visible positive control remains,
  and the catalog-only product stays directly accessible.
- Update search compatibility documentation, the changelog, and the spec
  lifecycle metadata.

## Non-goals

- Do not introduce catalog or taxonomy visibility contexts before a concrete
  Product Collection caller exists.
- Do not change indexing schema, stored documents, Redis setup, merchant
  settings, direct product access, or WooCommerce's native fallback behavior.
- Do not add the Playwright suite to the hermetic agentic validation gate.

## Implementation Plan

### Phase 1: Define the visibility contract

- 1.1 Add and unit-test a pure visibility resolver covering search, compatibility fallback, missing values, validated explicit exclusions, and invalid input.

### Phase 2: Adopt search visibility

- 2.1 Thread the search context through full-search builders, the Redis-backed product search archive, and search facet construction without changing autocomplete or taxonomy callers.

### Phase 3: Prove the WooCommerce behavior

- 3.1 Add a deterministic catalog-only E2E product fixture and assert that Redis search excludes it while its direct product permalink remains accessible.

### Phase 4: Document and close the spec

- 4.1 Update compatibility documentation and the changelog, then mark the source spec and specs index implemented by this PR.

### Phase 5: Address independent review

- 5.1 Align autocomplete visibility with the results archive, add a visible E2E positive control, simplify facet query forwarding, and update affected documentation.

## Risks

- A context propagated too broadly could unintentionally remove catalog-only
  products from taxonomy archives; focused query-string tests guard that
  compatibility boundary while autocomplete intentionally adopts the search
  policy.
- Facet queries must use the same visibility policy as their result set or
  counts can advertise products that the archive cannot render.
- RediSearch TAG syntax must never contain caller-controlled fragments; the
  resolver accepts only known contexts or a fully validated allowlisted set.

## Progress

PR: #46

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Define the visibility contract

- [x] 1.1 Add and unit-test a pure visibility resolver covering search, compatibility fallback, missing values, validated explicit exclusions, and invalid input. — 875e640

### Phase 2: Adopt search visibility

- [x] 2.1 Thread the search context through full-search builders, the Redis-backed product search archive, and search facet construction without changing autocomplete or taxonomy callers. — c662460

### Phase 3: Prove the WooCommerce behavior

- [x] 3.1 Add a deterministic catalog-only E2E product fixture and assert that Redis search excludes it while its direct product permalink remains accessible. — 48f45a5

### Phase 4: Document and close the spec

- [x] 4.1 Update compatibility documentation and the changelog, then mark the source spec and specs index implemented by this PR. — 0af3a40

### Phase 5: Address independent review

- [ ] 5.1 Align autocomplete visibility with the results archive, add a visible E2E positive control, simplify facet query forwarding, and update affected documentation.
