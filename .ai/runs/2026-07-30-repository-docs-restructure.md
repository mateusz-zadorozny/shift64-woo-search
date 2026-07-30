# Execution plan — Repository Docs Restructure

Source doc: .ai/specs/2026-07-30-repository-docs-restructure.md
Engine: om-auto-create-pr (steps: 10, --loop: no)

## Goal

Make `README.md` a vendor-attributed, audience-ordered entry point (badges first,
users → contributors → strategy, no internal commercial planning links), turn
`CONTRIBUTING.md` into a pipeline-first process document that stops duplicating
README's command list and finally writes down the two mandatory laws currently
living only in `AGENTS.md`, and correct the false "the config does not exist"
claim in both `CONTRIBUTING.md` and `USING_OM_SKILLS.md`.

## Scope

- `README.md` — badge row, section reordering, internal-planning links removed,
  owns the raw development command list.
- `CONTRIBUTING.md` — pipeline-first framing, spec Status-flip law, E2E/PHPCS
  rules, drops the duplicated command list.
- `USING_OM_SKILLS.md` — the "pipeline is not configured yet" section corrected;
  skill count verified against `.agents/skills/`.
- `.ai/specs/2026-07-30-repository-docs-restructure.md` and
  `.ai/specs/README.md` — the spec lifecycle flip this PR owes per `AGENTS.md`.

### Non-goals

- No runtime code, no build-script change. `build-release.sh`'s `--exclude='*.md'`
  already keeps all three documents out of the release ZIP.
- `docs/product-roadmap.md` and `docs/hosted-mvp-plan.md` stay on disk and stay
  linked from `docs/README.md`; they are only unlinked from `README.md`.
- No change to `AGENTS.md` itself — CONTRIBUTING restates its laws for humans,
  it does not relocate them.

## Autonomous decisions

- **Skill count.** The spec says "currently 27 — confirm rather than assume".
  `ls .agents/skills | wc -l` reports **38**, which is exactly what
  `USING_OM_SKILLS.md:3` already states, so that sentence needs no change; only
  the "not configured yet" section is wrong.
- **Stale ZIP bullet.** `USING_OM_SKILLS.md`'s "Things that will bite you"
  bullet tells the reader to add `.agents` and `.ai` to the rsync exclude list;
  `build-release.sh:32-33` already excludes both. It is the same class of defect
  as finding 5 (a doc asserting a repo state that is no longer true) in a file
  already in scope, so it is corrected in the same pass and noted here.

## Implementation Plan

### Phase 1 — Badge row and README audience split

1. Insert the badge row under the H1 (shift64.com → license → release → CI),
   with an HTML comment recording the `release.yml` coupling from the spec's
   Edge Cases table.
2. Reorder README sections to users → contributors → architecture → distribution
   → license.
3. Remove the `docs/product-roadmap.md` / `docs/hosted-mvp-plan.md` links from
   §Distribution, keeping only `docs/distribution-and-commercial-plan.md`.

### Phase 2 — De-duplication and the CONTRIBUTING rewrite

1. README §Development keeps the raw command list and points at
   `CONTRIBUTING.md` for process and `AGENTS.md` for conventions.
2. `CONTRIBUTING.md` drops the duplicated command list, keeping
   `composer test` / `composer lint` named as the review gate.
3. Lead `CONTRIBUTING.md` with the `om-*` pipeline; state that
   `.ai/agentic.config.json` exists and name its `validation.commands`; keep the
   manual workflow as an explicit fallback.
4. Correct `USING_OM_SKILLS.md`'s "does not exist yet" section and its stale
   release-ZIP bullet; verify the skill count against `.agents/skills/`.
5. Add the spec lifecycle law (Status header + `.ai/specs/README.md` row in the
   same PR; specs are never moved, renamed, or deleted) to `CONTRIBUTING.md`.
6. Add the E2E and PHPCS rules (Playwright never joins `validation.commands`;
   `npm run test:e2e` needs `bin/e2e-provision.sh`; run `vendor/bin/phpcs`
   before requesting review) to `CONTRIBUTING.md`.

### Phase 3 — Spec lifecycle

1. Flip the spec's `> **Status:**` header and its `.ai/specs/README.md` row.

## Risks

Documentation only; blast radius is five Markdown files, none of which ships in
the release ZIP or is read by WordPress. The badge markup adds the repository's
first external HTTP references in docs, all resolved at GitHub render time. The
license badge links `LICENSE`, which still carries the pre-correction copyright
holder until the Licensing Attribution Correction spec lands — the badge text
itself matches `composer.json`'s `GPL-2.0-or-later` either way. Rollback is
`git revert`.

## Progress

PR: #52

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Badge row and README audience split

- [x] 1.1 Insert the badge row — 9d23edb
- [x] 1.2 Reorder README sections by audience — 9d23edb
- [x] 1.3 Remove internal-planning links from §Distribution — 9d23edb

### Phase 2: De-duplication and the CONTRIBUTING rewrite

- [x] 2.1 README §Development owns the command list — 9d23edb
- [x] 2.2 CONTRIBUTING drops the duplicated command list — 5236002
- [x] 2.3 CONTRIBUTING leads with the pipeline and states the config exists — 5236002
- [x] 2.4 Correct USING_OM_SKILLS.md — 5236002
- [x] 2.5 Add the spec lifecycle law to CONTRIBUTING — 5236002
- [x] 2.6 Add the E2E and PHPCS rules to CONTRIBUTING — 5236002

### Phase 3: Spec lifecycle

- [x] 3.1 Flip the spec Status header and the specs index row — 722e96c
