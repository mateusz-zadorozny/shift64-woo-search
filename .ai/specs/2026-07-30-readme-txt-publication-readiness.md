# readme.txt Publication Readiness

> **Status:** implemented — PR #50, 2026-07-30

## TLDR

`readme.txt` declares `Stable tag: 0.12.0` but its changelog ends at `= 0.1.0 =`, its FAQ
hardcodes "Version 0.1.0", it is untested past WordPress 6.8, and it declares neither the
WooCommerce dependency nor any external-services position. This spec brings the one
user-facing metadata file in the release ZIP up to the standard that Stage 2 of
`docs/product-roadmap.md` — WordPress.org publication — requires.

## Problem Statement

`readme.txt` is the only documentation file that reaches an installed user:
`build-release.sh` excludes `*.md`, so `README.md` and `CHANGELOG.md` are absent from the ZIP.
Four defects:

1. **The changelog is eleven releases stale.** `== Changelog ==` ends at
   `= 0.1.0 =` — "Initial Shift64 Woo Search development release." Everything from `0.2.0`
   through `0.12.0` lives only in `CHANGELOG.md`, which is not shipped. A user who installs
   the release package can find no changelog at all, in any file.
2. **Compatibility is stale and the hard dependency is undeclared.**
   `Tested up to: 6.8`. There is no `Requires Plugins: woocommerce`, even though
   `shift64-woo-search.php:26` hard-`return`s with an admin notice when WooCommerce is
   inactive — the dependency is absolute and invisible in metadata.
3. **The feature list and FAQ have drifted.** `== Description ==` predates the blocks,
   the six-workspace admin IA, brands support, and the configurable autocomplete density.
   The FAQ answer `= Does this plugin include Redis? =` reads "Version 0.1.0 uses Bring Your
   Own Redis", pinning a permanent architectural position to a version number eleven
   releases old.
4. **No external-services statement and no upgrade notice.**
   `docs/product-roadmap.md` Stage 2 makes disclosure an explicit publication gate: "clearly
   disclose any external service, data sent, terms, privacy policy, and pricing." Today the
   answer is "none" — which is exactly when the section is cheapest to write, and it means
   Stage 3's managed option becomes an edit rather than a new disclosure conversation.

## Proposed Solution

Repair the header block, refresh the description and FAQ against the shipped feature set,
replace the per-version changelog with a pointer to `CHANGELOG.md` on GitHub, and add
`== Upgrade Notice ==` plus `== External services ==`.

**Alternative considered — mirror `CHANGELOG.md` into `readme.txt` at release time via
`build-release.sh`.** Rejected: it adds a generation step to a script whose line-anchored
regexes are a protected surface, in order to duplicate content one click away. WordPress.org
expects the `== Changelog ==` heading to exist, not a complete per-version list; linking out
is common practice for projects with a generated changelog.

**Prior art that constrains the design.** WordPress.org's own readme conventions and the
directory's larger search plugins (FiboSearch, ElasticPress) both treat a hard plugin
dependency as first-class metadata (`Requires Plugins`) and keep `== Changelog ==` short with
a canonical link out for old versions. Screenshot galleries, `assets/` banner artwork, and
upgrade-nag blocks are wp.org *submission* concerns and are explicitly out of scope here.

## Architecture

One file changes: `readme.txt`. No PHP, no runtime behavior, no build-script change.

The `readme.txt` header block is *extended*, not redefined. `Stable tag`,
`Requires at least`, `Requires PHP`, `License`, `License URI`, and `Contributors` keep their
current values; only `Tested up to` moves, and `Requires Plugins` is appended.

### Scoped feature audit

Step 3 is bounded to exactly these `### Features` entries from `CHANGELOG.md` `0.2.0`–`0.12.0`
— not an open-ended reread of eleven releases:

| Version | Feature | In `== Description ==` today? |
| --- | --- | --- |
| 0.2.0 | PHP floor raised 7.4 → 8.3 (#5) | header only — no change needed |
| 0.3.0 | Initial product search foundation (#1) | yes |
| 0.4.0 | Starlight docs site (#8) | n/a — not a plugin feature |
| 0.5.0 | PHP-only product search blocks (#9) | yes |
| 0.6.0 | Native WooCommerce brands support (#10) | **no** |
| 0.8.0 | Demo/seeding script (#18) | **no** — decide: dev tool, likely omit |
| 0.9.0 | Demo-data reset-only flag (#24) | **no** — same decision |
| 0.10.0 | Six-workspace admin settings IA (#33) | **no** |
| 0.11.0 | Debug panel request-phase timings (#35) | **no** — decide: opt-in diagnostic |
| 0.12.0 | Configurable quick-search density and tray width (#42) | **no** |

Four entries are clear user-facing additions (brands, admin IA, autocomplete density, and
blocks which are already listed). Three are developer or diagnostic tooling where the step
records an explicit include/omit decision rather than defaulting either way.

## Edge Cases & Failure Scenarios

| Scenario | Behavior |
| --- | --- |
| `Requires Plugins: woocommerce` on WordPress < 6.5 | The field is ignored by older cores. The `active_plugins` guard at `shift64-woo-search.php:26` remains the real enforcement — the header field does not replace it, and the admin notice must stay. |
| WordPress 7.0 not yet released when this ships | Never set `Tested up to` past the newest *released* WordPress. The WP 7.0 block-gating story stays as prose in the description, not as a compatibility claim. |
| `build-release.sh` run after this spec | Its `^Stable tag: ` regex must still match a single unmodified line. Step 5 verifies. |

## Risks & Impact Review

**Blast radius: one metadata file, no behavior.** One protected surface is directly in play:

**`BACKWARD_COMPATIBILITY.md` §10 — runtime requirements declared in three places that must
agree** (the plugin header, `readme.txt`, and `composer.json` with `config.platform` pinned to
`8.3.0`). This spec **changes no minimum**: `Requires at least: 6.0` and `Requires PHP: 8.3`
keep their values. `Tested up to` is not a minimum and is not part of that three-way contract.
Any temptation to "refresh" the minimums while editing this file is a separate, breaking
change requiring the §10 path (bump all three, announce prominently, minor bump at least).

**Rollback.** One text-only commit, no generated artifact, no state. `git revert` restores it.
Because `readme.txt` is read by WordPress.org only at release time, a revert before the next
release has no external effect at all.

**Depends on nothing.** Ships independently of
[Licensing Attribution Correction](2026-07-30-licensing-attribution-correction.md) — it leaves
`readme.txt`'s existing `License` / `License URI` lines untouched, which are already correct.

## Implementation Plan

Single phase.

1. **Header block.** Set `Tested up to` to the newest released WordPress; append
   `Requires Plugins: woocommerce`. **Declaring the dependency in metadata does not remove the
   runtime one** — leave the `active_plugins` guard and
   `shift64_woo_search_woocommerce_inactive_notice()` at `shift64-woo-search.php:26-39`
   exactly as they are. `Requires Plugins` is ignored below WordPress 6.5, which this plugin
   supports down to 6.0, so the guard is the only real enforcement on most of the supported
   range.
   *Test:* `Requires at least: 6.0` and `Requires PHP: 8.3` are unchanged and still match the
   plugin header and `composer.json` `config.platform`; `Stable tag: 0.12.0` unchanged;
   `git diff --stat` for this step touches `readme.txt` and nothing else — no PHP file is
   modified by this spec at all.
2. **Replace `== Changelog ==`** with a single pointer to
   `https://github.com/mateusz-zadorozny/shift64-woo-search/blob/main/CHANGELOG.md`.
   *Test:* the `= 0.1.0 =` block is gone; the heading `== Changelog ==` still exists; the
   link resolves.
3. **Refresh `== Description ==`** against the audit table in §Architecture, recording the
   include/omit decision for the three tooling entries in the PR description.
   *Test:* every one of the ten table rows is accounted for as listed, omitted-with-reason,
   or n/a; no listed feature lacks a shipped surface.
4. **Add `== Upgrade Notice ==`** with a `= 0.12.0 =` entry, and **fix the stale FAQ** so
   `= Does this plugin include Redis? =` states the BYOR position without naming a version.
   *Test:* no hardcoded version number remains in the FAQ; the upgrade notice names no
   unshipped behavior.
5. **Add `== External services ==`** stating that in Bring Your Own Redis mode the plugin
   contacts no external service and sends no data off-site, and that any future managed
   connection is opt-in with its own disclosure, per `docs/product-roadmap.md` Stage 2.
   *Test:* the section names the data sent (none, today) and promises no unshipped service.
   Then run `bash build-release.sh 0.12.0` on a clean tree — `git diff` shows only an
   idempotent same-version rewrite, `Stable tag: 0.12.0` intact — and delete the ZIP.
6. **Run the gate.** `composer test` and `composer lint`.
   *Test:* both pass, unchanged from baseline.
