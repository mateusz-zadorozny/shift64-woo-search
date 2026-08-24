# Composable Search Blocks

Shift64 provides two stable parent blocks: **Shift64 Product Search** and
**Shift64 Modal Product Search**. Each parent owns the same fixed structure:

```text
Search parent
├── Search Control
└── Search Panel
```

The structure is locked against insertion, removal, and movement. The two
children remain selectable in List View so their copy and native block design
tools can be edited independently.

## Editing and styling

Insert either parent from the block inserter. Select **Search Control** to edit
the field, submit, or trigger copy and to style the closed surface. Select
**Search Panel** to edit dialog, clear, close, and empty-state labels and to
style the suggestion tray or dialog surface. The Modal Search parent has an
editor-only preview toggle; it does not open the storefront dialog by default.

Both children expose standard WordPress color, typography, spacing, border, and
dimension supports. Shift64-specific appearance settings are not added to the
database. Global Styles and block styles therefore remain the source of truth.

## Storefront behavior

Both variants render ordinary `method=get` search forms with `s` and
`post_type=product`. They work without JavaScript and remain the fallback when
Redis is unavailable. When the Interactivity API module loads, each parent gets
isolated autocomplete, request cancellation, keyboard navigation, live status,
and canonical Product Collection navigation. Modal Search uses a native
`<dialog>`, restores focus to its trigger, and closes on Escape.

Autocomplete still calls the generated SHORTINIT endpoint. No Redis schema,
configuration, or response contract changes are required.

## Existing content and migration

The public parent names did not change. A legacy self-closing Search or Modal
Search comment with no children continues to use the previous PHP/shortcode
renderer, including its text, labels, and supported custom appearance values.
Opening and saving that content in the editor materializes Search Control and
Search Panel children.

The migration preserves visible and accessible copy. Legacy bespoke modal
colors and numeric sizing controls are retained by the PHP fallback but are not
the forward authoring model; after migration, use the nearest native child
block supports. Exact pixel parity is intentionally not guaranteed. Rolling
back before the separate legacy-removal work restores the old renderer because
the parent names and migration attributes remain readable.

If the saved child structure is invalid, the editor displays a warning and a
**Repair search parts** action. Repair is explicit and never silently discards
saved frontend content.

## Development

Block source lives under `src/blocks/` and the shared public store under
`src/interactivity/search/`. Release-ready generated assets live under
`build/blocks/` and are committed because release ZIPs exclude Node tooling.

```bash
npm ci
npm run validate:block-metadata
npm run lint:blocks
npm run test:blocks
npm run build:blocks
```

PHP registration uses the generated metadata directories. Do not hand-edit
`build/blocks/`; update source and rebuild instead.
