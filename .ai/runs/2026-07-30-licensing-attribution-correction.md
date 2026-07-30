# Licensing Attribution Correction

Source doc: .ai/specs/2026-07-30-licensing-attribution-correction.md

## Goal

Make the repository assert one licensing claim consistently — **GPLv2 or later,
copyright Shift64 (Mateusz Zadorożny)** — across `LICENSE`, the main plugin
header, and the mu-plugin bootstrap header, so a reader is no longer told the
code belongs to the WordPress contributors.

## Scope

- Replace `LICENSE` with the verbatim FSF GPL-2.0 text (Version 2, June 1991),
  preceded by a Shift64 copyright notice and the "version 2 or, at your option,
  any later version" paragraph.
- Extend both plugin headers with `Plugin URI`, `Author URI`, `License`, and
  `License URI`, the licensing values byte-identical to `readme.txt:8-9`.
- Add a regression test that pins every place stating the license claim, in the
  style of the existing `tests/test-php-requirement-declarations.php` guard.
- Prove `build-release.sh`'s line-anchored version sync still works against the
  extended headers.
- Flip the spec's `Status:` header and its `.ai/specs/README.md` index row, as
  AGENTS.md's spec lifecycle requires of an implementing PR.

## Non-goals

- Do not change the license *terms*. They were GPL-2.0-compatible before and
  after; only the attribution changes.
- Do not touch the declared runtime minimums (`Requires at least`,
  `Requires PHP`, `composer.json` `config.platform`) — that contract belongs to
  the readme.txt Publication Readiness spec.
- Do not link a product-page URL. Both `Plugin URI` and `Author URI` point at
  `https://shift64.com` bare until such a page exists.
- Do not alter `build-release.sh`, its exclusion list, or any runtime code path,
  hook, option, Redis key, or CLI command.

## Implementation Plan

### Phase 1: Assert one licensing claim

- 1.1 Replace `LICENSE` with the verbatim FSF GPL-2.0 text plus the Shift64 copyright notice.
- 1.2 Extend `shift64-woo-search.php`'s header with `Plugin URI`, `Author URI`, `License`, and `License URI`.
- 1.3 Extend `mu-plugins/shift64-woo-search-bootstrap.php`'s header with the same four fields.
- 1.4 Add `tests/test-license-declarations.php` pinning the license claim across all three files plus `composer.json` and `readme.txt`.

### Phase 2: Prove the release path and close the spec

- 2.1 Run `bash build-release.sh 0.12.2` on a clean tree and confirm the version sync stays idempotent, then delete the produced ZIP.
- 2.2 Flip the spec's `Status:` header to `implemented` and update its `.ai/specs/README.md` index row.
- 2.3 Run the full validation gate (`composer validate --strict`, `vendor/bin/phpcs`, `vendor/bin/phpunit`) and confirm it matches the pre-change baseline.

## Risks

**Blast radius: metadata only.** No behavioral surface in
`BACKWARD_COMPATIBILITY.md` is affected, and no runtime code changes.

The one adjacent protected mechanism is `build-release.sh`'s version-sync
regexes, anchored on `^ \* Version: ` in both plugin files. New header fields go
above or below those lines and must never split, duplicate, or reflow them —
Step 2.1 makes that a proof rather than an assumption.

Pre-change baseline recorded before any edit, for the Step 2.3 comparison:
`composer validate --strict` clean, `vendor/bin/phpcs` 8/8 files clean,
`vendor/bin/phpunit` OK with 528 tests and 7840 assertions.

**Rollback.** Text-only, no generated artifact and no state; `git revert` fully
restores the prior files, and the outgoing `LICENSE` remains in history at
`c821b5b`. No release needs re-cutting.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Assert one licensing claim

- [x] 1.1 Replace `LICENSE` with the verbatim FSF GPL-2.0 text plus the Shift64 copyright notice. — 5c8ee00
- [x] 1.2 Extend `shift64-woo-search.php`'s header with `Plugin URI`, `Author URI`, `License`, and `License URI`. — 7735801
- [x] 1.3 Extend `mu-plugins/shift64-woo-search-bootstrap.php`'s header with the same four fields. — 3363831
- [x] 1.4 Add `tests/test-license-declarations.php` pinning the license claim across all three files plus `composer.json` and `readme.txt`. — 38bad19

### Phase 2: Prove the release path and close the spec

- [ ] 2.1 Run `bash build-release.sh 0.12.2` on a clean tree and confirm the version sync stays idempotent, then delete the produced ZIP.
- [ ] 2.2 Flip the spec's `Status:` header to `implemented` and update its `.ai/specs/README.md` index row.
- [ ] 2.3 Run the full validation gate (`composer validate --strict`, `vendor/bin/phpcs`, `vendor/bin/phpunit`) and confirm it matches the pre-change baseline.
