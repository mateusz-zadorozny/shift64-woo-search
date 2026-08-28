# Checkpoint 3 — Steps 3.1 … 3.6 (Phase 3 closed)

**Recorded:** 2026-08-28T07:11:34Z
**Step range:** 3.1 → 3.6 (6 Steps)
**Commit range:** `0496ba7` → `b52ac23`

Phase 3 is complete: the admin surface, the generated fast-path config and the
declared runtime baselines all match the block-only storefront.

## Areas touched

- `admin/class-shift64-woo-search-admin.php` — the selector and tray-width fields
  removed; the Search Field section retired and its one surviving setting, the
  autocomplete debounce, moved into Autocomplete.
- `admin/class-shift64-woo-search-admin-routes.php` — the retired section dropped
  from the registry; the legacy `frontend` alias now lands on Autocomplete.
- `admin/class-shift64-woo-search-admin-settings.php` — five retired keys dropped
  from the save allowlist, so no payload can write a value nothing reads.
- `shift64-woo-search.php` — runtime baseline guard around block bootstrap; three
  new admin notices (unsupported runtime, classic theme, upgrade announcement);
  the per-user dismissal handler; baseline declarations raised.
- `includes/class-shift64-woo-search-requirements.php`,
  `includes/class-shift64-woo-search-legacy-shortcodes.php` — new.
- `cli/class-shift64-woo-search-cli.php` — every command asserts the runtime first.
- `readme.txt`, `README.md`, `AGENTS.md`, `BACKWARD_COMPATIBILITY.md`, the docs
  site — WordPress 7.0 and WooCommerce 10.9 declared consistently.
- `.github/workflows/release.yml` — a matrix entry pinned to WordPress 7.0.
- `tests/` — four new suites; the three admin suites retargeted.

## Checks

| Check | Result | Notes |
|-------|--------|-------|
| `composer validate --strict` | ✅ pass | |
| `vendor/bin/phpcs` | ✅ pass | 8/8 files clean. |
| `vendor/bin/phpunit` | ✅ pass | 787 tests / 8461 assertions (baseline 730 / 8385). |
| HTTP smoke | ✅ pass | Storefront and `/wp-admin/` respond with no PHP fatal. |
| Browser check, WP Admin | ✅ pass | See below. |

## UI verification

Artifacts in `checkpoint-3-artifacts/`:

- `screenshot-admin-upgrade-notice.png` — the plugin's Overview screen.
- `screenshot-admin-legacy-alias-lands.png` — the legacy `?tab=frontend` bookmark.

The upgrade notice renders for the administrator with its migration-guide link
and a Dismiss action, and it is the only notice shown — which is itself the
result being checked: this environment runs WordPress 7.1 with a current
WooCommerce and a block theme, so neither the unsupported-runtime error nor the
classic-theme information notice should appear, and neither does.

The second screenshot confirms the retired section did not strand a bookmark.
`?tab=frontend` resolves to Search Experience → Autocomplete; the secondary
navigation lists three sections rather than four, with Search Field gone; and
Debounce renders in its new home above the density toggles. No selector or
tray-width field appears anywhere on the route.

## Decisions recorded in this window

- **The Search Field section was retired rather than emptied.** Removing the three
  selector fields would have left a section named after a search field the plugin
  no longer renders, holding one unrelated setting. The debounce moved to
  Autocomplete, where it belongs, and the legacy alias was repointed so no
  bookmark breaks.
- **The requirements guard fails open on an unreadable WooCommerce version.** It
  disables the block layer only on a version it can positively read as too old.
  The plugin already returns early when WooCommerce is not active, so an
  unreadable version means an active installation the guard cannot introspect —
  switching every storefront block off in that situation would be a far worse
  outcome than trusting it.
- **A classic theme is a supported runtime, not an unmet requirement.** It gets an
  informational notice, and search, indexing, the endpoint and the CLI continue
  to work. Folding it into the version check would have taken those down too.
- **The upgrade-notice dismissal is keyed by identifier, not version.** A patch
  release must not resurrect a dismissed notice, and a later breaking release
  should be able to raise its own without being pre-dismissed.
- **The shortcode detector reports and never renders.** It is admin-only,
  capability-gated, precise about which tag a post actually carries, and cached
  for twelve hours because the lookup is a `LIKE` over `post_content`.

## Follow-ups

None open. The next Step is 4.1.
