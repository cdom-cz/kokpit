---
phase: 04-clients-and-projects
plan: 21
subsystem: docs
tags: [contributing, readme, documentation-test, gitignore-review, phase-gate]

requires:
  - phase: 04-clients-and-projects
    provides: plans 04-01 to 04-20 (client, contact, project, tag, ARES, invitation, lockout and password reset mechanisms)
provides:
  - CONTRIBUTING Conventions for every Phase 4 mechanism, each next to the test that enforces it
  - Hand-over notes for Phases 5, 8 and 12 and the diacritics search gap
  - Partner-visible step in the add-a-model checklist
  - README operator notes for queued invitation and reset mail and for the Admin password reset page
  - Documentation tests that fail when a named Phase 4 class or test file does not exist
affects: [05-tasks, 08-exports, 12-api]

actuals:
  tokens: 2850
  tasks: 2
  commits: 1
plan_head_before: 3cdb503b3be077c09ed1c391807bfab7c24554fa
plan_head_after: 30b6e33df70439a10eb3c6283b836e4da9b23830

tech-stack:
  added: []
  patterns:
    - "Documentation tests: every class and test file named in CONTRIBUTING must exist"

key-files:
  created: []
  modified:
    - CONTRIBUTING.md
    - README.md
    - tests/Feature/Repo/RepositoryFilesTest.php

key-decisions:
  - "The .gitignore needed no change: the review found no new Phase 4 tool output"
  - "The production config guard is not weakened to make a no-.env fresh clone install; the fresh-clone check was also run the way the README installs (with .env copied from .env.example)"

patterns-established:
  - "A CONTRIBUTING bullet per mechanism, naming the class and the enforcing test; the documentation test checks both exist"

requirements-completed: [US-02, CL-01, CL-02, CL-04, CL-05, PR-01, PR-02, PR-03, PR-04]

coverage:
  - id: D1
    description: "CONTRIBUTING Conventions name each Phase 4 mechanism with its enforcing test, plus the hand-over notes and the Partner-visible checklist step"
    requirement: "PR-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#documents the Phase 4 hand-over notes and the password reset path"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only names Phase 4 tests in CONTRIBUTING.md that exist"
        status: pass
    human_judgment: false
  - id: D2
    description: "A documentation test fails when a Phase 4 class named in CONTRIBUTING does not exist"
    requirement: "PR-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only names Phase 4 classes in CONTRIBUTING.md that exist in app/"
        status: pass
    human_judgment: false
  - id: D3
    description: "README tells the operator that invitations and resets are queued e-mails and no longer says the Admin has no password recovery"
    requirement: "US-02"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#documents the Phase 4 hand-over notes and the password reset path"
        status: pass
    human_judgment: true
    rationale: "The wording of the operator notes is judged by a reader; the test only proves the old sentence is gone and the new path is named"
  - id: D4
    description: "Phase gate: full suite, Pint, Larastan, licences, S3 group, shell self-tests, sensitive scan of the whole tree, composer validate"
    verification:
      - kind: other
        ref: "ddev composer ci (1547 passed, Pint, PHPStan no errors, 210 packages allowed)"
        status: pass
      - kind: integration
        ref: "ddev exec vendor/bin/pest --group=s3 (9 passed)"
        status: pass
      - kind: other
        ref: "bash scripts/tests/run.sh; scripts/check-sensitive.sh --all; composer validate --strict"
        status: pass
    human_judgment: false
  - id: D5
    description: "Fresh clone installs from the lock including the tags plugin and keeps the storage placeholders"
    verification:
      - kind: other
        ref: "git clone + cp .env.example .env + composer install (exit 0); the plan's literal command without a .env fails, see Issues Encountered"
        status: fail
    human_judgment: true
    rationale: "The literal plan command exits non-zero because the production config guard refuses to boot without .env; whether that is acceptable or the command should change is a maintainer decision"

duration: 35min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 21: Phase 4 documentation and gate Summary

**CONTRIBUTING now names every Phase 4 Partner-access, tag, Action, guest-page, escape-hatch and ARES mechanism with its enforcing test, a documentation test keeps those names real, and the full phase gate is green apart from one fresh-clone command that needs a `.env`.**

## Performance

- **Duration:** about 35 min
- **Completed:** 2026-10-08T16:25:00Z
- **Tasks:** 2 (Task 1 tracer, Task 2 review and gate)
- **Files modified:** 3 (CONTRIBUTING.md, README.md, tests/Feature/Repo/RepositoryFilesTest.php)

## Accomplishments

- CONTRIBUTING Conventions: new bullets for Partner-visible data (`projects` column allowlist, `ProjectColumns`, `project_billing`, `PartnerSafeColumnsTest`, `Project::selectable()`), typed tags (`TagType`, client tags never reach a Partner, project tags do and carry the accepted D-07 risk, the `detachTags` guard), domain Actions (`RethrowsDomainValidation`, `client_id` not fillable, `ProjectKeySuggester`), guest pages (`Audience::Guest`, `ClientInvitation::findAcceptable`, `AcceptInvitation`), escape hatches (`ESCAPE_HATCH_ALLOWLIST`, `QueryEscapeHatchTest`), external lookups (`AresClient`, `Http::preventStrayRequests()`, `FictionalCompanyId`) and passwords (minimum 12, deactivated accounts). The Isolation bullet now names `Audience::Guest`.
- A "Hand-over notes for later phases" list: Phase 5 task numbers from `number_sequences` with key `task:<project uuid>` and `Project::selectable()` in every picker, Phase 8 formula-character prefixing for ARES text, Phase 12 rejection of API tokens of deactivated users, and the diacritics-insensitive search gap.
- Add-a-model checklist: new step 9 for a Partner-visible model (real `constrainForPartner`, canary fixture, visibility test, column allowlist test, money on an Admin-only side table).
- README: Operations note that invitations and resets are queued e-mails (mail settings and worker must run, Mailpit in DDEV); the Recovery bullet now describes the "forgot password" page that works for the Admin (2FA still applies, minimum 12 characters).
- `RepositoryFilesTest`: three tests (named Phase 4 classes exist, every `tests/...` file named in CONTRIBUTING exists, hand-over notes and the README reset path are present). The first run correctly failed on `ProjectKeySuggester` not being named yet, which was then documented.

## Task Commits

1. **Task 1: Tracer - CONTRIBUTING Phase 4 mechanisms, documentation tests, README operator notes** - `30b6e33` (docs)
2. **Task 2: Per-phase .gitignore review and full phase gate** - no commit (no `.gitignore` change was needed; results below)

**Plan metadata:** the docs commit following this summary.

## Gate results

| Gate | Result |
|---|---|
| `ddev composer ci` (Pest 1547 passed, Pint, Larastan level 8 "No errors", licence allowlist 210 packages) | pass |
| `ddev exec vendor/bin/pest --group=s3` | pass, 9 tests |
| `bash scripts/tests/run.sh` | pass, PASS 10 FAIL 0 |
| `scripts/check-sensitive.sh --all` | clean |
| `composer validate --strict` | valid |
| `git ls-files tests/Isolation/PartnerSafeColumnsTest.php` | tracked |
| Fresh clone, plan's literal command (no `.env`) | FAIL, see Issues Encountered |
| Fresh clone with `cp .env.example .env` then `composer install` | pass, exit 0, `spatie-laravel-tags-plugin` installed from the lock, `storage/framework/views/.gitignore` and `bootstrap/cache/.gitignore` present |
| Fresh clone with `APP_ENV=local` and no `.env` | pass, exit 0 |

## .gitignore review

No change. Inspected `git status --ignored --porcelain` and `git ls-files --others --exclude-standard`. The only untracked, non-ignored path is `.planning/milestone.lock`, the GSD orchestrator's own transient lock (it carries a pid and session id; it was committed once in earlier history and is owned by the orchestrator, not by a Phase 4 tool), so no rule was added. Ignored entries are all covered by existing rules or by their own `.gitignore` (`.claude/*`, `.ddev/*` own gitignore, `.env`, `.gsd/`, `.idea/`, `.planning/codebase/`, `bootstrap/cache/*`, `public/css|fonts|js` (Filament assets), `storage/framework/{phpstan,testing,views}`). Phase 4 added no new tool output.

## Manual end-of-phase checks (from 04-VALIDATION.md)

1. ARES highlight (CL-04): in DDEV open a new client, country CZ, enter a checksum-valid fictional company ID, press the ARES button, confirm changed fields are highlighted; repeat with the network blocked and confirm an error shows next to the field and the form is still saveable.
2. Mailpit invitation (US-02): invite an `example.com` address, open the mail in Mailpit (port 8025), follow the link, set a password (at least 12 characters), sign in as the new Partner and confirm only visible projects appear.
3. Mailpit password reset (US-02, from plan 04-20): request a reset for the Admin and for a Partner on the forgot-password page and via the client's account tab, open the mail in Mailpit, set a new password; the Admin still gets the 2FA challenge afterwards.

## Decisions Made

- No `.gitignore` or `test-gitignore.sh` change (see review above).
- The production config guard was left as it is. It refuses to boot with `APP_ENV=production` and a log or array mailer (Phase 3, WR-03), which is the intended behaviour; the README install order (copy `.env.example` first) already avoids the problem.

## Deviations from Plan

None - plan executed exactly as written, apart from the fresh-clone finding recorded below. No application code was changed.

## Issues Encountered

- **Fresh-clone command in the plan fails as written.** `git clone` then `composer install` without any `.env` runs `artisan package:discover`, the application boots with the default `APP_ENV=production`, and `ProductionConfigGuard` refuses ("MAIL_MAILER must be a real mail transport"). This predates Phase 4 (the mail check came with Phase 3 fix `fda21c0`). It passes when `.env.example` is copied first (the order the README prescribes) or `APP_ENV=local` is set. I did not weaken the guard. A maintainer should either change the Phase 2 verify command to copy `.env.example`, or decide whether a no-`.env` install should be supported.
- **Possible Zerops build concern, not verified.** `zerops.yml` runs `composer install --no-dev` in the build with no `envVariables` for the build. If the Zerops build environment has no `APP_ENV` and no `MAIL_MAILER`, `package:discover` would hit the same guard. This was not tested here; worth checking at the next deploy.

## Known Stubs

None.

## Threat Flags

None. No new endpoints, auth paths or schema in this plan.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Phase 4 plans are complete. Remaining before closing the phase: the three manual checks above, then phase verification. Phase 5 should read the hand-over notes in CONTRIBUTING.

## Self-Check: PASSED

- CONTRIBUTING.md, README.md and tests/Feature/Repo/RepositoryFilesTest.php exist; commit `30b6e33` is an ancestor of HEAD.
- All acceptance criteria of Task 1 passed (greps, `RepositoryFilesTest` 22 passed); Task 2 gate results are in the table above.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
