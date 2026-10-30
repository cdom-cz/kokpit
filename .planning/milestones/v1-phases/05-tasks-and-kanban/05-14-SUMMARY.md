---
phase: 05-tasks-and-kanban
plan: 14
subsystem: notifications
status: complete
tags: [notifications, preferences, filament-profile, jsonb, bell, partner-isolation, tdd]

requires:
  - phase: 05-tasks-and-kanban
    provides: escalation and comments for both roles (05-13), Partner task surfaces (05-12), the Admin bell for operational alerts (Phase 3)
provides:
  - users.notification_preferences, a jsonb object column with an object CHECK (users_notification_preferences_check), default {}
  - App\Domain\Notifications with NotificationEvent (forRole), NotificationChannel (laravelChannel), the NotificationPreferences value object and the UpdateNotificationPreferences Action
  - App\Filament\Auth\EditProfile, the profile page for every account with the "Upozornění" section and the per-page save label "Uložit nastavení"
  - the Filament bell for any signed-in panel user, so Partners have one
affects: [05-15 task notifications (reads NotificationPreferences::allows after the internal-comment guard), 05-16 Partner notifications, 05-17 phase gate]

actuals:
  tokens: 7700
  tasks: 2
  commits: 4
plan_head_before: 97562e944b73572c4147f647c4e35f8633531727
plan_head_after: cdc431d1420e511df13f740f6edffb396028f4a2
commits: 4

tech-stack:
  added: []
  patterns:
    - "A Filament profile subclass kept outside app/Filament/Pages (page discovery would register it twice) and governed by #[AccessRule] plus the EnforcesPageAccessRule trait"
    - "handleRecordUpdate strips the non-column keys and hands them to a domain Action; the stock save never sees them"
    - "An empty jsonb object is written as an empty stdClass, because an empty PHP array encodes to the JSON array []"

key-files:
  created:
    - database/migrations/2026_10_10_000600_add_notification_preferences_to_users_table.php
    - app/Domain/Notifications/NotificationEvent.php
    - app/Domain/Notifications/NotificationChannel.php
    - app/Domain/Notifications/NotificationPreferences.php
    - app/Domain/Notifications/UpdateNotificationPreferences.php
    - app/Filament/Auth/EditProfile.php
    - tests/Feature/Notifications/NotificationPreferencesTest.php
  modified:
    - app/Domain/Identity/Models/User.php
    - app/Providers/Filament/AdminPanelProvider.php
    - lang/cs/kokpit.php
    - lang/cs/enums.php

key-decisions:
  - "Storage is one jsonb object per user ({event: {channel: bool}}); a missing key means on, so no backfill and no new model"
  - "Only the owner changes the switches: UpdateNotificationPreferences throws AuthorizationException when actor and target differ, the Admin included"
  - "fromInput keeps only known events and channels and only real booleans; anything else is dropped and reads as on, so a preference can only narrow delivery"
  - "The profile page lists the rows by role (Admin: task created, comment, escalation; Partner: comment, escalation, assignment change); the form state for the other role's events never exists"
  - "The raw column is removed from the profile form state on fill; the switches are its only door"
  - "The bell condition is app(PartnerContext::class)->user() !== null; Filament lists only the signed-in user's notifications, pinned by a two-user test"
  - "The profile save button reads Uložit nastavení (kokpit.notifications.profile.save), as UI-SPEC required for this plan, and the page keeps the simple layout"

patterns-established:
  - "RED commit may pass in part: behaviours the tracer already guaranteed (column CHECK, mass-assignment guard, bell scoping) are pinned as regression tests in the RED commit, and only the genuinely missing behaviour fails"

requirements-completed: [TA-07]

coverage:
  - id: D1
    description: "Every user, Admin and Partner, finds a Notifications section on the profile page with one row per event the role can receive and an e-mail and a bell switch per row, all on by default"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#shows a Partner the three Partner rows with both switches on"
        status: pass
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#shows the Admin the task created, comment and escalation rows"
        status: pass
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#serves the profile route with the notifications section to a signed-in Partner"
        status: pass
    human_judgment: true
    rationale: "The row layout, the stacking of the switches at 375px and the helper wording are visual; the UI-SPEC lists them as a backstop check at the phase gate"
  - id: D2
    description: "Saving the profile stores the switches in users.notification_preferences through the Action, only for the signed-in user; unknown events, channels and non-boolean values are ignored; the column is not mass-assignable and its shape is guarded by a CHECK"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#stores a Partner switching e-mail off for comments and reads it back as a narrowed channel"
        status: pass
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#ignores unknown events, unknown channels and values that are not booleans"
        status: pass
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#refuses to change the preferences of another user and changes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#refuses a JSON array or a string in the column with a check violation"
        status: pass
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#does not take the column through mass assignment"
        status: pass
    human_judgment: false
  - id: D3
    description: "The bell is available to Partners as well as the Admin and every user's bell lists, clears and reads only the own notifications"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#turns the bell on for a Partner as well as the Admin"
        status: pass
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#lists in the bell of each user only the own notifications"
        status: pass
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#does not let a Partner clear or read the notification of another user"
        status: pass
    human_judgment: false
  - id: D4
    description: "The profile page does not ship the generic primary label Uložit; it reads Uložit nastavení (UI-SPEC accepted item for this plan)"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Feature/Notifications/NotificationPreferencesTest.php#labels the profile save button for the notification settings, not with the generic Uložit"
        status: pass
    human_judgment: false
  - id: D5
    description: "Preferences can only narrow delivery: no preference can make an internal comment reach a Partner (enforced and tested in plan 05-15)"
    requirement: TA-07
    verification: []
    human_judgment: true
    rationale: "Delivery does not exist yet; this plan stores and reads the switches only. The internal-comment guard runs before any preference read in plan 05-15, where its test lives"
---

# Phase 5 Plan 14: Notification preferences and the Partner bell Summary

**Per-user, per-event, per-channel notification switches on a role-aware profile page, stored in a guarded jsonb column through a single-writer Action, plus the Filament bell for Partners.**

## Performance

- **Duration:** about 10 min of agent time (2026-10-08T23:59Z to 2026-10-09T00:10Z by the wall clock of the run)
- **Tasks:** 2 (a tracer and a TDD task)
- **Files:** 11 (7 created, 4 modified)

## Accomplishments

- A `jsonb` column `users.notification_preferences` (default `{}`, object CHECK) holds `{event: {channel: bool}}`. A missing key means on, so every existing user is already at the all-on default (D-15).
- `App\Domain\Notifications` holds the two enums (with Czech labels in `lang/cs/enums.php`), the `final readonly` `NotificationPreferences` value object (`for`, `fromInput`, `allows`, `toArray`) and the `UpdateNotificationPreferences` Action, which is the only writer of the column.
- `App\Filament\Auth\EditProfile` adds the "Upozornění" section after the stock fields and keeps the 2FA section: Admin rows are task created, comment and escalation; Partner rows are comment, escalation and assignment change (D-07, a Partner assignee is told of an escalation). Each row has a helper line and the switches "E-mail" and "Zvonek". The save button reads "Uložit nastavení".
- The bell condition changed from "is Admin" to "any signed-in user" (research correction C3). Filament lists, clears and marks only the signed-in user's rows, which a two-user test pins.
- 19 tests in `NotificationPreferencesTest` (81 assertions). Full suite at the end: 1931 passed. Pint and PHPStan (level 8) clean; `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. **Task 1 (tracer): profile switches, column, bell** - `aabb5a8` (feat)
2. **Task 2 RED: failing tests for the semantics** - `8c5b40f` (test)
3. **Task 2 GREEN: booleans only, owner only** - `3e1126e` (feat)
4. **Import order in the test file (Pint)** - `cdc431d` (style)

**Tracer gate:** `<verify>` of Task 1 (the four targeted test files, Pint, PHPStan) and then the full suite passed before expansion; logged "Tracer verified end-to-end - expanding".

## TDD Gate Compliance

RED `8c5b40f` precedes GREEN `3e1126e`. RED evidence (Pest, target file `tests/Feature/Notifications/NotificationPreferencesTest.php`, 19 tests, 3 failed, 16 passed, exit 1), semantic assessment: each failure is on the planned assertion and for the intended reason.

- `ignores unknown events, unknown channels and values that are not booleans`: `toArray()` returned `database => true` for the string `'no'` and `escalation => [mail => false, database => false]` for `0` and `null` (the tracer cast with `(bool)`); expected only `comment.mail => false`.
- `refuses to change the preferences of another user and changes nothing`: `AuthorizationException` not thrown (the tracer Action wrote any target).
- `refuses the Admin changing the preferences of a Partner`: same cause.

The other 16 tests passed in the RED run on purpose: the behaviours they pin (column CHECK with SQLSTATE 23514, mass-assignment guard, private bell, name and switch independence, empty column means all on) were delivered by the tracer and the migration, and were written as regression pins. No `gsd_run check tdd-red-evidence` record was produced (the classifier supports TAP, JUnit, swift-testing and unittest reports, not Pest console output); the semantic assessment above is the evidence. No REFACTOR commit was needed.

## Files Created/Modified

- `database/migrations/2026_10_10_000600_add_notification_preferences_to_users_table.php` - the column and `users_notification_preferences_check`
- `app/Domain/Notifications/{NotificationEvent,NotificationChannel,NotificationPreferences,UpdateNotificationPreferences}.php` - events, channels, value object, Action
- `app/Filament/Auth/EditProfile.php` - profile subclass with the section, `mutateFormDataBeforeFill`, `handleRecordUpdate`, save label
- `app/Domain/Identity/Models/User.php` - array cast and `@property`, not fillable
- `app/Providers/Filament/AdminPanelProvider.php` - `->profile(EditProfile::class)` and the bell for any signed-in user
- `lang/cs/kokpit.php`, `lang/cs/enums.php` - `notifications.profile.*` and the `notification_event` and `notification_channel` labels
- `tests/Feature/Notifications/NotificationPreferencesTest.php` - 19 tests

## Decisions Made

See `key-decisions` above. Two worth repeating: the owner-only rule has no Admin exception (switches are personal, and the Admin has no need to change a client's), and the profile page strips the switches in `handleRecordUpdate` rather than `mutateFormDataBeforeSave`, because the latter's output is what `handleRecordUpdate` receives.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] An empty preference set would have been stored as a JSON array**
- **Found during:** Task 1 (design, before the first run)
- **Issue:** Laravel's `array` cast encodes `[]` as the JSON array `[]`, which the new object CHECK refuses; saving a profile whose switches are all absent would have failed with SQLSTATE 23514.
- **Fix:** the Action writes an empty `stdClass` when there is nothing to store.
- **Files modified:** `app/Domain/Notifications/UpdateNotificationPreferences.php`
- **Verification:** `stores the switches of the owner through the Action` and the `{}` default read-back tests
- **Commit:** `aabb5a8`

**2. [Plan wording] `mutateFormDataBeforeSave` is not where the keys are stripped**
- The artifact table says `mutateFormDataBeforeSave` removes the `notifications` key and `handleRecordUpdate` saves it. The data reaching `handleRecordUpdate` is the output of the former, so stripping earlier would lose the values. `handleRecordUpdate` strips and saves in one place (the key link in the plan says the same). No behaviour differs from the plan's intent.

**Total deviations:** 1 auto-fixed (1 bug), 1 wording clarification. **Impact:** none on scope; no file outside `files_modified` was touched.

### Accepted UI-SPEC item (not a deviation)

The checker's open Copywriting item was honoured: the profile page ships "Uložit nastavení" (`kokpit.notifications.profile.save`) set on the page's own `getSaveFormAction()`, with no global `lang/cs` override. The labels of the Phase 2 baseline elsewhere are unchanged.

## UI-SPEC flags left untouched

F-8 (Phase 4 `InReview` enum colour) and F-9 (label changes on the built Admin task pages) are pending OWNER decisions and were not touched by this plan. F-1 (primary colour Amber) and the other flags are also unchanged.

## Issues Encountered

- A ddev Pest run right after a file write twice reported "file not found" or a stale copy (bind-mount timing); a short wait fixed it each time.
- Pint reordered the imports of the test file after the RED commit had been staged, which produced the separate style commit `cdc431d`.
- The unrelated uncommitted files `.planning/config.json`, `.planning/state.json` and `.planning/milestone.lock` were not touched or staged.

## Known Stubs

None. The switches are stored and readable; nothing reads them for delivery yet by design (plans 05-15 and 05-16), which is not a stub in this plan's goal.

## Threat Flags

None beyond the plan's threat model. T-05-33 (owner-only Action, column not fillable, page edits the signed-in user), T-05-34 (bell bound to the signed-in user, two-user and foreign-id tests) and T-05-35 (preferences only narrow; the internal guard and its test belong to 05-15) are mitigated as planned.

## Next Phase Readiness

Ready for 05-15. The delivery code reads `NotificationPreferences::for($recipient)->allows($event, $channel)` per channel, after the internal-comment rule, and maps `NotificationChannel::laravelChannel()` to the Laravel channel name. Open point for the owner: the `TaskCreated` and `AssignmentChange` events exist as switches now, while the senders arrive in 05-15 and 05-16.

## Self-Check: PASSED

Created files exist on disk (migration, four domain classes, `EditProfile`, test file). Commits `aabb5a8`, `8c5b40f`, `3e1126e`, `cdc431d` are ancestors of HEAD. Task acceptance greps pass; `NotificationPreferencesTest` 19 passed; the full suite 1931 passed; Pint and PHPStan clean.
