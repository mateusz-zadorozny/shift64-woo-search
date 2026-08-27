# Search Result Controls Audit

## Purpose

This document inventories every behavior that can change which products are returned, their order, fallback selection, and autocomplete suggestions. It is the design contract for a future versioned configuration shared by BYOR and managed installations.

## Current product-result pipeline

### Query preparation

- Replace RediSearch operators and indexing punctuation with spaces so token boundaries match indexed text.
- Normalize case and whitespace, and cap the query at 200 characters.
- Enforce a configurable minimum query length; tokens shorter than two characters are discarded.
- Greedily expand synonym phrases up to four words.
- Optionally use fuzzy synonym-key lookup.
- Concatenate SKU-like letter-and-number pairs.

### Retrieval cascade

The default `mixed` strategy runs one query in which every token matches as prefix OR fuzzy, joined by the configured logic. Tokens of four characters or fewer stay prefix-only.

The `strict_first` strategy runs:

1. strict prefix search;
2. strict prefix search after optional weak-token reduction;
3. per-token fuzzy search — the `mixed` shape, at the fallback fuzzy level;
4. OR prefix fallback when the configured logic is AND;
5. fuzzy fallback.

Pass acceptance depends on either an empty result or no result in the leading five covering every search term. On the results page this rule applies under relevance sorting; the other sort modes and the WooCommerce-sort candidate pass fetch IDs without document fields and hand over on an empty result only. Coverage reads all indexed TEXT fields and accepts a synonym variant in place of the term the shopper typed. Pass 3 is terminal on any hit: its matches are approximate by design, so re-testing coverage would always fail and hand a good answer to the broader passes below it.

Term coverage replaced a raw-score threshold here. RediSearch TF-IDF is unbounded and routinely lands in the tens, so comparing the best score against `shift64_woo_search_fallback_score_threshold` (`0.5`) was true only for an empty result set — which collapsed `low_score` into `no_results` and left a typo in one word of a multi-word query uncorrectable. That option still gates which fuzzy-pass matches are shown.

### Redis scoring

RediSearch uses TF-IDF. Default field weights are:

| Field | Weight |
| --- | ---: |
| `title` | 10 |
| `sku_text` | 8 |
| `categories_text` | 4 |
| `short_desc` | 3 |
| `attributes` | 2 |
| `description` | 1 |

`title_ascii` is indexed with `max(1, title / 2)`.

### PHP ranking

Autocomplete and full endpoint candidates are processed in this order:

1. minimum-score filter;
2. out-of-stock demotion;
3. exact title-start boost;
4. category/tag rule boost;
5. exact or partial SKU override;
6. promoted-product boost;
7. final sort and trim.

The OR pass first applies term-coverage filtering and scoring. Order is material: a document removed by the early score filter cannot be recovered by a later boost.

## Existing options

| Area | Option | Default | Effect |
| --- | --- | --- | --- |
| Query | `shift64_woo_search_min_query` | `2` | Minimum query length |
| Limit | `shift64_woo_search_autocomplete_limit` | `7` | Product autocomplete limit |
| Limit | `shift64_woo_search_full_limit` | `20` | Full endpoint limit |
| Logic | `shift64_woo_search_logic` | `AND` | Token AND/OR logic. Fresh installs only — see below |
| Strategy | `shift64_woo_search_strategy` | `mixed` | Cascade or mixed strategy. Fresh installs only — see below |
| Fallback | `shift64_woo_search_fallback_trigger` | `low_score` | Empty-only or low-score fallback |
| Fallback | `shift64_woo_search_fallback_score_threshold` | `0.5` | Score filter for the all-fuzzed pass only |
| Fuzzy | `shift64_woo_search_fallback_fuzzy_level` | `1` | Final fallback distance |
| Mixed fuzzy | `shift64_woo_search_fuzzy_level` | `1` | Mixed-strategy distance |
| Token reduction | `shift64_woo_search_token_reduction_enabled` | `yes` | Enables pass two |
| Token reduction | `shift64_woo_search_weak_tokens` | language list | Removable tokens |
| Token reduction | `shift64_woo_search_drop_trailing_weak_token_only` | `yes` | Remove trailing or all weak tokens |
| Synonyms | `shift64_woo_search_fuzzy_synonyms` | `no` | Approximate synonym-key matching |
| Weights | `shift64_woo_search_weights` | table above | Index field weights; rebuild required |
| Stock | `shift64_woo_search_outofstock_mode` | `exclude` | Exclude, show, or demote |
| Stock | `shift64_woo_search_outofstock_demote_factor` | `0.3` | Demotion multiplier |
| Taxonomy | `shift64_woo_search_category_boost_rules` | empty | Category/tag multipliers |
| Product | `_shift64_woo_search_promoted` | off | Promoted product flag |
| Product | `_shift64_woo_search_excluded` | off | Absolute exclusion flag |
| Archive | `shift64_woo_search_archive_enabled` | `no` | Redis-backed archive search |
| Price | `shift64_woo_search_price_sort_mode` | `redis` | Redis price or contextual WooCommerce price |
| Category suggestions | `shift64_woo_search_category_suggest_fuzzy` | `no` | Typo-tolerant category matching |
| Category suggestions | `shift64_woo_search_category_pin_rules` | empty | Query-to-category pins |
| Category suggestions | `shift64_woo_search_category_boosts` | empty | Category tie-break multipliers |
| Category suggestions | `shift64_woo_search_category_suggest_exclude` | empty | Hidden suggestion categories |

The `Logic` and `Strategy` defaults above describe a fresh install. `set_default_options()` seeds
both keys on activation, so an install that has ever been activated holds the previous pair
(`OR`, `strict_first`) as stored values and keeps it until a merchant changes the setting. See
`BACKWARD_COMPATIBILITY.md` §6.

Synonym expansion currently has no master switch: saved synonyms are always active.

## Hard-coded behaviors to expose

### Matching and cascade

| Behavior | Current value | Proposed key |
| --- | --- | --- |
| Strict pass | always in `strict_first` | `cascade.strict.enabled` |
| OR fallback | always for AND logic | `cascade.or_fallback.enabled` |
| Fuzzy fallback | always last | `cascade.fuzzy.enabled` |
| Final low-score filter | threshold-driven | `ranking.low_score_filter.enabled` |
| Minimum token length | `2` | `matching.token_min_length` |
| Synonym expansion | active when map exists | `matching.synonyms.enabled` |
| Maximum synonym phrase | four words | `matching.synonyms.max_phrase_words` |
| SKU concatenation | fixed pattern | `matching.sku_concatenation.enabled` |
| Mixed fuzzy minimum | terms longer than four | `matching.mixed.fuzzy_min_length` |

Query-length, response-size, scorer, and dialect limits should remain internal guardrails rather than merchant controls.

### Product ranking

| Behavior | Current value | Proposed key |
| --- | --- | --- |
| Title-start ranking | enabled | `ranking.title_start.enabled` |
| Single-term factor | `2.0` | `ranking.title_start.single_factor` |
| Phrase factor | `3.0` | `ranking.title_start.phrase_factor` |
| Maximum title offset | second word | `ranking.title_start.max_offset` |
| OR coverage | enabled in OR fallback | `ranking.term_coverage.enabled` |
| Minimum coverage | `0.4` | `ranking.term_coverage.min_ratio` |
| Coverage exponent | `2` | `ranking.term_coverage.exponent` |
| SKU priority | enabled | `ranking.sku.enabled` |
| Exact SKU rule | `max_score + 100` | `ranking.sku.exact_mode` and value |
| Partial SKU rule | `max_score + 10` | `ranking.sku.partial_mode` and value |
| Promoted ranking | enabled, factor `1.5` | `ranking.promoted.enabled/factor` |
| Taxonomy rules | active when non-empty | `ranking.taxonomy_rules.enabled` |
| Maximum taxonomy factor | `200` | internal guardrail |

Exact SKU is an override relative to the candidate pool, not a multiplier. The UI must describe that distinction. Promoted ranking currently runs after SKU ranking, so an aggressively boosted promoted product can still overtake an exact SKU match.

### Candidate pools

Over-fetch changes results because PHP cannot promote a document Redis did not return.

| Context | Current rule |
| --- | --- |
| Autocomplete | `max(limit * 20, 300)` |
| Full endpoint | `max(limit * 5, 100)` |
| Active taxonomy rules | `min(500, max(current, limit * 20, 300))` |
| Archive relevance | `max(per_page * page * 3, 300)` |
| Archive price with stock demotion | `per_page * 3` |

Expose these only as advanced, safely bounded values or managed ranking-profile settings.

### Index-time controls

The following behaviors require a schema version and rebuild when changed:

- category ancestors in TAG and TEXT fields;
- searchable category text;
- historical catalog numbers;
- searchable attribute labels, values, and normalized units;
- ASCII-normalized title field;
- field weights.

Published status, catalog visibility, and explicit product exclusion are policies, not ordinary ranking toggles. A zero title weight currently does not fully disable title influence because `title_ascii` keeps a minimum weight of one; this must be fixed before promising true field disablement.

Product variations and their SKUs are intentionally excluded from retrieval.
The search index represents parent products only.

## Category and query suggestions

Category suggestions use a separate tuple, not the product ranking pipeline:

```text
pin priority > text relevance > category boost > product count > stored order
```

Current hidden values include a five-category limit, edit distance one, minimum fuzzy token length four, and relevance tiers for exact, prefix, substring, first-token fuzzy, and later-token fuzzy matches. Expose merchant-level enable/disable controls and limits, but keep tier numbers inside a versioned profile.

Curated query suggestions currently use prefix matching, return three entries, and shuffle the list for an empty field. Proposed controls are `enabled`, `limit`, `match_mode`, and an empty-input mode of `random`, `ordered`, or later `popular`.

## Known inconsistencies

- Archive relevance does not run every autocomplete/full ranking rule.
- Archive fallback ignores the configured low-score trigger and final threshold.
- Archive does not implement the `mixed` strategy consistently.
- The archive configuration omits category/tag boost rules.
- `shift64_woo_search_diacritics_normalization` is stored but does not actually control indexing or queries.
- The tuning screen does not apply the production final low-score filter.
- Single-character weak tokens are discarded before token reduction can see them.
- Explicit price sorting intentionally bypasses relevance boosts.

These inconsistencies should be resolved before exposing more switches.

## Recommended configuration model

Create one typed, versioned `Search_Config` consumed by autocomplete, full search, archives, BYOR, and the managed service. Use three layers:

- Merchant controls: profiles, AND/OR, token reduction, fuzzy fallback, synonyms, title start, SKU priority, promoted products, taxonomy rules, stock mode, and suggestions.
- Advanced controls: factors, ratios, thresholds, fuzzy distance, weights, and safely bounded candidate pools.
- Internal guardrails: protocol compatibility, visibility policy, hard limits, schema version, and service-plan capacity.

Use named, versioned profiles such as `balanced_v1`, `strict_v1`, and `broad_v1`, plus explicit overrides. A profile version prevents an update from silently changing results for existing stores.

## Implementation order

1. Introduce the typed configuration object with validation and a revision number.
2. Extract one candidate retriever and one result ranker shared by every context.
3. Add explicit `enabled` flags for every optional rule.
4. Separate runtime settings from rebuild-required settings.
5. Include config revision and pass details in debug output and statistics.
6. Add side-by-side comparison for two configurations over the same query set.
7. Add UI and portal controls only after the shared pipeline is covered by golden-result tests.
