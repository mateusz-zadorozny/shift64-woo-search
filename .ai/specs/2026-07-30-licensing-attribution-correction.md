# Licensing Attribution Correction

> **Status:** implemented — PR #49, 2026-07-30. Execution plan: `.ai/runs/2026-07-30-licensing-attribution-correction.md`.

## TLDR

`LICENSE` is WordPress core's own license file — it opens `WordPress - Web publishing
software` / `Copyright 2011-2026 by the contributors` — while `composer.json` declares
`GPL-2.0-or-later` for Shift64. Neither plugin header declares a license at all. This spec
makes one licensing claim, asserted consistently across three files:
**GPLv2 or later, copyright Shift64 (Mateusz Zadorożny)**.

## Problem Statement

The license terms have been GPL-2.0-compatible since the first commit. The *attribution* has
been wrong for just as long, in two ways that contradict each other:

1. **`LICENSE` names the wrong project and the wrong copyright holders.** It was copied
   verbatim from WordPress core at `c821b5b` ("chore: initialize Shift64 Woo Search 0.1.0")
   and never adapted. A reader of the repository is told the code belongs to the WordPress
   contributors.
2. **`composer.json:5` says `GPL-2.0-or-later` and `readme.txt:8-9` says
   `GPLv2 or later` + the FSF URI** — both on Shift64's behalf. So the repository states two
   different things about ownership depending on which file you open.
3. **Neither plugin header declares a license.** `shift64-woo-search.php:2-13` carries
   `Plugin Name`, `Description`, `Version`, `Author`, `Text Domain`, `Domain Path`,
   `Requires at least`, `Requires PHP`. `mu-plugins/shift64-woo-search-bootstrap.php:2-9`
   carries even less. `License` and `License URI` are what WordPress.org reads to confirm GPL
   compatibility; `Plugin URI` and `Author URI` are the only place the plugin itself points a
   user back at shift64.com.

These three facts are one defect, not three: fixing `LICENSE` while the headers stay silent,
or declaring `License: GPLv2 or later` in a header while `LICENSE` still names WordPress,
leaves the repository self-contradictory. They ship together.

## Proposed Solution

`LICENSE` becomes the verbatim FSF GPL-2.0 text (Version 2, June 1991) with a Shift64
copyright notice and the standard "either version 2 of the License, or (at your option) any
later version" paragraph prepended. Both plugin headers gain `License`, `License URI`,
`Plugin URI`, and `Author URI`. Copyright holder of record everywhere:
`Shift64 (Mateusz Zadorożny)` — the person is already in `Author`, the org is already in
`composer.json`'s package name, and this form satisfies both.

Both `Plugin URI` and `Author URI` point at `https://shift64.com` bare. No product-page URL
is linked until such a page exists.

**Alternative considered — leave `LICENSE` alone since the terms are right.** Rejected: a
shipped license file naming another project's copyright holders is a real defect, and
`readme.txt` already promises GPLv2-or-later on Shift64's behalf. The contradiction is the
problem, not the terms.

## Architecture

No runtime code paths, hooks, options, Redis keys, or CLI commands change.

| File | Change |
| --- | --- |
| `LICENSE` | Replaced: verbatim FSF GPL-2.0 + Shift64 copyright notice |
| `shift64-woo-search.php` | Header gains `Plugin URI`, `Author URI`, `License`, `License URI` |
| `mu-plugins/shift64-woo-search-bootstrap.php` | Same four fields |

The plugin-header field set is *extended*, never redefined: WordPress ignores unknown fields
and reads new ones, and the existing values of `Version`, `Requires at least`, and
`Requires PHP` are untouched. `build-release.sh` already retains `LICENSE` (no extension)
while excluding `*.md`, so the release ZIP picks up the corrected file with no script change.

## Edge Cases & Failure Scenarios

| Scenario | Behavior |
| --- | --- |
| `build-release.sh` run after this spec | Its version sync is line-anchored on `^ \* Version: ` in both plugin files. New header fields must not duplicate or reflow those lines. Step 4 makes this a test. |
| A downstream fork relied on the old `LICENSE` bytes | Terms are unchanged (GPL-2.0-or-later before and after), so no recipient's rights change. Only the attribution differs. |

## Risks & Impact Review

**Blast radius: metadata only.** No behavioral surface in
`BACKWARD_COMPATIBILITY.md` is affected. One adjacent protected mechanism must be respected:
`build-release.sh`'s version-sync regexes, anchored on `^ \* Version: ` in both plugin files.
Adding fields above or below those lines is safe; splitting or duplicating them is not.

`BACKWARD_COMPATIBILITY.md` §10 (runtime requirements declared in the plugin header,
`readme.txt`, and `composer.json` with `config.platform` pinned to `8.3.0`) is **not touched**
by this spec — no minimum changes. That contract belongs to
[readme.txt Publication Readiness](2026-07-30-readme-txt-publication-readiness.md).

**Rollback.** One commit, text-only, no generated artifact and no state. `git revert` fully
restores the prior files, including the outgoing `LICENSE`, which remains in history at
`c821b5b`. No release needs re-cutting.

**Legal note, plainly.** This corrects an attribution error; it does not change the license
*terms*. Nobody's rights change. Because it touches the license file, it ships as its own
commit whose message says exactly that, so the diff is auditable in isolation.

## Implementation Plan

Single phase — the three files are one claim and land in one PR.

1. **Replace `LICENSE`.** Verbatim FSF GPL-2.0 text, preceded by `Shift64 Woo Search` /
   `Copyright (C) 2026 Shift64 (Mateusz Zadorożny)` and the "version 2 or, at your option,
   any later version" paragraph.
   *Test:* the file contains no occurrence of `WordPress - Web publishing software` or
   `Copyright 2011-2026 by the contributors`; it does contain
   `Copyright (C) 2026 Shift64 (Mateusz Zadorożny)`, `GNU GENERAL PUBLIC LICENSE`, and
   `Version 2, June 1991`.
2. **Extend `shift64-woo-search.php`'s header.** Add `Plugin URI: https://shift64.com` after
   `Description`, `Author URI: https://shift64.com` after `Author`,
   `License: GPLv2 or later`, and
   `License URI: https://www.gnu.org/licenses/gpl-2.0.html`.
   *Test:* the `License` and `License URI` values are byte-identical to `readme.txt:8-9`;
   `vendor/bin/phpcs` passes on the file.
3. **Extend `mu-plugins/shift64-woo-search-bootstrap.php`'s header** with the same four
   fields.
   *Test:* `vendor/bin/phpcs` passes; the four values are identical to the main plugin file's.
4. **Prove the release script still syncs.** Run `bash build-release.sh 0.12.0` on an
   otherwise clean tree.
   *Test:* `git diff` shows only an idempotent same-version rewrite; `Version: 0.12.0` and
   `SHIFT64_WOO_SEARCH_VERSION` intact in both plugin files, `Stable tag: 0.12.0` intact in
   `readme.txt`. Delete the produced ZIP afterward.
5. **Run the gate.** `composer test` and `composer lint`.
   *Test:* both pass, unchanged from the pre-change baseline.
