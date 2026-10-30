---
phase: 04-clients-and-projects
plan: 14
subsystem: validation
tags: [company-number, mod-11, validation-rule, filament, test-support]

requires:
  - phase: 04-clients-and-projects
    provides: ClientInput as the single validator of CreateClient and UpdateClient, ClientResource form with the live country input (04-09 to 04-11)
provides:
  - CompanyId::isValid, the Czech company number mod-11 check (pure, static)
  - CompanyIdRule, a ValidationRule shared by the client form and the client Actions
  - Czech-only company number check in the client form and, through ClientInput, in CreateClient and UpdateClient
  - FictionalCompanyId test support (valid, validWithRemainder, invalid), generated at runtime
  - kokpit.ares.errors.invalid_id Czech message, reused by the ARES failure messages in plan 04-15
affects: [04-15 ARES lookup (builds on CompanyId), 04-21 phase verification]

actuals:
  tokens: 7900
  tasks: 2
  commits: 3
plan_head_before: 18b9af057583b1d702788f4b6ba30be876681695
plan_head_after: f140df8d74a010de7c2a7c82e1914c6f39234287

tech-stack:
  added: []
  patterns:
    - "A rule shared by a Filament field and a domain Action is a ValidationRule class; the Action runs it through Validator::make so the form and the data layer give one message"
    - "Checksum-valid fictional identifiers are generated at test time by a Tests\\Support generator that computes the check digit on its own, never through the class under test"

key-files:
  created:
    - app/Domain/Clients/Ares/CompanyId.php
    - app/Domain/Clients/Rules/CompanyIdRule.php
    - tests/Support/FictionalCompanyId.php
    - tests/Unit/Ares/CompanyIdTest.php
  modified:
    - app/Filament/Resources/ClientResource.php
    - app/Domain/Clients/Actions/ClientInput.php
    - app/Domain/Clients/Actions/CreateClient.php
    - app/Domain/Clients/Actions/UpdateClient.php
    - lang/cs/kokpit.php
    - tests/Feature/Clients/ClientResourceTest.php
    - tests/Feature/Clients/ClientRulesTest.php

key-decisions:
  - "The data-layer check lives in ClientInput::attributes (the shared validator of both Actions) and runs CompanyIdRule through Validator::make, so the error is reported together with the other field errors; CreateClient and UpdateClient reference the rule in their docs"
  - "CompanyIdRule lets null and the empty string pass (the field is optional) and trims before the check; CompanyId::isValid itself refuses spaces"
  - "The form applies the rule through rule(new CompanyIdRule, condition) with the country read case-insensitively, the same way the Action upper-cases it"

patterns-established:
  - "Only the checksum-invalid placeholder 12345678 may appear as a literal company number in tests; a checksum-valid literal is flagged by scripts/check-sensitive.sh"

requirements-completed: [CL-04]

coverage:
  - id: D1
    description: "CompanyId::isValid implements the mod-11 rule exactly (8 digits, weights 8 to 2, remainder 0 gives 1, remainder 1 gives 0, else 11 minus remainder) and refuses the placeholder, 7 or 9 digits, letters, spaces and the empty string"
    requirement: CL-04
    verification:
      - kind: unit
        ref: "tests/Unit/Ares/CompanyIdTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "The client form refuses a Czech company number with a wrong check digit as a field error on company_number and accepts any company number of a foreign client"
    requirement: CL-04
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#refuses a Czech company number with a wrong check digit as a field error and creates no client"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/ClientResourceTest.php#saves a client of another country with a company number that has no checksum"
        status: pass
    human_judgment: false
  - id: D3
    description: "CreateClient and UpdateClient refuse an invalid Czech company number as a field error on company_number, so a crafted payload or a direct Action call cannot bypass the form, and accept any text for other countries"
    requirement: CL-04
    verification:
      - kind: integration
        ref: "tests/Feature/Clients/ClientRulesTest.php#Czech company number checksum"
        status: pass
    human_judgment: false
  - id: D4
    description: "No checksum-valid company number is committed: tests generate them at runtime through FictionalCompanyId"
    verification:
      - kind: other
        ref: "scripts/check-sensitive.sh tests/Support/FictionalCompanyId.php tests/Feature/Clients/ClientResourceTest.php tests/Unit/Ares/CompanyIdTest.php tests/Feature/Clients/ClientRulesTest.php"
        status: pass
    human_judgment: false

duration: 8min
completed: 2026-10-08
status: complete
---

# Phase 4 Plan 14: Czech company number checksum Summary

**Mod-11 Czech company number check (CompanyId and CompanyIdRule) enforced for Czech clients only, in the client form and in CreateClient and UpdateClient, with a runtime generator of fictional checksum-valid numbers for tests**

## Performance

- **Duration:** 8 min
- **Started:** 2026-10-08T14:47:00Z
- **Completed:** 2026-10-08T14:56:00Z
- **Tasks:** 2
- **Files modified:** 11

## Accomplishments
- `CompanyId::isValid` follows the mod-11 rule and refuses the placeholder `12345678` (check digit 9 expected), wrong lengths, letters, spaces and the empty string.
- The client form checks `company_number` with `CompanyIdRule` only while the country is `CZ`; a foreign client keeps a free company number (D-09). The check also runs on the edit page and stops when the country changes.
- `ClientInput::attributes` runs the same rule for Czech clients, so `CreateClient` and `UpdateClient` refuse a crafted payload with an error on `company_number`, reported together with any other field error.
- `FictionalCompanyId` produces checksum-valid numbers from random digits (and valid numbers with remainder 0 or 1 to cover the two special check-digit cases); the existing client tests no longer carry a literal valid number.
- The Czech message `kokpit.ares.errors.invalid_id` exists for reuse by the ARES failure messages in plan 04-15.

## Task Commits

1. **Task 1: Tracer, field error in the form** - `7d5c803` (feat)
2. **Task 2: Checksum edge cases and the data-layer rule** - `150a20a` (test, RED), `f140df8` (feat, GREEN)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

## Files Created/Modified
- `app/Domain/Clients/Ares/CompanyId.php` - pure mod-11 check
- `app/Domain/Clients/Rules/CompanyIdRule.php` - ValidationRule shared by form and Actions
- `app/Domain/Clients/Actions/ClientInput.php` - Czech-only company number check, reported with the other errors
- `app/Domain/Clients/Actions/CreateClient.php`, `UpdateClient.php` - documentation of the rule (the logic sits in ClientInput)
- `app/Filament/Resources/ClientResource.php` - rule on `company_number` while the country is CZ
- `lang/cs/kokpit.php` - `ares.errors.invalid_id`
- `tests/Support/FictionalCompanyId.php` - runtime generator
- `tests/Unit/Ares/CompanyIdTest.php` - edge cases of the check
- `tests/Feature/Clients/ClientResourceTest.php`, `ClientRulesTest.php` - runtime valid numbers, new CZ/foreign cases for form and Actions

## Decisions Made
- The data-layer check sits in `ClientInput` (single validator of both Actions) rather than being duplicated in each Action; the Actions reference `CompanyIdRule` in their documentation. This keeps one place for the rule and reports the company number error together with the other field errors.
- `CompanyIdRule` ignores null and the empty string (optional field) and trims before checking.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Removed a checksum-valid literal from the unit test**
- **Found during:** Task 2 (first RED commit attempt)
- **Issue:** The unit test asserted that the placeholder with its check digit corrected to 9 is valid; `scripts/check-sensitive.sh` flagged it as a company-id literal and the commit was refused.
- **Fix:** Dropped the literal; validity of generated numbers is covered through `FictionalCompanyId`, the placeholder stays as the only literal (checksum-invalid).
- **Files modified:** tests/Unit/Ares/CompanyIdTest.php
- **Verification:** `scripts/check-sensitive.sh` clean, hook passed
- **Committed in:** 150a20a

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** None on scope. The plan's acceptance grep for `CompanyIdRule` in `CreateClient.php` and `UpdateClient.php` is met through documentation references, while the executable check is in the shared `ClientInput`.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Known Stubs
None.

## Threat Flags
None - no new network endpoint, auth path or schema change. T-04-56 (crafted payload) is mitigated by the rule in the form and in `ClientInput`, tested per path; T-04-57 (real number in the public repository) is mitigated by runtime generation and the scanner run on the changed test files.

## Next Phase Readiness
- `CompanyId` is ready for the ARES adapter and button in plan 04-15, and `kokpit.ares.errors.invalid_id` exists for its failure messages.
- Full suite (1427 tests), Pint and PHPStan are green.

## Self-Check: PASSED

All created files exist; commits `7d5c803`, `150a20a`, `f140df8` are in the history.

---
*Phase: 04-clients-and-projects*
*Completed: 2026-10-08*
