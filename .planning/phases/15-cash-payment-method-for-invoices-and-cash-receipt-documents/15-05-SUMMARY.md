---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
plan: 05
subsystem: numbering
tags: [numbering, settings, filament, sequences, cash-receipt]

requires:
  - phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
    provides: "15-01 wave 1 (lang/cs/enums.php baseline)"
provides:
  - "DocumentKind::CashReceipt ('cash_receipt', default PPD{YYYY}{NNNN}, no variable symbol)"
  - "NumberingSettings::$cash_receipt_pattern with NumberPatternRule and patternFor() arm"
  - "Settings migration 2026_10_27_000100_add_cash_receipt_pattern (adds the row only when missing)"
  - "Cash receipt pattern field on the settings page with live preview and reset warning"
affects: [15-08, 15-09]

actuals:
  tokens: 5100
  tasks: 2
  commits: 4

tech-stack:
  added: []
  patterns:
    - "A new document kind is one enum case, one settings property, one settings migration and one page field; the allocator scope key follows from sequenceKind()"

key-files:
  created:
    - database/settings/2026_10_27_000100_add_cash_receipt_pattern.php
  modified:
    - app/Domain/Settings/Numbering/DocumentKind.php
    - app/Domain/Settings/Settings/NumberingSettings.php
    - app/Filament/Pages/SettingsPage.php
    - lang/cs/enums.php
    - lang/cs/kokpit.php
    - tests/Feature/Operations/NumberingTest.php
    - tests/Feature/Operations/NumberingPageTest.php
    - tests/Unit/Numbering/NumberPatternTest.php
    - tests/Unit/Invoicing/VariableSymbolTest.php

key-decisions:
  - "R-15-05-1..4 applied as ruled (enum case after CreditNote, explicit defaultPattern arm, settings property + rule + patternFor arm, settings migration, page field after the credit note)"
  - "The settings migration guards with migrator->exists(), so a fresh install, a repeated run and an Admin-chosen pattern all end with exactly one untouched row"
  - "create_numbering_settings (2026_10_08_000160) is not edited: existing and fresh installs both get the row from the new migration"

patterns-established:
  - "carriesVariableSymbol() false is what allows letters in the cash receipt pattern; NumberPattern needed no change"

requirements-completed: [P15-06]

coverage:
  - id: D1
    description: "Cash receipts are numbered PPD2026.. then next free number inside the caller's transaction, rollback gives the number back, invoice counter untouched"
    requirement: "P15-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php (cash receipt tests)"
        status: unknown
    human_judgment: false
  - id: D2
    description: "Pattern grammar for CashReceipt (letters allowed, one counter required), own yearly scope key cash_receipt:YYYY"
    requirement: "P15-06"
    verification:
      - kind: unit
        ref: "tests/Unit/Numbering/NumberPatternTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "Settings migration adds numbering.cash_receipt_pattern once and never overwrites"
    requirement: "P15-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingTest.php#adds the cash receipt pattern ..."
        status: unknown
    human_judgment: false
  - id: D4
    description: "Admin edits the cash receipt pattern on the settings page with preview, validation and reset warning"
    requirement: "P15-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/NumberingPageTest.php"
        status: unknown
    human_judgment: false

duration: 30min
completed: 2026-10-27
status: complete
commits: 4
plan_head_before: b16d274f6b39d49100617994be6b4bf4b78ce9a4
plan_head_after: 5f79bb8dbeaac57aa81e7d8228cc14988a442448
---

# Phase 15 Plan 05: Cash receipt number series Summary

**Cash receipts now have their own gap-free yearly series (`cash_receipt:YYYY`, default `PPD{YYYY}{NNNN}`) allocated through the existing `DocumentNumbering` and `SequenceAllocator`, with the pattern editable on the settings page and added to existing installations by a settings migration.**

## Accomplishments

- `DocumentKind::CashReceipt` (`cash_receipt`) with the explicit default `PPD{YYYY}{NNNN}`; `carriesVariableSymbol()` is unchanged and false for it, so the parser already allows letters and applies no 10-character cap. Czech label "Příjmový pokladní doklad".
- `NumberingSettings::$cash_receipt_pattern`, validated by `NumberPatternRule(DocumentKind::CashReceipt)`, plus the mandatory `patternFor()` arm.
- Settings migration `2026_10_27_000100_add_cash_receipt_pattern.php` adds the row only when missing.
- The numbering section of the settings page shows "Vzor čísla příjmového pokladního dokladu" after the credit note, with the preview from its own counter and the reset warning (reuses `patternInputs()`).
- Tests: NumberingTest (PPD0001/PPD0002, own counter row, rollback gives the number back, preview takes none, saved `PPD-{YY}/{NNN}` gives `PPD-26/001`, migration add and idempotence, invalid patterns refused), NumberPatternTest (grammar, scope key, format, enum default), VariableSymbolTest (a cash receipt has no symbol), NumberingPageTest (field, previews, save, refusal, warning). Existing stored-pattern expectations extended with the new property.

## Task Commits

1. Task 1 (tracer) RED: `68cfd0e` test(15-05) failing tests for the cash receipt number series
2. Task 1 GREEN: `2eb9428` feat(15-05) own gap-free number series for cash receipts
3. Task 2 RED: `13f08f5` test(15-05) failing tests for the cash receipt pattern on the settings page
4. Task 2 GREEN: `5f79bb8` feat(15-05) edit the cash receipt number pattern on the settings page

`commits: 4` is measured with `git rev-list --count` from the base `b16d274` to `5f79bb8`; the SUMMARY commit follows it. No refactor commit was needed.

## TDD Gate Compliance

RED commits (`68cfd0e`, `13f08f5`) precede their GREEN commits (`2eb9428`, `5f79bb8`). RED evidence is weak and no `gsd_run check tdd-red-evidence` record was produced:

- Task 1 RED: the unit run of `NumberPatternTest` failed at dataset collection (Pest `DatasetMissing`, because `DocumentKind::CashReceipt` is an undefined constant). This is the planned cause (the enum case did not exist) but not an assertion failure, so a strict classifier would call it INVALID_RED. The Feature tests need the shared database and were not run (project rule: no DB work in a worktree).
- Task 2 RED: not run; the tests need the Filament page and the shared database. By construction the field `cash_receipt_pattern` did not exist on the page or in the settings class.
- GREEN: the unit tests now pass in the worktree (`NumberPatternTest`, `VariableSymbolTest`, `InvoiceEnumsTest`: 84 passed; the 12 warnings are pre-existing `file_get_contents` warnings in tests that read files outside the worktree). The Feature tests are to be run after the merge.

## Verification

Run in the worktree (cheap, DB-free; `vendor/` copied from the main checkout, gitignored):

- `pest tests/Unit/Numbering/NumberPatternTest.php tests/Unit/Invoicing/VariableSymbolTest.php tests/Unit/Invoicing/InvoiceEnumsTest.php`: all pass.
- `pint --test` on every touched file: passed.
- `scripts/check-sensitive.sh` and gitleaks clean on every commit (lefthook ran, not bypassed).

NOT run (to do after the merge into the HEAD branch, in DDEV, after migrating):

- `ddev exec vendor/bin/pest tests/Feature/Operations tests/Unit/Numbering tests/Arch tests/Feature/Localisation/EnumLabelsTest.php tests/Unit/Invoicing/VariableSymbolTest.php`
- `ddev exec vendor/bin/pint --test` (whole repo)
- `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` (worktree PHPStan fails at application bootstrap without `.env`)
- The settings migration must be applied (`php artisan migrate`) before the Feature tests: it creates the `numbering.cash_receipt_pattern` row that `NumberingSettings` needs.

## Deviations from Plan

None in the code. Process deviation: the plan's Pest and PHPStan verify commands moved to the post-merge run (project rule for worktrees). A test for a Partner reaching the page was not added; the existing `SettingsPageTest` already covers the unchanged access rule.

## Decisions Made

- The settings migration uses `migrator->exists()` instead of the bare add form of `create_numbering_settings`, because the plan requires "only when missing" and idempotence.
- Feature tests that enumerate the stored numbering rows now include `cash_receipt_pattern`, as the migration adds it on every fresh database.

## Known Stubs

None.

## Threat Flags

None. T-15-12 mitigated: own scope key from `sequenceKind()` (pinned by tests as `cash_receipt:2026`, distinct from the invoice key) and `NumberPatternRule(DocumentKind::CashReceipt)` on the page and in `rules()`. T-15-13: allocation only via `DocumentNumbering::next()` in the caller's transaction (concurrency test belongs to 15-08). T-15-14 unchanged (page stays admin only). T-15-SC: composer files untouched.

## Self-Check: PASSED

- FOUND: database/settings/2026_10_27_000100_add_cash_receipt_pattern.php
- FOUND: app/Domain/Settings/Numbering/DocumentKind.php (case CashReceipt)
- FOUND commits: 68cfd0e, 2eb9428, 13f08f5, 5f79bb8
