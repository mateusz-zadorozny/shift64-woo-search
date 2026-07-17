# Contributing

Shift64 Woo Search uses Conventional Commits, Semantic Versioning, and automated GitHub releases.

## Agent automation

This repository ships a collection of `om-*` skills in `.agents/skills/` that automate the
path from a task to a reviewed pull request. They are optional — the manual workflow below
remains valid. If you want to use them, read [USING_OM_SKILLS.md](USING_OM_SKILLS.md) first:
the pipeline needs a one-time `/om-setup-agent-pipeline` run before any other skill works.

## Development workflow

1. Branch from `main`.
2. Keep source code, comments, user-facing source strings, and maintained documentation in English.
3. Add or update tests for behavior changes.
4. Run `composer test` and `composer lint`.
5. Open a pull request with a Conventional Commit title.
6. Squash-merge after review and successful checks.

Examples:

- `feat: add search form block`
- `fix: preserve filters during pagination`
- `docs: explain managed service boundary`
- `refactor: extract ranking configuration`

`feat` produces a minor release, `fix` and `perf` produce a patch release, and a breaking-change marker produces a major release. Documentation, tests, refactors, chores, and CI changes do not produce a release by default.

## Version ownership

Do not update versions manually during ordinary feature work. Semantic Release calculates the next version and `build-release.sh` synchronizes:

- the plugin header and `SHIFT64_WOO_SEARCH_VERSION`;
- `readme.txt` stable tag;
- `package.json` and `package-lock.json`;
- the release ZIP.

The `0.x` line is for product validation. `1.0.0` marks the stable public contract for configuration, hooks, storage keys, CLI commands, blocks, shortcodes, and migrations.

## Pull request checks

Pull request titles are validated. CI installs locked Composer dependencies and runs the WordPress PHPUnit suite across the supported PHP range. Run PHPCS locally before requesting review.

## Release artifacts

GitHub releases attach `shift64-woo-search.zip`. The ZIP excludes development dependencies, tests, repository automation, and internal Markdown files while retaining the plugin source, `readme.txt`, and license.
