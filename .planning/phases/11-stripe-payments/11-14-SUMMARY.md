---
phase: 11-stripe-payments
plan: 14
subsystem: operations
tags: [stripe, webhook, system-page, health-indicator, pruning, scheduler]
status: complete

requires:
  - phase: 11-stripe-payments
    provides: "webhook_calls processing columns and the ProcessStripeWebhook failure marking (11-04), StripeConfig::hasSecretKey()/hasWebhookSecret() and StripeTestConfig (11-01), CardPayment records independent of the event row (11-09), PaymentSettings::online_payments_enabled and the card switch (11-03, 11-10)"
provides:
  - "UnprocessedWebhooksIndicator: the real 'Nezpracované webhooky' slot (count of unprocessed Stripe events; Ok / Warning past 900 s / Error when an event failed), fixed Czech details with count and age, no payload or exception text"
  - "HealthSlot::Stripe and StripeConfigIndicator: 'Nastaveno' / 'Nenastaveno' with the UI-SPEC detail lines, booleans only, never a key or a prefix"
  - "kokpit.health.unprocessed_webhook_warning_after (900 s), lang keys kokpit.system.webhooks.* and kokpit.system.stripe.*, enums health_slot.stripe"
  - "WebhookCall::prunable() override and PruneProcessedWebhookCalls (KokpitJob), scheduled daily on one server as kokpit-webhook-prune"
affects: [11-15, 11-16, 11-19, system-page, card-payments]

actuals:
  tokens: 10000
  tasks: 3
  commits: 3
plan_head_before: aae8e1ac1153e01af6164a640e86da73ccde4cbd
plan_head_after: ff1a34651af13cbe65f796cd3005499f3db1477d
commits: 3

tech-stack:
  added: []
  patterns:
    - "A health indicator reads one aggregate (COUNT, COUNT FILTER, MIN) as system and formats a fixed Czech detail with a count and a Carbon absolute age"
    - "A secret is reported by yes-or-no questions to a config object, never by reading the value"
    - "MassPrunable prunable() narrowed to the rows that are safe to lose; the pruning job calls pruneAll() inside the job's system context"

key-files:
  created:
    - app/Domain/Operations/Health/Indicators/UnprocessedWebhooksIndicator.php
    - app/Domain/Operations/Health/Indicators/StripeConfigIndicator.php
    - app/Domain/Payments/Jobs/PruneProcessedWebhookCalls.php
    - tests/Feature/Payments/SystemIndicatorsTest.php
    - tests/Feature/Payments/PruneWebhookCallsTest.php
  modified:
    - app/Domain/Operations/Health/HealthSlot.php
    - app/Providers/OperationsServiceProvider.php
    - app/Domain/Shared/Models/WebhookCall.php
    - config/kokpit.php
    - lang/cs/enums.php
    - lang/cs/kokpit.php
    - routes/console.php
    - tests/Arch/JobContractTest.php
    - tests/Feature/Operations/HealthRegistryTest.php
    - tests/Feature/Operations/SystemPageTest.php
    - tests/Feature/Operations/ScheduleOnOneServerTest.php

key-decisions:
  - "R11-14-1 to R11-14-3 applied as written"
  - "The Ok state with a young waiting event shows the waiting text ('Čekající události ze Stripe: :count. Nejstarší čeká :age.') with the count as value, as ruled; Warning uses the same text, so the badge alone tells them apart"
  - "The age is Carbon's absolute Czech duration (diffForHumans with DIFF_ABSOLUTE), because no other indicator formats a relative duration: the oldest-pending slot prints plain seconds"
  - "The pruning age runs from processed_at, not created_at, so an event that waited long before it was processed still stays 90 days after processing"
  - "With no placeholder left, the registry test that listed the placeholder slots became 'has no placeholder left' plus a direct test of PlaceholderIndicator"

patterns-established:
  - "A new HealthSlot case needs three edits and no more: the enum case with its label in lang/cs/enums.php, the indicator, the registration in OperationsServiceProvider (the placeholder logic covers any slot left out)"

requirements-completed: []

duration: 25min
completed: 2026-10-18
---

# Phase 11 Plan 14: System page webhook and Stripe slots, event pruning Summary

**A Stripe event that waits or failed turns the System page slot Warning or Error with its count and age, a new Stripe slot says only whether the keys are set, and processed events are deleted 90 days after processing by a nightly system job that never touches a failed or waiting one.**

## Performance

- **Duration:** about 25 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 16 (5 created, 11 modified)

## Accomplishments

- Tracer: with no Stripe event the slot is Ok "0" with "Všechny události ze Stripe byly zpracovány."; one event waiting 5 minutes is Ok with the waiting text; one waiting 20 minutes is Warning; one with `failed_at` is Error "Nezpracováno: 1. Nejstarší čeká 40 minut. Zkontrolujte neúspěšné úlohy."; a processed event and an event of another webhook name are not counted. The rendered Admin System page contains the detail and neither the payload marker nor the failure class. The threshold is read with `max(1, ...)` (tested at 60 and at 0).
- `HealthSlot::Stripe` sits right after `UnprocessedWebhooks`; `StripeConfigIndicator` answers per the UI-SPEC table: both keys Ok "Nastaveno"; neither key and online payments off Ok "Nenastaveno"; neither key and online payments on Error; one key only Error naming the missing one. The page test asserts that neither configured value nor its first six characters appears in the HTML.
- `WebhookCall::prunable()` returns the Stripe rows with `processed_at` older than `webhook-client.delete_after_days` (90) and `failed_at` null. `PruneProcessedWebhookCalls` (KokpitJob, `#[Idempotent]`) calls `pruneAll()` as system; scheduled `kokpit-webhook-prune`, daily, one server. Test: two 91-day-old processed events deleted, the 89-day-old processed one, a 120-day-old waiting one, a 120-day-old failed one and a processed row of another webhook name kept; the card payment record of a pruned event survives with its Stripe references; a second run deletes nothing; a changed `delete_after_days` is honoured.
- Verification: Payments, Operations and Arch suites 803 passed (3996 assertions); Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every commit.

## Task Commits

1. Task 1 (tracer): unprocessed webhooks slot, threshold, copy, registration - `edf85bb` (feat)
2. Task 2: Stripe configured slot and the seventh slot in the registry - `08dde0b` (feat)
3. Task 3: prunable override, pruning job, schedule - `ff1a346` (feat)

Tests and implementation went into the same commit per task. Each test file was written and seen failing (missing class or slot) before the implementation was added, but no separate RED commit was made.

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Existing tests assumed a placeholder webhooks slot and six slots**
- **Found during:** Task 1 and Task 2
- **Issue:** `SystemPageTest` (a 'Zatím není k dispozici' assertion and the colour-badge count) and `HealthRegistryTest` (the placeholder and six-slot lists) encoded the state before this plan.
- **Fix:** removed the placeholder-text assertion, added the two slots to the replaced-status map of the badge test, turned the placeholder test into "no placeholder left" plus a direct `PlaceholderIndicator` test, and listed seven slots.
- **Files modified:** `tests/Feature/Operations/SystemPageTest.php`, `tests/Feature/Operations/HealthRegistryTest.php`
- **Commits:** `edf85bb`, `08dde0b`

**2. [Rule 3 - Blocking] Known scheduled events list**
- **Found during:** Task 3
- **Issue:** `tests/Feature/Operations/ScheduleOnOneServerTest.php` lists the registered event names; the new event belongs there. Not in the plan's file list.
- **Fix:** added `kokpit-webhook-prune`.
- **Commit:** `ff1a346`

### Choices within the plan's latitude

- `prunable()` ages from `processed_at` (the plan says the same; recorded here because the package default is `created_at`) and applies `max(1, ...)` to the retention so a zero configuration cannot delete everything processed today.
- The Ok-with-young-waiting-event detail reuses the waiting text, as ruled in R11-14-1.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. T-11-49 (a key on the System page): the indicator calls `hasSecretKey()` and `hasWebhookSecret()` only; the page test asserts the value and its first six characters are absent for both secrets. T-11-50 (losing an unprocessed event): `prunable()` selects processed, not failed Stripe rows only; tested with a waiting, a failed and a foreign-name row. T-11-51 (payload or exception text on the page): the aggregate selects a count, a failed count and the oldest `created_at`; the page test asserts a payload marker and the failure class are absent. T-11-SC: this plan changed no composer file.

## Notes for later plans

- PA-05 and PA-08 stay open. This plan delivers the System page visibility and the pruning parts only; the unmatched and duplicate alerts, the payments overview and manual resolution (PA-08) arrive with plans 11-15, 11-16 and 11-19, and the PA-05 endpoint and job were delivered by 11-04.
- The slot offers no retry control (assumption U-13); the Admin reruns a failed job from the failed job list.

## Self-Check: PASSED

- Files: all 5 created files exist.
- Commits: `edf85bb`, `08dde0b` and `ff1a346` are ancestors of HEAD; `git rev-list --count` from the recorded base gives 3.
