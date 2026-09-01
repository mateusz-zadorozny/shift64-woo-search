# Execution plan — product search relevance browser test

Source doc: .ai/specs/2026-08-28-product-search-relevance-browser-test.md
Engine: om-auto-create-pr (steps: 9, --loop: no)
Base branch: main
Branch: feat/product-search-relevance-browser-test

## 🎯 Goal

Lock the relevance-ranking behavior shipped by PR #84/#90 into a Playwright
browser contract, so a future change cannot reintroduce raw Redis ordering,
slice before ranking, or let the block-theme archive and the header
autocomplete disagree while the unit tests still pass.

## Scope

- Add `tests/e2e/specs/search-relevance-ordering.spec.ts` to the existing
  Playwright `main` project, covering the three scenarios the spec defines
  (archive ↔ header autocomplete parity, ranked pagination, 390 px shell).
- Extend `tests/e2e/helpers/search.ts` only if that avoids repeating the
  header instance's input/listbox selector strings inside the new spec.
- Flip the spec's `> **Status:**` header and its `.ai/specs/README.md` index
  row to `implemented`, as the repository's spec lifecycle rule in `AGENTS.md`
  requires of any PR that implements a spec.

### Non-goals

- No production PHP, JS, or CSS change. This run adds browser coverage only.
- No classic-theme projection of the relevance journey — the spec resolved
  that question explicitly, and `tests/e2e/classic-theme/classic.spec.ts`
  already owns the classic markup contract.
- No new catalog fixtures, mu-plugin fixtures, network interception, or Redis
  commands; the run reuses `bin/e2e-provision.sh`'s deterministic 48-product
  catalog.
- No change to `.ai/agentic.config.json`'s validation gate — `AGENTS.md`
  forbids adding Playwright to it, because the gate must stay hermetic.

## Implementation Plan

### Phase 1 — Parity contract (spec Scenario A)

Add the new spec file with the query constant and the two expected title
arrays, reuse the existing `SEL`/`isAutocompleteRequest`/instance helpers,
and implement the archive ↔ header-autocomplete parity test.

### Phase 2 — Ranked pagination and mobile shell (spec Scenarios B and C)

Add the page-2 ranked-membership test and the 390 px result-shell test to the
same file so the fixture contract and scope stay visible in one place.

### Phase 3 — Verification and spec lifecycle

Run the new file against the worktree's isolated provisioned site, then the
repository's normal E2E project set, run the configured validation gate, and
flip the spec Status header plus the specs index row.

## Risks

- **Fixture coupling.** The exact titles couple the test to
  `bin/demo-product-catalog.php`. Mitigated by keeping the arrays behind a
  comment that names the generator, so an intentional catalog change updates
  both together.
- **Router/debounce timing.** Product Collection navigates through the
  Interactivity API router and the autocomplete request is debounced.
  Mitigated with web-first assertions and a `waitForRequest` predicate rather
  than fixed delays.
- **Observed fixture drift.** The spec's expected arrays were observed on a
  QA catalog before this run; if the provisioned catalog now ranks
  differently, the arrays and the spec document must be corrected together
  and the reason recorded on the PR (spec Implementation Plan step 7).

## Progress

PR: #99

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Parity contract

- [x] 1.1 Add the spec file skeleton with the broad query, the two expected title arrays, and their generator-pointing comments — 379bbc1
- [x] 1.2 Add the header-instance helper to tests/e2e/helpers/search.ts if it avoids duplicating selector strings — 379bbc1
- [x] 1.3 Implement Scenario A — archive first four titles and header autocomplete first four titles agree — 379bbc1

### Phase 2: Ranked pagination and mobile shell

- [x] 2.1 Implement Scenario B — page 2 through the rendered pagination, ranked membership, and working navigation links — 379bbc1
- [x] 2.2 Implement Scenario C — 390x844 shell visibility and no horizontal overflow — 379bbc1

### Phase 3: Verification and spec lifecycle

- [x] 3.1 Run the targeted spec file against the worktree's provisioned site and reconcile any fixture drift — 379bbc1
- [x] 3.2 Run the repository's normal E2E project set — 379bbc1
- [x] 3.3 Run the configured validation gate (composer validate, phpcs, phpunit) — 379bbc1
- [x] 3.4 Flip the spec Status header and the .ai/specs/README.md index row to implemented — 49d9fd4
