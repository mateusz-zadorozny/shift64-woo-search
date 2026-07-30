# Skills collection full upgrade

## Goal

Commit the locally installed full skills collection upgrade so the repository, lockfile, and agent pipeline descriptors stay in sync.

## Scope

- Synchronize `.agents/skills/` with the complete local upgrade, including new, changed, renamed, and removed skills.
- Commit the matching `skills-lock.json` hashes.
- Include the related `.ai/agentic.config.json` and `.ai/trackers/github.md` compatibility updates.

## Non-goals

- Do not include the unrelated draft specs, temporary QA worktrees, or product-sort work currently present in the invoking checkout.
- Do not alter plugin runtime code or run E2E tests.

## Risks

The source checkout has unrelated uncommitted files. The upgrade is therefore copied only from the scoped skills and pipeline-descriptor paths into this isolated worktree, then verified against the lockfile.

## Implementation Plan

### Phase 1: Synchronize the collection

1. Copy the scoped full skills upgrade into the isolated branch.
2. Include the matching lockfile and pipeline-descriptor updates.

### Phase 2: Verify and publish

3. Verify the installed skills match the lockfile and inspect the scoped diff.
4. Run the applicable validation gate and publish the PR for review.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Synchronize the collection

- [x] 1.1 Copy the scoped full skills upgrade into the isolated branch — 80a5552
- [x] 1.2 Include the matching lockfile and pipeline-descriptor updates — 80a5552

### Phase 2: Verify and publish

- [ ] 2.1 Verify the installed skills match the lockfile and inspect the scoped diff
- [ ] 2.2 Run the applicable validation gate and publish the PR for review
