# Indexing Strategy

## Recommended Indexed Fields

Recommended indexed fields:

- `TEXT`: `title`, optional `description`
- `TAG`: `categories`, `attributes`, `visibility`, `excluded`, current SKU, legacy SKU, `in_stock:{yes|no}`
- `NUMERIC`: `price`

This supports:

- keyword search
- category filtering
- attribute filtering
- price filtering
- price sorting

## Categories

Categories should stay as `TAG` fields for filtering.

That means:

- they are very good for exact category filters
- they do not contribute to text relevance scoring by default

So if someone searches for `suszarka` and there is a category `suszarki`, the category alone should not add a Redis score bonus unless a separate text field is intentionally introduced later.

If category scoring is ever needed, the safer future options are:

- add a low-weight `categories_text` field
- or apply a small PHP boost outside Redis scoring

## Attribute Strategy

If you want to support many WooCommerce attributes, model filterable attributes as `TAG` fields.

Recommended approach:

- keep each important filterable attribute as a separate logical field when it matters for faceting or filtering
- store values in a normalized format
- use exact-match filtering only

Examples:

```text
attr_color:{black|white}
attr_material:{steel}
attr_room:{kitchen|dining-room}
```

This is usually easier to reason about than pushing all attributes into one large `TEXT` field.

## When Attributes Become Hard to Manage

If attribute count or variability grows too much, there are two practical options.

### Option A: Separate TAG Field Per Attribute

Best when:

- the attribute set is relatively stable
- precise filtering matters
- faceting is important

### Option B: One Combined TAG Field With `name:value` Pairs

Example:

```text
filters:{color:black|material:steel|room:kitchen}
```

Best when:

- the attribute set is larger or more variable
- you want one generic filter builder
- operational simplicity matters more than explicit schema per attribute

## Recommended Direction for This Project

For now, the recommended setup is:

- separate `TAG` fields for the most important filterable attributes
- keep categories as `TAG`
- keep stock as `TAG`
- keep `price` as the only numeric field for filtering and sorting right now

This is the clearest and lowest-risk schema.
