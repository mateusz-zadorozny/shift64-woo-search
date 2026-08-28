# Product Search Relevance Browser Test

> **Status:** draft

## 📝 TLDR

Add a Playwright browser contract for the relevance-ranking behavior delivered
by PR #90. The test will verify that the block-theme Product Collection, the
header autocomplete dropdown, and paginated results agree on the deterministic
seeded catalog ordering.

This is an E2E-only follow-up. It changes no production behavior, public API,
catalog data, or theme configuration.

## Resolved assumptions (autonomous defaults)

The follow-up brief left these design choices open. They are resolved here so
implementation can proceed without another planning round:

| Question | Autonomous default | Rationale |
| --- | --- | --- |
| Where should the test live? | Add one new file, `tests/e2e/specs/search-relevance-ordering.spec.ts`, to the existing `main` Playwright project. | The changed behavior is visible in the block-theme Product Collection and the existing main project already provisions the shared catalog and search page. A new project would add orchestration without adding coverage. |
| What should the order assertions use? | Assert the deterministic seeded product titles for the first four results on pages 1 and 2, and assert that the header dropdown's first four titles equal page 1. | Exact fixture assertions catch a regression in rank-before-pagination as well as drift between the archive and autocomplete. The fixture contract is already deterministic and should be updated alongside any intentional catalog-generator change. |
| Should classic-theme coverage be added? | No. Keep this spec focused on the block-theme Product Collection surface. | Existing classic-theme and block-theme suites cover pagination ownership and classic markup separately; duplicating the full relevance journey would broaden this follow-up without testing the PR's distinct failure mode. |
| Should new catalog fixtures be created? | No. Reuse the provisioned 48-product catalog, where `series` yields three pages at 16 products per page. | `bin/e2e-provision.sh` already creates the required multi-page, deterministic dataset and rebuilds the plugin index. Reusing it keeps setup and teardown centralized. |

## 📝 Problem Statement

PR #90 fixed a user-visible ordering defect by preserving relevance ranking
before the result set is sliced into pages. The existing browser suite checks
that a relevance sort control is rendered and exercises the autocomplete
dropdown independently, but it does not prove the complete user journey:

1. the first page contains the expected leading products;
2. the header autocomplete presents those same leading products;
3. page 2 contains the next ranked products rather than a repeated or
   independently ordered slice; and
4. the block-theme results shell remains usable at a narrow viewport.

Without this contract, a future change could reintroduce raw Redis ordering,
apply pagination before ranking, or make the archive and autocomplete disagree
while the current unit tests and isolated UI smoke tests still pass.

## 📝 Proposed Solution

Add `tests/e2e/specs/search-relevance-ordering.spec.ts` to the existing
Playwright `main` project. The test uses the repository's real provisioned
WordPress site, block theme, Redis index, and seeded catalog. It does not mock
the search endpoint or inspect internal Redis state.

The implementation should define the expected fixture contract in one place:

```ts
const PAGE_ONE = [
  'Aero Artemis Sweater Lightweight Midnight Black',
  'Aero Cipher Laptop Ultra Wide Space Grey',
  'Aero Cascade Micellar Cleanser Intensive Rose',
  'Lumen Apollo Cardigan Everyday Forest Green',
];

const PAGE_TWO = [
  'Onyx Nike Skirt Ribbed Cobalt Blue',
  'Onyx Spectra Smartphone Fast Charge Ruby Red',
  'Onyx Serene Conditioner Lightweight Coconut',
  'Crest Maia Jacket Brushed Deep Navy',
];
```

These values were observed in the deterministic QA catalog after PR #90. The
test should fail clearly if the fixture generator changes them; the expected
arrays and the generator comments must then be reviewed together.

### Scenario A — archive and header autocomplete share relevance order

1. Open `/?s=series&post_type=product`.
2. Wait for the Product Collection shell and first product card to be visible.
3. Assert the search-results heading, product grid, and first four product
   headings equal `PAGE_ONE` in order.
4. Open the provisioned header product-search control, fill `series`, and wait
   for the `mode=autocomplete` response and visible result tray.
5. Assert the first four product rows in the header tray equal `PAGE_ONE` in
   order. Close the tray and verify the page remains on the archive.

Use the existing selectors and helpers in `tests/e2e/helpers/search.ts`.
Address the header instance explicitly with
`E2E_HEADER_INLINE_INSTANCE` rather than selecting the first generic search
field: the provisioned header contains its own search blocks. Prefer
accessible roles and names for the heading, listbox, close control, and
pagination; use the stable instance id only to disambiguate duplicate blocks.

### Scenario B — pagination preserves ranked membership

1. Open `/?s=series&post_type=product` and assert the first page contract.
2. Click the visible Page 2 link through the user-facing pagination control.
3. Assert the URL matches `/page/2/?s=series&post_type=product` (allowing the
   block theme's existing query-page representation only if the current
   Product Collection emits it), the current page is 2, and the first four
   headings equal `PAGE_TWO` in order.
4. Assert the first four page-2 headings do not equal the first four page-1
   headings, and assert working Previous, Page 1, Page 3, and Next controls.

The test should use web-first assertions after navigation, not fixed delays.
It should not assert that the plugin owns the navigation: ownership is already
covered by `tests/e2e/block-theme/blockified.spec.ts`.

### Scenario C — mobile shell remains usable

1. Set the viewport to 390 × 844 before opening the broad search URL.
2. Assert the search-results heading, at least one product card, the header
   search control, and the pagination shell are visible.
3. Assert the document does not introduce horizontal overflow
   (`document.documentElement.scrollWidth <= window.innerWidth`).

This is a shell and responsiveness check, not a second complete ranking
journey. It should reuse the same provisioned environment and leave all
fixture/site state untouched.

## 📝 Research

Playwright's locator guidance recommends user-facing roles and labels first,
with explicit test ids or stable selectors only when they represent a real
contract. Its web-first assertions automatically retry until the expected UI
state is reached, which is important for the Product Collection router and the
debounced autocomplete request:

- [Playwright locators](https://playwright.dev/docs/locators)
- [Playwright assertions](https://playwright.dev/docs/api/class-playwrightassertions)
- [Playwright best practices](https://playwright.dev/docs/best-practices)

WooCommerce documents Product Collection as the block used to render product
catalog results and pagination. This spec therefore validates the rendered
collection and its navigation rather than reaching into plugin internals:

- [WooCommerce Product Collection block](https://woocommerce.com/document/woocommerce-store-editing/customizing-shop-page-catalog/product-collection-block/)
- [WooCommerce Product Collection developer documentation](https://developer.woocommerce.com/docs/block-development/extensible-blocks/product-collection-block/)

The repository's existing E2E helpers and `bin/e2e-provision.sh` remain the
authoritative local conventions for selectors, fixtures, teardown, and the
`series` query.

## 📝 Architecture

This change adds browser coverage only:

```text
e2e-provision.sh
  ├─ deterministic 48-product catalog
  ├─ Redis index rebuild
  └─ block-theme header + Product Collection
       └─ search-relevance-ordering.spec.ts
            ├─ archive order ↔ header autocomplete order
            ├─ ranked page 1 ↔ ranked page 2
            └─ 390px result-shell smoke check
```

The spec runs in the existing `main` project with its single worker. Each test
gets a fresh Playwright page. No network interception, Redis commands,
production code hooks, or test-only mu-plugins are needed. The implementation
may add a small header-input helper to `tests/e2e/helpers/search.ts` if that
keeps instance scoping consistent; it must not duplicate selector strings in
the new spec.

## 📝 Data Model

There is no new persistent model. The test consumes the existing seeded
catalog:

- 48 deterministic demo products;
- the shared description phrase makes `series` match all 48;
- 16 products per page, producing three pages; and
- the plugin search index rebuilt by the E2E provisioning script.

If the generator changes the count, page size, or leading names, the
implementation PR must update the fixture contract and its explanatory
comments in the same change. The test must not create ad hoc products or
leave index entries behind.

## 📝 API Contracts

No public API changes are proposed. The test-visible contracts are:

| Surface | Contract |
| --- | --- |
| Results page | `/?s=series&post_type=product` renders the ranked first page. |
| Page 2 | `/page/2/?s=series&post_type=product` (or the current Product Collection's equivalent query-page URL) renders the next ranked slice. |
| Header autocomplete | The header instance identified by `E2E_HEADER_INLINE_INSTANCE` opens a visible product tray for `series`; its first four titles match the first four archive titles. |
| Provisioning | `BASE_URL` points to the isolated provisioned site and the Redis index is rebuilt before the suite. |

The browser test should wait for the actual autocomplete request using the
existing `isAutocompleteRequest` helper, so a stale suggestion response cannot
count as a passing product-search assertion.

## 📝 UI/UX

No user-facing UI is proposed or changed by this spec. The UI contract being
protected is the current one:

- the results heading and product cards are visible after a cold load;
- the header search control accepts `series` and shows a product result tray;
- the first four archive and dropdown titles are ordered consistently;
- page 2 exposes a distinct ranked result set and usable previous/next links;
- the 390px viewport retains a readable, non-overflowing result shell.

Assertions should use the visible labels and accessible roles exposed by the
current markup. A selector should be changed only when the corresponding
rendered contract changes, not to make a failing assertion less precise.

## 📝 Edge Cases & Failure Scenarios

- Redis returns raw insertion order, so the first page's leading title differs
  from `PAGE_ONE`.
- Ranking is applied after slicing, so page 2 contains products that should
  have appeared on page 1.
- The archive is correctly ranked but the header autocomplete uses a different
  order, limit, or visibility scope.
- The page-2 link is absent, points at the wrong query, or leaves the current
  page indicator at 1.
- Page 2 repeats page 1, renders an empty collection, or lacks working
  previous/next links.
- The header locator accidentally targets the duplicate search block in the
  page body instead of the provisioned header instance.
- The autocomplete response is slow or fails; the test must report a timeout
  or missing tray, never convert it into a pass with a fixed sleep.
- The seeded catalog or index is stale; provisioning/rebuild failure should
  be diagnosed as an environment failure rather than hidden by weakening the
  expected arrays.
- The narrow viewport introduces horizontal overflow or hides the primary
  result shell.

## 📝 Risks & Impact Review

| Area | Assessment | Mitigation |
| --- | --- | --- |
| Runtime behavior | No production runtime risk; this is test-only. | Keep all changes under `tests/e2e/` and its helper module. |
| Fixture coupling | Exact titles intentionally couple the test to the deterministic catalog. | Keep the arrays beside a comment pointing to `bin/demo-product-catalog.php`; update both only with an intentional fixture change. |
| Test flakiness | Router navigation and debounce timing can be asynchronous. | Use request predicates, locator auto-waiting, URL assertions, and one shared worker; never fixed delays. |
| Site mutation | The scenario does not need theme switches or catalog writes. | Reuse provisioning and its teardown; do not add per-test site mutations. |
| CI duration | Adds a small number of page loads to the existing main project. | Keep three focused tests and avoid a new project or duplicate classic-theme projection. |

## 📋 Phasing

### Phase 1 — Add the parity contract

Add the new spec and, only if needed, a helper for addressing the header
instance. Implement Scenario A against the existing provisioned catalog.

### Phase 2 — Add ranked pagination and mobile coverage

Implement Scenarios B and C, keeping all three scenarios in the same spec file
so their fixture contract and scope remain obvious. Run the targeted file
against the provisioned block-theme site, then run the repository's normal E2E
project set in CI.

Each phase is independently reviewable and leaves the application behavior
unchanged.

## 📋 Implementation Plan

1. Add `tests/e2e/specs/search-relevance-ordering.spec.ts` with the `series`
   query and the two expected title arrays. Keep fixture comments aligned with
   the existing `search-results-page.spec.ts` convention.
2. Reuse `SEL.productsGrid`, `SEL.rowTitle`, `isAutocompleteRequest`, and the
   existing instance constants. Add a narrowly named header helper only if it
   avoids repeating the header input/listbox selector.
3. Implement Scenario A: cold-load the archive, compare the first four
   headings, open the header control, fill `series`, await autocomplete, and
   compare the first four dropdown headings before closing it.
4. Implement Scenario B: navigate to page 2 through the rendered pagination,
   assert the URL/current-page state, compare `PAGE_TWO`, and verify the
   navigation links.
5. Implement Scenario C at 390px and assert the mobile shell plus no
   horizontal overflow.
6. Run the targeted test with the isolated provisioned environment, for
   example:

   ```bash
   BASE_URL=http://127.0.0.1:8889 npm run test:e2e -- --project=main tests/e2e/specs/search-relevance-ordering.spec.ts
   ```

   Then run `npm run test:e2e` using the repository's normal provisioning and
   teardown flow. The implementation PR should also run the configured static
   checks and PHPUnit gate; no production PHP behavior is expected to change.
7. If a fixture or selector contract changed intentionally, update the
   generator/helper comments and this spec's expected values in the same PR,
   with the reason recorded in the implementation PR.

## 📝 Verification Matrix

| Scenario | Expected evidence | Regression caught |
| --- | --- | --- |
| Archive ↔ header dropdown | Four exact titles match in the same order | Divergent relevance ranking or visibility scope |
| Page 2 | Four exact next-ranked titles, distinct from page 1, working nav | Pagination before ranking, duplicate page, broken route |
| 390px shell | Heading, cards, search control, pagination visible; no horizontal overflow | Responsive layout regression |

## 💥 Breaking Changes

None. This is a design-only, browser-test follow-up with no production code,
API, data, or configuration changes.
