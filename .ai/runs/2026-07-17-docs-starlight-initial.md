# Documentation Starlight initial setup

## Goal

Create an isolated Astro Starlight documentation site for Shift64 Woo Search, with clear runtime requirements and release-safe packaging exclusions.

## Scope

- Add the Starlight application under `docs/`.
- Add repository and site ignore rules for documentation artifacts and local environment files.
- Publish introductory and requirements pages based on the current plugin metadata and configuration flow.
- Keep documentation sources and generated site assets out of WordPress plugin release archives.

## Non-goals

- Deploy or publish the documentation site.
- Change plugin runtime search behaviour or Redis configuration.
- Migrate existing planning notes into the published documentation structure.

## Risks

- Documentation requirements can become stale when plugin metadata changes; future runtime changes must update the Requirements page in the same commit.
- The documentation site uses its own npm dependencies and lockfile; its generated output and local environment files must remain untracked.
- Full PHPUnit validation requires the local WordPress test database at `127.0.0.1:3307`; it was unavailable during this isolated run.

## Implementation Plan

### Phase 1: Documentation foundation

1. Add the Starlight configuration, scripts, content collection, and site ignore rules.
2. Add the introduction, runtime requirements, and initial contributor guidance.

### Phase 2: Release safety and verification

1. Mark the documentation directory as export-ignored and confirm the existing release builder excludes it.
2. Build the static documentation site and inspect the final diff.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Documentation foundation

- [x] 1.1 Add the Starlight configuration, scripts, content collection, and site ignore rules. — 984c51f
- [x] 1.2 Add the introduction, runtime requirements, and initial contributor guidance. — 984c51f

### Phase 2: Release safety and verification

- [x] 2.1 Mark the documentation directory as export-ignored and confirm the existing release builder excludes it. — ce65dc0
- [x] 2.2 Build the static documentation site and inspect the final diff. — ce65dc0
