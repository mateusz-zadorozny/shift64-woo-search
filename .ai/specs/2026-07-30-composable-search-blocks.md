# Composable Search Blocks

> **Status:** implemented — PR #60, 2026-08-04

## 📝 TLDR

Rebuild the existing Search and Modal Search blocks as Site Editor-native
parents containing two locked, independently styleable children: the normal
control and its suggestion tray or open modal. Use standard block supports for
appearance and the WordPress Interactivity API for behavior while preserving
the two existing parent block names.

## 📝 Problem Statement

The current dynamic blocks mix behavior, structure, and bespoke appearance
attributes in one PHP registration and imperative frontend script. Their open
surfaces cannot be styled independently in the Site Editor, and the modal
currently needs runtime style copying because it is portaled outside the block
wrapper.

## 📝 Proposed Solution

Introduce metadata-based parent and child blocks, persist the fixed child
structure as InnerBlocks, and server-render progressive-enhancement markup.
Move interaction to a small public Interactivity API store while keeping the
existing SHORTINIT autocomplete endpoint and native search form fallback.

The public parent names remain:

- `shift64-woo-search/search` for the inline search field and suggestion tray;
- `shift64-woo-search/modal-search` for the closed trigger and open dialog.

Both parents contain exactly:

- `shift64-woo-search/search-control`; and
- `shift64-woo-search/search-panel`.

The parent supplies the `inline|modal` variant through block context. The
children own their content settings and block-support styling, so the closed
state and open surface can be selected and styled separately. They are locked
against removal, insertion, and movement but remain selectable; WordPress
`contentOnly` locking is not used because it would hide their design tools.

## 📝 Decisions

1. Existing parent block names are stable; existing content must continue to
   render before it is opened in the editor.
2. Child blocks are private structural children: they may appear only under
   either Search parent and are not independently insertable.
3. Appearance uses Site Editor block supports and Global Styles. There is no
   Shift64 admin appearance screen and no new bespoke color/size attributes.
4. The inline form and modal form keep native `GET` submission as their
   no-JavaScript and Redis-failure path.
5. Autocomplete continues to use the existing SHORTINIT endpoint and response
   contract; this spec changes its consumer, not its search backend.
6. The modal uses native `<dialog>` semantics on the supported modern browser
   baseline, with explicit focus restoration and Escape/close behavior.
7. Search submission resets Product Collection pagination and navigates to the
   canonical product-search URL through the shared catalog-state/router
   foundation.

## 📝 Research

- WordPress metadata registration is the canonical way to register editor and
  frontend assets, block supports, context, rendering, and Interactivity API
  modules. The current manual PHP-only registration obscures these contracts.
- `InnerBlocks` templates plus `templateLock="all"` protect a structural pair
  while allowing each child to retain normal design tools.
- Interactivity API server directives preserve progressive server rendering
  and can declare client-navigation compatibility, avoiding imperative DOM
  reinitialization after router updates.
- A native `<dialog>` supplies modal focus containment, Escape handling, and
  background inertness on the WordPress 7.0 browser baseline. Shift64 still
  owns labelling, initial focus, focus restoration, and reduced-motion CSS.

References:

- [WordPress block metadata](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/)
- [WordPress nested blocks](https://developer.wordpress.org/block-editor/how-to-guides/block-tutorial/nested-blocks-inner-blocks/)
- [WordPress block locking](https://developer.wordpress.org/block-editor/how-to-guides/curating-the-editor-experience/block-locking/)
- [WordPress Interactivity API](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/)

## 📝 Architecture

### Block registration and build

Introduce a conventional `src/blocks/` source tree and
`@wordpress/scripts` build:

```text
src/blocks/search/
src/blocks/modal-search/
src/blocks/search-control/
src/blocks/search-panel/
src/interactivity/search/
build/blocks/...               generated distributable assets
```

Each block has `block.json` with API version 3. PHP registers the build
directory via metadata; dynamic children use `render.php` (or a thin render
callback) and `viewScriptModule`. Generated assets are committed only if that
matches the repository's release packaging convention established during the
first block-build PR.

The parent edit component inserts its child template once:

```text
Search parent (variant in context, templateLock=all)
├── Search Control (lock move/remove)
└── Search Panel   (lock move/remove)
```

`allowedBlocks`, `parent`, `ancestor`, and `usesContext` enforce the same
structure from both sides. Invalid or legacy child content is repaired in the
editor only after user confirmation; frontend rendering never discards saved
content silently.

### Rendering boundaries

The parent renders only the semantic wrapper, shared store context, and its
saved inner content. The children render variant-specific elements:

| Child | Inline variant | Modal variant |
| --- | --- | --- |
| Search Control | Search form/input/submit | Dialog trigger |
| Search Panel | Suggestion list/tray | Dialog containing search form, clear/close controls, and suggestion list |

The Panel is present in server markup but closed/hidden until activated. For
the modal variant it may use the Interactivity Router's overlay attachment
mechanism only if the public API can preserve the native dialog node; no
runtime copying of computed styles is allowed. Block-support classes and CSS
custom properties travel with the Panel's own wrapper.

### Interactivity store

Use one public namespace, `shift64-woo-search/search`, initialized from
server-rendered context scoped per block instance. State includes:

- stable instance ID;
- variant and open/closed state;
- current query text;
- request status;
- active suggestion index;
- normalized suggestions;
- endpoint URL and minimum-character configuration; and
- references needed to restore focus.

Actions cover:

- open/close/toggle;
- input/debounced request;
- abort superseded request;
- keyboard listbox navigation;
- clear;
- choose suggestion; and
- submit canonical product search.

Derived state supplies busy/expanded attributes, active descendant, result
visibility, and no-results/error labels. The store never contains appearance
settings.

Every block instance owns its request and focus state. Opening one modal closes
another through a small namespace-level coordinator, not global DOM selectors.

### Endpoint and navigation

Reuse the existing SHORTINIT autocomplete endpoint, escaping its response into
an internal normalized suggestion model. AbortController cancels stale
requests. A monotonically increasing request token prevents an older response
from replacing a newer query even where abort delivery races.

Submitting builds the canonical search URL through the Catalog State contract
from `2026-07-30-block-theme-product-collection-integration.md`, removes all
pagination forms, and delegates to the core router when compatible. The form's
normal `action`, `method=get`, `s`, and `post_type=product` remain sufficient
without JavaScript.

## 📝 Data Model

### Parent attributes

| Block | Attribute | Type/default | Contract |
| --- | --- | --- | --- |
| both | `instanceId` | string/generated | Stable within saved content; sanitized HTML-safe ID stem |
| modal only | `previewOpen` | boolean/false | Editor-only preview; never opens the frontend |

The parent stores structure and identity, not appearance or visible labels.

### Search Control attributes

| Attribute | Type/default | Contract |
| --- | --- | --- |
| `label` | string/translated default | Accessible form or trigger label |
| `placeholder` | string/translated default | Used where the variant renders an input |
| `submitLabel` | string/translated default | Visible submit text |
| `triggerLabel` | string/translated default | Used by modal trigger |
| `triggerIcon` | enum `none|search` / `search` | Closed, versionable icon set |

### Search Panel attributes

| Attribute | Type/default | Contract |
| --- | --- | --- |
| `dialogLabel` | string/translated default | Modal accessible name |
| `closeLabel` | string/translated default | Close control accessible/visible text |
| `clearLabel` | string/translated default | Clear control text |
| `noResultsLabel` | string/translated default | Empty suggestion state |

For the modal variant, Panel also owns the form labels inherited as initial
defaults from Control. A later enhancement may split further primitives, but
this release intentionally keeps the user-approved two-child contract.

Standard block attributes generated by supports (`style`, class names,
alignment, typography presets, colors, spacing, border, dimensions) remain
WordPress-owned. `block.json` selectors map supported styles to each child's
semantic wrapper/control without saving Shift64-specific color fields.

No option, Redis schema, endpoint, or generated MU-plugin config field is
added.

## 📝 API Contracts

### Block names and relationships

- `shift64-woo-search/search` — existing public parent.
- `shift64-woo-search/modal-search` — existing public parent.
- `shift64-woo-search/search-control` — new child, parent-restricted.
- `shift64-woo-search/search-panel` — new child, parent-restricted.

Parents provide:

- `shift64WooSearch/variant`;
- `shift64WooSearch/instanceId`; and
- editor preview context where required.

Children consume those values and expose standard block supports. The
frontend interactivity namespace is
`shift64-woo-search/search`; no global `window.shift64...` object is public.

### Legacy content migration

Existing block comments contain only parent attributes and dynamic output.
Compatibility has two layers:

1. PHP render fallback maps old parent content/appearance attributes into the
   new child render model when no child content has yet been saved.
2. On first editor save, a versioned block deprecation/transform materializes
   the two children, migrates content labels and the nearest equivalent
   standard styles, and removes obsolete custom appearance attributes.

Exact visual parity is not promised for bespoke legacy modal styles, but
content labels and accessible behavior are preserved. The migration is
documented and covered by serialized fixture tests. Parent names never change.

## 📝 UI/UX

### Site Editor

- Inserting either parent produces the complete locked two-child structure.
- List View shows **Search Control** and **Search Panel**, both selectable.
- Selecting Control exposes its labels and normal block design tools.
- Selecting Panel exposes open-surface labels and its own colors, typography,
  spacing, border, dimensions, and background.
- The Panel displays a deterministic sample-results preview. Modal Search has
  an editor-only open/closed preview toggle.
- No control links to Shift64 admin appearance settings.

### Storefront

- Inline Search exposes a labelled search input and submit button; suggestions
  use combobox/listbox semantics without trapping Tab.
- Modal Search trigger exposes `aria-haspopup="dialog"`; opening moves focus
  to the search input, Escape and Close dismiss it, and focus returns to the
  opener.
- Loading, no-results, and request-error states are announced without
  repeating every keystroke.
- Arrow keys move the active suggestion, Enter chooses it, Escape closes the
  list/dialog as appropriate, and pointer selection behaves identically.
- Reduced-motion preferences suppress nonessential panel transitions.
- Backdrop/panel stacking is local and deterministic; the backdrop is above
  page search fields and the panel is above its backdrop.

## 📝 Edge Cases & Failure Scenarios

- **Endpoint unavailable/Redis degraded:** stop the busy state, announce that
  suggestions are unavailable, and leave native form submission usable.
- **Slow/out-of-order response:** aborted/token-stale results are ignored.
- **Multiple blocks:** IDs, listbox ownership, requests, and focus are isolated.
- **Duplicate pasted `instanceId`:** renderer suffixes the runtime DOM ID while
  leaving saved content untouched; editor repair can persist a unique ID.
- **Legacy block never re-saved:** PHP fallback continues to render it.
- **Legacy unsupported style value:** migrate the nearest standard support or
  omit it with an editor notice; never emit unsafe inline CSS.
- **Empty query:** close suggestions, abort work, and prevent an empty modal
  autocomplete request; normal form validation remains visible.
- **Suggestion removed/unpublished before click:** navigate to its canonical
  URL and let WordPress return the normal result.
- **Router navigation replaces a region:** client-navigation-compatible module
  remains initialized; state is rehydrated from new server context.
- **Native dialog unsupported despite baseline:** the form remains in markup
  and can be reached through its non-modal fallback; this is a defensive path,
  not a supported-browser target.

## 📝 Risks & Impact Review

- **Compatibility:** parent names and visible labels are preserved. Child
  blocks and attributes are additive; removal of shortcodes is deferred to the
  dedicated legacy spec.
- **Serialization risk:** dynamic legacy blocks need fixture-based migration
  tests because their old content has no InnerBlocks.
- **Accessibility risk:** modal and combobox keyboard/focus behavior require
  real-browser tests, not only DOM snapshots.
- **Styling risk:** standard block supports cannot reproduce every old custom
  slider. This is an intentional pre-1.0 simplification and requires a
  migration note.
- **Performance:** the block loads its view module only when rendered and
  debounces/aborts requests. No autocomplete request happens until input meets
  the configured threshold.
- **Rollback:** legacy parent attributes remain readable during the transition,
  so rolling back before the final legacy cleanup restores old rendering.
- **Security:** endpoint output is rendered as text/validated URLs; no HTML
  from Redis enters the suggestion DOM.

## 📋 Phasing

- **Phase 1 — metadata parents/children and migration.** Blocks can be inserted,
  saved, independently styled, and render functional native forms.
- **Phase 2 — Interactivity API autocomplete and modal behavior.** Replaces the
  imperative block runtime while keeping the endpoint.
- **Phase 3 — Product Collection navigation adoption and compatibility
  cleanup.** Search submission participates in the shared router contract;
  obsolete block-only scripts/attrs become migration-only.

## 📋 Implementation Plan

### Phase 1

1. Add `@wordpress/scripts`, block source/build conventions, and a package
   command that validates all `block.json` files. Keep current blocks
   registered until metadata parity tests pass.
2. Register the two parent and two child metadata blocks with contexts,
   supports, allowed-parent constraints, and locked templates. Add editor
   snapshot/e2e coverage for insertion, List View, locking, and independent
   design controls.
3. Implement server renderers with native forms and deterministic editor
   previews. PHPUnit covers escaping, labels, unique IDs, and block-support
   wrapper attributes.
4. Add legacy PHP fallback plus versioned editor migration fixtures for every
   current parent attribute shape. Document appearance differences.

### Phase 2

5. Implement the scoped Interactivity API store and directives for inline
   suggestions, abort/race handling, selection, clear, and submission.
6. Add modal native-dialog actions, overlay placement, focus restoration,
   reduced-motion behavior, and stacking tokens owned by the Panel block.
7. Add Jest/unit coverage for pure state/URL helpers and Playwright coverage
   for keyboard, screen-reader attributes, multiple instances, request errors,
   modal backdrop, and theme-editor styles.

### Phase 3

8. Route submission through the shared Catalog State/router utility and cover
   page reset, one history entry, Back, and full-reload fallback beside a real
   Product Collection.
9. Remove the superseded imperative block-editor/frontend initialization only
   after both parent fixture families pass. Keep shortcodes until the
   block-theme-only legacy-removal spec.
10. Update README/readme/changelog with insertion and migration instructions;
    flip this spec and its index row in the implementation PR.
