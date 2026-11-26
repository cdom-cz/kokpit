---
phase: 19-folders-tags-and-version-history
plan: 11
subsystem: knowledge
tags: [filament, modal-actions, restore, redaction, secret-guard, version-history, livewire]
requires:
  - "19-03: filament.knowledge.version-diff view, VersionDiff::compare() and lines()"
  - "19-06: ListArticleVersions, ViewArticleVersion"
  - "19-09: RestoreVersion, RedactVersion, RedactResult"
provides:
  - "ManagesVersionActions page concern: restoreVersionAction() and redactVersionAction()"
  - "filament.knowledge.redaction-preview view"
  - "Další akce group in the versions table; restore and redaction in the version view header"
  - "CONTRIBUTING.md Phase 19 hand-over bullet and its RepositoryFilesTest guard"
affects: [19-12]
tech-stack:
  added: []
  patterns:
    - "modal kept open on a refusal by ending the action body with Action::halt()"
    - "locked Livewire tokens taken in mountUsing() from a fresh read, never from the browser"
    - "acknowledgment recomputed from the stored version (hash_equals) on every call"
    - "ValidationException keys rewritten to mountedActions.0.data.<field>"
key-files:
  created:
    - app/Filament/Resources/KnowledgeArticleResource/Concerns/ManagesVersionActions.php
    - resources/views/filament/knowledge/redaction-preview.blade.php
    - tests/Feature/Knowledge/VersionRestoreModalTest.php
    - tests/Feature/Knowledge/VersionRedactModalTest.php
  modified:
    - app/Filament/Resources/KnowledgeArticleResource/Pages/ListArticleVersions.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/ViewArticleVersion.php
    - CONTRIBUTING.md
    - tests/Feature/Repo/RepositoryFilesTest.php
key-decisions:
  - "R-19-11-1 to R-19-11-7 implemented as written (halt-based modal, locked tokens, restore and redaction builders, preview, wiring, hand-over)"
  - "OD-3 handled: the redaction modal prints knowledge.redact.later_versions_keep inside the rotation callout"
  - "OD-4 recorded in the CONTRIBUTING bullet as one explicit disposition (see Hand-over notes)"
requirements-completed: [VER-03, VER-05]
metrics:
  tasks: 3
  completed: 2026-11-26
status: complete
commits: 3
plan_head_before: 2538ac152b250ace46c376c6578cc3f4c4338f59
plan_head_after: 251e456d45390fe71afb4f27049b060b04aae882
actuals:
  tokens: 18000
  tasks: 3
  commits: 3
---

# Phase 19 Plan 11: Restore and redact modals on the history screens Summary

Restore (diff first, then a new version) and redaction (irreversible, with the rotation and non-cascade warnings and a numbered preview) as modal actions on the versions table and the version view, with every refusal kept inside the modal, plus the Phase 19 hand-over in CONTRIBUTING.

## What was built

- **`ManagesVersionActions`** (trait, used by `ListArticleVersions` and `ViewArticleVersion`). Five `#[Locked]` properties (`restoreVersionNo`, `restoreExpectedVersion`, `restoreExpectedRedactionNo`, `restoreFindings`, `restoreAcknowledgeableHash`).
  - **`restoreVersionAction(Closure $versionNo)`** (`restoreVersion`): hidden on the current version and on an archived article; modal 5xl, heading and description from `knowledge.restore.*`; schema holds the blocking callout (role alert, finding lines through `guard-findings`, a link action that replaces the mounted action with the redaction of the same version), the ambiguous callout, the "identical" text and the `filament.knowledge.version-diff` view (current text against the chosen version, 60vh). `mountUsing()` stores the chosen number and takes `version_no` and `redaction_no` from a fresh read. The body recomputes the acknowledgment from the stored version (`hash_equals` over the sha256 of the normalised body, same version number), clears the stored refusal, calls `RestoreVersion`, and ends with `$action->halt()` on `SecretGuardRefused` (findings stored; hash stored only for an ambiguous-only refusal), `StaleArticle` (danger persistent notice; the redaction variant uses the `stale_redacted_*` copy) and `ArticleWriteFailed` (reported, the Phase 18 `write_failed` notice). A `ModelNotFoundException` for an archived article sends the archived warning and cancels. Success sends `restored` or `restored_sensitive` and redirects to the new version.
  - **`redactVersionAction(Closure $versionNo)`** (`redactVersion`): modal 3xl, danger; irreversible callout, rotation callout followed by `later_versions_keep` (OD-3), current-version note, the preview view, radio `scope` (default lines), `lines` (visible and required for the lines scope), `reason` (required, 500 characters). Field messages come from `knowledge.redact.errors.*`. `ValidationException` is rethrown with `mountedActions.0.data.<field>` keys; success sends `redacted` with the `key_extended` body when a key range grew, and a second persistent warning (`remaining_title` / `remaining_body`, rule label and line only) when the rescan still finds something; `ArticleWriteFailed` sends `failed_*` and halts. The reason goes only to `RedactVersion`; it is never copied into a property, a notification or a message.
- **`filament.knowledge.redaction-preview`**: one row per stored line, number and escaped text, scroll area of 320px, first 2,000 lines then `redact.source_truncated`; never through the renderer.
- **Pages**: `ListArticleVersions` gets the "Další akce" `ActionGroup` (icon button, tooltip and aria-label equal) holding restore (gray) and redaction; `ViewArticleVersion` gets restore (primary) and redaction (outlined danger) after compare-with-previous and re-reads its row after a redaction (`afterVersionRedacted()`), so the badge and callout appear in the same response.
- **Tests**: `VersionRestoreModalTest` (diff, view and table paths, hidden states, aria-label, identical, stale, blocking refusal without the value, ambiguous acknowledgment spent after one restore, acknowledgment dropped after a redaction, locked properties, write failure) and `VersionRedactModalTest` (warnings incl. `later_versions_keep`, hostile line escaped, 2,000-line cap, end-to-end proof of ROADMAP criterion 5 over version view, compare page and restore, table path, archived article, field errors under their fields, key range notice, remaining-finding notice, current version whole scope plus a stale editor, no reason/token on any surface, empty reason on a reopened modal).
- **CONTRIBUTING.md** Phase 19 bullet and `RepositoryFilesTest` case "hands Phase 19 over: the knowledge organisation bullet names only what exists" (names, classes, methods, enforcing test files, the log-capture claim and the absence of an error-reporter package).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] The restore diff is hidden while an unambiguous secret is stored as a refusal**
- **Found during:** Task 2
- **Issue:** the plan requires that the rendered modal after an unambiguous refusal never contains the sample, but the diff prints the stored lines of the chosen version, so the secret would be shown.
- **Fix:** the diff view is visible only while no Blocking finding is stored (the callout names rule and line, and offers the redaction). Before the first call the diff is shown, as the version view already shows the stored text to the Admin.
- **Files modified:** ManagesVersionActions.php
- **Commit:** 6cdbc63c

**2. [Rule 3 - Blocking] Commit grouping differs from the task split**
- The concern holds both builders and cannot be split without a throwaway half-version, and the CONTRIBUTING guard case names `VersionRedactModalTest`, which must exist first. Commits: (1) concern, preview view, both pages and `VersionRestoreModalTest` (Tasks 1 and 2 code, Task 3 code); (2) `VersionRedactModalTest`; (3) CONTRIBUTING and `RepositoryFilesTest`. The end state is as planned.

**3. [Rule 2] Stale copy for a redacted current version**
- A `StaleArticle` with `redactionChanged` uses `knowledge.notifications.stale_redacted_*` (UI-SPEC section 7) instead of `restore.notifications.stale_*`; both are danger and persistent.

**Total deviations:** 3 (2 Rule 2, 1 Rule 3). **Impact:** none on the contract.

## Hand-over notes

- **STATE.md Blockers/Concerns.** The Phase 19 item (retention, what a redaction rewrites) is answered by D-10 (no pruning, built nothing) and D-12 (a redaction rewrites the body of the chosen version and, when it is the current one, the article body).
- **Disposition of the cross-cutting item "Scrub the article body from Sentry and logs" (owner decision OD-4 of 2026-11-26).** No Sentry package, and no other external error reporter, is installed. The log part is answered by the content-free log assertions of 19-04 (`SaveArticleTokensTest`), 19-09 (`RedactVersionTest`) and 19-11 (`VersionRedactModalTest`) for the new Phase 19 restore and redaction paths. The Sentry part stays the deploy-time hand-over from Phase 18. The other half of that STATE.md line, the Filament SPA session behaviour on long edits, is not part of Phase 19 and stays open. Nothing beyond these paths is claimed.
- **OD-3.** The redaction modal shows the non-cascade warning `knowledge.redact.later_versions_keep` next to the rotation advice; `VersionRedactModalTest` asserts it.
- **OD-1.** The reason is validated by `RedactVersion` and discarded; only its length is stored; the end-to-end test searches notifications, page html, the Livewire public state, stored rows and the captured log for it.

## Not run in the worktree (no vendor, no database, per project rules)

Only `php -l` on every new or changed PHP file, the greps of the plan acceptance criteria (all met) and `scripts/check-sensitive.sh` ran, and the lefthook hook on each commit. Every Pest test is unrun, and the Blade view is uncompiled; the tracer feedback gate (re-running Task 1 `<verify>`) is the first post-merge run. The Filament 5 behaviour was taken from the vendor sources of the main checkout, read only. Expect small fixes at the first DDEV run, most likely in:

- the Filament test helpers (`mountAction` / `callAction` with `TestAction::make(...)->table($row)` for an action inside an `ActionGroup`, `fillForm` for the mounted action's data, `getSchema('mountedActionSchema0')->toHtml()` for the modal text);
- the nested link action `redactInstead` inside the callout footer and `replaceMountedAction('redactVersion', [], $this->mountedActions[0]['context'])` (the click itself has no Pest test; it is covered by the end-of-phase browser check);
- the exact Czech text matched in notification and modal assertions.

Post-merge commands (one Pest process at a time, never `--parallel`):

1. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/VersionRestoreModalTest.php tests/Feature/Knowledge/ArticleVersionsPageTest.php`
2. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/VersionRedactModalTest.php tests/Feature/Knowledge/VersionComparePageTest.php tests/Arch/KnowledgeSeamTest.php`
3. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php`, then the phase gate of the plan.
4. End-of-phase browser checks: a restore refused by the guard keeps the modal open with the callout in view and the "Redigovat verzi" link switches to the redaction; "Obnovit i přesto" restores and says the article is now sensitive; the redaction modal reads top to bottom as UI-SPEC section 8, the preview scrolls inside 320px, and the version view shows the badge and callout after submit without a reload.

Known limit worth a look in review: the acknowledgment hash is checked against the stored version just before `RestoreVersion` runs, and `RestoreVersion` takes a plain boolean, so a redaction landing in that short window is rescanned but acknowledged.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register: T-19-43 (locked tokens, hash recomputed), T-19-44 (rule and line only; reason never persisted), T-19-45 (escaped echo in the diff and preview views), T-19-46 (preview cap, diff budget), T-19-47 (pages refuse a Partner in mount, the Actions authorise again), T-19-48 (all three screens read the stored row).

## Self-Check: PASSED

- FOUND: app/Filament/Resources/KnowledgeArticleResource/Concerns/ManagesVersionActions.php, resources/views/filament/knowledge/redaction-preview.blade.php, tests/Feature/Knowledge/VersionRestoreModalTest.php, tests/Feature/Knowledge/VersionRedactModalTest.php; CONTRIBUTING.md bullet; RepositoryFilesTest case
- FOUND commits: 6cdbc63c, 9f5e4e92, 251e456d
