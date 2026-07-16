# Search Behavior

## Strict-First, Fuzzy-Fallback

Do not mix prefix and fuzzy matching in one query for the main results pipeline.

Recommended flow:

1. Run a strict query first.
2. If it returns results, return them immediately.
3. Only if it returns `0` results, run a fuzzy query.
4. Return fuzzy results only as a fallback, not mixed with strict results.

Example for input `stella`:

Strict pass:

```text
(stella*) -@excluded:{yes} -@visibility:{hidden}
```

Fallback pass:

```text
(%stella%) -@excluded:{yes} -@visibility:{hidden}
```

If the strict pass returns any results, the fuzzy pass is not executed.

## Why This Is Better

- more predictable results
- better ranking quality
- easier to explain to users
- less noise from rare fuzzy matches
- cleaner score interpretation
- fuzzy-only matches cannot outrank real prefix or title matches in the same result set

## Token Reduction Fallback

Multi-word queries should also support token reduction fallback, especially for autocomplete.

Problem:

- users often stop after typing an incomplete phrase
- short function words such as `do`, `na`, `z`, `i`, `w`, `od` are often weak search terms
- with strict `AND` logic, weak terms can reduce recall without improving relevance

Example:

```text
suszarka do
```

Current behavior searches separate tokens, not an exact phrase.

Recommended behavior:

1. Run the normal strict multi-token query.
2. If results are poor or empty, drop weak trailing tokens as a fallback.
3. Retry the query using only the meaningful tokens.
4. Only after that, apply fuzzy fallback if needed.

Recommended fallback chain:

1. strict:

```text
(suszarka* do*) -filters
```

2. token reduction fallback:

```text
(suszarka*) -filters
```

3. fuzzy fallback:

```text
(%suszarka%) -filters
```

This is particularly useful for autocomplete, where users often type partial phrases that should still produce strong results.

## Keep Autocomplete Lightweight

Suggestions should stay intentionally short and lightweight.

Recommended suggestion scope:

- product titles
- key categories
- optionally curated search phrases

Avoid overloading suggestions with too many entity types. The goal is quick guidance, not full ranking.

## Suggested Options

Add a settings group, for example `Search Strategy`.

Recommended settings:

- `Search Mode`
- `Fallback Trigger`
- `Fallback Fuzzy Level`
- `Enable token reduction fallback`
- `Weak token list`
- `Drop trailing weak token only`

Suggested option names:

- `shift64_woo_search_strategy`
- `shift64_woo_search_fallback_trigger`
- `shift64_woo_search_fallback_fuzzy_level`
- `shift64_woo_search_token_reduction_enabled`
- `shift64_woo_search_weak_tokens`
- `shift64_woo_search_drop_trailing_weak_token_only`

## Recommended Defaults

- strategy: `Strict first, fuzzy fallback`
- trigger: `Only when no results`
- fuzzy level: `1`

This is the safest and most predictable version.
