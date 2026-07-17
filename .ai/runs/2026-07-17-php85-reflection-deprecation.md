# Fix: PHP 8.5 Reflection deprecation breaks the test suite

## Goal

Make the `Tests (PHP 8.5)` CI job green again by removing the redundant
`setAccessible()` calls from the test suite, without weakening the suite's
deprecation strictness.

## Context

CI has failed on `main` since `f370337` ("ci: test supported PHP versions"), which
added PHP 8.5 to the matrix. PHP 8.3 and 8.4 pass; only 8.5 fails, with 7 errors of
the form:

```
Method ReflectionMethod::setAccessible() is deprecated since 8.5,
as it has no effect since PHP 8.1
```

`phpunit.xml.dist` sets `convertDeprecationsToExceptions="true"`, so a deprecation
notice becomes a test error. That setting is intentional and stays.

`ReflectionMethod::setAccessible()` / `ReflectionProperty::setAccessible()` have been
no-ops since PHP 8.1 — private and protected members are reachable through Reflection
without them. On 8.5 they are deprecated. The calls are therefore dead code on every
PHP version this project runs on.

## Why unconditional removal is safe (the decision worth recording)

The initial reading was that removal might be unsafe: the plugin header, `readme.txt`,
and `composer.json` all declare **PHP 7.4** as the minimum, `composer.config.platform`
is pinned to `7.4.0`, and PHPUnit 9.6 requires only `php >=7.3`. On PHP < 8.1
`setAccessible()` is genuinely **required**, so deleting the calls would break the
suite on a supported version — which would have argued for a `PHP_VERSION_ID < 80100`
guard instead.

**The maintainer's stated target is PHP 8.3+**, and CI runs exactly 8.3, 8.4, and 8.5.
That settles it: on every version this project targets, `setAccessible()` is dead code.
A version guard would add branching for versions the project does not support. So the
calls go, unconditionally.

### A rejected argument, recorded so it is not reused

An earlier draft of this plan offered a second justification: that the declared 7.4
floor was *already* false, because `includes/class-shift64-woo-search-archive.php:1091`
calls `str_starts_with()` (PHP 8.0), which would fatal on 7.4.

**That argument is wrong and must not be relied on.** WordPress core polyfills
`str_starts_with()` — `wp-includes/compat.php`, `if ( ! function_exists( 'str_starts_with' ) )`,
`@since 5.9.0` — and `compat.php` is required at `wp-settings.php:36`, *before* the
SHORTINIT bail at line 169. The polyfill is therefore present in every execution
context this plugin runs in, including the endpoint. The declared WP minimum is 6.0, so
the polyfill is always available. `PHPCompatibilityWP` recognizes WP core polyfills and
would not have flagged this either.

The 7.4 declaration is **unverified**, not **disproven**: nothing in CI exercises it.
That distinction matters for the follow-up below — raising the declared minimum is a
deliberate product decision under `BACKWARD_COMPATIBILITY.md` §10, not the correction of
an already-broken state.

### What this change does introduce

Removing the calls gives the **test suite** an undeclared PHP 8.1+ floor: a contributor
on 7.4 or 8.0 now gets a `ReflectionException` instead of a passing suite. Nothing
declares or checks that. This widens the declared-floor-vs-CI gap already documented in
`BACKWARD_COMPATIBILITY.md` §10 rather than opening a new one, and it is the follow-up's
job to close it.

## Scope

Remove all 5 `setAccessible()` calls:

| File | Lines |
|---|---|
| `tests/test-sync-category-delete.php` | 55, 65, 77 |
| `tests/test-archive-relevance.php` | 47 |
| `tests/test-filter-category-exclusions.php` | 19 |

## Non-goals

Deliberately out of scope; each is filed or noted separately:

- **Raising the declared minimum** (7.4 → 8.3) in the plugin header, `readme.txt`, and
  `composer.json` `require` + `config.platform`. Changing `config.platform` forces a
  `composer.lock` re-resolve that can move dependency versions — that churn must not
  ride along with a CI fix, or a green CI stops being evidence that *this* fix worked.
  It is also a break under `BACKWARD_COMPATIBILITY.md` §10 ("Breaking: raising any
  minimum"), so it needs its own PR, its own changelog entry, and a deliberate decision —
  not a drive-by. Follow-up issue.
- **Adding PHPCompatibility to `.phpcs.xml.dist`** with a `testVersion` matching whatever
  minimum that follow-up settles on, so the declared floor is machine-checked instead of
  trusted. Same follow-up.
- **Deduplicating the three near-identical reflection helpers** in
  `test-sync-category-delete.php`. Unrelated refactor.

## Risks

- Low. Test-only change; no plugin source is touched, so no user-facing surface moves.
- On PHP < 8.1 these tests now throw `ReflectionException`. Accepted: the target is
  8.3+ and CI runs 8.3/8.4/8.5. Note this is a real (if out-of-target) consequence, not
  an impossibility — see "What this change does introduce" above.
- The local gate cannot fully mirror CI, which runs the suite against a PHP matrix with
  a fresh MySQL service. Verified locally on PHP 8.5.3 — the exact version that was
  failing.
- The local WP test database is not clean (it emits `Table … doesn't exist` and `Table
  definition has changed` errors). Two of roughly twenty local runs failed and were not
  reproducible across twelve consecutive subsequent runs; both occurred while the main
  checkout and this worktree were touching the same test database. Treated as local
  environment noise, not a property of this change, which only deletes no-ops. CI
  provisions a fresh database per run.

## Implementation Plan

### Phase 1: Remove the redundant calls

- 1.1 Remove `setAccessible()` from the three reflection helpers in
  `tests/test-sync-category-delete.php`.
- 1.2 Remove `setAccessible()` from `tests/test-archive-relevance.php` and
  `tests/test-filter-category-exclusions.php`.

### Phase 2: Verify

- 2.1 Run the full validation gate (`composer validate --strict`, `vendor/bin/phpcs`,
  `vendor/bin/phpunit`) and confirm 0 errors on PHP 8.5.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Remove the redundant calls

- [x] 1.1 Remove setAccessible() from test-sync-category-delete.php reflection helpers — 9a2a77b
- [x] 1.2 Remove setAccessible() from test-archive-relevance.php and test-filter-category-exclusions.php — 9a2a77b

### Phase 2: Verify

- [x] 2.1 Full validation gate green on PHP 8.5 — 9a2a77b
