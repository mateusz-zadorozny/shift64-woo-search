# Search Architecture

## Overview

Use a unified Redis-backed search pipeline for:

- autocomplete
- full search
- archive filtering

The goal is consistency:

- fast autocomplete
- predictable full-search ranking
- the same business rules everywhere
- one Redis product index as the source of truth for candidate matching

## Recommended Search Flow

1. Use `FT.SUGGET` for short autocomplete suggestions.
2. Use `FT.SEARCH` for full search results.
3. Apply the same normalization and exclusion rules in both layers.
4. Use the same Redis product index as the source of truth for candidate matching.
5. Use strict-first, fuzzy-fallback behavior for `FT.SEARCH`.

This keeps the UI fast while preserving consistency between the search bar and full result pages.

## Consistency Rules

Autocomplete and full search do not need to use the same Redis command.

They do need to use the same:

- normalization rules
- exclusion rules
- searchable product set
- business logic for hidden products
- business logic for excluded products

That is what makes the experience feel consistent.

## Archive and Filtered Pages

Archive pages and filtered listing pages should use the same Redis query model as full search.

This allows one search model to handle:

- keyword search
- category filtering
- attribute filtering
- price filtering
- price sorting

## Recommended B2B Archive Architecture

For a B2B shop with customer-specific pricing, the safer architecture is a hybrid model.

Recommended approach:

1. Redis builds the candidate product set.
2. Redis applies search and filter logic.
3. Redis returns ordered product IDs.
4. WordPress and WooCommerce load those IDs and render the final product cards.

This is safer than treating Redis as the final archive engine.

## Why This Is Safer

- many customers may have altered prices
- the Redis index typically holds static or base prices
- the final visible product card must respect WooCommerce and business-specific logic
- customer-specific pricing makes Redis-only rendering more error-prone

## What Redis Should Handle

- keyword search
- category filtering
- attribute filtering
- hidden and excluded filtering
- stock filtering
- base-price filtering
- relevance ordering

## What WordPress / WooCommerce Should Handle

- final product card rendering
- customer-specific pricing
- tax and B2B visibility logic
- badges, hooks, and template behavior
- any business logic that depends on the current user or pricing context

## Practical Recommendation

Use Redis as:

- filter engine
- search engine
- candidate set builder

Use WooCommerce as:

- final renderer
- final business logic layer

That is the safer, easier, and less error-prone model for this project.
