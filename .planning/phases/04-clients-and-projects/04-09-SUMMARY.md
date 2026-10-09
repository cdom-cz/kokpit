---
phase: 04-clients-and-projects
plan: 09
subsystem: ui
tags: [filament, livewire, money, partner-isolation, enums]

requires:
  - phase: 04-clients-and-projects
    provides: Client model, clients table with CHECK constraints (04-01); RethrowsDomainValidation and the route-walk resource map (04-08)
  - phase: 01-foundation
    provides: Money::fromMajor no-rounding contract and Money::isoCurrencyCodes
provides:
  - ClientResource (Admin only) with list, create and edit under Klienti
  - CreateClient and UpdateClient domain Actions over a shared ClientInput rule set
  - ClientStage and InvoiceLanguage enums with Czech labels, cast on Client
  - Route walk entry for the Admin-only clients resource
affects: [04-10 typed defaults prefill, 04-11 client archive and uniqueness, 04-14 CZ company number checksum, 04-15 ARES lookup, phase 7 invoicing]

actuals:
  tokens: 10300
  tasks: 2
  commits: 2
plan_head_before: 0ebd1da778fa572a3daab3ede48382a31f1b1c30
plan_head_after: 4ef202cc8655f7ba514cec921285e11ba8d64ba2

tech-stack:
  added: []
  patterns:
    - "ClientInput::attributes() is the single validator and normaliser for both client Actions; it reports every field problem at once, keyed by data key"
    - "The form rate rule calls ClientInput::rate(), the same function the Actions use, so the form and the data layer cannot disagree"
    - "An Admin-only resource whose model has a deny-all Partner scope refuses in the edit page mount() with abort_unless(canAccess(), 403), because the scope would otherwise answer 404 first"

key-files:
  created:
    - app/Filament/Resources/ClientResource.php
    - app/Filament/Resources/ClientResource/Pages/ListClients.php
    - app/Filament/Resources/ClientResource/Pages/CreateClient.php
    - app/Filament/Resources/ClientResource/Pages/EditClient.php
    - app/Domain/Clients/Enums/ClientStage.php
    - app/Domain/Clients/Enums/InvoiceLanguage.php
    - app/Domain/Clients/Actions/CreateClient.php
    - app/Domain/Clients/Actions/UpdateClient.php
    - app/Domain/Clients/Actions/ClientInput.php
    - tests/Feature/Clients/ClientResourceTest.php
  modified:
    - app/Domain/Clients/Models/Client.php
    - lang/cs/enums.php
    - lang/cs/kokpit.php
    - tests/Isolation/RouteWalkTest.php

key-decisions:
  - "Country is a two-letter text input that must be typed in capitals (regex on the raw state, hint in Czech); the Actions upper-case country and currency for direct callers. No symfony/intl package."
  - "Payment terms accept a whole float from Filament's numeric input (14.0) in the Action, because the numeric text input hands over a float-typed state"
  - "No form defaults for country, currency, terms or language in this plan; only stage (active) and the online-payment toggle (off) are prefilled, the typed defaults arrive in plan 04-10 (D-13)"
  - "The edit page refuses a Partner with 403 in mount() before the record lookup"

patterns-established:
  - "Shared Action input class (ClientInput, like ProjectInput) with defensive array<string, mixed> input for crafted payloads"
  - "Money text fields on a form: closure rule over the shared parser, Czech message under kokpit.<area>.errors"

requirements-completed: [CL-01]

coverage:
  - id: D1
    description: "Admin creates a client with every billing and terms field; the rate 1500,50 CZK is stored as 150050 minor units with currency CZK; the list shows and searches it"
    requirement: CL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it creates a client with every field and stores the rate as an exact Money pair"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it shows the new client in the list"
        status: pass
    human_judgment: false
  - id: D2
    description: "Edit loads the rate as decimal-comma text, changes stage and invoice language, and keeps the Money pair in step with a changed currency"
    requirement: CL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it loads the rate as decimal-comma text and edits the stage and the invoice language"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it keeps the rate in step with a changed currency on edit"
        status: pass
    human_judgment: false
  - id: D3
    description: "Precision edge: more decimals than the currency allows, grouping characters, a negative sign, empty and text are field errors on hourly_rate on create and edit; nothing is stored or changed; JPY has no fraction"
    requirement: CL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it refuses a malformed rate as a field error on hourly_rate and creates no client"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it applies the same rate rule when a client is edited"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it stores the typed amount exactly in the minor units of the currency"
        status: pass
    human_judgment: false
  - id: D4
    description: "Boundary edge at form level: payment terms 0 and 365 save, -1 and 366 are field errors"
    requirement: CL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it accepts payment terms of 0 and 365 days and refuses -1 and 366"
        status: pass
    human_judgment: false
  - id: D5
    description: "Invalid e-mail, lower-case or three-letter country and empty name are field errors; a foreign client keeps a free-text company number; crafted Action payloads are refused like the form"
    requirement: CL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it refuses an invalid e-mail, a lower-case or three-letter country and an empty name as field errors"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it saves a foreign client with a free-text company number"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it refuses a crafted payload in the Actions the same way as the form"
        status: pass
    human_judgment: false
  - id: D6
    description: "Stage select offers Zajemce, Aktivni, Pozastaveny, Ukonceny (Czech labels with diacritics in the enum file); the list filter by stage narrows the table"
    requirement: CL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it offers the four Czech stage labels and filters the list by stage"
        status: pass
    human_judgment: false
  - id: D7
    description: "A Partner gets 403 on every client route (index, create, edit) and in the route walk; the resource is not globally searchable"
    requirement: CL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it gives a Partner 403 on every client route"
        status: pass
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#it is not globally searchable"
        status: pass
    human_judgment: false
  - id: D8
    description: "Look, layout and Czech wording of the client form and list (section titles, hints, labels)"
    verification: []
    human_judgment: true
    rationale: "Page tests assert behaviour and state, not visual layout or the adequacy of the Czech copy"

duration: 10min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 09: Admin Client Screens Summary

**Admin ClientResource (list, create, edit under Klienti) written through CreateClient and UpdateClient Actions that share one validator, with the hourly rate parsed by Money::fromMajor (no rounding), stage and invoice-language enums in Czech, and a Partner refused with 403 on every client route**

## Performance

- **Duration:** 10 min
- **Started:** 2026-10-08T13:46:00Z
- **Completed:** 2026-10-08T13:56:00Z
- **Tasks:** 2
- **Files modified:** 14 (10 created, 4 modified)

## Accomplishments
- Admin creates and edits clients with country, company number, name, tax number, street, city, postal code, stage, currency, hourly rate, payment terms, invoice e-mail, invoice language and the online-payment flag; every write goes through the domain Actions.
- The rate is exact: `1500,50` CZK is stored as 150050 minor units, more decimals than the currency allows (also the fraction on JPY), grouping characters, a negative sign and empty text are Czech field errors on `hourly_rate`, on create and on edit, and nothing is stored.
- Payment terms 0 and 365 save; -1 and 366 are field errors. The Actions re-check stage, language, terms, e-mail, country and currency, so a crafted payload cannot bypass the form.
- Stage works as a label and a list filter only; the select and filter offer Zájemce, Aktivní, Pozastavený, Ukončený.
- A Partner gets 403 on index, create and edit; the resource is not globally searchable; the route walk lists `clients` as Admin-only.

## Task Commits

1. **Task 1: Tracer - client resource, pages, Actions, enums, route walk, tests** - `155cafd` (feat)
2. **Task 2: Exact money, terms boundary, field validation and stage filter tests** - `4ef202c` (test)

**Plan metadata:** the docs commit following this summary (docs: complete plan)

## Files Created/Modified
- `app/Filament/Resources/ClientResource.php` - Admin resource: form sections Fakturační údaje and Obchodní podmínky, table, stage filter, state conversion helpers
- `app/Filament/Resources/ClientResource/Pages/{ListClients,CreateClient,EditClient}.php` - pages; Create and Edit call the Actions through `withFormErrors()`; Edit refuses a Partner in `mount()` and fills the rate text
- `app/Domain/Clients/Actions/{CreateClient,UpdateClient,ClientInput}.php` - Actions and the shared validator/normaliser
- `app/Domain/Clients/Enums/{ClientStage,InvoiceLanguage}.php` - string enums with Czech labels (stage also has badge colours)
- `app/Domain/Clients/Models/Client.php` - enum casts for `stage` and `invoice_language`
- `lang/cs/enums.php`, `lang/cs/kokpit.php` - `client_stage`, `invoice_language` labels and the `clients` section
- `tests/Isolation/RouteWalkTest.php` - `clients` entry (client id of each side)
- `tests/Feature/Clients/ClientResourceTest.php` - 33 test cases

## Decisions Made
- Country must be typed in capitals (regex on the raw state, so `cz` and `CZE` are field errors as Task 2 requires); the Actions upper-case country and currency for callers that bypass the form. `symfony/intl` stays uninstalled.
- Only stage (active) and the online-payment toggle (off) are prefilled; country, currency, terms and language wait for the typed defaults of plan 04-10, to avoid inventing hard-coded defaults that would then be replaced.
- The Partner refusal on the edit route is a 403 raised in `EditClient::mount()` before the record lookup, because the deny-all scope of `Client` would answer 404 first and `withoutGlobalScopes()` is a banned escape hatch.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Partner got 404 instead of 403 on the client edit route**
- **Found during:** Task 1
- **Issue:** `Client` has a deny-all Partner scope, so the record lookup in `EditRecord::mount()` throws 404 before the resource authorization runs. The plan and the route walk require 403.
- **Fix:** `EditClient::mount()` calls `abort_unless(ClientResource::canAccess(), 403)` before `parent::mount()`. No scope is removed.
- **Files modified:** app/Filament/Resources/ClientResource/Pages/EditClient.php
- **Verification:** `ClientResourceTest` Partner 403 test and the route walk pass
- **Committed in:** 155cafd

**2. [Rule 1 - Bug] Numeric input hands over a float, rejected by the terms check**
- **Found during:** Task 1
- **Issue:** `TextInput::numeric()` dehydrates `14` as a float-typed value, which the Action's integer check refused
- **Fix:** `ClientInput` accepts a whole float below 1000 and converts it to int
- **Files modified:** app/Domain/Clients/Actions/ClientInput.php, CreateClient.php (type doc)
- **Verification:** create and edit tests pass
- **Committed in:** 155cafd

**3. [Rule 2 - Missing critical] Shared `ClientInput` validator not named in the plan**
- **Found during:** Task 1
- **Issue:** the plan named only the rate conversion in the Actions, but a crafted payload could otherwise store an empty name, an unknown stage or terms outside 0 to 365 (the database CHECK would raise a 500 instead of a field error)
- **Fix:** one `ClientInput` class validates and normalises everything for both Actions, with Czech messages under `kokpit.clients.errors`
- **Files modified:** app/Domain/Clients/Actions/ClientInput.php (new)
- **Verification:** `it refuses a crafted payload in the Actions the same way as the form`
- **Committed in:** 155cafd

**4. [Process] Task 2 had no RED phase**
- **Found during:** Task 2
- **Issue:** the form rules, filter and messages that Task 2 lists were needed to make the Task 1 form work and were committed with it, so the Task 2 behavior tests passed on first run
- **Fix:** Task 2 is a test-only commit that locks the behaviour; no production change was needed
- **Committed in:** 4ef202c

---

**Total deviations:** 4 (2 bugs, 1 missing critical, 1 process note)
**Impact on plan:** No scope creep; the extra `ClientInput` class is the data-layer half of the plan's own "field errors instead of rounding" rule.

## Issues Encountered
- None beyond the deviations above. The DDEV file sync needed a short wait after edits, as the orchestrator warned.

## User Setup Required
None - no external service configuration required.

## Known Stubs
None.

## Threat Flags
None - no new endpoint or trust boundary beyond the plan's threat model (T-04-19 mitigated: AdminOnly access rule, deny-all scope and AdminOnlyPolicy, global search off, 403 for a Partner including the edit route; T-04-20 mitigated: `Money::fromMajor` in `ClientInput`, CHECK `hourly_rate_currency = currency`, field errors instead of rounding; T-04-SC accepted: no package added).

## Next Phase Readiness
- Plan 04-10 can add the typed defaults fallback inside `ClientInput`/`CreateClient` and prefill country, currency, terms and language in the form (they are intentionally empty now).
- Plan 04-11 attaches archive and the (country, company number) uniqueness error; a duplicate company number currently surfaces as a database unique violation.
- Plan 04-14 adds the CZ checksum on `company_number`; the country input is already `live(onBlur: true)`.

## Self-Check: PASSED

- All created files exist; commits `155cafd` and `4ef202c` are ancestors of HEAD.
- Full suite 1305 passed, Pint and PHPStan clean, `scripts/check-sensitive.sh` clean.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
