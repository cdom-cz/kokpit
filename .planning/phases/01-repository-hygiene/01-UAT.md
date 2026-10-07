---
status: complete
phase: 01-repository-hygiene
source: [01-VERIFICATION.md]
started: 2026-10-07T09:37:31.000Z
updated: 2026-10-07T21:25:37.060Z
---

## Current Test

[testing complete]

## Tests

### 1. First GitHub CI run
expected: Push the branch, open the pull request into main and read the Hygiene workflow run. Both jobs and "CI Passed" are green; gitleaks reports its commit count and "no leaks found" (first run with the new --log-opts).
result: pass
note: "Self-test on PR #1 (run 37642214531): 'Sensitive-content and secret scan' pass, 'Workflow lint' pass, 'CI Passed' pass; gitleaks 'INF 42 commits scanned', 'no leaks found'; PR mergeable, mergeStateStatus CLEAN."

### 2. GitHub repository settings
expected: Secret scanning and push protection enabled (including non-provider patterns and validity checks); the ruleset on main requires a pull request and the status check "CI Passed"; the Actions pinned-to-SHA policy is enabled; "Allow GitHub Actions to create and approve pull requests" is off; Dependabot security updates are on (closes threat T-01-20). Tick the CONTRIBUTING.md "GitHub settings checklist". Optionally push a fake provider key from a throwaway branch and confirm push protection rejects it.
result: pass
note: "Re-verified via gh api after the owner changed the settings in the UI: secret scanning, push protection, non-provider patterns and validity checks on; ruleset main requires a pull request and CI Passed; sha_pinning_required=true; can_approve_pull_request_reviews=false; Dependabot security updates enabled. Push-protection live test not run. CONTRIBUTING.md checklist ticking is left to the owner."

### 3. Owner policy decisions
expected: Decide whether the ruleset uses "Require review from Code Owners" (CODEOWNERS is only a review request otherwise, and a solo maintainer cannot approve their own pull request), and whether to switch commit_docs on now that all planning documents pass the checker. Record both decisions in the project state.
result: pass
note: "User confirmed pass. Decisions themselves not stated in the session; observed state: require_code_owner_review=false, commit_docs=false. Recorded in STATE.md (Accumulated Context, Decisions): no code-owner review requirement, commit_docs stays off until UAT test 4 is clean."

### 4. Scanner run with the real local denylist
expected: Run scripts/check-sensitive.sh --all, then --history, then the same over the untracked .planning/ files (explicit operands) with KOKPIT_DENYLIST pointing at your own list outside the repository, before .planning/ is ever committed. All three runs print "clean" and no "KOKPIT_DENYLIST not set" notice. Export KOKPIT_DENYLIST in the environment your IDE or Git GUI uses to commit (review finding WR-10: the hook hides the notice on success).
result: pass
note: "Owner re-ran --all and --history with KOKPIT_DENYLIST exported (it had been set but not exported at first): both 'clean', no 'KOKPIT_DENYLIST not set' notice. Owner confirmed the run over the untracked .planning files also works."

## Summary

total: 4
passed: 4
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

[none - G-01-2 closed by owner configuration change, re-verified via gh api]
