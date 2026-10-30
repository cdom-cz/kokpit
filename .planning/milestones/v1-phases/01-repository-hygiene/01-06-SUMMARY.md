---
phase: 01-repository-hygiene
plan: 06
subsystem: infra
tags: [git-history-rewrite, gitignore, gitleaks, secret-scanning, phase-gate, owner-decision]

requires:
  - phase: 01-repository-hygiene (plans 01-05)
    provides: check-sensitive.sh (--all, --history, explicit files), .gitleaks.toml, lefthook hook, test suite, hygiene workflow
provides:
  - Real-repository phase gate green in tree, full history and planning docs (check-sensitive --all, --history, gitleaks git all exit 0)
  - Unpushed history rewritten so the pre-tooling personal home path is absent from every reachable commit
  - .planning/codebase/ ignored and untracked, on-disk copy kept with its personal path line sanitised
affects: [phase-02-ci, first-push]

plan_head_before: ca099da3b4de11e23972919b265661e6e67b0546
plan_head_after: b1ebdcc1142579d2ffcac7152f3e0120e3f01e79

actuals:
  tokens: 80
  tasks: 3
  commits: 1

tech-stack:
  added: []
  patterns:
    - "Backup bundle plus file copy outside the repository before any history rewrite; rewrite only unpushed history after explicit owner decision"
    - "Generated or foreign planning maps stay ignored until they pass scripts/check-sensitive.sh"

key-files:
  created: []
  modified:
    - .gitignore
    - .planning/codebase/STACK.md (untracked on-disk copy, personal path line replaced by a neutral sentence)

key-decisions:
  - "Owner chose rewrite-untrack: drop commit 5554573 from unpushed history, keep .planning/codebase/ untracked, ignored and on disk"
  - "plan_head_before is the rewritten equivalent of the pre-rewrite HEAD, because the original base hash is no longer reachable after the rebase"

patterns-established:
  - "Old-to-new commit mapping recorded in the SUMMARY so earlier SUMMARY hash references stay resolvable"

requirements-completed: [HYG-01, HYG-02, HYG-04, HYG-05, HYG-06, HYG-07]

coverage:
  - id: D1
    description: "check-sensitive --all, --history and gitleaks git exit 0 on the real repository after the rewrite"
    requirement: HYG-05
    verification:
      - kind: other
        ref: "scripts/check-sensitive.sh --all; scripts/check-sensitive.sh --history; gitleaks git --config .gitleaks.toml --redact --no-banner --ignore-gitleaks-allow --exit-code 1 ."
        status: pass
    human_judgment: false
  - id: D2
    description: "Every planning Markdown file, .planning/config.json and .claude/CLAUDE.md pass the explicit-file scan"
    requirement: HYG-06
    verification:
      - kind: other
        ref: "find .planning -type f -name '*.md' -print0 | xargs -0 scripts/check-sensitive.sh -- .planning/config.json .claude/CLAUDE.md"
        status: pass
    human_judgment: false
  - id: D3
    description: "Commit 5554573 is unreachable, the codebase map is untracked and ignored but still on disk, origin still holds only main at the initial commit"
    requirement: HYG-07
    verification:
      - kind: other
        ref: "git rev-list --all has no 5554573; git ls-files .planning/codebase empty; git check-ignore -q .planning/codebase/STACK.md; git ls-remote --heads origin"
        status: pass
    human_judgment: false
  - id: D4
    description: "Full test suite green under /bin/bash 3.2, Homebrew bash 5.3 and ubuntu:24.04 with mawk"
    requirement: HYG-04
    verification:
      - kind: unit
        ref: "bash scripts/tests/run.sh; /bin/bash scripts/tests/run.sh --quick; bash scripts/tests/run-in-ubuntu.sh"
        status: pass
    human_judgment: false
  - id: D5
    description: "First push, pull request with green CI Passed, and GitHub settings checklist"
    verification: []
    human_judgment: true
    rationale: "Outward-facing actions are owner-only (plan prohibition, orchestrator decision 9); the Hygiene workflow can only be observed on GitHub"

duration: 10min (continuation after the Task 2 checkpoint; Task 1 ran in an earlier agent)
completed: 2026-10-07
status: complete
---

# Phase 1 Plan 06: Real-repository hygiene gate and history cleanup Summary

**The personal home path from the pre-tooling commit is gone from every reachable commit: the unpushed history was rewritten without commit 5554573, the codebase map is ignored and kept on disk sanitised, and all scans and the suite are green with origin untouched.**

## Performance

- **Duration:** about 10 min for this continuation (Task 1 ran in an earlier agent run, Task 2 was the owner's decision)
- **Completed:** 2026-10-07
- **Tasks:** 3 of 3
- **Files modified:** 2 (`.gitignore` tracked; `.planning/codebase/STACK.md` untracked on disk)

## Accomplishments

- Task 1 (tracer): full gate run on the real repository, single pre-tooling finding isolated (evidence below).
- Task 2: owner answered the blocking decision with `rewrite-untrack`.
- Task 3: backup created, history rewritten, `.gitignore` extended, scans and suite green, nothing pushed.

## Task 1 evidence (tracer, no tracked file changed, no commit)

Local gate, all green: `scripts/tests/run.sh` PASS 7; `/bin/bash` 3.2 `--quick` PASS 5; ubuntu with mawk exit 0; `lefthook validate` and `lefthook check-install` clean; shellcheck, actionlint, zizmor clean.

Real-repository scans reported only the known item, masked forms exactly as printed:

- tree: `.planning/codebase/STACK.md:50: home-path`
- history: `.planning/codebase/STACK.md:50@5554573: home-path`
- gitleaks: 20 commits scanned, 1 leak, rule `kokpit-home-path`, same file and line, commit 5554573, fingerprint `5554573:.planning/codebase/STACK.md:kokpit-home-path:50`

Two untracked planning-doc findings had already been fixed on disk: `.planning/research/FEATURES.md:548` and `.planning/phases/01-repository-hygiene/01-RESEARCH.md:335`.

Remote and history facts at that point: origin has only `main` at a50bf89; `origin/main..HEAD` was 19 commits with no merge commits; local `main` = a50bf89; stash empty; no later commit touched `.planning/codebase`; 5554573 added exactly the seven `.planning/codebase/*.md` files; 0ea5eaf added only `.gitignore`. HEAD at dispatch was cd17116.

## Task 2: owner decision

Option id selected by the owner: **`rewrite-untrack`**. Scope of the decision: rewrite unpushed local history to drop 5554573, keep `.planning/codebase/` untracked and ignored on disk with the personal path line sanitised. No push, pull request, settings change or deletion of on-disk files was authorised or done.

## Task 3: what was done

Preconditions held (main checkout, branch `base-crm-erp`, tracked tree clean, origin only main at a50bf89, no commit after 5554573 touching `.planning/codebase`, no merge commits, empty stash).

1. Backup outside the repository (session scratchpad, not recorded here): `git bundle create ... --all` (verified) and a copy of `.planning/codebase`.
2. `git rebase --rebase-merges --onto a50bf89 5554573 base-crm-erp`: succeeded, 18 commits replayed.
3. Restored `.planning/codebase/` from the copy (the checkout had removed the now-untracked files).
4. Replaced STACK.md line 50 (a per-user install path of the Node shims) with a neutral sentence without any path.
5. Added `/.planning/codebase/` with an explanatory comment to the Planning section of `.gitignore`, committed through the live lefthook hook (sensitive-content and gitleaks both passed).

### Old-to-new commit mapping

Commit 5554573 (`docs: map existing codebase`) is dropped. All others were replayed unchanged, old -> new:

| Old | New | Subject |
|---|---|---|
| 0ea5eaf | 0b52d82 | chore: add .gitignore |
| d6b29dc | a45c2b0 | feat(01-01): tracer - staged fake keys blocked by scanner |
| 9d78b20 | 1c6ae6e | test(01-01): add failing test for the lefthook pre-commit hook |
| 37e5c71 | 0d3257e | feat(01-01): add fail-closed hook installer |
| 74e41b4 | 8d764eb | feat(01-02): tracer - non-example e-mail blocked |
| bbbb632 | bbcf16b | test(01-02): add failing tests for company-id, iban and cz-account |
| 33b5881 | 2d2de33 | feat(01-02): add company-id, iban and cz-account rules |
| 5aca8c9 | 285c1d5 | test(01-02): add failing tests for public-ip, hosting-host and home-path |
| 1ec3836 | 7971737 | feat(01-02): add public-ip, hosting-host and home-path rules |
| c0aaf51 | 7a76c0c | feat(01-03): tracer - local denylist |
| 8624a43 | 6211e87 | test(01-03): add failing tests for explicit files, mode conflicts and --history |
| aa41cbb | bfc02f8 | feat(01-03): add explicit file lists, --history mode and the CI history scan |
| 4934fef | 0a2f54f | feat(01-04): tracer - fictional-data rule and review procedure |
| 7de0a13 | d255b5f | docs(01-04): CONTRIBUTING as full hygiene reference |
| f611a34 | 5cbff99 | test(01-04): add failing gitignore verdict tests |
| 0b1e0e1 | b326193 | feat(01-04): ignore reviewed gap list |
| 12c0036 | a3c3a7c | feat(01-05): tracer - gitleaks in the hook |
| cd17116 | ca099da | feat(01-05): lint-gate the workflow and keep pins current |
| (new) | b1ebdcc | chore(01-06): ignore the untracked GSD codebase map |

Earlier SUMMARY files (01-01 to 01-05) still cite the old hashes; resolve them through this table. The old objects stay in the local reflog and in the backup bundle only.

### Verification results (after the rewrite)

- `scripts/check-sensitive.sh --all`: clean (rc 0). `--history`: clean (rc 0).
- `gitleaks git ... --exit-code 1 .`: 20 commits scanned, no leaks (rc 0).
- Planning docs gate: every `*.md` under `.planning/`, `.planning/config.json` and `.claude/CLAUDE.md` clean (rc 0).
- Suite: default/Homebrew bash 5.3 PASS 7 FAIL 0, assertions 44/0; `/bin/bash` 3.2.57 `--quick` PASS 5 FAIL 0 SKIP 2; ubuntu:24.04 (mawk) rc 0, PASS 5 FAIL 0 SKIP 2.
- shellcheck, actionlint, zizmor (offline) and `lefthook validate` clean.
- Acceptance criteria: 5554573 absent from `git rev-list --all` and no branch contains it; a50bf89 is an ancestor of HEAD; `git ls-files .planning/codebase` empty; `git check-ignore -q .planning/codebase/STACK.md` succeeds; on-disk STACK.md exists with no personal path; `git ls-remote --heads origin` shows one head, main at a50bf89.

## Task Commits

1. **Task 1: Tracer, hygiene gate on the real repository** - no commit (no tracked file changed)
2. **Task 2: Owner decision** - no commit (checkpoint)
3. **Task 3: Apply the owner's choice** - `b1ebdcc` (chore) plus the history rewrite itself (no new commit)

**Plan metadata:** not committed (`commit_docs` is false; planning files stay untracked).

## Files Created/Modified

- `.gitignore` - ignores `/.planning/codebase/` with a comment on why and when to re-track
- `.planning/codebase/STACK.md` - untracked on-disk copy, personal path line replaced by a neutral sentence

## Decisions Made

- Owner decision `rewrite-untrack` applied exactly as written.
- The plan ledger in the git directory (`gsd-plan-head-before-01-06`) held the pre-rewrite HEAD cd17116, which is unreachable after the rebase. It was repointed to its rewritten equivalent ca099da so that `commits:` is measured correctly (1).

## Deviations from Plan

None - plan executed exactly as written. The ledger repoint above is bookkeeping caused by the rewrite itself, not a change of behaviour.

## Issues Encountered

- The pre-rewrite `plan_head_before` could not be measured against the rewritten history; handled by repointing the ledger (see Decisions).
- One ad-hoc shell check of the acceptance criterion for line 50 misreported FAIL because of a `grep -q` pipeline quirk in the tool shell; the literal plan command (`! grep ... | grep -v ...`) run under `bash -c` passes, and the file contains no matching path.

## Known Stubs

None.

## Threat Flags

None. T-01-29 (personal path disclosure) and T-01-30 (rewrite safety: backup, preconditions, mapping) are mitigated; T-01-33 holds (no push, PR or settings change).

## User Setup Required

None - no external service configuration required. Owner actions remain (below).

## Next Phase Readiness

Owner to-do, outside this plan:

- Push `base-crm-erp` and open the pull request into `main`. The Hygiene workflow must show green "Sensitive-content and secret scan" and "Workflow lint" and a green required check "CI Passed"; the pull request must be mergeable under the org ruleset.
- Confirm the open items of the CONTRIBUTING.md GitHub settings checklist (secret scanning, push protection).
- Decide whether to set `commit_docs` to true: all planning docs now pass the checker.
- Re-map the codebase and remove `/.planning/codebase/` from `.gitignore` only after Kokpit code exists and the new map passes `scripts/check-sensitive.sh`.
- A backup bundle of the pre-rewrite history is kept in the session scratchpad; copy it elsewhere if it should outlive the session.

## Self-Check: PASSED

- `.gitignore` change present and committed: b1ebdcc is an ancestor of HEAD.
- `.planning/codebase/STACK.md` found on disk, untracked and ignored.
- Commit count measured: 1 (`ca099da..HEAD`).

---
*Phase: 01-repository-hygiene*
*Completed: 2026-10-07*
