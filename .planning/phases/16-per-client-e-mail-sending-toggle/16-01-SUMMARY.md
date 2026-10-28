---
phase: 16-per-client-e-mail-sending-toggle
plan: 01
subsystem: clients, invoicing
tags: [clients, email, audit, filament, domain-gate]
requires: []
provides:
  - clients.document_emails_enabled column (boolean NOT NULL DEFAULT true)
  - App\Domain\Invoicing\Support\ClientDocumentEmails gate (enabledFor, enabledForInvoice, allows)
  - ClientFactory::withoutDocumentEmails() state
  - kokpit.clients.badges.document_emails_off and related Czech copy
  - REQUIREMENTS.md entries P16-01 to P16-11
affects: [16-02, 16-03, 16-04, 16-05]
tech-stack:
  added: []
  patterns:
    - allowlisted activity log entry as the whole audit of a switch
    - fresh-read domain gate that fails closed
key-files:
  created:
    - database/migrations/2026_10_28_000100_add_document_emails_enabled_to_clients_table.php
    - app/Domain/Invoicing/Support/ClientDocumentEmails.php
    - tests/Feature/Invoicing/ClientDocumentEmailsTest.php
  modified:
    - app/Domain/Clients/Models/Client.php
    - app/Domain/Clients/Actions/ClientInput.php
    - app/Domain/Clients/Actions/CreateClient.php
    - database/factories/ClientFactory.php
    - app/Filament/Resources/ClientResource.php
    - app/Filament/Resources/ClientResource/Pages/ViewClient.php
    - lang/cs/kokpit.php
    - tests/Feature/Clients/ClientResourceTest.php
    - tests/Feature/Clients/ClientDefaultsTest.php
    - tests/Feature/Schema/ClientTablesTest.php
    - .planning/REQUIREMENTS.md
key-decisions:
  - "ClientInput writes document_emails_enabled only when the key is present and not null, so an update never switches sending off by omission (unlike the online-payment idiom)"
  - "ClientDocumentEmails fails closed: an unreadable client row answers false (do not send)"
  - "ViewClient::getHeading() appends a warning badge only when the switch is off; otherwise the parent heading is returned unchanged"
requirements-completed: [P16-01, P16-02]
duration: 40min
completed: 2026-10-28
status: complete
actuals:
  tokens: 7600
  tasks: 3
  commits: 3
plan_head_before: c0942790fd9893c698fbded43d1bb66875d867fd
plan_head_after: fdb75615c82c4bf40939b7afebcb2a6fc70911c7
---

# Phase 16 Plan 01: Per-client document e-mail switch Summary

Every client now has a "send documents by e-mail" switch that is on by default, editable in the client form, shown on the detail with a heading badge when off, audited through the Client activity allowlist, and answered by one fresh-read, fail-closed domain gate for the later plans of the phase.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Tracer: migration, model, input, form toggle, gate, copy, tests | d08cca9 |
| 2 | Create default, factory state, detail icon entry and heading badge, tests | cee3b4f |
| 3 | P16-01 to P16-11 in REQUIREMENTS.md | fdb7561 |

## What was built

- Migration `2026_10_28_000100_...` adds `clients.document_emails_enabled` boolean NOT NULL DEFAULT true; the default alone enables every existing row, no backfill.
- `Client`: `@property`, fillable, `#[LoggedAttributes]` and boolean cast. The allowlist entry is the whole audit (one `updated` row with old and new value, causer and time).
- `ClientInput::attributes()` leaves the key out when it is missing or null; `CreateClient::withDefaults()` sets true when missing or null; `ClientResource::actionData()` reads a missing form value as true (Pitfall 1).
- `ClientResource`: `Toggle` after the invoice e-mail in the form, `IconEntry` in the terms section; no list column.
- `ViewClient::getHeading()` adds a warning badge "E-maily s doklady vypnuté" when the switch is off.
- `ClientDocumentEmails`: `enabledFor()` (fresh `withTrashed()` read, true only for `true`), `enabledForInvoice()` (fresh client_id read, unknown invoice false), `allows(?Client)`.
- `ClientFactory` carries `document_emails_enabled => true` and the `withoutDocumentEmails()` state.
- Tests: ClientResourceTest (form, audit row, create default, no list column, detail badge on and off), ClientDocumentEmailsTest (gate behaviour), ClientDefaultsTest (create and update defaults, omitted and null keys), ClientTablesTest (default true, NOT NULL 23502).

## Deviations from Plan

None - plan executed exactly as written. Task 1 also contains the `IconEntry` edit in `ClientResource.php` that the plan lists under Task 2, because both live in the same file and were committed together with the file.

## Verification status

Worktree checks only, as required by the project rules: `php -l` clean on every touched PHP file, `scripts/check-sensitive.sh` clean for each commit, task 3 grep verification of P16-01 to P16-11 passed. Not run in the worktree (no vendor or DB): migrations, Pest, Pint, PHPStan. These are post-merge steps for the orchestrator: `ddev exec php artisan migrate`, then the post-merge commands listed in each task and the plan gate (`pest tests/Feature/Clients tests/Feature/Schema tests/Feature/Invoicing tests/Feature/Operations tests/Isolation tests/Arch`, `pint --test`, `phpstan analyse`).

Points worth watching in the post-merge run (untested here): the `ViewClient::getHeading()` return type `string|Htmlable|null` matches `BasePage::getHeading()`; `assertTableColumnDoesNotExist` exists in Filament TestsColumns; the Activity `forSubject()/forEvent()` scopes are used as in `ActivityLogBehaviourTest`.

## Known Stubs

None.

## Threat Flags

None. The new column is on `clients` (a `DeniesPartners` table); no new endpoint or trust boundary.

## Self-Check: PASSED

- Files exist: migration, ClientDocumentEmails.php, ClientDocumentEmailsTest.php, this SUMMARY.
- Commits d08cca9, cee3b4f, fdb7561 are ancestors of HEAD.
