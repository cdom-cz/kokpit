---
phase: 03-operations-foundation
plan: 08
subsystem: settings
tags: [numbering, number-patterns, sequence-allocator, spatie-laravel-settings, validation, czech-ui]
type: execute

requires:
  - phase: 02-platform-foundation
    provides: SequenceAllocator (gap-free counters, KEY_PATTERN, scopeKeyForYear in Europe/Prague)
  - phase: 03-operations-foundation
    provides: ValidatedSettings, SettingsMigration and the explicit settings class list (plans 03-03 and 03-04)
provides:
  - DocumentKind, ResetPeriod and InvalidNumberPattern (numbering value types)
  - NumberPattern (linear token parser, per-kind limits, format, scope-key mapping)
  - DocumentNumbering (next, preview, nextTaskNumber) as the one numbering entry point
  - SequenceAllocator::peek() (read-only next value)
  - NumberPatternRule and NumberingSettings (group numbering) with its settings migration
affects: [03-09 numbering section of the invoicing tab, Phase 5 task numbers, Phase 10 invoice proforma and credit-note numbers]

plan_head_before: b680a4ec7cbffca5727e5cecd0b7faac1136a44a
plan_head_after: 3fc27c083123649b2fc2a65d4426b571542a1ebf

actuals:
  tokens: 12000
  tasks: 2
  commits: 3

tech-stack:
  added: []
  patterns:
    - "The pattern decides the allocator scope key (kind:YYYY, kind:YYYY-MM, kind:all) and the written form; the counter itself stays in the Phase 2 allocator, so a pattern change starts a new scope key and never rewrites a number"
    - "A read-only preview goes through SequenceAllocator::peek(): a plain SELECT with no lock, no insert and no transaction requirement"
    - "A data-layer rule that maps a domain exception reason to a Czech message (NumberPatternRule), so ValidationException messages are Czech text, not translation keys"

key-files:
  created:
    - app/Domain/Settings/Numbering/DocumentKind.php
    - app/Domain/Settings/Numbering/ResetPeriod.php
    - app/Domain/Settings/Numbering/InvalidNumberPattern.php
    - app/Domain/Settings/Numbering/NumberPattern.php
    - app/Domain/Settings/Numbering/DocumentNumbering.php
    - app/Domain/Settings/Rules/NumberPatternRule.php
    - app/Domain/Settings/Settings/NumberingSettings.php
    - database/settings/2026_10_08_000160_create_numbering_settings.php
    - tests/Unit/Numbering/NumberPatternTest.php
    - tests/Feature/Operations/NumberingTest.php
  modified:
    - app/Domain/Shared/Sequences/SequenceAllocator.php
    - config/settings.php
    - lang/cs/kokpit.php
    - lang/cs/enums.php

key-decisions:
  - "The task number is KEY-N from the scope key task:<project id>; nextTaskNumber uses the fixed pattern constant (DocumentKind::Task->defaultPattern()) and does not read NumberingSettings, so a Partner-context or job caller never needs the settings read for a number that cannot be configured anyway"
  - "{YYYY} and {YY} together in one pattern are refused as duplicate_token (a year token at most once), the conservative reading of 'each date token at most once'"
  - "A literal outside A-Za-z0-9._/- is reason literal for every kind; in an invoice pattern an allowed non-digit literal is invoice_digits"
  - "Reason precedence in parse(): too_long, then task_fixed (task kind), then the left-to-right scan (unclosed_token, duplicate_token, unknown_token, literal, invoice_digits), then counter_count, month_without_year, invoice_length"
  - "NumberPattern::scopeKey() on the task pattern throws LogicException: its counter is per project, so a date-based key would be a silent misuse"
  - "NumberingSettings::rules() uses bail, required, string and NumberPatternRule; the plan's 32-character cap lives in the rule (too_long is checked first and gives the Czech reason), not as a separate max:32 rule"

patterns-established:
  - "Numbers are allocated only through DocumentNumbering::next() / nextTaskNumber() inside the caller's transaction; previews only through preview()"

requirements-completed: []

coverage:
  - id: D1
    description: "The stored invoice pattern drives a real allocation through the Phase 2 allocator on the importer-compatible scope key; the default pattern yields exactly SequenceAllocator::scopeKeyForYear, also for 31 December 23:30 UTC (already next year in Prague)"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#maps the default invoice pattern onto the allocator yearly key, also across the Prague new year"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#takes the year in Europe/Prague, so 31 December 23:30 UTC is already the next year"
        status: pass
    human_judgment: false
  - id: D2
    description: "The preview reads the counter through peek() and never writes: repeated previews are identical and number_sequences rows and values stay unchanged, also for a candidate pattern; peek works without a transaction and refuses a malformed key"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#previews the next number twice with the same result and leaves the counters untouched"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#previews a candidate pattern without storing it and without touching a counter"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#peeks outside a transaction without an error"
        status: pass
    human_judgment: false
  - id: D3
    description: "The bounded token grammar refuses unknown tokens, zero or two counters, a month without a year, duplicate date tokens, unclosed tokens, disallowed literals, patterns over 32 characters, letters in invoice patterns, over-long invoice patterns and any task pattern but KEY-N, each with its named reason; a 10 000-character input is refused in well under 50 ms"
    requirement: FND-07
    verification:
      - kind: unit
        ref: "tests/Unit/Numbering/NumberPatternTest.php#refuses a pattern with the named reason"
        status: pass
      - kind: unit
        ref: "tests/Unit/Numbering/NumberPatternTest.php#refuses a ten thousand character input as too long, quickly"
        status: pass
    human_judgment: false
  - id: D4
    description: "An invoice number that would exceed 10 characters throws OverflowException instead of truncating and the counter is given back with the caller's rollback; a proforma counter grows past its pad width"
    requirement: FND-07
    verification:
      - kind: unit
        ref: "tests/Unit/Numbering/NumberPatternTest.php#refuses to write an invoice number longer than ten characters instead of truncating it"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#throws instead of truncating when a number no longer fits ten characters, and gives the number back"
        status: pass
    human_judgment: false
  - id: D5
    description: "Task numbers are KEY-N from a per-project counter (task:<project id>); an invalid project key is refused before anything is allocated; outside a transaction it throws"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#numbers tasks per project as KEY-N from the task scope key"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#refuses a project key that is not two to six capital letters"
        status: pass
    human_judgment: false
  - id: D6
    description: "A changed pattern applies only to later numbers: same reset period continues the counter (also switching {YYYY} to {YY}), a different reset period starts a new scope key at 1 and leaves the old counter alone, an already returned number string is unchanged"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#continues the counter when the pattern changes within the same reset period"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#starts a new series at 1 when the reset period changes and leaves the old counter alone"
        status: pass
    human_judgment: false
  - id: D7
    description: "NumberingSettings refuses an invalid pattern written outside any form with a ValidationException whose message is the Czech text of the reason, and the stored patterns stay unchanged"
    requirement: FND-07
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#refuses an invalid pattern written outside any form with the Czech reason and stores nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#has a Czech message for every refusal reason"
        status: pass
    human_judgment: false
  - id: D8
    description: "Czech wording of the refusal reasons under kokpit.settings.numbering.errors and the document-kind labels reads naturally"
    verification: []
    human_judgment: true
    rationale: "Wording quality is a language judgment no test asserts; tests only prove every key has a non-key translation"

duration: 9min
completed: 2026-10-08
status: complete
---

# Phase 3 Plan 08: Document Numbering Patterns Summary

**Token-pattern document numbering (a bounded linear grammar with per-kind limits, a digits-only 10-character invoice number, a fixed per-project KEY-N task number) mapped onto the Phase 2 gap-free allocator by scope key, with a read-only `peek()` preview that never consumes a number**

## Performance

- **Duration:** about 9 min
- **Started:** 2026-10-08T03:12Z
- **Completed:** 2026-10-08T03:21Z
- **Tasks:** 2
- **Files modified:** 14 (10 created, 4 modified)

## Accomplishments

- `NumberPattern`: one linear scan, no regular expression on the whole input (a single-character literal check only), length cap first; tokens `{YYYY}`, `{YY}`, `{MM}`, `{N}`..`{NNNNNNNNNN}`; ten named refusal reasons; reset period derived from the date tokens; `scopeKey()` maps to `kind:YYYY`, `kind:YYYY-MM` or `kind:all` in Europe/Prague, so the default `{YYYY}{NNNN}` yields exactly `SequenceAllocator::scopeKeyForYear('invoice', at)` and importer-written counter rows stay valid.
- `SequenceAllocator::peek()`: validates the key like `next()`, plain `SELECT` with no lock, insert or transaction requirement, absent row answers 1. `DocumentNumbering::preview()` uses it; previews never change `number_sequences`.
- `DocumentNumbering::next()` allocates inside the caller's transaction and formats; an invoice number over 10 characters throws `OverflowException` (the caller's rollback gives the counter back). `nextTaskNumber()` is the TA-02 contract for Phase 5 (`ABC-1`, `ABC-2`, per-project counter).
- `NumberingSettings` (group `numbering`, four patterns with defaults, settings migration, registered in `config/settings.php`) with `NumberPatternRule` per kind: an invalid pattern written by code, not only by a form, raises a `ValidationException` with the Czech reason and stores nothing.
- Full gate green: `ddev composer ci` (Pest 661 passed, Pint, PHPStan level 8, licence check on 201 packages); the Phase 2 allocator tests and the concurrency proof ran unchanged and pass.

## Task Commits

1. **Task 1: Tracer - stored invoice pattern drives allocation and the read-only preview** - `9de2175` (feat)
2. **Task 2: Full grammar, pattern rule, fixed task number, pattern-change semantics** - RED `b3ddda9` (test), GREEN `3fc27c0` (feat)

**Plan metadata:** committed separately after this summary (docs: complete plan)

## TDD Gate Compliance

Task 2 is `tdd="true"`; RED then GREEN commits are present and in order, no REFACTOR commit (nothing to clean up).

- **Tracer feedback gate (Task 1, automated-only verify):** the tracer `<verify>` (Pest on the numbering, sequence, concurrency and enum-label tests, Pint, PHPStan) was re-run green and the full suite passed before expansion.
- **RED (`b3ddda9`):** 22 unit tests failed and 26 feature tests failed. Semantic assessment: the unit failures are assertion failures on the planned behavior (`null is identical to 'duplicate_token'`, `null is identical to 'month_without_year'`, `null is identical to 'invoice_length'`, `'literal' is null` for valid proforma/credit-note patterns, `'task_fixed' is null` for `{KEY}-{N}`, `OverflowException not thrown`, wrong formatted strings); the feature failures are `Call to undefined method nextTaskNumber()` / missing `NumberPatternRule` class (expected: the behavior did not exist), `OverflowException not thrown`, and the Czech-reason keys not yet present. No failure came from syntax, import or fixture faults. `gsd_run check tdd-red-evidence` was not run (Pest console output is not one of its supported report formats); the assessment is manual inspection of the Pest output.
- **GREEN (`3fc27c0`):** all 96 numbering tests pass, then the full CI gate.
- **Tests green on their first run** (the tracer already implemented the behavior): the counter_count, unknown_token, empty-token and `{KEY}` in a document pattern cases, the reset-period and Prague-time scope-key cases, the continue-counter and the new-series-on-reset-change cases, and the task-not-through-document-entry-points case. Proven with mutation checks, source restored after each:

| Mutation | Tests that failed |
|---|---|
| yearly scope key written with the two-digit year | reset-period cases, allocator-key mapping, Prague new year, per-kind counters, pattern-change cases |
| time zone `Europe/Prague` replaced by `UTC` | month in Prague, allocator-key mapping across the new year, 31 December 23:30 UTC case |
| counter token accepts any number of `N` | the eleven-digit counter case |
| counter-count check disabled | no counter, two counters, empty pattern, rule test, data-layer invoice-without-counter test |

## Files Created/Modified

- `app/Domain/Settings/Numbering/DocumentKind.php` - backed enum with `sequenceKind()`, `defaultPattern()` and Czech labels
- `app/Domain/Settings/Numbering/ResetPeriod.php` - Yearly, Monthly, Never
- `app/Domain/Settings/Numbering/InvalidNumberPattern.php` - exception carrying the reason
- `app/Domain/Settings/Numbering/NumberPattern.php` - parser, validator, formatter, scope-key mapping
- `app/Domain/Settings/Numbering/DocumentNumbering.php` - `next`, `preview`, `nextTaskNumber`
- `app/Domain/Settings/Rules/NumberPatternRule.php` - validation rule with Czech reasons
- `app/Domain/Settings/Settings/NumberingSettings.php` - stored patterns, `rules()`, `patternFor()`
- `database/settings/2026_10_08_000160_create_numbering_settings.php` - four defaults
- `app/Domain/Shared/Sequences/SequenceAllocator.php` - `peek()`
- `config/settings.php`, `lang/cs/kokpit.php`, `lang/cs/enums.php` - registration and Czech strings
- `tests/Unit/Numbering/NumberPatternTest.php` (no app), `tests/Feature/Operations/NumberingTest.php` - 96 cases in total (with datasets)

## Decisions Made

See `key-decisions` in the frontmatter. In short: the task number ignores the settings read and uses the fixed pattern, two year tokens are a duplicate, reason precedence is fixed and documented, and the 32-character cap is part of the rule instead of a separate `max:32`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Design] 32-character cap carried by the rule, not by `max:32`**
- **Found during:** Task 2 (rules design)
- **Issue:** the Task 1 `rules()` required `max:32`; keeping it next to `NumberPatternRule` would show Laravel's generic English-style max message instead of the Czech `too_long` reason the plan's behavior list wants.
- **Fix:** `rules()` is `bail`, `required`, `string`, `NumberPatternRule`; the length check is the first thing `NumberPattern::parse()` does.
- **Files modified:** `app/Domain/Settings/Settings/NumberingSettings.php`
- **Committed in:** `3fc27c0`

**2. [Test fix] Settings loaded before the transaction is left in two tests**
- **Found during:** Task 1 (first run of the outside-a-transaction test)
- **Issue:** rolling back the test transaction removes the test's Admin, after which the fail-closed settings scope hides the settings and `MissingSettings` was thrown instead of the allocator's `LogicException`.
- **Fix:** the test loads the settings (via a preview) before leaving the transaction.
- **Committed in:** `9de2175`

---

**Total deviations:** 2 (1 design, 1 test fix)
**Impact on plan:** No scope change.

## Issues Encountered

- A Pest invocation right after a file write sometimes ran the previous version of the file (container sync lag); a rerun was fine.
- `requirements.ready-ids` reports 0 of 1 ready for FND-07: plans 03-09 and 03-19 also declare it and have no summary yet, so FND-07 is not marked complete here.

## Known Stubs

None.

## Threat Flags

None. T-03-18 (pathological input) is mitigated by the length cap first, a linear scan and the 10 000-character timing test; T-03-19 (preview consuming numbers) by `peek()` and the unchanged-counter assertions; T-03-20 (pattern change colliding with issued numbers) by new scope keys on a reset-period change and the digits-only 10-character invoice limit; T-03-SC: no package added, `composer.json` and `composer.lock` unchanged.

## User Setup Required

None - no external service configuration required.

## Manual follow-up

- Review the Czech wording under `kokpit.settings.numbering.errors.*` and `enums.document_kind.*`; it is placeholder-quality discretionary wording.

## Open items for later phases

- **Phase 10 (open item from the plan):** whether proformas, and credit notes, also need digits-only numbers because of the variable symbol. This plan allows letters and `. _ / -` in proforma and credit-note patterns (default is digits only) and limits only invoices to digits and 10 characters. If Phase 10 needs the same limit for them, the rule is one condition on `DocumentKind` in `NumberPattern::parse()`.
- A switch from a dated pattern to a dateless one (or between different widths) starts or continues a different scope key. Two different patterns can in theory write the same string (for example a yearly two-digit-year series and a dateless series); Phase 10 should keep a unique constraint on the issued number string, which this plan does not add.
- `DocumentNumbering` reads `NumberingSettings` through the scoped settings model: callers must run as the Admin or inside the system context (Phase 10 issuing and any scheduled job).
- Plan 03-09 adds `NumberingSettings` to `SettingsPage::SETTINGS` and the numbering section (with `preview()` for the live example).

## Next Phase Readiness

- Ready for 03-09 (settings page numbering section): `DocumentNumbering::preview($kind, $candidatePattern)` returns a preview for an unsaved pattern and throws `InvalidNumberPattern` (with a `reason`) for a refused one; `NumberPatternRule` is ready to be attached to form fields.
- Phase 5 calls `nextTaskNumber($projectId, $projectKey)` inside its create-task transaction; Phase 10 calls `next($kind, $issuedAt)` inside the issue transaction and stores the returned string on the document.

## Self-Check: PASSED

- All ten created files exist on disk.
- Commits `9de2175`, `b3ddda9` and `3fc27c0` are ancestors of HEAD; `git rev-list --count` from the ledger base gives 3.
- Acceptance greps for both tasks pass (`public function peek(string $scopeKey): int`, no lock in the peek body, `NumberingSettings::class` in `config/settings.php`, `nextTaskNumber(string $projectId, string $projectKey): string`, `task_fixed` and `invoice_length` in `NumberPattern.php`, `NumberPatternRule` in `NumberingSettings.php`); `ddev composer ci` (Pest 661, Pint, PHPStan level 8, licence check) is green; the allocator concurrency test passes unchanged.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
