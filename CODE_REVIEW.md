# Code Review

Review rules for Shift64 Woo Search. `om-code-review` reads this file automatically on every
review, so it applies to agent reviews and human reviews alike. It complements the reviewer's
general judgement; it does not replace it.

## Priorities, in order

1. **Correctness** — does it do what the PR says, and does it fail safely when Redis is down?
2. **Security** — untrusted input reaches Redis, `$wpdb`, and the DOM. See the endpoint rules.
3. **Contracts** — does it break a surface listed in `BACKWARD_COMPATIBILITY.md`?
4. **Quality** — naming, tests, dead code, and whether the change fits how the file already works.

## The SHORTINIT endpoint: the highest-risk file in the repo

`mu-plugins/endpoint.php` is served directly at `/wp-content/mu-plugins/shift64-woo-search/endpoint.php`
and runs under `SHORTINIT`. **Most of WordPress does not exist there.** This is where reviewers
must be strictest, because the usual WordPress safety net is absent.

- **No WordPress API calls.** No `get_option()`, no `esc_html()`, no `sanitize_text_field()`,
  no `wp_send_json()`, no plugin classes. Every setting arrives as a `define()`d constant from
  the generated `config.php`. A PR that adds a WP function call here is broken even if it
  appears to work on the author's machine — reject it.
- **`SHIFT64_WOO_SEARCH_PLUGIN_PATH` is the gate.** The endpoint 500s without it. Any new
  constant the endpoint reads must be added to `generate_mu_plugin_config()` in
  `shift64-woo-search.php` in the same PR, or production breaks the moment config is regenerated.
- **`$wpdb` is available and is used** — the stats insert. Every query must go through
  `$wpdb->prepare()` or `$wpdb->insert()` with a format array. A raw interpolated query here is
  a Critical finding, not a nitpick.
- **Input is untrusted and unsanitized by the framework.** `q`, `mode`, and `limit` come
  straight from `$_GET`. `mode` must stay on its allowlist (`autocomplete` / `full` /
  `suggestions`, anything else coerced to `autocomplete`); `limit` must be cast to int and
  bounded; `q` must be length-checked before it reaches Redis.
- **Never let a query string reach a Redis command unescaped.** RediSearch query syntax has
  metacharacters; unescaped input is the injection vector on this endpoint.
- **Rate limiting must survive.** `{prefix}:rl:{md5(REMOTE_ADDR)}` with `INCR` + `EXPIRE 1`,
  returning HTTP 429 with `Retry-After: 1`. A refactor that drops or bypasses it is a
  Major finding.
- **Redis-down must degrade, not explode.** The fallback to `/?s=…&post_type=product` is the
  contract. Any new code path needs the same treatment.

## Admin AJAX

All 18 handlers register as `wp_ajax_shift64_woo_search_*` only (never `wp_ajax_nopriv_`).
Every one of them, with no exceptions:

- checks the nonce for action `shift64_woo_search_admin`;
- checks `current_user_can( 'manage_woocommerce' )`;
- sanitizes each input explicitly, and escapes on output.

A new handler missing either the nonce or the capability check is a Critical finding. A handler
registered as `nopriv` is Critical unless the PR explains, convincingly, why it must be public.

## WordPress and WooCommerce conventions

- **Escape at output, every time**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
  PHPCS catches much of this, but not everything — read the diff, do not trust the linter alone.
- **Text domain is `shift64-woo-search`** and source strings are English. A new user-facing
  string that is untranslated, or uses the wrong domain, blocks the PR. Run `composer makepot`
  when strings change.
- **`.woocommerce` usually sits on `<body>`, not a page wrapper.** CSS overrides that assume
  otherwise silently do nothing. Ask for the winning selector, not a guess.
- **No jQuery.** The frontend is vanilla JS with no declared script dependencies. A PR that
  introduces a jQuery dependency needs an explicit justification.

## Naming (from AGENTS.md — enforced, not advisory)

| Thing | Pattern |
|---|---|
| Classes | `Shift64_Woo_Search_*` |
| Functions, options, custom hooks | `shift64_woo_search_*` |
| Constants | `SHIFT64_WOO_SEARCH_*` |
| Redis doc key | `{prefix}:product:{id}` |
| Redis index | `{prefix}_product_idx` |

## PHP 8.3 is the floor, and phpcs checks it

The plugin header, `readme.txt`, and `composer.json` all declare **PHP 8.3** as the minimum,
`composer.config.platform` pins resolution to `8.3.0`, and the CI matrix runs **8.3, 8.4,
and 8.5**. The floor is machine-checked: `.phpcs.xml.dist` runs `PHPCompatibilityWP` with
`testVersion 8.3-`, so `vendor/bin/phpcs` flags syntax that breaks on any supported version.

The reviewer's job is what the sniffs cannot see:

- `.phpcs.xml.dist` excludes `mu-plugins/`, `tests/`, and `bin/` — compatibility issues there
  reach neither phpcs nor the compat sniffs. Read those diffs with the floor in mind.
- A stdlib function newer than 8.3 (or a feature from 8.4+) in any excluded path is a Major
  finding, same as it would be in scanned code.

`PHPCompatibilityWP` is polyfill-aware: it will not flag functions WordPress core polyfills
(such as `str_contains` and `array_is_list`), which is correct — those are safe on 8.3.

If a PR genuinely needs a floor above 8.3, that is a `BACKWARD_COMPATIBILITY.md` decision, a
bump of all declarations plus `testVersion` together, and a prominent changelog entry — not a
quiet change.

## Redis and the index

- **Redis and RediSearch are the only backend.** Do not approve Elasticsearch, Elastica, or a
  second search engine. This is a project-level decision recorded in AGENTS.md.
- A change to the index schema (`includes/class-shift64-woo-search-schema.php`) means existing
  installs carry a stale index. The PR must say how a rebuild is triggered — `FT.DROPINDEX … DD`
  plus reindex, a bumped `shift64_woo_search_db_version`, or the self-heal path.
- The `{prefix}:categories`, `{prefix}:suggestions`, and `{prefix}:synonyms` blobs are written by
  the plugin and read by the endpoint. A shape change on one side is a break unless both sides
  land together.

## Settings and generated config

Settings are individual `wp_options` rows, not one array, and there are no `register_setting()`
calls — the admin screen saves through AJAX. So:

- a new setting means: the option, the AJAX save path, the read in `shift64-woo-search.php`, and
  (if the endpoint needs it) a constant in `generate_mu_plugin_config()`. Missing the last one is
  the classic bug in this codebase;
- **never commit a generated `mu-plugins/shift64-woo-search/config.php`**;
- changing a default means existing installs keep the old stored value. Say what happens to them.

## Tests

- Behavior changes ship with tests. `tests/` runs against the real WordPress test suite via
  `bin/install-wp-tests.sh` — these are not mocks.
- `phpunit.xml.dist` sets `convertDeprecationsToExceptions="true"`. A deprecation is a test
  failure here. That is intentional; do not switch it off to make a suite green.
- Never approve a green gate that was reached by weakening an assertion, skipping a test, or
  loosening the linter.

## Severity guidance

| Severity | Means | Examples in this repo |
|---|---|---|
| **Critical** | Merge blocker; exploitable or destroys data | Missing nonce/capability on an AJAX handler; unprepared `$wpdb` query; unescaped input into a Redis command; a `nopriv` endpoint |
| **Major** | Merge blocker; breaks behavior or a contract | WP API call inside the SHORTINIT endpoint; endpoint reads a constant that config.php never defines; rate limiter bypassed; syntax above the PHP 8.3 floor; breaking a documented surface without the deprecation path |
| **Minor** | Should fix, does not block | Missing escaping on low-risk output; a missing test for a branch; naming drift |
| **Nit** | Optional | Formatting the linter did not catch, wording, comment style |

State the severity on every finding. A review with no Critical or Major findings and a green
gate should approve — do not hold a PR hostage over nits.
