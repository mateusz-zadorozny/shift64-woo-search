# Composable Search Blocks implementation

Source doc: .ai/specs/2026-07-30-composable-search-blocks.md

## Goal

Replace the two PHP-only search blocks with metadata-registered, composable
parent/child blocks whose independently styleable surfaces use progressive
server rendering and the WordPress Interactivity API, while preserving the
existing parent block names, SHORTINIT endpoint, native form fallback, and
legacy shortcode behavior.

## Scope

- Add a conventional `@wordpress/scripts` source/build pipeline and commit the
  distributable block assets required by release ZIPs.
- Register Search and Modal Search parents plus locked Search Control and Search
  Panel children from API v3 block metadata.
- Add server-rendered native forms, listbox/dialog markup, scoped interactive
  state, canonical Product Collection navigation, and legacy parent fallback.
- Replace the old block-specific editor/runtime initialization only after
  migration fixtures and compatibility tests pass.
- Add PHP, JavaScript, and Playwright coverage, update maintained documentation,
  and mark the source spec implemented in this PR.

## Non-goals

- Do not change the SHORTINIT endpoint or Redis schema.
- Do not remove or rename either search shortcode or either public parent block.
- Do not implement Product Filter, Product Sort, or legacy-surface removal specs.
- Do not add Playwright to the hermetic agentic validation gate.
- Do not edit merchant templates automatically.

## Implementation Plan

### Phase 1: Metadata blocks, composition, and migration

1. Add `@wordpress/scripts`, block source/build conventions, build and metadata
   validation commands, and committed distributable assets.
2. Register the two parent and two child metadata blocks with context,
   constraints, locked templates, standard supports, and editor coverage.
3. Implement server renderers with native forms, deterministic editor previews,
   stable runtime IDs, escaping, and wrapper-support coverage.
4. Add PHP legacy fallback and versioned editor migration fixtures for existing
   parent attributes, and document intentional appearance differences.

### Phase 2: Interactivity API search and modal behavior

5. Implement the scoped Interactivity API store and directives for suggestions,
   request cancellation/race safety, selection, clear, and submission.
6. Add native-dialog behavior, instance coordination, focus restoration,
   reduced-motion behavior, and Panel-owned stacking styles.
7. Add JavaScript unit tests and Playwright coverage for keyboard semantics,
   multiple instances, request errors, modal behavior, and editor-facing styles.

### Phase 3: Catalog navigation and compatibility cleanup

8. Route enhanced submission through the shared Catalog State/router utility
   with pagination reset and progressive full-reload fallback coverage.
9. Remove superseded block-only imperative editor/frontend initialization after
   parent migration fixtures pass, while retaining shortcode runtime support.
10. Update README, readme, changelog, migration guidance, spec status, and the
    specs index; run the complete validation and UI verification flow.

## Risks

- Existing dynamic parent comments contain no InnerBlocks, so frontend fallback
  and editor migration must remain independently testable.
- Native dialog and combobox semantics can regress focus or keyboard behavior;
  unit tests are insufficient without browser verification.
- Search assets are also used by permanent shortcodes, so removing the old block
  initialization must not disable shortcode autocomplete or modal behavior.
- Metadata-generated handles and script-module dependencies vary across the
  WordPress 6.x/7.x compatibility boundary; unsupported versions must retain
  progressive forms without fatal errors.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Metadata blocks, composition, and migration

- [ ] 1.1 Add block tooling and committed distributable assets
- [ ] 1.2 Register metadata parents and constrained child blocks
- [ ] 1.3 Add server renderers, previews, IDs, and wrapper coverage
- [ ] 1.4 Preserve legacy parent rendering and editor migrations

### Phase 2: Interactivity API search and modal behavior

- [ ] 2.1 Implement scoped autocomplete state and actions
- [ ] 2.2 Implement native dialog, focus, and Panel-owned presentation
- [ ] 2.3 Add JavaScript and browser interaction coverage

### Phase 3: Catalog navigation and compatibility cleanup

- [ ] 3.1 Integrate canonical Product Collection navigation
- [ ] 3.2 Remove superseded block-only imperative initialization
- [ ] 3.3 Update docs and spec lifecycle, then complete verification
