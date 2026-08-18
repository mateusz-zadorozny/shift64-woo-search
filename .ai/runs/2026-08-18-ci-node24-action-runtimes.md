# Execution plan — retire Node.js 20 action runtimes in CI

## Goal

Remove the "Node.js 20 is deprecated … being forced to run on Node.js 24" annotations that GitHub prints on every workflow run, by bumping the four pinned actions that still declare `runs.using: node20` to majors that declare `node24`.

## Context

GitHub's hosted runners no longer provide a Node.js 20 binary for actions; any action whose `action.yml` says `runs.using: node20` is silently forced onto Node.js 24 and the run is annotated. The repository's own pins are mostly current already — `actions/checkout@v6`, `actions/setup-node@v6`, `actions/cache@v5` and `shivammathur/setup-php@v2` all resolve to `node24` — so the noise comes from four remaining references.

Verified runtimes (read from each action's `action.yml` at the pinned ref):

| Reference | File | Current runtime | Target | Target runtime |
|---|---|---|---|---|
| `actions/upload-artifact@v4` | `release.yml` (e2e failure artifacts) | `node20` | `v7` | `node24` |
| `actions/upload-artifact@v4` | `test-env.yml` (launcher diagnostics) | `node20` | `v7` | `node24` |
| `actions/checkout@v4` | `test-env.yml` | `node20` | `v6` | `node24` |
| `amannn/action-semantic-pull-request@v5` | `pr-lint.yml` | `node20` | `v6` | `node24` |

Version-target rationale:

- **`actions/upload-artifact` → `v7`** (latest major). `v5` is *still* `node20` by default despite its "Node 24 support" headline, so it would not silence the warning; `v6` is the first genuinely `node24` major and `v7` only adds an opt-in `archive: false` direct-upload mode plus an internal ESM repackage. Neither changes the semantics of the `name`/`path`/`retention-days`/`if-no-files-found` inputs both call sites use, so going straight to `v7` is no riskier than `v6` and buys a longer runway.
- **`actions/checkout` → `v6`**, not the newer `v7`, deliberately: `v6` already satisfies the Node 24 requirement and it is the exact pin the other six checkout steps in `release.yml` use. Consistency across the repository is worth more here than being on the newest major, and it keeps the diff to the one genuinely deprecated reference.
- **`amannn/action-semantic-pull-request` → `v6`** (latest major). Its only breaking change is the Node 24 + ESM migration itself; the `types` / `requireScope` input contract is unchanged.

`actions/cache@v5`, `actions/setup-node@v6` and `shivammathur/setup-php@v2` are intentionally left alone — they are already on `node24` and bumping them would be unrelated churn.

## Scope

- `.github/workflows/release.yml` — one `uses:` line.
- `.github/workflows/test-env.yml` — two `uses:` lines.
- `.github/workflows/pr-lint.yml` — one `uses:` line.

## Non-goals

- Bumping actions that are already on `node24` just to reach the latest major (`cache@v5` → `v6`, `checkout@v6` → `v7`, `setup-node@v6` → `v7`).
- Introducing Dependabot or any other automated action-update mechanism; that is a separate decision.
- Pinning actions to commit SHAs instead of floating major tags.
- Any change to job logic, matrices, triggers, or the validation gate itself.

## Risks

- **Low.** All four bumps keep the same input contract, so a mis-bump would surface immediately as a workflow-parse or step failure rather than as silent misbehaviour.
- `test-env.yml` is a manual/weekly workflow that is not part of the per-PR gate, so its two bumps cannot be proven green by this PR's checks. They are single-line tag swaps of the same actions proven by `release.yml`, and the workflow can be exercised on demand via `workflow_dispatch` afterwards.
- `pr-lint.yml` runs against this PR itself, so the `amannn` bump is self-verifying: a green "Validate PR title" check on this PR is the proof.

## Implementation Plan

### Phase 1: Bump the deprecated action pins

- 1.1 Bump `actions/upload-artifact@v4` → `@v7` in `.github/workflows/release.yml`.
- 1.2 Bump `actions/checkout@v4` → `@v6` and `actions/upload-artifact@v4` → `@v7` in `.github/workflows/test-env.yml`.
- 1.3 Bump `amannn/action-semantic-pull-request@v5` → `@v6` in `.github/workflows/pr-lint.yml`.

### Phase 2: Verify

- 2.1 Re-scan every `uses:` reference in `.github/workflows/` and confirm no pinned ref still resolves to a `node20` runtime.
- 2.2 Run the full validation gate (`composer validate --strict`, `vendor/bin/phpcs`, `vendor/bin/phpunit`) and confirm the PR's own workflow checks are green.

## Testing

No PHP or JavaScript source changes, so the unit-test rule does not apply — there is nothing in this diff a PHPUnit test could assert. Verification is instead the runtime re-scan of step 2.1 (every remaining pin reads `node24` from its own `action.yml`) plus the live evidence from this PR's checks: `pr-lint` exercises the bumped `amannn` action directly, and the `release.yml` workflow parses and runs its bumped `upload-artifact` step path.

## Progress

PR: #65

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Bump the deprecated action pins

- [x] 1.1 Bump upload-artifact in release.yml — 271a0a8
- [x] 1.2 Bump checkout and upload-artifact in test-env.yml — 271a0a8
- [x] 1.3 Bump action-semantic-pull-request in pr-lint.yml — 271a0a8

### Phase 2: Verify

- [x] 2.1 Re-scan workflow action runtimes for node20 — 271a0a8
- [x] 2.2 Run the full validation gate — 271a0a8
