---
phase: 261111-s6e-contacts-birth-date-dashboard-birthdays-
plan: 261111-s6e
subsystem: clients, dashboard, api
tags: [contacts, birthday, dashboard, filament, sanctum, scribe]
requires: []
provides:
  - contacts.birth_day, birth_month, birth_year (nullable smallint) with contacts_birth* CHECKs and a partial index
  - App\Domain\Clients\Queries\ContactBirthdays, the shared Admin-only reader of the birthdays of a Prague day
  - Admin dashboard card "Narozeniny dnes" (BirthdaysToday) with a contact modal
  - GET /api/v1/contacts/birthdays behind the new contacts:read ability
affects: [dashboard widget preferences CHECK, API token screen, API docs, access matrix registries]
tech-stack:
  added: []
  patterns: [shared reader for card and API, explicit-key API resource, ability per data class]
key-files:
  created:
    - database/migrations/2026_11_11_000100_add_birthday_to_contacts_table.php
    - database/migrations/2026_11_11_000200_add_birthdays_today_dashboard_widget.php
    - app/Domain/Clients/Queries/ContactBirthdays.php
    - app/Filament/Widgets/BirthdaysToday.php
    - app/Http/Controllers/Api/V1/ContactBirthdayController.php
    - app/Http/Controllers/Api/V1/ContactExamples.php
    - app/Http/Requests/Api/V1/ListBirthdaysRequest.php
    - app/Http/Resources/Api/V1/ContactResource.php
    - tests/Feature/Clients/ContactBirthdayTest.php
    - tests/Feature/Dashboard/BirthdaysTodayWidgetTest.php
    - tests/Feature/Api/ContactBirthdayTest.php
  modified:
    - app/Domain/Clients/Models/Contact.php
    - app/Domain/Clients/Actions/ContactInput.php
    - app/Filament/Resources/ClientResource/RelationManagers/ContactsRelationManager.php
    - app/Domain/Dashboard/Enums/DashboardWidget.php
    - app/Filament/Pages/Dashboard.php
    - app/Domain/Identity/ApiAbility.php
    - routes/api.php
    - config/scribe.php
    - lang/cs/kokpit.php
    - tests/Feature/Dashboard/AdminDashboardTest.php
    - tests/Feature/Dashboard/UnpaidInvoicesWidgetTest.php
    - tests/Feature/Dashboard/UnresolvedPaymentsWidgetTest.php
    - tests/Feature/Dashboard/CollapsibleWidgetsTest.php
    - tests/Isolation/PanelAccessTest.php
    - tests/Isolation/ApiAccessTest.php
    - tests/Feature/Api/ApiTokenScreenTest.php
    - tests/Support/PartnerMatrix.php
key-decisions:
  - "Birth date is three nullable smallint columns, not a date column, so no dummy leap year or 'year known' flag is needed"
  - "Today is the Europe/Prague day from TimerClock::now(); 29 February is listed on 28 February in a non-leap year"
  - "Contacts of archived clients are left out (whereHas('client') applies the soft delete scope)"
  - "The API uses a new contacts:read ability, not time:read, so time and finance tokens never reach personal data"
  - "The endpoint takes an optional date (YYYY-MM-DD) and answers the whole day without pagination"
  - "The dashboard row modal is a ViewAction with read-only entries instead of a plain Action (see Deviations)"
metrics:
  duration: "about 1 hour"
  completed: "2026-11-11"
status: complete
commits: 3
plan_head_before: f86082009b90f83a537097c46f7c42ea6aaca250
plan_head_after: 5975e23bb281c358117f3d03caca44dd04429f7e
actuals:
  tokens: 24000
  tasks: 3
  commits: 3
---

# Quick 261111-s6e: Contact birth date, dashboard birthdays, birthday API

Contacts carry a birth date with an optional year. The Admin dashboard lists who has a birthday today (Europe/Prague) with the age they turn, a row click opens the whole contact, and the same list is available over the Sanctum API under a new `contacts:read` ability.

## What was built

| Task | Commit | Result |
| ---- | ------ | ------ |
| 1 (tracer) | `0b88219` feat(contacts): record birth date with optional year | Migration with six `contacts_birth*` CHECKs and partial index `contacts_birthday_index`; Contact model (fillable, logged, casts, `hasBirthday`, `birthdayLabel`, `ageOn`); strict parsing and calendar validation in `ContactInput`; Fieldset with day, month and year inputs plus a "Narozeniny" column in the Kontakty tab; Czech strings; `ContactBirthdayTest` |
| 2 | `cf6b339` feat(dashboard): list today's contact birthdays with a contact modal | `ContactBirthdays` reader; `BirthdaysToday` card (sort 16, full width, collapsible, empty state, row-click modal with "Otevřít klienta"); `DashboardWidget::BirthdaysToday` plus a migration widening `dashboard_widget_preferences_widget_key_check`; Admin branch of `Dashboard::getWidgets()`; `BirthdaysTodayWidgetTest`; seven registries/tests updated for the new widget |
| 3 | `5975e23` feat(api): expose today's contact birthdays under contacts:read | `ApiAbility::ContactsRead`; route group with `throttle:api-read`; `ContactBirthdayController` (invokable, Scribe attributes), `ListBirthdaysRequest`, `ContactResource` (explicit 15 keys), `ContactExamples`; scribe config text; token-screen strings; `ContactBirthdayTest` (API); `ApiAccessTest` limits map and `PartnerMatrix` row |

Verified commit count: `git rev-list --count f860820..HEAD` after the plan base `f86082009b90f83a537097c46f7c42ea6aaca250` is 3, each commit holding only code, tests, migrations, lang and config (no `.planning` files).

## Design choices (as requested by the plan)

- Storage: `birth_day`, `birth_month`, `birth_year` nullable `smallint`. Day and month are set together (`contacts_birthday_pair_check`); a year needs both (`contacts_birth_year_needs_date_check`); month 1-12; day 1-30/29/31 by month; year 1900-2100 as a coarse backstop; `contacts_birth_leap_day_check` refuses 29 February of a known non-leap year and passes NULL columns and 29 February without a year. The "not in the future" rule lives in `ContactInput` only, because it depends on the current day.
- "Today" is the Europe/Prague day from `TimerClock::now()`, held in `ContactBirthdays::ZONE`.
- 29 February is listed on 28 February in a non-leap year (and on 29 February in a leap year).
- Archived (soft-deleted) clients' contacts are left out.
- The card is always on the Admin dashboard (no `canView` override), collapsible, full width, sort 16, directly after `TodayPlan`; on days without a birthday it shows an empty state and the summary "Dnes nikdo".
- The API uses a separate `contacts:read` ability; `?date=YYYY-MM-DD` selects another day, answers the whole day unpaginated, and is the endpoint's real 422 case. No `FILTERS` constant is defined.

## Deviations from Plan

### Auto-fixed / judgement calls

**1. [Rule 3 - Blocking uncertainty] Row modal is a `ViewAction`, not a plain `Action`**
- **Found during:** Task 2
- **Issue:** The plan prefers a plain `Filament\Actions\Action` with a read-only `schema()` and permits `ViewAction::make('showContact')->schema([...])` if the installed Filament refuses it. `vendor/` is absent in this checkout, so the plain-Action behaviour could not be checked.
- **Fix:** Used `ViewAction` from the start; every `TextEntry`/`IconEntry` carries an explicit `state()` closure, so it does not depend on record binding. The Contact policy (Admin only) authorizes `view`.
- **Files modified:** `app/Filament/Widgets/BirthdaysToday.php`
- **Commit:** `cf6b339`

**2. [Rule 2 - Missing critical] `reorder()` before `count()` in the collapsed summary**
- **Issue:** The reader orders by name and id; `count()` on an ordered query can fail on PostgreSQL.
- **Fix:** `BirthdaysToday::collapsedSummary()` calls `->reorder()->count()`.
- **Commit:** `cf6b339`

No other deviations: the plan was executed as written.

## Threat model

All `mitigate` dispositions are implemented: AdminOnly access rule at boot plus the reader's explicit Admin/system check (T-01); a separate `contacts:read` ability (T-02); explicit-key resource and archived exclusion (T-03); strict Action parsing plus six CHECKs (T-04); `date_format:Y-m-d` with a range (T-05); the three columns in `#[LoggedAttributes]` with Czech labels (T-06). No new threat surface outside the register.

## Known Stubs

None.

## Verification actually run

This checkout has no `vendor/` directory and no DDEV, so only the cheap checks ran (the plan and `.claude/CLAUDE.md` allow exactly this):

- `php -l` on every created and modified PHP file: clean (local PHP is 8.3.6, the project targets 8.5).
- All grep gates of the three tasks: pass.
- `scripts/check-sensitive.sh` and the lefthook pre-commit hook on every commit: clean.

**NOT run (must be run after the merge on the HEAD branch in DDEV):**

- `ddev exec php artisan migrate` (two new migrations, never executed, so the SQL of the six CHECKs, the partial index and the widened widget-key CHECK is unproven).
- Pint (`vendor/bin/pint --test`) and PHPStan.
- Pest: `ContactBirthdayTest` (clients), `BirthdaysTodayWidgetTest`, `ContactBirthdayTest` (API), and the full CI-equivalent suite without `--parallel`. In particular `ContactsTest`, `ActivityAllowlistTest`, the dashboard tests, `DashboardWidgetPreferencesTableTest`, `PanelAccessTest`, `AccessMatrixTest`, `ApiAccessTest`, `ApiTokenScreenTest` and the API documentation tests.

The tracer gate "re-run `<verify>` end to end before expanding" could only be satisfied with the automated lint/grep part, not with the database-backed tests.

## Points most likely to need a fix on first real run

- `Heroicon::OutlinedCake` was used for the empty state; the plan says to fall back to `OutlinedGift` if the installed enum lacks it. The enum could not be inspected.
- The `ViewAction` modal with infolist entries inside a `TableWidget`, and `mountAction(TestAction::make('showContact')->table($contact))` in the widget test, are unverified against Filament 5.11.
- `Fieldset` without a state path inside the contact modal: `underModal()` maps errors to `mountedActions.0.data.<key>`, which assumes the three birthday fields are top-level form keys.
- Test expectations that depend on rendered text (for example `'1 kontakt'`, `'2 kontakty'`, `'41. narozeniny'`) rely on the Czech `trans_choice` forms in `lang/cs/kokpit.php`.
- Rules of the form validation (`minValue(1900)` on the year input) produce a form error before the Action runs for 1899; the test asserts only that `birth_year` has an error.

## Human checks left (from the plan)

1. Kontakty tab: add a contact 14 / březen / no year (shows "14. 3."), then add 1985 (shows "14. 3. 1985").
2. Give a fictional contact today's day and month: the card "Narozeniny dnes" shows under "Plán na dnešek", "N. narozeniny" with a year, a row click opens the modal and "Otevřít klienta" works; a Partner dashboard shows no card.
3. API tokens screen: a token with only "Čtení kontaktů" reads `/api/v1/contacts/birthdays` and gets 403 on `/api/v1/time-entries`; the generated docs list the Contacts group.

## Self-Check

- Created files exist: all 11 created files verified present via `git status`/`git show` before commit (migrations x2, reader, widget, controller, examples, request, resource, three test files).
- Commits exist on the branch: `0b88219`, `cf6b339`, `5975e23` (`git log` shows all three above `f860820`).
- Working tree clean for code (only planning artifacts remain uncommitted, by instruction).

## Self-Check: PASSED
