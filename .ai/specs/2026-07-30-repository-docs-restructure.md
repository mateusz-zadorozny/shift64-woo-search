# Repository Docs Restructure

> **Status:** draft

## TLDR

`README.md` serves three audiences at once, links internal commercial planning docs to
first-time visitors, carries no badges, and duplicates `CONTRIBUTING.md`'s command list —
while `CONTRIBUTING.md` omits the `om-*` pipeline as the primary contribution path and both it
and `USING_OM_SKILLS.md` still claim `.ai/agentic.config.json` does not exist. This spec adds a
shift64.com-led badge row, splits README by audience, and rewrites CONTRIBUTING around the
skills pipeline.

## Problem Statement

1. **No shift64.com anywhere in `README.md`, and no badges.** The repository's most-read page
   carries nothing identifying the vendor and no at-a-glance license, release, or CI signal.
2. **README mixes three audiences.** Requirements and shortcode reference (end users), install
   and command list (contributors), and distribution strategy (evaluators) are interleaved
   with no ordering.
3. **README exposes internal planning.** `README.md:107` links
   `docs/product-roadmap.md` and `docs/hosted-mvp-plan.md` — which contain pilot capacity
   figures ("Start with 5–8 stores, not 50"), per-tenant cost reasoning, refund terms, and
   Stripe plans. `docs/distribution-and-commercial-plan.md` is the one strategy doc written
   for an outside reader.
4. **One de-duplication, currently split across two files.** `README.md:86-94`
   (§Development commands) and `CONTRIBUTING.md:12-19` (§Development workflow) overlap. README
   should own the raw command list; CONTRIBUTING should own process and stop restating it.
5. **`CONTRIBUTING.md` under-sells the pipeline and states a falsehood.** It calls the `om-*`
   skills "optional" behind the manual workflow, and `CONTRIBUTING.md:5-10` +
   `USING_OM_SKILLS.md:22-27` both say `.ai/agentic.config.json` "does not exist in this
   repository yet". **It exists** — `validation.commands` is
   `composer validate --strict`, `vendor/bin/phpcs`, `vendor/bin/phpunit`. Every new
   contributor is currently sent through an unnecessary setup interview.
6. **`CONTRIBUTING.md` omits two mandatory laws from `AGENTS.md`** — the `.ai/specs/` Status
   flip and the Playwright-never-in-the-gate rule — so the only place they are written down is
   a file aimed at agents, not at people.

## Proposed Solution

Badge row first (the visible deliverable), then a README ordered users → contributors →
strategy, then a CONTRIBUTING rewritten with the pipeline as the primary path and the manual
workflow retained as the fallback. Findings 4 and 5 are two halves of one edit and land in the
same phase — shipping either alone leaves the repo either duplicated or holding an orphaned
reference.

The shift64.com presence is **attribution, not promotion**:
`docs/distribution-and-commercial-plan.md:24` sets the constraint — the plugin is GPL and
fully useful alone; commercial value comes from managed hosting, not from crippling the
directory build. A badge and a link, not an upsell.

## Architecture

Documentation only. No runtime code, no build-script change — `build-release.sh`'s
`--exclude='*.md'` already keeps all three files out of the release ZIP, so
`CONTRIBUTING.md:47`'s claim about ZIP contents stays true.

| File | Change |
| --- | --- |
| `README.md` | Badge row; audience split; internal-planning links removed; owns the command list |
| `CONTRIBUTING.md` | Pipeline-first; spec Status-flip law; E2E/PHPCS rules; drops the command list |
| `USING_OM_SKILLS.md` | Corrects the "config does not exist" claim |

`USING_OM_SKILLS.md` is in scope: it is the file `CONTRIBUTING.md` sends contributors to, and
it carries the same false claim. Fixing one without the other just moves the error.

### Badge row

Four static-or-cheap Shields.io badges directly under the `# Shift64 Woo Search` H1,
shift64.com first:

```markdown
[![Shift64](https://img.shields.io/badge/Shift64-search-000000?style=flat-square)](https://shift64.com)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue?style=flat-square)](LICENSE)
[![Release](https://img.shields.io/github/v/release/mateusz-zadorozny/shift64-woo-search?style=flat-square)](https://github.com/mateusz-zadorozny/shift64-woo-search/releases)
[![CI](https://img.shields.io/github/actions/workflow/status/mateusz-zadorozny/shift64-woo-search/release.yml?branch=main&style=flat-square&label=CI)](https://github.com/mateusz-zadorozny/shift64-woo-search/actions/workflows/release.yml)
```

The CI badge targets `release.yml` (whose workflow `name:` is `CI`) on `main`, **not**
`pr-lint.yml`. Badges render only on GitHub; nothing shipped fetches them.

### README section order

```text
Badges → What it is → Requirements → Install → Blocks & shortcodes   (users)
       → Development: setup + commands, → CONTRIBUTING.md, → AGENTS.md (contributors)
       → Architecture pointer (→ docs/search-architecture.md)
       → Distribution (→ docs/distribution-and-commercial-plan.md only)
       → License
```

`docs/product-roadmap.md` and `docs/hosted-mvp-plan.md` stay on disk and stay reachable from
`docs/README.md`; they are simply no longer surfaced to first-time visitors.

## Edge Cases & Failure Scenarios

| Scenario | Behavior |
| --- | --- |
| `release.yml` renamed, or its `name:` changed | The CI badge renders "workflow not found". Cosmetic, but note the coupling in a README comment so a future workflow rename is caught. |
| Shields.io blocked on a corporate network | Alt text and links still work; nothing else is affected. |

## Risks & Impact Review

**Blast radius: three Markdown files.** No protected surface in
`BACKWARD_COMPATIBILITY.md`, no behavior, no metadata WordPress or wp.org reads. The badge
markup introduces the only external HTTP references in the repository's docs, all
GitHub-render-time.

**Rollback.** One commit per phase, text-only. `git revert` restores it.

**Depends on nothing, but reads better after
[Licensing Attribution Correction](2026-07-30-licensing-attribution-correction.md):** the
license badge links `LICENSE`, which until that spec lands still names the WordPress
contributors. The badge is correct either way — `composer.json` already declares
`GPL-2.0-or-later` — but a visitor who clicks through before A ships sees the wrong copyright
holder. Prefer shipping A first; do not block on it.

## Implementation Plan

### Phase 1 — Badge row and README audience split

1. **Insert the badge row** immediately under the H1, in the order shift64.com → license →
   release → CI, using the markup above.
   *Test:* four badges render on GitHub; every link resolves (shift64.com, `LICENSE`,
   `/releases`, `/actions/workflows/release.yml`); the license badge text matches
   `composer.json`'s `GPL-2.0-or-later`.
2. **Reorder sections** to the §Architecture layout: user-facing content before contributor
   content.
   *Test:* no section appears twice; every relative link resolves to a path that exists.
3. **Remove the internal-planning links** from §Distribution direction, keeping only
   `docs/distribution-and-commercial-plan.md`.
   *Test:* `grep -c 'product-roadmap\|hosted-mvp-plan' README.md` returns `0`; both files
   still exist and are still linked from `docs/README.md`.

### Phase 2 — The de-duplication and CONTRIBUTING rewrite

Steps 1 and 2 are the two halves of one move and must land together.

1. **README §Development keeps the raw command list** and links `CONTRIBUTING.md` for process,
   `AGENTS.md` for conventions.
   *Test:* no workflow prose — branching, commit format, review, release policy — remains in
   `README.md`.
2. **`CONTRIBUTING.md` drops the duplicated command list**, keeping `composer test` /
   `composer lint` as a named review gate rather than a second reference listing.
   *Test:* the commands appear in exactly one of the two files as a reference list; no
   dangling "see above" or orphaned reference in either.
3. **Lead `CONTRIBUTING.md` with the pipeline.** `/om-auto-create-pr` for work,
   `/om-auto-review-pr` for review, `USING_OM_SKILLS.md` for the map. State that
   `.ai/agentic.config.json` exists, so `/om-setup-agent-pipeline` is re-run only when the
   toolchain or label taxonomy changes. Keep the manual workflow as an explicit fallback.
   *Test:* `.ai/agentic.config.json` is present on disk and no sentence claims otherwise; the
   named validation commands match its `validation.commands`
   (`composer validate --strict`, `vendor/bin/phpcs`, `vendor/bin/phpunit`).
4. **Correct `USING_OM_SKILLS.md:22-27`** — the "That file does not exist in this repository
   yet" paragraph and the setup-is-a-prerequisite framing that follows it. Verify the stated
   skill count against `.agents/skills/` (currently 27 — correct, confirm rather than assume).
   *Test:* the stated count equals `ls .agents/skills | wc -l`; no text says the config is
   absent.
5. **Add the spec lifecycle law** from `AGENTS.md`: a PR implementing a spec flips the spec's
   `> **Status:**` header **and** its `.ai/specs/README.md` row in the same PR; spec files are
   never moved, renamed, or deleted.
   *Test:* both halves are stated, and the wording matches `.ai/specs/README.md`'s own
   statement of the rule.
6. **Add the E2E and PHPCS rules** from `AGENTS.md`: Playwright never joins
   `.ai/agentic.config.json` `validation.commands` (the gate stays hermetic; the degraded
   project would corrupt the dev site); `npm run test:e2e` mutates a live site and needs
   `bin/e2e-provision.sh`; run `vendor/bin/phpcs` before requesting review.
   *Test:* the claims match `AGENTS.md` §E2E, and `validation.commands` contains no Playwright
   entry.
