# Execution plan: readme.txt publication readiness

Source doc: .ai/specs/2026-07-30-readme-txt-publication-readiness.md

## Goal

Bring `readme.txt` — the only documentation file that reaches an installed user, because
`build-release.sh` excludes `*.md` from the release ZIP — up to the WordPress.org publication
standard Stage 2 of `docs/product-roadmap.md` requires: current compatibility metadata, a
declared WooCommerce dependency, a description and FAQ that match the shipped feature set, a
changelog pointer, an upgrade notice, and an external-services disclosure.

## Scope

- `readme.txt` — header block, description, FAQ, changelog, upgrade notice, external services.
- `.ai/specs/2026-07-30-readme-txt-publication-readiness.md` and `.ai/specs/README.md` — the
  spec Status flip that AGENTS.md § Spec lifecycle requires in the implementing PR.

### Non-goals

- No PHP change of any kind. The `active_plugins` guard and
  `shift64_woo_search_woocommerce_inactive_notice()` at `shift64-woo-search.php:26-39` stay
  exactly as they are — `Requires Plugins` is ignored below WordPress 6.5 and this plugin
  supports 6.0, so the runtime guard remains the only real enforcement across most of the
  supported range.
- No minimum-requirement change. `Requires at least: 6.0` and `Requires PHP: 8.3` keep their
  values; touching them is the `BACKWARD_COMPATIBILITY.md` §10 three-way path, a separate
  breaking change.
- No `build-release.sh` change, no changelog mirroring into `readme.txt`, no wp.org submission
  assets (screenshots, banner artwork).

## Autonomous decisions

1. **`Tested up to: 7.0`** — the newest *released* WordPress at run time is 7.0.2
   (`api.wordpress.org/core/version-check/1.7/`), so the spec's "never claim past the newest
   released version" edge case is satisfied by 7.0, and the existing "blocks on WordPress 7.0+"
   description line stops being a forward-looking claim.
2. **Version numbers are 0.12.2, not the spec's 0.12.0** — the spec was written against
   `Stable tag: 0.12.0`; two patch releases have shipped since. The upgrade notice is written
   for `= 0.12.2 =` and the step-5 build check runs `bash build-release.sh 0.12.2`, which keeps
   the "idempotent same-version rewrite" property the spec's test asks for.
3. **Feature-audit include/omit calls** (spec §Architecture table, the three tooling rows):
   - `0.8.0` demo/seeding script and `0.9.0` reset-only flag — **omitted** from
     `== Description ==`. They are developer tooling for building a test catalogue, not a
     capability an installing shop owner chooses this plugin for, and listing them on wp.org
     would invite support questions about a script the release ZIP's users have no reason to run.
   - `0.11.0` debug-panel request-phase timings — **included**, phrased as an opt-in
     diagnostic. It is user-visible in the storefront (hidden by default since 0.10.2) and it is
     the honest answer to "how do I see what the plugin is doing", which is a genuine evaluation
     criterion.

## Implementation Plan

### Phase 1: readme.txt publication readiness

1. **Header block** — `Tested up to: 7.0`, append `Requires Plugins: woocommerce`; every other
   header field byte-identical, `Stable tag: 0.12.2` untouched.
2. **Changelog pointer** — replace the `= 0.1.0 =` body under `== Changelog ==` with a single
   link to `CHANGELOG.md` on GitHub; the heading survives.
3. **Description refresh** — audit-table features added (brands, six-workspace admin IA,
   configurable autocomplete density, opt-in debug timings); tooling rows omitted per the
   decision above.
4. **Upgrade Notice + FAQ fix** — add `== Upgrade Notice ==` with a `= 0.12.2 =` entry; rewrite
   the Redis FAQ answer so the Bring Your Own Redis position carries no version number.
5. **External services** — add `== External services ==` stating that today the plugin contacts
   no external service and sends no data off-site, and that any future managed connection is
   opt-in with its own disclosure. Then verify `bash build-release.sh 0.12.2` on a clean tree
   leaves `Stable tag: 0.12.2` intact and produces only an idempotent rewrite; delete the ZIP.
6. **Spec status + gate** — flip the spec's `> **Status:**` line and its `.ai/specs/README.md`
   index row per AGENTS.md, then run the full gate (`composer validate --strict`,
   `vendor/bin/phpcs`, `vendor/bin/phpunit`).

## Risks

- **Low.** One user-facing text file plus two docs-index edits; no PHP, no runtime behavior, no
  generated artifact. Rollback is `git revert`, and because `readme.txt` is read by
  WordPress.org only at release time, a revert before the next release has no external effect.
- The one real trap is silently "refreshing" the minimums while editing the header; step 1's
  test explicitly asserts they are unchanged.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: readme.txt publication readiness

- [ ] 1.1 Header block: Tested up to 7.0, Requires Plugins woocommerce
- [ ] 1.2 Replace the Changelog section with a CHANGELOG.md pointer
- [ ] 1.3 Refresh the Description against the audit table
- [ ] 1.4 Add Upgrade Notice and de-version the Redis FAQ answer
- [ ] 1.5 Add External services and verify build-release.sh
- [ ] 1.6 Flip the spec Status and index row, run the full validation gate
