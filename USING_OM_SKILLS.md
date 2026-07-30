# Using OM Skills

This repository ships 38 skills in `.agents/skills/` — 34 `om-*` skills that automate the
path from "here is a task" to "here is a reviewed, merged pull request", plus four `wp-*`
skills carrying WordPress and Gutenberg domain knowledge. This note explains where to
start, which skill to pick, and what each one does.

If you read nothing else: **run `/om-setup-agent-pipeline` once, then use
`/om-auto-create-pr` for work and `/om-auto-review-pr` for review.**

## What these skills actually are

A skill is a Markdown instruction file that Claude Code loads on demand. It is not a script
you execute in a terminal — you invoke it in a Claude Code session by typing `/skill-name`,
and the agent then follows the instructions in that file. `.agents/skills/` holds the real
files (committed to git). `.claude/skills/` is a directory of symlinks pointing at them —
that is only how Claude Code discovers them, and it is gitignored. Edit files under
`.agents/`, never under `.claude/`.

Because they are instructions rather than code, the skills all read shared configuration
instead of hardcoding this project's details. That configuration is one file.

## Start here: the pipeline is already configured

Every `om-*` skill begins by loading `.ai/agentic.config.json`. **That file exists in this
repository**, so there is no setup step before your first task — go straight to
`/om-auto-create-pr`. Its committed configuration is:

- `baseBranch: "auto"` (resolved to the repository's default branch, `main`);
- `validation.commands` — `composer validate --strict`, `vendor/bin/phpcs`,
  `vendor/bin/phpunit`;
- the OM label taxonomy, with `qaGate: true`;
- `paths` for plans, specs, analysis, scripts, and QA artifacts under `.ai/`.

Re-run the setup skill only when the toolchain or the label taxonomy changes:

```
/om-setup-agent-pipeline
```

It inspects the repo, asks a handful of questions, and writes (or refreshes):

- `.ai/agentic.config.json` — the base branch, the validation commands, the label taxonomy,
  the QA gate switch, and where plans/specs/QA artifacts live. Every other skill reads it.
- `.ai/trackers/github.md` — the "tracker descriptor". No skill calls `gh` directly; they
  name operations like *create-pr* or *comment-pr*, and this file defines how each is run.
  Editing this file changes every skill's behavior at once.
- `.ai/browsers/agent-browser.md` — the same idea for browser automation.
- `SDLC.md`, `CODE_REVIEW.md`, `BACKWARD_COMPATIBILITY.md` — generated only if missing.
  `AGENTS.md` already exists here, so setup will leave it alone.

Two of those answers were already decided here, and a re-run should not casually reverse
them:

1. **Validation commands.** The gate is `composer validate --strict`, `vendor/bin/phpcs`,
   and `vendor/bin/phpunit`. CI additionally runs the suite across the supported PHP range.
   Playwright is deliberately kept out of the gate — see the E2E section of
   [CONTRIBUTING.md](CONTRIBUTING.md).
2. **Labels.** The repo carries an older `agent:*` taxonomy alongside the OM one
   (`review`, `changes-requested`, `qa`, `merge-queue`, …), which is what
   `.ai/agentic.config.json` declares. Setup never deletes or recolors labels you already
   have.

`.ai/` is committed: it is team configuration, not personal preference.

## Which skill do I use?

Find your situation in the left column.

| I want to… | Use | Notes |
| --- | --- | --- |
| Configure the pipeline (first time) | `om-setup-agent-pipeline` | Run once per repo; re-run when the toolchain or labels change. |
| Implement a task and get a PR | `om-auto-create-pr` | The main entry point. Plans, works in an isolated worktree, runs the gate, opens a labeled PR. |
| Implement a long, multi-phase spec | `om-auto-create-pr-loop` | Same idea, but with a run folder, one commit per step, and checkpoints every ~5 steps. Use for specs, not small fixes. |
| Resume a PR the agent left half-finished | `om-auto-continue-pr` <br> `om-auto-continue-pr-loop` | Pick the one matching how the PR was started. |
| Fix a GitHub issue, end to end | `om-auto-fix-issue` | Give it an issue number; it chains triage → root cause → fix → PR → review loop. |
| Review a PR | `om-auto-review-pr` | Reviews in a worktree, submits approve/request-changes, drives an autofix loop when changes are needed. |
| Review every unreviewed open PR | `om-review-prs` | Batch wrapper around the above, newest first. |
| Just make my branch green and push it | `om-check-and-commit` | Runs the full gate, fixes easy failures, commits, pushes. No PR. |
| Approve and merge a PR by number | `om-approve-merge-pr` | Refuses when the QA gate or a blocking label says no. |
| See what is mergeable right now | `om-merge-buddy` | Read-only report across open PRs. |
| Drive one PR all the way to merge-ready | `om-auto-fix-pr` | Merges the latest base, then loops review-autofix, CI stabilization, and UI QA until approvable. Hands off to `om-approve-merge-pr`; never merges itself. |
| Get red CI to green | `om-auto-fix-pr --ci-only` | Classifies each failure as real bug / test bug / flake / infra. Never disables checks to pass. Works on a PR or a plain branch. |
| Think an idea through before building | `om-brainstorm` | Divergent conversation, one question at a time. Alternatives include building nothing. Runs before the spec skills. |
| Shape a vague product or UI idea | `om-ux-shape` | Turns ambiguity into a decided direction, screen states, and an engineering handoff. |
| Write or review a feature spec | `om-spec-writing` | Its output feeds `om-auto-create-pr-loop`. |
| Get a spec written and landed on a PR | `om-auto-write-spec` | Unattended wrapper: writes the spec, attaches mockups, opens the PR. Chains into `om-auto-implement-spec`. |
| Implement an existing spec end to end | `om-auto-implement-spec` | Resolves the spec by path, name, issue, or spec-PR number, then delegates to the create/continue skills and the review loop. |
| File an issue without implementing it | `om-prepare-issue` | Checks for duplicates, writes step-by-step guidance into the body. |
| Clean up issues that already exist | `om-auto-manage-issues` | Applies missing SDLC labels, clarifies laconic issues, flags feature issues with no covering spec. Never implements anything. |
| Turn PR feedback into a follow-up issue | `om-followup-issue-from-pr` | Paste a PR or PR-comment link. |
| Set up a local test/QA environment | `om-prepare-test-env` | Prerequisite for the two QA skills below. |
| QA a UI change in a real browser | `om-auto-qa-pr` | Screenshots and a pass/fail report. Never touches source. Also runs locally against the current worktree. |
| Run or write integration/E2E tests | `om-integration-tests` | Reuses the environment from `om-prepare-test-env`. |
| Design-review a PR's UI | `om-ux-review-pr` | Walks the changed screens in a browser and ranks findings by user impact, each with evidence. Needs `om-ux-setup` first. |
| Capture the repo's design contract | `om-ux-setup` | Extracts tokens, components, and screen archetypes into `.uxproof/`. Run once; re-run after design-system changes. |
| Draft the changelog for a release | `om-auto-update-changelog` | Lands as a docs PR. |
| Close issues after PRs merged | `om-close-fixed-issues` | Post-merge housekeeping. Acts only on authoritative close links, never on bare `#N` mentions. |
| Write a new om-skill | `om-create-skill` | Knows the house conventions and lint rules. |
| Apply an upgrade of the skills collection | `om-apply-upgrade-notes` | Re-syncs descriptors while preserving local edits. |

The four `wp-*` skills are domain references rather than pipeline steps — they carry
WordPress and Gutenberg knowledge that the pipeline skills draw on while working in this
plugin.

| I am working on… | Use |
| --- | --- |
| Gutenberg blocks — `block.json`, attributes, dynamic rendering, deprecations | `wp-block-development` |
| Block themes — `theme.json`, templates, patterns, Site Editor behavior | `wp-block-themes` |
| The Interactivity API — `data-wp-*` directives, store/state/actions, hydration | `wp-interactivity-api` |
| Getting a structured inventory of a WordPress repo's tooling and tests | `wp-project-triage` |

Four skills — `om-verify-in-repo`, `om-root-cause`, `om-fix`, `om-open-pr` — are the internal
steps of the autofix chain. `om-auto-fix-issue` calls them in order. You rarely invoke them by
hand; do so only when you want to stop between steps and inspect the result.

## How the pieces fit together

The chain that `om-auto-fix-issue` drives, which is also the mental model for the rest:

```
issue → om-verify-in-repo   (is this still a real bug? stops early if not)
      → om-root-cause       (read-only: where is it, what must change)
      → om-fix              (minimal change + regression tests + gate)
      → om-open-pr          (commit, push, draft PR, labels)
      → om-auto-review-pr   (review; loops until clean)
      → om-approve-merge-pr (merge, if the gate allows)
```

`om-code-review` is the engine underneath every review: `om-auto-review-pr`, `om-review-prs`,
and the self-review steps inside the create/continue skills all call it. So if you want to
change *how* code gets reviewed in this repo, you do not edit six skills — you write
`CODE_REVIEW.md` at the root (picked up automatically) or point `reviewChecklist` in the
config at a file.

## Things that will bite you

- **Isolated worktrees.** The autonomous skills create a git worktree instead of working in
  your checkout. Your uncommitted changes are not visible to them, and their work will not
  show up in your `git status` until the branch is pushed.
- **The claim lock.** Skills mark an issue or PR `in-progress` while working, and other
  automation backs off when it sees that. If a run dies, the label can be left behind and the
  next run will refuse to start — remove it by hand.
- **Customize by configuration, not by forking.** Three extension points, in order of reach:
  edit `.ai/trackers/github.md` to change tracker operations everywhere; add
  `.ai/skills/<skill-name>/SKILL.md` to extend one skill for this repo only; edit
  `CODE_REVIEW.md` / `BACKWARD_COMPATIBILITY.md` to change what reviews enforce. Editing the
  files under `.agents/skills/` means your changes are overwritten on the next upgrade.
- **The release ZIP.** `build-release.sh` already excludes `.claude`, `.agents`, and `.ai`,
  so no pipeline configuration ships inside the plugin ZIP. If you add a new top-level
  automation directory, add it to that rsync exclude list too.

## A simpler way to think about it

Think of the skills as a team of contractors and `.ai/agentic.config.json` as the site
induction folder. Each contractor is competent and knows their trade in general, but before
touching anything they open the folder to learn *this* site's rules: which door is the main
entrance (`baseBranch`), what counts as "work is finished" (`validation.commands`), how to tag
a job as done (`labels`), and who signs it off (`qaGate`). Nobody starts before the folder
exists — that is why `om-setup-agent-pipeline` runs first, and why the folder is checked into
git instead of living on one person's laptop.
