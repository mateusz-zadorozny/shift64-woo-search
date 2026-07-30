# Contributing

Shift64 Woo Search uses Conventional Commits, Semantic Versioning, and automated GitHub releases.

The development commands themselves are listed once, in
[README.md](README.md#development). This document covers process: how work is
started, how it is reviewed, and the repository rules a change has to respect.

## The agent pipeline is the primary path

This repository ships a collection of `om-*` skills in `.agents/skills/` that automate the
path from a task to a reviewed pull request. In a Claude Code session:

- `/om-auto-create-pr "<task>"` — plans the work, implements it in an isolated worktree,
  runs the validation gate, and opens a labeled pull request.
- `/om-auto-review-pr <pr-number>` — reviews a pull request and drives the autofix loop.
- [USING_OM_SKILLS.md](USING_OM_SKILLS.md) — the map of every skill and when to reach
  for it.

The pipeline is already configured: `.ai/agentic.config.json` exists in this repository
and defines the base branch, the label taxonomy, the QA gate, and the validation gate
(`composer validate --strict`, `vendor/bin/phpcs`, `vendor/bin/phpunit`). You do **not**
need to run `/om-setup-agent-pipeline` before your first task — re-run it only when the
toolchain or the label taxonomy changes.

## Manual workflow (fallback)

Working by hand is entirely valid; the pipeline is a convenience, not a gate.

1. Branch from `main`.
2. Keep source code, comments, user-facing source strings, and maintained documentation in English.
3. Add or update tests for behavior changes.
4. Run `composer test` and `composer lint` — the same review gate the pipeline enforces.
5. Open a pull request with a Conventional Commit title.
6. Squash-merge after review and successful checks.

Examples:

- `feat: add search form block`
- `fix: preserve filters during pagination`
- `docs: explain managed service boundary`
- `refactor: extract ranking configuration`

`feat` produces a minor release, `fix` and `perf` produce a patch release, and a breaking-change marker produces a major release. Documentation, tests, refactors, chores, and CI changes do not produce a release by default.

## Spec lifecycle

Specs live in `.ai/specs/` and every file carries a `> **Status:**` line under its title,
mirrored in the [`.ai/specs/README.md`](.ai/specs/README.md) index table. A pull request
that implements a spec MUST flip that spec's Status header (`draft` →
`implemented — PR #N, date`) **and** its index row **in the same pull request**. Spec
files are never moved, renamed, or deleted — their paths are referenced from other specs,
`.ai/runs/` plans, and pull request bodies.

## Tests, E2E, and the validation gate

Run `vendor/bin/phpcs` locally before requesting review.

`npm run test:e2e` runs the Playwright suite against a **live** site provisioned by
`bin/e2e-provision.sh`; `BASE_URL` selects the target. The suite really mutates that
site — the degraded project rewrites its Redis configuration and the `block-theme`
project switches the active theme — so never point it at anything you care about, and
re-run `npm run e2e:provision` if an aborted run leaves the site broken.

For that reason Playwright is **never** added to `.ai/agentic.config.json`'s
`validation.commands`: the agentic gate must stay hermetic. E2E enforcement lives in
CI instead (`.github/workflows/release.yml`, the `e2e` job). See
[AGENTS.md](AGENTS.md) for the full E2E contract, including the pagination ownership
matrix the `block-theme` project encodes.

## Pull request checks

Pull request titles are validated. CI installs locked Composer dependencies and runs the WordPress PHPUnit suite across the supported PHP range.

## Version ownership

Do not update versions manually during ordinary feature work. Semantic Release calculates the next version and `build-release.sh` synchronizes:

- the plugin header and `SHIFT64_WOO_SEARCH_VERSION`;
- `readme.txt` stable tag;
- `package.json` and `package-lock.json`;
- the release ZIP.

The `0.x` line is for product validation. `1.0.0` marks the stable public contract for configuration, hooks, storage keys, CLI commands, blocks, shortcodes, and migrations.

## Release artifacts

GitHub releases attach `shift64-woo-search.zip`. The ZIP excludes development dependencies, tests, repository automation, and internal Markdown files while retaining the plugin source, `readme.txt`, and license.
